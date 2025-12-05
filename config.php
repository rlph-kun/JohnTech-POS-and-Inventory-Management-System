<?php
date_default_timezone_set('Asia/Manila');

// ============================================================================
// BASE URL CONFIGURATION
// ============================================================================
// Set this to '' (empty string) if deployed at root (e.g., johntech.duckdns.org/)
// Set this to '/subfolder' if deployed in a subfolder (e.g., localhost/johntech-system)
// NO trailing slash!
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// Helper function to get asset URLs
if (!function_exists('asset_url')) {
    function asset_url($path) {
        return BASE_URL . '/' . ltrim($path, '/');
    }
}

// Helper function to get page URLs
if (!function_exists('page_url')) {
    function page_url($path) {
        return BASE_URL . '/' . ltrim($path, '/');
    }
}

// ============================================================================
// Database Configuration
// ============================================================================
$host = 'localhost';
$user = 'john_root';
$pass = 'QOjRKL1Tot7GIQ^G';
$dbname = 'john_johntech_system';

$conn = new mysqli($host, $user, $pass, $dbname, 3307);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
