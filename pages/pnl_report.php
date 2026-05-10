<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to Management

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN profit_percentage DECIMAL(5,2) DEFAULT 0.00");
} catch(PDOException $e) {}
// -------------------------

// --- 1. REVENUE & COGS CALCULATION ---
// We calculate Gross Sales from active invoices
// Gross profit is now calculated based on the category's profit percentage (Agent Margin)
$salesStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(o.total_amount), 0) as gross_sales,
        COALESCE(SUM((oi.quantity * oi.price) * (c.profit_percentage / 100)), 0) as gross_profit
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE DATE_FORMAT(o.created_at, '%Y-%m') = ? AND o.total_amount > 0
");
$salesStmt->execute([$selected_month]);
$salesData = $salesStmt->fetch();

$gross_sales = (float)$salesData['gross_sales'];
$gross_profit = (float)$salesData['gross_profit'];
$total_cogs = $gross_sales - $gross_profit; // The amount remitted to the supplier

// Calculate Gross Margin %
$gross_margin_percent = ($gross_sales > 0) ? ($gross_profit / $gross_sales) * 100 : 0;

// --- 2. OPERATING EXPENSES CALCULATION ---
// A. Payroll Expenses
$payStmt = $pdo->prepare("SELECT COALESCE(SUM(net_pay), 0) FROM payroll WHERE month = ? AND status = 'paid'");
$payStmt->execute([$selected_month]);
$payroll_expenses = (float)$payStmt->fetchColumn();

// B. Route Expenses
$routeExpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM route_expenses WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
$routeExpStmt->execute([$selected_month]);
$route_expenses = (float)$routeExpStmt->fetchColumn();

// C. General Company Expenses
$genExpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM general_expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
$genExpStmt->execute([$selected_month]);
$general_expenses = (float)$genExpStmt->fetchColumn();

// Total OPEX
$total_opex = $payroll_expenses + $route_expenses + $general_expenses;

