<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
include '../../includes/cashier_sidebar.php';

/**
 * Compute stock status based on quantity and reorder level (same as admin inventory)
 */
function compute_status($quantity, $reorder_level) {
    if ($quantity == 0) return 'Out of Stock';
    if ($quantity <= $reorder_level) return 'Low Stock';
    return 'In Stock';
}

// Fetch products for branch 2 (Juban Branch)
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$where = "WHERE branch_id = 2";
if ($search) {
    $where .= " AND (name LIKE '%$search%' OR category LIKE '%$search%')";
}
// fetch products including category and reorder_level so we can compute status and group by category
$sql_products = "SELECT id, name, category, brand, model, price, quantity, reorder_level, unit FROM products $where ORDER BY category ASC, name ASC";
$result_products = mysqli_query($conn, $sql_products);

// Check for database errors
if (!$result_products) {
    die("Database error: " . mysqli_error($conn));
}

// Get count
$product_count = mysqli_num_rows($result_products);

// Fetch present mechanics for branch 2
$sql_mech = "SELECT id, name FROM mechanics WHERE branch_id = 2 ORDER BY name ASC";
$result_mech = mysqli_query($conn, $sql_mech);
$mechanics = [];
while ($row = mysqli_fetch_assoc($result_mech)) {
    $mechanics[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Juban Branch - POS</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
</head>
<body style="margin: 0 !important; padding: 0 !important;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <h2 class="mb-2 mt-0 pt-0"><i class="bi bi-cart-check me-2"></i>POS (Over-the-Counter) - Juban Branch</h2>
        <div class="content-card">
            <div class="row g-4">
                <!-- Product Search & List -->
                <div class="col-md-7">
                    <form method="get" class="row g-2 mb-3 align-items-center">
                        <div class="col-auto">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search product or category" class="form-control" style="min-width:220px;">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                        </div>
                        <?php if ($search): ?>
                        <div class="col-auto">
                            <a href="?" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Clear Search</a>
                        </div>
                        <?php endif; ?>
                    </form>
                    
                    <div class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> 
                            Showing <?php echo $product_count; ?> product(s) for Juban Branch
                            <?php if ($search): ?>
                                matching "<?php echo htmlspecialchars($search); ?>"
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="table-responsive product-list-scroll">
                        <table class="table table-hover align-middle pos-table">
                            <thead class="table-light">
                                <tr>
                                    <th><i class="bi bi-box"></i> Name</th>
                                    <th><i class="bi bi-award"></i> Brand</th>
                                    <th><i class="bi bi-card-text"></i> Model</th>
                                    <th><i class="bi bi-currency-peso"></i> Price</th>
                                    <th class="text-center"><i class="bi bi-123"></i> Stock</th>
                                    <th class="text-center"><i class="bi bi-rulers"></i> Unit</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                                <?php
                                $products = [];
                                while ($r = mysqli_fetch_assoc($result_products)) {
                                    $products[] = $r;
                                }
                                if (count($products) > 0):
                                    $prev_category = null;
                                    // render with index-based loop so we can detect category boundaries
                                    foreach ($products as $idx => $row):
                                        $next_category = isset($products[$idx + 1]) ? $products[$idx + 1]['category'] : null;
                                        $is_start = ($prev_category !== $row['category']);
                                        $is_end = ($next_category !== $row['category']);
                                        // Compute status using same logic as admin inventory
                                        $status = compute_status($row['quantity'], $row['reorder_level'] ?? 0);
                                        $status_class = '';
                                        $stock_icon = '';
                                        if ($status === 'Out of Stock') {
                                            $status_class = 'out-of-stock-row';
                                            $stock_icon = '<i class="bi bi-x-circle-fill text-danger me-1"></i>';
                                        } elseif ($status === 'Low Stock') {
                                            $status_class = 'low-stock-row';
                                            $stock_icon = '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>';
                                        }
                                        $classes = [];
                                        if ($is_start) $classes[] = 'category-start';
                                        if ($is_end) $classes[] = 'category-end';
                                        if ($status_class) $classes[] = $status_class;
                                        $classAttr = count($classes) ? ' class="' . implode(' ', $classes) . ' clickable-row"' : ' class="clickable-row"';
                                ?>
                                    <tr<?php echo $classAttr; ?> onclick="addToCart(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', <?php echo $row['price']; ?>, <?php echo $row['quantity']; ?>, '<?php echo htmlspecialchars($row['unit']); ?>')" title="Click to add to cart">
                                        <td><i class="bi bi-plus-circle-fill text-success me-2" style="opacity:0.6;"></i><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['brand'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($row['model'] ?? ''); ?></td>
                                        <td>₱<?php echo number_format($row['price'],2); ?></td>
                                        <td class="text-center <?php echo $status_class ? str_replace('-row', '-cell', $status_class) : ''; ?>">
                                            <?php echo $stock_icon; ?>
                                            <strong><?php echo $row['quantity']; ?></strong>
                                        </td>
                                        <td class="text-center"><?php echo htmlspecialchars($row['unit']); ?></td>
                                    </tr>
                                    <?php
                                        if ($is_end) {
                                            echo '<tr class="category-separator"><td colspan="6" style="background:#f8f9fa; height:10px; border:none;"></td></tr>';
                                        }
                                        $prev_category = $row['category'];
                                    endforeach;
                                else:
                                ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            <?php if ($search): ?>
                                                No products found matching your search criteria.
                                            <?php else: ?>
                                                No products available for Juban Branch. Please contact administrator.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cart & Payment -->
                <div class="col-md-5">
                    <form id="posForm" method="post" action="process_pos_branch2.php">
                        <!-- right panel scrollable wrapper -->
                        <div class="right-scroll-wrapper">
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="serviceOnlyToggle">
                                <label class="form-check-label" for="serviceOnlyToggle">Service Only (No Products)</label>
                            </div>
                            <small class="text-muted d-block mt-1">Toggle this switch if the customer only needs repair/service work without purchasing any products.</small>
                        </div>
                        <div id="productSection">
                            <h4 class="mb-3 text-primary"><i class="bi bi-cart4 me-2"></i>Products</h4>
                            <div id="cartItems" class="mb-3"></div>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label">Repair/Service Fee:</label>
                            <input type="number" min="0" step="0.01" name="repair_fee" id="repairFee" class="form-control" value="0">
                            <small class="text-muted">Enter the service fee amount. Required for service-only transactions.</small>
                        </div>
                        <div class="mb-3" id="serviceRemarksGroup">
                            <label class="form-label">Service Remarks:</label>
                            <textarea name="service_remarks" id="serviceRemarks" class="form-control" rows="3" placeholder="Enter details about the service/repair..."></textarea>
                            <small class="text-muted">Describe the service or repair work being done. Required when service is requested.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assign Mechanic (optional):</label>
                            <select name="mechanic_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($mechanics as $mech): ?>
                                    <option value="<?php echo $mech['id']; ?>"><?php echo htmlspecialchars($mech['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label">Grand Total:</label>
                            <div id="grandTotal" class="fs-3 fw-bold text-primary">₱0.00</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Discount:</label>
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDiscountRate(5)">5%</button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDiscountRate(10)">10%</button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDiscountRate(15)">15%</button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setDiscountRate(20)">20%</button>
                            </div>
                            <div class="input-group">
                                <input type="number" min="0" max="100" step="0.01" name="discount_rate" id="discountPercent" class="form-control" value="0">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">Apply a percentage discount. Use presets or enter a custom percent (0-100%).</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payable:</label>
                            <div id="payableAmount" class="fs-4 fw-bold text-primary">₱0.00</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount Received:</label>
                            <input type="number" min="0" step="0.01" name="amount_received" id="amountReceived" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Change:</label>
                            <div id="changeAmount" class="fs-5 fw-semibold text-success">₱0.00</div>
                        </div>
                        </div><!-- /.right-scroll-wrapper -->

                        <!-- actions - keep visible (sticky) below the scrollable area -->
                        <div class="right-actions">
                            <div id="posError" class="text-danger fw-semibold mb-3"></div>
                            <button type="submit" id="confirmSaleBtn" class="btn btn-primary w-100">Confirm Sale</button>
                            <input type="hidden" name="cart_data" id="cartDataInput">
                            <div id="saleSuccessMsg" class="alert alert-success mt-3 d-none"></div>
                            <button type="button" id="printReceiptBtn" class="btn btn-success w-100 mt-2 d-none">Print Receipt</button>
                            <button type="button" class="btn btn-secondary w-100 mt-2" onclick="window.location.reload();">Refresh</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/jquery-3.6.0.min.js"></script>
<script>
// Cart logic
let cart = [];
function addToCart(id, name, price, stock, unit) {
    let item = cart.find(i => i.id === id);
    if (item) {
        if (item.quantity + 1 > stock) {
            alert('Requested quantity exceeds available stock!');
            return;
        }
        item.quantity++;
    } else {
        if (stock < 1) {
            alert('No stock available!');
            return;
        }
        cart.push({id, name, price, stock, unit, quantity: 1});
    }
    renderCart();
}
function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}
function updateCartQty(id, qty) {
    let item = cart.find(i => i.id === id);
    if (!item) return;
    qty = parseInt(qty);
    if (isNaN(qty) || qty < 1) qty = 1;
    if (qty > item.stock) {
        alert('Requested quantity exceeds available stock!');
        qty = item.stock;
    }
    item.quantity = qty;
    renderCart();
}
function renderCart() {
    let html = '';
    let total = 0;
    cart.forEach(item => {
        let subtotal = item.price * item.quantity;
        total += subtotal;
        // Show product name (with unit), quantity input and a single amount (subtotal)
        html += `<div class='cart-row' style='display:flex;align-items:center;gap:8px;margin-bottom:8px;'>
            <span style='flex:3;'>${item.name} <span style='color:#888;font-size:0.95em;'>(${item.unit})</span></span>
            <input type='number' min='1' max='${item.stock}' value='${item.quantity}' style='width:60px;' onchange='updateCartQty(${item.id}, this.value)'>
            <span style='flex:1;text-align:right;'>₱${subtotal.toFixed(2)}</span>
            <button type='button' class='btn btn-sm btn-danger' style='margin-left:8px;' onclick='removeFromCart(${item.id})'>Remove</button>
        </div>`;
    });
    const isServiceOnly = document.getElementById('serviceOnlyToggle').checked;
    if (cart.length === 0 && !isServiceOnly) html = '<div style="color:#888;" id="noCartMsg">No items in cart.</div>';
    document.getElementById('cartItems').innerHTML = html;
    // Update grand total
    let repairFee = parseFloat(document.getElementById('repairFee').value) || 0;
    let grandTotal = total + repairFee;
    document.getElementById('grandTotal').innerText = '₱' + grandTotal.toFixed(2);
    // Discount (percent) and payable
    let discountRate = 0;
    const discountEl = document.getElementById('discountPercent');
    if (discountEl) {
        discountRate = parseFloat(discountEl.value) || 0;
        if (discountRate < 0) discountRate = 0;
        if (discountRate > 100) discountRate = 100;
    }
    // Make discount a whole peso amount (no centavos)
    let discountAmount = Math.floor(grandTotal * (discountRate / 100));
    let payable = grandTotal - discountAmount;
    if (payable < 0) payable = 0;
    document.getElementById('payableAmount').innerText = '₱' + payable.toFixed(2);
    // Update change
    let amountReceived = parseFloat(document.getElementById('amountReceived').value) || 0;
    let change = amountReceived - payable;
    document.getElementById('changeAmount').innerText = '₱' + (change >= 0 ? change.toFixed(2) : '0.00');
    // Save cart data to hidden input
    document.getElementById('cartDataInput').value = JSON.stringify(cart);
}
document.getElementById('repairFee').addEventListener('input', renderCart);
document.getElementById('amountReceived').addEventListener('input', renderCart);
var discountInput = document.getElementById('discountPercent');
if (discountInput) discountInput.addEventListener('input', renderCart);

function setDiscountRate(rate) {
    const el = document.getElementById('discountPercent');
    if (!el) return;
    el.value = rate;
    renderCart();
}

document.getElementById('serviceOnlyToggle').addEventListener('change', function() {
    const productSection = document.getElementById('productSection');
    const cartItems = document.getElementById('cartItems');
    const repairFee = document.getElementById('repairFee');
    const serviceRemarks = document.getElementById('serviceRemarks');
    const form = document.getElementById('posForm');
    const confirmBtn = document.getElementById('confirmSaleBtn');
    
    if (this.checked) {
        productSection.style.display = 'none';
        cartItems.innerHTML = '';
        cart = [];
        repairFee.value = '0';
        repairFee.required = true;
        serviceRemarks.required = true;
        form.classList.add('service-only-mode');
        confirmBtn.textContent = 'Confirm Service';
    } else {
        productSection.style.display = 'block';
        repairFee.required = false;
        serviceRemarks.required = false;
        form.classList.remove('service-only-mode');
        confirmBtn.textContent = 'Confirm Sale';
    }
    renderCart();
});

// Service remarks visibility logic
function updateServiceRemarksVisibility() {
    const serviceToggle = document.getElementById('serviceOnlyToggle');
    const repairFeeEl = document.getElementById('repairFee');
    const mechSelect = document.querySelector('select[name="mechanic_id"]');
    const remarksGroup = document.getElementById('serviceRemarksGroup');
    const remarksEl = document.getElementById('serviceRemarks');

    let requiresService = false;
    if (serviceToggle && serviceToggle.checked) requiresService = true;
    if (repairFeeEl && parseFloat(repairFeeEl.value) > 0) requiresService = true;
    if (mechSelect && mechSelect.value && mechSelect.value !== '') requiresService = true;

    if (remarksGroup) {
        if (requiresService) {
            remarksGroup.style.display = '';
            remarksEl.required = true;
        } else {
            // hide but keep value (so users don't lose typed text if they toggle)
            remarksGroup.style.display = 'none';
            remarksEl.required = false;
        }
    }
}

// hook events to show/hide remarks
document.querySelector('select[name="mechanic_id"]').addEventListener('change', function() {
    updateServiceRemarksVisibility();
});
document.getElementById('repairFee').addEventListener('input', function(){ updateServiceRemarksVisibility(); renderCart(); });
document.getElementById('serviceOnlyToggle').addEventListener('change', function(){ updateServiceRemarksVisibility(); renderCart(); });

// initialize visibility on load
document.addEventListener('DOMContentLoaded', function(){ updateServiceRemarksVisibility(); });

// Modify the form submission to handle service-only transactions
document.getElementById('posForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const isServiceOnly = document.getElementById('serviceOnlyToggle').checked;
    const repairFee = document.getElementById('repairFee').value;
    const serviceRemarks = document.getElementById('serviceRemarks').value;
    const amountReceived = document.getElementById('amountReceived').value;
    // Only require cart items if not in service-only mode
    if (!isServiceOnly && cart.length === 0) {
        document.getElementById('posError').innerText = 'Please add at least one product to the cart.';
        return;
    }
    // Validate service-only mode
    if (isServiceOnly && (!repairFee || repairFee <= 0)) {
        document.getElementById('posError').innerText = 'Please enter a valid service fee amount.';
        return;
    }
    if (isServiceOnly && !serviceRemarks.trim()) {
        document.getElementById('posError').innerText = 'Please enter service remarks.';
        return;
    }
    // Ensure amountReceived covers payable (grand total - discountPercent)
    let displayedGrand = parseFloat(document.getElementById('grandTotal').innerText.replace(/[^0-9.-]+/g, '')) || 0;
    let discountRateVal = parseFloat(document.getElementById('discountPercent').value) || 0;
    if (discountRateVal < 0) discountRateVal = 0;
    if (discountRateVal > 100) discountRateVal = 100;
    // Use whole peso discount during validation as well
    let discountAmtNow = Math.floor(displayedGrand * (discountRateVal / 100));
    let payableNow = displayedGrand - discountAmtNow;
    if (payableNow < 0) payableNow = 0;
    if (!amountReceived || amountReceived < payableNow) {
        document.getElementById('posError').innerText = 'Amount received must be equal to or greater than the payable amount.';
        return;
    }
    document.getElementById('posError').innerText = '';
    document.getElementById('saleSuccessMsg').classList.add('d-none');
    document.getElementById('printReceiptBtn').classList.add('d-none');
    const form = this;
    const formData = new FormData(form);
    // Add cart data only if not service-only
    if (!isServiceOnly) {
        formData.set('cart_data', JSON.stringify(cart));
    } else {
        formData.set('cart_data', JSON.stringify([]));
    }
    // Disable button
    document.getElementById('confirmSaleBtn').disabled = true;
    fetch('process_pos_branch2.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('confirmSaleBtn').disabled = false;
        if (data.success) {
            // Hide form fields
            Array.from(form.elements).forEach(el => { if (el.type !== 'button') el.disabled = true; });
            document.getElementById('saleSuccessMsg').innerText = 'Transaction recorded successfully!';
            document.getElementById('saleSuccessMsg').classList.remove('d-none');
            const printBtn = document.getElementById('printReceiptBtn');
            printBtn.classList.remove('d-none');
            printBtn.onclick = function() {
                window.open('print_receipt_branch2.php?sale_id=' + data.sale_id, '_blank');
            };
        } else {
            document.getElementById('posError').innerText = data.error || 'Transaction failed.';
        }
    })
    .catch(() => {
        document.getElementById('confirmSaleBtn').disabled = false;
        document.getElementById('posError').innerText = 'An error occurred. Please try again.';
    });
});
</script>

<style>
body {
    margin: 0 !important;
    padding: 0 !important;
}

.container-fluid {
    margin: 0 !important;
    padding: 0 !important;
}

.main-content {
    margin-left: 250px !important;
    margin-right: 20px !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    padding: 1.5rem 1rem !important;
    min-height: 100vh;
    transition: all 0.3s ease-in-out;
    max-width: calc(100vw - 270px);
    overflow-x: auto;
}

h2 {
    margin: 0 !important;
    padding: 0 !important;
}

.content-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(44,49,54,0.08);
    padding: 20px;
    margin: 0 !important;
    transition: all 0.3s ease;
}
.content-card:hover {
    box-shadow: 0 4px 20px rgba(44,49,54,0.12);
    transform: translateY(-2px);
}
.pos-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 24px 0 24px;
    transition: all 0.3s ease;
}
.pos-title {
    font-size: 1.45rem;
    font-weight: 700;
    color: #23272b;
    margin-bottom: 32px;
    margin-top: 0;
    transition: all 0.3s ease;
}
.pos-flex {
    display: flex;
    gap: 36px;
    flex-wrap: wrap;
    justify-content: flex-start;
    align-items: flex-start;
    margin: 0;
    transition: all 0.3s ease;
}
.pos-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(44,49,54,0.08);
    padding: 28px 24px 22px 24px;
    margin-bottom: 24px;
    transition: all 0.3s ease;
}
.pos-card:hover {
    box-shadow: 0 4px 20px rgba(44,49,54,0.12);
    transform: translateY(-2px);
}
.pos-products {
    flex: 2 1 370px;
    min-width: 350px;
    max-width: 540px;
    transition: all 0.3s ease;
}
.pos-cart {
    flex: 1 1 340px;
    min-width: 320px;
    max-width: 400px;
    transition: all 0.3s ease;
}
.pos-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    font-size: 1rem;
    transition: all 0.3s ease;
}
.pos-table th, .pos-table td {
    padding: 12px 10px;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    transition: all 0.2s ease;
}
/* subtle vertical separators between columns */
.pos-table th, .pos-table td {
    border-right: 1px solid #eef2f7;
}
.pos-table th:last-child, .pos-table td:last-child {
    border-right: none;
}
.pos-table tr.category-start td:first-child {
    border-left: 4px solid rgba(13,110,253,0.12);
}
.pos-table th {
    background: #f4f6fa;
    color: #0d6efd;
    font-weight: 600;
    border-bottom: 2px solid #e0e0e0;
    position: sticky;
    top: 0;
    z-index: 1;
    transition: all 0.3s ease;
}
.pos-table tr {
    transition: all 0.2s ease;
}
.pos-table tr.clickable-row {
    cursor: pointer;
}
.pos-table tr.clickable-row {
    transition: border-left 0.2s ease, background 0.2s ease, transform 0.2s ease;
}
.pos-table tr.clickable-row:hover {
    background: #e7f3ff !important;
    border-left: 6px solid #0d6efd !important;
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(13,110,253,0.15);
}
.pos-table tr.clickable-row:active {
    transform: translateX(2px);
    background: #d0e7ff !important;
    border-left: 6px solid #084298 !important;
}
.pos-table tr.clickable-row td:first-child i {
    transition: all 0.2s ease;
}
.pos-table tr.clickable-row:hover td:first-child i {
    opacity: 1 !important;
    transform: scale(1.2);
}
.pos-table tr:not(.clickable-row):hover {
    background: #f0f4ff;
}
/* Stock status styles matching admin inventory */
.pos-table tr.out-of-stock-row {
    background: #fef2f2;
    transition: border-left 0.2s ease, background 0.2s ease, transform 0.2s ease;
}
.pos-table tr.out-of-stock-row:hover {
    background: #fee2e2 !important;
    border-left: 6px solid #dc2626 !important;
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(239,68,68,0.3);
}
.pos-table tr.out-of-stock-row:active {
    border-left: 6px solid #b91c1c !important;
}
.pos-table tr.out-of-stock-row td.out-of-stock-cell {
    color: #b91c1c;
    font-weight: 600;
}
.pos-table tr.out-of-stock-row td.out-of-stock-cell strong {
    color: #b91c1c;
}
.pos-table tr.low-stock-row {
    background: #fffbeb;
    transition: border-left 0.2s ease, background 0.2s ease, transform 0.2s ease;
}
.pos-table tr.low-stock-row:hover {
    background: #fef3c7 !important;
    border-left: 6px solid #f97316 !important;
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(251,146,60,0.3);
}
.pos-table tr.low-stock-row:active {
    border-left: 6px solid #d97706 !important;
}
.pos-table tr.low-stock-row td.low-stock-cell {
    color: #b45309;
    font-weight: 600;
}
.pos-table tr.low-stock-row td.low-stock-cell strong {
    color: #b45309;
}
/* Scrollable product list */
.product-list-scroll {
    /* Make left product list scroll height responsive and match right panel */
    max-height: min(520px, calc(100vh - 220px)); /* fallback and responsive */
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 8px; /* avoid content sitting under scrollbar */
    scrollbar-width: thin;
    scrollbar-color: #c1c1c1 #f1f1f1;
}

