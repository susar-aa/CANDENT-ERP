<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$month = date('Y-m');

// --- FETCH METRICS ---
$mtd_purchases = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM grns WHERE DATE_FORMAT(grn_date, '%Y-%m') = '$month'")->fetchColumn();
$pending_payables = $pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM grns WHERE payment_status != 'paid'")->fetchColumn();
$po_sent = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'sent'")->fetchColumn();
$total_grns = $pdo->query("SELECT COUNT(*) FROM grns WHERE DATE_FORMAT(grn_date, '%Y-%m') = '$month'")->fetchColumn();

// Fetch Recent GRNs
$recent_grns = $pdo->query("
    SELECT g.id, g.reference_number, g.total_amount, g.payment_status, s.company_name 
    FROM grns g LEFT JOIN suppliers s ON g.supplier_id = s.id 
    ORDER BY g.created_at DESC LIMIT 6
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-box-arrow-in-down text-warning text-dark"></i> Purchasing Dashboard</h1>
    <div class="btn-group shadow-sm">
        <a href="create_po.php" class="btn btn-warning fw-bold text-dark"><i class="bi bi-file-earmark-text"></i> Create PO</a>
        <a href="create_grn.php" class="btn btn-outline-warning text-dark fw-bold"><i class="bi bi-cart-plus"></i> Receive GRN</a>
        <a href="stock_ledger.php" class="btn btn-outline-warning text-dark fw-bold"><i class="bi bi-sliders"></i> Stock Ledger</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-warning text-dark border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Purchases (MTD)</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($mtd_purchases, 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Pending Payables</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($pending_payables, 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-dark border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Sent POs (Pending GRN)</div>
                <h3 class="mb-0 fw-bold"><?php echo $po_sent; ?> Orders</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Total Intakes (MTD)</div>
                <h3 class="mb-0 fw-bold"><?php echo $total_grns; ?> GRNs</h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-12">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span><i class="bi bi-card-checklist"></i> Recent Stock Intakes (GRNs)</span>
                <a href="grn_list.php" class="btn btn-sm btn-outline-secondary">View History</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>GRN # / Ref</th>
                            <th>Supplier</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_grns as $g): ?>
                        <tr>
                            <td class="fw-bold text-dark">#<?php echo str_pad($g['id'], 6, '0', STR_PAD_LEFT); ?> <br><small class="text-muted fw-normal">Ref: <?php echo htmlspecialchars($g['reference_number']); ?></small></td>
                            <td class="fw-bold text-primary"><?php echo htmlspecialchars($g['company_name'] ?: 'Unknown'); ?></td>
                            <td class="text-end fw-bold text-danger">Rs <?php echo number_format($g['total_amount'], 2); ?></td>
                            <td class="text-center">
                                <?php if($g['payment_status'] == 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif($g['payment_status'] == 'waiting'): ?>
                                    <span class="badge bg-info text-dark">Waiting</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($recent_grns)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No recent stock intakes.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>