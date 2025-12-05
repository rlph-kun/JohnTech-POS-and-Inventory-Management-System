<?php
/**
 * Sales History Page - Branch 2 (Juban Branch) - Admin
 * 
 * This page displays sales history specifically for Branch 2 with filtering capabilities.
 * Admins can view sales data, filter by date, view returns, and export reports.
 * 
 * @author JohnTech Development Team
 * @version 1.0
 */

// ============================================================================
// INCLUDES AND INITIALIZATION
// ============================================================================

// Include required files for session, database, and authentication
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']); // Restrict access to admin role only
include '../../includes/header.php';
include '../../includes/admin_sidebar.php';

// Initialize error and success message arrays
$errors = [];
$success = '';

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
    return '₱' . number_format($amount, 2);
}

// ============================================================================
// DELETE SALE HANDLER
// ============================================================================

/**
 * Handle sale deletion via POST request
 * Deletes sale items first to maintain referential integrity
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sale'])) {
    $sale_id = intval($_POST['delete_sale']); // Sanitize input
    
    // Delete related sale items first (prevents foreign key constraint issues)
    $delete_items_sql = "DELETE FROM sale_items WHERE sale_id = ?";
    $stmt_items = $conn->prepare($delete_items_sql);
    $stmt_items->bind_param('i', $sale_id);
    $stmt_items->execute();
    $stmt_items->close();
    
    // Delete the sale record
    $delete_sale_sql = "DELETE FROM sales WHERE id = ?";
    $stmt_sale = $conn->prepare($delete_sale_sql);
    $stmt_sale->bind_param('i', $sale_id);
    
    if ($stmt_sale->execute()) {
        $success = 'Sale deleted successfully!';
    } else {
        $errors[] = 'Error deleting sale. Please try again.';
    }
    $stmt_sale->close();
}

// ============================================================================
// DATE FILTERING
// ============================================================================

// Get date filter from GET parameter, default to today's date
$date_filter = isset($_GET['date_b2']) ? $_GET['date_b2'] : date('Y-m-d');

// Branch ID is fixed to 2 for this page
$branch_id = 2;

// ============================================================================
// FETCH SALES DATA
// ============================================================================

/**
 * Fetch sales records for Branch 2 with date filter
 * Includes cashier and mechanic information via LEFT JOINs
 */
$sales = [];
$query_params = [];
$sales_sql = "SELECT s.*, 
                     cp.name AS cashier_name, 
                     m.name AS mechanic_name 
              FROM sales s 
              LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id 
              LEFT JOIN mechanics m ON s.mechanic_id = m.id 
              WHERE s.branch_id = 2";

// Add date filter if provided
if ($date_filter) {
    $sales_sql .= " AND DATE(s.created_at) = ?";
    $query_params[] = $date_filter;
}

$sales_sql .= " ORDER BY s.created_at DESC";

// Execute prepared statement
$stmt = $conn->prepare($sales_sql);
if (!$stmt) {
    $errors[] = 'Database error: ' . $conn->error;
} else {
    if (!empty($query_params)) {
        // All parameters are strings (date filter only)
        $param_types = str_repeat('s', count($query_params));
        $stmt->bind_param($param_types, ...$query_params);
    }
    
    if (!$stmt->execute()) {
        $errors[] = 'Error executing query: ' . $stmt->error;
    } else {
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $sales[] = $row;
        }
    }
    $stmt->close();
}

// ============================================================================
// FETCH SALE ITEMS AND TRACK RETURNS
// ============================================================================

/**
 * Fetch all sale items for the sales records
 * Also calculate returned quantities for each item
 * Track which sales have returns for status display
 */
$sale_items = [];
$sales_with_returns = []; // Array to track sales that have returned items

