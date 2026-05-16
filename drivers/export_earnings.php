<?php
require_once 'auth.php';

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$start_ts = strtotime($start_date);
$end_ts = strtotime($end_date);

if (!$start_ts || !$end_ts) {
    http_response_code(400);
    echo 'Invalid date range';
    exit;
}

$start_date = date('Y-m-d', $start_ts);
$end_date = date('Y-m-d', $end_ts);
$earnings_driver_id = $driver_profile_id ?: $driver_id;

$stmt = $conn->prepare("
    SELECT
        de.earning_date,
        b.booking_id,
        c.full_name AS passenger_name,
        b.pickup_location,
        b.dropoff_location,
        de.amount,
        COALESCE(b.status, de.status) AS status
    FROM driver_earnings de
    JOIN bookings b ON de.booking_id = b.booking_id
    JOIN customers c ON b.passenger_id = c.user_id
    WHERE de.driver_id = ?
      AND DATE(de.earning_date) BETWEEN ? AND ?
    ORDER BY de.earning_date DESC
");

if (!$stmt) {
    http_response_code(500);
    echo 'Unable to prepare export';
    exit;
}

$stmt->bind_param('iss', $earnings_driver_id, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

$filename = 'havenzen-driver-earnings-' . $start_date . '-to-' . $end_date . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Booking ID', 'Passenger', 'Pickup', 'Dropoff', 'Amount', 'Status']);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['earning_date'],
        $row['booking_id'],
        $row['passenger_name'],
        $row['pickup_location'],
        $row['dropoff_location'],
        number_format((float) $row['amount'], 2, '.', ''),
        $row['status'],
    ]);
}

fclose($out);
$stmt->close();
exit;
?>
