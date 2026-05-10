<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

$message = '';

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status ENUM('pending', 'sent', 'received', 'completed', 'cancelled') DEFAULT 'pending'");
} catch(PDOException $e) {}
// -------------------------

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete') {
    $id = (int)$_POST['po_id'];
    try {
        $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$id]);
        $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Purchase Order deleted successfully!</div>";
    } catch(Exception $e) {
        $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting PO.</div>";
    }
}

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($search_query !== '') {
    $whereClause .= " AND (po.id LIKE ? OR s.company_name LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if ($status_filter !== '') {
    $whereClause .= " AND po.status = ?";
    $params[] = $status_filter;
}

// Get Total Rows for Pagination
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Paginated POs
$stmt = $pdo->prepare("
    SELECT po.*, s.company_name 
    FROM purchase_orders po 
    JOIN suppliers s ON po.supplier_id = s.id 
    $whereClause
    ORDER BY po.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$pos = $stmt->fetchAll();

// Global Metrics for KPI Cards
$metrics = $pdo->query("
    SELECT 
        COUNT(*) as total_pos,
        SUM(CASE WHEN status IN ('pending', 'sent') THEN 1 ELSE 0 END) as active_pos,
        SUM(CASE WHEN status IN ('completed', 'received') THEN total_amount ELSE 0 END) as completed_amount
    FROM purchase_orders
")->fetch();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Page Specific Metric Cards */
    .metrics-card {
        border-radius: 16px;
        padding: 20px 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 20px;
        height: 100%;
        transition: transform 0.2s ease;
    }
    .metrics-card:hover { transform: translateY(-2px); }
    .metrics-icon {
        width: 54px; height: 54px;
        border-radius: 14px;
        background: rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
    }
    
    /* Selection Card (For Modal) */
    .option-card {
        display: flex;
        align-items: center;
        padding: 20px;
        border-radius: 16px;
        text-decoration: none;
        transition: transform 0.2s, box-shadow 0.2s;
        margin-bottom: 12px;
    }
    .option-card:hover {
        transform: translateY(-2px);
    }
    .option-card:active {
        transform: scale(0.98);
    }
    .option-card-ai {
        background: linear-gradient(145deg, #FF9500, #E07800);
        color: #fff;
        box-shadow: 0 8px 24px rgba(255,149,0,0.25);
    }
    .option-card-manual {
        background: var(--ios-surface-2);
        color: var(--ios-label);
        border: 1px solid var(--ios-separator);
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Purchase Orders</h1>
        <div class="page-subtitle">Manage supplier orders, draft requests, and process goods receiving.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" data-bs-toggle="modal" data-bs-target="#poChoiceModal">
            <i class="bi bi-plus-lg"></i> Create New PO
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Primary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <div class="metrics-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total POs Generated</div>
                <div style="font-size: 1.6rem; font-weight: 800; line-height: 1;"><?php echo number_format((float)($metrics['total_pos'] ?? 0)); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Active & Pending POs</div>
                <div style="font-size: 1.6rem; font-weight: 800; line-height: 1;"><?php echo number_format((float)($metrics['active_pos'] ?? 0)); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-12">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <div class="metrics-icon"><i class="bi bi-box-seam-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Completed Purchases</div>
                <div style="font-size: 1.6rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format((float)($metrics['completed_amount'] ?? 0), 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="ios-label-sm">Search Orders</label>
                <div class="ios-search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search PO Number or Supplier..." value="<?php echo htmlspecialchars($search_query); ?>" oninput="debounceSearch()">
                </div>
            </div>
            <div class="col-md-4">
                <label class="ios-label-sm">Order Status</label>
                <select name="status" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Draft / Pending</option>
                    <option value="sent" <?php echo $status_filter == 'sent' ? 'selected' : ''; ?>>Sent via Email</option>
                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed (GRN)</option>
                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="quick-btn quick-btn-secondary w-100" style="min-height: 42px;">
                    <i class="bi bi-funnel-fill"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Purchase Orders Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table text-center">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4">PO Number</th>
                    <th class="text-start">Supplier & Date</th>
                    <th class="text-end">Subtotal</th>

                    <th class="text-end" style="color: #1A9A3A !important;">Net Payable</th>
                    <th>Status</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pos as $po): ?>
                <tr>
                    <td class="text-start ps-4">
                        <span style="font-weight: 800; font-size: 0.95rem; color: var(--accent-dark);">
                            #<?php echo str_pad($po['id'], 6, '0', STR_PAD_LEFT); ?>
                        </span>
                    </td>
                    <td class="text-start">
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($po['company_name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            <i class="bi bi-calendar"></i> <?php echo date('M d, Y', strtotime($po['po_date'])); ?>
                        </div>
                    </td>
                    <td class="text-end fw-bold text-muted">
                        Rs <?php echo number_format($po['subtotal'], 2); ?>
                    </td>

                    <td class="text-end fw-bold" style="color: #1A9A3A; font-size: 1rem;">
                        Rs <?php echo number_format($po['total_amount'], 2); ?>
                    </td>
                    <td>
                        <?php if($po['status'] == 'pending'): ?>
                            <span class="ios-badge orange"><i class="bi bi-pencil-square"></i> Draft</span>
                        <?php elseif($po['status'] == 'sent'): ?>
                            <span class="ios-badge blue"><i class="bi bi-envelope-check"></i> Sent</span>
                        <?php elseif(in_array($po['status'], ['completed', 'received'])): ?>
                            <span class="ios-badge green"><i class="bi bi-check-circle-fill"></i> Completed</span>
                        <?php else: ?>
                            <span class="ios-badge gray"><?php echo ucfirst($po['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            
                            <!-- Convert to GRN Button -->
                            <?php if(!in_array($po['status'], ['completed', 'received', 'cancelled'])): ?>
                                <a href="create_grn.php?po_id=<?php echo $po['id']; ?>" class="quick-btn quick-btn-primary" style="padding: 6px 10px;" title="Receive Goods (Create GRN)">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </a>
                            <?php endif; ?>

                            
                            
                            <a href="view_po.php?id=<?php echo $po['id']; ?>" class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="View / Print">
                                <i class="bi bi-printer"></i>
                            </a>
                            
                            <a href="create_po.php?edit_id=<?php echo $po['id']; ?>" class="quick-btn quick-btn-ghost" style="padding: 6px 10px; color: #FF9500; background: rgba(255,149,0,0.1);" title="Edit">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this Purchase Order?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($pos)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <i class="bi bi-file-earmark-text" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No Purchase Orders generated yet.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<ul class="ios-pagination mb-4">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- PO Choice Modal -->
<div class="modal fade" id="poChoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <div class="modal-header border-bottom-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pb-4 pt-2 text-center">
                <div class="mb-3">
                    <i class="bi bi-robot" style="font-size: 4.5rem; color: #FF9500;"></i>
                </div>
                <h4 class="fw-bold text-dark mb-2" style="letter-spacing: -0.5px;">Create Purchase Order</h4>
                <p class="text-muted small mb-4" style="font-size: 0.9rem;">How would you like to generate this PO?</p>
                
                <a href="create_po.php?smart=true" class="option-card option-card-ai">
                    <i class="bi bi-stars fs-2 me-3" style="color: #FFCC00;"></i> 
                    <div class="text-start">
                        <div style="font-weight: 800; font-size: 1.1rem; letter-spacing: -0.3px;">Smart AI Generator</div>
                        <div style="font-size: 0.8rem; opacity: 0.9; margin-top: 2px;">Auto-calculate velocity & supplier claims</div>
                    </div>
                </a>
                
                <a href="create_po.php" class="option-card option-card-manual">
                    <i class="bi bi-pencil-square fs-2 me-3" style="color: var(--ios-label-3);"></i> 
                    <div class="text-start">
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">Manual Entry</div>
                        <div style="font-size: 0.8rem; color: var(--ios-label-2); margin-top: 2px;">Add products and quantities one by one</div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Search debounce
let searchTimer;
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 800);
}

// Email AJAX Handler
document.querySelectorAll('.btn-email-po').forEach(btn => {
    btn.addEventListener('click', async function() {
        const poId = this.dataset.id;
        const originalHtml = this.innerHTML;
        this.innerHTML = '<span class="spinner-border spinner-border-sm" style="width: 1rem; height: 1rem;"></span>';
        this.disabled = true;

        try {
            const formData = new FormData();
            formData.append('po_id', poId);
            
            const res = await fetch('../ajax/send_po_email.php', { method: 'POST', body: formData });
            const data = await res.json();
            
            if (data.success) {
                this.style.background = 'rgba(52,199,89,0.15)';
                this.style.color = '#1A9A3A';
                this.innerHTML = '<i class="bi bi-check2-all"></i> Sent';
                setTimeout(() => location.reload(), 1500); 
            } else {
                alert("Error sending email: " + data.error);
                this.disabled = false;
                this.innerHTML = originalHtml;
            }
        } catch(e) {
            alert("Network Error");
            this.disabled = false;
            this.innerHTML = originalHtml;
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>