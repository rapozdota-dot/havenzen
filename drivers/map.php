<?php
require_once 'auth.php';
require_once 'header.php';

// Ensure $driver_id is set to the correct drivers table ID from auth.php
// (auth.php sets $driver_id to drivers.driver_id if available)

// Get driver's current location and online status
$session_user_id = intval($_SESSION['user_id'] ?? 0);
$driver_result = $conn->query("SELECT ST_X(current_location) as current_lat, ST_Y(current_location) as current_lng, is_online FROM drivers WHERE user_id = $session_user_id");
$driver_row = $driver_result ? $driver_result->fetch_assoc() : [];
$default_lat = 11.2445;
$default_lng = 125.0050;
$driver_lat = isset($driver_row['current_lat']) && $driver_row['current_lat'] !== null ? $driver_row['current_lat'] : $default_lat;
$driver_lng = isset($driver_row['current_lng']) && $driver_row['current_lng'] !== null ? $driver_row['current_lng'] : $default_lng;
$vehicle_status = !empty($driver_row['is_online']) ? 'online' : 'offline';


// Fetch vehicle status directly from the database
// Also get assigned vehicle details (id, name, plate, status)
$vehicle_info_q = $conn->query("SELECT vehicle_id, vehicle_name, license_plate, vehicle_model, vehicle_type, status FROM vehicles WHERE driver_id = $session_user_id LIMIT 1");
if ($vehicle_info_q && $vehicle_info_q->num_rows > 0) {
    $vrow = $vehicle_info_q->fetch_assoc();
    $vehicle_db_status = $vrow['status'] ?? 'inactive';
    $assigned_vehicle_id = intval($vrow['vehicle_id']);
    $vehicle_name = $vrow['vehicle_name'] ?? 'No Vehicle Assigned';
    $license_plate = $vrow['license_plate'] ?? 'N/A';
} else {
    $vehicle_db_status = 'inactive';
    $assigned_vehicle_id = 0;
    $vehicle_name = 'No Vehicle Assigned';
    $license_plate = 'N/A';
}

$google_maps_script_url = google_maps_script_url('initMap', ['geometry', 'places']);
?>

<div class="dashboard-header">
    <h1>Driver Live Map</h1>
    <p>Track your location and manage active rides in real-time</p>
</div>

<!-- Map and Info Layout -->
<div class="map-layout">
    <!-- Left Side: Location Card and Info Lists -->
    <div class="map-sidebar">
        <div class="card">
            <div class="card-icon">📍</div>
            <h3>Your Location</h3>
            <div class="number" id="location-status">Live</div>
        </div>

        <!-- Vehicle List (admin-style) -->
        <div class="info-section">
            <h4>Active Vehicle <span id="vehicle-count" class="badge bg-primary">0</span></h4>
            <div id="vehicle-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; background: white;">
                <div class="text-center text-muted py-3">Waiting for vehicle data...</div>
            </div>
        </div>

        <!-- Active Bookings List -->
        <div class="info-section">
            <h4>Active Bookings <span id="booking-count" class="badge bg-primary">0</span></h4>
            <div id="booking-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; background: white;">
                <div class="text-center text-muted py-3">Loading active bookings...</div>
            </div>
        </div>
    </div>

    <!-- Right Side: Map -->
    <div class="map-main">
        <div class="table-container">
            <div class="table-header">
                <h2>Live Tracking <small class="text-muted" id="last-update">-</small></h2>
                <div>
                    <button class="btn btn-primary" id="refresh-btn">
                        <span id="refresh-text">🔄 Refresh</span>
                        <span id="refresh-spinner" style="display: none;">⏳ Loading...</span>
                    </button>
                </div>
            </div>
            
            <div class="map-container">
                <!-- Map with loading state -->
                <div id="driver-map" class="driver-map-container">
                    <div id="map-loading" class="text-center" style="padding: 250px 20px; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #f8f9fa; z-index: 1;">
                        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                            <span class="visually-hidden">Loading Google Maps...</span>
                        </div>
                        <p class="mt-3" id="map-status-text">Initializing Google Maps...</p>
                    </div>
                </div>
                
                <!-- Debug: raw API response (hidden by default). Add ?debug=1 to URL to show -->
                <pre id="api-debug" style="display: none; white-space: pre-wrap; max-height: 200px; overflow: auto; border: 1px solid #ddd; padding: 10px; margin-top: 10px;"></pre>
            </div>
        </div>
    </div>
</div>

<!-- Load Google Maps API -->
<script>
window.onerror = function(message, source, lineno, colno) {
    const statusTextEl = document.getElementById('map-status-text');
    const loadingEl = document.getElementById('map-loading');
    if (statusTextEl) {
        statusTextEl.textContent = 'Map script error: ' + message + ' at line ' + lineno;
    }
    if (loadingEl) {
        loadingEl.style.display = 'flex';
    }
};

