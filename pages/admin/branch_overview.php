<?php
/**
 * Branch Overview Page
 * 
 * Displays comprehensive overview of branch metrics including:
 * - Total products and low stock items
 * - Today's and monthly net sales (accounting for returns and repair fees)
 * - Recent sales and low stock items lists
 * 
 * Supports branch selection via dropdown to view different branches.
 */

// ============================================================================
// INCLUDES & AUTHENTICATION
// ============================================================================
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// ============================================================================
// BRANCH SELECTION
// ============================================================================

$branches = [];
$branch_result = $conn->query("SELECT id, name FROM branches");
while ($row = $branch_result->fetch_assoc()) {
    $branches[$row['id']] = $row['name'];
}

if (empty($branches)) {
    die('No branches configured. Please add a branch first.');
}

$branch_id = isset($_GET['branch_id']) && array_key_exists($_GET['branch_id'], $branches)
    ? intval($_GET['branch_id'])
    : array_key_first($branches);

// ============================================================================
// INVENTORY METRICS
// ============================================================================

$total_products_query = "SELECT COUNT(*) as count FROM products WHERE branch_id = $branch_id";
$total_products = $conn->query($total_products_query)->fetch_assoc()['count'];

$low_stock_query = "SELECT COUNT(*) as count 
                    FROM products 
                    WHERE branch_id = $branch_id AND quantity <= reorder_level";
$low_stock = $conn->query($low_stock_query)->fetch_assoc()['count'];

// ============================================================================
// SALES - TODAY
// ============================================================================

$today = date('Y-m-d');

$today_gross_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                            FROM sales 
                            WHERE branch_id = $branch_id AND DATE(created_at) = '$today'";
$today_gross_sales = $conn->query($today_gross_sales_query)->fetch_assoc()['total'];

$today_returns_sql = "SELECT r.id, r.sale_id, r.total_amount, s.repair_fee
                      FROM returns r
                      JOIN sales s ON r.sale_id = s.id
                      WHERE r.branch_id = ? AND DATE(r.created_at) = ?";

$stmt_today_returns = $conn->prepare($today_returns_sql);
$stmt_today_returns->bind_param('is', $branch_id, $today);
$stmt_today_returns->execute();
$today_returns_result = $stmt_today_returns->get_result();

$today_returns = 0;

while ($return_row = $today_returns_result->fetch_assoc()) {
    $return_amount = floatval($return_row['total_amount']);
    $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0;
    $sale_id = intval($return_row['sale_id']);
    
    if ($repair_fee > 0) {
        $check_first_sql = "SELECT COUNT(*) as count 
                           FROM returns r2
                           WHERE r2.sale_id = ? 
                           AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
        $stmt_check = $conn->prepare($check_first_sql);
        $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $prev_return = $check_result->fetch_assoc();
        $is_first_return = ($prev_return['count'] == 0);
        $stmt_check->close();
        
        $product_total_sql = "SELECT COALESCE(SUM(ri.quantity * ri.price), 0) as product_total
                              FROM return_items ri
                              WHERE ri.return_id = ?";
        $stmt_products = $conn->prepare($product_total_sql);
        $stmt_products->bind_param('i', $return_row['id']);
        $stmt_products->execute();
        $product_result = $stmt_products->get_result();
        $product_row = $product_result->fetch_assoc();
        $product_total = floatval($product_row['product_total']);
        $stmt_products->close();
        
        $expected_total = $product_total + $repair_fee;
        $repair_fee_included = (abs($return_amount - $expected_total) < 0.01);
        
        if ($is_first_return && !$repair_fee_included && $product_total > 0) {
            $return_amount += $repair_fee;
        }
    }
    
    $today_returns += $return_amount;
}

$stmt_today_returns->close();
$today_sales = $today_gross_sales - $today_returns;

// ============================================================================
// SALES - MONTH
// ============================================================================

$month_start = date('Y-m-01');

$month_gross_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                           FROM sales 
                           WHERE branch_id = $branch_id AND DATE(created_at) >= '$month_start'";
$month_gross_sales = $conn->query($month_gross_sales_query)->fetch_assoc()['total'];

$month_returns_sql = "SELECT r.id, r.sale_id, r.total_amount, s.repair_fee
                     FROM returns r
                     JOIN sales s ON r.sale_id = s.id
                     WHERE r.branch_id = ? AND DATE(r.created_at) >= ?";

$stmt_month_returns = $conn->prepare($month_returns_sql);
$stmt_month_returns->bind_param('is', $branch_id, $month_start);
$stmt_month_returns->execute();
$month_returns_result = $stmt_month_returns->get_result();

$month_returns = 0;

