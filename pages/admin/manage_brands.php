<?php
/**
 * Manage Brands Page - Admin
 * 
 * CRUD interface for managing product brands with soft delete functionality
 * 
 * @author JohnTech Development Team
 * @version 2.0
 */

include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// Initialize variables
$errors = [];
$success = '';
$edit_id = null;
$edit_name = '';

// ====================================================================
// HANDLE FORM SUBMISSIONS
// ====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Brand
    if (isset($_POST['add_brand'])) {
        $brand_name = trim($_POST['brand_name'] ?? '');
        
        if (empty($brand_name)) {
            $errors[] = 'Brand name is required.';
        } else {
            // Check if brand already exists (active or inactive)
            $stmt = $conn->prepare("SELECT id, status FROM brands WHERE brand_name = ?");
            $stmt->bind_param("s", $brand_name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                // If exists but inactive, reactivate it
                if ($existing['status'] === 'inactive') {
                    $update_stmt = $conn->prepare("UPDATE brands SET status = 'active' WHERE id = ?");
                    $update_stmt->bind_param("i", $existing['id']);
                    if ($update_stmt->execute()) {
                        $success = 'Brand reactivated successfully!';
                    } else {
                        $errors[] = 'Error reactivating brand: ' . $update_stmt->error;
                    }
                    $update_stmt->close();
                } else {
                    $errors[] = 'Brand already exists.';
                }
            } else {
                // Insert new brand
                $insert_stmt = $conn->prepare("INSERT INTO brands (brand_name, status) VALUES (?, 'active')");
                $insert_stmt->bind_param("s", $brand_name);
                if ($insert_stmt->execute()) {
                    $success = 'Brand added successfully!';
                } else {
                    $errors[] = 'Error adding brand: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
        }
    }
    
    // Edit Brand
    if (isset($_POST['edit_brand'])) {
        $brand_id = intval($_POST['brand_id'] ?? 0);
        $brand_name = trim($_POST['brand_name'] ?? '');
        
        if ($brand_id <= 0) {
            $errors[] = 'Invalid brand ID.';
        } elseif (empty($brand_name)) {
            $errors[] = 'Brand name is required.';
        } else {
            // Check if name already exists (excluding current brand)
            $stmt = $conn->prepare("SELECT id FROM brands WHERE brand_name = ? AND id != ?");
            $stmt->bind_param("si", $brand_name, $brand_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = 'Brand name already exists.';
            } else {
                $update_stmt = $conn->prepare("UPDATE brands SET brand_name = ? WHERE id = ?");
                $update_stmt->bind_param("si", $brand_name, $brand_id);
                if ($update_stmt->execute()) {
                    $success = 'Brand updated successfully!';
                } else {
                    $errors[] = 'Error updating brand: ' . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $stmt->close();
        }
    }
    
    // Soft Delete (Deactivate) Brand
    if (isset($_POST['deactivate_brand'])) {
        $brand_id = intval($_POST['brand_id'] ?? 0);
        
        if ($brand_id <= 0) {
            $errors[] = 'Invalid brand ID.';
        } else {
            // Check if brand is used by active products
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE brand_id = ? AND product_status = 'active'");
            $check_stmt->bind_param("i", $brand_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($check_data['count'] > 0) {
                $errors[] = 'Cannot deactivate brand. It is currently used by ' . $check_data['count'] . ' active product(s).';
            } else {
                $update_stmt = $conn->prepare("UPDATE brands SET status = 'inactive' WHERE id = ?");
                $update_stmt->bind_param("i", $brand_id);
                if ($update_stmt->execute()) {
                    $success = 'Brand deactivated successfully!';
                } else {
                    $errors[] = 'Error deactivating brand: ' . $update_stmt->error;
                }
                $update_stmt->close();
            }
        }
    }
    
    // Reactivate Brand
    if (isset($_POST['reactivate_brand'])) {
        $brand_id = intval($_POST['brand_id'] ?? 0);
        
        if ($brand_id <= 0) {
            $errors[] = 'Invalid brand ID.';
        } else {
            $update_stmt = $conn->prepare("UPDATE brands SET status = 'active' WHERE id = ?");
            $update_stmt->bind_param("i", $brand_id);
            if ($update_stmt->execute()) {
                $success = 'Brand reactivated successfully!';
            } else {
                $errors[] = 'Error reactivating brand: ' . $update_stmt->error;
            }
            $update_stmt->close();
        }
    }
}

// Get edit ID from GET parameter
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, brand_name FROM brands WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
        $edit_name = $edit_data['brand_name'];
    } else {
        $edit_id = null;
    }
    $stmt->close();
}

// ====================================================================
// FETCH BRANDS
// ====================================================================

$active_brands = [];
$inactive_brands = [];

$result = $conn->query("SELECT id, brand_name, status, created_at FROM brands ORDER BY status ASC, brand_name ASC");
while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'active') {
        $active_brands[] = $row;
    } else {
        $inactive_brands[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Brands - JohnTech Management System</title>
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
  <div class="main-content" style="position: relative !important; z-index: 2 !im
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
            <li class="breadcrumb-item active" aria-current="page">Manage Brands</li>
        </ol>
    </nav>

    <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-award me-2"></i>Manage Brands</h2>
    
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
            <?= $edit_id ? 'Edit Brand' : 'Add New Brand' ?>
          </h5>
        </div>
        <div class="card-body">
          <form method="POST">
            <?php if ($edit_id): ?>
              <input type="hidden" name="brand_id" value="<?= $edit_id ?>">
            <?php endif; ?>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                <input type="text" 
                       name="brand_name" 
                       class="form-control" 
                       value="<?= htmlspecialchars($edit_name) ?>" 
                       placeholder="Enter brand name" 
                       required>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" 
                        name="<?= $edit_id ? 'edit_brand' : 'add_brand' ?>" 
                        class="btn btn-primary w-100">
                  <i class="bi bi-<?= $edit_id ? 'check' : 'plus' ?>-circle me-1"></i>
                  <?= $edit_id ? 'Update Brand' : 'Add Brand' ?>
                </button>
                <?php if ($edit_id): ?>
                  <a href="manage_brands.php" class="btn btn-secondary ms-2">Cancel</a>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Active Brands -->
      <div class="mb-4">
        <h5 class="mb-3"><i class="bi bi-check-circle text-success me-2"></i>Active Brands (<?= count($active_brands) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th style="width: 80px;">ID</th>
                <th>Brand Name</th>
                <th style="width: 150px;">Created</th>
                <th style="width: 200px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($active_brands)): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted">No active brands found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($active_brands as $brand): ?>
                  <tr>
                    <td><?= $brand['id'] ?></td>
                    <td><strong><?= htmlspecialchars($brand['brand_name']) ?></strong></td>
                    <td><?= date('M d, Y', strtotime($brand['created_at'])) ?></td>
                    <td>
                      <a href="?edit=<?= $brand['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-pencil"></i> Edit
                      </a>
                      <form method="POST" style="display:inline-block;" onsubmit="return confirm('Deactivate this brand? It will be hidden from product dropdowns.');">
                        <input type="hidden" name="brand_id" value="<?= $brand['id'] ?>">
                        <button type="submit" name="deactivate_brand" class="btn btn-sm btn-warning">
                          <i class="bi bi-x-circle"></i> Deactivate
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

      <!-- Inactive Brands -->
      <?php if (!empty($inactive_brands)): ?>
      <div>
        <h5 class="mb-3"><i class="bi bi-x-circle text-danger me-2"></i>Inactive Brands (<?= count($inactive_brands) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-secondary">
              <tr>
                <th style="width: 80px;">ID</th>
                <th>Brand Name</th>
                <th style="width: 150px;">Created</th>
                <th style="width: 150px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inactive_brands as $brand): ?>
                <tr style="opacity: 0.7;">
                  <td><?= $brand['id'] ?></td>
                  <td><strong><?= htmlspecialchars($brand['brand_name']) ?></strong></td>
                  <td><?= date('M d, Y', strtotime($brand['created_at'])) ?></td>
                  <td>
                    <form method="POST" style="display:inline-block;">
                      <input type="hidden" name="brand_id" value="<?= $brand['id'] ?>">
                      <button type="submit" name="reactivate_brand" class="btn btn-sm btn-success">
                        <i class="bi bi-arrow-clockwise"></i> Reactivate
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
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

