<?php
/*
======================================================================
  Page: Settings
  Description: Admin settings including profile picture management
               (with optional cropping), admin credential updates,
               branch management (CRUD), and DB backup/restore.
  Notes:
  - Preserves all functionality and UI/UX.
  - Adds a top-level header and keeps existing section comments.
======================================================================
*/
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

$errors = [];
$success = '';

// ====================================================================
// LOAD CURRENT ADMIN PROFILE PICTURE
// ====================================================================
// Get current admin profile picture and email
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT profile_picture, email FROM users WHERE id=? AND role='admin'");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$stmt->bind_result($current_profile_picture, $current_admin_email);
$stmt->fetch();
$stmt->close();

// ====================================================================
// 1. PROFILE PICTURE UPLOAD (RAW FILE)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_picture'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $file = $_FILES['profile_picture'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = 'Only JPG, PNG, and GIF files are allowed.';
        } elseif ($file['size'] > $max_size) {
            $errors[] = 'File size must be less than 5MB.';
        } else {
            $upload_dir = '../../assets/uploads/profile_pictures/';
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'admin_' . $admin_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                // Delete old profile picture if exists
                if ($current_profile_picture && file_exists('../../assets/uploads/profile_pictures/' . $current_profile_picture)) {
                    unlink('../../assets/uploads/profile_pictures/' . $current_profile_picture);
                }
                
                // Update database
                $stmt = $conn->prepare("UPDATE users SET profile_picture=? WHERE id=? AND role='admin'");
                $stmt->bind_param('si', $new_filename, $admin_id);
                if ($stmt->execute()) {
                    $_SESSION['success'] = 'Profile picture updated successfully!';
                    $current_profile_picture = $new_filename;
                    header('Location: Settings.php');
                    exit;
                } else {
                    $errors[] = 'Error updating profile picture in database.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Error uploading file.';
            }
        }
    } else {
        $errors[] = 'Please select a valid image file.';
    }
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}

