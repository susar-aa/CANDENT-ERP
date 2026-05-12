<?php die("ANTIGRAVITY DEBUG: Script is running!");
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep', 'admin', 'supervisor']);

try {
$rep_id = $_SESSION['user_id'];
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

if (!$assignment_id) {
    error_log("Route Summary Debug: No assignment_id provided");
    die("Invalid Assignment ID.");
}

error_log("Route Summary Debug: Starting for assignment_id $assignment_id and user_id " . ($_SESSION['user_id'] ?? 'NONE'));

// Ensure database schema is up-to-date
try {
    $pdo->exec("ALTER TABLE customer_payments ADD COLUMN assignment_id INT NULL AFTER customer_id");
} catch(PDOException $e) {
    // Column likely already exists
}

error_log("Route Summary Debug: DB Migration check done");

// 1. Fetch Route, Rep, and Driver Info
$sql = "
    SELECT rr.*, r.name as route_name, e.name as driver_name, u.name as rep_name
    FROM rep_routes rr
    JOIN routes r ON rr.route_id = r.id
    LEFT JOIN employees e ON rr.driver_id = e.id
    LEFT JOIN users u ON rr.rep_id = u.id
    WHERE rr.id = ?
";
$params = [$assignment_id];

// Reps can only see their own routes, but admins/supervisors can see all
if ($_SESSION['user_role'] === 'rep') {
    $sql .= " AND rr.rep_id = ?";
    $params[] = $rep_id;
}

$routeStmt = $pdo->prepare($sql);
$routeStmt->execute($params);
$routeInfo = $routeStmt->fetch();

if (!$routeInfo) {
    error_log("Route Summary Debug: Route $assignment_id not found for user $rep_id (Role: " . $_SESSION['user_role'] . ")");
    die("Route not found or access denied.");
}

// Use the Rep ID associated with the route for all subsequent queries
$actual_rep_id = $routeInfo['rep_id'];

$assign_date = $routeInfo['assign_date'];
error_log("Route Summary Debug: Route found for date $assign_date");

// 2. Fetch Orders for this assignment to calculate Sales and Productive Calls
$ordersStmt = $pdo->prepare("
    SELECT total_amount, paid_cash, paid_bank, paid_cheque, customer_id
    FROM orders
    WHERE assignment_id = ? AND order_status != 'cancelled'
");
$ordersStmt->execute([$assignment_id]);
$orders = $ordersStmt->fetchAll();

$cash_sale = 0;
$bank_sale = 0;
$cheque_sale = 0;
$credit_sale = 0;
$total_sale = 0;
$productive_customers = [];

foreach ($orders as $o) {
    $c = (float)$o['paid_cash'];
    $b = (float)$o['paid_bank'];
    $ch = (float)$o['paid_cheque'];
    $tot = (float)$o['total_amount'];
    
    $cash_sale += $c;
    $bank_sale += $b;
    $cheque_sale += $ch;
    $total_sale += $tot;
    
    // Credit is whatever isn't paid by cash, bank, or cheque
    $paid = $c + $b + $ch;
    $credit = $tot - $paid;
    if ($credit > 0) {
        $credit_sale += $credit;
    }

    if (!empty($o['customer_id'])) {
        $productive_customers[$o['customer_id']] = true;
    }
}

$productive_calls = count($productive_customers);

// 3. Fetch Unproductive Calls for the same day
$unprodStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT customer_id) as count
    FROM unproductive_visits
    WHERE rep_id = ? AND DATE(created_at) = ?
");
$unprodStmt->execute([$actual_rep_id, $assign_date]);
$unproductive_calls = (int)$unprodStmt->fetchColumn();

$calls_visited = $productive_calls + $unproductive_calls;
$pc_ratio = $calls_visited > 0 ? round(($productive_calls / $calls_visited) * 100, 1) : 0;

// 4. Monthly Stats (Up to Yesterday)
$monthlyStmt = $pdo->prepare("
    SELECT SUM(total_amount) as prev_total
    FROM orders
    WHERE rep_id = ? 
    AND order_status != 'cancelled'
    AND DATE(created_at) >= DATE_FORMAT(?, '%Y-%m-01')
    AND DATE(created_at) < ?
");
$monthlyStmt->execute([$actual_rep_id, $assign_date, $assign_date]);
$month_up_to_yesterday = (float)$monthlyStmt->fetchColumn();

$cumulative_sale = $month_up_to_yesterday + $total_sale;

// 5. Product/Itemized Sales
$itemsStmt = $pdo->prepare("
    SELECT p.name, SUM(oi.quantity) as total_qty, SUM(oi.quantity * oi.price - oi.discount) as total_value
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.assignment_id = ? AND o.order_status != 'cancelled'
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC
");
$itemsStmt->execute([$assignment_id]);
$products_sold = $itemsStmt->fetchAll();

$credit_collected_cash = 0;
$credit_collected_cheque = 0;

error_log("Route Summary Debug: Fetching collections");
try {
    // 6. Fetch Credit Collections
    $collectionStmt = $pdo->prepare("SELECT method, SUM(amount) as total FROM customer_payments WHERE assignment_id = ? GROUP BY method");
    $collectionStmt->execute([$assignment_id]);
    $collections = $collectionStmt->fetchAll();

    foreach($collections as $col) {
        if ($col['method'] == 'Cash') $credit_collected_cash = (float)$col['total'];
        if ($col['method'] == 'Cheque') $credit_collected_cheque = (float)$col['total'];
    }
} catch (Exception $e) {
    error_log("Route Summary Debug: Collections fetch failed: " . $e->getMessage());
    // If table or column is missing, fail silently with 0 values
}
error_log("Route Summary Debug: Finalizing sales data");
echo "<script>console.log('Route Summary: Data Fetching Completed');</script>";


// ============================================================================
// SERVER-SIDE PDF GENERATION (Triggered via AJAX/Fetch)
// ============================================================================
if (isset($_GET['pdf']) && $_GET['pdf'] == 1) {
    require_once '../vendor/autoload.php'; // Load Dompdf
    
    // Format variables for the PDF Template
    $disp_date = date('d M Y', strtotime($assign_date));
    $driver_name = htmlspecialchars($routeInfo['driver_name'] ?: 'Self-Driven');
    $route_name = htmlspecialchars($routeInfo['route_name']);
    $rep_name = htmlspecialchars($routeInfo['rep_name']);
    
    $f_cash = number_format($cash_sale, 2);
    $f_bank = number_format($bank_sale, 2);
    $f_cheque = number_format($cheque_sale, 2);
    $f_credit = number_format($credit_sale, 2);
    $f_total = number_format($total_sale, 2);
    $f_month = number_format($month_up_to_yesterday, 2);
    $f_cumu = number_format($cumulative_sale, 2);
    $f_col_cash = number_format($credit_collected_cash, 2);
    $f_col_cheque = number_format($credit_collected_cheque, 2);

    // Build product list HTML for the PDF table
    $products_html = '';
    if (empty($products_sold)) {
        $products_html = '<tr><td colspan="2" style="text-align:center; color:#64748B;">No products sold on this route.</td></tr>';
    } else {
        $chunks = array_chunk($products_sold, ceil(count($products_sold) / 2));
        $products_html .= '<tr><td style="width:50%; vertical-align:top; padding-right:15px;"><table class="info-table">';
        if(!empty($chunks[0])) {
            foreach($chunks[0] as $p) {
                $products_html .= '<tr><td>'.htmlspecialchars($p['name']).'</td><td style="text-align:right;"><span class="badge">'.$p['total_qty'].'</span></td></tr>';
            }
        }
        $products_html .= '</table></td><td style="width:50%; vertical-align:top; padding-left:15px;"><table class="info-table">';
        if(!empty($chunks[1])) {
            foreach($chunks[1] as $p) {
                $products_html .= '<tr><td>'.htmlspecialchars($p['name']).'</td><td style="text-align:right;"><span class="badge">'.$p['total_qty'].'</span></td></tr>';
            }
        }
        $products_html .= '</table></td></tr>';
    }

    // HTML specifically optimized for Dompdf's renderer
    $pdfHtml = "
    <html>
    <head>
        <style>
            body { font-family: 'Helvetica', 'Arial', sans-serif; color: #0F172A; font-size: 13px; }
            .header-title { text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 20px; color: #1E293B; }
            .grid { width: 100%; border-collapse: separate; border-spacing: 15px; margin-top: -15px; margin-left: -15px; width: calc(100% + 30px); }
            .card { width: 50%; vertical-align: top; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 15px; }
            .card-full { width: 100%; vertical-align: top; background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 15px; }
            .title { font-size: 12px; font-weight: bold; color: #64748B; text-transform: uppercase; border-bottom: 1px dashed #E2E8F0; padding-bottom: 8px; margin-bottom: 10px; }
            .info-table { width: 100%; border-collapse: collapse; }
            .info-table td { padding: 8px 0; border-bottom: 1px solid #F8FAFC; }
            .info-table tr:last-child td { border-bottom: none; }
            .val { text-align: right; font-weight: bold; }
            .text-primary { color: #2563EB; } .text-success { color: #16A34A; } .text-danger { color: #DC2626; }
            .total-row { background: #EFF6FF; }
            .total-row td { padding: 10px; border: 1px dashed #BFDBFE !important; color: #1D4ED8; font-weight: bold; font-size: 14px; }
            .total-pc { background: #FFFBEB; }
            .total-pc td { border-color: #FDE68A !important; color: #B45309; }
            .total-cumu { background: #ECFDF5; }
            .total-cumu td { border-color: #A7F3D0 !important; color: #047857; }
            .money { font-family: 'Courier New', Courier, monospace; }
            .badge { background: #2563EB; color: white; padding: 3px 8px; border-radius: 10px; font-size: 11px; }
        </style>
    </head>
    <body>
        <div class='header-title'>Route Summary Dashboard</div>
        
        <table class='grid'>
            <tr>
                <td class='card'>
                    <div class='title'>General Details</div>
                    <table class='info-table'>
                        <tr><td>Date</td><td class='val'>{$disp_date}</td></tr>
                        <tr><td>Rep Name</td><td class='val'>{$rep_name}</td></tr>
                        <tr><td>Distributor</td><td class='val text-primary'>Candent</td></tr>
                        <tr><td>Route</td><td class='val'>{$route_name}</td></tr>
                        <tr><td>Driver</td><td class='val'>{$driver_name}</td></tr>
                    </table>
                </td>
                <td class='card'>
                    <div class='title'>Visit Metrics</div>
                    <table class='info-table'>
                        <tr><td>Productive Calls</td><td class='val text-success'>{$productive_calls}</td></tr>
                        <tr><td>Unproductive Calls</td><td class='val text-danger'>{$unproductive_calls}</td></tr>
                        <tr><td>Total Calls Visited</td><td class='val'>{$calls_visited}</td></tr>
                        <tr class='total-row total-pc'><td>TOTAL P/C</td><td class='val'>{$pc_ratio}%</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class='grid'>
            <tr>
                <td class='card'>
                    <div class='title'>Sales Breakdown</div>
                    <table class='info-table'>
                        <tr><td>Cash Sale</td><td class='val money'>Rs {$f_cash}</td></tr>
                        <tr><td>Bank Transfer</td><td class='val money'>Rs {$f_bank}</td></tr>
                        <tr><td>Cheque Sale</td><td class='val money'>Rs {$f_cheque}</td></tr>
                        <tr><td>Credit Sale</td><td class='val money text-danger'>Rs {$f_credit}</td></tr>
                        <tr class='total-row'><td>TOTAL SALE</td><td class='val money'>Rs {$f_total}</td></tr>
                    </table>
                </td>
                <td class='card'>
                    <div class='title'>Collections & Monthly</div>
                    <table class='info-table'>
                        <tr><td>Credit Collected (Cash)</td><td class='val money text-success'>Rs {$f_col_cash}</td></tr>
                        <tr><td>Credit Collected (Cheque)</td><td class='val money text-success'>Rs {$f_col_cheque}</td></tr>
                        <tr><td colspan="2" style="border-top: 1px dashed #E2E8F0;"></td></tr>
                        <tr><td>Month Up to Yesterday</td><td class='val money'>Rs {$f_month}</td></tr>
                        <tr class='total-row total-cumu'><td>CUMULATIVE SALE</td><td class='val money'>Rs {$f_cumu}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class='grid'>
            <tr>
                <td class='card-full'>
                    <div class='title'>Products Sold Today</div>
                    <table style='width: 100%; border-collapse: collapse;'>
                        {$products_html}
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Route_Summary_'.$assign_date.'.pdf"');
    echo $dompdf->output();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Route Summary - CANDENT ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2563EB;
            --primary-dark: #1D4ED8;
            --bg-color: #F8FAFC;
            --surface: #FFFFFF;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --border: #E2E8F0;
        }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: 30px;
            margin: 0;
        }

        #pdf-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 15px;
            background-color: var(--bg-color);
        }

        .summary-card {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--border);
            break-inside: avoid;
            margin-bottom: 15px;
        }

        .summary-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 12px;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.92rem;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .info-value {
            font-weight: 700;
            color: var(--text-main);
        }

        .info-value.money {
            font-family: 'JetBrains Mono', monospace;
        }

        .total-row {
            background: #eff6ff;
            padding: 10px 14px;
            border-radius: 10px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px dashed #bfdbfe;
        }

        .total-row .info-label {
            color: var(--primary-dark);
            font-weight: 800;
        }

        .total-row .info-value {
            color: var(--primary-dark);
            font-size: 1.1rem;
            font-family: 'JetBrains Mono', monospace;
        }

        .product-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .product-qty {
            background: var(--primary);
            color: white;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-family: 'JetBrains Mono', monospace;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        @media (min-width: 600px) {
            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }
            .summary-card {
                margin-bottom: 0;
            }
            .full-width {
                grid-column: span 2;
            }
        }

        @media print {
            .no-print, .btn-container { display: none !important; }
            body { background: white; padding: 0; }
            #pdf-container { padding: 0; }
            .summary-card { box-shadow: none; border: 1px solid #eee; }
        }
    </style>
</head>
<body>

    <div id="pdf-container">
        <!-- 1. Header Info & 4. Visit Metrics -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title"><i class="bi bi-info-circle-fill"></i> General Details</div>
                <div class="info-row">
                    <span class="info-label">Date</span>
                    <span class="info-value"><?php echo date('d M Y', strtotime($assign_date)); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Rep Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($routeInfo['rep_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Distributor</span>
                    <span class="info-value text-primary">Candent</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Route</span>
                    <span class="info-value"><?php echo htmlspecialchars($routeInfo['route_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Work With (Driver)</span>
                    <span class="info-value"><?php echo htmlspecialchars($routeInfo['driver_name'] ?: 'Self-Driven'); ?></span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-title"><i class="bi bi-shop"></i> Visit Metrics</div>
                <div class="info-row">
                    <span class="info-label">Productive Calls</span>
                    <span class="info-value text-success"><?php echo $productive_calls; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Unproductive Calls</span>
                    <span class="info-value text-danger"><?php echo $unproductive_calls; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Calls Visited</span>
                    <span class="info-value"><?php echo $calls_visited; ?></span>
                </div>
                <div class="total-row" style="background: #fffbeb; border-color: #fde68a;">
                    <span class="info-label" style="color: #b45309;">TOTAL P/C</span>
                    <span class="info-value" style="color: #b45309;"><?php echo $pc_ratio; ?>%</span>
                </div>
            </div>
        </div>

        <!-- 2. Sales Breakdown & 3. Monthly Performance -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-title"><i class="bi bi-wallet2"></i> Sales Breakdown</div>
                <div class="info-row">
                    <span class="info-label">Cash Sale</span>
                    <span class="info-value money">Rs <?php echo number_format($cash_sale, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Bank Transfer</span>
                    <span class="info-value money">Rs <?php echo number_format($bank_sale, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Cheque Sale</span>
                    <span class="info-value money">Rs <?php echo number_format($cheque_sale, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Credit Sale</span>
                    <span class="info-value money text-danger">Rs <?php echo number_format($credit_sale, 2); ?></span>
                </div>
                <div class="total-row">
                    <span class="info-label">TOTAL SALE</span>
                    <span class="info-value">Rs <?php echo number_format($total_sale, 2); ?></span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-title"><i class="bi bi-graph-up-arrow"></i> Collections & Monthly</div>
                <div class="info-row">
                    <span class="info-label">Credit Collected (Cash)</span>
                    <span class="info-value money text-success">Rs <?php echo number_format($credit_collected_cash, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Credit Collected (Cheque)</span>
                    <span class="info-value money text-success">Rs <?php echo number_format($credit_collected_cheque, 2); ?></span>
                </div>
                <div style="height: 12px; border-bottom: 1px dashed var(--border); margin-bottom: 12px;"></div>
                <div class="info-row">
                    <span class="info-label">Month Up to Yesterday</span>
                    <span class="info-value money">Rs <?php echo number_format($month_up_to_yesterday, 2); ?></span>
                </div>
                <div class="total-row" style="background: #ecfdf5; border-color: #a7f3d0;">
                    <span class="info-label" style="color: #047857;">CUMULATIVE SALE</span>
                    <span class="info-value" style="color: #047857;">Rs <?php echo number_format($cumulative_sale, 2); ?></span>
                </div>
            </div>
        </div>

        <!-- 5. Itemized Sales -->
        <div class="summary-grid">
            <div class="summary-card full-width">
                <div class="summary-title"><i class="bi bi-box-seam"></i> Products Sold Today</div>
                <?php if(empty($products_sold)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        <small>No products sold on this route.</small>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php 
                        $chunks = array_chunk($products_sold, ceil(count($products_sold) / 2));
                        foreach ($chunks as $chunk):
                        ?>
                        <div class="col-sm-6">
                            <ul class="product-list">
                                <?php foreach($chunk as $p): ?>
                                <li class="product-item">
                                    <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
                                    <div class="product-qty"><?php echo $p['total_qty']; ?></div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Buttons -->
    <div class="btn-container px-3 d-flex gap-2 max-w-900 mx-auto" style="max-width: 900px;">
        <button onclick="window.print()" class="btn btn-outline-primary flex-grow-1 rounded-pill fw-bold py-3">
            <i class="bi bi-printer me-2"></i> Print
        </button>
        <button id="shareBtn" onclick="sharePDF()" class="btn btn-primary flex-grow-1 rounded-pill fw-bold py-3">
            <i class="bi bi-share me-2"></i> Share PDF
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    async function sharePDF() {
        const btn = document.getElementById('shareBtn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Fetching PDF...';

        try {
            // Fetch the truly generated PDF from the server using the DOMPDF endpoint we created above
            const response = await fetch('?assignment_id=<?php echo $assignment_id; ?>&pdf=1');
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const pdfBlob = await response.blob();
            const fileName = 'Route_Summary_<?php echo $assign_date; ?>.pdf';
            const file = new File([pdfBlob], fileName, { type: 'application/pdf' });

            // Try utilizing the native sharing functionality on Mobile
            if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({
                    title: 'Route Summary - <?php echo $assign_date; ?>',
                    text: 'Please find the attached route summary report.',
                    files: [file]
                });
            } else {
                // Fallback for desktops or un-supported browsers: Download the file automatically
                const url = window.URL.createObjectURL(pdfBlob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = fileName;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
            }
        } catch (err) {
            console.error('Sharing/Downloading failed:', err);
            alert('Failed to generate PDF on the server. Please try again.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    </script>
</body>
    </script>
</body>
</html>
<?php
} catch (Throwable $e) {
    echo "<div style='background:#fee; color:#c00; padding:20px; border:2px solid #c00; margin:20px; font-family:sans-serif; position:relative; z-index:9999;'>";
    echo "<h2>Internal Server Error (Antigravity Debugger)</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " on line " . $e->getLine() . "</p>";
    echo "<pre style='background:#fff; padding:10px; border:1px solid #ddd; max-height:300px; overflow:auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}
?>