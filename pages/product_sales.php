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
$category_filter = isset($_GET['category_id']) ? $_GET['category_id'] : '';

$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$whereClause = "WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?";
$params = [$date_from, $date_to];

if ($search_query !== '') {
    $whereClause .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if ($category_filter !== '') {
    $whereClause .= " AND p.category_id = ?";
    $params[] = $category_filter;
}

// --- FETCH ALL PRODUCTS FOR SEARCH DROPDOWN ---
$allProductsList = $pdo->query("SELECT name, sku FROM products ORDER BY name ASC")->fetchAll();

// --- FETCH TOTALS FOR PAGINATION & GRAND ROW ---
$countQuery = "
    SELECT COUNT(DISTINCT p.id) 
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    $whereClause
";
$totalStmt = $pdo->prepare($countQuery);
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$grandQuery = "
    SELECT 
        SUM(oi.quantity) as grand_qty,
        SUM(oi.quantity * oi.price) as grand_gross,
        SUM(oi.discount) as grand_discount,
        SUM((oi.quantity * oi.price) - oi.discount) as grand_net
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    $whereClause
";
$grandStmt = $pdo->prepare($grandQuery);
$grandStmt->execute($params);
$grandTotals = $grandStmt->fetch();

// --- FETCH PAGINATED DATA ---
$query = "
    SELECT 
        p.id, p.name, p.sku, 
        COALESCE(c.name, 'Uncategorized') as category_name,
        SUM(oi.quantity) as total_qty,
        SUM(oi.quantity * oi.price) as gross_revenue,
        SUM(oi.discount) as total_discount,
        SUM((oi.quantity * oi.price) - oi.discount) as net_revenue,
        COUNT(DISTINCT o.id) as times_sold
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    $whereClause
    GROUP BY p.id, p.name, p.sku, category_name
    ORDER BY net_revenue DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reportData = $stmt->fetchAll();

// Fetch Categories for Dropdown
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

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
    .ios-badge.gray { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }

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
        <h1 class="page-title">Product Sales Report</h1>
        <div class="page-subtitle">Analyze volume and revenue performance item by item.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3 border-bottom">
    <h2 style="font-weight: 800; margin: 0;">Product Wise Sales Report</h2>
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
    <!-- Units Sold -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #5856D6, #4543B0);">
            <i class="bi bi-box-seam-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Total Units Sold</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo number_format($grandTotals['grand_qty'] ?? 0); ?></div>
            </div>
        </div>
    </div>
    <!-- Discounts Given -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <i class="bi bi-tags-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Item Discounts Given</div>
                <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($grandTotals['grand_discount'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="ios-label-sm">Search Product/SKU</label>
                <select name="search" id="productSearchSelect" class="form-select fw-bold">
                    <option value="">All Products</option>
                    <?php foreach($allProductsList as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['name']); ?>" <?php echo $search_query === $p['name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?> <?php echo $p['sku'] ? '('.htmlspecialchars($p['sku']).')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Category</label>
                <select name="category_id" id="categorySelect" class="form-select fw-bold" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $category_filter == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
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
                    <th style="width: 30%;" class="ps-4">Product Details</th>
                    <th class="text-center" style="width: 10%;">Orders</th>
                    <th class="text-center" style="width: 15%;">Qty Sold</th>
                    <th class="text-end" style="width: 15%;">Gross Revenue</th>
                    <th class="text-end" style="width: 15%; color: #CC2200 !important;">Item Discounts</th>
                    <th class="text-end pe-4" style="width: 15%; color: #1A9A3A !important;">Net Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($reportData as $row): ?>
                <tr>
                    <td class="ps-4">
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($row['name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            SKU: <?php echo htmlspecialchars($row['sku'] ?: 'N/A'); ?> | <span class="ios-badge gray px-2 ms-1" style="font-size: 0.65rem;"><?php echo htmlspecialchars($row['category_name']); ?></span>
                        </div>
                    </td>
                    <td class="text-center">
                        <span style="font-size: 0.9rem; font-weight: 600; color: var(--ios-label-2);"><?php echo number_format($row['times_sold']); ?></span>
                    </td>
                    <td class="text-center">
                        <span class="ios-badge blue px-3" style="font-size: 0.9rem;"><?php echo number_format($row['total_qty']); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 600; color: var(--ios-label-2);">Rs <?php echo number_format($row['gross_revenue'], 2); ?></span>
                    </td>
                    <td class="text-end">
                        <span style="font-weight: 700; color: #CC2200;">- Rs <?php echo number_format($row['total_discount'], 2); ?></span>
                    </td>
                    <td class="text-end pe-4">
                        <span style="font-weight: 800; font-size: 1.05rem; color: #1A9A3A;">Rs <?php echo number_format($row['net_revenue'], 2); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($reportData)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <div class="empty-state">
                            <i class="bi bi-box-seam" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No sales data found for the selected period.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            
            <?php if(!empty($reportData)): ?>
            <tfoot style="background: var(--ios-surface-2); border-top: 2px solid var(--ios-label);">
                <tr>
                    <td colspan="2" class="text-end text-uppercase fw-bold ps-4" style="color: var(--ios-label-2); font-size: 0.8rem;">Grand Totals:</td>
                    <td class="text-center fw-bold" style="color: #0055CC; font-size: 1.05rem;"><?php echo number_format($grandTotals['grand_qty'] ?? 0); ?></td>
                    <td class="text-end fw-bold" style="color: var(--ios-label);">Rs <?php echo number_format($grandTotals['grand_gross'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-danger">- Rs <?php echo number_format($grandTotals['grand_discount'] ?? 0, 2); ?></td>
                    <td class="text-end fw-bold text-success pe-4 fs-5">Rs <?php echo number_format($grandTotals['grand_net'] ?? 0, 2); ?></td>
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
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&category_id=<?php echo $category_filter; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new TomSelect('#productSearchSelect', {
        create: false,
        sortField: { field: "text", direction: "asc" },
        placeholder: "Type to search product..."
    });
    
    new TomSelect('#categorySelect', {
        create: false,
        sortField: { field: "text", direction: "asc" },
        placeholder: "All Categories"
    });
});
</script>

<?php include '../includes/footer.php'; ?>