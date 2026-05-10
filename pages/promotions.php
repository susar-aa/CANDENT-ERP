<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

// --- AUTO DB MIGRATION FOR PROMOTIONS WITH JSON TIERS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS promotions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        promo_type ENUM('percentage', 'foc'),
        target_category_id INT NULL,
        target_product_id INT NULL,
        tiers_config TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Fallback if table already exists without tiers_config
    $pdo->exec("ALTER TABLE promotions ADD COLUMN IF NOT EXISTS tiers_config TEXT NULL AFTER target_product_id");
} catch(PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE promotions ADD COLUMN target_main_category_id INT NULL AFTER promo_type");
} catch(PDOException $e) {}
// ----------------------------------------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Toggle Status
    if ($_POST['action'] == 'toggle_status') {
        $promo_id = (int)$_POST['promo_id'];
        $new_status = $_POST['status'] == 'active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE promotions SET status = ? WHERE id = ?")->execute([$new_status, $promo_id]);
        $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Promotion status updated!</div>";
    }

    // Add Promotion (With JSON Tiers)
    if ($_POST['action'] == 'add_promo') {
        $base_name = trim($_POST['name']);
        $promo_type = $_POST['promo_type'];
        $target_type = $_POST['target_type'];
        
        $target_main_category_id = ($target_type === 'main_category' && !empty($_POST['target_main_category_id'])) ? (int)$_POST['target_main_category_id'] : null;
        $target_category_id = ($target_type === 'category' && !empty($_POST['target_category_id'])) ? (int)$_POST['target_category_id'] : null;
        $target_product_id = ($target_type === 'product' && !empty($_POST['target_product_id'])) ? (int)$_POST['target_product_id'] : null;
        
        if (!$target_main_category_id && !$target_category_id && !$target_product_id) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> You must select a Target.</div>";
        } else {
            $tiers = [];

            if ($promo_type == 'percentage') {
                $min_amounts = $_POST['min_amount'] ?? [];
                $discount_percents = $_POST['discount_percent'] ?? [];

                for ($i = 0; $i < count($min_amounts); $i++) {
                    if ((float)$min_amounts[$i] > 0 && (float)$discount_percents[$i] > 0) {
                        $tiers[] = [
                            'min_amount' => (float)$min_amounts[$i],
                            'discount_percent' => (float)$discount_percents[$i]
                        ];
                    }
                }
            } elseif ($promo_type == 'foc') {
                $min_qtys = $_POST['min_qty'] ?? [];
                $free_qtys = $_POST['free_qty'] ?? [];
                $free_product_ids = $_POST['free_product_id'] ?? [];

                for ($i = 0; $i < count($min_qtys); $i++) {
                    if ((int)$min_qtys[$i] > 0 && (int)$free_qtys[$i] > 0 && !empty($free_product_ids[$i])) {
                        $tiers[] = [
                            'min_qty' => (int)$min_qtys[$i],
                            'free_qty' => (int)$free_qtys[$i],
                            'free_product_id' => (int)$free_product_ids[$i]
                        ];
                    }
                }
            }

            if (!empty($tiers)) {
                $tiers_config = json_encode($tiers);
                $stmt = $pdo->prepare("INSERT INTO promotions (name, promo_type, target_main_category_id, target_category_id, target_product_id, tiers_config, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$base_name, $promo_type, $target_main_category_id, $target_category_id, $target_product_id, $tiers_config]);
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Promotion Rule with " . count($tiers) . " tier(s) created successfully!</div>";
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Failed to create promotion. You must provide at least one valid condition and reward tier.</div>";
            }
        }
    }

    // Delete Promotion
    if ($_POST['action'] == 'delete_promo') {
        $promo_id = (int)$_POST['promo_id'];
        $pdo->prepare("DELETE FROM promotions WHERE id = ?")->execute([$promo_id]);
        $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Promotion rule deleted permanently.</div>";
    }
}

// Fetch Active Data
$main_categories = [];
try {
    $main_categories = $pdo->query("SELECT id, name FROM main_categories ORDER BY name ASC")->fetchAll();
} catch(PDOException $e) {}

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, sku FROM products WHERE status = 'available' ORDER BY name ASC")->fetchAll();

