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
    $receiptPayload = [
        'receipt_text' => $receiptText,
        'printer_name' => THERMAL_PRINTER_NAME,
        'booking_id' => $booking_id,
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Direct Thermal Print</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #f4f5f7;
            color: #111827;
            font-family: "Segoe UI", sans-serif;
        }
        .panel {
            width: min(520px, calc(100vw - 32px));
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
            padding: 24px;
            text-align: center;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 22px;
        }
        p {
            margin: 8px 0;
            line-height: 1.5;
            color: #4b5563;
        }
        .status {
            margin: 18px 0;
            padding: 12px;
            border-radius: 8px;
            background: #eef7ff;
            color: #17415f;
            font-weight: 600;
        }
        .status.error {
            background: #ffebee;
            color: #b42318;
        }
        .status.success {
            background: #e8f5e8;
            color: #226c30;
        }
        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        a, button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            background: #111827;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }
        button {
            background: #e91e63;
        }
        code {
            background: #f1f5f9;
            padding: 2px 5px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <main class="panel">
        <h1>Direct Thermal Print</h1>
        <p>The deployed server cannot access your USB printer directly, so this page is sending the raw ticket to the Havenzen print bridge on this laptop.</p>
        <div class="status" id="printStatus">Connecting to local print bridge...</div>
        <p>Before printing from the deployed website, run <code>start_thermal_print_bridge.bat</code> on this laptop and keep it open.</p>
        <div class="actions">
            <button type="button" id="retryPrint">Retry Direct Print</button>
            <a href="print_ticket.php?booking_id=<?php echo $booking_id; ?>" target="_blank" rel="noopener">Preview Ticket</a>
            <a href="bookings.php">Back to Bookings</a>
        </div>
    </main>

    <script>
    const payload = <?php echo json_encode($receiptPayload, JSON_UNESCAPED_SLASHES); ?>;
    const bridgeUrls = [
        'http://127.0.0.1:8765/print',
        'http://localhost:8765/print'
    ];
    const statusEl = document.getElementById('printStatus');
    const retryBtn = document.getElementById('retryPrint');

    async function sendToBridge() {
        statusEl.className = 'status';
        statusEl.textContent = 'Connecting to local print bridge...';

        let lastError = null;
        for (const url of bridgeUrls) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Print bridge returned HTTP ' + response.status);
                }

                statusEl.className = 'status success';
                statusEl.textContent = data.message || 'Ticket sent to thermal printer.';
                return;
            } catch (error) {
                lastError = error;
            }
        }

        statusEl.className = 'status error';
        statusEl.textContent = 'Could not reach the local print bridge. Start start_thermal_print_bridge.bat on this laptop, then press Retry Direct Print. ' + (lastError ? lastError.message : '');
    }

    retryBtn.addEventListener('click', sendToBridge);
    window.addEventListener('load', sendToBridge);
    </script>
</body>
</html>
    <?php
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
