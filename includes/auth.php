<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Universal role-based access control.
 * Usage: include 'includes/auth.php'; allow_roles(['admin', 'cashier']);
 */

function allow_roles($roles = []) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles)) {
        header("Location: /index.php");
        exit();
    }
}
