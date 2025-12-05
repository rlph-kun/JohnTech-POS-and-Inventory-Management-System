<?php
/**
 * ============================================================================
 * JohnTech System - Login Page
 * ============================================================================
 * 
 * This file handles user authentication and login functionality.
 * It includes session management, user verification, and role-based redirects.
 * 
 * Features:
 * - Session-based authentication
 * - Password verification
 * - Force password change for first-time login
 * - Role-based redirection (Admin/Cashier)
 * - Audit logging for cashier logins
 * 
 * ============================================================================
 */

// ============================================================================
// SESSION & CONFIGURATION
// ============================================================================

session_start();
include 'config.php';

// ============================================================================
// SESSION CHECK - Redirect if already logged in
// ============================================================================

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    // Admin users are redirected to admin dashboard
    if ($_SESSION['role'] === 'admin') {
        header("Location: " . BASE_URL . "/pages/admin/admin_dashboard.php");
        exit();
    } 
    // Cashier users are redirected to starting cash page if branch is set
    elseif ($_SESSION['role'] === 'cashier' && isset($_SESSION['branch'])) {
        header("Location: " . BASE_URL . "/pages/cashier/starting_cash.php");
        exit();
    }
}

// ============================================================================
// LOGIN FORM PROCESSING
// ============================================================================

$error = ''; // Initialize error message variable
$success_message = ''; // Initialize success message variable

