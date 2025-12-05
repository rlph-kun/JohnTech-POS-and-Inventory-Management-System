<?php
/**
 * Manage Categories Page - Admin
 * 
 * CRUD interface for managing product categories with soft delete functionality
 * 
 * @author JohnTech Development Team
 * @version 2.0
 */

include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// Error reporting (disable in production for security)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Initialize variables
$errors = [];
$success = '';
$edit_id = null;
$edit_name = '';

// ====================================================================
// HANDLE FORM SUBMISSIONS
// ====================================================================

// Ensure categories table exists (create if needed)
$table_check = $conn->query("SHOW TABLES LIKE 'categories'");
$use_categories = $table_check->num_rows > 0;

if (!$use_categories) {
    // Check if old category table exists
    $old_table_check = $conn->query("SHOW TABLES LIKE 'category'");
    if ($old_table_check->num_rows > 0) {
        // Create categories table and migrate
        $create_table = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_category_name (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($create_table)) {
                    // Migrate existing data (handle collation mismatch)
                    $migrate_result = $conn->query("INSERT INTO categories (category_name, created_at) 
                         SELECT name COLLATE utf8mb4_unicode_ci, created_at FROM category 
                         WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name COLLATE utf8mb4_unicode_ci = category.name COLLATE utf8mb4_unicode_ci)");
            if (!$migrate_result) {
                $errors[] = 'Migration warning: ' . $conn->error;
            }
            $use_categories = true;
        } else {
            $errors[] = 'Error creating categories table: ' . $conn->error;
        }
    } else {
        // Create new categories table
        $create_table = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_category_name (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ($conn->query($create_table)) {
            $use_categories = true;
        } else {
            $errors[] = 'Error creating categories table: ' . $conn->error;
        }
    }
    } else {
        // Table exists - verify structure and remove status column if it exists
        $columns_check = $conn->query("SHOW COLUMNS FROM categories LIKE 'category_name'");
        if ($columns_check->num_rows == 0) {
            // Table exists but wrong structure - recreate it
            $conn->query("DROP TABLE IF EXISTS categories");
            $create_table = "CREATE TABLE categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_category_name (category_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($create_table)) {
                $errors[] = 'Error recreating categories table: ' . $conn->error;
            }
        } else {
            // Remove status column if it exists (migration to remove status)
            $status_col_check = $conn->query("SHOW COLUMNS FROM categories LIKE 'status'");
            if ($status_col_check->num_rows > 0) {
                // Remove status column and its index
                $conn->query("ALTER TABLE categories DROP COLUMN status");
                // Try to remove index (may not exist, ignore error)
                $conn->query("ALTER TABLE categories DROP INDEX idx_status");
            }
        }
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Category
    if (isset($_POST['add_category'])) {
        $category_name = trim($_POST['category_name'] ?? '');
        
        if (empty($category_name)) {
            $errors[] = 'Category name is required.';
        } else {
            // Use categories table (should already exist from above)
            // Check if category already exists
            $stmt = $conn->prepare("SELECT id FROM categories WHERE category_name = ?");
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = 'Category already exists.';
            } else {
                // Insert new category (without status field)
                $insert_stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
                if (!$insert_stmt) {
                    $errors[] = 'Prepare failed: ' . $conn->error;
                } else {
                    $insert_stmt->bind_param("s", $category_name);
                    if ($insert_stmt->execute()) {
                        // Check if row was actually inserted
                        if ($insert_stmt->affected_rows > 0) {
                            $success = 'Category added successfully!';
                        } else {
                            $errors[] = 'Category was not inserted. No rows affected.';
                        }
                    } else {
                        $errors[] = 'Error adding category: ' . $insert_stmt->error;
                    }
                    $insert_stmt->close();
                }
            }
            $stmt->close();
        }
    }
    
    // Edit Category
    if (isset($_POST['edit_category'])) {
        $category_id = intval($_POST['category_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        
        if ($category_id <= 0) {
            $errors[] = 'Invalid category ID.';
        } elseif (empty($category_name)) {
            $errors[] = 'Category name is required.';
        } else {
            // Check if name already exists (excluding current category)
            $stmt = $conn->prepare("SELECT id FROM categories WHERE category_name = ? AND id != ?");
            $stmt->bind_param("si", $category_name, $category_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = 'Category name already exists.';
            } else {
                $update_stmt = $conn->prepare("UPDATE categories SET category_name = ? WHERE id = ?");
                $update_stmt->bind_param("si", $category_name, $category_id);
                if ($update_stmt->execute()) {
                    $success = 'Category updated successfully!';
                } else {
                    $errors[] = 'Error updating category: ' . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $stmt->close();
        }
    }
    
    // Delete Category (Permanent)
    if (isset($_POST['delete_category'])) {
        $category_id = intval($_POST['category_id'] ?? 0);
        
        if ($category_id <= 0) {
            $errors[] = 'Invalid category ID.';
        } else {
            // Check if category is used by products
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
            $check_stmt->bind_param("i", $category_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($check_data['count'] > 0) {
                $errors[] = 'Cannot delete category. It is currently used by ' . $check_data['count'] . ' product(s). Please update or remove those products first.';
            } else {
                // Permanently delete the category
                $delete_stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $delete_stmt->bind_param("i", $category_id);
                if ($delete_stmt->execute()) {
                    if ($delete_stmt->affected_rows > 0) {
                        $success = 'Category deleted successfully!';
                    } else {
                        $errors[] = 'Category was not deleted.';
                    }
                } else {
                    $errors[] = 'Error deleting category: ' . $delete_stmt->error;
                }
                $delete_stmt->close();
            }
        }
    }
}

// Get edit ID from GET parameter
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, category_name FROM categories WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
        $edit_name = $edit_data['category_name'];
    } else {
        $edit_id = null;
    }
    $stmt->close();
}

// ====================================================================
// FETCH CATEGORIES
// ====================================================================

$categories = [];

// Check if categories table exists, if not check for category table
$table_check = $conn->query("SHOW TABLES LIKE 'categories'");
if ($table_check->num_rows > 0) {
    $result = $conn->query("SELECT id, category_name, created_at FROM categories ORDER BY category_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
    }
} else {
    // Use old category table
    $old_table_check = $conn->query("SHOW TABLES LIKE 'category'");
    if ($old_table_check->num_rows > 0) {
        $result = $conn->query("SELECT id, name as category_name, created_at FROM category ORDER BY name ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
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
    <title>Manage Categories - JohnTech Management System</title>
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
            <li class="breadcrumb-item active" aria-current="page">Manage Categories</li>
        </ol>
    </nav>

    <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-tags me-2"></i>Manage Categories</h2>
    
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
            <?= $edit_id ? 'Edit Category' : 'Add New Category' ?>
          </h5>
        </div>
        <div class="card-body">
          <form method="POST">
            <?php if ($edit_id): ?>
              <input type="hidden" name="category_id" value="<?= $edit_id ?>">
            <?php endif; ?>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Category Name <span class="text-danger">*</span></label>
                <input type="text" 
                       name="category_name" 
                       class="form-control" 
                       value="<?= htmlspecialchars($edit_name) ?>" 
                       placeholder="Enter category name" 
                       required>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" 
                        name="<?= $edit_id ? 'edit_category' : 'add_category' ?>" 
                        class="btn btn-primary w-100">
                  <i class="bi bi-<?= $edit_id ? 'check' : 'plus' ?>-circle me-1"></i>
                  <?= $edit_id ? 'Update Category' : 'Add Category' ?>
                </button>
                <?php if ($edit_id): ?>
                  <a href="manage_categories.php" class="btn btn-secondary ms-2">Cancel</a>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Categories List -->
      <div class="mb-4">
        <h5 class="mb-3"><i class="bi bi-tags text-primary me-2"></i>Categories (<?= count($categories) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th style="width: 80px;">ID</th>
                <th>Category Name</th>
                <th style="width: 150px;">Created</th>
                <th style="width: 200px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($categories)): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted">No categories found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                  <tr>
                    <td><?= $cat['id'] ?></td>
                    <td><strong><?= htmlspecialchars($cat['category_name']) ?></strong></td>
                    <td><?= date('M d, Y', strtotime($cat['created_at'])) ?></td>
                    <td>
                      <a href="?edit=<?= $cat['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-pencil"></i> Edit
                      </a>
                      <form method="POST" style="display:inline-block;" onsubmit="return confirm('⚠️ WARNING: This will permanently delete this category from the database. This action cannot be undone!\\n\\nAre you sure you want to delete \"<?= htmlspecialchars($cat['category_name']) ?>\"?');">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <button type="submit" name="delete_category" class="btn btn-sm btn-danger">
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
    // Ensure content is visible after page load
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('loaded');
        setTimeout(function() {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.classList.add('loaded');
            }
        }, 50);
    });
    
    // Also check if page is already loaded
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
