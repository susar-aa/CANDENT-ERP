<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- FETCH METRICS ---
$fin = $pdo->query("SELECT * FROM company_finances WHERE id = 1")->fetch() ?: ['cash_on_hand' => 0, 'bank_balance' => 0];

$outstanding_debtors = $pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE payment_status != 'paid'")->fetchColumn();
$pending_cheques = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM cheques WHERE status = 'pending' AND type = 'incoming'")->fetchColumn();

// Fetch Last 7 Days Income vs Expenses for Chart
$chartLabels = [];
$incomeData = [];
$expenseData = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M d', strtotime("-$i days"));
    
    // Income = cash_in + bank_in
    $inc = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_logs WHERE DATE(created_at) = ? AND type IN ('cash_in', 'bank_in')");
    $inc->execute([$date]);
    $incomeData[] = (float)$inc->fetchColumn();
    
    // Expense = cash_out + bank_out
    $exp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_logs WHERE DATE(created_at) = ? AND type IN ('cash_out', 'bank_out')");
    $exp->execute([$date]);
    $expenseData[] = (float)$exp->fetchColumn();
}

// Fetch Recent Financial Transactions
$recent_logs = $pdo->query("SELECT f.*, u.name as user_name FROM finance_logs f LEFT JOIN users u ON f.created_by = u.id ORDER BY f.created_at DESC LIMIT 6")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-bank text-danger"></i> Finance Dashboard</h1>
    <div class="btn-group shadow-sm">
        <a href="bank_cash.php" class="btn btn-danger fw-bold"><i class="bi bi-wallet2"></i> Cash/Bank</a>
        <a href="cheques.php" class="btn btn-outline-danger fw-bold"><i class="bi bi-credit-card-2-front"></i> Cheques</a>
        <a href="expenses.php" class="btn btn-outline-danger fw-bold"><i class="bi bi-clipboard-minus"></i> Expenses</a>
        <a href="pnl_report.php" class="btn btn-outline-danger fw-bold"><i class="bi bi-graph-down-arrow"></i> P&L Report</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-success text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Cash on Hand</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($fin['cash_on_hand'], 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-primary text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Bank Balance</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($fin['bank_balance'], 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Ows from Debtors</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($outstanding_debtors, 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-dark border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Pending Cheques</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($pending_cheques, 2); ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-bar-chart"></i> 7-Day Income vs Expenses</div>
            <div class="card-body" style="position: relative; height: 300px;">
                <canvas id="financeChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Table -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span><i class="bi bi-list-columns-reverse"></i> Recent Transactions</span>
                <a href="bank_cash.php" class="btn btn-sm btn-outline-secondary">View Ledger</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <tbody>
                        <?php foreach($recent_logs as $log): ?>
                        <tr>
                            <td class="ps-3 py-3">
                                <?php 
                                    if(in_array($log['type'], ['cash_in', 'bank_in'])) echo '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-arrow-down-left"></i> IN</span>';
                                    elseif(in_array($log['type'], ['cash_out', 'bank_out'])) echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-arrow-up-right"></i> OUT</span>';
                                    else echo '<span class="badge bg-secondary bg-opacity-10 text-dark border border-secondary"><i class="bi bi-arrow-left-right"></i> TFR</span>';
                                ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark small text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($log['description']); ?></div>
                                <div class="text-muted" style="font-size: 0.7rem;"><?php echo date('M d, h:i A', strtotime($log['created_at'])); ?></div>
                            </td>
                            <td class="text-end pe-3 fw-bold <?php echo in_array($log['type'], ['cash_out', 'bank_out']) ? 'text-danger' : 'text-success'; ?>">
                                <?php echo in_array($log['type'], ['cash_out', 'bank_out']) ? '-' : '+'; ?> Rs <?php echo number_format($log['amount'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($recent_logs)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No recent transactions.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('financeChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Income (In)',
                    data: <?php echo json_encode($incomeData); ?>,
                    backgroundColor: '#198754',
                    borderRadius: 4
                },
                {
                    label: 'Expenses (Out)',
                    data: <?php echo json_encode($expenseData); ?>,
                    backgroundColor: '#dc3545',
                    borderRadius: 4
                }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [2, 4] } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>