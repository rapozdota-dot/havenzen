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
    $license_code = trim($_POST['license_code'] ?? '');
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
                                license_code,
                                license_expiry,
                                license_class,
                                license_front_image,
                                license_back_image,
                                years_experience,
                                emergency_contact,
                                emergency_phone,
                                address,
                                approval_status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                        ");

                        if ($profile_stmt) {
                            $profile_stmt->bind_param(
                                'issssssssssisss',
                                $newId,
                                $full_name,
                                $email,
                                $phone_digits,
                                $profile_picture,
                                $license_number,
                                $license_code,
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
        body.register-page {
            align-items: flex-start;
            min-height: 100vh;
            background: #eef3f8;
        }

        .register-page .container {
            max-width: 1180px;
            min-height: 100vh;
            justify-content: center;
            padding: 32px 22px;
        }

        .register-page .header-text {
            margin-bottom: 20px;
        }

        .register-page .header-text h1 {
            font-size: 1.65rem;
            margin-bottom: 6px;
            letter-spacing: 0;
        }

        .register-page .header-text p {
            font-size: 0.92rem;
        }

        .register-page .card-container {
            max-width: 1080px;
            align-items: stretch;
            border-radius: 10px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(148, 163, 184, 0.22);
        }

        .register-page .form-section {
            flex: 1 1 720px;
            padding: 34px;
        }

        .register-page .illustration-section {
            flex: 0 0 320px;
            padding: 36px 30px;
            background:
                linear-gradient(155deg, rgba(15, 23, 42, 0.95), rgba(30, 64, 88, 0.88)),
                linear-gradient(135deg, #d81b60, #0f766e);
            justify-content: center;
            gap: 28px;
        }

        .register-page .bus-animation {
            width: 76px;
            height: 76px;
            border: 2px solid rgba(255, 255, 255, 0.36);
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: 0;
            background: rgba(255, 255, 255, 0.12);
            margin-bottom: 0;
        }

        .register-page .illustration-text h3 {
            font-size: 1.28rem;
        }

        .register-page .illustration-text p {
            font-size: 0.9rem;
        }

        .register-page .form-section h2 {
            margin-bottom: 22px;
            font-size: 1.38rem;
        }

        .register-label {
            display: block;
            margin-bottom: 8px;
            color: #111827;
            font-weight: 700;
            font-size: 0.88rem;
        }

        .role-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }

        .role-option {
            position: relative;
            border: 1px solid rgba(233, 30, 99, 0.25);
            border-radius: 8px;
            padding: 12px 14px;
            cursor: pointer;
            background: #fff;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .role-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .role-option-content {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 34px;
        }

        .role-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 800;
        }

        .role-title {
            display: block;
            color: #111827;
            font-size: 0.92rem;
            font-weight: 700;
            line-height: 1.15;
        }

        .role-note {
            display: block;
            color: #64748b;
            font-size: 0.75rem;
            margin-top: 2px;
        }

        .role-option:has(input:checked) {
            border-color: #e91e63;
            background: rgba(233, 30, 99, 0.08);
            box-shadow: 0 0 0 3px rgba(233, 30, 99, 0.08);
            transform: translateY(-1px);
        }

        .role-option:has(input:checked) .role-icon {
            background: #e91e63;
            border-color: #e91e63;
            color: #fff;
        }

        .register-field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 16px;
        }

        .field-span-2 {
            grid-column: 1 / -1;
        }

        .register-page .form-group {
            margin-bottom: 0;
        }

        .register-page .form-group input,
        .register-page .form-group textarea {
            min-height: 42px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .password-input {
            position: relative;
        }

        .password-input input {
            width: 100%;
            padding-right: 46px !important;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 8px;
            width: 34px;
            height: 34px;
            transform: translateY(-50%);
            display: inline-grid;
            place-items: center;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .password-toggle:hover,
        .password-toggle:focus-visible {
            background: #f1f5f9;
            color: #111827;
            outline: none;
        }

        .password-toggle svg {
            width: 18px;
            height: 18px;
            pointer-events: none;
        }

        .password-toggle .icon-eye-off,
        .password-toggle.is-visible .icon-eye {
            display: none;
        }

        .password-toggle.is-visible .icon-eye-off {
            display: block;
        }

        .register-page .form-group textarea {
            width: 100%;
            min-height: 84px;
            resize: vertical;
            border: 1px solid var(--medium-gray);
            font-family: 'Poppins', sans-serif;
        }

        .register-page .form-group input[type="file"] {
            padding: 8px 10px;
            background: #fff;
        }

        .driver-fields {
            display: none;
            padding: 18px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin: 18px 0;
        }

        .driver-fields.is-visible {
            display: block;
        }

        .driver-fields h3 {
            margin: 0 0 14px;
            color: #111827;
            font-size: 1rem;
        }

        .password-rules {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
            font-size: 0.78rem;
        }

        .password-rule {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 34px;
            padding: 6px 9px;
            border-radius: 8px;
            border: 1px solid #fecaca;
            background: #fff7f7;
            color: #991b1b;
            font-weight: 600;
            line-height: 1.1;
        }

        .password-rule::before {
            content: "";
            width: 14px;
            height: 14px;
            flex: 0 0 14px;
            border-radius: 50%;
            border: 2px solid #ef4444;
            background: #fff;
            box-shadow: inset 0 0 0 3px #fee2e2;
        }

        .password-rule.is-valid {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
        }

        .password-rule.is-valid::before {
            border-color: #16a34a;
            background: #16a34a;
            box-shadow: inset 0 0 0 3px #fff;
        }

        .register-actions {
            margin-top: 18px;
        }

        .register-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px 16px;
            margin-top: 16px;
        }

        .register-links .link-text {
            margin-top: 0;
        }

        @media (max-width: 968px) {
            .register-page .card-container {
                max-width: 720px;
            }

            .register-page .illustration-section {
                flex-basis: auto;
                gap: 22px;
                justify-content: center;
            }

            .password-rules {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 680px) {
            .register-field-grid,
            .password-rules,
            .role-toggle {
                grid-template-columns: 1fr;
            }

            .register-page .form-section {
                padding: 24px 18px;
            }
        }
    </style>
</head>
<body class="register-page">
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
                    <label class="register-label">Register As *</label>
                    <div class="role-toggle">
                        <label class="role-option">
                            <input type="radio" name="role" value="passenger" <?php echo $selectedRole !== 'driver' ? 'checked' : ''; ?>>
                            <span class="role-option-content">
                                <span class="role-icon">P</span>
                                <span>
                                    <span class="role-title">Passenger</span>
                                    <span class="role-note">Book after signup</span>
                                </span>
                            </span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="driver" <?php echo $selectedRole === 'driver' ? 'checked' : ''; ?>>
                            <span class="role-option-content">
                                <span class="role-icon">D</span>
                                <span>
                                    <span class="role-title">Driver</span>
                                    <span class="role-note">Needs approval</span>
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="register-field-grid">
                        <div class="form-group field-span-2">
                            <label for="username">Username *</label>
                            <input type="text" id="username" name="username" placeholder="Choose a unique username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>

                        <div class="form-group field-span-2">
                            <label for="password">Password *</label>
                            <div class="password-input">
                                <input type="password" id="password" name="password" placeholder="Create a strong password" required>
                                <button type="button" class="password-toggle" data-target="password" aria-label="Show password" aria-pressed="false">
                                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"></path><path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 1.4-.35"></path><path d="M9.9 4.25A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18.6 18.6 0 0 1-3.1 4.35"></path><path d="M6.6 6.6C3.65 8.55 2 12 2 12s3.5 8 10 8a10.8 10.8 0 0 0 4.4-.95"></path></svg>
                                </button>
                            </div>
                        </div>

                        <div class="form-group field-span-2">
                            <label for="confirm_password">Type Password Again *</label>
                            <div class="password-input">
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required>
                                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password confirmation" aria-pressed="false">
                                    <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"></path><path d="M10.6 10.6A3 3 0 0 0 12 15a3 3 0 0 0 1.4-.35"></path><path d="M9.9 4.25A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18.6 18.6 0 0 1-3.1 4.35"></path><path d="M6.6 6.6C3.65 8.55 2 12 2 12s3.5 8 10 8a10.8 10.8 0 0 0 4.4-.95"></path></svg>
                                </button>
                            </div>
                            <div class="password-rules" aria-live="polite">
                                <span class="password-rule" data-rule="length">6+ characters</span>
                                <span class="password-rule" data-rule="upper">Uppercase letter</span>
                                <span class="password-rule" data-rule="lower">Lowercase letter</span>
                                <span class="password-rule" data-rule="number">Number</span>
                                <span class="password-rule" data-rule="match">Passwords match</span>
                            </div>
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
                                   placeholder="09171234567"
                                   title="Philippine mobile e.g. 09171234567 or +639171234567"
                                   required
                                   value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                            <small style="color: #666; font-size: 0.78em;">09171234567 or +639171234567</small>
                        </div>
                    </div>

                    <div class="driver-fields" id="driverFields">
                        <h3>Driver Application Details</h3>
                        <div class="register-field-grid">
                            <div class="form-group">
                                <label for="license_number">License Number *</label>
                                <input type="text" id="license_number" name="license_number" value="<?php echo htmlspecialchars($_POST['license_number'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="license_code">License Code</label>
                                <input type="text" id="license_code" name="license_code" value="<?php echo htmlspecialchars($_POST['license_code'] ?? ''); ?>" placeholder="Optional license code">
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
                            <div class="form-group field-span-2">
                                <label for="address">Address *</label>
                                <textarea id="address" name="address" rows="3"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label for="profile_picture">Profile Picture</label>
                                <input type="file" id="profile_picture" name="profile_picture" accept="image/*">
                            </div>
                            <div class="form-group">
                                <label for="license_front_image">License Front *</label>
                                <input type="file" id="license_front_image" name="license_front_image" accept="image/*">
                            </div>
                            <div class="form-group field-span-2">
                                <label for="license_back_image">License Back *</label>
                                <input type="file" id="license_back_image" name="license_back_image" accept="image/*">
                            </div>
                        </div>
                    </div>

                    <div class="register-actions">
                        <button type="submit" class="submit-btn">Register</button>
                    </div>
                </form>

                <div class="register-links">
                    <div class="link-text">
                        Already have an account? <a href="login.php">Login here</a>
                    </div>

                    <div class="link-text">
                        <a href="../index.php">Back to Home</a>
                    </div>
                </div>
            </div>

            <div class="illustration-section">
                <div class="bus-animation">HZ</div>
                <div class="illustration-text">
                    <h3>Join Haven Zen</h3>
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

        document.querySelectorAll('.password-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                const field = document.getElementById(button.dataset.target);
                if (!field) return;

                const showing = field.type === 'text';
                field.type = showing ? 'password' : 'text';
                button.classList.toggle('is-visible', !showing);
                button.setAttribute('aria-pressed', String(!showing));
                button.setAttribute('aria-label', (showing ? 'Show' : 'Hide') + (field.id === 'confirm_password' ? ' password confirmation' : ' password'));
            });
        });

        syncRoleFields();
        syncPasswordRules();
    });
    </script>
</body>
</html>
