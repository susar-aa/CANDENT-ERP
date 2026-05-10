<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

// --- FILTERING ---
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$whereClause = "WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?";
$params = [$date_from, $date_to];

if ($search_query !== '') {
    // Updated to use COALESCE so it can also match the Unassigned option exactly
    $whereClause .= " AND COALESCE(r.name, 'Unassigned / Walk-in / E-Commerce') LIKE ?";
    $params[] = "%$search_query%";
}

// --- FETCH ALL ROUTES FOR DROPDOWN ---
$allRoutes = $pdo->query("SELECT name FROM routes ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// --- FETCH TOTALS FOR PAGINATION & GRAND ROW ---
$countQuery = "
    SELECT COUNT(DISTINCT COALESCE(r.id, 0))
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN routes r ON c.route_id = r.id
    $whereClause
";
$totalStmt = $pdo->prepare($countQuery);
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$grandQuery = "
    SELECT 
        COUNT(o.id) as grand_orders,
        SUM(o.subtotal) as grand_gross,
        SUM(o.discount_amount) as grand_discount,
        SUM(o.total_amount) as grand_net,
        SUM(o.paid_amount) as grand_collected,
        SUM(o.total_amount - o.paid_amount) as grand_outstanding
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN routes r ON c.route_id = r.id
    $whereClause
";
$grandStmt = $pdo->prepare($grandQuery);
$grandStmt->execute($params);
$grandTotals = $grandStmt->fetch();

// --- FETCH PAGINATED DATA ---
// We group by Route to determine Area performance. E-commerce/Walk-ins will fall under Unassigned.
$query = "
    SELECT 
        COALESCE(r.id, 0) as route_id, 
        COALESCE(r.name, 'Unassigned / Walk-in / E-Commerce') as area_name,
        COUNT(o.id) as total_orders,
        SUM(o.subtotal) as gross_revenue,
        SUM(o.discount_amount) as total_discounts,
        SUM(o.total_amount) as net_revenue,
        SUM(o.paid_amount) as total_collected,
        SUM(o.total_amount - o.paid_amount) as total_outstanding
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN routes r ON c.route_id = r.id
    $whereClause
    GROUP BY route_id, area_name
    ORDER BY net_revenue DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reportData = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Tom Select CSS for Searchable Dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

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
    .page-title {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
    }
    .page-subtitle {
        font-size: 0.85rem;
        color: var(--ios-label-2);
        margin-top: 4px;
    }

    /* iOS Inputs & Labels */
    .ios-input, .form-select {
        background: var(--ios-surface) !important;
        border: 1px solid var(--ios-separator) !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: var(--ios-label) !important;
        transition: all 0.2s ease;
        box-shadow: none !important;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff !important;
        border-color: var(--accent) !important;
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
    .ios-badge.blue { background: rgba(0,122,255,0.12); color: #0055CC; }

    /* Pagination */
    .ios-pagination { display: flex; gap: 4px; list-style: none; padding: 0; justify-content: center; margin-top: 20px; }
    .ios-pagination .page-link {
        border: none;
        color: var(--ios-label);
        background: var(--ios-surface);
        border-radius: 8px;
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 0.9rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .ios-pagination .page-item.active .page-link {
        background: var(--accent); color: #fff; box-shadow: 0 4px 10px rgba(48,200,138,0.3);
    }
    .ios-pagination .page-link:hover:not(.active) { background: var(--ios-surface-2); }

    /* Print Overrides */
    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .dash-card { box-shadow: none !important; border: 1px solid var(--ios-separator); }
        .metrics-card { color: #000 !important; background: #f8f9fa !important; border: 1px solid var(--ios-separator); box-shadow: none !important; }
        .metrics-bg-icon { display: none; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Area & Route Sales</h1>
        <div class="page-subtitle">Analyze revenue, discounts, and collections by geographical territories.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3 border-bottom">
    <h2 style="font-weight: 800; margin: 0;">Area / Route Wise Sales Report</h2>
    <p style="color: #666; margin: 5px 0 0; font-size: 1.1rem;">Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
</div>

<!-- Primary KPIs (Extracted from Grand Totals) -->
<div class="row g-3 mb-4">
    <!-- Gross Revenue -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-cash-stack metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Gross Revenue</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_gross'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Net Revenue -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-check-circle-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Net Revenue</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_net'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Collected -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #5856D6, #4543B0);">
            <i class="bi bi-piggy-bank-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Collected</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_collected'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Outstanding -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-hourglass-split metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Outstanding</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_outstanding'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="ios-label-sm">Search Area/Route</label>
                <select name="search" id="routeSearchSelect" class="form-select fw-bold">
                    <option value="">All Areas / Routes</option>
                    <option value="Unassigned / Walk-in / E-Commerce" <?php echo $search_query === 'Unassigned / Walk-in / E-Commerce' ? 'selected' : ''; ?>>Unassigned / Walk-in / E-Commerce</option>
                    <?php foreach($allRoutes as $rName): ?>
                        <option value="<?php echo htmlspecialchars($rName); ?>" <?php echo $search_query === $rName ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">From Date</label>
                <input type="date" name="date_from" class="ios-input fw-bold" value="<?php echo htmlspecialchars($date_from); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-2">
                <label class="ios-label-sm">To Date</label>
                <input type="date" name="date_to" class="ios-input fw-bold" value="<?php echo htmlspecialchars($date_to); ?>" onchange="document.getElementById('filterForm').submit();">
            </div>
            <div class="col-md-2">
                <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 42px;">
                    <i class="bi bi-funnel-fill me-1"></i> Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Data Table -->
<div class="dash-card mb-5 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 25%;" class="ps-4">Area / Route</th>
                    <th class="text-center" style="width: 10%;">Orders</th>
                    <th class="text-end" style="width: 15%;">Gross Revenue</th>
                    <th class="text-end" style="width: 15%; color: #CC2200 !important;">Discounts</th>
                    <th class="text-end" style="width: 15%; color: #1A9A3A !important;">Net Revenue</th>
                    <th class="text-end pe-4" style="width: 20%;">Collected / Outstanding</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($reportData as $row): ?>
                <tr>
                    <td class="ps-4">
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                            <i class="bi bi-geo-alt-fill text-primary me-2"></i> <?php echo htmlspecialchars($row['area_name']); ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="ios-badge blue px-3" style="font-size: 0.85rem;"><?php echo number_format($row['total_orders']); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 600; color: var(--ios-label-2);">Rs <?php echo number_format($row['gross_revenue'], 2); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 700; color: #CC2200;">- Rs <?php echo number_format($row['total_discounts'], 2); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 800; font-size: 1.05rem; color: #1A9A3A;">Rs <?php echo number_format($row['net_revenue'], 2); ?></span>
                    </td>
                    <td class="text-end pe-4">
                        <div style="font-weight: 700; color: #1A9A3A; font-size: 0.85rem;">Col: Rs <?php echo number_format($row['total_collected'], 2); ?></div>
                        <?php if($row['total_outstanding'] > 0): ?>
                            <div style="font-weight: 600; color: #CC2200; font-size: 0.75rem; margin-top: 2px;">Ows: Rs <?php echo number_format($row['total_outstanding'], 2); ?></div>
                        <?php else: ?>
                            <div style="font-weight: 600; color: var(--ios-label-3); font-size: 0.75rem; margin-top: 2px;"><i class="bi bi-check2-all me-1"></i>Settled</div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($reportData)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <div class="empty-state">
                            <i class="bi bi-map" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No sales data found for the selected period.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            
            <?php if(!empty($reportData)): ?>
            <tfoot style="background: var(--ios-surface-2); border-top: 2px solid var(--ios-label);">
                <tr>
                    <td class="text-end text-uppercase fw-bold ps-4" style="color: var(--ios-label-2); font-size: 0.8rem;">Grand Totals:</td>
                    <td class="text-center fw-bold" style="color: #0055CC; font-size: 1rem;"><?php echo number_format($grandTotals['grand_orders'] ?? 0); ?></td>
                    <td class="text-end fw-bold" style="color: var(--ios-label);">Rs <?php echo number_format($grandTotals['grand_gross'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-danger">- Rs <?php echo number_format($grandTotals['grand_discount'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-success fs-5">Rs <?php echo number_format($grandTotals['grand_net'] ?? 0, 2); ?></td>
                    <td class="text-end pe-4">
                        <div class="text-success fw-bold" style="font-size: 0.95rem;">Col: Rs <?php echo number_format($grandTotals['grand_collected'] ?? 0, 2); ?></div>
                        <div class="text-danger fw-bold small">Ows: Rs <?php echo number_format($grandTotals['grand_outstanding'] ?? 0, 2); ?></div>
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<ul class="ios-pagination mb-5 no-print">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new TomSelect('#routeSearchSelect', {
        create: false,
        sortField: { field: "text", direction: "asc" },
        placeholder: "Type to search area..."
    });
});
</script>

<?php include '../includes/footer.php'; ?>