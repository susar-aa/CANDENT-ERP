<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to Management

// --- DATE & REP FILTERS ---
$period = isset($_GET['period']) ? $_GET['period'] : 'this_month';
$rep_filter = isset($_GET['rep_id']) ? $_GET['rep_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Resolve predefined periods into concrete dates
if ($period !== 'custom') {
    if ($period == 'today') {
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
    } elseif ($period == 'this_week') {
        // Find Monday and Sunday of the current week
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d', strtotime('sunday this week'));
    } elseif ($period == 'this_month') {
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
    } elseif ($period == 'this_year') {
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
    }
}

// Ensure custom dates are respected
if (empty($date_from)) $date_from = date('Y-m-01');
if (empty($date_to)) $date_to = date('Y-m-t');

// Build SQL Components
$whereClauseOrders = "DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?";
$params = [$date_from, $date_to];

if ($rep_filter !== '') {
    $whereClauseOrders .= " AND o.rep_id = ?";
    $params[] = $rep_filter;
}

// --- 1. FETCH KPIs ---

// Total Bill Discounts
$stmt = $pdo->prepare("SELECT COALESCE(SUM(discount_amount), 0) FROM orders o WHERE $whereClauseOrders");
$stmt->execute($params);
$total_bill_discount = (float)$stmt->fetchColumn();

// Total Line-Item Discounts (Excluding FOC)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(oi.discount), 0) 
    FROM order_items oi 
    JOIN orders o ON oi.order_id = o.id 
    WHERE oi.is_foc = 0 AND $whereClauseOrders
");
$stmt->execute($params);
$total_item_discount = (float)$stmt->fetchColumn();

$total_discounts_given = $total_bill_discount + $total_item_discount;

// Total FOC Items Issued & Values
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(oi.quantity), 0) as foc_qty,
        COALESCE(SUM(oi.quantity * p.selling_price), 0) as foc_cost_value
    FROM order_items oi 
    JOIN orders o ON oi.order_id = o.id 
    JOIN products p ON oi.product_id = p.id
    WHERE oi.is_foc = 1 AND $whereClauseOrders
");
$stmt->execute($params);
$foc_kpis = $stmt->fetch();

// --- 2. FETCH CHART DATA (GROUP BY DATE) ---
$chartQuery = "
    SELECT DATE(o.created_at) as stat_date, 
           SUM(o.discount_amount) as bill_dis,
           SUM(CASE WHEN oi.is_foc = 0 THEN oi.discount ELSE 0 END) as item_dis,
           SUM(CASE WHEN oi.is_foc = 1 THEN (oi.quantity * p.selling_price) ELSE 0 END) as foc_val
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE $whereClauseOrders
    GROUP BY DATE(o.created_at)
    ORDER BY DATE(o.created_at) ASC
";
$stmt = $pdo->prepare($chartQuery);
$stmt->execute($params);
$chartRows = $stmt->fetchAll();

$chartLabels = [];
$chartDiscounts = [];
$chartFoc = [];

foreach($chartRows as $row) {
    $chartLabels[] = date('M d', strtotime($row['stat_date']));
    $chartDiscounts[] = (float)($row['bill_dis'] + $row['item_dis']);
    $chartFoc[] = (float)$row['foc_val'];
}

// --- 3. FETCH DETAILED FOC ISSUANCE LOG ---
$focLogQuery = "
    SELECT 
        o.id as order_id, o.created_at, 
        u.name as rep_name, 
        p.name as product_name, p.sku, 
        oi.quantity, 
        (oi.quantity * p.selling_price) as cost_value,
        c.name as customer_name, 
        oi.promo_id, prom.name as promo_name
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN users u ON o.rep_id = u.id
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN promotions prom ON oi.promo_id = prom.id
    WHERE oi.is_foc = 1 AND $whereClauseOrders
    ORDER BY o.created_at DESC
";
$stmt = $pdo->prepare($focLogQuery);
$stmt->execute($params);
$foc_logs = $stmt->fetchAll();