function loadGoogleMaps() {
    console.log('Loading Google Maps API...');
    const script = document.createElement('script');
    script.src = <?php echo json_encode($google_maps_script_url); ?>;
    script.async = true;
    script.defer = true;
    script.onerror = function() {
        console.error('Failed to load Google Maps API');
        const statusTextEl = document.getElementById('map-status-text');
        if (statusTextEl) statusTextEl.textContent = 'Failed to load Google Maps. Please check your connection.';
    };
    document.head.appendChild(script);

    setTimeout(function() {
        if (!window.google || !window.google.maps) {
            const statusTextEl = document.getElementById('map-status-text');
            const loadingEl = document.getElementById('map-loading');
            if (statusTextEl) {
                statusTextEl.textContent = 'Google Maps did not load. Check GOOGLE_MAPS_API_KEY, Maps JavaScript API, billing, and Render domain referrer.';
            }
            if (loadingEl) {
                loadingEl.style.display = 'flex';
            }
        }
    }, 8000);
}

// Global variables
let driverMap;
let directionsRenderer;
let driverMarker;
let vehicleMarker;
let bookingMarkers = {};
let mapInitialized = false;
let firstDataLoad = true;
let watchId = null;
let locationIntervalId = null;
let mapLocationInFlight = false;
let vehicleLocationsInFlight = false;
let vehicleLocationsTimer = null;
let hasCenteredVehicle = false;
let hasCenteredDriver = false;
const GPS_UPDATE_INTERVAL_MS = 3000;
const LOCATION_HEARTBEAT_INTERVAL_MS = 3000;
const VEHICLE_REFRESH_INTERVAL_MS = 3000;
const SMOOTH_MARKER_DURATION_MS = 2600;
let lastLocationSentAt = 0;

function initMap() {
    try {
        window.havenzenMapInitCalled = true;
        const mapElement = document.getElementById('driver-map');
        if (!mapElement) {
            console.error('Map element not found!');
            document.getElementById('map-status-text').textContent = 'Error: Map container not found';
            return;
        }

        console.log('Initializing map...');
        
        driverMap = new google.maps.Map(mapElement, {
            center: { lat: <?php echo $driver_lat; ?>, lng: <?php echo $driver_lng; ?> },
            zoom: 17, // Default to street-level detail
            mapTypeControl: true,
            fullscreenControl: true,
            zoomControl: true,
            streetViewControl: false,
            styles: [] // Clean default style
        });

        console.log('Map created successfully');

        // Initialize directions renderer
        directionsRenderer = new google.maps.DirectionsRenderer({
            map: driverMap,
            suppressMarkers: true,
            polylineOptions: {
                strokeColor: '#e91e63',
                strokeOpacity: 0.8,
                strokeWeight: 5
            }
        });

        // Note: Driver marker will be created dynamically by updateVehicleMap()
        // This ensures it bounces on refresh and stays in sync with vehicle data
        console.log('Map initialized - driver marker will be created by vehicle data update');

        // Hide loading overlay when map is ready
        google.maps.event.addListenerOnce(driverMap, 'idle', function() {
            console.log('Map is idle - ready to load data');
            mapInitialized = true;
            const loadingEl = document.getElementById('map-loading');
            if (loadingEl) loadingEl.style.display = 'none';
            
            const statusTextEl = document.getElementById('map-status-text');
            if (statusTextEl) statusTextEl.textContent = 'Map ready - loading driver data...';
            
            // Load data
            loadDriverData();
            loadVehicleLocations();
            startLocationTracking();
        });

        // Force resize after a short delay to ensure map renders properly
        setTimeout(() => {
            if (driverMap) {
                google.maps.event.trigger(driverMap, 'resize');
                driverMap.setCenter({ lat: <?php echo $driver_lat; ?>, lng: <?php echo $driver_lng; ?> });
            }
        }, 500);

    } catch (error) {
        console.error('Map initialization error:', error);
        const statusTextEl = document.getElementById('map-status-text');
        if (statusTextEl) statusTextEl.textContent = 'Error loading map: ' + error.message;
    }
}

// Update refresh display (used by manual refresh)
function updateRefreshTimer() {
    const now = new Date();
    const timeString = now.toLocaleTimeString();
    document.getElementById('last-update').textContent = `Last updated: ${timeString}`;
}

