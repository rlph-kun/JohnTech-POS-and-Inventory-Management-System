
<?php
// ======================================================================================
// Inventory Management - Admin
// ======================================================================================

// Error reporting for debugging in dev environments
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --------------------------------------------------------------------------
// Helpers (PHP)
// --------------------------------------------------------------------------

/**
 * Compute stock status based on quantity and reorder level
 */
function compute_status($quantity, $reorder_level) {
    if ($quantity == 0) return 'Out of Stock';
    if ($quantity <= $reorder_level) return 'Low Stock';
    return 'In Stock';
}

/**
 * True if value matches accepted tire-size formats
 */
function is_valid_tire_size($value) {
    if ($value === null || $value === '') return false;
    $patterns = [
        '/^\d{2,3}\/\d{2,3}[A-Za-z]*-?\d{2,3}$/', // 120/70-17 or 120/70ZR17
        '/^\d+(?:\.\d+)?-\d{2,3}$/',              // 3.50-10
        '/^\d{1,3}(?:x\d+(?:\.\d+)?)?$/'          // 17 or 17x7.5
    ];
    foreach ($patterns as $rx) {
        if (preg_match($rx, trim($value))) return true;
    }
    return false;
}

/**
 * If size is numeric and unit is ml/L/g, concatenate to form e.g. "500ml" or "1L"
 */
function combine_unit_with_size_if_applicable($unit, $size) {
    $numeric_unit_suffixes = ['ml', 'L', 'g'];
    if ($size !== '' && preg_match('/^\d+(?:\.\d+)?$/', (string)$size) && in_array($unit, $numeric_unit_suffixes, true)) {
        return $size . $unit;
    }
    return $unit;
}

/**
 * Extract numeric prefix from combined unit like "500ml"
 */
function extract_unit_size_prefix($unitValue, $suffixes = ['ml', 'L', 'g']) {
    if (empty($unitValue)) return '';
    foreach ($suffixes as $suffix) {
        if (str_ends_with($unitValue, $suffix)) {
            return substr($unitValue, 0, -strlen($suffix));
        }
    }
    return '';
}

/**
 * Parse a price value that may contain symbols/commas
 */
function parse_price($raw) {
    $clean = preg_replace('/[^0-9\.\-\,]/', '', (string)$raw);
    $clean = str_replace(',', '', $clean);
    return floatval($clean);
}

/**
 * Combine unit + numeric prefix coming from the dedicated Unit Size field
 */
function combine_unit_from_form($unit, $unit_size, array $unit_size_suffixes) {
    $unit = trim((string)$unit);
    $unit_size = trim((string)$unit_size);
    if (
        $unit_size !== '' &&
        preg_match('/^\d+(?:\.\d+)?$/', $unit_size) &&
        in_array($unit, $unit_size_suffixes, true)
    ) {
        return $unit_size . $unit;
    }
    return $unit;
}

/**
 * Normalize product payload from POST/Import
 */
function build_product_payload(array $source, array $unit_size_suffixes) {
    $payload = [
        'product_code'  => trim($source['product_code'] ?? ''),
        'name'          => trim($source['name'] ?? ''),
        'category_id'   => isset($source['category_id']) ? intval($source['category_id']) : null,
        'brand_id'      => isset($source['brand_id']) ? intval($source['brand_id']) : null,
        'model_id'      => isset($source['model_id']) ? intval($source['model_id']) : null,
        'supplier'      => trim($source['supplier'] ?? ''),
        'supplier_id'   => isset($source['supplier_id']) && !empty($source['supplier_id']) ? intval($source['supplier_id']) : null,
        'size'          => trim($source['size'] ?? ''),
        'tire_size_id'  => isset($source['tire_size_id']) && !empty($source['tire_size_id']) ? intval($source['tire_size_id']) : null,
        'description'   => trim($source['description'] ?? ''),
        'price'         => isset($source['price']) ? floatval($source['price']) : 0,
        'quantity'      => isset($source['quantity']) ? intval($source['quantity']) : 0,
        'reorder_level' => isset($source['reorder_level']) ? intval($source['reorder_level']) : 0,
        'unit'          => trim($source['unit'] ?? ''),
        'product_status' => isset($source['product_status']) ? trim($source['product_status']) : 'active',
    ];

    $payload['unit'] = combine_unit_from_form($payload['unit'], $source['unit_size'] ?? '', $unit_size_suffixes);
    $payload['status'] = compute_status($payload['quantity'], $payload['reorder_level']);

    return $payload;
}

/**
 * Validate normalized payload; returns array of error strings
 */
function validate_product_payload(array $payload) {
    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Product name is required.';
    }
    if (empty($payload['category_id'])) {
        $errors[] = 'Please select a category.';
    }
    if ($payload['price'] < 0) {
        $errors[] = 'Price cannot be negative.';
    }
    if ($payload['quantity'] < 0) {
        $errors[] = 'Quantity cannot be negative.';
    }
    if ($payload['reorder_level'] < 0) {
        $errors[] = 'Reorder level cannot be negative.';
    }
    if (!in_array($payload['product_status'], ['active', 'discontinued'])) {
        $errors[] = 'Invalid product status.';
    }

    // Check if category is Tires for size validation
    if (!empty($payload['category_id'])) {
        global $conn;
        $cat_stmt = $conn->prepare("SELECT category_name FROM categories WHERE id = ?");
        $cat_stmt->bind_param("i", $payload['category_id']);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        if ($cat_result->num_rows > 0) {
            $cat_data = $cat_result->fetch_assoc();
            if (strtolower($cat_data['category_name']) === 'tires') {
                // Check if either tire_size_id (from dropdown) or size (from text input) is provided
                $has_tire_size_id = !empty($payload['tire_size_id']);
                $has_size = !empty($payload['size']) && trim($payload['size']) !== '';
                
                if (!$has_tire_size_id && !$has_size) {
                    $errors[] = 'Size is required for Tires.';
                } elseif ($has_size && !is_valid_tire_size($payload['size'])) {
                    // Only validate format if using text input (size), not if using dropdown (tire_size_id)
                    $errors[] = 'Invalid tire size format. Examples: 120/70-17 or 3.50-10.';
                }
            }
        }
        $cat_stmt->close();
    }

    return $errors;
}

/**
 * Insert or update product using prepared statements
 */
function persist_product(mysqli $conn, $branch_id, array $payload, $product_id = null) {
    // Get category name for backward compatibility (if category field still exists)
    $category_name = '';
    if (!empty($payload['category_id'])) {
        $cat_stmt = $conn->prepare("SELECT category_name FROM categories WHERE id = ?");
        $cat_stmt->bind_param("i", $payload['category_id']);
        $cat_stmt->execute();
        $cat_result = $cat_stmt->get_result();
        if ($cat_result->num_rows > 0) {
            $cat_data = $cat_result->fetch_assoc();
            $category_name = $cat_data['category_name'];
        }
        $cat_stmt->close();
    }
    
    // Get brand name for backward compatibility
    $brand_name = '';
    if (!empty($payload['brand_id'])) {
        $brand_stmt = $conn->prepare("SELECT brand_name FROM brands WHERE id = ?");
        $brand_stmt->bind_param("i", $payload['brand_id']);
        $brand_stmt->execute();
        $brand_result = $brand_stmt->get_result();
        if ($brand_result->num_rows > 0) {
            $brand_data = $brand_result->fetch_assoc();
            $brand_name = $brand_data['brand_name'];
        }
        $brand_stmt->close();
    }
    
    // Get model name for backward compatibility
    $model_name = '';
    if (!empty($payload['model_id'])) {
        $model_stmt = $conn->prepare("SELECT model_name FROM models WHERE id = ?");
        $model_stmt->bind_param("i", $payload['model_id']);
        $model_stmt->execute();
        $model_result = $model_stmt->get_result();
        if ($model_result->num_rows > 0) {
            $model_data = $model_result->fetch_assoc();
            $model_name = $model_data['model_name'];
        }
        $model_stmt->close();
    }
    
    // Get supplier name for backward compatibility
    $supplier_name = '';
    if (!empty($payload['supplier_id'])) {
        $supplier_stmt = $conn->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $supplier_stmt->bind_param("i", $payload['supplier_id']);
        $supplier_stmt->execute();
        $supplier_result = $supplier_stmt->get_result();
        if ($supplier_result->num_rows > 0) {
            $supplier_data = $supplier_result->fetch_assoc();
            $supplier_name = $supplier_data['supplier_name'];
        }
        $supplier_stmt->close();
    }
    
    // Get tire size value for backward compatibility
    $tire_size_value = '';
    if (!empty($payload['tire_size_id'])) {
        $tire_size_stmt = $conn->prepare("SELECT size_value FROM tire_sizes WHERE id = ?");
        $tire_size_stmt->bind_param("i", $payload['tire_size_id']);
        $tire_size_stmt->execute();
        $tire_size_result = $tire_size_stmt->get_result();
        if ($tire_size_result->num_rows > 0) {
            $tire_size_data = $tire_size_result->fetch_assoc();
            $tire_size_value = $tire_size_data['size_value'];
        }
        $tire_size_stmt->close();
    }
    
    // Handle NULL values for foreign keys
    $category_id = !empty($payload['category_id']) ? $payload['category_id'] : null;
    $brand_id = !empty($payload['brand_id']) ? $payload['brand_id'] : null;
    $model_id = !empty($payload['model_id']) ? $payload['model_id'] : null;
    $supplier_id = !empty($payload['supplier_id']) ? $payload['supplier_id'] : null;
    $tire_size_id = !empty($payload['tire_size_id']) ? $payload['tire_size_id'] : null;
    
    // Use supplier_name and tire_size_value (from tables or fallback to text)
    $supplier_value = !empty($supplier_name) ? $supplier_name : ($payload['supplier'] ?? '');
    $size_value = !empty($tire_size_value) ? $tire_size_value : ($payload['size'] ?? '');
    
    if ($product_id) {
        // Update - include both new fields and legacy fields for compatibility
        // Check if supplier_id and tire_size_id columns exist
        $supplier_id_col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'supplier_id'");
        $tire_size_id_col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'tire_size_id'");
        
        if ($supplier_id_col_check->num_rows > 0 && $tire_size_id_col_check->num_rows > 0) {
            // New structure with supplier_id and tire_size_id
            $stmt = $conn->prepare("UPDATE products SET product_code=?, name=?, category_id=?, brand_id=?, model_id=?, category=?, brand=?, model=?, description=?, price=?, quantity=?, reorder_level=?, unit=?, size=?, supplier=?, supplier_id=?, tire_size_id=?, status=?, product_status=? WHERE id=? AND branch_id=?");
            $stmt->bind_param(
                'ssiiissssdiissssissii',
                $payload['product_code'],
                $payload['name'],
                $category_id,
                $brand_id,
                $model_id,
                $category_name,
                $brand_name,
                $model_name,
                $payload['description'],
                $payload['price'],
                $payload['quantity'],
                $payload['reorder_level'],
                $payload['unit'],
                $size_value,
                $supplier_value,
                $supplier_id,
                $tire_size_id,
                $payload['status'],
                $payload['product_status'],
                $product_id,
                $branch_id
            );
        } else {
            // Old structure without supplier_id and tire_size_id
            $stmt = $conn->prepare("UPDATE products SET product_code=?, name=?, category_id=?, brand_id=?, model_id=?, category=?, brand=?, model=?, description=?, price=?, quantity=?, reorder_level=?, unit=?, size=?, supplier=?, status=?, product_status=? WHERE id=? AND branch_id=?");
            $stmt->bind_param(
                'ssiiissssdiisssssii',
                $payload['product_code'],
                $payload['name'],
                $category_id,
                $brand_id,
                $model_id,
                $category_name,
                $brand_name,
                $model_name,
                $payload['description'],
                $payload['price'],
                $payload['quantity'],
                $payload['reorder_level'],
                $payload['unit'],
                $size_value,
                $supplier_value,
                $payload['status'],
                $payload['product_status'],
                $product_id,
                $branch_id
            );
        }
    } else {
        // Insert
        // Check if supplier_id and tire_size_id columns exist
        $supplier_id_col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'supplier_id'");
        $tire_size_id_col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'tire_size_id'");
        
        if ($supplier_id_col_check->num_rows > 0 && $tire_size_id_col_check->num_rows > 0) {
            // New structure with supplier_id and tire_size_id
            $stmt = $conn->prepare("INSERT INTO products (branch_id, product_code, name, category_id, brand_id, model_id, category, brand, model, description, price, quantity, reorder_level, unit, size, supplier, supplier_id, tire_size_id, status, product_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                'issiiissssdiisssiiss',
                $branch_id,                    // 1: i
                $payload['product_code'],       // 2: s
                $payload['name'],              // 3: s
                $category_id,                  // 4: i
                $brand_id,                     // 5: i
                $model_id,                     // 6: i
                $category_name,                // 7: s
                $brand_name,                   // 8: s
                $model_name,                   // 9: s
                $payload['description'],       // 10: s
                $payload['price'],             // 11: d
                $payload['quantity'],          // 12: i
                $payload['reorder_level'],     // 13: i
                $payload['unit'],              // 14: s
                $size_value,                   // 15: s
                $supplier_value,               // 16: s
                $supplier_id,                  // 17: i
                $tire_size_id,                 // 18: i
                $payload['status'],            // 19: s
                $payload['product_status']     // 20: s
            );
        } else {
            // Old structure without supplier_id and tire_size_id
            $stmt = $conn->prepare("INSERT INTO products (branch_id, product_code, name, category_id, brand_id, model_id, category, brand, model, description, price, quantity, reorder_level, unit, size, supplier, status, product_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                'issiiissssdiisssss',
                $branch_id,
                $payload['product_code'],
                $payload['name'],
                $category_id,
                $brand_id,
                $model_id,
                $category_name,
                $brand_name,
                $model_name,
                $payload['description'],
                $payload['price'],
                $payload['quantity'],
                $payload['reorder_level'],
                $payload['unit'],
                $size_value,
                $supplier_value,
                $payload['status'],
                $payload['product_status']
            );
        }
    }

    $stmt->execute();
    $stmt->close();
}

/**
 * Redirect to inventory page with success flag
 */
function redirect_with_success($branch_id, $message) {
    header('Location: Inventory_management.php?branch_id=' . $branch_id . '&success=' . urlencode($message));
    exit;
}

/**
 * Shared logic for inserting delivery rows + updating product stock
 * Now saves to delivery_summary table and uses good_qty for stock updates
 */
