<?php
/**
 * Audit Activity Log Page
 * 
 * Displays audit logs grouped by user (cashiers/admins) with filtering capabilities.
 * Admins can view, filter, and delete audit log entries.
 */

// ============================================================================
// INCLUDES & AUTHENTICATION
// ============================================================================
include '../../includes/session.php';
include '../../config.php';
include '../../includes/auth.php';
allow_roles(['admin']);

// ============================================================================
// DELETE LOG ENTRY HANDLER
// ============================================================================
$delete_success = '';
$delete_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log_id'])) {
    $delete_id = intval($_POST['delete_log_id']);
    $stmt = $conn->prepare("DELETE FROM audit_logs WHERE id = ?");
    $stmt->bind_param('i', $delete_id);
    
    if ($stmt->execute()) {
        $delete_success = 'Log entry deleted successfully!';
    } else {
        $delete_error = 'Error deleting log entry.';
    }
    
    $stmt->close();
}

// ============================================================================
// FILTER PROCESSING
// ============================================================================

// Get filter values from GET parameters
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'cashier'; // Default to cashiers
$keyword_filter = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : '';

// Handle quick date navigation buttons
if (isset($_GET['quick_date'])) {
    switch ($_GET['quick_date']) {
        case 'today':
            $selected_date = date('Y-m-d');
            break;
        case 'yesterday':
            $selected_date = date('Y-m-d', strtotime('-1 day'));
            break;
    }
}

// Default to today if no date is selected
if (!$selected_date) {
    $selected_date = date('Y-m-d');
}

// ============================================================================
// QUERY BUILDING
// ============================================================================

$where_conditions = [];
$query_params = [];
$param_types = '';

// Build role filter condition
if ($role_filter) {
    if (strpos($role_filter, 'admin_') === 0) {
        // Filter by specific admin user
        $username = substr($role_filter, 6); // Remove 'admin_' prefix
        $where_conditions[] = 'al.role = ? AND u.username = ?';
        $query_params[] = 'admin';
        $query_params[] = $username;
        $param_types .= 'ss';
    } elseif (strpos($role_filter, 'cashier_') === 0) {
        // Filter by specific cashier user
        $username = substr($role_filter, 8); // Remove 'cashier_' prefix
        $where_conditions[] = 'al.role = ? AND u.username = ?';
        $query_params[] = 'cashier';
        $query_params[] = $username;
        $param_types .= 'ss';
    } else {
        // Filter by role only (all cashiers or all admins)
        $where_conditions[] = 'al.role = ?';
        $query_params[] = $role_filter;
        $param_types .= 's';
    }
}

// Build keyword search filter
if ($keyword_filter) {
    $where_conditions[] = '(al.action LIKE ? OR al.details LIKE ?)';
    $query_params[] = "%$keyword_filter%";
    $query_params[] = "%$keyword_filter%";
    $param_types .= 'ss';
}

// Always filter by the selected date
$where_conditions[] = 'DATE(al.log_time) = ?';
$query_params[] = $selected_date;
$param_types .= 's';

// Build WHERE clause
$where_sql = $where_conditions ? ('WHERE ' . implode(' AND ', $where_conditions)) : '';

// ============================================================================
// FETCH AUDIT LOGS
// ============================================================================

$sql = "SELECT al.*, u.username, u.role as user_role 
        FROM audit_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        $where_sql 
        ORDER BY al.log_time DESC 
        LIMIT 200";

$stmt = $conn->prepare($sql);
if ($query_params) {
    $stmt->bind_param($param_types, ...$query_params);
}
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();

// ============================================================================
// GROUP LOGS BY USER
// ============================================================================

/**
 * Groups logs by username for better visual organization
 * Each user gets their own section with all their activities
 */
$grouped_logs = [];

foreach ($logs as $log) {
    $username = $log['username'] ?? 'Unknown';
    
    // Initialize user group if it doesn't exist
    if (!isset($grouped_logs[$username])) {
        $grouped_logs[$username] = [
            'role' => $log['role'] ?? 'unknown',
            'logs' => []
        ];
    }
    
    $grouped_logs[$username]['logs'][] = $log;
}

/**
 * Sort grouped logs: cashiers first (alphabetically), then admins
 * This makes it easier to find specific cashiers
 */
uksort($grouped_logs, function($username_a, $username_b) use ($grouped_logs) {
    $role_a = $grouped_logs[$username_a]['role'];
    $role_b = $grouped_logs[$username_b]['role'];
    
    // If same role, sort alphabetically by username
    if ($role_a === $role_b) {
        return strcmp($username_a, $username_b);
    }
    
    // Cashiers always come before admins
    if ($role_a === 'cashier' && $role_b !== 'cashier') return -1;
    if ($role_a !== 'cashier' && $role_b === 'cashier') return 1;
    
    return strcmp($username_a, $username_b);
});

// ============================================================================
// GET ROLE OPTIONS FOR FILTER DROPDOWN
// ============================================================================

