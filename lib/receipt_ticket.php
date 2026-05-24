<?php
require_once __DIR__ . '/vehicle_helpers.php';

function hz_fetch_receipt_booking($conn, int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            b.*,
            c.full_name AS passenger_name,
            c.phone_number AS passenger_phone,
            c.email AS passenger_email,
            v.vehicle_name,
            v.license_plate,
            v.vehicle_model,
            v.vehicle_type,
            v.vehicle_color,
            COALESCE(vt.seat_capacity_snapshot, v.seat_capacity) AS vehicle_capacity_snapshot,
            COALESCE(d.full_name, vehicle_driver.full_name) AS driver_name,
            COALESCE(d.phone_number, vehicle_driver.phone_number) AS driver_phone,
            COALESCE(route_primary.route_name, route_vehicle.route_name) AS route_name
        FROM bookings b
        JOIN customers c ON c.user_id = b.passenger_id
        LEFT JOIN vehicles v ON v.vehicle_id = b.vehicle_id
        LEFT JOIN vehicle_trips vt ON vt.trip_id = b.trip_id
        LEFT JOIN drivers d ON d.user_id = b.driver_id
        LEFT JOIN drivers vehicle_driver ON vehicle_driver.user_id = v.driver_id
        LEFT JOIN routes route_primary ON route_primary.route_id = b.route_id
        LEFT JOIN routes route_vehicle ON route_vehicle.route_id = v.route_id
        WHERE b.booking_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $booking ?: null;
}

function hz_receipt_wrap(string $label, string $value, int $width = 28): array
{
    $text = trim($label . ': ' . preg_replace('/\s+/', ' ', $value));
    if ($text === '') {
        return [];
    }

    $wrapped = wordwrap($text, $width, "\n", true);
    return explode("\n", $wrapped);
}

function hz_receipt_clean_text(string $value): string
{
    $value = preg_replace('/,\s*Philippines/i', '', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string) $value);
}

function hz_receipt_center(string $value, int $width = 28): array
{
    $value = trim(preg_replace('/\s+/', ' ', $value));
    if ($value === '') {
        return [];
    }

    $wrapped = explode("\n", wordwrap($value, $width, "\n", true));
    return array_map(static function ($line) use ($width) {
        return str_pad($line, $width, ' ', STR_PAD_BOTH);
    }, $wrapped);
}

function hz_receipt_total_amount(array $booking): float
{
    return floatval($booking['fare'] ?? 0) > 0 ? floatval($booking['fare']) : floatval($booking['fare_estimate'] ?? 0);
}

function hz_receipt_baggage_count(array $booking): int
{
    return intval($booking['baggage_count'] ?? 0);
}

function hz_receipt_baggage_fee(array $booking): float
{
    $baggageCount = hz_receipt_baggage_count($booking);
    return defined('BAGGAGE_FEE_PER_BAG') ? $baggageCount * BAGGAGE_FEE_PER_BAG : 0.0;
}

function hz_receipt_travel_fare(array $booking): float
{
    $totalAmount = hz_receipt_total_amount($booking);
    $baggageFee = hz_receipt_baggage_fee($booking);
    return max(0.0, $totalAmount - $baggageFee);
}

function hz_receipt_passenger_count(array $booking): int
{
    return max(1, intval($booking['passenger_count'] ?? 1));
}

function hz_receipt_seat_availability(array $booking): string
{
    $capacity = intval($booking['vehicle_capacity_snapshot'] ?? 0);
    $seatsLeft = $booking['seats_left_at_booking'] ?? null;

    if ($capacity <= 0) {
        return ($seatsLeft !== null && $seatsLeft !== '') ? (string) intval($seatsLeft) : 'N/A';
    }

    if ($seatsLeft === null || $seatsLeft === '') {
        return 'N/A/' . $capacity;
    }

    return intval($seatsLeft) . '/' . $capacity;
}

