<?php
/**
 * SECURITY MONITOR
 * 
 * This script logs attempts to access deleted recovery scripts
 * and provides information about secure alternatives.
 */

// Log the access attempt
$log_entry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'requested_script' => basename($_SERVER['REQUEST_URI']),
    'referer' => $_SERVER['HTTP_REFERER'] ?? 'direct'
];

// Log to security file (create logs directory if needed)
if (!is_dir('logs')) {
    mkdir('logs', 0755, true);
}

file_put_contents('logs/security_access.log', json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);

// Provide secure alternatives
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Notice - JohnTech System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .security-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 3rem;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        .security-icon {
            font-size: 4rem;
            color: #ffc107;
            margin-bottom: 1rem;
        }
        .btn-secure {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin: 10px;
            transition: all 0.3s ease;
        }
        .btn-secure:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="security-card">
        <i class="fas fa-shield-alt security-icon"></i>
        <h2 class="mb-4">🔒 Security Notice</h2>
        <div class="alert alert-warning">
            <strong>Script Removed for Security</strong><br>
            The requested recovery script has been removed to improve system security.
        </div>
        
        <h4 class="mb-3">✅ Secure Recovery Options Available:</h4>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">🔐 Secure Admin Recovery</h5>
                        <p class="card-text">Access key protected system with rate limiting and audit logging.</p>
                        <a href="secure_admin_recovery.php" class="btn-secure">Access Secure Recovery</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title">📧 OTP-Based Recovery</h5>
                        <p class="card-text">Email-based recovery with OTP codes (6-digit codes sent to your email).</p>
                        <a href="forgot_password.php" class="btn-secure">Use OTP Recovery</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info">
            <strong>Access Key for Secure Recovery:</strong> <code>JOHNTECH2025</code><br>
            <small>Store this key securely and share only with authorized administrators.</small>
        </div>
        
        <hr>
        <p class="text-muted">
            <small>This access attempt has been logged for security purposes.</small><br>
            <small>Contact your system administrator if you need assistance.</small>
        </p>
        
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-home me-2"></i>Return to Login
        </a>
    </div>
</body>
</html>
