<?php
// Enable error reporting to prevent blank 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // Only used for UI logic, not for redirection.
require_once '../config/db.php';

// Check if user is an authenticated staff member
$is_staff = isset($_SESSION['user_id']) && in_array($_SESSION['user_role'], ['admin', 'supervisor']);

if (!isset($_GET['id']) || empty($_GET['id'])) { 
    die("<div style='padding:20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; color: #CC2200; font-weight: 600; font-size: 1.1rem;'>Purchase Order not found.</div>"); 
}
$po_id = (int)$_GET['id'];

// Fetch PO Details 
$poStmt = $pdo->prepare("SELECT po.*, s.company_name, s.name as contact_person, s.phone, s.email, s.address FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id WHERE po.id = ?");
$poStmt->execute([$po_id]);
$po = $poStmt->fetch();

if (!$po) { 
    die("<div style='padding:20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; color: #CC2200; font-weight: 600; font-size: 1.1rem;'>Purchase Order not found.</div>"); 
}

// Backward compatibility check for older orders
$claimed_daily_pay = isset($po['claimed_daily_pay']) ? (float)$po['claimed_daily_pay'] : 0;
$working_days = isset($po['working_days']) ? (int)$po['working_days'] : 0;
$daily_pay_rate = isset($po['daily_pay_rate']) ? (float)$po['daily_pay_rate'] : 0;

// Fetch Items
$itemsStmt = $pdo->prepare("SELECT poi.*, p.name, p.sku FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.po_id = ?");
$itemsStmt->execute([$po_id]);
$items = $itemsStmt->fetchAll();

