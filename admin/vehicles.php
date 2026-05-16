<?php
require_once 'auth.php';
$page_title = "Vehicle Management";
require_once 'header.php';

function hz_normalize_license_plate(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', '', $value)));
}

function hz_license_plate_exists($conn, string $licensePlate, ?int $excludeVehicleId = null): bool
{
    $sql = "SELECT vehicle_id FROM vehicles WHERE UPPER(license_plate) = ?";
    if ($excludeVehicleId !== null) {
        $sql .= " AND vehicle_id <> ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($excludeVehicleId !== null) {
        $stmt->bind_param('si', $licensePlate, $excludeVehicleId);
    } else {
        $stmt->bind_param('s', $licensePlate);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}
?>

<style>
    /* Optimize table layout to prevent columns from taking too much space */
    .table-container table {
        table-layout: fixed;
        width: 100%;
    }
    
    .table-container th, .table-container td {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: middle;
    }

    /* ID */
    .table-container th:nth-child(1), .table-container td:nth-child(1) { width: 50px; }
    /* Vehicle Name */
    .table-container th:nth-child(2), .table-container td:nth-child(2) { width: 15%; }
    /* License Plate */
    .table-container th:nth-child(3), .table-container td:nth-child(3) { width: 110px; }
    /* Vehicle Type */
    .table-container th:nth-child(4), .table-container td:nth-child(4) { width: 90px; }
    /* Seat Capacity */
    .table-container th:nth-child(5), .table-container td:nth-child(5) { width: 90px; }
    /* Assigned Route - Constrain width */
    .table-container th:nth-child(6), .table-container td:nth-child(6) { width: 20%; }
    /* Assigned Driver */
    .table-container th:nth-child(7), .table-container td:nth-child(7) { width: 15%; }
    /* Status */
    .table-container th:nth-child(8), .table-container td:nth-child(8) { width: 90px; }
    /* Actions */
    .table-container th:nth-child(9), .table-container td:nth-child(9) { width: 150px; }
</style>

<?php
// Handle form submissions
if (isset($_POST['action']) && $_POST['action'] == 'add_vehicle') {
    $vehicle_name = $conn->real_escape_string($_POST['vehicle_name']);
    $license_plate_raw = hz_normalize_license_plate($_POST['license_plate'] ?? '');
    $license_plate = $conn->real_escape_string($license_plate_raw);
    $assigned_driver_id = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
    $driver_id = $assigned_driver_id === null ? 'NULL' : $assigned_driver_id;
    $route_id = $_POST['route_id'] ?: null;
    $route_id = $route_id === null ? 'NULL' : intval($route_id);
    $return_route_id = $_POST['return_route_id'] ?: null;
    $return_route_id = $return_route_id === null ? 'NULL' : intval($return_route_id);
    $status = $conn->real_escape_string($_POST['status']);
    $vehicle_type = $conn->real_escape_string($_POST['vehicle_type'] ?? '');
    $vehicle_color = $conn->real_escape_string($_POST['vehicle_color'] ?? '');
    $seat_capacity = max(0, intval($_POST['seat_capacity'] ?? 0));

    if (hz_license_plate_exists($conn, $license_plate_raw)) {
        $error = "Plate number already exists.";
    } else {
        $sql = "INSERT INTO vehicles (vehicle_name, license_plate, driver_id, route_id, return_route_id, status, vehicle_type, vehicle_color, seat_capacity) 
                VALUES ('$vehicle_name', '$license_plate', $driver_id, $route_id, $return_route_id, '$status', '$vehicle_type', '$vehicle_color', $seat_capacity)";

        if ($conn->query($sql)) {
            $message = "Vehicle added successfully!";
            // Log creation by admin
            $adminId = $_SESSION['user_id'] ?? null;
            $newId = $conn->insert_id;
            if ($assigned_driver_id !== null) {
                $conn->query("UPDATE vehicles SET driver_id = NULL WHERE driver_id = {$assigned_driver_id} AND vehicle_id <> {$newId}");
            }
            logCRUD($conn, $adminId, 'CREATE', 'vehicles', $newId, 'Added vehicle: ' . $vehicle_name);
        } else {
            $error = intval($conn->errno) === 1062 ? "Plate number already exists." : "Error adding vehicle: " . $conn->error;
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] == 'update_vehicle') {
    $vehicle_id = intval($_POST['vehicle_id']);
    $vehicle_name = $conn->real_escape_string($_POST['vehicle_name']);
    $license_plate_raw = hz_normalize_license_plate($_POST['license_plate'] ?? '');
    $license_plate = $conn->real_escape_string($license_plate_raw);
    $assigned_driver_id = !empty($_POST['driver_id']) ? intval($_POST['driver_id']) : null;
    $driver_id = $assigned_driver_id === null ? 'NULL' : $assigned_driver_id;
    $route_id = $_POST['route_id'] ?: null;
    $route_id = $route_id === null ? 'NULL' : intval($route_id);
    $return_route_id = $_POST['return_route_id'] ?: null;
    $return_route_id = $return_route_id === null ? 'NULL' : intval($return_route_id);
    $status = $conn->real_escape_string($_POST['status']);
    $vehicle_type = $conn->real_escape_string($_POST['vehicle_type'] ?? '');
    $vehicle_color = $conn->real_escape_string($_POST['vehicle_color'] ?? '');
    $seat_capacity = max(0, intval($_POST['seat_capacity'] ?? 0));

    if (hz_license_plate_exists($conn, $license_plate_raw, $vehicle_id)) {
        $error = "Plate number already exists.";
    } else {
        $sql = "UPDATE vehicles SET 
                vehicle_name = '$vehicle_name', 
                license_plate = '$license_plate', 
                driver_id = $driver_id, 
                route_id = $route_id,
                return_route_id = $return_route_id,
                status = '$status',
                vehicle_type = '$vehicle_type',
                vehicle_color = '$vehicle_color',
                seat_capacity = $seat_capacity
                WHERE vehicle_id = $vehicle_id";

        if ($conn->query($sql)) {
            $message = "Vehicle updated successfully!";
            // Log update by admin
            $adminId = $_SESSION['user_id'] ?? null;
            if ($assigned_driver_id !== null) {
                $conn->query("UPDATE vehicles SET driver_id = NULL WHERE driver_id = {$assigned_driver_id} AND vehicle_id <> {$vehicle_id}");
            }
            logCRUD($conn, $adminId, 'UPDATE', 'vehicles', $vehicle_id, 'Updated vehicle: ' . $vehicle_name);
        } else {
            $error = intval($conn->errno) === 1062 ? "Plate number already exists." : "Error updating vehicle: " . $conn->error;
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $vehicle_id = intval($_GET['id']);
    if ($conn->query("DELETE FROM vehicles WHERE vehicle_id = $vehicle_id")) {
        $message = "Vehicle deleted successfully!";
        // Log delete by admin
        $adminId = $_SESSION['user_id'] ?? null;
        logCRUD($conn, $adminId, 'DELETE', 'vehicles', $vehicle_id, 'Deleted vehicle ID: ' . $vehicle_id);
    } else {
        $error = "Error deleting vehicle: " . $conn->error;
    }
}
?>

<?php if (isset($message)): ?>
    <div style="background: #e8f5e8; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<?php
// Fetch routes for dropdowns
$routes_list = [];
$routes_res = $conn->query("SELECT route_id, route_name FROM routes ORDER BY route_name ASC");
if ($routes_res) {
    while ($r = $routes_res->fetch_assoc()) {
        $routes_list[] = $r;
    }
}
?>

<!-- Add Vehicle: show edit form if editing, otherwise provide Add button + modal -->
<?php
$edit_vehicle = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_vehicle = $conn->query("SELECT * FROM vehicles WHERE vehicle_id = $edit_id")->fetch_assoc();
}
?>

<?php if ($edit_vehicle): ?>
    <script>
        // Auto-open edit modal if edit parameter is present
        document.addEventListener('DOMContentLoaded', function () {
            openEditModal(<?php echo json_encode($edit_vehicle); ?>);
        });
    </script>
<?php endif; ?>

<div style="margin-bottom:20px; display:flex; justify-content:flex-end;">
    <button class="btn btn-primary" id="openAddVehicle">Add Vehicle</button>
</div>
<div style="background:#eef7ff; color:#17415f; padding:12px 14px; border-radius:8px; margin-bottom:20px; border-left:4px solid #2196f3;">
    Assign drivers from the Add/Edit Vehicle form. A driver can only be assigned to one van at a time; assigning them here automatically removes them from any previous van.
</div>

<!-- Add Vehicle Modal -->
<div id="addVehicleModal" class="modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000;">
    <div class="modal-content" style="background:#fff; padding:20px; border-radius:8px; width:480px; max-width:95%;">
        <h2>Add New Vehicle</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_vehicle">

            <div class="form-group">
                <label for="vehicle_name_modal">Vehicle Name</label>
                <input type="text" id="vehicle_name_modal" name="vehicle_name" required>
            </div>

            <div class="form-group">
                <label for="license_plate_modal">License Plate</label>
                <input type="text" id="license_plate_modal" name="license_plate" placeholder="ABC-1234"
                    pattern="^[A-Z]{2,3}-\d{4}$"
                    title="Please enter a valid Philippine license plate (e.g., ABC-1234 or AB-1234)" maxlength="8"
                    style="text-transform: uppercase;" required>
                <small style="color: #666; font-size: 0.85em;">Format: ABC-1234 or AB-1234</small>
            </div>

            <div class="form-group">
                <label for="vehicle_type_modal">Vehicle Type</label>
                <select id="vehicle_type_modal" name="vehicle_type" required>
                    <option value="">Select Vehicle Type</option>
                    <option value="van">Van</option>
                    <option value="bus">Bus</option>
                </select>
            </div>

            <div class="form-group">
                <label for="vehicle_color_modal">Vehicle Color</label>
                <input type="text" id="vehicle_color_modal" name="vehicle_color" required>
            </div>

            <div class="form-group">
                <label for="seat_capacity_modal">Seat Capacity</label>
                <input type="number" id="seat_capacity_modal" name="seat_capacity" min="0" required>
            </div>

            <div class="form-group">
                <label for="driver_id_modal">Assigned Driver for this Van</label>
                <select id="driver_id_modal" name="driver_id">
                    <option value="">Select Driver (Optional)</option>
                    <?php
                    $drivers = $conn->query("
                        SELECT u.user_id, d.full_name
                        FROM users u
                        JOIN drivers d ON d.user_id = u.user_id
                        WHERE u.role = 'driver'
                    ");
                    while ($driver = $drivers->fetch_assoc()):
                        ?>
                        <option value="<?php echo $driver['user_id']; ?>">
                            <?php echo htmlspecialchars($driver['full_name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="route_id_modal">Assigned Route</label>
                <select id="route_id_modal" name="route_id">
                    <option value="">Select Route (Optional)</option>
                    <?php foreach ($routes_list as $route): ?>
                        <option value="<?php echo $route['route_id']; ?>">
                            <?php echo htmlspecialchars($route['route_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="return_route_id_modal">Assigned Return Route (Vice Versa)</label>
                <select id="return_route_id_modal" name="return_route_id">
                    <option value="">Select Return Route (Optional)</option>
                    <?php foreach ($routes_list as $route): ?>
                        <option value="<?php echo $route['route_id']; ?>">
                            <?php echo htmlspecialchars($route['route_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status_modal">Status</label>
                <select id="status_modal" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-secondary" id="closeAddVehicle">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Vehicle</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Vehicle Modal -->
<div id="editVehicleModal" class="modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000;">
    <div class="modal-content" style="background:#fff; padding:20px; border-radius:8px; width:480px; max-width:95%;">
        <h2>Edit Vehicle</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_vehicle">
            <input type="hidden" id="vehicle_id_modal" name="vehicle_id">

            <div class="form-group">
                <label for="vehicle_name_modal_edit">Vehicle Name</label>
                <input type="text" id="vehicle_name_modal_edit" name="vehicle_name" required>
            </div>

            <div class="form-group">
                <label for="license_plate_modal_edit">License Plate</label>
                <input type="text" id="license_plate_modal_edit" name="license_plate" placeholder="ABC-1234"
                    pattern="^[A-Z]{2,3}-\d{4}$"
                    title="Please enter a valid Philippine license plate (e.g., ABC-1234 or AB-1234)" maxlength="8"
                    style="text-transform: uppercase;" required>
                <small style="color: #666; font-size: 0.85em;">Format: ABC-1234 or AB-1234</small>
            </div>

            <div class="form-group">
                <label for="vehicle_type_modal_edit">Vehicle Type</label>
                <select id="vehicle_type_modal_edit" name="vehicle_type" required>
                    <option value="">Select Vehicle Type</option>
                    <option value="van">Van</option>
                    <option value="bus">Bus</option>
                </select>
            </div>

            <div class="form-group">
                <label for="vehicle_color_modal_edit">Vehicle Color</label>
                <input type="text" id="vehicle_color_modal_edit" name="vehicle_color" required>
            </div>

            <div class="form-group">
                <label for="seat_capacity_modal_edit">Seat Capacity</label>
                <input type="number" id="seat_capacity_modal_edit" name="seat_capacity" min="0" required>
            </div>

            <div class="form-group">
                <label for="driver_id_modal_edit">Assigned Driver for this Van</label>
                <select id="driver_id_modal_edit" name="driver_id">
                    <option value="">Select Driver (Optional)</option>
                    <?php
                    $drivers = $conn->query("
                        SELECT u.user_id, d.full_name
                        FROM users u
                        JOIN drivers d ON d.user_id = u.user_id
                        WHERE u.role = 'driver'
                    ");
                    while ($driver = $drivers->fetch_assoc()):
                        ?>
                        <option value="<?php echo $driver['user_id']; ?>">
                            <?php echo htmlspecialchars($driver['full_name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="route_id_modal_edit">Assigned Route</label>
                <select id="route_id_modal_edit" name="route_id">
                    <option value="">Select Route (Optional)</option>
                    <?php foreach ($routes_list as $route): ?>
                        <option value="<?php echo $route['route_id']; ?>">
                            <?php echo htmlspecialchars($route['route_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="return_route_id_modal_edit">Assigned Return Route (Vice Versa)</label>
                <select id="return_route_id_modal_edit" name="return_route_id">
                    <option value="">Select Return Route (Optional)</option>
                    <?php foreach ($routes_list as $route): ?>
                        <option value="<?php echo $route['route_id']; ?>">
                            <?php echo htmlspecialchars($route['route_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="status_modal_edit">Status</label>
                <select id="status_modal_edit" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-secondary" id="closeEditVehicle">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Vehicle</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Edit Vehicle Modal handlers
    document.getElementById('closeEditVehicle').addEventListener('click', function () {
        document.getElementById('editVehicleModal').style.display = 'none';
    });
    // Close edit modal when clicking outside content
    document.getElementById('editVehicleModal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });

    // Function to open edit modal with vehicle data
    function openEditModal(vehicle) {
        document.getElementById('vehicle_id_modal').value = vehicle.vehicle_id;
        document.getElementById('vehicle_name_modal_edit').value = vehicle.vehicle_name;
        document.getElementById('license_plate_modal_edit').value = vehicle.license_plate;
        document.getElementById('driver_id_modal_edit').value = vehicle.driver_id || '';
        document.getElementById('route_id_modal_edit').value = vehicle.route_id || '';
        document.getElementById('return_route_id_modal_edit').value = vehicle.return_route_id || '';
        document.getElementById('status_modal_edit').value = vehicle.status;
        if (document.getElementById('vehicle_type_modal_edit')) {
            document.getElementById('vehicle_type_modal_edit').value = vehicle.vehicle_type || '';
        }
        if (document.getElementById('vehicle_color_modal_edit')) {
            document.getElementById('vehicle_color_modal_edit').value = vehicle.vehicle_color || '';
        }
        if (document.getElementById('seat_capacity_modal_edit')) {
            document.getElementById('seat_capacity_modal_edit').value = vehicle.seat_capacity || 0;
        }
        document.getElementById('editVehicleModal').style.display = 'flex';
    }

    // Function to open edit modal from table button
    function editVehicle(vehicleId, vehicleName, licensePlate, driverId, status, vehicleType, vehicleColor, seatCapacity, routeId, returnRouteId) {
        const vehicle = {
            vehicle_id: vehicleId,
            vehicle_name: vehicleName,
            license_plate: licensePlate,
            driver_id: driverId,
            status: status,
            vehicle_type: vehicleType,
            vehicle_color: vehicleColor,
            seat_capacity: seatCapacity,
            route_id: routeId,
            return_route_id: returnRouteId
        };
        openEditModal(vehicle);
    }
</script>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000;">
    <div class="modal-content"
        style="background:#fff; padding:20px; border-radius:8px; width:400px; max-width:95%; text-align:center;">
        <h3 style="color:#d32f2f; margin-bottom:15px;">Confirm Delete</h3>
        <p id="deleteMessage" style="margin-bottom:20px;">Are you sure you want to delete this vehicle?</p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
        </div>
    </div>
</div>

<script>
    // License Plate Input Mask Function
    function formatLicensePlate(input) {
        // Remove all non-alphanumeric characters
        let value = input.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();

        // Limit to maximum 7 characters (3 letters + 4 digits or 2 letters + 4 digits)
        if (value.length > 7) {
            value = value.substring(0, 7);
        }

        // Format based on length
        let formatted = '';
        if (value.length <= 2) {
            // Just letters
            formatted = value.replace(/[^A-Z]/g, '');
        } else if (value.length === 3) {
            // Could be 2 letters + 1 digit or 3 letters
            const letters = value.substring(0, 2).replace(/[^A-Z]/g, '');
            const third = value.charAt(2);
            if (/[A-Z]/.test(third)) {
                // Third character is a letter
                formatted = value.replace(/[^A-Z]/g, '');
            } else {
                // Third character is a digit, insert hyphen
                formatted = letters + '-' + third;
            }
        } else if (value.length > 3) {
            // Determine if it's 2-letter or 3-letter format
            const firstTwo = value.substring(0, 2);
            const third = value.charAt(2);

            if (/[A-Z]/.test(third)) {
                // 3-letter format (ABC-1234)
                const letters = value.substring(0, 3).replace(/[^A-Z]/g, '');
                const digits = value.substring(3).replace(/[^0-9]/g, '').substring(0, 4);
                formatted = letters + (digits ? '-' + digits : '');
            } else {
                // 2-letter format (AB-1234)
                const letters = firstTwo.replace(/[^A-Z]/g, '');
                const digits = value.substring(2).replace(/[^0-9]/g, '').substring(0, 4);
                formatted = letters + (digits ? '-' + digits : '');
            }
        }

        input.value = formatted;
    }

    // Modal handlers for Add Vehicle
    document.getElementById('openAddVehicle').addEventListener('click', function () {
        document.getElementById('addVehicleModal').style.display = 'flex';
    });
    document.getElementById('closeAddVehicle').addEventListener('click', function () {
        document.getElementById('addVehicleModal').style.display = 'none';
    });
    // Close add modal when clicking outside content
    document.getElementById('addVehicleModal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });

    // Delete confirmation modal handlers
    let deleteUrl = '';
    document.addEventListener('DOMContentLoaded', function () {
        const cancelBtn = document.getElementById('cancelDelete');
        const confirmBtn = document.getElementById('confirmDelete');
        const modal = document.getElementById('deleteConfirmModal');
        const licensePlateAdd = document.getElementById('license_plate_modal');
        const licensePlateEdit = document.getElementById('license_plate_modal_edit');

        // Apply input mask to license plate fields
        if (licensePlateAdd) {
            licensePlateAdd.addEventListener('input', function (e) {
                formatLicensePlate(e.target);
            });

            licensePlateAdd.addEventListener('paste', function (e) {
                setTimeout(() => formatLicensePlate(e.target), 0);
            });
        }

        if (licensePlateEdit) {
            licensePlateEdit.addEventListener('input', function (e) {
                formatLicensePlate(e.target);
            });

            licensePlateEdit.addEventListener('paste', function (e) {
                setTimeout(() => formatLicensePlate(e.target), 0);
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                modal.style.display = 'none';
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (deleteUrl) {
                    window.location.href = deleteUrl;
                }
            });
        }

        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === this) this.style.display = 'none';
            });
        }
    });

    // Function to show delete confirmation
    function showDeleteConfirm(url, vehicleName) {
        deleteUrl = url;
        document.getElementById('deleteMessage').textContent = `Are you sure you want to delete "${vehicleName}"? This action cannot be undone.`;
        document.getElementById('deleteConfirmModal').style.display = 'flex';
        return false; // Prevent default link action
    }
</script>

<!-- Vehicles List -->
<div class="table-container">
    <div class="table-header">
        <h2>All Vehicles</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Vehicle Name</th>
                <th>License Plate</th>
                <th>Vehicle Type</th>
                <th>Seats</th>
                <th>Assigned Route</th>
                <th>Assigned Driver</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $vehicles = $conn->query("
                SELECT v.*, d.full_name as driver_name, r.route_name, rr.route_name as return_route_name
                FROM vehicles v 
                LEFT JOIN drivers d ON v.driver_id = d.user_id 
                LEFT JOIN routes r ON v.route_id = r.route_id
                LEFT JOIN routes rr ON v.return_route_id = rr.route_id
                ORDER BY v.created_at DESC
            ");

            while ($vehicle = $vehicles->fetch_assoc()):
                ?>
                <tr>
                    <td><?php echo $vehicle['vehicle_id']; ?></td>
                    <td><?php echo htmlspecialchars($vehicle['vehicle_name']); ?></td>
                    <td><?php echo htmlspecialchars($vehicle['license_plate']); ?></td>
                    <td><?php echo $vehicle['vehicle_type'] ? ucfirst(htmlspecialchars($vehicle['vehicle_type'])) : 'N/A'; ?>
                    </td>
                    <td><?php echo intval($vehicle['seat_capacity'] ?? 0); ?></td>
                    <td>
                        <?php echo $vehicle['route_name'] ? htmlspecialchars($vehicle['route_name']) : '<em style="color: #999;">Unassigned</em>'; ?>
                        <?php if ($vehicle['return_route_name']): ?>
                            <div style="font-size: 0.85em; color: #666; margin-top: 4px;">
                                <i class="fas fa-exchange-alt" style="margin-right: 4px;"></i>
                                <?php echo htmlspecialchars($vehicle['return_route_name']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $vehicle['driver_name'] ? htmlspecialchars($vehicle['driver_name']) : '<em style="color: #999;">To be assigned</em>'; ?>
                    </td>
                    <td><span
                            class="status-badge status-<?php echo $vehicle['status']; ?>"><?php echo ucfirst($vehicle['status']); ?></span>
                    </td>
                    <td>
                        <button type="button" class="btn btn-secondary" onclick="editVehicle(
                                <?php echo $vehicle['vehicle_id']; ?>,
                                '<?php echo addslashes($vehicle['vehicle_name']); ?>',
                                '<?php echo addslashes($vehicle['license_plate']); ?>',
                                <?php echo $vehicle['driver_id'] ?: 'null'; ?>,
                                '<?php echo $vehicle['status']; ?>',
                                '<?php echo addslashes($vehicle['vehicle_type'] ?? ''); ?>',
                                '<?php echo addslashes($vehicle['vehicle_color'] ?? ''); ?>',
                                <?php echo intval($vehicle['seat_capacity'] ?? 0); ?>,
                                <?php echo $vehicle['route_id'] ?: 'null'; ?>,
                                <?php echo $vehicle['return_route_id'] ?: 'null'; ?>
                            )">Edit</button>
                        <button type="button" class="btn btn-danger"
                            onclick="showDeleteConfirm('vehicles.php?action=delete&id=<?php echo $vehicle['vehicle_id']; ?>', '<?php echo addslashes($vehicle['vehicle_name']); ?>')">Delete</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php require_once 'footer.php'; ?>
