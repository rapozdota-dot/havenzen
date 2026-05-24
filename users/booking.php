<?php
require_once 'auth.php';
require_once '../lib/trip_helpers.php';

$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_date = date('Y-m-d', strtotime($selected_date));
$selected_route_id = intval($_GET['route_id'] ?? 0);
$selected_vehicle_id = intval($_GET['vehicle_id'] ?? 0);

$fareTierOptions = [
    60 => 'Short stop - 60% fare',
    80 => 'Mid route - 80% fare',
    100 => 'Full route - 100% fare',
];

function hz_booking_fare_tier_label(int $percent, array $options): string
{
    return $options[$percent] ?? $options[100];
}

hz_generate_trips_for_date($conn, $selected_date);
hz_expire_overdue_no_shows($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'book_trip') {
        $trip_id = intval($_POST['trip_id'] ?? 0);
        $baggage_count = max(0, intval($_POST['baggage_count'] ?? 0));
        $fare_tier_percent = intval($_POST['fare_tier_percent'] ?? 100);
        if (!array_key_exists($fare_tier_percent, $fareTierOptions)) {
            $fare_tier_percent = 100;
        }
        $fare_tier_label = $conn->real_escape_string(hz_booking_fare_tier_label($fare_tier_percent, $fareTierOptions));
        $notes = $conn->real_escape_string(trim($_POST['notes'] ?? ''));

        hz_expire_overdue_no_shows($conn, $trip_id);

        $tripQuery = $conn->query("
            SELECT
                vt.*,
                COALESCE(vt.driver_id, vs.driver_id, v.driver_id) AS driver_id,
                v.vehicle_name,
                v.license_plate,
                d.full_name AS driver_name,
                d.phone_number AS driver_phone,
                r.route_name,
                r.fare,
                r.stops
            FROM vehicle_trips vt
            LEFT JOIN vehicle_schedules vs ON vs.schedule_id = vt.schedule_id
            JOIN vehicles v ON v.vehicle_id = vt.vehicle_id
            LEFT JOIN drivers d ON d.user_id = COALESCE(vt.driver_id, vs.driver_id, v.driver_id)
            JOIN routes r ON r.route_id = vt.route_id
            WHERE vt.trip_id = {$trip_id}
            LIMIT 1
        ");

        if (!$tripQuery || $tripQuery->num_rows === 0) {
            $error_message = 'Selected trip was not found.';
        } else {
            $trip = $tripQuery->fetch_assoc();
            $availableSeats = hz_get_trip_available_seats($conn, $trip_id);
            $alreadyBookedQuery = $conn->query("
                SELECT booking_id
                FROM bookings
                WHERE trip_id = {$trip_id}
                  AND passenger_id = {$user_id}
                  AND status NOT IN ('cancelled', 'denied', 'completed')
                  AND boarding_status NOT IN ('no_show', 'dropped_off')
                LIMIT 1
            ");
            if ($availableSeats <= 0) {
                $error_message = 'That trip is already full. Please choose another departure.';
            } elseif (strtotime($trip['scheduled_departure_at']) <= time()) {
                $error_message = 'Booking is already closed because the departure time has been reached.';
            } elseif ($alreadyBookedQuery && $alreadyBookedQuery->num_rows > 0) {
                $error_message = 'You already reserved a seat for this trip.';
            } elseif ($trip['trip_status'] !== 'scheduled') {
                $error_message = 'That trip is no longer open for booking.';
            } else {
                $endpoints = hz_route_endpoints($trip);
                $pickup_location = $conn->real_escape_string($endpoints['origin']);
                $dropoff_location = $conn->real_escape_string($endpoints['destination']);
                $scheduled_departure_at = $conn->real_escape_string($trip['scheduled_departure_at']);
                $route_id = intval($trip['route_id']);
                $vehicle_id = intval($trip['vehicle_id']);
                $driver_id = !empty($trip['driver_id']) ? intval($trip['driver_id']) : 'NULL';
                $base_fare = floatval($trip['fare'] ?? 0);
                $travel_fare = round($base_fare * ($fare_tier_percent / 100), 2);
                $baggage_fee = $baggage_count * BAGGAGE_FEE_PER_BAG;
                $fare = $travel_fare + $baggage_fee;
                $seats_left_at_booking = max(0, $availableSeats - 1);

                $sql = "
                    INSERT INTO bookings (
                        passenger_id,
                        pickup_location,
                        dropoff_location,
                        status,
                        requested_time,
                        vehicle_id,
                        route_id,
                        trip_id,
                        scheduled_departure_at,
                        passenger_count,
                        baggage_count,
                        boarding_status,
                        notes,
                        fare,
                        fare_estimate,
                        seats_left_at_booking,
                        fare_tier_percent,
                        fare_tier_label,
                        driver_id
                    ) VALUES (
                        {$user_id},
                        '{$pickup_location}',
                        '{$dropoff_location}',
                        'confirmed',
                        '{$scheduled_departure_at}',
                        {$vehicle_id},
                        {$route_id},
                        {$trip_id},
                        '{$scheduled_departure_at}',
                        1,
                        {$baggage_count},
                        'scheduled',
                        '{$notes}',
                        {$fare},
                        {$fare},
                        {$seats_left_at_booking},
                        {$fare_tier_percent},
                        '{$fare_tier_label}',
                        {$driver_id}
                    )
                ";

                if ($conn->query($sql)) {
                    $booking_id = $conn->insert_id;
                    $success_message = 'Seat reserved successfully for ' . date('M j, Y g:i A', strtotime($trip['scheduled_departure_at'])) . ' on ' . $trip['vehicle_name'] . '. Total bill: PHP ' . number_format($fare, 2) . '. Seats left after booking: ' . $seats_left_at_booking . '.';
                    logCRUD($conn, $user_id, 'CREATE', 'bookings', $booking_id, 'Booked scheduled trip #' . $trip_id . '; Fare tier: ' . $fare_tier_percent . '%; Baggage: ' . $baggage_count . '; Seats left: ' . $seats_left_at_booking . '; Total bill: PHP ' . number_format($fare, 2));

                    if (!empty($trip['driver_id'])) {
                        hz_create_notification(
                            $conn,
                            intval($trip['driver_id']),
                            'scheduled_booking',
                            'A passenger booked a seat on ' . $trip['vehicle_name'] . ' for ' . date('M j, g:i A', strtotime($trip['scheduled_departure_at'])),
                            [
                                'booking_id' => $booking_id,
                                'trip_id' => $trip_id,
                                'passenger_id' => $user_id,
                            ]
                        );
                    }
                } else {
                    $error_message = 'Error creating booking: ' . $conn->error;
                }
            }
        }
    }

    if ($action === 'cancel_booking') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $bookingQuery = $conn->query("
            SELECT booking_id, status, boarding_status
            FROM bookings
            WHERE booking_id = {$booking_id}
              AND passenger_id = {$user_id}
            LIMIT 1
        ");

        if (!$bookingQuery || $bookingQuery->num_rows === 0) {
            $error_message = 'Booking not found.';
        } else {
            $booking = $bookingQuery->fetch_assoc();
            $canCancel = in_array($booking['status'], ['pending', 'confirmed'], true)
                && in_array($booking['boarding_status'], ['scheduled', 'vehicle_arrived'], true);

            if (!$canCancel) {
                $error_message = 'This booking can no longer be cancelled.';
            } else {
                if ($conn->query("
                    UPDATE bookings
                    SET status = 'cancelled',
                        boarding_status = 'no_show',
                        no_show_at = NOW()
                    WHERE booking_id = {$booking_id}
                ")) {
                    $success_message = 'Booking cancelled successfully.';
                    logCRUD($conn, $user_id, 'UPDATE', 'bookings', $booking_id, 'Passenger cancelled scheduled booking');
                } else {
                    $error_message = 'Error cancelling booking: ' . $conn->error;
                }
            }
        }
    }
}

$routeOptions = [];
$routeResult = $conn->query("
    SELECT route_id, route_name, fare, travel_minutes, stops
    FROM routes
    ORDER BY route_name ASC
");
if ($routeResult) {
    while ($row = $routeResult->fetch_assoc()) {
        $routeOptions[] = $row;
    }
}

$vehicleOptions = [];
$vehicleResult = $conn->query("
    SELECT vehicle_id, vehicle_name, seat_capacity
    FROM vehicles
    WHERE status = 'active'
    ORDER BY vehicle_name ASC
");
if ($vehicleResult) {
    while ($row = $vehicleResult->fetch_assoc()) {
        $vehicleOptions[] = $row;
    }
}

$tripSql = "
    SELECT
        vt.*,
        COALESCE(vt.driver_id, vs.driver_id, v.driver_id) AS driver_id,
        v.vehicle_name,
        v.license_plate,
        v.seat_capacity,
        d.full_name AS driver_name,
        d.phone_number AS driver_phone,
        r.route_name,
        r.fare,
        r.travel_minutes,
        r.stops
    FROM vehicle_trips vt
    LEFT JOIN vehicle_schedules vs ON vs.schedule_id = vt.schedule_id
    JOIN vehicles v ON v.vehicle_id = vt.vehicle_id
    LEFT JOIN drivers d ON d.user_id = COALESCE(vt.driver_id, vs.driver_id, v.driver_id)
    JOIN routes r ON r.route_id = vt.route_id
    WHERE DATE(vt.scheduled_departure_at) = '{$selected_date}'
      AND vt.trip_status = 'scheduled'
";

if ($selected_route_id > 0) {
    $tripSql .= " AND vt.route_id = {$selected_route_id}";
}
if ($selected_vehicle_id > 0) {
    $tripSql .= " AND vt.vehicle_id = {$selected_vehicle_id}";
}
if ($selected_date === date('Y-m-d')) {
    $tripSql .= " AND vt.scheduled_departure_at >= NOW()";
}

$tripSql .= " ORDER BY vt.scheduled_departure_at ASC";

$activeTripBookings = [];
$activeTripBookingResult = $conn->query("
    SELECT DISTINCT trip_id
    FROM bookings
    WHERE passenger_id = {$user_id}
      AND trip_id IS NOT NULL
      AND status NOT IN ('cancelled', 'denied', 'completed')
      AND boarding_status NOT IN ('no_show', 'dropped_off')
");
if ($activeTripBookingResult) {
    while ($row = $activeTripBookingResult->fetch_assoc()) {
        $activeTripBookings[intval($row['trip_id'])] = true;
    }
}

$availableTrips = [];
$tripResult = $conn->query($tripSql);
if ($tripResult) {
    while ($row = $tripResult->fetch_assoc()) {
        $row['metrics'] = hz_get_trip_metrics($conn, intval($row['trip_id']));
        if ($row['metrics']['available'] > 0) {
            $endpoints = hz_route_endpoints($row);
            $row['origin'] = $endpoints['origin'];
            $row['destination'] = $endpoints['destination'];
            $row['already_reserved'] = isset($activeTripBookings[intval($row['trip_id'])]);
            $availableTrips[] = $row;
        }
    }
}

$myBookings = [];
$bookingResult = $conn->query("
    SELECT
        b.*,
        v.vehicle_name,
        d.full_name AS driver_name,
        d.phone_number AS driver_phone,
        r.route_name
    FROM bookings b
    LEFT JOIN vehicles v ON v.vehicle_id = b.vehicle_id
    LEFT JOIN drivers d ON d.user_id = COALESCE(b.driver_id, v.driver_id)
    LEFT JOIN routes r ON r.route_id = b.route_id
    WHERE b.passenger_id = {$user_id}
    ORDER BY COALESCE(b.scheduled_departure_at, b.requested_time, b.created_at) DESC
    LIMIT 12
");
if ($bookingResult) {
    while ($row = $bookingResult->fetch_assoc()) {
        $myBookings[] = $row;
    }
}

require_once 'header.php';
?>

<style>
.trip-filters {
    background: var(--white);
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.trip-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.trip-card {
    background: var(--white);
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow);
    padding: 1.25rem;
    border: 1px solid var(--medium-gray);
}

.trip-card h3 {
    margin-bottom: 0.5rem;
    color: var(--dark-gray);
}

.trip-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin: 1rem 0;
}

.trip-meta span {
    display: block;
    font-size: 0.85rem;
    color: var(--text-color);
}

.trip-meta strong {
    color: var(--dark-gray);
}

.booking-history {
    background: var(--white);
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
}

.booking-history table {
    width: 100%;
}

.booking-row-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    align-items: center;
}

.booking-row-actions .btn {
    font-size: 0.85rem;
    padding: 0.375rem 0.75rem;
}

@media (max-width: 768px) {
    .trip-meta {
        grid-template-columns: 1fr;
    }

    .booking-row-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .booking-row-actions .btn,
    .booking-row-actions form {
        width: 100%;
    }
}
</style>

<div class="dashboard-header">
    <h1>Book Scheduled Trips</h1>
    <p>Choose a fixed vehicle departure, reserve one seat, and track availability before checkout.</p>
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

<div class="trip-filters">
    <form method="GET" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items:end;">
        <div class="form-group" style="margin-bottom:0;">
            <label for="date">Travel Date</label>
            <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label for="route_id">Route</label>
            <select id="route_id" name="route_id">
                <option value="0">All routes</option>
                <?php foreach ($routeOptions as $route): ?>
                    <option value="<?php echo intval($route['route_id']); ?>" <?php echo intval($route['route_id']) === $selected_route_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($route['route_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label for="vehicle_id">Vehicle</label>
            <select id="vehicle_id" name="vehicle_id">
                <option value="0">Any vehicle</option>
                <?php foreach ($vehicleOptions as $vehicle): ?>
                    <option value="<?php echo intval($vehicle['vehicle_id']); ?>" <?php echo intval($vehicle['vehicle_id']) === $selected_vehicle_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($vehicle['vehicle_name'] . ' (' . intval($vehicle['seat_capacity']) . ' seats)'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <button type="submit" class="btn btn-primary">Find Trips</button>
        </div>
    </form>
</div>

<div class="section-header">
    <h2>Available Trips For <?php echo date('M j, Y', strtotime($selected_date)); ?></h2>
</div>

<div class="trip-grid">
    <?php if (!$availableTrips): ?>
        <div class="empty-state" style="grid-column: 1 / -1;">
            <div class="empty-state-icon"><i class="fas fa-calendar-times"></i></div>
            <h3>No scheduled trips found</h3>
            <p>Try another route, vehicle, or travel date.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($availableTrips as $trip): ?>
        <div class="trip-card">
            <h3><?php echo htmlspecialchars($trip['route_name']); ?></h3>
            <p><strong><?php echo htmlspecialchars($trip['vehicle_name']); ?></strong> &bull; Plate <?php echo htmlspecialchars($trip['license_plate']); ?></p>
            <p>
                <strong>Driver:</strong>
                <?php if (!empty($trip['driver_name'])): ?>
                    <?php echo htmlspecialchars($trip['driver_name']); ?>
                    <?php if (!empty($trip['driver_phone'])): ?>
                        <span style="color: var(--text-color); opacity: 0.75;">(<?php echo htmlspecialchars($trip['driver_phone']); ?>)</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color: var(--text-color); opacity: 0.75;">To be assigned by admin</span>
                <?php endif; ?>
            </p>
            <div class="trip-meta">
                <div>
                    <span>Departure</span>
                    <strong><?php echo date('M j, g:i A', strtotime($trip['scheduled_departure_at'])); ?></strong>
                </div>
                <div>
                    <span>Travel Time</span>
                    <strong><?php echo intval($trip['travel_minutes'] ?? 0); ?> mins</strong>
                </div>
                <div>
                    <span>Route</span>
                    <strong><?php echo htmlspecialchars($trip['origin']); ?> to <?php echo htmlspecialchars($trip['destination']); ?></strong>
                </div>
                <div>
                    <span>Seat Availability</span>
                    <strong><?php echo intval($trip['metrics']['available']); ?> / <?php echo intval($trip['metrics']['capacity']); ?> seats left</strong>
                </div>
                <div>
                    <span>Reserved Seats</span>
                    <strong><?php echo intval($trip['metrics']['reserved']); ?></strong>
                </div>
                <div>
                    <span>Fare</span>
                    <strong>PHP <?php echo number_format((float) $trip['fare'], 2); ?></strong>
                </div>
                <div>
                    <span>Baggage Fee</span>
                    <strong>PHP <?php echo number_format((float) BAGGAGE_FEE_PER_BAG, 2); ?> / bag</strong>
                </div>
                <div>
                    <span>Booking Cutoff</span>
                    <strong title="Once the scheduled departure time is reached, this seat can no longer be reserved.">Closes at departure</strong>
                </div>
            </div>
            <form method="POST"
                  onsubmit="return confirmTripBooking(this);"
                  data-route="<?php echo htmlspecialchars($trip['route_name'], ENT_QUOTES); ?>"
                  data-departure="<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($trip['scheduled_departure_at'])), ENT_QUOTES); ?>"
                  data-vehicle="<?php echo htmlspecialchars($trip['vehicle_name'] . ' (' . $trip['license_plate'] . ')', ENT_QUOTES); ?>"
                  data-driver="<?php echo htmlspecialchars(!empty($trip['driver_name']) ? $trip['driver_name'] : 'To be assigned by admin', ENT_QUOTES); ?>"
                  data-fare="<?php echo htmlspecialchars(number_format((float) $trip['fare'], 2, '.', ''), ENT_QUOTES); ?>"
                  data-baggage-fee="<?php echo intval(BAGGAGE_FEE_PER_BAG); ?>"
                  data-seats="<?php echo intval($trip['metrics']['available']); ?> / <?php echo intval($trip['metrics']['capacity']); ?> seats left"
                  data-seats-left-after="<?php echo max(0, intval($trip['metrics']['available']) - 1); ?>">
                <input type="hidden" name="action" value="book_trip">
                <input type="hidden" name="trip_id" value="<?php echo intval($trip['trip_id']); ?>">
                <div class="form-group">
                    <label for="fare_tier_percent_<?php echo intval($trip['trip_id']); ?>">Stop / Fare Option</label>
                    <select id="fare_tier_percent_<?php echo intval($trip['trip_id']); ?>" name="fare_tier_percent">
                        <?php foreach ($fareTierOptions as $percent => $label): ?>
                            <option value="<?php echo intval($percent); ?>" <?php echo intval($percent) === 100 ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label . ' - PHP ' . number_format(((float) $trip['fare']) * ($percent / 100), 2)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small title="Choose the fare tier that matches where you plan to stop along the route.">Shorter stops can use 60% or 80%; full route uses 100%.</small>
                </div>
                <div class="form-group">
                    <label for="baggage_count_<?php echo intval($trip['trip_id']); ?>">Baggage Count (PHP <?php echo number_format((float) BAGGAGE_FEE_PER_BAG, 2); ?> each)</label>
                    <input type="number" id="baggage_count_<?php echo intval($trip['trip_id']); ?>" name="baggage_count" min="0" value="0">
                </div>
                <div class="form-group">
                    <label for="notes_<?php echo intval($trip['trip_id']); ?>">Notes</label>
                    <textarea id="notes_<?php echo intval($trip['trip_id']); ?>" name="notes" rows="3" placeholder="Optional rider note"></textarea>
                </div>
                <?php if (!empty($trip['already_reserved'])): ?>
                    <button type="button" class="btn btn-secondary" style="width:100%; cursor:not-allowed;" disabled title="You already reserved a seat on this trip. Check My Recent Scheduled Bookings below.">Seat Already Reserved</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary" style="width:100%;" title="Booking closes once the scheduled departure time is reached.">Reserve Seat</button>
                <?php endif; ?>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="booking-history">
    <div class="section-header" style="margin-bottom: 1rem;">
        <h2>My Recent Scheduled Bookings</h2>
    </div>
    <div class="table-container" style="box-shadow:none; border:1px solid var(--medium-gray);">
        <table class="responsive-table">
            <thead>
                <tr>
                    <th>Departure</th>
                    <th>Route</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Baggage</th>
                    <th>Total Bill</th>
                    <th>Status</th>
                    <th>Boarding</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$myBookings): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:2rem;">No bookings yet.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($myBookings as $booking): ?>
                    <tr>
                        <td data-label="Departure">
                            <?php
                            $departure = $booking['scheduled_departure_at'] ?: $booking['requested_time'];
                            echo $departure ? date('M j, Y g:i A', strtotime($departure)) : 'N/A';
                            ?>
                        </td>
                        <td data-label="Route"><?php echo htmlspecialchars($booking['route_name'] ?: ($booking['pickup_location'] . ' to ' . $booking['dropoff_location'])); ?></td>
                        <td data-label="Vehicle"><?php echo htmlspecialchars($booking['vehicle_name'] ?: 'Unassigned'); ?></td>
                        <td data-label="Driver">
                            <?php if (!empty($booking['driver_name'])): ?>
                                <?php echo htmlspecialchars($booking['driver_name']); ?>
                                <?php if (!empty($booking['driver_phone'])): ?>
                                    <br><small><?php echo htmlspecialchars($booking['driver_phone']); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--text-color); opacity: 0.75;">To be assigned</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Baggage">
                            <?php
                            $bookingBaggageCount = intval($booking['baggage_count'] ?? 0);
                            echo $bookingBaggageCount;
                            if ($bookingBaggageCount > 0) {
                                echo '<br><small>PHP ' . number_format($bookingBaggageCount * BAGGAGE_FEE_PER_BAG, 2) . '</small>';
                            }
                            ?>
                        </td>
                        <td data-label="Total Bill">PHP <?php echo number_format((float) ($booking['fare'] ?: $booking['fare_estimate']), 2); ?></td>
                        <td data-label="Status"><span class="status-badge status-<?php echo htmlspecialchars($booking['status']); ?>"><?php echo ucwords(str_replace('_', ' ', $booking['status'])); ?></span></td>
                        <td data-label="Boarding"><span class="status-badge status-<?php echo htmlspecialchars($booking['boarding_status'] ?? 'scheduled'); ?>"><?php echo ucwords(str_replace('_', ' ', $booking['boarding_status'] ?? 'scheduled')); ?></span></td>
                        <td data-label="Action">
                            <?php
                            $canCancel = in_array($booking['status'], ['pending', 'confirmed'], true)
                                && in_array($booking['boarding_status'] ?? 'scheduled', ['scheduled', 'vehicle_arrived'], true);
                            $canTrack = !empty($booking['vehicle_id'])
                                && in_array($booking['status'], ['confirmed', 'in_progress', 'completed'], true);
                            ?>
                            <?php if ($canTrack || $canCancel): ?>
                                <div class="booking-row-actions">
                                    <?php if ($canTrack): ?>
                                        <a class="btn btn-secondary" href="tracking.php?booking_id=<?php echo intval($booking['booking_id']); ?>">
                                            <i class="fas fa-location-dot"></i> Track Van
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canCancel): ?>
                                        <form method="POST" onsubmit="return confirm('Cancel this booking?');">
                                            <input type="hidden" name="action" value="cancel_booking">
                                            <input type="hidden" name="booking_id" value="<?php echo intval($booking['booking_id']); ?>">
                                            <button type="submit" class="btn btn-danger">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--text-color); opacity: 0.75;">Locked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmTripBooking(form) {
    const baggageInput = form.querySelector('input[name="baggage_count"]');
    const fareTierInput = form.querySelector('select[name="fare_tier_percent"]');
    const baggageCount = baggageInput ? Math.max(0, parseInt(baggageInput.value || '0', 10)) : 0;
    const baseFare = parseFloat(form.dataset.fare || '0') || 0;
    const fareTierPercent = fareTierInput ? Math.max(0, parseInt(fareTierInput.value || '100', 10)) : 100;
    const fareTierLabel = fareTierInput && fareTierInput.selectedOptions.length ? fareTierInput.selectedOptions[0].textContent.trim() : 'Full route';
    const travelFare = baseFare * (fareTierPercent / 100);
    const baggageFeePerBag = parseFloat(form.dataset.baggageFee || '0') || 0;
    const baggageFee = baggageCount * baggageFeePerBag;
    const totalBill = travelFare + baggageFee;
    const details = [
        'Please confirm your scheduled seat booking:',
        '',
        'Route: ' + (form.dataset.route || 'Selected route'),
        'Departure: ' + (form.dataset.departure || 'Selected departure'),
        'Vehicle: ' + (form.dataset.vehicle || 'Assigned vehicle'),
        'Driver: ' + (form.dataset.driver || 'Assigned driver'),
        'Fare option: ' + fareTierLabel,
        'Travel fare: PHP ' + travelFare.toFixed(2),
        'Seat availability: ' + (form.dataset.seats || 'Available'),
        'Seats left after booking: ' + (form.dataset.seatsLeftAfter || 'N/A'),
        'Baggage count: ' + baggageCount,
        'Baggage fee: PHP ' + baggageFee.toFixed(2),
        'Total bill: PHP ' + totalBill.toFixed(2),
        '',
        'Continue with this booking?'
    ];

    return confirm(details.join('\n'));
}
</script>

<?php require_once 'footer.php'; ?>
