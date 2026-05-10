<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// --- DATE FILTERING ---
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01'); // Default to 1st of current month
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t'); // Default to last day of current month

$params = [$date_from, $date_to];
$whereDate = "DATE(created_at) >= ? AND DATE(created_at) <= ?";

// --- 1. FETCH OVERALL KPIs ---
$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(id) as total_orders, 
        COALESCE(SUM(total_amount), 0) as gross_revenue 
    FROM orders 
    WHERE $whereDate
");
$kpiStmt->execute($params);
$kpiData = $kpiStmt->fetch();

$gross_revenue = (float)$kpiData['gross_revenue'];
$total_orders = (int)$kpiData['total_orders'];
$avg_order_value = $total_orders > 0 ? $gross_revenue / $total_orders : 0;

// Total outstanding credit for the period
$creditStmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount - paid_amount), 0) 
    FROM orders 
    WHERE payment_status != 'paid' AND $whereDate
");
$creditStmt->execute($params);
$period_credit = (float)$creditStmt->fetchColumn();

// --- 2. TREND CHART LOGIC (Daily vs Monthly) ---
$start = new DateTime($date_from);
$end = new DateTime($date_to);
$interval = $start->diff($end);
$days = $interval->days;

$trendLabels = [];
$trendDataRaw = [];

if ($days <= 60) {
    // Daily View
    for ($i = 0; $i <= $days; $i++) {
        $d = clone $start;
        $d->modify("+$i days");
        $ds = $d->format('Y-m-d');
        $trendLabels[] = $d->format('M d');
        $trendDataRaw[$ds] = 0;
    }
    
    $trendStmt = $pdo->prepare("
        SELECT DATE(created_at) as sale_date, SUM(total_amount) as revenue 
        FROM orders 
        WHERE $whereDate 
        GROUP BY DATE(created_at)
    ");
    $trendStmt->execute($params);
    foreach ($trendStmt->fetchAll() as $row) {
        if (isset($trendDataRaw[$row['sale_date']])) {
            $trendDataRaw[$row['sale_date']] = (float)$row['revenue'];
        }
    }
    $trendData = array_values($trendDataRaw);
} else {
    // Monthly View
    $trendStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as sale_date, SUM(total_amount) as revenue 
        FROM orders 
        WHERE $whereDate 
        GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
        ORDER BY sale_date ASC
    ");
    $trendStmt->execute($params);
    $trendDataRawArr = [];
    foreach ($trendStmt->fetchAll() as $row) {
        $trendLabels[] = date('M Y', strtotime($row['sale_date'] . '-01'));
        $trendDataRawArr[] = (float)$row['revenue'];
    }
    $trendData = $trendDataRawArr;
}

// --- 3. PAYMENT METHODS BREAKDOWN ---
$payStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(paid_cash), 0) as cash_total,
        COALESCE(SUM(paid_bank), 0) as bank_total,
        COALESCE(SUM(paid_cheque), 0) as cheque_total,
        COALESCE(SUM(total_amount - paid_amount), 0) as credit_total
    FROM orders 
    WHERE $whereDate
");
$payStmt->execute($params);
$payData = $payStmt->fetch();

$payChartLabels = ['Cash', 'Bank Transfer', 'Cheque', 'Unpaid/Credit'];
$payChartData = [
    (float)$payData['cash_total'], 
    (float)$payData['bank_total'], 
    (float)$payData['cheque_total'], 
    max(0, (float)$payData['credit_total'])
];

