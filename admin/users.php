<?php
require_once 'auth.php';
$page_title = "Users Management";
require_once 'header.php';

// Handle form submissions (guard against undefined POST keys)
$action = $_POST['action'] ?? null;
if ($action === 'add_user') {
    $username = $conn->real_escape_string($_POST['username'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    $confirm_raw = trim($_POST['confirm_password'] ?? '');
    $role = $conn->real_escape_string($_POST['role'] ?? 'passenger');
    // Accept either combined `full_name` or `first_name` + `last_name`
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    if ($first_name !== '' || $last_name !== '') {
        $full_name = $conn->real_escape_string($first_name . ($first_name !== '' && $last_name !== '' ? ' ' : '') . $last_name);
    } else {
        $full_name = $conn->real_escape_string($_POST['full_name'] ?? '');
    }
    $phone_number = $conn->real_escape_string($_POST['phone_number'] ?? '');
    $admin_address = $conn->real_escape_string($_POST['admin_address'] ?? '');
    $passenger_address = $conn->real_escape_string($_POST['passenger_address'] ?? '');

    // Handle profile picture upload
    $profile_picture = '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $profile_picture = hz_store_uploaded_image('profile_picture', 'profiles', 'profile_' . ($_SESSION['user_id'] ?? 'admin'), '', $error);
    }

    // Additional fields for driver
    $driver_fields = [];
    if ($role === 'driver') {
        $driver_fields = [
            // License: store uppercase alphanumeric only
            'license_number' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $conn->real_escape_string($_POST['license_number'] ?? ''))),
            'license_expiry' => $conn->real_escape_string($_POST['license_expiry'] ?? ''),
            'license_class' => $conn->real_escape_string($_POST['license_class'] ?? ''),
            'years_experience' => intval($_POST['years_experience'] ?? 0),
            'emergency_contact' => $conn->real_escape_string($_POST['emergency_contact'] ?? ''),
            // Emergency phone: keep only digits and leading +
            'emergency_phone' => preg_replace('/[^0-9+]/', '', $conn->real_escape_string($_POST['emergency_phone'] ?? '')),
            'address' => $conn->real_escape_string($_POST['address'] ?? ''),
            'vehicle_assigned' => intval($_POST['vehicle_assigned'] ?? null)
        ];
    }

    // Insert auth record into users table (auth-only)
    // Basic server-side validation: email and phone format, strong password
    $email_valid = filter_var($email, FILTER_VALIDATE_EMAIL);
    $phone_digits = preg_replace('/[^0-9+]/', '', $phone_number);
    // Validate Philippine phone numbers: mobile formats like 09171234567 or +639171234567
    $phone_valid = preg_match('/^(\+63|0)9[0-9]{9}$/', $phone_digits);
    $strong_pass = preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}/', $password_raw);

    // Confirm password check
    $passwords_match = ($password_raw !== '' && $password_raw === $confirm_raw);

    // Driver-specific validations
    $driver_license_valid = true;
    $driver_emergency_valid = true;
    if ($role === 'driver') {
        $lic = $driver_fields['license_number'] ?? '';
        // Philippines license: require uppercase alphanumeric, 5-20 chars
        $driver_license_valid = preg_match('/^[A-Z0-9]{5,20}$/', $lic);
        $em = $driver_fields['emergency_phone'] ?? '';
        $driver_emergency_valid = preg_match('/^(\+63|0)9[0-9]{9}$/', $em);
    }

    if (!empty($error)) {
        // Keep upload/validation error and skip creating the user.
    } elseif (!$email_valid) {
        $error = 'Please provide a valid email address.';
    } elseif (!$phone_valid) {
        $error = 'Please provide a valid Philippine phone number (e.g. 09171234567 or +639171234567).';
    } elseif (!$strong_pass) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, digit and symbol.';
    } elseif (!$passwords_match) {
        $error = 'Passwords do not match. Please confirm the password.';
    } elseif ($role === 'driver' && !$driver_license_valid) {
        $error = 'Please provide a valid license number (uppercase letters and digits, 5-20 chars).';
    } elseif ($role === 'driver' && !$driver_emergency_valid) {
        $error = 'Please provide a valid Philippine emergency contact number (e.g. 09171234567 or +639171234567).';
    } else {
        // Hash password after validation
        $password_hashed = password_hash($password_raw, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (username, password, role) VALUES ('$username', '$password_hashed', '$role')";

        if ($conn->query($sql)) {
            $newId = $conn->insert_id;

            // Insert profile record into appropriate role-specific table
            $profileOk = true;
            if ($role === 'passenger') {
                $profileSql = "INSERT INTO customers (user_id, full_name, email, phone_number, profile_picture, address) " .
                    "VALUES ($newId, '$full_name', '$email', '$phone_number', '$profile_picture', '$passenger_address')";
                if (!$conn->query($profileSql)) {
                    $profileOk = false;
                    $error = "Error adding passenger profile: " . $conn->error;
                }
            } elseif ($role === 'admin') {
                $profileSql = "INSERT INTO admins (user_id, full_name, email, phone_number, profile_picture, address) " .
                    "VALUES ($newId, '$full_name', '$email', '$phone_number', '$profile_picture', '$admin_address')";
                if (!$conn->query($profileSql)) {
                    $profileOk = false;
                    $error = "Error adding admin profile: " . $conn->error;
                }
            } elseif ($role === 'driver') {
                $profileSql = "INSERT INTO drivers (" .
                    "user_id, full_name, email, phone_number, profile_picture, " .
                    "license_number, license_expiry, license_class, years_experience, " .
                    "emergency_contact, emergency_phone, address" .
                    ") VALUES (" .
                    "$newId, '$full_name', '$email', '$phone_number', '$profile_picture', " .
                    "'{$driver_fields['license_number']}', '{$driver_fields['license_expiry']}', " .
                    "'{$driver_fields['license_class']}', {$driver_fields['years_experience']}, " .
                    "'{$driver_fields['emergency_contact']}', '{$driver_fields['emergency_phone']}', " .
                    "'{$driver_fields['address']}'" .
                    ")";
                if (!$conn->query($profileSql)) {
                    $profileOk = false;
                    $error = "Error adding driver profile: " . $conn->error;
                }

                // Handle initial vehicle assignment for drivers via vehicles.driver_id
                if ($profileOk && $driver_fields['vehicle_assigned']) {
                    $vehicleId = (int) $driver_fields['vehicle_assigned'];
                    $conn->query("UPDATE vehicles SET driver_id = $newId WHERE vehicle_id = $vehicleId");
                }
            }

            if ($profileOk) {
                $message = "User added successfully!";
                // Log user creation
                $adminId = $_SESSION['user_id'] ?? null;

                // Log CRUD operation with comprehensive details
                $details = [
                    'username' => $username,
                    'email' => $email,
                    'role' => $role
                ];

                if ($role === 'driver') {
                    $details['driver_info'] = [
                        'license_number' => $driver_fields['license_number'],
                        'license_class' => $driver_fields['license_class'],
                        'years_experience' => $driver_fields['years_experience'],
                        'vehicle_assigned' => $driver_fields['vehicle_assigned']
                    ];
                }

                logCRUD($conn, $adminId, 'CREATE', 'users', $newId, json_encode($details));
            }
        } else {
            $error = "Error adding user: " . $conn->error;
        }
    }
}