function record_delivery_transaction(mysqli $conn, $branch_id, $product_id, $delivered_qty, $defective_qty, $good_qty, $supplier, $delivery_date, $received_by, $notes = '') {
    $result = ['ok' => false, 'error' => 'Failed to save delivery record. Please try again.'];

    $stmt = $conn->prepare("SELECT quantity, reorder_level FROM products WHERE id=? AND branch_id=?");
    $stmt->bind_param('ii', $product_id, $branch_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $stmt->close();
        $result['error'] = 'Invalid product for this branch.';
        return $result;
    }
    $stmt->bind_result($current_qty, $reorder_level);
    $stmt->fetch();
    $stmt->close();

    // Only add good_qty to inventory (not defective items)
    $new_qty = $current_qty + $good_qty;
    $new_status = compute_status($new_qty, $reorder_level);

    $conn->begin_transaction();
    try {
        // Insert into delivery_summary table
        $ins = $conn->prepare("INSERT INTO delivery_summary (product_id, delivered_qty, defective_qty, good_qty, supplier, received_by, delivery_date, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $ins->bind_param('iiiissss', $product_id, $delivered_qty, $defective_qty, $good_qty, $supplier, $received_by, $delivery_date, $notes);
        $ins->execute();
        $ins->close();

        // Update product stock with good_qty only
        if ($supplier !== '') {
            $upd = $conn->prepare("UPDATE products SET quantity=?, status=?, supplier=? WHERE id=? AND branch_id=?");
            $upd->bind_param('issii', $new_qty, $new_status, $supplier, $product_id, $branch_id);
        } else {
            $upd = $conn->prepare("UPDATE products SET quantity=?, status=? WHERE id=? AND branch_id=?");
            $upd->bind_param('isii', $new_qty, $new_status, $product_id, $branch_id);
        }
        $upd->execute();
        $upd->close();

        $conn->commit();
        return ['ok' => true];
    } catch (Exception $e) {
        $conn->rollback();
        return $result;
    }
}
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// Add PhpSpreadsheet autoloader
require '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

// Fetch all branches for dropdown
$branches = [];
$branch_res = $conn->query("SELECT id, name FROM branches");
while ($row = $branch_res->fetch_assoc()) {
    $branches[$row['id']] = $row['name'];
}

// Get selected branch ID from GET, default to 1 if not set or invalid
$branch_id = isset($_GET['branch_id']) && array_key_exists($_GET['branch_id'], $branches) ? intval($_GET['branch_id']) : 1;

// Categories for Add modal: load from database (will be loaded later with brands/models)
$unit_options = ['pcs', 'set', 'ml', 'L', 'g', 'pair'];
$unit_size_suffixes = ['ml', 'L', 'g'];
$status_options = ['In Stock', 'Low Stock', 'Out of Stock'];

// Handle Add/Edit/Delete
$errors = [];
$success = '';
// Pick up success message from redirect query param, if any
if (!empty($_GET['success'])) {
    $success = trim($_GET['success']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        $payload = build_product_payload($_POST, $unit_size_suffixes);
        $validationErrors = validate_product_payload($payload);
        $errors = array_merge($errors, $validationErrors);
        if (empty($validationErrors)) {
            persist_product($conn, $branch_id, $payload);
            redirect_with_success($branch_id, 'Product added!');
        }
    } elseif (isset($_POST['add_delivery'])) {
        $product_id = intval($_POST['product_id'] ?? 0);
        $delivered_qty = intval($_POST['delivery_quantity'] ?? 0);
        $defective_qty = intval($_POST['defective_qty'] ?? 0);
        $good_qty = intval($_POST['good_qty'] ?? 0);
        $supplier = trim($_POST['delivery_supplier'] ?? '');
        $delivery_date = trim($_POST['delivery_date'] ?? '');
        $received_by = trim($_POST['received_by'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($product_id <= 0) {
            $errors[] = 'Please select a product.';
        }
        if ($delivered_qty <= 0) {
            $errors[] = 'Delivered quantity must be greater than zero.';
        }
        if ($defective_qty < 0) {
            $errors[] = 'Defective quantity cannot be negative.';
        }
        if ($defective_qty > $delivered_qty) {
            $errors[] = 'Defective quantity cannot exceed delivered quantity.';
        }
        if ($good_qty < 0) {
            $errors[] = 'Good quantity cannot be negative.';
        }
        if (empty($delivery_date)) {
            $errors[] = 'Delivery date is required.';
        }

        // Recalculate good_qty to ensure consistency
        $calculated_good_qty = $delivered_qty - $defective_qty;
        if ($calculated_good_qty < 0) {
            $calculated_good_qty = 0;
        }

        if (empty($errors)) {
            $result = record_delivery_transaction($conn, $branch_id, $product_id, $delivered_qty, $defective_qty, $calculated_good_qty, $supplier, $delivery_date, $received_by, $notes);
            if ($result['ok']) {
                redirect_with_success($branch_id, 'Delivery record added!');
            } else {
                $errors[] = $result['error'];
            }
        }
    } elseif (isset($_POST['import_delivery_excel'])) {
        if (isset($_FILES['delivery_excel_file']) && $_FILES['delivery_excel_file']['error'] === 0) {
            $allowed = ['xlsx', 'xls', 'xlsm', 'csv'];
            $filename = $_FILES['delivery_excel_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                try {
                    $spreadsheet = IOFactory::load($_FILES['delivery_excel_file']['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                    $header = [];
                    if (!empty($rows) && is_array($rows[0])) {
                        $first = array_map(function($v){ return is_string($v) ? strtolower(trim($v)) : ''; }, $rows[0]);
                        if (in_array('product_id', $first, true) || in_array('product id', $first, true)) {
                            $header = $first;
                            array_shift($rows);
                        }
                    }
                    $success_count = 0;
                    $error_count = 0;
                    foreach ($rows as $row) {
                        if (!is_array($row)) continue;
                        $product_id = 0;
                        $delivered_qty = 0;
                        $defective_qty = 0;
                        $supplier = '';
                        $delivery_date = '';
                        $received_by = '';
                        $notes = '';
                        if (!empty($header)) {
                            $assoc = [];
                            foreach ($header as $i => $colName) {
                                $assoc[$colName] = isset($row[$i]) ? trim($row[$i]) : '';
                            }
                            $product_id = intval($assoc['product_id'] ?? ($assoc['product id'] ?? 0));
                            $delivered_qty = intval($assoc['delivered_qty'] ?? ($assoc['delivered qty'] ?? ($assoc['quantity'] ?? 0)));
                            $defective_qty = intval($assoc['defective_qty'] ?? ($assoc['defective qty'] ?? 0));
                            $supplier = trim($assoc['supplier'] ?? '');
                            $delivery_date = trim($assoc['delivery_date'] ?? ($assoc['delivery date'] ?? ''));
                            $received_by = trim($assoc['received_by'] ?? ($assoc['received by'] ?? ''));
                            $notes = trim($assoc['notes'] ?? '');
                        } else {
                            if (count($row) < 4) continue;
                            $product_id = intval($row[0]);
                            $delivered_qty = intval($row[1]);
                            $defective_qty = isset($row[5]) ? intval($row[5]) : 0;
                            $supplier = isset($row[2]) ? trim($row[2]) : '';
                            $delivery_date = isset($row[3]) ? trim($row[3]) : '';
                            $received_by = isset($row[4]) ? trim($row[4]) : '';
                            $notes = isset($row[6]) ? trim($row[6]) : '';
                        }
                        if ($delivery_date !== '' && is_numeric($delivery_date)) {
                            $delivery_date = date('Y-m-d', SpreadsheetDate::excelToTimestamp($delivery_date));
                        }
                        if ($delivery_date !== '') {
                            $dt = DateTime::createFromFormat('Y-m-d', $delivery_date);
                            if (!$dt || $dt->format('Y-m-d') !== $delivery_date) {
                                $delivery_date = '';
                            }
                        }
                        if ($product_id <= 0 || $delivered_qty <= 0 || $delivery_date === '') {
                            $error_count++;
                            continue;
                        }
                        // Calculate good_qty
                        $good_qty = $delivered_qty - $defective_qty;
                        if ($good_qty < 0) {
                            $good_qty = 0;
                        }
                        $result = record_delivery_transaction($conn, $branch_id, $product_id, $delivered_qty, $defective_qty, $good_qty, $supplier, $delivery_date, $received_by, $notes);
                        if ($result['ok']) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    }
                    $success = "Delivery import completed: $success_count saved, $error_count failed.";
                } catch (Exception $e) {
                    $errors[] = 'Unable to read the uploaded Excel file.';
                }
            } else {
                $errors[] = 'Invalid file type. Please upload an Excel or CSV file.';
            }
        } else {
            $errors[] = 'Please select a valid Excel file.';
        }
    } elseif (isset($_POST['edit_product'])) {
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($product_id <= 0) {
            $errors[] = 'Invalid product selected.';
        } else {
            $payload = build_product_payload($_POST, $unit_size_suffixes);
            $validationErrors = validate_product_payload($payload);
            $errors = array_merge($errors, $validationErrors);
            if (empty($validationErrors)) {
                persist_product($conn, $branch_id, $payload, $product_id);
                redirect_with_success($branch_id, 'Product updated!');
            }
        }
    } elseif (isset($_POST['delete_product'])) {
        $id = intval($_POST['delete_product']);
        
        // Start transaction to ensure all deletions succeed or fail together
        $conn->begin_transaction();
        try {
            // Temporarily disable foreign key checks to allow deletion
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            
            // First, delete from deliveries table (if it exists and has foreign key constraint)
            $deliveries_check = $conn->query("SHOW TABLES LIKE 'deliveries'");
            if ($deliveries_check->num_rows > 0) {
                $del_stmt = $conn->prepare("DELETE FROM deliveries WHERE product_id=?");
                $del_stmt->bind_param('i', $id);
                $del_stmt->execute();
                $del_stmt->close();
            }
            
            // Delete from delivery_summary table
            $del_summary_check = $conn->query("SHOW TABLES LIKE 'delivery_summary'");
            if ($del_summary_check->num_rows > 0) {
                $del_summary_stmt = $conn->prepare("DELETE FROM delivery_summary WHERE product_id=?");
                $del_summary_stmt->bind_param('i', $id);
                $del_summary_stmt->execute();
                $del_summary_stmt->close();
            }
            
            // Delete from sale_items if it exists
            $sale_items_check = $conn->query("SHOW TABLES LIKE 'sale_items'");
            if ($sale_items_check->num_rows > 0) {
                $sale_items_stmt = $conn->prepare("DELETE FROM sale_items WHERE product_id=?");
                $sale_items_stmt->bind_param('i', $id);
                $sale_items_stmt->execute();
                $sale_items_stmt->close();
            }
            
            // Delete from return_items if it exists
            $return_items_check = $conn->query("SHOW TABLES LIKE 'return_items'");
            if ($return_items_check->num_rows > 0) {
                $return_items_stmt = $conn->prepare("DELETE FROM return_items WHERE product_id=?");
                $return_items_stmt->bind_param('i', $id);
                $return_items_stmt->execute();
                $return_items_stmt->close();
            }
            
            // Now delete the product itself
            $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND branch_id=?");
            $stmt->bind_param('ii', $id, $branch_id);
            if ($stmt->execute()) {
                // Re-enable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $conn->commit();
                $stmt->close();
                redirect_with_success($branch_id, 'Product permanently deleted!');
            } else {
                throw new Exception('Error deleting product: ' . $stmt->error);
            }
        } catch (Exception $e) {
            // Re-enable foreign key checks even on error
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $conn->rollback();
            $errors[] = 'Error deleting product: ' . $e->getMessage();
        }
    } elseif (isset($_POST['bulk_delete_products'])) {
        // Bulk delete multiple products
        $product_ids = isset($_POST['selected_products']) ? $_POST['selected_products'] : [];
        
        if (empty($product_ids)) {
            $errors[] = 'No products selected for deletion.';
        } else {
            $deleted_count = 0;
            $failed_count = 0;
            
            foreach ($product_ids as $product_id) {
                $id = intval($product_id);
                if ($id <= 0) continue;
                
                // Start transaction for each product
                $conn->begin_transaction();
                try {
                    // Temporarily disable foreign key checks to allow deletion
                    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                    
                    // Delete from deliveries table
                    $deliveries_check = $conn->query("SHOW TABLES LIKE 'deliveries'");
                    if ($deliveries_check->num_rows > 0) {
                        $del_stmt = $conn->prepare("DELETE FROM deliveries WHERE product_id=?");
                        $del_stmt->bind_param('i', $id);
                        $del_stmt->execute();
                        $del_stmt->close();
                    }
                    
                    // Delete from delivery_summary table
                    $del_summary_check = $conn->query("SHOW TABLES LIKE 'delivery_summary'");
                    if ($del_summary_check->num_rows > 0) {
                        $del_summary_stmt = $conn->prepare("DELETE FROM delivery_summary WHERE product_id=?");
                        $del_summary_stmt->bind_param('i', $id);
                        $del_summary_stmt->execute();
                        $del_summary_stmt->close();
                    }
                    
                    // Delete from sale_items if it exists
                    $sale_items_check = $conn->query("SHOW TABLES LIKE 'sale_items'");
                    if ($sale_items_check->num_rows > 0) {
                        $sale_items_stmt = $conn->prepare("DELETE FROM sale_items WHERE product_id=?");
                        $sale_items_stmt->bind_param('i', $id);
                        $sale_items_stmt->execute();
                        $sale_items_stmt->close();
                    }
                    
                    // Delete from return_items if it exists
                    $return_items_check = $conn->query("SHOW TABLES LIKE 'return_items'");
                    if ($return_items_check->num_rows > 0) {
                        $return_items_stmt = $conn->prepare("DELETE FROM return_items WHERE product_id=?");
                        $return_items_stmt->bind_param('i', $id);
                        $return_items_stmt->execute();
                        $return_items_stmt->close();
                    }
                    
                    // Delete the product itself
                    $stmt = $conn->prepare("DELETE FROM products WHERE id=? AND branch_id=?");
                    $stmt->bind_param('ii', $id, $branch_id);
                    if ($stmt->execute()) {
                        // Re-enable foreign key checks
                        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                        $conn->commit();
                        $deleted_count++;
                    } else {
                        throw new Exception('Error deleting product: ' . $stmt->error);
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    // Re-enable foreign key checks even on error
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->rollback();
                    $failed_count++;
                }
            }
            
            if ($deleted_count > 0) {
                $message = "$deleted_count product(s) permanently deleted!";
                if ($failed_count > 0) {
                    $message .= " $failed_count failed.";
                }
                redirect_with_success($branch_id, $message);
            } else {
                $errors[] = 'Failed to delete selected products.';
            }
        }
    } elseif (isset($_POST['discontinue_product'])) {
        $id = intval($_POST['discontinue_product']);
        $stmt = $conn->prepare("UPDATE products SET product_status = 'discontinued' WHERE id=? AND branch_id=?");
        $stmt->bind_param('ii', $id, $branch_id);
        $stmt->execute();
        $stmt->close();
        redirect_with_success($branch_id, 'Product discontinued!');
    } elseif (isset($_POST['reactivate_product'])) {
        $id = intval($_POST['reactivate_product']);
        $stmt = $conn->prepare("UPDATE products SET product_status = 'active' WHERE id=? AND branch_id=?");
        $stmt->bind_param('ii', $id, $branch_id);
        $stmt->execute();
        $stmt->close();
        redirect_with_success($branch_id, 'Product reactivated!');
    } elseif (isset($_POST['delete_all_products'])) {
        // Delete ALL products from ALL branches (fresh start) - user wants complete deletion
        $conn->begin_transaction();
        try {
            // Temporarily disable foreign key checks to allow deletion
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            
            // Delete from return_items table (all products from all branches)
            $return_items_check = $conn->query("SHOW TABLES LIKE 'return_items'");
            if ($return_items_check->num_rows > 0) {
                $conn->query("DELETE FROM return_items");
            }
            
            // Delete from deliveries table (all products from all branches)
            $deliveries_check = $conn->query("SHOW TABLES LIKE 'deliveries'");
            if ($deliveries_check->num_rows > 0) {
                $conn->query("DELETE FROM deliveries");
            }
            
            // Delete from delivery_summary table
            $del_summary_check = $conn->query("SHOW TABLES LIKE 'delivery_summary'");
            if ($del_summary_check->num_rows > 0) {
                $conn->query("DELETE FROM delivery_summary");
            }
            
            // Delete from sale_items if it exists
            $sale_items_check = $conn->query("SHOW TABLES LIKE 'sale_items'");
            if ($sale_items_check->num_rows > 0) {
                $conn->query("DELETE FROM sale_items");
            }
            
            // Get count before deletion (all branches)
            $count_result = $conn->query("SELECT COUNT(*) as count FROM products");
            $count_row = $count_result->fetch_assoc();
            $deleted_count = $count_row['count'];
            
            // Finally, delete all products from ALL branches
            $delete_result = $conn->query("DELETE FROM products");
            
            if ($delete_result) {
                // Re-enable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $conn->commit();
                redirect_with_success($branch_id, "All products deleted! $deleted_count product(s) permanently removed from all branches.");
            } else {
                throw new Exception('Error deleting products: ' . $conn->error);
            }
        } catch (Exception $e) {
            // Re-enable foreign key checks even on error
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $conn->rollback();
            $errors[] = 'Error deleting all products: ' . $e->getMessage();
        }
    } elseif (isset($_POST['import_excel'])) {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
            $allowed = ['xlsx', 'xls', 'xlsm', 'csv'];
            $filename = $_FILES['excel_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                try {
                    $spreadsheet = IOFactory::load($_FILES['excel_file']['tmp_name']);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                    // Try to detect header row. If the first row contains 'name' and 'category', use header mapping.
                    $header = [];
                    if (!empty($rows) && is_array($rows[0])) {
                        $first = array_map(function($v){ return is_string($v) ? strtolower(trim($v)) : ''; }, $rows[0]);
                        if (in_array('name', $first) && in_array('category', $first)) {
                            $header = $first;
                            array_shift($rows); // remove header
                        }
                    }
                    
                    $success_count = 0;
                    $error_count = 0;
                    
                    foreach ($rows as $row) {
                        // If header mapping detected, map columns by header names; otherwise fall back to positional formats
                        if (!empty($header)) {
                            // build assoc by header
                            $assoc = [];
                            foreach ($header as $i => $colName) {
                                $assoc[$colName] = isset($row[$i]) ? trim($row[$i]) : '';
                            }
                            $name = $assoc['name'] ?? '';
                            $category = $assoc['category'] ?? '';
                            // optional fields
                            $description = $assoc['description'] ?? '';
                            $quantity = isset($assoc['quantity']) ? intval($assoc['quantity']) : 0;
                            $reorder_level = isset($assoc['reorder level']) ? intval($assoc['reorder level']) : (isset($assoc['reorder_level']) ? intval($assoc['reorder_level']) : 0);
                            $unit = $assoc['unit'] ?? '';
                            $size = $assoc['size'] ?? '';
                            $brand = $assoc['brand'] ?? '';
                            $model_compat = $assoc['model'] ?? '';
                            $supplier = $assoc['supplier'] ?? '';

                            // Price detection: prefer explicit numeric column names
                            $price = 0.0;
                            $priceCandidates = ['pricenumeric','price_numeric','price numeric','price_raw','price_numeric','price_numeric'];
                            $found = false;
                            foreach ($priceCandidates as $c) {
                                if (array_key_exists($c, $assoc) && $assoc[$c] !== '') {
                                    $price = parse_price($assoc[$c]);
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                // fallback to 'price' or 'pricedisplay'
                                $praw = $assoc['price'] ?? ($assoc['pricedisplay'] ?? ($assoc['price_display'] ?? ''));
                                $price = parse_price($praw);
                            }

                        } else {
                            // Support two Excel formats (positional) as before
                            if (count($row) >= 11) {
                                $name = trim($row[0]);
                                $category = trim($row[1]);
                                $description = trim($row[2]);
                                $price = floatval($row[3]);
                                $quantity = intval($row[4]);
                                $reorder_level = intval($row[5]);
                                $unit = trim($row[6]);
                                $size = trim($row[7]);
                                $brand = trim($row[8]);
                                $model_compat = trim($row[9]);
                                $supplier = trim($row[10]);
                            } elseif (count($row) >= 10) {
                                // Old/new format without description
                                $name = trim($row[0]);
                                $category = trim($row[1]);
                                $description = '';
                                $price = floatval($row[2]);
                                $quantity = intval($row[3]);
                                $reorder_level = intval($row[4]);
                                $unit = trim($row[5]);
                                $size = trim($row[6]);
                                $brand = trim($row[7]);
                                $model_compat = trim($row[8]);
                                $supplier = trim($row[9]);
                            } else {
                                // Not enough columns, skip
                                continue;
                            }
                        }

                        // If unit requires numeric prefix (ml/L/g) and a numeric size is provided, combine (e.g. '500ml')
                        $unit = combine_unit_with_size_if_applicable($unit, $size);

                        // Validate data
                        $valid = !empty($name) && !empty($category) && $price >= 0 && $quantity >= 0 && $reorder_level >= 0;
                                // If category is Tires, validate size format
                                $tire_size_pattern = '/^(?:\d{2,3}\/\d{2,3}[A-Za-z]*-?\d{2,3}|\d+(?:\.\d+)?-\d{2,3}|\d{1,3}(?:x\d+(?:\.\d+)?)?)$/';
                                if ($valid && strtolower($category) === 'tires') {
                                    if (empty($size) || !preg_match($tire_size_pattern, $size)) {
                                        $valid = false;
                                    }
                                }

                                if ($valid) {
                                    $status = compute_status($quantity, $reorder_level);

                                    $stmt = $conn->prepare("INSERT INTO products (branch_id, name, category, description, price, quantity, reorder_level, unit, size, brand, model, supplier, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param('isssdiissssss', $branch_id, $name, $category, $description, $price, $quantity, $reorder_level, $unit, $size, $brand, $model_compat, $supplier, $status);

                                    if ($stmt->execute()) {
                                        $success_count++;
                                    } else {
                                        $error_count++;
                                    }
                                    $stmt->close();
                                } else {
                                    $error_count++;
                                }
                            }

                    // end foreach
                    $success = "Import completed: $success_count products imported successfully, $error_count failed.";
                } catch (Exception $e) {
                    $errors[] = "Error processing Excel file: " . $e->getMessage();
                }
            } else {
                $errors[] = "Invalid file format. Please upload an Excel file (.xlsx or .xls)";
            }
        } else {
            $errors[] = "Please select a file to import";
        }
    }
}

// ====================================================================
// AUTO-CREATE/MIGRATE TABLES IF THEY DON'T EXIST
// ====================================================================
$table_creation_errors = [];

// Check if 'category' table exists (old structure) or 'categories' (new structure)
$category_table_check = $conn->query("SHOW TABLES LIKE 'category'");
$categories_table_check = $conn->query("SHOW TABLES LIKE 'categories'");
$use_categories_table = false;

if ($categories_table_check->num_rows > 0) {
    // New 'categories' table exists
    $use_categories_table = true;
} elseif ($category_table_check->num_rows > 0) {
    // Old 'category' table exists - check if it has the new structure
    $columns_check = $conn->query("SHOW COLUMNS FROM category LIKE 'status'");
    if ($columns_check->num_rows == 0) {
        // Old structure - migrate to new 'categories' table
        $create_categories = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_category_name (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($create_categories)) {
            // Migrate data from old table (handle collation mismatch)
            $migrate_res = $conn->query("INSERT INTO categories (category_name, created_at) 
                                        SELECT name COLLATE utf8mb4_unicode_ci, created_at FROM category 
                                        WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name COLLATE utf8mb4_unicode_ci = category.name COLLATE utf8mb4_unicode_ci)");
            $use_categories_table = true;
        } else {
            $table_creation_errors[] = "Error creating categories table: " . $conn->error;
        }
    } else {
        // Old table has status column, rename it
        $conn->query("RENAME TABLE category TO categories");
        $use_categories_table = true;
    }
} else {
    // Neither exists - create new 'categories' table
    $create_categories = "CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_category_name (category_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_categories)) {
        $use_categories_table = true;
    } else {
        $table_creation_errors[] = "Error creating categories table: " . $conn->error;
    }
}

// Check and create brands table
$table_check = $conn->query("SHOW TABLES LIKE 'brands'");
if ($table_check->num_rows == 0) {
    $create_brands = "CREATE TABLE IF NOT EXISTS brands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        brand_name VARCHAR(255) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_brand_name (brand_name),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($create_brands)) {
        $table_creation_errors[] = "Error creating brands table: " . $conn->error;
    }
}

// Check and create models table
$table_check = $conn->query("SHOW TABLES LIKE 'models'");
if ($table_check->num_rows == 0) {
    $create_models = "CREATE TABLE IF NOT EXISTS models (
        id INT AUTO_INCREMENT PRIMARY KEY,
        model_name VARCHAR(255) NOT NULL,
        brand_id INT NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_brand_id (brand_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$conn->query($create_models)) {
        $table_creation_errors[] = "Error creating models table: " . $conn->error;
    }
}

// Check and create suppliers table
$table_check = $conn->query("SHOW TABLES LIKE 'suppliers'");
if ($table_check->num_rows == 0) {
    $create_suppliers = "CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255) NULL,
        contact_phone VARCHAR(50) NULL,
        contact_email VARCHAR(255) NULL,
        address TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_supplier_name (supplier_name),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_suppliers)) {
        // Migrate existing supplier names from products table (handle collation mismatch)
        $conn->query("INSERT INTO suppliers (supplier_name, status, created_at)
                     SELECT DISTINCT supplier COLLATE utf8mb4_unicode_ci, 'active', NOW()
                     FROM products
                     WHERE supplier IS NOT NULL 
                       AND supplier != ''
                       AND NOT EXISTS (
                           SELECT 1 FROM suppliers s WHERE s.supplier_name COLLATE utf8mb4_unicode_ci = products.supplier COLLATE utf8mb4_unicode_ci
                       )");
    } else {
        $table_creation_errors[] = "Error creating suppliers table: " . $conn->error;
    }
}

// Check and create tire_sizes table
$table_check = $conn->query("SHOW TABLES LIKE 'tire_sizes'");
if ($table_check->num_rows == 0) {
    $create_tire_sizes = "CREATE TABLE IF NOT EXISTS tire_sizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        size_value VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_size_value (size_value),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_tire_sizes)) {
        // Migrate existing tire sizes from products table (where category is Tires)
        $tire_cat_check = $conn->query("SHOW TABLES LIKE 'categories'");
        if ($tire_cat_check->num_rows > 0) {
            $conn->query("INSERT INTO tire_sizes (size_value, status, created_at)
                         SELECT DISTINCT size COLLATE utf8mb4_unicode_ci, 'active', NOW()
                         FROM products
                         WHERE size IS NOT NULL 
                           AND size != ''
                           AND (
                               LOWER(category) = 'tires' 
                               OR category_id IN (SELECT id FROM categories WHERE LOWER(category_name) = 'tires')
                           )
                           AND NOT EXISTS (
                               SELECT 1 FROM tire_sizes ts WHERE ts.size_value COLLATE utf8mb4_unicode_ci = products.size COLLATE utf8mb4_unicode_ci
                           )");
        } else {
            $conn->query("INSERT INTO tire_sizes (size_value, status, created_at)
                         SELECT DISTINCT size COLLATE utf8mb4_unicode_ci, 'active', NOW()
                         FROM products
                         WHERE size IS NOT NULL 
                           AND size != ''
                           AND LOWER(category) = 'tires'
                           AND NOT EXISTS (
                               SELECT 1 FROM tire_sizes ts WHERE ts.size_value COLLATE utf8mb4_unicode_ci = products.size COLLATE utf8mb4_unicode_ci
                           )");
        }
    } else {
        $table_creation_errors[] = "Error creating tire_sizes table: " . $conn->error;
    }
}

// Check and update products table structure
$columns_check = $conn->query("SHOW COLUMNS FROM products LIKE 'product_code'");
if ($columns_check->num_rows == 0) {
    // Add product_code column
    $conn->query("ALTER TABLE products ADD COLUMN product_code VARCHAR(255) NULL AFTER id");
    $conn->query("ALTER TABLE products ADD INDEX idx_product_code (product_code)");
}

$columns_check = $conn->query("SHOW COLUMNS FROM products LIKE 'category_id'");
if ($columns_check->num_rows == 0) {
    // Add foreign key columns
    $conn->query("ALTER TABLE products ADD COLUMN category_id INT NULL AFTER product_code");
    $conn->query("ALTER TABLE products ADD COLUMN brand_id INT NULL AFTER category_id");
    $conn->query("ALTER TABLE products ADD COLUMN model_id INT NULL AFTER brand_id");
    $conn->query("ALTER TABLE products ADD INDEX idx_category_id (category_id)");
    $conn->query("ALTER TABLE products ADD INDEX idx_brand_id (brand_id)");
    $conn->query("ALTER TABLE products ADD INDEX idx_model_id (model_id)");
}

$columns_check = $conn->query("SHOW COLUMNS FROM products LIKE 'product_status'");
if ($columns_check->num_rows == 0) {
    // Add product_status column
    $conn->query("ALTER TABLE products ADD COLUMN product_status ENUM('active', 'discontinued') DEFAULT 'active' AFTER status");
    $conn->query("ALTER TABLE products ADD INDEX idx_product_status (product_status)");
}

// Add supplier_id and tire_size_id columns if they don't exist
$columns_check = $conn->query("SHOW COLUMNS FROM products LIKE 'supplier_id'");
if ($columns_check->num_rows == 0) {
    $conn->query("ALTER TABLE products ADD COLUMN supplier_id INT NULL AFTER supplier");
    $conn->query("ALTER TABLE products ADD INDEX idx_supplier_id (supplier_id)");
    
    // Migrate existing supplier data to supplier_id (handle collation mismatch)
    $suppliers_table_check = $conn->query("SHOW TABLES LIKE 'suppliers'");
    if ($suppliers_table_check->num_rows > 0) {
        $conn->query("UPDATE products p
                     INNER JOIN suppliers s ON p.supplier COLLATE utf8mb4_unicode_ci = s.supplier_name COLLATE utf8mb4_unicode_ci
                     SET p.supplier_id = s.id
                     WHERE p.supplier_id IS NULL AND p.supplier IS NOT NULL AND p.supplier != ''");
    }
}

$columns_check = $conn->query("SHOW COLUMNS FROM products LIKE 'tire_size_id'");
if ($columns_check->num_rows == 0) {
    $conn->query("ALTER TABLE products ADD COLUMN tire_size_id INT NULL AFTER size");
    $conn->query("ALTER TABLE products ADD INDEX idx_tire_size_id (tire_size_id)");
    
    // Migrate existing tire size data to tire_size_id
    $tire_sizes_table_check = $conn->query("SHOW TABLES LIKE 'tire_sizes'");
    if ($tire_sizes_table_check->num_rows > 0) {
        $tire_cat_check = $conn->query("SHOW TABLES LIKE 'categories'");
        if ($tire_cat_check->num_rows > 0) {
            $conn->query("UPDATE products p
                         INNER JOIN tire_sizes ts ON p.size COLLATE utf8mb4_unicode_ci = ts.size_value COLLATE utf8mb4_unicode_ci
                         SET p.tire_size_id = ts.id
                         WHERE p.tire_size_id IS NULL 
                           AND p.size IS NOT NULL 
                           AND p.size != ''
                           AND (
                               LOWER(p.category) = 'tires' 
                               OR p.category_id IN (SELECT id FROM categories WHERE LOWER(category_name) = 'tires')
                           )");
        } else {
            $conn->query("UPDATE products p
                         INNER JOIN tire_sizes ts ON p.size COLLATE utf8mb4_unicode_ci = ts.size_value COLLATE utf8mb4_unicode_ci
                         SET p.tire_size_id = ts.id
                         WHERE p.tire_size_id IS NULL 
                           AND p.size IS NOT NULL 
                           AND p.size != ''
                           AND LOWER(p.category) = 'tires'");
        }
    }
}

// Check if viewing discontinued products
$view_discontinued = isset($_GET['status']) && $_GET['status'] === 'discontinued';
$product_status_filter = $view_discontinued ? 'discontinued' : 'active';

// Filtering and Sorting
// Use COALESCE to handle cases where product_status might not exist yet
$where = "WHERE p.branch_id=$branch_id";
// Check if product_status column exists before using it
$status_col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'product_status'");
if ($status_col_check->num_rows > 0) {
    $where .= " AND p.product_status='$product_status_filter'";
}

if (!empty($_GET['search'])) {
    $search = '%' . $conn->real_escape_string($_GET['search']) . '%';
    $where .= " AND (
        p.name COLLATE utf8mb4_unicode_ci LIKE '$search' COLLATE utf8mb4_unicode_ci OR 
        p.product_code COLLATE utf8mb4_unicode_ci LIKE '$search' COLLATE utf8mb4_unicode_ci OR 
        COALESCE(c.category_name, p.category) COLLATE utf8mb4_unicode_ci LIKE '$search' COLLATE utf8mb4_unicode_ci OR 
        COALESCE(b.brand_name, p.brand) COLLATE utf8mb4_unicode_ci LIKE '$search' COLLATE utf8mb4_unicode_ci OR 
        COALESCE(m.model_name, p.model) COLLATE utf8mb4_unicode_ci LIKE '$search' COLLATE utf8mb4_unicode_ci
    )";
}
if (!empty($_GET['category'])) {
    $cat = intval($_GET['category']);
    // Check if category_id column exists
    $cat_col_check = $conn->query("SHOW COLUMNS FROM products LIKE 'category_id'");
    if ($cat_col_check->num_rows > 0) {
        $where .= " AND p.category_id=$cat";
    } else {
        // Fallback to old category field
        $cat_name = $conn->real_escape_string($_GET['category']);
        $where .= " AND p.category='$cat_name'";
    }
}
$order = 'ORDER BY COALESCE(c.category_name, p.category) ASC, p.name ASC';
if (!empty($_GET['sort'])) {
    if ($_GET['sort'] === 'stock_asc') $order = 'ORDER BY COALESCE(c.category_name, p.category) ASC, p.quantity ASC, p.name ASC';
    if ($_GET['sort'] === 'stock_desc') $order = 'ORDER BY COALESCE(c.category_name, p.category) ASC, p.quantity DESC, p.name ASC';
}

// Fetch active categories for filter dropdown
$categories = [];
// Always check for categories table first (new system)
$categories_table_check = $conn->query("SHOW TABLES LIKE 'categories'");
if ($categories_table_check->num_rows > 0) {
    // Use new categories table (status column removed)
    $cat_res = $conn->query("SELECT id, category_name FROM categories ORDER BY category_name ASC");
    if ($cat_res) {
        while ($row = $cat_res->fetch_assoc()) {
            $categories[$row['id']] = $row['category_name'];
        }
    }
    $use_categories_table = true; // Ensure this is set
} else {
    // Check if old category table exists
    $old_cat_check = $conn->query("SHOW TABLES LIKE 'category'");
    if ($old_cat_check->num_rows > 0) {
        // Create categories table and migrate
        $create_categories = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_category_name (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($create_categories)) {
            // Migrate data from old table
            $conn->query("INSERT INTO categories (category_name, created_at) 
                         SELECT name COLLATE utf8mb4_unicode_ci, created_at FROM category 
                         WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.category_name COLLATE utf8mb4_unicode_ci = category.name COLLATE utf8mb4_unicode_ci)");
            
            // Now fetch from new table
            $cat_res = $conn->query("SELECT id, category_name FROM categories ORDER BY category_name ASC");
            if ($cat_res) {
                while ($row = $cat_res->fetch_assoc()) {
                    $categories[$row['id']] = $row['category_name'];
                }
            }
            $use_categories_table = true;
        } else {
            // Fallback to old table
            $cat_res = $conn->query("SELECT id, name as category_name FROM category ORDER BY name ASC");
            if ($cat_res) {
                while ($row = $cat_res->fetch_assoc()) {
                    $categories[$row['id']] = $row['category_name'];
                }
            }
        }
    } else {
        // Last fallback: use category field from products
        $cat_res = $conn->query("SELECT DISTINCT category FROM products WHERE branch_id=$branch_id AND category IS NOT NULL AND category != '' ORDER BY category ASC");
        if ($cat_res) {
            $cat_id = 1;
            while ($row = $cat_res->fetch_assoc()) {
                $categories[$cat_id++] = $row['category'];
            }
        }
    }
}

// Fetch active brands for dropdowns
$brands = [];
$brand_table_check = $conn->query("SHOW TABLES LIKE 'brands'");
if ($brand_table_check->num_rows > 0) {
    $brands_res = $conn->query("SELECT id, brand_name FROM brands WHERE status='active' ORDER BY brand_name ASC");
    if ($brands_res) {
        while ($row = $brands_res->fetch_assoc()) {
            $brands[$row['id']] = $row['brand_name'];
        }
    }
}

// Fetch active models for dropdowns (with brand names)
$models = [];
$model_table_check = $conn->query("SHOW TABLES LIKE 'models'");
if ($model_table_check->num_rows > 0) {
    // Join with brands table to get brand name
    $brand_table_check = $conn->query("SHOW TABLES LIKE 'brands'");
    if ($brand_table_check->num_rows > 0) {
        $models_res = $conn->query("SELECT m.id, m.model_name, m.brand_id, b.brand_name 
                                     FROM models m 
                                     LEFT JOIN brands b ON m.brand_id = b.id 
                                     WHERE m.status='active' 
                                     ORDER BY b.brand_name ASC, m.model_name ASC");
    } else {
        // Fallback if brands table doesn't exist
        $models_res = $conn->query("SELECT id, model_name, brand_id FROM models WHERE status='active' ORDER BY model_name ASC");
    }
    
    if ($models_res) {
        while ($row = $models_res->fetch_assoc()) {
            $brand_name = isset($row['brand_name']) ? $row['brand_name'] : '';
            $display_name = $brand_name ? $brand_name . ' - ' . $row['model_name'] : $row['model_name'];
            $models[$row['id']] = [
                'name' => $row['model_name'], 
                'brand_id' => $row['brand_id'],
                'brand_name' => $brand_name,
                'display_name' => $display_name
            ];
        }
    }
}

// Fetch active suppliers for dropdowns
$suppliers = [];
$suppliers_table_check = $conn->query("SHOW TABLES LIKE 'suppliers'");
if ($suppliers_table_check->num_rows > 0) {
    $suppliers_res = $conn->query("SELECT id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name ASC");
    if ($suppliers_res) {
        while ($row = $suppliers_res->fetch_assoc()) {
            $suppliers[$row['id']] = $row['supplier_name'];
        }
    }
} else {
    // Fallback: fetch from products table
    $suppliers_res = $conn->query("SELECT DISTINCT supplier FROM products WHERE branch_id = $branch_id AND supplier IS NOT NULL AND supplier != '' ORDER BY supplier ASC");
    if ($suppliers_res) {
        while ($row = $suppliers_res->fetch_assoc()) {
            $suppliers[] = $row['supplier'];
        }
    }
}

// Fetch tire sizes for dropdowns (status column removed)
$tire_sizes = [];
$tire_sizes_table_check = $conn->query("SHOW TABLES LIKE 'tire_sizes'");
if ($tire_sizes_table_check->num_rows > 0) {
    $tire_sizes_res = $conn->query("SELECT id, size_value FROM tire_sizes ORDER BY size_value ASC");
    if ($tire_sizes_res) {
        while ($row = $tire_sizes_res->fetch_assoc()) {
            $tire_sizes[$row['id']] = $row['size_value'];
        }
    }
} else {
    // Fallback: fetch from products table
    $tire_sizes_res = $conn->query("SELECT DISTINCT size FROM products WHERE branch_id = $branch_id AND size IS NOT NULL AND size != '' AND (LOWER(category) = 'tires' OR LOWER(category_name) = 'tires' OR category_id IN (SELECT id FROM categories WHERE LOWER(category_name) = 'tires')) ORDER BY size ASC");
    if ($tire_sizes_res) {
        while ($row = $tire_sizes_res->fetch_assoc()) {
            $tire_sizes[] = $row['size'];
        }
    }
}

// Fetch products with joins (handle cases where new tables/columns don't exist)
$products = [];
$brand_table_exists = $conn->query("SHOW TABLES LIKE 'brands'")->num_rows > 0;
$model_table_exists = $conn->query("SHOW TABLES LIKE 'models'")->num_rows > 0;
$cat_id_exists = $conn->query("SHOW COLUMNS FROM products LIKE 'category_id'")->num_rows > 0;

if ($use_categories_table && $cat_id_exists) {
    // Use new structure with joins
    $query = "SELECT p.*, 
                    c.category_name, 
                    b.brand_name, 
                    m.model_name 
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             LEFT JOIN brands b ON p.brand_id = b.id
             LEFT JOIN models m ON p.model_id = m.id
             $where $order";
} elseif ($use_categories_table) {
    // Categories table exists but products doesn't have category_id yet
    $query = "SELECT p.*, 
                    p.category as category_name,
                    COALESCE(b.brand_name, p.brand) as brand_name,
                    COALESCE(m.model_name, p.model) as model_name
             FROM products p
             LEFT JOIN brands b ON p.brand_id = b.id
             LEFT JOIN models m ON p.model_id = m.id
             $where $order";
} else {
    // Fallback to old structure
    $query = "SELECT p.*, 
                    p.category as category_name,
                    p.brand as brand_name,
                    p.model as model_name
             FROM products p
             $where $order";
}

$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $products[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Inventory Management</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    
    <!-- Critical CSS for layout stability -->
    <style>
        body { background: #f8fafc !important; margin: 0 !important; padding: 0 !important; }
        .sidebar { background: linear-gradient(180deg, #1a1d23 0%, #23272b 100%) !important; width: 250px !important; height: 100vh !important; position: fixed !important; top: 0 !important; left: 0 !important; z-index: 1050 !important; }
        .container-fluid { margin-left: 250px !important; }
        .show-reorder-toggle .form-check-label {
            font-weight: 500;
            color: #1f2937;
        }
        .show-reorder-toggle .form-check-input:focus {
            box-shadow: 0 0 0 0.15rem rgba(13,110,253,0.3);
        }
        body.reorder-hidden .reorder-col,
        body.reorder-hidden th.reorder-col,
        body.reorder-hidden td.reorder-col {
            display: none !important;
        }
        .status-cell {
            position: relative;
            display: block;
            padding-left: 1.75rem;
            font-weight: 600;
            font-size: 0.92rem;
            letter-spacing: 0.01em;
            color: #0f172a;
            min-height: 1.5rem;
        }
        .status-cell .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            position: absolute;
            top: 0;
            left: 0;
            border: 2px solid #fff;
            box-shadow: 0 0 0 3px rgba(15,23,42,0.12);
        }
        .status-cell.status-in-stock {
            color: #047857;
        }
        .status-cell.status-in-stock .status-indicator {
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.25);
        }
        .status-cell.status-low-stock {
            color: #b45309;
        }
        .status-cell.status-low-stock .status-indicator {
            background: #fb923c;
            box-shadow: 0 0 0 3px rgba(251,146,60,0.25);
        }
        .status-cell.status-out-stock {
            color: #b91c1c;
        }
        .status-cell.status-out-stock .status-indicator {
            background: #ef4444;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.22);
        }
    </style>
</head>
<body style="margin: 0 !important; padding: 0 !important;">
<?php include '../../includes/admin_sidebar.php'; ?>

<!-- Toast Container for Notifications -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1055;">
</div>

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
    <!-- Branch Selection Dropdown -->
    <form method="get" class="mb-3" style="max-width:300px;">
      <label for="branch_id" class="form-label fw-bold">Select Branch:</label>
      <select name="branch_id" id="branch_id" class="form-select" onchange="this.form.submit()">
        <?php foreach ($branches as $id => $name): ?>
          <option value="<?= $id ?>" <?= $id == $branch_id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    
    <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;"><i class="bi bi-box-seam me-2"></i>Inventory <?= htmlspecialchars($branches[$branch_id]) ?></h2>
    
    <!-- Product Status Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item" role="presentation">
        <a class="nav-link <?= !$view_discontinued ? 'active' : '' ?>" href="?branch_id=<?= $branch_id ?>&status=active">
          <i class="bi bi-check-circle me-1"></i>Active Products
        </a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link <?= $view_discontinued ? 'active' : '' ?>" href="?branch_id=<?= $branch_id ?>&status=discontinued">
          <i class="bi bi-x-circle me-1"></i>Discontinued Products
        </a>
      </li>
    </ul>
    
    <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
      <?php if ($success): ?>
      <div class="alert alert-success alert-dismissible fade show position-relative" role="alert" style="padding-right: 3rem;">
        <?= $success ?>
        <button type="button" class="btn btn-sm p-0 border-0 bg-transparent position-absolute top-50 end-0 translate-middle-y me-2" data-bs-dismiss="alert" aria-label="Close" title="Close">
            <i class="bi bi-x-circle-fill" style="font-size:1.1rem; color:#64748b;"></i>
        </button>
      </div>
      <?php endif; ?>
      <?php if ($errors): ?>
      <div class="alert alert-danger alert-dismissible fade show position-relative" role="alert" style="padding-right: 3rem;">
        <?= implode('<br>', $errors) ?>
        <button type="button" class="btn btn-sm p-0 border-0 bg-transparent position-absolute top-50 end-0 translate-middle-y me-2" data-bs-dismiss="alert" aria-label="Close" title="Close">
            <i class="bi bi-x-circle-fill" style="font-size:1.1rem; color:#64748b;"></i>
        </button>
      </div>
      <?php endif; ?>
      <form class="row g-2 mb-3" method="get" id="filterForm" action="" onsubmit="var s=document.getElementById('liveSearchInput');var h=document.getElementById('searchHidden');if(s&&h){h.value=s.value.trim();}return true;">
          <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
          <div class="col-md-3" style="position: relative;">
              <input type="text" id="liveSearchInput" class="form-control" placeholder="Search: name, code, category, brand, model..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" autocomplete="off">
              <input type="hidden" name="search" id="searchHidden" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
              <div id="searchSuggestions" style="position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 1050; max-height: 300px; overflow-y: auto; display: none; box-shadow: 0 4px 12px rgba(0,0,0,0.3); border-radius: 8px; background: #2d3748; padding: 8px 0;"></div>
          </div>
          
          <!-- Pass categories from PHP to JavaScript -->
          <script>
          var phpCategories = <?php echo json_encode(array_values($categories)); ?>;
          </script>
          <div class="col-md-3">
              <select name="category" class="form-select">
                  <option value="">All Categories</option>
                  <?php 
                  foreach ($categories as $cat_id => $cat_name): ?>
                      <option value="<?= $cat_id ?>" <?= (isset($_GET['category']) && intval($_GET['category']) === $cat_id) ? 'selected' : '' ?>><?= htmlspecialchars($cat_name) ?></option>
                  <?php endforeach; ?>
              </select>
          </div>
          <div class="col-md-3">
              <select name="sort" class="form-select">
                  <option value="">Sort by Stock</option>
                  <option value="stock_asc" <?= (($_GET['sort'] ?? '') === 'stock_asc') ? 'selected' : '' ?>>Low to High</option>
                  <option value="stock_desc" <?= (($_GET['sort'] ?? '') === 'stock_desc') ? 'selected' : '' ?>>High to Low</option>
              </select>
          </div>
          <div class="col-md-3">
              <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                <!-- Manage Options Dropdown -->
                <div class="dropdown w-100 mt-2">
                    <button class="btn btn-secondary w-100 dropdown-toggle" type="button" id="manageDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-tags"></i> Manage Options
                    </button>
                    <ul class="dropdown-menu w-100" aria-labelledby="manageDropdown">
                        <li><a class="dropdown-item" href="manage_categories.php"><i class="bi bi-tags"></i> Manage Categories</a></li>
                        <li><a class="dropdown-item" href="manage_brands.php"><i class="bi bi-bookmark"></i> Manage Brands</a></li>
                        <li><a class="dropdown-item" href="manage_models.php"><i class="bi bi-box"></i> Manage Models</a></li>
                        <li><a class="dropdown-item" href="manage_tire_sizes.php"><i class="bi bi-rulers"></i> Manage Tire Sizes</a></li>
                    </ul>
                </div>
          </div>
      </form>
    <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-circle"></i> Add Product</button>
    <button class="btn btn-warning mb-3 ms-2" data-bs-toggle="modal" data-bs-target="#addDeliveryModal"><i class="bi bi-truck"></i> Add Delivery Record</button>
    <a href="delivery_summary.php" class="btn btn-info mb-3 ms-2"><i class="bi bi-clipboard-data"></i> Delivery Summary</a>
    <div class="d-flex justify-content-end align-items-center mb-3">
        <div class="form-check form-switch show-reorder-toggle" title="Toggle to show or hide the Reorder Level column">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleReorderLevel">
            <label class="form-check-label" for="toggleReorderLevel">
                <i class="bi bi-exclamation-triangle me-1"></i>Show Reorder Level
            </label>
        </div>
    </div>
      <?php if ($view_discontinued && !empty($products)): ?>
      <div class="mb-3">
        <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('⚠️ WARNING: This will PERMANENTLY DELETE all selected products and their delivery records from the database. This action cannot be undone!\n\nAre you absolutely sure?');">
          <button type="submit" name="bulk_delete_products" class="btn btn-danger">
            <i class="bi bi-trash me-1"></i>Delete Selected Products
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllProducts()">
            <i class="bi bi-check-square me-1"></i>Select All
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllProducts()">
            <i class="bi bi-square me-1"></i>Deselect All
          </button>
          <span class="ms-2 text-muted" id="selectedCount">0 selected</span>
        </form>
      </div>
      <?php endif; ?>
      
      <!-- Delete All Products Button (for fresh start) -->
      <div class="mb-3">
        <div class="alert alert-danger d-inline-block mb-0">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          <strong>Fresh Start:</strong> Delete all products from ALL branches
          <form method="POST" class="d-inline ms-2" onsubmit="var confirmText = prompt('⚠️⚠️⚠️ CRITICAL WARNING ⚠️⚠️⚠️\\n\\nThis will PERMANENTLY DELETE ALL PRODUCTS from ALL BRANCHES and ALL related records (deliveries, delivery_summary, sale_items, return_items) from the database.\\n\\nThis action CANNOT be undone!\\n\\nType \"DELETE ALL\" to confirm:'); return confirmText === 'DELETE ALL';">
            <button type="submit" name="delete_all_products" class="btn btn-danger btn-sm">
              <i class="bi bi-trash-fill me-1"></i>Delete All Products (All Branches)
            </button>
          </form>
        </div>
      </div>
      
      <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
              <thead class="table-dark">
                  <tr>
                      <?php if ($view_discontinued): ?>
                      <th style="width: 50px;">
                        <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllProducts(this)">
                      </th>
                      <?php endif; ?>
                      <th>Code</th>
                      <th>Name</th>
                      <th>Category</th>
                      <th>Brand</th>
                      <th>Model</th>
                      <th>Supplier</th>
                      <!-- Size column removed; tire sizes will be shown in the Unit column -->
                      <th class="unit-col text-center">Unit</th>
                      <th class="text-center">Quantity</th>
                      <th class="text-center reorder-col">Reorder Level</th>
                      <th>Status</th>
                      <th>Price</th>
                      <th>Actions</th>
                  </tr>
              </thead>
              <tbody>
              <?php foreach ($products as $index => $prod): ?>
                  <tr>
                      <?php if ($view_discontinued): ?>
                      <td>
                        <input type="checkbox" 
                               class="product-checkbox" 
                               name="selected_products[]" 
                               value="<?= $prod['id'] ?>"
                               onchange="updateSelectedCount()">
                      </td>
                      <?php endif; ?>
                      <td><?= htmlspecialchars($prod['product_code'] ?? '') ?></td>
                      <td><?= htmlspecialchars($prod['name']) ?></td>
                      <td><?= htmlspecialchars($prod['category_name'] ?? $prod['category'] ?? '') ?></td>
                      <td><?= htmlspecialchars($prod['brand_name'] ?? $prod['brand'] ?? '') ?></td>
                      <td><?= htmlspecialchars($prod['model_name'] ?? $prod['model'] ?? '') ?></td>
                      <td><?= htmlspecialchars($prod['supplier'] ?? '') ?></td>
                      <!-- Size cell removed (moved to Unit column for Tires) -->
                      <td class="unit-col text-center">
                        <?php
                        // If this product is a Tire, prefer showing the tire size in the Unit column
                        $display_unit = '';
                        $categoryLower = strtolower($prod['category'] ?? '');
                        if ($categoryLower === 'tires' && !empty($prod['size'])) {
                            $display_unit = $prod['size'];
                        } else {
                            $unitVal = $prod['unit'] ?? '';
                            if (!empty($unitVal)) {
                                $hasCombinedUnit = false;
                                foreach ($unit_size_suffixes as $suffix) {
                                    if (str_ends_with($unitVal, $suffix)) {
                                        $hasCombinedUnit = true;
                                        break;
                                    }
                                }
                                if ($hasCombinedUnit) {
                                    $display_unit = $unitVal;
                                } elseif (in_array($unitVal, $unit_size_suffixes, true)) {
                                    // If unit is just the suffix (ml/L/g) and size exists, combine
                                    if (!empty($prod['size']) && preg_match('/^\d+(?:\.\d+)?$/', $prod['size'])) {
                                        $display_unit = $prod['size'] . $unitVal;
                                    } else {
                                        $display_unit = $unitVal;
                                    }
                                } else {
                                    $display_unit = $unitVal;
                                }
                            } else {
                                // If unit empty but size contains numeric and unit was left out, show size
                                if (!empty($prod['size']) && preg_match('/^\d+(?:\.\d+)?$/', $prod['size'])) {
                                    $display_unit = $prod['size'];
                                }
                            }
                        }
                        echo htmlspecialchars($display_unit);
                        ?>
                      </td>
                      <td class="text-center"><?= $prod['quantity'] ?></td>
                      <td class="text-center reorder-col"><?= $prod['reorder_level'] ?></td>
                      <td>
                        <?php
                        $status = compute_status($prod['quantity'], $prod['reorder_level']);
                        $status_class_map = [
                            'In Stock' => 'status-cell status-in-stock',
                            'Low Stock' => 'status-cell status-low-stock',
                            'Out of Stock' => 'status-cell status-out-stock'
                        ];
                        $status_class = $status_class_map[$status] ?? 'status-cell';
                        ?>
                        <div class="<?= $status_class ?>">
                            <?= htmlspecialchars($status) ?>
                            <span class="status-indicator"></span>
                        </div>
                      </td>
                      <td><?= '₱' . number_format($prod['price'], 2) ?></td>
                      <td>
                          <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $prod['id'] ?>"><i class="bi bi-pencil"></i> Edit</button>
                          <?php if (!$view_discontinued): ?>
                          <form method="post" style="display:inline-block" onsubmit="return confirm('Discontinue this product? It will be hidden from active inventory.');">
                              <input type="hidden" name="discontinue_product" value="<?= $prod['id'] ?>">
                              <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-x-circle"></i> Discontinue</button>
                          </form>
                          <?php else: ?>
                          <form method="post" style="display:inline-block" onsubmit="return confirm('Reactivate this product?');">
                              <input type="hidden" name="reactivate_product" value="<?= $prod['id'] ?>">
                              <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-arrow-clockwise"></i> Reactivate</button>
                          </form>
                          <form method="post" style="display:inline-block" onsubmit="return confirm('⚠️ WARNING: This will PERMANENTLY DELETE this product and all its delivery records from the database. This action cannot be undone!\n\nAre you absolutely sure you want to delete this product?');">
                              <input type="hidden" name="delete_product" value="<?= $prod['id'] ?>">
                              <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Delete</button>
                          </form>
                          <?php endif; ?>
                      </td>
                  </tr>
                  <?php
                  $next_category = isset($products[$index + 1]) ? ($products[$index + 1]['category_name'] ?? $products[$index + 1]['category'] ?? null) : null;
                  $current_category = $prod['category_name'] ?? $prod['category'] ?? null;
                  if ($current_category !== $next_category) {
                      // Adjusted colspan after adding Product Code column (now 12 columns)
                      echo '<tr><td colspan="12" style="background:#f8f9fa; height:10px; border:none;"></td></tr>';
                  }
                  ?>
              <?php endforeach; ?>
              </tbody>
          </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modals - Generate after the main table -->
<?php foreach ($products as $prod): ?>
    <div class="modal fade" id="editModal<?= $prod['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel<?= $prod['id'] ?>" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" class="editProductForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel<?= $prod['id'] ?>">Edit Product - <?= htmlspecialchars($prod['name']) ?></h5>
                    <div class="ms-auto d-flex align-items-center">
                        <button type="button" class="btn btn-link text-danger p-0 me-2 exit-btn" data-bs-dismiss="modal" aria-label="Close" title="Close">
                            <i class="bi bi-x-circle-fill" style="font-size:1.25rem; line-height:1;"></i>
                        </button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Product Code</label>
                                <input type="text" name="product_code" class="form-control" value="<?= htmlspecialchars($prod['product_code'] ?? '') ?>" placeholder="Optional product code">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($prod['name']) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <input type="text" class="form-control autosave-field" placeholder="Type or select category" list="edit_category_list" data-type="category" value="<?= htmlspecialchars(($categories[$prod['category_id'] ?? 0] ?? '')) ?>" required>
                                <input type="hidden" name="category_id" value="<?= $prod['category_id'] ?? '' ?>">
                                <datalist id="edit_category_list">
                                    <?php foreach ($categories as $cat_id => $cat_name): ?>
                                        <option value="<?= htmlspecialchars($cat_name) ?>" data-id="<?= $cat_id ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted"><a href="manage_categories.php">Manage Categories</a></small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Brand</label>
                                <input type="text" class="form-control autosave-field" placeholder="Type or select brand" list="edit_brand_list" data-type="brand" value="<?= htmlspecialchars(($brands[$prod['brand_id'] ?? 0] ?? '')) ?>">
                                <input type="hidden" name="brand_id" value="<?= $prod['brand_id'] ?? '' ?>">
                                <datalist id="edit_brand_list">
                                    <?php foreach ($brands as $brand_id => $brand_name): ?>
                                        <option value="<?= htmlspecialchars($brand_name) ?>" data-id="<?= $brand_id ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted"><a href="manage_brands.php">Manage Brands</a></small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Model</label>
                                <input type="text" class="form-control autosave-field" placeholder="Type or select model" list="edit_model_list" data-type="model" data-requires-brand="true" value="<?= htmlspecialchars(($models[$prod['model_id'] ?? 0]['name'] ?? '')) ?>">
                                <input type="hidden" name="model_id" value="<?= $prod['model_id'] ?? '' ?>">
                                <datalist id="edit_model_list">
                                    <?php foreach ($models as $model_id => $model_data): ?>
                                        <option value="<?= htmlspecialchars($model_data['name']) ?>" data-id="<?= $model_id ?>" data-brand-id="<?= $model_data['brand_id'] ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted"><a href="manage_models.php">Manage Models</a></small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Supplier</label>
                                <?php if (is_array($suppliers) && !empty($suppliers) && isset(array_values($suppliers)[0]) && is_numeric(array_keys($suppliers)[0])): ?>
                                    <!-- Use input with datalist from suppliers table -->
                                    <input type="text" class="form-control autosave-field" placeholder="Type or select supplier" list="edit_supplier_list" data-type="supplier" value="<?= htmlspecialchars(($suppliers[$prod['supplier_id'] ?? 0] ?? '')) ?>">
                                    <input type="hidden" name="supplier_id" value="<?= $prod['supplier_id'] ?? '' ?>">
                                    <datalist id="edit_supplier_list">
                                        <?php foreach ($suppliers as $supplier_id => $supplier_name): ?>
                                            <option value="<?= htmlspecialchars($supplier_name) ?>" data-id="<?= $supplier_id ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                    <small class="text-muted"><a href="manage_suppliers.php">Manage Suppliers</a></small>
                                <?php else: ?>
                                    <!-- Fallback: use text input with datalist -->
                                    <input type="text" name="supplier" class="form-control" value="<?= htmlspecialchars($prod['supplier'] ?? '') ?>" list="supplier_list_edit">
                                    <datalist id="supplier_list_edit">
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?= htmlspecialchars($supplier) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3 size-field-wrap">
                                <label class="form-label">Size</label>
                                <?php if (is_array($tire_sizes) && !empty($tire_sizes) && isset(array_values($tire_sizes)[0]) && is_numeric(array_keys($tire_sizes)[0])): ?>
                                    <!-- Use input with datalist from tire_sizes table -->
                                    <input type="text" class="form-control autosave-field" placeholder="Type or select tire size" list="edit_tire_size_list" data-type="tire_size" value="<?= htmlspecialchars(($tire_sizes[$prod['tire_size_id'] ?? 0] ?? '')) ?>">
                                    <input type="hidden" name="tire_size_id" value="<?= $prod['tire_size_id'] ?? '' ?>">
                                    <datalist id="edit_tire_size_list">
                                        <?php foreach ($tire_sizes as $tire_size_id => $tire_size_value): ?>
                                            <option value="<?= htmlspecialchars($tire_size_value) ?>" data-id="<?= $tire_size_id ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                    <small class="text-muted"><a href="manage_tire_sizes.php">Manage Tire Sizes</a></small>
                                <?php else: ?>
                                    <!-- Fallback: use text input with datalist -->
                                    <input type="text" name="size" class="form-control" value="<?= htmlspecialchars($prod['size'] ?? '') ?>" list="tire_size_list_edit">
                                    <datalist id="tire_size_list_edit">
                                        <?php foreach ($tire_sizes as $size): ?>
                                            <option value="<?= htmlspecialchars($size) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                <?php endif; ?>
                                <div class="invalid-feedback" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                    <!-- Description field removed per UI change; description remains stored in DB but is no longer edited here -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Price <span class="text-danger">*</span></label>
                                <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?= $prod['price'] ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Unit</label>
                                <div class="input-group">
                                    <select name="unit" class="form-select">
                                        <option value="">Select Unit</option>
                                        <?php foreach ($unit_options as $unit_opt):
                                            // Determine selection: if stored unit includes size (e.g. '500ml'), treat it as selected for 'ml' or 'L'
                                            $isSelected = false;
                                            if (!empty($prod['unit'])) {
                                                if (in_array($unit_opt, $unit_size_suffixes, true)) {
                                                    $isSelected = str_ends_with($prod['unit'], $unit_opt);
                                                } else {
                                                    $isSelected = ($prod['unit'] === $unit_opt);
                                                }
                                            }
                                        ?>
                                            <option value="<?= htmlspecialchars($unit_opt) ?>" <?= $isSelected ? 'selected' : '' ?>><?= htmlspecialchars($unit_opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="unit_size" class="form-control" placeholder="Size (for ml/L/g)" style="display:none;" value="<?= htmlspecialchars(extract_unit_size_prefix($prod['unit'] ?? '', $unit_size_suffixes)) ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <?php
                                $status = compute_status($prod['quantity'], $prod['reorder_level']);
                                $status_class_map = [
                                    'In Stock' => 'status-cell status-in-stock',
                                    'Low Stock' => 'status-cell status-low-stock',
                                    'Out of Stock' => 'status-cell status-out-stock'
                                ];
                                $status_class = $status_class_map[$status] ?? 'status-cell';
                                ?>
                                <div class="<?= $status_class ?>" style="cursor: default;">
                                    <?= htmlspecialchars($status) ?>
                                    <span class="status-indicator"></span>
                                </div>
                                <small class="text-muted">Status updates automatically based on quantity</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" class="form-control" value="<?= $prod['quantity'] ?>" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" class="form-control" value="<?= $prod['reorder_level'] ?>" min="0">
                                <small class="text-muted">System will alert when quantity falls below this level</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" name="edit_product" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Add Delivery Record Modal -->
<div class="modal fade" id="addDeliveryModal" tabindex="-1" aria-labelledby="addDeliveryLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addDeliveryLabel"><i class="bi bi-truck me-2"></i>Add Delivery Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-pills justify-content-center gap-2" id="addDeliveryTabNav" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="delivery-manual-tab" data-bs-toggle="pill" data-bs-target="#delivery-manual-pane" type="button" role="tab" aria-controls="delivery-manual-pane" aria-selected="true">
                            <i class="bi bi-pencil-square me-1"></i>Manual Entry
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="delivery-import-tab" data-bs-toggle="pill" data-bs-target="#delivery-import-pane" type="button" role="tab" aria-controls="delivery-import-pane" aria-selected="false">
                            <i class="bi bi-file-earmark-excel me-1"></i>Import Excel
                        </button>
                    </li>
                </ul>
                <div class="tab-content mt-3" id="addDeliveryTabContent">
                    <div class="tab-pane fade show active" id="delivery-manual-pane" role="tabpanel" aria-labelledby="delivery-manual-tab">
                        <form method="post" id="addDeliveryForm">
                            <div class="mb-3">
                                <label class="form-label">Product <span class="text-danger">*</span></label>
                                <div class="position-relative">
                                    <input type="text" 
                                           id="delivery_product_search" 
                                           class="form-control" 
                                           placeholder="Type to search products..." 
                                           autocomplete="off"
                                           required>
                                    <input type="hidden" name="product_id" id="delivery_product_id" required>
                                    <div id="delivery_product_dropdown" class="product-autocomplete-dropdown" style="display: none;">
                                        <!-- Results will be populated here -->
                                    </div>
                                </div>
                                <small class="text-muted">Start typing product name, ID, or category to search</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Delivered Quantity <span class="text-danger">*</span></label>
                                        <input type="number" name="delivery_quantity" id="delivery_quantity" class="form-control" min="1" required oninput="calculateGoodQty()">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Delivery Date <span class="text-danger">*</span></label>
                                        <input type="date" name="delivery_date" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Defective Quantity</label>
                                        <input type="number" name="defective_qty" id="defective_qty" class="form-control" min="0" value="0" oninput="calculateGoodQty()">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Good Quantity <span class="text-muted">(Auto-calculated)</span></label>
                                        <input type="number" name="good_qty" id="good_qty" class="form-control" readonly style="background-color: #f8f9fa;">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Supplier</label>
                                        <input type="text" name="delivery_supplier" class="form-control" placeholder="Supplier name">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Received By</label>
                                        <input type="text" name="received_by" class="form-control" placeholder="Staff name">
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this delivery"></textarea>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3 flex-wrap">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                                <button type="submit" name="add_delivery" class="btn btn-warning" onclick="return validateDeliveryForm()"><i class="bi bi-save me-1"></i>Save Delivery</button>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="delivery-import-pane" role="tabpanel" aria-labelledby="delivery-import-tab">
                        <form method="post" enctype="multipart/form-data" id="importDeliveryForm">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Upload Delivery Excel</label>
                                <div class="file-upload-container d-flex align-items-center" style="border: 2px dashed #cbd5e0; border-radius: 8px; padding: 0.75rem 1rem; background: white; transition: all 0.3s ease; cursor: pointer;" 
                                     onmouseover="this.style.borderColor='#1565c0'; this.style.backgroundColor='#f7fafc';" 
                                     onmouseout="this.style.borderColor='#cbd5e0'; this.style.backgroundColor='white';">
                                  <input type="file" name="delivery_excel_file" id="delivery_excel_file" class="form-control d-none" accept=".xlsx,.xls,.xlsm,.csv" data-display-id="delivery-file-name-display" onchange="updateFileName(this, 'delivery-file-name-display')" required>
                                  <label for="delivery_excel_file" style="cursor: pointer; margin: 0; width: 100%; display: flex; align-items: center; justify-content: space-between;">
                                    <div class="d-flex align-items-center">
                                      <i class="bi bi-file-earmark-spreadsheet-fill me-2" style="font-size: 1.3rem; color: #1565c0;"></i>
                                      <div>
                                        <div style="font-weight: 600; color: #2d3748; font-size: 0.9rem;">Click to browse or drag and drop</div>
                                        <div id="delivery-file-name-display" class="file-name-display" data-default="No file selected" style="color: #718096; font-size: 0.8rem;">No file selected</div>
                                      </div>
                                    </div>
                                    <span style="color: #a0aec0; font-size: 0.75rem;">.xlsx, .xls, .xlsm, .csv</span>
                                  </label>
                                </div>
                            </div>
                            <div class="card mb-3" style="border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-radius: 10px;">
                                <div class="card-body" style="padding: 0.85rem;">
                                    <h6 class="fw-semibold mb-2" style="font-size: 0.9rem;"><i class="bi bi-info-circle me-1 text-primary"></i>Required Columns</h6>
                                    <ul class="mb-0" style="padding-left: 1.2rem; font-size: 0.8rem; color: #4a5568;">
                                        <li><strong>product_id</strong> &ndash; matches an existing product in this branch</li>
                                        <li><strong>delivered_qty</strong> or <strong>quantity</strong> &ndash; positive whole number</li>
                                        <li><strong>defective_qty</strong> &ndash; optional, defaults to 0</li>
                                        <li><strong>supplier</strong> &ndash; optional</li>
                                        <li><strong>delivery_date</strong> &ndash; YYYY-MM-DD or Excel date</li>
                                        <li><strong>received_by</strong> &ndash; optional</li>
                                        <li><strong>notes</strong> &ndash; optional</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="alert alert-warning" style="border-radius: 8px; border: none; background: #fff3cd; padding: 0.6rem 0.75rem; font-size: 0.8rem; color: #856404;">
                                Header rows are detected automatically. Invalid rows will be skipped and reported.
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3 flex-wrap">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                                <button type="submit" name="import_delivery_excel" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import Deliveries</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel"><i class="bi bi-plus-circle me-2"></i>Add Product</h5>
                <div class="ms-auto d-flex align-items-center">
                    <button type="button" class="btn btn-link text-danger p-0 me-2 exit-btn" data-bs-dismiss="modal" aria-label="Close" title="Close">
                        <i class="bi bi-x-circle-fill" style="font-size:1.54rem; line-height:2;"></i>
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <ul class="nav nav-pills justify-content-center gap-2" id="addProductTabNav" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="manual-entry-tab" data-bs-toggle="pill" data-bs-target="#manual-entry-pane" type="button" role="tab" aria-controls="manual-entry-pane" aria-selected="true">
                            <i class="bi bi-pencil-square me-1"></i>Manual Entry
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="import-entry-tab" data-bs-toggle="pill" data-bs-target="#import-entry-pane" type="button" role="tab" aria-controls="import-entry-pane" aria-selected="false">
                            <i class="bi bi-file-earmark-excel me-1"></i>Import Excel
                        </button>
                    </li>
                </ul>
                <div class="tab-content mt-3" id="addProductTabContent">
                    <div class="tab-pane fade show active" id="manual-entry-pane" role="tabpanel" aria-labelledby="manual-entry-tab">
                        <form method="post" id="addProductForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Product Code</label>
                                        <input type="text" name="product_code" class="form-control" placeholder="Optional product code">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" class="form-control" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Category <span class="text-danger">*</span></label>
                                        <input type="text" id="add_category_input" class="form-control autosave-field" placeholder="Type or select category" list="add_category_list" data-type="category" required>
                                        <input type="hidden" name="category_id" id="add_category_id">
                                        <datalist id="add_category_list">
                                            <?php foreach ($categories as $cat_id => $cat_name): ?>
                                                <option value="<?= htmlspecialchars($cat_name) ?>" data-id="<?= $cat_id ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <small class="text-muted"><a href="manage_categories.php">Manage Categories</a></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Brand</label>
                                        <input type="text" id="add_brand_input" class="form-control autosave-field" placeholder="Type or select brand" list="add_brand_list" data-type="brand">
                                        <input type="hidden" name="brand_id" id="add_brand_id">
                                        <datalist id="add_brand_list">
                                            <?php foreach ($brands as $brand_id => $brand_name): ?>
                                                <option value="<?= htmlspecialchars($brand_name) ?>" data-id="<?= $brand_id ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <small class="text-muted"><a href="manage_brands.php">Manage Brands</a></small>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Model</label>
                                        <input type="text" id="add_model_input" class="form-control autosave-field" placeholder="Type or select model" list="add_model_list" data-type="model" data-requires-brand="true">
                                        <input type="hidden" name="model_id" id="add_model_id">
                                        <datalist id="add_model_list">
                                            <?php foreach ($models as $model_id => $model_data): ?>
                                                <option value="<?= htmlspecialchars($model_data['display_name'] ?? $model_data['name']) ?>" data-id="<?= $model_id ?>" data-brand-id="<?= $model_data['brand_id'] ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <small class="text-muted"><a href="manage_models.php">Manage Models</a></small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label class="form-label">Supplier</label>
                                        <?php if (is_array($suppliers) && !empty($suppliers) && isset(array_values($suppliers)[0]) && is_numeric(array_keys($suppliers)[0])): ?>
                                            <!-- Use input with datalist from suppliers table -->
                                            <input type="text" id="add_supplier_input" class="form-control autosave-field" placeholder="Type or select supplier" list="add_supplier_list" data-type="supplier">
                                            <input type="hidden" name="supplier_id" id="add_supplier_id">
                                            <datalist id="add_supplier_list">
                                                <?php foreach ($suppliers as $supplier_id => $supplier_name): ?>
                                                    <option value="<?= htmlspecialchars($supplier_name) ?>" data-id="<?= $supplier_id ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                            <small class="text-muted"><a href="manage_suppliers.php">Manage Suppliers</a></small>
                                        <?php else: ?>
                                            <!-- Fallback: use text input with datalist -->
                                            <input type="text" name="supplier" id="add_supplier" class="form-control" placeholder="Type or select supplier" list="supplier_list">
                                            <datalist id="supplier_list">
                                                <?php foreach ($suppliers as $supplier): ?>
                                                    <option value="<?= htmlspecialchars($supplier) ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3 size-field-wrap">
                                        <label class="form-label">Size</label>
                                        <?php if (is_array($tire_sizes) && !empty($tire_sizes) && isset(array_values($tire_sizes)[0]) && is_numeric(array_keys($tire_sizes)[0])): ?>
                                            <!-- Use input with datalist from tire_sizes table -->
                                            <input type="text" id="add_tire_size_input" class="form-control autosave-field" placeholder="Type or select tire size" list="add_tire_size_list" data-type="tire_size">
                                            <input type="hidden" name="tire_size_id" id="add_tire_size_id">
                                            <datalist id="add_tire_size_list">
                                                <?php foreach ($tire_sizes as $tire_size_id => $tire_size_value): ?>
                                                    <option value="<?= htmlspecialchars($tire_size_value) ?>" data-id="<?= $tire_size_id ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                            <small class="text-muted"><a href="manage_tire_sizes.php">Manage Tire Sizes</a></small>
                                        <?php else: ?>
                                            <!-- Fallback: use text input with datalist -->
                                            <input type="text" name="size" id="add_size" class="form-control" placeholder="e.g. 80/90-14" list="tire_size_list">
                                            <datalist id="tire_size_list">
                                                <?php foreach ($tire_sizes as $size): ?>
                                                    <option value="<?= htmlspecialchars($size) ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                        <?php endif; ?>
                                        <div class="invalid-feedback" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description field removed from Add Product form; still accepted via import if present -->

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Price</label>
                                        <input type="number" name="price" class="form-control" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Unit</label>
                                        <div class="input-group">
                                            <select name="unit" class="form-select">
                                                <option value="">Select Unit</option>
                                                <?php foreach ($unit_options as $unit_opt): ?>
                                                    <option value="<?= htmlspecialchars($unit_opt) ?>"><?= htmlspecialchars($unit_opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="unit_size" class="form-control" placeholder="Size (for ml/L/g)" style="display:none;">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Status</label>
                                        <div class="status-cell status-in-stock" style="cursor: default;">
                                            In Stock
                                            <span class="status-indicator"></span>
                                        </div>
                                        <small class="text-muted">Status updates automatically based on quantity</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" name="quantity" class="form-control" min="0">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Reorder Level</label>
                                        <input type="number" name="reorder_level" class="form-control" min="0">
                                        <small class="text-muted">System will alert when quantity falls below this level</small>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3 flex-wrap">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                                <button type="submit" name="add_product" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Add Product</button>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="import-entry-pane" role="tabpanel" aria-labelledby="import-entry-tab">
                        <form method="post" enctype="multipart/form-data" id="importExcelForm">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Upload Excel / CSV</label>
                                <div class="file-upload-container d-flex align-items-center" style="border: 2px dashed #cbd5e0; border-radius: 8px; padding: 0.75rem 1rem; background: white; transition: all 0.3s ease; cursor: pointer;" 
                                     onmouseover="this.style.borderColor='#1565c0'; this.style.backgroundColor='#f7fafc';" 
                                     onmouseout="this.style.borderColor='#cbd5e0'; this.style.backgroundColor='white';">
              <input type="file" name="excel_file" id="product_excel_file" class="form-control d-none" accept=".xlsx,.xls,.xlsm,.csv" data-display-id="product-file-name-display" required onchange="updateFileName(this, 'product-file-name-display')">
              <label for="product_excel_file" style="cursor: pointer; margin: 0; width: 100%; display: flex; align-items: center; justify-content: space-between;">
                                    <div class="d-flex align-items-center">
                                      <i class="bi bi-file-earmark-spreadsheet-fill me-2" style="font-size: 1.5rem; color: #1565c0;"></i>
                                      <div>
                                        <div style="font-weight: 600; color: #2d3748; font-size: 0.9rem;">Click to browse or drag and drop</div>
                    <div id="product-file-name-display" class="file-name-display" data-default="No file selected" style="color: #718096; font-size: 0.8rem;">No file selected</div>
                                      </div>
                                    </div>
                                    <span style="color: #a0aec0; font-size: 0.75rem;">.xlsx, .xls, .xlsm, .csv</span>
                                  </label>
                                </div>
                            </div>
                            
                            <div class="card mb-3" style="border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-radius: 10px;">
                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center" style="border: none; border-radius: 10px 10px 0 0; padding: 0.6rem 0.75rem;">
                                  <h6 class="mb-0" style="font-weight: 600; font-size: 0.9rem;">
                                    <i class="bi bi-list-ol me-2"></i>Column Order & Valid Values
                                  </h6>
                                </div>
                                <div class="card-body" style="padding: 0.75rem;">
                                  <div class="mb-2">
                                    <label class="fw-semibold mb-1" style="color: #2d3748; font-size: 0.85rem;">
                                      <i class="bi bi-arrow-down-up text-primary me-1"></i>Required Column Order:
                                    </label>
                                    <div class="d-flex flex-wrap gap-1" style="font-size: 0.8rem;">
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">1. Name</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">2. Category</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">3. Brand</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">4. Model</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">5. Supplier</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">6. Unit</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">7. Quantity</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">8. Reorder Level</span>
                                      <span class="badge bg-primary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">9. Status</span>
                                      <span class="badge bg-success" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">10. PriceNumeric</span>
                                      <span class="badge bg-secondary" style="font-size: 0.7rem; padding: 0.25rem 0.5rem;">or PriceDisplay</span>
                                    </div>
                                  </div>
                                  
                                  <hr style="margin: 0.5rem 0; opacity: 0.3;">
                                  
                                  <div class="row g-2">
                                    <div class="col-md-6">
                                      <label class="fw-semibold mb-1" style="color: #2d3748; font-size: 0.85rem;">
                                        <i class="bi bi-tags-fill text-primary me-1"></i>Categories:
                                      </label>
                                      <div class="d-flex flex-wrap gap-1" style="font-size: 0.75rem;">
                                        <?php foreach ($fixed_categories as $cat): ?>
                                          <span class="badge" style="background: #e2e8f0; color: #4a5568; padding: 0.2rem 0.5rem; font-size: 0.7rem; font-weight: 500;">
                                            <?= htmlspecialchars($cat) ?>
                                          </span>
                                        <?php endforeach; ?>
                                      </div>
                                    </div>
                                    <div class="col-md-6">
                                      <label class="fw-semibold mb-1" style="color: #2d3748; font-size: 0.85rem;">
                                        <i class="bi bi-rulers text-primary me-1"></i>Units:
                                      </label>
                                      <div class="d-flex flex-wrap gap-1" style="font-size: 0.75rem;">
                                        <?php foreach ($unit_options as $unit): ?>
                                          <span class="badge" style="background: #e0ecff; color: #0d47a1; padding: 0.2rem 0.5rem; font-size: 0.7rem; font-weight: 500;">
                                            <?= htmlspecialchars($unit) ?>
                                          </span>
                                        <?php endforeach; ?>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                            </div>

                            <div class="alert alert-warning" style="border-radius: 8px; border: none; background: #fff3cd; padding: 0.6rem 0.75rem;">
                                <div class="d-flex align-items-start">
                                  <i class="bi bi-info-circle-fill me-2" style="font-size: 0.9rem; color: #856404; margin-top: 0.1rem;"></i>
                                  <div style="flex: 1; font-size: 0.8rem; line-height: 1.4;">
                                    <strong style="color: #856404;">Note:</strong>
                                    Header row is auto-detected. Invalid rows are skipped automatically.
                                  </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-3 flex-wrap">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                                <button type="submit" name="import_excel" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import Products</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function calculateGoodQty() {
    var deliveredQty = parseFloat(document.getElementById('delivery_quantity').value) || 0;
    var defectiveQty = parseFloat(document.getElementById('defective_qty').value) || 0;
    var goodQty = deliveredQty - defectiveQty;
    
    // Ensure good_qty is not negative
    if (goodQty < 0) {
        goodQty = 0;
    }
    
    document.getElementById('good_qty').value = Math.floor(goodQty);
}

function validateDeliveryForm() {
    var productId = document.getElementById('delivery_product_id').value;
    if (!productId) {
        alert('Please select a product from the list.');
        document.getElementById('delivery_product_search').focus();
        return false;
    }
    return true;
}

function updateFileName(input, displayId) {
  if (!input) return;
  var display = displayId ? document.getElementById(displayId) : null;
  if (!display) {
    var container = input.closest('.file-upload-container');
    if (container) {
      display = container.querySelector('.file-name-display');
    }
  }
  if (!display) return;
  var defaultText = display.dataset.default || 'No file selected';
  if (input.files && input.files[0]) {
    var file = input.files[0];
    var fileSize = (file.size / 1024).toFixed(2) + ' KB';
    display.innerHTML = `<strong style="color: #667eea;">${file.name}</strong> <span style="color: #718096;">(${fileSize})</span>`;
  } else {
    display.textContent = defaultText;
  }
}

// Product autocomplete data
var deliveryProducts = [
  <?php foreach ($products as $p): ?>
  {
    id: <?= $p['id'] ?>,
    name: <?= json_encode($p['name']) ?>,
    category: <?= json_encode($p['category'] ?? '') ?>,
    quantity: <?= intval($p['quantity']) ?>,
    displayText: '#<?= $p['id'] ?> - <?= htmlspecialchars($p['name']) ?> (Qty: <?= intval($p['quantity']) ?>)'
  },
  <?php endforeach; ?>
];

// Product autocomplete functionality
function initDeliveryProductAutocomplete() {
  var searchInput = document.getElementById('delivery_product_search');
  var productIdInput = document.getElementById('delivery_product_id');
  var dropdown = document.getElementById('delivery_product_dropdown');
  var selectedProduct = null;

  if (!searchInput || !productIdInput || !dropdown) return;

  function filterProducts(query) {
    if (!query || query.trim() === '') {
      return deliveryProducts.slice(0, 10); // Show first 10 when empty
    }
    
    query = query.toLowerCase();
    return deliveryProducts.filter(function(product) {
      return product.name.toLowerCase().includes(query) ||
             product.id.toString().includes(query) ||
             (product.category && product.category.toLowerCase().includes(query));
    }).slice(0, 10); // Limit to 10 results
  }

  function showDropdown(results) {
    if (results.length === 0) {
      dropdown.innerHTML = '<div class="autocomplete-item">No products found</div>';
      dropdown.style.display = 'block';
      return;
    }

    dropdown.innerHTML = results.map(function(product) {
      return '<div class="autocomplete-item" data-id="' + product.id + '" data-text="' + 
             product.displayText.replace(/"/g, '&quot;') + '">' + 
             product.displayText + '</div>';
    }).join('');
    dropdown.style.display = 'block';
  }

  function hideDropdown() {
    setTimeout(function() {
      dropdown.style.display = 'none';
    }, 200);
  }

  function selectProduct(product) {
    selectedProduct = product;
    searchInput.value = product.displayText;
    productIdInput.value = product.id;
    hideDropdown();
    // Trigger validation
    searchInput.setCustomValidity('');
  }

  // Search input events
  searchInput.addEventListener('input', function() {
    var query = this.value;
    var results = filterProducts(query);
    showDropdown(results);
    
    // Clear selection if user is typing
    if (selectedProduct && query !== selectedProduct.displayText) {
      productIdInput.value = '';
      selectedProduct = null;
    }
  });

  searchInput.addEventListener('focus', function() {
    var query = this.value;
    var results = filterProducts(query);
    showDropdown(results);
  });

  searchInput.addEventListener('blur', function() {
    hideDropdown();
    
    // Validate on blur
    if (!productIdInput.value) {
      searchInput.setCustomValidity('Please select a product from the list');
    } else {
      searchInput.setCustomValidity('');
    }
  });

  // Dropdown click events
  dropdown.addEventListener('click', function(e) {
    var item = e.target.closest('.autocomplete-item');
    if (item) {
      var productId = parseInt(item.getAttribute('data-id'));
      var product = deliveryProducts.find(function(p) { return p.id === productId; });
      if (product) {
        selectProduct(product);
      }
    }
  });

  // Keyboard navigation
  searchInput.addEventListener('keydown', function(e) {
    var items = dropdown.querySelectorAll('.autocomplete-item');
    var currentIndex = -1;
    
    items.forEach(function(item, index) {
      if (item.classList.contains('active')) {
        currentIndex = index;
      }
    });

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      items.forEach(function(item) { item.classList.remove('active'); });
      var nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
      if (items[nextIndex]) {
        items[nextIndex].classList.add('active');
        items[nextIndex].scrollIntoView({ block: 'nearest' });
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      items.forEach(function(item) { item.classList.remove('active'); });
      var prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
      if (items[prevIndex]) {
        items[prevIndex].classList.add('active');
        items[prevIndex].scrollIntoView({ block: 'nearest' });
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var activeItem = dropdown.querySelector('.autocomplete-item.active');
      if (activeItem) {
        var productId = parseInt(activeItem.getAttribute('data-id'));
        var product = deliveryProducts.find(function(p) { return p.id === productId; });
        if (product) {
          selectProduct(product);
        }
      } else if (items.length === 1) {
        // If only one result, select it
        var productId = parseInt(items[0].getAttribute('data-id'));
        var product = deliveryProducts.find(function(p) { return p.id === productId; });
        if (product) {
          selectProduct(product);
        }
      }
    } else if (e.key === 'Escape') {
      hideDropdown();
    }
  });
}

// Reset delivery modal when opened
document.addEventListener('DOMContentLoaded', function() {
  var addDeliveryModal = document.getElementById('addDeliveryModal');
  if (addDeliveryModal) {
    addDeliveryModal.addEventListener('show.bs.modal', function() {
      // Reset form fields
      var form = document.getElementById('addDeliveryForm');
      if (form) {
        form.reset();
        document.getElementById('defective_qty').value = '0';
        document.getElementById('good_qty').value = '0';
        // Reset product autocomplete
        document.getElementById('delivery_product_search').value = '';
        document.getElementById('delivery_product_id').value = '';
      }
      // Initialize autocomplete
      initDeliveryProductAutocomplete();
    });
  }
  
  // Also initialize on page load if modal is already open
  initDeliveryProductAutocomplete();
  
  document.querySelectorAll('.file-upload-container').forEach(function(container) {
    var fileInput = container.querySelector('input[type="file"]');
    if (!fileInput) return;
    var displayId = fileInput.dataset.displayId || null;

    var preventDefaults = function(e) {
      e.preventDefault();
      e.stopPropagation();
    };

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName) {
      container.addEventListener(eventName, preventDefaults, false);
    });

    ['dragenter', 'dragover'].forEach(function(eventName) {
      container.addEventListener(eventName, function() {
        container.style.borderColor = '#667eea';
        container.style.backgroundColor = '#f7fafc';
      }, false);
    });

    ['dragleave', 'drop'].forEach(function(eventName) {
      container.addEventListener(eventName, function() {
        container.style.borderColor = '#cbd5e0';
        container.style.backgroundColor = 'white';
      }, false);
    });

    container.addEventListener('drop', function(e) {
      var files = e.dataTransfer.files;
      if (files.length > 0) {
        fileInput.files = files;
        updateFileName(fileInput, displayId);
      }
    }, false);
  });
});
</script>

<!-- Note: model suggestions are provided dynamically via AJAX endpoint /pages/admin/ajax_get_models.php -->

<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>

<!-- Reorder Level Toggle Script - Must run after jQuery loads -->
<script>
    // Reorder Level Toggle - Simple Direct Implementation
    (function() {
        function setupReorderToggle() {
            if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
                setTimeout(setupReorderToggle, 50);
                return;
            }
            
            var STORAGE_KEY = 'adminShowReorderLevel';
            
            $(document).ready(function() {
                var $toggle = $('#toggleReorderLevel');
                
                if ($toggle.length === 0) {
                    return;
                }
                
                // Function to show/hide columns
                function toggleColumns(show) {
                    if (show) {
                        $('.reorder-col').show();
                        $('body').removeClass('reorder-hidden');
                    } else {
                        $('.reorder-col').hide();
                        $('body').addClass('reorder-hidden');
                    }
                }
                
                // Initialize from localStorage
                var pref = localStorage.getItem(STORAGE_KEY);
                var show = pref !== 'false';
                $toggle.prop('checked', show);
                toggleColumns(show);
                
                // Event handler
                $toggle.on('change', function() {
                    var checked = $(this).is(':checked');
                    toggleColumns(checked);
                    localStorage.setItem(STORAGE_KEY, checked ? 'true' : 'false');
                });
            });
        }
        
        setupReorderToggle();
    })();
</script>

<!-- Auto-save new entries script -->
<script>
(function() {
    // Auto-save functionality for typing new values
    document.addEventListener('DOMContentLoaded', function() {
        // Handle all autosave fields
        document.addEventListener('blur', function(e) {
            const field = e.target;
            if (!field.classList.contains('autosave-field') || !field.value.trim()) {
                return;
            }
            
            const type = field.getAttribute('data-type');
            const value = field.value.trim();
            const datalistId = field.getAttribute('list');
            const datalist = document.getElementById(datalistId);
            const hiddenInput = field.parentElement.querySelector('input[type="hidden"]');
            
            if (!datalist || !hiddenInput) return;
            
            // Check if value exists in datalist
            const existingOption = Array.from(datalist.options).find(opt => opt.value.toLowerCase() === value.toLowerCase());
            
            if (existingOption) {
                // Value exists, set the hidden input to the ID
                const id = existingOption.getAttribute('data-id');
                if (id) {
                    hiddenInput.value = id;
                }
                return;
            }
            
            // Value doesn't exist, save it via AJAX
            const requiresBrand = field.getAttribute('data-requires-brand') === 'true';
            let brandId = null;
            
            if (requiresBrand) {
                // For models, get the brand_id from the brand field
                const form = field.closest('form');
                if (form) {
                    const brandInput = form.querySelector('input[data-type="brand"]');
                    const brandHidden = brandInput ? brandInput.parentElement.querySelector('input[type="hidden"][name*="brand"]') : null;
                    brandId = brandHidden ? brandHidden.value : null;
                    
                    if (!brandId || brandId === '') {
                        alert('Please select a brand first before adding a new model.');
                        field.focus();
                        return;
                    }
                }
            }
            
            // Show loading indicator
            field.disabled = true;
            const originalPlaceholder = field.placeholder;
            field.placeholder = 'Saving...';
            
            // Prepare form data
            const formData = new FormData();
            formData.append('type', type);
            formData.append('name', value);
            if (brandId) {
                formData.append('brand_id', brandId);
            }
            
            // Send AJAX request
            fetch('ajax_save_entry.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                field.disabled = false;
                field.placeholder = originalPlaceholder;
                
                if (data.success) {
                    // Update hidden input with new ID
                    hiddenInput.value = data.id;
                    
                    // Add new option to datalist
                    const newOption = document.createElement('option');
                    newOption.value = data.name;
                    newOption.setAttribute('data-id', data.id);
                    if (requiresBrand && brandId) {
                        newOption.setAttribute('data-brand-id', brandId);
                    }
                    datalist.appendChild(newOption);
                    
                    // Show success feedback
                    const feedback = document.createElement('small');
                    feedback.className = 'text-success';
                    feedback.textContent = '✓ Saved';
                    feedback.style.display = 'block';
                    field.parentElement.appendChild(feedback);
                    setTimeout(() => feedback.remove(), 2000);
                } else {
                    alert('Error saving ' + type + ': ' + (data.error || 'Unknown error'));
                    field.focus();
                }
            })
            .catch(error => {
                field.disabled = false;
                field.placeholder = originalPlaceholder;
                console.error('Error:', error);
                alert('Error saving ' + type + '. Please try again.');
                field.focus();
            });
        }, true); // Use capture phase
        
        // Also handle when user selects from datalist (input event)
        document.addEventListener('input', function(e) {
            const field = e.target;
            if (!field.classList.contains('autosave-field') || !field.value.trim()) {
                return;
            }
            
            const datalistId = field.getAttribute('list');
            const datalist = document.getElementById(datalistId);
            const hiddenInput = field.parentElement.querySelector('input[type="hidden"]');
            
            if (!datalist || !hiddenInput) return;
            
            // Check if the typed value matches an existing option
            const value = field.value.trim();
            const matchingOption = Array.from(datalist.options).find(opt => {
                const optValue = opt.value.trim();
                return optValue.toLowerCase() === value.toLowerCase();
            });
            
            if (matchingOption) {
                const id = matchingOption.getAttribute('data-id');
                if (id) {
                    hiddenInput.value = id;
                    
                    // For models, also check brand_id requirement
                    if (field.getAttribute('data-type') === 'model' && field.getAttribute('data-requires-brand') === 'true') {
                        const optionBrandId = matchingOption.getAttribute('data-brand-id');
                        const form = field.closest('form');
                        if (form) {
                            const brandHidden = form.querySelector('input[type="hidden"][name*="brand"]');
                            if (brandHidden && optionBrandId && brandHidden.value !== optionBrandId) {
                                // Brand mismatch - clear model
                                field.value = '';
                                hiddenInput.value = '';
                            }
                        }
                    }
                }
            } else {
                // Value doesn't match any option - clear hidden input
                hiddenInput.value = '';
            }
        });
    });
})();
</script>

<script>
    // Auto-open Add Product modal if URL hash is #addProduct
    // This script runs immediately after Bootstrap loads
    (function() {
        function openAddProductModal() {
            // Check if hash exists
            const hash = window.location.hash;
            if (hash === '#addProduct') {
                console.log('Detected #addProduct hash, attempting to open modal...');
                
                // Method 1: Try clicking the Add Product button (most reliable)
                const addProductBtn = document.querySelector('button[data-bs-target="#addModal"]');
                if (addProductBtn) {
                    console.log('Found Add Product button, clicking...');
                    // Use a small delay to ensure Bootstrap is ready
                    setTimeout(function() {
                        addProductBtn.click();
                        // Remove hash from URL after opening modal
                        setTimeout(function() {
                            if (window.location.hash === '#addProduct') {
                                history.replaceState(null, null, window.location.pathname + window.location.search);
                            }
                        }, 100);
                    }, 100);
                    return true;
                }
                
                // Method 2: Use Bootstrap Modal API directly
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const addModal = document.getElementById('addModal');
                    if (addModal) {
                        try {
                            console.log('Using Bootstrap API to open modal...');
                            const modal = new bootstrap.Modal(addModal);
                            setTimeout(function() {
                                modal.show();
                                // Remove hash from URL after opening modal
                                setTimeout(function() {
                                    if (window.location.hash === '#addProduct') {
                                        history.replaceState(null, null, window.location.pathname + window.location.search);
                                    }
                                }, 100);
                            }, 100);
                            return true;
                        } catch (e) {
                            console.error('Error opening modal with Bootstrap API:', e);
                        }
                    }
                }
                
                return false;
            }
            return false;
        }
        
        // Wait for DOM to be ready and Bootstrap to be fully loaded
        function tryOpenWhenReady() {
            // Check if DOM is ready and Bootstrap is loaded
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(tryOpenWhenReady, 300);
                });
                return;
            }
            
            // Check if Bootstrap is available
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                // Wait a bit more for Bootstrap
                setTimeout(tryOpenWhenReady, 100);
                return;
            }
            
            // Try to open modal
            if (!openAddProductModal()) {
                // If failed, retry after a short delay
                setTimeout(function() {
                    openAddProductModal();
                }, 200);
            }
        }
        
        // Start trying after a short delay to ensure everything is loaded
        setTimeout(tryOpenWhenReady, 300);
        
        // Also try on window load (after all resources loaded)
        window.addEventListener('load', function() {
            setTimeout(openAddProductModal, 200);
        });
        
        // Also listen for hash changes (in case hash is set after page load)
        window.addEventListener('hashchange', function() {
            if (window.location.hash === '#addProduct') {
                setTimeout(openAddProductModal, 200);
            }
        });
    })();
</script>

<!-- Autocomplete for model fields: fetch suggestions from ajax_get_models.php -->
<style>
    /* suggestions dropdown */
    .model-suggestions { position: absolute; z-index: 2000; display: none; max-height: 220px; overflow-y: auto; }
    .model-suggestions .item { cursor: pointer; padding: .375rem .75rem; }
    .model-suggestions .item:hover, .model-suggestions .item.active { background: #f1f5f9; }
    /* ensure the mb-3 wrapper is positioned so absolute dropdown can place itself */
    .mb-3 { position: relative; }
    
    /* Product autocomplete dropdown */
    .product-autocomplete-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        max-height: 300px;
        overflow-y: auto;
        z-index: 1050;
        margin-top: 2px;
    }
    
    .autocomplete-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
        font-size: 0.9rem;
    }
    
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    
    .autocomplete-item:hover,
    .autocomplete-item.active {
        background-color: #e3f2fd;
        color: #1565c0;
    }
    
    .autocomplete-item:first-child {
        border-top-left-radius: 0.375rem;
        border-top-right-radius: 0.375rem;
    }
    
    .autocomplete-item:last-child {
        border-bottom-left-radius: 0.375rem;
        border-bottom-right-radius: 0.375rem;
    }
</style>

<script>
;(function($){
    // simple debounce
    function debounce(fn, delay){
        var t;
        return function(){
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function(){ fn.apply(ctx, args); }, delay);
        };
    }

    // build dropdown container (single shared, reused)
    function ensureContainer($input){
        var $wrap = $input.closest('.mb-3');
        var $container = $wrap.find('.model-suggestions');
        if ($container.length === 0) {
            $container = $('<div class="model-suggestions card shadow-sm"></div>');
            $wrap.append($container);
        }
        return $container;
    }

    function showSuggestions($input, items){
        var $c = ensureContainer($input);
        $c.empty();
        if (!items || items.length === 0) { $c.hide(); return; }
        items.forEach(function(it){
            var $it = $('<div class="item">').text(it).data('value', it);
            $c.append($it);
        });
        $c.show();
    }

    $(document).on('click', function(e){
        if (!$(e.target).closest('.model-suggestions, .model-autocomplete').length) {
            $('.model-suggestions').hide();
        }
    });

    // delegate input events
    $(document).on('input', '.model-autocomplete', debounce(function(e){
        var $this = $(this);
        var q = $this.val();
        var branch = $this.data('branch-id') || '';
        if (!q || q.length < 1) { ensureContainer($this).hide(); return; }
        var url = '<?= BASE_URL ?>/pages/admin/ajax_get_models.php?branch_id=' + encodeURIComponent(branch) + '&q=' + encodeURIComponent(q);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(list){
                // remove the exact text match from suggestions to avoid duplicate
                showSuggestions($this, list);
            }).catch(function(){ ensureContainer($this).hide(); });
    }, 220));

    // click a suggestion
    $(document).on('click', '.model-suggestions .item', function(){
        var $it = $(this);
        var $wrap = $it.closest('.mb-3');
        var $input = $wrap.find('.model-autocomplete').first();
        if ($input.length) {
            $input.val($it.data('value'));
            $wrap.find('.model-suggestions').hide();
        }
    });

    // hide on ESC
    $(document).on('keydown', '.model-autocomplete', function(e){
        if (e.key === 'Escape') { $(this).closest('.mb-3').find('.model-suggestions').hide(); }
    });
})(jQuery);
</script>

