<?php
require_once 'auth.php';
require_once '../lib/trip_helpers.php';
require_once '../lib/vehicle_helpers.php';

$page_title = 'Dashboard Overview';
require_once 'header.php';

hz_generate_trips_for_date($conn, date('Y-m-d'));
hz_expire_overdue_no_shows($conn);

$stats = [];
$stats['active_vehicles'] = intval($conn->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active'")->fetch_row()[0] ?? 0);
$stats['total_drivers'] = intval($conn->query("SELECT COUNT(*) FROM drivers")->fetch_row()[0] ?? 0);
$stats['pending_bookings'] = intval($conn->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetch_row()[0] ?? 0);
$stats['total_users'] = intval($conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0);
$stats['today_trips'] = intval($conn->query("SELECT COUNT(*) FROM vehicle_trips WHERE DATE(scheduled_departure_at) = CURDATE()")->fetch_row()[0] ?? 0);
$stats['completed_today_trips'] = intval($conn->query("SELECT COUNT(*) FROM vehicle_trips WHERE DATE(scheduled_departure_at) = CURDATE() AND trip_status = 'completed'")->fetch_row()[0] ?? 0);
$stats['daily_income'] = (float) ($conn->query("
    SELECT COALESCE(SUM(COALESCE(NULLIF(fare, 0), fare_estimate, 0)), 0)
    FROM bookings
    WHERE status = 'completed'
      AND DATE(COALESCE(dropped_off_at, updated_at, created_at)) = CURDATE()
")->fetch_row()[0] ?? 0);

$recentGps = $conn->query("
    SELECT
        vehicle_id,
        COUNT(*) as count,
        MAX(timestamp) as latest,
        TIMESTAMPDIFF(SECOND, MAX(timestamp), NOW()) as latest_age
    FROM locations
    GROUP BY vehicle_id
");
$gpsRows = [];
$activeGpsVehicles = 0;
if ($recentGps) {
    while ($row = $recentGps->fetch_assoc()) {
        $row['latest_age'] = intval($row['latest_age']);
        $row['is_recent'] = $row['latest_age'] <= 120;
        if ($row['is_recent']) {
            $activeGpsVehicles++;
        }
        $gpsRows[] = $row;
    }
}
$gpsDataReceived = $activeGpsVehicles > 0;

$tripSeatCapacity = 0;
$tripReservedSeats = 0;
$tripBoardedSeats = 0;
$tripResult = $conn->query("
    SELECT trip_id
    FROM vehicle_trips
    WHERE DATE(scheduled_departure_at) = CURDATE()
");
if ($tripResult) {
    while ($tripRow = $tripResult->fetch_assoc()) {
        $metrics = hz_get_trip_metrics($conn, intval($tripRow['trip_id']));
        $tripSeatCapacity += $metrics['capacity'];
        $tripReservedSeats += $metrics['reserved'];
        $tripBoardedSeats += $metrics['boarded'];
    }
}
$stats['available_seats'] = max(0, $tripSeatCapacity - $tripReservedSeats);
$stats['boarded_passengers'] = $tripBoardedSeats;

$todayVehicleIncome = $conn->query("
    SELECT
        v.vehicle_name,
        v.license_plate,
        v.vehicle_model,
        v.vehicle_type,
        COUNT(DISTINCT vt.trip_id) AS trip_count,
        COALESCE(SUM(COALESCE(NULLIF(b.fare, 0), b.fare_estimate, 0)), 0) AS gross_income
    FROM vehicles v
    LEFT JOIN vehicle_trips vt
        ON vt.vehicle_id = v.vehicle_id
       AND DATE(vt.scheduled_departure_at) = CURDATE()
    LEFT JOIN bookings b
        ON b.trip_id = vt.trip_id
       AND b.status = 'completed'
    GROUP BY v.vehicle_id, v.vehicle_name, v.license_plate, v.vehicle_model, v.vehicle_type
    ORDER BY gross_income DESC, trip_count DESC, v.vehicle_name ASC
");

$upcomingArrivals = [];
$arrivalResult = $conn->query("
    SELECT
        vt.trip_id,
        vt.direction,
        vt.scheduled_departure_at,
        vt.arrival_reported_at,
        vt.trip_status,
        v.vehicle_name,
        v.license_plate,
        v.vehicle_model,
        v.vehicle_type,
        d.full_name AS driver_name,
        r.route_name,
        r.stops,
        r.travel_minutes
    FROM vehicle_trips vt
    JOIN vehicles v ON v.vehicle_id = vt.vehicle_id
    JOIN routes r ON r.route_id = vt.route_id
    LEFT JOIN drivers d ON d.user_id = vt.driver_id
    WHERE DATE(vt.scheduled_departure_at) = CURDATE()
      AND vt.trip_status <> 'cancelled'
    ORDER BY vt.scheduled_departure_at ASC
    LIMIT 10
");
if ($arrivalResult) {
    while ($arrival = $arrivalResult->fetch_assoc()) {
        $endpoints = hz_route_endpoints($arrival);
        $travelMinutes = max(0, intval($arrival['travel_minutes'] ?? 0));
        $arrival['origin'] = $endpoints['origin'];
        $arrival['destination'] = $endpoints['destination'];
        $arrival['scheduled_arrival_at'] = date('Y-m-d H:i:s', strtotime($arrival['scheduled_departure_at'] . " +{$travelMinutes} minutes"));
        $arrival['arrival_state'] = !empty($arrival['arrival_reported_at']) ? 'Arrived' : 'Expected';
        $upcomingArrivals[] = $arrival;
    }
}

$google_maps_script_url = google_maps_script_url('initMap', ['geometry']);
?>

<div class="dashboard-cards">
    <div class="card">
        <i class="fas fa-bus card-icon" aria-hidden="true"></i>
        <h3>Active Vehicles</h3>
        <div class="number" data-stat="active_vehicles"><?php echo $stats['active_vehicles']; ?></div>
    </div>
    <div class="card">
        <i class="fas fa-id-badge card-icon" aria-hidden="true"></i>
        <h3>Total Drivers</h3>
        <div class="number" data-stat="total_drivers"><?php echo $stats['total_drivers']; ?></div>
    </div>
    <div class="card">
        <i class="fas fa-calendar-check card-icon" aria-hidden="true"></i>
        <h3>Pending Bookings</h3>
        <div class="number" data-stat="pending_bookings"><?php echo $stats['pending_bookings']; ?></div>
    </div>
    <div class="card">
        <i class="fas fa-users card-icon" aria-hidden="true"></i>
        <h3>Total Users</h3>
        <div class="number" data-stat="total_users"><?php echo $stats['total_users']; ?></div>
    </div>
    <div class="card">
        <i class="fas fa-route card-icon" aria-hidden="true"></i>
        <h3>Trips Today</h3>
        <div class="number" data-stat="today_trips"><?php echo $stats['today_trips']; ?></div>
    </div>
    <div class="card">
        <i class="fas fa-money-bill-wave card-icon" aria-hidden="true"></i>
        <h3>Daily Gross Income</h3>
        <div class="number" data-stat="daily_income"><?php echo number_format($stats['daily_income'], 2); ?></div>
    </div>
    <div class="card">
        <i class="fas fa-chair card-icon" aria-hidden="true"></i>
        <h3>Available Seats Today</h3>
        <div class="number" data-stat="available_seats"><?php echo $stats['available_seats']; ?></div>
    </div>
    <div class="card">
        <i class="fas fa-user-check card-icon" aria-hidden="true"></i>
        <h3>Boarded Passengers</h3>
        <div class="number" data-stat="boarded_passengers"><?php echo $stats['boarded_passengers']; ?></div>
    </div>
</div>

<div class="table-container" style="margin-bottom: 20px;">
    <div class="table-header">
        <h2>Operational Snapshot</h2>
    </div>
    <table>
        <tbody>
            <tr>
                <td><strong>Completed Trips Today</strong></td>
                <td><?php echo $stats['completed_today_trips']; ?></td>
                <td><strong>Reserved Seats</strong></td>
                <td><?php echo $tripReservedSeats; ?></td>
            </tr>
            <tr>
                <td><strong>Total Scheduled Seats Today</strong></td>
                <td><?php echo $tripSeatCapacity; ?></td>
                <td><strong>Active GPS Vehicles</strong></td>
                <td><?php echo $activeGpsVehicles; ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="table-container" style="margin-bottom: 20px;">
    <div class="table-header">
        <h2>Upcoming Vehicle Arrivals</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>Vehicle</th>
                <th>Route</th>
                <th>Where It Will Arrive</th>
                <th>Departure</th>
                <th>Expected Arrival</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($upcomingArrivals): ?>
                <?php foreach ($upcomingArrivals as $arrival): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($arrival['vehicle_name']); ?></strong><br>
                            <small><?php echo htmlspecialchars(hz_vehicle_detail_line($arrival)); ?></small><br>
                            <small>Driver: <?php echo htmlspecialchars($arrival['driver_name'] ?: 'Unassigned'); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($arrival['origin']); ?> to <?php echo htmlspecialchars($arrival['destination']); ?><br>
                            <small><?php echo ucfirst(htmlspecialchars($arrival['direction'])); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($arrival['destination']); ?></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($arrival['scheduled_departure_at'])); ?></td>
                        <td>
                            <?php echo date('M j, Y g:i A', strtotime($arrival['scheduled_arrival_at'])); ?>
                            <?php if (!empty($arrival['arrival_reported_at'])): ?>
                                <br><small>Reported: <?php echo date('g:i A', strtotime($arrival['arrival_reported_at'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo htmlspecialchars($arrival['trip_status']); ?>">
                                <?php echo htmlspecialchars($arrival['arrival_state']); ?> / <?php echo ucwords(str_replace('_', ' ', $arrival['trip_status'])); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6">No scheduled vehicle arrivals for today.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="table-container" style="margin-bottom: 20px;">
    <div class="table-header">
        <h2>Per-Vehicle Daily Performance</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>Vehicle</th>
                <th>Trips Today</th>
                <th>Gross Income Today</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($todayVehicleIncome && $todayVehicleIncome->num_rows > 0): ?>
                <?php while ($vehicleRow = $todayVehicleIncome->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($vehicleRow['vehicle_name']); ?></strong><br>
                            <small><?php echo htmlspecialchars(hz_vehicle_detail_line($vehicleRow)); ?></small>
                        </td>
                        <td><?php echo intval($vehicleRow['trip_count']); ?></td>
                        <td>PHP <?php echo number_format((float) $vehicleRow['gross_income'], 2); ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">No trip data yet for today.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="alert <?php echo $gpsDataReceived ? 'alert-success' : 'alert-warning'; ?> mt-3">
    <?php if ($gpsDataReceived): ?>
        GPS system active. Receiving live location updates from tracked vehicles.
        <?php foreach ($gpsRows as $row): ?>
            <br><small>
                Vehicle <?php echo $row['vehicle_id']; ?>:
                <?php echo $row['count']; ?> updates,
                latest <?php echo $row['latest']; ?>
                (<?php echo $row['latest_age']; ?>s ago<?php echo $row['is_recent'] ? '' : ', stale'; ?>)
            </small>
        <?php endforeach; ?>
    <?php elseif (!empty($gpsRows)): ?>
        GPS data exists, but no vehicle has sent a fresh update in the last 2 minutes.
        <?php foreach ($gpsRows as $row): ?>
            <br><small>
                Vehicle <?php echo $row['vehicle_id']; ?>:
                latest <?php echo $row['latest']; ?>
                (<?php echo $row['latest_age']; ?>s ago, stale)
            </small>
        <?php endforeach; ?>
    <?php else: ?>
        Waiting for GPS data. No vehicle locations have been received yet.
    <?php endif; ?>
</div>

<div class="table-container mt-20">
    <div class="table-header">
        <h2>Live Vehicle Locations</h2>
        <div>
            <button onclick="loadVehicleLocations()" class="btn btn-primary" id="refresh-btn">
                <span id="refresh-text">Refresh</span>
                <span id="refresh-spinner" style="display: none;">Loading...</span>
            </button>
        </div>
    </div>

    <div class="map-container">
        <div id="vehicle-map" style="height: 650px; width: 100%; background: #f8f9fa; border-radius: 8px; overflow: hidden; position: relative;">
            <div id="map-loading" class="text-center" style="padding: 300px 20px; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; z-index: 1;">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading Google Maps...</span>
                </div>
                <p class="mt-3" id="map-status-text">Initializing Google Maps...</p>
            </div>
        </div>

        <div class="mt-3">
            <h4>Active Vehicles <span id="vehicle-count" class="badge bg-primary">0</span></h4>
            <div id="vehicle-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px;">
                <div class="text-center text-muted py-3">Waiting for vehicle data...</div>
            </div>
        </div>
    </div>
</div>

<script>
function loadGoogleMaps() {
    const script = document.createElement('script');
    script.src = <?php echo json_encode($google_maps_script_url); ?>;
    script.async = true;
    script.defer = true;
    script.onerror = function() {
        document.getElementById('map-status-text').textContent = 'Failed to load Google Maps. Please check your API key.';
    };
    document.head.appendChild(script);
}

loadGoogleMaps();
</script>

<script>
let vehicleMap;
let vehicleMarkers = {};
let mapInitialized = false;
let firstDataLoad = true;
const VEHICLE_REFRESH_MS = 4000;
const LIVE_LOCATION_SECONDS = 120;

function initMap() {
    try {
        vehicleMap = new google.maps.Map(document.getElementById('vehicle-map'), {
            center: { lat: 11.2445, lng: 125.0050 },
            zoom: 17,
            mapTypeId: 'roadmap',
            streetViewControl: true,
            mapTypeControl: true,
            fullscreenControl: true,
            zoomControl: true
        });

        mapInitialized = true;
        document.getElementById('map-loading').style.display = 'none';
        document.getElementById('map-status-text').textContent = 'Loading vehicle data...';
        loadVehicleLocations();
    } catch (error) {
        document.getElementById('map-status-text').textContent = 'Error loading map: ' + error.message;
    }
}

function loadVehicleLocations(silent = false) {
    if (!mapInitialized) {
        return;
    }

    const refreshBtn = document.getElementById('refresh-btn');
    const refreshText = document.getElementById('refresh-text');
    const refreshSpinner = document.getElementById('refresh-spinner');

    if (!silent) {
        refreshText.style.display = 'none';
        refreshSpinner.style.display = 'inline';
        refreshBtn.disabled = true;
    }

    fetch('../api/vehicle_locations.php')
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(data => {
            if (data.status === 'success') {
                updateVehicleMap(data.vehicles);
                document.getElementById('map-status-text').textContent = `Showing ${data.count} vehicles`;
            } else {
                throw new Error(data.message || 'API error');
            }
        })
        .catch(error => {
            document.getElementById('map-status-text').textContent = 'Error: ' + error.message;
        })
        .finally(() => {
            if (!silent) {
                refreshText.style.display = 'inline';
                refreshSpinner.style.display = 'none';
                refreshBtn.disabled = false;
            }
        });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];
    });
}

function updateVehicleMap(vehicles) {
    if (vehicles.length === 0) {
        document.getElementById('vehicle-list').innerHTML = '<div class="text-center text-muted py-3">No active vehicles found</div>';
        document.getElementById('vehicle-count').textContent = '0';
        return;
    }

    const bounds = new google.maps.LatLngBounds();
    const seenVehicleIds = new Set();

    vehicles.forEach(vehicle => {
        const lat = parseFloat(vehicle.latitude);
        const lng = parseFloat(vehicle.longitude);
        if (isNaN(lat) || isNaN(lng)) {
            return;
        }

        const vehicleId = String(vehicle.vehicle_id);
        seenVehicleIds.add(vehicleId);
        const position = { lat: lat, lng: lng };
        const isLive = vehicle.last_update !== null && Number(vehicle.last_update) <= LIVE_LOCATION_SECONDS;
        const vehicleName = escapeHtml(vehicle.vehicle_name || 'Vehicle');
        const driverName = escapeHtml(vehicle.driver_name || 'N/A');
        const plate = escapeHtml(vehicle.license_plate || 'N/A');
        const model = escapeHtml(vehicle.vehicle_model || vehicle.vehicle_type || 'N/A');
        const route = escapeHtml(vehicle.route_name || 'Unassigned');
        const status = escapeHtml(vehicle.status || 'N/A');
        const iconUrl = isLive
            ? 'https://maps.gstatic.com/mapfiles/ms2/micons/bus.png'
            : 'https://maps.google.com/mapfiles/ms/icons/yellow-dot.png';
        const infoHtml = `
                <div style="min-width: 250px; padding: 15px;">
                    <h4 style="margin: 0 0 10px 0; color: #333;">${vehicleName}</h4>
                    <p><strong>Driver:</strong> ${driverName}</p>
                    <p><strong>Plate:</strong> ${plate}</p>
                    <p><strong>Model:</strong> ${model}</p>
                    <p><strong>Route:</strong> ${route}</p>
                    <p><strong>Status:</strong> <span style="color: ${vehicle.status === 'active' ? 'green' : 'orange'}">${status}</span></p>
                    <p><strong>GPS:</strong> ${isLive ? 'Live' : 'Last known'}${vehicle.last_update === null ? '' : ` (${vehicle.last_update}s ago)`}</p>
                    <p><strong>Coordinates:</strong><br>${lat.toFixed(6)}, ${lng.toFixed(6)}</p>
                </div>
            `;

        if (vehicleMarkers[vehicleId]) {
            vehicleMarkers[vehicleId].setPosition(position);
            vehicleMarkers[vehicleId].setIcon({
                url: iconUrl,
                scaledSize: new google.maps.Size(isLive ? 48 : 36, isLive ? 48 : 36),
                anchor: new google.maps.Point(isLive ? 24 : 18, isLive ? 24 : 18)
            });
            if (vehicleMarkers[vehicleId].infoWindow) {
                vehicleMarkers[vehicleId].infoWindow.setContent(infoHtml);
            }
        } else {
            const marker = new google.maps.Marker({
                position,
                map: vehicleMap,
                title: vehicle.vehicle_name,
                icon: {
                    url: iconUrl,
                    scaledSize: new google.maps.Size(isLive ? 48 : 36, isLive ? 48 : 36),
                    anchor: new google.maps.Point(isLive ? 24 : 18, isLive ? 24 : 18)
                }
            });

            const infoWindow = new google.maps.InfoWindow({ content: infoHtml });
            marker.addListener('click', () => {
                Object.values(vehicleMarkers).forEach(existingMarker => {
                    existingMarker.infoWindow?.close();
                });
                infoWindow.open(vehicleMap, marker);
            });
            marker.infoWindow = infoWindow;
            vehicleMarkers[vehicleId] = marker;
        }

        bounds.extend(position);
    });

    Object.keys(vehicleMarkers).forEach(vehicleId => {
        if (!seenVehicleIds.has(vehicleId)) {
            vehicleMarkers[vehicleId].setMap(null);
            delete vehicleMarkers[vehicleId];
        }
    });

    if (!bounds.isEmpty()) {
        if (firstDataLoad) {
            vehicleMap.fitBounds(bounds);
            firstDataLoad = false;
        }

        const locatedCount = vehicles.filter(vehicle => vehicle.has_location).length;
        document.getElementById('vehicle-count').textContent = `${locatedCount}/${vehicles.length}`;
    } else {
        document.getElementById('vehicle-count').textContent = `0/${vehicles.length}`;
    }

    updateVehicleList(vehicles);
}

function updateVehicleList(vehicles) {
    let html = '';
    vehicles.forEach(vehicle => {
        const statusColor = vehicle.status === 'active' ? 'green' : 'orange';
        const hasLocation = vehicle.has_location && vehicle.latitude !== null && vehicle.longitude !== null;
        const isLive = vehicle.last_update !== null && Number(vehicle.last_update) <= LIVE_LOCATION_SECONDS;
        const gpsLabel = hasLocation
            ? `${isLive ? 'live' : 'last known'}${vehicle.last_update === null ? '' : `, ${vehicle.last_update}s ago`}`
            : 'no location yet';
        const coordinates = hasLocation
            ? `${parseFloat(vehicle.latitude).toFixed(6)}, ${parseFloat(vehicle.longitude).toFixed(6)}`
            : 'Waiting for first GPS update';
        const vehicleId = Number.parseInt(vehicle.vehicle_id, 10) || 0;
        const vehicleName = escapeHtml(vehicle.vehicle_name || 'Vehicle');
        const plate = escapeHtml(vehicle.license_plate || 'N/A');
        const driverName = escapeHtml(vehicle.driver_name || 'No driver');
        const model = escapeHtml(vehicle.vehicle_model || vehicle.vehicle_type || 'No model');
        const status = escapeHtml(vehicle.status || 'N/A');
        const clickAction = hasLocation && vehicleId > 0 ? ` onclick="centerOnVehicle(${vehicleId})"` : '';
        const cursor = hasLocation ? 'pointer' : 'default';
        html += `
        <div class="vehicle-item" style="padding: 12px; border-bottom: 1px solid #eee; cursor: ${cursor};"${clickAction}>
            <strong>${vehicleName}</strong> (${plate})<br>
            <small>
                <span style="color: #666;">${driverName}</span> &bull;
                ${model} &bull;
                <span style="color: ${statusColor}">${status}</span> &bull;
                ${escapeHtml(gpsLabel)}
            </small><br>
            <small style="color: #888; font-size: 11px;">${escapeHtml(coordinates)}</small>
        </div>`;
    });
    document.getElementById('vehicle-list').innerHTML = html;
}

function centerOnVehicle(vehicleId) {
    const marker = vehicleMarkers[vehicleId];
    if (!marker) {
        return;
    }

    vehicleMap.panTo(marker.getPosition());
    vehicleMap.setZoom(17);
    marker.setAnimation(google.maps.Animation.BOUNCE);
    setTimeout(() => marker.setAnimation(null), 2000);
    if (marker.infoWindow) {
        marker.infoWindow.open(vehicleMap, marker);
    }
}

window.gm_authFailure = function() {
    document.getElementById('map-status-text').textContent = 'Google Maps API key error. Check billing, Maps JavaScript API, and localhost referrer rules.';
    document.getElementById('map-loading').style.display = 'block';
};

setInterval(() => {
    if (mapInitialized) {
        loadVehicleLocations(true);
    }
}, VEHICLE_REFRESH_MS);
</script>

<style>
.vehicle-item:hover {
    background-color: #f8f9fa;
    transition: background-color 0.2s ease;
}

.map-container {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 20px;
}

.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(15,23,42,0.07);
    text-align: left;
    border-left: 4px solid var(--primary-pink);
}

.card-icon {
    font-size: 2.5em;
    margin-bottom: 10px;
}

.card h3 {
    margin: 10px 0;
    color: #555;
    font-size: 1.1em;
}

.card .number {
    font-size: 2em;
    font-weight: bold;
    color: var(--primary-pink);
}

.gm-style .gm-style-iw-c {
    padding: 0;
    border-radius: 8px;
}
</style>

<?php require_once 'footer.php'; ?>
