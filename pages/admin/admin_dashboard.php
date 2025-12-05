<?php
/**
 * Admin Dashboard Page
 * 
 * This page displays comprehensive dashboard statistics and overview for administrators.
 * Shows daily and monthly sales metrics, branch performance, low stock alerts,
 * and quick access to system features.
 * 
 * @author JohnTech Development Team
 * @version 1.0
 */

// ============================================================================
// INCLUDES AND INITIALIZATION
// ============================================================================

// Include required files for authentication, database, and sidebar
include '../../includes/auth.php';
allow_roles(['admin']); // Restrict access to admin role only
include '../../config.php';

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Format number as Philippine Peso currency
 * 
 * @param float $amount The amount to format
 * @return string Formatted currency string with peso sign
 */
function format_peso($amount) {
    return '₱' . number_format($amount, 0);
}

// ============================================================================
// DATE SETUP
// ============================================================================

// Get today's date for all daily calculations
$today = date('Y-m-d');
$month_start = date('Y-m-01'); // First day of current month

// ============================================================================
// CALCULATE TODAY'S OVERALL STATISTICS (ALL BRANCHES)
// ============================================================================

/**
 * Calculate daily sales statistics across all branches:
 * - Total sales amount
 * - Total returns amount
 * - Net sales (sales - returns)
 * - Products sold count
 * - Product returns count
 * - Items needing restock
 */

// Initialize variables
$total_sales_today = 0;
$total_returns_today = 0;
$net_sales = 0;
$products_sold = 0;
$product_returns = 0;
$items_to_restock = 0;

// 1. Calculate Total Sales for Today (All Branches)
$sales_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE DATE(created_at) = ?";
$stmt_sales = $conn->prepare($sales_sql);
$stmt_sales->bind_param('s', $today);
$stmt_sales->execute();
$stmt_sales->bind_result($total_sales_today);
$stmt_sales->fetch();
$stmt_sales->close();
// Use whole pesos on dashboard
$total_sales_today = floor($total_sales_today);

// 2. Calculate Total Returns for Today (All Branches)
// This includes recalculating returns for back job sales (sales with repair fees)
$returns_sql = "SELECT r.id, r.sale_id, s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                FROM returns r
                JOIN sales s ON r.sale_id = s.id
                WHERE DATE(r.created_at) = ?";
$stmt_returns = $conn->prepare($returns_sql);
$stmt_returns->bind_param('s', $today);
$stmt_returns->execute();
$returns_result = $stmt_returns->get_result();

$total_returns_today = 0;

while ($return_row = $returns_result->fetch_assoc()) {
    $sale_id = intval($return_row['sale_id']);
    $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0.0;
    $sale_total_amount = isset($return_row['sale_total_amount']) ? floatval($return_row['sale_total_amount']) : 0.0;
    $sale_discount = isset($return_row['sale_discount']) ? floatval($return_row['sale_discount']) : 0.0;
    $discount_ratio = ($sale_total_amount > 0) ? max(min($sale_discount / $sale_total_amount, 1), 0) : 0.0;

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

    // Start with discounted product amount
    $return_amount = $product_total * (1 - $discount_ratio);

    // Include discounted repair fee once (first return) if there are product items
    if ($repair_fee > 0 && $product_total > 0) {
        $check_first_sql = "SELECT COUNT(*) as count FROM returns r2
                            WHERE r2.sale_id = ? AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
        $stmt_check = $conn->prepare($check_first_sql);
        $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $prev_return = $check_result->fetch_assoc();
        $is_first_return = ($prev_return['count'] == 0);
        $stmt_check->close();

        if ($is_first_return) {
            $return_amount += ($repair_fee * (1 - $discount_ratio));
        }
    }

    // Cap to sale's net value
    $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
    if ($return_amount > $net_sale_cap) {
        $return_amount = $net_sale_cap;
    }

    $total_returns_today += $return_amount;
}

$stmt_returns->close();

// Floor returns to whole pesos for display consistency
$total_returns_today = floor($total_returns_today);