<!-- JavaScript to prevent FOUC -->
<script>
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
        
        // Preload logo image
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
</script>

<!-- Manage Category Modal (standalone form) -->
<div class="modal fade" id="manageCategoryModal" tabindex="-1" aria-labelledby="manageCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="manageCategoryModalLabel">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="categoryName" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="categoryName" name="categoryName" required>
                    </div>
                    <hr>
                    <h6>Existing Categories</h6>
                    <ul class="list-group">
                    <?php
                    require_once '../../includes/db_connection.php';
                    $conn2 = new mysqli($host, $user, $pass, $dbname, 3307);
                    if ($conn2->connect_error) {
                        echo '<li class="list-group-item">Database connection error.</li>';
                    } else {
                        $result = $conn2->query("SELECT id, name FROM category ORDER BY name ASC");
                        while ($row = $result->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= htmlspecialchars($row['name']) ?>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="deleteCategoryId" value="<?= $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" name="deleteCategory" onclick="return confirm('Are you sure you want to delete this category?');">Delete</button>
                                </form>
                            </li>
                        <?php endwhile;
                        $conn2->close();
                    }
                    ?>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" name="addCategory">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- (Duplicate Manage Category Modal removed to fix syntax error) -->
 
        <!-- Inline CSS: position exit button flush to the right edge of modal header -->
        <style>
            /* Ensure modal header is positioned so absolute children can align to right */
            .modal-header { position: relative; }
            /* Place .exit-btn exactly at the right edge of the header */
            .modal-header .exit-btn {
                position: absolute;
                right: 0.5rem; /* adjust to 0 or 0.25rem to move further right */
                top: 40%;
                transform: translateY(-50%);
                z-index: 1055;
            }
            /* Unit column: prevent wrapping for tire sizes like 70/90-17 and slightly reduce font-size to fit */
            .table td.unit-col,
            .table th.unit-col {
                white-space: nowrap;
                min-width: 110px;
                font-size: 0.95rem;
            }
            /* Allow wrapping on very small screens to avoid horizontal overflow */
            @media (max-width: 768px) {
                .table td.unit-col,
                .table th.unit-col {
                    white-space: normal;
                }
            }
        </style>


        <!-- Client-side: show/hide Size field and validate tire size format -->
        <script>
            (function($){
                // Regexes for tire sizes
                var tireSizeRegexes = [
                    /^\d{2,3}\/\d{2,3}[A-Za-z]*-?\d{2,3}$/, // 120/70-17 or 120/70ZR17
                    /^\d+(?:\.\d+)?-\d{2,3}$/, // 3.50-10
                    /^\d{1,3}(?:x\d+(?:\.\d+)?)?$/ // 17 or 17x7.5
                ];

                function isTireCategory(selectEl) {
                    if (!selectEl || !selectEl.options) return false;
                    var opt = selectEl.options[selectEl.selectedIndex];
                    if (!opt) return false;
                    return opt.text.trim().toLowerCase() === 'tires' || String(selectEl.value).trim().toLowerCase() === 'tires';
                }

                function validateTireSize(val) {
                    if (!val) return false;
                    val = val.trim();
                    for (var i=0;i<tireSizeRegexes.length;i++) {
                        if (tireSizeRegexes[i].test(val)) return true;
                    }
                    return false;
                }

                function toggleSizeField($form) {
                    var $cat = $form.find('select[name="category_id"]');
                    if ($cat.length === 0) {
                        $cat = $form.find('select[name="category"]');
                    }
                    var $wrap = $form.find('.size-field-wrap');
                    var $input = $form.find('input[name="size"]');
                    if ($cat.length === 0 || $wrap.length === 0) return;
                    
                    // Check if category is Tires
                    var selectedText = $cat.find('option:selected').text().toLowerCase();
                    var isTires = selectedText === 'tires';
                    
                    if (isTires) {
                        $wrap.show();
                        $input.prop('required', true);
                        // Enable tire size datalist
                        $input.attr('list', 'tire_size_list');
                        if ($form.attr('id') === 'addProductForm') {
                            $input.attr('list', 'tire_size_list');
                        } else {
                            $input.attr('list', 'tire_size_list_edit');
                        }
                    } else {
                        $wrap.hide();
                        $input.prop('required', false).removeClass('is-invalid').val('');
                        $wrap.find('.invalid-feedback').hide();
                        // Remove datalist for non-tire products
                        $input.removeAttr('list');
                    }
                }

                // Show/hide the unit_size input when unit needs numeric prefix
                function toggleUnitSizeField($form) {
                    var $unit = $form.find('select[name="unit"]');
                    var $uinput = $form.find('input[name="unit_size"]');
                    if ($unit.length === 0 || $uinput.length === 0) return;
                    var val = String($unit.val()).trim();
                    if (['ml', 'L', 'g'].indexOf(val) !== -1) {
                        $uinput.show();
                        $uinput.prop('required', true);
                    } else {
                        $uinput.hide();
                        $uinput.prop('required', false).removeClass('is-invalid').val('');
                    }
                }

                $(document).ready(function(){
                    // When any modal with add/edit forms opens, toggle size and unit-size fields
                    $(document).on('shown.bs.modal', '#addModal, .modal', function(e){
                        var $form = $(this).find('form').first();
                        if ($form.length) {
                            toggleSizeField($form);
                            toggleUnitSizeField($form);
                        }
                    });

                    // On category change inside forms
                    $(document).on('change', 'select[name="category"], select[name="category_id"]', function(){
                        var $form = $(this).closest('form');
                        toggleSizeField($form);
                    });

                    // On unit change inside forms: show/hide unit_size
                    $(document).on('change', 'select[name="unit"]', function(){
                        var $form = $(this).closest('form');
                        toggleUnitSizeField($form);
                    });

                    // On submit validate size if visible
                    $(document).on('submit', '#addProductForm, .editProductForm', function(e){
                        var $form = $(this);
                        var $wrap = $form.find('.size-field-wrap');
                        var $input = $form.find('input[name="size"]');
                        if ($wrap.length && $wrap.is(':visible')) {
                            var val = $input.val();
                            if (!validateTireSize(val)) {
                                e.preventDefault();
                                $input.addClass('is-invalid');
                                $wrap.find('.invalid-feedback').text('Please enter a valid tire size (e.g. 120/70-17 or 3.50-10).').show();
                                return false;
                            } else {
                                $input.removeClass('is-invalid');
                                $wrap.find('.invalid-feedback').hide();
                            }
                        }
                        return true;
                    });

                    // Initial toggle for any forms present on page load (for edit forms)
                    $('form.editProductForm').each(function(){ toggleSizeField($(this)); toggleUnitSizeField($(this)); });
                    // Also initialize add form if present
                    var $addForm = $('#addProductForm');
                    if ($addForm.length) { toggleSizeField($addForm); toggleUnitSizeField($addForm); }
                });
            })(jQuery);
            
            // Bulk Delete Functionality
            function selectAllProducts() {
                var checkboxes = document.querySelectorAll('.product-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.checked = true;
                });
                var selectAll = document.getElementById('selectAllCheckbox');
                if (selectAll) selectAll.checked = true;
                updateSelectedCount();
            }
            
            function deselectAllProducts() {
                var checkboxes = document.querySelectorAll('.product-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.checked = false;
                });
                var selectAll = document.getElementById('selectAllCheckbox');
                if (selectAll) selectAll.checked = false;
                updateSelectedCount();
            }
            
            function toggleAllProducts(checkbox) {
                var checkboxes = document.querySelectorAll('.product-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.checked = checkbox.checked;
                });
                updateSelectedCount();
            }
            
            function updateSelectedCount() {
                var checkboxes = document.querySelectorAll('.product-checkbox:checked');
                var count = checkboxes.length;
                var countElement = document.getElementById('selectedCount');
                if (countElement) {
                    countElement.textContent = count + ' selected';
                    if (count > 0) {
                        countElement.classList.remove('text-muted');
                        countElement.classList.add('text-danger', 'fw-bold');
                    } else {
                        countElement.classList.add('text-muted');
                        countElement.classList.remove('text-danger', 'fw-bold');
                    }
                }
            }
            
            // Initialize bulk delete functionality
            document.addEventListener('DOMContentLoaded', function() {
                var checkboxes = document.querySelectorAll('.product-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.addEventListener('change', updateSelectedCount);
                });
                updateSelectedCount();
            });
            
            // Autocomplete Search Functionality
            (function() {
                var searchInput, searchHidden, filterForm, suggestionsDiv;
                var searchTimeout;
                var selectedIndex = -1;
                var allSuggestions = [];
                var uniqueCategories = [];
                var hideTimeout;
                
                function initSearchAutocomplete() {
                    searchInput = document.getElementById('liveSearchInput');
                    searchHidden = document.getElementById('searchHidden');
                    filterForm = document.getElementById('filterForm');
                    suggestionsDiv = document.getElementById('searchSuggestions');
                    
                    if (!searchInput || !searchHidden || !filterForm || !suggestionsDiv) {
                        console.log('Search elements not found, retrying...', {
                            searchInput: !!searchInput,
                            searchHidden: !!searchHidden,
                            filterForm: !!filterForm,
                            suggestionsDiv: !!suggestionsDiv
                        });
                        setTimeout(initSearchAutocomplete, 100);
                        return;
                    }
                    
                    console.log('✓ Search autocomplete initializing...');
                    console.log('✓ All elements found and accessible');
                    
                    // Extract all product data from table for autocomplete
                    allSuggestions = extractProductData();
                    console.log('Extracted products:', allSuggestions.length);
                    
                    // Get categories from PHP (more reliable than extracting from table)
                    uniqueCategories = typeof phpCategories !== 'undefined' ? phpCategories : [];
                    console.log('Available categories from PHP:', uniqueCategories.length);
                    
                    // Also extract categories from products as fallback
                    var categoryMap = {};
                    allSuggestions.forEach(function(product) {
                        if (product.category && !categoryMap[product.category]) {
                            categoryMap[product.category] = true;
                            if (uniqueCategories.indexOf(product.category) === -1) {
                                uniqueCategories.push(product.category);
                            }
                        }
                    });
                    uniqueCategories.sort();
                    console.log('Total unique categories:', uniqueCategories.length, uniqueCategories);
                    
                    // Attach event listeners (will be attached after handleInput is defined)
                    attachEventListeners();
                }
                
                // Extract all product data from table for autocomplete
                function extractProductData() {
                    var tbody = document.querySelector('.table-responsive table tbody');
                    if (!tbody) {
                        tbody = document.querySelector('table tbody');
                    }
                    if (!tbody) {
                        console.log('Table tbody not found');
                        return [];
                    }
                    
                    var rows = Array.from(tbody.querySelectorAll('tr'));
                    var products = [];
                    var hasCheckbox = false;
                    
                    // Check if first row has checkbox (for discontinued products view)
                    if (rows.length > 0) {
                        var firstRowCells = rows[0].querySelectorAll('td');
                        if (firstRowCells.length > 0 && firstRowCells[0].querySelector('input[type="checkbox"]')) {
                            hasCheckbox = true;
                        }
                    }
                    
                    rows.forEach(function(row) {
                        // Skip header rows
                        if (row.classList.contains('table-dark') || row.querySelector('th')) {
                            return;
                        }
                        
                        var cells = row.querySelectorAll('td');
                        if (cells.length < 5) return;
                        
                        // Adjust index if checkbox column exists
                        var startIdx = hasCheckbox ? 1 : 0;
                        
                        var product = {
                            code: (cells[startIdx]?.textContent || '').trim(),
                            name: (cells[startIdx + 1]?.textContent || '').trim(),
                            category: (cells[startIdx + 2]?.textContent || '').trim(),
                            brand: (cells[startIdx + 3]?.textContent || '').trim(),
                            model: (cells[startIdx + 4]?.textContent || '').trim(),
                            row: row
                        };
                        
                        // Skip if name is empty (invalid row)
                        if (!product.name) return;
                        
                        // Create searchable text
                        product.searchText = (product.code + ' ' + product.name + ' ' + product.category + ' ' + product.brand + ' ' + product.model).toLowerCase();
                        product.displayText = product.name + (product.code ? ' (' + product.code + ')' : '') + ' - ' + product.category;
                        
                        products.push(product);
                    });
                    
                    console.log('Extracted ' + products.length + ' products');
                    return products;
                }
                
                // Handle input function
                function handleInput(e) {
                    var query = searchInput.value.trim();
                    console.log('Input detected, query:', query);
                    searchHidden.value = query;
                    
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        console.log('Processing query:', query);
                        if (query === '') {
                            suggestionsDiv.style.display = 'none';
                            // Show all rows
                            allSuggestions.forEach(function(product) {
                                if (product.row) product.row.style.display = '';
                            });
                        } else {
                            var suggestions = getSuggestions(query);
                            console.log('Query:', query, 'Suggestions found:', suggestions.length);
                            if (suggestions.length > 0) {
                                showSuggestions(suggestions);
                            } else {
                                suggestionsDiv.style.display = 'none';
                            }
                            filterTable(query);
                        }
                        selectedIndex = -1;
                    }, 100);
                }
                
                // Filter suggestions based on query
                function getSuggestions(query) {
                    if (!query || query.trim() === '') {
                        return [];
                    }
                    
                    var queryLower = query.toLowerCase().trim();
                    var categoryMatches = [];
                    var productMatches = [];
                    
                    // First, check for category matches
                    uniqueCategories.forEach(function(category) {
                        if (category.toLowerCase().includes(queryLower)) {
                            categoryMatches.push({
                                type: 'category',
                                text: category,
                                displayText: category
                            });
                        }
                    });
                    
                    // Then check product matches
                    allSuggestions.forEach(function(product) {
                        if (product.searchText.includes(queryLower)) {
                            productMatches.push(product);
                        }
                    });
                    
                    // Sort product matches by relevance
                    productMatches.sort(function(a, b) {
                        var aStarts = a.name.toLowerCase().startsWith(queryLower);
                        var bStarts = b.name.toLowerCase().startsWith(queryLower);
                        if (aStarts && !bStarts) return -1;
                        if (!aStarts && bStarts) return 1;
                        return a.name.localeCompare(b.name);
                    });
                    
                    // Prioritize category matches, then show top products
                    var results = categoryMatches.slice(0, 5).concat(productMatches.slice(0, 5));
                    return results.slice(0, 10); // Limit to 10 suggestions total
                }
                
                // Display suggestions
                function showSuggestions(suggestions) {
                    console.log('showSuggestions called with', suggestions.length, 'suggestions');
                    if (suggestions.length === 0) {
                        console.log('No suggestions to show, hiding div');
                        suggestionsDiv.style.display = 'none';
                        return;
                    }
                    
                    console.log('Showing suggestions:', suggestions);
                    suggestionsDiv.innerHTML = '';
                    
                    // Add tooltip arrow pointing up
                    var arrow = document.createElement('div');
                    arrow.style.cssText = 'position: absolute; top: -8px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 8px solid transparent; border-right: 8px solid transparent; border-bottom: 8px solid #2d3748; z-index: 1;';
                    suggestionsDiv.appendChild(arrow);
                    
                    suggestions.forEach(function(suggestion, index) {
                        var item = document.createElement('div');
                        item.className = 'search-suggestion-item' + (index === selectedIndex ? ' active' : '');
                        item.style.cssText = 'padding: 12px 16px; cursor: pointer; color: #ffffff; font-size: 0.95rem; transition: background-color 0.15s ease; border-bottom: 1px solid rgba(255,255,255,0.1); position: relative;';
                        
                        if (suggestion.type === 'category') {
                            // Category suggestion - simple and clean (like "Brake System")
                            item.innerHTML = '<div style="font-weight: 500; color: #ffffff;">' + escapeHtml(suggestion.displayText) + '</div>';
                        } else {
                            // Product suggestion - show details
                            item.innerHTML = '<div style="font-weight: 500; margin-bottom: 4px; color: #ffffff;">' + escapeHtml(suggestion.name) + '</div>' +
                                           '<div style="font-size: 0.85rem; color: #cbd5e0;">' + escapeHtml(suggestion.category) + 
                                           (suggestion.brand ? ' • ' + escapeHtml(suggestion.brand) : '') + '</div>';
                        }
                        
                        item.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            clearTimeout(hideTimeout);
                            selectSuggestion(suggestion);
                        });
                        
                        item.addEventListener('mouseenter', function() {
                            selectedIndex = index;
                            updateSuggestionsDisplay();
                        });
                        
                        suggestionsDiv.appendChild(item);
                    });
                    
                    // Remove border from last item
                    var lastItem = suggestionsDiv.querySelector('.search-suggestion-item:last-child');
                    if (lastItem) {
                        lastItem.style.borderBottom = 'none';
                    }
                    
                    suggestionsDiv.style.display = 'block';
                    suggestionsDiv.style.visibility = 'visible';
                    suggestionsDiv.style.opacity = '1';
                    console.log('✓ Suggestions div displayed, items:', suggestionsDiv.querySelectorAll('.search-suggestion-item').length);
                }
                
                function escapeHtml(text) {
                    var div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
                
                function updateSuggestionsDisplay() {
                    var items = suggestionsDiv.querySelectorAll('.search-suggestion-item');
                    items.forEach(function(item, index) {
                        if (index === selectedIndex) {
                            item.classList.add('active');
                            item.style.backgroundColor = '#4a5568';
                        } else {
                            item.classList.remove('active');
                            item.style.backgroundColor = 'transparent';
                        }
                    });
                }
                
                // Add CSS for hover effect
                var style = document.createElement('style');
                style.textContent = `
                    .search-suggestion-item:hover {
                        background-color: #4a5568 !important;
                    }
                    #searchSuggestions {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    }
                `;
                document.head.appendChild(style);
                
                // Select a suggestion
                function selectSuggestion(suggestion) {
                    if (suggestion.type === 'category') {
                        // If category selected, set search to category name
                        searchInput.value = suggestion.text;
                        searchHidden.value = suggestion.text;
                    } else {
                        // If product selected, set search to product name
                        searchInput.value = suggestion.name;
                        searchHidden.value = suggestion.name;
                    }
                    suggestionsDiv.style.display = 'none';
                    selectedIndex = -1;
                    
                    // Filter table based on selection
                    if (suggestion.type === 'category') {
                        filterTable(suggestion.text);
                    } else {
                        filterTable(suggestion.name);
                    }
                }
                
                // Filter table based on search
                function filterTable(query) {
                    var queryLower = query.toLowerCase().trim();
                    allSuggestions.forEach(function(product) {
                        if (query === '' || product.searchText.includes(queryLower)) {
                            product.row.style.display = '';
                        } else {
                            product.row.style.display = 'none';
                        }
                    });
                }
                
                // Attach event listeners function
                function attachEventListeners() {
                    if (searchInput) {
                        searchInput.addEventListener('input', handleInput);
                        searchInput.addEventListener('keyup', handleInput);
                        searchInput.addEventListener('focus', function() {
                            var query = this.value.trim();
                            if (query !== '') {
                                var suggestions = getSuggestions(query);
                                showSuggestions(suggestions);
                            }
                        });
                        console.log('✓ Event listeners attached');
                    }
                }
                
                // Handle keyboard navigation
                searchInput.addEventListener('keydown', function(e) {
                    var items = suggestionsDiv.querySelectorAll('.search-suggestion-item');
                    
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSuggestionsDisplay();
                        if (items[selectedIndex]) {
                            items[selectedIndex].scrollIntoView({ block: 'nearest' });
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSuggestionsDisplay();
                    } else if (e.key === 'Enter' && selectedIndex >= 0 && items[selectedIndex]) {
                        e.preventDefault();
                        items[selectedIndex].click();
                    } else if (e.key === 'Escape') {
                        suggestionsDiv.style.display = 'none';
                        selectedIndex = -1;
                    }
                });
                
                // Hide suggestions when clicking outside
                document.addEventListener('mousedown', function(e) {
                    if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                        clearTimeout(hideTimeout);
                        hideTimeout = setTimeout(function() {
                            suggestionsDiv.style.display = 'none';
                            selectedIndex = -1;
                        }, 150);
                    }
                });
                
                    // Sync search value before form submission
                    filterForm.addEventListener('submit', function(e) {
                        var searchValue = searchInput.value.trim();
                        searchHidden.value = searchValue;
                        suggestionsDiv.style.display = 'none';
                    });
                }
                
                // Initialize immediately and also on DOM ready
                console.log('Attempting to initialize search autocomplete...');
                
                // Try immediately first
                try {
                    initSearchAutocomplete();
                } catch (e) {
                    console.error('Immediate init failed:', e);
                }
                
                // Also try on DOM ready
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        console.log('DOMContentLoaded fired, initializing...');
                        try {
                            initSearchAutocomplete();
                        } catch (e) {
                            console.error('DOMContentLoaded init failed:', e);
                        }
                    });
                }
                
                // Backup: try after a short delay
                setTimeout(function() {
                    console.log('Delayed init attempt...');
                    try {
                        initSearchAutocomplete();
                    } catch (e) {
                        console.error('Delayed init failed:', e);
                    }
                }, 500);
            })();
            
            // Model Filtering Based on Brand Selection (Updated for input fields)
            (function() {
                function filterModels(brandHiddenId, modelInputId) {
                    var brandHidden = document.getElementById(brandHiddenId);
                    var modelInput = document.getElementById(modelInputId);
                    
                    if (!brandHidden || !modelInput) return;
                    
                    var modelDatalistId = modelInput.getAttribute('list');
                    var modelDatalist = modelDatalistId ? document.getElementById(modelDatalistId) : null;
                    
                    if (!modelDatalist) return;
                    
                    // Function to update model visibility
                    function updateModelVisibility() {
                        var selectedBrandId = brandHidden.value;
                        var options = modelDatalist.querySelectorAll('option');
                        
                        // Show/hide model options based on brand
                        options.forEach(function(option) {
                            var optionBrandId = option.getAttribute('data-brand-id');
                            // Convert to numbers for comparison to handle string vs integer mismatch
                            var selectedId = selectedBrandId === '' ? null : parseInt(selectedBrandId);
                            var optionId = optionBrandId ? parseInt(optionBrandId) : null;
                            
                            if (selectedId === null || selectedId === optionId) {
                                option.style.display = '';
                            } else {
                                option.style.display = 'none';
                            }
                        });
                    }
                    
                    // Initialize visibility on page load
                    updateModelVisibility();
                    
                    // Update visibility when brand changes (listen to input field changes)
                    var brandInput = brandHidden.parentElement.querySelector('input[data-type="brand"]');
                    var lastBrandId = brandHidden.value;
                    if (brandInput) {
                        brandInput.addEventListener('input', function() {
                            // Check if brand was selected from datalist
                            setTimeout(function() {
                                var currentBrandId = brandHidden.value;
                                updateModelVisibility();
                                // Clear model if brand changed
                                if (currentBrandId !== lastBrandId) {
                                    modelInput.value = '';
                                    var modelHidden = modelInput.parentElement.querySelector('input[type="hidden"][name*="model"]');
                                    if (modelHidden) modelHidden.value = '';
                                    lastBrandId = currentBrandId;
                                }
                            }, 100);
                        });
                    }
                }
                
                // Initialize for Add modal when modal is shown
                var addModal = document.getElementById('addModal');
                if (addModal) {
                    addModal.addEventListener('shown.bs.modal', function() {
                        setTimeout(function() {
                            filterModels('add_brand_id', 'add_model_input');
                        }, 100);
                    });
                }
                
                // Also initialize immediately if modal is already open
                setTimeout(function() {
                    filterModels('add_brand_id', 'add_model_input');
                }, 500);
                
                // Initialize for Edit modals (they have dynamic structure)
                document.addEventListener('input', function(e) {
                    if (e.target.matches('input[data-type="brand"]')) {
                        var form = e.target.closest('form');
                        if (form) {
                            var modelInput = form.querySelector('input[data-type="model"]');
                            var modelDatalistId = modelInput ? modelInput.getAttribute('list') : null;
                            var modelDatalist = modelDatalistId ? document.getElementById(modelDatalistId) : null;
                            
                            if (modelInput && modelDatalist) {
                                var brandHidden = e.target.parentElement.querySelector('input[type="hidden"][name*="brand"]');
                                var selectedBrandId = brandHidden ? brandHidden.value : '';
                                var options = modelDatalist.querySelectorAll('option');
                                
                                // Clear model input
                                modelInput.value = '';
                                var modelHidden = modelInput.parentElement.querySelector('input[type="hidden"][name*="model"]');
                                if (modelHidden) modelHidden.value = '';
                                
                                // Filter options
                                options.forEach(function(option) {
                                    var optionBrandId = option.getAttribute('data-brand-id');
                                    if (selectedBrandId === '' || optionBrandId === selectedBrandId) {
                                        option.style.display = '';
                                    } else {
                                        option.style.display = 'none';
                                    }
                                });
                            }
                        }
                    }
                });
            })();
        </script>

        <?php
