<?php
require_once 'auth.php';
require_once '../lib/trip_helpers.php';
require_once '../lib/vehicle_helpers.php';

$page_title = 'Bookings Management';
hz_expire_overdue_no_shows($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $vehicle_id_raw = $_POST['vehicle_id'] ?? '';
    $allowed_statuses = ['pending', 'confirmed', 'in_progress', 'denied', 'cancelled', 'completed'];

    if ($booking_id <= 0 || !in_array($status, $allowed_statuses, true)) {
        $error = 'Invalid booking update request.';
    } else {
        $currentBookingResult = $conn->query("
            SELECT booking_id, trip_id, vehicle_id, driver_id, boarding_status
            FROM bookings
            WHERE booking_id = {$booking_id}
            LIMIT 1
        ");
        $currentBooking = $currentBookingResult ? $currentBookingResult->fetch_assoc() : null;

        if (!$currentBooking) {
            $error = 'Booking not found.';
        } else {
            $isScheduledBooking = !empty($currentBooking['trip_id']);
            $vehicle_id = $currentBooking['vehicle_id'] !== null ? intval($currentBooking['vehicle_id']) : null;
            $assigned_driver_id = $currentBooking['driver_id'] !== null ? intval($currentBooking['driver_id']) : null;

            if (!$isScheduledBooking) {
                $vehicle_id = $vehicle_id_raw === '' ? null : intval($vehicle_id_raw);
                $assigned_driver_id = null;

                if (in_array($status, ['denied', 'cancelled'], true)) {
                    $vehicle_id = null;
                }

                if ($vehicle_id !== null) {
                    $driverStmt = $conn->prepare("SELECT driver_id FROM vehicles WHERE vehicle_id = ? LIMIT 1");
                    if ($driverStmt) {
                        $driverStmt->bind_param('i', $vehicle_id);
                        $driverStmt->execute();
                        $driverResult = $driverStmt->get_result();
                        $vehicleRow = $driverResult ? $driverResult->fetch_assoc() : null;
                        $assigned_driver_id = isset($vehicleRow['driver_id']) ? intval($vehicleRow['driver_id']) : null;
                        $driverStmt->close();
                    }
                }
            } elseif ($vehicle_id !== null && $assigned_driver_id === null) {
                $driverResult = $conn->query("SELECT driver_id FROM vehicles WHERE vehicle_id = " . intval($vehicle_id) . " LIMIT 1");
                $driverRow = $driverResult ? $driverResult->fetch_assoc() : null;
                $assigned_driver_id = isset($driverRow['driver_id']) ? intval($driverRow['driver_id']) : null;
            }

            $vehicle_sql = $vehicle_id === null ? 'NULL' : (string) $vehicle_id;
            $driver_sql = $assigned_driver_id === null ? 'NULL' : (string) $assigned_driver_id;
            $extra_sql = '';
            $currentBoardingStatus = $currentBooking['boarding_status'] ?? 'scheduled';

            if ($isScheduledBooking) {
                if ($status === 'in_progress' && in_array($currentBoardingStatus, ['scheduled', 'vehicle_arrived'], true)) {
                    $extra_sql .= ", boarding_status = 'boarded', boarded_at = COALESCE(boarded_at, NOW())";
                } elseif ($status === 'completed' && $currentBoardingStatus !== 'dropped_off') {
                    $extra_sql .= ", boarding_status = 'dropped_off', dropped_off_at = COALESCE(dropped_off_at, NOW())";
                } elseif (in_array($status, ['cancelled', 'denied'], true) && !in_array($currentBoardingStatus, ['no_show', 'dropped_off'], true)) {
                    $extra_sql .= ", boarding_status = 'no_show', no_show_at = COALESCE(no_show_at, NOW())";
                }
            }

            $stmt = $conn->prepare("
                UPDATE bookings
                SET status = ?,
                    vehicle_id = {$vehicle_sql},
                    driver_id = {$driver_sql}
                    {$extra_sql}
                WHERE booking_id = ?
            ");

            if ($stmt) {
                $stmt->bind_param('si', $status, $booking_id);
                if ($stmt->execute()) {
                    if ($status === 'completed') {
                        hz_create_driver_earning_if_missing($conn, $booking_id);
                    }

                    $message = 'Booking status updated successfully.';
                    $adminId = intval($_SESSION['user_id'] ?? 0);
                    logCRUD(
                        $conn,
                        $adminId,
                        'UPDATE',
                        'bookings',
                        $booking_id,
                        'Status set to: ' . $status .
                        '; Vehicle: ' . ($vehicle_id === null ? 'Unassigned' : $vehicle_id) .
                        '; Driver: ' . ($assigned_driver_id === null ? 'Unassigned' : $assigned_driver_id) .
                        '; Booking Type: ' . ($isScheduledBooking ? 'Scheduled Trip' : 'Legacy')
                    );
                } else {
                    $error = 'Error updating booking: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $error = 'Error preparing booking update: ' . $conn->error;
            }
        }
    }
}

require_once 'header.php';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(5, intval($_GET['per_page'])) : 20;
$offset = ($page - 1) * $per_page;
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_name = trim((string) ($_GET['customer_name'] ?? ''));

if (($_GET['suggest'] ?? '') === 'customer') {
    header('Content-Type: application/json');

    $term = trim((string) ($_GET['term'] ?? ''));
    if ($term === '') {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    $termEscaped = $conn->real_escape_string($term);
    $suggestions = [];
    $suggestQuery = $conn->query("
        SELECT DISTINCT c.full_name
        FROM bookings b
        JOIN customers c ON b.passenger_id = c.user_id
        WHERE c.full_name LIKE '%{$termEscaped}%'
        ORDER BY c.full_name ASC
        LIMIT 8
    ");

    if ($suggestQuery) {
        while ($row = $suggestQuery->fetch_assoc()) {
            $suggestions[] = $row['full_name'];
        }
    }

    echo json_encode(['suggestions' => $suggestions]);
    exit;
}

$whereClauses = [];

if ($start_date && $end_date) {
    $sd = $conn->real_escape_string($start_date);
    $ed = $conn->real_escape_string($end_date);
    $whereClauses[] = "DATE(COALESCE(b.scheduled_departure_at, vt.scheduled_departure_at, b.requested_time)) BETWEEN '{$sd}' AND '{$ed}'";
} elseif ($start_date) {
    $sd = $conn->real_escape_string($start_date);
    $whereClauses[] = "DATE(COALESCE(b.scheduled_departure_at, vt.scheduled_departure_at, b.requested_time)) >= '{$sd}'";
} elseif ($end_date) {
    $ed = $conn->real_escape_string($end_date);
    $whereClauses[] = "DATE(COALESCE(b.scheduled_departure_at, vt.scheduled_departure_at, b.requested_time)) <= '{$ed}'";
}

if ($search_name !== '') {
    $searchEscaped = $conn->real_escape_string($search_name);
    $whereClauses[] = "c.full_name LIKE '%{$searchEscaped}%'";
}

$where = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$countSql = "
    SELECT COUNT(*) AS cnt
    FROM bookings b
    JOIN customers c ON b.passenger_id = c.user_id
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_trips vt ON b.trip_id = vt.trip_id
    LEFT JOIN routes r ON r.route_id = IFNULL(vt.route_id, b.route_id)
    {$where}
";
$countResult = $conn->query($countSql);
$totalCount = $countResult ? intval($countResult->fetch_assoc()['cnt'] ?? 0) : 0;
$totalPages = $per_page > 0 ? max(1, ceil($totalCount / $per_page)) : 1;

$bookingsSql = "
    SELECT
        b.*,
        c.full_name,
        v.vehicle_name,
        v.license_plate,
        v.vehicle_model,
        v.vehicle_type,
        vt.trip_status,
        vt.scheduled_departure_at AS trip_departure,
        vt.direction,
        vt.seat_capacity_snapshot,
        r.route_name
    FROM bookings b
    JOIN customers c ON b.passenger_id = c.user_id
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_trips vt ON b.trip_id = vt.trip_id
    LEFT JOIN routes r ON r.route_id = IFNULL(vt.route_id, b.route_id)
    {$where}
    ORDER BY COALESCE(b.scheduled_departure_at, vt.scheduled_departure_at, b.requested_time) DESC, b.created_at DESC
    LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
$bookings = $conn->query($bookingsSql);
$tripMetricsCache = [];
$activeFilters = array_values(array_filter([
    $search_name !== '' ? 'Customer: ' . $search_name : null,
    $start_date !== '' ? 'From ' . $start_date : null,
    $end_date !== '' ? 'To ' . $end_date : null,
]));
?>

<?php if (isset($message)): ?>
    <div style="background: #e8f5e8; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="table-container bookings-table-shell responsive-cards">
    <div class="table-header" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;">
        <div>
            <h2>Trip Bookings</h2>
            <p style="margin-top:6px; color:#666; font-size:0.95rem;">Monitor scheduled departures, baggage, boarding state, and live seat usage.</p>
        </div>
        <div class="booking-results-copy">
            <span class="results-pill"><?php echo intval($totalCount); ?> result<?php echo $totalCount === 1 ? '' : 's'; ?></span>
            <?php if ($search_name !== ''): ?>
                <span class="results-pill subtle">Matching customer name</span>
            <?php endif; ?>
        </div>
    </div>

    <details class="control-panel" open>
        <summary>
            <span><i class="fas fa-sliders-h"></i> Search & Filters</span>
            <?php if ($activeFilters): ?>
                <span class="control-panel-count"><?php echo count($activeFilters); ?> active</span>
            <?php endif; ?>
        </summary>
        <form method="GET" class="booking-toolbar">
            <div class="booking-search">
                <label for="customer_name">Customer Name</label>
                <div class="search-input-group booking-search-group">
                    <input
                        type="text"
                        id="customer_name"
                        name="customer_name"
                        class="search-input"
                        autocomplete="off"
                        placeholder="Search passenger name"
                        value="<?php echo htmlspecialchars($search_name); ?>"
                    >
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
                <div id="bookingSuggestions" class="autocomplete-list" hidden></div>
            </div>
            <div class="booking-toolbar-row">
                <div class="filter-group">
                    <label for="start_date">From</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="filter-group">
                    <label for="end_date">To</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="booking-toolbar-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <?php if ($search_name || $start_date || $end_date): ?>
                        <a href="bookings.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php if ($activeFilters): ?>
            <div class="active-filter-list">
                <?php foreach ($activeFilters as $filterLabel): ?>
                    <span class="active-filter-chip"><?php echo htmlspecialchars($filterLabel); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </details>

    <div style="overflow-x:auto;">
        <table class="bookings-table" style="min-width: 1500px;">
            <thead>
                <tr>
                    <th>Booking</th>
                    <th>Passenger</th>
                    <th>Trip / Route</th>
                    <th>Pickup / Dropoff</th>
                    <th>Departure</th>
                    <th>Vehicle</th>
                    <th>Baggage</th>
                    <th>Booking Status</th>
                    <th>Boarding</th>
                    <th>Seats</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($bookings && $bookings->num_rows > 0): ?>
                    <?php while ($booking = $bookings->fetch_assoc()): ?>
                        <?php
                        $tripId = isset($booking['trip_id']) ? intval($booking['trip_id']) : 0;
                        $metrics = null;
                        if ($tripId > 0) {
                            if (!isset($tripMetricsCache[$tripId])) {
                                $tripMetricsCache[$tripId] = hz_get_trip_metrics($conn, $tripId);
                            }
                            $metrics = $tripMetricsCache[$tripId];
                        }

                        $departureRaw = $booking['scheduled_departure_at'] ?: ($booking['trip_departure'] ?: $booking['requested_time']);
                        $departureLabel = $departureRaw ? date('M j, Y g:i A', strtotime($departureRaw)) : 'Not set';
                        $boardingStatus = $booking['boarding_status'] ?: 'scheduled';
                        $tripLabel = $tripId > 0 ? 'Trip #' . $tripId : 'Legacy Booking';
                        if (!empty($booking['direction'])) {
                            $tripLabel .= ' (' . ucfirst($booking['direction']) . ')';
                        }
                        $routeLabel = $booking['route_name'] ?: 'Custom route';
                        $seatSummary = $metrics
                            ? ($metrics['boarded'] . ' onboard / ' . $metrics['available'] . ' open of ' . $metrics['capacity'])
                            : 'Manual assignment';
                        ?>
                        <tr data-passenger-name="<?php echo htmlspecialchars(strtolower($booking['full_name'])); ?>">
                            <td data-label="Booking">
                                <strong>#<?php echo intval($booking['booking_id']); ?></strong><br>
                                <span style="color:#666; font-size:0.9rem;"><?php echo htmlspecialchars($tripLabel); ?></span>
                            </td>
                            <td data-label="Passenger">
                                <strong><?php echo htmlspecialchars($booking['full_name']); ?></strong><br>
                                <span style="color:#666; font-size:0.9rem;">Seat x<?php echo intval($booking['passenger_count'] ?: 1); ?></span>
                            </td>
                            <td data-label="Trip / Route">
                                <strong><?php echo htmlspecialchars($routeLabel); ?></strong><br>
                                <?php if (!empty($booking['trip_status'])): ?>
                                    <span class="status-badge status-<?php echo htmlspecialchars($booking['trip_status']); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $booking['trip_status']))); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#666; font-size:0.9rem;">Legacy request flow</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Pickup / Dropoff">
                                <strong>From:</strong> <?php echo htmlspecialchars($booking['pickup_location']); ?><br>
                                <strong>To:</strong> <?php echo htmlspecialchars($booking['dropoff_location']); ?>
                            </td>
                            <td data-label="Departure"><?php echo htmlspecialchars($departureLabel); ?></td>
                            <td data-label="Vehicle">
                                <?php if (!empty($booking['vehicle_name'])): ?>
                                    <strong><?php echo htmlspecialchars($booking['vehicle_name']); ?></strong><br>
                                    <span style="color:#666; font-size:0.85rem;"><?php echo htmlspecialchars(hz_vehicle_detail_line($booking)); ?></span>
                                <?php else: ?>
                                    Not Assigned
                                <?php endif; ?>
                            </td>
                            <td data-label="Baggage">
                                <?php
                                $bookingBaggageCount = intval($booking['baggage_count'] ?? 0);
                                echo $bookingBaggageCount . ' bag(s)';
                                if ($bookingBaggageCount > 0) {
                                    echo '<br><span style="color:#666; font-size:0.85rem;">PHP ' . number_format($bookingBaggageCount * BAGGAGE_FEE_PER_BAG, 2) . '</span>';
                                }
                                ?>
                                <br><span style="color:#666; font-size:0.85rem;">Total: PHP <?php echo number_format((float) ($booking['fare'] ?: $booking['fare_estimate']), 2); ?></span>
                            </td>
                            <td data-label="Booking Status">
                                <span class="status-badge status-<?php echo htmlspecialchars($booking['status']); ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $booking['status']))); ?>
                                </span>
                            </td>
                            <td data-label="Boarding">
                                <span class="status-badge status-<?php echo htmlspecialchars($boardingStatus); ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $boardingStatus))); ?>
                                </span>
                                <?php if (!empty($booking['boarding_deadline_at']) && in_array($boardingStatus, ['vehicle_arrived', 'scheduled'], true)): ?>
                                    <br><span style="color:#666; font-size:0.85rem;">Deadline: <?php echo date('g:i A', strtotime($booking['boarding_deadline_at'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Seats">
                                <?php echo htmlspecialchars($seatSummary); ?>
                                <?php if ($metrics): ?>
                                    <br><span style="color:#666; font-size:0.85rem;">Reserved: <?php echo intval($metrics['reserved']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <div class="action-buttons">
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        onclick="openEditModal(<?php echo intval($booking['booking_id']); ?>, '<?php echo htmlspecialchars($booking['status'], ENT_QUOTES); ?>', <?php echo $booking['vehicle_id'] !== null ? intval($booking['vehicle_id']) : 'null'; ?>, <?php echo $tripId ?: 'null'; ?>)"
                                    >
                                        Edit
                                    </button>
                                    <?php if (in_array($booking['status'], ['confirmed', 'in_progress', 'completed'], true)): ?>
                                        <a class="btn btn-primary" href="direct_print_ticket.php?booking_id=<?php echo intval($booking['booking_id']); ?>">Print Ticket</a>
                                        <a class="btn btn-secondary" href="print_ticket.php?booking_id=<?php echo intval($booking['booking_id']); ?>" target="_blank" rel="noopener">Preview</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" style="text-align:center; padding:2rem; color:#666;">No bookings found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; gap:12px; flex-wrap:wrap;">
        <div>Showing <?php echo $totalCount === 0 ? 0 : min($totalCount, $offset + 1); ?> - <?php echo min($totalCount, $offset + $per_page); ?> of <?php echo $totalCount; ?></div>
        <div class="pagination" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
            <?php
            $baseParams = [];
            if ($search_name) {
                $baseParams['customer_name'] = $search_name;
            }
            if ($start_date) {
                $baseParams['start_date'] = $start_date;
            }
            if ($end_date) {
                $baseParams['end_date'] = $end_date;
            }
            $baseQuery = http_build_query($baseParams);
            ?>
            <?php if ($page > 1): ?>
                <a class="btn" href="?<?php echo $baseQuery; ?>&page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?>">&laquo; Prev</a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="btn" href="?<?php echo $baseQuery; ?>&page=<?php echo $p; ?>&per_page=<?php echo $per_page; ?>" style="<?php echo $p === $page ? 'background:var(--primary-pink); color:#fff;' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn" href="?<?php echo $baseQuery; ?>&page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:30px; border-radius:10px; width:min(420px, calc(100% - 30px));">
        <h3>Update Booking Status</h3>
        <p id="edit_vehicle_hint" style="margin:8px 0 18px; color:#666; font-size:0.92rem;">Legacy bookings can still be assigned manually.</p>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="booking_id" id="edit_booking_id">

            <div class="form-group">
                <label for="edit_status">Status</label>
                <select id="edit_status" name="status" required>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="in_progress">In Progress</option>
                    <option value="denied">Denied</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="completed">Completed</option>
                </select>
            </div>

            <div class="form-group">
                <label for="edit_vehicle_id">Assign Vehicle</label>
                <select id="edit_vehicle_id" name="vehicle_id">
                    <option value="">Select Vehicle</option>
                    <?php
                    $vehicles = $conn->query("SELECT vehicle_id, vehicle_name, license_plate, vehicle_model, vehicle_type, seat_capacity FROM vehicles WHERE status = 'active' ORDER BY vehicle_name ASC");
                    while ($vehicle = $vehicles->fetch_assoc()):
                    ?>
                        <option value="<?php echo intval($vehicle['vehicle_id']); ?>"><?php echo htmlspecialchars(hz_vehicle_display_label($vehicle, true)); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
const bookingCustomerInput = document.getElementById('customer_name');
const bookingSuggestionBox = document.getElementById('bookingSuggestions');

function hideBookingSuggestions() {
    if (bookingSuggestionBox) {
        bookingSuggestionBox.hidden = true;
        bookingSuggestionBox.innerHTML = '';
    }
}

function renderBookingSuggestions(items) {
    if (!bookingSuggestionBox) {
        return;
    }

    if (!items.length) {
        hideBookingSuggestions();
        return;
    }

    const escapeHtml = function (value) {
        return value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    bookingSuggestionBox.innerHTML = items.map(function (item) {
        const safeItem = escapeHtml(item);
        return '<button type="button" class="autocomplete-item" data-name="' + safeItem + '">' + safeItem + '</button>';
    }).join('');
    bookingSuggestionBox.hidden = false;
}

function fetchBookingSuggestions(term) {
    if (!term || term.trim().length < 1) {
        hideBookingSuggestions();
        return;
    }

    fetch('bookings.php?suggest=customer&term=' + encodeURIComponent(term.trim()))
        .then(function (response) { return response.json(); })
        .then(function (data) {
            renderBookingSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
        })
        .catch(function () {
            hideBookingSuggestions();
        });
}

let bookingSuggestionTimer = null;
if (bookingCustomerInput) {
    bookingCustomerInput.addEventListener('input', function () {
        window.clearTimeout(bookingSuggestionTimer);
        const currentValue = this.value;
        bookingSuggestionTimer = window.setTimeout(function () {
            fetchBookingSuggestions(currentValue);
        }, 180);
    });

    bookingCustomerInput.addEventListener('focus', function () {
        if (this.value.trim() !== '') {
            fetchBookingSuggestions(this.value);
        }
    });
}

if (bookingSuggestionBox) {
    bookingSuggestionBox.addEventListener('click', function (event) {
        const trigger = event.target.closest('.autocomplete-item');
        if (!trigger || !bookingCustomerInput) {
            return;
        }

        bookingCustomerInput.value = trigger.dataset.name || trigger.textContent.trim();
        hideBookingSuggestions();
        bookingCustomerInput.form.submit();
    });
}

document.addEventListener('click', function (event) {
    if (!bookingSuggestionBox || !bookingCustomerInput) {
        return;
    }

    if (!bookingSuggestionBox.contains(event.target) && event.target !== bookingCustomerInput) {
        hideBookingSuggestions();
    }
});

function openEditModal(bookingId, status, vehicleId, tripId) {
    const vehicleField = document.getElementById('edit_vehicle_id');
    const vehicleHint = document.getElementById('edit_vehicle_hint');

    document.getElementById('edit_booking_id').value = bookingId;
    document.getElementById('edit_status').value = status;
    vehicleField.value = vehicleId || '';

    if (tripId) {
        vehicleField.disabled = true;
        vehicleHint.textContent = 'Scheduled trip bookings already inherit a vehicle from the selected trip.';
    } else {
        vehicleField.disabled = false;
        vehicleHint.textContent = 'Legacy bookings can still be assigned manually.';
    }

    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('edit_vehicle_id').disabled = false;
    document.getElementById('editModal').style.display = 'none';
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

<?php require_once 'footer.php'; ?>
