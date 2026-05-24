<?php
session_start();
require_once __DIR__ . '/../config.php';

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and retrieve user input
    $username = htmlspecialchars($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic Validation
    if (empty($username) || empty($password)) {
        $login_error = "Please fill in both username and password.";
    } else {
        // Prepare and execute SQL statement
        $stmt = $conn->prepare("SELECT user_id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password, while supporting one-time login for legacy plain-text imports.
            $passwordMatches = password_verify($password, $user['password']);
            $isLegacyPlaintext = !$passwordMatches && $password === $user['password'];

            if ($passwordMatches || $isLegacyPlaintext) {
                if ($isLegacyPlaintext) {
                    $rehashStmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    if ($rehashStmt) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $rehashStmt->bind_param("si", $hashedPassword, $user['user_id']);
                        $rehashStmt->execute();
                        $rehashStmt->close();
                    }
                }

                $role = strtolower(trim((string) $user['role']));

                if ($role === 'driver') {
                    $approvalStmt = $conn->prepare("SELECT approval_status FROM drivers WHERE user_id = ? LIMIT 1");
                    if ($approvalStmt) {
                        $approvalStmt->bind_param("i", $user['user_id']);
                        $approvalStmt->execute();
                        $approvalResult = $approvalStmt->get_result();
                        $approvalRow = $approvalResult ? $approvalResult->fetch_assoc() : null;
                        $approvalStmt->close();

                        $approvalStatus = strtolower(trim((string) ($approvalRow['approval_status'] ?? 'approved')));
                        if ($approvalStatus === 'pending') {
                            $login_error = "Your driver account is still pending superadmin approval.";
                            logSystemEvent($conn, $user['user_id'], 'LOGIN_BLOCKED', 'Pending driver approval for user: ' . $user['username']);
                            $conn->close();
                            goto render_login_page;
                        }
                        if ($approvalStatus === 'rejected') {
                            $login_error = "Your driver account application was not approved. Please contact the administrator.";
                            logSystemEvent($conn, $user['user_id'], 'LOGIN_BLOCKED', 'Rejected driver approval for user: ' . $user['username']);
                            $conn->close();
                            goto render_login_page;
                        }
                    }
                }

                // Successful login
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $role;

                // Load full_name from role-specific profile tables
                $full_name = null;
                if ($role === 'admin') {
                    $profileStmt = $conn->prepare("SELECT full_name FROM admins WHERE user_id = ?");
                } elseif ($role === 'driver') {
                    $profileStmt = $conn->prepare("SELECT full_name FROM drivers WHERE user_id = ?");
                } else {
                    $profileStmt = $conn->prepare("SELECT full_name FROM customers WHERE user_id = ?");
                }

                if ($profileStmt) {
                    $profileStmt->bind_param("i", $user['user_id']);
                    $profileStmt->execute();
                    $profileResult = $profileStmt->get_result();
                    if ($profileResult && $profileResult->num_rows === 1) {
                        $profileRow = $profileResult->fetch_assoc();
                        $full_name = $profileRow['full_name'] ?? null;
                    }
                    $profileStmt->close();
                }

                $_SESSION['full_name'] = $full_name ?: $user['username'];
                
                // Log successful login
                logSystemEvent($conn, $user['user_id'], 'LOGIN', 'Successful login attempt for user: ' . $user['username']);

                // Update last_login in role-specific profile table
                if ($role === 'admin') {
                    $updateStmt = $conn->prepare("UPDATE admins SET last_login = NOW() WHERE user_id = ?");
                } elseif ($role === 'driver') {
                    $updateStmt = $conn->prepare("UPDATE drivers SET last_login = NOW() WHERE user_id = ?");
                } else {
                    // Treat any other role as passenger/customer
                    $updateStmt = $conn->prepare("UPDATE customers SET last_login = NOW() WHERE user_id = ?");
                }

                if ($updateStmt) {
                    $updateStmt->bind_param("i", $user['user_id']);
                    $updateStmt->execute();
                    $updateStmt->close();
                }

                // Redirect based on role
                if (isset($user['role'])) {
                    switch ($role) {
                        case 'admin':
                            header("Location: ../admin/index.php");
                            break;
                        case 'driver':
                            header("Location: ../drivers/index.php");
                            break;
                        case 'passenger':
                            header("Location: ../users/index.php");
                            break;
                        default:
                            header("Location: ../users/index.php");
                    }
                    exit();
                }
                
                // Fallback if no role is set
                header("Location: ../users/index.php");
                exit();
            } else {
                $login_error = "Invalid username or password.";
                // Log failed login attempt
                logSystemEvent($conn, null, 'LOGIN_FAILED', 'Failed login attempt for username: ' . htmlspecialchars($username));
            }
        } else {
            $login_error = "Invalid username or password.";
        }
        
        $stmt->close();
    }
}

$conn->close();
render_login_page:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passenger Login - Haven Zen</title>
    <link rel="stylesheet" href="loginsection.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header-text">
            <h1>🚌 <span>Haven Zen Transportation</span></h1>
            <p>Real-Time Vehicle Tracking and Booking System</p>
        </div>

        <div class="card-container">
            <div class="form-section">
                <h2>Login to Your Account</h2>
                
                <?php if ($login_error): ?>
                    <div class="error-message"><?php echo $login_error; ?></div>
                <?php endif; ?>
                
                <form action="login.php" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input type="password" id="password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="submit-btn">Login</button>
                </form>

                <div class="link-text">
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>

                <div class="link-text">
                    Don't have an account? <a href="register.php">Register here</a>
                </div>
                
                <div class="link-text">
                    <a href="../index.php">← Back to Home</a>
                </div>
            </div>

            <div class="illustration-section">
                <div class="bus-animation">🚌</div>
                <div class="illustration-text">
                    <h3>Welcome Back!</h3>
                    <p>Login to track your rides and manage your bookings with Haven Zen Transportation System.</p>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const passwordInput = document.getElementById('password');
        const toggleButton = document.getElementById('togglePassword');

        if (!passwordInput || !toggleButton) {
            return;
        }

        toggleButton.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            toggleButton.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            toggleButton.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            toggleButton.innerHTML = isHidden
                ? '<i class="fas fa-eye-slash"></i>'
                : '<i class="fas fa-eye"></i>';
        });
    });
    </script>
</body>
</html>
