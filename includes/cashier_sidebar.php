<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Include config if not already included (for BASE_URL)
if (!defined('BASE_URL')) {
    include_once __DIR__ . '/../config.php';
}
$branch = isset($_SESSION['branch']) ? $_SESSION['branch'] : '';
$branch_suffix = ($branch == 1) ? 'branch1' : 'branch2';
$branch_name = ($branch == 1) ? 'Sorsogon' : 'Juban';
$current_page = basename($_SERVER['SCRIPT_NAME']);

// Get cashier profile picture
$cashier_profile_picture = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT profile_picture FROM cashier_profiles WHERE user_id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($cashier_profile_picture);
    $stmt->fetch();
    $stmt->close();
}
?>
<!-- Enhanced Modern Cashier Sidebar -->
<style>
/* Enhanced Sidebar Styling */
.sidebar {
    background: linear-gradient(145deg, #1a1d23 0%, #23272b 50%, #1e2125 100%);
    box-shadow: 0 0 30px rgba(0,0,0,0.3), 2px 0 15px rgba(0,0,0,0.15);
    border-right: 1px solid rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 250px !important;
    height: 100vh !important;
    z-index: 1050 !important;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #404448 transparent;
}

.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.05);
}

.sidebar::-webkit-scrollbar-thumb {
    background: #404448;
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: #505458;
}

.sidebar::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #1565c0, #1976d2, #0d47a1);
    z-index: 1;
}

.sidebar-header {
    padding: 1.5rem 1rem;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    position: relative;
    background: rgba(255,255,255,0.02);
}

.sidebar-logo {
    width: 56px !important;
    height: 56px !important;
    border-radius: 50% !important;
    object-fit: cover !important;
    border: 3px solid #1565c0 !important;
    box-shadow: 0 4px 12px rgba(21, 101, 192, 0.3), 0 0 0 2px rgba(255,255,255,0.1) !important;
    margin-bottom: 0.75rem !important;
    display: block !important;
    transition: all 0.3s ease !important;
    position: relative;
}

.sidebar-logo:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 16px rgba(21, 101, 192, 0.4), 0 0 0 3px rgba(255,255,255,0.15) !important;
}

.sidebar-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #ffffff;
    margin: 0 0 0.25rem 0;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    letter-spacing: 0.5px;
}

.sidebar-subtitle {
    font-size: 0.85rem;
    color: #b8bcc8;
    margin: 0;
    font-weight: 500;
    opacity: 0.9;
}

.sidebar-section {
    margin: 0.5rem 0;
}

.sidebar-section-title {
    color: #8a909a;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0.75rem 1.25rem 0.5rem 1.25rem;
    margin: 0;
    position: relative;
    background: rgba(255,255,255,0.02);
}

.sidebar-section-title::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 1.25rem;
    right: 1.25rem;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
}

.sidebar nav a {
    display: flex;
    align-items: center;
    padding: 0.875rem 1.25rem;
    /*color: #e4e6ea;*/
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    border-left: 3px solid transparent;
    position: relative;
    margin: 0.125rem 0;
}

.sidebar nav a::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 0;
    background: linear-gradient(135deg, rgba(21, 101, 192, 0.1), rgba(25, 118, 210, 0.15));
    z-index: 0;
}

.sidebar nav a:hover:not(.active) {
    background: rgba(255,255,255,0.08);
    color: #ffffff;
    border-left-color: #1565c0;
    transform: translateX(2px);
    box-shadow: inset 0 0 20px rgba(21, 101, 192, 0.1);
}

.sidebar nav a:hover:not(.active)::before {
    width: 100%;
}

