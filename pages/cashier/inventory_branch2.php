<?php
include '../../includes/auth.php';
allow_roles(['cashier']);
include_once '../../config.php';
include '../../includes/cashier_sidebar.php';

function compute_status($quantity, $reorder_level) {
    if ($quantity == 0) {
        return 'Out of Stock';
    }
    if ($quantity <= $reorder_level) {
        return 'Low Stock';
    }
    return 'In Stock';
}

// Handle search and sort
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$sort = isset($_GET['sort']) && $_GET['sort'] === 'asc' ? 'ASC' : 'DESC';

$where = "WHERE branch_id = 2";
if ($search) {
    $where .= " AND (name LIKE '%$search%' OR category LIKE '%$search%')";
}
$order = "ORDER BY quantity $sort";

$sql = "SELECT name, category, brand, model, price, quantity, reorder_level, unit, size, status FROM products $where $order";
$result = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    /*<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Juban Branch - Inventory</title>
    <link href="<?= BASE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/styles.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.css">
</head>
<body style="margin: 0 !important; padding: 0 !important;">

<div class="container-fluid p-0 m-0">
    <div class="main-content">
        <h2 class="mb-2 mt-0 pt-0"><i class="bi bi-boxes me-2"></i>Juban Branch - Inventory</h2>
        <div class="content-card">
            <form method="get" class="inventory-filters mb-3 d-flex gap-2 align-items-center">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or category" class="form-control" style="max-width:220px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                <a href="?sort=<?php echo $sort === 'ASC' ? 'desc' : 'asc'; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-sort-down"></i> Sort by Stock: <?php echo $sort === 'ASC' ? 'Ascending' : 'Descending'; ?>
                </a>
            </form>
            <div class="table-responsive">
                <table class="table inventory-table">
                    <thead>
                        <tr>
                            <th style="width:48px; text-align:center;">#</th>
                            <th><i class="bi bi-box me-2"></i>Product Name</th>
                            <th><i class="bi bi-tags me-2"></i>Category</th>
                            <th><i class="bi bi-award me-2"></i>Brand</th>
                            <th><i class="bi bi-card-text me-2"></i>Model</th>
                            <th class="text-center"><i class="bi bi-123 me-2"></i>Quantity</th>
                            <th class="text-center"><i class="bi bi-rulers me-2"></i>Unit</th>
                            <th class="text-center"><i class='bi bi-traffic-light me-2'></i>Status</th>
                            <th class="price-col"><i class="bi bi-currency-peso me-2"></i>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php 
                            $products = [];
                            while ($row = mysqli_fetch_assoc($result)) {
                                $products[] = $row;
                            }
                            $prev_category = null;
                            foreach ($products as $index => $row): 
                                $next_category = isset($products[$index + 1]) ? $products[$index + 1]['category'] : null;
                                $is_start = ($prev_category !== $row['category']);
                                $is_end = ($next_category !== $row['category']);
                                $classes = [];
                                if ($is_start) $classes[] = 'category-start';
                                if ($is_end) $classes[] = 'category-end';
                                $classAttr = count($classes) ? ' class="' . implode(' ', $classes) . '"' : '';
                                $styleAttr = ($row['quantity'] <= $row['reorder_level']) ? ' style="background:#fff3cd"' : '';
                            ?>
                                <tr<?php echo $classAttr . $styleAttr; ?>>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                                    <td><?php echo htmlspecialchars($row['brand'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['model'] ?? ''); ?></td>
                                    <td class="text-center"><?php echo $row['quantity']; ?></td>
                                    <td class="text-center">
                                        <?php
                                        $display_unit = '';
                                        $catLower = strtolower($row['category'] ?? '');
                                        if ($catLower === 'tires' && !empty($row['size'])) {
                                            $display_unit = $row['size'];
                                        } else {
                                            $unitVal = $row['unit'] ?? '';
                                            if (!empty($unitVal)) {
                                                if (str_ends_with($unitVal, 'ml') || str_ends_with($unitVal, 'L')) {
                                                    $display_unit = $unitVal;
                                                } elseif ($unitVal === 'ml' || $unitVal === 'L') {
                                                    if (!empty($row['size']) && preg_match('/^\d+(?:\.\d+)?$/', $row['size'])) {
                                                        $display_unit = $row['size'] . $unitVal;
                                                    } else {
                                                        $display_unit = $unitVal;
                                                    }
                                                } else {
                                                    $display_unit = $unitVal;
                                                }
                                            } else {
                                                if (!empty($row['size']) && preg_match('/^\d+(?:\.\d+)?$/', $row['size'])) {
                                                    $display_unit = $row['size'];
                                                }
                                            }
                                        }
                                        echo htmlspecialchars($display_unit);
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $status = compute_status((int)$row['quantity'], (int)$row['reorder_level']);
                                        $status_class_map = [
                                            'In Stock' => 'status-cell status-in-stock',
                                            'Low Stock' => 'status-cell status-low-stock',
                                            'Out of Stock' => 'status-cell status-out-stock'
                                        ];
                                        $status_class = $status_class_map[$status] ?? 'status-cell';
                                        ?>
                                        <div class="<?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                            <span class="status-indicator"></span>
                                        </div>
                                    </td>
                                    <td class="price-col">₱<?php echo number_format($row['price'], 2); ?></td>
                                </tr>
                                <?php
                                if ($is_end) {
                                    echo '<tr class="category-separator"><td colspan="9" style="background:#f8f9fa; height:10px; border:none;"></td></tr>';
                                }
                                $prev_category = $row['category'];
                                ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" style="text-align:center; color:#888;">No products found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

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
.inventory-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}
.inventory-filters .form-control {
    border-radius: 6px;
    border: 1px solid #d1d5db;
    padding: 7px 12px;
    font-size: 1rem;
    min-width: 200px;
    flex: 1;
    transition: all 0.2s ease;
}
.inventory-filters .form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.15);
    transform: translateY(-1px);
}
.inventory-filters .btn {
    border-radius: 6px;
    font-size: 1rem;
    padding: 7px 16px;
    white-space: nowrap;
    transition: all 0.2s ease;
}
.inventory-filters .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(13,110,253,0.2);
}
.inventory-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    font-size: 1rem;
    transition: all 0.3s ease;
}
.inventory-table th, .inventory-table td {
    padding: 12px 10px;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    transition: all 0.2s ease;
}
.inventory-table th {
    background: #f4f6fa;
    color: #23272b;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 1;
    transition: all 0.3s ease;
}
.inventory-table tr {
    transition: all 0.2s ease;
}
.inventory-table tr:hover {
    /* keep subtle hover for accessibility; primary highlight handled via JS .row-highlight */
    background: transparent;
}
.table-responsive {
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(44,49,54,0.07);
    transition: all 0.3s ease;
}
.table-responsive:hover {
    box-shadow: 0 4px 16px rgba(44,49,54,0.1);
}
.status-cell {
    position: relative;
    display: block;
    padding-left: 1.5rem;
    font-weight: 600;
    letter-spacing: 0.01em;
}
.status-cell .status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    position: absolute;
    top: 0;
    left: 0;
    border: 2px solid #fff;
    box-shadow: 0 0 0 3px rgba(15,23,42,0.12);
}
.status-cell.status-in-stock {
    color: #047857;
}
.status-cell.status-in-stock .status-indicator {
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,0.25);
}
.status-cell.status-low-stock {
    color: #b45309;
}
.status-cell.status-low-stock .status-indicator {
    background: #fb923c;
    box-shadow: 0 0 0 3px rgba(251,146,60,0.25);
}
.status-cell.status-out-stock {
    color: #b91c1c;
}
.status-cell.status-out-stock .status-indicator {
    background: #ef4444;
    box-shadow: 0 0 0 3px rgba(239,68,68,0.22);
}

