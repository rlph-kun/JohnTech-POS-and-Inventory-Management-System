<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
include '../../includes/cashier_sidebar.php';

// Date filter
$date_filter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Fetch sales for branch 1 and date
$sql = "SELECT s.*, cp.name AS cashier_name, m.name AS mechanic_name FROM sales s
        LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id
        LEFT JOIN mechanics m ON s.mechanic_id = m.id
        WHERE s.branch_id = 1 AND DATE(s.created_at) = ? ORDER BY s.created_at DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $date_filter);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sales = [];
while ($row = mysqli_fetch_assoc($result)) {
    $sales[] = $row;
}
function peso($n) { return '₱' . number_format($n, 2); }

// Calculate total sales for the day (NET of discount)
$total_sales_today = 0;
$sales_total_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) 
                     FROM sales 
                     WHERE branch_id = 1 AND DATE(created_at) = ?";
$stmt_sales_total = $conn->prepare($sales_total_sql);
$stmt_sales_total->bind_param('s', $date_filter);
$stmt_sales_total->execute();
$stmt_sales_total->bind_result($total_sales_today);
$stmt_sales_total->fetch();
$stmt_sales_total->close();
// Floor to whole pesos to align with discount policy
$total_sales_today = floor($total_sales_today);

// Calculate total returns for the day
// Recompute returns applying sale discount proportionally and include repair fee on first return only
$sql_returns = "SELECT r.id, r.sale_id, r.total_amount, 
                       s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                FROM returns r
                JOIN sales s ON r.sale_id = s.id
                WHERE r.branch_id = 1 AND DATE(r.created_at) = ?";
$stmt = $conn->prepare($sql_returns);
$stmt->bind_param('s', $date_filter);
$stmt->execute();
$returns_result = $stmt->get_result();

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

    // Start with discounted product refund
    $return_amount = $product_total * (1 - $discount_ratio);

    // Include discounted repair fee once on the first return when there are product items
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

    // Cap by the net sale amount (sale total minus discount)
    $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
    if ($return_amount > $net_sale_cap) {
        $return_amount = $net_sale_cap;
    }

    $total_returns_today += $return_amount;
}

$stmt->close();
// Floor returns to whole pesos as well
$total_returns_today = floor($total_returns_today);
$net_sales_today = $total_sales_today - $total_returns_today;
if ($net_sales_today < 0) { $net_sales_today = 0; }

// Calculate product returns for the day
$sql_product_returns = "SELECT COALESCE(SUM(ri.quantity),0) AS product_returns
    FROM return_items ri
    JOIN returns r ON ri.return_id = r.id
    WHERE r.branch_id = 1 AND DATE(r.created_at) = ?";
