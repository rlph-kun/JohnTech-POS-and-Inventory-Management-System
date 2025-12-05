<?php
/**
 * Manage Models Page - Admin
 * 
 * CRUD interface for managing product models with brand filtering and soft delete
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
$edit_brand_id = '';

// ====================================================================
// FETCH ACTIVE BRANDS FOR DROPDOWN
// ====================================================================
$active_brands = [];
$brands_result = $conn->query("SELECT id, brand_name FROM brands WHERE status = 'active' ORDER BY brand_name ASC");
while ($row = $brands_result->fetch_assoc()) {
    $active_brands[$row['id']] = $row['brand_name'];
}

// ====================================================================
// HANDLE FORM SUBMISSIONS
// ====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Model
    if (isset($_POST['add_model'])) {
        $model_name = trim($_POST['model_name'] ?? '');
        $brand_id = intval($_POST['brand_id'] ?? 0);
        
        if (empty($model_name)) {
            $errors[] = 'Model name is required.';
        } elseif ($brand_id <= 0) {
            $errors[] = 'Please select a brand.';
        } else {
            // Check if model already exists for this brand (active or inactive)
            $stmt = $conn->prepare("SELECT id, status FROM models WHERE model_name = ? AND brand_id = ?");
            $stmt->bind_param("si", $model_name, $brand_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                // If exists but inactive, reactivate it
                if ($existing['status'] === 'inactive') {
                    $update_stmt = $conn->prepare("UPDATE models SET status = 'active' WHERE id = ?");
                    $update_stmt->bind_param("i", $existing['id']);
                    if ($update_stmt->execute()) {
                        $success = 'Model reactivated successfully!';
                    } else {
                        $errors[] = 'Error reactivating model: ' . $update_stmt->error;
                    }
                    $update_stmt->close();
                } else {
                    $errors[] = 'Model already exists for this brand.';
                }
            } else {
                // Insert new model
                $insert_stmt = $conn->prepare("INSERT INTO models (model_name, brand_id, status) VALUES (?, ?, 'active')");
                $insert_stmt->bind_param("si", $model_name, $brand_id);
                if ($insert_stmt->execute()) {
                    $success = 'Model added successfully!';
                } else {
                    $errors[] = 'Error adding model: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
        }
    }
    
    // Edit Model
    if (isset($_POST['edit_model'])) {
        $model_id = intval($_POST['model_id'] ?? 0);
        $model_name = trim($_POST['model_name'] ?? '');
        $brand_id = intval($_POST['brand_id'] ?? 0);
        
        if ($model_id <= 0) {
            $errors[] = 'Invalid model ID.';
        } elseif (empty($model_name)) {
            $errors[] = 'Model name is required.';
        } elseif ($brand_id <= 0) {
            $errors[] = 'Please select a brand.';
        } else {
            // Check if name already exists for this brand (excluding current model)
            $stmt = $conn->prepare("SELECT id FROM models WHERE model_name = ? AND brand_id = ? AND id != ?");
            $stmt->bind_param("sii", $model_name, $brand_id, $model_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $errors[] = 'Model name already exists for this brand.';
            } else {
                $update_stmt = $conn->prepare("UPDATE models SET model_name = ?, brand_id = ? WHERE id = ?");
                $update_stmt->bind_param("sii", $model_name, $brand_id, $model_id);
                if ($update_stmt->execute()) {
                    $success = 'Model updated successfully!';
                } else {
                    $errors[] = 'Error updating model: ' . $update_stmt->error;
                }
                $update_stmt->close();
            }
            $stmt->close();
        }
    }
    
    // Soft Delete (Deactivate) Model
    if (isset($_POST['deactivate_model'])) {
        $model_id = intval($_POST['model_id'] ?? 0);
        
        if ($model_id <= 0) {
            $errors[] = 'Invalid model ID.';
        } else {
            // Check if model is used by active products
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE model_id = ? AND product_status = 'active'");
            $check_stmt->bind_param("i", $model_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_data = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($check_data['count'] > 0) {
                $errors[] = 'Cannot deactivate model. It is currently used by ' . $check_data['count'] . ' active product(s).';
            } else {
                $update_stmt = $conn->prepare("UPDATE models SET status = 'inactive' WHERE id = ?");
                $update_stmt->bind_param("i", $model_id);
                if ($update_stmt->execute()) {
                    $success = 'Model deactivated successfully!';
                } else {
                    $errors[] = 'Error deactivating model: ' . $update_stmt->error;
                }
                $update_stmt->close();
            }
        }
    }
    
    // Reactivate Model
    if (isset($_POST['reactivate_model'])) {
        $model_id = intval($_POST['model_id'] ?? 0);
        
        if ($model_id <= 0) {
            $errors[] = 'Invalid model ID.';
        } else {
            $update_stmt = $conn->prepare("UPDATE models SET status = 'active' WHERE id = ?");
            $update_stmt->bind_param("i", $model_id);
            if ($update_stmt->execute()) {
                $success = 'Model reactivated successfully!';
            } else {
                $errors[] = 'Error reactivating model: ' . $update_stmt->error;
            }
            $update_stmt->close();
        }
    }
}

// Get edit ID from GET parameter
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT id, model_name, brand_id FROM models WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
        $edit_name = $edit_data['model_name'];
        $edit_brand_id = $edit_data['brand_id'];
    } else {
        $edit_id = null;
    }
    $stmt->close();
}

// ====================================================================
// FETCH MODELS WITH BRAND NAMES
// ====================================================================

$active_models = [];
$inactive_models = [];

$result = $conn->query("SELECT m.id, m.model_name, m.brand_id, m.status, m.created_at, b.brand_name 
                        FROM models m 
                        LEFT JOIN brands b ON m.brand_id = b.id 
                        ORDER BY m.status ASC, b.brand_name ASC, m.model_name ASC");
while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'active') {
        $active_models[] = $row;
    } else {
        $inactive_models[] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Models - JohnTech Management System</title>
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
            <li class="breadcrumb-item active" aria-current="page">Manage Models</li>
        </ol>
    </nav>

    <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-diagram-3 me-2"></i>Manage Models</h2>
    
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
            <?= $edit_id ? 'Edit Model' : 'Add New Model' ?>
          </h5>
        </div>
        <div class="card-body">
          <form method="POST">
            <?php if ($edit_id): ?>
              <input type="hidden" name="model_id" value="<?= $edit_id ?>">
            <?php endif; ?>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Brand <span class="text-danger">*</span></label>
                <select name="brand_id" class="form-select" required>
                  <option value="">Select Brand</option>
                  <?php foreach ($active_brands as $bid => $bname): ?>
                    <option value="<?= $bid ?>" <?= ($edit_brand_id == $bid) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($bname) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-5">
                <label class="form-label">Model Name <span class="text-danger">*</span></label>
                <input type="text" 
                       name="model_name" 
                       class="form-control" 
                       value="<?= htmlspecialchars($edit_name) ?>" 
                       placeholder="Enter model name" 
                       required>
              </div>
              <div class="col-md-3 d-flex align-items-end">
                <button type="submit" 
                        name="<?= $edit_id ? 'edit_model' : 'add_model' ?>" 
                        class="btn btn-primary w-100">
                  <i class="bi bi-<?= $edit_id ? 'check' : 'plus' ?>-circle me-1"></i>
                  <?= $edit_id ? 'Update' : 'Add Model' ?>
                </button>
                <?php if ($edit_id): ?>
                  <a href="manage_models.php" class="btn btn-secondary ms-2">Cancel</a>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Active Models -->
      <div class="mb-4">
        <h5 class="mb-3"><i class="bi bi-check-circle text-success me-2"></i>Active Models (<?= count($active_models) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th style="width: 80px;">ID</th>
                <th>Brand</th>
                <th>Model Name</th>
                <th style="width: 150px;">Created</th>
                <th style="width: 200px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($active_models)): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted">No active models found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($active_models as $model): ?>
                  <tr>
                    <td><?= $model['id'] ?></td>
                    <td><strong><?= htmlspecialchars($model['brand_name'] ?? 'N/A') ?></strong></td>
                    <td><?= htmlspecialchars($model['model_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($model['created_at'])) ?></td>
                    <td>
                      <a href="?edit=<?= $model['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-pencil"></i> Edit
                      </a>
                      <form method="POST" style="display:inline-block;" onsubmit="return confirm('Deactivate this model? It will be hidden from product dropdowns.');">
                        <input type="hidden" name="model_id" value="<?= $model['id'] ?>">
                        <button type="submit" name="deactivate_model" class="btn btn-sm btn-warning">
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

      <!-- Inactive Models -->
      <?php if (!empty($inactive_models)): ?>
      <div>
        <h5 class="mb-3"><i class="bi bi-x-circle text-danger me-2"></i>Inactive Models (<?= count($inactive_models) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-secondary">
              <tr>
                <th style="width: 80px;">ID</th>
                <th>Brand</th>
                <th>Model Name</th>
                <th style="width: 150px;">Created</th>
                <th style="width: 150px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inactive_models as $model): ?>
                <tr style="opacity: 0.7;">
                  <td><?= $model['id'] ?></td>
                  <td><strong><?= htmlspecialchars($model['brand_name'] ?? 'N/A') ?></strong></td>
                  <td><?= htmlspecialchars($model['model_name']) ?></td>
                  <td><?= date('M d, Y', strtotime($model['created_at'])) ?></td>
                  <td>
                    <form method="POST" style="display:inline-block;">
                      <input type="hidden" name="model_id" value="<?= $model['id'] ?>">
                      <button type="submit" name="reactivate_model" class="btn btn-sm btn-success">
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

