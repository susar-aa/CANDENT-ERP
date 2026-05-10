<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("<div style='padding:20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; color: #CC2200; font-weight: 600; font-size: 1.1rem;'>Invalid GRN ID.</div>");
}

$grn_id = (int)$_GET['id'];

// Fetch GRN and Supplier Details
$stmt = $pdo->prepare("
    SELECT g.*, s.company_name, s.address, s.phone, s.email, u.name as receiver_name 
    FROM grns g 
    LEFT JOIN suppliers s ON g.supplier_id = s.id 
    LEFT JOIN users u ON g.created_by = u.id 
    WHERE g.id = ?
");
$stmt->execute([$grn_id]);
$grn = $stmt->fetch();

if (!$grn) {
    die("<div style='padding:20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; color: #CC2200; font-weight: 600; font-size: 1.1rem;'>GRN not found.</div>");
}

// Fetch GRN Items
$itemStmt = $pdo->prepare("
    SELECT gi.*, p.name as product_name, p.sku 
    FROM grn_items gi 
    JOIN products p ON gi.product_id = p.id 
    WHERE gi.grn_id = ?
");
$itemStmt->execute([$grn_id]);
$items = $itemStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRN #<?php echo str_pad($grn['id'], 6, '0', STR_PAD_LEFT); ?> - Candent</title>
    
    <!-- Modern Fonts & Bootstrap -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        :root {
            --brand-primary: #0055CC;
            --brand-success: #198754;
            --text-main: #1A1A1A;
            --text-muted: #555555;
            --border-color: #E0E0E0;
            --bg-light: #F8F9FA;
            --bg-body: #F4F6F9;
        }

        body { 
            background-color: var(--bg-body); 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }
        
        .invoice-wrapper { max-width: 900px; margin: 2.5rem auto; }
        
        .action-bar { 
            background: #ffffff; padding: 1rem 1.5rem; border-radius: 12px; 
            border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
            margin-bottom: 24px; 
        }
        
        #grn-document { 
            background: #ffffff; 
            padding: 3rem 3.5rem; 
            border-radius: 16px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08); 
            border: 1px solid var(--border-color);
            position: relative; overflow: hidden;
            margin: 0 auto;
        }
        
        /* Premium Top Accent Line */
        .invoice-top-accent { 
            position: absolute; top: 0; left: 0; right: 0; height: 6px; 
            background: linear-gradient(90deg, #30C88A, var(--brand-primary)); 
        }

        .company-name { font-size: 1.2rem; font-weight: 800; color: var(--text-main); margin-top: 0.5rem; letter-spacing: -0.5px; }
        .company-details { font-size: 0.85rem; color: var(--text-muted); line-height: 1.6; margin-top: 0.25rem; }
        
        .invoice-title { font-size: 1.8rem; font-weight: 800; color: var(--text-main); letter-spacing: 1px; margin-bottom: 0.25rem; line-height: 1.2; text-transform: uppercase;}
        .invoice-number { font-size: 1.1rem; font-weight: 600; color: var(--text-muted); }

        .section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; color: var(--text-muted); margin-bottom: 0.75rem; }
        
        /* Info Boxes */
        .info-box { background-color: var(--bg-light); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; height: 100%; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem; align-items: center;}
        .info-row:last-child { margin-bottom: 0; }
        .info-label { color: var(--text-muted); font-weight: 500; }
        .info-value { color: var(--text-main); font-weight: 700; text-align: right; }
        
        /* Table Styling */
        .table-invoice { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 2rem; margin-bottom: 2rem; }
        .table-invoice th { 
            background-color: var(--bg-light); color: var(--text-main); 
            font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; 
            padding: 12px 16px; border-bottom: 1px solid var(--border-color); border-top: 2px solid var(--text-main); 
        }
        .table-invoice th:first-child { border-left: 1px solid var(--bg-light); }
        .table-invoice th:last-child { border-right: 1px solid var(--bg-light); }
        
        .table-invoice td { padding: 14px 16px; border-bottom: 1px solid var(--border-color); vertical-align: middle; color: var(--text-main); font-size: 0.9rem; }
        .table-invoice tr { page-break-inside: avoid; } 
        
        .item-name { font-weight: 700; color: var(--text-main); display: block; }
        .item-sku { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem; }

        /* Totals Section */
        .totals-wrapper { width: 100%; max-width: 380px; margin-left: auto; page-break-inside: avoid; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.9rem; }
        .totals-label { color: var(--text-muted); font-weight: 500; text-align: right; padding-right: 1.5rem; flex: 1;}
        .totals-value { color: var(--text-main); font-weight: 600; width: 120px; text-align: right;}
        
        .totals-row.claims .totals-label, .totals-row.claims .totals-value { color: #dc3545; }
        
        .grand-total-row { 
            display: flex; justify-content: space-between; align-items: center; 
            border-top: 2px solid var(--text-main); border-bottom: 2px solid var(--border-color);
            padding: 12px 0; margin-top: 0.5rem;
        }
        .grand-total-label { color: var(--text-main); font-weight: 700; font-size: 1.1rem; text-transform: uppercase; flex: 1; text-align: right; padding-right: 1.5rem;}
        .grand-total-value { color: var(--text-main); font-weight: 800; font-size: 1.3rem; width: 120px; text-align: right;}

        /* Badges */
        .status-badge { padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; }
        .status-paid { background-color: #e6f4ea; color: #1e8e3e; border: 1px solid #ceead6; }
        .status-waiting { background-color: #e0f2fe; color: #1e40af; border: 1px solid #bfdbfe; }
        .status-pending { background-color: #fef7e0; color: #b06000; border: 1px solid #feefc3; }
        
        .signature-line { border-top: 1px solid var(--border-color); width: 250px; margin-top: 4rem; padding-top: 0.5rem; text-align: center; font-size: 0.8rem; font-weight: 500; color: var(--text-muted); page-break-inside: avoid;}

        @media print {
            body { background-color: #fff; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .invoice-wrapper { margin: 0; max-width: 100%; width: 100%; }
            #grn-document { box-shadow: none; padding: 20px !important; border: none; max-width: 100%; margin: 0;}
            .no-print { display: none !important; }
            .invoice-top-accent { display: none; }
        }
    </style>
</head>
<body>

<div class="invoice-wrapper container">
    
    <div class="action-bar d-flex justify-content-between align-items-center no-print flex-wrap gap-2">
        <button onclick="window.close();" class="btn btn-light border fw-semibold text-secondary">
            <i class="bi bi-x-lg me-1"></i> Close
        </button>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark fw-semibold shadow-sm">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <button onclick="downloadGRN()" class="btn btn-primary fw-semibold shadow-sm px-4" id="btnDownload" style="background-color: var(--brand-primary); border-color: var(--brand-primary);">
                <i class="bi bi-file-earmark-pdf me-1"></i> Save PDF
            </button>
        </div>
    </div>

    <div id="grn-document">
        <!-- Top Gradient Line -->
        <div class="invoice-top-accent"></div>
        
        <!-- Header Section -->
        <!-- Fixed columns to ensure they don't stack in PDF engine -->
        <div class="row mb-5 align-items-start">
            <div class="col-6">
                <img src="/images/logo/logo.png" alt="Candent Logo" style="height: 65px; display: block; object-fit: contain;" onerror="this.onerror=null; this.src='https://via.placeholder.com/200x65/000000/ffffff?text=CANDENT';">
                <div class="company-name mt-3">Candent</div>
                <div class="company-details mt-1">
                    79, Dambakanda Estate, Boyagane,<br>
                    Kurunegala, Sri Lanka.<br>
                    Tel: 076 140 7876 | Email: candentlk@gmail.com<br>
                    Web: candent.suzxlabs.com
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="invoice-title">GOODS RECEIVE NOTE</div>
                <div class="invoice-number mb-3">GRN-#<?php echo str_pad($grn['id'], 6, '0', STR_PAD_LEFT); ?></div>
                
                <?php 
                    $statusClass = 'status-pending';
                    $statusText = 'PENDING';
                    
                    if ($grn['payment_status'] == 'paid') {
                        $statusClass = 'status-paid';
                        $statusText = 'PAID';
                    } elseif ($grn['payment_status'] == 'waiting') {
                        $statusClass = 'status-waiting';
                        $statusText = 'WAITING (CHQ)';
                    }
                ?>
                <div class="status-badge <?php echo $statusClass; ?>">
                    <?php echo $statusText; ?>
                </div>
            </div>
        </div>

        <!-- Supplier & Details Grid -->
        <div class="row g-4 mb-2">
            <!-- Received From -->
            <div class="col-7">
                <div class="section-title">Received From</div>
                <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($grn['company_name'] ?: 'Unknown Supplier'); ?></h5>
                <div class="text-muted small" style="line-height: 1.6; font-size: 0.95rem;">
                    <?php echo nl2br(htmlspecialchars($grn['address'] ?? '')); ?><br>
                    <?php if (!empty($grn['phone'])): ?>
                        <i class="bi bi-telephone text-secondary me-1 mt-1 d-inline-block"></i> <?php echo htmlspecialchars($grn['phone']); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- GRN Info Box -->
            <div class="col-5">
                <div class="info-box shadow-sm">
                    <div class="info-row">
                        <span class="info-label">GRN Date</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($grn['grn_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Supplier Ref</span>
                        <span class="info-value" style="color: var(--brand-primary);"><?php echo htmlspecialchars($grn['reference_number'] ?: 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Pay Term</span>
                        <span class="info-value text-capitalize"><?php echo htmlspecialchars($grn['payment_method']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Received By</span>
                        <span class="info-value"><?php echo htmlspecialchars($grn['receiver_name']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="table-invoice">
            <thead>
                <tr>
                    <th style="width: 5%; text-align: center;">#</th>
                    <th style="width: 45%;">Product Description</th>
                    <th style="width: 15%; text-align: center;">Rec. Qty</th>
                    <th style="width: 15%; text-align: right;">Unit Cost (Rs)</th>
                    <th style="width: 20%; text-align: right;">Total (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach($items as $item): 
                    $grossTotal = $item['quantity'] * $item['unit_cost'];
                ?>
                <tr>
                    <td style="text-align: center; color: var(--text-muted); font-weight: 500;"><?php echo $counter++; ?></td>
                    <td>
                        <span class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                        <div class="item-sku">SKU: <?php echo htmlspecialchars($item['sku'] ?: 'N/A'); ?></div>
                    </td>
                    <td style="text-align: center; font-weight: 700;"><?php echo $item['quantity']; ?></td>
                    <td style="text-align: right; color: var(--text-muted);"><?php echo number_format($item['unit_cost'], 2); ?></td>
                    <td style="text-align: right; font-weight: 700; color: var(--text-main);"><?php echo number_format($grossTotal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Footer / Totals Section -->
        <div class="row mt-4">
            <!-- Authorizations & Notices -->
            <div class="col-6">
                <div class="mb-4">
                    <div class="section-title">Goods Verification</div>
                    <div class="text-muted small" style="line-height: 1.6;">
                        I hereby confirm that all goods listed above have been received in good condition, matched with the supplier invoice, and placed into inventory.
                    </div>
                </div>
                
                <!-- Signature Block -->
                <div class="signature-line">
                    Receiver's Signature<br>
                    <span style="font-weight: 800; color: var(--text-main); font-size: 0.95rem; margin-top: 6px; display: inline-block;">
                        <?php echo htmlspecialchars($grn['receiver_name']); ?>
                    </span>
                </div>
            </div>
            
            <!-- Totals Calculation -->
            <div class="col-6">
                <div class="totals-wrapper">
                    <div class="totals-row">
                        <span class="totals-label">Subtotal:</span>
                        <span class="totals-value">Rs <?php echo number_format($grn['subtotal'], 2); ?></span>
                    </div>
                    
                    <?php if($grn['discount_amount'] > 0): ?>
                    <div class="totals-row claims">
                        <span class="totals-label">Less: Discount Received</span>
                        <span class="totals-value">- Rs <?php echo number_format($grn['discount_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($grn['tax_amount'] > 0): ?>
                    <div class="totals-row">
                        <span class="totals-label">VAT / Tax</span>
                        <span class="totals-value">+ Rs <?php echo number_format($grn['tax_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Net Amount Box -->
                    <div class="grand-total-row">
                        <span class="grand-total-label">Net Value:</span>
                        <span class="grand-total-value">Rs <?php echo number_format($grn['total_amount'], 2); ?></span>
                    </div>

                </div>
            </div>
        </div>
        
        <div class="row mt-5 pt-3 text-center no-print">
            <div class="col-12 text-muted" style="font-size: 0.75rem;">
                System Developed & Maintained by <a href="https://suzxlabs.com" target="_blank" class="text-decoration-none fw-bold text-secondary">Suzxlabs</a>
            </div>
        </div>

    </div>
</div>

<script>
async function downloadGRN() {
    const btn = document.getElementById('btnDownload');
    const origHtml = btn.innerHTML;
    
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Generating PDF...';
    btn.disabled = true;

    try {
        const element = document.getElementById('grn-document');
        const grnName = 'GRN_<?php echo str_pad($grn['id'], 6, '0', STR_PAD_LEFT); ?>.pdf';
        
        // TEMPORARY FIX FOR HTML2PDF RESPONSIVE SCALING ISSUES
        const originalWidth = element.style.width;
        const originalMaxWidth = element.style.maxWidth;
        element.style.width = '850px';
        element.style.maxWidth = '850px';
        
        const opt = {
            margin:       [0.3, 0.3, 0.3, 0.3], 
            filename:     grnName,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        await html2pdf().set(opt).from(element).save();

        // Restore styles
        element.style.width = originalWidth;
        element.style.maxWidth = originalMaxWidth;
        
        btn.innerHTML = origHtml;
        btn.disabled = false;
        
    } catch(e) {
        console.error(e);
        alert('PDF generation failed.');
        btn.innerHTML = origHtml;
        btn.disabled = false;
        
        // Ensure styles revert
        const element = document.getElementById('grn-document');
        element.style.width = '';
        element.style.maxWidth = '';
    }
}
</script>
</body>
</html>