while ($return_row = $month_returns_result->fetch_assoc()) {
    $return_amount = floatval($return_row['total_amount']);
    $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0;
    $sale_id = intval($return_row['sale_id']);
    
    if ($repair_fee > 0) {
        $check_first_sql = "SELECT COUNT(*) as count 
                           FROM returns r2
                           WHERE r2.sale_id = ? 
                           AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
        $stmt_check = $conn->prepare($check_first_sql);
        $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $prev_return = $check_result->fetch_assoc();
        $is_first_return = ($prev_return['count'] == 0);
        $stmt_check->close();
        
        $product_total_sql = "SELECT COALESCE(SUM(ri.quantity * ri.price), 0) as product_total
                              FROM return_items ri
                              WHERE ri.return_id = ?";
        $stmt_products = $conn->prepare($product_total_sql);
        $stmt_products->bind_param('i', $return_row['id']);
        $stmt_products->execute();
        $product_result = $stmt_products->get_result();
        $product_row = $product_result->fetch_assoc();
        $product_total = floatval($product_row['product_total']);
        $stmt_products->close();
        
        $expected_total = $product_total + $repair_fee;
        $repair_fee_included = (abs($return_amount - $expected_total) < 0.01);
        
        if ($is_first_return && !$repair_fee_included && $product_total > 0) {
            $return_amount += $repair_fee;
        }
    }
    
    $month_returns += $return_amount;
}

$stmt_month_returns->close();
$month_sales = $month_gross_sales - $month_returns;

// ============================================================================
// ADDITIONAL DATA
// ============================================================================

$recent_sales_query = "SELECT * FROM sales 
                      WHERE branch_id = $branch_id 
                      ORDER BY created_at DESC 
                      LIMIT 5";
$recent_sales = $conn->query($recent_sales_query);

$low_stock_items_query = "SELECT * FROM products 
                         WHERE branch_id = $branch_id 
                         AND quantity <= reorder_level 
                         ORDER BY quantity ASC 
                         LIMIT 5";
$low_stock_items = $conn->query($low_stock_items_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Branch Overview</title>
    
    <!-- Preload logo to prevent flash -->
    <link rel="preload" href="<?= BASE_URL ?>/assets/images/johntech.jpg" as="image">
    
    <!-- Local Assets -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    
    <!-- Critical sidebar styles to prevent layout shift -->
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
        
        <!-- ====================================================================
             TOP HEADER AREA
             ==================================================================== -->
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
        
        <!-- ====================================================================
             BRANCH SELECTION DROPDOWN
             ==================================================================== -->
        <form method="get" class="mb-3" style="max-width:300px;">
            <label for="branch_id" class="form-label fw-bold">Select Branch:</label>
            <select name="branch_id" id="branch_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($branches as $id => $name): ?>
                    <option value="<?= $id ?>" <?= $id == $branch_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        
        <!-- ====================================================================
             PAGE TITLE
             ==================================================================== -->
        <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;">
            <i class="bi bi-building me-2"></i><?= htmlspecialchars($branches[$branch_id]) ?> Overview
        </h2>
        
        <!-- ====================================================================
             MAIN CONTENT CARD
             ==================================================================== -->
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            
            <!-- Key Metrics Cards -->
            <div class="row g-3 my-2">
                <!-- Total Products Card -->
                <div class="col-md-3">
                    <div class="card text-bg-primary mb-2" style="background: #1565c0 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class="bi bi-box-seam me-2" style="color: white !important;"></i>Total Products
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                <?= number_format($total_products) ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Low Stock Items Card -->
                <div class="col-md-3">
                    <div class="card text-bg-warning mb-2" style="background: #ff9800 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class="bi bi-exclamation-triangle-fill me-2" style="color: white !important;"></i>Low Stock Items
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                <?= number_format($low_stock) ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Today's Net Sales Card -->
                <div class="col-md-3">
                    <div class="card text-bg-success mb-2" style="background: #4caf50 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class="bi bi-cash-coin me-2" style="color: white !important;"></i>Today's Sales Amount
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                ₱<?= number_format($today_sales, 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Net Sales Card -->
                <div class="col-md-3">
                    <div class="card text-bg-info mb-2" style="background: #2196f3 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class="bi bi-calendar-check me-2" style="color: white !important;"></i>Monthly Sales Amount
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                ₱<?= number_format($month_sales, 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Information Tables -->
            <div class="row g-3">
                <!-- Recent Sales Section -->
                <div class="col-md-6">
                    <div class="card border-primary mb-3" style="border: 2px solid #1565c0 !important; border-radius: 8px !important;">
                        <div class="card-header" style="background: #1565c0 !important; color: white !important; border-radius: 6px 6px 0 0 !important;">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-clock-history me-2" style="color: white !important;"></i>Recent Sales
                            </h5>
                        </div>
                        <div class="card-body" style="padding: 1rem !important;">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($sale = $recent_sales->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date('M d, Y', strtotime($sale['created_at'])) ?></td>
                                            <td>₱<?= number_format($sale['total_amount'], 2) ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Low Stock Items Section -->
                <div class="col-md-6">
                    <div class="card border-warning mb-3" style="border: 2px solid #ff9800 !important; border-radius: 8px !important;">
                        <div class="card-header" style="background: #ff9800 !important; color: white !important; border-radius: 6px 6px 0 0 !important;">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-2" style="color: white !important;"></i>Low Stock Items
                            </h5>
                        </div>
                        <div class="card-body" style="padding: 1rem !important;">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Category</th>
                                            <th>Current Stock</th>
                                            <th>Reorder Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($item = $low_stock_items->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['name']) ?></td>
                                            <td><?= htmlspecialchars($item['category']) ?></td>
                                            <td><?= $item['quantity'] ?></td>
                                            <td><?= $item['reorder_level'] ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     JAVASCRIPT
     ============================================================================ -->
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
// Add loaded class to body for any CSS transitions
window.addEventListener('load', function() {
    document.body.classList.add('loaded');
});
</script>
</body>
</html>