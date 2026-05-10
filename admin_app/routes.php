<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$page_title = 'Routes';

$stmt = $pdo->query("
    SELECT r.*, (SELECT COUNT(*) FROM customers WHERE route_id = r.id) as customer_count 
    FROM routes r 
    ORDER BY r.name ASC
");
$routes = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .route-card {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
    .rc-info { flex: 1; }
    .rc-name { font-size: 16px; font-weight: 700; margin: 0 0 4px 0; color: var(--text-main); }
    .rc-desc { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; }
    .rc-meta { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; background: var(--purple-bg); color: var(--purple); padding: 4px 10px; border-radius: 20px; }
    .rc-action { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--bg-color); color: var(--primary); text-decoration: none; font-size: 16px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="section-title m-0">Route Areas</h2>
    <a href="../pages/routes.php" class="btn btn-sm rounded-pill fw-bold" style="background: var(--primary-bg); color: var(--primary);"><i class="bi bi-gear-fill"></i> Manage</a>
</div>

<?php foreach($routes as $r): ?>
    <div class="route-card">
        <div class="rc-info">
            <h3 class="rc-name"><?php echo htmlspecialchars($r['name']); ?></h3>
            <div class="rc-desc text-truncate" style="max-width: 250px;"><?php echo htmlspecialchars($r['description'] ?: 'No description'); ?></div>
            <div class="rc-meta"><i class="bi bi-shop"></i> <?php echo $r['customer_count']; ?> Shops</div>
        </div>
        <a href="customers.php?route_id=<?php echo $r['id']; ?>" class="rc-action"><i class="bi bi-chevron-right"></i></a>
    </div>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>