function moveMarkerSmoothly(marker, nextPosition, durationMs) {
    if (!marker) return;

    const currentPosition = marker.getPosition && marker.getPosition();
    if (!currentPosition || typeof requestAnimationFrame !== 'function') {
        marker.setPosition(nextPosition);
        return;
    }

    const fromLat = currentPosition.lat();
    const fromLng = currentPosition.lng();
    const toLat = Number(nextPosition.lat);
    const toLng = Number(nextPosition.lng);
    if (!Number.isFinite(toLat) || !Number.isFinite(toLng)) return;

    if (Math.abs(fromLat - toLat) < 0.000001 && Math.abs(fromLng - toLng) < 0.000001) {
        marker.setPosition(nextPosition);
        return;
    }

    if (marker._havenzenAnimationFrame) {
        cancelAnimationFrame(marker._havenzenAnimationFrame);
    }

    const startedAt = performance.now();
    const duration = Math.max(300, durationMs || SMOOTH_MARKER_DURATION_MS);

    function step(now) {
        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = progress < 0.5
            ? 2 * progress * progress
            : 1 - Math.pow(-2 * progress + 2, 2) / 2;

        marker.setPosition({
            lat: fromLat + (toLat - fromLat) * eased,
            lng: fromLng + (toLng - fromLng) * eased
        });

        if (progress < 1) {
            marker._havenzenAnimationFrame = requestAnimationFrame(step);
        } else {
            marker._havenzenAnimationFrame = null;
            marker.setPosition(nextPosition);
        }
    }

    marker._havenzenAnimationFrame = requestAnimationFrame(step);
}

// Start tracking driver's location
function startLocationTracking() {
    if (!navigator.geolocation) {
        document.getElementById('location-status').textContent = 'GPS Unavailable';
        return;
    }

    if (watchId || locationIntervalId) return;

    requestAndSendDriverLocation(true);

    locationIntervalId = setInterval(function() {
        requestAndSendDriverLocation(true);
    }, LOCATION_HEARTBEAT_INTERVAL_MS);

    watchId = navigator.geolocation.watchPosition(
        (position) => handleDriverPosition(position, false),
        (error) => {
            console.error('Geolocation error:', error);
            document.getElementById('location-status').textContent = 'GPS Error';
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: GPS_UPDATE_INTERVAL_MS
        }
    );
}

function requestAndSendDriverLocation(forceUpload) {
    if (mapLocationInFlight || !navigator.geolocation) return;

    mapLocationInFlight = true;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            mapLocationInFlight = false;
            handleDriverPosition(position, forceUpload);
        },
        (error) => {
            mapLocationInFlight = false;
            console.error('Geolocation error:', error);
            document.getElementById('location-status').textContent = 'GPS Error';
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: forceUpload ? 0 : GPS_UPDATE_INTERVAL_MS
        }
    );
}

function handleDriverPosition(position, forceUpload) {
    const newPos = {
        lat: position.coords.latitude,
        lng: position.coords.longitude
    };

    if (driverMarker) {
        moveMarkerSmoothly(driverMarker, newPos, SMOOTH_MARKER_DURATION_MS);
        if (!hasCenteredDriver) {
            driverMap.panTo(newPos);
            driverMap.setZoom(17);
            hasCenteredDriver = true;
        }
    }

    const now = Date.now();
    if (forceUpload || lastLocationSentAt === 0 || now - lastLocationSentAt >= GPS_UPDATE_INTERVAL_MS) {
        lastLocationSentAt = now;
        updateDriverLocation(newPos.lat, newPos.lng);
    }
}

// Load driver data (bookings and location)
function loadDriverData() {
    if (!mapInitialized) return;
    // Show loading state
    const refreshBtn = document.getElementById('refresh-btn');
    const refreshText = document.getElementById('refresh-text');
    const refreshSpinner = document.getElementById('refresh-spinner');

    if (refreshText) refreshText.style.display = 'none';
    if (refreshSpinner) refreshSpinner.style.display = 'inline';
    if (refreshBtn) refreshBtn.disabled = true;

    fetch('../api/driver_bookings.php')
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            console.log('Driver bookings response:', data);

            if (data.status !== 'success') {
                throw new Error(data.message || 'API error');
            }

            updateBookingsMap(data.bookings || []);
            const statusTextEl = document.getElementById('map-status-text');
            if (statusTextEl) statusTextEl.textContent = 'Live - ' + (data.count || 0) + ' active bookings';
            const activeCountEl = document.getElementById('active-rides-count');
            if (activeCountEl) activeCountEl.textContent = data.count || 0;
            updateRefreshTimer();

            // Debug output
            try {
                const params = new URLSearchParams(window.location.search);
                const dbg = params.get('debug');
                const el = document.getElementById('api-debug');
                if (el) {
                    el.textContent = JSON.stringify(data, null, 2);
                    if (dbg === '1') el.style.display = 'block';
                }
            } catch (e) {
                // ignore
            }

            if (data.vehicle_status) {
                updateVehicleStatusDisplay(data.vehicle_status);
            }

            if (data.vehicle_lat && data.vehicle_lng) {
                const vLat = parseFloat(data.vehicle_lat);
                const vLng = parseFloat(data.vehicle_lng);
                if (!isNaN(vLat) && !isNaN(vLng)) {
                    const vPos = { lat: vLat, lng: vLng };
                    if (!vehicleMarker) {
                        vehicleMarker = new google.maps.Marker({
                            position: vPos,
                            map: driverMap,
                            title: 'Vehicle Location',
                            icon: {
                                url: 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                                scaledSize: new google.maps.Size(32, 32),
                                anchor: new google.maps.Point(16, 16)
                            }
                        });
                    } else {
                        moveMarkerSmoothly(vehicleMarker, vPos, SMOOTH_MARKER_DURATION_MS);
                    }

                    try {
                        const bounds = new google.maps.LatLngBounds();
                        if (driverMarker) bounds.extend(driverMarker.getPosition());
                        bounds.extend(new google.maps.LatLng(vLat, vLng));
                        if (!hasCenteredVehicle) {
                            driverMap.fitBounds(bounds);
                            hasCenteredVehicle = true;
                        }
                    } catch (e) {
                        // ignore
                    }
                }
            }
        })
        .catch(function(error) {
            const statusTextEl = document.getElementById('map-status-text');
            if (statusTextEl) statusTextEl.textContent = 'Error: ' + error.message;
            console.error('API Error:', error);
        })
        .finally(function() {
            if (refreshText) refreshText.style.display = 'inline';
            if (refreshSpinner) refreshSpinner.style.display = 'none';
            if (refreshBtn) refreshBtn.disabled = false;
        });
}