/* WebKit scrollbar styling */
.product-list-scroll::-webkit-scrollbar {
    width: 10px;
}
.product-list-scroll::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}
.product-list-scroll::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
}
.product-list-scroll::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Right panel scroll wrapper to match product list height */
.right-scroll-wrapper {
    max-height: min(520px, calc(100vh - 220px));
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 8px;
    box-sizing: border-box;
}
.right-scroll-wrapper::-webkit-scrollbar {
    width: 10px;
}
.right-scroll-wrapper::-webkit-scrollbar-track {
    background: #f9f9f9;
    border-radius: 10px;
}
.right-scroll-wrapper::-webkit-scrollbar-thumb {
    background: #cfcfcf;
    border-radius: 10px;
}
.right-scroll-wrapper::-webkit-scrollbar-thumb:hover {
    background: #b6b6b6;
}

/* Actions area under the scrollable right panel. Keep buttons visible. */
.right-actions {
    position: sticky;
    bottom: 12px;
    background: transparent;
    z-index: 5;
    padding-top: 6px;
}
.cart-title {
    font-size: 1.15rem;
    font-weight: 600;
    color: #0d6efd;
    margin-bottom: 18px;
    transition: all 0.3s ease;
}
.pos-label {
    font-size: 1rem;
    color: #23272b;
    font-weight: 500;
    margin-bottom: 2px;
    transition: all 0.3s ease;
}
.pos-input {
    border-radius: 6px;
    border: 1px solid #d1d5db;
    padding: 7px 12px;
    font-size: 1rem;
    margin-bottom: 6px;
    width: 100%;
    box-sizing: border-box;
    transition: all 0.2s ease;
}
.pos-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.15);
    transform: translateY(-1px);
}
.pos-btn {
    border-radius: 6px !important;
    font-size: 1rem !important;
    padding: 7px 18px !important;
    font-weight: 500;
    background: #0d6efd !important;
    color: #fff !important;
    border: none !important;
    transition: all 0.2s ease;
}
.pos-btn:hover {
    background: #084298 !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(13,110,253,0.2);
}
.pos-grand {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 4px;
    transition: all 0.3s ease;
}
.pos-change {
    font-size: 1.2rem;
    font-weight: 600;
    color: #198754;
    margin-bottom: 4px;
    transition: all 0.3s ease;
}
.cart-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 1rem;
    flex-wrap: wrap;
    transition: all 0.2s ease;
}
.cart-row:hover {
    transform: translateX(4px);
}
.cart-row input[type='number'] {
    border-radius: 6px;
    border: 1px solid #d1d5db;
    padding: 4px 8px;
    font-size: 1rem;
    width: 60px;
    transition: all 0.2s ease;
}
.cart-row input[type='number']:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.15);
    transform: translateY(-1px);
}
.cart-row .btn-danger {
    padding: 2px 10px;
    font-size: 0.95rem;
    border-radius: 6px;
    background: #dc3545 !important;
    color: #fff !important;
    border: none !important;
    transition: all 0.2s ease;
}
.cart-row .btn-danger:hover {
    background: #a71d2a !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(220,53,69,0.2);
}

