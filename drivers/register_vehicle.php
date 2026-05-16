<?php
require_once 'auth.php';
require_once 'header.php';

$message = '';
$error = '';

function hz_driver_plate_normalize(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', '', $value)));
}

function hz_driver_plate_exists($conn, string $plate, int $excludeVehicleId = 0): bool
{
    $sql = 'SELECT vehicle_id FROM vehicles WHERE UPPER(license_plate) = ?';
    if ($excludeVehicleId > 0) {
        $sql .= ' AND vehicle_id <> ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($excludeVehicleId > 0) {
        $stmt->bind_param('si', $plate, $excludeVehicleId);
    } else {
        $stmt->bind_param('s', $plate);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

$driverUserId = intval($_SESSION['user_id'] ?? 0);
$vehicleResult = $conn->query("SELECT * FROM vehicles WHERE driver_id = {$driverUserId} LIMIT 1");
$vehicle = $vehicleResult && $vehicleResult->num_rows > 0 ? $vehicleResult->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId = intval($vehicle['vehicle_id'] ?? 0);
    $vehicleName = trim($_POST['vehicle_name'] ?? '');
    $licensePlate = hz_driver_plate_normalize($_POST['license_plate'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $vehicleColor = trim($_POST['vehicle_color'] ?? '');
    $seatCapacity = max(1, intval($_POST['seat_capacity'] ?? 0));

    if ($vehicleName === '' || $licensePlate === '') {
        $error = 'Vehicle name and plate number are required.';
    } elseif (hz_driver_plate_exists($conn, $licensePlate, $vehicleId)) {
        $error = 'Plate number already exists.';
    } else {
        if ($vehicleId > 0) {
            $stmt = $conn->prepare("
                UPDATE vehicles
                SET vehicle_name = ?, license_plate = ?, vehicle_type = ?, vehicle_color = ?, seat_capacity = ?, status = 'inactive'
                WHERE vehicle_id = ? AND driver_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('ssssiii', $vehicleName, $licensePlate, $vehicleType, $vehicleColor, $seatCapacity, $vehicleId, $driverUserId);
                $ok = $stmt->execute();
                $stmt->close();
                $message = $ok ? 'Vehicle updated successfully.' : 'Unable to update vehicle.';
            } else {
                $error = 'Unable to prepare vehicle update.';
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO vehicles (vehicle_name, license_plate, vehicle_type, vehicle_color, seat_capacity, driver_id, status)
                VALUES (?, ?, ?, ?, ?, ?, 'inactive')
            ");
            if ($stmt) {
                $stmt->bind_param('ssssii', $vehicleName, $licensePlate, $vehicleType, $vehicleColor, $seatCapacity, $driverUserId);
                $ok = $stmt->execute();
                $stmt->close();
                $message = $ok ? 'Vehicle registered successfully. Set yourself online on the map when ready.' : 'Unable to register vehicle.';
            } else {
                $error = 'Unable to prepare vehicle registration.';
            }
        }

        $vehicleResult = $conn->query("SELECT * FROM vehicles WHERE driver_id = {$driverUserId} LIMIT 1");
        $vehicle = $vehicleResult && $vehicleResult->num_rows > 0 ? $vehicleResult->fetch_assoc() : null;
    }
}

$vehicleNameValue = htmlspecialchars($vehicle['vehicle_name'] ?? '', ENT_QUOTES, 'UTF-8');
$plateValue = htmlspecialchars($vehicle['license_plate'] ?? '', ENT_QUOTES, 'UTF-8');
$typeValue = htmlspecialchars($vehicle['vehicle_type'] ?? '', ENT_QUOTES, 'UTF-8');
$colorValue = htmlspecialchars($vehicle['vehicle_color'] ?? '', ENT_QUOTES, 'UTF-8');
$seatValue = intval($vehicle['seat_capacity'] ?? 14);
?>

<div class="dashboard-header">
    <h1>Register Vehicle</h1>
    <p>Add or update the vehicle assigned to your driver account.</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="form-container vehicle-register-panel">
    <form method="POST" action="register_vehicle.php">
        <div class="form-grid">
            <div class="form-group">
                <label for="vehicle_name">Vehicle Name</label>
                <input type="text" id="vehicle_name" name="vehicle_name" class="form-control" value="<?php echo $vehicleNameValue; ?>" required>
            </div>
            <div class="form-group">
                <label for="license_plate">Plate Number</label>
                <input type="text" id="license_plate" name="license_plate" class="form-control" value="<?php echo $plateValue; ?>" required>
            </div>
            <div class="form-group">
                <label for="vehicle_type">Vehicle Type</label>
                <input type="text" id="vehicle_type" name="vehicle_type" class="form-control" value="<?php echo $typeValue; ?>" placeholder="Van, Bus, Car">
            </div>
            <div class="form-group">
                <label for="vehicle_color">Color</label>
                <input type="text" id="vehicle_color" name="vehicle_color" class="form-control" value="<?php echo $colorValue; ?>">
            </div>
            <div class="form-group">
                <label for="seat_capacity">Seat Capacity</label>
                <input type="number" id="seat_capacity" name="seat_capacity" class="form-control" value="<?php echo $seatValue; ?>" min="1" max="99">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Vehicle
            </button>
            <a href="index.php" class="btn btn-secondary">Back</a>
        </div>
    </form>
</div>

<?php require_once 'footer.php'; ?>
