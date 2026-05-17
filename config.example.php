<?php
// Copy this file to config.php and update the values for your environment.

$databaseUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: '';
$databaseParts = $databaseUrl ? parse_url($databaseUrl) : false;

if ($databaseParts && in_array($databaseParts['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
    require_once __DIR__ . '/lib/postgres_mysqli_compat.php';
    try {
        $conn = new PgCompatConnection($databaseUrl);
    } catch (Throwable $e) {
        die('Connection failed: ' . $e->getMessage());
    }
} elseif ($databaseParts && in_array($databaseParts['scheme'] ?? '', ['mysql', 'mysql2', 'mariadb'], true)) {
    $servername = $databaseParts['host'] ?? 'localhost';
    $username = isset($databaseParts['user']) ? urldecode($databaseParts['user']) : 'root';
    $password = isset($databaseParts['pass']) ? urldecode($databaseParts['pass']) : '';
    $dbname = isset($databaseParts['path']) ? ltrim($databaseParts['path'], '/') : 'havenzen_db';
    $dbport = intval($databaseParts['port'] ?? 3306);
    $conn = new mysqli($servername, $username, $password, $dbname, $dbport);

    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');
} else {
    $servername = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
    $username = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
    $dbname = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'havenzen_db';
    $dbport = intval(getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306);
    $conn = new mysqli($servername, $username, $password, $dbname, $dbport);

    if ($conn->connect_error) {
        die('Connection failed: ' . $conn->connect_error);
    }

    $conn->set_charset('utf8mb4');
}

function logSystemEvent($conn, $userId, $action, $description)
{
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'cli';

    if ($userId === null) {
        $sql = 'INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (NULL, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $action, $description, $ipAddress);
    } else {
        $sql = 'INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isss', $userId, $action, $description, $ipAddress);
    }

    $stmt->execute();
    $stmt->close();
}

function logCRUD($conn, $userId, $action, $table, $recordId, $details = '')
{
    $actions = [
        'CREATE' => 'Created new',
        'UPDATE' => 'Updated',
        'DELETE' => 'Deleted',
        'READ' => 'Viewed',
    ];

    $baseMsg = $actions[$action] ?? $action;
    $description = sprintf(
        '%s record in %s (ID: %s) %s',
        $baseMsg,
        $table,
        $recordId,
        $details ? "- $details" : ''
    );

    logSystemEvent($conn, $userId, $action, $description);
}

if (!defined('FARE_BASE')) {
    define('FARE_BASE', 30);
}

if (!defined('FARE_PER_KM')) {
    define('FARE_PER_KM', 15);
}

if (!defined('BAGGAGE_FEE_PER_BAG')) {
    define('BAGGAGE_FEE_PER_BAG', 25);
}

if (!defined('HAVENZEN_CONTACT_NUMBER')) {
    define('HAVENZEN_CONTACT_NUMBER', '+63 938 006 7775');
}

if (!defined('HAVENZEN_RECEIPT_MOTTO')) {
    define('HAVENZEN_RECEIPT_MOTTO', 'Thank you for choosing Havenzen. Ride with us again!');
}

if (!defined('THERMAL_PRINTER_NAME')) {
    define('THERMAL_PRINTER_NAME', getenv('THERMAL_PRINTER_NAME') ?: 'Xprinter XP-58IIH');
}

if (!defined('GPS_TRACKING_API_KEY')) {
    define('GPS_TRACKING_API_KEY', getenv('GPS_TRACKING_API_KEY') ?: 'change-this-gps-api-key');
}

if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: 'replace-with-google-maps-api-key');
}

if (!function_exists('google_maps_script_url')) {
    function google_maps_script_url($callback = 'initMap', $libraries = ['geometry'])
    {
        $params = [
            'key' => GOOGLE_MAPS_API_KEY,
            'libraries' => implode(',', $libraries),
        ];

        if ($callback !== null && $callback !== '') {
            $params['callback'] = $callback;
        }

        return 'https://maps.googleapis.com/maps/api/js?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('hz_upload_public_path')) {
    function hz_upload_public_path($subdir, $fileName)
    {
        $subdir = trim(str_replace('\\', '/', (string) $subdir), '/');
        return '../uploads/' . $subdir . '/' . ltrim((string) $fileName, '/');
    }
}

if (!function_exists('hz_upload_filesystem_path')) {
    function hz_upload_filesystem_path($publicPath)
    {
        $normalized = ltrim(str_replace('\\', '/', (string) $publicPath), '/');
        while (strpos($normalized, '../') === 0) {
            $normalized = substr($normalized, 3);
        }

        return __DIR__ . '/' . $normalized;
    }
}

if (!function_exists('hz_upload_path_exists')) {
    function hz_upload_path_exists($path)
    {
        $path = trim((string) $path);
        if ($path === '') {
            return false;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return true;
        }

        return is_file(hz_upload_filesystem_path($path));
    }
}

if (!function_exists('hz_upload_href')) {
    function hz_upload_href($path)
    {
        $path = trim((string) $path);
        if ($path === '' || preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        while (strpos($normalized, '../') === 0) {
            $normalized = substr($normalized, 3);
        }

        return '../' . $normalized;
    }
}

if (!function_exists('hz_store_uploaded_image')) {
    function hz_store_uploaded_image($fieldName, $subdir, $prefix, $existingPath = '', &$errorMessage = null, $maxBytes = 5242880)
    {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            return $existingPath;
        }

        if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'Upload failed for ' . str_replace('_', ' ', $fieldName) . '.';
            return $existingPath;
        }

        if (!empty($_FILES[$fieldName]['size']) && $_FILES[$fieldName]['size'] > $maxBytes) {
            $errorMessage = 'Image uploads must be 5MB or smaller.';
            return $existingPath;
        }

        $extension = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extension, $allowedTypes, true)) {
            $errorMessage = 'Images must be JPG, PNG, GIF, or WEBP files.';
            return $existingPath;
        }

        if (@getimagesize($_FILES[$fieldName]['tmp_name']) === false) {
            $errorMessage = 'The uploaded file is not a valid image.';
            return $existingPath;
        }

        $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $prefix);
        $fileName = $safePrefix . '_' . time() . '_' . uniqid('', true) . '.' . $extension;
        $targetDir = __DIR__ . '/uploads/' . trim(str_replace('\\', '/', (string) $subdir), '/');
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $errorMessage = 'Could not create upload directory.';
            return $existingPath;
        }

        $targetPath = $targetDir . '/' . $fileName;
        if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
            $errorMessage = 'Could not save the uploaded image.';
            return $existingPath;
        }

        if ($existingPath !== '' && hz_upload_path_exists($existingPath)) {
            $existingFile = hz_upload_filesystem_path($existingPath);
            $existingReal = realpath($existingFile);
            $uploadsReal = realpath(__DIR__ . '/uploads');
            if ($existingReal && $uploadsReal && is_file($existingReal) && strpos($existingReal, $uploadsReal) === 0) {
                @unlink($existingFile);
            }
        }

        return hz_upload_public_path($subdir, $fileName);
    }
}
?>