// Update vehicle status display
function updateVehicleStatusDisplay(vehicleStatus) {
    try {
        const badge = document.getElementById('vehicle-status-badge');
        const dbStatus = document.getElementById('vehicle-db-status');

        if (vehicleStatus === 'active') {
            if (badge) { badge.className = 'badge bg-success ms-1'; badge.textContent = 'Vehicle: Active'; }
            if (dbStatus) dbStatus.textContent = 'DB: active';
        } else {
            if (badge) { badge.className = 'badge bg-warning ms-1'; badge.textContent = 'Vehicle: Inactive'; }
            if (dbStatus) dbStatus.textContent = 'DB: inactive';
        }
    } catch (e) {
        console.warn('updateVehicleStatusDisplay error', e);
    }
}

// Update map with booking markers
function updateBookingsMap(bookings) {
    // Clear existing booking markers
    Object.values(bookingMarkers).forEach(marker => marker.setMap(null));
    bookingMarkers = {};
    
    if (bookings.length === 0) {
        document.getElementById('booking-list').innerHTML = '<div class="text-center text-muted py-3">No active bookings found</div>';
        document.getElementById('booking-count').textContent = '0';
        return;
    }

    const bounds = new google.maps.LatLngBounds();
    
    // Add driver position to bounds if vehicle marker exists
    if (window.vehicleMarkers && Object.keys(window.vehicleMarkers).length > 0) {
        const vehicleMarker = Object.values(window.vehicleMarkers)[0];
        if (vehicleMarker) {
            bounds.extend(vehicleMarker.getPosition());
        }
    }
    
    bookings.forEach(booking => {
        // Create pickup marker
        const pickupLat = parseFloat(booking.pickup_lat);
        const pickupLng = parseFloat(booking.pickup_lng);
        
        if (!isNaN(pickupLat) && !isNaN(pickupLng)) {
            const pickupMarker = new google.maps.Marker({
                position: { lat: pickupLat, lng: pickupLng },
                map: driverMap,
                title: `Pickup: ${booking.passenger_name}`,
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png',
                    scaledSize: new google.maps.Size(32, 32),
                    anchor: new google.maps.Point(16, 16)
                }
            });

            const pickupInfoWindow = new google.maps.InfoWindow({
                content: `
                    <div style="min-width: 250px; padding: 15px;">
                        <h4 style="margin: 0 0 10px 0; color: #333;">Pickup Location</h4>
                        <p><strong>Passenger:</strong> ${booking.passenger_name}</p>
                        <p><strong>Phone:</strong> ${booking.phone_number}</p>
                        <p><strong>Address:</strong> ${booking.pickup_location}</p>
                        <p><strong>Status:</strong> <span style="color: orange">${booking.status}</span></p>
                        <div class="mt-2">
                            <button class="btn btn-sm btn-primary" onclick="navigateToPickup(${booking.booking_id})">
                                🚗 Navigate to Pickup
                            </button>
                        </div>
                    </div>
                `
            });

            pickupMarker.addListener('click', () => {
                pickupInfoWindow.open(driverMap, pickupMarker);
            });

            bookingMarkers[`pickup_${booking.booking_id}`] = pickupMarker;
            bounds.extend(pickupMarker.getPosition());
        }

        // Create dropoff marker
        const dropoffLat = parseFloat(booking.dropoff_lat);
        const dropoffLng = parseFloat(booking.dropoff_lng);
        
        if (!isNaN(dropoffLat) && !isNaN(dropoffLng)) {
            const dropoffMarker = new google.maps.Marker({
                position: { lat: dropoffLat, lng: dropoffLng },
                map: driverMap,
                title: `Dropoff: ${booking.passenger_name}`,
                icon: {
                    url: 'https://maps.google.com/mapfiles/ms/icons/green-dot.png',
                    scaledSize: new google.maps.Size(32, 32),
                    anchor: new google.maps.Point(16, 16)
                }
            });

            const dropoffInfoWindow = new google.maps.InfoWindow({
                content: `
                    <div style="min-width: 250px; padding: 15px;">
                        <h4 style="margin: 0 0 10px 0; color: #333;">Dropoff Location</h4>
                        <p><strong>Passenger:</strong> ${booking.passenger_name}</p>
                        <p><strong>Address:</strong> ${booking.dropoff_location}</p>
                        <p><strong>Status:</strong> <span style="color: orange">${booking.status}</span></p>
                    </div>
                `
            });

            dropoffMarker.addListener('click', () => {
                dropoffInfoWindow.open(driverMap, dropoffMarker);
            });

            bookingMarkers[`dropoff_${booking.booking_id}`] = dropoffMarker;
            bounds.extend(dropoffMarker.getPosition());
        }
    });

    // Adjust map view on first load
    if (bookings.length > 0 && !bounds.isEmpty() && firstDataLoad) {
        driverMap.fitBounds(bounds);
        // Add some padding
        driverMap.panToBounds(bounds);
    }
    
    document.getElementById('booking-count').textContent = bookings.length;
    updateBookingList(bookings);
}

