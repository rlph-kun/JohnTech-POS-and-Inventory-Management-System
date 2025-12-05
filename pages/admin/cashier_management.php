<?php
/*
======================================================================
  Page: Cashier Management
  Description: Admin interface to create, edit, view, and delete cashier
               accounts, including password management and profile photos.
  Notes:
  - Keep functionality and UI/UX consistent with existing behavior.
  - Structured with clear section headers for easier maintenance.
  - Similar commenting style to admin dashboard pages.
======================================================================
*/
// ====================================================================
// INCLUDES & ACCESS CONTROL
// ====================================================================
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

$errors = [];
$success = '';
$show_edit_modal = false;
$edit_user_id = null;

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Cashier account updated successfully!';
}

// ====================================================================
// Add Cashier
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_cashier'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $name = trim($_POST['name']);
    $branch_id = intval($_POST['branch_id']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    
    // Validate contact: must be exactly 11 digits and only numbers
    if (!preg_match('/^\d{11}$/', $contact)) {
        $errors[] = 'Contact must be exactly 11 digits and contain only numbers.';
    }
    
    if ($username && $password && $name && $branch_id && $contact && empty($errors)) {
        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = 'Username already exists.';
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Insert into users
            $stmt2 = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'cashier')");
            $stmt2->bind_param('ss', $username, $hashed_password);
            if ($stmt2->execute()) {
                $user_id = $stmt2->insert_id;
                
                // Handle profile picture upload
                $profile_picture = null;
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
                    $file = $_FILES['profile_picture'];
                    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    $max_size = 5 * 1024 * 1024; // 5MB
                    
                    if (!in_array($file['type'], $allowed_types)) {
                        $errors[] = 'Only JPG, PNG, and GIF files are allowed.';
                    } elseif ($file['size'] > $max_size) {
                        $errors[] = 'File size must be less than 5MB.';
                    } else {
                        $upload_dir = '../../assets/uploads/profile_pictures/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $new_filename = 'cashier_' . $user_id . '_' . time() . '.' . $file_extension;
                        $upload_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                            $profile_picture = $new_filename;
                        } else {
                            $errors[] = 'Error uploading profile picture.';
                        }
                    }
                }
                
                // Insert into cashier_profiles
                if (empty($errors)) {
                    $stmt3 = $conn->prepare("INSERT INTO cashier_profiles (user_id, name, branch_id, contact, email, profile_picture) VALUES (?, ?, ?, ?, ?, ?)");
                    // Types: i (user_id), s (name), i (branch_id), s (contact), s (email), s (profile_picture)
                    $stmt3->bind_param('isisss', $user_id, $name, $branch_id, $contact, $email, $profile_picture);
                    if ($stmt3->execute()) {
                        $success = 'Cashier account created successfully!';
                    } else {
                        $errors[] = 'Error creating cashier profile.';
                        // Rollback user and delete uploaded file
                        $conn->query("DELETE FROM users WHERE id = $user_id");
                        if ($profile_picture && file_exists($upload_path)) {
                            unlink($upload_path);
                        }
                    }
                    $stmt3->close();
                } else {
                    // Rollback user if there were errors
                    $conn->query("DELETE FROM users WHERE id = $user_id");
                }
            } else {
                $errors[] = 'Error creating user account.';
            }
            $stmt2->close();
        }
        $stmt->close();
    } else {
        $errors[] = 'All fields except email are required.';
    }
}

