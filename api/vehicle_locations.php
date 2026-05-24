<?php
require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');

try {
    // Get every vehicle with its latest known location and assigned driver.
    $vehicle_id_filter = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : null;
    
    $query = "
        SELECT 
            v.vehicle_id,
            v.vehicle_name,
            v.license_plate,
            v.vehicle_model,
            v.vehicle_type,
            v.status,
            d.full_name as driver_name,
            r.route_name,
            COALESCE(l.latitude, ST_X(d.current_location)) as latitude,
            COALESCE(l.longitude, ST_Y(d.current_location)) as longitude,
            l.timestamp,
            CASE
                WHEN l.timestamp IS NULL THEN NULL
                ELSE TIMESTAMPDIFF(SECOND, l.timestamp, NOW())
            END as last_update
        FROM vehicles v
        LEFT JOIN drivers d ON v.driver_id = d.user_id
        LEFT JOIN routes r ON v.route_id = r.route_id
        LEFT JOIN (
            SELECT l1.vehicle_id, l1.latitude, l1.longitude, l1.timestamp
            FROM locations l1
            INNER JOIN (
                SELECT vehicle_id, MAX(timestamp) as latest_timestamp
                FROM locations
                GROUP BY vehicle_id
            ) latest ON latest.vehicle_id = l1.vehicle_id
                    AND latest.latest_timestamp = l1.timestamp
        ) l ON v.vehicle_id = l.vehicle_id
        WHERE 1 = 1
    ";

    if ($vehicle_id_filter) {
        $query .= " AND v.vehicle_id = $vehicle_id_filter";
    }

    $query .= " ORDER BY v.vehicle_name";
    
    $result = $conn->query($query);
    if (!$result) {
        throw new Exception($conn->error ?: 'Unable to load vehicle locations');
    }
    
    $vehicles = [];
    while ($row = $result->fetch_assoc()) {
        $hasCoordinates = $row['latitude'] !== null && $row['longitude'] !== null && $row['latitude'] !== '' && $row['longitude'] !== '';
        $vehicles[] = [
            'vehicle_id' => intval($row['vehicle_id']),
            'vehicle_name' => $row['vehicle_name'],
            'license_plate' => $row['license_plate'],
            'vehicle_model' => $row['vehicle_model'],
            'vehicle_type' => $row['vehicle_type'],
            'driver_name' => $row['driver_name'],
            'route_name' => $row['route_name'],
            'latitude' => $hasCoordinates ? (float) $row['latitude'] : null,
            'longitude' => $hasCoordinates ? (float) $row['longitude'] : null,
            'status' => $row['status'],
            'last_update' => $row['last_update'] === null ? null : intval($row['last_update']),
            'has_location' => $hasCoordinates
        ];
    }
    
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'count' => count($vehicles),
        'vehicles' => $vehicles
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
        'count' => 0,
        'vehicles' => []
    ]);
}
?>
