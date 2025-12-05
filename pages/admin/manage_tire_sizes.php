<?php
/**
 * Manage Tire Sizes Page - Admin
 * 
 * CRUD interface for managing tire sizes with delete functionality
 * 
 * @author JohnTech Development Team
 * @version 2.0
 */

include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// Error reporting (disable in production for security)
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../../includes/admin_sidebar.php';

// Initialize variables
$errors = [];
$success = '';
$edit_id = null;
$edit_size_value = '';
$edit_description = '';

// ====================================================================
// HANDLE FORM SUBMISSIONS
// ====================================================================

// Ensure tire_sizes table exists (create if needed)
$table_check = $conn->query("SHOW TABLES LIKE 'tire_sizes'");
$use_tire_sizes = $table_check->num_rows > 0;

if (!$use_tire_sizes) {
    // Create new tire_sizes table
    $create_table = "CREATE TABLE IF NOT EXISTS tire_sizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        size_value VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_size_value (size_value)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if ($conn->query($create_table)) {
        $use_tire_sizes = true;
    } else {
        $errors[] = 'Error creating tire_sizes table: ' . $conn->error;
    }
} else {
    // Table exists - verify structure and remove status column if it exists
    $columns_check = $conn->query("SHOW COLUMNS FROM tire_sizes LIKE 'size_value'");
    if ($columns_check->num_rows == 0) {
        // Table exists but wrong structure - recreate it
        $conn->query("DROP TABLE IF EXISTS tire_sizes");
        $create_table = "CREATE TABLE tire_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            size_value VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_size_value (size_value)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($create_table)) {
            $errors[] = 'Error recreating tire_sizes table: ' . $conn->error;
        }
    } else {
        // Remove status column if it exists (migration to remove status)
        $status_col_check = $conn->query("SHOW COLUMNS FROM tire_sizes LIKE 'status'");
        if ($status_col_check->num_rows > 0) {
            // Remove status column and its index
            $conn->query("ALTER TABLE tire_sizes DROP COLUMN status");
            // Try to remove index (may not exist, ignore error)
            $conn->query("ALTER TABLE tire_sizes DROP INDEX idx_status");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Tire Size
    if (isset($_POST['add_tire_size'])) {
        $size_value = trim($_POST['size_value'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($size_value)) {
            $errors[] = 'Tire size is required.';
        } else {
            // Check if tire size already exists
            $stmt = $conn->prepare("SELECT id FROM tire_sizes WHERE size_value = ?");
            $stmt->bind_param("s", $size_value);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = 'Tire size already exists.';
            } else {
                // Insert new tire size
                $insert_stmt = $conn->prepare("INSERT INTO tire_sizes (size_value, description) VALUES (?, ?)");
                if (!$insert_stmt) {
                    $errors[] = 'Prepare failed: ' . $conn->error;
                } else {
                    $insert_stmt->bind_param("ss", $size_value, $description);
                    if ($insert_stmt->execute()) {
                        if ($insert_stmt->affected_rows > 0) {
                            $success = 'Tire size added successfully!';
                        } else {
                            $errors[] = 'Tire size was not inserted. No rows affected.';
                        }
                    } else {
                        $errors[] = 'Error adding tire size: ' . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
            }
            $stmt->close();
        }
    }
    
    // Edit Tire Size
    if (isset($_POST['edit_tire_size'])) {
        $tire_size_id = intval($_POST['tire_size_id'] ?? 0);
        $size_value = trim($_POST['size_value'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if ($tire_size_id <= 0) {
            $errors[] = 'Invalid tire size ID.';
        } elseif (empty($size_value)) {
            $errors[] = 'Tire size is required.';
        } else {
            // Check if size already exists (excluding current tire size)
            $stmt = $conn->prepare("SELECT id FROM tire_sizes WHERE size_value = ? AND id != ?");
            $stmt->bind_param("si", $size_value, $tire_size_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = 'Tire size already exists.';
            } else {
                $update_stmt = $conn->prepare("UPDATE tire_sizes SET size_value = ?, description = ? WHERE id = ?");
                $update_stmt->bind_param("ssi", $size_value, $description, $tire_size_id);
                if ($update_stmt->execute()) {
                    $success = 'Tire size updated successfully!';
                } else {
                    $errors[] = 'Error updating tire size: ' . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $stmt->close();
        }
    }
    
    // Delete Tire Size (Permanent)
    if (isset($_POST['delete_tire_size'])) {
        $tire_size_id = intval($_POST['tire_size_id'] ?? 0);
        
        if ($tire_size_id <= 0) {
            $errors[] = 'Invalid tire size ID.';
        } else {
            // Check if tire size is used by products
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE tire_size_id = ?");
            $check_stmt->bind_param("i", $tire_size_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($check_data['count'] > 0) {
                $errors[] = 'Cannot delete tire size. It is currently used by ' . $check_data['count'] . ' product(s). Please update or remove those products first.';
            } else {
                // Permanently delete the tire size
                $delete_stmt = $conn->prepare("DELETE FROM tire_sizes WHERE id = ?");
                $delete_stmt->bind_param("i", $tire_size_id);
                if ($delete_stmt->execute()) {
                    if ($delete_stmt->affected_rows > 0) {
                        $success = 'Tire size deleted successfully!';
                    } else {
                        $errors[] = 'Tire size was not deleted.';
                    }
                } else {
                    $errors[] = 'Error deleting tire size: ' . $delete_stmt->error;
                }
                $delete_stmt->close();
            }
        }
    }
}

// Get edit ID from GET parameter
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, size_value, description FROM tire_sizes WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
        $edit_size_value = $edit_data['size_value'];
        $edit_description = $edit_data['description'] ?? '';
    } else {
        $edit_id = null;
    }
    $stmt->close();
}

// ====================================================================
// FETCH TIRE SIZES
// ====================================================================

$tire_sizes = [];

// Check if tire_sizes table exists
$table_check = $conn->query("SHOW TABLES LIKE 'tire_sizes'");
if ($table_check->num_rows > 0) {
    $result = $conn->query("SELECT id, size_value, description, created_at FROM tire_sizes ORDER BY size_value ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tire_sizes[] = $row;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tire Sizes - JohnTech Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    
    <!-- Critical CSS for layout stability -->
    <style>
        body { background: #f8fafc !important; margin: 0 !important; padding: 0 !important; }
        .sidebar { background: linear-gradient(180deg, #1a1d23 0%, #23272b 100%) !important; width: 250px !important; height: 100vh !important; position: fixed !important; top: 0 !important; left: 0 !important; z-index: 1050 !important; }
        .container-fluid { margin-left: 250px !important; }
    </style>
</head>
<body>
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
    
    <nav aria-label="breadcrumb" class="breadcrumb-modern">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="admin_dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="Inventory_management.php">Inventory</a></li>
            <li class="breadcrumb-item active" aria-current="page">Manage Tire Sizes</li>
        </ol>
    </nav>

    <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-rulers me-2"></i>Manage Tire Sizes</h2>
    
    <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
      <?php if ($success): ?>
      <div class="alert alert-success alert-dismissible fade show position-relative" role="alert" style="padding-right: 3rem;">
        <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn btn-sm p-0 border-0 bg-transparent position-absolute top-50 end-0 translate-middle-y me-2" data-bs-dismiss="alert" aria-label="Close" title="Close">
            <i class="bi bi-x-circle-fill" style="font-size:1.1rem; color:#64748b;"></i>
        </button>
      </div>
      <?php endif; ?>
      <?php if ($errors): ?>
      <div class="alert alert-danger alert-dismissible fade show position-relative" role="alert" style="padding-right: 3rem;">
        <i class="bi bi-exclamation-triangle me-2"></i><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
        <button type="button" class="btn btn-sm p-0 border-0 bg-transparent position-absolute top-50 end-0 translate-middle-y me-2" data-bs-dismiss="alert" aria-label="Close" title="Close">
            <i class="bi bi-x-circle-fill" style="font-size:1.1rem; color:#64748b;"></i>
        </button>
      </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <a href="Inventory_management.php#addProduct" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Add Product
          </a>
        </div>
      </div>

      <!-- Add/Edit Form -->
      <div class="card mb-4" style="border: 1px solid #e2e8f0;">
        <div class="card-header bg-light">
          <h5 class="mb-0">
            <i class="bi bi-plus-circle me-2"></i>
            <?= $edit_id ? 'Edit Tire Size' : 'Add New Tire Size' ?>
          </h5>
        </div>
        <div class="card-body">
          <form method="POST">
            <?php if ($edit_id): ?>
              <input type="hidden" name="tire_size_id" value="<?= $edit_id ?>">
            <?php endif; ?>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Tire Size <span class="text-danger">*</span></label>
                <input type="text" 
                       name="size_value" 
                       class="form-control" 
                       value="<?= htmlspecialchars($edit_size_value) ?>" 
                       placeholder="e.g., 80/90-14" 
                       required>
              </div>
              <div class="col-md-5">
                <label class="form-label">Description (Optional)</label>
                <input type="text" 
                       name="description" 
                       class="form-control" 
                       value="<?= htmlspecialchars($edit_description) ?>" 
                       placeholder="Optional description">
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <button type="submit" 
                        name="<?= $edit_id ? 'edit_tire_size' : 'add_tire_size' ?>" 
                        class="btn btn-primary w-100">
                  <i class="bi bi-<?= $edit_id ? 'check' : 'plus' ?>-circle me-1"></i>
                  <?= $edit_id ? 'Update' : 'Add Tire Size' ?>
                </button>
                <?php if ($edit_id): ?>
                  <a href="manage_tire_sizes.php" class="btn btn-secondary ms-2">Cancel</a>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Tire Sizes List -->
      <div class="mb-4">
        <h5 class="mb-3"><i class="bi bi-rulers text-primary me-2"></i>Tire Sizes (<?= count($tire_sizes) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th style="width: 80px;">ID</th>
                <th>Tire Size</th>
                <th>Description</th>
                <th style="width: 150px;">Created</th>
                <th style="width: 200px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($tire_sizes)): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted">No tire sizes found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($tire_sizes as $size): ?>
                  <tr>
                    <td><?= $size['id'] ?></td>
                    <td><strong><?= htmlspecialchars($size['size_value']) ?></strong></td>
                    <td><?= htmlspecialchars($size['description'] ?? '-') ?></td>
                    <td><?= date('M d, Y', strtotime($size['created_at'])) ?></td>
                    <td>
                      <a href="?edit=<?= $size['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-pencil"></i> Edit
                      </a>
                      <form method="POST" style="display:inline-block;" onsubmit="return confirm('⚠️ WARNING: This will permanently delete this tire size from the database. This action cannot be undone!\\n\\nAre you sure you want to delete \"<?= htmlspecialchars($size['size_value']) ?>\"?');">
                        <input type="hidden" name="tire_size_id" value="<?= $size['id'] ?>">
                        <button type="submit" name="delete_tire_size" class="btn btn-sm btn-danger">
                          <i class="bi bi-trash"></i> Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .breadcrumb-modern {
    background: transparent;
    padding: 0.5rem 0;
    margin-bottom: 1rem;
  }
  
  .breadcrumb-modern .breadcrumb-item a {
    color: #64748b;
    text-decoration: none;
    font-size: 0.875rem;
    transition: color 0.15s ease;
  }
  
  .breadcrumb-modern .breadcrumb-item a:hover {
    color: #1565c0;
  }
  
  .breadcrumb-modern .breadcrumb-item.active {
    color: #1a202c;
    font-weight: 500;
  }
</style>

<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>

<script>
    // Force content visibility immediately
    (function() {
        document.body.classList.add('loaded');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.visibility = 'visible';
            mainContent.style.display = 'block';
            mainContent.classList.add('loaded');
        }
    })();
    
    // Ensure content is visible after page load
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('loaded');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.visibility = 'visible';
            mainContent.style.display = 'block';
            mainContent.classList.add('loaded');
        }
    });
    
    // Also check if page is already loaded
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        document.body.classList.add('loaded');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.visibility = 'visible';
            mainContent.style.display = 'block';
            mainContent.classList.add('loaded');
        }
    }
    
    // Window load event as fallback
    window.addEventListener('load', function() {
        document.body.classList.add('loaded');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.visibility = 'visible';
            mainContent.style.display = 'block';
            mainContent.classList.add('loaded');
        }
    });
</script>

</body>
</html>

