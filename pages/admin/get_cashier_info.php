<?php
/*
======================================================================
  Endpoint: get_cashier_info.php
  Description: Returns JSON with cashier account/profile information
               used by the Cashier Management page's "View Info" modal.
  Notes:
  - Output is JSON; no UI changes here.
  - Preserves existing fields and formatting.
======================================================================
*/
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

header('Content-Type: application/json');

// ====================================================================
// Validate input
// ====================================================================
if (!isset($_GET['user_id'])) {
    echo json_encode(['error' => 'User ID required']);
    exit;
}

$user_id = intval($_GET['user_id']);

// ====================================================================
// Fetch cashier details
// ====================================================================
try {
    // Get cashier information
    $stmt = $conn->prepare("
        SELECT u.username, u.last_login, u.created_at, u.last_password_change, u.status,
               cp.name, cp.email, cp.contact, cp.branch_id,
               b.name as branch_name
        FROM users u 
        JOIN cashier_profiles cp ON u.id = cp.user_id 
        LEFT JOIN branches b ON cp.branch_id = b.id
        WHERE u.id = ? AND u.role = 'cashier'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Format status
        if ($row['status'] == 'active') {
            $status = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Active</span>';
        } else {
            $status = '<span class="badge bg-secondary"><i class="bi bi-pause-circle"></i> Inactive</span>';
        }
        
        // Format dates
        $last_login = $row['last_login'] ? date('M d, Y H:i', strtotime($row['last_login'])) : 'Never';
        $last_password_change = $row['last_password_change'] ? date('M d, Y H:i', strtotime($row['last_password_change'])) : 'Never';
        $created_at = date('M d, Y', strtotime($row['created_at']));
        
        echo json_encode([
            'status' => $status,
            'last_login' => $last_login,
            'last_password_change' => $last_password_change,
            'created_at' => $created_at,
            'username' => $row['username'],
            'name' => $row['name'],
            'email' => $row['email'] ?: 'Not provided',
            'contact' => $row['contact'],
            'branch_name' => $row['branch_name']
        ]);
    } else {
        echo json_encode(['error' => 'Cashier not found']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error']);
}
?>
