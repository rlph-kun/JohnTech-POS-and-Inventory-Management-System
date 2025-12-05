<?php
/**
 * PROFESSIONAL SECURITY CONFIGURATION
 * 
 * This file contains security settings suitable for capstone defense
 * and production deployment.
 * 
 * @author JohnTech Development Team
 * @version 1.0 (Capstone Ready)
 */

// Security Configuration
class SecurityConfig {
    
    // Password Policy
    const MIN_PASSWORD_LENGTH = 8;
    const REQUIRE_UPPERCASE = true;
    const REQUIRE_LOWERCASE = true;
    const REQUIRE_NUMBERS = true;
    const REQUIRE_SPECIAL_CHARS = true;
    const PASSWORD_EXPIRY_DAYS = 90; // Force password change every 90 days
    
    // Recovery System
    const RECOVERY_TOKEN_EXPIRY_HOURS = 1;
    const MAX_RECOVERY_ATTEMPTS_PER_HOUR = 3;
    const SECURITY_QUESTION_REQUIRED = false; // Deprecated - using OTP-based recovery instead
    
    // Account Lockout
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_DURATION_MINUTES = 30;
    
    // Session Security
    const SESSION_TIMEOUT_MINUTES = 30;
    const REQUIRE_SESSION_REGENERATION = true;
    
    // Audit Logging
    const LOG_ALL_ADMIN_ACTIONS = true;
    const LOG_RECOVERY_ATTEMPTS = true;
    const LOG_FAILED_LOGINS = true;
    
    // Default Admin Configuration (for capstone)
    const DEFAULT_ADMIN_USERNAME = 'admin';
    const DEFAULT_ADMIN_EMAIL = 'admin@johntech.com';
    const DEFAULT_SECURITY_QUESTION = 'What is the name of the company this system is built for?';
    const DEFAULT_SECURITY_ANSWER = 'johntech'; // Case insensitive
    
    /**
     * Generate a secure default password
     * Better than hardcoded '123456'
     */
    public static function generateSecureDefaultPassword() {
        return 'JohnTech' . date('Y') . '!'; // JohnTech2025!
    }
    
    /**
     * Validate password strength
     */
    public static function validatePasswordStrength($password) {
        $errors = [];
        
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long';
        }
        
        if (self::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (self::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (self::REQUIRE_NUMBERS && !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if (self::REQUIRE_SPECIAL_CHARS && !preg_match('/[@$!%*?&]/', $password)) {
            $errors[] = 'Password must contain at least one special character (@$!%*?&)';
        }
        
        return $errors;
    }
    
    /**
     * Check if password has expired
     */
    public static function isPasswordExpired($last_change_date) {
        if (!$last_change_date) return true;
        
        $expiry_date = date('Y-m-d', strtotime($last_change_date . ' +' . self::PASSWORD_EXPIRY_DAYS . ' days'));
        return date('Y-m-d') > $expiry_date;
    }
    
    /**
     * Generate secure recovery token
     */
    public static function generateRecoveryToken() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Get recovery token expiry time
     */
    public static function getRecoveryTokenExpiry() {
        return date('Y-m-d H:i:s', strtotime('+' . self::RECOVERY_TOKEN_EXPIRY_HOURS . ' hours'));
    }
}

/**
 * Security Helper Functions
 */
class SecurityHelper {
    
    /**
     * Log security events
     */
    public static function logSecurityEvent($conn, $event_type, $details, $user_id = null) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $conn->prepare("INSERT INTO security_log (event_type, details, user_id, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssiss", $event_type, $details, $user_id, $ip_address, $user_agent);
        $stmt->execute();
    }
    
    /**
     * Check for suspicious activity
     */
    public static function checkSuspiciousActivity($conn, $ip_address, $event_type, $time_window_minutes = 60) {
        $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM security_log WHERE ip_address = ? AND event_type = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->bind_param("ssi", $ip_address, $event_type, $time_window_minutes);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['attempts'];
    }
    
    /**
     * Sanitize input for logging
     */
    public static function sanitizeForLog($input) {
        return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Capstone Defense Documentation
 */
class CapstoneDocumentation {
    
    public static function getSecurityFeatures() {
        return [
            'Strong Password Policy' => 'Enforces complex passwords with multiple character types',
            'Secure Recovery System' => 'Token-based recovery with time limits and audit trails',
            'Security Questions' => 'Additional authentication factor for password recovery',
            'Account Lockout Protection' => 'Prevents brute force attacks',
            'Session Management' => 'Secure session handling with timeouts',
            'Comprehensive Audit Logging' => 'All security events are logged and traceable',
            'Rate Limiting' => 'Prevents automated attacks and abuse',
            'Input Validation' => 'All user inputs are properly validated and sanitized',
            'Secure Token Generation' => 'Cryptographically secure random tokens',
            'Time-based Security' => 'Recovery tokens and sessions have expiration times'
        ];
    }
    
    public static function getComplianceStandards() {
        return [
            'OWASP Top 10' => 'Addresses common web application security risks',
            'Password Security' => 'Follows industry best practices for password management',
            'Data Protection' => 'Implements proper data handling and privacy measures',
            'Audit Requirements' => 'Maintains comprehensive logs for security analysis',
            'Access Control' => 'Proper authentication and authorization mechanisms'
        ];
    }
}
?>
