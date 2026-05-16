<?php
require_once 'auth.php';

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : null;

if (!$booking_id) {
    header('Location: booking.php');
    exit;
}

// Get booking details
$booking_query = $conn->query("
    SELECT b.*, 
           v.vehicle_name, v.vehicle_id, v.license_plate, v.vehicle_type, d.full_name as driver_name, d.phone_number, 
           l.latitude, l.longitude, l.timestamp
    FROM bookings b
    LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
    LEFT JOIN drivers d ON d.user_id = COALESCE(b.driver_id, v.driver_id)
    LEFT JOIN locations l ON v.vehicle_id = l.vehicle_id AND l.timestamp = (
        SELECT MAX(timestamp) FROM locations WHERE vehicle_id = v.vehicle_id
    )
    WHERE b.booking_id = $booking_id AND b.passenger_id = $user_id
");

if ($booking_query->num_rows === 0) {
    header('Location: booking.php');
    exit;
}

$booking = $booking_query->fetch_assoc();

// Check if booking can be tracked
$can_track = in_array($booking['status'], ['confirmed', 'in_progress', 'completed'], true);

if (!$can_track) {
    header('Location: booking.php');
    exit;
}

$google_maps_script_url = google_maps_script_url(null, ['places', 'geometry']);
require_once 'header.php';
?>

    <style>
        .tracking-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: clamp(1rem, 2vw, 2rem);
            padding: clamp(1rem, 2vw, 2rem);
            margin: clamp(1rem, 2vw, 2rem) auto;
            max-width: 100vw;
            width: 100%;
            box-sizing: border-box;
        }

        @media (max-width: 1024px) {
            .tracking-container {
                grid-template-columns: 1fr !important;
                padding: 1rem !important;
            }
            #trackingMap {
                height: 300px !important;
                min-height: 200px !important;
            }
        }

        @media (max-width: 480px) {
            .tracking-container {
                padding: 0.5rem !important;
                gap: 0.5rem !important;
            }
            .booking-details, .driver-card {
                padding: 0.5rem !important;
            }
        }

        #trackingMap {
            width: 100%;
            height: 60vh; /* Responsive height */
            min-height: 500px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .booking-details {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .booking-details h2 {
            margin-bottom: 20px;
            color: var(--primary-color);
        }

        .detail-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1.1rem;
            color: var(--text-color);
        }

        .driver-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-color-dark));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .driver-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            margin: 0 auto 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }

        .driver-card h3 {
            margin: 10px 0 5px;
        }

        .driver-card p {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f0f0f0;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .status-indicator.confirmed {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-indicator.in_progress {
            background: #fff3e0;
            color: #e65100;
        }

        .status-indicator.completed {
            background: #e3f2fd;
            color: #1976d2;
        }

        .eta-display {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
        }

        .eta-display .label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .eta-display .time {
            font-size: 1.8rem;
            font-weight: bold;
            margin-top: 5px;
        }

        .location-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
        }

        .location-info .icon {
            font-size: 1.2rem;
            margin-right: 8px;
        }

        .back-button {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .back-button:hover {
            background: var(--primary-color-dark);
        }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: #4caf50;
            font-weight: 600;
        }

        .live-indicator::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #4caf50;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(76, 175, 80, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
            }
        }

        .contact-button {
            display: inline-block;
            padding: 10px 15px;
            background: #4caf50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 10px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.3s;
        }

        .contact-button:hover {
            background: #45a049;
        }

        @media (max-width: 1024px) {
            .tracking-container {
                grid-template-columns: 1fr;
            }
            
            #trackingMap {
                height: 400px;
            }
        }
    </style>
    <a href="booking.php" class="back-button">
        <i class="fas fa-arrow-left"></i> Back to Bookings
    </a>

    <div class="tracking-container">
        <div>
            <div id="trackingMap"></div>
        </div>

        <div class="booking-details">
            <div class="status-indicator <?php echo $booking['status']; ?>">
                <?php 
                    $status_icons = [
                        'confirmed' => '⏳',
                        'in_progress' => '🚗',
                        'completed' => '✓'
                    ];
                    echo ($status_icons[$booking['status']] ?? '•') . ' ';
                    echo ucwords(str_replace('_', ' ', $booking['status']));
                ?>
            </div>

            <?php if ($booking['driver_name']): ?>
                <div class="driver-card">
                    <div class="driver-avatar">🚕</div>
                    <h3><?php echo htmlspecialchars($booking['driver_name']); ?></h3>
                    <p><?php echo $booking['vehicle_type'] ?? 'Vehicle'; ?> • <?php echo $booking['license_plate']; ?></p>
                    <a href="tel:<?php echo $booking['phone_number']; ?>" class="contact-button">
                        <i class="fas fa-phone"></i> Call Driver
                    </a>
                </div>

                <?php if (!empty($booking['vehicle_id'])): ?>
                    <div class="eta-display">
                        <div class="label">Estimated Time to Pickup</div>
                        <div class="time" id="etaToPickup"><?php echo ($booking['latitude'] && $booking['longitude']) ? 'Calculating...' : 'Waiting for GPS'; ?></div>
                        <span class="live-indicator">Updates every 10 seconds</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$booking['driver_name'] && !empty($booking['vehicle_id'])): ?>
                <div class="eta-display">
                    <div class="label">Estimated Time to Pickup</div>
                    <div class="time" id="etaToPickup"><?php echo ($booking['latitude'] && $booking['longitude']) ? 'Calculating...' : 'Waiting for GPS'; ?></div>
                    <span class="live-indicator">Updates every 10 seconds</span>
                </div>
            <?php endif; ?>

            <div class="detail-item">
                <div class="detail-label">📍 Pickup Location</div>
                <div class="detail-value"><?php echo htmlspecialchars($booking['pickup_location']); ?></div>
                <?php if (!empty($booking['pickup_lat']) && !empty($booking['pickup_lng'])): ?>
                <div class="location-info">
                    <small>Lat: <?php echo round($booking['pickup_lat'], 4); ?>, Lng: <?php echo round($booking['pickup_lng'], 4); ?></small>
                </div>
                <?php endif; ?>
            </div>

            <div class="detail-item">
                <div class="detail-label">🎯 Dropoff Location</div>
                <div class="detail-value"><?php echo htmlspecialchars($booking['dropoff_location']); ?></div>
                <?php if (!empty($booking['dropoff_lat']) && !empty($booking['dropoff_lng'])): ?>
                <div class="location-info">
                    <small>Lat: <?php echo round($booking['dropoff_lat'], 4); ?>, Lng: <?php echo round($booking['dropoff_lng'], 4); ?></small>
                </div>
                <?php endif; ?>
            </div>

            <div class="detail-item">
                <div class="detail-label">💰 Total Bill</div>
                <div class="detail-value">₱<?php echo number_format((float) ($booking['fare'] ?: $booking['fare_estimate']), 2); ?></div>
                <?php if (intval($booking['baggage_count'] ?? 0) > 0): ?>
                    <small><?php echo intval($booking['baggage_count']); ?> bag(s) x ₱<?php echo number_format((float) BAGGAGE_FEE_PER_BAG, 2); ?> included</small>
                <?php endif; ?>
            </div>

            <div class="detail-item">
                <div class="detail-label">🚌 Vehicle</div>
                <div class="detail-value"><?php echo htmlspecialchars($booking['vehicle_name']); ?></div>
            </div>

            <div class="detail-item">
                <div class="detail-label">📝 Notes</div>
                <div class="detail-value">
                    <?php echo !empty($booking['notes']) ? htmlspecialchars($booking['notes']) : '<em style="color: #999;">No special notes</em>'; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars($google_maps_script_url, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script>
        const bookingData = {
            booking_id: <?php echo $booking_id; ?>,
            pickup_lat: <?php echo !empty($booking['pickup_lat']) ? $booking['pickup_lat'] : 'null'; ?>,
            pickup_lng: <?php echo !empty($booking['pickup_lng']) ? $booking['pickup_lng'] : 'null'; ?>,
            dropoff_lat: <?php echo !empty($booking['dropoff_lat']) ? $booking['dropoff_lat'] : 'null'; ?>,
            dropoff_lng: <?php echo !empty($booking['dropoff_lng']) ? $booking['dropoff_lng'] : 'null'; ?>,
            vehicle_id: <?php echo $booking['vehicle_id'] ? $booking['vehicle_id'] : 'null'; ?>,
            status: '<?php echo $booking['status']; ?>',
            pickup_address: '<?php echo addslashes($booking['pickup_location']); ?>',
            dropoff_address: '<?php echo addslashes($booking['dropoff_location']); ?>'
        };

        let map, pickupMarker, dropoffMarker, vehicleMarker;
        let passengerMarker = null;
        let passengerPosition = null;
        let passengerInterval = null;
        let directionsService, directionsRenderer, vehicleToPickupRenderer;
        let vehicleLocation = null;
        let updateInterval = null;

        function initTrackingMap() {
            // Handle missing coordinates by geocoding
            if (!bookingData.pickup_lat || !bookingData.pickup_lng || !bookingData.dropoff_lat || !bookingData.dropoff_lng) {
                geocodeMissingCoordinates();
                return;
            }
            startMapInitialization();
        }

        function geocodeMissingCoordinates() {
            const geocoder = new google.maps.Geocoder();
            let pending = 0;
            
            if (!bookingData.pickup_lat || !bookingData.pickup_lng) {
                pending++;
                geocoder.geocode({ address: bookingData.pickup_address }, function(results, status) {
                    pending--;
                    if (status === 'OK' && results[0]) {
                        bookingData.pickup_lat = results[0].geometry.location.lat();
                        bookingData.pickup_lng = results[0].geometry.location.lng();
                    }
                    if (pending === 0) startMapInitialization();
                });
            }
            
            if (!bookingData.dropoff_lat || !bookingData.dropoff_lng) {
                pending++;
                geocoder.geocode({ address: bookingData.dropoff_address }, function(results, status) {
                    pending--;
                    if (status === 'OK' && results[0]) {
                        bookingData.dropoff_lat = results[0].geometry.location.lat();
                        bookingData.dropoff_lng = results[0].geometry.location.lng();
                    }
                    if (pending === 0) startMapInitialization();
                });
            }
            
            // If nothing was pending (e.g. only one was missing and it failed immediately? No, pending++ happens first)
            // Actually if both are present we wouldn't be here.
            // But if we are here, pending > 0.
        }

        function startMapInitialization() {
            // If still missing after geocoding attempt, fallback to default or alert?
            // Just try to render with what we have or default center
            const centerLat = bookingData.pickup_lat || 14.5995;
            const centerLng = bookingData.pickup_lng || 120.9842;

            const mapOptions = {
                    zoom: 17,
                    center: {lat: parseFloat(centerLat), lng: parseFloat(centerLng)},
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                styles: [
                    {
                        "featureType": "poi",
                        "elementType": "labels",
                        "stylers": [{"visibility": "off"}]
                    }
                ]
            };

            map = new google.maps.Map(document.getElementById('trackingMap'), mapOptions);
            directionsService = new google.maps.DirectionsService();
            
            // Main route renderer (pickup to dropoff - blue)
            directionsRenderer = new google.maps.DirectionsRenderer({
                map: map,
                polylineOptions: {
                    strokeColor: '#2196F3',
                    strokeWeight: 3,
                    strokeOpacity: 0.8
                },
                suppressMarkers: true
            });

            // Vehicle route renderer (vehicle to pickup - orange)
            vehicleToPickupRenderer = new google.maps.DirectionsRenderer({
                map: map,
                polylineOptions: {
                    strokeColor: '#FF9800',
                    strokeWeight: 3,
                    strokeOpacity: 0.8
                },
                suppressMarkers: true
            });

            // Pickup marker (green)
            if (bookingData.pickup_lat && bookingData.pickup_lng) {
                pickupMarker = new google.maps.Marker({
                    position: {lat: parseFloat(bookingData.pickup_lat), lng: parseFloat(bookingData.pickup_lng)},
                    map: map,
                    title: 'Pickup Location',
                    icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'
                });
            }

            // Dropoff marker (red)
            if (bookingData.dropoff_lat && bookingData.dropoff_lng) {
                dropoffMarker = new google.maps.Marker({
                    position: {lat: parseFloat(bookingData.dropoff_lat), lng: parseFloat(bookingData.dropoff_lng)},
                    map: map,
                    title: 'Dropoff Location',
                    icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
                });
            }

            // Draw the main route (pickup to dropoff)
            if (bookingData.pickup_lat && bookingData.pickup_lng && bookingData.dropoff_lat && bookingData.dropoff_lng) {
                drawMainRoute();
            }

            // Initialize passenger location (browser geolocation)
            initPassengerLocation();

            // Initialize vehicle tracking
            updateVehicleLocation();
            updateInterval = setInterval(updateVehicleLocation, 10000); // Update every 10 seconds
        }

        function drawMainRoute() {
            directionsService.route({
                origin: {lat: parseFloat(bookingData.pickup_lat), lng: parseFloat(bookingData.pickup_lng)},
                destination: {lat: parseFloat(bookingData.dropoff_lat), lng: parseFloat(bookingData.dropoff_lng)},
                travelMode: google.maps.TravelMode.DRIVING
            }, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    directionsRenderer.setDirections(result);
                }
            });
        }

        function updateVehicleLocation() {
            if (!bookingData.vehicle_id) return;

            fetch(`../api/vehicle_locations.php?vehicle_id=${bookingData.vehicle_id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.vehicles && data.vehicles.length > 0) {
                        const loc = data.vehicles[0];
                        const vehicleLat = parseFloat(loc.latitude);
                        const vehicleLng = parseFloat(loc.longitude);
                        if (isNaN(vehicleLat) || isNaN(vehicleLng)) {
                            return;
                        }

                        vehicleLocation = {lat: vehicleLat, lng: vehicleLng};
                        updateVehicleMarker();
                        
                        if (!bookingData.pickup_lat || !bookingData.pickup_lng) return;

                        // Determine whether vehicle is approaching pickup or already at/near pickup
                        try {
                            const vehicleLatLng = new google.maps.LatLng(vehicleLocation.lat, vehicleLocation.lng);
                            const pickupLatLng = new google.maps.LatLng(parseFloat(bookingData.pickup_lat), parseFloat(bookingData.pickup_lng));
                            const distToPickup = google.maps.geometry.spherical.computeDistanceBetween(vehicleLatLng, pickupLatLng);

                            // If vehicle is farther than 30 meters, show route to pickup and full pickup->dropoff route
                            if (distToPickup > 30) {
                                // show vehicle -> pickup (orange)
                                drawVehicleToPickupRoute();
                                // ensure pickup->dropoff (blue) remains visible as the full route
                                drawMainRoute();
                            } else {
                                // vehicle arrived at pickup (or very near): hide vehicle->pickup route
                                vehicleToPickupRenderer.setDirections({routes: []});
                                
                                if (bookingData.dropoff_lat && bookingData.dropoff_lng) {
                                    // show remaining route from vehicle (current position) -> dropoff
                                    directionsService.route({
                                        origin: vehicleLocation,
                                        destination: {lat: parseFloat(bookingData.dropoff_lat), lng: parseFloat(bookingData.dropoff_lng)},
                                        travelMode: google.maps.TravelMode.DRIVING
                                    }, function(result, status) {
                                        if (status === google.maps.DirectionsStatus.OK) {
                                            directionsRenderer.setDirections(result);
                                        }
                                    });
                                }
                            }
                        } catch (e) {
                            // Fallback: draw vehicle->pickup and update ETA
                            drawVehicleToPickupRoute();
                        }

                        updateETADisplay();
                    }
                })
                .catch(error => console.error('Error fetching vehicle location:', error));
        }

        function updateVehicleMarker() {
            if (!vehicleLocation) return;

            if (!vehicleMarker) {
                vehicleMarker = new google.maps.Marker({
                    position: vehicleLocation,
                    map: map,
                    title: 'Vehicle Location',
                    icon: {
                        url: 'https://maps.gstatic.com/mapfiles/ms2/micons/bus.png',
                        scaledSize: new google.maps.Size(42, 42),
                        anchor: new google.maps.Point(21, 21)
                    },
                    animation: google.maps.Animation.DROP
                });
            } else {
                vehicleMarker.setPosition(vehicleLocation);
            }

                map.panTo(vehicleLocation);
                map.setZoom(17);
        }

        function drawVehicleToPickupRoute() {
            if (!vehicleLocation || !bookingData.pickup_lat || !bookingData.pickup_lng) return;

            directionsService.route({
                origin: vehicleLocation,
                destination: {lat: parseFloat(bookingData.pickup_lat), lng: parseFloat(bookingData.pickup_lng)},
                travelMode: google.maps.TravelMode.DRIVING
            }, function(result, status) {
                if (status === google.maps.DirectionsStatus.OK) {
                    vehicleToPickupRenderer.setDirections(result);
                    try {
                        const leg = result.routes[0].legs[0];
                        const etaEl = document.getElementById('etaToPickup');
                        if (etaEl && leg && leg.duration) {
                            const mins = Math.ceil(leg.duration.value / 60);
                            etaEl.textContent = mins <= 0 ? 'Arriving now' : (mins + ' minutes');
                        }
                    } catch (e) { console.error(e); }
                }
            });
        }

        // Initialize passenger geolocation on the map and keep it updated
        function initPassengerLocation() {
            if (!navigator.geolocation) return;

            navigator.geolocation.getCurrentPosition(function(position) {
                passengerPosition = {lat: position.coords.latitude, lng: position.coords.longitude};
                if (!passengerMarker) {
                    passengerMarker = new google.maps.Marker({
                        position: passengerPosition,
                        map: map,
                        title: 'Your Location',
                        icon: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                    });
                } else {
                    passengerMarker.setPosition(passengerPosition);
                }
            });

            // Poll passenger location periodically (every 10s)
            passengerInterval = setInterval(function() {
                navigator.geolocation.getCurrentPosition(function(position) {
                    passengerPosition = {lat: position.coords.latitude, lng: position.coords.longitude};
                    if (!passengerMarker) {
                        passengerMarker = new google.maps.Marker({
                            position: passengerPosition,
                            map: map,
                            title: 'Your Location',
                            icon: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                        });
                    } else {
                        passengerMarker.setPosition(passengerPosition);
                    }
                });
            }, 10000);
        }

        function updateETADisplay() {
            if (!vehicleLocation || !bookingData.pickup_lat || !bookingData.pickup_lng) return;

            // Estimate speed at 40 km/h
            const distance = google.maps.geometry.spherical.computeDistanceBetween(
                new google.maps.LatLng(vehicleLocation.lat, vehicleLocation.lng),
                new google.maps.LatLng(parseFloat(bookingData.pickup_lat), parseFloat(bookingData.pickup_lng))
            );

            const distanceKm = distance / 1000;
            const speedKmh = 40;
            const etaMinutes = Math.ceil((distanceKm / speedKmh) * 60);

            const etaElement = document.getElementById('etaToPickup');
            if (etaElement) {
                if (etaMinutes < 1) {
                    etaElement.textContent = 'Arriving now';
                } else if (etaMinutes === 1) {
                    etaElement.textContent = '1 minute';
                } else {
                    etaElement.textContent = etaMinutes + ' minutes';
                }
            }
        }

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', initTrackingMap);

        window.gm_authFailure = function() {
            const mapElement = document.getElementById('trackingMap');
            if (mapElement) {
                mapElement.innerHTML = '<div style="padding: 2rem; text-align: center; color: #b00020;">Google Maps API key error. Check billing, Maps JavaScript API, and localhost referrer rules.</div>';
            }
        };

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            if (updateInterval) clearInterval(updateInterval);
        });
    </script>

    <?php require_once 'footer.php'; ?>