/* Column separators and price alignment */
.inventory-table th, .inventory-table td {
    border-right: 1px solid #eef2f7;
}
.inventory-table th:last-child, .inventory-table td:last-child {
    border-right: none;
}
.inventory-table th.price-col, .inventory-table td.price-col {
    text-align: right;
}

/* Category accent and separators */
.inventory-table tr.category-start td:first-child {
    border-left: 4px solid rgba(13,110,253,0.12);
}
.category-separator td {
    padding: 6px 0 !important;
}

/* Row-only highlight controlled via JS */
.inventory-table tr.row-highlight td {
    background: rgba(13,110,253,0.06) !important;
}

/* Responsive styles */
@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0 !important;
        padding: 10px !important;
    }
    .content-card {
        padding: 16px;
        transform: none !important;
    }
    .inventory-filters {
        flex-direction: column;
        align-items: stretch;
    }
    .inventory-filters .form-control,
    .inventory-filters .btn {
        width: 100%;
    }
    .inventory-table th, 
    .inventory-table td {
        font-size: 0.95rem;
        padding: 8px;
    }
}

@media (max-width: 767.98px) {
    .main-content {
        padding: 5px !important;
    }
    .content-card {
        padding: 12px;
    }
    h2 {
        padding: 5px 15px 8px 15px !important;
        font-size: 1.3rem;
    }
    .inventory-table {
        font-size: 0.9rem;
    }
    .inventory-table th, 
    .inventory-table td {
        padding: 6px;
    }
    .inventory-filters .form-control {
        min-width: 100%;
    }
}

/* Print styles */
@media print {
    .sidebar,
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
    .inventory-table {
        box-shadow: none !important;
    }
    .inventory-table th {
        background: #f4f6fa !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var rows = document.querySelectorAll('.inventory-table tbody tr');
        rows.forEach(function(row) {
            row.addEventListener('mouseenter', function() {
                row.classList.add('row-highlight');
            });
            row.addEventListener('mouseleave', function() {
                row.classList.remove('row-highlight');
            });
        });
    });
</script>

<script>
window.addEventListener('load', function() {
  document.body.classList.add('loaded');
});
</script>

</body>
</html>
