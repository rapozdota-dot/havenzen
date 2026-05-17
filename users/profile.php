<?php
require_once 'auth.php';
require_once 'header.php';

function hz_user_upload_path_exists(?string $path): bool
{
    return hz_upload_path_exists($path);
}

// Handle profile updates
if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    // Accept either combined `full_name` or separate `first_name` + `last_name`
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    if ($first_name !== '' || $last_name !== '') {
        $full_name = $conn->real_escape_string($first_name . ($first_name !== '' && $last_name !== '' ? ' ' : '') . $last_name);
    } else {
        $full_name = $conn->real_escape_string($_POST['full_name'] ?? '');
    }
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $phone_number = $conn->real_escape_string($_POST['phone_number'] ?? '');
    
    // Phone: keep only digits and leading +
    $phone_digits = preg_replace('/[^0-9+]/', '', $phone_number);

    // Server-side validation: email format and Philippine phone format
    $email_valid = filter_var($email, FILTER_VALIDATE_EMAIL);
    // Validate Philippine phone numbers: mobile formats like 09171234567 or +639171234567
    $phone_valid = preg_match('/^(\+63|0)9[0-9]{9}$/', $phone_digits);
    
    if (!$email_valid) {
        $profile_error = "Please provide a valid email address.";
    } elseif (!$phone_valid) {
        $profile_error = "Please provide a valid Philippine phone number (e.g. 09171234567 or +639171234567).";
    } else {
        // Handle optional profile picture upload
        $profile_picture = $user_data['profile_picture'] ?? '';
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $profile_picture = hz_store_uploaded_image('profile_picture', 'profiles', 'passenger_profile_' . $user_id, $profile_picture, $profile_error);
        }

        // Update passenger profile in customers table using prepared statement
        $stmt = empty($profile_error) ? $conn->prepare("UPDATE customers SET full_name = ?, email = ?, phone_number = ?, profile_picture = ? WHERE user_id = ?") : null;
        if ($stmt) {
            $stmt->bind_param('ssssi', $full_name, $email, $phone_number, $profile_picture, $user_id);
            if ($stmt->execute()) {
                $_SESSION['full_name'] = $full_name;
                $profile_success = "Profile updated successfully!";
                // Log profile update
                logCRUD($conn, $user_id, 'UPDATE', 'customers', $user_id, 'Updated passenger profile information');
                // Refresh user data from joined users+customers view
                $user_data = $conn->query("SELECT 
                    u.user_id,
                    u.username,
                    u.role,
                    c.full_name,
                    c.email,
                    c.phone_number,
                    c.profile_picture,
                    c.created_at,
                    c.last_login
                FROM users u
                JOIN customers c ON c.user_id = u.user_id
                WHERE u.user_id = $user_id")->fetch_assoc();
            } else {
                $profile_error = "Error updating profile: " . $stmt->error;
            }
            $stmt->close();
        } elseif (empty($profile_error)) {
            $profile_error = "Error preparing profile update: " . $conn->error;
        }
    }
}

// Handle password change
if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Verify current password
    $user_query = $conn->query("SELECT password FROM users WHERE user_id = $user_id");
    $user = $user_query->fetch_assoc();
    
    if (!password_verify($current_password, $user['password'])) {
        $password_error = "Current password is incorrect.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New passwords do not match.";
    } elseif (!preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}/', $new_password)) {
        $password_error = "Password must be at least 8 characters long and include uppercase, lowercase, number, and special character.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        if ($conn->query("UPDATE users SET password = '$hashed_password' WHERE user_id = $user_id")) {
            $password_success = "Password changed successfully!";
            // Log successful password change
            logSystemEvent($conn, $user_id, 'PASSWORD_CHANGE', 'User changed their password successfully');
            // Also record as a CRUD UPDATE for consistency
            logCRUD($conn, $user_id, 'UPDATE', 'users', $user_id, 'Changed password');
        } else {
            $password_error = "Error changing password: " . $conn->error;
            // Log failed password change
            logSystemEvent($conn, $user_id, 'PASSWORD_CHANGE_FAILED', 'Failed to change password: ' . $conn->error);
            // Record failed attempt as well
            logCRUD($conn, $user_id, 'UPDATE', 'users', $user_id, 'Failed password change: ' . $conn->error);
        }
    }
}
?>

<div class="dashboard-header">
    <h1>Your Profile</h1>
    <p>Manage your account information and security settings</p>
</div>

<div class="profile-header">
    <div class="profile-card">
        <div class="profile-avatar">
            <?php if (hz_user_upload_path_exists($user_data['profile_picture'] ?? '')): ?>
                <img src="<?php echo htmlspecialchars(hz_upload_href($user_data['profile_picture'])); ?>" alt="Profile Picture" class="avatar-image">
            <?php else: ?>
                <div class="avatar-placeholder">
                    <i class="fas fa-user"></i>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-info">
            <h2><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
            <p class="profile-role">Passenger</p>
        </div>
    </div>
</div>