$promotions = $pdo->query("
    SELECT p.*, c.name as category_name, tp.name as target_product_name, mc.name as main_category_name
    FROM promotions p 
    LEFT JOIN categories c ON p.target_category_id = c.id 
    LEFT JOIN products tp ON p.target_product_id = tp.id 
    LEFT JOIN main_categories mc ON p.target_main_category_id = mc.id
    ORDER BY p.status ASC, p.created_at DESC
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .modal-body .ios-input, .modal-body .form-select {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: #000000 !important;
        width: 100%;
        outline: none;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.03) !important;
        transition: border 0.2s;
    }
    .modal-body .ios-input:focus, .modal-body .form-select:focus { 
        border-color: var(--accent) !important; 
        box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
    .modal-body .ios-label-sm { font-size: 0.75rem; font-weight: 600; color: var(--ios-label-2); margin-bottom: 6px; display: block; }
    
    /* Config Tiers Styling */
    .tier-row {
        background: #FFFFFF;
        border: 1px solid var(--ios-separator);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.02);
        position: relative;
    }
    .tier-row .ios-input, .tier-row .form-select {
        min-height: 38px !important;
        padding: 6px 12px !important;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Tiered Promotions Engine</h1>
        <div class="page-subtitle">Configure automated discount rules and Free of Charge (FOC) item rewards.</div>
    </div>
    <div>
        <button class="quick-btn px-3" style="background: #AF52DE; color: #fff; box-shadow: 0 4px 14px rgba(175,82,222,0.3);" data-bs-toggle="modal" data-bs-target="#addPromoModal">
            <i class="bi bi-magic me-1"></i> Create New Rule
        </button>
    </div>
</div>

<?php echo $message; ?>

<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(175,82,222,0.1); color: #AF52DE;">
                <i class="bi bi-tags-fill"></i>
            </span>
            Active Promotion Rules
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table align-middle" style="margin: 0;">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 25%;">Rule Collection Name</th>
                    <th style="width: 25%;">Target Item/Category</th>
                    <th style="width: 20%;">Reward Type</th>
                    <th style="width: 10%; text-align: center;">Tiers</th>
                    <th style="width: 10%; text-align: center;">Status</th>
                    <th class="text-end pe-4" style="width: 10%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($promotions as $p): 
                    $tiersArr = json_decode($p['tiers_config'], true) ?: [];
                    $numTiers = count($tiersArr);
                ?>
                <tr class="<?php echo $p['status'] == 'inactive' ? 'opacity-50' : ''; ?>" style="<?php echo $p['status'] == 'inactive' ? 'background: var(--ios-bg);' : ''; ?>">
                    <td class="text-start ps-4">
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($p['name']); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--ios-label);">
                            <?php if($p['target_product_id']): ?>
                                <i class="bi bi-box-seam me-1 text-primary"></i> Product: <?php echo htmlspecialchars($p['target_product_name']); ?>
                            <?php elseif($p['target_main_category_id']): ?>
                                <i class="bi bi-tags-fill me-1" style="color: #FF9500;"></i> Main Category: <?php echo htmlspecialchars($p['main_category_name']); ?>
                            <?php else: ?>
                                <i class="bi bi-tag me-1" style="color: #AF52DE;"></i> Sub Category: <?php echo htmlspecialchars($p['category_name']); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if($p['promo_type'] == 'percentage'): ?>
                            <span class="ios-badge blue outline"><i class="bi bi-percent"></i> Value Discount (%)</span>
                        <?php else: ?>
                            <span class="ios-badge purple outline"><i class="bi bi-gift-fill"></i> Free Items (FOC)</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-weight: 800; font-size: 0.95rem; color: var(--ios-label-2);"><?php echo $numTiers; ?></span>
                        <span style="font-size: 0.75rem; color: var(--ios-label-3);">Lvls</span>
                    </td>
                    <td style="text-align: center;">
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="promo_id" value="<?php echo $p['id']; ?>">
                            <input type="hidden" name="status" value="<?php echo $p['status']; ?>">
                            <button type="submit" class="ios-badge <?php echo $p['status'] == 'active' ? 'green' : 'gray'; ?>" style="border: none; cursor: pointer;">
                                <?php echo ucfirst($p['status']); ?>
                            </button>
                        </form>
                    </td>
                    <td class="text-end pe-4">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this entire promotion rule and all its tiers?');">
                            <input type="hidden" name="action" value="delete_promo">
                            <input type="hidden" name="promo_id" value="<?php echo $p['id']; ?>">
                            <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Rule">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($promotions)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-magic" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No promotion rules active. Create one to automate billing discounts!</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Tiered Promotion Modal -->
