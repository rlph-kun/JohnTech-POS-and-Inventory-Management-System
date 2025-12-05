<?php
/**
 * SECURE ADMIN RECOVERY SCRIPT
 * 
 * This script provides multiple recovery options with basic security:
 * 1. Reset default admin (username: admin) password 
 * 2. Create emergency super admin account
 * 3. Show all existing admin accounts
 * 
 * SECURITY FEATURES:
 * - Access key required
 * - Rate limiting (max 3 attempts per hour)
 * - Audit logging of recovery attempts
 * 
 * Access Key: JOHNTECH2025
 */

// Simple rate limiting
session_start();
include 'config.php';
$max_attempts = 3;
$time_window = 3600; // 1 hour

if (!isset($_SESSION['recovery_attempts'])) {
    $_SESSION['recovery_attempts'] = [];
}

// Clean old attempts
$_SESSION['recovery_attempts'] = array_filter($_SESSION['recovery_attempts'], function($time) use ($time_window) {
    return (time() - $time) < $time_window;
});

// Check if too many attempts
if (count($_SESSION['recovery_attempts']) >= $max_attempts) {
    die('
    <div style="padding: 20px; background: #ffe6e6; border: 2px solid red; margin: 20px; border-radius: 10px;">
        <h2>🚫 Access Blocked</h2>
        <p>Too many recovery attempts. Please wait 1 hour before trying again.</p>
        <p>For immediate assistance, contact the system administrator.</p>
    </div>
    ');
}

$access_granted = false;
$access_key = 'JOHNTECH2025';

// Check access key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_key'])) {
    if ($_POST['access_key'] === $access_key) {
        $access_granted = true;
    } else {
        $_SESSION['recovery_attempts'][] = time();
        $remaining_attempts = $max_attempts - count($_SESSION['recovery_attempts']);
        $error_message = "Invalid access key. $remaining_attempts attempts remaining.";
    }
}