<div class="cards-grid">
    <!-- Profile Information -->
    <div class="form-container">
        <h2>Personal Information</h2>
        
        <?php if (isset($profile_success)): ?>
            <div class="notification success">
                <div class="notification-content">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $profile_success; ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($profile_error)): ?>
            <div class="notification error">
                <div class="notification-content">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $profile_error; ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" disabled>
                <small style="color: var(--text-color); opacity: 0.7;">Username cannot be changed</small>
            </div>
            
            <div class="form-group">
                <label for="first_name">First Name *</label>
                <input type="text" id="first_name" name="first_name" required 
                       placeholder="e.g. Juan"
                       value="<?php 
                           $nameParts = explode(' ', $user_data['full_name'] ?? '', 2);
                           echo htmlspecialchars(count($nameParts) > 0 ? (count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 0, -1)) : $nameParts[0]) : '');
                       ?>">
            </div>
            
            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" id="last_name" name="last_name" required 
                       placeholder="e.g. Dela Cruz"
                       value="<?php 
                           $nameParts = explode(' ', $user_data['full_name'] ?? '', 2);
                           echo htmlspecialchars(count($nameParts) > 1 ? $nameParts[1] : '');
                       ?>">
            </div>
            
            <div class="form-group">
                <label for="phone_number">Phone Number *</label>
                <input type="tel" id="phone_number" name="phone_number" required 
                       inputmode="numeric"
                       pattern="^(\+63|0)9[0-9]{9}$"
                       placeholder="e.g. 09171234567 or +639171234567"
                       title="Philippine mobile e.g. 09171234567 or +639171234567"
                       value="<?php echo htmlspecialchars($user_data['phone_number']); ?>">
            </div>
            
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required
                       placeholder="name@example.com"
                       value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                <small style="color: var(--text-color); opacity: 0.7;">We'll use this to send notifications and account updates.</small>
            </div>
            
            <div class="form-group">
                <label for="profile_picture">Profile Picture</label>
                <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </div>
        </form>
    </div>

    <!-- Change Password -->
    <div class="form-container">
        <h2>Change Password</h2>
        
        <?php if (isset($password_success)): ?>
            <div class="notification success">
                <div class="notification-content">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $password_success; ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($password_error)): ?>
            <div class="notification error">
                <div class="notification-content">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $password_error; ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            
            <div class="form-group">
                <label for="current_password">Current Password *</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password *</label>
                <input type="password" id="new_password" name="new_password" required 
                       pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}"
                       title="At least 8 characters including uppercase, lowercase, number and special character"
                       placeholder="Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol">
                <small style="color: var(--text-color); opacity: 0.7;">Must include uppercase, lowercase, number, and special character</small>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm New Password *</label>
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

<!-- Account Statistics -->
<div class="cards-grid">
    <div class="card">
        <div class="card-content">
            <h3>📊 Account Statistics</h3>
            <div style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <span>Member Since:</span>
                    <strong><?php echo date('F j, Y', strtotime($user_data['created_at'])); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <span>Last Login:</span>
                    <strong>
                        <?php 
                        echo $user_data['last_login'] 
                            ? date('M j, Y g:i A', strtotime($user_data['last_login'])) 
                            : 'First time';
                        ?>
                    </strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <span>Total Bookings:</span>
                    <strong>
                        <?php
                        $total_bookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE passenger_id = $user_id")->fetch_row()[0];
                        echo $total_bookings;
                        ?>
                    </strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>Completed Rides:</span>
                    <strong>
                        <?php
                        $completed_rides = $conn->query("SELECT COUNT(*) FROM bookings WHERE passenger_id = $user_id AND status = 'completed'")->fetch_row()[0];
                        echo $completed_rides;
                        ?>
                    </strong>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <h3>🔒 Security Tips</h3>
            <ul style="margin-left: 20px; margin-top: 15px;">
                <li>Use a strong, unique password</li>
                <li>Never share your login credentials</li>
                <li>Log out after each session on shared devices</li>
                <li>Update your password regularly</li>
                <li>Contact support if you notice suspicious activity</li>
            </ul>
            
            <div style="margin-top: 25px; padding: 15px; background: var(--light-gray); border-radius: 10px;">
                <h4 style="margin-bottom: 10px; color: var(--dark-gray);">Need Help?</h4>
                <p style="margin-bottom: 0; font-size: 0.9rem;">
                    Contact our support team for any account-related issues.
                </p>
            </div>
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
    if (emailEl && emailEl.value && !/^[^\@\s]+@[^\@\s]+\.[^\@\s]+$/.test(emailEl.value)) {
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

    return true;
}

// Hook profile form submit
document.addEventListener('DOMContentLoaded', function() {
    const forms = Array.from(document.querySelectorAll('form'));
    const profileForm = forms.find(f => f.querySelector('input[name="action"][value="update_profile"]'));
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            if (!validateProfileForm(profileForm)) e.preventDefault();
        });
    }

    // Enforce numeric-only phone inputs (allow leading +)
    const phoneInputs = document.querySelectorAll('input[name="phone_number"]');
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

    // Password confirmation validation for change_password form
    const pwForm = forms.find(f => f.querySelector('input[name="action"][value="change_password"]'));
    if (pwForm) {
        pwForm.addEventListener('submit', function(e) {
            const newPassword = pwForm.querySelector('input[name="new_password"]').value;
            const confirmPassword = pwForm.querySelector('input[name="confirm_password"]').value;
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match!');
            }
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
