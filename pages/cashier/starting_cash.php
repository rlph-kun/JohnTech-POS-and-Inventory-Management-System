<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
include '../../includes/cashier_sidebar.php';

$cashier_id = $_SESSION['user_id'];
$branch_id = $_SESSION['branch'];
$date = date('Y-m-d');
$message = '';
$message_type = '';
$success_starting = false;
$success_closing = false;

// Check existing records
$starting_cash_exists = false;
$closing_cash_exists = false;
$starting_cash_data = null;
$closing_cash_data = null;

$check_starting = mysqli_query($conn, "SELECT * FROM starting_cash WHERE branch_id=$branch_id AND date='$date'");
if (mysqli_num_rows($check_starting) > 0) {
    $starting_cash_exists = true;
    $starting_cash_data = mysqli_fetch_assoc($check_starting);
}

$check_closing = mysqli_query($conn, "SELECT * FROM closing_cash WHERE branch_id=$branch_id AND date='$date'");
if (mysqli_num_rows($check_closing) > 0) {
    $closing_cash_exists = true;
    $closing_cash_data = mysqli_fetch_assoc($check_closing);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $denoms = [
        1000 => intval($_POST['denom_1000'] ?? 0),
        500  => intval($_POST['denom_500'] ?? 0),
        200  => intval($_POST['denom_200'] ?? 0),
        100  => intval($_POST['denom_100'] ?? 0),
        50   => intval($_POST['denom_50'] ?? 0),
        20   => intval($_POST['denom_20'] ?? 0),
        10   => intval($_POST['denom_10'] ?? 0),
        5    => intval($_POST['denom_5'] ?? 0),
        1    => intval($_POST['denom_1'] ?? 0),
    ];
    $total = 0;
    foreach ($denoms as $value => $qty) {
        $total += $value * $qty;
    }

    if ($action === 'starting_cash') {
        // Handle starting cash submission
        if ($starting_cash_exists) {
            $message = 'Starting cash already set for today.';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("INSERT INTO starting_cash (branch_id, cashier_id, date, denom_1000, denom_500, denom_200, denom_100, denom_50, denom_20, denom_10, denom_5, denom_1, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisiiiiiiiid', $branch_id, $cashier_id, $date, $denoms[1000], $denoms[500], $denoms[200], $denoms[100], $denoms[50], $denoms[20], $denoms[10], $denoms[5], $denoms[1], $total);
            if ($stmt->execute()) {
                $message = 'Starting cash saved successfully!';
                $message_type = 'success';
                $success_starting = true;
                $starting_cash_exists = true;
                // Refresh starting cash data
                $check_starting = mysqli_query($conn, "SELECT * FROM starting_cash WHERE branch_id=$branch_id AND date='$date'");
                $starting_cash_data = mysqli_fetch_assoc($check_starting);
            } else {
                $message = 'Error saving starting cash.';
                $message_type = 'danger';
            }
        }
    } elseif ($action === 'closing_cash') {
        // Handle closing cash submission
        // Check if starting cash exists first
        if (!$starting_cash_exists) {
            $message = 'Starting cash must be set before closing cash can be recorded.';
            $message_type = 'warning';
        } elseif ($closing_cash_exists) {
            $message = 'Closing cash already set for today.';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("INSERT INTO closing_cash (branch_id, cashier_id, date, denom_1000, denom_500, denom_200, denom_100, denom_50, denom_20, denom_10, denom_5, denom_1, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisiiiiiiiid', $branch_id, $cashier_id, $date, $denoms[1000], $denoms[500], $denoms[200], $denoms[100], $denoms[50], $denoms[20], $denoms[10], $denoms[5], $denoms[1], $total);
            if ($stmt->execute()) {
                $message = 'Closing cash saved successfully!';
                $message_type = 'success';
                $success_closing = true;
                $closing_cash_exists = true;
                // Refresh closing cash data
                $check_closing = mysqli_query($conn, "SELECT * FROM closing_cash WHERE branch_id=$branch_id AND date='$date'");
                $closing_cash_data = mysqli_fetch_assoc($check_closing);
            } else {
                $message = 'Error saving closing cash.';
                $message_type = 'danger';
            }
        }
    }
}

// Calculate net sales (closing - starting) if both exist
$net_sales = null;
if ($starting_cash_exists && $closing_cash_exists && $starting_cash_data && $closing_cash_data) {
    $net_sales = floatval($closing_cash_data['total_amount']) - floatval($starting_cash_data['total_amount']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Management - JohnTech System</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
    <style>
        .cash-form-table th, .cash-form-table td {
            vertical-align: middle;
            text-align: center;
        }
        .cash-form-table input[type='number'] {
            width: 80px;
            text-align: right;
        }
        .total-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0d6efd;
        }
        .net-sales-amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: #198754;
        }
        .section-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            background: #fff;
            height: 100%;
        }
        .table-scroll-container {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .table-scroll-container thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8f9fa;
        }
        .table-scroll-container::-webkit-scrollbar {
            width: 8px;
        }
        .table-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .section-header {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
        }
        .section-header h4 {
            margin: 0;
            color: #495057;
        }
        .section-header.closing {
            border-bottom-color: #dc3545;
        }
        .section-header.closing h4 {
            color: #dc3545;
        }
        .section-header.starting {
            border-bottom-color: #198754;
        }
        .section-header.starting h4 {
            color: #198754;
        }
        .disabled-section {
            opacity: 0.6;
            pointer-events: none;
        }
        .disabled-section .form-control {
            background-color: #e9ecef;
        }
        .info-badge {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        .container-fluid {
            margin-left: 250px !important;
            padding: 0 !important;
            margin-top: 0 !important;
        }
        .main-content {
            padding: 1.5rem 1rem !important;
            margin-top: 0 !important;
            padding-top: 1.5rem !important;
        }
        h2 {
            margin-top: 0 !important;
            padding-top: 0 !important;
            margin-bottom: 1.5rem !important;
        }
        body {
            margin: 0 !important;
            padding: 0 !important;
        }
        .btn::before {
            display: none !important;
        }
        .cash-form-table tr:hover {
            background: transparent !important;
        }
        .table-hover tbody tr:hover {
            background: rgba(21, 101, 192, 0.02) !important;
        }
        .btn:hover::before {
            display: none !important;
        }
        .content-card:hover {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07), 0 2px 4px rgba(0, 0, 0, 0.06) !important;
            border-color: #f1f5f9 !important;
        }
        .bi::before {
            display: inline-block !important;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        .summary-card h5 {
            color: white;
            margin-bottom: 1rem;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        .summary-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 1.2rem;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body style="margin: 0 !important; padding: 0 !important;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <h2 class="mb-2 mt-0 pt-0"><i class="bi bi-cash-coin me-2"></i>Cash Management - <?= date('F d, Y') ?></h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Starting Cash Section (Left) -->
            <div class="col-md-6">
                <div class="section-card">
                    <div class="section-header starting">
                        <h4><i class="bi bi-box-arrow-in-up me-2"></i>Starting Cash</h4>
                    </div>

                    <?php if ($starting_cash_exists): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>Starting cash has been recorded for today.
                        </div>
                        <div class="table-scroll-container">
                            <table class="table table-bordered cash-form-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Denomination</th>
                                        <th>Count</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $denom_icons = [
                                        1000 => 'bi-cash-stack',
                                        500  => 'bi-cash-stack',
                                        200  => 'bi-cash-stack',
                                        100  => 'bi-cash-stack',
                                        50   => 'bi-cash-stack',
                                        20   => 'bi-cash-stack',
                                        10   => 'bi-coin',
                                        5    => 'bi-coin',
                                        1    => 'bi-coin',
                                    ];
                                    foreach ([1000,500,200,100,50,20,10,5,1] as $denom):
                                        $field = 'denom_' . $denom;
                                        $count = intval($starting_cash_data[$field] ?? 0);
                                        $total = $count * $denom;
                                    ?>
                                    <tr>
                                        <td><i class="bi <?= $denom_icons[$denom] ?> me-1"></i>₱<?= $denom ?></td>
                                        <td><?= $count ?></td>
                                        <td>₱<?= number_format($total, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mb-3 text-end">
                            <span class="me-2 fw-bold">Total Starting Cash:</span>
                            <span class="total-amount">₱<?= number_format($starting_cash_data['total_amount'], 2) ?></span>
                        </div>
                    <?php else: ?>
                        <form method="post" id="startingCashForm" autocomplete="off">
                            <input type="hidden" name="action" value="starting_cash">
                            <div class="table-scroll-container">
                                <table class="table table-bordered cash-form-table mb-4">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Denomination</th>
                                            <th>Count</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $denom_icons = [
                                            1000 => 'bi-cash-stack',
                                            500  => 'bi-cash-stack',
                                            200  => 'bi-cash-stack',
                                            100  => 'bi-cash-stack',
                                            50   => 'bi-cash-stack',
                                            20   => 'bi-cash-stack',
                                            10   => 'bi-coin',
                                            5    => 'bi-coin',
                                            1    => 'bi-coin',
                                        ];
                                        foreach ([1000,500,200,100,50,20,10,5,1] as $denom): ?>
                                        <tr>
                                            <td><i class="bi <?= $denom_icons[$denom] ?> me-1"></i>₱<?= $denom ?></td>
                                            <td>
                                                <input type="number" min="0" name="denom_<?= $denom ?>" id="starting_denom_<?= $denom ?>" class="form-control text-end" value="0" oninput="updateStartingTotal()">
                                            </td>
                                            <td>
                                                <span id="starting_total_<?= $denom ?>">0.00</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mb-3 text-end">
                                <span class="me-2 fw-bold">Total Starting Cash:</span>
                                <span class="total-amount" id="startingGrandTotal">₱0.00</span>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-save me-2"></i>Save Starting Cash
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Closing Cash Section (Right) -->
            <div class="col-md-6">
                <div class="section-card <?= !$starting_cash_exists ? 'disabled-section' : '' ?>">
                    <div class="section-header closing">
                        <h4><i class="bi bi-box-arrow-in-down me-2"></i>Closing Cash</h4>
                    </div>
                    
                    <?php if (!$starting_cash_exists): ?>
                        <div class="info-badge bg-warning text-dark">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Starting cash must be set first before closing cash can be recorded.
                        </div>
                    <?php endif; ?>

                    <?php if ($closing_cash_exists): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>Closing cash has been recorded for today.
                        </div>
                        <div class="table-scroll-container">
                            <table class="table table-bordered cash-form-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Denomination</th>
                                        <th>Count</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    foreach ([1000,500,200,100,50,20,10,5,1] as $denom):
                                        $field = 'denom_' . $denom;
                                        $count = intval($closing_cash_data[$field] ?? 0);
                                        $total = $count * $denom;
                                    ?>
                                    <tr>
                                        <td><i class="bi <?= $denom_icons[$denom] ?> me-1"></i>₱<?= $denom ?></td>
                                        <td><?= $count ?></td>
                                        <td>₱<?= number_format($total, 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mb-3 text-end">
                            <span class="me-2 fw-bold">Total Closing Cash:</span>
                            <span class="total-amount">₱<?= number_format($closing_cash_data['total_amount'], 2) ?></span>
                        </div>
                    <?php else: ?>
                        <form method="post" id="closingCashForm" autocomplete="off">
                            <input type="hidden" name="action" value="closing_cash">
                            <div class="table-scroll-container">
                                <table class="table table-bordered cash-form-table mb-4">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Denomination</th>
                                            <th>Count</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ([1000,500,200,100,50,20,10,5,1] as $denom): ?>
                                        <tr>
                                            <td><i class="bi <?= $denom_icons[$denom] ?> me-1"></i>₱<?= $denom ?></td>
                                            <td>
                                                <input type="number" min="0" name="denom_<?= $denom ?>" id="closing_denom_<?= $denom ?>" class="form-control text-end" value="0" oninput="updateClosingTotal()" <?= !$starting_cash_exists ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <span id="closing_total_<?= $denom ?>">0.00</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mb-3 text-end">
                                <span class="me-2 fw-bold">Total Closing Cash:</span>
                                <span class="total-amount" id="closingGrandTotal">₱0.00</span>
                            </div>
                            <button type="submit" class="btn btn-danger w-100" <?= !$starting_cash_exists ? 'disabled' : '' ?>>
                                <i class="bi bi-save me-2"></i>Save Closing Cash
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Summary Card (if both exist) -->
        <?php if ($starting_cash_exists && $closing_cash_exists && $net_sales !== null): ?>
        <div class="summary-card">
            <h5><i class="bi bi-calculator me-2"></i>Daily Cash Summary</h5>
            <div class="summary-row">
                <span>Starting Cash:</span>
                <span>₱<?= number_format($starting_cash_data['total_amount'], 2) ?></span>
            </div>
            <div class="summary-row">
                <span>Closing Cash:</span>
                <span>₱<?= number_format($closing_cash_data['total_amount'], 2) ?></span>
            </div>
            <div class="summary-row">
                <span>Net Sales (Cash Collected):</span>
                <span>₱<?= number_format($net_sales, 2) ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function updateStartingTotal() {
    let denoms = [1000,500,200,100,50,20,10,5,1];
    let grandTotal = 0;
    denoms.forEach(function(denom) {
        let count = parseInt(document.getElementById('starting_denom_' + denom).value) || 0;
        let total = count * denom;
        document.getElementById('starting_total_' + denom).innerText = total.toFixed(2);
        grandTotal += total;
    });
    document.getElementById('startingGrandTotal').innerText = '₱' + grandTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

function updateClosingTotal() {
    let denoms = [1000,500,200,100,50,20,10,5,1];
    let grandTotal = 0;
    denoms.forEach(function(denom) {
        let count = parseInt(document.getElementById('closing_denom_' + denom).value) || 0;
        let total = count * denom;
        document.getElementById('closing_total_' + denom).innerText = total.toFixed(2);
        grandTotal += total;
    });
    document.getElementById('closingGrandTotal').innerText = '₱' + grandTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

document.addEventListener('DOMContentLoaded', function() {
    updateStartingTotal();
    updateClosingTotal();
});
</script>

<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>

</body>
</html>
