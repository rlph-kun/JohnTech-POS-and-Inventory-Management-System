<?php
// ======================================================================================
// View Returns - Admin
// ======================================================================================

include '../../includes/auth.php';
allow_roles(['admin']);
include_once '../../config.php';

// --------------------------------------------------------------------------
// Helpers (PHP)
// --------------------------------------------------------------------------

/** Get human-readable branch name for display */
function branch_name_from_id($branch_id) {
    return ($branch_id == 1) ? 'Sorsogon' : 'Juban';
}

/** Money formatting (kept original name for compatibility) */
function peso($n) { return '₱' . number_format($n, 2); }
/** Money formatting without decimals (whole peso) */
function peso_whole($n) { return '₱' . number_format(floor($n), 0); }

/** Fetch return items and compute raw product total for a return */
function fetch_return_items_and_total(mysqli $conn, int $return_id) {
    $items_sql = "SELECT ri.*, p.name as product_name, p.unit 
                 FROM return_items ri 
                 JOIN products p ON ri.product_id = p.id 
                 WHERE ri.return_id = ?";
    $items_stmt = mysqli_prepare($conn, $items_sql);
    mysqli_stmt_bind_param($items_stmt, 'i', $return_id);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    $product_total = 0.0;
    $items = [];
    while ($item = mysqli_fetch_assoc($items_result)) {
        $product_total += (float)($item['price'] * $item['quantity']);
        $items[] = $item;
    }
    mysqli_stmt_close($items_stmt);
    return [$items, $product_total];
}

/** Determine if the given return is the first return for the sale */
function is_first_return(mysqli $conn, int $sale_id, int $return_id): bool {
    $check_first_sql = "SELECT COUNT(*) as cnt 
                        FROM returns r2 
                        WHERE r2.sale_id = ? 
                          AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
    $check_stmt = mysqli_prepare($conn, $check_first_sql);
    mysqli_stmt_bind_param($check_stmt, 'ii', $sale_id, $return_id);
    mysqli_stmt_execute($check_stmt);
    $check_res = mysqli_stmt_get_result($check_stmt);
    $rowCnt = mysqli_fetch_assoc($check_res);
    mysqli_stmt_close($check_stmt);
    return isset($rowCnt['cnt']) ? (intval($rowCnt['cnt']) === 0) : false;
}

/**
 * Compute financials for a specific return given sale context.
 * Returns array with keys: items, product_total, includes_repair_fee,
 * refunded_products, refunded_repair_fee, total_refund, total_discount,
 * discount_products, discount_repair
 */
function compute_return_financials(
    mysqli $conn,
    int $sale_id,
    int $return_id,
    float $repair_fee,
    float $discount_ratio,
    float $sale_total_amount,
    float $sale_discount,
    bool $is_back_job_sale
) {
    // Items and product total for this return
    [$items, $product_total] = fetch_return_items_and_total($conn, $return_id);

    // Determine inclusion of repair fee (first return only and has products)
    $includes_repair = false;
    $refunded_repair_fee = 0.0;
    if ($is_back_job_sale && $product_total > 0 && is_first_return($conn, $sale_id, $return_id)) {
        $includes_repair = true;
        $refunded_repair_fee = $repair_fee * (1 - $discount_ratio);
    }

    // Discounted product refund
    $refunded_products = $product_total * (1 - $discount_ratio);

    // Cap by net sale amount
    $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
    $total_refund = $refunded_products + $refunded_repair_fee;
    if ($total_refund > $net_sale_cap) {
        $total_refund = $net_sale_cap;
    }

    // Discount amounts for display rows
    $discount_products = max($product_total - $refunded_products, 0);
    $discount_repair = $includes_repair ? max($repair_fee - $refunded_repair_fee, 0) : 0.0;
    $total_discount = $discount_products + $discount_repair;

    return [
        'items' => $items,
        'product_total' => $product_total,
        'includes_repair_fee' => $includes_repair,
        'refunded_products' => $refunded_products,
        'refunded_repair_fee' => $refunded_repair_fee,
        'total_refund' => $total_refund,
        'total_discount' => $total_discount,
        'discount_products' => $discount_products,
        'discount_repair' => $discount_repair,
    ];
}