.sidebar nav a.active {
    background: linear-gradient(135deg, #1565c0, #1976d2) !important;
    color: #ffffff !important;
    border-left-color: #0d47a1;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(21, 101, 192, 0.3), inset 0 1px 0 rgba(255,255,255,0.2);
    position: relative;
}

/* Specific hover rule for active items to prevent them from turning white */
.sidebar nav a.active:hover {
    background: linear-gradient(135deg, #1976d2, #1e88e5) !important;
    color: #ffffff !important;
    border-left-color: #0d47a1 !important;
    transform: translateX(2px);
    box-shadow: 0 4px 16px rgba(21, 101, 192, 0.4), inset 0 1px 0 rgba(255,255,255,0.3) !important;
}

.sidebar nav a.active::after {
    content: '';
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    width: 4px;
    height: 4px;
    /*background: #ffffff;*/
    border-radius: 50%;
    box-shadow: 0 0 8px rgba(255,255,255,0.5);
}

.sidebar nav a i {
    width: 22px;
    margin-right: 0.875rem;
    text-align: center;
    font-size: 1.1rem;
    position: relative;
    z-index: 1;
}

.sidebar nav a:hover i {
    transform: scale(1.1);
}

.sidebar-footer {
    margin-top: auto;
    padding: 1.25rem;
    border-top: 1px solid rgba(255,255,255,0.1);
    background: rgba(0,0,0,0.2);
}

.logout-btn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    padding: 0.875rem !important;
    background: linear-gradient(135deg, #dc3545, #c82333) !important;
    color: white !important;
    text-decoration: none !important;
    border-radius: 8px !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3) !important;
    position: relative !important;
    overflow: hidden !important;
    margin: 0 !important;
}

.logout-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
}

.logout-btn:hover {
    background: linear-gradient(135deg, #e74c3c, #dc2626) !important;
    color: white !important;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4) !important;
}

.logout-btn:hover::before {
    left: 100%;
}

.logout-btn:active {
    transform: translateY(0);
}

.logout-btn i {
    margin-right: 0.5rem;
    font-size: 1rem;
}

.sidebar-copyright {
    text-align: center;
    color: #6c757d;
    font-size: 0.7rem;
    margin-top: 0.875rem;
    opacity: 0.7;
    font-weight: 500;
    letter-spacing: 0.5px;
}

/* Mobile Responsiveness */
@media (max-width: 991.98px) {
    .sidebar {
        transform: translateX(-100%);
        z-index: 9999;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .menu-toggle {
        position: fixed;
        top: 1rem;
        left: 1rem;
        z-index: 10000;
        background: #1565c0;
        color: white;
        border: none;
        padding: 0.75rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(21, 101, 192, 0.3);
        transition: all 0.3s ease;
    }
    
    .menu-toggle:hover {
        background: #1976d2;
        transform: scale(1.05);
    }
}

/* Zoom level responsiveness */
@media (min-resolution: 120dpi) and (max-resolution: 150dpi) {
    .sidebar {
        width: 240px;
    }
}

@media (min-resolution: 150dpi) {
    .sidebar {
        width: 230px;
    }
    
    .sidebar-header {
        padding: 1.25rem 0.875rem;
    }
    
    .sidebar nav a {
        padding: 0.75rem 1rem;
        font-size: 0.85rem;
    }
    
    .sidebar-section-title {
        padding: 0.625rem 1rem 0.375rem 1rem;
        font-size: 0.65rem;
    }
}

/* Better container handling for all zoom levels */
.container-fluid {
    width: 100% !important;
    max-width: 100vw !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 250px !important;
}

/* Ensure sidebar positioning takes priority */
aside.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 250px !important;
    height: 100vh !important;
    z-index: 1050 !important;
}

