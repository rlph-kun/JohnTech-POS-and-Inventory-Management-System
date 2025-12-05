<?php
/**
 * Branch 2 Overview Page
 * 
 * Displays comprehensive overview of branch 2 metrics including:
 * - Total products and low stock items
 * - Today's and monthly net sales (accounting for returns and repair fees)
 * - Recent sales and low stock items lists
 * 
 * This page is specifically for Branch 2 (fixed branch_id = 2).
 */

// ============================================================================
// INCLUDES & AUTHENTICATION
// ============================================================================
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);
include '../../includes/header.php';
include '../../includes/admin_sidebar.php';

// ============================================================================
// BRANCH ID
// ============================================================================

// Fixed to Branch 2
$branch_id = 2;

// ============================================================================
// BRANCH METRICS - PRODUCTS & INVENTORY
// ============================================================================

// Get total products count for branch 2
$total_products_query = "SELECT COUNT(*) as count FROM products WHERE branch_id = $branch_id";
$total_products = $conn->query($total_products_query)->fetch_assoc()['count'];

// Get low stock items count (quantity <= reorder_level)
$low_stock_query = "SELECT COUNT(*) as count 
                    FROM products 
                    WHERE branch_id = $branch_id AND quantity <= reorder_level";
$low_stock = $conn->query($low_stock_query)->fetch_assoc()['count'];

// ============================================================================
// SALES CALCULATIONS - TODAY
// ============================================================================

$today = date('Y-m-d');

// Get total gross sales for today
$today_gross_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                           FROM sales 
                           WHERE branch_id = $branch_id AND DATE(created_at) = '$today'";
$today_gross_sales = $conn->query($today_gross_sales_query)->fetch_assoc()['total'];

/**
 * Calculate today's returns including repair fees for back job sales
 * 
 * Back job scenario: When a sale has a repair_fee, and products are returned,
 * the repair fee should be included in the return amount only for the first return.
 */
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
    
    // Handle back job scenario (sales with repair fees)
    if ($repair_fee > 0) {
        // Check if this is the first return chronologically for this sale
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
        
        // Calculate product total for this return
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
        
        // Check if repair fee is already included in return amount
        $expected_total = $product_total + $repair_fee;
        $repair_fee_included = (abs($return_amount - $expected_total) < 0.01);
        
        // Add repair fee if this is the first return, products were returned, and fee not already included
        if ($is_first_return && !$repair_fee_included && $product_total > 0) {
            $return_amount += $repair_fee;
        }
    }
    
    $today_returns += $return_amount;
}

$stmt_today_returns->close();

// Calculate net sales for today (gross sales minus returns)
$today_sales = $today_gross_sales - $today_returns;

// ============================================================================
// SALES CALCULATIONS - MONTH
// ============================================================================

$month_start = date('Y-m-01');

// Get total gross sales for this month
$month_gross_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                           FROM sales 
                           WHERE branch_id = $branch_id AND DATE(created_at) >= '$month_start'";
$month_gross_sales = $conn->query($month_gross_sales_query)->fetch_assoc()['total'];

/**
 * Calculate monthly returns including repair fees for back job sales
 * Uses the same logic as today's returns calculation
 */
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
    
    // Handle back job scenario (sales with repair fees)
    if ($repair_fee > 0) {
        // Check if this is the first return chronologically for this sale
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
        
        // Calculate product total for this return
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
        
        // Check if repair fee is already included in return amount
        $expected_total = $product_total + $repair_fee;
        $repair_fee_included = (abs($return_amount - $expected_total) < 0.01);
        
        // Add repair fee if this is the first return, products were returned, and fee not already included
        if ($is_first_return && !$repair_fee_included && $product_total > 0) {
            $return_amount += $repair_fee;
        }
    }
    
    $month_returns += $return_amount;
}

$stmt_month_returns->close();

// Calculate net sales for this month (gross sales minus returns)
$month_sales = $month_gross_sales - $month_returns;

// ============================================================================
// FETCH DETAILED DATA
// ============================================================================

// Get recent sales (last 5)
$recent_sales_query = "SELECT * FROM sales 
                      WHERE branch_id = $branch_id 
                      ORDER BY created_at DESC 
                      LIMIT 5";
$recent_sales = $conn->query($recent_sales_query);

// Get low stock items details (top 5 by quantity)
$low_stock_items_query = "SELECT * FROM products 
                         WHERE branch_id = $branch_id 
                         AND quantity <= reorder_level 
                         ORDER BY quantity ASC 
                         LIMIT 5";
$low_stock_items = $conn->query($low_stock_items_query);
?>

<!-- ============================================================================
     HTML OUTPUT
     ============================================================================ -->
<div class="container-fluid">
    <div class="main-content">
        
        <!-- Page Title -->
        <h2 class="mb-4">
            <i class="bi bi-building me-2"></i>Branch 2 Overview
        </h2>
        
        <!-- Main Content Card -->
        <div class="content-card">
            
            <!-- Key Metrics Cards -->
            <div class="row g-4 my-3">
                <!-- Total Products Card -->
                <div class="col-md-3">
                    <div class="card text-bg-primary mb-3">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-box-seam me-2"></i>Total Products
                            </h5>
                            <p class="card-text fs-4"><?= number_format($total_products) ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Low Stock Items Card -->
                <div class="col-md-3">
                    <div class="card text-bg-warning mb-3">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>Low Stock Items
                            </h5>
                            <p class="card-text fs-4"><?= number_format($low_stock) ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Today's Net Sales Card -->
                <div class="col-md-3">
                    <div class="card text-bg-success mb-3">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-cash-coin me-2"></i>Today's Net Sales
                            </h5>
                            <p class="card-text fs-4">₱<?= number_format($today_sales, 2) ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Net Sales Card -->
                <div class="col-md-3">
                    <div class="card text-bg-info mb-3">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-calendar-check me-2"></i>Monthly Net Sales
                            </h5>
                            <p class="card-text fs-4">₱<?= number_format($month_sales, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Information Tables -->
            <div class="row g-4">
                <!-- Recent Sales Section -->
                <div class="col-md-6">
                    <div class="card border-primary mb-3">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-clock-history me-2"></i>Recent Sales
                            </h5>
                        </div>
                        <div class="card-body">
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
                    <div class="card border-warning mb-3">
                        <div class="card-header bg-warning text-white">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>Low Stock Items
                            </h5>
                        </div>
                        <div class="card-body">
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
     STYLESHEETS & SCRIPTS
     ============================================================================ -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<?php include '../../includes/footer.php'; ?>