<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';

// Redirect Reps to their own specific mobile dashboard
if (hasRole(['rep'])) {
    header("Location: ../rep/dashboard.php");
    exit;
}

// Only Admin and Supervisors from here on
requireRole(['admin', 'supervisor']);

// --- 1. FETCH FINANCIAL METRICS ---
$fin = $pdo->query("SELECT * FROM company_finances WHERE id = 1")->fetch() ?: ['cash_on_hand' => 0, 'bank_balance' => 0];
$outstanding_debtors = $pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE payment_status != 'paid'")->fetchColumn();

// --- 2. FETCH SALES METRICS ---
$today_sales = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$yesterday_sales = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY")->fetchColumn();
$mtd_sales = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetchColumn();
$online_orders_pending = $pdo->query("SELECT COUNT(*) FROM orders WHERE shipping_name IS NOT NULL AND order_status = 'pending'")->fetchColumn();

// --- 2.5 FETCH TODAY'S TOP SOLD PRODUCTS ---
$top_products = $pdo->query("
    SELECT p.name, p.sku, SUM(oi.quantity) as total_qty 
    FROM order_items oi 
    JOIN orders o ON oi.order_id = o.id 
    JOIN products p ON oi.product_id = p.id 
    WHERE DATE(o.created_at) = CURDATE() 
    GROUP BY p.id 
    ORDER BY total_qty DESC 
    LIMIT 6
")->fetchAll();

// --- 3. FETCH RECENT SALES ---
$recent_sales = $pdo->query("
    SELECT o.id, o.total_amount, o.payment_method, o.payment_status, c.name as customer_name, o.created_at, o.shipping_name
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    ORDER BY o.created_at DESC 
    LIMIT 6
")->fetchAll();

// --- 4. FETCH ACTIVE ROUTES (TODAY) ---
$active_routes = $pdo->query("
    SELECT rr.*, u.name as rep_name, r.name as route_name 
    FROM rep_routes rr 
    JOIN users u ON rr.rep_id = u.id 
    JOIN routes r ON rr.route_id = r.id 
    WHERE rr.assign_date = CURDATE() AND rr.status IN ('assigned', 'accepted')
")->fetchAll();

// --- 5. FETCH REP TARGETS (CURRENT MONTH) ---
$targets = $pdo->query("
    SELECT 
        t.target_amount, 
        u.name as rep_name, 
        (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE rep_id = t.rep_id AND DATE_FORMAT(created_at, '%Y-%m') = t.month) as achieved 
    FROM rep_targets t 
    JOIN users u ON t.rep_id = u.id 
    WHERE t.month = DATE_FORMAT(CURDATE(), '%Y-%m') 
    ORDER BY (achieved/target_amount) DESC LIMIT 5
")->fetchAll();

// --- 6. FETCH LOW STOCK ALERTS ---
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'available' AND stock <= 5")->fetchColumn();
$low_stock_items = $pdo->query("
    SELECT p.name, p.sku, p.stock, s.company_name as supplier 
    FROM products p 
    LEFT JOIN suppliers s ON p.supplier_id = s.id 
    WHERE p.status = 'available' AND p.stock <= 5 
    ORDER BY p.stock ASC LIMIT 6
")->fetchAll();

// --- 7. FETCH CHEQUE BANKING REMINDERS ---
$cheque_reminders = $pdo->query("
    SELECT bank_name, cheque_number, amount, banking_date 
    FROM cheques 
    WHERE status = 'pending' AND type = 'incoming' 
    AND banking_date <= DATE_ADD(CURDATE(), INTERVAL 5 DAY)
    ORDER BY banking_date ASC
    LIMIT 6
")->fetchAll();

// --- 8. FETCH CHART DATA (7-DAY SALES TREND) ---
$chartLabels = [];
$salesDataRaw = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M d', strtotime("-$i days"));
    $salesDataRaw[$date] = 0;
}
$stmt = $pdo->query("SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_total FROM orders WHERE created_at >= DATE(NOW() - INTERVAL 7 DAY) GROUP BY DATE(created_at)");
while($row = $stmt->fetch()) {
    if(isset($salesDataRaw[$row['sale_date']])) $salesDataRaw[$row['sale_date']] = (float)$row['daily_total'];
}
$chartData = array_values($salesDataRaw);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    @import url('https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap');

    :root {
        --ios-bg: #F2F2F7;
        --ios-surface: #FFFFFF;
        --ios-surface-2: #F2F2F7;
        --ios-surface-grouped: #FFFFFF;
        --ios-separator: rgba(60,60,67,0.12);
        --ios-separator-opaque: #C6C6C8;

        /* iOS System Colors */
        --ios-blue: #007AFF;
        --ios-green: #34C759;
        --ios-teal: #30B0C7;
        --ios-indigo: #5856D6;
        --ios-orange: #FF9500;
        --ios-red: #FF3B30;
        --ios-yellow: #FFCC00;
        --ios-mint: #00C7BE;

        /* iOS Label Colors */
        --ios-label: #000000;
        --ios-label-2: rgba(60,60,67,0.6);
        --ios-label-3: rgba(60,60,67,0.3);
        --ios-label-4: rgba(60,60,67,0.18);

        /* Brand accent */
        --accent: #30C88A;
        --accent-dark: #25A872;
        --accent-light: rgba(48,200,138,0.12);

        --radius-sm: 10px;
        --radius-md: 14px;
        --radius-lg: 20px;
        --radius-xl: 26px;

        --shadow-card: 0 2px 8px rgba(0,0,0,0.06), 0 0 1px rgba(0,0,0,0.04);
        --shadow-elevated: 0 8px 24px rgba(0,0,0,0.10), 0 2px 6px rgba(0,0,0,0.06);
    }

    * { box-sizing: border-box; }
    
    body {
        background: var(--ios-bg);
        font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Helvetica Neue', sans-serif;
        color: var(--ios-label);
        -webkit-font-smoothing: antialiased;
    }

    /* ── Page Header ── */
    .dash-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 32px 0 24px;
    }
    .dash-header-left .dash-eyebrow {
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--accent);
        margin-bottom: 4px;
    }
    .dash-header h1 {
        font-size: 1.9rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
        line-height: 1.1;
    }
    .date-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        color: var(--ios-label-2);
        font-weight: 500;
        font-size: 0.8rem;
        padding: 8px 14px;
        border-radius: 50px;
        margin-top: 6px;
        white-space: nowrap;
    }
    .date-pill i { color: var(--accent); }

    /* ── Quick Actions ── */
    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 28px;
    }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: 50px;
        font-size: 0.84rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.18s ease;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        letter-spacing: -0.1px;
    }
    .quick-btn:active { transform: scale(0.97); }
    .quick-btn-primary {
        background: var(--accent);
        color: #fff;
        box-shadow: 0 4px 14px rgba(48,200,138,0.35);
    }
    .quick-btn-primary:hover { background: var(--accent-dark); color: #fff; }
    .quick-btn-secondary {
        background: var(--ios-surface);
        color: var(--ios-label);
        border: 1px solid var(--ios-separator);
    }
    .quick-btn-secondary:hover { background: var(--ios-surface-2); color: var(--ios-label); }
    .quick-btn-ghost {
        background: var(--accent-light);
        color: var(--accent-dark);
    }
    .quick-btn-ghost:hover { background: rgba(48,200,138,0.2); color: var(--accent-dark); }

    /* ── Section Labels ── */
    .section-label {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--ios-label-2);
        margin: 28px 0 12px;
        padding-left: 4px;
    }

    /* ── KPI Cards ── */
    .kpi-card {
        background: var(--ios-surface);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        padding: 20px 18px 16px;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-elevated);
    }
    .kpi-card .kpi-label {
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        color: var(--ios-label-2);
        margin-bottom: 10px;
    }
    .kpi-card .kpi-value {
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: -0.5px;
        color: var(--ios-label);
        line-height: 1.1;
    }
    .kpi-card .kpi-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 14px;
    }
    /* Coloured accent cards */
    .kpi-card.kpi-accent {
        color: #fff;
    }
    .kpi-card.kpi-accent .kpi-label { color: rgba(255,255,255,0.75); }
    .kpi-card.kpi-accent .kpi-value { color: #fff; }
    .kpi-card.kpi-accent .kpi-icon { background: rgba(255,255,255,0.2); color: #fff; }
    .kpi-card.kpi-green  { background: linear-gradient(145deg, #30C88A, #25A872); }
    .kpi-card.kpi-teal   { background: linear-gradient(145deg, #30B0C7, #1A95AC); }
    .kpi-card.kpi-indigo { background: linear-gradient(145deg, #5856D6, #4543B0); }
    .kpi-card.kpi-orange { background: linear-gradient(145deg, #FF9500, #E07800); }

    .kpi-card:not(.kpi-accent) .kpi-icon.green  { background: rgba(48,200,138,0.12); color: #25A872; }
    .kpi-card:not(.kpi-accent) .kpi-icon.blue   { background: rgba(0,122,255,0.1);   color: #007AFF; }
    .kpi-card:not(.kpi-accent) .kpi-icon.red    { background: rgba(255,59,48,0.1);   color: #FF3B30; }
    .kpi-card:not(.kpi-accent) .kpi-icon.orange { background: rgba(255,149,0,0.1);   color: #FF9500; }

    /* ── Dashboard Cards (general) ── */
    .dash-card {
        background: var(--ios-surface);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        overflow: hidden;
        height: 100%;
    }
    .dash-card-header {
        padding: 16px 20px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--ios-separator);
    }
    .card-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--ios-label);
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        letter-spacing: -0.1px;
    }
    .card-title-icon {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }
    .card-link-btn {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--ios-blue);
        text-decoration: none;
        transition: opacity 0.15s;
        white-space: nowrap;
    }
    .card-link-btn:hover { opacity: 0.7; color: var(--ios-blue); }

    /* ── iOS Table ── */
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table tbody tr {
        border-bottom: 1px solid var(--ios-separator);
        transition: background 0.12s;
    }
    .ios-table tbody tr:last-child { border-bottom: none; }
    .ios-table tbody tr:hover { background: var(--ios-bg); }
    .ios-table td { padding: 13px 20px; vertical-align: middle; }

    /* ── iOS List ── */
    .ios-list { list-style: none; padding: 0; margin: 0; }
    .ios-list li {
        padding: 13px 20px;
        border-bottom: 1px solid var(--ios-separator);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.12s;
        gap: 12px;
    }
    .ios-list li:last-child { border-bottom: none; }
    .ios-list li:hover { background: var(--ios-bg); }
    .ios-list .item-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--ios-label);
        margin-bottom: 2px;
        letter-spacing: -0.1px;
    }
    .ios-list .item-sub {
        font-size: 0.76rem;
        color: var(--ios-label-2);
    }

    /* ── Badges ── */
    .ios-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        letter-spacing: 0.01em;
    }
    .ios-badge.green   { background: rgba(48,199,89,0.12);   color: #1A9A3A; }
    .ios-badge.teal    { background: rgba(48,176,199,0.12);   color: #1A8A9A; }
    .ios-badge.warm    { background: rgba(0,0,0,0.06);        color: var(--ios-label-2); }
    .ios-badge.warning { background: rgba(255,204,0,0.18);    color: #9A7800; }
    .ios-badge.danger  { background: rgba(255,59,48,0.1);     color: #CC2200; }
    .ios-badge.blue    { background: rgba(0,122,255,0.1);     color: #0055CC; }
    .ios-badge.blink   { animation: blinker 1.5s linear infinite; }

    /* ── Progress Bars ── */
    .ios-progress-wrap {
        margin-bottom: 16px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-progress-wrap:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .ios-progress-track {
        height: 7px;
        border-radius: 50px;
        background: var(--ios-bg);
        overflow: hidden;
        margin: 7px 0 5px;
    }
    .ios-progress-fill {
        height: 100%;
        border-radius: 50px;
        transition: width 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .fill-green  { background: linear-gradient(90deg, #30C759, #25B04E); }
    .fill-teal   { background: linear-gradient(90deg, #30B0C7, #1A95AC); }
    .fill-amber  { background: linear-gradient(90deg, #FFCC00, #FF9500); }
    .fill-red    { background: linear-gradient(90deg, #FF3B30, #CC1500); }

    /* ── Empty States ── */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--ios-label-3);
    }
    .empty-state i {
        font-size: 2.8rem;
        display: block;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    .empty-state p {
        font-size: 0.85rem;
        margin: 0;
        color: var(--ios-label-2);
    }

    /* ── Online Orders Widget ── */
    .online-orders-body {
        padding: 36px 20px;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .online-badge-big {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: linear-gradient(145deg, var(--accent), var(--accent-dark));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.9rem;
        color: #fff;
        box-shadow: 0 8px 24px rgba(48,200,138,0.3);
        position: relative;
        margin-bottom: 16px;
    }
    .online-badge-big .count-bubble {
        position: absolute;
        top: -4px;
        right: -4px;
        background: var(--ios-red);
        color: #fff;
        font-size: 0.68rem;
        font-weight: 700;
        border-radius: 50px;
        padding: 2px 7px;
        border: 2px solid #fff;
    }

    /* ── Card top accent strips ── */
    .accent-strip-teal   { border-top: 3px solid var(--ios-teal); }
    .accent-strip-red    { border-top: 3px solid var(--ios-red); }
    .accent-strip-green  { border-top: 3px solid var(--ios-green); }
    .accent-strip-indigo { border-top: 3px solid var(--ios-indigo); }

    /* ── Animate in ── */
    .kpi-card, .dash-card {
        animation: fadeUp 0.35s ease both;
    }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    @keyframes blinker { 50% { opacity: 0.3; } }
</style>

<!-- ── Page Header ── -->
<div class="dash-header">
    <div class="dash-header-left">
        <div class="dash-eyebrow">Overview</div>
        <h1>Command Center</h1>
    </div>
    <span class="date-pill">
        <i class="bi bi-calendar3"></i>
        <?php echo date('l, F j, Y'); ?>
    </span>
</div>

<!-- ── Quick Actions ── -->
<div class="quick-actions">
    <a href="create_order.php" class="quick-btn quick-btn-primary"><i class="bi bi-cart-plus"></i> New POS Order</a>
    <a href="create_grn.php" class="quick-btn quick-btn-ghost"><i class="bi bi-box-arrow-in-down"></i> Receive Stock</a>
    <a href="expenses.php" class="quick-btn quick-btn-secondary"><i class="bi bi-wallet2"></i> Add Expense</a>
    <a href="routes.php" class="quick-btn quick-btn-secondary"><i class="bi bi-truck"></i> Dispatch Route</a>
    <a href="customers.php" class="quick-btn quick-btn-secondary"><i class="bi bi-person-plus"></i> Add Customer</a>
</div>

<!-- ── KPI Cards: Revenue & Liquidity ── -->
<div class="section-label">Revenue &amp; Liquidity</div>
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-6">
        <div class="kpi-card" style="animation-delay:0.05s">
            <div class="kpi-icon green"><i class="bi bi-lightning-charge-fill"></i></div>
            <div class="kpi-label">Today's Sales</div>
            <div class="kpi-value">Rs <?php echo number_format($today_sales); ?></div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="kpi-card" style="animation-delay:0.08s">
            <div class="kpi-icon blue"><i class="bi bi-clock-history"></i></div>
            <div class="kpi-label">Yesterday</div>
            <div class="kpi-value">Rs <?php echo number_format($yesterday_sales); ?></div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="kpi-card kpi-accent kpi-green" style="animation-delay:0.11s">
            <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="kpi-label">Gross Sales (MTD)</div>
            <div class="kpi-value">Rs <?php echo number_format($mtd_sales); ?></div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="kpi-card" style="animation-delay:0.14s">
            <div class="kpi-icon orange"><i class="bi bi-cash-coin"></i></div>
            <div class="kpi-label">Cash on Hand</div>
            <div class="kpi-value">Rs <?php echo number_format($fin['cash_on_hand']); ?></div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="kpi-card kpi-accent kpi-teal" style="animation-delay:0.17s">
            <div class="kpi-icon"><i class="bi bi-bank"></i></div>
            <div class="kpi-label">Bank Balance</div>
            <div class="kpi-value">Rs <?php echo number_format($fin['bank_balance']); ?></div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="kpi-card kpi-accent kpi-indigo" style="animation-delay:0.20s">
            <div class="kpi-icon"><i class="bi bi-exclamation-octagon"></i></div>
            <div class="kpi-label">Owed from Debtors</div>
            <div class="kpi-value">Rs <?php echo number_format($outstanding_debtors); ?></div>
        </div>
    </div>
</div>

<!-- ── Charts & Recent Sales ── -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(0,122,255,0.1); color:var(--ios-blue);">
                        <i class="bi bi-bar-chart-fill"></i>
                    </span>
                    7-Day Revenue Trend
                </span>
                <a href="reports.php" class="card-link-btn">View Report</a>
            </div>
            <div style="position: relative; height: 300px; padding: 20px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(48,200,138,0.12); color:var(--accent-dark);">
                        <i class="bi bi-receipt-cutoff"></i>
                    </span>
                    Recent Sales
                </span>
                <a href="orders_list.php" class="card-link-btn">View All</a>
            </div>
            <table class="ios-table">
                <tbody>
                    <?php foreach($recent_sales as $sale): ?>
                    <tr>
                        <td>
                            <div style="font-weight:700; font-size:0.82rem; color:var(--ios-label-2);">#<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></div>
                            <div style="font-size:0.72rem; color:var(--ios-label-3);"><?php echo date('M d, h:i A', strtotime($sale['created_at'])); ?></div>
                        </td>
                        <td>
                            <div style="font-weight:600; font-size:0.84rem; color:var(--ios-label); max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?php echo htmlspecialchars($sale['shipping_name'] ?? $sale['customer_name'] ?? 'Walk-in'); ?>
                            </div>
                            <?php if($sale['shipping_name']): ?>
                                <span class="ios-badge teal" style="font-size:0.65rem; margin-top:2px;">Web Store</span>
                            <?php else: ?>
                                <span class="ios-badge warm" style="font-size:0.65rem; margin-top:2px;"><?php echo htmlspecialchars($sale['payment_method']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="font-weight:700; font-size:0.88rem; color:var(--accent-dark);">Rs <?php echo number_format($sale['total_amount'], 2); ?></div>
                            <?php if($sale['payment_status'] == 'paid'): ?>
                                <span style="font-size:0.72rem; color:var(--ios-green); font-weight:600;"><i class="bi bi-check-circle-fill"></i> Paid</span>
                            <?php else: ?>
                                <span style="font-size:0.72rem; color:var(--ios-orange); font-weight:600;"><i class="bi bi-hourglass-split"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($recent_sales)): ?>
                        <tr><td colspan="3"><div class="empty-state"><i class="bi bi-inbox"></i><p>No sales recorded recently.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Operations & Alerts ── -->
<div class="section-label">Operations &amp; Alerts</div>
<div class="row g-3 mb-4">

    <!-- Active Routes -->
    <div class="col-lg-4">
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(48,200,138,0.12); color:var(--accent-dark);">
                        <i class="bi bi-truck"></i>
                    </span>
                    Active Routes
                </span>
                <a href="routes.php" class="card-link-btn">Manage</a>
            </div>
            <ul class="ios-list">
                <?php foreach($active_routes as $route): ?>
                <li>
                    <div>
                        <div class="item-title"><?php echo htmlspecialchars($route['route_name']); ?></div>
                        <div class="item-sub"><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($route['rep_name']); ?></div>
                    </div>
                    <?php if($route['status'] == 'accepted'): ?>
                        <span class="ios-badge green"><i class="bi bi-play-circle-fill"></i> On Route</span>
                    <?php else: ?>
                        <span class="ios-badge warning"><i class="bi bi-hourglass-split"></i> Assigned</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
                <?php if(empty($active_routes)): ?>
                    <li style="justify-content:center; border:none;">
                        <div class="empty-state"><i class="bi bi-signpost-split"></i><p>No vehicles dispatched today.</p></div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Cheque Reminders -->
    <div class="col-lg-4">
        <div class="dash-card accent-strip-teal">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(48,176,199,0.12); color:var(--ios-teal);">
                        <i class="bi bi-bank2"></i>
                    </span>
                    Banking Reminders
                </span>
                <a href="cheques.php" class="card-link-btn">Ledger</a>
            </div>
            <ul class="ios-list">
                <?php foreach($cheque_reminders as $chk): 
                    $isToday = ($chk['banking_date'] == date('Y-m-d'));
                    $isPast  = ($chk['banking_date'] < date('Y-m-d'));
                    if ($isToday) {
                        $badgeClass = 'warning blink'; $dateText = 'Today!';
                    } elseif ($isPast) {
                        $badgeClass = 'danger'; $dateText = 'Overdue';
                    } else {
                        $badgeClass = 'teal'; $dateText = date('M d', strtotime($chk['banking_date']));
                    }
                ?>
                <li>
                    <div>
                        <div class="item-title"><?php echo htmlspecialchars($chk['bank_name']); ?></div>
                        <div class="item-sub">No: <?php echo htmlspecialchars($chk['cheque_number']); ?></div>
                        <div style="font-size:0.82rem; font-weight:700; color:var(--accent-dark); margin-top:2px;">Rs <?php echo number_format($chk['amount'], 2); ?></div>
                    </div>
                    <span class="ios-badge <?php echo $badgeClass; ?>"><i class="bi bi-calendar-event"></i> <?php echo $dateText; ?></span>
                </li>
                <?php endforeach; ?>
                <?php if(empty($cheque_reminders)): ?>
                    <li style="justify-content:center; border:none;">
                        <div class="empty-state"><i class="bi bi-check2-circle" style="color:var(--ios-green);"></i><p>No cheques due in 5 days.</p></div>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Low Stock -->
    <div class="col-lg-4">
        <div class="dash-card accent-strip-red">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(255,59,48,0.1); color:var(--ios-red);">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </span>
                    Low Stock Alerts
                </span>
                <?php if($low_stock_count > 0): ?>
                    <span class="ios-badge danger"><?php echo $low_stock_count; ?> Items</span>
                <?php endif; ?>
            </div>
            <ul class="ios-list">
                <?php foreach($low_stock_items as $item): ?>
                <li>
                    <div>
                        <div class="item-title"><?php echo htmlspecialchars($item['name']); ?></div>
                        <div class="item-sub">SKU: <?php echo htmlspecialchars($item['sku'] ?: 'N/A'); ?> · <?php echo htmlspecialchars($item['supplier'] ?: 'No supplier'); ?></div>
                    </div>
                    <span class="ios-badge <?php echo $item['stock'] == 0 ? 'danger' : 'warning'; ?>"><?php echo $item['stock']; ?> Left</span>
                </li>
                <?php endforeach; ?>
                <?php if(empty($low_stock_items)): ?>
                    <li style="justify-content:center; border:none;">
                        <div class="empty-state"><i class="bi bi-check-circle-fill" style="color:var(--ios-green);"></i><p>All inventory levels look good.</p></div>
                    </li>
                <?php else: ?>
                    <li style="justify-content:center;">
                        <a href="products.php" class="card-link-btn">View All Inventory <i class="bi bi-arrow-right"></i></a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<!-- ── Today's Top Products ── -->
<div class="section-label">Sales Performance (Today)</div>
<div class="row g-3 mb-4">
    <div class="col-lg-12">
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(48,200,138,0.12); color:var(--accent-dark);">
                        <i class="bi bi-award-fill"></i>
                    </span>
                    Today's Top Sold Products
                </span>
                <a href="reports.php" class="card-link-btn">Detailed Report</a>
            </div>
            <div class="row g-0">
                <?php if(empty($top_products)): ?>
                    <div class="col-12"><div class="empty-state"><i class="bi bi-bag-dash"></i><p>No sales recorded today yet.</p></div></div>
                <?php else: ?>
                    <?php foreach($top_products as $tp): ?>
                    <div class="col-md-4 border-bottom border-end">
                        <div class="p-3 d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold text-dark" style="font-size:0.85rem;"><?php echo htmlspecialchars($tp['name']); ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">SKU: <?php echo htmlspecialchars($tp['sku'] ?: 'N/A'); ?></div>
                            </div>
                            <span class="ios-badge green fw-bold" style="font-size:0.82rem;"><?php echo $tp['total_qty']; ?> Sold</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-5">

    <!-- Online Orders -->
    <div class="col-lg-6">
        <div class="dash-card accent-strip-green">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(48,199,89,0.1); color:var(--ios-green);">
                        <i class="bi bi-globe"></i>
                    </span>
                    Online Store Orders
                </span>
                <a href="online_orders.php" class="card-link-btn">Manage</a>
            </div>
            <div class="online-orders-body">
                <?php if($online_orders_pending > 0): ?>
                    <div class="online-badge-big">
                        <i class="bi bi-cart-check-fill"></i>
                        <span class="count-bubble"><?php echo $online_orders_pending; ?></span>
                    </div>
                    <h5 style="font-weight:700; color:var(--ios-label); font-size:1rem; margin:0 0 6px; letter-spacing:-0.3px;">Action Required</h5>
                    <p style="color:var(--ios-label-2); font-size:0.84rem; margin:0; max-width:280px;">
                        You have <?php echo $online_orders_pending; ?> online e-commerce order(s) waiting to be processed and dispatched.
                    </p>
                <?php else: ?>
                    <i class="bi bi-shop" style="font-size: 3.2rem; color: var(--ios-label-3); display:block; margin-bottom:12px;"></i>
                    <h6 style="font-weight:700; color:var(--ios-label); margin:0 0 6px; letter-spacing:-0.2px;">All Caught Up</h6>
                    <p style="color:var(--ios-label-2); font-size:0.84rem; margin:0;">No pending online orders right now.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Rep Targets -->
    <div class="col-lg-6">
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="card-title">
                    <span class="card-title-icon" style="background:rgba(88,86,214,0.1); color:var(--ios-indigo);">
                        <i class="bi bi-bullseye"></i>
                    </span>
                    Rep Targets (<?php echo date('M Y'); ?>)
                </span>
                <a href="rep_targets.php" class="card-link-btn">All Targets</a>
            </div>
            <div style="padding: 18px 20px;">
                <?php foreach($targets as $t): 
                    $progress = ($t['target_amount'] > 0) ? ($t['achieved'] / $t['target_amount']) * 100 : 0;
                    if ($progress >= 100) $fillClass = 'fill-green';
                    elseif ($progress >= 75) $fillClass = 'fill-teal';
                    elseif ($progress >= 50) $fillClass = 'fill-amber';
                    else $fillClass = 'fill-red';
                ?>
                <div class="ios-progress-wrap">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px;">
                        <span style="font-weight:600; font-size:0.86rem; color:var(--ios-label); letter-spacing:-0.1px;">
                            <i class="bi bi-person" style="color:var(--ios-label-2); margin-right:4px;"></i>
                            <?php echo htmlspecialchars($t['rep_name']); ?>
                        </span>
                        <span style="font-weight:700; font-size:0.82rem; color:<?php echo $progress >= 100 ? 'var(--ios-green)' : 'var(--ios-label-2)'; ?>;">
                            <?php echo number_format($progress, 1); ?>%
                        </span>
                    </div>
                    <div class="ios-progress-track">
                        <div class="ios-progress-fill <?php echo $fillClass; ?>" style="width: <?php echo min(100, $progress); ?>%;"></div>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span style="font-size:0.74rem; color:var(--ios-label-2);">Rs <?php echo number_format($t['achieved']); ?></span>
                        <span style="font-size:0.74rem; color:var(--ios-label-2);">Target: Rs <?php echo number_format($t['target_amount']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($targets)): ?>
                    <div class="empty-state"><i class="bi bi-bullseye"></i><p>No sales targets set for this month.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Initialization -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    
    const gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(48, 200, 138, 0.3)');
    gradient.addColorStop(1, 'rgba(48, 200, 138, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Gross Revenue (Rs)',
                data: <?php echo json_encode($chartData); ?>,
                borderColor: '#30C88A',
                backgroundColor: gradient,
                borderWidth: 2.5,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#30C88A',
                pointBorderWidth: 2.5,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { 
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1C1C1E',
                    padding: 12,
                    cornerRadius: 12,
                    titleColor: 'rgba(255,255,255,0.6)',
                    titleFont: { size: 11, family: '-apple-system, BlinkMacSystemFont, sans-serif' },
                    bodyFont: { size: 14, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: '700' },
                    bodyColor: '#FFFFFF',
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
                    grid: { color: 'rgba(60,60,67,0.08)', borderDash: [4, 4] },
                    ticks: {
                        callback: function(value) {
                            if(value >= 1000000) return (value/1000000).toFixed(1) + 'M';
                            if(value >= 1000) return (value/1000).toFixed(0) + 'k';
                            return value;
                        },
                        font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 11 },
                        color: 'rgba(60,60,67,0.4)'
                    },
                    border: { display: false }
                },
                x: { 
                    grid: { display: false },
                    ticks: { 
                        font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 11 },
                        color: 'rgba(60,60,67,0.4)'
                    },
                    border: { display: false }
                }
            },
            interaction: { intersect: false, mode: 'index' },
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>