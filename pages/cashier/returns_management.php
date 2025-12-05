<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
include '../../includes/cashier_sidebar.php';

// Get branch information
$branch_id = isset($_SESSION['branch']) ? intval($_SESSION['branch']) : 1;
$branch_name = ($branch_id == 1) ? 'Sorsogon' : 'Juban';

// Date filter - default to last 30 days
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Fetch recent sales that can potentially be returned
$sql = "SELECT s.*, cp.name AS cashier_name, m.name AS mechanic_name,
        (SELECT COUNT(*) FROM return_items ri 
         JOIN returns r ON ri.return_id = r.id 
         WHERE r.sale_id = s.id) as return_count
        FROM sales s
        LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id
        LEFT JOIN mechanics m ON s.mechanic_id = m.id
        WHERE s.branch_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
        ORDER BY s.created_at DESC
        LIMIT 50";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'iss', $branch_id, $start_date, $end_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sales = [];
while ($row = mysqli_fetch_assoc($result)) {
    $sales[] = $row;
}

function peso($n) { return '₱' . number_format($n, 2); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Returns Management - JohnTech System</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <style>
        /* Fix modal z-index and positioning issues */
        .modal {
            z-index: 1055 !important;
        }
        .modal-backdrop {
            z-index: 1050 !important;
        }
        .modal-dialog {
            z-index: 1060 !important;
            margin: 1.75rem auto !important;
        }
        .modal-content {
            background-color: white !important;
            border: 1px solid rgba(0,0,0,.2) !important;
            border-radius: 0.375rem !important;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.15) !important;
        }
        
        /* Ensure proper modal visibility */
        .modal.show {
            display: block !important;
        }
        .modal.show .modal-dialog {
            transform: none !important;
        }
        
        /* Fix any overflow issues */
        body.modal-open {
            overflow: hidden !important;
        }
    </style>
</head>
<body class="cashier-page" style="margin: 0 !important; padding: 0 !important; background-color: #f8f9fa;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <h2 class="mb-4 mt-0 pt-0">
            <i class="bi bi-arrow-return-left me-2"></i>Returns Management - <?= $branch_name ?> Branch
        </h2>
        
        <div class="content-card">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>How to Process Returns:</strong> Select a sale from the list below and click the "Return Items" button to process returns for that transaction.
                    </div>
                </div>
            </div>

            <!-- Date Filter -->
            <form method="get" class="row g-3 align-items-end mb-4">
                <div class="col-md-4">
                    <label for="start_date" class="form-label">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-4">
                    <label for="end_date" class="form-label">End Date:</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-2"></i>Filter Sales
                    </button>
                    <a href="?" class="btn btn-outline-secondary ms-2">Reset</a>
                </div>
            </form>

            <!-- Sales List -->
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th><i class="bi bi-receipt me-1"></i>Sale ID</th>
                            <th><i class="bi bi-calendar me-1"></i>Date</th>
                            <th><i class="bi bi-person me-1"></i>Cashier</th>
                            <th><i class="bi bi-tools me-1"></i>Mechanic</th>
                            <th><i class="bi bi-currency-peso me-1"></i>Total</th>
                            <th><i class="bi bi-box me-1"></i>Items</th>
                            <th><i class="bi bi-arrow-return-left me-1"></i>Returns</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                                    <div class="text-muted mt-2">No sales found for the selected date range</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td>
                                        <strong>#<?= $sale['id'] ?></strong>
                                    </td>
                                    <td><?= date('M j, Y H:i', strtotime($sale['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($sale['cashier_name']) ?></td>
                                    <td><?= $sale['mechanic_name'] ? htmlspecialchars($sale['mechanic_name']) : '<span class="text-muted">-</span>' ?></td>
                                    <td><?= peso($sale['total_amount']) ?></td>
                                    <td>
                                        <a href="view_sale_items.php?sale_id=<?= $sale['id'] ?>&branch_id=<?= $branch_id ?>" 
                                           class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-eye me-1"></i>View Items
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($sale['return_count'] > 0): ?>
                                            <span class="badge bg-warning">
                                                <i class="bi bi-arrow-return-left me-1"></i><?= $sale['return_count'] ?> returned
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="return_items.php?sale_id=<?= $sale['id'] ?>&branch_id=<?= $branch_id ?>" 
                                           class="btn btn-warning btn-sm">
                                            <i class="bi bi-arrow-return-left me-1"></i>Return Items
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>

<script>
// Clean modal implementation without aria-hidden conflicts
document.addEventListener('DOMContentLoaded', function() {
    // Ensure Bootstrap is loaded
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap JS not loaded!');
        return;
    }
    
    // Store original focus element for each modal
    const modalFocusMap = new Map();
    
    // Handle all modal events globally
    document.addEventListener('show.bs.modal', function(event) {
        const modal = event.target;
        const triggerButton = event.relatedTarget || document.activeElement;
        
        // Store the trigger button for this modal
        modalFocusMap.set(modal.id, triggerButton);
    });
    
    document.addEventListener('hide.bs.modal', function(event) {
        const modal = event.target;
        
        // Immediately blur any focused elements inside the modal
        const focusedElement = modal.querySelector(':focus');
        if (focusedElement) {
            focusedElement.blur();
        }
        
        // Move focus back to the trigger button
        const triggerButton = modalFocusMap.get(modal.id);
        if (triggerButton && triggerButton.offsetParent !== null) {
            // Use setTimeout to ensure focus happens after Bootstrap processes
            setTimeout(() => {
                triggerButton.focus();
            }, 0);
        }
    });
    
    document.addEventListener('hidden.bs.modal', function(event) {
        const modal = event.target;
        
        // Clean up our focus map
        modalFocusMap.delete(modal.id);
        
        // Remove any lingering backdrops
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.remove();
        });
        
        // Restore body scroll
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';
        document.body.style.overflow = '';
    });
    
    // Handle View Items button clicks
    document.querySelectorAll('[data-bs-toggle="modal"]').forEach(function(button) {
        button.addEventListener('click', function(e) {
            const targetSelector = this.getAttribute('data-bs-target');
            const targetModal = document.querySelector(targetSelector);
            
            if (targetModal) {
                // Close any existing modals first
                document.querySelectorAll('.modal.show').forEach(existingModal => {
                    const instance = bootstrap.Modal.getInstance(existingModal);
                    if (instance) {
                        instance.hide();
                    }
                });
                
                // Store this button as the trigger for the modal
                modalFocusMap.set(targetModal.id, this);
                
                // Show the modal
                const modal = new bootstrap.Modal(targetModal, {
                    backdrop: true,
                    keyboard: true,
                    focus: false // Disable automatic focus to prevent conflicts
                });
                modal.show();
            }
        });
    });
    
    // Handle escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modalInstance = bootstrap.Modal.getInstance(openModal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
        }
    });
});
</script>

