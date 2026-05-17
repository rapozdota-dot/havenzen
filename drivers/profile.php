<?php
require_once 'auth.php';
require_once 'header.php';

// Use session user id as driver id
$driver_id = $_SESSION['user_id'] ?? 0;

function hz_driver_profile_details($conn, int $driverId): ?array
{
    $result = $conn->query("
        SELECT 
            u.user_id,
            u.username,
            u.role,
            u.password,
            d.full_name,
            d.email,
            d.phone_number,
            d.license_number,
            d.license_expiry,
            d.license_class,
            d.license_front_image,
            d.license_back_image,
            d.years_experience,
            d.emergency_contact,
            d.emergency_phone,
            d.address,
            d.profile_picture,
            v.vehicle_id,
            v.vehicle_name,
            v.license_plate,
            v.vehicle_type,
            v.vehicle_color,
            v.status as vehicle_status
        FROM users u
        JOIN drivers d ON d.user_id = u.user_id
        LEFT JOIN vehicles v ON v.driver_id = u.user_id
        WHERE u.user_id = {$driverId} AND u.role = 'driver'
    ");

    return $result ? $result->fetch_assoc() : null;
}

function hz_driver_profile_stats($conn, int $driverId): array
{
    $defaults = [
        'total_bookings' => 0,
        'total_earnings' => 0,
        'avg_rating' => 0,
    ];

    $result = $conn->query("
        SELECT 
            COUNT(*) as total_bookings,
            COALESCE(SUM(fare_estimate), 0) as total_earnings,
            COALESCE(AVG(rating), 0) as avg_rating
        FROM bookings 
        WHERE driver_id = {$driverId}
          AND status = 'completed'
    ");

    if (!$result) {
        return $defaults;
    }

    return array_merge($defaults, $result->fetch_assoc() ?: []);
}

$driver_details = hz_driver_profile_details($conn, intval($driver_id));
if (!$driver_details) {
    echo '<div class="alert alert-error">Driver profile could not be loaded. Please log out and sign in again.</div>';
    require_once 'footer.php';
    exit;
}

$driver_stats = hz_driver_profile_stats($conn, intval($driver_id));

function hz_driver_upload_path_exists(?string $path): bool
{
    return hz_upload_path_exists($path);
}

function handle_driver_image_upload($fieldName, $existingPath, $prefix, &$error_message)
{
    return hz_store_uploaded_image($fieldName, 'licenses', $prefix, $existingPath, $error_message);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Accept either combined `full_name` or `first_name` + `last_name`
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        if ($first_name !== '' || $last_name !== '') {
            $full_name = $conn->real_escape_string($first_name . ($first_name !== '' && $last_name !== '' ? ' ' : '') . $last_name);
        } else {
            $full_name = $conn->real_escape_string($_POST['full_name'] ?? '');
        }
        $email = $conn->real_escape_string($_POST['email']);
        $phone_number = $conn->real_escape_string($_POST['phone_number']);
        $address = $conn->real_escape_string($_POST['address'] ?? '');
        $emergency_contact = $conn->real_escape_string($_POST['emergency_contact'] ?? '');
        // Emergency phone: keep only digits and leading +
        $emergency_phone = preg_replace('/[^0-9+]/', '', $conn->real_escape_string($_POST['emergency_phone'] ?? ''));

        // Server-side validation: email and phone format, following admin user management rules
        $email_valid = filter_var($email, FILTER_VALIDATE_EMAIL);
        $phone_digits = preg_replace('/[^0-9+]/', '', $phone_number);
        // Validate Philippine phone numbers: mobile formats like 09171234567 or +639171234567
        $phone_valid = preg_match('/^(\+63|0)9[0-9]{9}$/', $phone_digits);
        // Emergency phone validation
        $emergency_phone_valid = empty($emergency_phone) || preg_match('/^(\+63|0)9[0-9]{9}$/', $emergency_phone);

        if (!$email_valid) {
            $error_message = 'Please provide a valid email address.';
        } elseif (!$phone_valid) {
            $error_message = 'Please provide a valid Philippine phone number (e.g. 09171234567 or +639171234567).';
        } elseif (!$emergency_phone_valid) {
            $error_message = 'Please provide a valid Philippine emergency contact number (e.g. 09171234567 or +639171234567).';
        } else {
            // Handle profile picture upload (optional)
            $profile_picture = $driver_details['profile_picture'] ?? '';
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $profile_picture = hz_store_uploaded_image('profile_picture', 'profiles', 'driver_profile_' . $driver_id, $profile_picture, $error_message);
            }

            // Update the drivers profile table
            $license_front_image = handle_driver_image_upload(
                'license_front_image',
                $driver_details['license_front_image'] ?? '',
                'license_front_' . $driver_id,
                $error_message
            );
            $license_back_image = handle_driver_image_upload(
                'license_back_image',
                $driver_details['license_back_image'] ?? '',
                'license_back_' . $driver_id,
                $error_message
            );

            if (!empty($error_message)) {
                // Keep the validation message and skip the database update.
            } else {
                $license_front_image = $conn->real_escape_string($license_front_image);
                $license_back_image = $conn->real_escape_string($license_back_image);

            $update_sql = "UPDATE drivers SET 
                    full_name = '$full_name',
                    email = '$email',
                    phone_number = '$phone_number',
                    address = '$address',
                    emergency_contact = '$emergency_contact',
                    emergency_phone = '$emergency_phone',
                    profile_picture = '$profile_picture',
                    license_front_image = '$license_front_image',
                    license_back_image = '$license_back_image'
                WHERE user_id = $driver_id";

            if ($conn->query($update_sql)) {
                $success_message = "Profile updated successfully!";
                $_SESSION['full_name'] = $full_name;

                // Log profile update
                logCRUD($conn, $driver_id, 'UPDATE', 'drivers', $driver_id, 'Driver updated profile information');

                // Refresh driver details from joined users+drivers+vehicles view
                $driver_details = hz_driver_profile_details($conn, intval($driver_id)) ?: $driver_details;

            } else {
                $error_message = "Failed to update profile: " . $conn->error;
            }
            }
        }
    }
    
    if (isset($_POST['update_vehicle'])) {
        $error_message = "Vehicle details can only be updated by an administrator.";
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // users table stores the hashed password in the `password` column
        if (password_verify($current_password, $driver_details['password'])) {
            if ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match!";
            } elseif (!preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}/', $new_password)) {
                $error_message = "Password must be at least 8 characters long and include uppercase, lowercase, number, and special character.";
            } else {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_sql = "UPDATE users SET password = '$new_password_hash' WHERE user_id = $driver_id";

                if ($conn->query($update_sql)) {
                    $success_message = "Password changed successfully!";
                    
                    // Log password change
                    logCRUD($conn, $driver_id, 'UPDATE', 'users', $driver_id, 'Driver changed password');
                } else {
                    $error_message = "Failed to change password: " . $conn->error;
                    logCRUD($conn, $driver_id, 'UPDATE', 'users', $driver_id, 'Failed password change: ' . $conn->error);
                }
            }
        } else {
            $error_message = "Current password is incorrect!";
        }
    }
}

