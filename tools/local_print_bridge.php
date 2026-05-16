<?php
require_once dirname(__DIR__) . '/lib/receipt_ticket.php';

if (!defined('THERMAL_PRINTER_NAME')) {
    define('THERMAL_PRINTER_NAME', getenv('THERMAL_PRINTER_NAME') ?: 'Xprinter XP-58IIH');
}

function hz_bridge_allowed_origins(): array
{
    $configured = getenv('HAVENZEN_PRINT_ALLOWED_ORIGINS') ?: 'https://havenzen.onrender.com,http://localhost:8000,http://127.0.0.1:8000';
    return array_values(array_filter(array_map('trim', explode(',', $configured))));
}

function hz_bridge_origin_allowed(string $origin): bool
{
    if ($origin === '') {
        return true;
    }

    if (in_array($origin, hz_bridge_allowed_origins(), true)) {
        return true;
    }

    return (bool) preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $origin);
}

function hz_bridge_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (hz_bridge_origin_allowed($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!hz_bridge_origin_allowed($origin)) {
    hz_bridge_json(403, [
        'success' => false,
        'message' => 'Origin is not allowed to use this local print bridge.',
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    hz_bridge_json(200, [
        'success' => true,
        'status' => 'ready',
        'printer' => THERMAL_PRINTER_NAME,
        'message' => 'Havenzen local thermal print bridge is running.',
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    hz_bridge_json(405, [
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    hz_bridge_json(400, [
        'success' => false,
        'message' => 'Invalid JSON payload.',
    ]);
}

$receiptText = (string) ($payload['receipt_text'] ?? '');
$printerName = trim((string) ($payload['printer_name'] ?? THERMAL_PRINTER_NAME));

if (trim($receiptText) === '') {
    hz_bridge_json(400, [
        'success' => false,
        'message' => 'Receipt text is required.',
    ]);
}

$errorMessage = null;
$success = hz_send_raw_receipt_to_printer($receiptText, $printerName, $errorMessage);
if (!$success) {
    hz_bridge_json(500, [
        'success' => false,
        'message' => $errorMessage ?: 'Local thermal print failed.',
    ]);
}

hz_bridge_json(200, [
    'success' => true,
    'message' => 'Receipt sent to ' . $printerName . '.',
]);