// Handle update user
if ($action === 'update_user') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $username = $conn->real_escape_string($_POST['username'] ?? '');
    $email = $conn->real_escape_string($_POST['email'] ?? '');
    $role = $conn->real_escape_string($_POST['role'] ?? 'passenger');
    // Accept either combined `full_name` or `first_name` + `last_name`
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    if ($first_name !== '' || $last_name !== '') {
        $full_name = $conn->real_escape_string($first_name . ($first_name !== '' && $last_name !== '' ? ' ' : '') . $last_name);
    } else {
        $full_name = $conn->real_escape_string($_POST['full_name'] ?? '');
    }
    $phone_number = $conn->real_escape_string($_POST['phone_number'] ?? '');
    $admin_address = $conn->real_escape_string($_POST['admin_address'] ?? '');
    $passenger_address = $conn->real_escape_string($_POST['passenger_address'] ?? '');

    // Handle profile picture upload
    $profile_picture = $_POST['current_profile_picture'] ?? '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $profile_picture = hz_store_uploaded_image('profile_picture', 'profiles', 'profile_' . $user_id, $profile_picture, $error);
    }

    // Additional fields for driver (sanitize similarly to add_user)
    $driver_fields = [];
    if ($role === 'driver') {
        $driver_fields = [
            'license_number' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $conn->real_escape_string($_POST['license_number'] ?? ''))),
            'license_expiry' => $conn->real_escape_string($_POST['license_expiry'] ?? ''),
            'license_class' => $conn->real_escape_string($_POST['license_class'] ?? ''),
            'years_experience' => intval($_POST['years_experience'] ?? 0),
            'emergency_contact' => $conn->real_escape_string($_POST['emergency_contact'] ?? ''),
            'emergency_phone' => preg_replace('/[^0-9+]/', '', $conn->real_escape_string($_POST['emergency_phone'] ?? '')),
            'address' => $conn->real_escape_string($_POST['address'] ?? ''),
            'vehicle_assigned' => intval($_POST['vehicle_assigned'] ?? null)
        ];
    }

    // Validate driver fields server-side during update
    if ($role === 'driver') {
        $licU = $driver_fields['license_number'] ?? '';
        $emU = $driver_fields['emergency_phone'] ?? '';
        $validLicU = preg_match('/^[A-Z0-9]{5,20}$/', $licU);
        $validEmU = preg_match('/^(\+63|0)9[0-9]{9}$/', $emU);
        if (!$validLicU) {
            $error = 'Please provide a valid license number (uppercase letters and digits, 5-20 chars).';
        } elseif (!$validEmU) {
            $error = 'Please provide a valid Philippine emergency contact number (e.g. 09171234567 or +639171234567).';
        }
        // If $error set, we should skip update; check below before applying DB changes
    }

    // Only proceed with DB updates if there are no validation errors
    if (isset($error) && $error) {
        // Skip DB update when validation failed; $error will be shown to user below
    } else {
        // Update auth record in users table (auth-only)
        $sql = "UPDATE users SET 
            username = '$username',
            role = '$role'
            WHERE user_id = $user_id";

        if ($conn->query($sql)) {
            $profileOk = true;

            // Update role-specific profile table
            if ($role === 'passenger') {
                $profileSql = "UPDATE customers SET 
                                full_name = '$full_name',
                                email = '$email',
                                phone_number = '$phone_number',
                                profile_picture = '$profile_picture',
                                address = '$passenger_address'
                              WHERE user_id = $user_id";
                if (!$conn->query($profileSql)) {
                    $profileOk = false;
                    $error = "Error updating passenger profile: " . $conn->error;
                }
            } elseif ($role === 'admin') {
                $profileSql = "UPDATE admins SET 
                                full_name = '$full_name',
                                email = '$email',
                                phone_number = '$phone_number',
                                profile_picture = '$profile_picture',
                                address = '$admin_address'
                              WHERE user_id = $user_id";
                if (!$conn->query($profileSql)) {
                    $profileOk = false;
                    $error = "Error updating admin profile: " . $conn->error;
                }
            } elseif ($role === 'driver') {
                $profileSql = "UPDATE drivers SET 
                                full_name = '$full_name',
                                email = '$email',
                                phone_number = '$phone_number',
                                profile_picture = '$profile_picture',
                                license_number = '{$driver_fields['license_number']}',
                                license_expiry = '{$driver_fields['license_expiry']}',
                                license_class = '{$driver_fields['license_class']}',
                                years_experience = {$driver_fields['years_experience']},
                                emergency_contact = '{$driver_fields['emergency_contact']}',
                                emergency_phone = '{$driver_fields['emergency_phone']}',
                                address = '{$driver_fields['address']}'
                              WHERE user_id = $user_id";
                if (!$conn->query($profileSql)) {
                    $profileOk = false;
                    $error = "Error updating driver profile: " . $conn->error;
                }

                // Handle vehicle assignment for drivers via vehicles.driver_id
                if ($profileOk) {
                    // Clear existing assignments for this driver
                    $conn->query("UPDATE vehicles SET driver_id = NULL WHERE driver_id = $user_id");

                    if ($driver_fields['vehicle_assigned']) {
                        $vehicleId = (int) $driver_fields['vehicle_assigned'];
                        $conn->query("UPDATE vehicles SET driver_id = $user_id WHERE vehicle_id = $vehicleId");
                    }
                }
            } else {
                // If role is changed away from driver, clear any vehicle assignments
                $conn->query("UPDATE vehicles SET driver_id = NULL WHERE driver_id = $user_id");
            }

            if ($profileOk) {
                $message = "User updated successfully!";
                // Log user update
                $adminId = $_SESSION['user_id'] ?? null;
                logCRUD($conn, $adminId, 'UPDATE', 'users', $user_id, 'User details updated');
            }
        } else {
            $error = "Error updating user: " . $conn->error;
        }
    }
}

