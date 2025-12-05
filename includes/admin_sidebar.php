<?php
$current_page = basename($_SERVER['SCRIPT_NAME']);
// Include config if not already included (for BASE_URL)
if (!defined('BASE_URL')) {
    include_once __DIR__ . '/../config.php';
}
?>
<!-- Enhanced Modern Admin Sidebar -->
<aside class="sidebar d-flex flex-column">
    <div class="sidebar-header">
        <img src="<?= BASE_URL ?>/assets/images/johntech.jpg" alt="Logo" class="sidebar-logo">
        <h3 class="sidebar-title">JohnTech</h3>
        <p class="sidebar-subtitle">Management System</p>
    </div>
    
    <nav class="flex-grow-1">
        <div class="sidebar-section">
            <a href="admin_dashboard.php" class="<?php if($current_page == 'admin_dashboard.php') echo 'active'; ?>">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-section-title">Business Operations</div>
            <a href="branch_overview.php" class="<?php if($current_page == 'branch_overview.php') echo 'active'; ?>">
                <i class="bi bi-building"></i>
                Branch Overview
            </a>
            <a href="Inventory_management.php" class="<?php if($current_page == 'Inventory_management.php') echo 'active'; ?>">
                <i class="bi bi-cart-check"></i>
                Product Management
            </a>
            <a href="Sales_history1.php" class="<?php if($current_page == 'Sales_history1.php') echo 'active'; ?>">
                <i class="bi bi-graph-up"></i>
                Sales History
            </a>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-section-title">Staff Management</div>
            <a href="mechanic_management.php" class="<?php if($current_page == 'mechanic_management.php') echo 'active'; ?>">
                <i class="bi bi-wrench"></i>
                Mechanic Management
            </a>
            <a href="cashier_management.php" class="<?php if($current_page == 'cashier_management.php') echo 'active'; ?>">
                <i class="bi bi-person-badge"></i>
                Cashier Management
            </a>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-section-title">System</div>
            <a href="Audit_Activity_log.php" class="<?php if($current_page == 'Audit_Activity_log.php') echo 'active'; ?>">
                <i class="bi bi-shield-check"></i>
                Audit Logs
            </a>
            <a href="Settings.php" class="<?php if($current_page == 'Settings.php') echo 'active'; ?>">
                <i class="bi bi-gear"></i>
                Settings
            </a>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout.php" class="logout-btn">
            <i class="bi bi-box-arrow-right"></i>
            Log Out
        </a>
        <div class="sidebar-copyright">&copy; <?php echo date('Y'); ?> JohnTech System</div>
    </div>
</aside>

