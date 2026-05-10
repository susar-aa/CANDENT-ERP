<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$page_title = 'Customers';
$route_filter = isset($_GET['route_id']) ? (int)$_GET['route_id'] : null;

$sql = "
    SELECT c.*, r.name as route_name,
    (SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE customer_id = c.id) as outstanding
    FROM customers c
    LEFT JOIN routes r ON c.route_id = r.id
";
$params = [];

if ($route_filter) {
    $sql .= " WHERE c.route_id = ?";
    $params[] = $route_filter;
}
$sql .= " ORDER BY c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .search-wrapper { position: relative; margin-bottom: 20px; }
    .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 16px; }
    .search-input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 12px 16px 12px 44px; font-size: 15px; outline: none; transition: border 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
    .search-input:focus { border-color: var(--primary); }

    .cust-card {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px; display: flex; align-items: flex-start; gap: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
    .cust-avatar { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; background: var(--info-bg); color: var(--info); flex-shrink: 0; }
    .cust-info { flex: 1; }
    .cust-name { font-size: 16px; font-weight: 700; margin: 0 0 4px 0; color: var(--text-main); }
    .cust-address { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; display: flex; align-items: flex-start; gap: 6px; }
    .cust-badges { display: flex; gap: 8px; flex-wrap: wrap; }
    .cb-badge { font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 6px; }
    .cb-route { background: var(--bg-color); color: var(--text-muted); border: 1px solid var(--border); }
    .cb-out { background: var(--danger-bg); color: var(--danger); font-family: 'JetBrains Mono', monospace; }
</style>

<div class="search-wrapper">
    <i class="bi bi-search search-icon"></i>
    <input type="text" id="searchInput" class="search-input" placeholder="Search customers...">
</div>

<div id="customerList">
    <?php foreach($customers as $c): ?>
        <div class="cust-card item-card">
            <div class="cust-avatar"><i class="bi bi-shop"></i></div>
            <div class="cust-info">
                <h3 class="cust-name item-name"><?php echo htmlspecialchars($c['name']); ?></h3>
                <div class="cust-address">
                    <i class="bi bi-geo-alt mt-1"></i>
                    <span><?php echo htmlspecialchars($c['address'] ?: 'No address provided'); ?></span>
                </div>
                <div class="cust-badges">
                    <?php if($c['route_name']): ?>
                        <span class="cb-badge cb-route"><i class="bi bi-map-fill me-1"></i><?php echo htmlspecialchars($c['route_name']); ?></span>
                    <?php endif; ?>
                    <?php if($c['outstanding'] > 0): ?>
                        <span class="cb-badge cb-out"><i class="bi bi-exclamation-circle-fill me-1"></i>Ows: Rs <?php echo number_format($c['outstanding'], 2); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
    document.getElementById('searchInput').addEventListener('input', function() {
        let val = this.value.toLowerCase();
        document.querySelectorAll('.item-card').forEach(card => {
            let name = card.querySelector('.item-name').innerText.toLowerCase();
            card.style.display = name.includes(val) ? 'flex' : 'none';
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