// ====================================================================
// Edit Cashier
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_cashier'])) {
    $user_id = intval($_POST['user_id']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $name = trim($_POST['name']);
    $branch_id = intval($_POST['branch_id']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    
    // Validate contact: must be exactly 11 digits and only numbers
    if (!preg_match('/^\d{11}$/', $contact)) {
        $errors[] = 'Contact must be exactly 11 digits and contain only numbers.';
    }
    
    if ($user_id && $username && $name && $branch_id && $contact && empty($errors)) {
        // Check if username is taken by another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->bind_param('si', $username, $user_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = 'Username already exists.';
        } else {
            // Handle profile picture upload
            $profile_picture = null;
            $upload_new_picture = false;
            
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
                $file = $_FILES['profile_picture'];
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $max_size = 5 * 1024 * 1024; // 5MB
                
                if (!in_array($file['type'], $allowed_types)) {
                    $errors[] = 'Only JPG, PNG, and GIF files are allowed.';
                } elseif ($file['size'] > $max_size) {
                    $errors[] = 'File size must be less than 5MB.';
                } else {
                    $upload_dir = '../../assets/uploads/profile_pictures/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $new_filename = 'cashier_' . $user_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                        $profile_picture = $new_filename;
                        $upload_new_picture = true;
                    } else {
                        $errors[] = 'Error uploading profile picture.';
                    }
                }
            }
            
            if (empty($errors)) {
                // Update users table
                if ($password) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt2 = $conn->prepare("UPDATE users SET username=?, password=? WHERE id=?");
                    $stmt2->bind_param('ssi', $username, $hashed_password, $user_id);
                } else {
                    $stmt2 = $conn->prepare("UPDATE users SET username=? WHERE id=?");
                    $stmt2->bind_param('si', $username, $user_id);
                }
                if ($stmt2->execute()) {
                    // Update cashier_profiles
                    if ($upload_new_picture) {
                        // Get current profile picture to delete old file
                        $stmt_current = $conn->prepare("SELECT profile_picture FROM cashier_profiles WHERE user_id = ?");
                        $stmt_current->bind_param('i', $user_id);
                        $stmt_current->execute();
                        $stmt_current->bind_result($current_picture);
                        $stmt_current->fetch();
                        $stmt_current->close();
                        
                        // Delete old profile picture
                        if ($current_picture && file_exists('../../assets/uploads/profile_pictures/' . $current_picture)) {
                            unlink('../../assets/uploads/profile_pictures/' . $current_picture);
                        }
                        
                        $stmt3 = $conn->prepare("UPDATE cashier_profiles SET name=?, branch_id=?, contact=?, email=?, profile_picture=? WHERE user_id=?");
                        $stmt3->bind_param('sisssi', $name, $branch_id, $contact, $email, $profile_picture, $user_id);
                    } else {
                        $stmt3 = $conn->prepare("UPDATE cashier_profiles SET name=?, branch_id=?, contact=?, email=? WHERE user_id=?");
                        $stmt3->bind_param('sissi', $name, $branch_id, $contact, $email, $user_id);
                    }
                    
                    if ($stmt3->execute()) {
                        $success = 'Cashier account updated successfully!';
                        $show_edit_modal = false; // Hide modal on success
                        $edit_user_id = null;
                        
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
                        exit();
                    } else {
                        $errors[] = 'Error updating cashier profile: ' . $stmt3->error;
                        // Delete uploaded file if update failed
                        if ($upload_new_picture && file_exists($upload_path)) {
                            unlink($upload_path);
                        }
                    }
                    $stmt3->close();
                } else {
                    $errors[] = 'Error updating user account: ' . $stmt2->error;
                    // Delete uploaded file if update failed
                    if ($upload_new_picture && file_exists($upload_path)) {
                        unlink($upload_path);
                    }
                }
                $stmt2->close();
            }
        }
        $stmt->close();
    } else {
        $errors[] = 'All fields except email and password are required.';
    }
}

// ====================================================================
// Change Password
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id']) && isset($_POST['new_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    
    if ($user_id && $new_password) {
        // Get cashier details
        $stmt_details = $conn->prepare("SELECT cp.name, u.username FROM cashier_profiles cp JOIN users u ON cp.user_id = u.id WHERE u.id = ?");
        $stmt_details->bind_param('i', $user_id);
        $stmt_details->execute();
        $stmt_details->bind_result($cashier_name, $username);
        $stmt_details->fetch();
        $stmt_details->close();
        
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password in database
        $stmt = $conn->prepare("UPDATE users SET password = ?, last_password_change = NOW() WHERE id = ? AND role = 'cashier'");
        $stmt->bind_param('si', $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            $success = "Password changed successfully for <strong>$cashier_name</strong> (Username: $username)!<br>";
            $success .= "<div class='alert alert-info mt-2'>";
            $success .= "<i class='bi bi-info-circle'></i> <strong>New Password:</strong> <span class='badge bg-primary'>$new_password</span><br>";
            $success .= "<small class='text-warning'><i class='bi bi-exclamation-triangle'></i> Please share this password with the cashier.</small>";
            $success .= "</div>";
        } else {
            $errors[] = 'Error changing password: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $errors[] = 'User ID and new password are required.';
    }
}

