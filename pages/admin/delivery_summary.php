<?php
/**
 * Delivery Summary Page - Admin
 * 
 * This page displays all delivery records with defective quantity tracking.
 * Shows delivered quantity, defective quantity, good quantity, supplier, and notes.
 * 
 * @author JohnTech Development Team
 * @version 1.0
 */

// Start output buffering to catch any errors
ob_start();

// ============================================================================
// INCLUDES AND INITIALIZATION
// ============================================================================

try {
    include '../../includes/session.php';
    include '../../config.php';
    include '../../includes/auth.php';
    allow_roles(['admin']);
} catch (Exception $e) {
    die("Error loading required files: " . $e->getMessage());
}

// Initialize error and success message arrays
$errors = [];
$success = '';

// ============================================================================
// DATE FILTER HANDLING
// ============================================================================

// Get date filter parameters from GET
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

// Quick filter presets
if (isset($_GET['filter'])) {
    $filter = $_GET['filter'];
    switch ($filter) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d');
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-d');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date = date('Y-m-t', strtotime('last month'));
            break;
        case 'this_year':
            $start_date = date('Y-01-01');
            $end_date = date('Y-m-d');
            break;
        case 'all':
            $start_date = '';
            $end_date = '';
            break;
    }
}

// ============================================================================
// FETCH DELIVERY SUMMARY DATA
// ============================================================================

// Fetch all delivery records joined with products table, sorted by newest first
$deliveries = [];
$total_records = 0;

