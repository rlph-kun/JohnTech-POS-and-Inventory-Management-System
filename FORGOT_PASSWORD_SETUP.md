# Admin Forgot Password Setup Guide

## Overview
This system provides a secure OTP-based password recovery system for admin accounts only. Cashiers cannot use this feature and must have their passwords reset by administrators through the Cashier Management page.

## Files Created

1. **forgot_password.php** - Admin-only page to request password reset via email
2. **verify_otp.php** - Page to verify the 6-digit OTP code sent via email
3. **reset_password.php** - Page to set a new password after OTP verification
4. **includes/email_config.php** - PHPMailer configuration for Gmail SMTP
5. **sql/add_otp_fields.sql** - Database migration to add OTP fields

## Setup Instructions

### Step 1: Run Database Migration

Execute the SQL migration file to add OTP fields to the users table:

```sql
-- Run this file: sql/add_otp_fields.sql
-- Or manually execute the SQL commands in your database
```

The migration adds:
- `otp_code` (VARCHAR(6)) - Stores the 6-digit OTP
- `otp_expires_at` (DATETIME) - OTP expiration timestamp (5 minutes)

### Step 2: Configure Gmail SMTP

1. Open `includes/email_config.php`

2. Update the following constants with your Gmail credentials:

```php
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your Gmail address
define('SMTP_PASSWORD', 'your-app-password');    // Gmail App Password (not regular password)
define('SMTP_FROM_EMAIL', 'your-email@gmail.com'); // Your Gmail address
```

3. **Generate Gmail App Password:**
   - Go to your Google Account: https://myaccount.google.com/
   - Enable 2-Step Verification (if not already enabled)
   - Go to: https://myaccount.google.com/apppasswords
   - Generate an app password for "Mail"
   - Use this 16-character password (not your regular Gmail password)

### Step 3: Test the System

1. Navigate to the login page: `index.php`
2. Click "Forgot Password? (Admin Only)"
3. Enter an admin email address
4. Check your email for the OTP code
5. Enter the OTP code on the verification page
6. Set a new password

## Security Features

- ✅ Admin-only access (cashiers cannot use this feature)
- ✅ 6-digit OTP code expires in 5 minutes
- ✅ OTP verification before password reset
- ✅ Password hashing using `password_hash()`
- ✅ OTP fields cleared after successful reset
- ✅ Email enumeration protection (doesn't reveal if email exists)
- ✅ Session-based flow security

## Flow Diagram

```
1. Admin clicks "Forgot Password" link
   ↓
2. forgot_password.php - Enter admin email
   ↓
3. System generates 6-digit OTP (expires in 5 min)
   ↓
4. OTP sent via email using PHPMailer
   ↓
5. verify_otp.php - Admin enters OTP code
   ↓
6. System verifies OTP (checks expiration)
   ↓
7. reset_password.php - Admin sets new password
   ↓
8. Password hashed and saved, OTP fields cleared
   ↓
9. Redirect to login page with success message
```

## Troubleshooting

### Email Not Sending

1. **Check Gmail App Password:**
   - Make sure you're using an App Password, not your regular Gmail password
   - Verify 2-Step Verification is enabled

2. **Check SMTP Configuration:**
   - Verify `SMTP_USERNAME`, `SMTP_PASSWORD`, and `SMTP_FROM_EMAIL` in `includes/email_config.php`
   - Ensure PHPMailer is installed: `vendor/autoload.php` exists

3. **Check PHP Error Logs:**
   - Check `error_log` for PHPMailer errors
   - Enable debug mode temporarily: Set `SMTPDebug = SMTP::DEBUG_SERVER` in `email_config.php`

### OTP Not Working

1. **Check Database:**
   - Verify OTP fields were added: `otp_code` and `otp_expires_at`
   - Check if OTP expired (5 minutes limit)

2. **Check Session:**
   - Ensure sessions are working properly
   - Clear browser cookies if needed

## Notes

- Cashiers must contact an administrator to reset their passwords
- OTP codes are single-use and expire after 5 minutes
- The system uses secure password hashing (`PASSWORD_DEFAULT`)
- All OTP fields are cleared after successful password reset