// ====================================================================
// 1.a PROFILE PICTURE UPLOAD (CROPPED DATA URL)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_cropped_image'])) {
    $errors = [];
    
    if (!isset($_POST['cropped_image']) || empty($_POST['cropped_image'])) {
        $errors[] = 'No cropped image data received.';
    } else {
        $cropped_image_data = $_POST['cropped_image'];
        
        // Validate the data URL format
        if (!preg_match('/^data:image\/(jpeg|jpg|png|gif);base64,/', $cropped_image_data)) {
            $errors[] = 'Invalid image data format.';
        } else {
            // Remove the data URL prefix to get the base64 data
            $base64_data = preg_replace('#^data:image/\w+;base64,#i', '', $cropped_image_data);
            $image_data = base64_decode($base64_data);
            
            if ($image_data === false || strlen($image_data) < 100) {
                $errors[] = 'Failed to decode image data or image too small.';
            } else {
                // Ensure upload directory exists
                $upload_dir = '../../assets/uploads/profile_pictures/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $new_filename = 'admin_' . $admin_id . '_' . time() . '.jpg';
                $upload_path = $upload_dir . $new_filename;
                
                // Try to save the file
                $bytes_written = file_put_contents($upload_path, $image_data);
                if ($bytes_written === false) {
                    $errors[] = 'Error writing image file to disk.';
                } else {
                    // Verify the file was created and has content
                    if (!file_exists($upload_path) || filesize($upload_path) < 100) {
                        $errors[] = 'Image file was not created properly.';
                    } else {
                        // Delete old profile picture if exists
                        if ($current_profile_picture && file_exists('../../assets/uploads/profile_pictures/' . $current_profile_picture)) {
                            unlink('../../assets/uploads/profile_pictures/' . $current_profile_picture);
                        }
                        
                        // Update database
                        $stmt = $conn->prepare("UPDATE users SET profile_picture=? WHERE id=? AND role='admin'");
                        $stmt->bind_param('si', $new_filename, $admin_id);
                        if ($stmt->execute()) {
                            $_SESSION['success'] = 'Profile picture updated successfully!';
                            $current_profile_picture = $new_filename;
                            header('Location: Settings.php');
                            exit;
                        } else {
                            $errors[] = 'Error updating profile picture in database.';
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
    
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}

// ====================================================================
// 1.b REMOVE PROFILE PICTURE
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_profile_picture'])) {
    if ($current_profile_picture && file_exists('../../assets/uploads/profile_pictures/' . $current_profile_picture)) {
        unlink('../../assets/uploads/profile_pictures/' . $current_profile_picture);
    }
    
    $stmt = $conn->prepare("UPDATE users SET profile_picture=NULL WHERE id=? AND role='admin'");
    $stmt->bind_param('i', $admin_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Profile picture removed successfully!';
        $current_profile_picture = null;
        header('Location: Settings.php');
        exit;
    } else {
        $errors[] = 'Error removing profile picture.';
    }
    $stmt->close();
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}

// ====================================================================
// 2. UPDATE ADMIN EMAIL (FOR OTP PASSWORD RESET)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_email'])) {
    $new_email = trim($_POST['new_email']);
    $admin_id = $_SESSION['user_id'];
    
    // Validate email format
    if (empty($new_email)) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        // Check if email is already used by another admin
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role = 'admin' AND id != ?");
        $stmt->bind_param('si', $new_email, $admin_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $errors[] = 'This email is already registered to another admin account.';
        } else {
            // Update email
            $update_stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ? AND role = 'admin'");
            $update_stmt->bind_param('si', $new_email, $admin_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['success'] = 'Email address updated successfully! OTP codes will now be sent to this email.';
                $current_admin_email = $new_email;
                header('Location: Settings.php');
                exit;
            } else {
                $errors[] = 'Error updating email address. Please try again.';
            }
            $update_stmt->close();
        }
        $stmt->close();
    }
    
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}

// ====================================================================
// 3. CHANGE ADMIN USERNAME & PASSWORD
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_admin_credentials'])) {
    $current_password = $_POST['current_password'];
    $new_username = trim($_POST['new_username']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $admin_id = $_SESSION['user_id'];

    // Fetch current admin
    $stmt = $conn->prepare("SELECT username, password FROM users WHERE id=? AND role='admin'");
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $stmt->bind_result($current_username, $hashed_password);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($current_password, $hashed_password)) {
        $errors[] = 'Current password is incorrect.';
    } elseif ($new_password !== $confirm_password) {
        $errors[] = 'New passwords do not match.';
    } elseif (!$new_username) {
        $errors[] = 'Username cannot be empty.';
    } else {
        $update_sql = "UPDATE users SET username=?, password=? WHERE id=? AND role='admin'";
        $stmt = $conn->prepare($update_sql);
        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt->bind_param('ssi', $new_username, $hashed_new_password, $admin_id);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Admin credentials updated successfully!';
            $_SESSION['username'] = $new_username;
            header('Location: Settings.php');
            exit;
        } else {
            $errors[] = 'Error updating credentials.';
        }
        $stmt->close();
    }
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}

// ====================================================================
// 3. MANAGE BRANCHES (CRUD)
// 3.a ADD BRANCH
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    $branch_name = trim($_POST['branch_name']);
    $branch_address = trim($_POST['branch_address']);
    if ($branch_name) {
        $stmt = $conn->prepare("INSERT INTO branches (name, address) VALUES (?, ?)");
        $stmt->bind_param('ss', $branch_name, $branch_address);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Branch added successfully!';
            header('Location: Settings.php');
            exit;
        } else {
            $errors[] = 'Error adding branch.';
        }
        $stmt->close();
    } else {
        $errors[] = 'Branch name is required.';
    }
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}
// ====================================================================
// 3.b EDIT BRANCH
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_branch'])) {
    $branch_id = intval($_POST['branch_id']);
    $branch_name = trim($_POST['branch_name']);
    $branch_address = trim($_POST['branch_address']);
    if ($branch_id && $branch_name) {
        $stmt = $conn->prepare("UPDATE branches SET name=?, address=? WHERE id=?");
        $stmt->bind_param('ssi', $branch_name, $branch_address, $branch_id);
        if ($stmt->execute()) {
            $_SESSION['success'] = 'Branch updated successfully!';
            header('Location: Settings.php');
            exit;
        } else {
            $errors[] = 'Error updating branch.';
        }
        $stmt->close();
    } else {
        $errors[] = 'Branch name is required.';
    }
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}
// ====================================================================
// 3.c DELETE BRANCH
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_branch'])) {
    $branch_id = intval($_POST['delete_branch']);
    $stmt = $conn->prepare("DELETE FROM branches WHERE id=?");
    $stmt->bind_param('i', $branch_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = 'Branch deleted successfully!';
        header('Location: Settings.php');
        exit;
    } else {
        $errors[] = 'Error deleting branch.';
    }
    $stmt->close();
    if ($errors) {
        $_SESSION['errors'] = $errors;
        header('Location: Settings.php');
        exit;
    }
}
// ====================================================================
// 3.d GET ALL BRANCHES
// ====================================================================
$branches = [];
$res = $conn->query("SELECT * FROM branches ORDER BY id ASC");
while ($row = $res->fetch_assoc()) {
    $branches[] = $row;
}

