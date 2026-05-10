<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN profit_percentage DECIMAL(5,2) DEFAULT 0.00");
} catch(PDOException $e) {}
// -------------------------

// Month filter
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_supplier = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

$suppliers = $pdo->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();

$report_data = [];
$total_claimable_profit = 0;
$total_gross_sales = 0;

if ($selected_supplier > 0) {
    // We want to group by Category and calculate total sales volume & claimable profit.
    // Sale volume = SUM(quantity * selling_price)
    // Profit = Sale volume * (profit_percentage / 100)
    
    $stmt = $pdo->prepare("
        SELECT 
            c.id as category_id,
            c.name as category_name,
            c.profit_percentage,
            COALESCE(SUM(oi.quantity), 0) as total_units_sold,
            COALESCE(SUM(oi.quantity * oi.price), 0) as total_sales_value
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE DATE_FORMAT(o.created_at, '%Y-%m') = ? 
          AND p.supplier_id = ?
          AND o.total_amount > 0
        GROUP BY c.id
        ORDER BY total_sales_value DESC
    ");
    $stmt->execute([$selected_month, $selected_supplier]);
    $results = $stmt->fetchAll();

    foreach($results as $row) {
        $category_name = $row['category_name'] ?: 'Uncategorized';
        $profit_percentage = (float)$row['profit_percentage'];
        $sales_value = (float)$row['total_sales_value'];
        $claimable = $sales_value * ($profit_percentage / 100);

        $total_gross_sales += $sales_value;
        $total_claimable_profit += $claimable;

        $report_data[] = [
            'category_name' => $category_name,
            'profit_percentage' => $profit_percentage,
            'total_units_sold' => $row['total_units_sold'],
            'total_sales_value' => $sales_value,
            'claimable_profit' => $claimable
        ];
    }
}

// Supplier name for report header
$supplier_name = "All Suppliers";
if ($selected_supplier > 0) {
    foreach($suppliers as $s) {
        if ($s['id'] == $selected_supplier) {
            $supplier_name = $s['company_name'];
            break;
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
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

    /* Filter Bar */
    .filter-bar {
        background: var(--ios-surface-2);
        border-radius: 14px;
        padding: 16px 20px;
        margin-bottom: 24px;
    }
    
    .ios-input, .form-select {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.95rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }

    /* Print Styles */
    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .dash-card { box-shadow: none !important; border: 1px solid #ddd; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Agent Profit Claims Report</h1>
        <div class="page-subtitle">Calculate category-based profit margins claimable from agents.</div>
    </div>
    <div>
        <?php if (!empty($report_data)): ?>
            <a href="../ajax/generate_agent_claim_pdf.php?supplier_id=<?php echo $selected_supplier; ?>&month=<?php echo urlencode($selected_month); ?>" class="quick-btn quick-btn-secondary text-decoration-none" target="_blank">
                <i class="bi bi-file-earmark-pdf"></i> Download PDF Claim
            </a>
        <?php else: ?>
            <button class="quick-btn quick-btn-secondary opacity-50" disabled>
                <i class="bi bi-file-earmark-pdf"></i> Download PDF Claim
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3" style="border-bottom: 2px solid #000;">
    <h2 style="font-weight: 800; margin: 0;">Agent Margin Claim Report</h2>
    <h4 style="margin: 5px 0 0; color: #333; font-weight: 700;">Agent: <?php echo htmlspecialchars($supplier_name); ?></h4>
    <p style="color: #666; margin: 5px 0 0; font-size: 1.1rem;">Sales Period: <?php echo date('F Y', strtotime($selected_month . '-01')); ?></p>
</div>

<!-- Filters -->
<div class="filter-bar no-print">
    <form method="GET" id="filterForm" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Select Agent / Supplier</label>
            <select name="supplier_id" class="form-select fw-bold text-dark" onchange="document.getElementById('filterForm').submit();">
                <option value="0">-- Select Agent to Generate Report --</option>
                <?php foreach($suppliers as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $selected_supplier == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['company_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Sales Month</label>
            <input type="month" name="month" class="ios-input fw-bold" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="document.getElementById('filterForm').submit();">
        </div>
        <div class="col-md-3">
            <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 44px;">
                <i class="bi bi-arrow-repeat me-2"></i> Generate
            </button>
        </div>
    </form>
</div>

<?php if ($selected_supplier > 0): ?>
    <?php if (empty($report_data)): ?>
        <div class="dash-card p-5 text-center mb-4">
            <i class="bi bi-file-earmark-x" style="font-size: 3rem; color: var(--ios-label-4);"></i>
            <h4 class="mt-3 fw-bold">No Sales Found</h4>
            <p class="text-muted">No sales were recorded for this agent during the selected month.</p>
        </div>
    <?php else: ?>
        <!-- KPI Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="dash-card p-4" style="background: linear-gradient(145deg, #007AFF, #0055CC); color: white;">
                    <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8; margin-bottom: 4px;">Total Gross Sales Value</div>
                    <div style="font-size: 2rem; font-weight: 800; letter-spacing: -1px;">Rs <?php echo number_format($total_gross_sales, 2); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="dash-card p-4" style="background: linear-gradient(145deg, #34C759, #30D158); color: white;">
                    <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8; margin-bottom: 4px;">Total Claimable Profit (Agent Margin)</div>
                    <div style="font-size: 2rem; font-weight: 800; letter-spacing: -1px;">Rs <?php echo number_format($total_claimable_profit, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Detailed Claim Table -->
        <div class="dash-card mb-4 overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
                <span class="card-title fw-bold">
                    <i class="bi bi-table text-primary me-2"></i> Category Profit Breakdown
                </span>
            </div>
            <div class="table-responsive">
                <table class="ios-table">
                    <thead>
                        <tr class="table-ios-header" style="background: var(--ios-surface-2);">
                            <th class="ps-4 py-3 text-uppercase fw-bold text-muted" style="font-size: 0.75rem;">Category</th>
                            <th class="text-center py-3 text-uppercase fw-bold text-muted" style="font-size: 0.75rem;">Total Units Sold</th>
                            <th class="text-end py-3 text-uppercase fw-bold text-muted" style="font-size: 0.75rem;">Gross Sales (Rs)</th>
                            <th class="text-center py-3 text-uppercase fw-bold text-muted" style="font-size: 0.75rem;">Agent Margin (%)</th>
                            <th class="text-end pe-4 py-3 text-uppercase fw-bold text-muted" style="font-size: 0.75rem;">Claimable Profit (Rs)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($report_data as $row): ?>
                        <tr>
                            <td class="ps-4 fw-bold" style="font-size: 1rem; color: var(--ios-label);">
                                <?php echo htmlspecialchars($row['category_name']); ?>
                            </td>
                            <td class="text-center fw-medium" style="color: var(--ios-label-2);">
                                <?php echo number_format($row['total_units_sold']); ?>
                            </td>
                            <td class="text-end fw-bold" style="color: var(--ios-label);">
                                <?php echo number_format($row['total_sales_value'], 2); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning text-dark fw-bold" style="font-size: 0.85rem; padding: 6px 10px; border-radius: 6px;">
                                    <?php echo number_format($row['profit_percentage'], 2); ?>%
                                </span>
                            </td>
                            <td class="text-end pe-4 fw-bolder" style="color: #1A9A3A; font-size: 1.1rem;">
                                <?php echo number_format($row['claimable_profit'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <!-- Totals Footer -->
                        <tr style="background: var(--ios-surface-2);">
                            <td colspan="2" class="ps-4 fw-bold text-uppercase" style="font-size: 0.85rem; color: var(--ios-label-2);">Grand Totals</td>
                            <td class="text-end fw-bold" style="font-size: 1.1rem; color: var(--ios-label);">
                                Rs <?php echo number_format($total_gross_sales, 2); ?>
                            </td>
                            <td></td>
                            <td class="text-end pe-4 fw-bolder" style="font-size: 1.3rem; color: #1A9A3A;">
                                Rs <?php echo number_format($total_claimable_profit, 2); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="text-center mt-5 mb-3 no-print">
            <p class="text-muted small"><i class="bi bi-info-circle-fill"></i> This report shows actual invoiced sales matched against your configured Category Profit Percentages to calculate the exact claimable rebate from your agent.</p>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="dash-card p-5 text-center mb-4" style="background: rgba(0,122,255,0.05); border: 1px dashed rgba(0,122,255,0.2);">
        <i class="bi bi-search" style="font-size: 3rem; color: #0055CC;"></i>
        <h4 class="mt-3 fw-bold" style="color: #0055CC;">Select an Agent</h4>
        <p class="text-muted" style="max-width: 400px; margin: 0 auto;">Please select an Agent/Supplier and a Sales Month from the filters above to generate the Profit Claims Report.</p>
    </div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