function hz_build_receipt_text(array $booking): string
{
    $fareAmount = hz_receipt_total_amount($booking);
    $travelFare = hz_receipt_travel_fare($booking);
    $baggageCount = hz_receipt_baggage_count($booking);
    $baggageFee = hz_receipt_baggage_fee($booking);
    $passengerCount = hz_receipt_passenger_count($booking);
    $seatAvailability = hz_receipt_seat_availability($booking);
    $fareTierLabel = trim((string) ($booking['fare_tier_label'] ?? ''));
    $fareTierPercent = intval($booking['fare_tier_percent'] ?? 100);
    $statusLabel = ucwords(str_replace('_', ' ', (string) ($booking['status'] ?? '')));
    $ticketCode = 'BK-' . str_pad((string) intval($booking['booking_id'] ?? 0), 6, '0', STR_PAD_LEFT);
    $requestedAt = !empty($booking['requested_time']) ? date('m/d/y h:i A', strtotime((string) $booking['requested_time'])) : date('m/d/y h:i A');

    $pickupShort = hz_receipt_clean_text((string) ($booking['pickup_location'] ?? ''));
    $dropoffShort = hz_receipt_clean_text((string) ($booking['dropoff_location'] ?? ''));
    $routeShort = hz_receipt_clean_text((string) ($booking['route_name'] ?? ''));
    $driverShort = hz_receipt_clean_text((string) (($booking['driver_name'] ?? '') ?: 'Not assigned'));
    $driverPhoneShort = preg_replace('/\s+/', '', (string) ($booking['driver_phone'] ?? ''));
    $vehicleShort = hz_receipt_clean_text(hz_vehicle_display_label($booking));
    $notesShort = hz_receipt_clean_text((string) ($booking['notes'] ?? ''));
    $contactShort = preg_replace('/\s+/', '', (string) ($booking['passenger_phone'] ?? ''));

    $receiptLines = array_merge(
        [str_repeat('=', 28)],
        ['|     H A V E N Z E N      |'],
        [str_repeat('=', 28)],
        hz_receipt_center('Trip Receipt'),
        hz_receipt_center('Barugo, Leyte'),
        hz_receipt_center('Call ' . HAVENZEN_CONTACT_NUMBER),
        [str_repeat('-', 28)]
    );

    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Ticket', $ticketCode));
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Status', $statusLabel));
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Date', $requestedAt));
    $receiptLines[] = str_repeat('-', 28);
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Passenger', (string) ($booking['passenger_name'] ?? '')));
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Seats', (string) $passengerCount));
    if ($seatAvailability !== 'N/A') {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Seat Availability', $seatAvailability));
    }

    if ($contactShort !== '') {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Contact', $contactShort));
    }

    $receiptLines[] = str_repeat('-', 28);
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('From', $pickupShort));
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('To', $dropoffShort));
    $receiptLines[] = str_repeat('-', 28);
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Driver', $driverShort));
    if ($driverPhoneShort !== '') {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Driver Phone', $driverPhoneShort));
    }
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Vehicle', $vehicleShort));
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Plate', (string) ($booking['license_plate'] ?? 'N/A')));

    if ($routeShort !== '') {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Route', $routeShort));
    }

    $receiptLines[] = str_repeat('-', 28);
    $receiptLines = array_merge($receiptLines, hz_receipt_center('PAYMENT DETAILS'));
    if ($fareTierLabel !== '') {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Fare Option', $fareTierLabel . ' (' . $fareTierPercent . '%)'));
    }
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Ticket Fare', 'PHP ' . number_format($travelFare, 2)));
    if (defined('BAGGAGE_FEE_PER_BAG')) {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Baggage Count', (string) $baggageCount));
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Baggage Rate', 'PHP ' . number_format((float) BAGGAGE_FEE_PER_BAG, 2) . '/bag'));
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Baggage Fee', 'PHP ' . number_format($baggageFee, 2)));
    } else {
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Baggage Count', (string) $baggageCount));
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Baggage Fee', 'PHP ' . number_format($baggageFee, 2)));
    }
    $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Total Bill', 'PHP ' . number_format($fareAmount, 2)));

    if ($notesShort !== '') {
        $receiptLines[] = str_repeat('-', 28);
        $receiptLines = array_merge($receiptLines, hz_receipt_wrap('Notes', $notesShort));
    }

    $receiptLines[] = str_repeat('-', 28);
    $receiptLines = array_merge($receiptLines, hz_receipt_center(HAVENZEN_RECEIPT_MOTTO));
    $receiptLines[] = 'Booking ID: ' . intval($booking['booking_id'] ?? 0);

    return implode("\n", array_filter($receiptLines, static function ($line) {
        return $line !== null;
    })) . "\n\n\n";
}