// Load vehicle locations using the same flow as admin, but filter to this driver's assigned vehicle
function loadVehicleLocations(silent) {
    silent = !!silent;
    if (!mapInitialized) return;
    if (vehicleLocationsInFlight) return;
    vehicleLocationsInFlight = true;

    const refreshBtn = document.getElementById('refresh-btn');
    const refreshText = document.getElementById('refresh-text');
    const refreshSpinner = document.getElementById('refresh-spinner');
    if (!silent) {
        if (refreshText) refreshText.style.display = 'none';
        if (refreshSpinner) refreshSpinner.style.display = 'inline';
        if (refreshBtn) refreshBtn.disabled = true;
    }

    const assignedId = <?php echo intval($assigned_vehicle_id ?? 0); ?>;
    const vehicleUrl = '../api/vehicle_locations.php' + (assignedId ? '?vehicle_id=' + encodeURIComponent(assignedId) : '');

    fetch(vehicleUrl)
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.status !== 'success') throw new Error(data.message || 'API error');

            const vehicles = (data.vehicles || []).filter(function(v) {
                return !assignedId || Number(v.vehicle_id) === Number(assignedId);
            });

            updateVehicleMap(vehicles);
            const statusTextEl = document.getElementById('map-status-text');
            if (statusTextEl) statusTextEl.textContent = 'Showing ' + vehicles.length + ' vehicle(s)';
        })
        .catch(function(err) {
            console.error('vehicle_locations error', err);
            const statusTextEl = document.getElementById('map-status-text');
            if (statusTextEl) statusTextEl.textContent = 'Vehicle load error';
        })
        .finally(function() {
            vehicleLocationsInFlight = false;
            if (!silent) {
                if (refreshText) refreshText.style.display = 'inline';
                if (refreshSpinner) refreshSpinner.style.display = 'none';
                if (refreshBtn) refreshBtn.disabled = false;
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

// Update vehicle map (copied from admin code, adapted for driver page)
function updateVehicleMap(vehicles) {
    if (!window.vehicleMarkers) {
        window.vehicleMarkers = {};
    }

    if (!vehicles || vehicles.length === 0) {
        const list = document.getElementById('vehicle-list') || document.getElementById('booking-list');
        if (list) list.innerHTML = '<div class="text-center text-muted py-3">No vehicle location available</div>';
        const vcount = document.getElementById('vehicle-count');
        if (vcount) vcount.textContent = '0';
        return;
    }

    const bounds = new google.maps.LatLngBounds();
    const seenVehicleIds = new Set();

    vehicles.forEach(function(vehicle){
        const lat = parseFloat(vehicle.latitude);
        const lng = parseFloat(vehicle.longitude);
        if (isNaN(lat) || isNaN(lng)) return;

        const vehicleName = escapeHtml(vehicle.vehicle_name || 'Vehicle');
        const driverName = escapeHtml(vehicle.driver_name || 'N/A');
        const plate = escapeHtml(vehicle.license_plate || 'N/A');
        const model = escapeHtml(vehicle.vehicle_model || vehicle.vehicle_type || 'N/A');
        const status = escapeHtml(vehicle.status || 'N/A');
        const position = { lat: lat, lng: lng };
        const markerId = String(vehicle.vehicle_id);
        seenVehicleIds.add(markerId);

        const infoContent = `
                <div style="min-width:250px;padding:15px;">
                    <h4 style="margin:0 0 10px 0;color:#333;">${vehicleName}</h4>
                    <p><strong>Driver:</strong> ${driverName}</p>
                    <p><strong>Plate:</strong> ${plate}</p>
                    <p><strong>Model:</strong> ${model}</p>
                    <p><strong>Status:</strong> <span style="color:${vehicle.status === 'active' ? 'green' : 'orange'}">${status}</span></p>
                    <p><strong>Last Update:</strong> ${escapeHtml(vehicle.last_update || '')}s ago</p>
                    <p><strong>Coordinates:</strong><br>${lat.toFixed(6)}, ${lng.toFixed(6)}</p>
                </div>
            `;

        let marker = window.vehicleMarkers[markerId];
        if (marker) {
            moveMarkerSmoothly(marker, position, SMOOTH_MARKER_DURATION_MS);
            marker.setTitle(vehicle.vehicle_name || 'Vehicle');
            if (marker.infoWindow) {
                marker.infoWindow.setContent(infoContent);
            }
        } else {
            marker = new google.maps.Marker({
                position: position,
                map: driverMap,
                title: vehicle.vehicle_name || 'Vehicle',
                icon: {
                    url: 'https://maps.gstatic.com/mapfiles/ms2/micons/bus.png',
                    scaledSize: new google.maps.Size(42, 42),
                    anchor: new google.maps.Point(21, 21)
                }
            });

            marker.infoWindow = new google.maps.InfoWindow({ content: infoContent });

            marker.addListener('click', function(){
                try { Object.values(window.vehicleMarkers).forEach(function(m){ if (m.infoWindow) m.infoWindow.close(); }); } catch(e){}
                marker.infoWindow.open(driverMap, marker);
            });

            window.vehicleMarkers[markerId] = marker;
        }

        bounds.extend(marker.getPosition());
    });

    Object.keys(window.vehicleMarkers).forEach(function(vehicleId) {
        if (!seenVehicleIds.has(vehicleId)) {
            window.vehicleMarkers[vehicleId].setMap(null);
            delete window.vehicleMarkers[vehicleId];
        }
    });

    if (!bounds.isEmpty() && !hasCenteredVehicle) {
        try {
            const firstVehicle = vehicles[0];
            if (firstVehicle) {
                const center = { lat: parseFloat(firstVehicle.latitude), lng: parseFloat(firstVehicle.longitude) };
                driverMap.panTo(center);
                driverMap.setZoom(17);
                hasCenteredVehicle = true;
            }
        } catch (e) {
            // ignore pan/zoom errors
        }
    }

    // Update vehicle list UI if present
    try {
        updateVehicleList(vehicles);
    } catch (e) {}
}

// Update vehicle list (admin-style) - adapted to driver page element IDs
function updateVehicleList(vehicles) {
    const listEl = document.getElementById('vehicle-list') || document.getElementById('booking-list');
    if (!listEl) return;

    let html = '';
    vehicles.forEach(function(vehicle){
        const statusColor = vehicle.status === 'active' ? 'green' : 'orange';
        const vehicleId = Number.parseInt(vehicle.vehicle_id, 10) || 0;
        const vehicleName = escapeHtml(vehicle.vehicle_name || 'Vehicle');
        const plate = escapeHtml(vehicle.license_plate || 'N/A');
        const vehicleModelLine = escapeHtml(vehicle.vehicle_model || vehicle.vehicle_type || 'No model');
        const driverName = escapeHtml(vehicle.driver_name || 'No driver');
        const status = escapeHtml(vehicle.status || 'N/A');
        const lastUpdate = escapeHtml(vehicle.last_update || '0');
        html += `
        <div class="vehicle-item" style="padding: 12px; border-bottom: 1px solid #eee; cursor: pointer;" onclick="centerOnVehicle(${vehicleId})">
            <strong>${vehicleName}</strong> (${plate})<br>
            <small style="color: #666;">${vehicleModelLine}</small><br>
            <small>
                <span style="color: #666;">${driverName}</span> &bull;
                <span style="color: ${statusColor}">${status}</span> &bull;
                ${lastUpdate}s ago
            </small><br>
            <small style="color: #888; font-size: 11px;">${parseFloat(vehicle.latitude).toFixed(6)}, ${parseFloat(vehicle.longitude).toFixed(6)}</small>
        </div>`;
    });

    listEl.innerHTML = html;
    const vcount = document.getElementById('vehicle-count');
    if (vcount) vcount.textContent = vehicles.length;
}

// Center on vehicle - adapted from admin
function centerOnVehicle(vehicleId) {
    const marker = window.vehicleMarkers && window.vehicleMarkers[vehicleId];
    if (!marker) return;

    driverMap.panTo(marker.getPosition());
    driverMap.setZoom(17); // Street-level detail
    marker.setAnimation(google.maps.Animation.BOUNCE);
    setTimeout(function(){ marker.setAnimation(null); }, 2000);
    if (marker.infoWindow) marker.infoWindow.open(driverMap, marker);
}

// Keep the driver's own map fresh without rebuilding markers too aggressively on mobile.
vehicleLocationsTimer = setInterval(function(){ if (mapInitialized) loadVehicleLocations(true); }, VEHICLE_REFRESH_INTERVAL_MS);

// Update booking list sidebar
function updateBookingList(bookings) {
    let html = '';
    bookings.forEach(booking => {
        const statusColor = booking.status === 'confirmed' ? 'orange' : 
                           booking.status === 'in_progress' ? 'blue' : 'green';
        html += `
        <div class="booking-item" style="padding: 12px; border-bottom: 1px solid #eee;">
            <div class="d-flex justify-content-between align-items-start">
                <strong>${booking.passenger_name}</strong>
                <span style="color: ${statusColor}; font-size: 12px;">${booking.status.toUpperCase()}</span>
            </div>
            <div style="font-size: 12px; color: #666;">
                <div>📞 ${booking.phone_number}</div>
                <div class="mt-1">
                    <strong>Pickup:</strong> ${booking.pickup_location}
                </div>
                <div>
                    <strong>Dropoff:</strong> ${booking.dropoff_location}
                </div>
            </div>
            <div class="mt-2">
                <button class="btn btn-sm btn-primary me-1" onclick="navigateToPickup(${booking.booking_id})">
                    🚗 Navigate
                </button>
                <button class="btn btn-sm btn-success" onclick="startTrip(${booking.booking_id})" 
                        ${booking.status !== 'confirmed' ? 'disabled' : ''}>
                    ▶️ Start Trip
                </button>
                <button class="btn btn-sm btn-warning mt-1" onclick="completeTrip(${booking.booking_id})"
                        ${booking.status !== 'in_progress' ? 'disabled' : ''}>
                    ✅ Complete
                </button>
            </div>
        </div>`;
    });
    document.getElementById('booking-list').innerHTML = html;
}

// Navigate to pickup location
function navigateToPickup(bookingId) {
    const pickupMarker = bookingMarkers[`pickup_${bookingId}`];
    const vehicleMarker = window.vehicleMarkers ? Object.values(window.vehicleMarkers)[0] : null;
    
    if (pickupMarker && vehicleMarker) {
        const request = {
            origin: vehicleMarker.getPosition(),
            destination: pickupMarker.getPosition(),
            travelMode: 'DRIVING'
        };

        const directionsService = new google.maps.DirectionsService();
        
        directionsService.route(request, (result, status) => {
            if (status === 'OK') {
                directionsRenderer.setDirections(result);
                // Don't manually set center/zoom - let directions renderer handle the view
                
                // Show notification
                showNotification('Navigation to pickup started', 'success');
            } else {
                showNotification('Could not calculate route', 'error');
            }
        });
    }
}

// Start trip
function startTrip(bookingId) {
    fetch('../api/update_booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `booking_id=${bookingId}&status=in_progress`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Trip started successfully!', 'success');
            loadDriverData(); // Refresh data
        } else {
            showNotification(data.message || 'Failed to start trip', 'error');
        }
    })
    .catch(error => {
        showNotification('Error starting trip', 'error');
    });
}

