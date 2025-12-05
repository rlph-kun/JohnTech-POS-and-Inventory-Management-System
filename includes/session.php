<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Include config if not already included (for BASE_URL)
if (!defined('BASE_URL')) {
    include_once __DIR__ . '/../config.php';
}
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit();
}
