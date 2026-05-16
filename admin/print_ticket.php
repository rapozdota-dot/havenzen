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
$statusMessage = null;
$statusType = null;

if (isset($_GET['direct_print'])) {
    if ($_GET['direct_print'] === 'success') {
        $statusType = 'success';
        $statusMessage = 'Direct thermal print sent to ' . THERMAL_PRINTER_NAME . '.';
    } elseif ($_GET['direct_print'] === 'error') {
        $statusType = 'error';
        $statusMessage = trim((string) ($_GET['message'] ?? 'Direct thermal print failed.'));
    }
}

if (($_GET['format'] ?? '') === 'txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: inline; filename="ticket-' . intval($booking['booking_id']) . '.txt"');
    echo $receiptText;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Ticket #<?php echo intval($booking['booking_id']); ?></title>
    <style>
        @page {
            size: 58mm auto;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f5f7;
            color: #111827;
            font-family: "Poppins", "Segoe UI", sans-serif;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            padding: 16px;
            background: #ffffff;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }

        .controls button,
        .controls a {
            border: none;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            padding: 10px 14px;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .controls button:hover,
        .controls a:hover {
            background: #e91e63;
            box-shadow: 0 10px 18px rgba(233, 30, 99, 0.22);
            transform: translateY(-1px);
        }

        .status-banner {
            max-width: 520px;
            margin: 12px auto 0;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.45;
        }

        .status-banner.success {
            background: #e8f5e8;
            color: #226c30;
        }

        .status-banner.error {
            background: #ffebee;
            color: #b42318;
        }

        .print-note {
            max-width: 420px;
            margin: 12px auto;
            padding: 0 16px;
            color: #374151;
            font-size: 13px;
            line-height: 1.45;
            text-align: center;
        }

        .ticket {
            width: min(58mm, calc(100vw - 24px));
            margin: 0 auto 24px;
            background: #ffffff;
            padding: 1.8mm 1.8mm 2.4mm;
            overflow: hidden;
            border-radius: 8px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        .receipt-card {
            text-align: center;
        }

        .receipt-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .receipt-mark {
            width: 46px;
            height: 34px;
            color: #111827;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .receipt-mark-vehicle .receipt-roof {
            position: absolute;
            top: 0;
            left: 13px;
            width: 20px;
            height: 16px;
            border-left: 3px solid #e91e63;
            border-top: 3px solid #e91e63;
            transform: skewX(-28deg);
        }

        .receipt-mark-vehicle .receipt-vehicle-body {
            position: absolute;
            bottom: 4px;
            left: 4px;
            width: 38px;
            height: 17px;
            border: 3px solid #111827;
            border-radius: 5px 7px 5px 5px;
        }

        .receipt-mark-vehicle .receipt-vehicle-body::before,
        .receipt-mark-vehicle .receipt-vehicle-body::after {
            content: "";
            position: absolute;
            bottom: -7px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #111827;
        }

        .receipt-mark-vehicle .receipt-vehicle-body::before {
            left: 5px;
        }

        .receipt-mark-vehicle .receipt-vehicle-body::after {
            right: 5px;
        }

        .receipt-mark-vehicle .receipt-vehicle-body span {
            position: absolute;
            top: 3px;
            width: 7px;
            height: 5px;
            background: #e91e63;
            border-radius: 1px;
        }

        .receipt-mark-vehicle .receipt-vehicle-body span:first-child {
            left: 7px;
        }

        .receipt-mark-vehicle .receipt-vehicle-body span:last-child {
            right: 7px;
        }

        .receipt-brand-copy h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.14em;
            color: #111827;
        }

        .receipt-brand-copy p {
            margin: 2px 0 0;
            font-size: 10px;
            color: #4b5563;
        }

        .receipt-divider {
            margin: 12px 0;
            border-top: 1px dashed #cbd5e1;
        }

        .receipt-grid {
            display: grid;
            gap: 8px;
        }

        .receipt-field {
            padding: 6px 8px;
            border-radius: 6px;
            background: #f8fafc;
        }

        .receipt-field-highlight {
            background: rgba(233, 30, 99, 0.08);
        }

        .receipt-label {
            display: block;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 3px;
        }

        .receipt-value {
            display: block;
            font-size: 11px;
            line-height: 1.45;
            color: #111827;
        }

        .receipt-footer p {
            margin: 0;
            font-size: 10px;
            font-weight: 600;
            line-height: 1.5;
            color: #374151;
        }

        @media print {
            body {
                background: #fff;
            }

            .controls,
            .print-note,
            .status-banner {
                display: none !important;
            }

            .ticket {
                width: 58mm;
                margin: 0;
                padding: 1.8mm 1.8mm 2.4mm;
                box-shadow: none;
                border: none;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
    <div class="controls">
        <a href="direct_print_ticket.php?booking_id=<?php echo intval($booking['booking_id']); ?>">Direct Thermal Print</a>
        <button type="button" onclick="window.print()">Print In Browser</button>
        <a href="print_ticket.php?booking_id=<?php echo intval($booking['booking_id']); ?>&format=txt" target="_blank" rel="noopener">Open Plain Text</a>
        <a href="bookings.php">Back to Bookings</a>
    </div>

    <?php if ($statusMessage !== null): ?>
        <div class="status-banner <?php echo htmlspecialchars($statusType ?: 'success'); ?>">
            <?php echo htmlspecialchars($statusMessage); ?>
        </div>
    <?php endif; ?>

    <div class="print-note">
        Direct thermal print uses the local Havenzen print bridge when the web app is deployed. Keep <strong>start_thermal_print_bridge.bat</strong> running on the printer laptop.
    </div>

    <div class="ticket">
        <?php echo hz_render_receipt_html($booking); ?>
    </div>

    <script>
    window.addEventListener('load', function () {
        if (window.location.search.indexOf('autoprint=1') !== -1) {
            window.setTimeout(function () {
                window.print();
            }, 250);
        }
    });
    </script>
</body>
</html>