if (!empty($sales)) {
    $sale_ids = array_column($sales, 'id');
    
    // Build prepared statement with placeholders for all sale IDs
    $placeholders = implode(',', array_fill(0, count($sale_ids), '?'));
    
    // Query to get sale items with returned quantity calculation
    $items_sql = "SELECT si.*, 
                         p.name as product_name,
                         (SELECT COALESCE(SUM(ri.quantity), 0)
                          FROM return_items ri
                          JOIN returns r ON ri.return_id = r.id
                          WHERE r.sale_id = si.sale_id 
                            AND ri.product_id = si.product_id
                         ) AS returned_qty
                  FROM sale_items si 
                  LEFT JOIN products p ON si.product_id = p.id 
                  WHERE si.sale_id IN ($placeholders)";
    
    $stmt_items = $conn->prepare($items_sql);
    $types = str_repeat('i', count($sale_ids));
    $stmt_items->bind_param($types, ...$sale_ids);
    $stmt_items->execute();
    $items_result = $stmt_items->get_result();
    
    while ($item = $items_result->fetch_assoc()) {
        $sale_items[$item['sale_id']][] = $item;
        
        // Mark sale as having returns if any item has been returned
        if ($item['returned_qty'] > 0) {
            $sales_with_returns[$item['sale_id']] = true;
        }
    }
    $stmt_items->close();
}

// ============================================================================
// CALCULATE SUMMARY STATISTICS
// ============================================================================

/**
 * Calculate daily sales statistics:
 * - Total sales amount
 * - Total returns amount
 * - Net sales (sales - returns)
 * - Product returns count
 */

// Initialize variables
$net_sales_today = 0;
$total_sales_today = 0;
$total_returns_today = 0;
$product_returns_today = 0;

// Calculate total sales for the selected date (NET: total - discount)
$sales_total_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) 
                     FROM sales 
                     WHERE branch_id = 2 AND DATE(created_at) = ?";
$stmt_sales = $conn->prepare($sales_total_sql);
$stmt_sales->bind_param('s', $date_filter);
$stmt_sales->execute();
$stmt_sales->bind_result($total_sales_today);
$stmt_sales->fetch();
$total_sales_today = floor($total_sales_today);
$stmt_sales->close();

// Calculate total returns for the selected date
// Recompute returns with sale discount applied proportionally and repair-fee handling
$returns_total_sql = "SELECT r.id, r.sale_id, r.total_amount, s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                       FROM returns r
                       JOIN sales s ON r.sale_id = s.id
                       WHERE r.branch_id = 2 AND DATE(r.created_at) = ?";
$stmt_returns = $conn->prepare($returns_total_sql);
$stmt_returns->bind_param('s', $date_filter);
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

    // For back job with repair fee, include discounted repair fee once (first return only)
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

    // Cap returns to the sale's net value
    $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
    if ($return_amount > $net_sale_cap) {
        $return_amount = $net_sale_cap;
    }

    $total_returns_today += $return_amount;
}

$stmt_returns->close();

// Floor returns to whole pesos to match discount policy
$total_returns_today = floor($total_returns_today);

// Calculate net sales (total sales minus returns), and clamp to zero for display
$net_sales_today = $total_sales_today - $total_returns_today;
if ($net_sales_today < 0) {
    $net_sales_today = 0;
}

// Calculate product returns count for the selected date
$product_returns_sql = "SELECT COALESCE(SUM(ri.quantity), 0) AS product_returns
                         FROM return_items ri
                         JOIN returns r ON ri.return_id = r.id
                         WHERE r.branch_id = 2 AND DATE(r.created_at) = ?";
$stmt_product_returns = $conn->prepare($product_returns_sql);
$stmt_product_returns->bind_param('s', $date_filter);
$stmt_product_returns->execute();
$stmt_product_returns->bind_result($product_returns_today);
$stmt_product_returns->fetch();
$stmt_product_returns->close();