// Fetch Reps for the Filter
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

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

    /* iOS Inputs & Labels */
    .ios-input, .form-select {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.9rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }
    .ios-label-sm {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ios-label-2);
        margin-bottom: 6px;
        padding-left: 4px;
    }

    /* Custom Tables */
    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 14px 20px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 20px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }

    /* iOS Badges */
    .ios-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        letter-spacing: 0.02em;
    }
    .ios-badge.green   { background: rgba(52,199,89,0.12); color: #1A9A3A; }
    .ios-badge.blue    { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.orange  { background: rgba(255,149,0,0.15); color: #C07000; }
    .ios-badge.gray    { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }
    .ios-badge.purple  { background: rgba(175,82,222,0.12); color: #AF52DE; }

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
        <h1 class="page-title">Promotions & FOC Audit</h1>
        <div class="page-subtitle">Track discount values and Free of Charge (FOC) item distributions.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Audit
        </button>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3 border-bottom">
    <h2 style="font-weight: 800; margin: 0;">Promotions & FOC Audit Report</h2>
    <p style="color: #666; margin: 5px 0 0; font-size: 1.1rem;">Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
</div>

<!-- Dynamic Filters -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="ios-label-sm">Sales Rep</label>
                <select name="rep_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Representatives</option>
                    <?php foreach($reps as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo $rep_filter == $r['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Time Period</label>
                <select name="period" id="periodSelect" class="form-select" onchange="toggleCustomDates();">
                    <option value="today" <?php echo $period == 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="this_week" <?php echo $period == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="this_month" <?php echo $period == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="this_year" <?php echo $period == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            
            <div class="col-md-2 custom-date-block" style="<?php echo $period != 'custom' ? 'display:none;' : ''; ?>">
                <label class="ios-label-sm">From</label>
                <input type="date" name="date_from" id="dateFrom" class="ios-input" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2 custom-date-block" style="<?php echo $period != 'custom' ? 'display:none;' : ''; ?>">
                <label class="ios-label-sm">To</label>
                <input type="date" name="date_to" id="dateTo" class="ios-input" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            
            <div class="col-md-2 custom-date-block" style="<?php echo $period != 'custom' ? 'display:none;' : ''; ?>">
                <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 42px;"><i class="bi bi-funnel-fill"></i> Apply</button>
            </div>
        </form>
    </div>
</div>

<!-- Primary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-sm-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-tags-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Flat Discounts</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 4px;">Rs <?php echo number_format($total_discounts_given, 2); ?></div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.8);">Given away as direct cash discounts</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 col-sm-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-box-seam-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">FOC Items Issued</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 4px;"><?php echo number_format($foc_kpis['foc_qty']); ?> <span style="font-size: 1rem; font-weight: 600; opacity: 0.8;">Units</span></div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.8);">Total physical goods given free</div>
            </div>
        </div>
    </div>

    <div class="col-md-4 col-sm-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-graph-down metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">FOC Value to Company</div>
                <div style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 4px;">Rs <?php echo number_format($foc_kpis['foc_cost_value'], 2); ?></div>
                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.8);">Calculated via product selling prices</div>
            </div>
        </div>
    </div>
</div>

<!-- Trend Chart -->
<div class="dash-card mb-4 no-print">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(175,82,222,0.1); color: #AF52DE;">
                <i class="bi bi-bar-chart-fill"></i>
            </span>
            Daily Discounts vs FOC Value
        </span>
    </div>
    <div class="p-4" style="background: var(--ios-surface); position: relative; height: 350px;">
        <canvas id="promoChart"></canvas>
    </div>
</div>

<!-- Detailed FOC Issuance Log -->
<div class="dash-card mb-5 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(52,199,89,0.1); color: #1A9A3A;">
                <i class="bi bi-list-columns-reverse"></i>
            </span>
            Comprehensive FOC Issuance Log
        </span>
        <span class="ios-badge gray outline fw-normal">Showing <?php echo count($foc_logs); ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 15%;" class="ps-4">Date & Time</th>
                    <th style="width: 25%;">Customer & Rep</th>
                    <th style="width: 25%;">Free Product (FOC)</th>
                    <th class="text-center" style="width: 10%;">Qty</th>
                    <th class="text-end" style="width: 10%;">FOC Value</th>
                    <th style="width: 15%;" class="pe-4">Trigger Source</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($foc_logs as $log): ?>
                <tr>
                    <td class="ps-4">
                        <div style="font-weight: 800; font-size: 0.95rem;">
                            <a href="view_invoice.php?id=<?php echo $log['order_id']; ?>" target="_blank" style="color: #0055CC; text-decoration: none;">Order #<?php echo str_pad($log['order_id'], 6, '0', STR_PAD_LEFT); ?></a>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;"><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label); text-truncate" style="max-width: 200px;">
                            <i class="bi bi-shop text-muted me-1"></i> <?php echo htmlspecialchars($log['customer_name'] ?: 'Walk-in'); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;">
                            <i class="bi bi-person-badge me-1"></i> <?php echo htmlspecialchars($log['rep_name'] ?: 'System'); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($log['product_name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            SKU: <?php echo htmlspecialchars($log['sku'] ?: 'N/A'); ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="ios-badge green fs-6 px-3"><?php echo $log['quantity']; ?></span>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 800; font-size: 0.95rem; color: #CC2200;">
                            Rs <?php echo number_format($log['cost_value'], 2); ?>
                        </div>
                    </td>
                    <td class="pe-4">
                        <?php if(!empty($log['promo_id'])): ?>
                            <span class="ios-badge purple outline"><i class="bi bi-magic"></i> Auto Promo</span>
                            <div style="font-size: 0.75rem; font-weight: 600; color: var(--ios-label-2); margin-top: 4px; max-width: 150px;" class="text-truncate" title="<?php echo htmlspecialchars($log['promo_name']); ?>">
                                <?php echo htmlspecialchars($log['promo_name']); ?>
                            </div>
                        <?php else: ?>
                            <span class="ios-badge gray outline"><i class="bi bi-person-gear"></i> Manual FOC</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($foc_logs)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-check-circle" style="font-size: 3rem; color: var(--ios-green);"></i>
                            <h4 class="mt-3 fw-bold">No FOC items issued</h4>
                            <p class="text-muted">No free items were given during this period.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    function toggleCustomDates() {
        const period = document.getElementById('periodSelect').value;
        const customBlocks = document.querySelectorAll('.custom-date-block');
        
        if (period === 'custom') {
            customBlocks.forEach(b => b.style.display = 'block');
        } else {
            customBlocks.forEach(b => b.style.display = 'none');
            document.getElementById('filterForm').submit();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('promoChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Flat Discounts Given (Rs)',
                        data: <?php echo json_encode($chartDiscounts); ?>,
                        backgroundColor: '#0055CC', // iOS Blue
                        borderRadius: 6,
                        borderSkipped: false
                    },
                    {
                        label: 'FOC Value (Rs)',
                        data: <?php echo json_encode($chartFoc); ?>,
                        backgroundColor: '#34C759', // iOS Green
                        borderRadius: 6,
                        borderSkipped: false
                    }
                ]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { 
                            usePointStyle: true, 
                            pointStyle: 'circle',
                            color: 'rgba(60,60,67,0.8)',
                            font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 12, weight: '500' } 
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(28, 28, 30, 0.9)',
                        padding: 12,
                        titleFont: { size: 12, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: 'normal' },
                        bodyFont: { size: 14, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: 'bold' },
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': Rs ' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
                }
            }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>