// Check if password was reset successfully
if (isset($_SESSION['password_reset_success'])) {
    $success_message = 'Password reset successfully! You can now log in with your new password.';
    unset($_SESSION['password_reset_success']);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form input values
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Query database to find user
    $stmt = $conn->prepare("SELECT id, password, role, password_changed FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    // Check if user exists
    if ($stmt->num_rows === 1) {
        // Bind result variables
        $stmt->bind_result($user_id, $hashed_password, $role, $password_changed);
        $stmt->fetch();

        // Verify password
        if (password_verify($password, $hashed_password)) {
            // Set session variables
            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = $role;
            
            // Update last login timestamp
            $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->bind_param("i", $user_id);
            $update_stmt->execute();
            
            // Check if password change is required (first-time login)
            if (!$password_changed) {
                $_SESSION['force_password_change'] = true;
                header("Location: " . BASE_URL . "/change_password.php");
                exit;
            }

            // ====================================================================
            // AUDIT LOGGING
            // ====================================================================
            require_once __DIR__ . '/includes/audit_log.php';
            
            // Log login action for cashiers
            if ($role === 'cashier') {
                log_audit($conn, $user_id, $role, 'login', 'User logged in successfully');
            }

            // ====================================================================
            // ROLE-BASED REDIRECTION
            // ====================================================================
            
            if ($role === 'cashier') {
                // Get branch assignment for cashier
                $stmt2 = $conn->prepare("SELECT branch_id FROM cashier_profiles WHERE user_id = ?");
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();
                $stmt2->store_result();
                
                if ($stmt2->num_rows === 1) {
                    $stmt2->bind_result($branch_id);
                    $stmt2->fetch();
                    $_SESSION['branch'] = $branch_id;
                    header("Location: " . BASE_URL . "/pages/cashier/starting_cash.php");
                    exit;
                } else {
                    $error = "Cashier profile not found.";
                }
            } 
            elseif ($role === 'admin') {
                // Redirect admin to dashboard
                header("Location: " . BASE_URL . "/pages/admin/admin_dashboard.php");
                exit;
            } 
            else {
                $error = "Unknown user role.";
            }
        } else {
            // Invalid password
            $error = "Invalid password.";
        }
    } else {
        // User not found
        $error = "User not found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ===================================================================== -->
    <!-- META TAGS & PAGE TITLE -->
    <!-- ===================================================================== -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - JohnTech System</title>

    <!-- ===================================================================== -->
    <!-- STYLESHEETS -->
    <!-- ===================================================================== -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
    
    <!-- Preload logo image to prevent flashing during page load -->
    <link rel="preload" href="assets/images/johntech.jpg" as="image">

    <!-- ===================================================================== -->
    <!-- CUSTOM STYLES -->
    <!-- ===================================================================== -->
    <style>
        /* ================================================================== */
        /* CSS VARIABLES - Design System Tokens */
        /* ================================================================== */
        :root {
            /* Color Palette */
            --primary-color: #1565c0;
            --primary-dark: #0d47a1;
            --primary-light: #42a5f5;
            --secondary-color: #26a69a;
            --accent-color: #ff7043;
            --success-color: #4caf50;
            --warning-color: #ff9800;
            --error-color: #f44336;
            
            /* Dark Mode Colors */
            --dark-bg: #1a1d23;
            --dark-surface: #23272b;
            
            /* Light Mode Colors */
            --light-bg: #f8fafc;
            --light-surface: #ffffff;
            
            /* Text Colors */
            --text-primary: #1a202c;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            
            /* Border Colors */
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            
            /* Shadow System */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07), 0 2px 4px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15), 0 5px 10px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.1), 0 8px 16px rgba(0, 0, 0, 0.06);
            
            /* Border Radius System */
            --border-radius-sm: 6px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 24px;
            
            /* Transition Timing */
            --transition-fast: 0.15s ease;
            --transition-normal: 0.3s ease;
            --transition-slow: 0.5s ease;
        }

        /* ================================================================== */
        /* BASE STYLES */
        /* ================================================================== */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--light-bg);
            overflow-x: hidden;
        }
        
        body {
            min-height: 100vh;
        }
        
        /* ================================================================== */
        /* LAYOUT - Split Container */
        /* ================================================================== */
        .split-container {
            display: flex;
            min-height: 100vh;
            height: 100vh;
            width: 100vw;
            position: absolute;
            top: 0;
            left: 0;
        }
        
        /* ================================================================== */
        /* LEFT PANEL - Branding & Welcome Section */
        /* ================================================================== */
        .left-panel {
            flex: 1;
            background: linear-gradient(135deg, rgba(21, 101, 192, 0.9) 0%, rgba(13, 71, 161, 0.9) 100%), 
                        url('assets/images/background.jpg') no-repeat center center;
            background-size: cover;
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        
        /* Gradient overlay for better text readability */
        .left-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, 
                rgba(21, 101, 192, 0.1) 0%, 
                rgba(21, 101, 192, 0.3) 50%, 
                rgba(13, 71, 161, 0.4) 100%);
            backdrop-filter: blur(1px);
        }
        
        /* Company Logo */
        .logo-img {
            position: absolute;
            top: 2rem;
            left: 2rem;
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.9);
            box-shadow: var(--shadow-lg);
            display: block;
            margin: 0;
            z-index: 2;
            transition: all var(--transition-normal);
        }
        
        .logo-img:hover {
            transform: scale(1.05);
            box-shadow: var(--shadow-xl);
        }
        
        /* Welcome Text */
        .welcome-text {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1.1;
            text-align: center;
            margin: 0;
            z-index: 2;
            position: relative;
            text-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.9) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Feature Highlights at Bottom */
        .feature-highlights {
            position: absolute;
            bottom: 2rem;
            left: 10%;
            right: 10%;
            z-index: 2;
            display: flex;
            gap: 2rem;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .feature-item i {
            margin-right: 0.5rem;
            font-size: 1.1em;
            color: var(--primary-light);
        }
        
        /* Floating Animation Shapes */
        .floating-shapes {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }
        
        .shape {
            position: absolute;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }
        
        .shape:nth-child(1) {
            top: 20%;
            left: 10%;
            animation-delay: -2s;
        }
        
        .shape:nth-child(2) {
            top: 60%;
            left: 80%;
            animation-delay: -4s;
        }
        
        .shape:nth-child(3) {
            top: 40%;
            left: 60%;
            animation-delay: -1s;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
        
        /* ================================================================== */
        /* RIGHT PANEL - Login Form Section */
        /* ================================================================== */
        .right-panel {
            flex: 1;
            background: var(--light-bg);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem;
            position: relative;
        }
        
        /* Decorative circle element */
        .right-panel::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            border-radius: 50%;
            opacity: 0.05;
            transform: translate(50%, -50%);
        }
        
        /* Login Form Container */
        .login-form {
            width: 100%;
            max-width: 480px;
            background: var(--light-surface);
            border-radius: var(--border-radius-xl);
            padding: 3rem 2.5rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-light);
            position: relative;
            z-index: 1;
        }
        
        /* Top accent bar */
        .login-form::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--primary-light) 100%);
            border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
        }
        
        /* Form Heading */
        .login-form h2 {
            font-size: 2.25rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .login-form p {
            color: var(--text-secondary);
            margin-bottom: 2rem;
            font-size: 1.1rem;
            font-weight: 400;
        }
        
        /* ================================================================== */
        /* FORM ELEMENTS */
        /* ================================================================== */
        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border-radius: var(--border-radius-sm);
            border: 1px solid var(--border-color);
            font-size: 1rem;
            margin-bottom: 1.5rem;
            padding: 0.875rem 1rem;
            transition: all var(--transition-fast);
            background: var(--light-surface);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(21, 101, 192, 0.1);
            outline: none;
        }
        
        .form-check-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Forgot Password Link */
        .forgot-link {
            float: right;
            font-size: 0.9rem;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: color var(--transition-fast);
        }
        
        .forgot-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        /* ================================================================== */
        /* BUTTONS */
        /* ================================================================== */
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: var(--border-radius-sm);
            padding: 0.875rem;
            font-size: 1rem;
            width: 100%;
            margin-bottom: 1rem;
            transition: all var(--transition-normal);
            box-shadow: 0 4px 12px rgba(21, 101, 192, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        /* Ripple effect on hover */
        .btn-login::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transition: all 0.4s ease;
            transform: translate(-50%, -50%);
        }
        
        .btn-login:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-login:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(21, 101, 192, 0.4);
        }
        
        .btn-cancel {
            background: transparent;
            color: var(--text-secondary);
            font-weight: 600;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            padding: 0.875rem;
            font-size: 1rem;
            width: 100%;
            transition: all var(--transition-normal);
        }
        
        .btn-cancel:hover {
            background: var(--light-bg);
            color: var(--text-primary);
            border-color: var(--text-secondary);
            transform: translateY(-1px);
        }
        
        /* ================================================================== */
        /* ALERT MESSAGES */
        /* ================================================================== */
        .alert {
            border-radius: var(--border-radius-md);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border: none;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.1) 0%, rgba(244, 67, 54, 0.05) 100%);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }
        
        /* ================================================================== */
        /* RESPONSIVE DESIGN - Tablet and Below */
        /* ================================================================== */
        @media (max-width: 992px) {
            .split-container {
                flex-direction: column;
            }
            
            .left-panel, .right-panel {
                flex: unset;
                width: 100%;
            }
            
            .left-panel {
                min-height: 50vh;
                background-attachment: scroll;
            }
            
            .welcome-text {
                font-size: 2.5rem;
                text-align: center;
                margin: 0 5%;
            }
            
            .feature-highlights {
                display: none;
            }
            
            .login-form {
                padding: 2rem 1.5rem;
                margin-top: -2rem;
                border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
            }
        }
        
        /* ================================================================== */
        /* RESPONSIVE DESIGN - Mobile */
        /* ================================================================== */
        @media (max-width: 576px) {
            .right-panel {
                padding: 1rem;
            }
            
            .login-form {
                padding: 1.5rem 1rem;
            }
            
            .login-form h2 {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- ===================================================================== -->
    <!-- MAIN CONTAINER -->
    <!-- ===================================================================== -->
    <div class="split-container">
        
        <!-- ================================================================= -->
        <!-- LEFT PANEL - Branding Section -->
        <!-- ================================================================= -->
        <div class="left-panel">
            <!-- Animated Background Shapes -->
            <div class="floating-shapes">
                <div class="shape">
                    <i class="fas fa-cog fa-3x"></i>
                </div>
                <div class="shape">
                    <i class="fas fa-chart-line fa-2x"></i>
                </div>
                <div class="shape">
                    <i class="fas fa-users fa-2x"></i>
                </div>
            </div>
            
            <!-- Company Logo -->
            <img src="assets/images/johntech.jpg" alt="JohnTech Logo" class="logo-img mb-4">
            
            <!-- Welcome Text -->
            <div class="welcome-text">JohnTech<br>Management System</div>
            
            <!-- Feature Highlights -->
            <div class="feature-highlights">
                <div class="feature-item">
                    <i class="fas fa-shield-alt"></i>
                    Secure Access
                </div>
                <div class="feature-item">
                    <i class="fas fa-chart-bar"></i>
                    Real-time Analytics
                </div>
                <div class="feature-item">
                    <i class="fas fa-user"></i>
                    User Friendly
                </div>
            </div>
        </div>
        
        <!-- ================================================================= -->
        <!-- RIGHT PANEL - Login Form Section -->
        <!-- ================================================================= -->
        <div class="right-panel">
            <form class="login-form" method="post" onsubmit="return validateForm()">
                <h2>Welcome Back</h2>
                <p>Please sign in to your account to continue</p>
                
                <!-- Error Message Display -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Success Message Display -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Username Field -->
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-control" 
                           placeholder="Enter your username" required>
                    <div id="username-error" class="text-danger small mb-2" style="display:none;"></div>
                </div>
                
                <!-- Password Field -->
                <div class="mb-2">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" 
                           placeholder="Enter your password" required>
                    <div id="password-error" class="text-danger small mb-2" style="display:none;"></div>
                </div>
                
                <!-- Forgot Password Link -->
                <div class="d-flex justify-content-end align-items-center mb-4">
                    <a href="forgot_password.php" class="forgot-link">Forgot Password? (Admin Only)</a>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>
                    Sign In
                </button>
                
                <!-- Cancel Button -->
                <button type="button" class="btn btn-cancel" onclick="window.location.href='index.php'">
                    <i class="fas fa-times me-2"></i>
                    Cancel
                </button>
            </form>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- JAVASCRIPT LIBRARIES -->
    <!-- ===================================================================== -->
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>

    <!-- ===================================================================== -->
    <!-- FORM VALIDATION SCRIPT -->
    <!-- ===================================================================== -->
    <script>
    /**
     * Client-side form validation
     * Validates username and password fields before form submission
     * 
     * @returns {boolean} - Returns true if form is valid, false otherwise
     */
    function validateForm() {
        let valid = true;
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value.trim();
        const usernameError = document.getElementById('username-error');
        const passwordError = document.getElementById('password-error');

        // Reset error messages
        usernameError.style.display = 'none';
        passwordError.style.display = 'none';
        usernameError.textContent = '';
        passwordError.textContent = '';

        // Validate username
        if (username === '') {
            usernameError.textContent = 'Please enter your username.';
            usernameError.style.display = 'block';
            valid = false;
        }
        
        // Validate password
        if (password === '') {
            passwordError.textContent = 'Please enter your password.';
            passwordError.style.display = 'block';
            valid = false;
        }
        
        return valid;
    }

    /**
     * Clear error messages when user starts typing
     * Username field event listener
     */
    document.getElementById('username').addEventListener('input', function() {
        document.getElementById('username-error').style.display = 'none';
    });
    
    /**
     * Clear error messages when user starts typing
     * Password field event listener
     */
    document.getElementById('password').addEventListener('input', function() {
        document.getElementById('password-error').style.display = 'none';
    });
    </script>
</body>
</html>
