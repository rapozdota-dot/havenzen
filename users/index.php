<?php
require_once 'auth.php';
require_once 'header.php';

$google_maps_script_url = google_maps_script_url('initMap', ['geometry']);
?>

<div class="dashboard-header">
    <h1>Live Vehicle Tracking</h1>
    <p>Track available vehicles in real-time and see their current locations</p>
</div>

<!-- Quick Stats -->
<div class="cards-grid">
    <div class="card">
        <div class="card-content">
            <h3>Available Vehicles</h3>
            <p>Currently active vehicles in your area</p>
            <div class="card-stats">
                <div class="stat-number">
                    <?php
                    $active_vehicles = $conn->query("SELECT COUNT(*) FROM vehicles WHERE status = 'active' AND driver_id IS NOT NULL")->fetch_row()[0];
                    echo $active_vehicles;
                    ?>
                </div>
                <div class="stat-label">Active Now</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <h3>Your Bookings</h3>
            <p>Total rides booked with us</p>
            <div class="card-stats">
                <div class="stat-number">
                    <?php
                    $user_bookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE passenger_id = $user_id")->fetch_row()[0];
                    echo $user_bookings;
                    ?>
                </div>
                <div class="stat-label">Total Rides</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <h3>Recent Activity</h3>
            <p>Your last booking status</p>
            <div class="card-stats">
                <div class="stat-number">
                    <?php
                    $last_booking = $conn->query("SELECT status FROM bookings WHERE passenger_id = $user_id ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
                    echo $last_booking ? ucfirst($last_booking['status']) : 'None';
                    ?>
                </div>
                <div class="stat-label">Last Ride</div>
            </div>
        </div>
    </div>
</div>

<!-- Live Map -->
<div class="map-container">
    <div class="map-header">
        <h3> Live Vehicle Locations</h3>
        <p>Click on vehicle markers to see details and book rides</p>
    </div>
    <div class="map-content">
        <div id="vehicle-map" style="height: 550px; width: 100%; background: #f8f9fa; border-radius: 8px; overflow: hidden; position: relative;">
            <div id="map-loading" class="text-center" style="padding: 220px 20px; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; z-index: 1;">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading Google Maps...</span>
                </div>
                <p class="mt-3" id="map-status-text">Initializing Google Maps...</p>
            </div>
        </div>

        <!-- Vehicle List for passengers -->
        <div class="mt-3">
            <h4>Active Vehicles <span id="vehicle-count" class="badge bg-primary">0</span></h4>
            <div id="vehicle-list" style="max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px;">
                <div class="text-center text-muted py-3">Waiting for vehicle data...</div>
            </div>
        </div>
    </div>
</div>

<!-- Available Vehicles List -->
<div class="table-container">
    <div class="table-header">
        <h2>Available Vehicles</h2>
        <div class="header-actions">
            <button onclick="loadVehicleLocations()" class="btn btn-primary" id="refresh-btn">
                <span id="refresh-text"><i class="fas fa-rotate-right"></i> Refresh</span>
                <span id="refresh-spinner" style="display: none;"><i class="fas fa-spinner fa-spin"></i> Loading...</span>
            </button>
            <a href="booking.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Book Scheduled Trip
            </a>
        </div>
    </div>
    <table class="responsive-table">
        <thead>
            <tr>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>License Plate</th>
                <th>Status</th>
                <th>Last Location</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $vehicles = $conn->query("
                SELECT v.*, d.full_name as driver_name,
                       l.latitude, l.longitude, l.timestamp as last_update
                FROM vehicles v 
                LEFT JOIN drivers d ON v.driver_id = d.user_id 
                LEFT JOIN locations l ON v.vehicle_id = l.vehicle_id 
                   AND l.timestamp = (SELECT MAX(timestamp) FROM locations WHERE vehicle_id = v.vehicle_id)
                WHERE v.status = 'active' AND v.driver_id IS NOT NULL
                ORDER BY COALESCE(l.timestamp, v.updated_at) DESC
            ");
            
            if ($vehicles->num_rows > 0):
                while ($vehicle = $vehicles->fetch_assoc()):
            ?>
            <tr>
                <td data-label="Vehicle">
                    <strong style="display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-bus" style="color: #2196F3;"></i>
                        <?php echo htmlspecialchars($vehicle['vehicle_name']); ?>
                    </strong>
                </td>
                <td data-label="Driver"><?php echo $vehicle['driver_name'] ? htmlspecialchars($vehicle['driver_name']) : 'Not Assigned'; ?></td>
                <td data-label="License Plate"><?php echo htmlspecialchars($vehicle['license_plate']); ?></td>
                <td data-label="Status">
                    <span class="status-badge status-active">Active</span>
                    <?php if ($vehicle['latitude'] && $vehicle['longitude']): ?>
                        <br><small style="color: #28a745;"><i class="fas fa-location-dot"></i> Live</small>
                    <?php else: ?>
                        <br><small style="color: #ffc107;"><i class="fas fa-triangle-exclamation"></i> No GPS</small>
                    <?php endif; ?>
                </td>
                <td data-label="Last Location">
                    <?php if ($vehicle['latitude'] && $vehicle['longitude']): ?>
                        <small>Updated: <?php echo date('H:i', strtotime($vehicle['last_update'])); ?></small>
                    <?php else: ?>
                        <small>No location data</small>
                    <?php endif; ?>
                </td>
                <td data-label="Action">
                    <a href="booking.php?vehicle_id=<?php echo $vehicle['vehicle_id']; ?>" class="btn btn-primary" style="font-size: 0.85rem; padding: 0.375rem 0.75rem;">
                        <i class="fas fa-calendar-plus"></i> View Trips
                    </a>
                </td>
            </tr>
            <?php 
                endwhile;
            else:
            ?>
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px;">
                    <div class="empty-state-icon"><i class="fas fa-bus"></i></div>
                    <h3 style="color: var(--text-color); margin-bottom: 10px;">No Vehicles Available</h3>
                    <p style="color: var(--text-color); opacity: 0.8;">Check back later for available vehicles</p>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Google Maps loader for passenger live map
function loadGoogleMaps() {
    const script = document.createElement('script');
    script.src = <?php echo json_encode($google_maps_script_url); ?>;
    script.async = true;
    script.defer = true;
    script.onerror = function() {
        document.getElementById('map-status-text').textContent = 'Failed to load Google Maps. Please check your connection.';
    };
    document.head.appendChild(script);
}

window.gm_authFailure = function() {
    document.getElementById('map-status-text').textContent = 'Google Maps API key error. Check billing, Maps JavaScript API, and localhost referrer rules.';
    document.getElementById('map-loading').style.display = 'block';
};

// Start loading Google Maps immediately
loadGoogleMaps();

// Live vehicle map logic (shared behavior with admin dashboard, simplified)
let vehicleMap;
let vehicleMarkers = {};
let mapInitialized = false;
let firstDataLoad = true;

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

function loadVehicleLocations() {
    if (!mapInitialized) {
        return;
    }

    // Show loading state
    const refreshBtn = document.getElementById('refresh-btn');
    const refreshText = document.getElementById('refresh-text');
    const refreshSpinner = document.getElementById('refresh-spinner');
    
    if (refreshBtn && refreshText && refreshSpinner) {
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
            // Hide loading state
            const refreshBtn = document.getElementById('refresh-btn');
            const refreshText = document.getElementById('refresh-text');
            const refreshSpinner = document.getElementById('refresh-spinner');
            
            if (refreshBtn && refreshText && refreshSpinner) {
                refreshText.style.display = 'inline';
                refreshSpinner.style.display = 'none';
                refreshBtn.disabled = false;
            }
            
            if (data.error) {
                console.error('Error:', data.error);
                return;
            }

            if (data.status === 'success') {
                updateVehicleMap(data.vehicles);
                document.getElementById('map-status-text').textContent = `Showing ${data.count} vehicles`;
            } else {
                throw new Error(data.message || 'API error');
            }
        })
        .catch(error => {
            document.getElementById('map-status-text').textContent = 'Error: ' + error.message;
            console.error('API Error:', error);
        });
}

function updateVehicleMap(vehicles) {
    // Clear existing markers
    Object.values(vehicleMarkers).forEach(marker => marker.setMap(null));
    vehicleMarkers = {};

    if (vehicles.length === 0) {
        document.getElementById('vehicle-list').innerHTML = '<div class="text-center text-muted py-3">No active vehicles found</div>';
        document.getElementById('vehicle-count').textContent = '0';
        return;
    }

    const bounds = new google.maps.LatLngBounds();

    vehicles.forEach(vehicle => {
        const lat = parseFloat(vehicle.latitude);
        const lng = parseFloat(vehicle.longitude);

        if (isNaN(lat) || isNaN(lng)) {
            console.warn('Invalid coordinates for vehicle:', vehicle.vehicle_name, vehicle.latitude, vehicle.longitude);
            return;
        }

        const marker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: vehicleMap,
            title: vehicle.vehicle_name,
            icon: {
                url: 'https://maps.gstatic.com/mapfiles/ms2/micons/bus.png',
                scaledSize: new google.maps.Size(40, 40),
                anchor: new google.maps.Point(20, 20)
            },
            animation: google.maps.Animation.DROP
        });

        const infoWindow = new google.maps.InfoWindow({
            content: `
                <div style="min-width: 220px; padding: 10px;">
                    <h4 style="margin: 0 0 8px 0; color: #333;">${vehicle.vehicle_name}</h4>
                    <p style="margin: 0 0 4px 0;"><strong>Plate:</strong> ${vehicle.license_plate}</p>
                    <p style="margin: 0 0 4px 0;"><strong>Status:</strong> ${vehicle.status}</p>
                    <p style="margin: 0;"><strong>GPS:</strong> ${vehicle.last_update === null ? 'No update yet' : `${vehicle.last_update}s ago`}</p>
                </div>
            `
        });

        marker.addListener('click', () => {
            infoWindow.open(vehicleMap, marker);
        });

        vehicleMarkers[vehicle.vehicle_id] = marker;
        bounds.extend(marker.getPosition());
    });

    if (vehicles.length > 0 && !bounds.isEmpty()) {
        if (firstDataLoad) {
            vehicleMap.fitBounds(bounds);
            vehicleMap.setZoom(17);
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
        const hasLocation = vehicle.has_location && vehicle.latitude !== null && vehicle.longitude !== null;
        const status = hasLocation ? 'Live Tracking' : 'No GPS yet';
        const statusColor = hasLocation ? '#28a745' : '#ffc107';
        const clickAction = hasLocation ? ` onclick="centerOnVehicle(${vehicle.vehicle_id})"` : '';
        const cursor = hasLocation ? 'pointer' : 'default';
        html += `
        <div class="vehicle-item" style="padding: 10px 12px; border-bottom: 1px solid #eee; cursor: ${cursor}; display: flex; justify-content: space-between; align-items: center;"${clickAction}>
            <div style="flex-grow: 1;">
                <strong style="display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-bus" style="color: #2196F3;"></i>
                    ${vehicle.vehicle_name}
                </strong>
                <small>${vehicle.license_plate} &bull; <span style="color: ${statusColor};">${status}</span></small>
            </div>
            <a href="booking.php?vehicle_id=${vehicle.vehicle_id}" class="btn btn-primary" onclick="event.stopPropagation();" style="font-size: 0.75rem; padding: 0.25rem 0.5rem; margin-left: 8px;">
                Book
            </a>
        </div>`;
    });
    document.getElementById('vehicle-list').innerHTML = html;
}

function centerOnVehicle(vehicleId) {
    const marker = vehicleMarkers[vehicleId];
    if (marker) {
        vehicleMap.panTo(marker.getPosition());
        vehicleMap.setZoom(17);
        marker.setAnimation(google.maps.Animation.BOUNCE);
        setTimeout(() => {
            marker.setAnimation(null);
        }, 1500);
    }
}

// Auto-refresh every 8 seconds
setInterval(() => {
    if (mapInitialized) {
        loadVehicleLocations();
    }
}, 8000);
</script>

<?php require_once 'footer.php'; ?>
