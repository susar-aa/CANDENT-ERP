<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    die("Invalid request method. Please generate the quotation from the Create Quotation page.");
}

$supermarket = trim($_POST['supermarket_name'] ?? '');
$manager = trim($_POST['manager_name'] ?? '');
$quotation_date = $_POST['quotation_date'] ?? date('Y-m-d');
$valid_until = $_POST['valid_until'] ?? date('Y-m-d', strtotime('+7 days'));
$selected_products = $_POST['selected_products'] ?? [];

if (empty($selected_products)) {
    die("<div style='font-family: sans-serif; padding: 20px; text-align: center; color: red;'><strong>Error:</strong> Please select at least one product.</div>");
}

// Prepare items array
$items = [];
foreach ($selected_products as $pid) {
    $items[] = [
        'name' => $_POST['product_name'][$pid] ?? 'Unknown',
        'cost' => (float)($_POST['product_cost'][$pid] ?? 0),
        'mrp' => (float)($_POST['product_mrp'][$pid] ?? 0),
        'pcs' => (int)($_POST['product_pcs'][$pid] ?? 1),
        'promo' => trim($_POST['promo_text'][$pid] ?? '')
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation - <?php echo htmlspecialchars($supermarket); ?></title>
    <!-- Same style foundation as view_invoice -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 20px 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .invoice-container { max-width: 850px; margin: 0 auto; background: #fff; padding: 50px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 12px; }
        
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #f3f4f6; padding-bottom: 30px; margin-bottom: 30px; }
        .company-details h1 { margin: 0; font-size: 26px; font-weight: 800; color: #111827; letter-spacing: -0.5px; }
        .company-details p { margin: 4px 0 0; color: #4b5563; font-size: 14px; }
        .invoice-title { text-align: right; }
        .invoice-title h2 { margin: 0; font-size: 32px; font-weight: 800; color: #2563eb; text-transform: uppercase; letter-spacing: 1.5px; }
        .invoice-title p { margin: 6px 0 0; color: #6b7280; font-size: 14px; font-weight: 600; }
        
        .meta-row { display: flex; justify-content: space-between; margin-bottom: 40px; }
        .billed-to { flex: 1; }
        .billed-to h3 { margin: 0 0 10px; font-size: 12px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
        .billed-to strong { display: block; font-size: 18px; color: #111827; margin-bottom: 4px; }
        .billed-to p { margin: 4px 0; color: #4b5563; font-size: 14px; }
        
        .invoice-meta { text-align: right; }
        .invoice-meta table { width: 100%; max-width: 250px; margin-left: auto; font-size: 14px; }
        .invoice-meta td { padding: 6px 0; }
        .invoice-meta td.label { color: #6b7280; font-weight: 500; text-align: right; padding-right: 15px; }
        .invoice-meta td.value { color: #111827; font-weight: 700; text-align: right; }
        
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 14px; }
        .items-table th { background: #f9fafb; padding: 14px; text-align: left; font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .items-table td { padding: 16px 14px; border-bottom: 1px solid #f3f4f6; color: #1f2937; vertical-align: top; }
        .items-table .text-right { text-align: right; }
        .items-table .text-center { text-align: center; }
        
        .promo-text { color: #059669; font-weight: 600; font-size: 12px; margin-top: 6px; display: flex; align-items: center; gap: 4px;}
        .margin-badge { background: #ecfdf5; color: #059669; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; display: inline-block; border: 1px solid #d1fae5; }
        
        .footer-note { text-align: center; margin-top: 50px; padding-top: 30px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 13px; line-height: 1.6; }
        
        .action-bar { max-width: 850px; margin: 0 auto 20px; display: flex; justify-content: flex-end; gap: 12px; }
        .btn-custom { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: opacity 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .btn-custom:hover { opacity: 0.9; }
        .btn-print { background: #111827; color: #fff; }
        .btn-whatsapp { background: #25D366; color: #fff; text-decoration: none; }
        
        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .invoice-container { box-shadow: none; max-width: 100%; padding: 20px; }
            .no-print { display: none !important; }
            .margin-badge { border: none; padding: 0; background: transparent; }
        }

        @media (max-width: 768px) {
            body { padding: 10px 0; }
            .invoice-container { padding: 20px; border-radius: 0; }
            .header { flex-direction: column; text-align: center; gap: 20px; }
            .invoice-title { text-align: center; }
            .meta-row { flex-direction: column; gap: 20px; }
            .invoice-meta { text-align: left; }
            .invoice-meta table { margin-left: 0; max-width: 100%; }
            .invoice-meta td.label { text-align: left; }
            .action-bar { flex-direction: column; padding: 0 16px; width: 100%; }
            .btn-custom { width: 100%; justify-content: center; }
            .items-table { font-size: 12px; }
            .items-table th, .items-table td { padding: 10px 8px; }
        }
    </style>
</head>
<body>

<div class="action-bar no-print">
    <button onclick="window.print()" class="btn-custom btn-print">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1z"/><path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/></svg>
        Save PDF / Print
    </button>
    <a href="#" id="waLink" target="_blank" class="btn-custom btn-whatsapp">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c-.003 1.396.362 2.76.104 3.963L0 16l4.223-1.108c1.168.627 2.477.958 3.766.958 4.368 0 7.927-3.558 7.93-7.926a7.854 7.854 0 0 0-2.318-5.598zM7.994 14.365c-1.18 0-2.338-.317-3.35-.914l-.24-.142-2.492.653.666-2.433-.156-.251a6.36 6.36 0 0 1-1-3.691c.003-3.523 2.871-6.39 6.397-6.39 1.708.001 3.314.667 4.522 1.876 1.207 1.208 1.872 2.813 1.87 4.522-.003 3.522-2.87 6.39-6.393 6.39z"/></svg>
        Share on WhatsApp
    </a>
</div>

<div class="invoice-container">
    <div class="header">
        <div class="company-details">
            <h1>CANDENT ERP</h1>
            <p>123 Business Road, Colombo 03</p>
            <p>Phone: +94 77 123 4567</p>
            <p>Email: sales@candent.lk</p>
        </div>
        <div class="invoice-title">
            <h2>QUOTATION</h2>
            <p>Product Pricing & Offers</p>
        </div>
    </div>
    
    <div class="meta-row">
        <div class="billed-to">
            <h3>Prepared For</h3>
            <strong><?php echo htmlspecialchars($supermarket); ?></strong>
            <?php if($manager): ?>
            <p>Attn: <?php echo htmlspecialchars($manager); ?></p>
            <?php endif; ?>
        </div>
        <div class="invoice-meta">
            <table>
                <tr><td class="label">Date:</td><td class="value"><?php echo date('d M Y', strtotime($quotation_date)); ?></td></tr>
                <tr><td class="label">Valid Until:</td><td class="value" style="color: #dc2626;"><?php echo date('d M Y', strtotime($valid_until)); ?></td></tr>
            </table>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 30%;">Product Description</th>
                    <th class="text-center" style="width: 10%;">Pack<br>Size</th>
                    <th class="text-right" style="width: 12%;">Unit Cost<br><small style="text-transform: none; font-weight: normal;">(To You - Rs)</small></th>
                    <th class="text-right" style="width: 12%;">Unit MRP<br><small style="text-transform: none; font-weight: normal;">(Retail - Rs)</small></th>
                    <th class="text-center" style="width: 12%;">Your<br>Margin</th>
                    <th class="text-right" style="width: 12%;">Pack Cost<br><small style="text-transform: none; font-weight: normal;">(Rs)</small></th>
                    <th class="text-right" style="width: 12%;">Pack MRP<br><small style="text-transform: none; font-weight: normal;">(Rs)</small></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $wa_text = "*QUOTATION FOR ".strtoupper($supermarket)."*\n";
                if($manager) $wa_text .= "Attn: $manager\n";
                $wa_text .= "Date: " . date('d M Y', strtotime($quotation_date)) . "\n";
                $wa_text .= "Valid Until: " . date('d M Y', strtotime($valid_until)) . "\n\n";

                foreach($items as $item): 
                    $pcs = max(1, $item['pcs']);
                    $unit_cost = $item['cost'] / $pcs;
                    $unit_selling = $item['mrp'] / $pcs;
                    
                    // Supermarket's profit margin = ((Unit Selling - Unit Cost) / Unit Selling) * 100
                    $margin_pct = 0;
                    if ($unit_selling > 0) {
                        $margin_pct = (($unit_selling - $unit_cost) / $unit_selling) * 100;
                    }
                    
                    $wa_text .= "▪️ *{$item['name']}*\n";
                    $wa_text .= "Pack Size: {$item['pcs']} pcs\n";
                    $wa_text .= "Pack Cost: Rs " . number_format($item['cost'], 2) . "\n";
                    $wa_text .= "MRP per Pack: Rs " . number_format($item['mrp'], 2) . "\n";
                    $wa_text .= "Your Margin: " . number_format($margin_pct, 1) . "%\n";
                    if ($item['promo']) $wa_text .= "🎁 Promo: {$item['promo']}\n";
                    $wa_text .= "\n";
                ?>
                <tr>
                    <td>
                        <strong style="color: #111827;"><?php echo htmlspecialchars($item['name']); ?></strong>
                        <?php if($item['promo']): ?>
                            <div class="promo-text"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg><?php echo htmlspecialchars($item['promo']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center font-monospace"><?php echo $pcs; ?></td>
                    <td class="text-right" style="color: #2563eb; font-weight: 600;"><?php echo number_format($unit_cost, 2); ?></td>
                    <td class="text-right" style="font-weight: 600;"><?php echo number_format($unit_selling, 2); ?></td>
                    <td class="text-center">
                        <span class="margin-badge">
                            <?php echo number_format($margin_pct, 1); ?>%
                        </span>
                    </td>
                    <td class="text-right" style="font-weight: 700; color: #111827;"><?php echo number_format($item['cost'], 2); ?></td>
                    <td class="text-right" style="color: #6b7280; font-weight: 500;"><?php echo number_format($item['mrp'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="footer-note">
        <strong>Terms & Conditions</strong><br>
        Prices are subject to change without prior notice after the validity period.<br>
        This is a computer-generated quotation and does not require a physical signature.<br>
        Thank you for your business!
    </div>
</div>

<script>
    // Prepare WhatsApp Link
    const waText = encodeURIComponent(`<?php echo $wa_text; ?>`);
    document.getElementById('waLink').href = `https://wa.me/?text=${waText}`;
</script>

</body>
</html>