// Refresh driver details
$driver_details = hz_driver_profile_details($conn, intval($driver_id)) ?: $driver_details;
?>

<div class="dashboard-header">
    <h1>Driver Profile</h1>
    <p>Manage your account information and vehicle details.</p>
</div>

<?php if (isset($success_message)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $success_message; ?>
    </div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $error_message; ?>
    </div>
<?php endif; ?>

<div class="profile-container">
    <div class="profile-sidebar">
        <div class="profile-card">
            <div class="profile-avatar">
                <?php if (hz_driver_upload_path_exists($driver_details['profile_picture'] ?? '')): ?>
                    <img src="<?php echo htmlspecialchars(hz_upload_href($driver_details['profile_picture'])); ?>" alt="Profile Picture" class="avatar-image">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($driver_details['full_name']); ?></h3>
                <p class="profile-role">Professional Driver</p>
                <p class="profile-status">
                    <span class="status-indicator <?php echo $driver_details['vehicle_status'] ?? 'inactive'; ?>">
                        <i class="fas fa-circle"></i>
                        <?php echo ucfirst($driver_details['vehicle_status'] ?? 'inactive'); ?>
                    </span>
                </p>
            </div>
        </div>

        <div class="stats-card">
            <h4>Driver Statistics</h4>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $driver_stats['total_bookings']; ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">₱<?php echo number_format($driver_stats['total_earnings'], 2); ?></div>
                    <div class="stat-label">Total Earnings</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($driver_stats['avg_rating'] ?? 0, 1); ?></div>
                    <div class="stat-label">Avg Rating</div>
                </div>
            </div>
        </div>

        <div class="quick-actions">
            <h4>Quick Actions</h4>
            <button class="btn btn-outline" onclick="document.getElementById('profileForm').scrollIntoView()">
                <i class="fas fa-user-edit"></i> Edit Profile
            </button>
            <button class="btn btn-outline" onclick="document.getElementById('passwordForm').scrollIntoView()">
                <i class="fas fa-lock"></i> Change Password
            </button>
        </div>
    </div>

    <div class="profile-content">
        <!-- Personal Information Form -->
        <div class="form-section" id="profileForm">
            <div class="section-header">
                <h3>Personal Information</h3>
                <p>Update your personal details and contact information</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="profile-form">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" 
                               value="<?php 
                                   $nameParts = explode(' ', $driver_details['full_name'] ?? '', 2);
                                   echo htmlspecialchars(count($nameParts) > 0 ? (count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 0, -1)) : $nameParts[0]) : '');
                               ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" 
                               value="<?php 
                                   $nameParts = explode(' ', $driver_details['full_name'] ?? '', 2);
                                   echo htmlspecialchars(count($nameParts) > 1 ? $nameParts[1] : '');
                               ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($driver_details['email']); ?>" 
                               placeholder="name@example.com"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone_number">Phone Number</label>
                        <input type="tel" id="phone_number" name="phone_number" 
                               value="<?php echo htmlspecialchars($driver_details['phone_number']); ?>"
                               inputmode="numeric"
                               pattern="^(\+63|0)9[0-9]{9}$"
                               placeholder="e.g. 09171234567 or +639171234567"
                               title="Philippine mobile e.g. 09171234567 or +639171234567"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="user_id">User ID</label>
                        <input type="text" id="user_id" 
                               value="<?php echo htmlspecialchars($driver_details['user_id']); ?>" 
                               disabled>
                        <small>Your unique user identification number</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="4" style="min-height: 100px; resize: vertical; max-height: 200px;"><?php echo htmlspecialchars($driver_details['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_contact">Emergency Contact</label>
                        <input type="text" id="emergency_contact" name="emergency_contact" 
                               value="<?php echo htmlspecialchars($driver_details['emergency_contact'] ?? ''); ?>"
                               placeholder="Contact Name">
                    </div>
                    
                    <div class="form-group">
                        <label for="emergency_phone">Emergency Contact Phone</label>
                        <input type="tel" id="emergency_phone" name="emergency_phone" 
                               value="<?php echo htmlspecialchars($driver_details['emergency_phone'] ?? ''); ?>"
                               inputmode="numeric"
                               pattern="^(\+63|0)9[0-9]{9}$"
                               placeholder="e.g. 09171234567 or +639171234567"
                               title="Philippine mobile e.g. 09171234567 or +639171234567">
                    </div>
                    
                    <div class="form-group">
                        <label for="profile_picture">Profile Picture</label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label for="license_front_image">Driver License Image - Front</label>
                        <?php if (hz_driver_upload_path_exists($driver_details['license_front_image'] ?? '')): ?>
                            <div style="margin-bottom: 0.5rem;">
                                <a href="<?php echo htmlspecialchars(hz_upload_href($driver_details['license_front_image'])); ?>" target="_blank" rel="noopener">
                                    View current front image
                                </a>
                            </div>
                        <?php else: ?>
                            <small>No front license image uploaded yet.</small>
                        <?php endif; ?>
                        <input type="file" id="license_front_image" name="license_front_image" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label for="license_back_image">Driver License Image - Back</label>
                        <?php if (hz_driver_upload_path_exists($driver_details['license_back_image'] ?? '')): ?>
                            <div style="margin-bottom: 0.5rem;">
                                <a href="<?php echo htmlspecialchars(hz_upload_href($driver_details['license_back_image'])); ?>" target="_blank" rel="noopener">
                                    View current back image
                                </a>
                            </div>
                        <?php else: ?>
                            <small>No back license image uploaded yet.</small>
                        <?php endif; ?>
                        <input type="file" id="license_back_image" name="license_back_image" accept="image/*">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>

        <!-- Vehicle Information (read-only for drivers) -->
        <div class="form-section" id="vehicleForm">
            <div class="section-header">
                <h3>Vehicle Information</h3>
                <p>These details are managed by the admin and cannot be edited here.</p>
            </div>
            
            <div class="profile-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="vehicle_name">Vehicle Name/Model</label>
                        <input type="text" id="vehicle_name" name="vehicle_name" 
                               value="<?php echo htmlspecialchars($driver_details['vehicle_name'] ?? ''); ?>" 
                               readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="license_plate">License Plate</label>
                        <input type="text" id="license_plate" name="license_plate" 
                               value="<?php echo htmlspecialchars($driver_details['license_plate'] ?? ''); ?>" 
                               readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="vehicle_type">Vehicle Type</label>
                        <input type="text" id="vehicle_type" 
                               value="<?php echo htmlspecialchars($driver_details['vehicle_type'] ?? ''); ?>" 
                               readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="vehicle_color">Vehicle Color</label>
                        <input type="text" id="vehicle_color" name="vehicle_color" 
                               value="<?php echo htmlspecialchars($driver_details['vehicle_color'] ?? ''); ?>" 
                               readonly>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Password Form -->
        <div class="form-section" id="passwordForm">
            <div class="section-header">
                <h3>Change Password</h3>
                <p>Update your password to keep your account secure</p>
            </div>
            
            <form method="POST" class="profile-form">
                <input type="hidden" name="change_password" value="1">
                
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required
                           pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}"
                           title="At least 8 characters including uppercase, lowercase, number and special character"
                           placeholder="Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol">
                    <small>Must include uppercase, lowercase, number, and special character</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                           pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}"
                           title="At least 8 characters including uppercase, lowercase, number and special character">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Combine first+last into full_name, sanitize phone, and validate fields before submit
    function validateProfileForm(formEl) {
        const first = formEl.querySelector('input[name="first_name"]')?.value.trim() || '';
        const last = formEl.querySelector('input[name="last_name"]')?.value.trim() || '';

        // Email validation
        const emailEl = formEl.querySelector('input[name="email"]');
        if (emailEl && emailEl.value && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(emailEl.value)) {
            alert('Please enter a valid email address.');
            emailEl.focus();
            return false;
        }

        // Phone: strip spaces and common punctuation
        const phoneEl = formEl.querySelector('input[name="phone_number"]');
        if (phoneEl && phoneEl.value) {
            const cleaned = phoneEl.value.replace(/[^0-9+]/g, '');
            phoneEl.value = cleaned;
            // Philippine mobile check: starts with 0 or +63 then 9XXXXXXXXX
            const phRegex = /^(\+63|0)9\d{9}$/;
            if (!phRegex.test(cleaned)) {
                alert('Please provide a valid Philippine phone number (e.g. 09171234567 or +639171234567).');
                phoneEl.focus();
                return false;
            }
        }

        // Emergency phone validation (optional but if provided must be valid)
        const emergPhoneEl = formEl.querySelector('input[name="emergency_phone"]');
        if (emergPhoneEl && emergPhoneEl.value) {
            const cleaned = emergPhoneEl.value.replace(/[^0-9+]/g, '');
            emergPhoneEl.value = cleaned;
            const phRegex = /^(\+63|0)9\d{9}$/;
            if (!phRegex.test(cleaned)) {
                alert('Please provide a valid Philippine emergency contact number (e.g. 09171234567 or +639171234567).');
                emergPhoneEl.focus();
                return false;
            }
        }

        return true;
    }

    // Hook profile form submit
    document.addEventListener('DOMContentLoaded', function() {
        const profileForm = document.querySelector('form[name="update_profile"]') || 
                           Array.from(document.querySelectorAll('form')).find(f => f.querySelector('input[name="update_profile"]'));
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                if (!validateProfileForm(profileForm)) e.preventDefault();
            });
        }

        // Enforce numeric-only phone inputs (allow leading +)
        const phoneInputs = document.querySelectorAll('input[name="phone_number"], input[name="emergency_phone"]');
        phoneInputs.forEach(pi => {
            pi.addEventListener('input', function() {
                const val = this.value;
                // allow leading +, then digits only
                let cleaned = val.replace(/[^0-9+]/g, '');
                // ensure only one leading + and only at start
                cleaned = cleaned.replace(/(?!^)\+/g, '');
                if (cleaned.indexOf('+') > 0) cleaned = cleaned.replace(/\+/g, '');
                this.value = cleaned;
            });
            // also prevent invalid keypresses for better UX
            pi.addEventListener('keypress', function(e) {
                const ch = String.fromCharCode(e.which || e.keyCode);
                if (!(/[0-9+]/.test(ch))) e.preventDefault();
                // prevent + anywhere except at position 0
                if (ch === '+' && this.selectionStart !== 0) e.preventDefault();
            });
        });
    });
</script>

<?php require_once 'footer.php'; ?>
