<?php
session_start();
require_once __DIR__ . '/../config.php';

$reg_message = '';
$message_type = 'error';

function hz_register_clean_phone(string $phone): string
{
    return preg_replace('/[^0-9+]/', '', $phone);
}

function hz_register_password_errors(string $password, string $confirmPassword): array
{
    $errors = [];
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least 1 uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least 1 lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include at least 1 number.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Password confirmation must match.';
    }

    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $full_name = trim($first_name . ' ' . $last_name);
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $role = strtolower(trim($_POST['role'] ?? 'passenger'));
    $phone_digits = hz_register_clean_phone($phone_number);

    $license_number = trim($_POST['license_number'] ?? '');
    $license_expiry = trim($_POST['license_expiry'] ?? '');
    $license_class = trim($_POST['license_class'] ?? '');
    $years_experience = max(0, intval($_POST['years_experience'] ?? 0));
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $emergency_phone = trim($_POST['emergency_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $passwordErrors = hz_register_password_errors($password, $confirm_password);

    if (!in_array($role, ['passenger', 'driver'], true)) {
        $reg_message = 'Please choose whether to register as a passenger or driver.';
    } elseif ($username === '' || $password === '' || $confirm_password === '' || $first_name === '' || $last_name === '' || $email === '' || $phone_number === '') {
        $reg_message = 'Please fill in all required fields.';
    } elseif ($passwordErrors) {
        $reg_message = implode(' ', $passwordErrors);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reg_message = 'Please provide a valid email address.';
    } elseif (!preg_match('/^(\+63|0)9[0-9]{9}$/', $phone_digits)) {
        $reg_message = 'Please provide a valid Philippine phone number (e.g. 09171234567 or +639171234567).';
    } elseif ($role === 'driver' && ($license_number === '' || $license_expiry === '' || $license_class === '' || $address === '')) {
        $reg_message = 'Please complete the required driver application details.';
    } elseif ($role === 'driver' && (
        !isset($_FILES['license_front_image']) || $_FILES['license_front_image']['error'] === UPLOAD_ERR_NO_FILE ||
        !isset($_FILES['license_back_image']) || $_FILES['license_back_image']['error'] === UPLOAD_ERR_NO_FILE
    )) {
        $reg_message = 'Please upload front and back images of your driver license.';
    } else {
        $check_stmt = $conn->prepare('SELECT user_id FROM users WHERE username = ?');
        $check_stmt->bind_param('s', $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $reg_message = 'Username already exists. Please choose a different username.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
            $insert_stmt->bind_param('sss', $username, $hashed_password, $role);

            if ($insert_stmt->execute()) {
                $newId = $conn->insert_id;

                if ($role === 'passenger') {
                    $profile_stmt = $conn->prepare('INSERT INTO customers (user_id, full_name, email, phone_number) VALUES (?, ?, ?, ?)');
                    if ($profile_stmt) {
                        $profile_stmt->bind_param('isss', $newId, $full_name, $email, $phone_digits);
                        $profile_stmt->execute();
                        $profile_stmt->close();
                    }

                    $reg_message = 'Registration successful! You can now login with your credentials.';
                    $message_type = 'success';
                    logCRUD($conn, $newId, 'CREATE', 'users', $newId, 'Self-registered passenger: ' . $username);
                    $_POST = [];
                } else {
                    $upload_error = null;
                    $license_front_image = hz_store_uploaded_image('license_front_image', 'licenses', 'license_front_' . $newId, '', $upload_error);
                    $license_back_image = $upload_error ? '' : hz_store_uploaded_image('license_back_image', 'licenses', 'license_back_' . $newId, '', $upload_error);
                    $profile_picture = $upload_error ? '' : hz_store_uploaded_image('profile_picture', 'profiles', 'driver_profile_' . $newId, '', $upload_error);

                    if ($upload_error || $license_front_image === '' || $license_back_image === '') {
                        $conn->query('DELETE FROM users WHERE user_id = ' . intval($newId));
                        $reg_message = $upload_error ?: 'Please upload valid driver license images.';
                    } else {
                        $profile_stmt = $conn->prepare("
                            INSERT INTO drivers (
                                user_id,
                                full_name,
                                email,
                                phone_number,
                                profile_picture,
                                license_number,
                                license_expiry,
                                license_class,
                                license_front_image,
                                license_back_image,
                                years_experience,
                                emergency_contact,
                                emergency_phone,
                                address,
                                approval_status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");

                        if ($profile_stmt) {
                            $profile_stmt->bind_param(
                                'isssssssssisss',
                                $newId,
                                $full_name,
                                $email,
                                $phone_digits,
                                $profile_picture,
                                $license_number,
                                $license_expiry,
                                $license_class,
                                $license_front_image,
                                $license_back_image,
                                $years_experience,
                                $emergency_contact,
                                $emergency_phone,
                                $address
                            );

                            if ($profile_stmt->execute()) {
                                $reg_message = 'Driver application submitted. Please wait for superadmin approval before logging in.';
                                $message_type = 'success';
                                logCRUD($conn, $newId, 'CREATE', 'users', $newId, 'Self-registered driver application: ' . $username);
                                $_POST = [];
                            } else {
                                $conn->query('DELETE FROM users WHERE user_id = ' . intval($newId));
                                $reg_message = 'Driver application failed. Please try again.';
                            }
                            $profile_stmt->close();
                        } else {
                            $conn->query('DELETE FROM users WHERE user_id = ' . intval($newId));
                            $reg_message = 'Driver application failed. Please try again.';
                        }
                    }
                }
            } else {
                $reg_message = 'Registration failed. Please try again.';
            }

            $insert_stmt->close();
        }

        $check_stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Haven Zen</title>
    <link rel="stylesheet" href="loginsection.css">
    <style>
        .role-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }

        .role-option {
            border: 1px solid rgba(233, 30, 99, 0.25);
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            background: #fff;
            transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        .role-option:has(input:checked) {
            border-color: #e91e63;
            background: rgba(233, 30, 99, 0.08);
            transform: translateY(-1px);
        }

        .role-option input {
            margin-right: 8px;
        }

        .driver-fields {
            display: none;
            padding: 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin: 12px 0 18px;
        }

        .driver-fields.is-visible {
            display: block;
        }

        .password-rules {
            display: grid;
            gap: 6px;
            margin-top: 8px;
            font-size: 0.86rem;
        }

        .password-rule {
            color: #b91c1c;
            font-weight: 600;
        }

        .password-rule.is-valid {
            color: #15803d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-text">
            <h1>Haven Zen Transportation</h1>
            <p>Track, Book, and Manage Your Journey in Barugo, Leyte</p>
        </div>

        <div class="card-container">
            <div class="form-section">
                <h2>Create Your Account</h2>

                <?php if ($reg_message): ?>
                    <div class="<?php echo $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
                        <?php echo htmlspecialchars($reg_message); ?>
                    </div>
                <?php endif; ?>

                <?php $selectedRole = $_POST['role'] ?? 'passenger'; ?>
                <form action="register.php" method="POST" enctype="multipart/form-data" id="registerForm">
                    <label style="display:block; margin-bottom:8px; font-weight:700;">Register As *</label>
                    <div class="role-toggle">
                        <label class="role-option">
                            <input type="radio" name="role" value="passenger" <?php echo $selectedRole !== 'driver' ? 'checked' : ''; ?>>
                            Passenger
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="driver" <?php echo $selectedRole === 'driver' ? 'checked' : ''; ?>>
                            Driver
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" placeholder="Choose a unique username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" placeholder="Create a strong password" required>
                        <div class="password-rules" aria-live="polite">
                            <span class="password-rule" data-rule="length">At least 6 characters</span>
                            <span class="password-rule" data-rule="upper">1 uppercase letter</span>
                            <span class="password-rule" data-rule="lower">1 lowercase letter</span>
                            <span class="password-rule" data-rule="number">1 number</span>
                            <span class="password-rule" data-rule="match">Passwords match</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Type Password Again *</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required>
                    </div>

                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" placeholder="e.g. Juan" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" placeholder="e.g. Dela Cruz" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" placeholder="name@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone_number">Phone Number *</label>
                        <input type="tel" id="phone_number" name="phone_number"
                               inputmode="numeric"
                               pattern="^(\+63|0)9[0-9]{9}$"
                               placeholder="e.g. 09171234567 or +639171234567"
                               title="Philippine mobile e.g. 09171234567 or +639171234567"
                               required
                               value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                        <small style="color: #666; font-size: 0.85em;">Format: 09171234567 or +639171234567</small>
                    </div>

                    <div class="driver-fields" id="driverFields">
                        <h3 style="margin:0 0 12px;">Driver Application Details</h3>
                        <div class="form-group">
                            <label for="license_number">License Number *</label>
                            <input type="text" id="license_number" name="license_number" value="<?php echo htmlspecialchars($_POST['license_number'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="license_expiry">License Expiry *</label>
                            <input type="date" id="license_expiry" name="license_expiry" value="<?php echo htmlspecialchars($_POST['license_expiry'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="license_class">License Class *</label>
                            <input type="text" id="license_class" name="license_class" placeholder="Professional / Non-professional" value="<?php echo htmlspecialchars($_POST['license_class'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="years_experience">Years of Experience</label>
                            <input type="number" id="years_experience" name="years_experience" min="0" value="<?php echo htmlspecialchars($_POST['years_experience'] ?? '0'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="emergency_contact">Emergency Contact</label>
                            <input type="text" id="emergency_contact" name="emergency_contact" value="<?php echo htmlspecialchars($_POST['emergency_contact'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="emergency_phone">Emergency Phone</label>
                            <input type="tel" id="emergency_phone" name="emergency_phone" value="<?php echo htmlspecialchars($_POST['emergency_phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="address">Address *</label>
                            <textarea id="address" name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="profile_picture">Profile Picture</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label for="license_front_image">Driver License Image - Front *</label>
                            <input type="file" id="license_front_image" name="license_front_image" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label for="license_back_image">Driver License Image - Back *</label>
                            <input type="file" id="license_back_image" name="license_back_image" accept="image/*">
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">Register</button>
                </form>

                <div class="link-text">
                    Already have an account? <a href="login.php">Login here</a>
                </div>

                <div class="link-text">
                    <a href="../index.php">Back to Home</a>
                </div>
            </div>

            <div class="illustration-section">
                <div class="bus-animation">Bus</div>
                <div class="illustration-text">
                    <h3>Join Haven Zen Today!</h3>
                    <p>Passengers can book right away. Driver accounts are reviewed by the superadmin before access is granted.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const roleInputs = document.querySelectorAll('input[name="role"]');
        const driverFields = document.getElementById('driverFields');
        const driverRequiredIds = ['license_number', 'license_expiry', 'license_class', 'address', 'license_front_image', 'license_back_image'];
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const rules = {
            length: document.querySelector('[data-rule="length"]'),
            upper: document.querySelector('[data-rule="upper"]'),
            lower: document.querySelector('[data-rule="lower"]'),
            number: document.querySelector('[data-rule="number"]'),
            match: document.querySelector('[data-rule="match"]')
        };

        function selectedRole() {
            const selected = document.querySelector('input[name="role"]:checked');
            return selected ? selected.value : 'passenger';
        }

        function syncRoleFields() {
            const isDriver = selectedRole() === 'driver';
            driverFields.classList.toggle('is-visible', isDriver);
            driverRequiredIds.forEach(function (id) {
                const field = document.getElementById(id);
                if (field) {
                    field.required = isDriver;
                }
            });
        }

        function setRule(rule, valid) {
            if (rule) {
                rule.classList.toggle('is-valid', valid);
            }
        }

        function syncPasswordRules() {
            const value = password.value || '';
            const confirmValue = confirmPassword.value || '';
            setRule(rules.length, value.length >= 6);
            setRule(rules.upper, /[A-Z]/.test(value));
            setRule(rules.lower, /[a-z]/.test(value));
            setRule(rules.number, /[0-9]/.test(value));
            setRule(rules.match, value !== '' && value === confirmValue);
        }

        roleInputs.forEach(function (input) {
            input.addEventListener('change', syncRoleFields);
        });
        password.addEventListener('input', syncPasswordRules);
        confirmPassword.addEventListener('input', syncPasswordRules);

        syncRoleFields();
        syncPasswordRules();
    });
    </script>
</body>
</html>
