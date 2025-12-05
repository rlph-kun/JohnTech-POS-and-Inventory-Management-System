<?php
// Generate detailed sales report (Excel) for a date range and branch
// Usage: GET params: branch_id, start_date (YYYY-MM-DD), end_date (YYYY-MM-DD), format=xlsx|csv, report_type=detail|summary

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
allow_roles(['admin']);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$format = isset($_GET['format']) && $_GET['format'] === 'csv' ? 'csv' : 'xlsx';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detail';

// Optional period parameter from UI: 'daily' or 'monthly'. If daily, force today's date range.
$period = isset($_GET['period']) ? $_GET['period'] : null;
if ($period === 'daily') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} elseif ($period === 'weekly') {
    // Default to current week (Monday-Sunday) if dates were not provided
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    if (empty($start_date) || empty($end_date)) {
        $start_date = $week_start;
        $end_date = $week_end;
    }
}

// Default to current month if no dates provided
if (empty($start_date) || empty($end_date)) {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
}

// Basic validation for dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    echo 'Invalid date format. Use YYYY-MM-DD.';
    exit;
}

// Lightweight request logging to project logs for easier debugging (does not replace Apache/PHP logs)
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/report_requests.log';
@file_put_contents($logFile, date('[Y-m-d H:i:s] ') . 'Request: ' . json_encode($_GET) . PHP_EOL, FILE_APPEND);

// Build detailed query
if ($report_type === 'summary') {
    $sql = "SELECT DATE(s.created_at) AS day, COUNT(*) AS transactions, COALESCE(SUM(s.total_amount),0) AS revenue
            FROM sales s
            WHERE s.branch_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
            GROUP BY DATE(s.created_at)
            ORDER BY DATE(s.created_at) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $branch_id, $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // detailed: include each sale item as a row, plus a separate row for service/repair fees
    // Also include discount, status, and calculate product/service amounts
    $sql = "SELECT s.id AS sale_id, s.created_at AS sale_date, u.username AS cashier, s.remarks, m.name AS mechanic, 
             s.total_amount AS sale_total, s.discount, s.discount_rate, s.repair_fee,
             si.product_id, p.name AS product_name, si.quantity, si.price AS unit_price,
             (SELECT CASE WHEN COUNT(*) > 0 THEN 'Returned' ELSE 'Active' END
              FROM return_items ri
              JOIN returns r ON ri.return_id = r.id
              WHERE r.sale_id = s.id) AS status
         FROM sales s
         LEFT JOIN users u ON s.cashier_id = u.id
         LEFT JOIN mechanics m ON s.mechanic_id = m.id
         JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN products p ON si.product_id = p.id
         WHERE s.branch_id = ? AND DATE(s.created_at) BETWEEN ? AND ?

         UNION ALL

         SELECT s.id AS sale_id, s.created_at AS sale_date, u.username AS cashier, s.remarks, m.name AS mechanic, 
             s.total_amount AS sale_total, s.discount, s.discount_rate, s.repair_fee,
             NULL AS product_id, 'Service/Repair' AS product_name, 1 AS quantity, s.repair_fee AS unit_price,
             (SELECT CASE WHEN COUNT(*) > 0 THEN 'Returned' ELSE 'Active' END
              FROM return_items ri
              JOIN returns r ON ri.return_id = r.id
              WHERE r.sale_id = s.id) AS status
         FROM sales s
         LEFT JOIN users u ON s.cashier_id = u.id
         LEFT JOIN mechanics m ON s.mechanic_id = m.id
         WHERE s.branch_id = ? AND DATE(s.created_at) BETWEEN ? AND ? AND COALESCE(s.repair_fee,0) > 0

         ORDER BY sale_date ASC";

    $stmt = $conn->prepare($sql);
    // bind params for both SELECTs in the UNION (branch_id, start_date, end_date) twice
    $stmt->bind_param('ississ', $branch_id, $start_date, $end_date, $branch_id, $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate total sales NET of discounts for the same period (to match Sales History pages)
    $total_sales_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) AS total_sales
                        FROM sales 
                        WHERE branch_id = ? AND DATE(created_at) BETWEEN ? AND ?";
    $stmt_total = $conn->prepare($total_sales_sql);
    $stmt_total->bind_param('iss', $branch_id, $start_date, $end_date);
    $stmt_total->execute();
    $total_result = $stmt_total->get_result();
    $total_sales_row = $total_result->fetch_assoc();
    $net_sales_before_returns = floatval($total_sales_row['total_sales'] ?? 0);
    $stmt_total->close();

    // Recompute total returns for the same period (apply sale discount proportionally and include service fee once for back jobs)
    $returns_total_sql = "SELECT r.id, r.sale_id, r.total_amount, 
                                 s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                          FROM returns r
                          JOIN sales s ON r.sale_id = s.id
                          WHERE r.branch_id = ? AND DATE(r.created_at) BETWEEN ? AND ?";
    $stmt_returns = $conn->prepare($returns_total_sql);
    $stmt_returns->bind_param('iss', $branch_id, $start_date, $end_date);
    $stmt_returns->execute();
    $returns_result = $stmt_returns->get_result();

    $adjusted_returns_total = 0.0;
    while ($return_row = $returns_result->fetch_assoc()) {
        $sale_id = intval($return_row['sale_id']);
        $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0.0;
        $sale_total_amount = isset($return_row['sale_total_amount']) ? floatval($return_row['sale_total_amount']) : 0.0;
        $sale_discount = isset($return_row['sale_discount']) ? floatval($return_row['sale_discount']) : 0.0;
        $discount_ratio = ($sale_total_amount > 0) ? max(min($sale_discount / $sale_total_amount, 1), 0) : 0.0;

        // Calculate product total for this return
        $product_total_sql = "SELECT COALESCE(SUM(ri.quantity * ri.price), 0) as product_total
                              FROM return_items ri
                              WHERE ri.return_id = ?";
        $stmt_products = $conn->prepare($product_total_sql);
        $stmt_products->bind_param('i', $return_row['id']);
        $stmt_products->execute();
        $product_result = $stmt_products->get_result();
        $product_row = $product_result->fetch_assoc();
        $product_total = floatval($product_row['product_total']);
        $stmt_products->close();

        // Start with discounted product amount
        $return_amount = $product_total * (1 - $discount_ratio);

        // For back job with repair fee, include discounted repair fee once (first return only) if there are product items
        if ($repair_fee > 0 && $product_total > 0) {
            $check_first_sql = "SELECT COUNT(*) as count FROM returns r2
                                WHERE r2.sale_id = ? AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
            $stmt_check = $conn->prepare($check_first_sql);
            $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
            $stmt_check->execute();
            $check_result = $stmt_check->get_result();
            $prev_return = $check_result->fetch_assoc();
            $is_first_return = ($prev_return['count'] == 0);
            $stmt_check->close();

            if ($is_first_return) {
                $return_amount += ($repair_fee * (1 - $discount_ratio));
            }
        }

        // Cap returns to the sale's net value
        $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
        if ($return_amount > $net_sale_cap) {
            $return_amount = $net_sale_cap;
        }

        $adjusted_returns_total += $return_amount;
    }
    $stmt_returns->close();

    // Net sales = (sales net of discounts) - (adjusted returns)
    $total_sales = $net_sales_before_returns - $adjusted_returns_total;
}

