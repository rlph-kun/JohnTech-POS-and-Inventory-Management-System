<?php
/**
 * DATABASE STRUCTURE CHECKER
 * 
 * This script checks if all required tables and columns exist
 * for the JohnTech System to function properly.
 */

include 'config.php';

// Required tables for the system
$required_tables = [
    'users' => [
        'required_columns' => ['id', 'username', 'password', 'role', 'password_changed', 'last_login', 'email', 'otp_code', 'otp_expires_at'],
        'description' => 'User accounts (admin and cashier)'
    ],
    'audit_logs' => [
        'required_columns' => ['id', 'user_id', 'role', 'action', 'details', 'log_time'],
        'description' => 'System audit trail'
    ],
    'password_recovery_tokens' => [
        'required_columns' => ['id', 'user_id', 'token', 'expires_at', 'used', 'created_at'],
        'description' => 'Professional recovery system tokens'
    ],
    'admin_recovery_log' => [
        'required_columns' => ['id', 'action', 'details', 'ip_address', 'created_at'],
        'description' => 'Recovery attempt audit log'
    ],
    'products' => [
        'required_columns' => ['id', 'branch_id', 'name', 'category', 'price', 'quantity', 'status'],
        'description' => 'Inventory products (modern branch-based structure)'
    ],
    'sales' => [
        'required_columns' => ['id', 'branch_id', 'cashier_id', 'total_amount', 'created_at'],
        'description' => 'Sales transactions (advanced POS system)'
    ],
    'returns' => [
        'required_columns' => ['id', 'sale_id', 'branch_id', 'cashier_id', 'total_amount', 'reason', 'created_at'],
        'description' => 'Product returns (comprehensive return management)'
    ],
    'cashier_profiles' => [
        'required_columns' => ['id', 'user_id', 'branch_id'],
        'description' => 'Cashier branch assignments'
    ],
    'branches' => [
        'required_columns' => ['id', 'name'],
        'description' => 'System branches'
    ]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Structure Check - JohnTech System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .status-good { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-error { color: #dc3545; }
        .table-card { 
            border: 1px solid #dee2e6; 
            border-radius: 8px; 
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .table-header {
            background: #f8f9fa;
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
            border-radius: 8px 8px 0 0;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h2><i class="fas fa-database me-2"></i>Database Structure Check</h2>
                <p class="text-muted">Verifying all required tables and columns exist for JohnTech System</p>
                <hr>
                
                <?php
                $all_good = true;
                $missing_tables = [];
                $missing_columns = [];
                
                foreach ($required_tables as $table_name => $table_info) {
                    echo "<div class='table-card'>";
                    echo "<div class='table-header'>";
                    
                    // Check if table exists
                    $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
                    
                    if ($table_check->num_rows > 0) {
                        echo "<h5 class='status-good mb-1'><i class='fas fa-check-circle me-2'></i>$table_name</h5>";
                        echo "<small class='text-muted'>{$table_info['description']}</small>";
                        echo "</div>";
                        
                        // Check columns
                        $columns_check = $conn->query("SHOW COLUMNS FROM $table_name");
                        $existing_columns = [];
                        while ($row = $columns_check->fetch_assoc()) {
                            $existing_columns[] = $row['Field'];
                        }
                        
                        echo "<div class='p-3'>";
                        echo "<strong>Columns:</strong><br>";
                        foreach ($table_info['required_columns'] as $required_col) {
                            if (in_array($required_col, $existing_columns)) {
                                echo "<span class='status-good'><i class='fas fa-check me-1'></i>$required_col</span> ";
                            } else {
                                echo "<span class='status-error'><i class='fas fa-times me-1'></i>$required_col (MISSING)</span> ";
                                $missing_columns[] = "$table_name.$required_col";
                                $all_good = false;
                            }
                        }
                        echo "</div>";
                        
                    } else {
                        echo "<h5 class='status-error mb-1'><i class='fas fa-times-circle me-2'></i>$table_name</h5>";
                        echo "<small class='text-muted'>{$table_info['description']} - <strong>TABLE MISSING</strong></small>";
                        echo "</div></div>";
                        $missing_tables[] = $table_name;
                        $all_good = false;
                        continue;
                    }
                    
                    echo "</div>";
                }
                
                // Summary
                echo "<div class='mt-4'>";
                if ($all_good) {
                    echo "<div class='alert alert-success'>";
                    echo "<h4><i class='fas fa-check-circle me-2'></i>Database Structure Complete!</h4>";
                    echo "<p class='mb-0'>All required tables and columns are present. Your system is ready for production deployment.</p>";
                    echo "</div>";
                } else {
                    echo "<div class='alert alert-danger'>";
                    echo "<h4><i class='fas fa-exclamation-triangle me-2'></i>Database Issues Found</h4>";
                    
                    if (!empty($missing_tables)) {
                        echo "<p><strong>Missing Tables:</strong></p>";
                        echo "<ul>";
                        foreach ($missing_tables as $table) {
                            echo "<li>$table</li>";
                        }
                        echo "</ul>";
                    }
                    
                    if (!empty($missing_columns)) {
                        echo "<p><strong>Missing Columns:</strong></p>";
                        echo "<ul>";
                        foreach ($missing_columns as $column) {
                            echo "<li>$column</li>";
                        }
                        echo "</ul>";
                    }
                    
                    echo "<p class='mb-0'><strong>Action Required:</strong> Run the database setup scripts to fix these issues.</p>";
                    echo "</div>";
                    
                    echo "<div class='alert alert-info'>";
                    echo "<h5>Recommended Actions:</h5>";
                    echo "<ol>";
                    echo "<li>Run <code>database_updates.sql</code> to add missing basic columns</li>";
                    echo "<li>Run <code>capstone_recovery_setup.sql</code> to add recovery system tables</li>";
                    echo "<li>Re-run this checker to verify fixes</li>";
                    echo "</ol>";
                    echo "</div>";
                }
                echo "</div>";
                ?>
                
                <div class="mt-4">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i>Return to Login
                    </a>
                    <?php if (!$all_good): ?>
                    <a href="database_updates.sql" class="btn btn-warning" download>
                        <i class="fas fa-download me-2"></i>Download Basic Updates SQL
                    </a>
                    <a href="capstone_recovery_setup.sql" class="btn btn-info" download>
                        <i class="fas fa-download me-2"></i>Download Recovery Setup SQL
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