// ====================================================================
// Function to send password reset email (Optional utility)
// ====================================================================
function sendPasswordResetEmail($email, $name, $username, $password) {
    $subject = "JohnTech Management System - New Login Credentials";
    $message = "
    <html>
    <head>
        <title>New Login Credentials</title>
    </head>
    <body>
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #1565c0;'>JohnTech Management System</h2>
            <p>Dear $name,</p>
            <p>Your login credentials have been reset by the administrator:</p>
            <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                <strong>Username:</strong> $username<br>
                <strong>Temporary Password:</strong> <span style='background-color: #007bff; color: white; padding: 3px 8px; border-radius: 3px;'>$password</span>
            </div>
            <p style='color: #dc3545;'><strong>Important:</strong> You must change this password when you first log in.</p>
            <p>If you have any issues logging in, please contact your administrator.</p>
            <hr>
            <small style='color: #6c757d;'>This is an automated message from JohnTech Management System.</small>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: JohnTech System <noreply@johntech.com>" . "\r\n";
    
    return mail($email, $subject, $message, $headers);
}

// ====================================================================
// Delete Cashier
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cashier'])) {
    $user_id = intval($_POST['delete_cashier']);
    
    // Get profile picture before deletion
    $stmt_pic = $conn->prepare("SELECT profile_picture FROM cashier_profiles WHERE user_id = ?");
    $stmt_pic->bind_param('i', $user_id);
    $stmt_pic->execute();
    $stmt_pic->bind_result($profile_picture);
    $stmt_pic->fetch();
    $stmt_pic->close();
    
    // Delete user (profile will be deleted via ON DELETE CASCADE)
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role='cashier'");
    $stmt->bind_param('i', $user_id);
    if ($stmt->execute()) {
        // Delete profile picture file
        if ($profile_picture && file_exists('../../assets/uploads/profile_pictures/' . $profile_picture)) {
            unlink('../../assets/uploads/profile_pictures/' . $profile_picture);
        }
        $success = 'Cashier account deleted successfully!';
    } else {
        $errors[] = 'Error deleting cashier account.';
    }
    $stmt->close();
}

// ====================================================================
// Get branches for dropdown
// ====================================================================
$branches = [];
$branch_res = $conn->query("SELECT id, name FROM branches");
while ($row = $branch_res->fetch_assoc()) {
    $branches[$row['id']] = $row['name'];
}

