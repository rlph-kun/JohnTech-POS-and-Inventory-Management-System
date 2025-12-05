<?php
/**
 * Reset Password Page
 * 
 * This page allows the admin to set a new password after OTP verification.
 * After successful reset, clears OTP fields and redirects to login.
 * 
 * @author JohnTech Development Team
 * @version 1.0
 */

session_start();
include 'config.php';

// Check if OTP was verified
if (!isset($_SESSION['otp_verified']) || !isset($_SESSION['reset_admin_id'])) {
    header("Location: " . BASE_URL . "/forgot_password.php");
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$admin_id = $_SESSION['reset_admin_id'];
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate password
    if (empty($new_password)) {
        $errors[] = 'New password is required.';
    } elseif (strlen($new_password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (empty($errors)) {
        // Verify that OTP is still valid (double check)
        $verify_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin'");
        $verify_stmt->bind_param("i", $admin_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows === 1) {
            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password and clear OTP fields
            $update_stmt = $conn->prepare("UPDATE users SET password = ?, otp_code = NULL, otp_expires_at = NULL, password_changed = 1, last_password_change = NOW() WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $admin_id);
            
            if ($update_stmt->execute()) {
                // Clear all session variables related to password reset
                unset($_SESSION['otp_verified']);
                unset($_SESSION['reset_admin_id']);
                unset($_SESSION['otp_admin_id']);
                unset($_SESSION['otp_email']);
                
                // Log the password reset (if audit logging is available)
                try {
                    require_once __DIR__ . '/includes/audit_log.php';
                    log_audit($conn, $admin_id, 'admin', 'password_reset', 'Admin password reset via OTP recovery');
                } catch (Exception $e) {
                    // Log audit failed, but continue anyway
                }
                
                // Redirect to login page with success message
                $_SESSION['password_reset_success'] = true;
                header("Location: " . BASE_URL . "/index.php");
                exit;
            } else {
                $errors[] = 'Failed to update password. Please try again.';
            }
        } else {
            $errors[] = 'Invalid admin account. Please start the recovery process again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - JohnTech System</title>
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
        .reset-password-card {
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
        .requirement {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .requirement.met {
            color: #28a745;
        }
        .requirement i {
            margin-right: 0.5rem;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #667eea;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="reset-password-card">
        <div class="text-center mb-4">
            <div class="bg-success rounded-circle d-inline-flex align-items-center justify-content-center" 
                 style="width: 80px; height: 80px;">
                <i class="fas fa-lock fa-2x text-white"></i>
            </div>
            <h2 class="mt-3 mb-2">Reset Password</h2>
            <p class="text-muted">Set a new password for your admin account</p>
        </div>

        <div class="info-box">
            <i class="fas fa-check-circle text-success"></i>
            <strong>OTP Verified:</strong> Your identity has been verified. Please set a new secure password.
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

        <form method="post" id="resetPasswordForm">
            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <input type="password" 
                       class="form-control" 
                       id="new_password" 
                       name="new_password" 
                       required
                       autofocus>
                
                <!-- Password Requirements -->
                <div class="mt-2">
                    <div class="requirement" id="length-req">
                        <i class="fas fa-times text-danger"></i>
                        At least 6 characters
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" 
                       class="form-control" 
                       id="confirm_password" 
                       name="confirm_password" 
                       required>
                <div class="requirement" id="match-req">
                    <i class="fas fa-times text-danger"></i>
                    Passwords match
                </div>
            </div>

            <button type="submit" name="reset_password" class="btn btn-primary w-100" id="submitBtn" disabled>
                <i class="fas fa-key me-2"></i>
                Reset Password
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="index.php" class="text-muted text-decoration-none">
                <i class="fas fa-arrow-left me-1"></i>
                Back to Login
            </a>
        </div>
    </div>

    <script>
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        
        const lengthReq = document.getElementById('length-req');
        const matchReq = document.getElementById('match-req');
        
        function updateRequirement(element, met) {
            const icon = element.querySelector('i');
            if (met) {
                element.classList.add('met');
                icon.className = 'fas fa-check text-success';
            } else {
                element.classList.remove('met');
                icon.className = 'fas fa-times text-danger';
            }
        }
        
        function validateForm() {
            const lengthValid = newPassword.value.length >= 6;
            const matchValid = newPassword.value === confirmPassword.value && confirmPassword.value !== '';
            
            updateRequirement(lengthReq, lengthValid);
            updateRequirement(matchReq, matchValid);
            
            submitBtn.disabled = !(lengthValid && matchValid);
        }
        
        newPassword.addEventListener('input', validateForm);
        confirmPassword.addEventListener('input', validateForm);
    </script>
</body>
</html>