// Check if database connection exists
if (!isset($conn) || !$conn) {
    $errors[] = 'Database connection error. Please check your configuration.';
} else {
    try {
        // Verify we're using the correct database
        $current_db = $conn->query("SELECT DATABASE()")->fetch_array()[0];
        
        // Check if table exists, if not create it
        $table_check = $conn->query("SHOW TABLES LIKE 'delivery_summary'");
        if (!$table_check || $table_check->num_rows == 0) {
            // Table doesn't exist, create it
            $create_table_sql = "CREATE TABLE IF NOT EXISTS delivery_summary (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                delivered_qty INT NOT NULL,
                defective_qty INT DEFAULT 0,
                good_qty INT NOT NULL,
                supplier VARCHAR(255),
                received_by VARCHAR(255),
                delivery_date DATE NOT NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_id (product_id),
                INDEX idx_delivery_date (delivery_date),
                INDEX idx_created_at (created_at)
            )";
            
            if ($conn->query($create_table_sql)) {
                $success = 'The delivery_summary table has been created successfully!';
            } else {
                $errors[] = 'Error creating delivery_summary table: ' . $conn->error;
            }
        }
        
        // Build WHERE clause for date filtering
        $where_clause = "WHERE 1=1";
        if (!empty($start_date)) {
            $start_date_escaped = $conn->real_escape_string($start_date);
            $where_clause .= " AND ds.delivery_date >= '$start_date_escaped'";
        }
        if (!empty($end_date)) {
            $end_date_escaped = $conn->real_escape_string($end_date);
            $where_clause .= " AND ds.delivery_date <= '$end_date_escaped'";
        }
        
        // Now fetch data (table should exist now)
        $query = "SELECT 
                    ds.id,
                    ds.product_id,
                    ds.delivered_qty,
                    ds.defective_qty,
                    ds.good_qty,
                    ds.supplier,
                    ds.received_by,
                    ds.delivery_date,
                    ds.notes,
                    ds.created_at,
                    p.name as product_name,
                    p.category
                  FROM delivery_summary ds
                  LEFT JOIN products p ON ds.product_id = p.id
                  $where_clause
                  ORDER BY ds.delivery_date DESC, ds.created_at DESC";

        $result = $conn->query($query);

        if ($result) {
            // Query succeeded, fetch the data
            while ($row = $result->fetch_assoc()) {
                $deliveries[] = $row;
            }
            $total_records = count($deliveries);
        } else {
            // Query failed
            $error_msg = $conn->error ?? 'Unknown error';
            $errors[] = 'Error fetching delivery records: ' . $error_msg;
        }
    } catch (Exception $e) {
        $errors[] = 'Database error: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Summary - JohnTech Management System</title>
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
            <li class="breadcrumb-item active" aria-current="page">Delivery Summary</li>
        </ol>
    </nav>

    <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-clipboard-data me-2"></i>Delivery Summary</h2>
    
    <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
      <?php if ($success): ?>
      <div class="alert alert-success alert-dismissible fade show position-relative" role="alert" style="padding-right: 3rem;">
        <?= $success ?>
        <button type="button" class="btn btn-sm p-0 border-0 bg-transparent position-absolute top-50 end-0 translate-middle-y me-2" data-bs-dismiss="alert" aria-label="Close" title="Close">
            <i class="bi bi-x-circle-fill" style="font-size:1.1rem; color:#64748b;"></i>
        </button>
      </div>
      <?php endif; ?>
      <?php if ($errors): ?>
      <div class="alert alert-danger alert-dismissible fade show position-relative" role="alert" style="padding-right: 3rem;">
        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
        <button type="button" class="btn btn-sm p-0 border-0 bg-transparent position-absolute top-50 end-0 translate-middle-y me-2" data-bs-dismiss="alert" aria-label="Close" title="Close">
            <i class="bi bi-x-circle-fill" style="font-size:1.1rem; color:#64748b;"></i>
        </button>
      </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <a href="Inventory_management.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Inventory
          </a>
        </div>
        <div>
          <span class="badge bg-info">Total Records: <?= $total_records ?></span>
        </div>
      </div>

      <!-- Date Filter Form -->
      <div class="card mb-4" style="border: 1px solid #e2e8f0; background: #f8fafc;">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Filter by Date Range</h6>
        </div>
        <div class="card-body">
          <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
              <label class="form-label">Start Date</label>
              <input type="date" 
                     name="start_date" 
                     class="form-control" 
                     value="<?= htmlspecialchars($start_date) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Date</label>
              <input type="date" 
                     name="end_date" 
                     class="form-control" 
                     value="<?= htmlspecialchars($end_date) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label d-block">Quick Filters</label>
              <div class="btn-group" role="group">
                <a href="?filter=today" class="btn btn-sm btn-outline-primary <?= (isset($_GET['filter']) && $_GET['filter'] === 'today') ? 'active' : '' ?>">Today</a>
                <a href="?filter=this_week" class="btn btn-sm btn-outline-primary <?= (isset($_GET['filter']) && $_GET['filter'] === 'this_week') ? 'active' : '' ?>">This Week</a>
                <a href="?filter=this_month" class="btn btn-sm btn-outline-primary <?= (isset($_GET['filter']) && $_GET['filter'] === 'this_month') ? 'active' : '' ?>">This Month</a>
                <a href="?filter=last_month" class="btn btn-sm btn-outline-primary <?= (isset($_GET['filter']) && $_GET['filter'] === 'last_month') ? 'active' : '' ?>">Last Month</a>
                <a href="?filter=this_year" class="btn btn-sm btn-outline-primary <?= (isset($_GET['filter']) && $_GET['filter'] === 'this_year') ? 'active' : '' ?>">This Year</a>
                <a href="?filter=all" class="btn btn-sm btn-outline-secondary <?= (!isset($_GET['filter']) || $_GET['filter'] === 'all') ? 'active' : '' ?>">All</a>
              </div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-funnel me-1"></i>Apply Filter
              </button>
              <?php if (!empty($start_date) || !empty($end_date)): ?>
                <a href="delivery_summary.php" class="btn btn-secondary">
                  <i class="bi bi-x-circle me-1"></i>Clear Filter
                </a>
              <?php endif; ?>
            </div>
          </form>
          <?php if (!empty($start_date) || !empty($end_date)): ?>
            <div class="mt-2">
              <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Showing records from 
                <strong><?= !empty($start_date) ? date('M d, Y', strtotime($start_date)) : 'beginning' ?></strong>
                <?php if (!empty($start_date) && !empty($end_date)): ?>
                  to <strong><?= date('M d, Y', strtotime($end_date)) ?></strong>
                <?php elseif (!empty($end_date)): ?>
                  to <strong><?= date('M d, Y', strtotime($end_date)) ?></strong>
                <?php endif; ?>
              </small>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (empty($deliveries)): ?>
        <div class="alert alert-info" style="border-radius: 8px; border: none; background: #e3f2fd; padding: 1rem;">
          <i class="bi bi-info-circle me-2"></i>No delivery records found. Start by adding a delivery record from the Inventory page.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>Date Received</th>
                <th>Product</th>
                <th class="text-center">Delivered Qty</th>
                <th class="text-center">Defective Qty</th>
                <th class="text-center">Good Qty</th>
                <th>Supplier</th>
                <th>Received By</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($deliveries as $delivery): ?>
                <tr>
                  <td>
                    <?= htmlspecialchars(date('M d, Y', strtotime($delivery['delivery_date']))) ?>
                    <br>
                    <small class="text-muted">
                      <?= htmlspecialchars(date('h:i A', strtotime($delivery['created_at']))) ?>
                    </small>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($delivery['product_name'] ?? 'Product #' . $delivery['product_id']) ?></strong>
                    <?php if (!empty($delivery['category'])): ?>
                      <br><small class="text-muted"><?= htmlspecialchars($delivery['category']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-primary"><?= number_format($delivery['delivered_qty']) ?></span>
                  </td>
                  <td class="text-center">
                    <?php if ($delivery['defective_qty'] > 0): ?>
                      <span class="badge bg-danger"><?= number_format($delivery['defective_qty']) ?></span>
                    <?php else: ?>
                      <span class="badge bg-success">0</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-success"><?= number_format($delivery['good_qty']) ?></span>
                  </td>
                  <td><?= htmlspecialchars($delivery['supplier'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($delivery['received_by'] ?? '-') ?></td>
                  <td>
                    <?php if (!empty($delivery['notes'])): ?>
                      <span title="<?= htmlspecialchars($delivery['notes']) ?>">
                        <?= htmlspecialchars(mb_substr($delivery['notes'], 0, 50)) ?>
                        <?= mb_strlen($delivery['notes']) > 50 ? '...' : '' ?>
                      </span>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
  .table th {
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background-color: #1a202c !important;
    color: #ffffff !important;
    border-color: #2d3748 !important;
    padding: 0.75rem !important;
  }
  
  .table td {
    vertical-align: middle;
    padding: 0.75rem !important;
    border-color: #e2e8f0 !important;
  }
  
  .table tbody tr:hover {
    background-color: #f7fafc !important;
  }
  
  .badge {
    font-size: 0.85rem;
    padding: 0.4rem 0.6rem;
    font-weight: 500;
  }
  
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
    // Show body with fade-in effect
    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('loaded');
        
        // Show main content after brief delay to ensure CSS is applied
        setTimeout(function() {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.classList.add('loaded');
            }
        }, 50);
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
