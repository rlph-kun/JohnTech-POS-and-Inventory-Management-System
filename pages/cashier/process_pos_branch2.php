<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';

header('Content-Type: application/json');

// Helper: format peso
function peso($n) { return '₱' . number_format($n, 2); }

// Get cashier info
$cashier_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$branch_id = 2;

// Get POST data
$cart_data = isset($_POST['cart_data']) ? json_decode($_POST['cart_data'], true) : [];
$repair_fee = isset($_POST['repair_fee']) ? floatval($_POST['repair_fee']) : 0;
$mechanic_id = !empty($_POST['mechanic_id']) ? intval($_POST['mechanic_id']) : null;
$amount_received = isset($_POST['amount_received']) ? floatval($_POST['amount_received']) : 0;
$service_remarks = isset($_POST['service_remarks']) ? trim($_POST['service_remarks']) : '';
$discount_rate = isset($_POST['discount_rate']) ? floatval($_POST['discount_rate']) : 0; // percent

// Validate
// Validation
$errors = [];
// Determine if this is a service-only transaction (no products and a repair fee was provided)
$service_only = (empty($cart_data) || !is_array($cart_data) || count($cart_data) == 0) && $repair_fee > 0;
if ((!$cart_data || !is_array($cart_data) || count($cart_data) == 0) && !$service_only) {
    $errors[] = 'No items in cart.';
}
$total = 0;
foreach ($cart_data as $item) {
    $product_id = intval($item['id']);
    $qty = intval($item['quantity']);
    // Check stock
    $q = mysqli_query($conn, "SELECT quantity FROM products WHERE id = $product_id AND branch_id = $branch_id");
    $row = mysqli_fetch_assoc($q);
    if (!$row || $qty > $row['quantity']) {
        $errors[] = 'Insufficient stock for ' . htmlspecialchars($item['name']);
    }
    $total += floatval($item['price']) * $qty;
}
$grand_total = $total + $repair_fee;
// Compute discount amount from percent (server-side authoritative)
$discount_rate = max(0, min(100, $discount_rate));
$discount_amount = round(($grand_total * ($discount_rate / 100)), 2);
$payable = $grand_total - $discount_amount;
if ($amount_received < $payable) {
    $errors[] = 'Insufficient amount received.';
}
if ($cashier_id == 0) {
    $errors[] = 'Session expired. Please log in again.';
}
// Require service remarks when service is requested (service-only, repair fee > 0, or mechanic assigned)
if ($service_only) {
    if ($repair_fee <= 0) {
        $errors[] = 'Service fee is required for service-only transactions.';
    }
}
// If any service is involved (repair fee > 0 or mechanic assigned or service-only), require remarks
$mechanic_assigned = !empty($mechanic_id);
if ($service_only || $repair_fee > 0 || $mechanic_assigned) {
    if ($service_remarks === '') {
        $errors[] = 'Service remarks are required when service is requested.';
    }
}

if ($errors) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

$change = $amount_received - $payable;

// Insert sale (store discount amount)
$sql = "INSERT INTO sales (branch_id, cashier_id, mechanic_id, total_amount, discount, discount_rate, repair_fee, remarks, amount_received, change_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
$stmt = mysqli_prepare($conn, $sql);
// types: i i i d d d d s d d => iiiddddsdd
mysqli_stmt_bind_param($stmt, 'iiiddddsdd', $branch_id, $cashier_id, $mechanic_id, $grand_total, $discount_amount, $discount_rate, $repair_fee, $service_remarks, $amount_received, $change);
mysqli_stmt_execute($stmt);
$sale_id = mysqli_insert_id($conn);

// Insert sale items & update stock (only if there are products)
if ($cart_data && is_array($cart_data) && count($cart_data) > 0) {
    foreach ($cart_data as $item) {
        $product_id = intval($item['id']);
        $qty = intval($item['quantity']);
        $price = floatval($item['price']);
        // Insert sale item
        $sql = "INSERT INTO sale_items (sale_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'iiid', $sale_id, $product_id, $qty, $price);
        mysqli_stmt_execute($stmt);
        // Update product stock
        mysqli_query($conn, "UPDATE products SET quantity = quantity - $qty WHERE id = $product_id AND branch_id = $branch_id");
    }
}

echo json_encode(['success' => true, 'sale_id' => $sale_id]);
exit;