// Calculate net sales (total sales minus returns)
$net_sales = floor($total_sales_today - $total_returns_today);

// 3. Calculate Products Sold Today (All Branches)
$products_sold_sql = "SELECT COALESCE(SUM(si.quantity), 0) 
                      FROM sale_items si 
                      JOIN sales s ON si.sale_id = s.id 
                      WHERE DATE(s.created_at) = ?";
$stmt_products_sold = $conn->prepare($products_sold_sql);
$stmt_products_sold->bind_param('s', $today);
$stmt_products_sold->execute();
$stmt_products_sold->bind_result($products_sold);
$stmt_products_sold->fetch();
$stmt_products_sold->close();

// 4. Calculate Product Returns Today (All Branches)
$product_returns_sql = "SELECT COALESCE(SUM(ri.quantity), 0) 
                        FROM return_items ri 
                        JOIN returns r ON ri.return_id = r.id 
                        WHERE DATE(r.created_at) = ?";
$stmt_product_returns = $conn->prepare($product_returns_sql);
$stmt_product_returns->bind_param('s', $today);
$stmt_product_returns->execute();
$stmt_product_returns->bind_result($product_returns);
$stmt_product_returns->fetch();
$stmt_product_returns->close();

// 5. Calculate Items Needing Restock (All Branches)
$restock_sql = "SELECT COUNT(*) FROM products WHERE quantity <= reorder_level";
$stmt_restock = $conn->prepare($restock_sql);
$stmt_restock->execute();
$stmt_restock->bind_result($items_to_restock);
$stmt_restock->fetch();
$stmt_restock->close();

// 6. Get Branch Count (for welcome message)
$branch_count_sql = "SELECT COUNT(DISTINCT branch_id) as count FROM products";
$stmt_branch_count = $conn->prepare($branch_count_sql);
$stmt_branch_count->execute();
$branch_count_result = $stmt_branch_count->get_result();
$branch_count_data = $branch_count_result->fetch_assoc();
$branch_count = $branch_count_data['count'] ?? 0;
$stmt_branch_count->close();

// ============================================================================
// CALCULATE MONTHLY STATISTICS
// ============================================================================

/**
 * Calculate monthly statistics from the start of the current month:
 * - Monthly sales amount
 * - Monthly returns amount
 * - Monthly net sales
 * - Monthly products sold
 * - Monthly transaction count
 */

// Initialize monthly variables
$monthly_sales = 0;
$monthly_returns = 0;
$monthly_net = 0;
$monthly_products_sold = 0;
$monthly_transactions = 0;

// Monthly Sales
$monthly_sales_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE DATE(created_at) >= ?";
$stmt_monthly_sales = $conn->prepare($monthly_sales_sql);
$stmt_monthly_sales->bind_param('s', $month_start);
$stmt_monthly_sales->execute();
$stmt_monthly_sales->bind_result($monthly_sales);
$stmt_monthly_sales->fetch();
$stmt_monthly_sales->close();
// Whole pesos
$monthly_sales = floor($monthly_sales);

// Monthly Returns
// This includes recalculating returns for back job sales (sales with repair fees)
$monthly_returns_sql = "SELECT r.id, r.sale_id, s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                        FROM returns r
                        JOIN sales s ON r.sale_id = s.id
                        WHERE DATE(r.created_at) >= ?";
$stmt_monthly_returns = $conn->prepare($monthly_returns_sql);
$stmt_monthly_returns->bind_param('s', $month_start);
$stmt_monthly_returns->execute();
$monthly_returns_result = $stmt_monthly_returns->get_result();

$monthly_returns = 0;

