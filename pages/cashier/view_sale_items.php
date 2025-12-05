<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
include '../../includes/cashier_sidebar.php';

// Get sale ID from URL
$sale_id = isset($_GET['sale_id']) ? intval($_GET['sale_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if (!$sale_id || !$branch_id) {
    header('Location: returns_management.php');
    exit;
}

// Get branch information
$branch_name = ($branch_id == 1) ? 'Sorsogon' : 'Juban';

// Fetch sale details
$sql = "SELECT s.*, cp.name AS cashier_name, m.name AS mechanic_name 
        FROM sales s 
        LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id 
        LEFT JOIN mechanics m ON s.mechanic_id = m.id 
        WHERE s.id = ? AND s.branch_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $sale_id, $branch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sale = mysqli_fetch_assoc($result);

if (!$sale) {
    header('Location: returns_management.php');
    exit;
}

// Fetch sale items
$items_sql = "SELECT si.*, p.name, p.unit FROM sale_items si 
             JOIN products p ON si.product_id = p.id 
             WHERE si.sale_id = ?";
$items_stmt = mysqli_prepare($conn, $items_sql);
mysqli_stmt_bind_param($items_stmt, 'i', $sale_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);

function peso($n) { return '₱' . number_format($n, 2); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale #<?= $sale_id ?> - Items Details - JohnTech System</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
</head>
<body class="cashier-page" style="margin: 0 !important; padding: 0 !important; background-color: #f8f9fa;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <div class="mb-4">
            <h2 class="mb-0">
                <i class="bi bi-receipt me-2"></i>Sale #<?= $sale_id ?> - Items Details
            </h2>
        </div>
        
        <div class="content-card">
            <!-- Sale Items Table -->
            <div class="table-responsive mb-4">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Item Name</th>
                            <th>Unit</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $items_total = 0;
                        while ($item = mysqli_fetch_assoc($items_result)): 
                            $subtotal = $item['quantity'] * $item['price'];
                            $items_total += $subtotal;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                                <td><?= htmlspecialchars($item['unit']) ?></td>
                                <td><?= $item['quantity'] ?></td>
                                <td><?= peso($item['price']) ?></td>
                                <td><?= peso($subtotal) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                    <tfoot class="table-secondary">
                        <tr>
                            <th colspan="4">Items Total:</th>
                            <th><?= peso($items_total) ?></th>
                        </tr>
                        <?php if ($sale['repair_fee'] > 0): ?>
                            <tr>
                                <th colspan="4">Repair/Service Fee:</th>
                                <th><?= peso($sale['repair_fee']) ?></th>
                            </tr>
                        <?php endif; ?>
                        <tr class="table-dark">
                            <th colspan="4">Grand Total:</th>
                            <th><?= peso($sale['total_amount']) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Sale Details -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Sale Information</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li><strong>Sale ID:</strong> #<?= $sale['id'] ?></li>
                                <li><strong>Date:</strong> <?= date('F j, Y g:i A', strtotime($sale['created_at'])) ?></li>
                                <li><strong>Branch:</strong> <?= $branch_name ?></li>
                                <li><strong>Cashier:</strong> <?= htmlspecialchars($sale['cashier_name']) ?></li>
                                <li><strong>Mechanic:</strong> <?= $sale['mechanic_name'] ? htmlspecialchars($sale['mechanic_name']) : 'None' ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Payment Details</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li><strong>Total Amount:</strong> <?= peso($sale['total_amount']) ?></li>
                                <li><strong>Amount Received:</strong> <?= peso($sale['amount_received']) ?></li>
                                <li><strong>Change:</strong> <?= peso($sale['change_amount']) ?></li>
                                <?php if (!empty($sale['remarks'])): ?>
                                    <li><strong>Remarks:</strong> <?= htmlspecialchars($sale['remarks']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-12 text-center">
                    <a href="returns_management.php" class="btn btn-secondary me-3">
                        <i class="bi bi-arrow-left me-2"></i>Back to Returns
                    </a>
                    <a href="return_items.php?sale_id=<?= $sale_id ?>&branch_id=<?= $branch_id ?>" 
                       class="btn btn-warning">
                        <i class="bi bi-arrow-return-left me-2"></i>Process Return
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>

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
    color: #2c3e50;
    font-weight: 600;
}

.content-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(44,49,54,0.08);
    padding: 25px;
    border: 1px solid #e9ecef;
}

.table th {
    background-color: #f8f9fa;
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

.card {
    border: none;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.card-header {
    border-radius: 8px 8px 0 0 !important;
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
</style>

</body>
</html>
