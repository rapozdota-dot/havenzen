<?php
require_once 'auth.php';
require_once '../lib/vehicle_helpers.php';

$page_title = 'Routes Management';

$action = $_POST['action'] ?? null;

function hz_post_int_or_null(string $key): ?int
{
    $value = $_POST[$key] ?? '';
    return $value === '' ? null : max(0, intval($value));
}

function hz_apply_route_assignment($conn, int $routeId, ?int $returnRouteId, ?int $vehicleId, ?int $driverId, &$message, &$error): void
{
    if ($routeId <= 0 || ($vehicleId === null && $driverId === null)) {
        return;
    }

    if ($vehicleId === null && $driverId !== null) {
        $vehicleResult = $conn->query("SELECT vehicle_id FROM vehicles WHERE driver_id = {$driverId} ORDER BY vehicle_name ASC LIMIT 1");
        if ($vehicleResult && $vehicleResult->num_rows > 0) {
            $vehicleId = intval($vehicleResult->fetch_assoc()['vehicle_id'] ?? 0);
        } else {
            $error = trim(($error ?? '') . ' Route saved, but that driver has no assigned vehicle yet.');
            return;
        }
    }

    if (!$vehicleId) {
        return;
    }

    if ($driverId !== null) {
        $conn->query("UPDATE vehicles SET driver_id = NULL WHERE driver_id = {$driverId} AND vehicle_id <> {$vehicleId}");
    }

    $driverSql = $driverId === null ? '' : ", driver_id = {$driverId}";
    $returnSql = $returnRouteId === null ? '' : ", return_route_id = {$returnRouteId}";
    if ($conn->query("UPDATE vehicles SET route_id = {$routeId}{$returnSql}{$driverSql} WHERE vehicle_id = {$vehicleId}")) {
        $message = trim(($message ?? '') . ' Route assignment updated.');
    } else {
        $error = trim(($error ?? '') . ' Route assignment failed: ' . $conn->error);
    }
}

if ($action === 'add_route') {
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $fare = floatval($_POST['fare'] ?? 0);
    $distance_km = floatval($_POST['distance_km'] ?? 0);
    $travel_minutes = max(0, intval($_POST['travel_minutes'] ?? 0));
    $create_return = isset($_POST['create_return']);
    $assigned_vehicle_id = hz_post_int_or_null('assignment_vehicle_id');
    $assigned_driver_id = hz_post_int_or_null('assignment_driver_id');

    if ($origin === '' || $destination === '') {
        $error = 'Please provide both origin and destination.';
    } else {
        $route_name_raw = $origin . ' to ' . $destination;
        $route_name = $conn->real_escape_string($route_name_raw);
        $stops_json = $conn->real_escape_string(json_encode([
            ['name' => $origin],
            ['name' => $destination],
        ]));

        $sql = "
            INSERT INTO routes (route_name, fare, distance_km, travel_minutes, stops)
            VALUES ('$route_name', $fare, $distance_km, $travel_minutes, '$stops_json')
        ";

        if ($conn->query($sql)) {
            $message = 'Route added successfully.';
            $routeId = $conn->insert_id;
            $returnRouteId = null;
            logCRUD($conn, $_SESSION['user_id'] ?? null, 'CREATE', 'routes', $routeId, 'Added route: ' . $route_name_raw);

            if ($create_return) {
                $return_route_name_raw = $destination . ' to ' . $origin;
                $return_route_name = $conn->real_escape_string($return_route_name_raw);
                $return_stops_json = $conn->real_escape_string(json_encode([
                    ['name' => $destination],
                    ['name' => $origin],
                ]));

                $sqlReturn = "
                    INSERT INTO routes (route_name, fare, distance_km, travel_minutes, stops)
                    VALUES ('$return_route_name', $fare, $distance_km, $travel_minutes, '$return_stops_json')
                ";

                if ($conn->query($sqlReturn)) {
                    $returnRouteId = $conn->insert_id;
                    logCRUD($conn, $_SESSION['user_id'] ?? null, 'CREATE', 'routes', $returnRouteId, 'Added return route: ' . $return_route_name_raw);
                    $message .= ' Return route created as well.';
                } else {
                    $error = 'Route added, but failed to create return route: ' . $conn->error;
                }
            }

            hz_apply_route_assignment($conn, intval($routeId), $returnRouteId, $assigned_vehicle_id, $assigned_driver_id, $message, $error);
        } else {
            $error = 'Error adding route: ' . $conn->error;
        }
    }
}

