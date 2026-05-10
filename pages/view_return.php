<?php
session_start(); // Only used for UI logic, not for redirection.
require_once '../config/db.php';

// Check if user is an authenticated staff member
$is_staff = isset($_SESSION['user_id']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("<div style='padding:20px; font-family:sans-serif;'>Invalid Return ID.</div>");
}

$return_id = (int)$_GET['id'];

// Fetch Return, Customer, and Rep Details
$stmt = $pdo->prepare("
    SELECT sr.*, c.name as customer_name, c.address, c.phone, u.name as rep_name 
    FROM sales_returns sr 
    LEFT JOIN customers c ON sr.customer_id = c.id 
    LEFT JOIN users u ON sr.rep_id = u.id 
    WHERE sr.id = ?
");
$stmt->execute([$return_id]);
$return = $stmt->fetch();

if (!$return) {
    die("<div style='padding:20px; font-family:sans-serif;'>Return note not found or has been deleted.</div>");
}

// Fetch Return Items
$itemStmt = $pdo->prepare("
    SELECT sri.*, p.name as product_name, p.sku 
    FROM sales_return_items sri 
    JOIN products p ON sri.product_id = p.id 
    WHERE sri.return_id = ?
");
$itemStmt->execute([$return_id]);
$items = $itemStmt->fetchAll();

// Construct Dynamic File Name
$custNameStr = !empty($return['customer_name']) ? $return['customer_name'] : 'Walk-in';
$dateStr = date('Y-m-d', strtotime($return['created_at']));
$rawFileName = "Credit Note - {$custNameStr} - {$dateStr}";
$cleanFileName = preg_replace('/[^A-Za-z0-9 \-_]/', '', $rawFileName) . '.pdf';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return #<?php echo str_pad($return['id'], 6, '0', STR_PAD_LEFT); ?> - Fintrix DMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        body { background-color: <?php echo $is_staff ? '#f4f6f9' : '#fff'; ?>; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .invoice-wrapper { max-width: 850px; margin: <?php echo $is_staff ? '2rem auto' : '0 auto'; ?>; }
        .action-bar { background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        
        #invoice-content { background: #fff; padding: <?php echo $is_staff ? '40px' : '20px 15px'; ?>; border-radius: 8px; box-shadow: <?php echo $is_staff ? '0 4px 15px rgba(0,0,0,0.1)' : 'none'; ?>; }
        
        .invoice-header { border-bottom: 3px solid #dc3545; padding-bottom: 1.5rem; margin-bottom: 2rem; }
        .brand-title { color: #2c3e50; font-weight: 800; letter-spacing: 1px; }
        .invoice-meta-label { font-weight: 700; color: #6c757d; font-size: 0.85rem; text-transform: uppercase; }
        .invoice-meta-val { font-weight: 600; color: #212529; }
        
        .table-invoice th { background-color: #dc3545 !important; color: #fff !important; font-size: 0.85rem; text-transform: uppercase; padding: 12px 10px; }
        .table-invoice td { vertical-align: middle; padding: 12px 10px; }
        
        .totals-section { background-color: #f8f9fa; border-radius: 5px; padding: 20px; margin-top: 20px; border: 1px solid #f5c2c7; }
        .totals-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 1rem; }
        .totals-row.grand-total { border-top: 2px solid #dee2e6; padding-top: 10px; font-size: 1.3rem; font-weight: 800; color: #dc3545; }
        
        @media print {
            body { background-color: #fff; margin: 0; padding: 0; }
            .invoice-wrapper { margin: 0; max-width: 100%; }
            #invoice-content { box-shadow: none; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="invoice-wrapper">
    
    <?php if($is_staff): ?>
    <div class="action-bar d-flex justify-content-between align-items-center no-print flex-wrap gap-2">
        <a href="javascript:window.close();" class="btn btn-outline-secondary fw-bold">
            <i class="bi bi-x-lg"></i> Close
        </a>
        <div class="d-flex gap-2">
            <button onclick="downloadInvoice()" class="btn btn-danger fw-bold shadow-sm" id="downloadBtn">
                <i class="bi bi-file-pdf-fill"></i> Download PDF
            </button>
            <button onclick="window.print()" class="btn btn-primary fw-bold shadow-sm">
                <i class="bi bi-printer-fill"></i> Print
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div id="invoice-content">
        
        <div class="invoice-header d-flex justify-content-between align-items-end">
            <div>
                <h1 class="brand-title mb-1">FINTRIX</h1>
                <div class="text-muted small">Distribution Management System</div>
            </div>
            <div class="text-end">
                <h2 class="text-uppercase fw-bold mb-1 text-danger">Credit Note / Return</h2>
                <div class="fs-5 fw-bold text-dark">RET-<?php echo str_pad($return['id'], 6, '0', STR_PAD_LEFT); ?></div>
            </div>
        </div>

        <div class="row mb-4 g-4">
            <div class="col-sm-6">
                <div class="invoice-meta-label mb-2">Customer Details</div>
                <?php if(!empty($return['customer_name'])): ?>
                    <div class="fs-5 fw-bold mb-1 text-dark"><?php echo htmlspecialchars($return['customer_name']); ?></div>
                    <div class="text-muted"><?php echo nl2br(htmlspecialchars($return['address'])); ?></div>
                    <div class="text-muted mt-1"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($return['phone'] ?: 'N/A'); ?></div>
                <?php else: ?>
                    <div class="fs-5 fw-bold text-muted fst-italic">Unknown Customer</div>
                <?php endif; ?>
            </div>
            
            <div class="col-sm-6">
                <div class="bg-danger bg-opacity-10 p-3 rounded border border-danger border-opacity-25">
                    <div class="row mb-2">
                        <div class="col-5 invoice-meta-label">Return Date:</div>
                        <div class="col-7 invoice-meta-val text-end"><?php echo date('M d, Y', strtotime($return['created_at'])); ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-5 invoice-meta-label">Processed By:</div>
                        <div class="col-7 invoice-meta-val text-end"><?php echo htmlspecialchars($return['rep_name'] ?: 'System Admin'); ?></div>
                    </div>
                    <div class="row">
                        <div class="col-5 invoice-meta-label">Reason:</div>
                        <div class="col-7 text-end fst-italic small">
                            <?php echo htmlspecialchars($return['notes'] ?: 'Customer Return'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-invoice mb-4">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 15%;">SKU</th>
                        <th style="width: 40%;">Item Description</th>
                        <th class="text-center" style="width: 10%;">Condition</th>
                        <th class="text-center" style="width: 10%;">Qty</th>
                        <th class="text-end" style="width: 10%;">Rate</th>
                        <th class="text-end" style="width: 10%;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach($items as $item): 
                        $grossTotal = $item['quantity'] * $item['unit_price'];
                    ?>
                    <tr>
                        <td class="text-center text-muted"><?php echo $counter++; ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars($item['sku'] ?: 'N/A'); ?></td>
                        <td class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td class="text-center text-uppercase fw-bold" style="font-size: 0.8rem;">
                            <?php if($item['condition_status'] == 'good'): ?>
                                <span class="text-success">Good</span>
                            <?php elseif($item['condition_status'] == 'damaged'): ?>
                                <span class="text-danger">Damaged</span>
                            <?php else: ?>
                                <span class="text-warning text-dark">Expired</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo $item['quantity']; ?></td>
                        <td class="text-end"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="text-end fw-bold"><?php echo number_format($grossTotal, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row">
            <div class="col-md-6 mt-4">
                <div class="text-muted small">
                    <strong>Note to Customer:</strong><br>
                    The total credit value shown here has been automatically deducted from your outstanding balance in our system.
                </div>
            </div>
            <div class="col-md-6">
                <div class="totals-section">
                    <div class="totals-row grand-total">
                        <span>Total Credit Value</span>
                        <span>Rs: <?php echo number_format($return['total_amount'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-5 pt-4 text-center no-print">
            <div class="col-12 text-muted small">
                Generated securely by <strong>Fintrix Distribution Management System</strong>.
            </div>
        </div>

    </div>
</div>

<script>
    function downloadInvoice() {
        const element = document.getElementById('invoice-content');
        const btn = document.getElementById('downloadBtn');
        const originalHtml = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating...';

        const opt = {
            margin:       [0.3, 0.3, 0.3, 0.3], 
            filename:     '<?php echo addslashes($cleanFileName); ?>',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(element).save().then(() => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        });
    }
</script>

</body>
</html>