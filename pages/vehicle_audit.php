<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'audit_stock') {
        $rep_id = (int)$_POST['rep_id'];

        try {
            $pdo->beginTransaction();

            if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
                foreach ($_POST['product_id'] as $index => $prod_id) {
                    $actual_qty = (int)$_POST['actual_qty'][$index];
                    $system_qty = (int)$_POST['system_qty'][$index];
                    
                    if ($actual_qty !== $system_qty) {
                        $diff = $actual_qty - $system_qty; // Positive means excess, Negative means shortage

                        // 1. Update vehicle stock to match physical reality
                        $pdo->prepare("UPDATE vehicle_stock SET stock_qty = ?, last_audit_date = NOW() WHERE rep_id = ? AND product_id = ?")
                            ->execute([$actual_qty, $rep_id, $prod_id]);
                        
                        // 2. Log the audit adjustment
                        $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'audit_adjustment', ?, ?, ?, ?, ?)")
                            ->execute([$prod_id, $rep_id, $diff, $system_qty, $actual_qty, $_SESSION['user_id']]);
                    } else {
                        // Just update the audit date
                        $pdo->prepare("UPDATE vehicle_stock SET last_audit_date = NOW() WHERE rep_id = ? AND product_id = ?")
                            ->execute([$rep_id, $prod_id]);
                    }
                }
            }

            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Weekly vehicle audit completed successfully! Stock updated.</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error during audit: ".$e->getMessage()."</div>";
        }
    }
}

// Fetch Data
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        margin-bottom: 24px;
    }
    .page-title { font-size: 1.8rem; font-weight: 700; letter-spacing: -0.8px; color: var(--ios-label); margin: 0; }
    .page-subtitle { font-size: 0.85rem; color: var(--ios-label-2); margin-top: 4px; }
    
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

    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 12px 16px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td { vertical-align: middle; padding: 12px 16px; border-bottom: 1px solid var(--ios-separator); font-size: 0.9rem;}
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Weekly Vehicle Audit</h1>
        <div class="page-subtitle">Perform physical stock checks and adjust van inventory.</div>
    </div>
</div>

<?php echo $message; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="dash-card overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(255,149,0,0.1); color: #C07000;">
                        <i class="bi bi-clipboard-check"></i>
                    </span>
                    Stock Verification Form
                </span>
            </div>
            
            <div class="p-4" style="background: #fff;">
                <form method="POST" id="auditForm">
                    <input type="hidden" name="action" value="audit_stock">
                    
                    <div class="row mb-4 align-items-end border-bottom border-secondary border-opacity-10 pb-4">
                        <div class="col-md-8">
                            <label class="ios-label-sm">Select Sales Rep <span class="text-danger">*</span></label>
                            <select name="rep_id" id="repSelect" class="form-select fw-bold" style="background: #fff; font-size: 1.1rem; padding: 12px 14px;" required>
                                <option value="">-- Choose Rep --</option>
                                <?php foreach($reps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 text-end">
                            <button type="button" class="btn btn-outline-primary fw-bold" id="btnFetchStock" style="padding: 11px 20px; width: 100%;">
                                <i class="bi bi-cloud-download me-2"></i> Load Stock
                            </button>
                        </div>
                    </div>

                    <div id="auditContainer" class="d-none">
                        <h6 class="fw-bold mb-3" style="color: var(--ios-label); font-size: 0.95rem;">
                            <i class="bi bi-boxes me-1 text-primary"></i> Current Vehicle Stock
                        </h6>
                        
                        <div class="table-responsive rounded border mb-4">
                            <table class="ios-table text-center" style="margin: 0;">
                                <thead>
                                    <tr class="table-ios-header">
                                        <th class="text-start">Product</th>
                                        <th>Last Audit</th>
                                        <th style="color: #0055CC !important;">System Qty</th>
                                        <th style="color: #C07000 !important;">Physical Count</th>
                                        <th>Diff</th>
                                    </tr>
                                </thead>
                                <tbody id="auditTbody">
                                    <!-- Injected via AJAX -->
                                </tbody>
                            </table>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-warning px-4 fw-bold rounded-pill" style="padding: 12px 30px; color: #fff;">
                                <i class="bi bi-check-lg me-2"></i> Verify & Adjust Stock
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const repSelect = document.getElementById('repSelect');
    const btnFetchStock = document.getElementById('btnFetchStock');
    const auditContainer = document.getElementById('auditContainer');
    const auditTbody = document.getElementById('auditTbody');

    btnFetchStock.addEventListener('click', async function() {
        const repId = repSelect.value;
        if (!repId) {
            alert('Please select a Rep first.');
            return;
        }

        try {
            btnFetchStock.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Loading...';
            btnFetchStock.disabled = true;

            const formData = new FormData();
            formData.append('ajax_action', 'get_vehicle_stock');
            formData.append('rep_id', repId);

            const response = await fetch('vehicle_audit_ajax.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            auditTbody.innerHTML = '';
            
            if (data.length === 0) {
                auditTbody.innerHTML = '<tr><td colspan="5" class="text-muted py-4">No stock found in this vehicle. Please use the "Load Vehicle" page first.</td></tr>';
            } else {
                data.forEach(item => {
                    const lastAudit = item.last_audit_date ? new Date(item.last_audit_date).toLocaleDateString() : 'Never';
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="text-start">
                            <input type="hidden" name="product_id[]" value="${item.product_id}">
                            <input type="hidden" name="system_qty[]" value="${item.stock_qty}">
                            <strong>${item.name}</strong>
                            <div style="font-size: 0.75rem; color: #888;">SKU: ${item.sku}</div>
                        </td>
                        <td style="font-size: 0.8rem; color: #666;">${lastAudit}</td>
                        <td style="font-size: 1.1rem; font-weight: 700; color: #0055CC;">${item.stock_qty}</td>
                        <td>
                            <input type="number" name="actual_qty[]" class="ios-input text-center mx-auto actual-input" style="width: 80px; font-weight: bold; background: #FFFBEB;" value="${item.stock_qty}" required min="0">
                        </td>
                        <td class="diff-cell fw-bold">0</td>
                    `;
                    auditTbody.appendChild(tr);
                });

                // Attach event listeners for difference calculation
                document.querySelectorAll('.actual-input').forEach(input => {
                    input.addEventListener('input', function() {
                        const tr = this.closest('tr');
                        const sys = parseInt(tr.querySelector('input[name="system_qty[]"]').value);
                        const act = parseInt(this.value) || 0;
                        const diff = act - sys;
                        const diffCell = tr.querySelector('.diff-cell');
                        
                        diffCell.textContent = diff > 0 ? '+' + diff : diff;
                        
                        if (diff > 0) { diffCell.style.color = '#1A9A3A'; }
                        else if (diff < 0) { diffCell.style.color = '#CC2200'; }
                        else { diffCell.style.color = '#333'; }
                    });
                });
            }

            auditContainer.classList.remove('d-none');
        } catch (e) {
            console.error('Error fetching stock:', e);
            alert('Failed to fetch stock data.');
        } finally {
            btnFetchStock.innerHTML = '<i class="bi bi-cloud-download me-2"></i> Load Stock';
            btnFetchStock.disabled = false;
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
