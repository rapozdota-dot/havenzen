<?php
$current_page = basename($_SERVER['PHP_SELF']);

// compute the number of unresolved passenger actions for this driver's assigned vehicle today
$pending_count = 0;
$vehicle_id = intval($driver_data['vehicle_id'] ?? 0);
if (!$vehicle_id && isset($_SESSION['user_id'])) {
    $vidRow = $conn->query("SELECT vehicle_id FROM vehicles WHERE driver_id = " . intval($_SESSION['user_id']) . " LIMIT 1");
    if ($vidRow && $vidRow->num_rows) {
        $vidData = $vidRow->fetch_assoc();
        $vehicle_id = intval($vidData['vehicle_id'] ?? 0);
    }
}
if ($vehicle_id) {
    $today = date('Y-m-d');
    $cntResult = $conn->query("
        SELECT COUNT(*) as c
        FROM bookings b
        LEFT JOIN vehicle_trips vt ON vt.trip_id = b.trip_id
        WHERE b.vehicle_id = $vehicle_id
          AND DATE(COALESCE(b.scheduled_departure_at, vt.scheduled_departure_at, b.requested_time)) = '$today'
          AND b.status IN ('pending', 'confirmed', 'in_progress')
          AND b.boarding_status NOT IN ('no_show', 'dropped_off')
    ");
    $cntRow = $cntResult ? $cntResult->fetch_assoc() : ['c' => 0];
    $pending_count = intval($cntRow['c'] ?? 0);
}
$driverCssVersion = @filemtime(__DIR__ . '/drivers.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - Haven Zen</title>
    <link rel="stylesheet" href="drivers.css?v=<?php echo $driverCssVersion; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Navigation Header -->
    <nav class="driver-nav">
        <div class="nav-container">
            <div class="nav-brand">
                <div class="logo" aria-hidden="true"><i class="fas fa-bus"></i></div>
                <span class="brand-name">Haven Zen Driver</span>
            </div>
            
            <!-- Mobile Menu Toggle (hidden on desktop via CSS) -->
            <button class="mobile-menu-toggle" id="mobileMenuToggle" style="display: none;">
                <i class="fas fa-bars"></i>
            </button>

            <div class="nav-content" id="navContent">
                <div class="nav-links">
                    <a href="index.php" class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="bookings.php" class="nav-link <?php echo $current_page == 'bookings.php' ? 'active' : ''; ?>">
                        <i class="fas fa-route"></i> Trips
                    </a>
                    <a href="map.php" class="nav-link <?php echo $current_page == 'map.php' ? 'active' : ''; ?>">
                        <i class="fas fa-map-marked-alt"></i> Map
                    </a>
                    <a href="earnings.php" class="nav-link <?php echo $current_page == 'earnings.php' ? 'active' : ''; ?>">
                        <i class="fas fa-money-bill-wave"></i> Earnings
                    </a>
                    <a href="availability.php" class="nav-link <?php echo $current_page == 'availability.php' ? 'active' : ''; ?>">
                        <i class="fas fa-clock"></i> Availability
                    </a>
                    <a href="profile.php" class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-cog"></i> Profile
                    </a>
                    <!-- Mobile-only Logout inside hamburger menu -->
                    <a href="#" class="nav-link mobile-only-link" onclick="return confirmLogout();">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Driver menu - separate from nav-content for proper desktop layout -->
            <div class="driver-menu">
                <div class="status-indicator online" id="statusIndicator">
                    <i class="fas fa-circle"></i>
                    <span>Online</span>
                </div>
                <div class="user-initials">
                    <?php 
                        $name_parts = explode(' ', $_SESSION['full_name'] ?? 'U');
                        $initials = strtoupper(substr($name_parts[0], 0, 1));
                        if (count($name_parts) > 1) {
                            $initials .= strtoupper(substr($name_parts[count($name_parts)-1], 0, 1));
                        }
                        echo $initials;
                    ?>
                    <span class="user-role-badge">Driver</span>
                </div>
                <a href="#" onclick="return confirmLogout();" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        <div class="nav-overlay" id="navOverlay"></div>
    </nav>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('mobileMenuToggle');
            const navContent = document.getElementById('navContent');
            const navOverlay = document.getElementById('navOverlay');
            const mobileNav = document.querySelector('.mobile-nav');
            
            if (menuToggle) {
                menuToggle.addEventListener('click', function() {
                    navContent.classList.toggle('active');
                    navOverlay.classList.toggle('active');
                    document.body.style.overflow = navContent.classList.contains('active') ? 'hidden' : '';
                    
                    // Toggle mobile nav visibility
                    if (mobileNav) {
                        mobileNav.style.display = navContent.classList.contains('active') ? 'none' : 'flex';
                    }
                });
                
                navOverlay.addEventListener('click', function() {
                    navContent.classList.remove('active');
                    navOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                    
                    // Show mobile nav again
                    if (mobileNav) {
                        mobileNav.style.display = 'flex';
                    }
                });
            }
        });
    </script>

    <script>
    function toggleNotifDropdown() {
        const dd = document.getElementById('notifDropdown');
        if (!dd) return;
        if (dd.style.display === 'block') { dd.style.display = 'none'; return; }
        dd.style.display = 'block';
        // fetch notifications
        fetch('notifications.php')
        .then(r => r.json())
        .then(data => {
            const list = document.getElementById('notifList');
            list.innerHTML = '';
            if (!data.success) { list.innerHTML = '<div style="padding:10px;color:#666;">Unable to load notifications</div>'; return; }
            if (!data.notifications.length) { list.innerHTML = '<div style="padding:10px;color:#666;">No notifications</div>'; return; }
            data.notifications.forEach(n => {
                const item = document.createElement('div');
                item.style.padding = '10px';
                item.style.borderBottom = '1px solid #f4f4f4';
                item.style.cursor = 'pointer';
                item.innerHTML = `<div style="font-weight:600">${n.type.replace(/_/g,' ')}</div><div style="font-size:0.95rem;margin-top:6px;color:#333">${n.message}</div><div style="font-size:0.8rem;color:#888;margin-top:6px">${n.created_at}</div>`;
                item.onclick = function() { markNotifRead(n.id); };
                list.appendChild(item);
            });
        }).catch(err => console.error(err));
    }

    function markNotifRead(id) {
        const fd = new FormData(); fd.append('action','mark_read'); fd.append('id', id);
        fetch('notifications.php',{method:'POST', body: fd}).then(()=>{ document.getElementById('notifDropdown').style.display='none'; location.reload(); });
    }

    function markAllNotifications(e) {
        e.stopPropagation();
        const fd = new FormData(); fd.append('action','mark_all');
        fetch('notifications.php',{method:'POST', body: fd}).then(()=>{ document.getElementById('notifDropdown').style.display='none'; location.reload(); });
    }

    function confirmLogout() {
        if (typeof showLogoutModal === 'function') {
            return showLogoutModal();
        }
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = '../login/logout.php';
        }
        return false;
    }
    </script>

    <!-- Mobile Navigation -->
    <div class="mobile-nav">
        <a href="index.php" class="mobile-nav-item <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="bookings.php" class="mobile-nav-item <?php echo $current_page == 'bookings.php' ? 'active' : ''; ?>">
            <i class="fas fa-route"></i>
            <span>Trips</span>
            <?php if ($pending_count > 0): ?>
                <span class="mobile-badge"><?php echo $pending_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="map.php" class="mobile-nav-item <?php echo $current_page == 'map.php' ? 'active' : ''; ?>">
            <i class="fas fa-map-marked-alt"></i>
            <span>Map</span>
        </a>
        <a href="earnings.php" class="mobile-nav-item <?php echo $current_page == 'earnings.php' ? 'active' : ''; ?>">
            <i class="fas fa-money-bill-wave"></i>
            <span>Earnings</span>
        </a>
        <a href="availability.php" class="mobile-nav-item <?php echo $current_page == 'availability.php' ? 'active' : ''; ?>">
            <i class="fas fa-clock"></i>
            <span>Availability</span>
        </a>
    </div>

    <!-- Main Content -->
    <main class="driver-main">
        <div class="container">