// ============================================================================
// HTML OUTPUT
// ============================================================================
?>
<div class="container-fluid">
    <div class="main-content">
        
        <!-- ===================================================================
            PAGE TITLE
            =================================================================== -->
        <h2 class="mb-4">
            <i class="bi bi-clock-history me-2"></i>Sales History - Branch 2
        </h2>
        
        <div class="content-card">
            
            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><?= implode('<br>', $errors) ?></div>
            <?php endif; ?>
            
            <!-- ===============================================================
                SUMMARY STATISTICS CARDS
                =============================================================== -->
            <div class="row mb-4 justify-content-center">
                <!-- Returns Card -->
                <div class="col-md-4 mb-2">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="bi bi-arrow-counterclockwise text-danger" style="font-size: 2rem;"></i>
                            </div>
                            <div class="fw-bold text-secondary small">Returns for <?= htmlspecialchars($date_filter) ?></div>
                            <div class="fs-4 fw-bold text-danger"><?= format_peso($total_returns_today) ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Sales Card -->
                <div class="col-md-4 mb-2">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="bi bi-graph-up-arrow text-success" style="font-size: 2rem;"></i>
                            </div>
                            <div class="fw-bold text-secondary small">Sales for <?= htmlspecialchars($date_filter) ?></div>
                            <div class="fs-4 fw-bold text-success"><?= format_peso($net_sales_today) ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Product Returns Card -->
                <div class="col-md-4 mb-2">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="bi bi-arrow-return-left text-danger" style="font-size: 2rem;"></i>
                            </div>
                            <div class="fw-bold text-secondary small">Product Returns for <?= htmlspecialchars($date_filter) ?></div>
                            <div class="fs-4 fw-bold text-danger"><?= $product_returns_today ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===============================================================
                DATE FILTER AND EXPORT CONTROLS
                =============================================================== -->
            <div class="mb-3">
                <form method="get" action="Sales_history2.php" class="row g-2 align-items-end">
                    <!-- Date Filter Input -->
                    <div class="col-auto ms-auto text-end">
                        <label for="date" class="form-label mb-0">Select Date:</label>
                        <input type="date" id="date" name="date_b2" class="form-control" value="<?= htmlspecialchars($date_filter) ?>">
                    </div>
                    
                    <!-- Filter Button -->
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                    
                    <!-- Today's Sales Quick Link -->
                    <div class="col-auto">
                        <a href="?date_b2=<?= date('Y-m-d') ?>" class="btn btn-success">Today's Sales</a>
                    </div>
                    
                    <!-- Export Dropdown -->
                    <div class="col-auto">
                        <?php
                        // Calculate export date range based on selected date
                        $selected_timestamp = strtotime($date_filter);
                        $export_start = date('Y-m-01', $selected_timestamp);
                        $export_end = date('Y-m-t', $selected_timestamp);
                        
                        // Weekly defaults (aligned to selected date)
                        $week_day = date('w', $selected_timestamp);
                        $week_day = ($week_day == 0) ? 7 : $week_day; // convert Sunday (0) to 7
                        $week_start_ts = strtotime('-' . ($week_day - 1) . ' days', $selected_timestamp);
                        $week_end_ts = strtotime('+' . (7 - $week_day) . ' days', $selected_timestamp);
                        $export_week_start = date('Y-m-d', $week_start_ts);
                        $export_week_end = date('Y-m-d', $week_end_ts);
                        $export_week_value = date('o-\WW', $selected_timestamp);
                        ?>
                        <div class="dropdown ms-auto text-end">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" id="exportDropdown2" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-download me-1"></i> Export
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-3" aria-labelledby="exportDropdown2" style="min-width:320px;">
                                <div id="exportControls2">
                                    <!-- Export Presets -->
                                    <div class="d-flex gap-2 mb-2 flex-wrap">
                                        <button type="button" id="preset2_today" class="btn btn-outline-primary btn-sm">Today</button>
                                        <button type="button" id="preset2_week" class="btn btn-outline-primary btn-sm">This Week</button>
                                        <button type="button" id="preset2_month" class="btn btn-outline-primary btn-sm">This Month</button>
                                    </div>
                                    
                                    <!-- Hidden values for export -->
                                    <input type="hidden" id="exp2_branch_id" value="2">
                                    <input type="hidden" id="exp2_start_month" value="<?= $export_start ?>">
                                    <input type="hidden" id="exp2_end_month" value="<?= $export_end ?>">
                                    <input type="hidden" id="exp2_week_start" value="<?= $export_week_start ?>">
                                    <input type="hidden" id="exp2_week_end" value="<?= $export_week_end ?>">
                                    <input type="hidden" id="exp2_week_value" value="<?= $export_week_value ?>">

                                    <!-- Period Selection -->
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">Period</label>
                                        <select id="exp2_period" class="form-select form-select-sm">
                                            <option value="daily">Daily (today)</option>
                                            <option value="weekly">Weekly (this week)</option>
                                            <option value="monthly">Monthly (this month)</option>
                                            <option value="range">Custom range</option>
                                        </select>
                                    </div>

                                    <!-- Date Input (shown for daily) -->
                                    <div class="mb-2" id="exp2_date_wrap" style="display:none;">
                                        <label class="form-label small mb-1">Date</label>
                                        <input type="date" id="exp2_date" class="form-control form-control-sm">
                                    </div>

                                    <!-- Month Input (shown for monthly) -->
                                    <div class="mb-2" id="exp2_month_wrap" style="display:none;">
                                        <label class="form-label small mb-1">Month</label>
                                        <input type="month" id="exp2_month" class="form-control form-control-sm">
                                    </div>

                                    <!-- Week Input (shown for weekly) -->
                                    <div class="mb-2" id="exp2_week_wrap" style="display:none;">
                                        <label class="form-label small mb-1">Week</label>
                                        <input type="week" id="exp2_week" class="form-control form-control-sm" value="<?= $export_week_value ?>">
                                    </div>

                                    <!-- Date Range Input (shown for custom range) -->
                                    <div class="mb-2" id="exp2_range_wrap" style="display:none;">
                                        <label class="form-label small mb-1">Range</label>
                                        <div class="d-flex gap-2">
                                            <input type="date" id="exp2_start" class="form-control form-control-sm">
                                            <input type="date" id="exp2_end" class="form-control form-control-sm">
                                        </div>
                                    </div>

                                    <!-- Format Selection -->
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">Format</label>
                                        <select id="exp2_format" class="form-select form-select-sm">
                                            <option value="xlsx">Excel (.xlsx)</option>
                                            <option value="csv">CSV</option>
                                        </select>
                                    </div>

                                    <!-- Export Button -->
                                    <button type="button" id="exportBtn2" class="btn btn-success btn-sm w-100">Export</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ===============================================================
                SALES HISTORY TABLE
                =============================================================== -->
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="salesTable">
                    <thead class="table-dark">
                        <tr>
                            <th><i class="bi bi-receipt me-1"></i>Sale ID</th>
                            <th><i class="bi bi-person me-1"></i>Cashier</th>
                            <th>Items/Services</th>
                            <th>Remarks</th>
                            <th>Mechanic</th>
                            <th>Product Amount / Service Fee</th>
                            <th>Discount</th>
                            <th><i class="bi bi-currency-peso me-1"></i>Total Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="10" class="text-center">No sales found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <!-- Sale ID -->
                                    <td><?= $sale['id'] ?></td>
                                    
                                    <!-- Cashier Name -->
                                    <td><?= htmlspecialchars($sale['cashier_name'] ?? 'N/A') ?></td>
                                    
                                    <!-- Items/Services List -->
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            <?php
                                            // Fetch items for this specific sale
                                            $items_sql = "SELECT si.*, 
                                                                 p.name,
                                                                 (SELECT COALESCE(SUM(ri.quantity), 0)
                                                                  FROM return_items ri
                                                                  JOIN returns r ON ri.return_id = r.id
                                                                  WHERE r.sale_id = si.sale_id 
                                                                    AND ri.product_id = si.product_id
                                                                 ) AS returned_qty
                                                          FROM sale_items si 
                                                          JOIN products p ON si.product_id = p.id 
                                                          WHERE si.sale_id = ?";
                                            $stmt_items_display = $conn->prepare($items_sql);
                                            $stmt_items_display->bind_param('i', $sale['id']);
                                            $stmt_items_display->execute();
                                            $items_result = $stmt_items_display->get_result();
                                            
                                            while ($item = $items_result->fetch_assoc()): ?>
                                                <li>
                                                    <?= htmlspecialchars($item['name']) ?> x<?= $item['quantity'] ?>
                                                </li>
                                            <?php endwhile; ?>
                                            <?php $stmt_items_display->close(); ?>
                                            
                                            <!-- Display repair/service fee if applicable -->
                                            <?php if ($sale['repair_fee'] > 0): ?>
                                                <li>Repair/Service Fee</li>
                                            <?php endif; ?>
                                        </ul>
                                    </td>
                                    
                                    <!-- Remarks -->
                                    <td>
                                        <?php
                                        if (!empty($sale['remarks'])) {
                                            echo htmlspecialchars($sale['remarks']);
                                        } elseif ($sale['repair_fee'] > 0) {
                                            echo 'Repair/Service';
                                        } else {
                                            echo 'Product Sale';
                                        }
                                        ?>
                                    </td>
                                    
                                    <!-- Mechanic Name -->
                                    <td>
                                        <?= $sale['mechanic_name'] ? htmlspecialchars($sale['mechanic_name']) : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    
                                    <!-- Product Amount / Service Fee -->
                                    <td>
                                        <?php
                                        // Calculate product total from sale_items
                                        $product_total = 0;
                                        if (isset($sale_items[$sale['id']])) {
                                            foreach ($sale_items[$sale['id']] as $item) {
                                                $product_total += ($item['quantity'] * $item['price']);
                                            }
                                        }
                                        
                                        $repair_fee = floatval($sale['repair_fee'] ?? 0);
                                        
                                        // Display product amount
                                        if ($product_total > 0) {
                                            echo '<div class="small"><strong>Products:</strong> ' . format_peso($product_total) . '</div>';
                                        }
                                        
                                        // Display service fee
                                        if ($repair_fee > 0) {
                                            echo '<div class="small"><strong>Service Fee:</strong> ' . format_peso($repair_fee) . '</div>';
                                        }
                                        
                                        // Show "-" if neither exists
                                        if ($product_total == 0 && $repair_fee == 0) {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    
                                    <!-- Discount Display -->
                                    <td>
                                        <?php
                                        $discount_amount = floor(floatval($sale['discount']));
                                        $discount_rate = isset($sale['discount_rate']) ? floatval($sale['discount_rate']) : null;
                                        
                                        if ($discount_amount > 0) {
                                            $percentage_label = '';
                                            
                                            // Prefer stored discount_rate when available
                                            if ($discount_rate !== null && $discount_rate > 0) {
                                                // Format as integer if whole number, otherwise keep decimals
                                                $percentage_label = (intval($discount_rate) == $discount_rate) 
                                                    ? intval($discount_rate) . '%' 
                                                    : $discount_rate . '%';
                                            } else {
                                                // Calculate percentage from amount
                                                $total_amount = floatval($sale['total_amount']);
                                                if ($total_amount > 0) {
                                                    $calculated_percentage = round(($discount_amount / $total_amount) * 100, 2);
                                                    $percentage_label = (intval($calculated_percentage) == $calculated_percentage) 
                                                        ? intval($calculated_percentage) . '%' 
                                                        : $calculated_percentage . '%';
                                                }
                                            }
                                            
                                            // Display percentage and amount (discount amount forced to whole pesos)
                                            $discount_whole = max(floor($discount_amount), 0);
                                            $discount_amount_text = '₱' . number_format($discount_whole, 0);
                                            echo $percentage_label 
                                                ? htmlspecialchars($percentage_label) . ' (' . $discount_amount_text . ')' 
                                                : $discount_amount_text;
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    
                                    <!-- Total Amount (net of discount) -->
                                    <td>
                                        <?php
                                        $gross_total = floatval($sale['total_amount']);
                                        $discount_amount = floor(floatval($sale['discount']));
                                        $net_total = max($gross_total - $discount_amount, 0);
                                        echo format_peso($net_total);
                                        ?>
                                    </td>
                                    
                                    <!-- Status Badge -->
                                    <td>
                                        <?php
                                        if (isset($sales_with_returns[$sale['id']])) {
                                            echo '<span class="badge bg-warning text-dark">';
                                            echo '<i class="bi bi-arrow-return-left me-1"></i>Returned';
                                            echo '</span>';
                                        } else {
                                            echo '<span class="badge bg-success">';
                                            echo '<i class="bi bi-check-circle me-1"></i>Active';
                                            echo '</span>';
                                        }
                                        ?>
                                    </td>
                                    
                                    <!-- Action Buttons -->
                                    <td>
                                        <?php if (isset($sales_with_returns[$sale['id']])): ?>
                                            <a href="view_returns.php?sale_id=<?= $sale['id'] ?>&branch_id=2" 
                                               class="btn btn-sm btn-info me-1">
                                                <i class="bi bi-eye"></i> View Returns
                                            </a>
                                        <?php endif; ?>
                                        
                                        <form method="post" style="display:inline-block" 
                                              onsubmit="return confirm('Are you sure you want to delete this sale?');">
                                            <input type="hidden" name="delete_sale" value="<?= $sale['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
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

<!-- ============================================================================
    JAVASCRIPT
    ============================================================================ -->

<script>
/**
 * Export Functionality Handler for Branch 2
 * Handles export button clicks and period selection visibility
 */
document.addEventListener('DOMContentLoaded', function() {
    var periodSelect = document.getElementById('exp2_period');
    var dateWrap = document.getElementById('exp2_date_wrap');
    var weekWrap = document.getElementById('exp2_week_wrap');
    var monthWrap = document.getElementById('exp2_month_wrap');
    var rangeWrap = document.getElementById('exp2_range_wrap');
    var weekInputField = document.getElementById('exp2_week');

    function formatDate(dateObj) {
        var year = dateObj.getFullYear();
        var month = String(dateObj.getMonth() + 1).padStart(2, '0');
        var day = String(dateObj.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function getISOWeekRange(weekValue) {
        if (!weekValue || weekValue.indexOf('-W') === -1) {
            return null;
        }
        var parts = weekValue.split('-W');
        var year = parseInt(parts[0], 10);
        var week = parseInt(parts[1], 10);
        if (isNaN(year) || isNaN(week)) {
            return null;
        }
        var simple = new Date(year, 0, 1 + (week - 1) * 7);
        var dayOfWeek = simple.getDay();
        var isoWeekStart = new Date(simple);
        var diff = (dayOfWeek <= 4 ? 1 : 8) - dayOfWeek;
        isoWeekStart.setDate(simple.getDate() + diff);
        var isoWeekEnd = new Date(isoWeekStart);
        isoWeekEnd.setDate(isoWeekStart.getDate() + 6);
        return {
            start: formatDate(isoWeekStart),
            end: formatDate(isoWeekEnd)
        };
    }

    function getSelectedWeekRange() {
        var start = document.getElementById('exp2_week_start')?.value;
        var end = document.getElementById('exp2_week_end')?.value;
        if (!start || !end) {
            return null;
        }
        return { start: start, end: end };
    }

    function performExport(period, start, end) {
        var baseUrl = '<?= BASE_URL ?>/handlers/generate_sales_report.php';
        var branch = encodeURIComponent(document.getElementById('exp2_branch_id').value || '2');
        var format = encodeURIComponent(document.getElementById('exp2_format').value || 'xlsx');
        var exportUrl = baseUrl + '?branch_id=' + branch
                      + '&start_date=' + encodeURIComponent(start)
                      + '&end_date=' + encodeURIComponent(end)
                      + '&period=' + encodeURIComponent(period)
                      + '&format=' + format;
        window.open(exportUrl, '_blank', 'noopener');
    }

    /**
     * Show/hide period-specific input fields based on selection
     */
    function updateExportVisibility() {
        var selectedPeriod = periodSelect.value;
        dateWrap.style.display = (selectedPeriod === 'daily') ? 'block' : 'none';
        weekWrap.style.display = (selectedPeriod === 'weekly') ? 'block' : 'none';
        monthWrap.style.display = (selectedPeriod === 'monthly') ? 'block' : 'none';
        rangeWrap.style.display = (selectedPeriod === 'range') ? 'block' : 'none';
    }
    
    if (periodSelect) {
        updateExportVisibility();
        periodSelect.addEventListener('change', updateExportVisibility);
    }

    if (weekInputField && !weekInputField.value) {
        weekInputField.value = document.getElementById('exp2_week_value')?.value || '';
    }

    /**
     * Export button click handler
     */
    var exportBtn = document.getElementById('exportBtn2');
    if (!exportBtn) return;
    
    exportBtn.addEventListener('click', function() {
        var period = document.getElementById('exp2_period').value || 'monthly';
        var startDate = '';
        var endDate = '';

        if (period === 'daily') {
            // Use server date to avoid timezone issues
            startDate = '<?= date('Y-m-d') ?>';
            endDate = startDate;
        } else if (period === 'weekly') {
            var weekValue = weekInputField.value || document.getElementById('exp2_week_value').value;
            var weekRange = getISOWeekRange(weekValue) || getSelectedWeekRange();
            if (!weekRange) {
                alert('Unable to determine week range. Please pick a valid week.');
                return;
            }
            startDate = weekRange.start;
            endDate = weekRange.end;
        } else if (period === 'monthly') {
            // Use month range from hidden inputs
            startDate = document.getElementById('exp2_start_month').value || '';
            endDate = document.getElementById('exp2_end_month').value || '';
        } else {
            // Custom range - validate input
            var startInput = document.getElementById('exp2_start').value;
            var endInput = document.getElementById('exp2_end').value;
            
            if (!startInput || !endInput) {
                alert('Please select start and end dates for the range.');
                return;
            }
            
            if (startInput > endInput) {
                alert('Start date cannot be after end date.');
                return;
            }
            
            startDate = startInput;
            endDate = endInput;
        }

        performExport(period, startDate, endDate);
    });
    
    /**
     * Quick preset button handlers
     */
    var presetToday = document.getElementById('preset2_today');
    var presetWeek = document.getElementById('preset2_week');
    var presetMonth = document.getElementById('preset2_month');
    
    if (presetToday) {
        presetToday.addEventListener('click', function() {
            var today = '<?= date('Y-m-d') ?>';
            performExport('daily', today, today);
        });
    }

    if (presetWeek) {
        presetWeek.addEventListener('click', function() {
            var range = getSelectedWeekRange();
            if (!range) {
                var weekValue = weekInputField.value || document.getElementById('exp2_week_value').value;
                range = getISOWeekRange(weekValue);
            }
            if (!range) {
                alert('Unable to determine week range for the selected date.');
                return;
            }
            performExport('weekly', range.start, range.end);
        });
    }
    
    if (presetMonth) {
        presetMonth.addEventListener('click', function() {
            var start = document.getElementById('exp2_start_month').value || '';
            var end = document.getElementById('exp2_end_month').value || '';
            performExport('monthly', start, end);
        });
    }
});
</script>

<!-- External CSS and JS -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<?php include '../../includes/footer.php'; ?>