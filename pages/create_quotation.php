<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor', 'rep']);

// Fetch all available products
$stmt = $pdo->query("SELECT p.*, c.name as category_name 
                     FROM products p 
                     LEFT JOIN categories c ON p.category_id = c.id 
                     WHERE p.status = 'available' 
                     ORDER BY c.name, p.name ASC");
$products = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Create Quotation</h1>
        <div class="page-subtitle">Generate a custom product quotation.</div>
    </div>
</div>

<div class="dash-card">
    <div class="p-4">
        <form action="view_quotation.php" method="POST" target="_blank">
            
            <h5 class="fw-bold mb-3"><i class="bi bi-person-lines-fill text-primary me-2"></i> Client Details</h5>
            <div class="row g-3 mb-4 pb-4 border-bottom">
                <div class="col-md-6">
                    <label class="ios-label-sm">Prepared For (Supermarket/Shop Name) <span class="text-danger">*</span></label>
                    <input type="text" name="supermarket_name" class="ios-input" required placeholder="e.g. Keells Super">
                </div>
                <div class="col-md-6">
                    <label class="ios-label-sm">Contact Person (Manager Name)</label>
                    <input type="text" name="manager_name" class="ios-input" placeholder="e.g. Mr. John Doe">
                </div>
                <div class="col-md-6">
                    <label class="ios-label-sm">Date of Quotation</label>
                    <input type="date" name="quotation_date" class="ios-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="ios-label-sm">Valid Until (Auto 7 Days)</label>
                    <input type="date" name="valid_until" class="ios-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold m-0"><i class="bi bi-box-seam text-primary me-2"></i> Select Products</h5>
                <button type="button" class="quick-btn quick-btn-secondary btn-sm" id="selectAllBtn">Select All</button>
            </div>

            <div class="table-responsive mb-4 border rounded" style="max-height: 500px; overflow-y: auto;">
                <table class="ios-table mb-0">
                    <thead style="position: sticky; top: 0; background: #fff; z-index: 1;">
                        <tr class="table-ios-header">
                            <th style="width: 50px; text-align: center;">Inc</th>
                            <th style="width: 25%;">Product</th>
                            <th style="width: 15%;">Category</th>
                            <th style="width: 10%;">Cost (Rs)</th>
                            <th style="width: 10%;">MRP (Rs)</th>
                            <th style="width: 10%;">Pcs/Pack</th>
                            <th style="width: 25%;">Free Issue / Promo Text</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($products as $p): ?>
                        <tr>
                            <td style="text-align: center; vertical-align: middle;">
                                <input type="checkbox" name="selected_products[]" value="<?php echo $p['id']; ?>" class="form-check-input product-checkbox" style="width: 20px; height: 20px;">
                                <!-- Hidden inputs to pass data safely -->
                                <input type="hidden" name="product_name[<?php echo $p['id']; ?>]" value="<?php echo htmlspecialchars($p['name']); ?>">
                                <input type="hidden" name="product_cost[<?php echo $p['id']; ?>]" value="<?php echo $p['selling_price']; ?>">
                                <input type="hidden" name="product_mrp[<?php echo $p['id']; ?>]" value="<?php echo $p['mrp']; ?>">
                                <input type="hidden" name="product_pcs[<?php echo $p['id']; ?>]" value="<?php echo $p['pcs_per_pack']; ?>">
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 14px; color: var(--ios-label);"><?php echo htmlspecialchars($p['name']); ?></div>
                                <div style="font-size: 11px; color: var(--ios-label-3);">SKU: <?php echo htmlspecialchars($p['sku'] ?: 'N/A'); ?></div>
                            </td>
                            <td style="font-size: 13px; color: var(--ios-label-2);"><?php echo htmlspecialchars($p['category_name']); ?></td>
                            <td style="font-weight: 700; color: #1A9A3A;"><?php echo number_format($p['selling_price'], 2); ?></td>
                            <td style="font-weight: 700; color: #FF3B30;"><?php echo number_format($p['mrp'], 2); ?></td>
                            <td style="font-size: 13px; font-weight: 600; text-align: center;"><?php echo $p['pcs_per_pack']; ?></td>
                            <td>
                                <input type="text" name="promo_text[<?php echo $p['id']; ?>]" class="ios-input form-control-sm" placeholder="e.g. Buy 10 Get 1 Free" style="padding: 6px 10px; font-size: 13px; min-height: unset; border-radius: 6px;">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($products)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No available products found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-end">
                <button type="submit" class="quick-btn quick-btn-primary px-5 py-3" style="font-size: 16px;">
                    <i class="bi bi-file-earmark-pdf-fill me-2"></i> Generate Quotation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllBtn = document.getElementById('selectAllBtn');
    const checkboxes = document.querySelectorAll('.product-checkbox');
    let allSelected = false;

    selectAllBtn.addEventListener('click', function() {
        allSelected = !allSelected;
        checkboxes.forEach(cb => cb.checked = allSelected);
        this.innerText = allSelected ? 'Deselect All' : 'Select All';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