// Construct Dynamic URLs for QR and Live Link
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = $_SERVER['HTTP_HOST'];
$root_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
$po_url = $protocol . "://" . $host . $root_path . "/pages/view_po.php?id=" . $po_id;
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($po_url);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order #<?php echo str_pad($po['id'], 6, '0', STR_PAD_LEFT); ?> - Candent</title>
    
    <!-- Modern Fonts & Bootstrap -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
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
            background-color: <?php echo $is_staff ? 'var(--bg-body)' : '#ffffff'; ?>; 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }
        
        .invoice-wrapper { max-width: 900px; margin: <?php echo $is_staff ? '2.5rem auto' : '0 auto'; ?>; }
        
        .action-bar { 
            background: #ffffff; padding: 1rem 1.5rem; border-radius: 12px; 
            border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); 
            margin-bottom: 24px; 
        }
        
        #po-document { 
            background: #ffffff; 
            padding: <?php echo $is_staff ? '3rem 3.5rem' : '1.5rem'; ?>; 
            border-radius: 16px; 
            box-shadow: <?php echo $is_staff ? '0 10px 30px rgba(0,0,0,0.08)' : 'none'; ?>; 
            border: <?php echo $is_staff ? '1px solid var(--border-color)' : 'none'; ?>;
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
        
        .invoice-title { font-size: 2.2rem; font-weight: 800; color: var(--text-main); letter-spacing: 1px; margin-bottom: 0.25rem; line-height: 1; text-transform: uppercase;}
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
        .status-pending { background-color: #fef7e0; color: #b06000; border: 1px solid #feefc3; }
        
        .signature-line { border-top: 1px solid var(--border-color); width: 220px; margin-top: 3rem; padding-top: 0.5rem; text-align: center; font-size: 0.8rem; font-weight: 500; color: var(--text-muted); page-break-inside: avoid;}

        .notice-box { background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 24px; }
        .notice-box h6 { color: #dc2626; font-weight: 700; margin-bottom: 6px; font-size: 0.9rem; }
        .notice-box p { color: #991b1b; font-size: 0.8rem; margin: 0; line-height: 1.5; }

        @media print {
            body { background-color: #fff; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .invoice-wrapper { margin: 0; max-width: 100%; width: 100%; }
            #po-document { box-shadow: none; padding: 20px !important; border: none; max-width: 100%; margin: 0;}
            .no-print { display: none !important; }
            .invoice-top-accent { display: none; }
            .d-print-block { display: block !important; }
        }
    </style>
</head>
<body>

<?php if($is_staff): ?>
<div class="invoice-wrapper container">
    <div class="action-bar d-flex justify-content-between align-items-center no-print flex-wrap gap-2">
        <a href="purchase_orders.php" class="btn btn-light border fw-semibold text-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to POs
        </a>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-dark fw-semibold shadow-sm">
                <i class="bi bi-printer me-1"></i> Print / PDF
            </button>
            <button class="btn btn-primary fw-semibold shadow-sm px-4" id="btnMail" style="background-color: var(--brand-primary); border-color: var(--brand-primary);">
                <i class="bi bi-envelope me-1"></i> Send via Email
            </button>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Public UI Actions (Mobile Friendly Download) -->
<div class="text-center mb-4 mt-4 no-print d-flex justify-content-center gap-2">
    <button onclick="window.print()" class="btn btn-primary rounded-pill fw-semibold px-4 py-2" style="background-color: var(--brand-primary); border-color: var(--brand-primary);">
        <i class="bi bi-printer me-2"></i> Print / Save PDF
    </button>
</div>
<?php endif; ?>

<div class="invoice-wrapper container">
    <div id="po-document">
        <!-- Top Gradient Line -->
        <div class="invoice-top-accent"></div>
        
        <!-- Header Section -->
        <div class="row mb-5 align-items-start">
            <div class="col-6">
                <img src="/images/logo/croped-white-logo.png" alt="Candent Logo" style="height: 65px; display: block; object-fit: contain;" onerror="this.onerror=null; this.src='https://via.placeholder.com/200x65/000000/ffffff?text=CANDENT';">
                <div class="company-name mt-3">Candent</div>
                <div class="company-details mt-1">
                    79, Dambakanda Estate, Boyagane,<br>
                    Kurunegala, Sri Lanka.<br>
                    Tel: 076 140 7876 | Email: candentlk@gmail.com
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="invoice-title">PURCHASE ORDER</div>
                <div class="invoice-number mb-3">PO-<?php echo str_pad($po['id'], 6, '0', STR_PAD_LEFT); ?></div>
                <div class="status-badge <?php echo in_array(strtolower($po['status']), ['completed', 'received']) ? 'status-paid' : 'status-pending'; ?>">
                    <?php echo strtoupper($po['status']); ?>
                </div>
            </div>
        </div>

        <!-- Supplier & Details Grid -->
        <div class="row g-4 mb-2">
            <!-- Order Issued To -->
            <div class="col-7">
                <div class="section-title">Order Issued To</div>
                <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($po['company_name']); ?></h5>
                <div class="text-muted small" style="line-height: 1.6; font-size: 0.95rem;">
                    Attn: <?php echo htmlspecialchars($po['contact_person'] ?: 'Sales Dept'); ?><br>
                    <?php echo nl2br(htmlspecialchars($po['address'])); ?><br>
                    <i class="bi bi-telephone text-secondary me-1 mt-1 d-inline-block"></i> <?php echo htmlspecialchars($po['phone']); ?>
                </div>
            </div>
            
            <!-- PO Info Box -->
            <div class="col-5">
                <div class="info-box shadow-sm">
                    <div class="info-row">
                        <span class="info-label">Order Date</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($po['po_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Expected By</span>
                        <span class="info-value"><?php echo $po['expected_date'] ? date('M d, Y', strtotime($po['expected_date'])) : 'ASAP'; ?></span>
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
                    <th style="width: 15%; text-align: center;">Order Qty</th>
                    <th style="width: 15%; text-align: right;">Unit Cost (Rs)</th>
                    <th style="width: 20%; text-align: right;">Line Total (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach($items as $item): 
                    $line_total = $item['quantity'] * $item['unit_price'];
                ?>
                <tr>
                    <td style="text-align: center; color: var(--text-muted); font-weight: 500;"><?php echo $counter++; ?></td>
                    <td>
                        <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                        <div class="item-sku">SKU: <?php echo htmlspecialchars($item['sku'] ?: 'N/A'); ?></div>
                    </td>
                    <td style="text-align: center; font-weight: 700;"><?php echo $item['quantity']; ?></td>
                    <td style="text-align: right; color: var(--text-muted);"><?php echo number_format($item['unit_price'], 2); ?></td>
                    <td style="text-align: right; font-weight: 700; color: var(--text-main);"><?php echo number_format($line_total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Footer / Totals Section -->
        <div class="row mt-4">
            <!-- Authorizations & Notices -->
            <div class="col-6">
                


                <div class="mb-4">
                    <div class="section-title">Notes / Instructions</div>
                    <div class="text-muted small" style="line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($po['notes'] ?? 'Please confirm receipt of this Purchase Order and expected delivery date.')); ?>
                    </div>
                </div>
                
                <!-- Signature Block -->
                <div class="signature-line">
                    System Generated Signature<br>
                    <span style="font-weight: 800; color: var(--text-main); font-size: 0.95rem; margin-top: 6px; display: inline-block;">
                        Candent
                    </span>
                </div>
            </div>
            
            <!-- Totals Calculation -->
            <div class="col-6">
                <div class="totals-wrapper">
                    <div class="totals-row">
                        <span class="totals-label">Gross Subtotal:</span>
                        <span class="totals-value">Rs <?php echo number_format($po['subtotal'], 2); ?></span>
                    </div>
                    


                    <!-- Net Amount Box -->
                    <div class="grand-total-row">
                        <span class="grand-total-label">Net Payable:</span>
                        <span class="grand-total-value">Rs <?php echo number_format($po['total_amount'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Print Footer with QR -->
        <div class="row mt-5 pt-4 text-center border-top border-light">
            <div class="col-12 text-muted" style="font-size: 0.75rem;">
                System Developed & Maintained by <a href="https://suzxlabs.com" target="_blank" class="text-decoration-none fw-bold text-secondary">Suzxlabs</a>
            </div>
        </div>

        <div class="text-center d-none d-print-block mt-4 mb-2">
            <img src="<?php echo $qr_url; ?>" alt="QR Code" style="width: 90px; height: 90px; border: 1px solid var(--border-color); padding: 5px; border-radius: 8px; background: #fff;">
            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 6px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Scan for live tracking</div>
        </div>

    </div>
</div>

<?php if($is_staff): ?>
<script>
document.getElementById('btnMail').addEventListener('click', async function() {
    const btn = this;
    const origHtml = btn.innerHTML;
    
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Sending...';
    btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('po_id', '<?php echo $po_id; ?>');

        const res = await fetch('../ajax/send_po.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            btn.classList.replace('btn-primary', 'btn-success');
            btn.style.backgroundColor = 'var(--brand-success)';
            btn.style.borderColor = 'var(--brand-success)';
            btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Sent with True PDF!';
            
            setTimeout(() => {
                btn.classList.replace('btn-success', 'btn-primary');
                btn.style.backgroundColor = 'var(--brand-primary)';
                btn.style.borderColor = 'var(--brand-primary)';
                btn.innerHTML = origHtml;
                btn.disabled = false;
            }, 4000);
        } else {
            alert('Failed to send: ' + data.error);
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    } catch(e) {
        console.error(e);
        alert('Network error or Server PDF generation failed.');
        btn.innerHTML = origHtml;
        btn.disabled = false;
    }
});
</script>
<?php endif; ?>

</body>
</html>