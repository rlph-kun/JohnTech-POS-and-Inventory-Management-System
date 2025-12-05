<?php
/**
 * Simple credential checker
 * This helps verify the password format is correct
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Credential Verification</h2>";

require_once __DIR__ . '/includes/email_config.php';

echo "<h3>Configuration Check:</h3>";
echo "<strong>Username:</strong> " . SMTP_USERNAME . "<br>";
echo "<strong>Password Length:</strong> " . strlen(SMTP_PASSWORD) . " characters<br>";
echo "<strong>Password (first 4 chars):</strong> " . substr(SMTP_PASSWORD, 0, 4) . "****<br>";
echo "<strong>Password (last 4 chars):</strong> ****" . substr(SMTP_PASSWORD, -4) . "<br>";
echo "<strong>Contains spaces:</strong> " . (strpos(SMTP_PASSWORD, ' ') !== false ? "YES (BAD!)" : "NO (GOOD)") . "<br>";

echo "<h3>Important Verification Steps:</h3>";
echo "<ol>";
echo "<li><strong>Verify 2-Step Verification is enabled:</strong><br>";
echo "   Go to: <a href='https://myaccount.google.com/security' target='_blank'>https://myaccount.google.com/security</a><br>";
echo "   Make sure '2-Step Verification' shows as <strong>ON</strong></li>";

echo "<li><strong>Verify App Password exists:</strong><br>";
echo "   Go to: <a href='https://myaccount.google.com/apppasswords' target='_blank'>https://myaccount.google.com/apppasswords</a><br>";
echo "   Look for 'JohnTech System' in your app passwords list</li>";

echo "<li><strong>Copy App Password correctly:</strong><br>";
echo "   The password should be: <code>wjwz nbzp yvju zmab</code><br>";
echo "   In code (no spaces): <code>wjwznbzpyvjuzmab</code> (16 characters)</li>";

echo "<li><strong>Make sure you're using the correct Google account:</strong><br>";
echo "   Username in config: <strong>" . SMTP_USERNAME . "</strong><br>";
echo "   This must match the account where you generated the App Password!</li>";
echo "</ol>";

echo "<h3>Common Issues:</h3>";
echo "<ul>";
echo "<li>❌ If 2-Step Verification is OFF, App Passwords won't work</li>";
echo "<li>❌ If you're using a different Google account, the password won't work</li>";
echo "<li>❌ If the password has spaces in the code, it will fail</li>";
echo "<li>❌ If you deleted the App Password and generated a new one, use the NEW password</li>";
echo "</ul>";

echo "<p><a href='test_email.php'>← Back to Email Test</a></p>";
?>