if ($action === 'update_route') {
    $route_id = intval($_POST['route_id'] ?? 0);
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $fare = floatval($_POST['fare'] ?? 0);
    $distance_km = floatval($_POST['distance_km'] ?? 0);
    $travel_minutes = max(0, intval($_POST['travel_minutes'] ?? 0));
    $assigned_vehicle_id = hz_post_int_or_null('assignment_vehicle_id');
    $assigned_driver_id = hz_post_int_or_null('assignment_driver_id');

    if ($route_id <= 0 || $origin === '' || $destination === '') {
        $error = 'Invalid route data. Origin and destination are required.';
    } else {
        $route_name_raw = $origin . ' to ' . $destination;
        $route_name = $conn->real_escape_string($route_name_raw);
        $stops_json = $conn->real_escape_string(json_encode([
            ['name' => $origin],
            ['name' => $destination],
        ]));

        $sql = "
            UPDATE routes
            SET route_name = '$route_name',
                fare = $fare,
                distance_km = $distance_km,
                travel_minutes = $travel_minutes,
                stops = '$stops_json'
            WHERE route_id = $route_id
        ";

        if ($conn->query($sql)) {
            $message = 'Route updated successfully.';
            logCRUD($conn, $_SESSION['user_id'] ?? null, 'UPDATE', 'routes', $route_id, 'Updated route: ' . $route_name_raw);
            hz_apply_route_assignment($conn, $route_id, null, $assigned_vehicle_id, $assigned_driver_id, $message, $error);
        } else {
            $error = 'Error updating route: ' . $conn->error;
        }
    }
}

if ($action === 'delete_route') {
    $route_id = intval($_POST['route_id'] ?? 0);
    if ($route_id > 0) {
        $routeRow = $conn->query("SELECT route_name FROM routes WHERE route_id = $route_id");
        $routeName = $routeRow && $routeRow->num_rows ? ($routeRow->fetch_assoc()['route_name'] ?? ('ID ' . $route_id)) : ('ID ' . $route_id);

        if ($conn->query("DELETE FROM routes WHERE route_id = $route_id")) {
            $message = 'Route deleted successfully.';
            logCRUD($conn, $_SESSION['user_id'] ?? null, 'DELETE', 'routes', $route_id, 'Deleted route: ' . $routeName);
        } else {
            $error = 'Error deleting route: ' . $conn->error;
        }
    }
}

