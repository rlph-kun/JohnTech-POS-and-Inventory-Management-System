<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';

// Check if include worked
if (file_exists('../../includes/cashier_sidebar.php')) {
    include '../../includes/cashier_sidebar.php';
} else {
    echo '<div class="alert alert-warning">Sidebar file not found</div>';
}

// Get sale ID from URL
$sale_id = isset($_GET['sale_id']) ? intval($_GET['sale_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if (!$sale_id || !$branch_id) {
    echo '<div class="alert alert-danger">Invalid sale or branch ID.</div>';
    exit;
}

// Check database connection
if (!$conn) {
    echo '<div class="alert alert-danger">Database connection failed: ' . mysqli_connect_error() . '</div>';
    exit;
}

// Fetch sale details
$sql = "SELECT s.*, cp.name AS cashier_name, m.name AS mechanic_name 
        FROM sales s 
        LEFT JOIN cashier_profiles cp ON s.cashier_id = cp.user_id 
        LEFT JOIN mechanics m ON s.mechanic_id = m.id 
        WHERE s.id = ? AND s.branch_id = ?";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo '<div class="alert alert-danger">SQL prepare failed: ' . mysqli_error($conn) . '</div>';
    exit;
}
mysqli_stmt_bind_param($stmt, 'ii', $sale_id, $branch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$sale = mysqli_fetch_assoc($result);

if (!$sale) {
    echo '<div class="alert alert-warning">Sale not found or you do not have permission to view this sale.</div>';
    echo '<a href="sales_history_branch' . $branch_id . '.php" class="btn btn-secondary">Back to Sales History</a>';
    exit;
}

// Fetch sale items with returned quantity
$sql_items = "SELECT si.*, p.name, p.unit, (
    SELECT COALESCE(SUM(ri.quantity), 0)
    FROM return_items ri
    JOIN returns r ON ri.return_id = r.id
    WHERE r.sale_id = si.sale_id AND ri.product_id = si.product_id
) AS returned_qty
FROM sale_items si 
JOIN products p ON si.product_id = p.id 
WHERE si.sale_id = ?
ORDER BY p.name";
$stmt = mysqli_prepare($conn, $sql_items);
if (!$stmt) {
    echo '<div class="alert alert-danger">SQL prepare failed for items: ' . mysqli_error($conn) . '</div>';
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $sale_id);
mysqli_stmt_execute($stmt);
$result_items = mysqli_stmt_get_result($stmt);
$items = [];
while ($row = mysqli_fetch_assoc($result_items)) {
    $items[] = $row;
}

// Check if there are any items that can be returned
$can_return = false;
foreach ($items as $item) {
    if (($item['quantity'] - $item['returned_qty']) > 0) {
        $can_return = true;
        break;
    }
}

// Check if this is a "back job" scenario (has repair/service fee)
$repair_fee = isset($sale['repair_fee']) ? floatval($sale['repair_fee']) : 0;
$is_back_job = ($repair_fee > 0 && !empty($items));

// Check if repair fee has already been refunded in previous returns
// Repair fee should only be refunded ONCE - when products are first returned
// So we check if there are any previous return_items for this sale
$repair_fee_refunded = false;
if ($is_back_job) {
    $sql_check_previous_returns = "SELECT COUNT(*) as count FROM return_items ri
                                    JOIN returns r ON ri.return_id = r.id
                                    WHERE r.sale_id = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check_previous_returns);
    if ($stmt_check) {
        mysqli_stmt_bind_param($stmt_check, 'i', $sale_id);
        mysqli_stmt_execute($stmt_check);
        $result_check = mysqli_stmt_get_result($stmt_check);
        $check_row = mysqli_fetch_assoc($result_check);
        // If there are previous return_items, repair fee was likely already refunded
        // (Repair fee is refunded on the first return of products)
        $repair_fee_refunded = ($check_row['count'] > 0);
    }
}

// Debug information (remove this later)
if (empty($items)) {
    error_log("No items found for sale_id: $sale_id");
}

