<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

// --- AUTO DB MIGRATION FOR TARGETS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rep_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rep_id INT NOT NULL,
        month VARCHAR(7) NOT NULL,
        target_amount DECIMAL(12,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY rep_month (rep_id, month),
        FOREIGN KEY (rep_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) { 
    error_log("Targets Migration Error: " . $e->getMessage()); 
}
// -------------------------------------

// Handle selected month (default to current YYYY-MM)
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Add or Update Target
    if ($_POST['action'] == 'save_target') {
        $rep_id = (int)$_POST['rep_id'];
        $month = $_POST['target_month'];
        $amount = (float)$_POST['target_amount'];

        if ($rep_id && !empty($month) && $amount > 0) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO rep_targets (rep_id, month, target_amount) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount)
                ");
                $stmt->execute([$rep_id, $month, $amount]);
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Target saved successfully!</div>";
            } catch (PDOException $e) {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error saving target.</div>";
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-info-circle-fill me-2'></i> Please fill all fields correctly.</div>";
        }
    }

    // Delete Target
    if ($_POST['action'] == 'delete_target') {
        $target_id = (int)$_POST['target_id'];
        try {
            $pdo->prepare("DELETE FROM rep_targets WHERE id = ?")->execute([$target_id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Target removed.</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting target.</div>";
        }
    }
}

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereClause = "WHERE t.month = ?";
$params = [$selected_month];

if ($search_query !== '') {
    $whereClause .= " AND u.name LIKE ?";
    $params[] = "%$search_query%";
}

// Get Total Rows for Pagination
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM rep_targets t JOIN users u ON t.rep_id = u.id $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// --- FETCH PAGINATED DATA ---
$query = "
    SELECT 
        t.*, u.name as rep_name,
        (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE rep_id = t.rep_id AND DATE_FORMAT(created_at, '%Y-%m') = t.month) as achieved_amount
    FROM rep_targets t
    JOIN users u ON t.rep_id = u.id
    $whereClause
    ORDER BY u.name ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$targets = $stmt->fetchAll();

// --- CALCULATE OVERALL TEAM TOTALS (Ignoring Pagination) ---
$summaryStmt = $pdo->prepare("
    SELECT 
        SUM(t.target_amount) as total_target,
        SUM(
            (SELECT COALESCE(SUM(total_amount), 0) 
             FROM orders 
             WHERE rep_id = t.rep_id 
             AND DATE_FORMAT(created_at, '%Y-%m') = t.month)
        ) as total_achieved
    FROM rep_targets t
    WHERE t.month = ?
");
$summaryStmt->execute([$selected_month]);
$summary = $summaryStmt->fetch();

$total_target_view = $summary['total_target'] ?: 0;
$total_achieved_view = $summary['total_achieved'] ?: 0;
$overall_progress = ($total_target_view > 0) ? ($total_achieved_view / $total_target_view) * 100 : 0;

// Fetch Reps for Dropdown
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* --- Specific Page Styles (Candent Theme) --- */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
    }
    .page-title {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
    }
    .page-subtitle {
        font-size: 0.85rem;
        color: var(--ios-label-2);
        margin-top: 4px;
    }

    /* iOS Inputs & Labels */
    .ios-input, .form-select {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.9rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }
    .ios-label-sm {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ios-label-2);
        margin-bottom: 6px;
        padding-left: 4px;
    }

    /* Search Bar with Icon */
    .ios-search-wrapper { position: relative; }
    .ios-search-wrapper .bi-search {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ios-label-3);
    }
    .ios-search-wrapper .ios-input { padding-left: 38px; }

    /* Custom Tables */
    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 14px 20px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 20px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }

    /* Modals */
    .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; }
    .modal-header { background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); padding: 18px 24px; }
    .modal-footer { border-top: 1px solid var(--ios-separator); padding: 16px 24px; background: var(--ios-surface); }
    
    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        position: relative;
        overflow: hidden;
    }

    /* Progress Bars */
    .ios-progress-track {
        height: 10px;
        border-radius: 50px;
        background: rgba(0,0,0,0.06);
        overflow: hidden;
        margin: 6px 0;
        width: 100%;
    }
    .metrics-card .ios-progress-track {
        background: rgba(255,255,255,0.2);
    }
    .ios-progress-fill {
        height: 100%;
        border-radius: 50px;
        transition: width 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .fill-green  { background: linear-gradient(90deg, #34C759, #30D158); }
    .fill-teal   { background: linear-gradient(90deg, #30B0C7, #1A95AC); }
    .fill-amber  { background: linear-gradient(90deg, #FFCC00, #FF9500); }
    .fill-red    { background: linear-gradient(90deg, #FF3B30, #CC1500); }
    .metrics-card .fill-light { background: linear-gradient(90deg, rgba(255,255,255,0.8), #ffffff); }

    /* Pagination */
    .ios-pagination { display: flex; gap: 4px; list-style: none; padding: 0; justify-content: center; margin-top: 20px; }
    .ios-pagination .page-link {
        border: none;
        color: var(--ios-label);
        background: var(--ios-surface);
        border-radius: 8px;
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 0.9rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .ios-pagination .page-item.active .page-link {
        background: var(--accent); color: #fff; box-shadow: 0 4px 10px rgba(48,200,138,0.3);
    }
    .ios-pagination .page-link:hover:not(.active) { background: var(--ios-surface-2); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Sales Targets & Performance</h1>
        <div class="page-subtitle">Track individual sales representative goals and team achievements.</div>
    </div>
    <div class="d-flex gap-2">
        <button class="quick-btn quick-btn-primary" data-bs-toggle="modal" data-bs-target="#targetModal">
            <i class="bi bi-bullseye"></i> Set Rep Target
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Top Controls & Overall Progress -->
<div class="row mb-4 align-items-stretch g-3">
    <!-- Filter / Month Picker -->
    <div class="col-md-4">
        <div class="dash-card h-100 p-4 d-flex flex-column justify-content-center" style="background: var(--ios-surface-2);">
            <form method="GET" id="filterForm" class="d-flex flex-column gap-3">
                <div>
                    <label class="ios-label-sm"><i class="bi bi-calendar-month me-1"></i> Selected Month</label>
                    <input type="month" name="month" class="ios-input w-100 fw-bold" style="font-size: 1.05rem;" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="document.getElementById('filterForm').submit();">
                </div>
                <div>
                    <label class="ios-label-sm"><i class="bi bi-search me-1"></i> Search Rep</label>
                    <div class="ios-search-wrapper">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" class="ios-input w-100" placeholder="Find by name..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>
                <button type="submit" class="d-none">Submit</button>
            </form>
        </div>
    </div>
    
    <!-- Team Summary -->
    <div class="col-md-8">
        <div class="metrics-card" style="background: linear-gradient(145deg, #5856D6, #4543B0);">
            <h6 class="fw-bold text-uppercase mb-3" style="color: rgba(255,255,255,0.7); font-size: 0.8rem; letter-spacing: 0.05em;">
                <i class="bi bi-people-fill me-1"></i> Overall Team Performance (<?php echo date('F Y', strtotime($selected_month . '-01')); ?>)
            </h6>
            <div class="row text-center mb-3">
                <div class="col-6" style="border-right: 1px solid rgba(255,255,255,0.15);">
                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7); margin-bottom: 2px;">Total Target</div>
                    <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;">Rs <?php echo number_format($total_target_view, 2); ?></div>
                </div>
                <div class="col-6">
                    <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7); margin-bottom: 2px;">Total Achieved</div>
                    <div style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px; color: #34C759;">Rs <?php echo number_format($total_achieved_view, 2); ?></div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span style="font-size: 0.8rem; font-weight: 600; color: rgba(255,255,255,0.9);">Goal Completion</span>
                    <span style="font-size: 0.85rem; font-weight: 800; color: #fff;"><?php echo number_format($overall_progress, 1); ?>%</span>
                </div>
                <div class="ios-progress-track">
                    <div class="ios-progress-fill <?php echo $overall_progress >= 100 ? 'fill-green' : 'fill-light'; ?>" style="width: <?php echo min(100, $overall_progress); ?>%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Targets Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(88,86,214,0.1); color: #5856D6;">
                <i class="bi bi-person-lines-fill"></i>
            </span>
            Individual Rep Targets
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 25%;">Sales Rep</th>
                    <th class="text-end" style="width: 15%;">Target (Rs)</th>
                    <th class="text-end" style="width: 15%;">Achieved (Rs)</th>
                    <th style="width: 30%;">Progress</th>
                    <th class="text-end" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($targets as $t): 
                    $progress = ($t['target_amount'] > 0) ? ($t['achieved_amount'] / $t['target_amount']) * 100 : 0;
                    
                    $fillClass = 'fill-red';
                    $textColor = 'var(--ios-label-2)';
                    if ($progress >= 100) { $fillClass = 'fill-green'; $textColor = '#1A9A3A'; }
                    elseif ($progress >= 75) { $fillClass = 'fill-teal'; $textColor = '#1A8A9A'; }
                    elseif ($progress >= 50) { $fillClass = 'fill-amber'; $textColor = '#C07000'; }
                ?>
                <tr>
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <i class="bi bi-person-circle text-muted me-2"></i><?php echo htmlspecialchars($t['rep_name']); ?>
                        </div>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <?php echo number_format($t['target_amount'], 2); ?>
                        </div>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 800; font-size: 0.95rem; color: <?php echo $progress >= 100 ? 'var(--ios-green)' : 'var(--accent-dark)'; ?>;">
                            <?php echo number_format($t['achieved_amount'], 2); ?>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="ios-progress-track flex-grow-1 me-3" style="margin: 0;">
                                <div class="ios-progress-fill <?php echo $fillClass; ?>" style="width: <?php echo min(100, $progress); ?>%;"></div>
                            </div>
                            <span style="font-size: 0.8rem; font-weight: 700; color: <?php echo $textColor; ?>; min-width: 45px; text-align: right;">
                                <?php echo number_format($progress, 1); ?>%
                            </span>
                        </div>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="Edit Target" 
                                onclick='openEditModal(<?php echo $t['id']; ?>, <?php echo $t['rep_id']; ?>, <?php echo $t['target_amount']; ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this target completely?');">
                                <input type="hidden" name="action" value="delete_target">
                                <input type="hidden" name="target_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Target">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($targets)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-bullseye" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No targets found for this criteria.</p>
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
<ul class="ios-pagination">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?month=<?php echo urlencode($selected_month); ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- Add/Edit Target Modal -->
<div class="modal fade" id="targetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700;">
                        <i class="bi bi-bullseye text-primary me-2"></i>Set Sales Target
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="background: var(--ios-bg);">
                    <input type="hidden" name="action" value="save_target">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Target Month <span class="text-danger">*</span></label>
                        <input type="month" name="target_month" id="modal_target_month" class="ios-input fw-bold" style="background: #fff;" value="<?php echo htmlspecialchars($selected_month); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Sales Rep <span class="text-danger">*</span></label>
                        <select name="rep_id" id="modal_rep_id" class="form-select fw-bold" style="background: #fff;" required>
                            <option value="">-- Choose Rep --</option>
                            <?php foreach($reps as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Target Amount (Gross Sales Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="target_amount" id="modal_target_amount" class="ios-input text-primary fw-bold" style="font-size: 1.2rem; height: 50px; background: #fff;" required placeholder="0.00">
                    </div>
                    
                    <div class="ios-alert mt-4" style="background: rgba(0,122,255,0.08); color: #0055CC; padding: 12px; border-radius: 10px; font-size: 0.8rem; font-weight: 500;">
                        <i class="bi bi-info-circle-fill me-1"></i> If a target already exists for this rep in the selected month, it will be updated to the new amount.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Target</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(id, repId, amount) {
    document.getElementById('modal_rep_id').value = repId;
    document.getElementById('modal_target_amount').value = amount;
    new bootstrap.Modal(document.getElementById('targetModal')).show();
}

// Reset form when modal is closed
document.getElementById('targetModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('modal_rep_id').value = '';
    document.getElementById('modal_target_amount').value = '';
});
</script>

<?php include '../includes/footer.php'; ?>