while ($return_row = $monthly_returns_result->fetch_assoc()) {
    $sale_id = intval($return_row['sale_id']);
    $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0.0;
    $sale_total_amount = isset($return_row['sale_total_amount']) ? floatval($return_row['sale_total_amount']) : 0.0;
    $sale_discount = isset($return_row['sale_discount']) ? floatval($return_row['sale_discount']) : 0.0;
    $discount_ratio = ($sale_total_amount > 0) ? max(min($sale_discount / $sale_total_amount, 1), 0) : 0.0;

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

    // Start with discounted product amount
    $return_amount = $product_total * (1 - $discount_ratio);

    // Include discounted repair fee once (first return) if there are product items
    if ($repair_fee > 0 && $product_total > 0) {
        $check_first_sql = "SELECT COUNT(*) as count FROM returns r2
                            WHERE r2.sale_id = ? AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
        $stmt_check = $conn->prepare($check_first_sql);
        $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $prev_return = $check_result->fetch_assoc();
        $is_first_return = ($prev_return['count'] == 0);
        $stmt_check->close();

        if ($is_first_return) {
            $return_amount += ($repair_fee * (1 - $discount_ratio));
        }
    }

    // Cap to sale's net value
    $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
    if ($return_amount > $net_sale_cap) {
        $return_amount = $net_sale_cap;
    }

    $monthly_returns += $return_amount;
}

$stmt_monthly_returns->close();

// Floor monthly returns to whole pesos
$monthly_returns = floor($monthly_returns);

// Calculate monthly net sales
$monthly_net = floor($monthly_sales - $monthly_returns);

// Monthly Products Sold
$monthly_products_sql = "SELECT COALESCE(SUM(si.quantity), 0) 
                         FROM sale_items si 
                         JOIN sales s ON si.sale_id = s.id 
                         WHERE DATE(s.created_at) >= ?";
$stmt_monthly_products = $conn->prepare($monthly_products_sql);
$stmt_monthly_products->bind_param('s', $month_start);
$stmt_monthly_products->execute();
$stmt_monthly_products->bind_result($monthly_products_sold);
$stmt_monthly_products->fetch();
$stmt_monthly_products->close();

// Monthly Transaction Count
$monthly_transactions_sql = "SELECT COUNT(*) FROM sales WHERE DATE(created_at) >= ?";
$stmt_monthly_transactions = $conn->prepare($monthly_transactions_sql);
$stmt_monthly_transactions->bind_param('s', $month_start);
$stmt_monthly_transactions->execute();
$stmt_monthly_transactions->bind_result($monthly_transactions);
$stmt_monthly_transactions->fetch();
$stmt_monthly_transactions->close();

// ============================================================================
// HTML OUTPUT
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Admin Dashboard</title>
    
    <!-- Preload logo to prevent flash -->
    <link rel="preload" href="<?= BASE_URL ?>/assets/images/johntech.jpg" as="image">
    
    <!-- External CSS Libraries -->
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

