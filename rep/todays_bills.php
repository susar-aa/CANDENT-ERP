<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];
$message = '';

// Check if filtering by isolated route assignment
$assignment_id = isset($_GET['assignment_id']) && $_GET['assignment_id'] !== '' ? (int)$_GET['assignment_id'] : null;

// --- HANDLE DELETE ACTION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_order') {
    $order_id = (int)$_POST['order_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Verify this order belongs to this rep and is from today
        $verifyStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND rep_id = ? AND DATE(created_at) = CURDATE()");
        $verifyStmt->execute([$order_id, $rep_id]);
        if (!$verifyStmt->fetch()) {
            throw new Exception("Unauthorized to delete this order or it is not from today.");
        }

        // Fetch items to reverse stock
        $itemsStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$order_id]);
        $items = $itemsStmt->fetchAll();
        
        $revertStockStmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'returned', ?, ?, (SELECT stock + ? FROM products WHERE id = ?), (SELECT stock FROM products WHERE id = ?), ?)");
        
        foreach($items as $item) {
            $revertStockStmt->execute([$item['quantity'], $item['product_id']]);
            $logStmt->execute([$item['product_id'], $order_id, $item['quantity'], $item['quantity'], $item['product_id'], $item['product_id'], $rep_id]);
        }
        
        // Remove associated cheques and delete the order
        $pdo->prepare("DELETE FROM cheques WHERE order_id = ?")->execute([$order_id]);
        $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$order_id]);
        
        $pdo->commit();
        $message = "<div class='clean-alert success-alert mb-4'><i class='bi bi-check-circle-fill'></i><div><h6 class='m-0 fw-bold'>Order Deleted</h6><p class='m-0 small'>Order #".str_pad($order_id, 6, '0', STR_PAD_LEFT)." removed and stock safely restored.</p></div></div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='clean-alert error-alert mb-4'><i class='bi bi-x-circle-fill'></i><div><h6 class='m-0 fw-bold'>Error</h6><p class='m-0 small'>" . htmlspecialchars($e->getMessage()) . "</p></div></div>";
    }
}

// --- FETCH ORDERS ISOLATED BY ROUTE ASSIGNMENT ---
$routeName = "Today's Bills";
if ($assignment_id) {
    // Fetch specifically for this dispatch assignment
    $routeStmt = $pdo->prepare("SELECT r.name FROM rep_routes rr JOIN routes r ON rr.route_id = r.id WHERE rr.id = ?");
    $routeStmt->execute([$assignment_id]);
    $fetchedName = $routeStmt->fetchColumn();
    if ($fetchedName) $routeName = $fetchedName . " Bills";
    
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.assignment_id = ? 
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$assignment_id]);
} else {
    // Fallback: Fetch all today's orders globally
    $stmt = $pdo->prepare("
        SELECT o.*, c.name as customer_name 
        FROM orders o 
        LEFT JOIN customers c ON o.customer_id = c.id 
        WHERE o.rep_id = ? AND DATE(o.created_at) = CURDATE() 
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$rep_id]);
}

$orders = $stmt->fetchAll();

