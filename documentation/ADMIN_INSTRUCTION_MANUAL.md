# 📖 **JOHNTECH MANAGEMENT SYSTEM**
## **ADMIN INSTRUCTION MANUAL**

**Version**: 1.0  
**Developed by**: Ralph Brogada  
**Date**: July 2025  
**For**: System Owner/Administrator  

---

## 🎯 **TABLE OF CONTENTS**

1. [System Overview](#system-overview)
2. [Initial Setup](#initial-setup)
3. [Admin Login & Password Management](#admin-login--password-management)
4. [System Administration](#system-administration)
5. [Password Recovery Procedures](#password-recovery-procedures)
6. [Security Best Practices](#security-best-practices)
7. [Troubleshooting](#troubleshooting)
8. [Emergency Procedures](#emergency-procedures)
9. [Support & Maintenance](#support--maintenance)

---

## 🏢 **SYSTEM OVERVIEW**

### **What is JohnTech Management System?**
The JohnTech Management System is a comprehensive business management solution designed for your company. It includes:

- **Point of Sale (POS)** system for multiple branches
- **Inventory Management** across locations
- **Sales Reporting** and analytics
- **User Management** (Admin and Cashier accounts)
- **Returns Management** system
- **Audit Logging** for security compliance

### **System Components**
- **Web Application**: Browser-based interface
- **Database**: MySQL database for data storage
- **Multi-Branch Support**: Branch 1 and Branch 2 operations
- **Role-Based Access**: Admin and Cashier user roles

---

## 🚀 **INITIAL SETUP**

### **System Requirements**
- **Web Server**: Apache (XAMPP recommended)
- **Database**: MySQL 5.7 or higher
- **PHP**: Version 7.4 or higher
- **Browser**: Chrome, Firefox, Safari, or Edge

### **First-Time Access**

**🔗 Access URL**: 
- **Local Development**: `http://localhost/johntech-system/`
- **Live/Deployed**: `https://yourdomain.com/` (replace with actual domain)

**👤 Default Admin Credentials**:
```
Username: admin
Password: JohnTech2025!
```

**⚠️ IMPORTANT**: You MUST change this password immediately after first login for security reasons. This is a temporary default password.

### **Initial Configuration Steps**

1. **Login with default credentials**
2. **Change your password immediately**
3. **Update your contact information**
4. **Review system settings**
5. **Create cashier accounts for your staff**

---

## 🔑 **ADMIN LOGIN & PASSWORD MANAGEMENT**

### **How to Login**

1. **Open your web browser**
2. **Navigate to**: 
   - **Local**: `http://localhost/johntech-system/`
   - **Live**: `https://yourdomain.com/` (your actual domain)
3. **Enter your username and password**
4. **Click "Sign In"**

### **Changing Your Password**

1. **Go to**: Admin Dashboard → Admin Management
2. **Find your admin account**
3. **Click "Reset Password"**
4. **Enter a strong new password**
5. **Confirm the change**

### **Password Requirements**
Your password must contain:
- ✅ **At least 8 characters**
- ✅ **One uppercase letter** (A-Z)
- ✅ **One lowercase letter** (a-z)
- ✅ **One number** (0-9)
- ✅ **One special character** (@$!%*?&)

**Good Examples**: 
- `JohnTech2025!`
- `MySecure@Pass123`
- `Admin$Strong2025`

**❌ Avoid**:
- `JohnTech2025!` (current default - CHANGE IMMEDIATELY!)
- `password` (too common)
- `admin123` (too simple)

---

## 👨‍💼 **SYSTEM ADMINISTRATION**

### **Managing Cashier Accounts**

**📍 Location**: Admin Dashboard → Cashier Management

#### **Adding New Cashiers**
1. **Click "Add Cashier"**
2. **Fill in the form**:
   - Username (unique)
   - Password (must meet requirements)
   - Full Name
   - Branch Assignment (1 or 2)
   - Contact Number (11 digits)
   - Email Address
   - Profile Picture (optional)
3. **Click "Add Cashier"**

#### **Editing Cashier Information**
1. **Find the cashier in the list**
2. **Click "Edit" button**
3. **Update information as needed**
4. **Save changes**

#### **Resetting Cashier Passwords**
1. **Go to Cashier Management**
2. **Click "Reset Password" for the cashier**
3. **Set new temporary password**
4. **Inform the cashier to change it on next login**

### **Monitoring System Activity**

**📍 Location**: Admin Dashboard → Audit Logs

**What you can see**:
- User login/logout times
- Password changes
- System modifications
- Failed login attempts
- Administrative actions

### **Viewing Sales & Inventory**

#### **Sales Reports**
- **Branch 1**: Admin Dashboard → Sales History (Branch 1)
- **Branch 2**: Admin Dashboard → Sales History (Branch 2)

#### **Inventory Status**
- **Branch 1**: Admin Dashboard → Inventory (Branch 1)
- **Branch 2**: Admin Dashboard → Inventory (Branch 2)

#### **Returns Management**
- **View Returns**: Admin Dashboard → View Returns
- **Process Refunds**: Check return status and approve

---

## 🆘 **PASSWORD RECOVERY PROCEDURES**

### **If You Forget Your Admin Password**

**🔗 Recovery URL**: 
- **Local**: `http://localhost/johntech-system/admin_recovery.php`
- **Live**: `https://yourdomain.com/admin_recovery.php` (your actual domain)

#### **Step-by-Step Recovery Process**:

**Step 1: Request Recovery**
1. **Open the recovery page**
2. **Enter your admin username**: `admin`
3. **Answer the security question**: 
   - Question: "What is the name of the company this system is built for?"
   - Answer: `johntech` (case insensitive)
4. **Click "Send Recovery Email"**

**Step 2: Use Recovery Token**
1. **Click the recovery link provided**
2. **Create a new strong password**
3. **Confirm the new password**
4. **Click "Reset Password"**

**Step 3: Login with New Password**
1. **Go back to the login page**
2. **Use your new credentials**
3. **Access your admin dashboard**

### **Security Questions & Answers**

**Q**: What is the name of the company this system is built for?  
**A**: `johntech`

**Note**: The answer is case-insensitive, so `JohnTech`, `JOHNTECH`, or `johntech` all work.

---

## 🛡️ **SECURITY BEST PRACTICES**

### **Password Security**
- ✅ **Use strong, unique passwords**
- ✅ **Change passwords every 90 days**
- ✅ **Never share your admin credentials**
- ✅ **Log out when finished**
- ❌ **Don't use the same password for multiple systems**
- ❌ **Don't write passwords down where others can see**

### **Account Management**
- ✅ **Regularly review cashier accounts**
- ✅ **Remove accounts for departed employees**
- ✅ **Monitor audit logs for suspicious activity**
- ✅ **Update contact information when it changes**

### **System Access**
- ✅ **Only access from trusted computers**
- ✅ **Ensure antivirus software is running**
- ✅ **Keep your browser updated**
- ✅ **Use secure networks (avoid public WiFi)**

---

## 🔧 **TROUBLESHOOTING**

### **Common Issues & Solutions**

#### **Can't Login**
**Problem**: Username or password incorrect
**Solution**: 
1. Check caps lock is off
2. Verify username spelling
3. Use password recovery if needed

**Problem**: Account locked
**Solution**: Wait 30 minutes or contact system administrator

#### **Page Won't Load**
**Problem**: Website not accessible
**Solution**:
1. Check internet connection
2. Verify XAMPP is running
3. Try refreshing the page
4. Clear browser cache

#### **Password Reset Not Working**
**Problem**: Recovery email not received
**Solution**:
1. Check the demo recovery link on screen
2. Verify username spelling
3. Ensure security answer is correct
4. Try again in a few minutes

### **Error Messages**

| Error | Meaning | Solution |
|-------|---------|----------|
| "Invalid credentials" | Wrong username/password | Check spelling, use recovery |
| "Session expired" | Been idle too long | Login again |
| "Access denied" | Insufficient permissions | Contact administrator |
| "Database error" | System issue | Contact technical support |

---

## 🚨 **EMERGENCY PROCEDURES**

### **Complete Admin Lockout**

If you cannot access the admin account at all, contact your system developer with these details:
- **System URL**
- **Database access information**
- **Description of the problem**
- **When the issue started**

### **Suspected Security Breach**

If you suspect unauthorized access:
1. **Change all passwords immediately**
2. **Check audit logs for suspicious activity**
3. **Review all user accounts**
4. **Document any unusual activity**
5. **Contact system support**

### **System Down/Not Responding**

1. **Check if XAMPP is running**
2. **Restart the web server**
3. **Check database connectivity**
4. **Contact technical support if needed**

---

## 📞 **SUPPORT & MAINTENANCE**

### **Getting Help**

**📧 Technical Support**: [Your Support Email]  
**📱 Phone Support**: [Your Phone Number]  
**🕒 Support Hours**: Monday-Friday, 9 AM - 5 PM  

### **What to Include When Requesting Support**
- **System URL**
- **Username (never send passwords)**
- **Description of the problem**
- **Steps you tried**
- **Error messages**
- **Screenshots (if helpful)**

### **Regular Maintenance Tasks**

#### **Weekly**
- ✅ Review audit logs
- ✅ Check system performance
- ✅ Backup important data

#### **Monthly**
- ✅ Update cashier information
- ✅ Review user accounts
- ✅ Check inventory accuracy
- ✅ Review sales reports

#### **Quarterly**
- ✅ Change admin password
- ✅ Security review
- ✅ System updates (if available)
- ✅ Staff training refresh

### **System Updates**

When updates are available:
1. **You will be notified by the development team**
2. **Schedule downtime for updates**
3. **Backup your data before updating**
4. **Test the system after updates**
5. **Report any issues immediately**

---

## 📋 **QUICK REFERENCE**

### **Important URLs**
- **Main System**: 
  - **Local**: `http://localhost/johntech-system/`
  - **Live**: `https://yourdomain.com/` (your actual domain)
- **Password Recovery**: 
  - **Local**: `http://localhost/johntech-system/admin_recovery.php`
  - **Live**: `https://yourdomain.com/admin_recovery.php` (your actual domain)
- **Admin Dashboard**: Available after login

### **Default Credentials**
- **Username**: `admin`
- **Password**: `JohnTech2025!` (CHANGE IMMEDIATELY!)
- **Security Answer**: `johntech`

### **Key Features Access**
- **Cashier Management**: Admin Dashboard → Cashier Management
- **Sales Reports**: Admin Dashboard → Sales History
- **Inventory**: Admin Dashboard → Inventory Management
- **Audit Logs**: Admin Dashboard → Audit Logs
- **Settings**: Admin Dashboard → Settings

---

## ✅ **SYSTEM HANDOVER CHECKLIST**

**For System Owner/Administrator:**

- [ ] ✅ **Received system access credentials**
- [ ] ✅ **Successfully logged into admin dashboard**
- [ ] ✅ **Changed default password**
- [ ] ✅ **Tested password recovery system**
- [ ] ✅ **Created test cashier account**
- [ ] ✅ **Reviewed all system features**
- [ ] ✅ **Understood security procedures**
- [ ] ✅ **Bookmarked important URLs**
- [ ] ✅ **Saved support contact information**
- [ ] ✅ **Scheduled regular maintenance tasks**

---

## 📄 **APPENDIX**

### **System Specifications**
- **Framework**: PHP/MySQL
- **Security**: Password hashing, session management, audit logging
- **Browser Compatibility**: All modern browsers
- **Mobile Friendly**: Responsive design

### **Database Tables**
- **Users**: Admin and cashier accounts
- **Products**: Inventory items
- **Sales**: Transaction records
- **Audit Logs**: Security events
- **Recovery Tokens**: Password reset system

---

**🏢 JohnTech Management System**  
**Developed by**: [Your Development Team]  
**© 2025 - All Rights Reserved**

**For technical support or questions about this manual, please contact our development team.**

---

*This manual is designed to help you successfully manage and maintain your JohnTech Management System. Keep this document in a safe, accessible location for future reference.*