/**
 * Fetches all available roles and usernames for the filter dropdown
 * This allows admins to filter by specific users
 */
$role_options = ['admin' => [], 'cashier' => []];

$role_query = "SELECT DISTINCT al.role, u.username 
               FROM audit_logs al 
               LEFT JOIN users u ON al.user_id = u.id 
               WHERE al.role IS NOT NULL AND al.role != ''
               ORDER BY al.role, u.username";

$role_result = $conn->query($role_query);

while ($row = $role_result->fetch_assoc()) {
    $role = $row['role'];
    $username = $row['username'];
    
    // Only process admin and cashier roles
    if (!in_array($role, ['admin', 'cashier'])) continue;
    
    // Add username to role options if not already present
    if ($username && !in_array($username, $role_options[$role])) {
        $role_options[$role][] = $username;
    }
}

// ============================================================================
// HTML OUTPUT
// ============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System - Audit Activity Log</title>
    
    <!-- Local Assets -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
</head>
<body style="margin: 0 !important; padding: 0 !important;">
<?php include '../../includes/admin_sidebar.php'; ?>

<div class="container-fluid" style="margin-left: 250px !important; margin-top: 0 !important; padding: 0.5rem !important; padding-top: 0 !important; background: #f8fafc !important; min-height: 100vh !important; position: relative !important; z-index: 1 !important;">
    <div class="main-content" style="position: relative !important; z-index: 2 !important; margin-top: 0 !important; padding-top: 0.5rem !important;">
        
        <!-- ====================================================================
             TOP HEADER AREA
             ==================================================================== -->
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
        
        <!-- ====================================================================
             PAGE TITLE
             ==================================================================== -->
        <h2 class="mb-4" style="color: #1a202c !important; font-size: 1.75rem;">
            <i class="bi bi-clipboard-data me-2"></i>Audit / Activity Logs
        </h2>
        
        <!-- ====================================================================
             DATE INFORMATION ALERT
             ==================================================================== -->
        <div class="alert alert-info mb-3">
            <i class="bi bi-calendar-day me-2"></i>
            Showing logs for <strong><?= date('M d, Y', strtotime($selected_date)) ?></strong>
            <?php if ($selected_date === date('Y-m-d')): ?>
                (Today)
            <?php elseif ($selected_date === date('Y-m-d', strtotime('-1 day'))): ?>
                (Yesterday)
            <?php endif; ?>
        </div>
        
        <!-- ====================================================================
             MAIN CONTENT CARD
             ==================================================================== -->
        <div class="content-card" style="background: #ffffff !important; border-radius: 12px !important; padding: 1.5rem !important; margin-bottom: 1.5rem !important; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important; position: relative !important; z-index: 3 !important;">
            
            <!-- Quick Date Navigation Buttons -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="fw-bold me-2">Quick View:</span>
                        
                        <!-- Today Button -->
                        <a href="?quick_date=today<?= $role_filter ? '&role=' . urlencode($role_filter) : '' ?><?= $keyword_filter ? '&keyword=' . urlencode($keyword_filter) : '' ?>" 
                           class="btn btn-sm <?= $selected_date === date('Y-m-d') ? 'btn-primary' : 'btn-outline-primary' ?>">
                            <i class="bi bi-calendar-day me-1"></i>Today
                        </a>
                        
                        <!-- Yesterday Button -->
                        <a href="?quick_date=yesterday<?= $role_filter ? '&role=' . urlencode($role_filter) : '' ?><?= $keyword_filter ? '&keyword=' . urlencode($keyword_filter) : '' ?>" 
                           class="btn btn-sm <?= $selected_date === date('Y-m-d', strtotime('-1 day')) ? 'btn-primary' : 'btn-outline-primary' ?>">
                            <i class="bi bi-calendar-minus me-1"></i>Yesterday
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- ================================================================
                 FILTERS FORM
                 ================================================================ -->
            <form method="get" class="row g-2 mb-3 align-items-end">
                <!-- User Role Filter -->
                <div class="col-auto">
                    <label for="role" class="form-label mb-0">User Role:</label>
                    <select name="role" id="role" class="form-select">
                        <option value="cashier" <?= $role_filter === 'cashier' ? 'selected' : '' ?>>All Cashiers</option>
                        
                        <?php if (!empty($role_options['admin'])): ?>
                            <optgroup label="Specific Admins">
                                <?php foreach ($role_options['admin'] as $admin): ?>
                                    <option value="admin_<?= htmlspecialchars($admin) ?>" 
                                            <?= $role_filter === "admin_$admin" ? 'selected' : '' ?>>
                                        Admin (<?= htmlspecialchars($admin) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        
                        <?php if (!empty($role_options['cashier'])): ?>
                            <optgroup label="Specific Cashiers">
                                <?php foreach ($role_options['cashier'] as $cashier): ?>
                                    <option value="cashier_<?= htmlspecialchars($cashier) ?>" 
                                            <?= $role_filter === "cashier_$cashier" ? 'selected' : '' ?>>
                                        Cashier (<?= htmlspecialchars($cashier) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                
                <!-- Date Filter -->
                <div class="col-auto">
                    <label for="date" class="form-label mb-0">View Date:</label>
                    <input type="date" name="date" id="date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>">
                </div>
                
                <!-- Keyword Search Filter -->
                <div class="col-auto">
                    <label for="keyword" class="form-label mb-0">Action Keyword:</label>
                    <input type="text" name="keyword" id="keyword" class="form-control" 
                           placeholder="Search action or details" 
                           value="<?= htmlspecialchars($keyword_filter) ?>">
                </div>
                
                <!-- Filter Submit Button -->
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
            
            <!-- ================================================================
                 SUCCESS/ERROR MESSAGES
                 ================================================================ -->
            <?php if ($delete_success): ?>
                <div class="alert alert-success mb-3">
                    <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($delete_success) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($delete_error): ?>
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-x-circle-fill me-1"></i> <?= htmlspecialchars($delete_error) ?>
                </div>
            <?php endif; ?>
            
            <!-- ================================================================
                 AUDIT LOGS DISPLAY
                 ================================================================ -->
            <?php if (empty($grouped_logs)): ?>
                <!-- No Logs Found Message -->
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle me-2"></i>No logs found for the selected filters.
                </div>
            <?php else: ?>
                <!-- Display grouped logs by user -->
                <?php 
                $user_index = 0;
                $total_users = count($grouped_logs);
                
                foreach ($grouped_logs as $username => $user_data): 
                    $user_index++;
                    $is_cashier = $user_data['role'] === 'cashier';
                    
                    // Set styling based on role (both use blue to match filter button)
                    $badge_class = 'bg-primary';
                    $border_color = '#3b82f6';
                    $gradient_end = '#2563eb';
                ?>
                    <!-- User Activity Section -->
                    <div class="user-activity-section mb-4" 
                         style="border: 2px solid <?= $border_color ?>; border-radius: 12px; overflow: hidden; background: #ffffff;">
                        
                        <!-- User Header with Gradient Background -->
                        <div class="user-header p-3" 
                             style="background: linear-gradient(135deg, <?= $border_color ?> 0%, <?= $gradient_end ?> 100%); color: white;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-3">
                                    <!-- User Avatar Icon -->
                                    <div style="width: 45px; height: 45px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-person-circle" style="font-size: 1.5rem;"></i>
                                    </div>
                                    
                                    <!-- User Information -->
                                    <div>
                                        <h4 class="mb-0" style="font-weight: 600; font-size: 1.1rem;">
                                            <?= htmlspecialchars(ucfirst($username)) ?>
                                        </h4>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <!-- Role Badge -->
                                            <span class="badge <?= $badge_class ?>" style="font-size: 0.75rem;">
                                                <?= htmlspecialchars(ucfirst($user_data['role'])) ?>
                                            </span>
                                            <!-- Activity Count -->
                                            <span style="font-size: 0.85rem; opacity: 0.9;">
                                                <i class="bi bi-list-ul me-1"></i>
                                                <?= count($user_data['logs']) ?> activit<?= count($user_data['logs']) === 1 ? 'y' : 'ies' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Activity Table -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead style="background-color: #f8fafc;">
                                    <tr>
                                        <th style="border-top: none;">Date/Time</th>
                                        <th style="border-top: none;">Action</th>
                                        <th style="border-top: none;">Details</th>
                                        <th style="border-top: none; width: 100px;">Delete</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_data['logs'] as $log): ?>
                                        <tr>
                                            <!-- Date/Time Column -->
                                            <td style="font-weight: 500; color: #475569;">
                                                <i class="bi bi-clock me-1"></i>
                                                <?= date('m-d-Y g:ia', strtotime($log['log_time'])) ?>
                                            </td>
                                            
                                            <!-- Action Column -->
                                            <td>
                                                <span class="badge bg-info text-dark">
                                                    <?= htmlspecialchars($log['action']) ?>
                                                </span>
                                            </td>
                                            
                                            <!-- Details Column -->
                                            <td style="color: #64748b;">
                                                <?= nl2br(htmlspecialchars($log['details'])) ?>
                                            </td>
                                            
                                            <!-- Delete Column -->
                                            <td>
                                                <form method="post" 
                                                      onsubmit="return confirm('Are you sure you want to delete this log entry?');" 
                                                      style="display:inline;">
                                                    <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Separator between users (show if not last user) -->
                    <?php if ($user_index < $total_users): ?>
                        <div class="text-center my-4">
                            <hr style="border: 2px dashed #cbd5e1; margin: 0;">
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<!-- ============================================================================
     JAVASCRIPT
     ============================================================================ -->
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
// Add loaded class to body for any CSS transitions
window.addEventListener('load', function() {
    document.body.classList.add('loaded');
});
</script>
</body>
</html>