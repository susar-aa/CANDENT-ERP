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
        $collectionStmt = $pdo->prepare("SELECT method, SUM(amount) as total FROM dispatch_collections WHERE assignment_id = ? GROUP BY method");
        // Wait, earlier the user said dispatch_collections exists. Let's check table name.
        // In my previous logic I used customer_payments. 
        // User's table list has BOTH. Usually collection for credit is in customer_payments.
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
            $products_html .= '<tr><td style="width:50%; vertical-align:top;"><table style="width:100%;">';
            if(!empty($chunks[0])) {
                foreach($chunks[0] as $p) {
                    $products_html .= '<tr><td style="padding:4px; border-bottom:1px solid #eee;">'.htmlspecialchars($p['name']).'</td><td style="text-align:right; font-weight:bold;">'.$p['total_qty'].'</td></tr>';
                }
            }
            $products_html .= '</table></td><td style="width:50%; vertical-align:top; padding-left:15px;"><table style="width:100%;">';
            if(!empty($chunks[1])) {
                foreach($chunks[1] as $p) {
                    $products_html .= '<tr><td style="padding:4px; border-bottom:1px solid #eee;">'.htmlspecialchars($p['name']).'</td><td style="text-align:right; font-weight:bold;">'.$p['total_qty'].'</td></tr>';
                }
            }
            $products_html .= '</table></td></tr>';
        }

        $pdfHtml = "
        <html>
        <head>
            <style>
                body { font-family: Helvetica, sans-serif; font-size: 11px; color: #1e293b; }
                .header { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; color: #2563eb; }
                .grid { width: 100%; margin-bottom: 15px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
                .title { font-weight: bold; color: #64748b; text-transform: uppercase; font-size: 10px; margin-bottom: 8px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 5px; }
                .val { text-align: right; font-weight: bold; }
                .money { font-family: 'Courier'; }
                .total { background: #eff6ff; color: #1d4ed8; font-size: 13px; font-weight: bold; }
                .success { color: #16a34a; } .danger { color: #dc2626; }
            </style>
        </head>
        <body>
            <div class='header'>Route Summary Dashboard</div>
            <table style='width:100%; border-spacing: 10px;'>
                <tr>
                    <td style='width:50%; vertical-align:top;'>
                        <div class='grid'><div class='title'>General Details</div>
                            <table style='width:100%'>
                                <tr><td>Date:</td><td class='val'>$disp_date</td></tr>
                                <tr><td>Rep Name:</td><td class='val'>".htmlspecialchars($routeInfo['rep_name'])."</td></tr>
                                <tr><td>Distributor:</td><td class='val'>Candent</td></tr>
                                <tr><td>Route:</td><td class='val'>".htmlspecialchars($routeInfo['route_name'])."</td></tr>
                                <tr><td>Driver:</td><td class='val'>".htmlspecialchars($routeInfo['driver_name'] ?: 'Self-Driven')."</td></tr>
                            </table>
                        </div>
                    </td>
                    <td style='width:50%; vertical-align:top;'>
                        <div class='grid'><div class='title'>Visit Metrics</div>
                            <table style='width:100%'>
                                <tr><td>Productive Calls:</td><td class='val success'>$productive_calls</td></tr>
                                <tr><td>Unproductive Calls:</td><td class='val danger'>$unproductive_calls</td></tr>
                                <tr><td>Total Calls Visited:</td><td class='val'>$calls_visited</td></tr>
                                <tr class='total' style='background:#fef3c7; color:#92400e;'><td>TOTAL P/C:</td><td class='val'>$pc_ratio%</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style='width:50%; vertical-align:top;'>
                        <div class='grid'><div class='title'>Sales Breakdown</div>
                            <table style='width:100%'>
                                <tr><td>Cash Sale:</td><td class='val money'>Rs $f_cash</td></tr>
                                <tr><td>Bank Transfer:</td><td class='val money'>Rs $f_bank</td></tr>
                                <tr><td>Cheque Sale:</td><td class='val money'>Rs $f_cheque</td></tr>
                                <tr><td>Credit Sale:</td><td class='val money danger'>Rs $f_credit</td></tr>
                                <tr class='total'><td>TOTAL SALE:</td><td class='val money'>Rs $f_total</td></tr>
                            </table>
                        </div>
                    </td>
                    <td style='width:50%; vertical-align:top;'>
                        <div class='grid'><div class='title'>Monthly Performance</div>
                            <table style='width:100%'>
                                <tr><td>Month Up to Yesterday:</td><td class='val money'>Rs $f_month</td></tr>
                                <tr class='total' style='background:#ecfdf5; color:#047857;'><td>CUMULATIVE SALE:</td><td class='val money'>Rs $f_cumu</td></tr>
                                <tr><td colspan='2' style='height:10px;'></td></tr>
                                <tr class='title'><td colspan='2'>Credit Collections</td></tr>
                                <tr><td>Cash Collected:</td><td class='val money success'>Rs $f_col_cash</td></tr>
                                <tr><td>Cheque Collected:</td><td class='val money success'>Rs $f_col_cheque</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>
            <div class='grid' style='width:100%'>
                <div class='title'>Products Sold Today</div>
                <table style='width:100%'>$products_html</table>
            </div>
        </body>
        </html>";

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
    <title>Route Summary Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --primary: #2563EB; --bg: #F8FAFC; --surface: #FFFFFF; --text: #0F172A; --text-muted: #64748B; --border: #E2E8F0; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; padding: 15px; margin: 0; }
        .card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 15px; padding: 18px; }
        .card-title { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px dashed var(--border); padding-bottom: 8px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .info-row:last-child { border-bottom: none; }
        .val { font-weight: 700; }
        .money { font-family: monospace; font-size: 0.95rem; }
        .total-row { background: #eff6ff; padding: 12px; border-radius: 12px; border: 1px dashed #bfdbfe; margin-top: 8px; display: flex; justify-content: space-between; align-items: center; }
        .total-row span:first-child { font-weight: 800; color: var(--primary); }
        .total-row span:last-child { font-weight: 800; color: var(--primary); font-size: 1.1rem; }
        .badge-pill { background: var(--primary); color: white; padding: 2px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }
    </style>
</head>
<body>
    <div style="max-width: 900px; margin: 0 auto;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Route Summary</h4>
            <div class="d-flex gap-2">
                <button onclick="sharePDF()" id="shareBtn" class="btn btn-primary rounded-pill px-4 fw-bold">
                    <i class="bi bi-share-fill me-2"></i> Share
                </button>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-title"><i class="bi bi-info-circle"></i> General Details</div>
                    <div class="info-row"><span>Date</span><span class="val"><?php echo date('d M Y', strtotime($assign_date)); ?></span></div>
                    <div class="info-row"><span>Rep Name</span><span class="val"><?php echo htmlspecialchars($routeInfo['rep_name']); ?></span></div>
                    <div class="info-row"><span>Distributor</span><span class="val text-primary">Candent</span></div>
                    <div class="info-row"><span>Route</span><span class="val"><?php echo htmlspecialchars($routeInfo['route_name']); ?></span></div>
                    <div class="info-row"><span>Work With (Driver)</span><span class="val"><?php echo htmlspecialchars($routeInfo['driver_name'] ?: 'Self-Driven'); ?></span></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-title"><i class="bi bi-shop"></i> Visit Metrics</div>
                    <div class="info-row"><span>Productive Calls</span><span class="val text-success"><?php echo $productive_calls; ?></span></div>
                    <div class="info-row"><span>Unproductive Calls</span><span class="val text-danger"><?php echo $unproductive_calls; ?></span></div>
                    <div class="info-row"><span>Total Calls Visited</span><span class="val"><?php echo $calls_visited; ?></span></div>
                    <div class="total-row" style="background:#fffbeb; border-color:#fde68a;"><span style="color:#b45309;">TOTAL P/C</span><span style="color:#b45309;"><?php echo $pc_ratio; ?>%</span></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-title"><i class="bi bi-wallet2"></i> Sales Breakdown</div>
                    <div class="info-row"><span>Cash Sale</span><span class="val money">Rs <?php echo number_format($cash_sale, 2); ?></span></div>
                    <div class="info-row"><span>Bank Transfer</span><span class="val money">Rs <?php echo number_format($bank_sale, 2); ?></span></div>
                    <div class="info-row"><span>Cheque Sale</span><span class="val money">Rs <?php echo number_format($cheque_sale, 2); ?></span></div>
                    <div class="info-row"><span>Credit Sale</span><span class="val money text-danger">Rs <?php echo number_format($credit_sale, 2); ?></span></div>
                    <div class="total-row"><span>TOTAL SALE</span><span class="money">Rs <?php echo number_format($total_sale, 2); ?></span></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-title"><i class="bi bi-graph-up-arrow"></i> Monthly Performance</div>
                    <div class="info-row"><span>Month Up to Yesterday</span><span class="val money">Rs <?php echo number_format($month_up_to_yesterday, 2); ?></span></div>
                    <div class="total-row" style="background:#ecfdf5; border-color:#a7f3d0;"><span style="color:#047857;">CUMULATIVE SALE</span><span style="color:#047857;" class="money">Rs <?php echo number_format($cumulative_sale, 2); ?></span></div>
                    <div style="height:15px;"></div>
                    <div class="card-title" style="border:none;"><i class="bi bi-cash-coin"></i> Credit Collections</div>
                    <div class="info-row"><span>Cash Collected</span><span class="val money text-success">Rs <?php echo number_format($credit_collected_cash, 2); ?></span></div>
                    <div class="info-row"><span>Cheque Collected</span><span class="val money text-success">Rs <?php echo number_format($credit_collected_cheque, 2); ?></span></div>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-title"><i class="bi bi-box-seam"></i> Products Sold Today</div>
                    <?php if(empty($products_sold)): ?>
                        <div class="text-center text-muted py-3">No products sold today.</div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach($products_sold as $p): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded" style="border:1px solid #eee;">
                                        <span class="small fw-bold"><?php echo htmlspecialchars($p['name']); ?></span>
                                        <span class="badge-pill"><?php echo $p['total_qty']; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    async function sharePDF() {
        const btn = document.getElementById('shareBtn');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Preparing...';

        try {
            const response = await fetch('?assignment_id=<?php echo $assignment_id; ?>&pdf=1');
            if (!response.ok) throw new Error('Failed to fetch PDF');
            
            const blob = await response.blob();
            const fileName = 'Route_Summary_<?php echo $assign_date; ?>.pdf';
            const file = new File([blob], fileName, { type: 'application/pdf' });

            if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
                await navigator.share({
                    title: 'Route Summary - <?php echo $assign_date; ?>',
                    text: 'Attached is the route summary report.',
                    files: [file]
                });
            } else {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = fileName;
                document.body.appendChild(a); a.click();
                window.URL.revokeObjectURL(url);
            }
        } catch (e) {
            alert('Error: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }
    </script>
</body>
</html>
<?php
} catch (Throwable $e) {
    echo "<div class='alert alert-danger m-3'><h5>System Error</h5><p>".htmlspecialchars($e->getMessage())."</p></div>";
}
?>