// For summary report type, calculate total if not already calculated
if ($report_type === 'summary') {
    if (!isset($total_sales)) {
        // Sales net of discounts
        $sales_net_sql = "SELECT COALESCE(SUM(total_amount - discount), 0) AS sales_net
                          FROM sales 
                          WHERE branch_id = ? AND DATE(created_at) BETWEEN ? AND ?";
        $stmt_sn = $conn->prepare($sales_net_sql);
        $stmt_sn->bind_param('iss', $branch_id, $start_date, $end_date);
        $stmt_sn->execute();
        $sn_res = $stmt_sn->get_result();
        $sn_row = $sn_res->fetch_assoc();
        $net_sales_before_returns = floatval($sn_row['sales_net'] ?? 0);
        $stmt_sn->close();

        // Adjusted returns (apply discount ratio; include repair fee once if back job)
        $returns_total_sql = "SELECT r.id, r.sale_id, r.total_amount, 
                                     s.repair_fee, s.total_amount AS sale_total_amount, s.discount AS sale_discount
                              FROM returns r
                              JOIN sales s ON r.sale_id = s.id
                              WHERE r.branch_id = ? AND DATE(r.created_at) BETWEEN ? AND ?";
        $stmt_returns = $conn->prepare($returns_total_sql);
        $stmt_returns->bind_param('iss', $branch_id, $start_date, $end_date);
        $stmt_returns->execute();
        $returns_result = $stmt_returns->get_result();

        $adjusted_returns_total = 0.0;
        while ($return_row = $returns_result->fetch_assoc()) {
            $sale_id = intval($return_row['sale_id']);
            $repair_fee = isset($return_row['repair_fee']) ? floatval($return_row['repair_fee']) : 0.0;
            $sale_total_amount = isset($return_row['sale_total_amount']) ? floatval($return_row['sale_total_amount']) : 0.0;
            $sale_discount = isset($return_row['sale_discount']) ? floatval($return_row['sale_discount']) : 0.0;
            $discount_ratio = ($sale_total_amount > 0) ? max(min($sale_discount / $sale_total_amount, 1), 0) : 0.0;

            $product_total_sql = "SELECT COALESCE(SUM(ri.quantity * ri.price), 0) as product_total
                                  FROM return_items ri
                                  WHERE ri.return_id = ?";
            $stmt_products = $conn->prepare($product_total_sql);
            $stmt_products->bind_param('i', $return_row['id']);
            $stmt_products->execute();
            $product_result = $stmt_products->get_result();
            $product_row = $product_result->fetch_assoc();
            $product_total = floatval($product_row['product_total']);
            $stmt_products->close();

            $return_amount = $product_total * (1 - $discount_ratio);

            if ($repair_fee > 0 && $product_total > 0) {
                $check_first_sql = "SELECT COUNT(*) as count FROM returns r2
                                    WHERE r2.sale_id = ? AND r2.created_at < (SELECT created_at FROM returns WHERE id = ?)";
                $stmt_check = $conn->prepare($check_first_sql);
                $stmt_check->bind_param('ii', $sale_id, $return_row['id']);
                $stmt_check->execute();
                $check_result = $stmt_check->get_result();
                $prev_return = $check_result->fetch_assoc();
                $is_first_return = ($prev_return['count'] == 0);
                $stmt_check->close();

                if ($is_first_return) {
                    $return_amount += ($repair_fee * (1 - $discount_ratio));
                }
            }

            $net_sale_cap = max($sale_total_amount - $sale_discount, 0);
            if ($return_amount > $net_sale_cap) {
                $return_amount = $net_sale_cap;
            }

            $adjusted_returns_total += $return_amount;
        }
        $stmt_returns->close();

        $total_sales = $net_sales_before_returns - $adjusted_returns_total;
    }
}