// Get sale ID and branch ID from URL
$sale_id = isset($_GET['sale_id']) ? intval($_GET['sale_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if (!$sale_id || !$branch_id) {
    header('Location: Sales_history1.php');
    exit;
}

// Get branch information
$branch_name = branch_name_from_id($branch_id);

// Fetch sale details
$sql = "SELECT s.*, cp.name AS cashier_name, m.name AS mechanic_name 
        FROM sales s 
        LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id 
        LEFT JOIN mechanics m ON s.mechanic_id = m.id 
        WHERE s.id = ? AND s.branch_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $sale_id, $branch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sale = mysqli_fetch_assoc($result);

if (!$sale) {
    header('Location: Sales_history1.php');
    exit;
}

// Fetch returns for this sale
$returns_sql = "SELECT r.*, u.username AS processed_by_username, cp.name AS processed_by_name
                FROM returns r
                LEFT JOIN users u ON r.cashier_id = u.id
                LEFT JOIN cashier_profiles cp ON r.cashier_id = cp.user_id
                WHERE r.sale_id = ? AND r.branch_id = ?
                ORDER BY r.created_at DESC";
$returns_stmt = mysqli_prepare($conn, $returns_sql);
mysqli_stmt_bind_param($returns_stmt, 'ii', $sale_id, $branch_id);
mysqli_stmt_execute($returns_stmt);
$returns_result = mysqli_stmt_get_result($returns_stmt);

// Get repair fee from sale
$repair_fee = isset($sale['repair_fee']) ? floatval($sale['repair_fee']) : 0;
$is_back_job_sale = ($repair_fee > 0);

// Prepare discount context from original sale (used to compute refunded amounts)
$sale_total_amount = isset($sale['total_amount']) ? floatval($sale['total_amount']) : 0.0;
$sale_discount = isset($sale['discount']) ? floatval($sale['discount']) : 0.0;
$discount_ratio = ($sale_total_amount > 0)
    ? max(min($sale_discount / $sale_total_amount, 1), 0)
    : 0.0; // 0..1 proportion of discount to apply to returns

// Calculate product total (sum of sale items) for display
$product_total = 0.0;
$product_total_sql = "SELECT COALESCE(SUM(si.quantity * si.price), 0) as product_total FROM sale_items si WHERE si.sale_id = ?";
$product_stmt = mysqli_prepare($conn, $product_total_sql);
if ($product_stmt) {
    mysqli_stmt_bind_param($product_stmt, 'i', $sale_id);
    mysqli_stmt_execute($product_stmt);
    $res_pt = mysqli_stmt_get_result($product_stmt);
    $pt_row = mysqli_fetch_assoc($res_pt);
    $product_total = isset($pt_row['product_total']) ? floatval($pt_row['product_total']) : 0.0;
    mysqli_stmt_close($product_stmt);
}

// Count returns for display
$returns_count = mysqli_num_rows($returns_result);

// Extract date from sale for back navigation (preserve date filter)
$sale_date = date('Y-m-d', strtotime($sale['created_at']));

// Build back URL - always use Sales_history1.php with branch_id parameter
// This ensures consistency since Sales_history1.php handles both branches via branch_id parameter
$back_url = "Sales_history1.php?branch_id=" . $branch_id . "&date=" . urlencode($sale_date);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Returns - Sale #<?= $sale_id ?> - JohnTech System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
</head>
<body style="margin: 0 !important; padding: 0 !important;">
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

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0" style="color: #1a202c !important; font-size: 1.75rem;">
                <i class="bi bi-arrow-return-left me-2"></i>Returns for Sale #<?= $sale_id ?> - <?= $branch_name ?>
            </h2>
            <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-outline-secondary" title="Return to Sales History">
                <i class="bi bi-arrow-left me-2"></i>Back to Sales History
            </a>
        </div>
        
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            <!-- Sale Information -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Original Sale Information</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li><strong>Sale ID:</strong> #<?= $sale['id'] ?></li>
                                <li><strong>Date:</strong> <?= date('F j, Y g:i A', strtotime($sale['created_at'])) ?></li>
                                <li><strong>Branch:</strong> <?= $branch_name ?></li>
                                <li><strong>Cashier:</strong> <?= htmlspecialchars($sale['cashier_name']) ?></li>
                                <li><strong>Mechanic:</strong> <?= $sale['mechanic_name'] ? htmlspecialchars($sale['mechanic_name']) : 'None' ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Original Sale Details</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li><strong>Item Price:</strong> <?= peso($product_total) ?></li>
                                <?php if ($repair_fee > 0): ?>
                                    <li><strong>Repair/Service Fee:</strong> <span class="badge bg-warning text-dark"><?= peso($repair_fee) ?></span></li>
                                <?php endif; ?>
                                <?php
                                    // Show discount details if present
                                    $discountAmt = isset($sale['discount']) ? floatval($sale['discount']) : 0;
                                    $discountRate = isset($sale['discount_rate']) ? floatval($sale['discount_rate']) : null;
                                ?>
                                <?php if ($discountAmt > 0): ?>
                                    <li><strong>Discount:</strong>
                                        <?php
                                            $pctLabel = '';
                                            if ($discountRate !== null && $discountRate > 0) {
                                                // If stored as a decimal (e.g., 0.05), convert to percentage; if already a percent (e.g., 5), keep it
                                                $normalizedPct = ($discountRate <= 1) ? ($discountRate * 100) : $discountRate;
                                                $normalizedPct = round($normalizedPct, 2);
                                                $pctLabel = (intval($normalizedPct) == $normalizedPct)
                                                    ? intval($normalizedPct) . '%'
                                                    : $normalizedPct . '%';
                                            } else {
                                                // derive percentage from amounts when possible (use product_total + repair_fee as base)
                                                $baseTotal = floatval($sale['total_amount']);
                                                if ($baseTotal > 0) {
                                                    $discountPct = round(($discountAmt / $baseTotal) * 100, 2);
                                                    $pctLabel = (intval($discountPct) == $discountPct) ? intval($discountPct) . '%' : $discountPct . '%';
                                                }
                                            }
                                            // Match Sales History: floor discount amount to whole peso for display
                                            $discount_whole_display = max(floor($discountAmt), 0);
                                            $discount_amt_text = '₱' . number_format($discount_whole_display, 0);
                                            echo $pctLabel ? htmlspecialchars($pctLabel) . ' (' . $discount_amt_text . ')' : $discount_amt_text;
                                        ?>
                                    </li>
                                <?php endif; ?>

                                <?php
                                    // Compute payable (total after discount) - align with Sales History (floor discount)
                                    $sale_total_amount = isset($sale['total_amount']) ? floatval($sale['total_amount']) : ($product_total + $repair_fee);
                                    $payable = $sale_total_amount - floor($discountAmt);
                                ?>

                                <li class="mt-2"><strong>Total Amount:</strong> <span class="fs-5 ms-2"><?= peso($payable) ?></span></li>
                                <hr>
                                <li><strong>Amount Received:</strong> <?= peso($sale['amount_received']) ?></li>
                                <li><strong>Change:</strong> <?= peso_whole($sale['change_amount']) ?></li>
                                <?php if (!empty($sale['remarks'])): ?>
                                    <li><strong>Remarks:</strong> <?= htmlspecialchars($sale['remarks']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Returns List -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0"><i class="bi bi-list-ul me-2"></i>Return Transactions</h4>
                <?php if ($is_back_job_sale && $returns_count == 0): ?>
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-tools me-1"></i>Back Job Sale - Repair Fee: <?= peso($repair_fee) ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <?php if ($returns_count > 0): ?>
                <?php 
                // Reset the result pointer to iterate through returns
                mysqli_data_seek($returns_result, 0);
                while ($return = mysqli_fetch_assoc($returns_result)):
                    // Compute all figures for this return using helpers
                    $calc = compute_return_financials(
                        $conn,
                        (int)$sale_id,
                        (int)$return['id'],
                        (float)$repair_fee,
                        (float)$discount_ratio,
                        (float)$sale_total_amount,
                        (float)$sale_discount,
                        (bool)$is_back_job_sale
                    );
                    $return_items_list = $calc['items'];
                    $product_total = $calc['product_total'];
                    $includes_repair_fee = $calc['includes_repair_fee'];
                    $refunded_repair_fee = $calc['refunded_repair_fee'];
                    $computed_total_refund = $calc['total_refund'];
                    $total_discount_applied = $calc['total_discount'];
                    // Prepare discount percent label for rows (normalize decimal ratios)
                    $discount_percent_label = '';
                    if ($discount_ratio > 0) {
                        $pct = round($discount_ratio * 100, 2);
                        $discount_percent_label = (intval($pct) == $pct) ? intval($pct) . '%' : $pct . '%';
                    }
                    // Align display with Sales History rules (floor discount to whole peso and recompute display total)
                    $discount_display_whole = max(floor($total_discount_applied), 0);
                    $refund_gross = $product_total + ($includes_repair_fee ? $repair_fee : 0);
                    $display_total_refund = $refund_gross - $discount_display_whole;
                    $cap_whole = max($sale_total_amount - floor($sale_discount), 0);
                    if ($display_total_refund > $cap_whole) { $display_total_refund = $cap_whole; }
                ?>
                    <div class="card mb-3 border-primary">
                        <div class="card-header bg-primary bg-opacity-10">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h6 class="mb-0">
                                        <i class="bi bi-arrow-return-left me-2"></i>Return #<?= $return['id'] ?>
                                        <?php if ($includes_repair_fee): ?>
                                            <span class="badge bg-danger ms-2" title="Back Job - Includes Repair/Service Fee Refund">
                                                <i class="bi bi-tools me-1"></i>Back Job
                                            </span>
                                        <?php endif; ?>
                                        <span class="badge bg-warning text-dark ms-2"><?= peso($display_total_refund) ?></span>
                                    </h6>
                                </div>
                                <div class="col-auto">
                                    <small class="text-muted">
                                        <?= date('M j, Y g:i A', strtotime($return['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Processed by:</strong> <?= htmlspecialchars($return['processed_by_name'] ?: $return['processed_by_username']) ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>Reason:</strong> <?= htmlspecialchars($return['reason']) ?>
                                </div>
                            </div>
                            
                            <!-- Return Amount Breakdown -->
                            <?php // Removed summary banner above the table ?>
                            
                            <!-- Return Items -->
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Item</th>
                                            <th>Unit</th>
                                            <th>Quantity</th>
                                            <th>Price</th>
                                            <th>Condition</th>
                                            <th>Added to Inventory</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($return_items_list as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                <td><?= htmlspecialchars($item['unit']) ?></td>
                                                <td><?= $item['quantity'] ?></td>
                                                <td><?= peso($item['price']) ?></td>
                                                <td>
                                                    <span class="badge <?= $item['condition_status'] === 'good' ? 'bg-success' : 'bg-danger' ?>">
                                                        <?= ucfirst($item['condition_status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= $item['add_to_inventory'] ? '<i class="bi bi-check-circle text-success"></i> Yes' : '<i class="bi bi-x-circle text-danger"></i> No' ?>
                                                </td>
                                                <td><?= peso(($item['price'] * $item['quantity'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if ($includes_repair_fee): ?>
                                            <tr class="table-primary-subtle">
                                                <td colspan="6" class="text-end"><strong>Repair/Service Fee Refund:</strong></td>
                                                <td><strong><?= peso($repair_fee) ?></strong></td>
                                            </tr>
                                            <tr class="table-secondary-subtle">
                                                <td colspan="6" class="text-end"><strong>Discount<?= $discount_percent_label ? ' (' . htmlspecialchars($discount_percent_label) . ')' : '' ?>:</strong></td>
                                                <td><strong><?= '₱' . number_format($discount_display_whole, 0) ?></strong></td>
                                            </tr>
                                            <tr class="table-primary">
                                                <td colspan="6" class="text-end"><strong>Total Return Amount:</strong></td>
                                                <td><strong><?= peso($display_total_refund) ?></strong></td>
                                            </tr>
                                        <?php else: ?>
                                            <tr class="table-secondary-subtle">
                                                <td colspan="6" class="text-end"><strong>Discount<?= $discount_percent_label ? ' (' . htmlspecialchars($discount_percent_label) . ')' : '' ?>:</strong></td>
                                                <td><strong><?= '₱' . number_format($discount_display_whole, 0) ?></strong></td>
                                            </tr>
                                            <tr class="table-primary">
                                                <td colspan="6" class="text-end"><strong>Total Return Amount:</strong></td>
                                                <td><strong><?= peso($display_total_refund) ?></strong></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No returns found for this sale.
                </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-12 text-center">
                    <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-secondary" title="Return to Sales History">
                        <i class="bi bi-arrow-left me-2"></i>Back to Sales History
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
