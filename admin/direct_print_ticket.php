<?php
require_once 'auth.php';
require_once '../lib/receipt_ticket.php';

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit;
}

$booking = hz_fetch_receipt_booking($conn, $booking_id);
if (!$booking) {
    header('Location: bookings.php');
    exit;
}

$receiptText = hz_build_receipt_text($booking);
$errorMessage = null;

if (!hz_can_server_raw_print($errorMessage)) {
    $message = rawurlencode($errorMessage . ' Opening browser print instead.');
    header('Location: print_ticket.php?booking_id=' . $booking_id . '&direct_print=browser_fallback&autoprint=1&message=' . $message);
    exit;
}

$success = hz_send_raw_receipt_to_printer($receiptText, THERMAL_PRINTER_NAME, $errorMessage);

if ($success) {
    logCRUD(
        $conn,
        intval($_SESSION['user_id'] ?? 0),
        'READ',
        'bookings',
        $booking_id,
        'Sent direct thermal ticket to printer: ' . THERMAL_PRINTER_NAME
    );

    header('Location: print_ticket.php?booking_id=' . $booking_id . '&direct_print=success');
    exit;
}

$message = rawurlencode($errorMessage ?: 'Direct thermal print failed.');
header('Location: print_ticket.php?booking_id=' . $booking_id . '&direct_print=error&autoprint=1&message=' . $message);
exit;