// Add test data if no sale found (for debugging)
if (isset($_GET['test']) && $_GET['test'] == '1' && empty($sale)) {
    $sale = [
        'id' => $sale_id,
        'created_at' => date('Y-m-d H:i:s'),
        'cashier_name' => 'Test Cashier',
        'mechanic_name' => 'Test Mechanic'
    ];
    $items = [
        [
            'product_id' => 1,
            'name' => 'Test Product',
            'unit' => 'pcs',
            'quantity' => 5,
            'returned_qty' => 0,
            'price' => 100.00
        ]
    ];
    $can_return = true;
}

function peso($n) { return '₱' . number_format($n, 2); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Return - JohnTech System</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
</head>
<body class="cashier-page" style="margin: 0 !important; padding: 0 !important; background-color: #f8f9fa;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <!-- Debug info for development -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="alert alert-info">
            <strong>Debug Info:</strong><br>
            Sale ID: <?= $sale_id ?><br>
            Branch ID: <?= $branch_id ?><br>
            Sale found: <?= $sale ? 'Yes' : 'No' ?><br>
            Items count: <?= count($items) ?><br>
            Can return: <?= $can_return ? 'Yes' : 'No' ?>
        </div>
        <?php endif; ?>
        
        <h2 class="mb-4 mt-0 pt-0"><i class="bi bi-arrow-return-left me-2"></i>Process Return</h2>
        <div class="content-card">
            <div class="row">
                <div class="col-12">
                        <?php if (empty($sale)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                No sale data found. Sale ID: <?= $sale_id ?>, Branch ID: <?= $branch_id ?>
                            </div>
                        <?php elseif (empty($items)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                No items found for this sale.
                            </div>
                        <?php else: ?>
                        <h4 class="mb-3">Sale Details</h4>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th>Sale ID:</th>
                                    <td><?= $sale['id'] ?></td>
                                    <th>Date:</th>
                                    <td><?= date('Y-m-d H:i', strtotime($sale['created_at'])) ?></td>
                                </tr>
                                <tr>
                                    <th>Cashier:</th>
                                    <td><?= htmlspecialchars($sale['cashier_name']) ?></td>
                                    <th>Mechanic:</th>
                                    <td><?= $sale['mechanic_name'] ? htmlspecialchars($sale['mechanic_name']) : '-' ?></td>
                                </tr>
                            </table>
                        </div>

                        <h4 class="mb-3 mt-4">Select Items to Return</h4>
                        
                        <?php if (!$can_return): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                All items from this sale have already been returned.
                            </div>
                        <?php else: ?>
                        
                        <?php if ($is_back_job && !$repair_fee_refunded): ?>
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-tools me-2"></i>
                                <strong>Back Job Detected:</strong> This sale includes a repair/service fee of <?= peso($repair_fee) ?>. 
                                When products are returned, the repair/service fee will also be refunded to the customer.
                            </div>
                        <?php endif; ?>
                        
                        <form id="returnForm">
                            <input type="hidden" name="sale_id" value="<?= $sale_id ?>">
                            <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                            <input type="hidden" name="repair_fee" id="repairFeeInput" value="<?= $repair_fee ?>">
                            <input type="hidden" id="saleTotalAmount" value="<?= floatval($sale['total_amount']) ?>">
                            <input type="hidden" id="saleDiscountAmount" value="<?= floatval($sale['discount']) ?>">
                            <input type="hidden" name="repair_fee_refunded" id="repairFeeRefundedInput" value="<?= $repair_fee_refunded ? '1' : '0' ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Item</th>
                                            <th>Original Qty</th>
                                            <th>Returned Qty</th>
                                            <th>Available</th>
                                            <th>Return Qty</th>
                                            <th>Condition</th>
                                            <th>Price</th>
                                            <th>Discount</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item):
                                            $max_returnable = $item['quantity'] - $item['returned_qty'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                                                <br><small class="text-muted">(<?= $item['unit'] ?>)</small>
                                            </td>
                                            <td><?= $item['quantity'] ?></td>
                                            <td><?= $item['returned_qty'] ?></td>
                                            <td>
                                                <span class="badge <?= $max_returnable > 0 ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $max_returnable ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($max_returnable > 0): ?>
                                                    <input type="number" class="form-control return-qty" 
                                                           data-id="<?= $item['product_id'] ?>"
                                                           data-name="<?= htmlspecialchars($item['name']) ?>"
                                                           data-price="<?= $item['price'] ?>"
                                                           data-line-total="<?= $item['price'] * $item['quantity'] ?>"
                                                           data-original-qty="<?= $item['quantity'] ?>"
                                                           min="0" max="<?= $max_returnable ?>" value="0"
                                                           style="width: 80px;">
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($max_returnable > 0): ?>
                                                    <select class="form-select item-condition" data-id="<?= $item['product_id'] ?>" style="width: 120px;">
                                                        <option value="good">Good</option>
                                                        <option value="damaged">Damaged</option>
                                                        <option value="broken">Broken</option>
                                                    </select>
                                                    <small class="text-muted condition-note" style="display: none;">
                                                        <i class="bi bi-exclamation-triangle text-warning"></i> Won't add to inventory
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= peso($item['price']) ?></td>
                                            <td class="item-discount">₱0.00</td>
                                            <td class="item-subtotal">₱0.00</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Reason for Return: <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control" rows="3" required 
                                          placeholder="Please provide a detailed reason for the return..."></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Return Amount Breakdown:</label>
                                <div class="card border-primary">
                                    <div class="card-body">
                                        <div class="row mb-2">
                                            <div class="col-6"><strong>Product Returns:</strong></div>
                                            <div class="col-6 text-end" id="productTotalAmount">₱0.00</div>
                                        </div>
                                        <?php if ($is_back_job && !$repair_fee_refunded): ?>
                                        <div class="row mb-2" id="repairFeeRow" style="display: none;">
                                            <div class="col-6"><strong>Repair/Service Fee Refund:</strong></div>
                                            <div class="col-6 text-end text-warning" id="repairFeeAmount"><?= peso($repair_fee) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <hr>
                                        <div class="row">
                                            <div class="col-6"><strong>Total Return Amount:</strong></div>
                                            <div class="col-6 text-end">
                                                <div id="totalAmount" class="fs-4 fw-bold text-primary">₱0.00</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="inventorySummary" class="mb-3" style="display: none;">
                                <div class="card border-info">
                                    <div class="card-header bg-info bg-opacity-10">
                                        <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Inventory Impact Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="goodItemsList" class="mb-2"></div>
                                        <div id="damagedItemsList"></div>
                                    </div>
                                </div>
                            </div>

                            <div id="returnError" class="text-danger fw-semibold mb-3"></div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>Process Return
                            </button>
                            <a href="sales_history_branch<?= $branch_id ?>.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Cancel
                            </a>
                        </form>
                        
                        <?php endif; ?>
                        <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

    <script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        function updateTotals() {
            let productGrossTotal = 0; // sum of qty * price (before discount)
            let productNetTotal = 0;   // sum after allocated discount
            let discountTotal = 0;

            const saleTotal = parseFloat($('#saleTotalAmount').val()) || 0;
            const saleDiscount = parseFloat($('#saleDiscountAmount').val()) || 0;

            $('.return-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                let price = parseFloat($(this).data('price')) || 0;
                let originalQty = parseFloat($(this).data('original-qty')) || 0;
                let lineTotal = parseFloat($(this).data('line-total')) || (price * originalQty);

                let gross = qty * price;

                // Calculate proportional discount for this returned qty
                let perUnitDiscount = 0;
                if (saleDiscount > 0 && saleTotal > 0 && originalQty > 0) {
                    // allocate discount proportional to this line's share of the sale total
                    perUnitDiscount = (lineTotal / saleTotal) * saleDiscount / originalQty;
                }
                let itemDiscount = perUnitDiscount * qty;
                let subtotal = gross - itemDiscount;

                // Update row displays
                $(this).closest('tr').find('.item-discount').text('₱' + itemDiscount.toFixed(2));
                $(this).closest('tr').find('.item-subtotal').text('₱' + subtotal.toFixed(2));

                productGrossTotal += gross;
                productNetTotal += subtotal;
                discountTotal += itemDiscount;
            });

            // Update product gross total display (shows product returns before discount)
            $('#productTotalAmount').text('₱' + productGrossTotal.toFixed(2));

            // Check if repair fee should be included (back job scenario)
            let repairFee = parseFloat($('#repairFeeInput').val()) || 0;
            let repairFeeRefunded = $('#repairFeeRefundedInput').val() === '1';
            let includeRepair = (productGrossTotal > 0 && repairFee > 0 && !repairFeeRefunded);

            if (includeRepair) {
                $('#repairFeeRow').show();
            } else {
                $('#repairFeeRow').hide();
            }

            // If repair fee is included, allocate discount portion for repair fee as well
            let discountForRepair = 0;
            if (includeRepair && saleDiscount > 0 && saleTotal > 0) {
                discountForRepair = (repairFee / saleTotal) * saleDiscount;
                discountTotal += discountForRepair;
            }

            // Show discount row (create if not exists)
            if ($('#discountRow').length === 0) {
                $(".card-body .row").filter(function() {
                    return $(this).find('#productTotalAmount').length > 0;
                }).first().after(`
                    <div class="row mb-2" id="discountRow">
                        <div class="col-6"><strong>Discount Refund:</strong></div>
                        <div class="col-6 text-end text-danger" id="discountRefundAmount">₱0.00</div>
                    </div>
                `);
            }

            $('#discountRefundAmount').text('₱' + discountTotal.toFixed(2));

            // Compute total return amount (gross product + repair if included - discountAllocated)
            let grossReturnTotal = productGrossTotal + (includeRepair ? repairFee : 0);
            let netReturnTotal = grossReturnTotal - discountTotal;

            $('#totalAmount').text('₱' + netReturnTotal.toFixed(2));

            // Store discount amount in a hidden input for submission (optional)
            if ($('#discountRefundInput').length === 0) {
                $('<input>').attr({type: 'hidden', id: 'discountRefundInput', name: 'discount_refund'}).appendTo('#returnForm');
            }
            $('#discountRefundInput').val(discountTotal.toFixed(2));
        }

        function updateConditionNote() {
            $('.item-condition').each(function() {
                const $row = $(this).closest('tr');
                const $note = $row.find('.condition-note');
                const condition = $(this).val();
                
                if (condition === 'damaged' || condition === 'broken') {
                    $note.show();
                } else {
                    $note.hide();
                }
            });
            updateInventorySummary();
        }

        function updateInventorySummary() {
            let goodItems = [];
            let damagedItems = [];
            
            $('.return-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                if (qty > 0) {
                    const productId = $(this).data('id');
                    const name = $(this).data('name');
                    const condition = $(`.item-condition[data-id="${productId}"]`).val();
                    
                    if (condition === 'good') {
                        goodItems.push(`${name} (${qty})`);
                    } else {
                        damagedItems.push(`${name} (${qty}) - ${condition}`);
                    }
                }
            });
            
            if (goodItems.length > 0 || damagedItems.length > 0) {
                $('#inventorySummary').show();
                
                if (goodItems.length > 0) {
                    $('#goodItemsList').html(`
                        <p class="mb-1"><strong class="text-success"><i class="bi bi-arrow-up-circle me-1"></i>Will be added back to inventory:</strong></p>
                        <ul class="mb-0">${goodItems.map(item => `<li>${item}</li>`).join('')}</ul>
                    `);
                } else {
                    $('#goodItemsList').html('');
                }
                
                if (damagedItems.length > 0) {
                    $('#damagedItemsList').html(`
                        <p class="mb-1"><strong class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Will NOT be added to inventory:</strong></p>
                        <ul class="mb-0">${damagedItems.map(item => `<li>${item}</li>`).join('')}</ul>
                    `);
                } else {
                    $('#damagedItemsList').html('');
                }
            } else {
                $('#inventorySummary').hide();
            }
        }

        $('.return-qty').on('input', function() {
            // Validate input
            let max = parseInt($(this).attr('max'));
            let current = parseInt($(this).val());
            
            if (current > max) {
                $(this).val(max);
                $(this).addClass('is-invalid');
                setTimeout(() => $(this).removeClass('is-invalid'), 2000);
            } else if (current < 0) {
                $(this).val(0);
            }
            
            updateTotals();
            updateInventorySummary();
        });

        $('.item-condition').on('change', updateConditionNote);

        $('#returnForm').on('submit', function(e) {
            e.preventDefault();
            $('#returnError').text('');
            
            // Disable submit button to prevent double submission
            const submitBtn = $(this).find('button[type="submit"]');
            submitBtn.prop('disabled', true).html('<i class="spinner-border spinner-border-sm me-2"></i>Processing...');

            let returnItems = [];
            $('.return-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                if (qty > 0) {
                    const productId = $(this).data('id');
                    const condition = $(`.item-condition[data-id="${productId}"]`).val();
                    
                    returnItems.push({
                        id: productId,
                        name: $(this).data('name'),
                        quantity: qty,
                        price: parseFloat($(this).data('price')),
                        condition: condition
                    });
                }
            });

            if (returnItems.length === 0) {
                $('#returnError').text('Please select at least one item to return.');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Process Return');
                return;
            }

            // Validate reason
            const reason = $('textarea[name="reason"]').val().trim();
            if (reason.length === 0) {
                $('#returnError').text('Please provide a reason for the return.');
                $('textarea[name="reason"]').focus();
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Process Return');
                return;
            }

            // Include repair fee information in form data if applicable
            let repairFee = parseFloat($('#repairFeeInput').val()) || 0;
            let repairFeeRefunded = $('#repairFeeRefundedInput').val() === '1';
            let productTotal = 0;
            returnItems.forEach(item => {
                productTotal += item.price * item.quantity;
            });
            
            // If products are being returned and repair fee exists, include it
            let includeRepairFee = (productTotal > 0 && repairFee > 0 && !repairFeeRefunded);
            
            let formData = new FormData(this);
            formData.append('return_items', JSON.stringify(returnItems));
            formData.append('include_repair_fee', includeRepairFee ? '1' : '0');

            $.ajax({
                url: 'process_return.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 30000, // 30 second timeout
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                          '<i class="bi bi-check-circle me-2"></i>' + 
                          (response.message || 'Return processed successfully!') +
                          '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
                          '</div>').insertBefore('#returnForm');
                        
                        // Redirect after 2 seconds
                        setTimeout(() => {
                            window.location.href = 'sales_history_branch<?= $branch_id ?>.php';
                        }, 2000);
                    } else {
                        $('#returnError').text(response.error || 'An error occurred while processing the return.');
                        submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Process Return');
                    }
                },
                error: function(xhr, status, error) {
                    let errorMsg = 'An error occurred. Please try again.';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out. Please try again.';
                    } else if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    $('#returnError').text(errorMsg);
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle me-2"></i>Process Return');
                }
            });
        });
        
        // Initialize tooltips if available
        if (typeof bootstrap !== 'undefined') {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }
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

.content-card:hover {
    box-shadow: 0 4px 20px rgba(44,49,54,0.12);
    transform: translateY(-2px);
}

/* Return form specific styles */
.return-qty {
    transition: all 0.2s ease;
}

.return-qty:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.return-qty.is-invalid {
    border-color: #dc3545;
    animation: shake 0.5s;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

.btn {
    transition: all 0.2s ease;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.alert {
    border: none;
    border-radius: 8px;
}

#totalAmount {
    background: linear-gradient(45deg, #0d6efd, #6610f2);
    background-clip: text;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.spinner-border-sm {
    width: 1rem;
    height: 1rem;
}

.condition-note {
    font-size: 0.75rem;
    margin-top: 2px;
}

.item-condition {
    transition: border-color 0.2s ease;
}

.item-condition:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.item-condition option[value="damaged"],
.item-condition option[value="broken"] {
    background-color: #fff3cd;
    color: #856404;
}

/* Responsive adjustments */
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
    
    .return-qty {
        width: 60px !important;
    }
}
</style>

<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>

</body>
</html> 