// ====================================================================
// Get all cashiers (users + profiles)
// ====================================================================
$cashiers = [];
$res = $conn->query("
    SELECT u.id as user_id, u.username, u.created_at, u.last_login, 
           COALESCE(u.password_changed, 1) as password_changed,
           cp.name, cp.branch_id, b.name as branch_name, cp.contact, cp.email, cp.profile_picture 
    FROM users u 
    JOIN cashier_profiles cp ON u.id = cp.user_id 
    LEFT JOIN branches b ON cp.branch_id = b.id 
    WHERE u.role = 'cashier' 
    ORDER BY cp.name ASC
");
if (!$res) {
    die('Query error: ' . $conn->error);
}
while ($row = $res->fetch_assoc()) {
    $cashiers[] = $row;
}
?>

<!-- ====================================================================
     HTML OUTPUT
     ==================================================================== -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Cashier Management</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    <style>
        /* Fix modal z-index issues */
        .modal {
            z-index: 1060 !important;
        }
        .modal-backdrop {
            z-index: 1040 !important;
        }
        .modal-dialog {
            z-index: 1070 !important;
        }
        /* Ensure modal content is accessible */
        .modal-content {
            position: relative;
            background-color: #fff;
            border: 1px solid rgba(0,0,0,.2);
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
        }
        .info-group {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.375rem;
            line-height: 1.8;
        }
        .info-group strong {
            color: #495057;
            min-width: 120px;
            display: inline-block;
        }
    </style>
</head>
<body style="margin: 0 !important; padding: 0 !important;">
<?php include '../../includes/admin_sidebar.php'; ?>

<div class="container-fluid" style="margin-left: 250px !important; margin-top: 0 !important; padding: 0.5rem !important; padding-top: 0 !important; background: #f8fafc !important; min-height: 100vh !important; position: relative !important; z-index: 1 !important;">
    <div class="main-content" style="position: relative !important; z-index: 2 !important; margin-top: 0 !important; padding-top: 0.5rem !important;">
        <!-- Top Header Area -->
        <div class="d-flex justify-content-between align-items-center mb-3" style="background: #ffffff; padding: 0.75rem 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-top: 0 !important;">
            <div>
                <h1 class="mb-0" style="color: #1565c0; font-size: 1.25rem; font-weight: 600;">
                    <i class="bi bi-gear me-2"></i>JohnTech Management System
                </h1>
            </div>
            <div class="text-end">
                <div style="color: #64748b; font-size: 0.85rem;">
                    <i class="bi bi-person-circle me-1"></i>Welcome, Admin
                </div>
                <div style="color: #64748b; font-size: 0.8rem;">
                    <i class="bi bi-clock me-1"></i><?= date('h:i A') ?>
                </div>
            </div>
        </div>
        
        <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-person-badge me-2"></i>Cashier Management</h2>
        
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error:</strong><br>
                    <?= implode('<br>', $errors) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row mb-3">
                <div class="col-md-4">
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="bi bi-plus-circle"></i> Add New Cashier
                    </button>
                </div>
                <div class="col-md-4">
                    <div class="row">
                        <div class="col-md-8">
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by name, username, or contact...">
                        </div>
                        <div class="col-md-4">
                            <select id="branchFilter" class="form-select">
                                <option value="">All Branches</option>
                                <?php foreach ($branches as $id => $name): ?>
                                    <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Profile</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Branch</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Account Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashiers as $cashier): ?>
                            <tr>
                                <td class="text-center">
                                    <?php if ($cashier['profile_picture'] && file_exists('../../assets/uploads/profile_pictures/' . $cashier['profile_picture'])): ?>
                                        <img src="<?= BASE_URL ?>/assets/uploads/profile_pictures/<?= htmlspecialchars($cashier['profile_picture']) ?>" 
                                             alt="Profile Picture" 
                                             class="rounded-circle" 
                                             style="width: 50px; height: 50px; object-fit: cover; border: 2px solid #dee2e6;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" 
                                             style="width: 50px; height: 50px; margin: 0 auto;">
                                            <i class="bi bi-person text-white"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($cashier['username']) ?></td>
                                <td><?= htmlspecialchars($cashier['name']) ?></td>
                                <td><?= htmlspecialchars($cashier['branch_name']) ?></td>
                                <td><?= htmlspecialchars($cashier['contact']) ?></td>
                                <td><?= htmlspecialchars($cashier['email']) ?></td>
                                <td>
                                    <?php if ($cashier['password_changed'] == 0): ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="bi bi-exclamation-triangle"></i> Temp Password
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle"></i> Active
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($cashier['last_login']): ?>
                                        <br><small class="text-muted">Last: <?= date('M d, Y', strtotime($cashier['last_login'])) ?></small>
                                    <?php else: ?>
                                        <br><small class="text-muted">Never logged in</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="edit_cashier.php?id=<?= $cashier['user_id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <button type="button" class="btn btn-sm btn-warning" onclick="changePassword(<?= $cashier['user_id'] ?>, '<?= htmlspecialchars($cashier['username']) ?>')">
                                        <i class="bi bi-key"></i> Change Password
                                    </button>
                                    <button type="button" class="btn btn-sm btn-info" onclick="showCredentials(<?= $cashier['user_id'] ?>, '<?= htmlspecialchars($cashier['username']) ?>')">
                                        <i class="bi bi-eye"></i> View Info
                                    </button>
                                    <form method="post" style="display:inline-block" onsubmit="return confirm('Are you sure you want to delete this cashier?');">
                                        <input type="hidden" name="delete_cashier" value="<?= $cashier['user_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Cashier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Profile Picture Section -->
                    <div class="row mb-3">
                        <div class="col-md-4 text-center">
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mb-2" 
                                 style="width: 120px; height: 120px; margin: 0 auto;">
                                <i class="bi bi-person text-white" style="font-size: 3rem;"></i>
                            </div>
                            <div class="form-text">Profile Picture Preview</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Profile Picture</label>
                            <input type="file" name="profile_picture" class="form-control" accept="image/*" id="profilePictureInput">
                            <div class="form-text">JPG, PNG, or GIF up to 5MB. Optional.</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="passwordInput" class="form-control" required autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generatePassword()">
                                        <i class="bi bi-shuffle"></i> Generate
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()">
                                        <i class="bi bi-eye" id="toggleIcon"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    <span id="generatedPasswordDisplay" class="text-success fw-bold"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select" required>
                                    <?php foreach ($branches as $id => $name): ?>
                                        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Contact</label>
                                <input type="text" name="contact" class="form-control" required pattern="\d{11}" maxlength="11" minlength="11" inputmode="numeric" title="Contact must be exactly 11 digits">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_cashier" class="btn btn-success">Add Cashier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cropper.js CSS -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cropper.min.css"/>
