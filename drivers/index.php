<?php
require_once 'auth.php';
require_once 'header.php';

// Set timezone to match your location
date_default_timezone_set('Asia/Manila'); // Change this to your timezone if needed

// Use user_id for bookings (matches bookings.driver_id), and profile id for earnings
$today_date = date('Y-m-d');

// Today's bookings (by user_id stored in bookings.driver_id)
$today_bookings_query = $conn->query("
    SELECT COUNT(*) as count 
    FROM bookings 
WHERE driver_id = $driver_id 
    AND DATE(created_at) = '$today_date'
    AND status IN ('confirmed', 'in_progress', 'completed')
");
$today_bookings = $today_bookings_query ? $today_bookings_query->fetch_assoc() : ['count' => 0];

// Get today's earnings from driver_earnings table (uses drivers.driver_id)
$earnings_driver_id = $driver_profile_id ?: $driver_id;

$today_earnings_result = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM driver_earnings 
    WHERE driver_id = $earnings_driver_id 
    AND DATE(earning_date) = '$today_date'
    AND status = 'pending'
");
$today_earnings_result = $today_earnings_result ? $today_earnings_result->fetch_assoc() : ['total' => 0];
$today_earnings = $today_earnings_result['total'] ?? 0;

// Get weekly earnings
$weekly_earnings_result = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM driver_earnings 
    WHERE driver_id = $earnings_driver_id 
    AND YEARWEEK(earning_date, 1) = YEARWEEK('$today_date', 1)
    AND status = 'pending'
");
$weekly_earnings_result = $weekly_earnings_result ? $weekly_earnings_result->fetch_assoc() : ['total' => 0];
$weekly_earnings = $weekly_earnings_result['total'] ?? 0;

// Get vehicle information - check if we already have it from auth.php
if (isset($driver_data['vehicle_id']) && !empty($driver_data['vehicle_id'])) {
    // If vehicle_id exists in driver_data from auth.php
    $vehicle_query = $conn->query("
        SELECT v.*, r.route_name,
               (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.vehicle_id AND b.status = 'completed') as total_bookings
        FROM vehicles v 
        LEFT JOIN routes r ON v.route_id = r.route_id
        WHERE v.vehicle_id = " . $driver_data['vehicle_id'] . "
        LIMIT 1
    ");
    
    if ($vehicle_query && $vehicle_query->num_rows > 0) {
        $vehicle_info = $vehicle_query->fetch_assoc();
    } else {
        // If no vehicle found with that ID, try to get any vehicle for this driver
        $vehicle_query2 = $conn->query("
            SELECT v.*, r.route_name,
                   (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.vehicle_id AND b.status = 'completed') as total_bookings
            FROM vehicles v 
            LEFT JOIN routes r ON v.route_id = r.route_id
            WHERE v.driver_id = $driver_id
            LIMIT 1
        ");
        
        if ($vehicle_query2 && $vehicle_query2->num_rows > 0) {
            $vehicle_info = $vehicle_query2->fetch_assoc();
        } else {
            // If no vehicle found at all, set default values
            $vehicle_info = [
                'vehicle_id' => 0,
                'vehicle_name' => 'No Vehicle Assigned',
                'license_plate' => 'N/A',
                'vehicle_type' => 'N/A',
                'vehicle_color' => 'N/A',
                'route_name' => 'N/A',
                'status' => 'inactive',
                'total_bookings' => 0
            ];
        }
    }
} else {
    // If no vehicle_id in driver_data, try to get vehicle directly
    $vehicle_query = $conn->query("
        SELECT v.*, r.route_name,
               (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.vehicle_id AND b.status = 'completed') as total_bookings
        FROM vehicles v 
        LEFT JOIN routes r ON v.route_id = r.route_id
        WHERE v.driver_id = $driver_id
        LIMIT 1
    ");
    
    if ($vehicle_query && $vehicle_query->num_rows > 0) {
        $vehicle_info = $vehicle_query->fetch_assoc();
    } else {
        $vehicle_info = [
            'vehicle_id' => 0,
            'vehicle_name' => 'No Vehicle Assigned',
            'license_plate' => 'N/A',
            'vehicle_type' => 'N/A',
            'vehicle_color' => 'N/A',
            'route_name' => 'N/A',
            'status' => 'inactive',
            'total_bookings' => 0
        ];
    }
}

// Debug: Check what vehicle info we have
// echo "<pre>"; print_r($vehicle_info); echo "</pre>";
?>

<div class="dashboard-header">
    <h1>Driver Dashboard</h1>
    <p>Welcome back, <?php echo htmlspecialchars($driver_data['full_name'] ?? $_SESSION['full_name'] ?? 'Driver'); ?>! Here's your driving overview.</p>
</div>

<!-- Driver Stats -->
<div class="driver-stats">
    <div class="stat-card">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-peso-sign"></i></div>
            <div class="stat-number">PHP <?php echo number_format($today_earnings, 2); ?></div>
            <div class="stat-label">Today's Earnings</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-route"></i></div>
            <div class="stat-number"><?php echo $today_bookings['count'] ?? 0; ?></div>
            <div class="stat-label">Today's Bookings</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-number">PHP <?php echo number_format($weekly_earnings, 2); ?></div>
            <div class="stat-label">Weekly Earnings</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <div class="stat-icon"><i class="fas fa-star"></i></div>
            <div class="stat-number"><?php echo $vehicle_info['total_bookings'] ?? 0; ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="action-cards">
    <div class="action-card">
        <div class="action-content">
            <div class="action-icon"><i class="fas fa-location-dot"></i></div>
            <h3>Live Location Sharing</h3>
            <p>Your location now updates automatically every 6 seconds while you are online. Use this only as a manual fallback.</p>
            <button class="btn btn-primary" onclick="openLocationModal()">
                <i class="fas fa-map-marker-alt"></i> Send Location Now
            </button>
        </div>
    </div>
    
    <div class="action-card">
        <div class="action-content">
            <div class="action-icon"><i class="fas fa-users"></i></div>
            <h3>Manage Bookings</h3>
            <p>View and manage passenger booking requests. Accept or decline ride requests.</p>
            <a href="bookings.php" class="btn btn-primary">
                <i class="fas fa-calendar-check"></i> View Bookings
            </a>
        </div>
    </div>
    
    <div class="action-card">
        <div class="action-content">
            <div class="action-icon"><i class="fas fa-map-marked-alt"></i></div>
            <h3>Live Map</h3>
            <p>Access real-time navigation and view your assigned routes and passenger locations.</p>
            <a href="map.php" class="btn btn-primary">
                <i class="fas fa-map-marked-alt"></i> Open Map
            </a>
        </div>
    </div>
</div>

<!-- Recent Bookings -->
<div class="section-header">
    <h2>Recent Bookings</h2>
    <div class="section-actions">
        <a href="bookings.php" class="btn btn-secondary">View All</a>
    </div>
</div>

<div class="booking-cards">
    <?php
    // Get recent bookings for this driver
    $recent_bookings_query = "
        SELECT b.*, c.full_name, c.phone_number 
        FROM bookings b 
        JOIN customers c ON b.passenger_id = c.user_id 
        WHERE (b.driver_id = $driver_id OR b.vehicle_id = " . ($vehicle_info['vehicle_id'] ?? 0) . ")
        AND b.status IN ('pending', 'confirmed', 'in_progress')
        ORDER BY b.created_at DESC 
        LIMIT 3
    ";
    
    $recent_bookings = $conn->query($recent_bookings_query);
    
    if ($recent_bookings && $recent_bookings->num_rows > 0):
        while ($booking = $recent_bookings->fetch_assoc()):
    ?>
    <div class="booking-card">
        <div class="booking-header">
            <div class="booking-id">Booking #<?php echo $booking['booking_id']; ?></div>
            <div class="booking-passenger"><?php echo htmlspecialchars($booking['full_name']); ?></div>
            <div class="booking-time">
                <?php echo date('M j, Y g:i A', strtotime($booking['requested_time'] ?? $booking['created_at'])); ?>
            </div>
        </div>
        <div class="booking-body">
            <div class="booking-route">
                <div class="route-point pickup">
                    <div class="point-icon"><i class="fas fa-location-dot"></i></div>
                    <div class="point-info">
                        <h4>Pickup</h4>
                        <p><?php echo htmlspecialchars($booking['pickup_location']); ?></p>
                    </div>
                </div>
                <div class="route-point dropoff">
                    <div class="point-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="point-info">
                        <h4>Dropoff</h4>
                        <p><?php echo htmlspecialchars($booking['dropoff_location']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="booking-details">
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="status-badge status-<?php echo $booking['status']; ?>">
                        <?php echo ucwords(str_replace('_', ' ', $booking['status'])); ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Fare</span>
                    <span class="detail-value">PHP <?php echo number_format($booking['fare_estimate'] ?? 0, 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Passenger</span>
                    <span class="detail-value"><?php echo htmlspecialchars($booking['full_name']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Contact</span>
                    <span class="detail-value"><?php echo htmlspecialchars($booking['phone_number']); ?></span>
                </div>
            </div>
            
            <?php if ($booking['status'] == 'pending'): ?>
            <div class="booking-actions">
                <button class="btn btn-success" onclick="showConfirmModal(<?php echo intval($booking['booking_id'] ?? 0); ?>, 'accept')">
                    <i class="fas fa-check"></i> Accept
                </button>
                <button class="btn btn-danger" onclick="showConfirmModal(<?php echo intval($booking['booking_id'] ?? 0); ?>, 'reject')">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php 
        endwhile;
    else:
    ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
        <h3>No Recent Bookings</h3>
        <p>You don't have any recent bookings. New ride requests will appear here.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Vehicle Information -->
<div class="section-header">
    <h2>Vehicle Information</h2>
</div>

<div class="vehicle-card-upgraded">
    <div class="vehicle-card-header">
        <span class="vehicle-icon"><i class="fas fa-car"></i></span>
        <div class="vehicle-title-wrap">
            <span class="vehicle-title">
                <?php echo htmlspecialchars($vehicle_info['vehicle_name'] ?? 'No Vehicle Assigned'); ?>
            </span>
            <?php if (($vehicle_info['status'] ?? 'inactive') === 'inactive'): ?>
                <span class="vehicle-substatus">Inactive</span>
            <?php endif; ?>
        </div>
        <?php if (($vehicle_info['status'] ?? 'inactive') !== 'inactive'): ?>
            <span class="status-badge <?php 
                if (($vehicle_info['status'] ?? '') === 'active') {
                    echo 'status-confirmed';
                } else {
                    echo 'status-cancelled';
                }
            ?>">
                <?php echo ucfirst($vehicle_info['status'] ?? 'inactive'); ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="vehicle-card-body">
        <div class="vehicle-details-grid">
            <div class="vehicle-detail">
                <span class="detail-label">License Plate</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle_info['license_plate'] ?? 'N/A'); ?></span>
            </div>
            <?php if (!empty($vehicle_info['route_name']) && $vehicle_info['route_name'] !== 'N/A'): ?>
            <div class="vehicle-detail">
                <span class="detail-label">Assigned Route</span>
                <span class="detail-value" style="color: #2e7d32;"><?php echo htmlspecialchars($vehicle_info['route_name']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($vehicle_info['vehicle_type']) && $vehicle_info['vehicle_type'] !== 'N/A'): ?>
            <div class="vehicle-detail">
                <span class="detail-label">Type</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle_info['vehicle_type']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($vehicle_info['vehicle_color']) && $vehicle_info['vehicle_color'] !== 'N/A'): ?>
            <div class="vehicle-detail">
                <span class="detail-label">Color</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle_info['vehicle_color']); ?></span>
            </div>
            <?php endif; ?>
            <div class="vehicle-detail">
                <span class="detail-label">Total Bookings</span>
                <span class="detail-value"><?php echo $vehicle_info['total_bookings'] ?? 0; ?></span>
            </div>
            <?php if (!empty($vehicle_info['model_year'])): ?>
            <div class="vehicle-detail">
                <span class="detail-label">Year</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle_info['model_year']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($vehicle_info['capacity'])): ?>
            <div class="vehicle-detail">
                <span class="detail-label">Capacity</span>
                <span class="detail-value"><?php echo htmlspecialchars($vehicle_info['capacity']); ?> seats</span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (($vehicle_info['vehicle_id'] ?? 0) == 0): ?>
        <div class="vehicle-empty-state">
            <p>You haven't registered a vehicle yet. Register your vehicle to start accepting bookings.</p>
            <a href="register_vehicle.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Register Vehicle
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.vehicle-card-upgraded {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(233,30,99,0.08), 0 1.5px 4px rgba(33,37,41,0.04);
    padding: 28px 32px 20px 32px;
    margin: 12px 0 32px 0;
    max-width: 920px;
    width: 100%;
    box-sizing: border-box;
    transition: box-shadow 0.2s;
}
.vehicle-card-upgraded:hover {
    box-shadow: 0 8px 24px rgba(233,30,99,0.13), 0 2px 8px rgba(33,37,41,0.07);
}
.vehicle-card-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
}
.vehicle-title-wrap {
    display: flex;
    flex-direction: column;
}
.vehicle-substatus {
    font-size: 0.85rem;
    color: #888;
    margin-top: 4px;
}
.vehicle-icon {
    font-size: 2.2rem;
    color: var(--primary-pink, #e91e63);
    background: var(--light-pink, #f8bbd9);
    border-radius: 50%;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(233,30,99,0.08);
}
.vehicle-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--dark-gray, #212529);
    flex: 1;
}
.vehicle-card-header .status-badge {
    font-size: 0.95rem;
    padding: 7px 16px;
    border-radius: 6px;
    font-weight: 500;
}
.vehicle-card-body {
    margin-top: 8px;
}
.vehicle-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 18px 32px;
    margin-bottom: 20px;
}
.vehicle-detail .detail-label {
    display: block;
    font-size: 0.92rem;
    color: #888;
    margin-bottom: 2px;
}
.vehicle-detail .detail-value {
    font-size: 1.08rem;
    color: var(--dark-gray, #212529);
    font-weight: 500;
}
.vehicle-empty-state {
    text-align: center;
    padding: 30px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 2px dashed #dee2e6;
}
.vehicle-empty-state p {
    color: #6c757d;
    margin-bottom: 20px;
}
@media (max-width: 600px) {
    .vehicle-card-upgraded {
        padding: 16px 8px 12px 8px;
    }
    .vehicle-card-header {
        gap: 8px;
    }
    .vehicle-title {
        font-size: 1.05rem;
    }
    .vehicle-details-grid {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 10px 8px;
    }
}
</style>

<div id="confirmModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.6); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; border-radius: 12px; padding: 32px; max-width: 420px; width: 90%; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); text-align: center;">
        <h3 id="confirmTitle" style="margin: 0 0 16px 0; font-size: 1.3rem; color: #212529;"></h3>
        <p id="confirmMessage" style="margin: 0 0 24px 0; font-size: 1rem; color: #666; line-height: 1.6;"></p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" onclick="closeConfirmModal()" style="flex: 1; padding: 12px 20px; border: none; border-radius: 6px; background: #e9ecef; color: #212529; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                Cancel
            </button>
            <button type="button" id="confirmActionBtn" onclick="proceedWithAction()" style="flex: 1; padding: 12px 20px; border: none; border-radius: 6px; background: #2196F3; color: white; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                Confirm
            </button>
        </div>
    </div>
</div>

<script>
let pendingBookingId = null;
let pendingAction = null;

function showConfirmModal(bookingId, action) {
    console.log('showConfirmModal called with:', bookingId, action);
    pendingBookingId = bookingId;
    pendingAction = action;
    
    const modal = document.getElementById('confirmModal');
    console.log('Modal element found:', modal);
    const title = document.getElementById('confirmTitle');
    const message = document.getElementById('confirmMessage');
    const btn = document.getElementById('confirmActionBtn');
    
    if (action === 'accept') {
        title.textContent = 'Accept Booking?';
        message.textContent = 'You are about to accept this booking. The passenger will be notified and earnings will be recorded.';
        btn.textContent = 'Accept Booking';
        btn.style.background = '#28a745';
        btn.onmouseover = function() { this.style.background = '#218838'; };
        btn.onmouseout = function() { this.style.background = '#28a745'; };
    } else if (action === 'reject') {
        title.textContent = 'Reject Booking?';
        message.textContent = 'You are about to reject this booking. The passenger will be notified and can request another driver.';
        btn.textContent = 'Reject Booking';
        btn.style.background = '#dc3545';
        btn.onmouseover = function() { this.style.background = '#c82333'; };
        btn.onmouseout = function() { this.style.background = '#dc3545'; };
    }
    
    console.log('Setting modal display to flex');
    modal.style.display = 'flex';
    console.log('Modal should now be visible');
}

function closeConfirmModal() {
    console.log('closeConfirmModal called');
    document.getElementById('confirmModal').style.display = 'none';
    pendingBookingId = null;
    pendingAction = null;
}

function proceedWithAction() {
    console.log('proceedWithAction called:', pendingBookingId, pendingAction);
    if (!pendingBookingId || !pendingAction) {
        console.log('Validation failed - missing bookingId or action');
        return;
    }
    
    // Store values before closing modal (which clears them)
    const bookingId = pendingBookingId;
    const action = pendingAction;
    
    closeConfirmModal();
    console.log('Modal closed, calling action function');
    
    if (action === 'accept') {
        console.log('Calling acceptBooking with:', bookingId);
        acceptBooking(bookingId);
    } else if (action === 'reject') {
        console.log('Calling rejectBooking with:', bookingId);
        rejectBooking(bookingId);
    }
}

function acceptBooking(bookingId) {
    console.log('acceptBooking called with:', bookingId);
    showLoading();
    fetch('update_booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `booking_id=${bookingId}&action=accept`
    })
    .then(response => {
        hideLoading();
        if (!response.ok) {
            throw new Error('Network error: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification('Booking accepted successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to accept booking', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error: ' + error.message, 'error');
    });
}

function rejectBooking(bookingId) {
    console.log('rejectBooking called with:', bookingId);
    showLoading();
    fetch('update_booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: `booking_id=${bookingId}&action=reject`
    })
    .then(response => {
        hideLoading();
        if (!response.ok) {
            throw new Error('Network error: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification('Booking rejected successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(data.message || 'Failed to reject booking', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error: ' + error.message, 'error');
    });
}
</script>

<?php require_once 'footer.php'; ?>
