# Profile Picture Feature

## Overview
The admin profile picture feature allows administrators to upload, manage, and display their profile pictures throughout the JohnTech system.

## Features
- **Upload Profile Picture**: Admins can upload JPG, PNG, or GIF images up to 5MB
- **Preview**: Real-time preview of selected images before upload
- **Remove Picture**: Option to remove current profile picture
- **Rounded Display**: Profile pictures are displayed as rounded circles throughout the system
- **Sidebar Integration**: Profile picture appears in the admin sidebar

## File Structure
```
assets/
├── uploads/
│   └── profile_pictures/     # Profile picture storage
└── css/
    └── styles.css           # Custom CSS for profile pictures
```

## Database Changes
- Added `profile_picture` column to `users` table (VARCHAR(255), NULL)
- Added index for better performance

## Implementation Details

### Settings Page (`pages/admin/Settings.php`)
- New "Profile Picture" section at the top of settings
- File upload with validation
- Preview functionality with JavaScript
- Remove picture option

### Admin Sidebar (`includes/admin_sidebar.php`)
- Displays admin's profile picture instead of default logo
- Falls back to default logo if no profile picture is set

### CSS Styling (`assets/css/styles.css`)
- Custom styles for profile picture containers
- Hover effects and transitions
- Responsive design

## Usage
1. Navigate to Admin Settings
2. In the "Profile Picture" section, click "Choose File"
3. Select an image file (JPG, PNG, or GIF, max 5MB)
4. Preview the image
5. Click "Upload New Picture" to save
6. Use "Remove Current Picture" to delete existing picture

## Security Features
- File type validation (JPG, PNG, GIF only)
- File size limit (5MB)
- Secure file naming with timestamps
- Automatic cleanup of old files when replaced
- SQL injection protection with prepared statements

## Browser Compatibility
- Modern browsers with FileReader API support
- Fallback for older browsers
- Responsive design for mobile devices 