<?php
require_once 'auth.php';

if (isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    
    // Fetch auth data and merged profile fields based on role
    $sql = "SELECT 
        u.user_id,
        u.username,
        u.role,
        COALESCE(a.full_name, d.full_name, c.full_name) AS full_name,
        COALESCE(a.email, d.email, c.email) AS email,
        COALESCE(a.phone_number, d.phone_number, c.phone_number) AS phone_number,
        COALESCE(a.profile_picture, d.profile_picture, c.profile_picture) AS profile_picture,

        d.license_number,
        d.license_expiry,
        d.license_class,
        d.years_experience,
        d.emergency_contact,
        d.emergency_phone,
        COALESCE(a.address, d.address, c.address) AS address,

        v.vehicle_id AS vehicle_assigned
    FROM users u
    LEFT JOIN admins a ON a.user_id = u.user_id
    LEFT JOIN drivers d ON d.user_id = u.user_id
    LEFT JOIN customers c ON c.user_id = u.user_id
    LEFT JOIN vehicles v ON v.driver_id = u.user_id
    WHERE u.user_id = $user_id";
    
    $user = $conn->query($sql)->fetch_assoc();
    if ($user && !empty($user['profile_picture'])) {
        $user['profile_picture_url'] = hz_upload_href($user['profile_picture']);
    } elseif ($user) {
        $user['profile_picture_url'] = '';
    }
    
    header('Content-Type: application/json');
    echo json_encode($user);
    exit;
}

header('HTTP/1.1 400 Bad Request');
echo json_encode(['error' => 'User ID required']);
?>