// Ensure total_sales is initialized for all cases
if (!isset($total_sales)) {
    $total_sales = 0;
}

// Log how many rows were returned for this request (helps debug empty exports)
$rowsCount = is_array($rows) ? count($rows) : 0;
@file_put_contents($logFile, date('[Y-m-d H:i:s] ') . 'RowsReturned: ' . $rowsCount . ' Params: ' . json_encode([
    'branch_id' => $branch_id,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'report_type' => $report_type,
    'period' => $period,
    'format' => $format
]) . PHP_EOL, FILE_APPEND);

// Create spreadsheet and populate
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

if ($report_type === 'summary') {
    if (empty($rows)) {
        $sheet->fromArray(['Day', 'Transactions', 'Revenue'], NULL, 'A1');
    } else {
        $sheet->fromArray(array_keys($rows[0]), NULL, 'A1');
        $sheet->fromArray($rows, NULL, 'A2');
    }
} else {
    $headers = ['Sale ID','Sale Date','Cashier','Product ID','Product Name','Quantity','Unit Price','Product Amount','Service Fee','Discount','Remarks','Mechanic','Status','Sale Total'];
    $sheet->fromArray($headers, NULL, 'A1');
    $summary_row = null;
    $lastRow = 1;

    if (!empty($rows)) {
        $r = 2;
        // Track sales to calculate product amounts per sale
        $sales_data = [];
        foreach ($rows as $row) {
            $sale_id = $row['sale_id'];
            if (!isset($sales_data[$sale_id])) {
                $sales_data[$sale_id] = [
                    'product_total' => 0,
                    'service_fee' => floatval($row['repair_fee'] ?? 0),
                    'discount' => floor(floatval($row['discount'] ?? 0)),
                    'discount_rate' => isset($row['discount_rate']) ? floatval($row['discount_rate']) : null,
                    'status' => $row['status'] ?? 'Active',
                    'net_total' => max(floatval($row['sale_total']) - floor(floatval($row['discount'] ?? 0)), 0)
                ];
            }
            
            // Calculate product total (only for actual products, not service/repair)
            if (!empty($row['product_id']) && $row['product_name'] !== 'Service/Repair') {
                $sales_data[$sale_id]['product_total'] += (floatval($row['quantity']) * floatval($row['unit_price']));
            }
        }
        
        foreach ($rows as $row) {
            $sale_id = $row['sale_id'];
            $quantity = isset($row['quantity']) ? floatval($row['quantity']) : 0;
            $unit_price = isset($row['unit_price']) ? floatval($row['unit_price']) : 0;
            
            // Sale ID
            $sheet->setCellValue('A' . $r, $row['sale_id']);
            
            // Sale date -> attempt to convert to Excel datetime for proper formatting
            if (!empty($row['sale_date'])) {
                try {
                    $dt = new DateTime($row['sale_date']);
                    $sheet->setCellValue('B' . $r, PhpSpreadsheetDate::PHPToExcel($dt));
                    // Date with 12-hour time and AM/PM (e.g., 4:00PM)
                    $sheet->getStyle('B' . $r)->getNumberFormat()->setFormatCode('yyyy-mm-dd h:mm AM/PM');
                } catch (Exception $ex) {
                    $sheet->setCellValue('B' . $r, $row['sale_date']);
                }
            } else {
                $sheet->setCellValue('B' . $r, '');
            }
            
            $sheet->setCellValue('C' . $r, $row['cashier']);
            $sheet->setCellValue('D' . $r, $row['product_id']);
            $sheet->setCellValue('E' . $r, $row['product_name']);
            // Quantity: leave blank for Service/Repair, show quantity for products
            if ($row['product_name'] === 'Service/Repair') {
                $sheet->setCellValue('F' . $r, '');
            } else {
                $sheet->setCellValue('F' . $r, $quantity);
            }
            $sheet->setCellValue('G' . $r, $unit_price);
            
            // Product Amount (only show on first row of each sale, or if it's a product row)
            if ($row['product_name'] !== 'Service/Repair') {
                $sheet->setCellValue('H' . $r, $sales_data[$sale_id]['product_total']);
            } else {
                $sheet->setCellValue('H' . $r, '');
            }
            
            // Service Fee (only show on service/repair row)
            if ($row['product_name'] === 'Service/Repair') {
                $sheet->setCellValue('I' . $r, $sales_data[$sale_id]['service_fee']);
            } else {
                $sheet->setCellValue('I' . $r, '');
            }
            
            // Discount (format as "amount (percentage%)")
            // Show discount on first row of each sale (when product_id is not null, or if it's the service row and no products)
            if ($row['product_id'] !== null || ($row['product_name'] === 'Service/Repair' && $sales_data[$sale_id]['product_total'] == 0)) {
                $discount_amount = $sales_data[$sale_id]['discount'];
                $discount_rate = $sales_data[$sale_id]['discount_rate'];
                
                if ($discount_amount > 0) {
                    // Force whole peso discount amount
                    $discount_amount = floor($discount_amount);
                    $formatted_amount = number_format($discount_amount, 0, '.', '');
                    
                    // Calculate percentage if not provided
                    if ($discount_rate === null || $discount_rate == 0) {
                        // Calculate percentage from sale total
                        $sale_total = floatval($row['sale_total']);
                        if ($sale_total > 0) {
                            $discount_rate = round(($discount_amount / $sale_total) * 100, 2);
                        }
                    }
                    
                    // Always show percentage if we have a discount amount
                    if ($discount_rate !== null && $discount_rate > 0) {
                        // Format percentage (integer if whole number, otherwise keep decimals)
                        $pct = (intval($discount_rate) == $discount_rate) ? intval($discount_rate) : $discount_rate;
                        $discount_text = '₱' . $formatted_amount . ' (' . $pct . '%)';
                    } else {
                        // If no rate can be calculated, just show amount with consistent formatting
                        $discount_text = '₱' . $formatted_amount;
                    }
                    
                    $sheet->setCellValue('J' . $r, $discount_text);
                } else {
                    $sheet->setCellValue('J' . $r, '');
                }
            } else {
                $sheet->setCellValue('J' . $r, '');
            }
            
            $sheet->setCellValue('K' . $r, $row['remarks']);
            $sheet->setCellValue('L' . $r, $row['mechanic']);
            
            // Status (only show on first row of each sale)
            if ($row['product_id'] !== null || ($row['product_name'] === 'Service/Repair' && $sales_data[$sale_id]['product_total'] == 0)) {
                $sheet->setCellValue('M' . $r, $sales_data[$sale_id]['status']);
            } else {
                $sheet->setCellValue('M' . $r, '');
            }
            
            // Sale Total (net of discount) – show only on the first row for each sale
            if ($row['product_id'] !== null || ($row['product_name'] === 'Service/Repair' && $sales_data[$sale_id]['product_total'] == 0)) {
                $sheet->setCellValue('N' . $r, $sales_data[$sale_id]['net_total']);
            } else {
                $sheet->setCellValue('N' . $r, '');
            }
            $r++;
        }
        $lastRow = $r - 1;
        $summary_row = $r;
    }

    if ($summary_row === null) {
        $summary_row = $lastRow + 1;
    }

    // Add summary row with total sales (net sales after returns)
    $sheet->setCellValue('A' . $summary_row, 'TOTAL SALES (NET)');
    // Merge cells A through M for "TOTAL SALES (NET)" label (removed Line Total column)
    $sheet->mergeCells('A' . $summary_row . ':M' . $summary_row);
    // Set total sales value in column N (net sales = gross sales - returns)
    // Display total sales as whole pesos
    $sheet->setCellValue('N' . $summary_row, floor($total_sales));
    
    // Style the summary row
    $sheet->getStyle('A' . $summary_row . ':N' . $summary_row)->getFont()->setBold(true);
    $sheet->getStyle('A' . $summary_row . ':N' . $summary_row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F5E9');
    $sheet->getStyle('A' . $summary_row . ':N' . $summary_row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    
    // Format the total sales value as currency
    $currencyFormat = '₱#,##0';
    $sheet->getStyle('N' . $summary_row)->getNumberFormat()->setFormatCode($currencyFormat);

    // Style header and set column widths / formats
    $headerRange = 'A1:N1';
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1565C0');
    $sheet->freezePane('A2');
    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(30);
    $sheet->getColumnDimension('F')->setWidth(10);
    $sheet->getColumnDimension('G')->setWidth(12);
    $sheet->getColumnDimension('H')->setWidth(15); // Product Amount
    $sheet->getColumnDimension('I')->setWidth(15); // Service Fee
    $sheet->getColumnDimension('J')->setWidth(18); // Discount
    $sheet->getColumnDimension('K')->setWidth(40); // Remarks
    $sheet->getColumnDimension('L')->setWidth(20); // Mechanic
    $sheet->getColumnDimension('M')->setWidth(12); // Status
    $sheet->getColumnDimension('N')->setWidth(12); // Sale Total
    if ($lastRow >= 2) {
        // Quantity as whole number (no decimals)
        $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
        // Currency columns with peso symbol
        $currencyFormat = '₱#,##0';
        $sheet->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat);
        $sheet->getStyle("H2:H{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat); // Product Amount
        $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat); // Service Fee
        // Discount is formatted as text with amount and percentage, no currency formatting needed
        $sheet->getStyle("N2:N{$lastRow}")->getNumberFormat()->setFormatCode($currencyFormat); // Sale Total
    }
    // Center all content (headers + data) and vertically center rows
    $sheet->getStyle("A1:N{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    // Allow remarks to wrap text
    $sheet->getStyle("K1:K{$lastRow}")->getAlignment()->setWrapText(true);
    // Center align discount column (text format: "amount (percentage%)")
    if ($lastRow >= 2) {
        $sheet->getStyle("J2:J{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Add thin borders around all cells (headers, data, and summary row)
    $fullRange = "A1:N{$summary_row}";
    $sheet->getStyle($fullRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

$filename = 'sales_report_' . ($branch_id ? 'branch' . $branch_id . '_' : '') . date('Ymd_His') . '.' . ($format === 'csv' ? 'csv' : 'xlsx');

try {
    // Clean (turn off) output buffering to avoid corrupting the file
    if (ob_get_length()) {
        while (ob_get_level() > 0) ob_end_clean();
    }

    // Common headers
    header('Pragma: public');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Cache-Control: private', false);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $writer = IOFactory::createWriter($spreadsheet, 'Csv');
        $writer->setDelimiter(',');
        $writer->save('php://output');
    } else {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
    }
    exit;
} catch (\Exception $e) {
    // Log and show a simple message (do not output stack traces in production)
    error_log('Report generation error: ' . $e->getMessage());
    if (isset($logFile)) {
        @file_put_contents($logFile, date('[Y-m-d H:i:s] ') . 'Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
    http_response_code(500);
    echo 'Error generating report. Check server logs.';
    exit;
}
