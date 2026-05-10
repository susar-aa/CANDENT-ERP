<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];

// --- 1. Fetch KPI Metrics ---
// Month-to-Date Sales
$mtdStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE rep_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$mtdStmt->execute([$rep_id]);
$mtd_sales = (float)$mtdStmt->fetchColumn();

// Month-to-Date Bills
$mtdBillsStmt = $pdo->prepare("SELECT COUNT(id) FROM orders WHERE rep_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$mtdBillsStmt->execute([$rep_id]);
$mtd_bills = (int)$mtdBillsStmt->fetchColumn();

// Total Outstanding for Rep's Customers
$outStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE rep_id = ? AND payment_status != 'paid'");
$outStmt->execute([$rep_id]);
$total_outstanding = (float)$outStmt->fetchColumn();

// --- 2. Fetch Rep Sales Target ---
$targetStmt = $pdo->prepare("SELECT target_amount FROM rep_targets WHERE rep_id = ? AND month = ?");
$targetStmt->execute([$rep_id, date('Y-m')]);
$monthly_target = (float)$targetStmt->fetchColumn();
$target_progress = ($monthly_target > 0) ? ($mtd_sales / $monthly_target) * 100 : 0;

// --- 3. Fetch Last 7 Days Sales for Chart ---
$chartLabels = [];
$salesDataRaw = [];

// Initialize array with last 7 days set to 0
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('M d', strtotime("-$i days"));
    $chartLabels[] = $label;
    $salesDataRaw[$date] = 0;
}

