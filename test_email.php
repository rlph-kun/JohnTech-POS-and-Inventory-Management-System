<?php
/**
 * Email Configuration Test Script
 * Run this to diagnose email sending issues
 */

// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Email Configuration Test</h2>";

// Check if autoloader exists
echo "<h3>1. Checking PHPMailer Installation</h3>";
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    echo "✅ Autoloader found: $autoload_path<br>";
    require_once $autoload_path;
} else {
    echo "❌ Autoloader NOT found at: $autoload_path<br>";
    echo "Please run: composer install<br>";
    exit;
}

// Check if PHPMailer classes exist
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "✅ PHPMailer class loaded<br>";
} else {
    echo "❌ PHPMailer class NOT found<br>";
    exit;
}

// Check PHP extensions
echo "<h3>2. Checking PHP Extensions</h3>";
$required_extensions = ['openssl', 'mbstring'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ $ext extension loaded<br>";
    } else {
        echo "❌ $ext extension NOT loaded<br>";
    }
}

// Load email config
echo "<h3>3. Loading Email Configuration</h3>";
require_once __DIR__ . '/includes/email_config.php';

echo "SMTP Host: " . SMTP_HOST . "<br>";
echo "SMTP Port: " . SMTP_PORT . "<br>";
echo "SMTP Username: " . SMTP_USERNAME . "<br>";
echo "SMTP Password: " . (strlen(SMTP_PASSWORD) > 0 ? "✅ Set (" . strlen(SMTP_PASSWORD) . " chars)" : "❌ Not set") . "<br>";
echo "From Email: " . SMTP_FROM_EMAIL . "<br>";

// Test email sending
echo "<h3>4. Testing Email Connection</h3>";
echo "<p>Attempting to send test email...</p>";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Enable verbose debug output
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) {
        echo "<pre style='background:#f0f0f0;padding:5px;margin:5px 0;'>$str</pre>";
    };
    
    // Server settings
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    
    // Recipients
    $mail->setFrom(SMTP_FROM_EMAIL, 'JohnTech System Test');
    $mail->addAddress(SMTP_USERNAME); // Send to yourself for testing
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Test Email - OTP System';
    $mail->Body    = '<h1>Test Email</h1><p>If you receive this, your email configuration is working!</p>';
    $mail->AltBody = 'Test Email - If you receive this, your email configuration is working!';
    
    $mail->send();
    echo "<div style='background:#d4edda;color:#155724;padding:15px;border-radius:5px;margin:10px 0;'>";
    echo "✅ <strong>SUCCESS!</strong> Test email sent successfully to " . SMTP_USERNAME;
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background:#f8d7da;color:#721c24;padding:15px;border-radius:5px;margin:10px 0;'>";
    echo "❌ <strong>ERROR:</strong> Email could not be sent.<br>";
    echo "Error Info: " . $mail->ErrorInfo . "<br>";
    echo "Exception: " . $e->getMessage();
    echo "</div>";
}

echo "<h3>5. Common Issues & Solutions</h3>";
echo "<ul>";
echo "<li><strong>Authentication failed:</strong> Check that you're using an App Password (16 chars), not your regular Gmail password</li>";
echo "<li><strong>Connection timeout:</strong> Check firewall settings or try port 465 with SMTPS</li>";
echo "<li><strong>SSL/TLS error:</strong> Make sure OpenSSL extension is enabled</li>";
echo "<li><strong>Could not authenticate:</strong> Verify 2-Step Verification is enabled on your Google account</li>";
echo "</ul>";

echo "<p><a href='forgot_password.php'>← Back to Forgot Password</a></p>";
?>

