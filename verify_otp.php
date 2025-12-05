<?php
/**
 * OTP Verification Page
 * 
 * This page verifies the OTP code sent to the admin's email.
 * After successful verification, redirects to reset password page.
 * 
 * @author JohnTech Development Team
 * @version 1.0
 */

session_start();
include 'config.php';

// Redirect if not coming from forgot password flow
if (!isset($_SESSION['otp_admin_id']) || !isset($_SESSION['otp_email'])) {
    header("Location: " . BASE_URL . "/forgot_password.php");
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$errors = [];
$admin_id = $_SESSION['otp_admin_id'];
$email = $_SESSION['otp_email'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otp_code = trim($_POST['otp_code']);
    
    // Validate OTP format
    if (empty($otp_code)) {
        $errors[] = 'OTP code is required.';
    } elseif (!preg_match('/^\d{6}$/', $otp_code)) {
        $errors[] = 'OTP code must be exactly 6 digits.';
    }
    
    if (empty($errors)) {
        // Verify OTP against database
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE id = ? AND otp_code = ? AND otp_expires_at > NOW() AND role = 'admin'");
        $stmt->bind_param("is", $admin_id, $otp_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($admin = $result->fetch_assoc()) {
            // OTP is valid - set session flag for password reset
            $_SESSION['otp_verified'] = true;
            $_SESSION['reset_admin_id'] = $admin_id;
            
            // Redirect to reset password page
            header("Location: " . BASE_URL . "/reset_password.php");
            exit;
        } else {
            // Check if OTP exists but expired
            $stmt2 = $conn->prepare("SELECT otp_code, otp_expires_at FROM users WHERE id = ? AND otp_code = ?");
            $stmt2->bind_param("is", $admin_id, $otp_code);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            
            if ($result2->num_rows > 0) {
                $errors[] = 'OTP code has expired. Please request a new OTP code.';
            } else {
                $errors[] = 'Invalid OTP code. Please check and try again.';
            }
        }
    }
}

// Handle resend OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    // Include email configuration
    require_once __DIR__ . '/includes/email_config.php';
    
    // Generate new OTP
    $otp_code = str_pad(strval(random_int(0, 999999)), 6, '0', STR_PAD_LEFT);
    $otp_expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    
    // Update OTP in database
    $update_stmt = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
    $update_stmt->bind_param("ssi", $otp_code, $otp_expires_at, $admin_id);
    
    if ($update_stmt->execute()) {
        if (sendOTPEmail($email, $otp_code)) {
            $success_message = 'A new OTP code has been sent to your email address.';
        } else {
            // Clear OTP if email failed
            $clear_stmt = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
            $clear_stmt->bind_param("i", $admin_id);
            $clear_stmt->execute();
            
            $errors[] = 'Failed to send email. Please check your email configuration or try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - JohnTech System</title>
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
        .verify-otp-card {
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
            text-align: center;
            font-size: 1.5rem;
            letter-spacing: 0.5rem;
            font-weight: bold;
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
        .btn-outline-secondary {
            border-radius: 10px;
            padding: 0.75rem 2rem;
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
        .otp-input-wrapper {
            position: relative;
        }
        .otp-input-wrapper input {
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="verify-otp-card">
        <div class="text-center mb-4">
            <div class="bg-info rounded-circle d-inline-flex align-items-center justify-content-center" 
                 style="width: 80px; height: 80px;">
                <i class="fas fa-shield-alt fa-2x text-white"></i>
            </div>
            <h2 class="mt-3 mb-2">Verify OTP Code</h2>
            <p class="text-muted">Enter the 6-digit code sent to your email</p>
        </div>

        <div class="info-box">
            <i class="fas fa-envelope text-primary"></i>
            <strong>Email:</strong> <?php echo htmlspecialchars($email); ?><br>
            <small class="text-muted">The OTP code expires in 5 minutes.</small>
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

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <form method="post" id="verifyOTPForm">
            <div class="mb-3">
                <label for="otp_code" class="form-label">Enter OTP Code</label>
                <div class="otp-input-wrapper">
                    <input type="text" 
                           class="form-control" 
                           id="otp_code" 
                           name="otp_code" 
                           maxlength="6" 
                           pattern="[0-9]{6}"
                           placeholder="000000"
                           required
                           autofocus
                           autocomplete="off">
                </div>
                <small class="text-muted">Enter the 6-digit code you received via email.</small>
            </div>

            <button type="submit" name="verify_otp" class="btn btn-primary w-100 mb-2">
                <i class="fas fa-check me-2"></i>
                Verify OTP
            </button>
        </form>

        <form method="post" class="mt-3">
            <button type="submit" name="resend_otp" class="btn btn-outline-secondary w-100">
                <i class="fas fa-redo me-2"></i>
                Resend OTP Code
            </button>
        </form>

        <div class="text-center mt-4">
            <a href="forgot_password.php" class="text-muted text-decoration-none">
                <i class="fas fa-arrow-left me-1"></i>
                Back to Forgot Password
            </a>
        </div>
    </div>

    <script>
        // Auto-format OTP input (numbers only)
        document.getElementById('otp_code').addEventListener('input', function(e) {
            // Remove non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
            
            // Limit to 6 digits
            if (this.value.length > 6) {
                this.value = this.value.slice(0, 6);
            }
        });

        // Auto-submit when 6 digits are entered
        document.getElementById('otp_code').addEventListener('input', function(e) {
            if (this.value.length === 6) {
                // Optional: auto-submit after a short delay
                // setTimeout(() => {
                //     document.getElementById('verifyOTPForm').submit();
                // }, 500);
            }
        });
    </script>
</body>
</html>

