<?php
require_once __DIR__ . '/../config.php';
$date = $argv[1] ?? '2025-06-16';
$branch = $argv[2] ?? '1';

echo "Checking sales for branch $branch on date $date\n";

$stmt = $conn->prepare("SELECT id, created_at, total_amount, repair_fee FROM sales WHERE branch_id = ? AND DATE(created_at) = ? ORDER BY created_at ASC");
$stmt->bind_param('is', $branch, $date);
$stmt->execute();
$res = $stmt->get_result();
$sales = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$sales) {
    echo "No sales found.\n";
} else {
    echo "Sales:\n";
    foreach ($sales as $s) {
        echo "- Sale ID: {$s['id']} created_at: {$s['created_at']} total: {$s['total_amount']} repair_fee: {$s['repair_fee']}\n";
    }
}

// Check sale_items for that date

$sql = "SELECT si.* FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.branch_id = ? AND DATE(s.created_at) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('is', $branch, $date);
$stmt->execute();
$res = $stmt->get_result();
$items = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$items) {
    echo "No sale_items for that date.\n";
} else {
    echo "Sale items:\n";
    foreach ($items as $it) {
        echo "- sale_id: {$it['sale_id']} product_id: {$it['product_id']} qty: {$it['quantity']} price: {$it['price']}\n";
    }
}

?>