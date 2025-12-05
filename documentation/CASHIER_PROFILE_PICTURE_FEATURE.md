# Cashier Profile Picture Feature

## Overview
The cashier profile picture feature allows administrators to upload and manage profile pictures for cashiers in the JohnTech system. Cashiers can see their profile pictures displayed in the sidebar.

## Features
- **Upload Profile Picture**: Admins can upload JPG, PNG, or GIF images up to 5MB for cashiers
- **Preview**: Real-time preview of selected images before upload in the add modal
- **Rounded Display**: Profile pictures are displayed as rounded circles throughout the system
- **Sidebar Integration**: Cashier profile pictures appear in the cashier sidebar
- **Automatic Cleanup**: Old profile pictures are automatically deleted when replaced or when cashier is deleted

## File Structure
```
assets/
├── uploads/
│   └── profile_pictures/     # Profile picture storage
│       ├── admin_*.jpg       # Admin profile pictures
│       └── cashier_*.jpg     # Cashier profile pictures
└── css/
    └── styles.css           # Custom CSS for profile pictures
```

## Database Changes
- Added `profile_picture` column to `cashier_profiles` table (VARCHAR(255), NULL)
- Profile pictures are stored as filenames in the database
- Physical files are stored in `assets/uploads/profile_pictures/`

## Implementation Details

### Cashier Management Page (`pages/admin/cashier_management.php`)
- New "Profile" column in the cashier table showing rounded profile pictures
- Enhanced add/edit modals with profile picture upload functionality
- File validation (JPG, PNG, GIF, max 5MB)
- Preview functionality with JavaScript
- Automatic file cleanup on deletion

### Cashier Sidebar (`includes/cashier_sidebar.php`)
- Displays cashier's profile picture instead of default logo
- Falls back to default logo if no profile picture is set
- Rounded styling with blue border

### File Naming Convention
- Cashier profile pictures: `cashier_{user_id}_{timestamp}.{extension}`
- Example: `cashier_5_1752543680.jpg`

## Usage for Admins
1. Navigate to Admin Dashboard → Cashier Management
2. Click "Add New Cashier" or "Edit" on existing cashier
3. In the modal, upload a profile picture (optional)
4. Preview the image before saving
5. Save changes to apply the profile picture

## Security Features
- File type validation (JPG, PNG, GIF only)
- File size limit (5MB)
- Secure file naming with timestamps and user IDs
- Automatic cleanup of old files when replaced
- SQL injection protection with prepared statements
- XSS protection with htmlspecialchars()

## Display Locations
- Cashier Management table (50px rounded circles)
- Cashier sidebar (56px rounded circles with blue border)
- Edit modals (120px rounded circles for preview)

## Error Handling
- Validation errors are displayed to the user
- Failed uploads are cleaned up automatically
- Database rollback on errors
- File existence checks before display 