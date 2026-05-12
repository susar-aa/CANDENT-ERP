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

    // Reps can only see their own routes, but admins/supervisors can see all
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

    // 6. Fetch Credit Collections
    $credit_collected_cash = 0;
    $credit_collected_cheque = 0;
    try {
        $collectionStmt = $pdo->prepare("SELECT method, SUM(amount) as total FROM customer_payments WHERE assignment_id = ? GROUP BY method");
        $collectionStmt->execute([$assignment_id]);
        $collections = $collectionStmt->fetchAll();
        foreach($collections as $col) {
            if ($col['method'] == 'Cash') $credit_collected_cash = (float)$col['total'];
            if ($col['method'] == 'Cheque') $credit_collected_cheque = (float)$col['total'];
        }
    } catch (Exception $e) {
        // Handle gracefully if table not updated
    }

    // ============================================================================
    // PDF GENERATION BLOCK
    // ============================================================================
    if (isset($_GET['pdf']) && $_GET['pdf'] == 1) {
        if (!file_exists('../vendor/autoload.php')) {
            die("Error: Dompdf library not found. Please install it via composer.");
        }
        require_once '../vendor/autoload.php';
        
        $disp_date = date('d M Y', strtotime($assign_date));
        $f_cash = number_format($cash_sale, 2);
        $f_bank = number_format($bank_sale, 2);
        $f_cheque = number_format($cheque_sale, 2);
        $f_credit = number_format($credit_sale, 2);
        $f_total = number_format($total_sale, 2);
        $f_col_cash = number_format($credit_collected_cash, 2);
        $f_col_cheque = number_format($credit_collected_cheque, 2);

        $products_html = '';
        if (empty($products_sold)) {
            $products_html = '<tr><td colspan="2" style="text-align:center;">No products sold.</td></tr>';
        } else {
            $chunks = array_chunk($products_sold, ceil(count($products_sold) / 2));
            $products_html .= '<tr><td style="width:50%; vertical-align:top;"><table style="width:100%; border-collapse:collapse;">';
            if(!empty($chunks[0])) {
                foreach($chunks[0] as $p) {
                    $products_html .= '<tr><td style="padding:5px; border-bottom:1px solid #eee;">'.htmlspecialchars($p['name']).'</td><td style="text-align:right; font-weight:bold;">'.$p['total_qty'].'</td></tr>';
                }
            }
            $products_html .= '</table></td><td style="width:50%; vertical-align:top; padding-left:15px;"><table style="width:100%; border-collapse:collapse;">';
            if(!empty($chunks[1])) {
                foreach($chunks[1] as $p) {
                    $products_html .= '<tr><td style="padding:5px; border-bottom:1px solid #eee;">'.htmlspecialchars($p['name']).'</td><td style="text-align:right; font-weight:bold;">'.$p['total_qty'].'</td></tr>';
                }
            }
            $products_html .= '</table></td></tr>';
        }

        $pdfHtml = "
        <html>
        <head>
            <style>
                body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #333; }
                .header { text-align: center; font-size: 18px; font-weight: bold; margin-bottom: 20px; color: #2563EB; }
                .section { margin-bottom: 15px; border: 1px solid #ddd; border-radius: 8px; padding: 10px; }
                .title { font-weight: bold; background: #f8fafc; padding: 5px; margin: -10px -10px 10px -10px; border-bottom: 1px solid #ddd; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 5px 0; }
                .val { text-align: right; font-weight: bold; }
                .total { color: #2563EB; font-size: 14px; border-top: 1px dashed #ddd; }
            </style>
        </head>
        <body>
            <div class='header'>Route Summary Report</div>
            <div class='section'>
                <div class='title'>General Details</div>
                <table>
                    <tr><td>Date:</td><td class='val'>$disp_date</td></tr>
                    <tr><td>Rep:</td><td class='val'>".htmlspecialchars($routeInfo['rep_name'])."</td></tr>
                    <tr><td>Route:</td><td class='val'>".htmlspecialchars($routeInfo['route_name'])."</td></tr>
                </table>
            </div>
            <div class='section'>
                <div class='title'>Sales Breakdown</div>
                <table>
                    <tr><td>Cash Sales:</td><td class='val'>Rs $f_cash</td></tr>
                    <tr><td>Bank Transfers:</td><td class='val'>Rs $f_bank</td></tr>
                    <tr><td>Cheque Sales:</td><td class='val'>Rs $f_cheque</td></tr>
                    <tr><td>Credit Sales:</td><td class='val' style='color:#dc2626;'>Rs $f_credit</td></tr>
                    <tr class='total'><td>TOTAL SALES:</td><td class='val'>Rs $f_total</td></tr>
                </table>
            </div>
            <div class='section'>
                <div class='title'>Collections</div>
                <table>
                    <tr><td>Credit Collected (Cash):</td><td class='val'>Rs $f_col_cash</td></tr>
                    <tr><td>Credit Collected (Cheque):</td><td class='val'>Rs $f_col_cheque</td></tr>
                </table>
            </div>
            <div class='section'>
                <div class='title'>Products Sold</div>
                <table>$products_html</table>
            </div>
        </body>
        </html>";

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($pdfHtml);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Summary_'.$assign_date.'.pdf"');
        echo $dompdf->output();
        exit;
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Summary - CANDENT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f8fafc; font-family: sans-serif; padding: 20px; }
        .card { border-radius: 15px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .stat-label { color: #64748b; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; }
        .stat-value { font-weight: 800; font-size: 1.1rem; }
        .money { font-family: monospace; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 800px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0">Route Summary</h4>
            <a href="?assignment_id=<?php echo $assignment_id; ?>&pdf=1" class="btn btn-primary btn-sm rounded-pill px-3">
                <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
            </a>
        </div>

        <div class="card p-3">
            <div class="row g-3">
                <div class="col-6">
                    <div class="stat-label">Route</div>
                    <div class="stat-value"><?php echo htmlspecialchars($routeInfo['route_name']); ?></div>
                </div>
                <div class="col-6">
                    <div class="stat-label">Date</div>
                    <div class="stat-value"><?php echo date('d M Y', strtotime($assign_date)); ?></div>
                </div>
                <div class="col-12 border-top pt-2 mt-2">
                    <div class="stat-label">Rep Name</div>
                    <div class="stat-value"><?php echo htmlspecialchars($routeInfo['rep_name']); ?></div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card p-3 h-100">
                    <h6 class="fw-bold mb-3 border-bottom pb-2">Sales Breakdown</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Cash Sale</span>
                        <span class="money fw-bold">Rs <?php echo number_format($cash_sale, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Bank Trans</span>
                        <span class="money fw-bold">Rs <?php echo number_format($bank_sale, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Cheque Sale</span>
                        <span class="money fw-bold">Rs <?php echo number_format($cheque_sale, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Credit Sale</span>
                        <span class="money fw-bold text-danger">Rs <?php echo number_format($credit_sale, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2 mt-2">
                        <span class="fw-bold">TOTAL</span>
                        <span class="money fw-bold text-primary">Rs <?php echo number_format($total_sale, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-3 h-100">
                    <h6 class="fw-bold mb-3 border-bottom pb-2">Collections</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Credit Cash</span>
                        <span class="money fw-bold text-success">Rs <?php echo number_format($credit_collected_cash, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Credit Cheque</span>
                        <span class="money fw-bold text-success">Rs <?php echo number_format($credit_collected_cheque, 2); ?></span>
                    </div>
                    <h6 class="fw-bold mt-4 mb-3 border-bottom pb-2">Visit Metrics</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Productive</span>
                        <span class="fw-bold"><?php echo $productive_calls; ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Unproductive</span>
                        <span class="fw-bold"><?php echo $unproductive_calls; ?></span>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2 mt-2">
                        <span class="fw-bold">P/C Ratio</span>
                        <span class="fw-bold text-success"><?php echo $pc_ratio; ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-3 mt-3">
            <h6 class="fw-bold mb-3 border-bottom pb-2">Products Sold Today</h6>
            <?php if(empty($products_sold)): ?>
                <div class="text-center py-3 text-muted">No products sold.</div>
            <?php else: ?>
                <div class="row">
                    <?php foreach($products_sold as $p): ?>
                        <div class="col-6 mb-2">
                            <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                <span class="small fw-bold"><?php echo htmlspecialchars($p['name']); ?></span>
                                <span class="badge bg-primary rounded-pill"><?php echo $p['total_qty']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
} catch (Throwable $e) {
    echo "<div class='alert alert-danger m-3'>";
    echo "<h5>System Error</h5>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<small>Line " . $e->getLine() . " in " . $e->getFile() . "</small>";
    echo "</div>";
}
?>