<!-- Cropper.js Modal -->
<div class="modal fade" id="cropModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Crop Profile Picture</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <div style="width: 300px; height: 300px; margin: 0 auto; border-radius: 50%; overflow: hidden; background: #f8f9fa;">
          <img id="cropper-image" style="max-width:100%; display:block; margin:0 auto;" />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" id="crop-btn" class="btn btn-primary">Crop & Use</button>
      </div>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Change password for <strong id="changeUsername"></strong></p>
                    
                    <div class="mb-3">
                        <label for="newPassword" class="form-label">New Password</label>
                        <input type="text" class="form-control" id="newPassword" name="new_password" required 
                               placeholder="Enter new password" minlength="6">
                        <div class="form-text">Password must be at least 6 characters long.</div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Note:</strong> The cashier will use this password to login immediately.
                    </div>
                    
                    <input type="hidden" name="user_id" id="changeUserId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="bi bi-key"></i> Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Credentials Modal -->
<div class="modal fade" id="viewCredentialsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-circle"></i> Cashier Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="bi bi-person"></i> Personal Information</h6>
                        <div class="info-group">
                            <strong>Full Name:</strong> <span id="viewName"></span><br>
                            <strong>Username:</strong> <span id="viewUsername" class="badge bg-primary"></span><br>
                            <strong>Email:</strong> <span id="viewEmail"></span><br>
                            <strong>Contact:</strong> <span id="viewContact"></span><br>
                            <strong>Branch:</strong> <span id="viewBranch"></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="bi bi-activity"></i> Account Status</h6>
                        <div class="info-group">
                            <strong>Status:</strong> <span id="viewStatus"></span><br>
                            <strong>Last Login:</strong> <span id="lastLogin"></span><br>
                            <strong>Password Changed:</strong> <span id="lastPasswordChange"></span><br>
                            <strong>Account Created:</strong> <span id="createdAt"></span>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="text-center">
                    <h6><i class="bi bi-tools"></i> Quick Actions</h6>
                    <button type="button" class="btn btn-warning me-2" onclick="initiatePasswordChange()">
                        <i class="bi bi-key"></i> Change Password
                    </button>
                    <button type="button" class="btn btn-primary" onclick="editCashier()">
                        <i class="bi bi-pencil"></i> Edit Profile
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">

<!-- ====================================================================
     JAVASCRIPT
     ==================================================================== -->
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/cropper.min.js"></script>

<script>
let cropper;
let currentInput;

function openCropper(input) {
    console.log('openCropper called'); // Debug
    const file = input.files[0];
    if (file) {
        console.log('File selected:', file.name); // Debug
        const reader = new FileReader();
        reader.onload = function(event) {
            const image = document.getElementById('cropper-image');
            image.src = event.target.result;
            
            // Show the crop modal
            const cropModal = new bootstrap.Modal(document.getElementById('cropModal'));
            cropModal.show();
            
            // Initialize cropper after modal is shown
            setTimeout(function() {
                if (cropper) {
                    cropper.destroy();
                }
                cropper = new Cropper(image, {
                    aspectRatio: 1,
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 1,
                    responsive: true,
                    background: false,
                    guides: false,
                    highlight: false,
                    cropBoxResizable: true,
                    cropBoxMovable: true,
                    minContainerWidth: 300,
                    minContainerHeight: 300
                });
            }, 500);
        };
        reader.readAsDataURL(file);
    }
}

