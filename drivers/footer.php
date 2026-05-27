        </div>
    </main>

    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <!-- Location Update Modal -->
    <div id="locationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Your Location</h3>
                <button class="modal-close" onclick="closeLocationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Your current location is shared automatically while you are online. You can also send it now if needed.</p>
                <div class="location-coordinates">
                    <span id="currentCoordinates">Getting location...</span>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeLocationModal()">Cancel</button>
                <button class="btn btn-primary" onclick="updateFooterDriverLocation({ silent: false, forceFresh: true })">Update Now</button>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal (Driver) -->
    <div id="logoutModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:3000;">
        <div class="modal-content" style="background:#fff; padding:20px; border-radius:8px; width:400px; max-width:95%; text-align:center;">
            <div style="color:#ff9800; font-size:48px; margin-bottom:15px;">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h3 style="color:#333; margin-bottom:15px;">Confirm Logout</h3>
            <p style="margin-bottom:20px; color:#666;">Are you sure you want to logout? You will need to login again to access the driver dashboard.</p>
            <div style="display:flex; justify-content:center; gap:10px;">
                <button type="button" class="btn btn-secondary" id="cancelLogout">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmLogout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </div>

    <script>
    // Global functions
    function showLoading() {
        document.getElementById('loadingSpinner').style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingSpinner').style.display = 'none';
    }

    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }

    // Online/Offline Status Toggle
    function toggleDriverStatus() {
        const indicator = document.getElementById('statusIndicator');
        const isOnline = indicator.classList.contains('online');
        
        fetch('../api/update_driver_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `status=${isOnline ? 'offline' : 'online'}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (isOnline) {
                    indicator.classList.remove('online');
                    indicator.classList.add('offline');
                    indicator.innerHTML = '<i class="fas fa-circle"></i><span>Offline</span>';
                    stopDriverLocationAutoUpdate();
                    showNotification('You are now offline', 'info');
                } else {
                    indicator.classList.remove('offline');
                    indicator.classList.add('online');
                    indicator.innerHTML = '<i class="fas fa-circle"></i><span>Online</span>';
                    startDriverLocationAutoUpdate();
                    showNotification('You are now online', 'success');
                }
            } else {
                showNotification(data.message || 'Unable to update driver status', 'error');
            }
        })
        .catch(() => showNotification('Unable to update driver status', 'error'));
    }

    // Location Services
    const DRIVER_LOCATION_INTERVAL_MS = 15000;
    let currentLocation = null;
    let driverLocationTimer = null;
    let driverLocationInFlight = false;
    let lastLocationErrorNoticeAt = 0;

    function getStatusIndicator() {
        return document.getElementById('statusIndicator');
    }

    function isDriverOnline() {
        const indicator = getStatusIndicator();
        return !indicator || indicator.classList.contains('online');
    }

    function setCoordinateText(text) {
        const coordinateEl = document.getElementById('currentCoordinates');
        if (coordinateEl) {
            coordinateEl.textContent = text;
        }
    }

    function shouldShowLocationError(silent) {
        if (!silent) return true;

        const now = Date.now();
        if (now - lastLocationErrorNoticeAt < 60000) {
            return false;
        }

        lastLocationErrorNoticeAt = now;
        return true;
    }

    function getCurrentLocation(options = {}) {
        const silent = options.silent !== false;

        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                const error = new Error('Geolocation is not supported by this browser');
                setCoordinateText('Location not supported');
                if (shouldShowLocationError(silent)) {
                    showNotification(error.message, 'error');
                }
                reject(error);
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    currentLocation = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude
                    };
                    setCoordinateText(`${currentLocation.latitude.toFixed(6)}, ${currentLocation.longitude.toFixed(6)}`);
                    resolve(currentLocation);
                },
                (error) => {
                    console.error('Error getting location:', error);
                    setCoordinateText('Location access denied');
                    if (shouldShowLocationError(silent)) {
                        showNotification('Location access denied. Please allow location sharing for live tracking.', 'error');
                    }
                    reject(error);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: options.forceFresh ? 0 : DRIVER_LOCATION_INTERVAL_MS
                }
            );
        });
    }

    function updateFooterDriverLocation(options = {}) {
        const silent = options.silent !== false;

        if (driverLocationInFlight || !isDriverOnline()) {
            return Promise.resolve(false);
        }

        driverLocationInFlight = true;
        if (!silent) showLoading();

        return getCurrentLocation({ silent, forceFresh: options.forceFresh })
            .then((location) => {
                return fetch('update_location.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `lat=${encodeURIComponent(location.latitude)}&lng=${encodeURIComponent(location.longitude)}`
                });
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (!silent) {
                        showNotification('Location updated successfully!', 'success');
                        closeLocationModal();
                    }
                    return true;
                }

                if (shouldShowLocationError(silent)) {
                    showNotification(data.message || 'Failed to update location', 'error');
                }
                return false;
            })
            .catch(error => {
                console.error('Error updating location:', error);
                return false;
            })
            .finally(() => {
                driverLocationInFlight = false;
                if (!silent) hideLoading();
            });
    }

    function openLocationModal() {
        getCurrentLocation({ silent: false, forceFresh: true }).catch(() => {});
        document.getElementById('locationModal').style.display = 'flex';
    }

    function closeLocationModal() {
        document.getElementById('locationModal').style.display = 'none';
    }

    function startDriverLocationAutoUpdate() {
        if (typeof window !== 'undefined' && window.DISABLE_AUTO_GEO) {
            return;
        }

        if (driverLocationTimer) {
            return;
        }

        updateFooterDriverLocation({ silent: true, forceFresh: true });
        driverLocationTimer = setInterval(() => {
            updateFooterDriverLocation({ silent: true, forceFresh: true });
        }, DRIVER_LOCATION_INTERVAL_MS);
    }

    function stopDriverLocationAutoUpdate() {
        if (driverLocationTimer) {
            clearInterval(driverLocationTimer);
            driverLocationTimer = null;
        }
    }

    // Handle page visibility
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && isDriverOnline()) {
            updateFooterDriverLocation({ silent: true, forceFresh: true });
        }
    });

    // Initialize automatic location sharing on page load (skip when page opts out)
    try {
        if (!(typeof window !== 'undefined' && window.DISABLE_AUTO_GEO)) {
            startDriverLocationAutoUpdate();
        }
    } catch (e) {
        // ignore
    }

    // Shared logout modal handlers (driver side)
    document.addEventListener('DOMContentLoaded', function() {
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogoutBtn = document.getElementById('cancelLogout');
        const confirmLogoutBtn = document.getElementById('confirmLogout');

        if (cancelLogoutBtn && logoutModal) {
            cancelLogoutBtn.addEventListener('click', function() {
                logoutModal.style.display = 'none';
            });
        }

        if (confirmLogoutBtn) {
            confirmLogoutBtn.addEventListener('click', function() {
                window.location.href = '../login/logout.php';
            });
        }

        if (logoutModal) {
            logoutModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        }
    });

    function showLogoutModal() {
        const modal = document.getElementById('logoutModal');
        if (modal) {
            modal.style.display = 'flex';
        }
        return false;
    }

    function confirmLogout() {
        return showLogoutModal();
    }
    </script>
</body>
</html>
