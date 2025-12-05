<?php
/*
======================================================================
  Endpoint: ajax_save_entry.php
  Description: Saves new entries (brands, categories, models, tire sizes, suppliers)
               via AJAX when user types a new value in the form.
  Notes:
  - Used for auto-saving new entries from inventory form
  - Returns JSON with success status and new entry ID
======================================================================
*/

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only allow admin role
allow_roles(['admin']);

$response = ['success' => false, 'error' => '', 'id' => null, 'name' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$type = $_POST['type'] ?? '';
$name = trim($_POST['name'] ?? '');
$brand_id = isset($_POST['brand_id']) ? intval($_POST['brand_id']) : 0; // For models

if (empty($type) || empty($name)) {
    $response['error'] = 'Type and name are required';
    echo json_encode($response);
    exit;
}

try {
    switch ($type) {
        case 'brand':
            // Check if brand already exists
            $stmt = $conn->prepare("SELECT id, status FROM brands WHERE brand_name = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                if ($existing['status'] === 'inactive') {
                    // Reactivate
                    $update_stmt = $conn->prepare("UPDATE brands SET status = 'active' WHERE id = ?");
                    $update_stmt->bind_param("i", $existing['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    $response['success'] = true;
                    $response['id'] = $existing['id'];
                    $response['name'] = $name;
                } else {
                    $response['success'] = true;
                    $response['id'] = $existing['id'];
                    $response['name'] = $name;
                }
            } else {
                // Insert new brand
                $insert_stmt = $conn->prepare("INSERT INTO brands (brand_name, status) VALUES (?, 'active')");
                $insert_stmt->bind_param("s", $name);
                if ($insert_stmt->execute()) {
                    $response['success'] = true;
                    $response['id'] = $insert_stmt->insert_id;
                    $response['name'] = $name;
                } else {
                    $response['error'] = 'Error adding brand: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
            break;
            
        case 'category':
            // Check if category already exists
            $stmt = $conn->prepare("SELECT id FROM categories WHERE category_name = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                $response['success'] = true;
                $response['id'] = $existing['id'];
                $response['name'] = $name;
            } else {
                // Insert new category
                $insert_stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
                $insert_stmt->bind_param("s", $name);
                if ($insert_stmt->execute()) {
                    $response['success'] = true;
                    $response['id'] = $insert_stmt->insert_id;
                    $response['name'] = $name;
                } else {
                    $response['error'] = 'Error adding category: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
            break;
            
        case 'model':
            if ($brand_id <= 0) {
                $response['error'] = 'Brand ID is required for models';
                break;
            }
            
            // Check if model already exists for this brand
            $stmt = $conn->prepare("SELECT id, status FROM models WHERE model_name = ? AND brand_id = ?");
            $stmt->bind_param("si", $name, $brand_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                if ($existing['status'] === 'inactive') {
                    // Reactivate
                    $update_stmt = $conn->prepare("UPDATE models SET status = 'active' WHERE id = ?");
                    $update_stmt->bind_param("i", $existing['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    $response['success'] = true;
                    $response['id'] = $existing['id'];
                    $response['name'] = $name;
                } else {
                    $response['success'] = true;
                    $response['id'] = $existing['id'];
                    $response['name'] = $name;
                }
            } else {
                // Insert new model
                $insert_stmt = $conn->prepare("INSERT INTO models (model_name, brand_id, status) VALUES (?, ?, 'active')");
                $insert_stmt->bind_param("si", $name, $brand_id);
                if ($insert_stmt->execute()) {
                    $response['success'] = true;
                    $response['id'] = $insert_stmt->insert_id;
                    $response['name'] = $name;
                } else {
                    $response['error'] = 'Error adding model: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
            break;
            
        case 'tire_size':
            // Check if tire size already exists
            $stmt = $conn->prepare("SELECT id FROM tire_sizes WHERE size_value = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                $response['success'] = true;
                $response['id'] = $existing['id'];
                $response['name'] = $name;
            } else {
                // Insert new tire size
                $insert_stmt = $conn->prepare("INSERT INTO tire_sizes (size_value) VALUES (?)");
                $insert_stmt->bind_param("s", $name);
                if ($insert_stmt->execute()) {
                    $response['success'] = true;
                    $response['id'] = $insert_stmt->insert_id;
                    $response['name'] = $name;
                } else {
                    $response['error'] = 'Error adding tire size: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
            break;
            
        case 'supplier':
            // Check if supplier already exists
            $stmt = $conn->prepare("SELECT id, status FROM suppliers WHERE supplier_name = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $existing = $result->fetch_assoc();
                if ($existing['status'] === 'inactive') {
                    // Reactivate
                    $update_stmt = $conn->prepare("UPDATE suppliers SET status = 'active' WHERE id = ?");
                    $update_stmt->bind_param("i", $existing['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    $response['success'] = true;
                    $response['id'] = $existing['id'];
                    $response['name'] = $name;
                } else {
                    $response['success'] = true;
                    $response['id'] = $existing['id'];
                    $response['name'] = $name;
                }
            } else {
                // Insert new supplier
                $insert_stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, status) VALUES (?, 'active')");
                $insert_stmt->bind_param("s", $name);
                if ($insert_stmt->execute()) {
                    $response['success'] = true;
                    $response['id'] = $insert_stmt->insert_id;
                    $response['name'] = $name;
                } else {
                    $response['error'] = 'Error adding supplier: ' . $insert_stmt->error;
                }
                $insert_stmt->close();
            }
            $stmt->close();
            break;
            
        default:
            $response['error'] = 'Invalid type';
    }
} catch (Exception $e) {
    $response['error'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
exit;

