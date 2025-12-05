<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
if (!isset($_GET['sale_id']) || !is_numeric($_GET['sale_id'])) {
    die('Invalid sale ID.');
}
$sale_id = intval($_GET['sale_id']);

// Fetch sale
$sql = "SELECT s.*, cp.name AS cashier_name, m.name AS mechanic_name, b.name AS branch_name, b.address AS branch_address
        FROM sales s
        LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id
        LEFT JOIN mechanics m ON s.mechanic_id = m.id
        LEFT JOIN branches b ON s.branch_id = b.id
        WHERE s.id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $sale_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sale = mysqli_fetch_assoc($result);
if (!$sale) die('Sale not found.');

// Fetch sale items
$sql_items = "SELECT si.*, p.name, p.unit FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?";
$stmt = mysqli_prepare($conn, $sql_items);
mysqli_stmt_bind_param($stmt, 'i', $sale_id);
mysqli_stmt_execute($stmt);
$result_items = mysqli_stmt_get_result($stmt);
$items = [];
while ($row = mysqli_fetch_assoc($result_items)) {
    $items[] = $row;
}
function peso($n) { return '₱' . number_format($n, 2); }
?>
<head>
    <title>Receipt - JohnTech Branch 2</title>
</head>

<!DOCTYPE html>
<html>
<head>
    <title>Receipt - JohnTech Branch 2</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fff; color: #23272b; }
        .receipt-box { max-width: 420px; margin: 40px auto; border-radius: 14px; background: #fff; box-shadow: 0 4px 24px rgba(44,49,54,0.10); padding: 32px 28px 24px 28px; position: relative; }
        .receipt-header { display: flex; align-items: center; gap: 16px; margin-bottom: 10px; }
        .receipt-logo { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #0d6efd; background: #f4f6fa; }
        .receipt-title { font-size: 1.25rem; font-weight: 700; color: #0d6efd; margin-bottom: 0; }
        .receipt-branch { font-size: 1.05rem; color: #23272b; font-weight: 500; }
        .receipt-sub { text-align: left; color: #888; font-size: 0.98rem; margin-bottom: 18px; margin-top: 2px; }
        .receipt-table { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
    .receipt-table th, .receipt-table td { padding: 10px 0; font-size: 1rem; text-align: left; }
        .receipt-table th { color: #0d6efd; font-weight: 600; border-bottom: 1.5px solid #e0e0e0; }
        .receipt-table td:last-child, .receipt-table th:last-child { text-align: right; }
    .receipt-table tr:last-child td { border-bottom: none; }
    /* separator row between sections */
    .receipt-table tr.section-sep td { border-top: 1px solid #e6e9ee; padding: 12px 0; height: 8px; }
        .receipt-total { font-size: 1.1rem; font-weight: 600; color: #23272b; }
        .receipt-footer { margin-top: 20px; text-align: center; color: #b0b3b8; font-size: 0.97rem; }
        .receipt-btns { text-align: center; margin-top: 22px; }
        .receipt-btns button { padding: 8px 22px; border-radius: 6px; border: none; background: #0d6efd; color: #fff; font-size: 1rem; cursor: pointer; margin-right: 10px; font-weight: 500; transition: background 0.15s; }
        .receipt-btns button:last-child { background: #adb5bd; color: #fff; margin-right: 0; }
        .receipt-btns button:hover { background: #084298; }
        @media print { body { background: #fff; } .receipt-box { box-shadow: none; border: none; margin: 0; } .receipt-btns { display: none; } }
    </style>
</head>
<body>
<div class="receipt-box">
    <div class="receipt-header">
        <img src="<?= BASE_URL ?>/assets/images/johntech.jpg" alt="JohnTech Logo" class="receipt-logo">
        <div>
            <div class="receipt-title">JohnTech System</div>
            <div class="receipt-branch"><?php echo htmlspecialchars($sale['branch_name']); ?> &mdash; <?php echo htmlspecialchars($sale['branch_address']); ?></div>
        </div>
    </div>
    <div class="receipt-sub">
        Date: <?php echo date('Y-m-d h:ia', strtotime($sale['created_at'])); ?><br>
        Cashier: <?php echo htmlspecialchars($sale['cashier_name']); ?>
    </div>
    <table class="receipt-table">
        <thead>
            <tr><th>Item</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['name']); ?></td>
                <td><?php echo $item['quantity']; ?></td>
                <td><?php echo peso($item['price']); ?></td>
                <td><?php echo peso($item['price'] * $item['quantity']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($sale['repair_fee'] > 0): ?>
            <tr>
                <td colspan="3">Repair/Service Fee</td>
                <td><?php echo peso($sale['repair_fee']); ?></td>
            </tr>
            <?php if (empty($items) && !empty($sale['remarks'])): ?>
            <tr>
                <td colspan="4" style="text-align:left;"><span style="font-weight:600;">Remarks:</span> <?php echo htmlspecialchars($sale['remarks']); ?></td>
            </tr>
            <?php endif; ?>
        <?php endif; ?>
        </tbody>
    <tr class="section-sep"><td colspan="4"></td></tr>
    <tfoot>
            <tr><td colspan="3" class="receipt-total">Grand Total</td><td class="receipt-total"><?php echo peso($sale['total_amount']); ?></td></tr>
            <?php if (!empty($sale['discount']) && floatval($sale['discount']) > 0):
                $discountAmt = floatval($sale['discount']);
                $totalAmt = floatval($sale['total_amount']);
                $payable = $totalAmt - $discountAmt;
                if ($payable < 0) $payable = 0;
                // Prefer stored discount_rate if available
                $storedRate = isset($sale['discount_rate']) ? floatval($sale['discount_rate']) : null;
                $pctLabel = '';
                if ($storedRate !== null && $storedRate > 0) {
                    if (intval($storedRate) == $storedRate) $pctLabel = intval($storedRate) . '%';
                    else $pctLabel = $storedRate . '%';
                } else {
                    // fallback to computed percent
                    if ($totalAmt > 0) {
                        $discountPct = round(($discountAmt / $totalAmt) * 100, 2);
                        if (intval($discountPct) == $discountPct) $pctLabel = intval($discountPct) . '%';
                        else $pctLabel = $discountPct . '%';
                    }
                }
            ?>
            <tr>
                <td colspan="3"><?php echo $pctLabel ? 'Discount (' . htmlspecialchars($pctLabel) . ')' : 'Discount'; ?></td>
                <td><?php echo peso($discountAmt); ?></td>
            </tr>
            <tr><td colspan="3" class="receipt-total">Payable</td><td class="receipt-total"><?php echo peso($payable); ?></td></tr>
            <?php else: ?>
            <?php $payable = floatval($sale['total_amount']); ?>
            <?php endif; ?>
            <?php if (isset($sale['amount_received'])): ?>
            <tr><td colspan="3">Amount Received</td><td><?php echo peso(floatval($sale['amount_received'])); ?></td></tr>
            <?php endif; ?>
            <?php if (isset($sale['change_amount'])): ?>
            <tr><td colspan="3">Change</td><td><?php echo peso(floatval($sale['change_amount'])); ?></td></tr>
            <?php endif; ?>
        </tfoot>
    </table>
    <div style="margin-top:12px;"><b>Mechanic:</b> <?php echo htmlspecialchars($sale['mechanic_name'] ?? ''); ?></div>
    <div class="receipt-footer">Thank you for your purchase!<br>This receipt is saved in sales history.</div>
    <div class="receipt-btns">
        <button onclick="window.print()">Print</button>
        <button onclick="window.close()">Close</button>
    </div>
</div>
</body>
</html> 