// Attach to all profile_picture inputs (Add & Edit)
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up cropper'); // Debug
    
    // Function to attach event listeners
    function attachCropperListeners() {
        document.querySelectorAll('input[name="profile_picture"]').forEach(function(input) {
            console.log('Found input:', input); // Debug
            input.addEventListener('change', function(e) {
                console.log('File input changed'); // Debug
                currentInput = input;
                openCropper(input);
            });
        });
    }
    
    // Attach listeners initially
    attachCropperListeners();
    
    // Re-attach listeners when modals are shown (for dynamically created edit modals)
    document.addEventListener('shown.bs.modal', function(e) {
        if (e.target.id.includes('editModal') || e.target.id === 'addModal') {
            console.log('Modal shown, re-attaching listeners'); // Debug
            attachCropperListeners();
        }
    });
});

// Handle crop button
document.addEventListener('click', function(e) {
    if (e.target.id === 'crop-btn') {
        console.log('Crop button clicked'); // Debug
        if (cropper) {
            cropper.getCroppedCanvas({
                width: 300,
                height: 300,
                imageSmoothingQuality: 'high'
            }).toBlob(function(blob) {
                // Replace the file input with the cropped image blob
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(new File([blob], 'profile.jpg', {type: 'image/jpeg'}));
                if (currentInput) {
                    currentInput.files = dataTransfer.files;
                    
                    // Show preview in modal
                    const modalBody = currentInput.closest('.modal-body');
                    if (modalBody) {
                        const previewContainer = modalBody.querySelector('.rounded-circle');
                        if (previewContainer) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                previewContainer.innerHTML = `<img src="${e.target.result}" class="rounded-circle" style="width: 120px; height: 120px; object-fit: cover;">`;
                            };
                            reader.readAsDataURL(blob);
                        }
                    }
                }
                
                // Hide crop modal
                const cropModal = bootstrap.Modal.getInstance(document.getElementById('cropModal'));
                if (cropModal) {
                    cropModal.hide();
                }
                
                cropper.destroy();
            }, 'image/jpeg', 0.95);
        }
    }
});

// Change Password Function
function changePassword(userId, username) {
    document.getElementById('changeUserId').value = userId;
    document.getElementById('changeUsername').textContent = username;
    
    // Clear the password field
    document.getElementById('newPassword').value = '';
    
    const changeModal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
    changeModal.show();
}