/* Smooth animations */
* {
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
</style>
<aside class="sidebar d-flex flex-column">
    <div class="sidebar-header">
        <?php if ($cashier_profile_picture && file_exists(__DIR__ . '/../assets/uploads/profile_pictures/' . $cashier_profile_picture)): ?>
            <div style="display: flex; justify-content: center; margin-bottom: 0.5rem;">
                <img src="<?= BASE_URL ?>/assets/uploads/profile_pictures/<?= htmlspecialchars($cashier_profile_picture) ?>" 
                     alt="Cashier Profile" 
                     class="sidebar-logo"
                     style="width: 48px !important; height: 48px !important; border-radius: 50% !important; object-fit: cover !important; border: 2px solid #1565c0 !important; box-shadow: 0 2px 8px rgba(21, 101, 192, 0.2) !important; display: block !important;">
            </div>
        <?php else: ?>
            <div style="display: flex; justify-content: center; margin-bottom: 0.5rem;">
                <img src="<?= BASE_URL ?>/assets/images/johntech.jpg" alt="JohnTech Logo" class="sidebar-logo"
                     style="width: 48px !important; height: 48px !important; border-radius: 50% !important; object-fit: cover !important; border: 2px solid #1565c0 !important; box-shadow: 0 2px 8px rgba(21, 101, 192, 0.2) !important; display: block !important;">
            </div>
        <?php endif; ?>
        <h3 class="sidebar-title">JohnTech POS</h3>
        <p class="sidebar-subtitle"><?php echo $branch_name; ?> Branch</p>
    </div>
    
    <nav class="flex-grow-1">
    <div class="sidebar-section">
            <div class="sidebar-section-title">Cashier Operations</div>
            <a href="<?= BASE_URL ?>/pages/cashier/starting_cash.php" class="<?php if(strpos($current_page, 'starting_cash') !== false) echo 'active'; ?>">
                <i class="bi bi-cash-coin"></i>
                Starting Cash
            </a>
        </div>
        <div class="sidebar-section">
            <a href="<?= BASE_URL ?>/pages/cashier/pos_<?php echo $branch_suffix; ?>.php" class="<?php if(strpos($current_page, 'pos') !== false) echo 'active'; ?>">
                <i class="bi bi-cart-check"></i>
                Point of Sale
            </a>
        </div>

        <div class="sidebar-section">
            <div class="sidebar-section-title">Inventory & Sales</div>
            <a href="<?= BASE_URL ?>/pages/cashier/inventory_<?php echo $branch_suffix; ?>.php" class="<?php if(strpos($current_page, 'inventory') !== false) echo 'active'; ?>">
                <i class="bi bi-boxes"></i>
                Inventory View
            </a>
            <a href="<?= BASE_URL ?>/pages/cashier/sales_history_<?php echo $branch_suffix; ?>.php" class="<?php if(strpos($current_page, 'sales_history') !== false) echo 'active'; ?>">
                <i class="bi bi-clock-history"></i>
                Sales History
            </a>
        </div>

            <div class="sidebar-section">
            <div class="sidebar-section-title">Returns Management</div>    
            <a href="<?= BASE_URL ?>/pages/cashier/returns_management.php" class="<?php if(strpos($current_page, 'return') !== false) echo 'active'; ?>">
                <i class="bi bi-arrow-return-left"></i>
                Returns Management
            </a>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout.php" class="logout-btn">
            <i class="bi bi-box-arrow-right"></i>
            Log Out
        </a>
        <div class="sidebar-copyright">&copy; <?php echo date('Y'); ?> JohnTech System</div>
    </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Enhanced mobile menu functionality
    const menuToggle = document.createElement('button');
    menuToggle.className = 'menu-toggle d-lg-none';
    menuToggle.innerHTML = '<i class="bi bi-list"></i>';
    menuToggle.setAttribute('aria-label', 'Toggle navigation menu');
    document.body.appendChild(menuToggle);

    const sidebar = document.querySelector('.sidebar');
    
    // Toggle sidebar on mobile
    menuToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        sidebar.classList.toggle('show');
        
        // Update icon
        const icon = menuToggle.querySelector('i');
        if (sidebar.classList.contains('show')) {
            icon.className = 'bi bi-x';
            menuToggle.style.background = '#dc3545';
        } else {
            icon.className = 'bi bi-list';
            menuToggle.style.background = '#1565c0';
        }
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 991.98 && 
            !sidebar.contains(e.target) && 
            !menuToggle.contains(e.target) && 
            sidebar.classList.contains('show')) {
            
            sidebar.classList.remove('show');
            const icon = menuToggle.querySelector('i');
            icon.className = 'bi bi-list';
            menuToggle.style.background = '#1565c0';
        }
    });

    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 991.98) {
            sidebar.classList.remove('show');
            const icon = menuToggle.querySelector('i');
            icon.className = 'bi bi-list';
            menuToggle.style.background = '#1565c0';
        }
    });

    // Add smooth scrolling to sidebar
    sidebar.style.scrollBehavior = 'smooth';

    // Remove loading animation to prevent transition effects
    // sidebar.style.opacity = '0';
    // sidebar.style.transform = 'translateX(-20px)';
    
    // setTimeout(() => {
    //     sidebar.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    //     sidebar.style.opacity = '1';
    //     sidebar.style.transform = 'translateX(0)';
    // }, 100);
});
</script>
