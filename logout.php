<?php
session_start();
include 'config.php';
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    require_once __DIR__ . '/includes/audit_log.php';
    // Log the logout action
    if ($_SESSION['role'] === 'cashier') {
        log_audit($conn, $_SESSION['user_id'], $_SESSION['role'], 'logout', 'User logged out');
    }
}
session_destroy();
header("Location: index.php");
exit();
?>