function hz_render_receipt_html(array $booking): string
{
    $fareAmount = hz_receipt_total_amount($booking);
    $travelFare = hz_receipt_travel_fare($booking);
    $baggageCount = hz_receipt_baggage_count($booking);
    $baggageFee = hz_receipt_baggage_fee($booking);
    $passengerCount = hz_receipt_passenger_count($booking);
    $seatAvailability = hz_receipt_seat_availability($booking);
    $fareTierLabel = trim((string) ($booking['fare_tier_label'] ?? ''));
    $fareTierPercent = intval($booking['fare_tier_percent'] ?? 100);
    $statusLabel = ucwords(str_replace('_', ' ', (string) ($booking['status'] ?? '')));
    $ticketCode = 'BK-' . str_pad((string) intval($booking['booking_id'] ?? 0), 6, '0', STR_PAD_LEFT);
    $requestedAt = !empty($booking['requested_time']) ? date('M j, Y g:i A', strtotime((string) $booking['requested_time'])) : date('M j, Y g:i A');

    $fields = [
        ['label' => 'Passenger', 'value' => (string) ($booking['passenger_name'] ?? 'N/A')],
        ['label' => 'Seats', 'value' => (string) $passengerCount],
        ['label' => 'Seat Availability', 'value' => $seatAvailability],
        ['label' => 'Status', 'value' => $statusLabel],
        ['label' => 'Ticket', 'value' => $ticketCode],
        ['label' => 'Date', 'value' => $requestedAt],
        ['label' => 'Contact', 'value' => (string) ($booking['passenger_phone'] ?? HAVENZEN_CONTACT_NUMBER)],
        ['label' => 'Route', 'value' => hz_receipt_clean_text((string) ($booking['route_name'] ?? '')) ?: 'Custom route'],
        ['label' => 'From', 'value' => hz_receipt_clean_text((string) ($booking['pickup_location'] ?? '')) ?: 'N/A'],
        ['label' => 'To', 'value' => hz_receipt_clean_text((string) ($booking['dropoff_location'] ?? '')) ?: 'N/A'],
        ['label' => 'Driver', 'value' => hz_receipt_clean_text((string) (($booking['driver_name'] ?? '') ?: 'Not assigned'))],
        ['label' => 'Driver Phone', 'value' => preg_replace('/\s+/', '', (string) ($booking['driver_phone'] ?? '')) ?: 'N/A'],
        ['label' => 'Vehicle', 'value' => hz_receipt_clean_text(hz_vehicle_display_label($booking))],
        ['label' => 'Plate', 'value' => (string) ($booking['license_plate'] ?? 'N/A')],
        ['label' => 'Fare Option', 'value' => $fareTierLabel !== '' ? $fareTierLabel . ' (' . $fareTierPercent . '%)' : 'Full route (100%)'],
        ['label' => 'Ticket Fare', 'value' => 'PHP ' . number_format($travelFare, 2)],
        ['label' => 'Baggage Count', 'value' => $baggageCount . ' bag(s)'],
        ['label' => 'Baggage Rate', 'value' => defined('BAGGAGE_FEE_PER_BAG') ? 'PHP ' . number_format((float) BAGGAGE_FEE_PER_BAG, 2) . '/bag' : 'N/A'],
        ['label' => 'Baggage Fee', 'value' => 'PHP ' . number_format($baggageFee, 2)],
        ['label' => 'Total Bill', 'value' => 'PHP ' . number_format($fareAmount, 2), 'highlight' => true],
    ];

    $notesShort = hz_receipt_clean_text((string) ($booking['notes'] ?? ''));
    if ($notesShort !== '') {
        $fields[] = ['label' => 'Notes', 'value' => $notesShort];
    }

    $html = '<article class="receipt-card">';
    $html .= '<header class="receipt-brand">';
    $html .= '<div class="receipt-mark receipt-mark-vehicle" aria-hidden="true"><span class="receipt-roof"></span><span class="receipt-vehicle-body"><span></span><span></span></span></div>';
    $html .= '<div class="receipt-brand-copy">';
    $html .= '<h1>HAVENZEN</h1>';
    $html .= '<p>Barugo, Leyte</p>';
    $html .= '<p>' . htmlspecialchars(HAVENZEN_CONTACT_NUMBER) . '</p>';
    $html .= '</div>';
    $html .= '</header>';
    $html .= '<div class="receipt-divider"></div>';
    $html .= '<section class="receipt-grid">';

    foreach ($fields as $field) {
        $className = !empty($field['highlight']) ? 'receipt-field receipt-field-highlight' : 'receipt-field';
        $html .= '<div class="' . $className . '">';
        $html .= '<span class="receipt-label">' . htmlspecialchars($field['label']) . '</span>';
        $html .= '<strong class="receipt-value">' . htmlspecialchars($field['value']) . '</strong>';
        $html .= '</div>';
    }

    $html .= '</section>';
    $html .= '<div class="receipt-divider"></div>';
    $html .= '<footer class="receipt-footer">';
    $html .= '<p>' . htmlspecialchars(HAVENZEN_RECEIPT_MOTTO) . '</p>';
    $html .= '</footer>';
    $html .= '</article>';

    return $html;
}