if (!$access_granted) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Recovery Access</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .access-card {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                padding: 2rem;
                max-width: 400px;
                width: 100%;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="access-card">
            <div class="mb-4">
                <i class="fas fa-shield-alt fa-3x text-warning"></i>
                <h2 class="mt-3">Admin Recovery Access</h2>
                <p class="text-muted">Enter access key to proceed</p>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <input type="password" class="form-control" name="access_key" placeholder="Access Key" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Access Recovery System</button>
            </form>

            <div class="mt-3">
                <small class="text-muted">
                    Access key format: JOHNTECH####<br>
                    Contact administrator if you don't have the key.
                </small>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// If access granted, continue with recovery system
include 'config.php';

$message = '';
$success = false;
$action_taken = '';

// Get all admin accounts
$admin_query = "SELECT id, username, last_login FROM users WHERE role = 'admin' ORDER BY id";
$admin_result = $conn->query($admin_query);
$admin_accounts = [];
while ($row = $admin_result->fetch_assoc()) {
    $admin_accounts[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recovery_action'])) {
    
    // Log recovery attempt
    try {
        require_once __DIR__ . '/includes/audit_log.php';
        $action_details = $_POST['recovery_action'] . ' - IP: ' . $_SERVER['REMOTE_ADDR'];
        log_audit($conn, 0, 'system', 'admin_recovery_used', $action_details);
    } catch (Exception $e) {
        // Audit logging failed, but continue
    }
    
    // Option 1: Reset default admin password
    if ($_POST['recovery_action'] === 'reset_admin') {
        $new_password = 'emergency123';
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ?, password_changed = 0 WHERE username = 'admin' AND role = 'admin'");
        $stmt->bind_param("s", $hashed_password);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success = true;
            $message = "Admin password has been reset to: <strong>emergency123</strong><br>";
            $message .= "Login with: <strong>admin</strong> / <strong>emergency123</strong><br>";
            $message .= "You will be forced to change the password on first login.";
            $action_taken = 'admin_password_reset';
        } else {
            $message = "Failed to reset admin password. Admin user may not exist.";
        }
        $stmt->close();
    }
    
    // Option 2: Create emergency admin
    elseif ($_POST['recovery_action'] === 'create_emergency') {
        $emergency_username = 'emergency_admin';
        $emergency_password = 'EmergencyAccess2025!';
        $hashed_password = password_hash($emergency_password, PASSWORD_DEFAULT);
        
        // Check if emergency admin already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $emergency_username);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            // Update existing emergency admin
            $update_stmt = $conn->prepare("UPDATE users SET password = ?, password_changed = 0 WHERE username = ?");
            $update_stmt->bind_param("ss", $hashed_password, $emergency_username);
            
            if ($update_stmt->execute()) {
                $success = true;
                $message = "Emergency admin account updated!<br>";
                $message .= "Login with: <strong>emergency_admin</strong> / <strong>EmergencyAccess2025!</strong>";
                $action_taken = 'emergency_admin_updated';
            } else {
                $message = "Failed to update emergency admin account.";
            }
            $update_stmt->close();
        } else {
            // Create new emergency admin
            $create_stmt = $conn->prepare("INSERT INTO users (username, password, role, password_changed) VALUES (?, ?, 'admin', 0)");
            $create_stmt->bind_param("ss", $emergency_username, $hashed_password);
            
            if ($create_stmt->execute()) {
                $success = true;
                $message = "Emergency admin account created!<br>";
                $message .= "Login with: <strong>emergency_admin</strong> / <strong>EmergencyAccess2025!</strong>";
                $action_taken = 'emergency_admin_created';
            } else {
                $message = "Failed to create emergency admin account.";
            }
            $create_stmt->close();
        }
        $check_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Admin Recovery - JohnTech System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .recovery-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .recovery-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .btn-recovery {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            border: none;
            border-radius: 10px;
            padding: 1rem 2rem;
            color: white;
            font-weight: 600;
            margin: 0.5rem;
        }
        .btn-recovery:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .admin-list {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
        }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="recovery-container">
        <!-- Header -->
        <div class="recovery-card text-center">
            <div class="mb-4">
                <i class="fas fa-tools fa-3x text-primary"></i>
                <h1 class="mt-3 mb-2">Smart Admin Recovery</h1>
                <p class="text-muted">Emergency access and recovery system</p>
            </div>
            
            <div class="warning-box">
                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                <strong>Security Notice:</strong> This tool provides administrative access. Use only when necessary.
            </div>
        </div>

        <!-- Current Admin Accounts -->
        <div class="recovery-card">
            <h3><i class="fas fa-users me-2"></i>Current Admin Accounts</h3>
            <div class="admin-list">
                <?php if (empty($admin_accounts)): ?>
                    <p class="text-danger">⚠️ No admin accounts found in the system!</p>
                <?php else: ?>
                    <?php foreach ($admin_accounts as $admin): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                <span class="badge bg-primary ms-2">Admin</span>
                            </div>
                            <small class="text-muted">
                                Last login: <?php echo $admin['last_login'] ? date('M j, Y g:i A', strtotime($admin['last_login'])) : 'Never'; ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recovery Options -->
        <div class="recovery-card">
            <h3><i class="fas fa-wrench me-2"></i>Recovery Options</h3>
            
            <?php if ($message): ?>
                <div class="alert <?php echo $success ? 'alert-success' : 'alert-danger'; ?>">
                    <i class="fas <?php echo $success ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
                    <?php echo $message; ?>
                </div>
                
                <?php if ($success): ?>
                    <div class="text-center mt-3">
                        <a href="<?= BASE_URL ?>/index.php" class="btn btn-success">
                            <i class="fas fa-sign-in-alt me-2"></i>Go to Login Page
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-key fa-2x text-danger mb-3"></i>
                            <h5>Reset Admin Password</h5>
                            <p class="text-muted">Reset the default admin account password to a known value.</p>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="access_key" value="<?php echo $access_key; ?>">
                                <input type="hidden" name="recovery_action" value="reset_admin">
                                <button type="submit" class="btn btn-recovery" onclick="return confirm('Reset admin password?')">
                                    <i class="fas fa-redo me-2"></i>Reset Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-user-plus fa-2x text-warning mb-3"></i>
                            <h5>Create Emergency Admin</h5>
                            <p class="text-muted">Create a temporary emergency admin account with full access.</p>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="access_key" value="<?php echo $access_key; ?>">
                                <input type="hidden" name="recovery_action" value="create_emergency">
                                <button type="submit" class="btn btn-recovery" onclick="return confirm('Create emergency admin account?')">
                                    <i class="fas fa-plus me-2"></i>Create Account
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Instructions -->
        <div class="recovery-card">
            <h3><i class="fas fa-info-circle me-2"></i>Recovery Instructions</h3>
            <div class="row">
                <div class="col-md-6">
                    <h5>Option 1: Reset Admin Password</h5>
                    <ol>
                        <li>Click "Reset Password" above</li>
                        <li>Login with: <code>admin</code> / <code>emergency123</code></li>
                        <li>Change password immediately when prompted</li>
                    </ol>
                </div>
                <div class="col-md-6">
                    <h5>Option 2: Emergency Admin</h5>
                    <ol>
                        <li>Click "Create Account" above</li>
                        <li>Login with: <code>emergency_admin</code> / <code>EmergencyAccess2025!</code></li>
                        <li>Delete emergency account after recovery</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="recovery-card text-center">
            <div class="warning-box">
                <strong>Important:</strong> After recovery, consider implementing additional security measures and backup admin accounts.
            </div>
            <a href="<?= BASE_URL ?>/" class="btn btn-outline-primary">
                <i class="fas fa-home me-2"></i>Return to System
            </a>
        </div>
    </div>
</body>
</html>
