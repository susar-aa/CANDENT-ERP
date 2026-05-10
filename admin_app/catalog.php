<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$page_title = 'Catalog';

$stmt = $pdo->query("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    ORDER BY p.name ASC
");
$products = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .search-wrapper { position: relative; margin-bottom: 20px; }
    .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 16px; }
    .search-input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 12px 16px 12px 44px; font-size: 15px; outline: none; transition: border 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
    .search-input:focus { border-color: var(--primary); }

    .prod-card {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 14px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
    .pc-info { flex: 1; }
    .pc-name { font-size: 15px; font-weight: 700; margin: 0 0 4px 0; color: var(--text-main); }
    .pc-cat { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
    .pc-meta { display: flex; gap: 12px; font-size: 13px; font-weight: 600; font-family: 'JetBrains Mono', monospace; }
    .pc-price { color: var(--primary); }
    .pc-stock { color: var(--success); }
    .pc-stock.low { color: var(--danger); }
    .pc-status { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
    .status-available { background: var(--success); box-shadow: 0 0 0 3px var(--success-bg); }
    .status-unavailable { background: var(--danger); box-shadow: 0 0 0 3px var(--danger-bg); }
</style>

<div class="search-wrapper">
    <i class="bi bi-search search-icon"></i>
    <input type="text" id="searchInput" class="search-input" placeholder="Search products...">
</div>

<div id="productList">
    <?php foreach($products as $p): ?>
        <div class="prod-card item-card">
            <div class="pc-info">
                <h3 class="pc-name item-name"><?php echo htmlspecialchars($p['name']); ?></h3>
                <div class="pc-cat"><i class="bi bi-tag-fill me-1 opacity-50"></i><?php echo htmlspecialchars($p['category_name'] ?: 'Uncategorized'); ?></div>
                <div class="pc-meta">
                    <span class="pc-price">Rs <?php echo number_format($p['mrp'], 2); ?></span>
                    <span class="pc-stock <?php echo $p['stock'] <= 5 ? 'low' : ''; ?>"><i class="bi bi-box me-1"></i><?php echo $p['stock']; ?></span>
                </div>
            </div>
            <div class="pc-status status-<?php echo $p['status']; ?>"></div>
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