$chartStmt = $pdo->prepare("
    SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_total 
    FROM orders 
    WHERE rep_id = ? AND created_at >= DATE(NOW() - INTERVAL 7 DAY) 
    GROUP BY DATE(created_at)
");
$chartStmt->execute([$rep_id]);

foreach ($chartStmt->fetchAll() as $row) {
    if (isset($salesDataRaw[$row['sale_date']])) {
        $salesDataRaw[$row['sale_date']] = (float)$row['daily_total'];
    }
}
$chartData = array_values($salesDataRaw);

// --- 4. Fetch Top 5 Products Sold by Rep ---
$topProductsStmt = $pdo->prepare("
    SELECT p.name, SUM(oi.quantity) as total_qty, SUM(oi.quantity * oi.price) as revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.rep_id = ? AND MONTH(o.created_at) = MONTH(CURDATE())
    GROUP BY p.id
    ORDER BY total_qty DESC
    LIMIT 5
");
$topProductsStmt->execute([$rep_id]);
$top_products = $topProductsStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Analytics - Rep App</title>
    
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
    
    <!-- Chart.js for Graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

        .section-title {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 12px 4px;
            display: flex; align-items: center; gap: 6px;
        }

        /* ── Analytics Cards ── */
        .analytics-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 20px; margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        /* Target Card Specifics */
        .target-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 12px;
        }
        .target-title { color: var(--info); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .target-percentage { font-family: 'JetBrains Mono', monospace; font-size: 24px; font-weight: 700; color: var(--text-main); line-height: 1; }
        
        .progress-track {
            height: 8px; background: var(--bg-color); border-radius: 10px;
            overflow: hidden; margin-bottom: 12px; border: 1px solid var(--border);
        }
        .progress-fill {
            height: 100%; border-radius: 10px; transition: width 1s ease-out; background: var(--info);
        }
        .target-meta {
            display: flex; justify-content: space-between;
            font-size: 12px; font-weight: 600; color: var(--text-muted);
        }

        /* Metric Grid Cards */
        .metric-highlight {
            border-radius: var(--radius-lg); padding: 20px; margin-bottom: 16px;
            border: 1px solid transparent;
        }
        .metric-highlight.primary {
            background: var(--primary-bg); border-color: #DBEAFE;
        }
        .metric-highlight.danger {
            background: var(--danger-bg); border-color: #FECACA;
        }
        .metric-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
            margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
        }
        .metric-highlight.primary .metric-label { color: var(--primary); }
        .metric-highlight.danger .metric-label { color: var(--danger); }
        
        .metric-value {
            font-family: 'JetBrains Mono', monospace; font-size: 28px; font-weight: 700;
            color: var(--text-main); line-height: 1.1; margin-bottom: 4px;
        }
        .metric-sub {
            font-size: 12px; color: var(--text-muted); font-weight: 500;
        }

        /* Top Products List */
        .top-products-list { padding: 0; margin: 0; list-style: none; }
        .top-product-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 0; border-bottom: 1px solid var(--border);
        }
        .top-product-item:last-child { border-bottom: none; padding-bottom: 0; }
        .top-product-item:first-child { padding-top: 0; }
        
        .tp-rank {
            font-family: 'JetBrains Mono', monospace; font-size: 18px; font-weight: 700;
            color: var(--border); width: 32px; text-align: left; flex-shrink: 0;
        }
        .tp-info { flex: 1; }
        .tp-name { font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 2px; }
        .tp-qty { font-size: 12px; color: var(--text-muted); font-weight: 500; }
        .tp-revenue {
            font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 700;
            color: var(--success); text-align: right;
        }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .clean-alert i { font-size: 20px; color: var(--text-muted); }
        .clean-alert p { margin: 0; font-size: 13px; color: var(--text-muted); font-weight: 500; }

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
                <h1 class="header-title">My Analytics</h1>
                <span class="header-sub"><?php echo date('F Y'); ?> Performance</span>
            </div>
        </div>
    </header>

    <div class="page-content">
        
        <!-- TARGET ACHIEVING CARD -->
        <?php if ($monthly_target > 0): ?>
        <div class="analytics-card" style="border-left: 4px solid var(--info);">
            <div class="target-header">
                <div class="target-title"><i class="bi bi-bullseye"></i> Monthly Target</div>
                <div class="target-percentage"><?php echo number_format(min(100, $target_progress), 1); ?>%</div>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width: <?php echo min(100, $target_progress); ?>%"></div>
            </div>
            <div class="target-meta">
                <span>Achieved: Rs <?php echo number_format($mtd_sales, 2); ?></span>
                <span>Goal: Rs <?php echo number_format($monthly_target, 2); ?></span>
            </div>
        </div>
        <?php else: ?>
        <div class="clean-alert">
            <i class="bi bi-info-circle"></i>
            <p>No sales target set for this month.</p>
        </div>
        <?php endif; ?>

        <!-- Summary KPIs -->
        <div class="metric-highlight primary">
            <div class="metric-label"><i class="bi bi-graph-up-arrow"></i> Month-to-Date Sales</div>
            <div class="metric-value">Rs <?php echo number_format($mtd_sales, 2); ?></div>
            <div class="metric-sub"><?php echo $mtd_bills; ?> Total Bills Generated</div>
        </div>
            
        <div class="metric-highlight danger">
            <div class="metric-label"><i class="bi bi-exclamation-circle"></i> Pending Collections</div>
            <div class="metric-value">Rs <?php echo number_format($total_outstanding, 2); ?></div>
            <div class="metric-sub">Total outstanding from your clients</div>
        </div>

        <!-- Sales Chart -->
        <h2 class="section-title mt-4"><i class="bi bi-bar-chart"></i> Last 7 Days Sales</h2>
        <div class="analytics-card pb-4">
            <!-- Strict height wrapper to fix infinite expanding issue -->
            <div style="position: relative; height: 250px; width: 100%;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <!-- Top Products -->
        <h2 class="section-title mt-4"><i class="bi bi-trophy"></i> Top Products (This Month)</h2>
        <div class="analytics-card">
            <ul class="top-products-list">
                <?php foreach($top_products as $index => $prod): ?>
                    <li class="top-product-item">
                        <div class="tp-rank">#<?php echo $index + 1; ?></div>
                        <div class="tp-info">
                            <h6 class="tp-name"><?php echo htmlspecialchars($prod['name']); ?></h6>
                            <div class="tp-qty"><?php echo $prod['total_qty']; ?> Units Sold</div>
                        </div>
                        <div class="tp-revenue">Rs <?php echo number_format($prod['revenue'], 0); ?></div>
                    </li>
                <?php endforeach; ?>
                
                <?php if(empty($top_products)): ?>
                    <li class="top-product-item text-center text-muted border-0 py-2 justify-content-center">
                        <small>No sales recorded this month.</small>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

    </div>

    <!-- Mobile Bottom Navigation -->
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
        <a href="analytics.php" class="nav-tab active">
            <i class="bi bi-bar-chart-line-fill"></i> Stats
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Set Default Font for Chart.js
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#64748B'; // text-muted

        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('salesChart').getContext('2d');
            
            // Clean UI Gradient (Primary Blue)
            const gradient = ctx.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(37, 99, 235, 0.7)'); // var(--primary)
            gradient.addColorStop(1, 'rgba(37, 99, 235, 0.05)');

            const labels = <?php echo json_encode($chartLabels); ?>;
            const data = <?php echo json_encode($chartData); ?>;

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Gross Sales (Rs)',
                        data: data,
                        backgroundColor: gradient,
                        borderColor: '#2563EB', // var(--primary)
                        borderWidth: 2,
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0F172A',
                            titleFont: { family: "'Inter', sans-serif", size: 13, weight: '600' },
                            bodyFont: { family: "'JetBrains Mono', monospace", size: 14, weight: '700' },
                            padding: 12,
                            cornerRadius: 10,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return 'Rs ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#E2E8F0', // var(--border)
                                drawBorder: false,
                            },
                            ticks: {
                                font: { family: "'JetBrains Mono', monospace", size: 11 },
                                callback: function(value) {
                                    if(value >= 1000) return (value/1000) + 'k';
                                    return value;
                                }
                            }
                        },
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: {
                                font: { family: "'Inter', sans-serif", size: 11, weight: '500' }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>