// --- 4. TOP PRODUCTS ---
$topProdStmt = $pdo->prepare("
    SELECT p.name, p.sku, SUM(oi.quantity) as total_qty, SUM(oi.quantity * oi.price) as revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
    GROUP BY p.id
    ORDER BY revenue DESC
    LIMIT 5
");
$topProdStmt->execute($params);
$top_products = $topProdStmt->fetchAll();

// --- 5. TOP CUSTOMERS ---
$topCustStmt = $pdo->prepare("
    SELECT COALESCE(c.name, 'Walk-in Customers') as name, SUM(o.total_amount) as revenue, COUNT(o.id) as order_count
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
    GROUP BY o.customer_id
    ORDER BY revenue DESC
    LIMIT 5
");
$topCustStmt->execute($params);
$top_customers = $topCustStmt->fetchAll();

// --- 6. TOP REPS ---
$topRepsStmt = $pdo->prepare("
    SELECT COALESCE(u.name, 'System/E-commerce') as name, SUM(o.total_amount) as revenue
    FROM orders o
    LEFT JOIN users u ON o.rep_id = u.id
    WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
    GROUP BY o.rep_id
    ORDER BY revenue DESC
    LIMIT 5
");
$topRepsStmt->execute($params);
$top_reps = $topRepsStmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* --- Specific Page Styles (Candent Theme) --- */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
    }
    .page-title { font-size: 1.8rem; font-weight: 700; letter-spacing: -0.8px; color: var(--ios-label); margin: 0; }
    .page-subtitle { font-size: 0.85rem; color: var(--ios-label-2); margin-top: 4px; }

    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    .metrics-bg-icon {
        position: absolute;
        right: -15px;
        bottom: -20px;
        font-size: 6rem;
        opacity: 0.15;
        z-index: 1;
    }
    .metrics-content {
        position: relative;
        z-index: 2;
    }

    /* Rank Badges */
    .rank-badge {
        width: 32px; height: 32px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 50%; font-weight: 800; font-size: 0.85rem; margin-right: 14px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1); flex-shrink: 0;
    }
    .rank-1 { background: linear-gradient(135deg, #FFD700, #F5A623); color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
    .rank-2 { background: linear-gradient(135deg, #E0E0E0, #9E9E9E); color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
    .rank-3 { background: linear-gradient(135deg, #E67E22, #A04000); color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
    .rank-other { background: var(--ios-surface-2); color: var(--ios-label-2); box-shadow: none; border: 1px solid var(--ios-separator); }
    
    .leaderboard-list { margin: 0; padding: 0; list-style: none; }
    .leaderboard-item {
        display: flex; justify-content: space-between; align-items: center;
        padding: 12px 20px; border-bottom: 1px solid var(--ios-separator);
        transition: background 0.2s;
    }
    .leaderboard-item:hover { background: var(--ios-bg); }
    .leaderboard-item:last-child { border-bottom: none; }

    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .dash-card { box-shadow: none !important; border: 1px solid #ddd; }
        .metrics-card { color: #000 !important; background: #f8f9fa !important; border: 1px solid #ddd; box-shadow: none !important; }
        .metrics-bg-icon { display: none; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Business Analytics</h1>
        <div class="page-subtitle">Track revenue trends, top performers, and payment breakdowns.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<div class="text-center mb-4 pb-2 border-bottom d-none d-print-block">
    <h2 style="font-weight: 800; margin: 0;">Sales & Analytics Report</h2>
    <p style="color: #666; margin: 5px 0 0; font-size: 1.1rem;">Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
</div>

<!-- Filters -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="ios-label-sm">Date From</label>
                <input type="date" name="date_from" class="ios-input fw-bold" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-4">
                <label class="ios-label-sm">Date To</label>
                <input type="date" name="date_to" class="ios-input fw-bold" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 42px;">
                    <i class="bi bi-arrow-repeat me-1"></i> Generate Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================= 1. PRIMARY KPIs ================= -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-cash-stack metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Gross Revenue</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($gross_revenue); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-receipt metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Orders</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo number_format($total_orders); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #30B0C7, #1A95AC);">
            <i class="bi bi-calculator metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Avg Order Value</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($avg_order_value); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-clock-history metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Period Credit / Ows</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($period_credit); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ================= 2. CHARTS ================= -->
<div class="row g-4 mb-4">
    <!-- Revenue Trend Chart -->
    <div class="col-lg-8">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-graph-up-arrow"></i>
                    </span>
                    Revenue Trend
                </span>
                <span class="ios-badge gray outline fw-normal"><?php echo $days <= 60 ? 'Daily' : 'Monthly'; ?> View</span>
            </div>
            <div class="p-4" style="position: relative; height: 350px; flex: 1;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Payment Methods Chart -->
    <div class="col-lg-4">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(52,199,89,0.1); color: #1A9A3A;">
                        <i class="bi bi-pie-chart-fill"></i>
                    </span>
                    Payment Breakdown
                </span>
            </div>
            <div class="p-4 d-flex justify-content-center align-items-center" style="position: relative; height: 350px; flex: 1;">
                <?php if(array_sum($payChartData) > 0): ?>
                    <canvas id="paymentChart"></canvas>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-pie-chart" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                        <p class="mt-2" style="font-weight: 500;">No payment data.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================= 3. LEADERBOARDS ================= -->
<div class="row g-4 mb-5">
    
    <!-- Top Products -->
    <div class="col-lg-4">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(255,149,0,0.1); color: #C07000;">
                        <i class="bi bi-box-seam-fill"></i>
                    </span>
                    Top 5 Products
                </span>
            </div>
            <ul class="leaderboard-list">
                <?php foreach($top_products as $idx => $tp): 
                    $rankClass = ($idx == 0) ? 'rank-1' : (($idx == 1) ? 'rank-2' : (($idx == 2) ? 'rank-3' : 'rank-other'));
                ?>
                <li class="leaderboard-item">
                    <div class="d-flex align-items-center overflow-hidden me-2">
                        <div class="rank-badge <?php echo $rankClass; ?>"><?php echo $idx + 1; ?></div>
                        <div class="text-truncate">
                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label); line-height: 1.2;" class="text-truncate"><?php echo htmlspecialchars($tp['name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;"><?php echo $tp['total_qty']; ?> Units Sold</div>
                        </div>
                    </div>
                    <div style="font-weight: 800; font-size: 0.95rem; color: #1A9A3A;" class="text-end flex-shrink-0">Rs <?php echo number_format($tp['revenue']); ?></div>
                </li>
                <?php endforeach; ?>
                <?php if(empty($top_products)): ?>
                    <li class="leaderboard-item justify-content-center text-muted py-5 border-0">No sales data found.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Top Customers -->
    <div class="col-lg-4">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(48,176,199,0.1); color: #30B0C7;">
                        <i class="bi bi-shop"></i>
                    </span>
                    Top 5 Customers
                </span>
            </div>
            <ul class="leaderboard-list">
                <?php foreach($top_customers as $idx => $tc): 
                    $rankClass = ($idx == 0) ? 'rank-1' : (($idx == 1) ? 'rank-2' : (($idx == 2) ? 'rank-3' : 'rank-other'));
                ?>
                <li class="leaderboard-item">
                    <div class="d-flex align-items-center overflow-hidden me-2">
                        <div class="rank-badge <?php echo $rankClass; ?>"><?php echo $idx + 1; ?></div>
                        <div class="text-truncate">
                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label); line-height: 1.2;" class="text-truncate"><?php echo htmlspecialchars($tc['name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;"><?php echo $tc['order_count']; ?> Orders</div>
                        </div>
                    </div>
                    <div style="font-weight: 800; font-size: 0.95rem; color: #1A9A3A;" class="text-end flex-shrink-0">Rs <?php echo number_format($tc['revenue']); ?></div>
                </li>
                <?php endforeach; ?>
                <?php if(empty($top_customers)): ?>
                    <li class="leaderboard-item justify-content-center text-muted py-5 border-0">No sales data found.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Top Sales Reps -->
    <div class="col-lg-4">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(88,86,214,0.1); color: #5856D6;">
                        <i class="bi bi-person-badge-fill"></i>
                    </span>
                    Top Sales Reps
                </span>
            </div>
            <ul class="leaderboard-list">
                <?php foreach($top_reps as $idx => $tr): 
                    $rankClass = ($idx == 0) ? 'rank-1' : (($idx == 1) ? 'rank-2' : (($idx == 2) ? 'rank-3' : 'rank-other'));
                ?>
                <li class="leaderboard-item">
                    <div class="d-flex align-items-center overflow-hidden me-2">
                        <div class="rank-badge <?php echo $rankClass; ?>"><?php echo $idx + 1; ?></div>
                        <div class="text-truncate">
                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label); line-height: 1.2;" class="text-truncate"><?php echo htmlspecialchars($tr['name']); ?></div>
                        </div>
                    </div>
                    <div style="font-weight: 800; font-size: 0.95rem; color: #1A9A3A;" class="text-end flex-shrink-0">Rs <?php echo number_format($tr['revenue']); ?></div>
                </li>
                <?php endforeach; ?>
                <?php if(empty($top_reps)): ?>
                    <li class="leaderboard-item justify-content-center text-muted py-5 border-0">No sales data found.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

</div>

<!-- Scripts for Chart.js -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. TREND CHART (Line) ---
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        const gradient = trendCtx.getContext('2d').createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, 'rgba(0, 85, 204, 0.4)'); // iOS Blue Gradient Top
        gradient.addColorStop(1, 'rgba(0, 85, 204, 0.0)'); // Transparent Bottom

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trendLabels); ?>,
                datasets: [{
                    label: 'Gross Revenue (Rs)',
                    data: <?php echo json_encode($trendData); ?>,
                    borderColor: '#0055CC',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#0055CC',
                    pointBorderWidth: 2,
                    pointRadius: <?php echo $days <= 30 ? 4 : 0; ?>,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4 // Smooth curves
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false, 
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(28, 28, 30, 0.9)',
                        padding: 12,
                        titleFont: { size: 13, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: 'normal' },
                        bodyFont: { size: 14, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: 'bold' },
                        callbacks: {
                            label: function(context) {
                                return ' Rs ' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { borderDash: [4, 4], color: 'rgba(60,60,67,0.08)' },
                        ticks: {
                            color: 'rgba(60,60,67,0.5)',
                            font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 11 },
                            callback: function(value) {
                                if(value >= 1000000) return (value/1000000) + 'M';
                                if(value >= 1000) return (value/1000) + 'k';
                                return value;
                            }
                        },
                        border: { display: false }
                    },
                    x: { 
                        grid: { display: false },
                        ticks: {
                            color: 'rgba(60,60,67,0.5)',
                            font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 11 }
                        },
                        border: { display: false }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
            }
        });
    }

    // --- 2. PAYMENT METHODS CHART (Doughnut) ---
    const payCtx = document.getElementById('paymentChart');
    if (payCtx) {
        new Chart(payCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($payChartLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($payChartData); ?>,
                    backgroundColor: [
                        '#34C759', // Cash (Success)
                        '#30B0C7', // Bank (Teal)
                        '#FF9500', // Cheque (Orange)
                        '#FF3B30'  // Credit (Danger)
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%', // Sleeker, thinner ring
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { 
                            padding: 20, 
                            usePointStyle: true, 
                            pointStyle: 'circle',
                            color: 'rgba(60,60,67,0.8)',
                            font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 12, weight: '500' } 
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(28, 28, 30, 0.9)',
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed !== null) {
                                    label += 'Rs ' + context.parsed.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

});
</script>

<?php include '../includes/footer.php'; ?>