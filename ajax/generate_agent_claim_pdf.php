<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

if (!file_exists('../vendor/autoload.php')) {
    die("DomPDF library is missing. Please run 'composer require dompdf/dompdf' on the server.");
}

require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_supplier = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

if ($selected_supplier <= 0) {
    die("Invalid Supplier ID");
}

// Fetch Supplier Info
$stmt = $pdo->prepare("SELECT company_name, name FROM suppliers WHERE id = ?");
$stmt->execute([$selected_supplier]);
$supplier = $stmt->fetch();
$supplier_name = $supplier['company_name'] ?? 'Unknown Supplier';

// Get Report Data
$stmt = $pdo->prepare("
    SELECT 
        c.id as category_id,
        c.name as category_name,
        c.profit_percentage,
        COALESCE(SUM(oi.quantity), 0) as total_units_sold,
        COALESCE(SUM(oi.quantity * oi.price), 0) as total_sales_value
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE DATE_FORMAT(o.created_at, '%Y-%m') = ? 
      AND p.supplier_id = ?
      AND o.total_amount > 0
    GROUP BY c.id
    ORDER BY total_sales_value DESC
");
$stmt->execute([$selected_month, $selected_supplier]);
$results = $stmt->fetchAll();

$report_data = [];
$total_gross_sales = 0;
$total_claimable_profit = 0;

foreach($results as $row) {
    $category_name = $row['category_name'] ?: 'Uncategorized';
    $profit_percentage = (float)$row['profit_percentage'];
    $sales_value = (float)$row['total_sales_value'];
    $claimable = $sales_value * ($profit_percentage / 100);

    $total_gross_sales += $sales_value;
    $total_claimable_profit += $claimable;

    $report_data[] = [
        'category_name' => $category_name,
        'profit_percentage' => $profit_percentage,
        'total_units_sold' => $row['total_units_sold'],
        'total_sales_value' => $sales_value,
        'claimable_profit' => $claimable
    ];
}

// Prepare Data for PDF
$base64Logo = '';
$logoUrl = 'https://candent.suzxlabs.com/images/logo/croped-white-logo.png';
$logoData = @file_get_contents($logoUrl);
if ($logoData) {
    $base64Logo = 'data:image/png;base64,' . base64_encode($logoData);
} else {
    $localPath = __DIR__ . '/../images/logo/logo.png';
    if (file_exists($localPath)) {
        $mime = mime_content_type($localPath);
        $base64Logo = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($localPath));
    }
}
$pdfLogoTag = $base64Logo ? "<img src='{$base64Logo}' class='logo' alt='Candent Logo'>" : "<h1 style='color:#0055CC; margin:0;'>CANDENT</h1>";

$table_rows = '';
foreach($report_data as $row) {
    $table_rows .= "
        <tr>
            <td style='padding: 12px; border-bottom: 1px solid #E0E0E0;'><strong>" . htmlspecialchars($row['category_name']) . "</strong></td>
            <td style='padding: 12px; border-bottom: 1px solid #E0E0E0; text-align: center;'>" . number_format($row['total_units_sold']) . "</td>
            <td style='padding: 12px; border-bottom: 1px solid #E0E0E0; text-align: right;'>Rs " . number_format($row['total_sales_value'], 2) . "</td>
            <td style='padding: 12px; border-bottom: 1px solid #E0E0E0; text-align: center;'><strong>" . number_format($row['profit_percentage'], 2) . "%</strong></td>
            <td style='padding: 12px; border-bottom: 1px solid #E0E0E0; text-align: right; color: #1A9A3A; font-weight: bold;'>Rs " . number_format($row['claimable_profit'], 2) . "</td>
        </tr>
    ";
}

if (empty($report_data)) {
    $table_rows = "<tr><td colspan='5' style='padding: 20px; text-align: center; color: #777;'>No sales data found for the selected month.</td></tr>";
}

