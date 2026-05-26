<?php

function hz_normalize_day_name($day)
{
    $day = strtolower(trim((string) $day));
    $map = [
        'mon' => 'monday',
        'monday' => 'monday',
        'tue' => 'tuesday',
        'tues' => 'tuesday',
        'tuesday' => 'tuesday',
        'wed' => 'wednesday',
        'wednesday' => 'wednesday',
        'thu' => 'thursday',
        'thur' => 'thursday',
        'thurs' => 'thursday',
        'thursday' => 'thursday',
        'fri' => 'friday',
        'friday' => 'friday',
        'sat' => 'saturday',
        'saturday' => 'saturday',
        'sun' => 'sunday',
        'sunday' => 'sunday',
    ];

    return $map[$day] ?? '';
}

function hz_get_day_name($date)
{
    return hz_normalize_day_name(date('l', strtotime($date)));
}

function hz_decode_active_days($rawDays)
{
    if ($rawDays === null || $rawDays === '') {
        return [];
    }

    $decoded = json_decode($rawDays, true);
    if (is_array($decoded)) {
        $days = $decoded;
    } else {
        $days = explode(',', (string) $rawDays);
    }

    $normalized = [];
    foreach ($days as $day) {
        $value = hz_normalize_day_name($day);
        if ($value !== '' && !in_array($value, $normalized, true)) {
            $normalized[] = $value;
        }
    }

    return $normalized;
}

function hz_encode_active_days($days)
{
    $normalized = [];
    foreach ((array) $days as $day) {
        $value = hz_normalize_day_name($day);
        if ($value !== '' && !in_array($value, $normalized, true)) {
            $normalized[] = $value;
        }
    }

    return json_encode(array_values($normalized));
}

function hz_table_has_column($conn, $table, $column)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");

    return $result && $result->num_rows > 0;
}

function hz_ensure_schedule_driver_columns($conn)
{
    static $checked = false;
    if ($checked) {
        return true;
    }

    if (!hz_table_has_column($conn, 'vehicle_schedules', 'driver_id')) {
        $conn->query('ALTER TABLE vehicle_schedules ADD COLUMN driver_id INT NULL');
    }

    if (!hz_table_has_column($conn, 'vehicle_trips', 'driver_id')) {
        $conn->query('ALTER TABLE vehicle_trips ADD COLUMN driver_id INT NULL');
    }

    $checked = true;
    return true;
}

function hz_route_place_label(string $place): string
{
    $place = trim(preg_replace('/\s+/', ' ', $place));
    $place = preg_replace('/,\s*(Philippines|PH)\s*$/i', '', $place);
    return trim($place);
}

function hz_route_endpoints($routeRow)
{
    $stops = json_decode($routeRow['stops'] ?? '[]', true);
    if (!is_array($stops) || count($stops) < 2) {
        $routeName = hz_route_place_label((string) ($routeRow['route_name'] ?? 'Route'));
        return [
            'origin' => $routeName,
            'destination' => $routeName,
        ];
    }

    return [
        'origin' => hz_route_place_label((string) ($stops[0]['name'] ?? ($routeRow['route_name'] ?? 'Route'))),
        'destination' => hz_route_place_label((string) ($stops[count($stops) - 1]['name'] ?? ($routeRow['route_name'] ?? 'Route'))),
    ];
}

function hz_generate_trips_for_date($conn, $date)
{
    hz_ensure_schedule_driver_columns($conn);

    $targetDate = date('Y-m-d', strtotime($date));
    $dayName = hz_get_day_name($targetDate);

    $sql = "
        SELECT
            vs.*,
            v.seat_capacity,
            v.vehicle_name,
            v.driver_id AS vehicle_driver_id,
            r.route_name,
            r.travel_minutes,
            rr.travel_minutes AS return_travel_minutes
        FROM vehicle_schedules vs
        JOIN vehicles v ON v.vehicle_id = vs.vehicle_id
        JOIN routes r ON r.route_id = vs.route_id
        LEFT JOIN routes rr ON rr.route_id = vs.return_route_id
        WHERE vs.is_active = 1
          AND v.status = 'active'
        ORDER BY vs.departure_time ASC, vs.schedule_id ASC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return false;
    }

    while ($schedule = $result->fetch_assoc()) {
        $activeDays = hz_decode_active_days($schedule['active_days'] ?? '[]');
        if (!in_array($dayName, $activeDays, true)) {
            continue;
        }

        $seatCapacity = max(0, intval($schedule['seat_capacity'] ?? 0));
        $driverId = !empty($schedule['driver_id'])
            ? intval($schedule['driver_id'])
            : (!empty($schedule['vehicle_driver_id']) ? intval($schedule['vehicle_driver_id']) : null);
        $outboundDeparture = $targetDate . ' ' . $schedule['departure_time'];
        hz_upsert_trip(
            $conn,
            intval($schedule['schedule_id']),
            intval($schedule['vehicle_id']),
            intval($schedule['route_id']),
            'outbound',
            $outboundDeparture,
            $seatCapacity,
            $driverId
        );

        if (!empty($schedule['return_route_id'])) {
            $outboundTravelMinutes = max(1, intval($schedule['travel_minutes'] ?? 0));
            $layoverMinutes = max(0, intval($schedule['layover_minutes'] ?? 0));
            $returnTravelMinutes = max(1, intval($schedule['return_travel_minutes'] ?? $outboundTravelMinutes));
            $returnDepartureTs = strtotime($outboundDeparture . " +{$outboundTravelMinutes} minutes +{$layoverMinutes} minutes");

            hz_upsert_trip(
                $conn,
                intval($schedule['schedule_id']),
                intval($schedule['vehicle_id']),
                intval($schedule['return_route_id']),
                'return',
                date('Y-m-d H:i:s', $returnDepartureTs),
                $seatCapacity,
                $driverId
            );

            unset($returnTravelMinutes);
        }
    }

    return true;
}

