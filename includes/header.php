<?php include 'session.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JohnTech Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css?v=<?= time() ?>">
    
    <!-- Instant page visibility - no FOUC delays -->
    <style>
        /* Show page immediately - no hidden states */
        body {
            background: #f8fafc !important;
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Critical sidebar styles to prevent layout shift */
        .sidebar {
            background: linear-gradient(180deg, #1a1d23 0%, #23272b 100%) !important;
            color: #ffffff !important;
            height: 100vh !important;
            width: 250px !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1050 !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15) !important;
            overflow-y: auto !important;
        }
        
        /* Critical main content positioning */
        .container-fluid {
            margin-left: 250px !important;
        }
        
        .navbar-modern {
            background: linear-gradient(90deg, #ffffff 0%, rgba(248, 250, 252, 0.95) 100%) !important;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #f1f5f9;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        
        .navbar-modern .navbar-brand {
            font-weight: 700;
            font-size: 1.125rem;
            background: linear-gradient(135deg, #1565c0 0%, #42a5f5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .navbar-modern .nav-link {
            color: #64748b !important;
            font-weight: 500;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <!-- Enhanced Top Navbar -->
    <nav class="navbar navbar-expand-lg navbar-modern">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-gear me-2"></i>
                JohnTech Management System
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="bi bi-person-circle me-1"></i>
                            Welcome, <?php echo isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'User'; ?>
                        </span>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="bi bi-clock me-1"></i>
                            <?php echo date('h:i A'); ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>