// Initialize session variables for notifications
if (!isset($_SESSION)) session_start();

// Backend logic for adding new category
if (isset($_POST['addCategory'])) {
        require_once '../../includes/db_connection.php'; // Adjust path if needed
        $categoryName = trim($_POST['categoryName']);
        if (!empty($categoryName)) {
                // Check if category already exists
                $stmt = $conn->prepare("SELECT id FROM category WHERE name = ?");
                $stmt->bind_param("s", $categoryName);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows == 0) {
                        // Insert new category
                        $stmt = $conn->prepare("INSERT INTO category (name) VALUES (?)");
                        $stmt->bind_param("s", $categoryName);
                        if ($stmt->execute()) {
                                $_SESSION['notification'] = ['type' => 'success', 'message' => 'Category added successfully.'];
                        } else {
                                $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error adding category.'];
                        }
                } else {
                        $_SESSION['notification'] = ['type' => 'warning', 'message' => 'Category already exists.'];
                }
                $stmt->close();
                $conn->close();
                // Redirect to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
        }
}
// Backend logic for deleting category
if (isset($_POST['deleteCategory']) && isset($_POST['deleteCategoryId'])) {
    require_once '../../includes/db_connection.php';
    $deleteId = intval($_POST['deleteCategoryId']);
    // Optionally: Check if category is used in products before deleting
    $stmt = $conn->prepare("DELETE FROM category WHERE id = ?");
    $stmt->bind_param("i", $deleteId);
    if ($stmt->execute()) {
        $_SESSION['notification'] = ['type' => 'success', 'message' => 'Category deleted successfully.'];
    } else {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Error deleting category.'];
    }
    $stmt->close();
    $conn->close();
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>

<?php
// Display notification if exists
if (isset($_SESSION['notification'])) {
    $notification = $_SESSION['notification'];
    $alertClass = match($notification['type']) {
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        default => 'alert-info'
    };
    $iconClass = match($notification['type']) {
        'success' => 'bi-check-circle',
        'error' => 'bi-exclamation-circle',
        'warning' => 'bi-exclamation-triangle',
        default => 'bi-info-circle'
    };
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const toastContainer = document.querySelector(".toast-container");
            const toastId = "toast-" + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="toast align-items-center text-white bg-' . substr($alertClass, 6) . ' border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi ' . $iconClass . ' me-2"></i>' . htmlspecialchars($notification['message']) . '
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            toastContainer.innerHTML = toastHtml;
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: 5000
            });
            toast.show();
        });
    </script>';
    unset($_SESSION['notification']);
}
?>

</body>
</html>