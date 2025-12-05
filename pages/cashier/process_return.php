<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';

header('Content-Type: application/json');

// Get cashier info
$cashier_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : 0;

// Get POST data
$sale_id = isset($_POST['sale_id']) ? intval($_POST['sale_id']) : 0;
$return_items = isset($_POST['return_items']) ? json_decode($_POST['return_items'], true) : [];
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
$include_repair_fee = isset($_POST['include_repair_fee']) ? ($_POST['include_repair_fee'] === '1') : false;

// Validate
$errors = [];
if (!$sale_id) {
    $errors[] = 'Invalid sale ID.';
}
if (!$return_items || !is_array($return_items) || count($return_items) == 0) {
    $errors[] = 'No items to return.';
}
if (empty($reason)) {
    $errors[] = 'Please provide a reason for the return.';
}
if ($cashier_id == 0) {
    $errors[] = 'Session expired. Please log in again.';
}
if ($branch_id == 0) {
    $errors[] = 'Invalid branch ID.';
}

// Verify sale exists and belongs to the correct branch, and fetch repair_fee, total_amount and discount
$repair_fee = 0;
$sale_total_amount = 0;
$sale_discount_amount = 0;
$sale_items_map = [];
if (!$errors) {
    $sql = "SELECT id, repair_fee, total_amount, discount FROM sales WHERE id = ? AND branch_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $sale_id, $branch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $sale_data = mysqli_fetch_assoc($result);
    if (!$sale_data) {
        $errors[] = 'Sale not found or does not belong to this branch.';
    } else {
        $repair_fee = isset($sale_data['repair_fee']) ? floatval($sale_data['repair_fee']) : 0;
        $sale_total_amount = isset($sale_data['total_amount']) ? floatval($sale_data['total_amount']) : 0;
        $sale_discount_amount = isset($sale_data['discount']) ? floatval($sale_data['discount']) : 0;
    }

    // Build a map of sale item original totals to use for proportional discount allocation
    $sql_items_all = "SELECT si.product_id, si.quantity, si.price FROM sale_items si WHERE si.sale_id = ?";
    $stmt_items_all = mysqli_prepare($conn, $sql_items_all);
    if ($stmt_items_all) {
        mysqli_stmt_bind_param($stmt_items_all, 'i', $sale_id);
        mysqli_stmt_execute($stmt_items_all);
        $res_items_all = mysqli_stmt_get_result($stmt_items_all);
        while ($r = mysqli_fetch_assoc($res_items_all)) {
            $sale_items_map[intval($r['product_id'])] = ['quantity' => floatval($r['quantity']), 'price' => floatval($r['price'])];
        }
    }
}

// Calculate total return amount and validate items. Build validated items list for DB operations.
$validated_items = [];
$product_gross_total = 0; // sum of qty * price for returned items
foreach ($return_items as $item) {
    $product_id = intval($item['id']);
    $qty = intval($item['quantity']);
    $price = floatval($item['price']);
    $condition = isset($item['condition']) ? $item['condition'] : 'good';
    
    if ($qty <= 0) {
        $errors[] = 'Invalid quantity for ' . htmlspecialchars($item['name']);
        continue;
    }
    
    // Validate condition
    if (!in_array($condition, ['good', 'damaged', 'broken'])) {
        $errors[] = 'Invalid condition for ' . htmlspecialchars($item['name']);
        continue;
    }
    
    // Verify item was in original sale and get available quantity for return
    $sql = "SELECT si.quantity, si.price,
            (SELECT COALESCE(SUM(ri.quantity), 0)
             FROM return_items ri
             JOIN returns r ON ri.return_id = r.id
             WHERE r.sale_id = si.sale_id AND ri.product_id = si.product_id
            ) AS returned_qty
            FROM sale_items si 
            WHERE si.sale_id = ? AND si.product_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $sale_id, $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    if (!$row) {
        $errors[] = 'Product ' . htmlspecialchars($item['name']) . ' was not found in this sale.';
        continue;
    }
    
    $max_returnable = $row['quantity'] - $row['returned_qty'];
    if ($qty > $max_returnable) {
        $errors[] = 'Cannot return ' . $qty . ' units of ' . htmlspecialchars($item['name']) . '. Maximum returnable: ' . $max_returnable;
        continue;
    }
    
    // Verify price matches original sale price
    if (abs($price - $row['price']) > 0.01) {
        $errors[] = 'Price mismatch for ' . htmlspecialchars($item['name']);
        continue;
    }
    
    // accumulate gross product total for returned qty
    $line_gross = $price * $qty;
    $product_gross_total += $line_gross;

    // store validated item info for later DB insert
    $validated_items[] = [
        'product_id' => $product_id,
        'quantity' => $qty,
        'price' => $price,
        'condition' => $condition,
        'original_qty' => floatval($row['quantity']),
        'line_sale_total' => floatval($row['quantity']) * floatval($row['price']),
        'name' => $item['name']
    ];
}

// Determine repair fee inclusion (only refunded once on first product return)
$repair_fee_already_refunded = false;
if ($include_repair_fee && $repair_fee > 0) {
    $sql_check_previous = "SELECT COUNT(*) as count FROM return_items ri
                           JOIN returns r ON ri.return_id = r.id
                           WHERE r.sale_id = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check_previous);
    if ($stmt_check) {
        mysqli_stmt_bind_param($stmt_check, 'i', $sale_id);
        mysqli_stmt_execute($stmt_check);
        $result_check = mysqli_stmt_get_result($stmt_check);
        $prev_row = mysqli_fetch_assoc($result_check);
        $repair_fee_already_refunded = ($prev_row['count'] > 0);
    }
}

