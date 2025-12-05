<?php
/*
======================================================================
  Page: Admin Management
  Description: Manage admin accounts — create admins and reset
               admin passwords (forces change on next login).
  Notes:
  - Maintains existing UI and behavior.
  - Adds structured section headers for clarity.
======================================================================
*/

include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);
include '../../includes/admin_sidebar.php';

$errors = [];
$success = '';

// ====================================================================
// Reset admin password
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_admin_password'])) {
    $admin_id = intval($_POST['admin_id']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password and mark as needs change
        $stmt = $conn->prepare("UPDATE users SET password = ?, password_changed = 0 WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("si", $hashed_password, $admin_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Log the password reset
            require_once __DIR__ . '/../../includes/audit_log.php';
            log_audit($conn, $_SESSION['user_id'], 'admin', 'password_reset', "Reset password for admin ID: $admin_id");
            
            $success = 'Admin password reset successfully! The admin will be required to change the password on next login.';
        } else {
            $errors[] = 'Failed to reset password. Please try again.';
        }
    }
}

// ====================================================================
// Create new admin account
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } else {
        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $errors[] = 'Username already exists.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare("INSERT INTO users (username, password, role, password_changed) VALUES (?, ?, 'admin', 0)");
            $stmt2->bind_param('ss', $username, $hashed_password);
            
            if ($stmt2->execute()) {
                // Log the admin creation
                require_once __DIR__ . '/../../includes/audit_log.php';
                log_audit($conn, $_SESSION['user_id'], 'admin', 'admin_created', "Created new admin account: $username");
                
                $success = 'New admin account created successfully!';
            } else {
                $errors[] = 'Failed to create admin account. Please try again.';
            }
        }
    }
}

// ====================================================================
// Get all admin users
// ====================================================================
$admin_query = "SELECT id, username, last_login, password_changed FROM users WHERE role = 'admin' ORDER BY username";
$admin_result = $conn->query($admin_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - JohnTech System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/styles.css">
</head>
<body>
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-user-shield"></i> Admin Management</h2>
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
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Existing Admin Accounts -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-users-cog"></i> Existing Admin Accounts</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Username</th>
                                            <th>Last Login</th>
                                            <th>Password Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($admin = $admin_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $admin['id']; ?></td>
                                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                            <td>
                                                <?php 
                                                echo $admin['last_login'] ? 
                                                    date('M j, Y g:i A', strtotime($admin['last_login'])) : 
                                                    '<span class="text-muted">Never</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($admin['password_changed']): ?>
                                                    <span class="badge bg-success">Changed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Needs Change</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" 
                                                        data-bs-target="#resetPasswordModal" 
                                                        data-admin-id="<?php echo $admin['id']; ?>"
                                                        data-admin-username="<?php echo htmlspecialchars($admin['username']); ?>">
                                                    <i class="fas fa-key"></i> Reset Password
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Create New Admin -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-user-plus"></i> Create New Admin</h5>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           minlength="8" required>
                                    <div class="form-text">Minimum 8 characters</div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password</label>
                                    <input type="password" class="form-control" id="confirm_password" 
                                           name="confirm_password" required>
                                </div>
                                <button type="submit" name="create_admin" class="btn btn-primary w-100">
                                    <i class="fas fa-plus"></i> Create Admin
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Emergency Recovery Info -->
                    <div class="card mt-3">
                        <div class="card-header bg-warning">
                            <h6><i class="fas fa-exclamation-triangle"></i> Emergency Recovery</h6>
                        </div>
                        <div class="card-body">
                            <p class="small mb-2">If you lose admin access:</p>
                            <ol class="small">
                                <li>Use the emergency setup script</li>
                                <li>Run database reset script</li>
                                <li>Contact system administrator</li>
                            </ol>
                            <p class="text-muted small">These scripts are available in the root directory when needed.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Admin Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" id="reset_admin_id" name="admin_id">
                        <p>Reset password for admin: <strong id="reset_admin_username"></strong></p>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                   minlength="8" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_new_password" 
                                   name="confirm_password" required>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> The admin will be required to change this password on next login.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_admin_password" class="btn btn-warning">
                            <i class="fas fa-key"></i> Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle reset password modal
        const resetPasswordModal = document.getElementById('resetPasswordModal');
        resetPasswordModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const adminId = button.getAttribute('data-admin-id');
            const adminUsername = button.getAttribute('data-admin-username');
            
            document.getElementById('reset_admin_id').value = adminId;
            document.getElementById('reset_admin_username').textContent = adminUsername;
        });
        
        // Password confirmation validation
        document.getElementById('confirm_new_password').addEventListener('input', function() {
            const password = document.getElementById('new_password').value;
            const confirm = this.value;
            
            if (password !== confirm) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
        
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirm = this.value;
            
            if (password !== confirm) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
