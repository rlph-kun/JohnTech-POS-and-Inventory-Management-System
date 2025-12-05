<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Check if user is logged in and needs to change password
if (!isset($_SESSION['user_id']) || !isset($_SESSION['force_password_change'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

include 'config.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password)) {
        $errors[] = 'Current password is required.';
    }
    
    if (strlen($new_password) < 6) {
        $errors[] = 'New password must be at least 6 characters long.';
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = 'New passwords do not match.';
    }
    
    if ($current_password === $new_password) {
        $errors[] = 'New password must be different from current password.';
    }
    
    if (empty($errors)) {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $stmt->bind_result($stored_password);
        $stmt->fetch();
        $stmt->close();
        
        if (!password_verify($current_password, $stored_password)) {
            $errors[] = 'Current password is incorrect.';
        } else {
            // Update password
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ?, password_changed = 1 WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_new_password, $_SESSION['user_id']);
            
            if ($update_stmt->execute()) {
                // Try to log the password change (optional - won't break if it fails)
                try {
                    require_once __DIR__ . '/includes/audit_log.php';
                    log_audit($conn, $_SESSION['user_id'], $_SESSION['role'], 'password_changed', 'User changed password after forced reset');
                } catch (Exception $e) {
                    // Log audit failed, but continue anyway
                }
                
                // Remove force password change flag
                unset($_SESSION['force_password_change']);
                
                // Redirect to appropriate dashboard
                if ($_SESSION['role'] === 'admin') {
                    header("Location: " . BASE_URL . "/pages/admin/admin_dashboard.php");
                } elseif ($_SESSION['role'] === 'cashier' && isset($_SESSION['branch'])) {
                    header("Location: " . BASE_URL . "/pages/cashier/pos_branch{$_SESSION['branch']}.php");
                }
                exit;
            } else {
                $errors[] = 'Failed to update password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - JohnTech System</title>
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
        .change-password-card {
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
    </style>
</head>
<body>
    <div class="change-password-card">
        <div class="text-center mb-4">
            <div class="bg-warning rounded-circle d-inline-flex align-items-center justify-content-center" 
                 style="width: 80px; height: 80px;">
                <i class="fas fa-exclamation-triangle fa-2x text-white"></i>
            </div>
            <h2 class="mt-3 mb-2">Password Change Required</h2>
            <p class="text-muted">Your password needs to be changed before you can continue.</p>
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

        <form method="post" id="changePasswordForm">
            <div class="mb-3">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password" class="form-control" id="current_password" name="current_password" required>
            </div>

            <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required>
                
                <!-- Password Requirements -->
                <div class="mt-2">
                    <div class="requirement" id="length-req">
                        <i class="fas fa-times text-danger"></i>
                        At least 6 characters
                    </div>
                    <div class="requirement" id="different-req">
                        <i class="fas fa-times text-danger"></i>
                        Different from current password
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                <div class="requirement" id="match-req">
                    <i class="fas fa-times text-danger"></i>
                    Passwords match
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100" id="submitBtn" disabled>
                <i class="fas fa-key me-2"></i>
                Change Password
            </button>
        </form>

        <div class="text-center mt-3">
            <small class="text-muted">
                This is a one-time requirement for security purposes.
            </small>
        </div>
    </div>

    <script>
        const currentPassword = document.getElementById('current_password');
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        
        const lengthReq = document.getElementById('length-req');
        const differentReq = document.getElementById('different-req');
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
            const differentValid = currentPassword.value !== newPassword.value && newPassword.value !== '';
            const matchValid = newPassword.value === confirmPassword.value && confirmPassword.value !== '';
            
            updateRequirement(lengthReq, lengthValid);
            updateRequirement(differentReq, differentValid);
            updateRequirement(matchReq, matchValid);
            
            submitBtn.disabled = !(lengthValid && differentValid && matchValid && currentPassword.value !== '');
        }
        
        currentPassword.addEventListener('input', validateForm);
        newPassword.addEventListener('input', validateForm);
        confirmPassword.addEventListener('input', validateForm);
    </script>
</body>
</html>
