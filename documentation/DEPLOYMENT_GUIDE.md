# 🚀 **DEPLOYMENT GUIDE**
## **JohnTech Management System - Going Live**

**Version**: 1.0  
**For**: System Administrator/Web Hosting  
**Date**: July 2025  

---

## 📋 **PRE-DEPLOYMENT CHECKLIST**

### **Before Moving to Live Server**

- [ ] ✅ **Test system thoroughly on localhost**
- [ ] ✅ **Backup all files and database**
- [ ] ✅ **Prepare hosting account with PHP/MySQL support**
- [ ] ✅ **Purchase domain name (if needed)**
- [ ] ✅ **Gather hosting credentials (FTP, cPanel, etc.)**
- [ ] ✅ **Update configuration files**
- [ ] ✅ **Test admin recovery system locally**

---

## 🌐 **HOSTING REQUIREMENTS**

### **Server Requirements**
- **PHP**: Version 7.4 or higher
- **MySQL**: Version 5.7 or higher  
- **Web Server**: Apache or Nginx
- **SSL Certificate**: Recommended for security
- **Storage**: Minimum 100MB (for files and database)
- **Bandwidth**: Depends on usage, start with basic plan

### **Recommended Hosting Providers**
- **Shared Hosting**: Bluehost, HostGator, SiteGround
- **VPS Hosting**: DigitalOcean, Linode, Vultr
- **Cloud Hosting**: AWS, Google Cloud, Azure
- **Local Providers**: Check for Philippines-based providers

---

## 📁 **FILE UPLOAD PROCESS**

### **Step 1: Prepare Files**
1. **Copy entire johntech-system folder**
2. **Update config.php with live database settings**
3. **Remove or secure documentation folder for production**
4. **Update any hardcoded localhost URLs**

### **Step 2: Upload Files**
**Via FTP/File Manager:**
```
Local: d:\xampp\htdocs\johntech-system\
Upload to: public_html/ (or your domain folder)
```

**Via cPanel File Manager:**
1. Login to cPanel
2. Open File Manager
3. Navigate to public_html
4. Upload and extract files

### **Step 3: Database Setup**
1. **Create MySQL database in hosting control panel**
2. **Create database user with full privileges**
3. **Import your local database**
4. **Update config.php with new database credentials**

---

## ⚙️ **CONFIGURATION UPDATES**

### **Database Configuration (config.php)**

**Local Version:**
```php
$host = 'localhost';
$dbname = 'johntech_system';
$username = 'root';
$password = '';
$port = 3307;
```

**Live Version (Update with your hosting details):**
```php
$host = 'localhost'; // Or hosting provider's MySQL server
$dbname = 'yourhosting_johntech'; // Your actual database name
$username = 'yourhosting_dbuser'; // Your database username
$password = 'your_secure_db_password'; // Your database password
```

### **URL Updates Needed**

**Files to check for localhost URLs:**
- `config.php` - Base URL settings
- `admin_recovery.php` - Recovery email links
- Any hardcoded links in PHP files
- JavaScript files with AJAX calls

**Find and Replace:**
```
OLD: http://localhost/johntech-system/
NEW: https://yourdomain.com/
```

---

## 🔐 **SECURITY CONSIDERATIONS**

### **Production Security Updates**

1. **Change Default Credentials**
   ```
   Username: admin
   Password: JohnTech2025! → [STRONG PASSWORD]
   ```

2. **Update Security Questions**
   - Consider changing the security question/answer
   - Use company-specific information

3. **File Permissions**
   ```
   Folders: 755
   PHP Files: 644
   Config Files: 600 (if possible)
   Upload Folders: 755
   ```

4. **Hide Sensitive Files**
   - Move documentation folder outside public_html
   - Add .htaccess to protect sensitive directories
   - Remove any test/debug files