$stmt = $conn->prepare($sql_product_returns);
$stmt->bind_param('s', $date_filter);
$stmt->execute();
$stmt->bind_result($product_returns_today);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sorsogon Branch - Sales History</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
</head>
<body style="margin: 0 !important; padding: 0 !important;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <h2 class="mb-2 mt-0 pt-0"><i class="bi bi-clock-history me-2"></i>Sales History - Sorsogon Branch</h2>
        <div class="content-card">
            <div class="row mb-4 justify-content-center">
                <div class="col-md-4 mb-2">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="bi bi-arrow-counterclockwise text-danger" style="font-size: 2rem;"></i>
                            </div>
                            <div class="fw-bold text-secondary small">Returns for <?= htmlspecialchars($date_filter) ?></div>
                            <div class="fs-4 fw-bold text-danger"><?= peso($total_returns_today) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-2">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="bi bi-graph-up-arrow text-success" style="font-size: 2rem;"></i>
                            </div>
                            <div class="fw-bold text-secondary small">Net Sales for <?= htmlspecialchars($date_filter) ?></div>
                            <div class="fs-4 fw-bold text-success"><?= peso($net_sales_today) ?></div>
                        </div>
                    </div>
                </div>
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
            <form method="get" class="row g-2 align-items-end mb-3">
                <div class="col-auto">
                    <label for="date" class="form-label mb-0">Select Date:</label>
                    <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($date_filter) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
                <div class="col-auto">
                    <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-success">Today's Sales</a>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <!--<th><i class="bi bi-calendar-date me-1"></i>Date</th>-->
                            <th><i class="bi bi-receipt me-1"></i>Sale ID</th>
                            <th><i class="bi bi-person me-1"></i>Cashier</th>
                            <!--<th><i class="bi bi-currency-peso me-1"></i>Total Amount</th>-->
                            <th>Items/Services</th>
                            <th>Remarks</th>
                            <th>Mechanic</th>
                            <th>Grand Total</th>
                            <th>Discount</th>
                            <th><i class="bi bi-currency-peso me-1"></i>Total Amount</th>
                            <th>Amount Received</th>
                            <th>Change</th>
                            <th>Return</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr><td colspan="12" class="text-center">No sales found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <!--<td><?= date('Y-m-d', strtotime($sale['created_at'])) ?></td>-->
                                    <td><?= $sale['id'] ?></td>
                                    <td><?= htmlspecialchars($sale['cashier_name']) ?></td>
                                    <!--<td><?= peso($sale['total_amount']) ?></td>-->
                                    <td>
                                        <ul class="mb-0 ps-3">
                                        <?php
                                        $items = $conn->query("SELECT si.*, p.name, (
                                            SELECT COALESCE(SUM(ri.quantity), 0)
                                            FROM return_items ri
                                            JOIN returns r ON ri.return_id = r.id
                                            WHERE r.sale_id = si.sale_id AND ri.product_id = si.product_id
                                        ) AS returned_qty
                                        FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = " . intval($sale['id']));
                                        while ($item = $items->fetch_assoc()): ?>
                                            <li><?= htmlspecialchars($item['name']) ?> x<?= $item['quantity'] ?><?= $item['returned_qty'] > 0 ? ' <span class="badge bg-success">Returned</span>' : '' ?></li>
                                        <?php endwhile; ?>
                                        <?php if ($sale['repair_fee'] > 0): ?>
                                            <li>Repair/Service Fee</li>
                                        <?php endif; ?>
                                        </ul>
                                    </td>
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
                                    <td><?= $sale['mechanic_name'] ? htmlspecialchars($sale['mechanic_name']) : '<span class="text-muted">-</span>' ?></td>
                                    <td><?= peso($sale['total_amount']) ?></td>
                                    <td>
                                        <?php
                                        $discountAmt = floatval($sale['discount']);
                                        $discountRate = isset($sale['discount_rate']) ? floatval($sale['discount_rate']) : null;
                                        if ($discountAmt > 0) {
                                            // Prefer stored discount_rate when available
                                            $pctLabel = '';
                                            if ($discountRate !== null && $discountRate > 0) {
                                                if (intval($discountRate) == $discountRate) $pctLabel = intval($discountRate) . '%';
                                                else $pctLabel = $discountRate . '%';
                                            } else {
                                                $totalAmt = floatval($sale['total_amount']);
                                                if ($totalAmt > 0) {
                                                    $discountPct = round(($discountAmt / $totalAmt) * 100, 2);
                                                    $pctLabel = (intval($discountPct) == $discountPct) ? intval($discountPct) . '%' : $discountPct . '%';
                                                }
                                            }
                                            // Force discount amount to whole pesos
                                            $discountWhole = max(floor($discountAmt), 0);
                                            $discountText = '₱' . number_format($discountWhole, 0);
                                            echo $pctLabel ? htmlspecialchars($pctLabel) . ' (' . $discountText . ')' : $discountText;
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <?php $payable = floatval($sale['total_amount']) - floor(floatval($sale['discount'])); ?>
                                    <td><?= peso($payable) ?></td>
                                    <td><?= peso($sale['amount_received']) ?></td>
                                    <td><?= peso($sale['change_amount']) ?></td>
                                    <td>
                                        <a href="return_items.php?sale_id=<?= $sale['id'] ?>&branch_id=1" class="btn btn-warning btn-sm">
                                            <i class="bi bi-arrow-return-left"></i> Return
                                        </a>
                                    </td>
                                    <td><a href="print_receipt_branch1.php?sale_id=<?= $sale['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">Print</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
body {
    margin: 0 !important;
    padding: 0 !important;
}

.container-fluid {
    margin: 0 !important;
    padding: 0 !important;
}

.main-content {
    margin-left: 250px !important;
    margin-right: 20px !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    padding: 1.5rem 1rem !important;
    min-height: 100vh;
    transition: all 0.3s ease-in-out;
    max-width: calc(100vw - 270px);
    overflow-x: auto;
}

h2 {
    margin: 0 !important;
    padding: 0 !important;
}

.content-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(44,49,54,0.08);
    padding: 20px;
    margin: 0 !important;
    transition: all 0.3s ease;
}

.content-card:hover {
    box-shadow: 0 4px 20px rgba(44,49,54,0.12);
    transform: translateY(-2px);
}

/* Responsive styles */
@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0 !important;
        padding: 10px !important;
    }
    .content-card {
        padding: 16px;
        transform: none !important;
    }
    .table th, 
    .table td {
        font-size: 0.95rem;
        padding: 8px;
    }
}

@media (max-width: 767.98px) {
    .main-content {
        padding: 5px !important;
    }
    .content-card {
        padding: 12px;
    }
    h2 {
        padding: 5px 15px 8px 15px !important;
        font-size: 1.3rem;
    }
    .table {
        font-size: 0.9rem;
    }
    .table th, 
    .table td {
        padding: 6px;
    }
}

/* Print styles */
@media print {
    .sidebar,
    .btn {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .content-card {
        box-shadow: none !important;
        padding: 0 !important;
        transform: none !important;
    }
    .table {
        box-shadow: none !important;
    }
    .table th {
        background: #f4f6fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* Responsive styles */
@media (max-width: 1199.98px) {
    .main-content {
        margin-right: 10px !important;
        max-width: calc(100vw - 260px);
    }
    .table {
        min-width: 1100px;
    }
}

@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0 !important;
        margin-right: 10px !important;
        padding: 10px !important;
        max-width: calc(100vw - 20px);
    }
    .content-card {
        padding: 16px;
        transform: none !important;
    }
    .table {
        min-width: 900px;
    }
    .table th, 
    .table td {
        font-size: 0.8rem;
        padding: 8px 6px;
    }
}

@media (max-width: 767.98px) {
    .main-content {
        padding: 5px !important;
        margin-right: 5px !important;
        max-width: calc(100vw - 10px);
    }
    .content-card {
        padding: 12px;
    }
    h2 {
        padding: 5px 15px 8px 15px !important;
        font-size: 1.3rem;
    }
    .table {
        font-size: 0.75rem;
        min-width: 800px;
    }
    .table th, 
    .table td {
        padding: 6px 4px;
        font-size: 0.75rem;
    }
}

/* Print styles */
@media print {
    .sidebar-minimal,
    .btn {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .content-card {
        box-shadow: none !important;
        padding: 0 !important;
        transform: none !important;
    }
    .table {
        box-shadow: none !important;
    }
    .table th {
        background: #f4f6fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>

</body>
</html>