$routes = [];
$routeResult = $conn->query("SELECT * FROM routes ORDER BY created_at DESC");
if ($routeResult) {
    while ($row = $routeResult->fetch_assoc()) {
        $stops = json_decode($row['stops'], true);
        $row['origin'] = '';
        $row['destination'] = '';
        $row['stop_count'] = is_array($stops) ? count($stops) : 0;
        if (is_array($stops) && count($stops) >= 2) {
            $row['origin'] = $stops[0]['name'] ?? '';
            $row['destination'] = $stops[count($stops) - 1]['name'] ?? '';
        }
        $row['assignment_vehicle_id'] = '';
        $row['assignment_driver_id'] = '';
        $row['assignment_label'] = '<em style="color:#777;">Unassigned</em>';

        $routeId = intval($row['route_id']);
        $assignmentResult = $conn->query("
            SELECT v.vehicle_id, v.vehicle_name, v.license_plate, v.vehicle_model, v.vehicle_type, v.driver_id, d.full_name AS driver_name
            FROM vehicles v
            LEFT JOIN drivers d ON d.user_id = v.driver_id
            WHERE v.route_id = {$routeId}
            ORDER BY v.vehicle_name ASC
        ");
        if ($assignmentResult && $assignmentResult->num_rows > 0) {
            $assignmentParts = [];
            while ($assignment = $assignmentResult->fetch_assoc()) {
                if ($row['assignment_vehicle_id'] === '') {
                    $row['assignment_vehicle_id'] = intval($assignment['vehicle_id']);
                    $row['assignment_driver_id'] = $assignment['driver_id'] ? intval($assignment['driver_id']) : '';
                }
                $vehicleLabel = hz_vehicle_display_label($assignment);
                $driverLabel = $assignment['driver_name'] ? ' - ' . $assignment['driver_name'] : ' - no driver';
                $assignmentParts[] = htmlspecialchars($vehicleLabel . $driverLabel);
            }
            $row['assignment_label'] = implode('<br>', $assignmentParts);
        }
        $routes[] = $row;
    }
}

$drivers_list = [];
$driversResult = $conn->query("
    SELECT u.user_id, d.full_name
    FROM users u
    JOIN drivers d ON d.user_id = u.user_id
    WHERE u.role = 'driver'
      AND d.approval_status = 'approved'
    ORDER BY d.full_name ASC
");
if ($driversResult) {
    while ($driver = $driversResult->fetch_assoc()) {
        $drivers_list[] = $driver;
    }
}

$vehicles_list = [];
$vehiclesResult = $conn->query("
    SELECT v.vehicle_id, v.vehicle_name, v.license_plate, v.vehicle_model, v.vehicle_type, v.driver_id, d.full_name AS driver_name
    FROM vehicles v
    LEFT JOIN drivers d ON d.user_id = v.driver_id
    ORDER BY v.vehicle_name ASC
");
if ($vehiclesResult) {
    while ($vehicle = $vehiclesResult->fetch_assoc()) {
        $vehicles_list[] = $vehicle;
    }
}

require_once 'header.php';
?>

<?php if (isset($message)): ?>
    <div class="notification success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="notification error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="section-header">
    <h2>Routes List</h2>
    <div class="section-actions">
        <button class="btn btn-primary" id="openAddRoute">Add Route</button>
    </div>
</div>

<div id="addRouteModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000;">
    <div class="modal-content" style="background:#fff; padding:20px; border-radius:8px; width:560px; max-width:95%;">
        <h2>Add New Route</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_route">

            <div class="form-group">
                <label for="origin_modal">From (Origin)</label>
                <input type="text" id="origin_modal" name="origin" required placeholder="e.g., Barugo, Leyte, Philippines">
            </div>

            <div class="form-group">
                <label for="destination_modal">To (Destination)</label>
                <input type="text" id="destination_modal" name="destination" required placeholder="e.g., Tacloban, Leyte, Philippines">
            </div>

            <div class="form-group">
                <label for="fare_modal">Fare (PHP)</label>
                <input type="number" id="fare_modal" name="fare" step="0.01" min="0" required>
            </div>

            <div class="form-group">
                <label for="distance_modal">Distance (km)</label>
                <input type="number" id="distance_modal" name="distance_km" step="0.01" min="0" required placeholder="e.g., 45.33">
            </div>

            <div class="form-group">
                <label for="travel_minutes_modal">Travel Time (minutes)</label>
                <input type="number" id="travel_minutes_modal" name="travel_minutes" min="0" required placeholder="e.g., 75">
            </div>

            <div class="form-group">
                <label for="assignment_vehicle_id_modal">Vehicle Taking This Route</label>
                <select id="assignment_vehicle_id_modal" name="assignment_vehicle_id">
                    <option value="">Select Vehicle (Optional)</option>
                    <?php foreach ($vehicles_list as $vehicle): ?>
                        <option value="<?php echo intval($vehicle['vehicle_id']); ?>">
                            <?php
                            echo htmlspecialchars(hz_vehicle_display_label($vehicle, false, true));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Selecting a vehicle assigns this route to that vehicle.</small>
            </div>

            <div class="form-group">
                <label for="assignment_driver_id_modal">Driver Taking This Route</label>
                <select id="assignment_driver_id_modal" name="assignment_driver_id">
                    <option value="">Keep Vehicle Driver / Select Driver (Optional)</option>
                    <?php foreach ($drivers_list as $driver): ?>
                        <option value="<?php echo intval($driver['user_id']); ?>">
                            <?php echo htmlspecialchars($driver['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>If both vehicle and driver are selected, that driver will be moved to this vehicle.</small>
            </div>

            <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                <input type="checkbox" id="create_return_modal" name="create_return" style="width:auto; margin:0;">
                <label for="create_return_modal" style="margin:0;">Create return route (vice versa)</label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-secondary" id="closeAddRoute">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Route</button>
            </div>
        </form>
    </div>
</div>

<div id="editRouteModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000;">
    <div class="modal-content" style="background:#fff; padding:20px; border-radius:8px; width:560px; max-width:95%;">
        <h2>Edit Route</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_route">
            <input type="hidden" name="route_id" id="edit_route_id">

            <div class="form-group">
                <label for="edit_origin">From (Origin)</label>
                <input type="text" id="edit_origin" name="origin" required>
            </div>

            <div class="form-group">
                <label for="edit_destination">To (Destination)</label>
                <input type="text" id="edit_destination" name="destination" required>
            </div>

            <div class="form-group">
                <label for="edit_fare">Fare (PHP)</label>
                <input type="number" id="edit_fare" name="fare" step="0.01" min="0" required>
            </div>

            <div class="form-group">
                <label for="edit_distance">Distance (km)</label>
                <input type="number" id="edit_distance" name="distance_km" step="0.01" min="0" required>
            </div>

            <div class="form-group">
                <label for="edit_travel_minutes">Travel Time (minutes)</label>
                <input type="number" id="edit_travel_minutes" name="travel_minutes" min="0" required>
            </div>

            <div class="form-group">
                <label for="edit_assignment_vehicle_id">Vehicle Taking This Route</label>
                <select id="edit_assignment_vehicle_id" name="assignment_vehicle_id">
                    <option value="">No vehicle change</option>
                    <?php foreach ($vehicles_list as $vehicle): ?>
                        <option value="<?php echo intval($vehicle['vehicle_id']); ?>">
                            <?php
                            echo htmlspecialchars(hz_vehicle_display_label($vehicle, false, true));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="edit_assignment_driver_id">Driver Taking This Route</label>
                <select id="edit_assignment_driver_id" name="assignment_driver_id">
                    <option value="">No driver change</option>
                    <?php foreach ($drivers_list as $driver): ?>
                        <option value="<?php echo intval($driver['user_id']); ?>">
                            <?php echo htmlspecialchars($driver['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                <button type="button" class="btn btn-secondary" id="closeEditRoute">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteConfirmModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:2000;">
    <div class="modal-content" style="background:#fff; padding:20px; border-radius:8px; width:400px; max-width:95%; text-align:center;">
        <h3 style="color:#d32f2f; margin-bottom:15px;">Confirm Delete</h3>
        <p id="deleteMessage" style="margin-bottom:20px;">Are you sure you want to delete this route?</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete_route">
            <input type="hidden" name="route_id" id="delete_route_id">
            <div style="display:flex; justify-content:center; gap:10px;">
                <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th style="width: 5%;">ID</th>
                <th style="width: 22%;">Route Name</th>
                <th style="width: 10%;">Fare</th>
                <th style="width: 10%;">Distance</th>
                <th style="width: 10%;">Travel Time</th>
                <th style="width: 10%;">Stops</th>
                <th style="width: 15%;">Assigned Vehicle / Driver</th>
                <th style="width: 12%;">Created Date</th>
                <th style="width: 18%;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$routes): ?>
                <tr>
                    <td colspan="9">No routes found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($routes as $route): ?>
                <tr>
                    <td><?php echo intval($route['route_id']); ?></td>
                    <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                    <td>PHP <?php echo number_format((float) $route['fare'], 2); ?></td>
                    <td><?php echo number_format((float) $route['distance_km'], 2); ?> km</td>
                    <td><?php echo intval($route['travel_minutes'] ?? 0); ?> mins</td>
                    <td><?php echo intval($route['stop_count']); ?> stops</td>
                    <td><?php echo $route['assignment_label']; ?></td>
                    <td><?php echo date('M j, Y', strtotime($route['created_at'])); ?></td>
                    <td style="white-space: nowrap;">
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm edit-route-btn"
                            data-route-id="<?php echo intval($route['route_id']); ?>"
                            data-route-origin="<?php echo htmlspecialchars($route['origin']); ?>"
                            data-route-destination="<?php echo htmlspecialchars($route['destination']); ?>"
                            data-route-fare="<?php echo htmlspecialchars($route['fare']); ?>"
                            data-route-distance="<?php echo htmlspecialchars($route['distance_km']); ?>"
                            data-route-travel-minutes="<?php echo intval($route['travel_minutes'] ?? 0); ?>"
                            data-route-assignment-vehicle-id="<?php echo htmlspecialchars((string) $route['assignment_vehicle_id']); ?>"
                            data-route-assignment-driver-id="<?php echo htmlspecialchars((string) $route['assignment_driver_id']); ?>"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            onclick="showDeleteConfirm(<?php echo intval($route['route_id']); ?>, '<?php echo addslashes($route['route_name']); ?>')"
                        >
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.getElementById('openAddRoute').addEventListener('click', function () {
    document.getElementById('addRouteModal').style.display = 'flex';
});

document.getElementById('closeAddRoute').addEventListener('click', function () {
    document.getElementById('addRouteModal').style.display = 'none';
});

document.getElementById('closeEditRoute').addEventListener('click', function () {
    document.getElementById('editRouteModal').style.display = 'none';
});

document.getElementById('cancelDelete').addEventListener('click', function () {
    document.getElementById('deleteConfirmModal').style.display = 'none';
});

document.getElementById('addRouteModal').addEventListener('click', function (event) {
    if (event.target === this) {
        this.style.display = 'none';
    }
});

document.getElementById('editRouteModal').addEventListener('click', function (event) {
    if (event.target === this) {
        this.style.display = 'none';
    }
});

document.getElementById('deleteConfirmModal').addEventListener('click', function (event) {
    if (event.target === this) {
        this.style.display = 'none';
    }
});

document.querySelectorAll('.edit-route-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('edit_route_id').value = this.dataset.routeId || '';
        document.getElementById('edit_origin').value = this.dataset.routeOrigin || '';
        document.getElementById('edit_destination').value = this.dataset.routeDestination || '';
        document.getElementById('edit_fare').value = this.dataset.routeFare || '';
        document.getElementById('edit_distance').value = this.dataset.routeDistance || '';
        document.getElementById('edit_travel_minutes').value = this.dataset.routeTravelMinutes || '';
        document.getElementById('edit_assignment_vehicle_id').value = this.dataset.routeAssignmentVehicleId || '';
        document.getElementById('edit_assignment_driver_id').value = this.dataset.routeAssignmentDriverId || '';
        document.getElementById('editRouteModal').style.display = 'flex';
    });
});

function showDeleteConfirm(routeId, routeName) {
    document.getElementById('delete_route_id').value = routeId;
    document.getElementById('deleteMessage').textContent = `Are you sure you want to delete route "${routeName}"? This action cannot be undone.`;
    document.getElementById('deleteConfirmModal').style.display = 'flex';
}
</script>

<?php require_once 'footer.php'; ?>
