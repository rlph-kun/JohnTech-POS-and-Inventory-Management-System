<?php
/*
======================================================================
  Page: Edit Cashier
  Description: Admin interface to update an existing cashier's account
               details, including credentials and profile picture.
  Notes:
  - Preserves the same functionality and UI/UX.
  - Adds structured section headers for maintainability.
  - Commenting style aligns with admin dashboard pages.
======================================================================
*/
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);
include '../../includes/admin_sidebar.php';

$errors = [];
$success = '';
$cashier = null;

// ====================================================================
// Get cashier ID from URL
// ====================================================================
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$user_id) {
    header("Location: cashier_management.php");
    exit();
}

// ====================================================================
// Get cashier data
// ====================================================================
$stmt = $conn->prepare("SELECT u.id as user_id, u.username, cp.name, cp.branch_id, cp.contact, cp.email, cp.profile_picture FROM users u JOIN cashier_profiles cp ON u.id = cp.user_id WHERE u.id = ? AND u.role = 'cashier'");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$cashier = $result->fetch_assoc();
$stmt->close();

if (!$cashier) {
    header("Location: cashier_management.php");
    exit();
}

// ====================================================================
// Check for success message from redirect
// ====================================================================
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Cashier account updated successfully!';
}

// ====================================================================
// Handle form submission
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cashier'])) {
    // Debug: Add error reporting and logging
    error_log("Edit form submitted for user ID: " . $user_id);
    error_log("POST data: " . print_r($_POST, true));
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $name = trim($_POST['name']);
    $branch_id = intval($_POST['branch_id']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    
    // Debug: Log the extracted values
    error_log("Extracted values - Username: $username, Name: $name, Branch: $branch_id, Contact: $contact");
    
    // Validate contact: must be exactly 11 digits and only numbers
    if (!preg_match('/^\d{11}$/', $contact)) {
        $errors[] = 'Contact must be exactly 11 digits and contain only numbers.';
        error_log("Contact validation failed: " . $contact);
    }
    
    if ($username && $name && $branch_id && $contact && empty($errors)) {
        error_log("Validation passed, proceeding with update");
        // Check if username is taken by another user
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt_check->bind_param('si', $username, $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
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
                        // Delete old profile picture
                        if ($cashier['profile_picture'] && file_exists('../../assets/uploads/profile_pictures/' . $cashier['profile_picture'])) {
                            unlink('../../assets/uploads/profile_pictures/' . $cashier['profile_picture']);
                        }
                        
                        $stmt3 = $conn->prepare("UPDATE cashier_profiles SET name=?, branch_id=?, contact=?, email=?, profile_picture=? WHERE user_id=?");
                        $stmt3->bind_param('sisssi', $name, $branch_id, $contact, $email, $profile_picture, $user_id);
                    } else {
                        $stmt3 = $conn->prepare("UPDATE cashier_profiles SET name=?, branch_id=?, contact=?, email=? WHERE user_id=?");
                        $stmt3->bind_param('sissi', $name, $branch_id, $contact, $email, $user_id);
                    }
                    
                    if ($stmt3->execute()) {
                        // Redirect to prevent form resubmission on page refresh
                        header("Location: edit_cashier.php?id=" . $user_id . "&success=1");
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
        $stmt_check->close();
    } else {
        error_log("Validation failed - Username: '$username', Name: '$name', Branch: '$branch_id', Contact: '$contact', Errors: " . print_r($errors, true));
        $errors[] = 'All fields except email and password are required.';
    }
}

// ====================================================================
// Get branches for dropdown
// ====================================================================
$branches = [];
$branch_res = $conn->query("SELECT id, name FROM branches");
while ($row = $branch_res->fetch_assoc()) {
    $branches[$row['id']] = $row['name'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Edit Cashier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    <!-- Cropper.js CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet"/>
</head>
<body style="margin: 0 !important; padding: 0 !important;">

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
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0" style="color: #1a202c !important; font-size: 1.75rem;">
                <i class="bi bi-pencil-square me-2"></i>Edit Cashier
            </h2>
            <a href="cashier_management.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-2"></i>Back to Cashier Management
            </a>
        </div>
        
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 2rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= $success ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><strong>Error:</strong><br>
                    <?= implode('<br>', $errors) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" onsubmit="console.log('Form submitted!'); return true;">
                <!-- Hidden input to ensure we can detect the form submission -->
                <input type="hidden" name="update_cashier" value="1">
                <!-- Profile Picture Section -->
                <div class="row mb-4">
                    <div class="col-md-4 text-center">
                        <div class="mb-3">
                            <?php if ($cashier['profile_picture'] && file_exists('../../assets/uploads/profile_pictures/' . $cashier['profile_picture'])): ?>
                                <img src="<?= BASE_URL ?>/assets/uploads/profile_pictures/<?= htmlspecialchars($cashier['profile_picture']) ?>" 
                                     alt="Current Profile Picture" 
                                     class="rounded-circle mb-2" 
                                     style="width: 150px; height: 150px; object-fit: cover; border: 3px solid #007bff;">
                            <?php else: ?>
                                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mb-2" 
                                     style="width: 150px; height: 150px; margin: 0 auto;">
                                    <i class="bi bi-person text-white" style="font-size: 4rem;"></i>
                                </div>
                            <?php endif; ?>
                            <div class="form-text">Current Profile Picture</div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Update Profile Picture</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/*" id="profilePictureInput">
                        <div class="form-text">JPG, PNG, or GIF up to 5MB. Leave empty to keep current picture.</div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($cashier['username']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Password <small class="text-muted">(leave blank to keep unchanged)</small></label>
                            <input type="password" name="password" class="form-control" autocomplete="new-password">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cashier['name']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Branch <span class="text-danger">*</span></label>
                            <select name="branch_id" class="form-select" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $id => $name): ?>
                                    <option value="<?= $id ?>" <?= $id == $cashier['branch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Contact <span class="text-danger">*</span></label>
                            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($cashier['contact']) ?>" required pattern="\d{11}" maxlength="11" minlength="11" inputmode="numeric" title="Contact must be exactly 11 digits">
                            <div class="form-text">Must be exactly 11 digits (numbers only)</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($cashier['email']) ?>">
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2 mt-4">
                    <button type="submit" name="update_cashier" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>Update Cashier
                    </button>
                    <a href="cashier_management.php" class="btn btn-secondary">
                        <i class="bi bi-x-lg me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cropper.js Modal -->
<div class="modal fade" id="cropModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Crop Profile Picture</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div style="max-width: 100%; height: 400px; margin: 0 auto;">
                    <img id="cropper-image" style="max-width: 100%; max-height: 100%;" />
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="crop-btn" class="btn btn-primary">Crop & Use</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Cropper.js JavaScript -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script>
let cropper;
let currentInput;

// Function to open cropper modal
function openCropper(input) {
    console.log('openCropper called with input:', input);
    const file = input.files[0];
    console.log('File object:', file);
    
    if (file) {
        console.log('File selected:', file.name, 'Type:', file.type, 'Size:', file.size);
        
        // Check if file is an image
        if (!file.type.startsWith('image/')) {
            console.error('Selected file is not an image');
            alert('Please select an image file.');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(event) {
            console.log('FileReader loaded successfully');
            const image = document.getElementById('cropper-image');
            console.log('Cropper image element:', image);
            
            if (!image) {
                console.error('Cropper image element not found!');
                return;
            }
            
            image.src = event.target.result;
            console.log('Image src set to:', event.target.result.substring(0, 50) + '...');
            
            // Store reference to input
            currentInput = input;
            
            // Show the crop modal
            const cropModalElement = document.getElementById('cropModal');
            console.log('Crop modal element:', cropModalElement);
            
            if (!cropModalElement) {
                console.error('Crop modal element not found!');
                return;
            }
            
            const cropModal = new bootstrap.Modal(cropModalElement);
            console.log('Bootstrap modal instance created:', cropModal);
            cropModal.show();
            
            // Initialize cropper after modal is shown
            cropModalElement.addEventListener('shown.bs.modal', function() {
                console.log('Modal shown event triggered, initializing cropper');
                if (cropper) {
                    console.log('Destroying existing cropper');
                    cropper.destroy();
                }
                
                try {
                    cropper = new Cropper(image, {
                        aspectRatio: 1, // Square crop
                        viewMode: 2,
                        autoCropArea: 0.8,
                        responsive: true,
                        restore: false,
                        guides: true,
                        center: true,
                        highlight: false,
                        cropBoxMovable: true,
                        cropBoxResizable: true,
                        toggleDragModeOnDblclick: false,
                    });
                    console.log('Cropper initialized successfully:', cropper);
                } catch (error) {
                    console.error('Error initializing cropper:', error);
                }
            }, { once: true });
        };
        
        reader.onerror = function(error) {
            console.error('FileReader error:', error);
        };
        
        reader.readAsDataURL(file);
    } else {
        console.log('No file provided to openCropper');
    }
}

// Handle crop button click
document.addEventListener('click', function(e) {
    if (e.target.id === 'crop-btn') {
        console.log('Crop button clicked');
        if (cropper) {
            // Get cropped canvas
            const canvas = cropper.getCroppedCanvas({
                width: 300,
                height: 300,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });
            
            // Convert canvas to blob
            canvas.toBlob(function(blob) {
                if (blob) {
                    // Create new File object from blob
                    const file = new File([blob], 'cropped-profile.jpg', {
                        type: 'image/jpeg',
                        lastModified: Date.now()
                    });
                    
                    // Create new FileList and assign to input
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    currentInput.files = dt.files;
                    
                    // Update preview if exists
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Update the preview image
                        const previewImg = document.querySelector('.rounded-circle img, .rounded-circle');
                        if (previewImg && previewImg.tagName === 'IMG') {
                            previewImg.src = e.target.result;
                        } else {
                            // Create new img element if placeholder div exists
                            const placeholder = document.querySelector('.bg-secondary.rounded-circle');
                            if (placeholder) {
                                const newImg = document.createElement('img');
                                newImg.src = e.target.result;
                                newImg.alt = 'Profile Preview';
                                newImg.className = 'rounded-circle mb-2';
                                newImg.style.cssText = 'width: 150px; height: 150px; object-fit: cover; border: 3px solid #007bff;';
                                placeholder.parentNode.replaceChild(newImg, placeholder);
                            }
                        }
                    };
                    reader.readAsDataURL(file);
                    
                    console.log('Cropped file assigned to input');
                    
                    // Close modal
                    const cropModal = bootstrap.Modal.getInstance(document.getElementById('cropModal'));
                    cropModal.hide();
                }
            }, 'image/jpeg', 0.9);
        }
    }
});

// Attach event listener to profile picture input
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded, setting up cropper');
    
    const profileInput = document.getElementById('profilePictureInput');
    console.log('Profile input element:', profileInput);
    
    if (profileInput) {
        console.log('Attaching change event listener to profile input');
        profileInput.addEventListener('change', function(e) {
            console.log('File input changed, files:', this.files);
            if (this.files && this.files[0]) {
                console.log('File detected, calling openCropper');
                openCropper(this);
            } else {
                console.log('No file detected');
            }
        });
    } else {
        console.error('Profile input element not found!');
    }
    
    // Form validation setup
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            console.log('Form submit event triggered');
            console.log('Form data:', new FormData(this));
            
            // Don't disable the button immediately - let the form submit first
            // We'll disable it after a small delay to ensure the button value is included
            setTimeout(() => {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    console.log('Disabling submit button after delay');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
                }
            }, 100);
            
            // Let the form submit normally
            return true;
        });
    } else {
        console.log('No form found on page');
    }
    
    // Auto-dismiss success and error messages after page load
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (alert.classList.contains('alert-success') || alert.classList.contains('alert-danger')) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        });
    }, 2000); // Auto-dismiss after 2 seconds (reduced from 3)
});
</script>

</body>
</html>