<div class="modal fade" id="addPromoModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #AF52DE;"><i class="bi bi-magic me-2"></i>Setup Tiered Promotion Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_promo">
                    
                    <!-- Basic Config Block -->
                    <div style="background: #FFFFFF; border: 1px solid var(--ios-separator); border-radius: 16px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="ios-label-sm">Rule Collection Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="ios-input fw-bold" required placeholder="e.g. Mega Drinks Promo">
                            </div>
                            <div class="col-md-4">
                                <label class="ios-label-sm">Rule Type <span class="text-danger">*</span></label>
                                <select name="promo_type" id="promoTypeSelect" class="form-select fw-bold" style="color: #AF52DE;" required>
                                    <option value="foc">Buy X Get Y Free (FOC)</option>
                                    <option value="percentage">Total Value Discount (%)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="ios-label-sm">Target Level <span class="text-danger">*</span></label>
                                <select name="target_type" id="targetTypeSelect" class="form-select fw-bold" required>
                                    <option value="main_category">Main Category Level</option>
                                    <option value="category">Sub Category Level</option>
                                    <option value="product">Specific Product</option>
                                </select>
                            </div>
                            
                            <!-- Toggle Blocks for Target -->
                            <div class="col-md-12 mt-3" id="targetMainCategoryBlock">
                                <label class="ios-label-sm">Target Main Category <span class="text-danger">*</span></label>
                                <select name="target_main_category_id" id="target_main_category_id" class="form-select" required>
                                    <option value="">-- Select Main Category --</option>
                                    <?php foreach($main_categories as $mc): ?>
                                        <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mt-3 d-none" id="targetCategoryBlock">
                                <label class="ios-label-sm">Target Sub Category <span class="text-danger">*</span></label>
                                <select name="target_category_id" id="target_category_id" class="form-select">
                                    <option value="">-- Select Sub Category --</option>
                                    <?php foreach($categories as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-12 mt-3 d-none" id="targetProductBlock">
                                <label class="ios-label-sm">Target Product <span class="text-danger">*</span></label>
                                <select name="target_product_id" id="target_product_id" class="form-select">
                                    <option value="">-- Select Product --</option>
                                    <?php foreach($products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['sku']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Logic Block: FOC Item Tiers -->
                    <div id="blockFOC" style="background: rgba(175,82,222,0.05); border: 1px solid rgba(175,82,222,0.2); border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3" style="border-color: rgba(175,82,222,0.2) !important;">
                            <h6 class="fw-bold mb-0" style="color: #AF52DE;"><i class="bi bi-gift-fill me-2"></i>Free Item Conditions (Tiers)</h6>
                            <button type="button" class="quick-btn" style="background: #AF52DE; color: #fff; padding: 6px 14px;" id="addTierFocBtn"><i class="bi bi-plus-lg me-1"></i> Add Tier</button>
                        </div>
                        
                        <div class="ios-alert mb-3" style="background: rgba(175,82,222,0.1); color: #8E30C0; font-size: 0.8rem; padding: 10px 14px; border-radius: 10px;">
                            <i class="bi bi-info-circle-fill me-1"></i> Reps will be automatically upgraded to the highest eligible tier based on their cart quantity.
                        </div>

                        <div id="tiersFocContainer">
                            <div class="tier-row tier-row-foc">
                                <div class="row g-2 align-items-center">
                                    <div class="col-auto"><span class="ios-label-sm m-0">If Qty >=</span></div>
                                    <div class="col-2"><input type="number" name="min_qty[]" class="ios-input text-center fw-bold" placeholder="e.g. 12"></div>
                                    
                                    <div class="col-auto"><span class="ios-label-sm m-0 ms-2">Give</span></div>
                                    <div class="col-2"><input type="number" name="free_qty[]" class="ios-input text-center fw-bold" style="color: #AF52DE; border-color: rgba(175,82,222,0.4) !important;" placeholder="Qty"></div>
                                    
                                    <div class="col-auto"><span class="ios-label-sm m-0 ms-2">FREE:</span></div>
                                    <div class="col">
                                        <select name="free_product_id[]" class="form-select fw-bold" style="color: #AF52DE; border-color: rgba(175,82,222,0.4) !important;">
                                            <option value="">-- Select Free Product --</option>
                                            <?php foreach($products as $p): ?>
                                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-auto ps-2">
                                        <button type="button" class="quick-btn remove-tier-btn" style="background: rgba(255,59,48,0.1); color: #CC2200; padding: 8px 12px; min-height: 38px;" title="Remove Tier"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Logic Block: Percentage Discount Tiers -->
                    <div id="blockPercentage" class="d-none" style="background: rgba(0,122,255,0.05); border: 1px solid rgba(0,122,255,0.2); border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3" style="border-color: rgba(0,122,255,0.2) !important;">
                            <h6 class="fw-bold mb-0" style="color: #0055CC;"><i class="bi bi-percent me-2"></i>Discount Conditions (Tiers)</h6>
                            <button type="button" class="quick-btn" style="background: #0055CC; color: #fff; padding: 6px 14px;" id="addTierPctBtn"><i class="bi bi-plus-lg me-1"></i> Add Tier</button>
                        </div>
                        
                        <div class="ios-alert mb-3" style="background: rgba(0,122,255,0.1); color: #0055CC; font-size: 0.8rem; padding: 10px 14px; border-radius: 10px;">
                            <i class="bi bi-info-circle-fill me-1"></i> Reps will automatically receive the highest eligible percentage discount based on their cart value.
                        </div>

                        <div id="tiersPctContainer">
                            <div class="tier-row tier-row-pct">
                                <div class="row g-2 align-items-center">
                                    <div class="col-auto"><span class="ios-label-sm m-0">If Amount >= Rs</span></div>
                                    <div class="col"><input type="number" step="0.01" name="min_amount[]" class="ios-input fw-bold" placeholder="e.g. 5000"></div>
                                    
                                    <div class="col-auto"><span class="ios-label-sm m-0 ms-3">Give Discount of</span></div>
                                    <div class="col"><input type="number" step="0.01" name="discount_percent[]" class="ios-input text-end fw-bold" style="color: #0055CC; border-color: rgba(0,122,255,0.4) !important;" placeholder="e.g. 10"></div>
                                    <div class="col-auto fw-bold" style="font-size: 1.2rem; color: #0055CC;">%</div>
                                    
                                    <div class="col-auto ps-3">
                                        <button type="button" class="quick-btn remove-tier-btn" style="background: rgba(255,59,48,0.1); color: #CC2200; padding: 8px 12px; min-height: 38px;" title="Remove Tier"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-5" style="background: #AF52DE; color: #fff;">Save & Activate Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('promoTypeSelect').addEventListener('change', function() {
    if (this.value === 'percentage') {
        document.getElementById('blockPercentage').classList.remove('d-none');
        document.getElementById('blockFOC').classList.add('d-none');
    } else {
        document.getElementById('blockPercentage').classList.add('d-none');
        document.getElementById('blockFOC').classList.remove('d-none');
    }
});

