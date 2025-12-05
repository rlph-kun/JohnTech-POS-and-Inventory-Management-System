<?php
/**
 * Email Configuration for PHPMailer
 * Gmail SMTP Configuration
 * 
 * To use Gmail SMTP:
 * 1. Enable 2-Step Verification on your Google account
 * 2. Generate an App Password: https://myaccount.google.com/apppasswords
 * 3. Update the SMTP_USERNAME and SMTP_PASSWORD constants below
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Gmail SMTP Configuration
// IMPORTANT: Replace the values below with your actual Gmail credentials
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587); // Using STARTTLS (more commonly allowed)
define('SMTP_USERNAME', 'brogadaralph6@gmail.com');
define('SMTP_PASSWORD', 'wjwznbzpyvjuzmab'); // App Password (spaces removed)
define('SMTP_FROM_EMAIL', 'brogadaralph6@gmail.com');
define('SMTP_FROM_NAME', 'JohnTech System');

/**
 * Send OTP email using PHPMailer
 * 
 * @param string $to_email Recipient email address
 * @param string $otp_code 6-digit OTP code
 * @return bool True if email sent successfully, false otherwise
 */
function sendOTPEmail($to_email, $otp_code) {
    // Load Composer's autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        
        // Use SMTPS (SSL) for port 465, or STARTTLS for port 587
        if (SMTP_PORT == 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL/TLS
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS
        }
        
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 30; // Connection timeout in seconds
        
        // SSL/TLS options for better compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false
            )
        );
        
        // Debug output (disabled for production - enable for troubleshooting)
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Set to SMTP::DEBUG_SERVER for troubleshooting
        $mail->Debugoutput = function($str, $level) {
            // Only log to error log, don't output to screen
            error_log("PHPMailer Debug: $str");
        };
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to_email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Admin Password Reset - OTP Code';
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                .otp-box { background: white; border: 2px dashed #667eea; padding: 20px; text-align: center; margin: 20px 0; border-radius: 10px; }
                .otp-code { font-size: 32px; font-weight: bold; color: #667eea; letter-spacing: 5px; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>JohnTech System</h2>
                    <p>Password Reset Request</p>
                </div>
                <div class="content">
                    <h3>Hello Admin,</h3>
                    <p>You have requested to reset your password. Please use the following OTP code to verify your identity:</p>
                    
                    <div class="otp-box">
                        <p style="margin: 0; color: #666;">Your OTP Code:</p>
                        <div class="otp-code">' . htmlspecialchars($otp_code) . '</div>
                    </div>
                    
                    <div class="warning">
                        <strong>⚠️ Security Notice:</strong><br>
                        This OTP code will expire in <strong>5 minutes</strong>.<br>
                        If you did not request this password reset, please ignore this email or contact system administrator immediately.
                    </div>
                    
                    <p>After entering the OTP code, you will be able to set a new password for your admin account.</p>
                    
                    <p>Best regards,<br>JohnTech System</p>
                </div>
                <div class="footer">
                    <p>This is an automated email. Please do not reply.</p>
                    <p>&copy; ' . date('Y') . ' JohnTech System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "JohnTech System - Password Reset OTP\n\nYou have requested to reset your admin password.\n\nYour OTP Code: $otp_code\n\nThis code will expire in 5 minutes.\n\nIf you did not request this, please ignore this email.";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        // Log detailed error information
        $error_details = "Email sending failed: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage();
        error_log($error_details);
        
        // Store error for debugging (remove in production)
        global $last_email_error;
        $last_email_error = $error_details;
        
        return false;
    }
}