### **Recommended .htaccess Security**
Create `.htaccess` in root directory:
```apache
# Hide documentation folder
<Directory "documentation">
    Order allow,deny
    Deny from all
</Directory>

# Protect config files
<Files "config.php">
    Order allow,deny
    Deny from all
</Files>

# Enable HTTPS redirect
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## 🌍 **DOMAIN SETUP**

### **Domain Configuration**

1. **Point Domain to Hosting**
   - Update nameservers with domain registrar
   - Wait for DNS propagation (24-48 hours)

2. **SSL Certificate Setup**
   - Most hosting providers offer free SSL
   - Enable HTTPS redirect
   - Update all URLs to use https://

3. **Test Domain Access**
   ```
   Test URLs:
   - https://yourdomain.com/
   - https://yourdomain.com/admin_recovery.php
   ```

---

## 📧 **EMAIL CONFIGURATION**

### **For Password Recovery System**

**Option 1: Hosting Email**
```php
// In admin_recovery.php, update email settings
$from_email = "noreply@yourdomain.com";
$smtp_host = "mail.yourdomain.com"; // Your hosting SMTP
```

**Option 2: Gmail SMTP (Recommended)**
```php
// Use Gmail or professional email service
$smtp_host = "smtp.gmail.com";
$smtp_port = 587;
$smtp_username = "your-email@gmail.com";
$smtp_password = "your-app-password";
```

**Option 3: Email Service (SendGrid, Mailgun)**
- More reliable for transactional emails
- Better deliverability
- Professional setup

---

## 🧪 **POST-DEPLOYMENT TESTING**

### **Testing Checklist**

**Basic Functionality:**
- [ ] ✅ **Main login page loads**
- [ ] ✅ **Admin login works**
- [ ] ✅ **Dashboard displays correctly**
- [ ] ✅ **All navigation links work**
- [ ] ✅ **Database connections successful**

**Password Recovery:**
- [ ] ✅ **Recovery page accessible**
- [ ] ✅ **Security question validation**
- [ ] ✅ **Password reset process**
- [ ] ✅ **Email delivery (if configured)**

**Advanced Features:**
- [ ] ✅ **Cashier management**
- [ ] ✅ **POS functionality**
- [ ] ✅ **Inventory management**
- [ ] ✅ **Sales reporting**
- [ ] ✅ **File uploads work**

**Security Testing:**
- [ ] ✅ **HTTPS working**
- [ ] ✅ **Sensitive files protected**
- [ ] ✅ **Login security active**
- [ ] ✅ **Session management**

---

## 🔧 **COMMON DEPLOYMENT ISSUES**

### **Database Connection Errors**
**Problem**: "Database connection failed"
**Solutions**:
1. Check database credentials in config.php
2. Verify database exists on hosting server
3. Ensure database user has correct permissions
4. Check hosting MySQL server address

### **File Permission Errors**
**Problem**: "Cannot write to file" or upload errors
**Solutions**:
1. Set correct folder permissions (755)
2. Check uploads folder permissions
3. Ensure web server can write to necessary folders

### **Email Not Sending**
**Problem**: Password recovery emails not delivered
**Solutions**:
1. Configure SMTP settings properly
2. Use hosting provider's email service
3. Set up external email service (Gmail, SendGrid)
4. Check spam folders during testing

### **CSS/JavaScript Not Loading**
**Problem**: Styling broken or features not working
**Solutions**:
1. Check file paths in HTML
2. Ensure all assets uploaded correctly
3. Clear browser cache
4. Check for mixed content (HTTP/HTTPS) issues

---

## 📱 **MOBILE CONSIDERATIONS**

### **Responsive Design Testing**
- **Test on actual mobile devices**
- **Check POS functionality on tablets**
- **Verify touch interactions work**
- **Test different screen sizes**

### **Performance Optimization**
- **Optimize images in assets/images/**
- **Enable gzip compression**
- **Use CDN for static assets (optional)**
- **Minimize CSS/JavaScript (for production)**

---

## 🔄 **ONGOING MAINTENANCE**

### **Regular Tasks After Deployment**

**Weekly:**
- [ ] ✅ **Check system functionality**
- [ ] ✅ **Monitor error logs**
- [ ] ✅ **Backup database**
- [ ] ✅ **Review audit logs**

**Monthly:**
- [ ] ✅ **Update passwords**
- [ ] ✅ **Check security**
- [ ] ✅ **Performance review**
- [ ] ✅ **Software updates**

**As Needed:**
- [ ] ✅ **Scale hosting resources**
- [ ] ✅ **Update documentation**
- [ ] ✅ **Add new features**
- [ ] ✅ **Security patches**

---

## 📞 **DEPLOYMENT SUPPORT**

### **When You Need Help**

**Contact Information:**
- **Development Team**: [Your Team Contact]
- **Hosting Support**: [Hosting Provider Support]
- **Domain Support**: [Domain Registrar Support]

**Information to Provide:**
- **Domain name**
- **Hosting provider**
- **Error messages**
- **Screenshots**
- **What you were trying to do**

---

## 🎯 **QUICK DEPLOYMENT SUMMARY**

1. **Prepare hosting account with PHP/MySQL**
2. **Upload files to public_html or domain folder**
3. **Create database and import local data**
4. **Update config.php with hosting database credentials**
5. **Replace all localhost URLs with your domain**
6. **Test admin login and password recovery**
7. **Set up SSL certificate and HTTPS redirect**
8. **Configure email for password recovery**
9. **Test all functionality thoroughly**
10. **Update documentation with live URLs**

---

**🚀 Ready for Launch!**

Once deployed, your system will be accessible at:
- **Main System**: `https://yourdomain.com/`
- **Password Recovery**: `https://yourdomain.com/admin_recovery.php`

**Remember**: Always test thoroughly before going live, and keep backups of both files and database!

---

**🏢 JohnTech Management System**  
**Deployment Guide v1.0**  
**© 2025 - Development Team**
