<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$page_title = 'Sales History';

$stmt = $pdo->prepare("
    SELECT o.*, c.name as customer_name, u.name as rep_name 
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    LEFT JOIN users u ON o.rep_id = u.id 
    ORDER BY o.created_at DESC 
    LIMIT 50
");
$stmt->execute();
$orders = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .order-card {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px; margin-bottom: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
    .oc-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 10px; }
    .oc-id { font-weight: 700; font-family: 'JetBrains Mono', monospace; font-size: 14px; color: var(--text-main); }
    .oc-date { font-size: 12px; color: var(--text-muted); }
    
    .oc-customer { font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; }
    .oc-rep { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 12px; }
    
    .oc-footer { display: flex; justify-content: space-between; align-items: center; }
    .oc-total { font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 700; color: var(--success); }
    .oc-status { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; }
    .status-paid { background: var(--success-bg); color: var(--success); }
    .status-pending { background: var(--warning-bg); color: var(--warning); }
    .status-partial { background: var(--info-bg); color: var(--info); }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="section-title m-0">Recent Orders</h2>
</div>

<?php foreach($orders as $o): ?>
    <div class="order-card">
        <div class="oc-header">
            <span class="oc-id">#<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?></span>
            <span class="oc-date"><?php echo date('d M, h:i A', strtotime($o['created_at'])); ?></span>
        </div>
        <div class="oc-customer"><?php echo htmlspecialchars($o['customer_name'] ?: 'Walk-in Customer'); ?></div>
        <div class="oc-rep"><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($o['rep_name'] ?: 'Admin/System'); ?></div>
        <div class="oc-footer">
            <span class="oc-total">Rs <?php echo number_format($o['total_amount'], 2); ?></span>
            <span class="oc-status status-<?php echo $o['payment_status']; ?>"><?php echo ucfirst($o['payment_status']); ?></span>
        </div>
    </div>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>