// Calculate total sales
$totalSales = 0;
foreach($orders as $o) {
    $totalSales += $o['total_amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($routeName); ?> - Rep App</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Google Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            /* Clean UI Color Palette */
            --bg-color: #F8FAFC;         
            --surface: #FFFFFF;          
            --text-main: #0F172A;        
            --text-muted: #64748B;       
            --border: #E2E8F0;           
            
            --primary: #2563EB;          
            --primary-bg: #EFF6FF;
            --success: #10B981;          
            --success-bg: #ECFDF5;
            --danger: #EF4444;           
            --danger-bg: #FEF2F2;
            --warning: #F59E0B;          
            --warning-bg: #FFFBEB;
            --info: #0EA5E9;
            --info-bg: #E0F2FE;
            
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            
            --nav-h: 70px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: calc(var(--nav-h) + 20px);
            -webkit-font-smoothing: antialiased;
            margin: 0;
        }

        /* ── Modern Header ── */
        .app-header {
            background: var(--surface);
            padding: 20px 20px 16px;
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .header-stack { display: flex; align-items: center; gap: 12px; }
        .back-btn {
            color: var(--text-main); font-size: 20px;
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: var(--bg-color); transition: background 0.2s;
            text-decoration: none;
        }
        .back-btn:active { background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: -0.01em; }
        .header-sub { font-size: 12px; color: var(--text-muted); font-weight: 500; display: block; }

        /* ── Content Area ── */
        .page-content { padding: 20px 16px; }

        .results-meta {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding: 0 4px;
        }
        .meta-label {
            font-size: 11px; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em;
        }
        .meta-value {
            font-size: 20px; font-weight: 700; color: var(--success);
            font-family: 'JetBrains Mono', monospace;
        }

        /* ── Order Card ── */
        .order-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }
        .order-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 12px;
        }
        
        .order-id { font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 700; color: var(--primary); margin: 0 0 4px 0; }
        .order-customer { font-size: 13px; color: var(--text-main); font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .order-customer i { color: var(--text-muted); }
        
        .order-amounts { text-align: right; }
        .order-total { font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 2px; }
        .order-time { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--text-muted); }

        .order-status-row {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px;
        }
        .badge-custom {
            display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.02em;
        }
        .badge-custom.gray { background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border); }
        .badge-custom.success { background: var(--success-bg); color: var(--success); }
        .badge-custom.info { background: var(--info-bg); color: var(--info); }
        .badge-custom.warning { background: var(--warning-bg); color: var(--warning); }

        /* ── Action Grid ── */
        .action-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .btn-act {
            text-decoration: none; border: none; padding: 10px 0; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;
            transition: transform 0.1s, background 0.1s; cursor: pointer; width: 100%;
        }
        .btn-act:active { transform: scale(0.96); }
        
        .btn-act.info-outline { background: var(--surface); color: var(--info); border: 1px solid var(--info-bg); }
        .btn-act.info-outline:active { background: var(--info-bg); }
        
        .btn-act.primary-outline { background: var(--surface); color: var(--primary); border: 1px solid var(--primary-bg); }
        .btn-act.primary-outline:active { background: var(--primary-bg); }
        
        .btn-act.danger-outline { background: var(--surface); color: var(--danger); border: 1px solid var(--danger-bg); }
        .btn-act.danger-outline:active { background: var(--danger-bg); }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border);
        }
        .clean-alert.info-alert { background: var(--primary-bg); border-color: #BFDBFE; color: #1E3A8A; }
        .clean-alert.success-alert { background: var(--success-bg); border-color: #A7F3D0; color: var(--success); }
        .clean-alert.error-alert { background: var(--danger-bg); border-color: #FECACA; color: var(--danger); }
        .clean-alert i { font-size: 22px; }

        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 14px;
            font-size: 15px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: inline-block;
            background: var(--primary); color: #fff; margin-top: 12px;
        }
        .btn-full:active { transform: scale(0.98); }

        /* ── Bottom Nav (Glassmorphism) ── */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid rgba(226, 232, 240, 0.8);
            display: flex; justify-content: space-around; align-items: center;
            height: var(--nav-h); z-index: 1000; padding-bottom: env(safe-area-inset-bottom, 0);
        }
        .nav-tab {
            flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
            text-decoration: none; color: var(--text-muted); font-size: 11px; font-weight: 500;
            padding: 8px 0; transition: color 0.2s;
        }
        .nav-tab i { font-size: 22px; }
        .nav-tab.active { color: var(--primary); }
        .nav-fab-wrapper { position: relative; top: -16px; flex: 1; display: flex; flex-direction: column; align-items: center; text-decoration: none;}
        .nav-fab {
            width: 52px; height: 52px; border-radius: 50%;
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; box-shadow: 0 8px 16px rgba(37, 99, 235, 0.25);
            transition: transform 0.1s;
        }
        .nav-fab:active { transform: scale(0.95); }
        .nav-fab-label { font-size: 11px; font-weight: 600; color: var(--text-main); margin-top: 6px; }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="app-header">
        <div class="header-stack">
            <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <div>
                <h1 class="header-title"><?php echo htmlspecialchars($routeName); ?></h1>
                <span class="header-sub"><?php echo date('M d, Y'); ?></span>
            </div>
        </div>
    </header>

    <div class="page-content">
        
        <?php echo $message; ?>

        <div class="results-meta">
            <span class="meta-label">Generated Invoices (<?php echo count($orders); ?>)</span>
            <span class="meta-value">Rs <?php echo number_format($totalSales, 2); ?></span>
        </div>

        <?php if (empty($orders)): ?>
            <div class="clean-alert info-alert mt-4">
                <i class="bi bi-receipt"></i>
                <div>
                    <h6 class="m-0 fw-bold">No Bills Today</h6>
                    <p class="m-0 small mt-1">You haven't generated any invoices today.</p>
                    <a href="create_order.php" class="btn-full mt-3">Create Invoice</a>
                </div>
            </div>
        <?php else: ?>
            
            <?php foreach ($orders as $o): ?>
            <div class="order-card">
                <div class="order-header">
                    <div>
                        <h3 class="order-id">#<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?></h3>
                        <div class="order-customer">
                            <i class="bi bi-shop"></i> 
                            <?php echo htmlspecialchars($o['customer_name'] ?: 'Walk-in Customer'); ?>
                        </div>
                    </div>
                    <div class="order-amounts">
                        <div class="order-total">Rs <?php echo number_format($o['total_amount'], 2); ?></div>
                        <div class="order-time"><?php echo date('h:i A', strtotime($o['created_at'])); ?></div>
                    </div>
                </div>

                <div class="order-status-row">
                    <span class="badge-custom gray"><?php echo htmlspecialchars($o['payment_method']); ?></span>
                    
                    <?php if($o['payment_status'] == 'paid'): ?>
                        <span class="badge-custom success"><i class="bi bi-check-circle-fill"></i> Paid</span>
                    <?php elseif($o['payment_status'] == 'waiting'): ?>
                        <span class="badge-custom info"><i class="bi bi-clock-fill"></i> Waiting (Chq)</span>
                    <?php else: ?>
                        <span class="badge-custom warning"><i class="bi bi-hourglass-split"></i> Pending</span>
                    <?php endif; ?>
                </div>

                <div class="action-grid">
                    <a href="../pages/view_invoice.php?id=<?php echo $o['id']; ?>" class="btn-act info-outline">
                        <i class="bi bi-eye"></i> View
                    </a>
                    
                    <a href="create_order.php?edit_id=<?php echo $o['id']; ?>&customer_id=<?php echo $o['customer_id'] ?: '0'; ?>" class="btn-act primary-outline">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to completely delete Invoice #<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?>? The items will be returned to your vehicle stock.');" style="margin: 0;">
                        <input type="hidden" name="action" value="delete_order">
                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                        <button type="submit" class="btn-act danger-outline">
                            <i class="bi bi-trash3"></i> Del
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <!-- Bottom Nav -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-tab">
            <i class="bi bi-house-door-fill"></i> Home
        </a>
        <a href="catalog.php" class="nav-tab">
            <i class="bi bi-grid"></i> Catalog
        </a>
        <div class="nav-fab-wrapper">
            <a href="create_order.php" class="nav-fab">
                <i class="bi bi-plus-lg"></i>
            </a>
            <span class="nav-fab-label">POS</span>
        </div>
        <a href="customers.php" class="nav-tab">
            <i class="bi bi-people-fill"></i> Customers
        </a>
        <a href="analytics.php" class="nav-tab">
            <i class="bi bi-bar-chart-line-fill"></i> Stats
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>