// Complete trip
function completeTrip(bookingId) {
    fetch('../api/update_booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `booking_id=${bookingId}&status=completed`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Trip completed successfully!', 'success');
            loadDriverData(); // Refresh data
        } else {
            showNotification(data.message || 'Failed to complete trip', 'error');
        }
    })
    .catch(error => {
        showNotification('Error completing trip', 'error');
    });
}

// Center map on driver - removed (no longer needed)

// Toggle online status - NOW UPDATES VEHICLE TABLE
function toggleOnlineStatus() {
    const toggle = document.getElementById('onlineToggle');
    const isOnline = toggle.checked;
    const statusText = document.getElementById('status-text');
    const toggleLabel = document.getElementById('toggle-label');
    const statusIndicator = document.getElementById('status-indicator');
    
    fetch('../api/update_driver_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `status=${isOnline ? 'online' : 'offline'}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const newStatus = isOnline ? 'online' : 'offline';
            statusText.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            statusText.className = `badge ${isOnline ? 'bg-success' : 'bg-secondary'} ms-2`;
            toggleLabel.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
            statusIndicator.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            
            // Update vehicle status display
            updateVehicleStatusDisplay(isOnline ? 'active' : 'inactive');
            
            showNotification(`You are now ${newStatus}`, 'success');
            
            // Start/stop location tracking based on status
            if (isOnline) {
                startLocationTracking();
            } else if (watchId) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
                lastLocationSentAt = 0;
            }
        } else {
            // Revert toggle on error
            toggle.checked = !isOnline;
            showNotification(data.message || 'Failed to update status', 'error');
        }
    })
    .catch(error => {
        // Revert toggle on error
        toggle.checked = !isOnline;
        showNotification('Error updating status', 'error');
    });
}