// Show Credentials Function
function showCredentials(userId, username) {
    document.getElementById('viewUsername').textContent = username;
    
    // Fetch cashier information
    fetch(`get_cashier_info.php?user_id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error loading cashier information');
                return;
            }
            
            document.getElementById('viewName').textContent = data.name;
            document.getElementById('viewUsername').textContent = data.username;
            document.getElementById('viewEmail').textContent = data.email;
            document.getElementById('viewContact').textContent = data.contact;
            document.getElementById('viewBranch').textContent = data.branch_name;
            document.getElementById('viewStatus').innerHTML = data.status;
            document.getElementById('lastLogin').textContent = data.last_login;
            document.getElementById('lastPasswordChange').textContent = data.last_password_change;
            document.getElementById('createdAt').textContent = data.created_at;
            
            // Store current user ID for quick actions
            document.getElementById('viewCredentialsModal').setAttribute('data-user-id', userId);
            document.getElementById('viewCredentialsModal').setAttribute('data-username', username);
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading cashier information');
        });
    
    const viewModal = new bootstrap.Modal(document.getElementById('viewCredentialsModal'));
    viewModal.show();
}

// Quick action functions
function initiatePasswordChange() {
    const modal = document.getElementById('viewCredentialsModal');
    const userId = modal.getAttribute('data-user-id');
    const username = modal.getAttribute('data-username');
    
    // Close view modal and open change password modal
    bootstrap.Modal.getInstance(modal).hide();
    changePassword(userId, username);
}

function editCashier() {
    const modal = document.getElementById('viewCredentialsModal');
    const userId = modal.getAttribute('data-user-id');
    
    // Close modal and redirect to edit page
    bootstrap.Modal.getInstance(modal).hide();
    window.location.href = `edit_cashier.php?id=${userId}`;
}

// Initiate Reset from View Modal
function initiateReset() {
    const modal = document.getElementById('viewCredentialsModal');
    const userId = modal.getAttribute('data-user-id');
    const username = modal.getAttribute('data-username');
    
    // Close view modal
    const viewModal = bootstrap.Modal.getInstance(modal);
    viewModal.hide();
    
    // Open reset modal
    setTimeout(() => {
        changePassword(userId, username);
    }, 300);
}

// Password Generation Function
function generatePassword() {
    const length = 12;
    const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
    let password = "";
    
    // Ensure at least one character from each category
    password += "ABCDEFGHIJKLMNOPQRSTUVWXYZ"[Math.floor(Math.random() * 26)]; // Uppercase
    password += "abcdefghijklmnopqrstuvwxyz"[Math.floor(Math.random() * 26)]; // Lowercase
    password += "0123456789"[Math.floor(Math.random() * 10)]; // Number
    password += "!@#$%^&*"[Math.floor(Math.random() * 8)]; // Special char
    
    // Fill the rest randomly
    for (let i = 4; i < length; i++) {
        password += charset[Math.floor(Math.random() * charset.length)];
    }
    
    // Shuffle the password
    password = password.split('').sort(() => Math.random() - 0.5).join('');
    
    document.getElementById('passwordInput').value = password;
    document.getElementById('generatedPasswordDisplay').textContent = 'Generated: ' + password;
}

// Toggle Password Visibility
function togglePassword() {
    const passwordInput = document.getElementById('passwordInput');
    const toggleIcon = document.getElementById('toggleIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'bi bi-eye-slash';
    } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'bi bi-eye';
    }
}

// Search and Filter Functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const branchFilter = document.getElementById('branchFilter');
    const tableRows = document.querySelectorAll('tbody tr');
    
    function filterTable() {
        const searchTerm = searchInput.value.toLowerCase();
        const selectedBranch = branchFilter.value.toLowerCase();
        
        tableRows.forEach(row => {
            const name = row.cells[2].textContent.toLowerCase();
            const username = row.cells[1].textContent.toLowerCase();
            const contact = row.cells[4].textContent.toLowerCase();
            const branch = row.cells[3].textContent.toLowerCase();
            
            const matchesSearch = name.includes(searchTerm) || 
                                username.includes(searchTerm) || 
                                contact.includes(searchTerm);
            const matchesBranch = selectedBranch === '' || branch.includes(selectedBranch);
            
            if (matchesSearch && matchesBranch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    searchInput.addEventListener('input', filterTable);
    branchFilter.addEventListener('change', filterTable);
});

// Show edit modal if there were errors during edit
<?php if ($show_edit_modal && $edit_user_id && $errors): ?>
document.addEventListener('DOMContentLoaded', function() {
    const editModal = new bootstrap.Modal(document.getElementById('editModal<?= $edit_user_id ?>'));
    editModal.show();
});
<?php endif; ?>

// Add form validation and debugging
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing form validation and modal handlers');
    
    // Test modal functionality - ensure all edit buttons work
    document.querySelectorAll('[data-bs-toggle="modal"]').forEach(function(button) {
        button.addEventListener('click', function(e) {
            console.log('Modal button clicked:', this.getAttribute('data-bs-target'));
            const targetModal = document.querySelector(this.getAttribute('data-bs-target'));
            if (targetModal) {
                console.log('Target modal found:', targetModal.id);
                // Ensure modal is properly shown
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(targetModal);
                    if (!modal) {
                        console.log('Creating new modal instance for:', targetModal.id);
                        new bootstrap.Modal(targetModal).show();
                    }
                }, 100);
            } else {
                console.error('Target modal not found for:', this.getAttribute('data-bs-target'));
            }
        });
    });
    
    // Ensure all modals are properly initialized
    document.querySelectorAll('.modal').forEach(function(modalElement) {
        console.log('Initializing modal:', modalElement.id);
        modalElement.addEventListener('show.bs.modal', function() {
            console.log('Modal showing:', this.id);
        });
        modalElement.addEventListener('shown.bs.modal', function() {
            console.log('Modal shown:', this.id);
        });
    });
    
    // Add form submission debugging
    const editForms = document.querySelectorAll('form[method="post"]');
    editForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            console.log('Form submitted:', this);
            
            // Basic validation
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            // Validate contact field (11 digits)
            const contactField = this.querySelector('input[name="contact"]');
            if (contactField && !/^\d{11}$/.test(contactField.value)) {
                isValid = false;
                contactField.classList.add('is-invalid');
                alert('Contact must be exactly 11 digits and contain only numbers.');
                e.preventDefault();
                return false;
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields correctly.');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
            }
        });
    });
});
</script>

    </div>
</div>

<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>
</body>
</html>