// ====================================================================
// 4. BACKUP/RESTORE DATABASE
// 4.a BACKUP: EXPORT ALL TABLES AS SQL
// ====================================================================
if (isset($_POST['backup_db'])) {
    $db_name = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
    $backup_file = '../../backup_' . $db_name . '_' . date('Ymd_His') . '.sql';
    $command = "mysqldump --user={$_ENV['DB_USER']} --password={$_ENV['DB_PASS']} --host=localhost $db_name > $backup_file";
    system($command);
    if (file_exists($backup_file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($backup_file));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backup_file));
        readfile($backup_file);
        exit;
    } else {
        $_SESSION['errors'] = ['Backup failed.'];
        header('Location: Settings.php');
        exit;
    }
}
// ====================================================================
// 4.b RESTORE: IMPORT SQL FILE
// ====================================================================
if (isset($_POST['restore_db']) && isset($_FILES['restore_file'])) {
    $file = $_FILES['restore_file']['tmp_name'];
    if ($file && is_uploaded_file($file)) {
        $db_name = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        $command = "mysql --user={$_ENV['DB_USER']} --password={$_ENV['DB_PASS']} --host=localhost $db_name < $file";
        system($command, $retval);
        if ($retval === 0) {
            $_SESSION['success'] = 'Database restored successfully!';
            header('Location: Settings.php');
            exit;
        } else {
            $_SESSION['errors'] = ['Restore failed.'];
            header('Location: Settings.php');
            exit;
        }
    } else {
        $_SESSION['errors'] = ['No file uploaded.'];
        header('Location: Settings.php');
        exit;
    }
}
?>
<?php
// ====================================================================
// HTML OUTPUT
// ====================================================================
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Settings</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cropper.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    
    <!-- Critical CSS for layout stability -->
    <style>
        body { background: #f8fafc !important; margin: 0 !important; padding: 0 !important; }
        .sidebar { background: linear-gradient(180deg, #1a1d23 0%, #23272b 100%) !important; width: 250px !important; height: 100vh !important; position: fixed !important; top: 0 !important; left: 0 !important; z-index: 1050 !important; }
        .container-fluid { margin-left: 250px !important; }
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
        
        <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-gear me-2"></i>Settings</h2>
        
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['success'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['errors'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= implode('<br>', $_SESSION['errors']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['errors']); ?>
            <?php endif; ?>

            <!-- 1. Profile Picture Management -->
            <h4><i class="bi bi-person-circle me-2"></i>Profile Picture</h4>
            <form method="post" enctype="multipart/form-data" class="mb-4" id="profile-picture-form">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="profile-picture-card">
                            <div class="profile-picture-flex">
                                <label class="form-label fw-bold mb-3 text-center w-100">Current Profile Picture</label>
                                <div class="profile-picture-container mx-auto">
                                    <?php if (
                                        $current_profile_picture && file_exists('../../assets/uploads/profile_pictures/' . $current_profile_picture)
                                    ): ?>
                                        <img src="<?= BASE_URL ?>/assets/uploads/profile_pictures/<?= htmlspecialchars($current_profile_picture) ?>" alt="Profile Picture" class="img-fluid rounded-circle">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center" style="width: 160px; height: 160px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                                            <i class="bi bi-person text-white" style="font-size: 4rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="profile-picture-upload">
                            <label class="form-label fw-bold mb-3">Upload New Profile Picture</label>
                            <div class="mb-3">
                                <input type="file" name="profile_picture" id="profile-picture-input" class="form-control" accept="image/*">
                                <small class="text-muted d-block mt-2">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Allowed types: JPG, PNG, GIF. Max size: 5MB.
                                </small>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="enable-crop" checked>
                                    <label class="form-check-label" for="enable-crop">
                                        <i class="bi bi-crop me-1"></i>Enable image cropping
                                    </label>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="upload_profile_picture" class="btn btn-primary" id="upload-btn">
                                    <i class="bi bi-upload me-2"></i>Upload New Picture
                                </button>
                                <?php if ($current_profile_picture): ?>
                                    <button type="submit" name="remove_profile_picture" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to remove your profile picture?');">
                                        <i class="bi bi-trash me-2"></i>Remove Current Picture
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <hr>

            <!-- 2. Update Admin Email (For OTP Password Reset) -->
            <h4><i class="bi bi-envelope me-2"></i>Admin Email Address</h4>
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle me-2"></i>
                <strong>OTP Password Reset:</strong> Your email address is used to send OTP codes when you reset your password. 
                Make sure this email is active and accessible.
            </div>
            <form method="post" class="mb-4">
                <label class="form-label fw-bold">Email Address</label>
                <div class="row g-3">
                    <div class="col-md-8">
                        <input type="email" 
                               name="new_email" 
                               class="form-control" 
                               value="<?= htmlspecialchars($current_admin_email ?? '') ?>" 
                               placeholder="your-email@gmail.com" 
                               required>
                    </div>
                    <div class="col-md-4 d-flex align-items-start">
                        <button type="submit" name="update_admin_email" class="btn btn-primary w-100">
                            <i class="bi bi-save me-2"></i>Update Email
                        </button>
                    </div>
                </div>
                <small class="text-muted">
                    <i class="bi bi-shield-check me-1"></i>
                    This email will receive OTP codes for password reset
                </small>
                <?php if ($current_admin_email): ?>
                    <div class="mt-2">
                        <small class="text-success">
                            <i class="bi bi-check-circle me-1"></i>
                            Current email: <strong><?= htmlspecialchars($current_admin_email) ?></strong>
                        </small>
                    </div>
                <?php else: ?>
                    <div class="mt-2">
                        <small class="text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            No email address set. Please add an email to enable password reset via OTP.
                        </small>
                    </div>
                <?php endif; ?>
            </form>

            <hr>

            <!-- 3. Change Admin Username & Password -->
            <h4><i class="bi bi-shield-lock me-2"></i>Change Admin Username & Password</h4>
            <form method="post" class="mb-4">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">New Username</label>
                        <input type="text" name="new_username" class="form-control" value="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" name="change_admin_credentials" class="btn btn-primary mt-3">Update Credentials</button>
            </form>

            <hr>

            <!-- 4. Manage Branches -->
            <h4><i class="bi bi-building me-2"></i>Manage Branches</h4>
            <button class="btn btn-success mb-2" data-bs-toggle="modal" data-bs-target="#addBranchModal"><i class="bi bi-plus-circle"></i> Add Branch</button>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td><?= $branch['id'] ?></td>
                                <td><?= htmlspecialchars($branch['name']) ?></td>
                                <td><?= htmlspecialchars($branch['address']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editBranchModal<?= $branch['id'] ?>">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <form method="post" style="display:inline-block" onsubmit="return confirm('Are you sure you want to delete this branch?');">
                                        <input type="hidden" name="delete_branch" value="<?= $branch['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <!-- Edit Branch Modal -->
                            <div class="modal fade" id="editBranchModal<?= $branch['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="post">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Branch</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="branch_id" value="<?= $branch['id'] ?>">
                                                <div class="mb-3">
                                                    <label class="form-label">Branch Name</label>
                                                    <input type="text" name="branch_name" class="form-control" value="<?= htmlspecialchars($branch['name']) ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Address</label>
                                                    <input type="text" name="branch_address" class="form-control" value="<?= htmlspecialchars($branch['address']) ?>">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="edit_branch" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Add Branch Modal -->
            <div class="modal fade" id="addBranchModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post">
                            <div class="modal-header">
                                <h5 class="modal-title">Add Branch</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Branch Name</label>
                                    <input type="text" name="branch_name" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="branch_address" class="form-control">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="add_branch" class="btn btn-success">Add Branch</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Image Cropping Modal -->
            <div class="modal fade" id="cropModal" tabindex="-1" data-bs-backdrop="false" data-bs-keyboard="false" style="z-index: 99999 !important; position: fixed !important;">
                <div class="modal-dialog modal-lg" style="z-index: 100000 !important; position: relative !important;">
                    <div class="modal-content" style="z-index: 100001 !important; position: relative !important;
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-crop me-2"></i>Crop Profile Picture
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="img-container">
                                        <img id="crop-image" src="" alt="Crop Image" style="max-width: 100%; display: block;">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="crop-preview">
                                        <h6>Preview</h6>
                                        <div class="preview-container">
                                            <div class="preview-image"></div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <h6>Crop Options</h6>
                                        <div class="mb-2">
                                            <label class="form-label">Aspect Ratio</label>
                                            <select class="form-select" id="aspect-ratio">
                                                <option value="1">1:1 (Square)</option>
                                                <option value="4/3">4:3</option>
                                                <option value="3/4">3:4</option>
                                                <option value="16/9">16:9</option>
                                                <option value="NaN">Free</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Rotation</label>
                                            <input type="range" class="form-range" id="rotation" min="-180" max="180" value="0">
                                            <div class="text-center mt-1">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="rotate-left">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="rotate-right">
                                                    <i class="bi bi-arrow-clockwise"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">Zoom</label>
                                            <input type="range" class="form-range" id="zoom" min="0.1" max="3" step="0.1" value="1">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="crop-btn">
                                <i class="bi bi-check me-2"></i>Crop & Save
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <hr>

            <!-- 5. Backup/Restore Database -->
            <h4><i class="bi bi-database me-2"></i>Backup/Restore Database</h4>
            <form method="post" enctype="multipart/form-data" class="mb-3">
                <button type="submit" name="backup_db" class="btn btn-info me-2"><i class="bi bi-download"></i> Backup Database</button>
                <input type="file" name="restore_file" accept=".sql" class="form-control d-inline-block w-auto" style="display:inline-block; width:auto;">
                <button type="submit" name="restore_db" class="btn btn-warning"><i class="bi bi-upload"></i> Restore Database</button>
            </form>
        </div>
    </div>
</div>

<style>
/* Cropping modal styles */
.img-container {
    max-height: 400px;
    margin-bottom: 1rem;
}

.img-container img {
    max-width: 100%;
    max-height: 100%;
}

/* Profile picture card improvements */
.profile-picture-card {
    background: #f8f9fa;
    border-radius: 18px;
    box-shadow: 0 2px 16px rgba(0,0,0,0.07);
    padding: 32px 0 24px 0;
    margin-bottom: 16px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.profile-picture-flex {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
}

.profile-picture-container {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 12px;
    margin-bottom: 8px;
}

.profile-picture-container img {
    border: 5px solid #007bff;
    box-shadow: 0 6px 24px rgba(0,123,255,0.18);
    width: 160px;
    height: 160px;
    object-fit: cover;
    border-radius: 50%;
    background: #fff;
    transition: box-shadow 0.2s;
}

.profile-picture-container img:hover {
    box-shadow: 0 8px 32px rgba(0,123,255,0.25);
}

.preview-container {
    width: 150px;
    height: 150px;
    border: 1px solid #ddd;
    border-radius: 50%;
    overflow: hidden;
    margin: 0 auto;
    background-color: #f8f9fa;
}

.preview-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.crop-preview h6 {
    text-align: center;
    margin-bottom: 1rem;
    color: #6c757d;
}

/* Cropper.js custom styles */
.cropper-view-box,
.cropper-face {
    border-radius: 50%;
}

.cropper-view-box {
    outline: 2px solid #007bff;
    outline-color: rgba(0, 123, 255, 0.75);
}

.cropper-line,
.cropper-point {
    background-color: #007bff;
}

.cropper-bg {
    background-image: linear-gradient(45deg, #f0f0f0 25%, transparent 25%), 
                      linear-gradient(-45deg, #f0f0f0 25%, transparent 25%), 
                      linear-gradient(45deg, transparent 75%, #f0f0f0 75%), 
                      linear-gradient(-45deg, transparent 75%, #f0f0f0 75%);
    background-size: 20px 20px;
    background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
}

/* Form check styling */
.form-check-input:checked {
    background-color: #007bff;
    border-color: #007bff;
}

.form-check-label {
    cursor: pointer;
    user-select: none;
}

/* Modal z-index fixes */
.modal {
    z-index: 99999 !important;
    position: fixed !important;
}

.modal-backdrop {
    z-index: 99998 !important;
    display: none !important; /* Disable backdrop completely */
}

.modal-dialog {
    z-index: 100000 !important;
    position: relative !important;
}

.modal-content {
    z-index: 100001 !important;
    position: relative !important;
    background: white !important;
    border: 2px solid #007bff !important;
    box-shadow: 0 10px 50px rgba(0,0,0,0.5) !important;
}

/* Force modal to be clickable */
.modal.show {
    pointer-events: auto !important;
}

.modal.show .modal-dialog {
    pointer-events: auto !important;
}

.modal.show .modal-content {
    pointer-events: auto !important;
}

/* Ensure sidebar doesn't interfere */
.sidebar {
    z-index: 1000 !important;
}

/* Ensure main content doesn't interfere with modal */
.main-content {
    position: relative !important;
    z-index: 1 !important;
}

/* Fix for modal positioning */
body.modal-open {
    overflow: hidden !important;
}

body.modal-open .modal {
    overflow-x: hidden !important;
    overflow-y: auto !important;
}
</style>

<script>
// Function to hide success message
function hideSuccessMessage() {
    const successMessage = document.getElementById('profile-success-message');
    if (successMessage) {
        successMessage.style.display = 'none';
    }
}

// Global variables for cropping
let cropper = null;
let cropModal = null;

// Profile picture preview and cropping functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('Profile picture cropping script loaded');
    
    // Wait for all scripts to load
    setTimeout(function() {
        initializeCropping();
    }, 100);
});

function initializeCropping() {
    const fileInput = document.getElementById('profile-picture-input');
    const previewContainer = document.querySelector('.profile-picture-container');
    const enableCropCheckbox = document.getElementById('enable-crop');
    const uploadBtn = document.getElementById('upload-btn');
    const cropModalElement = document.getElementById('cropModal');
    const cropImage = document.getElementById('crop-image');
    const cropBtn = document.getElementById('crop-btn');
    const aspectRatioSelect = document.getElementById('aspect-ratio');
    const rotationRange = document.getElementById('rotation');
    const zoomRange = document.getElementById('zoom');
    const rotateLeftBtn = document.getElementById('rotate-left');
    const rotateRightBtn = document.getElementById('rotate-right');
    const profileForm = document.getElementById('profile-picture-form');
    
    // Debug: Check if elements exist
    console.log('Elements found:', {
        fileInput: !!fileInput,
        previewContainer: !!previewContainer,
        enableCropCheckbox: !!enableCropCheckbox,
        cropModalElement: !!cropModalElement,
        cropImage: !!cropImage,
        cropBtn: !!cropBtn,
        profileForm: !!profileForm,
        bootstrap: typeof bootstrap !== 'undefined',
        Cropper: typeof Cropper !== 'undefined'
    });
    
    // Initialize Bootstrap modal with error handling
    let cropModal = null;
    if (cropModalElement && typeof bootstrap !== 'undefined') {
        try {
            cropModal = new bootstrap.Modal(cropModalElement, {
                backdrop: false,  // Disable backdrop completely
                keyboard: false,
                focus: true
            });
            console.log('Bootstrap modal initialized successfully');
        } catch (error) {
            console.error('Error initializing Bootstrap modal:', error);
        }
    } else {
        console.error('Bootstrap or modal element not available');
    }
    
    // Prevent form submission when cropping is enabled and file is selected
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            if (enableCropCheckbox && enableCropCheckbox.checked && fileInput && fileInput.files.length > 0) {
                console.log('Form submission prevented - cropping enabled');
                e.preventDefault();
                alert('Please use the cropping tool first, or disable cropping to upload directly.');
                return false;
            }
        });
    }
    
    if (fileInput && previewContainer) {
        fileInput.addEventListener('change', function(e) {
            console.log('File input changed');
            const file = e.target.files[0];
            if (file) {
                console.log('File selected:', file.name, file.type, file.size);
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, or GIF).');
                    this.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB.');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    console.log('File loaded, enable crop checked:', enableCropCheckbox.checked);
                    
                    if (enableCropCheckbox && enableCropCheckbox.checked) {
                        if (!cropModal) {
                            alert('Cropping modal is not available. Please disable cropping or refresh the page.');
                            return;
                        }
                        
                        console.log('Opening crop modal');
                        // Show cropping modal
                        if (cropImage) {
                            cropImage.src = e.target.result;
                            cropImage.style.maxWidth = '100%';
                            cropImage.style.maxHeight = '400px';
                        }
                        
                        try {
                            cropModal.show();
                            console.log('Modal shown successfully');
                            
                            // Initialize cropper after a short delay
                            setTimeout(function() {
                                initializeCropper(cropImage);
                            }, 300);
                            
                        } catch (error) {
                            console.error('Error showing modal:', error);
                            alert('Error opening cropping tool. Please try again.');
                        }
                    } else {
                        console.log('Direct upload without cropping');
                        // Direct upload without cropping - just show preview
                        showPreview(e.target.result);
                    }
                };
                reader.onerror = function(error) {
                    console.error('Error reading file:', error);
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    let cropper = null;
    
    function initializeCropper(image) {
        console.log('Initializing cropper...');
        
        if (!image || typeof Cropper === 'undefined') {
            console.error('Image element or Cropper library not available');
            return;
        }
        
        // Destroy existing cropper if any
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        
        try {
            cropper = new Cropper(image, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.8,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                preview: '.preview-image',
                ready: function() {
                    console.log('Cropper initialized and ready');
                },
                error: function(error) {
                    console.error('Cropper error:', error);
                }
            });
        } catch (error) {
            console.error('Error creating cropper:', error);
            alert('Error initializing image cropper. Please try again.');
        }
    }
    
    // Handle aspect ratio change
    if (aspectRatioSelect) {
        aspectRatioSelect.addEventListener('change', function() {
            if (cropper) {
                const ratio = this.value === 'NaN' ? NaN : parseFloat(this.value);
                cropper.setAspectRatio(ratio);
            }
        });
    }
    
    // Handle rotation range
    if (rotationRange) {
        rotationRange.addEventListener('input', function() {
            if (cropper) {
                cropper.rotateTo(parseFloat(this.value));
            }
        });
    }
    
    // Handle zoom range
    if (zoomRange) {
        zoomRange.addEventListener('input', function() {
            if (cropper) {
                cropper.zoomTo(parseFloat(this.value));
            }
        });
    }
    
    // Handle rotate buttons
    if (rotateLeftBtn) {
        rotateLeftBtn.addEventListener('click', function() {
            if (cropper) {
                cropper.rotate(-90);
                if (rotationRange) {
                    rotationRange.value = (parseFloat(rotationRange.value) - 90) % 360;
                }
            }
        });
    }
    
    if (rotateRightBtn) {
        rotateRightBtn.addEventListener('click', function() {
            if (cropper) {
                cropper.rotate(90);
                if (rotationRange) {
                    rotationRange.value = (parseFloat(rotationRange.value) + 90) % 360;
                }
            }
        });
    }
    
    // Handle crop and save button
    if (cropBtn) {
        cropBtn.addEventListener('click', function() {
            console.log('Crop button clicked');
            if (!cropper) {
                console.error('Cropper not initialized');
                alert('Cropper not initialized. Please try selecting the image again.');
                return;
            }
            
            console.log('Getting cropped canvas');
            try {
                // Show loading state
                cropBtn.disabled = true;
                cropBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                
                const canvas = cropper.getCroppedCanvas({
                    width: 400,
                    height: 400,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });
                
                if (canvas) {
                    console.log('Canvas created successfully, dimensions:', canvas.width + 'x' + canvas.height);
                    const croppedImageData = canvas.toDataURL('image/jpeg', 0.9);
                    console.log('Image data created, length:', croppedImageData.length);
                    console.log('Data URL prefix:', croppedImageData.substring(0, 50));
                    
                    if (croppedImageData.length > 1000) { // Basic validation
                        uploadCroppedImage(croppedImageData);
                    } else {
                        console.error('Generated image data too small:', croppedImageData.length);
                        alert('Generated image appears to be invalid. Please try again.');
                        // Reset button
                        cropBtn.disabled = false;
                        cropBtn.innerHTML = '<i class="bi bi-check me-2"></i>Crop & Save';
                    }
                } else {
                    console.error('Failed to create canvas');
                    alert('Failed to process the cropped image. Please try again.');
                    // Reset button
                    cropBtn.disabled = false;
                    cropBtn.innerHTML = '<i class="bi bi-check me-2"></i>Crop & Save';
                }
            } catch (error) {
                console.error('Error processing cropped image:', error);
                alert('Error processing the image: ' + error.message);
                // Reset button
                cropBtn.disabled = false;
                cropBtn.innerHTML = '<i class="bi bi-check me-2"></i>Crop & Save';
            }
        });
    }
    
    // Function to show preview without cropping
    function showPreview(imageData) {
        const img = previewContainer.querySelector('img');
        if (img) {
            img.src = imageData;
        } else {
            const newImg = document.createElement('img');
            newImg.src = imageData;
            newImg.alt = 'Profile Picture Preview';
            newImg.className = 'img-fluid rounded-circle';
            newImg.style = 'width: 160px; height: 160px; object-fit: cover; border: 5px solid #007bff; box-shadow: 0 6px 24px rgba(0,123,255,0.18);';
            previewContainer.innerHTML = '';
            previewContainer.appendChild(newImg);
        }
    }
    
    // Function to upload cropped image
    function uploadCroppedImage(imageData) {
        console.log('Uploading cropped image, data length:', imageData.length);
        console.log('Image data preview:', imageData.substring(0, 100));
        
        // Validate image data format
        if (!imageData || !imageData.startsWith('data:image/')) {
            console.error('Invalid image data format');
            alert('Invalid image data format. Please try again.');
            return;
        }
        
        try {
            // Create a hidden form to submit the cropped image
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cropped_image';
            input.value = imageData;
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'upload_cropped_image';
            submitInput.value = '1';
            
            form.appendChild(input);
            form.appendChild(submitInput);
            document.body.appendChild(form);
            
            console.log('Form created with data length:', imageData.length);
            console.log('Hiding modal and submitting form');
            
            // Close modal first
            const modal = document.getElementById('cropModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            
            // Small delay to ensure modal is hidden before form submission
            setTimeout(() => {
                console.log('Submitting form now');
                form.submit();
            }, 300);
        } catch (error) {
            console.error('Error uploading cropped image:', error);
            alert('Error uploading the image. Please try again.');
        }
    }
    
    // Handle modal hidden event to clean up cropper
    if (cropModalElement) {
        cropModalElement.addEventListener('hidden.bs.modal', function() {
            console.log('Modal hidden, cleaning up cropper');
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            // Reset form inputs
            if (fileInput) fileInput.value = '';
            if (rotationRange) rotationRange.value = 0;
            if (zoomRange) zoomRange.value = 1;
            if (aspectRatioSelect) aspectRatioSelect.value = '1';
        });
    }
}
</script>

<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/cropper.min.js"></script>

<!-- JavaScript to prevent FOUC -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Show body with fade-in effect
        document.body.classList.add('loaded');
        
        // Show main content after brief delay to ensure CSS is applied
        setTimeout(function() {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.classList.add('loaded');
            }
        }, 50);
        
        // Preload logo image
        const logo = document.querySelector('.sidebar-logo');
        if (logo) {
            logo.onload = function() {
                this.classList.add('loaded');
            };
        }
    });
    
    // Alternative: Show content immediately if DOM is already loaded
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        document.body.classList.add('loaded');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.classList.add('loaded');
        }
    }
</script>
</body>
</html>