document.getElementById('targetTypeSelect').addEventListener('change', function() {
    const mainCatBlock = document.getElementById('targetMainCategoryBlock');
    const catBlock = document.getElementById('targetCategoryBlock');
    const prodBlock = document.getElementById('targetProductBlock');
    
    const mainCatSelect = document.getElementById('target_main_category_id');
    const catSelect = document.getElementById('target_category_id');
    const prodSelect = document.getElementById('target_product_id');

    mainCatBlock.classList.add('d-none');
    catBlock.classList.add('d-none');
    prodBlock.classList.add('d-none');
    
    mainCatSelect.required = false; mainCatSelect.value = '';
    catSelect.required = false; catSelect.value = '';
    prodSelect.required = false; prodSelect.value = '';

    if (this.value === 'main_category') {
        mainCatBlock.classList.remove('d-none');
        mainCatSelect.required = true;
    } else if (this.value === 'category') {
        catBlock.classList.remove('d-none');
        catSelect.required = true;
    } else {
        prodBlock.classList.remove('d-none');
        prodSelect.required = true;
    }
});

document.getElementById('targetTypeSelect').dispatchEvent(new Event('change'));

document.getElementById('addTierPctBtn').addEventListener('click', function() {
    const container = document.getElementById('tiersPctContainer');
    const newRow = container.querySelector('.tier-row-pct').cloneNode(true);
    newRow.querySelectorAll('input').forEach(input => input.value = '');
    container.appendChild(newRow);
});

document.getElementById('addTierFocBtn').addEventListener('click', function() {
    const container = document.getElementById('tiersFocContainer');
    const newRow = container.querySelector('.tier-row-foc').cloneNode(true);
    newRow.querySelectorAll('input').forEach(input => input.value = '');
    newRow.querySelector('select').value = '';
    container.appendChild(newRow);
});

document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-tier-btn')) {
        const btn = e.target.closest('.remove-tier-btn');
        const pctRows = document.querySelectorAll('.tier-row-pct');
        const focRows = document.querySelectorAll('.tier-row-foc');

        if (btn.closest('.tier-row-pct') && pctRows.length > 1) {
            btn.closest('.tier-row-pct').remove();
        } else if (btn.closest('.tier-row-foc') && focRows.length > 1) {
            btn.closest('.tier-row-foc').remove();
        } else {
            alert('You must have at least one tier condition.');
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>