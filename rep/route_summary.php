<?php
// Enable error reporting
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
        die("Invalid Assignment ID.");
    }

    // Ensure database schema is up-to-date
    try {
        $pdo->exec("ALTER TABLE customer_payments ADD COLUMN assignment_id INT NULL AFTER customer_id");
    } catch(PDOException $e) {
        // Column likely already exists
    }

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

    if ($_SESSION['user_role'] === 'rep') {
        $sql .= " AND rr.rep_id = ?";
        $params[] = $rep_id;
    }

    $routeStmt = $pdo->prepare($sql);
    $routeStmt->execute($params);
    $routeInfo = $routeStmt->fetch();

    if (!$routeInfo) {
        die("Route not found or access denied.");
    }

    $actual_rep_id = $routeInfo['rep_id'];
    $assign_date = $routeInfo['assign_date'];

    // 2. Fetch Orders for this assignment
    $ordersStmt = $pdo->prepare("
        SELECT total_amount, paid_cash, paid_bank, paid_cheque, customer_id
        FROM orders
        WHERE assignment_id = ? AND order_status != 'cancelled'
    ");
    $ordersStmt->execute([$assignment_id]);
    $orders = $ordersStmt->fetchAll();

    $cash_sale = 0; $bank_sale = 0; $cheque_sale = 0; $credit_sale = 0; $total_sale = 0;
    $productive_customers = [];

    foreach ($orders as $o) {
        $c = (float)$o['paid_cash'];
        $b = (float)$o['paid_bank'];
        $ch = (float)$o['paid_cheque'];
        $tot = (float)$o['total_amount'];
        
        $cash_sale += $c; $bank_sale += $b; $cheque_sale += $ch; $total_sale += $tot;
        
        $paid = $c + $b + $ch;
        $credit = $tot - $paid;
        if ($credit > 0) $credit_sale += $credit;

        if (!empty($o['customer_id'])) {
            $productive_customers[$o['customer_id']] = true;
        }
    }

    $productive_calls = count($productive_customers);

    // 3. Fetch Unproductive Calls
    $unprodStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT customer_id) as count
        FROM unproductive_visits
        WHERE rep_id = ? AND DATE(created_at) = ?
    ");
    $unprodStmt->execute([$actual_rep_id, $assign_date]);
    $unproductive_calls = (int)$unprodStmt->fetchColumn();

    $calls_visited = $productive_calls + $unproductive_calls;
    $pc_ratio = $calls_visited > 0 ? round(($productive_calls / $calls_visited) * 100, 1) : 0;

    // 4. Monthly Stats
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

    // 5. Product Sales
    $itemsStmt = $pdo->prepare("
        SELECT p.name, SUM(oi.quantity) as total_qty
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE o.assignment_id = ? AND o.order_status != 'cancelled'
        GROUP BY p.id, p.name
        ORDER BY total_qty DESC
    ");
    $itemsStmt->execute([$assignment_id]);
    $products_sold = $itemsStmt->fetchAll();

    // 6. Credit Collections
    $credit_collected_cash = 0; $credit_collected_cheque = 0;
    try {
        $collectionStmt = $pdo->prepare("SELECT method, SUM(amount) as total FROM customer_payments WHERE assignment_id = ? GROUP BY method");
        $collectionStmt->execute([$assignment_id]);
        $collections = $collectionStmt->fetchAll();
        foreach($collections as $col) {
            if ($col['method'] == 'Cash') $credit_collected_cash = (float)$col['total'];
            if ($col['method'] == 'Cheque') $credit_collected_cheque = (float)$col['total'];
        }
    } catch (Exception $e) {}

    // ============================================================================
    // PDF GENERATION BLOCK
    // ============================================================================
    if (isset($_GET['pdf']) && $_GET['pdf'] == 1) {
        if (!file_exists('../vendor/autoload.php')) { die("Dompdf not found."); }
        require_once '../vendor/autoload.php';
        
        $disp_date = date('d M Y', strtotime($assign_date));
        $f_cash = number_format($cash_sale, 2); $f_bank = number_format($bank_sale, 2);
        $f_cheque = number_format($cheque_sale, 2); $f_credit = number_format($credit_sale, 2);
        $f_total = number_format($total_sale, 2); $f_col_cash = number_format($credit_collected_cash, 2);
        $f_col_cheque = number_format($credit_collected_cheque, 2);
        $f_month = number_format($month_up_to_yesterday, 2);
        $f_cumu = number_format($cumulative_sale, 2);

        $products_html = '';
        if (empty($products_sold)) {
            $products_html = '<tr><td colspan="2" style="text-align:center; padding:20px; color:#666;">No products sold.</td></tr>';
        } else {
            $chunks = array_chunk($products_sold, ceil(count($products_sold) / 2));
            $products_html .= '<tr><td style="width:50%; vertical-align:top; padding-right:10px;"><table class="product-table">';
            if(!empty($chunks[0])) {
                foreach($chunks[0] as $p) {
                    $products_html .= '<tr><td class="prod-name">'.htmlspecialchars($p['name']).'</td><td class="prod-qty">'.$p['total_qty'].'</td></tr>';
                }
            }
            $products_html .= '</table></td><td style="width:50%; vertical-align:top; padding-left:10px;"><table class="product-table">';
            if(!empty($chunks[1])) {
                foreach($chunks[1] as $p) {
                    $products_html .= '<tr><td class="prod-name">'.htmlspecialchars($p['name']).'</td><td class="prod-qty">'.$p['total_qty'].'</td></tr>';
                }
            }
            $products_html .= '</table></td></tr>';
        }

        $pdfHtml = "
        <html>
        <head>
            <style>
                @page { margin: 30px; }
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #334155; line-height: 1.4; }
                .header-container { text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
                .header-title { font-size: 22px; font-weight: bold; color: #1e40af; margin: 0; text-transform: uppercase; letter-spacing: 1px; }
                .header-subtitle { font-size: 12px; color: #64748b; margin-top: 5px; }
                
                .section-container { margin-bottom: 20px; page-break-inside: avoid; }
                .section-title { 
                    font-size: 11px; font-weight: bold; color: #ffffff; background-color: #3b82f6; 
                    padding: 6px 10px; margin-bottom: 10px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;
                }
                
                table { width: 100%; border-collapse: collapse; }
                .layout-table { width: 100%; table-layout: fixed; margin-bottom: 20px; }
                .layout-table > tbody > tr > td { padding: 0 10px; vertical-align: top; }
                .layout-table > tbody > tr > td:first-child { padding-left: 0; }
                .layout-table > tbody > tr > td:last-child { padding-right: 0; }
                
                .data-table { width: 100%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
                .data-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
                .data-table tr:last-child td { border-bottom: none; }
                .data-table .label { color: #64748b; font-weight: normal; }
                .data-table .val { text-align: right; font-weight: bold; color: #0f172a; }
                .data-table .money { font-family: 'Courier New', Courier, monospace; letter-spacing: -0.5px; }
                
                .total-row td { background-color: #f8fafc; font-weight: bold; font-size: 13px; color: #0f172a; border-top: 2px solid #e2e8f0; }
                .total-row .val { color: #1d4ed8; }
                
                .highlight-success td { background-color: #f0fdf4; }
                .highlight-success .val { color: #16a34a; }
                .highlight-danger td { background-color: #fef2f2; }
                .highlight-danger .val { color: #dc2626; }
                .highlight-warning td { background-color: #fffbeb; }
                .highlight-warning .val { color: #d97706; }
                
                .product-table { width: 100%; border: 1px solid #e2e8f0; border-radius: 4px; }
                .product-table td { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; }
                .product-table tr:last-child td { border-bottom: none; }
                .prod-name { color: #475569; font-size: 11px; }
                .prod-qty { text-align: right; font-weight: bold; color: #0f172a; background-color: #f8fafc; width: 40px; }
            </style>
        </head>
        <body>
            <div class='header-container'>
                <h1 class='header-title'>Route Summary Report</h1>
                <div class='header-subtitle'>Generated on: " . date('Y-m-d H:i:s') . "</div>
            </div>
            
            <table class='layout-table'>
                <tr>
                    <td style='width: 50%;'>
                        <div class='section-container'>
                            <div class='section-title'>General Details</div>
                            <table class='data-table'>
                                <tr><td class='label'>Date</td><td class='val'>$disp_date</td></tr>
                                <tr><td class='label'>Rep Name</td><td class='val'>".htmlspecialchars($routeInfo['rep_name'])."</td></tr>
                                <tr><td class='label'>Distributor</td><td class='val'>Candent</td></tr>
                                <tr><td class='label'>Route</td><td class='val'>".htmlspecialchars($routeInfo['route_name'])."</td></tr>
                                <tr><td class='label'>Driver</td><td class='val'>".htmlspecialchars($routeInfo['driver_name'] ?: 'Self-Driven')."</td></tr>
                            </table>
                        </div>
                    </td>
                    <td style='width: 50%;'>
                        <div class='section-container'>
                            <div class='section-title'>Visit Metrics</div>
                            <table class='data-table'>
                                <tr class='highlight-success'><td class='label'>Productive Calls</td><td class='val'>$productive_calls</td></tr>
                                <tr class='highlight-danger'><td class='label'>Unproductive Calls</td><td class='val'>$unproductive_calls</td></tr>
                                <tr><td class='label'>Total Calls Visited</td><td class='val'>$calls_visited</td></tr>
                                <tr class='total-row highlight-warning'><td class='label'>TOTAL P/C RATIO</td><td class='val'>$pc_ratio%</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <table class='layout-table'>
                <tr>
                    <td style='width: 50%;'>
                        <div class='section-container'>
                            <div class='section-title'>Sales Breakdown</div>
                            <table class='data-table'>
                                <tr><td class='label'>Cash Sale</td><td class='val money'>Rs $f_cash</td></tr>
                                <tr><td class='label'>Bank Transfer</td><td class='val money'>Rs $f_bank</td></tr>
                                <tr><td class='label'>Cheque Sale</td><td class='val money'>Rs $f_cheque</td></tr>
                                <tr><td class='label'>Credit Sale</td><td class='val money' style='color:#dc2626;'>Rs $f_credit</td></tr>
                                <tr class='total-row'><td class='label'>TOTAL SALE</td><td class='val money'>Rs $f_total</td></tr>
                            </table>
                        </div>
                    </td>
                    <td style='width: 50%;'>
                        <div class='section-container'>
                            <div class='section-title'>Monthly Performance</div>
                            <table class='data-table'>
                                <tr><td class='label'>Month Up to Yesterday</td><td class='val money'>Rs $f_month</td></tr>
                                <tr class='total-row highlight-success'><td class='label'>CUMULATIVE SALE</td><td class='val money'>Rs $f_cumu</td></tr>
                            </table>
                        </div>
                        
                        <div class='section-container' style='margin-top: 15px;'>
                            <div class='section-title' style='background-color:#10b981;'>Credit Collections</div>
                            <table class='data-table'>
                                <tr><td class='label'>Cash Collected</td><td class='val money'>Rs $f_col_cash</td></tr>
                                <tr><td class='label'>Cheque Collected</td><td class='val money'>Rs $f_col_cheque</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <div class='section-container'>
                <div class='section-title' style='background-color:#475569;'>Products Sold Today</div>
                <table style='width: 100%;'>
                    $products_html
                </table>
            </div>
        </body>
        </html>";

        $dompdf = new \Dompdf\Dompdf();
        $options = $dompdf->getOptions();
        $options->set('isHtml5ParserEnabled', true);
        $dompdf->setOptions($options);
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
    <title>Route Summary Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #3b82f6; 
            --primary-dark: #2563eb;
            --bg: #f8fafc; 
            --surface: #ffffff; 
            --text-main: #0f172a; 
            --text-muted: #64748b; 
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        
        body { 
            background-color: var(--bg); 
            color: var(--text-main); 
            font-family: 'Inter', sans-serif; 
            padding: 20px 15px; 
            margin: 0; 
            -webkit-font-smoothing: antialiased;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            color: white;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title { margin: 0; font-weight: 700; font-size: 1.5rem; letter-spacing: -0.5px; }
        .header-subtitle { opacity: 0.8; font-size: 0.9rem; margin-top: 4px; }
        
        .btn-share {
            background-color: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 50px;
            transition: all 0.2s ease;
        }
        
        .btn-share:hover {
            background-color: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-2px);
        }

        .metric-card { 
            background: var(--surface); 
            border-radius: 16px; 
            border: 1px solid var(--border); 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -2px rgba(0,0,0,0.02); 
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
        }
        
        .card-header-custom {
            padding: 16px 20px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: var(--text-main);
            font-size: 1.05rem;
        }
        
        .card-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        
        .icon-blue { background: #eff6ff; color: #3b82f6; }
        .icon-green { background: #ecfdf5; color: #10b981; }
        .icon-purple { background: #f5f3ff; color: #8b5cf6; }
        .icon-orange { background: #fffbeb; color: #f59e0b; }
        .icon-slate { background: #f1f5f9; color: #64748b; }

        .card-body-custom { padding: 12px 20px 20px; }

        .info-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 10px 0; 
            border-bottom: 1px dashed var(--border); 
            font-size: 0.95rem;
            color: var(--text-muted);
        }
        
        .info-row:last-child { border-bottom: none; }
        
        .val { font-weight: 600; color: var(--text-main); }
        .money { font-family: 'Courier New', Courier, monospace; font-size: 1rem; font-weight: 700; letter-spacing: -0.5px; }
        
        .total-box { 
            background: #f8fafc; 
            padding: 14px 16px; 
            border-radius: 12px; 
            margin-top: 12px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            border: 1px solid var(--border);
        }
        
        .total-box.primary { background: #eff6ff; border-color: #bfdbfe; }
        .total-box.primary .label { color: #1e40af; }
        .total-box.primary .val { color: #1d4ed8; }
        
        .total-box.success { background: #ecfdf5; border-color: #a7f3d0; }
        .total-box.success .label { color: #065f46; }
        .total-box.success .val { color: #047857; }
        
        .total-box.warning { background: #fffbeb; border-color: #fde68a; }
        .total-box.warning .label { color: #92400e; }
        .total-box.warning .val { color: #b45309; }
        
        .total-box .label { font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .total-box .val { font-weight: 800; font-size: 1.15rem; }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 8px;
            transition: background-color 0.2s;
        }
        
        .product-item:hover { background: #f1f5f9; }
        .product-name { font-size: 0.9rem; font-weight: 500; color: var(--text-main); }
        .product-qty { 
            background: var(--surface); 
            color: var(--primary); 
            min-width: 36px;
            text-align: center;
            padding: 4px 8px; 
            border-radius: 20px; 
            font-size: 0.85rem; 
            font-weight: 700; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .text-success-custom { color: var(--success); }
        .text-danger-custom { color: var(--danger); }
        
        @media (max-width: 768px) {
            .dashboard-header { flex-direction: column; text-align: center; gap: 16px; padding: 20px; }
            .btn-share { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div style="max-width: 1000px; margin: 0 auto;">
        
        <div class="dashboard-header">
            <div>
                <h1 class="header-title"><i class="bi bi-geo-alt-fill me-2 opacity-75"></i>Route Summary</h1>
                <div class="header-subtitle">Performance dashboard for <?php echo htmlspecialchars($routeInfo['rep_name']); ?></div>
            </div>
            <button onclick="sharePDF()" id="shareBtn" class="btn btn-share d-flex align-items-center">
                <i class="bi bi-file-earmark-pdf-fill me-2 fs-5"></i> Export PDF
            </button>
        </div>

        <div class="row g-4">
            <!-- General Details -->
            <div class="col-lg-6">
                <div class="metric-card">
                    <div class="card-header-custom">
                        <div class="card-icon icon-blue"><i class="bi bi-info-circle-fill"></i></div>
                        General Details
                    </div>
                    <div class="card-body-custom">
                        <div class="info-row"><span>Date</span><span class="val"><?php echo date('d M Y', strtotime($assign_date)); ?></span></div>
                        <div class="info-row"><span>Rep Name</span><span class="val"><?php echo htmlspecialchars($routeInfo['rep_name']); ?></span></div>
                        <div class="info-row"><span>Distributor</span><span class="val text-primary">Candent</span></div>
                        <div class="info-row"><span>Route</span><span class="val"><?php echo htmlspecialchars($routeInfo['route_name']); ?></span></div>
                        <div class="info-row"><span>Driver</span><span class="val"><?php echo htmlspecialchars($routeInfo['driver_name'] ?: 'Self-Driven'); ?></span></div>
                    </div>
                </div>
            </div>

            <!-- Visit Metrics -->
            <div class="col-lg-6">
                <div class="metric-card">
                    <div class="card-header-custom">
                        <div class="card-icon icon-orange"><i class="bi bi-shop"></i></div>
                        Visit Metrics
                    </div>
                    <div class="card-body-custom">
                        <div class="info-row"><span>Productive Calls</span><span class="val text-success-custom"><?php echo $productive_calls; ?></span></div>
                        <div class="info-row"><span>Unproductive Calls</span><span class="val text-danger-custom"><?php echo $unproductive_calls; ?></span></div>
                        <div class="info-row"><span>Total Calls Visited</span><span class="val"><?php echo $calls_visited; ?></span></div>
                        
                        <div class="total-box warning mt-4">
                            <span class="label">Total P/C Ratio</span>
                            <span class="val"><?php echo $pc_ratio; ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales Breakdown -->
            <div class="col-lg-6">
                <div class="metric-card">
                    <div class="card-header-custom">
                        <div class="card-icon icon-purple"><i class="bi bi-wallet2"></i></div>
                        Sales Breakdown
                    </div>
                    <div class="card-body-custom">
                        <div class="info-row"><span>Cash Sale</span><span class="val money">Rs <?php echo number_format($cash_sale, 2); ?></span></div>
                        <div class="info-row"><span>Bank Transfer</span><span class="val money">Rs <?php echo number_format($bank_sale, 2); ?></span></div>
                        <div class="info-row"><span>Cheque Sale</span><span class="val money">Rs <?php echo number_format($cheque_sale, 2); ?></span></div>
                        <div class="info-row"><span>Credit Sale</span><span class="val money text-danger-custom">Rs <?php echo number_format($credit_sale, 2); ?></span></div>
                        
                        <div class="total-box primary mt-3">
                            <span class="label">Total Sale</span>
                            <span class="val money">Rs <?php echo number_format($total_sale, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Performance & Collections -->
            <div class="col-lg-6">
                <div class="metric-card d-flex flex-column">
                    <div class="card-header-custom">
                        <div class="card-icon icon-green"><i class="bi bi-graph-up-arrow"></i></div>
                        Monthly Performance
                    </div>
                    <div class="card-body-custom pb-2">
                        <div class="info-row"><span>Month Up to Yesterday</span><span class="val money">Rs <?php echo number_format($month_up_to_yesterday, 2); ?></span></div>
                        
                        <div class="total-box success mt-3 mb-4">
                            <span class="label">Cumulative Sale</span>
                            <span class="val money">Rs <?php echo number_format($cumulative_sale, 2); ?></span>
                        </div>
                    </div>
                    
                    <div class="card-header-custom mt-auto border-top-0 pt-0">
                        <div class="card-icon icon-slate"><i class="bi bi-cash-coin"></i></div>
                        Credit Collections
                    </div>
                    <div class="card-body-custom pt-2">
                        <div class="info-row"><span>Cash Collected</span><span class="val money text-success-custom">Rs <?php echo number_format($credit_collected_cash, 2); ?></span></div>
                        <div class="info-row"><span>Cheque Collected</span><span class="val money text-success-custom">Rs <?php echo number_format($credit_collected_cheque, 2); ?></span></div>
                    </div>
                </div>
            </div>

            <!-- Products Sold -->
            <div class="col-12">
                <div class="metric-card">
                    <div class="card-header-custom">
                        <div class="card-icon icon-slate"><i class="bi bi-box-seam"></i></div>
                        Products Sold Today
                    </div>
                    <div class="card-body-custom">
                        <?php if(empty($products_sold)): ?>
                            <div class="text-center text-muted py-5 d-flex flex-column align-items-center">
                                <i class="bi bi-inbox fs-1 mb-2 text-light"></i>
                                <p class="mb-0">No products sold during this assignment.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-2 mt-1">
                                <?php foreach($products_sold as $p): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="product-item">
                                            <span class="product-name text-truncate me-2" title="<?php echo htmlspecialchars($p['name']); ?>">
                                                <?php echo htmlspecialchars($p['name']); ?>
                                            </span>
                                            <span class="product-qty"><?php echo $p['total_qty']; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    async function sharePDF() {
        const btn = document.getElementById('shareBtn');
        const originalContent = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Generating...';

        try {
            const response = await fetch('?assignment_id=<?php echo $assignment_id; ?>&pdf=1');
            if (!response.ok) throw new Error('Failed to fetch PDF data from server.');
            
            const blob = await response.blob();
            const fileName = 'Route_Summary_<?php echo $assign_date; ?>.pdf';
            const file = new File([blob], fileName, { type: 'application/pdf' });

            if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({
                    title: 'Route Summary - <?php echo $assign_date; ?>',
                    text: 'Attached is the route summary report for your records.',
                    files: [file]
                });
            } else {
                // Fallback for browsers that don't support Web Share API with files (like desktop browsers)
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; 
                a.download = fileName;
                document.body.appendChild(a); 
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            }
        } catch (e) {
            console.error(e);
            alert('An error occurred while generating the PDF: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    }
    </script>
</body>
</html>
<?php
} catch (Throwable $e) {
    echo "<div class='container mt-5'><div class='alert alert-danger shadow-sm'>
            <h4 class='alert-heading'><i class='bi bi-exclamation-triangle-fill me-2'></i>System Error</h4>
            <hr>
            <p class='mb-0'>".htmlspecialchars($e->getMessage())."</p>
          </div></div>";
}
?>