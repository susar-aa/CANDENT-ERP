<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$page_title = 'Dispatch';

// Get today's assigned routes
$stmt = $pdo->prepare("
    SELECT rr.*, r.name as route_name, u.name as rep_name, e.name as driver_name 
    FROM rep_routes rr 
    JOIN routes r ON rr.route_id = r.id 
    JOIN users u ON rr.rep_id = u.id 
    LEFT JOIN employees e ON rr.driver_id = e.id
    WHERE rr.assign_date = CURDATE()
    ORDER BY rr.id DESC
");
$stmt->execute();
$dispatches = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .dispatch-card {
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .dc-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 12px; }
    .dc-route { font-weight: 700; font-size: 16px; color: var(--text-main); margin: 0; }
    .dc-badge { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; letter-spacing: 0.05em; }
    .status-assigned { background: var(--warning-bg); color: var(--warning); }
    .status-accepted { background: var(--info-bg); color: var(--info); }
    .status-completed { background: var(--primary-bg); color: var(--primary); }
    .status-unloaded { background: var(--success-bg); color: var(--success); }
    .status-rejected { background: var(--danger-bg); color: var(--danger); }
    
    .dc-row { display: flex; align-items: center; gap: 8px; font-size: 14px; margin-bottom: 6px; color: var(--text-muted); }
    .dc-row i { color: var(--primary); width: 16px; text-align: center; }
    .dc-val { font-weight: 600; color: var(--text-main); }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="section-title m-0">Today's Vehicles</h2>
    <a href="../pages/dispatch.php" class="btn btn-sm btn-primary rounded-pill fw-bold" style="background: var(--primary);"><i class="bi bi-plus-lg"></i> New</a>
</div>

<?php if(empty($dispatches)): ?>
    <div class="text-center py-5">
        <i class="bi bi-truck text-muted" style="font-size: 3rem; opacity: 0.5;"></i>
        <p class="mt-3 text-muted fw-bold">No vehicles dispatched today.</p>
    </div>
<?php else: ?>
    <?php foreach($dispatches as $d): ?>
        <div class="dispatch-card">
            <div class="dc-header">
                <h3 class="dc-route"><i class="bi bi-map-fill me-2 text-primary"></i><?php echo htmlspecialchars($d['route_name']); ?></h3>
                <span class="dc-badge status-<?php echo $d['status']; ?>"><?php echo ucfirst($d['status']); ?></span>
            </div>
            <div class="dc-body">
                <div class="dc-row"><i class="bi bi-person-badge"></i> Rep: <span class="dc-val"><?php echo htmlspecialchars($d['rep_name']); ?></span></div>
                <div class="dc-row"><i class="bi bi-person-fill"></i> Driver: <span class="dc-val"><?php echo htmlspecialchars($d['driver_name'] ?: 'N/A'); ?></span></div>
                <?php if($d['start_meter']): ?>
                    <div class="dc-row mt-2 pt-2 border-top"><i class="bi bi-speedometer2"></i> Start: <span class="dc-val font-monospace"><?php echo $d['start_meter']; ?> km</span></div>
                <?php endif; ?>
                <?php if($d['end_meter']): ?>
                    <div class="dc-row"><i class="bi bi-flag-fill text-success"></i> End: <span class="dc-val font-monospace"><?php echo $d['end_meter']; ?> km</span></div>
                    <div class="dc-row fw-bold text-success"><i class="bi bi-cash-stack"></i> Sales: <span class="dc-val font-monospace">Rs <?php echo number_format($d['actual_cash'] + $d['actual_bank'], 2); ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