<style>
body {
    margin: 0 !important;
    padding: 0 !important;
    background-color: #f8f9fa !important;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.container-fluid {
    margin: 0 !important;
    padding: 0 !important;
}

.main-content {
    margin-left: 250px !important;
    margin-right: 20px !important;
    margin-top: 20px !important;
    margin-bottom: 20px !important;
    padding: 1.5rem 1rem !important;
    min-height: calc(100vh - 40px);
    transition: all 0.3s ease-in-out;
    max-width: calc(100vw - 270px);
    overflow-x: auto;
}

h2 {
    margin: 0 0 1rem 0 !important;
    padding: 0 !important;
    color: #2c3e50;
    font-weight: 600;
}

.content-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(44,49,54,0.08);
    padding: 25px;
    margin: 0 !important;
    transition: all 0.3s ease;
    border: 1px solid #e9ecef;
}

.table th {
    background-color: #343a40;
    color: white;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    vertical-align: middle;
}

.btn {
    transition: all 0.2s ease;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important;
        margin-right: 10px !important;
        padding: 1rem 0.5rem !important;
        max-width: 100vw;
    }
    
    .table-responsive {
        font-size: 0.9rem;
    }
}

/* Modal Improvements */
.modal {
    z-index: 1055 !important;
}

.modal-backdrop {
    z-index: 1050 !important;
}

.modal-dialog {
    margin: 1.75rem auto;
}

.modal-content {
    border: none;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

.modal-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 1px solid #dee2e6;
    border-radius: 10px 10px 0 0;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    border-radius: 0 0 10px 10px;
}

.modal-body {
    max-height: 70vh;
    overflow-y: auto;
}

/* Custom close button */
.btn-close {
    font-size: 1.2rem;
    opacity: 0.8;
}

.btn-close:hover {
    opacity: 1;
    transform: scale(1.1);
}
</style>

<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>

</body>
</html>
