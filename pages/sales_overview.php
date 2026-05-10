<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- FETCH METRICS ---
$month = date('Y-m');
$mtd_sales = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'")->fetchColumn();
$mtd_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'")->fetchColumn();
$ecommerce_sales = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month' AND shipping_name IS NOT NULL")->fetchColumn();
$avg_order = $mtd_orders > 0 ? $mtd_sales / $mtd_orders : 0;

// Fetch Last 7 Days Sales
$chartLabels = [];
$salesDataRaw = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M d', strtotime("-$i days"));
    $salesDataRaw[$date] = 0;
}
$stmt = $pdo->query("SELECT DATE(created_at) as sale_date, SUM(total_amount) as total FROM orders WHERE created_at >= DATE(NOW() - INTERVAL 7 DAY) GROUP BY DATE(created_at)");
while($row = $stmt->fetch()) {
    if(isset($salesDataRaw[$row['sale_date']])) $salesDataRaw[$row['sale_date']] = (float)$row['total'];
}
$chartData = array_values($salesDataRaw);

// Fetch Top Reps
$top_reps = $pdo->query("
    SELECT u.name, SUM(o.total_amount) as total_sales 
    FROM orders o JOIN users u ON o.rep_id = u.id 
    WHERE DATE_FORMAT(o.created_at, '%Y-%m') = '$month'
    GROUP BY u.id ORDER BY total_sales DESC LIMIT 5
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-cart-check text-success"></i> Sales & Targets Dashboard</h1>
    <div class="btn-group shadow-sm">
        <a href="create_order.php" class="btn btn-success fw-bold"><i class="bi bi-receipt"></i> POS</a>
        <a href="online_orders.php" class="btn btn-outline-success fw-bold"><i class="bi bi-globe"></i> E-Commerce</a>
        <a href="routes.php" class="btn btn-outline-success fw-bold"><i class="bi bi-truck"></i> Routes</a>
        <a href="rep_targets.php" class="btn btn-outline-success fw-bold"><i class="bi bi-bullseye"></i> Targets</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-success text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Total Sales (MTD)</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($mtd_sales, 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-primary text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">E-Commerce Sales</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($ecommerce_sales, 2); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-dark border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Total Orders (MTD)</div>
                <h3 class="mb-0 fw-bold"><?php echo $mtd_orders; ?> Invoices</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Avg Order Value</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($avg_order, 2); ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-graph-up-arrow"></i> 7-Day Sales Trend</div>
            <div class="card-body" style="position: relative; height: 300px;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Table -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-trophy text-warning"></i> Top Reps (MTD)</div>
            <ul class="list-group list-group-flush">
                <?php foreach($top_reps as $idx => $r): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div>
                        <span class="fw-bold text-muted me-2">#<?php echo $idx+1; ?></span>
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($r['name']); ?></span>
                    </div>
                    <span class="badge bg-success rounded-pill fs-6">Rs <?php echo number_format($r['total_sales'], 0); ?></span>
                </li>
                <?php endforeach; ?>
                <?php if(empty($top_reps)): ?>
                    <li class="list-group-item text-center text-muted py-4">No sales recorded this month.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('salesChart').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(25, 135, 84, 0.5)');
    gradient.addColorStop(1, 'rgba(25, 135, 84, 0.05)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Gross Sales (Rs)',
                data: <?php echo json_encode($chartData); ?>,
                borderColor: '#198754',
                backgroundColor: gradient,
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#198754',
                pointRadius: 4
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
});
</script>

<?php include '../includes/footer.php'; ?>