$pdfHtml = "
<!DOCTYPE html>
<html>
<head>
    <style>
        @page { margin: 40px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 13px; color: #1A1A1A; }
        .header-table { width: 100%; margin-bottom: 30px; }
        .header-table td { vertical-align: top; border: none; padding: 0; }
        .logo { width: 100%; max-width: 250px; height: auto; margin-bottom: 10px; }
        .brand-details { font-size: 11px; color: #555; line-height: 1.4; }
        .title { font-size: 24px; font-weight: bold; color: #0055CC; margin-bottom: 5px; }
        
        .info-table { width: 100%; margin-bottom: 30px; }
        .info-table td { vertical-align: top; border: none; padding: 0; }
        .info-box { background: #F8F9FA; padding: 15px; border: 1px solid #E0E0E0; border-radius: 4px; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background: #F8F9FA; padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase; border-bottom: 2px solid #1A1A1A; border-top: 1px solid #E0E0E0; }
        
        .totals-table { width: 350px; float: right; border-collapse: collapse; margin-top: 10px; }
        .totals-table td { padding: 6px 0; font-size: 12px; border: none; }
        .grand-total td { font-size: 15px; font-weight: bold; border-top: 2px solid #1A1A1A; border-bottom: 2px solid #E0E0E0; padding: 10px 0; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .clear { clear: both; }
    </style>
</head>
<body>
    <table class='header-table'>
        <tr>
            <td style='width: 50%;'>
                {$pdfLogoTag}
                <div class='brand-details'>
                    <strong>Candent</strong><br>
                    79, Dambakanda Estate, Boyagane,<br>
                    Kurunegala, Sri Lanka.<br>
                    Tel: 076 140 7876 | Email: candentlk@gmail.com<br>
                    Web: candent.suzxlabs.com
                </div>
            </td>
            <td class='text-right' style='width: 50%;'>
                <div class='title'>AGENT PROFIT CLAIM</div>
                <div style='font-size: 16px; font-weight: bold; color: #333;'>Month: " . date('F Y', strtotime($selected_month . '-01')) . "</div>
                <div style='font-size: 12px; color: #777; margin-top: 5px;'>Generated on: " . date('d M Y, H:i') . "</div>
            </td>
        </tr>
    </table>

    <table class='info-table'>
        <tr>
            <td style='width: 100%;'>
                <div style='font-size: 11px; color: #777; text-transform: uppercase; font-weight: bold; margin-bottom: 5px; letter-spacing: 1px;'>Claim Addressed To</div>
                <div style='font-size: 18px; font-weight: bold; color: #1A1A1A; margin-bottom: 4px;'>" . htmlspecialchars($supplier_name) . "</div>
                <div style='color: #555; font-size: 13px; line-height: 1.5;'>
                    Attn: " . htmlspecialchars($supplier['name'] ?? 'Sales / Finance Dept') . "
                </div>
            </td>
        </tr>
    </table>

    <table class='items-table'>
        <thead>
            <tr>
                <th style='width: 30%;'>Category</th>
                <th style='width: 15%;' class='text-center'>Units Sold</th>
                <th style='width: 20%;' class='text-right'>Gross Sales (Rs)</th>
                <th style='width: 15%;' class='text-center'>Margin (%)</th>
                <th style='width: 20%;' class='text-right'>Claimable (Rs)</th>
            </tr>
        </thead>
        <tbody>
            {$table_rows}
        </tbody>
    </table>

    <table class='totals-table'>
        <tr>
            <td class='text-right' style='color: #555; width: 60%;'>Total Gross Sales:</td>
            <td class='text-right' style='font-weight: bold; width: 40%;'>Rs " . number_format($total_gross_sales, 2) . "</td>
        </tr>
        <tr class='grand-total'>
            <td class='text-right' style='color: #1A9A3A;'>TOTAL CLAIM AMOUNT:</td>
            <td class='text-right' style='color: #1A9A3A;'>Rs " . number_format($total_claimable_profit, 2) . "</td>
        </tr>
    </table>
    
    <div class='clear'></div>

    <div style='margin-top: 60px; font-size: 11px;'>
        <div style='font-weight: bold; color: #777; text-transform: uppercase; margin-bottom: 5px;'>Notes / Authorization</div>
        <div style='color: #555; line-height: 1.5;'>
            This document outlines the total category sales volume and the corresponding profit margin agreed upon. Please process this rebate claim against our outstanding balance.
        </div>
    </div>

    <div style='margin-top: 50px; width: 200px; border-top: 1px solid #1A1A1A; padding-top: 5px; text-align: center; float: right;'>
        <div style='font-size: 10px; color: #555;'>Authorized Signature</div>
        <div style='font-weight: bold; font-size: 12px; margin-top: 2px;'>Candent Management</div>
    </div>
    
    <div style='position: fixed; bottom: -20px; left: 0; right: 0; text-align: center; font-size: 10px; color: #777;'>
        System Developed & Maintained by Suzxlabs
    </div>
</body>
</html>
";

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($pdfHtml);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = "Agent_Claim_" . preg_replace('/[^A-Za-z0-9]/', '_', $supplier_name) . "_" . $selected_month . ".pdf";

$dompdf->stream($filename, ["Attachment" => true]);
?>