function hz_can_server_raw_print(?string &$reason = null): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        $reason = 'Direct thermal printing is only available when the PHP server is running on the Windows laptop with the printer driver installed.';
        return false;
    }

    $reason = null;
    return true;
}

function hz_send_raw_receipt_to_printer(string $receiptText, string $printerName, ?string &$errorMessage = null): bool
{
    if (!hz_can_server_raw_print($errorMessage)) {
        return false;
    }

    $printerName = trim($printerName);
    if ($printerName === '') {
        $errorMessage = 'Thermal printer name is not configured.';
        return false;
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'hz_receipt_');
    if ($tempFile === false) {
        $errorMessage = 'Could not create temporary receipt file.';
        return false;
    }

    $receiptText = str_replace(["\r\n", "\r"], "\n", $receiptText);
    $receiptText = preg_replace("/[^\x09\x0A\x0D\x20-\x7E]/", '', $receiptText);
    file_put_contents($tempFile, $receiptText, LOCK_EX);

    $printerB64 = base64_encode($printerName);
    $fileB64 = base64_encode($tempFile);

    $psScript = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$printerName = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String('__PRINTER_B64__'))
$filePath = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String('__FILE_B64__'))
$encoding = [System.Text.Encoding]::ASCII
$lines = [System.IO.File]::ReadAllLines($filePath, $encoding)
$bytesList = New-Object 'System.Collections.Generic.List[Byte]'

function Add-Bytes {
    param([byte[]]$Chunk)
    $bytesList.AddRange($Chunk)
}

function Add-Text {
    param([string]$Value)
    Add-Bytes ($encoding.GetBytes($Value))
}

Add-Bytes ([byte[]](0x1B,0x40))

foreach ($line in $lines) {
    Add-Text ($line + "`n")
}

Add-Bytes ([byte[]](0x0A,0x0A,0x0A))
$bytes = $bytesList.ToArray()

$code = @"
using System;
using System.Runtime.InteropServices;
public class RawPrinterHelper {
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
    public class DOCINFOA {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }
    [DllImport("winspool.drv", EntryPoint="OpenPrinterW", SetLastError=true, CharSet=CharSet.Unicode)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool ClosePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true, CharSet=CharSet.Unicode)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In] DOCINFOA di);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.drv", SetLastError=true)]
    public static extern bool WritePrinter(IntPtr hPrinter, byte[] pBytes, int dwCount, out int dwWritten);
}
"@
Add-Type -TypeDefinition $code

$h = [IntPtr]::Zero
if (-not [RawPrinterHelper]::OpenPrinter($printerName, [ref]$h, [IntPtr]::Zero)) { throw "OpenPrinter failed" }
try {
    $doc = New-Object RawPrinterHelper+DOCINFOA
    $doc.pDocName = 'Haven Zen Thermal Ticket'
    $doc.pDataType = 'RAW'
    if (-not [RawPrinterHelper]::StartDocPrinter($h, 1, $doc)) { throw "StartDocPrinter failed" }
    try {
        if (-not [RawPrinterHelper]::StartPagePrinter($h)) { throw "StartPagePrinter failed" }
        try {
            $written = 0
            if (-not [RawPrinterHelper]::WritePrinter($h, $bytes, $bytes.Length, [ref]$written)) { throw "WritePrinter failed" }
        } finally {
            [RawPrinterHelper]::EndPagePrinter($h) | Out-Null
        }
    } finally {
        [RawPrinterHelper]::EndDocPrinter($h) | Out-Null
    }
} finally {
    [RawPrinterHelper]::ClosePrinter($h) | Out-Null
}
POWERSHELL;

    $psScript = str_replace(['__PRINTER_B64__', '__FILE_B64__'], [$printerB64, $fileB64], $psScript);
    $scriptFile = tempnam(sys_get_temp_dir(), 'hz_print_');
    if ($scriptFile === false) {
        @unlink($tempFile);
        $errorMessage = 'Could not create temporary print script.';
        return false;
    }

    $scriptPath = $scriptFile . '.ps1';
    if (!@rename($scriptFile, $scriptPath)) {
        $scriptPath = $scriptFile;
    }

    if (file_put_contents($scriptPath, $psScript, LOCK_EX) === false) {
        @unlink($tempFile);
        @unlink($scriptPath);
        $errorMessage = 'Could not write temporary print script.';
        return false;
    }

    $command = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($scriptPath);

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    @unlink($tempFile);
    @unlink($scriptPath);

    if ($exitCode !== 0) {
        $errorMessage = trim(implode("\n", $output)) ?: 'PowerShell direct print failed.';
        return false;
    }

    return true;
}
