<?php
require_once __DIR__ . '/config.php';

$path = $_GET['path'] ?? '';
$file = hz_fetch_uploaded_file($path);

if (!$file) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Uploaded file not found.';
    exit;
}

$mimeType = $file['mime_type'] ?: 'application/octet-stream';
if (strpos($mimeType, 'image/') !== 0) {
    $mimeType = 'application/octet-stream';
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . strlen($file['bytes']));
header('Cache-Control: private, max-age=3600');
echo $file['bytes'];
