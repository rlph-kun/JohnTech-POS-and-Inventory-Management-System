<?php
/**
 * Admin Forgot Password Page
 * 
 * This page allows admin users (only) to request a password reset via OTP email.
 * Cashiers cannot use this page - they must have passwords reset by admin.
 * 
 * @author JohnTech Development Team
 * @version 1.0
 */

session_start();
include 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: " . BASE_URL . "/pages/admin/admin_dashboard.php");
        exit;
    } else {
        header("Location: " . BASE_URL . "/index.php");
        exit;
    }
}

$errors = [];
$success = '';

// Include email configuration
require_once __DIR__ . '/includes/email_config.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_otp'])) {
    $email = trim($_POST['email']);
    
    // Validate email
    if (empty($email)) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($errors)) {
        // Check if email exists and belongs to an admin (not a cashier)
        $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE email = ? AND role = 'admin'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($admin = $result->fetch_assoc()) {
            // Generate 6-digit OTP
            $otp_code = str_pad(strval(random_int(0, 999999)), 6, '0', STR_PAD_LEFT);
            
            // Set expiration time (5 minutes from now)
            $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            // Save OTP to database
            $update_stmt = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
            $update_stmt->bind_param("ssi", $otp_code, $otp_expires_at, $admin['id']);
            
            if ($update_stmt->execute()) {
                // Send OTP via email
                if (sendOTPEmail($email, $otp_code)) {
                    $success = 'An OTP code has been sent to your email address. Please check your inbox and enter the code on the next page.';
                    
                    // Store admin ID in session for OTP verification
                    $_SESSION['otp_admin_id'] = $admin['id'];
                    $_SESSION['otp_email'] = $email;
                    
                    // Redirect to verify OTP page after 2 seconds
                    header("refresh:2;url=" . BASE_URL . "/verify_otp.php");
                } else {
                    // Clear OTP if email failed
                    $clear_stmt = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
                    $clear_stmt->bind_param("i", $admin['id']);
                    $clear_stmt->execute();
                    
                    // Get detailed error message
                    global $last_email_error;
                    $error_msg = 'Failed to send email. ';
                    if (isset($last_email_error)) {
                        $error_msg .= 'Error details: ' . htmlspecialchars($last_email_error);
                    } else {
                        $error_msg .= 'Please check your email configuration, PHP error logs, or try again later.';
                    }
                    $errors[] = $error_msg;
                }
            } else {
                $errors[] = 'Failed to generate OTP. Please try again.';
            }
        } else {
            // Don't reveal if email exists or not (security best practice)
            $errors[] = 'If this email is registered as an admin account, you will receive an OTP code shortly.';
            
            // For security, we still wait a bit (prevent email enumeration)
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Admin Only - JohnTech System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }
        .forgot-password-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 2rem;
            max-width: 500px;
            width: 100%;
        }
        .form-control {
            border-radius: 10px;
            border: 1px solid #e0e6ed;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #667eea;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="forgot-password-card">
        <div class="text-center mb-4">
            <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center" 
                 style="width: 80px; height: 80px;">
                <i class="fas fa-key fa-2x text-white"></i>
            </div>
            <h2 class="mt-3 mb-2">Forgot Password</h2>
            <p class="text-muted">Admin Account Recovery</p>
        </div>

        <div class="info-box">
            <i class="fas fa-info-circle text-primary"></i>
            <strong>Admin Only:</strong> This page is for admin accounts only. Cashiers must contact an administrator to reset their password.
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <p class="mb-0 mt-2"><small>Redirecting to OTP verification page...</small></p>
            </div>
        <?php else: ?>
            <form method="post" id="forgotPasswordForm">
                <div class="mb-3">
                    <label for="email" class="form-label">Registered Admin Email</label>
                    <input type="email" 
                           class="form-control" 
                           id="email" 
                           name="email" 
                           placeholder="admin@example.com" 
                           required
                           autofocus>
                    <small class="text-muted">Enter the email address registered with your admin account.</small>
                </div>

                <button type="submit" name="request_otp" class="btn btn-primary w-100">
                    <i class="fas fa-paper-plane me-2"></i>
                    Send OTP Code
                </button>
            </form>

            <div class="warning-box">
                <i class="fas fa-exclamation-triangle text-warning"></i>
                <strong>Security Note:</strong> The OTP code will be sent to your registered email and will expire in 5 minutes.
            </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="index.php" class="text-muted text-decoration-none">
                <i class="fas fa-arrow-left me-1"></i>
                Back to Login
            </a>
        </div>
    </div>
</body>
</html>

