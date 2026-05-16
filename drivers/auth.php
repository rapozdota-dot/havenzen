<?php
if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Check if user is logged in and is a driver
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    // If this is an AJAX request, return JSON so client-side code can handle it
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestPath = $_SERVER['REQUEST_URI'] ?? '';
    $wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || stripos($acceptHeader, 'application/json') !== false
        || strpos($requestPath, '/api/') !== false;

    if ($wantsJson) {
        header('Content-Type: application/json', true, 401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated or not a driver']);
        exit();
    }

    if (!headers_sent()) {
        header('Location: ../login/login.php', true, 302);
    }

    echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=../login/login.php"><title>Redirecting</title></head><body><p>Redirecting to login...</p><script>window.location.replace("../login/login.php");</script></body></html>';
    exit();
}

// Include config and get driver data
require_once '../config.php';

// This is the user_id from the users table (session)
$driver_user_id = intval($_SESSION['user_id']);

// For backwards compatibility: many places use $driver_id to mean the user_id
$driver_id = $driver_user_id;

// Avoid a database write on every click; refresh online state every few minutes.
$lastDriverHeartbeat = intval($_SESSION['driver_online_heartbeat_at'] ?? 0);
if ($lastDriverHeartbeat < time() - 300) {
    $stmt = $conn->prepare("UPDATE drivers SET last_login = NOW(), is_online = 1 WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $driver_user_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['driver_online_heartbeat_at'] = time();
    }
}

// Get driver data with vehicle information and profile details
$stmt = $conn->prepare("
    SELECT 
        u.user_id,
        u.username,
        u.role,
        d.full_name,
        d.email,
        d.phone_number,
        d.license_number,
        d.license_expiry,
        d.license_class,
        d.years_experience,
        d.emergency_contact,
        d.emergency_phone,
        d.address,
        d.is_online,
        d.driver_id,
        v.vehicle_id,
        v.vehicle_name,
        v.license_plate,
        v.vehicle_type,
        v.vehicle_color,
        v.status as vehicle_status
    FROM users u
    JOIN drivers d ON d.user_id = u.user_id
    LEFT JOIN vehicles v ON v.driver_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $driver_user_id);
$stmt->execute();
$result = $stmt->get_result();
$driver_data = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$driver_data) {
    session_unset();
    session_destroy();
    if (!headers_sent()) {
        header('Location: ../login/login.php', true, 302);
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=../login/login.php"><title>Redirecting</title></head><body><p>Driver account not found. Redirecting to login...</p><script>window.location.replace("../login/login.php");</script></body></html>';
    exit();
}

// Separate internal numeric driver profile id (drivers.driver_id) from user_id
$driver_profile_id = 0;
if ($driver_data && isset($driver_data['driver_id'])) {
    $driver_profile_id = intval($driver_data['driver_id']);
}
?>
