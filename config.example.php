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

if (!function_exists('hz_normalize_upload_path')) {
    function hz_normalize_upload_path($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path));
        $path = strtok($path, '?') ?: $path;
        $path = ltrim($path, '/');

        while (strpos($path, '../') === 0) {
            $path = substr($path, 3);
        }

        if (strpos($path, 'uploads/') !== 0) {
            return '';
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}

if (!function_exists('hz_upload_filesystem_path')) {
    function hz_upload_filesystem_path($publicPath)
    {
        $normalized = hz_normalize_upload_path($publicPath);
        return __DIR__ . '/' . $normalized;
    }
}

if (!function_exists('hz_ensure_uploaded_files_table')) {
    function hz_ensure_uploaded_files_table($connection = null)
    {
        static $checked = false;
        if ($checked) {
            return true;
        }

        $connection = $connection ?: ($GLOBALS['conn'] ?? null);
        if (!$connection) {
            return false;
        }

        $isPostgres = class_exists('PgCompatConnection') && $connection instanceof PgCompatConnection;
        $dataType = $isPostgres ? 'TEXT' : 'LONGTEXT';
        $sql = "
            CREATE TABLE IF NOT EXISTS uploaded_files (
                file_path VARCHAR(255) PRIMARY KEY,
                mime_type VARCHAR(100) NOT NULL,
                file_data {$dataType} NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ";

        $checked = (bool) $connection->query($sql);
        return $checked;
    }
}

if (!function_exists('hz_upload_db_path_exists')) {
    function hz_upload_db_path_exists($path, $connection = null)
    {
        $connection = $connection ?: ($GLOBALS['conn'] ?? null);
        $normalized = hz_normalize_upload_path($path);
        if (!$connection || $normalized === '' || !hz_ensure_uploaded_files_table($connection)) {
            return false;
        }

        $stmt = $connection->prepare('SELECT file_path FROM uploaded_files WHERE file_path = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        return $exists;
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

        return is_file(hz_upload_filesystem_path($path)) || hz_upload_db_path_exists($path);
    }
}

if (!function_exists('hz_upload_href')) {
    function hz_upload_href($path)
    {
        $path = trim((string) $path);
        if ($path === '' || preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $normalized = hz_normalize_upload_path($path);
        if ($normalized === '') {
            return '';
        }

        return '../uploaded_file.php?path=' . rawurlencode($normalized);
    }
}

if (!function_exists('hz_persist_uploaded_file')) {
    function hz_persist_uploaded_file($path, $mimeType, $bytes, &$errorMessage = null, $connection = null)
    {
        $connection = $connection ?: ($GLOBALS['conn'] ?? null);
        $normalized = hz_normalize_upload_path($path);
        if (!$connection || $normalized === '') {
            $errorMessage = 'Could not persist uploaded image.';
            return false;
        }

        if (!hz_ensure_uploaded_files_table($connection)) {
            $errorMessage = 'Could not prepare upload storage.';
            return false;
        }

        $deleteStmt = $connection->prepare('DELETE FROM uploaded_files WHERE file_path = ?');
        if ($deleteStmt) {
            $deleteStmt->bind_param('s', $normalized);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $encoded = base64_encode($bytes);
        $stmt = $connection->prepare('INSERT INTO uploaded_files (file_path, mime_type, file_data) VALUES (?, ?, ?)');
        if (!$stmt) {
            $errorMessage = 'Could not prepare upload persistence.';
            return false;
        }

        $stmt->bind_param('sss', $normalized, $mimeType, $encoded);
        $ok = $stmt->execute();
        if (!$ok) {
            $errorMessage = 'Could not persist uploaded image.';
        }
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('hz_fetch_uploaded_file')) {
    function hz_fetch_uploaded_file($path, $connection = null)
    {
        $connection = $connection ?: ($GLOBALS['conn'] ?? null);
        $normalized = hz_normalize_upload_path($path);
        if (!$connection || $normalized === '') {
            return null;
        }

        if (is_file(hz_upload_filesystem_path($normalized))) {
            $filePath = hz_upload_filesystem_path($normalized);
            return [
                'mime_type' => mime_content_type($filePath) ?: 'application/octet-stream',
                'bytes' => file_get_contents($filePath),
            ];
        }

        if (!hz_ensure_uploaded_files_table($connection)) {
            return null;
        }

        $stmt = $connection->prepare('SELECT mime_type, file_data FROM uploaded_files WHERE file_path = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        $bytes = base64_decode((string) $row['file_data'], true);
        if ($bytes === false) {
            return null;
        }

        return [
            'mime_type' => $row['mime_type'] ?: 'application/octet-stream',
            'bytes' => $bytes,
        ];
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

        $imageBytes = file_get_contents($_FILES[$fieldName]['tmp_name']);
        if ($imageBytes === false) {
            $errorMessage = 'Could not read the uploaded image.';
            return $existingPath;
        }

        $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $prefix);
        $fileName = $safePrefix . '_' . time() . '_' . uniqid('', true) . '.' . $extension;
        $publicPath = hz_upload_public_path($subdir, $fileName);
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

        $mimeType = mime_content_type($targetPath) ?: ('image/' . ($extension === 'jpg' ? 'jpeg' : $extension));
        if (!hz_persist_uploaded_file($publicPath, $mimeType, $imageBytes, $errorMessage)) {
            @unlink($targetPath);
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

        return $publicPath;
    }
}

if (!function_exists('hz_core_table_has_column')) {
    function hz_core_table_has_column($connection, $table, $column)
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
        if ($table === '' || $column === '') {
            return false;
        }

        $result = $connection->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('hz_add_column_if_missing')) {
    function hz_add_column_if_missing($connection, $table, $column, $definition)
    {
        if (hz_core_table_has_column($connection, $table, $column)) {
            return true;
        }

        return (bool) $connection->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

if (!function_exists('hz_schema_marker_path')) {
    function hz_schema_marker_path($version)
    {
        $storageDir = __DIR__ . '/storage';
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            return '';
        }

        $safeVersion = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $version);
        return $storageDir . '/schema_' . $safeVersion . '.ok';
    }
}

if (!function_exists('hz_core_table_has_index')) {
    function hz_core_table_has_index($connection, $table, $index)
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
        $index = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $index);
        if ($table === '' || $index === '') {
            return false;
        }

        $result = $connection->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('hz_add_index_if_missing')) {
    function hz_add_index_if_missing($connection, $table, $index, $columns)
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
        $index = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $index);
        if ($table === '' || $index === '' || hz_core_table_has_index($connection, $table, $index)) {
            return true;
        }

        return (bool) $connection->query("CREATE INDEX {$index} ON {$table} ({$columns})");
    }
}

if (!function_exists('hz_ensure_havenzen_performance_indexes')) {
    function hz_ensure_havenzen_performance_indexes($connection)
    {
        $indexes = [
            ['locations', 'idx_locations_vehicle_timestamp', 'vehicle_id, timestamp'],
            ['vehicles', 'idx_vehicles_driver_id', 'driver_id'],
            ['bookings', 'idx_bookings_driver_status', 'driver_id, status'],
            ['bookings', 'idx_bookings_vehicle_status', 'vehicle_id, status'],
            ['vehicle_trips', 'idx_vehicle_trips_departure_status', 'scheduled_departure_at, trip_status'],
        ];

        foreach ($indexes as $indexSpec) {
            [$table, $index, $columns] = $indexSpec;
            hz_add_index_if_missing($connection, $table, $index, $columns);
        }

        return true;
    }
}

if (!function_exists('hz_ensure_havenzen_feature_columns')) {
    function hz_ensure_havenzen_feature_columns($connection)
    {
        static $checked = false;
        if ($checked || !$connection) {
            return true;
        }

        $schemaVersion = '20260528_performance_schema';
        $markerPath = hz_schema_marker_path($schemaVersion);
        if ($markerPath !== '' && is_file($markerPath)) {
            $checked = true;
            return true;
        }

        $columns = [
            ['drivers', 'approval_status', "VARCHAR(20) NOT NULL DEFAULT 'approved'"],
            ['drivers', 'approval_notes', 'TEXT'],
            ['drivers', 'approved_at', 'TIMESTAMP NULL'],
            ['drivers', 'approved_by', 'INT NULL'],
            ['drivers', 'license_front_image', 'VARCHAR(255) DEFAULT NULL'],
            ['drivers', 'license_back_image', 'VARCHAR(255) DEFAULT NULL'],
            ['vehicles', 'vehicle_model', 'VARCHAR(100) DEFAULT NULL'],
            ['bookings', 'seats_left_at_booking', 'INT NULL'],
            ['bookings', 'fare_tier_percent', 'INT NOT NULL DEFAULT 100'],
            ['bookings', 'fare_tier_label', "VARCHAR(50) NOT NULL DEFAULT 'Full route'"],
        ];

        $columnsOk = true;
        foreach ($columns as $columnSpec) {
            [$table, $column, $definition] = $columnSpec;
            $columnsOk = hz_add_column_if_missing($connection, $table, $column, $definition) && $columnsOk;
        }

        hz_ensure_havenzen_performance_indexes($connection);

        if ($columnsOk && $markerPath !== '') {
            @file_put_contents($markerPath, gmdate('c'));
        }

        $checked = true;
        return true;
    }
}

hz_ensure_havenzen_feature_columns($conn);
?>