// Handle delete user
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);

    // Get user details for logging
    $user = $conn->query("SELECT username FROM users WHERE user_id = $user_id")->fetch_assoc();

    if ($conn->query("DELETE FROM users WHERE user_id = $user_id")) {
        $message = "User deleted successfully!";
        // Log user deletion
        $adminId = $_SESSION['user_id'] ?? null;
        logCRUD($conn, $adminId, 'DELETE', 'users', $user_id, 'User deleted: ' . ($user['username'] ?? ''));
    } else {
        $error = "Error deleting user: " . $conn->error;
    }
}
?>

<!-- Users List Table and Actions -->

<?php if (isset($message)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Users List Section -->
<div class="section-header">
    <h2>Users List</h2>
    <div class="section-actions">
        <div class="search-container">
            <input type="text" id="searchUsers" placeholder="Search users..." class="search-input"
                style="border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px;">
        </div>
        <button class="btn btn-primary" id="openAddUser">
            <i class="fas fa-plus"></i> Add User
        </button>
    </div>
</div>

<!-- Role Filter Buttons -->
<div class="filter-buttons">
    <button class="filter-btn active" data-filter="all">
        All Users <span class="count" id="count-all">0</span>
    </button>
    <button class="filter-btn" data-filter="admin">
        <i class="fas fa-user-shield"></i> Admins <span class="count" id="count-admin">0</span>
    </button>
    <button class="filter-btn" data-filter="driver">
        <i class="fas fa-car"></i> Drivers <span class="count" id="count-driver">0</span>
    </button>
    <button class="filter-btn" data-filter="passenger">
        <i class="fas fa-user"></i> Passengers <span class="count" id="count-passenger">0</span>
    </button>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content" style="max-width:900px; width:90%;">
        <h2>Add New User</h2>
        <form method="POST" enctype="multipart/form-data" id="addUserForm" novalidate>
            <input type="hidden" name="action" value="add_user">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label for="username_modal">Username</label>
                    <input type="text" id="username_modal" name="username" required>
                </div>

                <div class="form-group">
                    <label for="email_modal">Email</label>
                    <input type="email" id="email_modal" name="email" required placeholder="name@example.com">
                </div>

                <div class="form-group">
                    <label for="password_modal">Password</label>
                    <input type="password" id="password_modal" name="password" required title="At least 8 characters including uppercase, lowercase, number and symbol">
                </div>

                <div class="form-group">
                    <label for="confirm_password_modal">Confirm Password</label>
                    <input type="password" id="confirm_password_modal" name="confirm_password" required title="Repeat the password">
                </div>

                <div class="form-group">
                    <label for="role_modal">Role</label>
                    <select id="role_modal" name="role" required>
                        <option value="passenger">Passenger</option>
                        <option value="driver">Driver</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="first_name_modal">First Name</label>
                    <input type="text" id="first_name_modal" name="first_name" required>
                </div>

                <div class="form-group">
                    <label for="last_name_modal">Last Name</label>
                    <input type="text" id="last_name_modal" name="last_name" required>
                </div>

                <input type="hidden" id="full_name_modal" name="full_name">

                <div class="form-group" style="grid-column:1 / span 2;">
                    <label for="phone_number_modal">Phone Number</label>
                    <input type="tel" id="phone_number_modal" name="phone_number" required inputmode="numeric" pattern="^(\+63|0)9[0-9]{9}$" title="Philippine mobile e.g. 09171234567 or +639171234567" placeholder="e.g. 09171234567 or +639171234567">
                </div>
            </div>

            <div id="adminFields" style="display: none;">
                <div class="form-group">
                    <label for="admin_address">Address</label>
                    <textarea id="admin_address" name="admin_address" rows="3"></textarea>
                </div>
            </div>

            <div id="passengerFields" style="display: none;">
                <div class="form-group">
                    <label for="passenger_address">Address</label>
                    <textarea id="passenger_address" name="passenger_address" rows="3"></textarea>
                </div>
            </div>

            <!-- Driver Specific Fields (shown only when role=driver) -->
            <div id="driverFields">
                <h3>Driver Information</h3>

                <!-- Two Column Layout for Driver Fields -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <!-- Left Column -->
                    <div>
                        <div class="form-group">
                            <label for="license_number">Driver's License Number</label>
                            <input type="text" id="license_number" name="license_number" placeholder="e.g. AB1234567" pattern="[A-Za-z0-9]{5,20}" title="Uppercase letters and digits only, 5-20 chars" style="text-transform:uppercase;">
                        </div>

                        <div class="form-group">
                            <label for="license_expiry">License Expiration Date</label>
                            <input type="date" id="license_expiry" name="license_expiry">
                        </div>

                        <div class="form-group">
                            <label for="license_class">License Class</label>
                            <select id="license_class" name="license_class">
                                <option value="">Select License Class</option>
                                <option value="Professional">Professional</option>
                                <option value="Non-Professional">Non-Professional</option>
                                <option value="Student">Student Permit</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="years_experience">Years of Driving Experience</label>
                            <input type="number" id="years_experience" name="years_experience" min="0" step="1">
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <div class="form-group">
                            <label for="emergency_contact">Emergency Contact</label>
                            <input type="text" id="emergency_contact" name="emergency_contact"
                                placeholder="Contact Name">
                        </div>

                        <div class="form-group">
                            <label for="emergency_phone">Emergency Contact Phone</label>
                            <input type="tel" id="emergency_phone" name="emergency_phone" inputmode="numeric" pattern="^(\+63|0)9[0-9]{9}$" placeholder="e.g. 09171234567 or +639171234567">
                        </div>

                        <div class="form-group">
                            <label for="vehicle_assigned">Assign Vehicle (Optional)</label>
                            <select id="vehicle_assigned" name="vehicle_assigned">
                                <option value="">To be assigned</option>
                                <?php
                                $vehicles = $conn->query("SELECT vehicle_id, vehicle_name, license_plate FROM vehicles WHERE status = 'active' AND (driver_id IS NULL OR driver_id = 0)");
                                while ($vehicle = $vehicles->fetch_assoc()) {
                                    echo "<option value=\"{$vehicle['vehicle_id']}\">{$vehicle['vehicle_name']} ({$vehicle['license_plate']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Full Width Field -->
                <div class="form-group">
                    <label for="address">Complete Address</label>
                    <textarea id="address" name="address" rows="3"></textarea>
                </div>
            </div>

            <!-- Profile Picture Upload (moved to bottom for consistency) -->
            <div class="form-group">
                <label for="profile_picture">Profile Picture</label>
                <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="closeAddUser">Cancel</button>
                <button type="submit" class="btn btn-primary">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content" style="max-width:900px; width:90%;">
        <h2>Edit User</h2>
        <form method="POST" enctype="multipart/form-data" id="editUserForm">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="current_profile_picture" id="edit_current_profile_picture">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label for="edit_username">Username</label>
                    <input type="text" id="edit_username" name="username" required>
                </div>

                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" required placeholder="name@example.com">
                </div>

                <div class="form-group">
                    <label for="edit_role">Role</label>
                    <select id="edit_role" name="role" required>
                        <option value="passenger">Passenger</option>
                        <option value="driver">Driver</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_first_name">First Name</label>
                    <input type="text" id="edit_first_name" name="first_name" required>
                </div>

                <div class="form-group">
                    <label for="edit_last_name">Last Name</label>
                    <input type="text" id="edit_last_name" name="last_name" required>
                </div>

                <input type="hidden" id="edit_full_name" name="full_name">

                <div class="form-group" style="grid-column:1 / span 2;">
                    <label for="edit_phone_number">Phone Number</label>
                    <input type="tel" id="edit_phone_number" name="phone_number" required inputmode="numeric" pattern="^(\+63|0)9[0-9]{9}$" title="Philippine mobile e.g. 09171234567 or +639171234567" placeholder="e.g. 09171234567 or +639171234567">
                </div>
            </div>

            <div id="editAdminFields" style="display: none;">
                <div class="form-group">
                    <label for="edit_admin_address">Address</label>
                    <textarea id="edit_admin_address" name="admin_address" rows="3"></textarea>
                </div>
            </div>

            <div id="editPassengerFields" style="display: none;">
                <div class="form-group">
                    <label for="edit_passenger_address">Address</label>
                    <textarea id="edit_passenger_address" name="passenger_address" rows="3"></textarea>
                </div>
            </div>

            <!-- Driver Specific Fields (shown only when role=driver) -->
            <div id="editDriverFields">
                <h3>Driver Information</h3>

                <!-- Two Column Layout for Driver Fields -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <!-- Left Column -->
                    <div>
                        <div class="form-group">
                            <label for="edit_license_number">Driver's License Number</label>
                            <input type="text" id="edit_license_number" name="license_number" placeholder="e.g. AB1234567" pattern="[A-Za-z0-9]{5,20}" title="Uppercase letters and digits only, 5-20 chars" style="text-transform:uppercase;">
                        </div>

                        <div class="form-group">
                            <label for="edit_license_expiry">License Expiration Date</label>
                            <input type="date" id="edit_license_expiry" name="license_expiry">
                        </div>

                        <div class="form-group">
                            <label for="edit_license_class">License Class</label>
                            <select id="edit_license_class" name="license_class">
                                <option value="">Select License Class</option>
                                <option value="Professional">Professional</option>
                                <option value="Non-Professional">Non-Professional</option>
                                <option value="Student">Student Permit</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_years_experience">Years of Driving Experience</label>
                            <input type="number" id="edit_years_experience" name="years_experience" min="0" step="1">
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <div class="form-group">
                            <label for="edit_emergency_contact">Emergency Contact</label>
                            <input type="text" id="edit_emergency_contact" name="emergency_contact"
                                placeholder="Contact Name">
                        </div>

                        <div class="form-group">
                            <label for="edit_emergency_phone">Emergency Contact Phone</label>
                            <input type="tel" id="edit_emergency_phone" name="emergency_phone" inputmode="numeric" pattern="^(\+63|0)9[0-9]{9}$" placeholder="e.g. 09171234567 or +639171234567">
                        </div>

                        <div class="form-group">
                            <label for="edit_vehicle_assigned">Assign Vehicle (Optional)</label>
                            <select id="edit_vehicle_assigned" name="vehicle_assigned">
                                <option value="">Remove assignment</option>
                                <?php
                                $vehicles = $conn->query("SELECT vehicle_id, vehicle_name, license_plate FROM vehicles WHERE status = 'active'");
                                while ($vehicle = $vehicles->fetch_assoc()) {
                                    echo "<option value=\"{$vehicle['vehicle_id']}\">{$vehicle['vehicle_name']} ({$vehicle['license_plate']})</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Full Width Field -->
                <div class="form-group">
                    <label for="edit_address">Complete Address</label>
                    <textarea id="edit_address" name="address" rows="3"></textarea>
                </div>
            </div>

            <!-- Profile Picture Upload (moved to bottom for consistency) -->
            <div class="form-group">
                <label for="edit_profile_picture">Profile Picture</label>
                <div class="profile-picture-upload">
                    <img id="edit_profile_picture_preview" src="" class="profile-picture-preview">
                    <input type="file" id="edit_profile_picture" name="profile_picture" accept="image/*">
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="closeEditUser">Cancel</button>
                <button type="submit" class="btn btn-primary">Update User</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="modal">
    <div class="modal-content">
        <div style="color:#d32f2f; font-size:48px; margin-bottom:15px; text-align: center;">
            <i class="fas fa-user-times"></i>
        </div>
        <h3 style="color:#d32f2f; margin-bottom:15px; text-align: center;">Confirm Delete User</h3>
        <p id="deleteMessage" style="margin-bottom:20px; color:#666; text-align: center;"></p>
        <div style="display:flex; justify-content:center; gap:10px;">
            <button type="button" class="btn btn-secondary" id="cancelDelete">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDelete">
                <i class="fas fa-trash"></i> Delete User
            </button>
        </div>
    </div>
</div>

<!-- Users List -->
<div class="table-container">
    <div class="table-header">
        <h2>All Users</h2>
        <div>
            <button onclick="loadUsersData()" class="btn btn-primary" id="refresh-btn">
                <span id="refresh-text">🔄 Refresh</span>
                <span id="refresh-spinner" style="display: none;">⏳ Loading...</span>
            </button>
        </div>
    </div>
    <?php
    // Pagination setup
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(5, intval($_GET['per_page'])) : 20;
    $offset = ($page - 1) * $per_page;

    // Get total count for pagination
    $countResult = $conn->query("SELECT COUNT(*) as cnt FROM users");
    $totalCount = 0;
    if ($countResult) {
        $row = $countResult->fetch_assoc();
        $totalCount = $row ? intval($row['cnt']) : 0;
    }
    $totalPages = $per_page > 0 ? ceil($totalCount / $per_page) : 1;

    ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Phone Number</th>
                <th>Role</th>
                <th>Registered Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $users = $conn->query("SELECT 
                    u.user_id,
                    u.username,
                    u.role,
                    COALESCE(a.full_name, d.full_name, c.full_name) AS full_name,
                    COALESCE(a.phone_number, d.phone_number, c.phone_number) AS phone_number,
                    COALESCE(a.created_at, d.created_at, c.created_at) AS created_at
                FROM users u
                LEFT JOIN admins a ON a.user_id = u.user_id
                LEFT JOIN drivers d ON d.user_id = u.user_id
                LEFT JOIN customers c ON c.user_id = u.user_id
                ORDER BY created_at DESC
                LIMIT " . intval($per_page) . " OFFSET " . intval($offset));

            while ($user = $users->fetch_assoc()):
                ?>
                <tr data-role="<?php echo $user['role']; ?>">
                    <td><?php echo $user['user_id']; ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['phone_number']); ?></td>
                    <td>
                        <span class="role-badge <?php echo $user['role']; ?>">
                            <?php echo ucfirst($user['role']); ?>
                        </span>
                    </td>
                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn btn-primary edit-user"
                                data-userid="<?php echo $user['user_id']; ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="btn btn-danger"
                                onclick="showDeleteConfirm('users.php?action=delete&id=<?php echo $user['user_id']; ?>', '<?php echo addslashes($user['username']); ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
        <div>Showing <?php echo min($totalCount, $offset+1); ?> - <?php echo min($totalCount, $offset + $per_page); ?> of <?php echo $totalCount; ?></div>
        <div class="pagination" style="display:flex; gap:6px; align-items:center;">
            <?php if ($page > 1): ?>
                <a class="btn" href="?page=<?php echo $page-1; ?>&per_page=<?php echo $per_page; ?>">&laquo; Prev</a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="btn" href="?page=<?php echo $p; ?>&per_page=<?php echo $per_page; ?>" style="<?php echo $p === $page ? 'background:var(--primary-pink); color:#fff;' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a class="btn" href="?page=<?php echo $page+1; ?>&per_page=<?php echo $per_page; ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Role filter functionality
    document.addEventListener('DOMContentLoaded', function() {
        const filterButtons = document.querySelectorAll('.filter-btn');
        const userRows = document.querySelectorAll('table tbody tr');
        
        // Count users by role
        function updateRoleCounts() {
            const counts = {
                'all': userRows.length,
                'admin': 0,
                'driver': 0,
                'passenger': 0
            };
            
            userRows.forEach(row => {
                const role = row.getAttribute('data-role');
                if (counts.hasOwnProperty(role)) {
                    counts[role]++;
                }
            });
            
            // Update count displays
            document.getElementById('count-all').textContent = counts.all;
            document.getElementById('count-admin').textContent = counts.admin;
            document.getElementById('count-driver').textContent = counts.driver;
            document.getElementById('count-passenger').textContent = counts.passenger;
        }
        
        // Filter users by role
        function filterUsersByRole(role) {
            userRows.forEach(row => {
                const userRole = row.getAttribute('data-role');
                if (role === 'all' || userRole === role) {
                    row.style.display = '';
                    row.classList.remove('hidden');
                } else {
                    row.style.display = 'none';
                    row.classList.add('hidden');
                }
            });
            
            // Update active filter button
            filterButtons.forEach(btn => {
                if (btn.getAttribute('data-filter') === role) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // Check if any rows are visible
            const visibleRows = document.querySelectorAll('table tbody tr:not(.hidden)').length;
            if (visibleRows === 0) {
                showEmptyState('No users found with the selected filter.');
            } else {
                hideEmptyState();
            }
        }
        
        // Add event listeners to filter buttons
        filterButtons.forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                filterUsersByRole(filter);
            });
        });
        
        // Update counts on page load
        updateRoleCounts();
        
        // Search functionality
        const searchInput = document.getElementById('searchUsers');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const currentFilter = document.querySelector('.filter-btn.active').getAttribute('data-filter');
                
                userRows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const userRole = row.getAttribute('data-role');
                    
                    // Apply both search and role filter
                    const matchesSearch = text.includes(searchTerm);
                    const matchesRole = currentFilter === 'all' || userRole === currentFilter;
                    
                    if (matchesSearch && matchesRole) {
                        row.style.display = '';
                        row.classList.remove('hidden');
                    } else {
                        row.style.display = 'none';
                        row.classList.add('hidden');
                    }
                });
                
                const visibleRows = document.querySelectorAll('table tbody tr:not(.hidden)').length;
                if (visibleRows === 0) {
                    showEmptyState(searchTerm ? 'No users match your search.' : 'No users found with the selected filter.');
                } else {
                    hideEmptyState();
                }
            });
        }
    });
    
    function showEmptyState(message) {
        let emptyState = document.querySelector('.empty-state');
        if (!emptyState) {
            emptyState = document.createElement('div');
            emptyState.className = 'empty-state';
            document.querySelector('.table-container').appendChild(emptyState);
        }
        emptyState.innerHTML = `
            <div class="empty-state-icon">🔍</div>
            <h3>No Results Found</h3>
            <p>${message}</p>
        `;
    }
    
    function hideEmptyState() {
        const emptyState = document.querySelector('.empty-state');
        if (emptyState) {
            emptyState.remove();
        }
    }
    
    function updateAddUserRoleFields() {
        const roleSelect = document.getElementById('role_modal');
        if (!roleSelect) return;

        const driverFields = document.getElementById('driverFields');
        const adminFields = document.getElementById('adminFields');
        const passengerFields = document.getElementById('passengerFields');

        const role = roleSelect.value;
        const isDriver = role === 'driver';
        const isAdmin = role === 'admin';
        const isPassenger = role === 'passenger';

        if (driverFields) {
            driverFields.style.display = isDriver ? 'block' : 'none';
            const driverInputs = driverFields.querySelectorAll('input, select, textarea');
            driverInputs.forEach(input => {
                input.required = isDriver;
            });
        }

        // Ensure license input uppercases and only allows alphanumeric
        const licInputs = document.querySelectorAll('#license_number, #edit_license_number');
        licInputs.forEach(li => {
            li.addEventListener('input', function() {
                this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            });
        });

        if (adminFields) {
            adminFields.style.display = isAdmin ? 'block' : 'none';
        }

        if (passengerFields) {
            passengerFields.style.display = isPassenger ? 'block' : 'none';
        }
    }

    // Modal handlers for Add User
    document.getElementById('openAddUser').addEventListener('click', function () {
        document.getElementById('addUserModal').style.display = 'flex';
        updateAddUserRoleFields();
    });

    document.getElementById('closeAddUser').addEventListener('click', function () {
        document.getElementById('addUserModal').style.display = 'none';
    });
    document.getElementById('addUserModal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });

    // Modal handlers for Edit User
    document.getElementById('closeEditUser').addEventListener('click', function () {
        document.getElementById('editUserModal').style.display = 'none';
    });
    document.getElementById('editUserModal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });

    // Show/hide driver/admin/passenger fields based on role selection
    document.getElementById('role_modal').addEventListener('change', function () {
        updateAddUserRoleFields();
    });

    // Initialize role-based fields on page load
    document.addEventListener('DOMContentLoaded', function () {
        updateAddUserRoleFields();
    });

    // Enhanced client-side validation for Add User form
    (function() {
        var addForm = document.getElementById('addUserForm');
        if (!addForm) return;

        var pwEl = document.getElementById('password_modal');
        var cpwEl = document.getElementById('confirm_password_modal');
        var phoneEl = document.getElementById('phone_number_modal');
        var emailEl = document.getElementById('email_modal');

        var pwRegex = /(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}/;
        var phoneRegex = /^(\+63|0)9[0-9]{9}$/;

        function showError(message, el) {
            alert(message);
            if (el && typeof el.focus === 'function') el.focus();
        }

        addForm.addEventListener('submit', function(e) {
            var pw = (pwEl && pwEl.value) ? pwEl.value.trim() : '';
            var cpw = (cpwEl && cpwEl.value) ? cpwEl.value.trim() : '';
            var phone = (phoneEl && phoneEl.value) ? phoneEl.value.trim() : '';
            var emailValid = true;

            if (emailEl) {
                // Use browser validity for email input if available
                emailValid = emailEl.checkValidity();
                if (!emailValid) {
                    e.preventDefault();
                    showError('Please provide a valid email address (e.g. name@example.com).', emailEl);
                    return false;
                }
            }

            if (!pwRegex.test(pw)) {
                e.preventDefault();
                showError('Password must be at least 8 characters and include uppercase, lowercase, a number, and a symbol.', pwEl);
                return false;
            }

            if (pw !== cpw) {
                e.preventDefault();
                showError('Passwords do not match. Please confirm the password.', cpwEl);
                return false;
            }

            if (phoneEl && phone.length > 0 && !phoneRegex.test(phone)) {
                e.preventDefault();
                showError('Please provide a valid Philippine mobile number (e.g. 09171234567 or +639171234567).', phoneEl);
                return false;
            }

            return true;
        });
    })();

    document.getElementById('edit_role').addEventListener('change', function () {
        const driverFields = document.getElementById('editDriverFields');
        const adminFields = document.getElementById('editAdminFields');
        const passengerFields = document.getElementById('editPassengerFields');
        const role = this.value;
        const isDriver = role === 'driver';
        const isAdmin = role === 'admin';
        const isPassenger = role === 'passenger';
        driverFields.style.display = isDriver ? 'block' : 'none';
        if (adminFields) {
            adminFields.style.display = isAdmin ? 'block' : 'none';
        }
        if (passengerFields) {
            passengerFields.style.display = isPassenger ? 'block' : 'none';
        }
    });

    // Edit user functionality — use event delegation on table container so handlers always work
    (function() {
        const tableContainer = document.querySelector('.table-container');
        if (tableContainer) {
            tableContainer.addEventListener('click', function(e) {
                const btn = e.target.closest('.edit-user');
                if (!btn) return;
                const userId = btn.getAttribute('data-userid');
                if (userId) loadUserData(userId);
            });
        }
    })();

    // Fallback: bind directly to existing buttons (covers cases where delegation might not catch clicks)
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.edit-user').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const userId = this.getAttribute('data-userid');
                if (userId) loadUserData(userId);
            });
        });
    });

    function loadUserData(userId) {
        console.log('loadUserData()', userId);
        fetch('./get_user.php?id=' + userId)
            .then(response => {
                const ct = response.headers.get('content-type') || '';
                if (!ct.includes('application/json')) throw new Error('Invalid response');
                return response.json();
            })
            .then(user => {
                if (!user || typeof user !== 'object') throw new Error('Invalid user data');
                // Clear edit form first
                try { document.getElementById('editUserForm').reset(); } catch (e) {}

                // Populate form fields
                document.getElementById('edit_user_id').value = user.user_id;
                document.getElementById('edit_username').value = user.username;
                document.getElementById('edit_email').value = user.email || '';
                document.getElementById('edit_role').value = user.role;
                // Split full name into first/last for edit form
                const nameParts = (user.full_name || '').trim().split(/\s+/);
                const eFirst = nameParts.length > 0 ? nameParts.slice(0, -1).join(' ') || nameParts[0] : '';
                const eLast = nameParts.length > 1 ? nameParts.slice(-1).join(' ') : '';
                document.getElementById('edit_first_name').value = eFirst;
                document.getElementById('edit_last_name').value = eLast;
                // keep hidden full_name value too
                document.getElementById('edit_full_name').value = user.full_name || '';
                document.getElementById('edit_phone_number').value = user.phone_number;
                document.getElementById('edit_current_profile_picture').value = user.profile_picture || '';

                // Show profile picture if exists
                const preview = document.getElementById('edit_profile_picture_preview');
                if (user.profile_picture) {
                    preview.src = user.profile_picture;
                    preview.style.display = 'block';
                } else {
                    preview.style.display = 'none';
                }

                // Handle role-specific fields
                const driverFields = document.getElementById('editDriverFields');
                const adminFields = document.getElementById('editAdminFields');
                const passengerFields = document.getElementById('editPassengerFields');
                if (user.role === 'driver') {
                    driverFields.style.display = 'block';
                    if (adminFields) adminFields.style.display = 'none';
                    if (passengerFields) passengerFields.style.display = 'none';
                    document.getElementById('edit_license_number').value = user.license_number || '';
                    document.getElementById('edit_license_expiry').value = user.license_expiry || '';
                    document.getElementById('edit_license_class').value = user.license_class || '';
                    document.getElementById('edit_years_experience').value = user.years_experience || '';
                    document.getElementById('edit_emergency_contact').value = user.emergency_contact || '';
                    document.getElementById('edit_emergency_phone').value = user.emergency_phone || '';
                    document.getElementById('edit_address').value = user.address || '';
                    document.getElementById('edit_vehicle_assigned').value = user.vehicle_assigned || '';
                } else if (user.role === 'admin') {
                    driverFields.style.display = 'none';
                    if (adminFields) {
                        adminFields.style.display = 'block';
                        document.getElementById('edit_admin_address').value = user.address || '';
                    }
                    if (passengerFields) passengerFields.style.display = 'none';
                } else {
                    driverFields.style.display = 'none';
                    if (adminFields) adminFields.style.display = 'none';
                    if (passengerFields) {
                        passengerFields.style.display = 'block';
                        document.getElementById('edit_passenger_address').value = user.address || '';
                    }
                }

                // Show modal and focus first input
                const modal = document.getElementById('editUserModal');
                modal.style.display = 'flex';
                setTimeout(() => {
                    const firstInput = modal.querySelector('input[name="username"]') || modal.querySelector('input');
                    if (firstInput) firstInput.focus();
                }, 50);
            })
            .catch(error => {
                console.error('Error loading user data:', error);
                alert('Error loading user data');
            });
    }

    // Delete confirmation modal handlers
    let deleteUrl = '';
    document.addEventListener('DOMContentLoaded', function () {
        const cancelBtn = document.getElementById('cancelDelete');
        const confirmBtn = document.getElementById('confirmDelete');
        const modal = document.getElementById('deleteConfirmModal');

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                modal.style.display = 'none';
            });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (deleteUrl) {
                    window.location.href = deleteUrl;
                }
            });
        }

        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === this) this.style.display = 'none';
            });
        }
    });

    // Function to load users data
    function loadUsersData() {
        // Show loading state
        const refreshBtn = document.getElementById('refresh-btn');
        const refreshText = document.getElementById('refresh-text');
        const refreshSpinner = document.getElementById('refresh-spinner');

        refreshText.style.display = 'none';
        refreshSpinner.style.display = 'inline';
        refreshBtn.disabled = true;

        // Reload the page after a short delay to show the loading state
        setTimeout(() => {
            window.location.reload();
        }, 500);
    }

    // Function to show delete confirmation
    function showDeleteConfirm(url, username) {
        deleteUrl = url;
        document.getElementById('deleteMessage').textContent = `Are you sure you want to delete user "${username}"? This action cannot be undone and will remove all associated data.`;
        document.getElementById('deleteConfirmModal').style.display = 'flex';
        return false; // Prevent default link action
    }

    // Combine first+last into hidden full_name, sanitize phone, and validate fields before submit
    function combineNamesAndValidate(formEl) {
        const first = formEl.querySelector('input[name="first_name"]')?.value.trim() || '';
        const last = formEl.querySelector('input[name="last_name"]')?.value.trim() || '';
        const hiddenFull = formEl.querySelector('input[name="full_name"]');
        if (hiddenFull) hiddenFull.value = (first + (first && last ? ' ' : '') + last).trim();

        // Email validation (HTML5 will catch most cases) - extra check
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

        // Password strength check for add form
        const passEl = formEl.querySelector('input[name="password"]');
        if (passEl && passEl.value) {
            const pass = passEl.value;
            const strong = /(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}/.test(pass);
            if (!strong) {
                alert('Password must be at least 8 characters and include uppercase, lowercase, number and symbol.');
                passEl.focus();
                return false;
            }
        }

        return true;
    }

    // Hook add and edit forms
    document.addEventListener('DOMContentLoaded', function() {
        const addForm = document.getElementById('addUserForm');
        if (addForm) {
            addForm.addEventListener('submit', function(e) {
                if (!combineNamesAndValidate(addForm)) e.preventDefault();
            });
        }

        const editForm = document.getElementById('editUserForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                if (!combineNamesAndValidate(editForm)) e.preventDefault();
            });
        }

        // Enforce numeric-only phone inputs (allow leading +)
        const phoneInputs = document.querySelectorAll('input[name="phone_number"], input[name="emergency_phone"], #emergency_phone, #edit_emergency_phone');
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
