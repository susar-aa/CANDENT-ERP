<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
// Only Admins and Supervisors can manage inventory
requireRole(['admin', 'supervisor']);

$message = '';

// Handle Add/Update Inventory Mapping
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_mapping') {
    $product_id = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
    $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $price = !empty($_POST['price']) ? (float)$_POST['price'] : 0;
    $stock = !empty($_POST['stock']) ? (int)$_POST['stock'] : 0;

    if ($product_id && $supplier_id && $price > 0) {
        try {
            // Using ON DUPLICATE KEY UPDATE allows us to just add stock or update price if the mapping already exists
            $stmt = $pdo->prepare("
                INSERT INTO product_suppliers (product_id, supplier_id, price, stock) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE price = VALUES(price), stock = stock + VALUES(stock)
            ");
            $stmt->execute([$product_id, $supplier_id, $price, $stock]);
            $message = "<div class='alert alert-success'>Inventory mapped successfully!</div>";
        } catch(PDOException $e) {
            $message = "<div class='alert alert-danger'>Database error occurred while saving.</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>Please select a product, supplier, and enter a valid price.</div>";
    }
}

// Fetch current inventory mappings
$inventory = $pdo->query("
    SELECT ps.*, p.name as product_name, s.company_name as supplier_name 
    FROM product_suppliers ps 
    JOIN products p ON ps.product_id = p.id 
    JOIN suppliers s ON ps.supplier_id = s.id 
    ORDER BY p.name ASC, s.company_name ASC
")->fetchAll();

// Fetch products and suppliers for the modal dropdowns
$products = $pdo->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, company_name FROM suppliers ORDER BY company_name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Inventory Mapping</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
        <i class="bi bi-link-45deg"></i> Map Product to Supplier
    </button>
</div>

<?php echo $message; ?>

<div class="table-responsive bg-white p-3 rounded shadow-sm">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th>Product</th>
                <th>Supplier</th>
                <th>Selling Price</th>
                <th>Available Stock</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($inventory as $item): ?>
            <tr>
                <td class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['supplier_name']); ?></span></td>
                <td class="text-success fw-bold">$<?php echo number_format($item['price'], 2); ?></td>
                <td>
                    <?php if($item['stock'] <= 5): ?>
                        <span class="badge bg-danger">Low: <?php echo $item['stock']; ?></span>
                    <?php else: ?>
                        <span class="badge bg-success"><?php echo $item['stock']; ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($inventory)): ?>
            <tr>
                <td colspan="5" class="text-center text-muted py-4">No inventory mapped yet. Click 'Map Product to Supplier' to begin.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Inventory Mapping Modal -->
<div class="modal fade" id="addInventoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Map Product & Add Stock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_mapping">
                    
                    <div class="mb-3">
                        <label class="form-label">Select Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Choose a Product --</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Supplier <span class="text-danger">*</span></label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">-- Choose a Supplier --</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Price per unit ($) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="price" class="form-control" required placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Add Stock Qty <span class="text-danger">*</span></label>
                            <input type="number" name="stock" class="form-control" required placeholder="e.g., 100">
                        </div>
                    </div>
                    <small class="text-muted"><i class="bi bi-info-circle"></i> If this mapping already exists, the price will be updated and the new stock will be added to the existing stock.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" <?php echo (empty($products) || empty($suppliers)) ? 'disabled' : ''; ?>>Save Mapping</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>