// Update driver location on server
function updateDriverLocation(lat, lng) {
    if (typeof lat !== 'number' || typeof lng !== 'number' || Number.isNaN(lat) || Number.isNaN(lng)) {
        console.warn('Skipping invalid map location update', lat, lng);
        return;
    }

    fetch('../api/update_driver_location.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `lat=${lat}&lng=${lng}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('location-status').textContent = 'Live';
        }
    })
    .catch(error => {
        console.error('Error updating location:', error);
    });
}

// Show notification
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show`;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '1000';
    notification.style.minWidth = '300px';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 5000);
}

// Handle Google Maps errors
window.gm_authFailure = function() {
    document.getElementById('map-status-text').textContent = 'Google Maps API key error. Check billing, Maps JavaScript API, and localhost referrer rules.';
    document.getElementById('map-loading').style.display = 'block';
};

// Clean up intervals when page is closed
window.addEventListener('beforeunload', function() {
    if (watchId) {
        navigator.geolocation.clearWatch(watchId);
    }
    if (locationIntervalId) {
        clearInterval(locationIntervalId);
    }
    if (vehicleLocationsTimer) {
        clearInterval(vehicleLocationsTimer);
    }
});

// Attach refresh button handler after functions are defined
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded');
    
    const refreshBtn = document.getElementById('refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            if (typeof loadDriverData === 'function') {
                loadDriverData();
                loadVehicleLocations();
            } else {
                console.warn('loadDriverData not defined yet');
            }
        });
    }
    
    // Verify map container exists
    const mapContainer = document.getElementById('driver-map');
    if (!mapContainer) {
        console.error('Map container #driver-map not found!');
    } else {
        console.log('Map container found:', mapContainer);
    }
});

// Now that functions (including initMap) are defined, load the Google Maps script
console.log('About to load Google Maps...');
loadGoogleMaps();
</script>

<style>
.map-layout {
    display: grid;
    grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.map-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.map-main {
    min-width: 0;
}

.info-section {
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.info-section h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 1em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.booking-item:hover {
    background-color: #f8f9fa;
    transition: background-color 0.2s ease;
}

.map-container {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 20px;
}

.driver-map-container {
    height: min(68dvh, 640px) !important;
    min-height: 520px;
    width: 100% !important;
    background: #f8f9fa;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
}

#driver-map {
    height: 100% !important;
    width: 100% !important;
    min-height: inherit;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    text-align: center;
    border-left: 4px solid #e91e63;
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
    color: #e91e63;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

#map-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.75em;
    font-weight: 600;
}

.bg-primary {
    background-color: #e91e63;
    color: white;
}

@media (max-width: 1024px) {
    .map-layout {
        grid-template-columns: 1fr;
    }
    
    .map-sidebar {
        order: 2;
        max-height: none;
    }
    
    .map-main {
        order: 1;
    }
    
    .info-section > div {
        max-height: 200px !important;
    }
}

@media (max-width: 768px) {
    .driver-map-container,
    #driver-map {
        height: min(64dvh, 520px) !important;
        min-height: 380px;
    }
    
    .card {
        padding: 15px;
    }
    
    .card h3 {
        font-size: 1rem;
    }
    
    .info-section h4 {
        font-size: 0.95rem;
    }
    
    .info-section > div {
        max-height: 150px !important;
    }
    
    .table-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
    
    .table-header .btn {
        width: 100%;
        min-height: 44px;
    }
}

@media (max-width: 480px) {
    .dashboard-header h1 {
        font-size: 1.5rem;
    }
    
    .dashboard-header p {
        font-size: 0.9rem;
    }
    
    .driver-map-container,
    #driver-map {
        height: min(62dvh, 480px) !important;
        min-height: 340px;
    }
    
    .map-sidebar {
        padding: 10px;
    }
    
    .card {
        padding: 12px;
    }
    
    .info-section {
        margin-top: 15px;
    }
    
    .badge {
        font-size: 0.75rem;
        padding: 3px 8px;
    }
}
</style>

<!-- Prevent footer from auto-requesting geolocation on this page -->
<script>window.DISABLE_AUTO_GEO = true;</script>
<?php require_once 'footer.php'; ?>
