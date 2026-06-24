<?php
require_once 'auth.php';
require_once '../lib/trip_helpers.php';
require_once '../lib/vehicle_helpers.php';

$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_date = date('Y-m-d', strtotime($selected_date));
$vehicle_id = intval($driver_data['vehicle_id'] ?? 0);
$driver_user_id = intval($_SESSION['user_id'] ?? 0);

hz_generate_trips_for_date($conn, $selected_date);
hz_expire_overdue_no_shows($conn);

function driver_trip_belongs_to_vehicle($conn, $tripId, $vehicleId)
{
    $result = $conn->query("
        SELECT trip_id
        FROM vehicle_trips
        WHERE trip_id = " . intval($tripId) . "
          AND vehicle_id = " . intval($vehicleId) . "
        LIMIT 1
    ");

    return $result && $result->num_rows === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $vehicle_id > 0) {
    $action = $_POST['action'] ?? '';

    if ($action === 'report_arrived') {
        $trip_id = intval($_POST['trip_id'] ?? 0);
        if (driver_trip_belongs_to_vehicle($conn, $trip_id, $vehicle_id)) {
            $conn->query("
                UPDATE vehicle_trips
                SET trip_status = 'boarding',
                    arrival_reported_at = NOW()
                WHERE trip_id = {$trip_id}
                  AND trip_status = 'scheduled'
            ");

            $conn->query("
                UPDATE bookings
                SET boarding_status = 'vehicle_arrived',
                    arrival_reported_at = NOW(),
                    boarding_deadline_at = DATE_ADD(NOW(), INTERVAL 4 MINUTE),
                    driver_id = {$driver_user_id}
                WHERE trip_id = {$trip_id}
                  AND status IN ('pending', 'confirmed')
                  AND boarding_status = 'scheduled'
            ");

            $notifyResult = $conn->query("
                SELECT booking_id, passenger_id
                FROM bookings
                WHERE trip_id = {$trip_id}
                  AND boarding_status = 'vehicle_arrived'
            ");
            if ($notifyResult) {
                while ($notifyRow = $notifyResult->fetch_assoc()) {
                    hz_create_notification(
                        $conn,
                        intval($notifyRow['passenger_id']),
                        'vehicle_arrived',
                        'Your vehicle has arrived. Please board within 4 minutes.',
                        [
                            'booking_id' => intval($notifyRow['booking_id']),
                            'trip_id' => $trip_id,
                        ]
                    );
                }
            }

            $success_message = 'Trip marked as arrived. Passenger no-show countdown has started.';
            logCRUD($conn, $driver_user_id, 'UPDATE', 'vehicle_trips', $trip_id, 'Driver reported vehicle arrival');
        }
    }

    if ($action === 'start_trip') {
        $trip_id = intval($_POST['trip_id'] ?? 0);
        if (driver_trip_belongs_to_vehicle($conn, $trip_id, $vehicle_id)) {
            $conn->query("
                UPDATE vehicle_trips
                SET trip_status = 'in_transit',
                    actual_departure_at = COALESCE(actual_departure_at, NOW())
                WHERE trip_id = {$trip_id}
                  AND trip_status IN ('scheduled', 'boarding')
            ");
            $success_message = 'Trip started successfully.';
            logCRUD($conn, $driver_user_id, 'UPDATE', 'vehicle_trips', $trip_id, 'Driver started trip');
        }
    }

    if ($action === 'complete_trip') {
        $trip_id = intval($_POST['trip_id'] ?? 0);
        if (driver_trip_belongs_to_vehicle($conn, $trip_id, $vehicle_id)) {
            hz_expire_overdue_no_shows($conn, $trip_id);
            $activeBookings = $conn->query("
                SELECT COUNT(*) AS total
                FROM bookings
                WHERE trip_id = {$trip_id}
                  AND status NOT IN ('cancelled', 'denied', 'completed')
                  AND boarding_status NOT IN ('no_show', 'dropped_off')
            ");
            $activeCount = 0;
            if ($activeBookings) {
                $activeRow = $activeBookings->fetch_assoc();
                $activeCount = intval($activeRow['total'] ?? 0);
            }

            if ($activeCount > 0) {
                $error_message = 'Finish all passenger actions before completing the trip.';
            } else {
                $conn->query("
                    UPDATE vehicle_trips
                    SET trip_status = 'completed',
                        completed_at = NOW()
                    WHERE trip_id = {$trip_id}
                ");
                $success_message = 'Trip completed successfully.';
                logCRUD($conn, $driver_user_id, 'UPDATE', 'vehicle_trips', $trip_id, 'Driver completed trip');
            }
        }
    }

    if ($action === 'board_passenger') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $trip_id = intval($_POST['trip_id'] ?? 0);
        if (driver_trip_belongs_to_vehicle($conn, $trip_id, $vehicle_id)) {
            $bookingResult = $conn->query("
                SELECT boarding_deadline_at, boarding_status
                FROM bookings
                WHERE booking_id = {$booking_id}
                  AND trip_id = {$trip_id}
                LIMIT 1
            ");
            if ($bookingResult && $bookingResult->num_rows === 1) {
                $booking = $bookingResult->fetch_assoc();
                $deadlineExpired = !empty($booking['boarding_deadline_at']) && strtotime($booking['boarding_deadline_at']) < time();
                if ($deadlineExpired) {
                    $error_message = 'Boarding window already expired for this passenger.';
                } else {
                    $conn->query("
                        UPDATE bookings
                        SET status = 'in_progress',
                            boarding_status = 'boarded',
                            boarded_at = NOW(),
                            driver_id = {$driver_user_id}
                        WHERE booking_id = {$booking_id}
                          AND trip_id = {$trip_id}
                          AND boarding_status IN ('scheduled', 'vehicle_arrived')
                    ");
                    $success_message = 'Passenger boarded successfully.';
                    logCRUD($conn, $driver_user_id, 'UPDATE', 'bookings', $booking_id, 'Passenger boarded scheduled trip');
                }
            }
        }
    }

    if ($action === 'mark_no_show') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $trip_id = intval($_POST['trip_id'] ?? 0);
        if (driver_trip_belongs_to_vehicle($conn, $trip_id, $vehicle_id)) {
            $conn->query("
                UPDATE bookings
                SET status = 'cancelled',
                    boarding_status = 'no_show',
                    no_show_at = NOW(),
                    driver_id = {$driver_user_id}
                WHERE booking_id = {$booking_id}
                  AND trip_id = {$trip_id}
                  AND boarding_status IN ('scheduled', 'vehicle_arrived')
            ");
            $success_message = 'Passenger marked as no-show.';
            logCRUD($conn, $driver_user_id, 'UPDATE', 'bookings', $booking_id, 'Passenger marked as no-show');
        }
    }

    if ($action === 'drop_off_passenger') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $trip_id = intval($_POST['trip_id'] ?? 0);
        if (driver_trip_belongs_to_vehicle($conn, $trip_id, $vehicle_id)) {
            $conn->query("
                UPDATE bookings
                SET status = 'completed',
                    boarding_status = 'dropped_off',
                    dropped_off_at = NOW(),
                    driver_id = {$driver_user_id}
                WHERE booking_id = {$booking_id}
                  AND trip_id = {$trip_id}
                  AND boarding_status = 'boarded'
            ");
            hz_create_driver_earning_if_missing($conn, $booking_id);
            $success_message = 'Passenger drop-off recorded successfully.';
            logCRUD($conn, $driver_user_id, 'UPDATE', 'bookings', $booking_id, 'Passenger dropped off');
        }
    }
}

$tripStats = [
    'scheduled' => 0,
    'boarding' => 0,
    'in_transit' => 0,
    'completed' => 0,
];

$trips = [];
if ($vehicle_id > 0) {
    $tripResult = $conn->query("
        SELECT
            vt.*,
            r.route_name,
            v.vehicle_name,
            v.license_plate,
            v.vehicle_model,
            v.vehicle_type
        FROM vehicle_trips vt
        JOIN routes r ON r.route_id = vt.route_id
        JOIN vehicles v ON v.vehicle_id = vt.vehicle_id
        WHERE vt.vehicle_id = {$vehicle_id}
          AND DATE(vt.scheduled_departure_at) = '{$selected_date}'
        ORDER BY vt.scheduled_departure_at ASC, vt.direction ASC
    ");

    if ($tripResult) {
        while ($trip = $tripResult->fetch_assoc()) {
            $metrics = hz_get_trip_metrics($conn, intval($trip['trip_id']));
            $trip['metrics'] = $metrics;
            $tripStats[$trip['trip_status']] = ($tripStats[$trip['trip_status']] ?? 0) + 1;

            $bookingRows = [];
            $bookingResult = $conn->query("
                SELECT
                    b.*,
                    c.full_name,
                    c.phone_number
                FROM bookings b
                JOIN customers c ON c.user_id = b.passenger_id
                WHERE b.trip_id = " . intval($trip['trip_id']) . "
                ORDER BY
                    CASE
                        WHEN b.boarding_status = 'boarded' THEN 1
                        WHEN b.boarding_status = 'vehicle_arrived' THEN 2
                        WHEN b.boarding_status = 'scheduled' THEN 3
                        WHEN b.boarding_status = 'dropped_off' THEN 4
                        WHEN b.boarding_status = 'no_show' THEN 5
                        ELSE 6
                    END,
                    b.created_at ASC
            ");

            if ($bookingResult) {
                while ($booking = $bookingResult->fetch_assoc()) {
                    $bookingRows[] = $booking;
                }
            }

            $trip['bookings'] = $bookingRows;
            $trips[] = $trip;
        }
    }
}

require_once 'header.php';
?>

<style>
.trip-ops-grid {
    display: grid;
    gap: 1.5rem;
}

.trip-ops-card {
    background: var(--white);
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow);
    border: 1px solid var(--medium-gray);
    overflow: hidden;
}

.trip-ops-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--medium-gray);
    background: var(--light-gray);
}

.trip-ops-body {
    padding: 1.25rem;
}

.trip-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.metric-box {
    padding: 0.9rem;
    border-radius: var(--border-radius-sm);
    background: #fafafa;
    border: 1px solid var(--medium-gray);
}

.passenger-list {
    display: grid;
    gap: 0.9rem;
}

.passenger-card {
    border: 1px solid var(--medium-gray);
    border-radius: var(--border-radius-sm);
    padding: 1rem;
    background: #fff;
}

.passenger-card form {
    display: inline-flex;
    margin-right: 0.5rem;
    margin-top: 0.5rem;
}

@media (max-width: 768px) {
    .passenger-card form {
        display: flex;
        width: 100%;
        margin-right: 0;
    }

    .passenger-card form .btn {
        width: 100%;
    }
}
</style>

<div class="dashboard-header">
    <h1>Trip Operations</h1>
    <p>Manage today's scheduled departures, boarding windows, no-shows, and completed drop-offs.</p>
</div>

<?php if (isset($success_message)): ?>
    <div class="notification success">
        <div class="notification-content">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="notification error">
        <div class="notification-content">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
    </div>
<?php endif; ?>

<?php if ($vehicle_id <= 0): ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-bus"></i></div>
        <h3>No assigned vehicle</h3>
        <p>You need an assigned vehicle before you can manage scheduled trips.</p>
    </div>
<?php else: ?>
    <div class="action-cards">
        <div class="action-card">
            <div class="action-content">
                <h3>Today's Trip Summary</h3>
                <div class="booking-details" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
                    <div class="detail-item">
                        <span class="detail-label">Scheduled</span>
                        <span class="detail-value"><?php echo $tripStats['scheduled']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Boarding</span>
                        <span class="detail-value"><?php echo $tripStats['boarding']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">In Transit</span>
                        <span class="detail-value"><?php echo $tripStats['in_transit']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Completed</span>
                        <span class="detail-value"><?php echo $tripStats['completed']; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="section-header">
        <h2><?php echo htmlspecialchars(hz_vehicle_display_label($driver_data)); ?> Trips</h2>
        <div class="section-actions">
            <form method="GET" style="display:flex; gap:10px; align-items:center;">
                <label for="date"><strong>Date</strong></label>
                <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <button type="submit" class="btn btn-secondary">Load Trips</button>
            </form>
        </div>
    </div>

    <div class="trip-ops-grid">
        <?php if (!$trips): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-calendar-xmark"></i></div>
                <h3>No trips for this date</h3>
                <p>No departures are scheduled yet for <?php echo date('M j, Y', strtotime($selected_date)); ?>.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($trips as $trip): ?>
            <div class="trip-ops-card">
                <div class="trip-ops-header">
                    <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;"><?php echo htmlspecialchars($trip['route_name']); ?></h3>
                            <small><?php echo htmlspecialchars(hz_vehicle_detail_line($trip)); ?> &bull; <?php echo date('M j, Y g:i A', strtotime($trip['scheduled_departure_at'])); ?> &bull; <?php echo ucfirst($trip['direction']); ?></small>
                        </div>
                        <span class="status-badge status-<?php echo htmlspecialchars($trip['trip_status']); ?>">
                            <?php echo ucwords(str_replace('_', ' ', $trip['trip_status'])); ?>
                        </span>
                    </div>
                </div>
                <div class="trip-ops-body">
                    <div class="trip-metrics">
                        <div class="metric-box">
                            <span class="detail-label">Seat Capacity</span>
                            <div class="detail-value"><?php echo intval($trip['metrics']['capacity']); ?></div>
                        </div>
                        <div class="metric-box">
                            <span class="detail-label">Reserved</span>
                            <div class="detail-value"><?php echo intval($trip['metrics']['reserved']); ?></div>
                        </div>
                        <div class="metric-box">
                            <span class="detail-label">Boarded</span>
                            <div class="detail-value"><?php echo intval($trip['metrics']['boarded']); ?></div>
                        </div>
                        <div class="metric-box">
                            <span class="detail-label">Available</span>
                            <div class="detail-value"><?php echo intval($trip['metrics']['available']); ?></div>
                        </div>
                    </div>

                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1rem;">
                        <?php if ($trip['trip_status'] === 'scheduled'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="report_arrived">
                                <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                                <button type="submit" class="btn btn-secondary">Report Arrived</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($trip['trip_status'], ['scheduled', 'boarding'], true)): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="start_trip">
                                <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                                <button type="submit" class="btn btn-primary">Start Trip</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($trip['trip_status'], ['boarding', 'in_transit'], true)): ?>
                            <form method="POST" onsubmit="return confirm('Complete this trip?');">
                                <input type="hidden" name="action" value="complete_trip">
                                <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                                <button type="submit" class="btn btn-success">Complete Trip</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <h4 style="margin-bottom:0.75rem;">Passenger List</h4>
                    <div class="passenger-list">
                        <?php if (!$trip['bookings']): ?>
                            <div class="passenger-card">No passengers booked for this trip yet.</div>
                        <?php endif; ?>
                        <?php foreach ($trip['bookings'] as $booking): ?>
                            <div class="passenger-card">
                                <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($booking['full_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($booking['phone_number'] ?? 'No phone'); ?></small>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="status-badge status-<?php echo htmlspecialchars($booking['boarding_status'] ?? 'scheduled'); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $booking['boarding_status'] ?? 'scheduled')); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="booking-details" style="margin-top:0.75rem;">
                                    <div class="detail-item">
                                        <span class="detail-label">Booking Status</span>
                                        <span class="detail-value"><?php echo ucwords(str_replace('_', ' ', $booking['status'])); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Baggage</span>
                                        <span class="detail-value"><?php echo intval($booking['baggage_count'] ?? 0); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Boarding Deadline</span>
                                        <span class="detail-value">
                                            <?php echo !empty($booking['boarding_deadline_at']) ? date('g:i A', strtotime($booking['boarding_deadline_at'])) : 'Not started'; ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Fare</span>
                                        <span class="detail-value">PHP <?php echo number_format((float) ($booking['fare'] ?: $booking['fare_estimate']), 2); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Pickup / Destination Notes</span>
                                        <span class="detail-value"><?php echo !empty($booking['notes']) ? htmlspecialchars($booking['notes']) : 'No passenger note'; ?></span>
                                    </div>
                                </div>

                                <?php if (in_array($booking['boarding_status'], ['scheduled', 'vehicle_arrived'], true) && !in_array($booking['status'], ['cancelled', 'completed', 'denied'], true)): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="board_passenger">
                                        <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo intval($booking['booking_id']); ?>">
                                        <button type="submit" class="btn btn-primary">Board Passenger</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Mark this passenger as no-show?');">
                                        <input type="hidden" name="action" value="mark_no_show">
                                        <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo intval($booking['booking_id']); ?>">
                                        <button type="submit" class="btn btn-danger">Mark No Show</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (($booking['boarding_status'] ?? '') === 'boarded'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="drop_off_passenger">
                                        <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo intval($booking['booking_id']); ?>">
                                        <button type="submit" class="btn btn-success">Drop Off Passenger</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
