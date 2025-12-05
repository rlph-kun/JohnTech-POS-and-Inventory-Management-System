<?php
require_once __DIR__ . '/../config.php';
$start = $argv[1] ?? '2025-06-01';
$end = $argv[2] ?? '2025-06-30';
$branch = $argv[3] ?? '1';

echo "Checking sale_items for branch $branch between $start and $end\n";

$sql = "SELECT s.id AS sale_id, s.created_at AS sale_date, si.product_id, si.quantity, si.price
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        WHERE s.branch_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
        ORDER BY s.created_at ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iss', $branch, $start, $end);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$rows) {
    echo "No rows returned from detailed query.\n";
} else {
    echo "Found " . count($rows) . " rows:\n";
    foreach ($rows as $r) {
        echo "- sale_id: {$r['sale_id']} date: {$r['sale_date']} product: {$r['product_id']} qty: {$r['quantity']} price: {$r['price']}\n";
    }
}

?>