<div class="container-fluid" style="margin-left: 250px !important; margin-top: 0 !important; padding: 0.5rem !important; padding-top: 0 !important; background: #f8fafc !important; min-height: 100vh !important;">
    <div class="main-content" style="margin-top: 0 !important; padding-top: 0.5rem !important;">
        
        <!-- ===================================================================
            PAGE HEADER
            =================================================================== -->
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

        <!-- ===================================================================
            PAGE TITLE AND DATE
            =================================================================== -->
        <h2 class="mb-3" style="color: #1a202c !important; font-size: 1.75rem;">
            <i class="bi bi-speedometer2 me-2"></i>Admin Dashboard
        </h2>
        <div class="mb-3 fw-bold text-muted" style="font-size:1em;">
            <i class="bi bi-calendar3 me-2"></i><?= date('l, F j, Y') ?>
        </div>
        
        <!-- ===================================================================
            MAIN DASHBOARD CONTENT CARD
            =================================================================== -->
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            
            <!-- Welcome Message -->
            <div class="alert alert-info mb-3" style="background: rgba(33, 150, 243, 0.1) !important; color: #2196f3 !important; border: 1px solid #2196f3 !important; border-radius: 6px !important; padding: 0.75rem !important;">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Welcome to JohnTech Management System!</strong> Monitoring <?= $branch_count ?> branches with real-time data.
            </div>
            
            <!-- ===============================================================
                TODAY'S STATISTICS CARDS
                =============================================================== -->
            <div class="row g-3 my-2">
                <!-- Sales Card -->
                <div class="col-md-3">
                    <div class="card text-bg-primary mb-2" style="background: #1565c0 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class='bi bi-cash-coin me-2' style="color: white !important;"></i>Sales Amount
                            </h5> 
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                <?= format_peso($net_sales) ?>
                            </p>
                            <small class="text-white-50">Today (<?= date('M j, Y') ?>)</small>
                        </div>
                    </div>
                </div>
                
                <!-- Products Sold Card -->
                <div class="col-md-3">
                    <div class="card text-bg-success mb-2" style="background: #4caf50 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class='bi bi-box-seam me-2' style="color: white !important;"></i>Products Sold
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                <?= number_format($products_sold) ?>
                            </p>
                            <small class="text-white-50">Today (<?= date('M j, Y') ?>)</small>
                        </div>
                    </div>
                </div>
                
                <!-- Product Returns Card -->
                <div class="col-md-3">
                    <div class="card text-bg-danger mb-2" style="background: #f44336 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class='bi bi-arrow-counterclockwise me-2' style="color: white !important;"></i>Product Returns
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                <?= number_format($product_returns) ?>
                            </p>
                            <small class="text-white-50">Today (<?= date('M j, Y') ?>)</small>
                        </div>
                    </div>
                </div>
                
                <!-- Items to Restock Card -->
                <div class="col-md-3">
                    <div class="card text-bg-warning mb-2" style="background: #ff9800 !important; color: white !important; border-radius: 10px !important;">
                        <div class="card-body" style="padding: 1.25rem !important;">
                            <h5 class="card-title" style="color: white !important; font-size: 1rem;">
                                <i class='bi bi-exclamation-triangle-fill me-2' style="color: white !important;"></i>Items to Restock
                            </h5>
                            <p class="card-text fs-4" style="color: white !important; font-size: 1.25rem !important; margin-bottom: 0;">
                                <?= number_format($items_to_restock) ?>
                            </p>
                            <small class="text-white-50">All Branches</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===============================================================
                BRANCH PERFORMANCE BREAKDOWN
                =============================================================== -->
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <h6 class="text-muted mb-2">
                        <i class="bi bi-building me-2"></i>Today's Branch Performance
                    </h6>
                </div>
                
                <?php
                // Define branch names mapping
                $branch_names = [
                    1 => "Sorsogon Branch",
                    2 => "Juban Branch"
                ];
                
                // Loop through branches to calculate individual statistics
                for ($branch = 1; $branch <= 2; $branch++) {
                    // Initialize branch variables
                    $branch_sales = 0;
                    $branch_returns = 0;
                    $branch_net = 0;
                    $branch_products_sold = 0;
                    $branch_transactions = 0;
                    
                    // Branch Sales Today
                    $branch_sales_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) FROM sales WHERE branch_id = ? AND DATE(created_at) = ?";
                    $stmt_branch_sales = $conn->prepare($branch_sales_sql);
                    $stmt_branch_sales->bind_param('is', $branch, $today);
                    $stmt_branch_sales->execute();
                    $stmt_branch_sales->bind_result($branch_sales);
                    $stmt_branch_sales->fetch();
                    $stmt_branch_sales->close();
                    $branch_sales = floor($branch_sales);
                    
                    // Branch Returns Today
                    // This includes recalculating returns for back job sales (sales with repair fees)
                    $branch_returns_sql = "SELECT r.id, r.sale_id, s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                                            FROM returns r
                                            JOIN sales s ON r.sale_id = s.id
                                            WHERE r.branch_id = ? AND DATE(r.created_at) = ?";
                    $stmt_branch_returns = $conn->prepare($branch_returns_sql);
                    $stmt_branch_returns->bind_param('is', $branch, $today);
                    $stmt_branch_returns->execute();
                    $branch_returns_result = $stmt_branch_returns->get_result();

                    $branch_returns = 0;

                    while ($return_row = $branch_returns_result->fetch_assoc()) {
                        $sale_id = intval($return_row['sale_id']);
                        $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0.0;
                        $sale_total_amount = isset($return_row['sale_total_amount']) ? floatval($return_row['sale_total_amount']) : 0.0;
                        $sale_discount = isset($return_row['sale_discount']) ? floatval($return_row['sale_discount']) : 0.0;
                        $discount_ratio = ($sale_total_amount > 0) ? max(min($sale_discount / $sale_total_amount, 1), 0) : 0.0;

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

                        // Start with discounted product amount
                        $return_amount = $product_total * (1 - $discount_ratio);

                        // Include discounted repair fee once (first return) if there are product items
                        if ($repair_fee > 0 && $product_total > 0) {
                            $check_first_sql = "SELECT COUNT(*) as count FROM returns r2
                                                WHERE r2.sale_id = ? AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
                            $stmt_check = $conn->prepare($check_first_sql);
                            $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
                            $stmt_check->execute();
                            $check_result = $stmt_check->get_result();
                            $prev_return = $check_result->fetch_assoc();
                            $is_first_return = ($prev_return['count'] == 0);
                            $stmt_check->close();

                            if ($is_first_return) {
                                $return_amount += ($repair_fee * (1 - $discount_ratio));
                            }
                        }

                        // Cap to sale's net value
                        $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
                        if ($return_amount > $net_sale_cap) {
                            $return_amount = $net_sale_cap;
                        }

                        $branch_returns += $return_amount;
                    }

                    $stmt_branch_returns->close();
                    
                    // Floor branch returns to whole pesos for display consistency
                    $branch_returns = floor($branch_returns);
                    
                    // Calculate branch net sales
                    $branch_net = floor($branch_sales - $branch_returns);
                    
                    // Branch Products Sold Today
                    $branch_products_sql = "SELECT COALESCE(SUM(si.quantity), 0) 
                                           FROM sale_items si 
                                           JOIN sales s ON si.sale_id = s.id 
                                           WHERE s.branch_id = ? AND DATE(s.created_at) = ?";
                    $stmt_branch_products = $conn->prepare($branch_products_sql);
                    $stmt_branch_products->bind_param('is', $branch, $today);
                    $stmt_branch_products->execute();
                    $stmt_branch_products->bind_result($branch_products_sold);
                    $stmt_branch_products->fetch();
                    $stmt_branch_products->close();
                    
                    // Branch Transaction Count
                    $branch_transactions_sql = "SELECT COUNT(*) FROM sales WHERE branch_id = ? AND DATE(created_at) = ?";
                    $stmt_branch_transactions = $conn->prepare($branch_transactions_sql);
                    $stmt_branch_transactions->bind_param('is', $branch, $today);
                    $stmt_branch_transactions->execute();
                    $stmt_branch_transactions->bind_result($branch_transactions);
                    $stmt_branch_transactions->fetch();
                    $stmt_branch_transactions->close();
                    
                    // Determine performance status
                    $performance_color = 'secondary';
                    $performance_text = 'No Activity';
                    if ($branch_net > 0) {
                        $performance_color = 'success';
                        $performance_text = 'Performing Well';
                    } elseif ($branch_transactions > 0) {
                        $performance_color = 'warning';
                        $performance_text = 'Active - Low Revenue';
                    }
                ?>
                <div class="col-md-6">
                    <div class="card border-<?= $branch == 1 ? 'primary' : 'success' ?> h-100">
                        <div class="card-header bg-<?= $branch == 1 ? 'primary' : 'success' ?> text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-building me-2"></i><?= htmlspecialchars($branch_names[$branch]) ?> - Today
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <!-- Net Sales -->
                                <div class="col-6">
                                    <div class="text-center">
                                        <h5 class="text-<?= $branch == 1 ? 'primary' : 'success' ?>">
                                            <?= format_peso($branch_net) ?>
                                        </h5>
                                        <small class="text-muted">Net Sales</small>
                                    </div>
                                </div>
                                
                                <!-- Products Sold -->
                                <div class="col-6">
                                    <div class="text-center">
                                        <h5 class="text-info"><?= number_format($branch_products_sold) ?></h5>
                                        <small class="text-muted">Products Sold</small>
                                    </div>
                                </div>
                                
                                <!-- Transactions -->
                                <div class="col-6">
                                    <div class="text-center">
                                        <h5 class="text-warning"><?= number_format($branch_transactions) ?></h5>
                                        <small class="text-muted">Transactions</small>
                                    </div>
                                </div>
                                
                                <!-- Returns -->
                                <div class="col-6">
                                    <div class="text-center">
                                        <h5 class="text-danger"><?= format_peso($branch_returns) ?></h5>
                                        <small class="text-muted">Returns</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Performance Indicator -->
                            <div class="mt-2">
                                <span class="badge bg-<?= $performance_color ?> w-100"><?= $performance_text ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>

        <!-- ===================================================================
            QUICK ACTIONS SECTION
            =================================================================== -->
        <div class="content-card quick-actions" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            <h4 class="mb-3" style="color: #1a202c !important; font-size: 1.25rem;">
                <i class="bi bi-lightning-charge me-2"></i>Quick Actions
            </h4>
            <div class="row g-3">
                <div class="col-md-3">
                    <a href="<?= BASE_URL ?>/pages/admin/Inventory_management.php" class="btn btn-outline-primary w-100 p-3" style="border: 2px solid #1565c0 !important; color: #1565c0 !important; text-decoration: none !important; border-radius: 8px !important;">
                        <i class="bi bi-boxes d-block mb-2" style="font-size: 1.5rem;"></i>
                        <span>Manage Inventory</span>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?= BASE_URL ?>/pages/admin/cashier_management.php" class="btn btn-outline-success w-100 p-3" style="border: 2px solid #4caf50 !important; color: #4caf50 !important; text-decoration: none !important; border-radius: 8px !important;">
                        <i class="bi bi-person-plus d-block mb-2" style="font-size: 1.5rem;"></i>
                        <span>Add Cashier</span>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?= BASE_URL ?>/pages/admin/Sales_history1.php" class="btn btn-outline-info w-100 p-3" style="border: 2px solid #2196f3 !important; color: #2196f3 !important; text-decoration: none !important; border-radius: 8px !important;">
                        <i class="bi bi-graph-up d-block mb-2" style="font-size: 1.5rem;"></i>
                        <span>View Sales</span>
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="<?= BASE_URL ?>/pages/admin/Audit_Activity_log.php" class="btn btn-outline-warning w-100 p-3" style="border: 2px solid #ff9800 !important; color: #ff9800 !important; text-decoration: none !important; border-radius: 8px !important;">
                        <i class="bi bi-list-check d-block mb-2" style="font-size: 1.5rem;"></i>
                        <span>Audit Logs</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- ===================================================================
            RECENT ACTIVITY SECTION
            =================================================================== -->
        <div class="content-card">
            <h4 class="mb-3">
                <i class="bi bi-clock-history me-2"></i>Recent Activity
            </h4>
            <div class="row g-3">
                <!-- Branch Sales Overview Card -->
                <div class="col-md-6">
                    <div class="card border-info">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-building me-2"></i>Branch Sales Overview (Today)
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php
                            // Get today's sales by branch with detailed statistics
                            $branch_sales_sql = "SELECT 
                                                    s.branch_id,
                                                    COUNT(*) as transaction_count,
                                                    SUM(s.total_amount - s.discount) as total_sales,
                                                    AVG(s.total_amount - s.discount) as avg_sale,
                                                    MIN(s.total_amount - s.discount) as min_sale,
                                                    MAX(s.total_amount - s.discount) as max_sale,
                                                    cp.name AS last_cashier_name,
                                                    MAX(s.created_at) as last_sale_time
                                                  FROM sales s 
                                                  LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id 
                                                  WHERE DATE(s.created_at) = ?
                                                  GROUP BY s.branch_id 
                                                  ORDER BY s.branch_id";
                            $stmt_branch_sales_overview = $conn->prepare($branch_sales_sql);
                            $stmt_branch_sales_overview->bind_param('s', $today);
                            $stmt_branch_sales_overview->execute();
                            $branch_sales_result = $stmt_branch_sales_overview->get_result();
                            
                            if ($branch_sales_result && $branch_sales_result->num_rows > 0): 
                                $branches_data = [];
                                while ($row = $branch_sales_result->fetch_assoc()) {
                                    $branches_data[] = $row;
                                }
                            ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Branch</th>
                                                <th>Transactions</th>
                                                <th>Total Sales</th>
                                                <th>Avg Sale</th>
                                                <th>Last Sale</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($branches_data as $branch_data): ?>
                                            <tr>
                                                <td>
                                                    <strong>Branch <?= $branch_data['branch_id'] ?></strong>
                                                    <br><small class="text-muted">Last: <?= date('H:i', strtotime($branch_data['last_sale_time'])) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?= $branch_data['transaction_count'] ?></span>
                                                    <br><small class="text-muted">transactions</small>
                                                </td>
                                                <td>
                                                    <strong><?= format_peso($branch_data['total_sales']) ?></strong>
                                                    <br><small class="text-muted">Range: <?= format_peso($branch_data['min_sale']) ?>-<?= format_peso($branch_data['max_sale']) ?></small>
                                                </td>
                                                <td>
                                                    <?= format_peso($branch_data['avg_sale']) ?>
                                                    <br><small class="text-muted">per transaction</small>
                                                </td>
                                                <td>
                                                    <?= date('H:i', strtotime($branch_data['last_sale_time'])) ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($branch_data['last_cashier_name'] ?: 'Unknown') ?></small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Quick Branch Comparison -->
                                <?php if (count($branches_data) >= 2): 
                                    $branch1 = $branches_data[0];
                                    $branch2 = $branches_data[1];
                                    $sales_diff = $branch1['total_sales'] - $branch2['total_sales'];
                                    $trans_diff = $branch1['transaction_count'] - $branch2['transaction_count'];
                                ?>
                                <div class="mt-3">
                                    <h6><i class="bi bi-bar-chart me-2"></i>Quick Comparison</h6>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <div class="p-2 bg-light rounded">
                                                <small class="text-muted">Sales Difference:</small><br>
                                                <span class="<?= $sales_diff >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= format_peso(abs($sales_diff)) ?>
                                                    <?= $sales_diff >= 0 ? '(B1 leads)' : '(B2 leads)' ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-2 bg-light rounded">
                                                <small class="text-muted">Transaction Difference:</small><br>
                                                <span class="<?= $trans_diff >= 0 ? 'text-success' : 'text-danger' ?>">
                                                    <?= abs($trans_diff) ?> transactions
                                                    <?= $trans_diff >= 0 ? '(B1 leads)' : '(B2 leads)' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-graph-down text-muted" style="font-size: 2rem;"></i>
                                    <p class="text-muted mb-0">No sales recorded today</p>
                                    <small class="text-muted">Sales will appear here once transactions are made</small>
                                </div>
                            <?php 
                            endif;
                            $stmt_branch_sales_overview->close();
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Low Stock Alerts Card -->
                <div class="col-md-6">
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alerts
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php
                            // Get low stock items (top 5)
                            $low_stock_sql = "SELECT name, quantity, reorder_level, branch_id 
                                             FROM products 
                                             WHERE quantity <= reorder_level 
                                             ORDER BY quantity ASC 
                                             LIMIT 5";
                            $stmt_low_stock = $conn->prepare($low_stock_sql);
                            $stmt_low_stock->execute();
                            $low_stock_result = $stmt_low_stock->get_result();
                            
                            if ($low_stock_result && $low_stock_result->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Branch</th>
                                                <th>Stock</th>
                                                <th>Reorder</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($item = $low_stock_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['name']) ?></td>
                                                <td>Branch <?= $item['branch_id'] ?></td>
                                                <td><span class="badge bg-danger"><?= $item['quantity'] ?></span></td>
                                                <td><?= $item['reorder_level'] ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-success">All products are well-stocked!</p>
                            <?php 
                            endif;
                            $stmt_low_stock->close();
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
            SYSTEM STATUS SECTION
            =================================================================== -->
        <div class="content-card">
            <h4 class="mb-3">
                <i class="bi bi-shield-check me-2"></i>System Status
            </h4>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="badge bg-success rounded-circle me-3" style="width: 12px; height: 12px;"></div>
                        <div>
                            <div class="fw-bold">Database Connection</div>
                            <small class="text-muted">Connected and operational</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="badge bg-success rounded-circle me-3" style="width: 12px; height: 12px;"></div>
                        <div>
                            <div class="fw-bold">System Version</div>
                            <small class="text-muted">JohnTech v1.0</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center">
                        <div class="badge bg-info rounded-circle me-3" style="width: 12px; height: 12px;"></div>
                        <div>
                            <div class="fw-bold">Last Updated</div>
                            <small class="text-muted"><?= date('M j, Y') ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
            MONTHLY SUMMARY SECTION
            =================================================================== -->
        <div class="content-card">
            <h4 class="mb-3">
                <i class="bi bi-calendar-month me-2"></i>Monthly Summary
            </h4>
            <div class="row g-3">
                <!-- Monthly Net Sales -->
                <div class="col-md-3">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <h5 class="text-primary"><?= format_peso($monthly_net) ?></h5>
                            <p class="mb-0">Net Sales</p>
                            <small class="text-muted"><?= date('F Y') ?></small>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Products Sold -->
                <div class="col-md-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="text-success"><?= number_format($monthly_products_sold) ?></h5>
                            <p class="mb-0">Products Sold</p>
                            <small class="text-muted"><?= date('F Y') ?></small>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Transactions -->
                <div class="col-md-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h5 class="text-info"><?= number_format($monthly_transactions) ?></h5>
                            <p class="mb-0">Transactions</p>
                            <small class="text-muted"><?= date('F Y') ?></small>
                        </div>
                    </div>
                </div>
                
                <!-- Monthly Returns -->
                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h5 class="text-warning"><?= format_peso($monthly_returns) ?></h5>
                            <p class="mb-0">Returns</p>
                            <small class="text-muted"><?= date('F Y') ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
            GETTING STARTED GUIDE SECTION
            =================================================================== -->
        <div class="content-card">
            <h4 class="mb-3">
                <i class="bi bi-book me-2"></i>Getting Started
            </h4>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <i class="bi bi-boxes text-primary" style="font-size: 2rem;"></i>
                            <h5 class="card-title mt-2">Step 1: Manage Inventory</h5>
                            <p class="card-text">Add products and manage stocks.</p>
                            <a href="<?= BASE_URL ?>/pages/admin/Inventory_management.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-arrow-right me-1"></i>Go to Inventory
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <i class="bi bi-person-badge text-success" style="font-size: 2rem;"></i>
                            <h5 class="card-title mt-2">Step 2: Manage Staff</h5>
                            <p class="card-text">Add cashiers and mechanics to start operations.</p>
                            <a href="<?= BASE_URL ?>/pages/admin/cashier_management.php" class="btn btn-success btn-sm">
                                <i class="bi bi-arrow-right me-1"></i>Manage Cashiers
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <i class="bi bi-building text-info" style="font-size: 2rem;"></i>
                            <h5 class="card-title mt-2">Step 3: Monitor Branches</h5>
                            <p class="card-text">Monitor your branches and their performance.</p>
                            <a href="<?= BASE_URL ?>/pages/admin/branch_overview.php" class="btn btn-info btn-sm">
                                <i class="bi bi-arrow-right me-1"></i>View Branches
                            </a>
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

<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>

<!-- JavaScript to prevent FOUC (Flash of Unstyled Content) -->
<script>
/**
 * Page Load Handler
 * Prevents flash of unstyled content by adding loaded classes
 */
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
    
    // Preload logo image if present
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

/**
 * Window Load Handler
 * Additional fallback to ensure content is visible
 */
window.addEventListener('load', function() {
    document.body.classList.add('loaded');
});
</script>

</body>
</html>