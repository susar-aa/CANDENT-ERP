<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- FETCH METRICS ---
$total_products = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'available'")->fetchColumn();
$total_suppliers = $pdo->query("SELECT COUNT(*) FROM suppliers WHERE status = 'active'")->fetchColumn();
$total_categories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$low_stock_items = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'available' AND stock <= 5")->fetchColumn();

// Fetch Category Distribution for Chart
$catStmt = $pdo->query("SELECT c.name, COUNT(p.id) as count FROM categories c LEFT JOIN products p ON c.id = p.category_id GROUP BY c.id");
$catLabels = [];
$catData = [];
while($row = $catStmt->fetch()) {
    $catLabels[] = $row['name'];
    $catData[] = $row['count'];
}

// Fetch Recently Added Products
$recent_products = $pdo->query("
    SELECT p.name, p.sku, p.stock, p.selling_price, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    ORDER BY p.created_at DESC LIMIT 5
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-grid-1x2 text-primary"></i> Core Setup Dashboard</h1>
    <div class="btn-group shadow-sm">
        <a href="products.php" class="btn btn-primary fw-bold"><i class="bi bi-box-seam"></i> Products</a>
        <a href="suppliers.php" class="btn btn-outline-primary fw-bold"><i class="bi bi-truck"></i> Suppliers</a>
        <a href="categories.php" class="btn btn-outline-primary fw-bold"><i class="bi bi-tags"></i> Categories</a>
        <a href="inventory.php" class="btn btn-outline-primary fw-bold"><i class="bi bi-link-45deg"></i> Mapping</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Active Products</div>
                <h2 class="mb-0 fw-bold"><?php echo $total_products; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Active Suppliers</div>
                <h2 class="mb-0 fw-bold"><?php echo $total_suppliers; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-dark border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Product Categories</div>
                <h2 class="mb-0 fw-bold"><?php echo $total_categories; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Low Stock Alerts</div>
                <h2 class="mb-0 fw-bold"><?php echo $low_stock_items; ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-pie-chart"></i> Product Distribution by Category</div>
            <div class="card-body d-flex justify-content-center" style="position: relative; height: 300px;">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Table -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span><i class="bi bi-box-seam"></i> Recently Added Products</span>
                <a href="products.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th class="text-end">Price</th>
                            <th class="text-center">Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_products as $p): ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars($p['name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($p['sku']); ?></small></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($p['category_name']); ?></span></td>
                            <td class="text-end fw-bold text-success">Rs <?php echo number_format($p['selling_price'], 2); ?></td>
                            <td class="text-center">
                                <span class="badge <?php echo $p['stock'] > 5 ? 'bg-primary' : 'bg-danger'; ?>"><?php echo $p['stock']; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('categoryChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($catLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($catData); ?>,
                backgroundColor: ['#0d6efd', '#198754', '#0dcaf0', '#ffc107', '#dc3545', '#6610f2', '#6f42c1', '#d63384', '#fd7e14'],
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });
});
</script>

<?php include '../includes/footer.php'; ?>