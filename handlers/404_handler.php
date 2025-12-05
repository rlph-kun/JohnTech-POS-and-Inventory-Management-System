<?php
/**
 * REDIRECT FOR REMOVED SCRIPTS
 * This handles requests for scripts that were removed for security
 */
include_once __DIR__ . '/../config.php';

// List of removed scripts that should redirect to security notice
$removed_scripts = [
    'smart_admin_recovery.php',
    'emergency_password_reset.php',
    'reset_admin_password.php',
    'emergency_admin_setup.php',
    'admin_recovery.php' // Removed - replaced with OTP-based forgot_password.php
];

$requested_script = basename($_SERVER['REQUEST_URI']);
$requested_script = strtok($requested_script, '?'); // Remove query parameters

if (in_array($requested_script, $removed_scripts)) {
    header('Location: ../handlers/security_notice.php');
    exit;
}

// If script not in removed list, show 404
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - JohnTech System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 3rem;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <h1 class="display-1">404</h1>
        <h2>Page Not Found</h2>
        <p class="text-muted">The requested page could not be found.</p>
        <?php
        // Check if user is logged in and redirect appropriately
        session_start();
        if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
            if ($_SESSION['role'] === 'admin') {
                echo '<a href="' . BASE_URL . '/pages/admin/admin_dashboard.php" class="btn btn-primary">Return to Dashboard</a>';
            } elseif ($_SESSION['role'] === 'cashier' && isset($_SESSION['branch'])) {
                echo '<a href="' . BASE_URL . '/pages/cashier/pos_branch' . $_SESSION['branch'] . '.php" class="btn btn-primary">Return to POS</a>';
            } else {
                echo '<a href="' . BASE_URL . '/logout.php" class="btn btn-warning me-2">Logout</a>';
                echo '<a href="' . BASE_URL . '/index.php" class="btn btn-primary">Go to Login</a>';
            }
        } else {
            echo '<a href="' . BASE_URL . '/index.php" class="btn btn-primary">Return to Login</a>';
        }
        ?>
    </div>
</body>
</html>