// If there were validation errors, abort early
if ($errors) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// Compute discount allocation and final totals server-side
$discount_refund_total = 0.0;
$sale_total = $sale_total_amount; // grand total before discount
$sale_discount = $sale_discount_amount;

// Allocate discount proportionally across returned items
foreach ($validated_items as $v) {
    $line_sale_total = $v['line_sale_total'];
    $orig_qty = $v['original_qty'] > 0 ? $v['original_qty'] : 1;
    $return_qty = $v['quantity'];
    $line_discount_full = 0.0;
    if ($sale_discount > 0 && $sale_total > 0) {
        $line_discount_full = ($line_sale_total / $sale_total) * $sale_discount;
    }
    // discount proportional to returned qty
    $item_discount = ($orig_qty > 0) ? ($line_discount_full * ($return_qty / $orig_qty)) : 0.0;
    $discount_refund_total += $item_discount;
}

// Include repair fee if applicable and compute its discount share
$repair_included_amount = 0.0;
if ($include_repair_fee && $repair_fee > 0 && !$repair_fee_already_refunded) {
    $repair_included_amount = $repair_fee;
    if ($sale_discount > 0 && $sale_total > 0) {
        $discount_for_repair = ($repair_fee / $sale_total) * $sale_discount;
        $discount_refund_total += $discount_for_repair;
    }
}

// Gross return total = returned product gross + repair (if included)
$gross_return_total = $product_gross_total + $repair_included_amount;
// Net return amount after refunding proportional discount
$net_return_total = $gross_return_total - $discount_refund_total;

// Final total amount to record
$total_amount = round($net_return_total, 2);

if ($errors) {
    echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
    exit;
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Insert return record
    $sql = "INSERT INTO returns (sale_id, branch_id, cashier_id, total_amount, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare return insert statement: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, 'iiids', $sale_id, $branch_id, $cashier_id, $total_amount, $reason);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to insert return record: ' . mysqli_stmt_error($stmt));
    }
    
    $return_id = mysqli_insert_id($conn);

    // If returns table has a 'discount_refund' column, store the computed discount refund amount
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM returns LIKE 'discount_refund'");
    if ($col_check && mysqli_num_rows($col_check) > 0) {
        $sql_upd = "UPDATE returns SET discount_refund = ? WHERE id = ?";
        $stmt_upd = mysqli_prepare($conn, $sql_upd);
        if ($stmt_upd) {
            $dr = round($discount_refund_total, 2);
            mysqli_stmt_bind_param($stmt_upd, 'di', $dr, $return_id);
            mysqli_stmt_execute($stmt_upd);
        }
    }

    // Insert return items & update stock
    foreach ($return_items as $item) {
        $product_id = intval($item['id']);
        $qty = intval($item['quantity']);
        $price = floatval($item['price']);
        $condition = isset($item['condition']) ? $item['condition'] : 'good';
        $add_to_inventory = ($condition === 'good') ? 1 : 0;
        
        // Insert return item with condition
        $sql = "INSERT INTO return_items (return_id, product_id, quantity, price, condition_status, add_to_inventory) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare return item insert statement: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, 'iiidsi', $return_id, $product_id, $qty, $price, $condition, $add_to_inventory);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to insert return item: ' . mysqli_stmt_error($stmt));
        }
        
        // Only update product stock if item is in good condition
        if ($condition === 'good') {
            $sql = "UPDATE products SET quantity = quantity + ? WHERE id = ? AND branch_id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'iii', $qty, $product_id, $branch_id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to update product stock.');
            }
        }
    }

    // Log the return action for audit trail (optional - don't fail if table doesn't exist)
    $item_details = [];
    foreach ($return_items as $item) {
        $condition = isset($item['condition']) ? $item['condition'] : 'good';
        $add_to_inv = ($condition === 'good') ? 'added to inventory' : 'not added to inventory';
        $item_details[] = $item['name'] . ' (qty: ' . $item['quantity'] . ', condition: ' . $condition . ', ' . $add_to_inv . ')';
    }
    
    $back_job_note = '';
    if ($include_repair_fee && $repair_fee > 0 && !$repair_fee_already_refunded) {
        $back_job_note = " [Back Job: Repair/Service Fee ₱" . number_format($repair_fee, 2) . " included]";
    }
    $discount_note = '';
    if (!empty($discount_refund_total) && $discount_refund_total > 0) {
        $discount_note = " [Discount Refund ₱" . number_format($discount_refund_total, 2) . "]";
    }
    
    $description = "Processed return for Sale ID: $sale_id, Total Amount: ₱" . number_format($total_amount, 2) . ", Items: " . implode(', ', $item_details) . $back_job_note . $discount_note . ", Reason: $reason";
    
    // Check if audit_log table exists before trying to insert
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'audit_log'");
    if (mysqli_num_rows($table_check) > 0) {
        $sql = "INSERT INTO audit_log (user_id, action, description, created_at) VALUES (?, 'Return Processed', ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $cashier_id, $description);
            mysqli_stmt_execute($stmt); // Don't fail if audit log fails
        }
    }

    // Commit transaction
    mysqli_commit($conn);
    echo json_encode([
        'success' => true, 
        'return_id' => $return_id,
        'message' => 'Return processed successfully!'
    ]);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Return processing error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred while processing the return. Please try again.']);
}

mysqli_close($conn);
exit; 