function hz_generate_trips_for_range($conn, $dateFrom, $dateTo)
{
    $start = strtotime(date('Y-m-d', strtotime($dateFrom)));
    $end = strtotime(date('Y-m-d', strtotime($dateTo)));

    if ($start === false || $end === false || $end < $start) {
        return false;
    }

    for ($cursor = $start; $cursor <= $end; $cursor += 86400) {
        hz_generate_trips_for_date($conn, date('Y-m-d', $cursor));
    }

    return true;
}

function hz_upsert_trip($conn, $scheduleId, $vehicleId, $routeId, $direction, $scheduledDepartureAt, $seatCapacitySnapshot, $driverId = null)
{
    $sql = "
        INSERT INTO vehicle_trips (
            schedule_id,
            vehicle_id,
            driver_id,
            route_id,
            direction,
            scheduled_departure_at,
            seat_capacity_snapshot
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            vehicle_id = VALUES(vehicle_id),
            driver_id = VALUES(driver_id),
            route_id = VALUES(route_id),
            seat_capacity_snapshot = VALUES(seat_capacity_snapshot)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'iiiissi',
        $scheduleId,
        $vehicleId,
        $driverId,
        $routeId,
        $direction,
        $scheduledDepartureAt,
        $seatCapacitySnapshot
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function hz_expire_overdue_no_shows($conn, $tripId = null)
{
    $tripClause = '';
    if ($tripId !== null) {
        $tripClause = ' AND trip_id = ' . intval($tripId);
    }

    $sql = "
        UPDATE bookings
        SET status = 'cancelled',
            boarding_status = 'no_show',
            no_show_at = NOW()
        WHERE boarding_status = 'vehicle_arrived'
          AND boarding_deadline_at IS NOT NULL
          AND boarding_deadline_at < NOW()
          AND status IN ('pending', 'confirmed', 'in_progress')
          {$tripClause}
    ";

    $conn->query($sql);

    return $conn->affected_rows;
}

function hz_get_trip_reserved_seats($conn, $tripId)
{
    $tripId = intval($tripId);
    $sql = "
        SELECT COALESCE(SUM(passenger_count), 0) AS reserved_count
        FROM bookings
        WHERE trip_id = {$tripId}
          AND status NOT IN ('cancelled', 'denied', 'completed')
          AND boarding_status NOT IN ('no_show', 'dropped_off')
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return intval($row['reserved_count'] ?? 0);
}

function hz_get_trip_boarded_seats($conn, $tripId)
{
    $tripId = intval($tripId);
    $sql = "
        SELECT COALESCE(SUM(passenger_count), 0) AS boarded_count
        FROM bookings
        WHERE trip_id = {$tripId}
          AND boarding_status = 'boarded'
          AND status IN ('confirmed', 'in_progress')
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return intval($row['boarded_count'] ?? 0);
}

function hz_get_trip_capacity($conn, $tripId)
{
    $tripId = intval($tripId);
    $result = $conn->query("SELECT seat_capacity_snapshot FROM vehicle_trips WHERE trip_id = {$tripId} LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return intval($row['seat_capacity_snapshot'] ?? 0);
}

function hz_get_trip_available_seats($conn, $tripId)
{
    $capacity = hz_get_trip_capacity($conn, $tripId);
    $reserved = hz_get_trip_reserved_seats($conn, $tripId);

    return max(0, $capacity - $reserved);
}

function hz_get_trip_metrics($conn, $tripId)
{
    $capacity = hz_get_trip_capacity($conn, $tripId);
    $reserved = hz_get_trip_reserved_seats($conn, $tripId);
    $boarded = hz_get_trip_boarded_seats($conn, $tripId);

    return [
        'capacity' => $capacity,
        'reserved' => $reserved,
        'boarded' => $boarded,
        'available' => max(0, $capacity - $reserved),
    ];
}

function hz_create_driver_earning_if_missing($conn, $bookingId)
{
    $bookingId = intval($bookingId);
    if ($bookingId <= 0) {
        return false;
    }

    $bookingQuery = "
        SELECT
            booking_id,
            driver_id,
            COALESCE(NULLIF(fare, 0), fare_estimate, 0) AS earning_amount,
            COALESCE(dropped_off_at, updated_at, created_at) AS earning_date
        FROM bookings
        WHERE booking_id = {$bookingId}
          AND driver_id IS NOT NULL
        LIMIT 1
    ";
    $bookingResult = $conn->query($bookingQuery);
    if (!$bookingResult || $bookingResult->num_rows === 0) {
        return false;
    }

    $booking = $bookingResult->fetch_assoc();
    $existsResult = $conn->query("SELECT COUNT(*) AS total FROM driver_earnings WHERE booking_id = {$bookingId}");
    if ($existsResult && intval($existsResult->fetch_assoc()['total'] ?? 0) > 0) {
        return true;
    }

    $sql = "
        INSERT INTO driver_earnings (driver_id, booking_id, amount, earning_date, status)
        VALUES (?, ?, ?, DATE(?), 'pending')
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $driverId = intval($booking['driver_id']);
    $amount = floatval($booking['earning_amount']);
    $earningDate = $booking['earning_date'];
    $stmt->bind_param('iids', $driverId, $bookingId, $amount, $earningDate);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function hz_create_notification($conn, $userId, $type, $message, array $data = [])
{
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, message, data)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $payload = json_encode($data);
    $stmt->bind_param('isss', $userId, $type, $message, $payload);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}