// --- 3. NET PROFIT CALCULATION ---
$net_profit = $gross_profit - $total_opex;
$net_margin_percent = ($gross_sales > 0) ? ($net_profit / $gross_sales) * 100 : 0;


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

    /* iOS Inputs */
    .ios-input {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.95rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
    }
    .ios-input:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }

    /* Custom P&L Table */
    .pnl-table { width: 100%; border-collapse: collapse; }
    .pnl-table th, .pnl-table td { padding: 14px 20px; border-bottom: 1px solid var(--ios-separator); font-size: 0.95rem; }
    .pnl-table .section-header { 
        background-color: var(--ios-surface-2); 
        font-weight: 800; 
        font-size: 0.85rem; 
        color: var(--ios-label); 
        text-transform: uppercase; 
        letter-spacing: 0.05em; 
    }
    .pnl-table .sub-item { padding-left: 40px; color: var(--ios-label-2); font-weight: 500; }
    .pnl-table .total-row { font-weight: 700; color: var(--ios-label); border-top: 2px solid var(--ios-separator); }
    .pnl-table .major-subtotal { background-color: rgba(0,122,255,0.05); font-weight: 800; font-size: 1.1rem; color: #0055CC; }
    
    .net-profit-row { background-color: rgba(52,199,89,0.1); color: #1A9A3A; font-weight: 800; font-size: 1.25rem; }
    .net-loss-row { background-color: rgba(255,59,48,0.1); color: #CC2200; font-weight: 800; font-size: 1.25rem; }

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
        <h1 class="page-title">Profit & Loss Statement</h1>
        <div class="page-subtitle">Analyze revenues, direct costs, operational expenses, and net margins.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3" style="border-bottom: 2px solid #000;">
    <h2 style="font-weight: 800; margin: 0;">Statement of Comprehensive Income</h2>
    <p style="color: #666; margin: 5px 0 0; font-size: 1.1rem;">Period: <?php echo date('F Y', strtotime($selected_month . '-01')); ?></p>
</div>

<!-- Statement Period Filter -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="shadow-sm d-flex align-items-center justify-content-center rounded-circle" style="width: 46px; height: 46px; background: #fff; color: #0055CC; font-size: 1.3rem;">
                <i class="bi bi-calendar-event-fill"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Statement Period</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: var(--ios-label);"><?php echo date('F Y', strtotime($selected_month . '-01')); ?></div>
            </div>
        </div>
        <form method="GET" id="monthForm" class="d-flex gap-2 align-items-center">
            <input type="month" name="month" class="ios-input fw-bold" style="width: auto; min-height: 42px;" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="document.getElementById('monthForm').submit();">
            <button type="submit" class="quick-btn quick-btn-primary" style="min-height: 42px; padding: 0 20px;">Load Period</button>
        </form>
    </div>
</div>

<!-- Financial Highlights (Metrics Row) -->
<div class="row g-3 mb-4">
    <!-- Gross Sales -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-receipt metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Gross Sales</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($gross_sales, 2); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Gross Margin -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #30B0C7, #1A95AC);">
            <i class="bi bi-pie-chart-fill metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Gross Margin %</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo number_format($gross_margin_percent, 1); ?>%</div>
            </div>
        </div>
    </div>

    <!-- Total OPEX -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <i class="bi bi-wallet2 metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Operating Expenses</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_opex, 2); ?></div>
            </div>
        </div>
    </div>

    <!-- Net Profit Margin -->
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, <?php echo $net_profit >= 0 ? '#34C759, #30D158' : '#FF3B30, #CC1500'; ?>);">
            <i class="bi bi-graph-up-arrow metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.8); margin-bottom: 2px;">Net <?php echo $net_profit >= 0 ? 'Profit' : 'Loss'; ?> Margin</div>
                <div style="font-size: 1.5rem; font-weight: 800; letter-spacing: -0.5px;"><?php echo number_format($net_margin_percent, 1); ?>%</div>
            </div>
        </div>
    </div>
</div>

<!-- Formal P&L Ledger Table -->
<div class="dash-card mb-5 overflow-hidden">
    <div class="table-responsive">
        <table class="pnl-table">
            
            <!-- REVENUES -->
            <tr class="section-header">
                <td colspan="2"><i class="bi bi-arrow-down-right-circle text-primary me-2"></i> Revenues</td>
            </tr>
            <tr>
                <td class="sub-item">Gross Sales (Invoiced)</td>
                <td class="text-end fw-bold">Rs <?php echo number_format($gross_sales, 2); ?></td>
            </tr>
            <tr class="total-row">
                <td class="ps-4">Total Revenue</td>
                <td class="text-end text-primary" style="font-size: 1.05rem;">Rs <?php echo number_format($gross_sales, 2); ?></td>
            </tr>

            <!-- COGS -->
            <tr class="section-header">
                <td colspan="2"><i class="bi bi-box-seam text-warning me-2" style="color: #C07000 !important;"></i> Cost of Goods Sold (COGS)</td>
            </tr>
            <tr>
                <td class="sub-item">Product Base Costs (Inventory Issued)</td>
                <td class="text-end fw-bold">Rs <?php echo number_format($total_cogs, 2); ?></td>
            </tr>
            <tr class="total-row">
                <td class="ps-4">Total Cost of Goods Sold</td>
                <td class="text-end" style="color: #CC2200;">Rs <?php echo number_format($total_cogs, 2); ?></td>
            </tr>

            <!-- GROSS PROFIT -->
            <tr class="major-subtotal">
                <td>GROSS PROFIT</td>
                <td class="text-end">Rs <?php echo number_format($gross_profit, 2); ?></td>
            </tr>

            <!-- OPEX -->
            <tr class="section-header">
                <td colspan="2"><i class="bi bi-wallet2 text-danger me-2"></i> Operating Expenses (OPEX)</td>
            </tr>
            <tr>
                <td class="sub-item">Employee Payroll (Cleared Salaries)</td>
                <td class="text-end fw-bold">Rs <?php echo number_format($payroll_expenses, 2); ?></td>
            </tr>
            <tr>
                <td class="sub-item">Route / Delivery Expenses (Fuel, Meals, etc.)</td>
                <td class="text-end fw-bold">Rs <?php echo number_format($route_expenses, 2); ?></td>
            </tr>
            <tr>
                <td class="sub-item">General Admin / Company Expenses</td>
                <td class="text-end fw-bold">Rs <?php echo number_format($general_expenses, 2); ?></td>
            </tr>
            <tr class="total-row">
                <td class="ps-4">Total Operating Expenses</td>
                <td class="text-end" style="color: #CC2200; font-size: 1.05rem;">Rs <?php echo number_format($total_opex, 2); ?></td>
            </tr>

            <!-- NET PROFIT -->
            <tr class="<?php echo $net_profit >= 0 ? 'net-profit-row' : 'net-loss-row'; ?>">
                <td>NET <?php echo $net_profit >= 0 ? 'PROFIT (INCOME)' : 'LOSS'; ?></td>
                <td class="text-end">Rs <?php echo number_format(abs($net_profit), 2); ?></td>
            </tr>

        </table>
    </div>
</div>

<div class="text-center mb-5 pb-5 text-muted fw-medium" style="font-size: 0.8rem;">
    <i class="bi bi-info-circle-fill me-1"></i> P&L Reports strictly analyze finalized data. Pending GRNs and Unpaid Payroll are excluded until marked Paid.
</div>

<?php include '../includes/footer.php'; ?>