/* Responsive styles */
@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    .content-card {
        padding: 16px;
        transform: none !important;
    }
    .pos-container {
        padding: 16px 8px;
    }
    .pos-flex {
        flex-direction: column;
        gap: 24px;
    }
    .pos-products, .pos-cart {
        max-width: 100%;
        min-width: 100%;
    }
    .pos-table th, 
    .pos-table td {
        font-size: 0.95rem;
        padding: 8px;
    }
    .cart-row {
        font-size: 0.95rem;
    }
}

@media (max-width: 767.98px) {
    .pos-table {
        font-size: 0.9rem;
    }
    .pos-table th, 
    .pos-table td {
        padding: 6px;
    }
    .cart-row {
        font-size: 0.9rem;
    }
    .pos-grand {
        font-size: 1.3rem;
    }
    .pos-change {
        font-size: 1.1rem;
    }
    .pos-title {
        font-size: 1.3rem;
    }
}

/* Print styles */
@media print {
    .sidebar-minimal,
    .pos-flex,
    .btn {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .content-card {
        box-shadow: none !important;
        padding: 0 !important;
        transform: none !important;
    }
    .pos-table {
        box-shadow: none !important;
    }
    .pos-table th {
        background: #f4f6fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
.service-only-mode {
    border-left: 4px solid #0d6efd;
    padding-left: 15px;
    background-color: #f8f9fa;
}
.service-only-mode .form-label {
    color: #0d6efd;
    font-weight: 500;
}
.service-only-mode .form-control {
    border-color: #0d6efd;
}
.service-only-mode .form-control:focus {
    box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.25);
}
</style>

<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>

</body>
</html>
