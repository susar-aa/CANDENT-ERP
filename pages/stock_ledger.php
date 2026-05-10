<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

$message = '';

// --- HANDLE MANUAL ADJUSTMENT ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'manual_adjustment') {
    $product_id = (int)$_POST['product_id'];
    $adjust_type = $_POST['adjust_type']; // 'add' or 'subtract'
    $qty = (int)$_POST['quantity'];
    $reason = trim($_POST['reason']);
    $user_id = $_SESSION['user_id'];

    if ($product_id && $qty > 0) {
        try {
            $pdo->beginTransaction();
            
            // Get current stock
            $prodStmt = $pdo->prepare("SELECT stock, name FROM products WHERE id = ? FOR UPDATE");
            $prodStmt->execute([$product_id]);
            $product = $prodStmt->fetch();

            if (!$product) throw new Exception("Product not found.");

            $current_stock = (int)$product['stock'];
            
            if ($adjust_type === 'subtract') {
                if ($qty > $current_stock) throw new Exception("Cannot subtract more than available stock.");
                $qty_change = -$qty;
                $new_stock = $current_stock - $qty;
            } else {
                $qty_change = $qty;
                $new_stock = $current_stock + $qty;
            }

            // Update Product
            $pdo->prepare("UPDATE products SET stock = ? WHERE id = ?")->execute([$new_stock, $product_id]);

            // Log Adjustment
            // Since manual adjustments don't have a specific Order/GRN ID, we pass NULL for reference_id
            $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'manual_adj', NULL, ?, ?, ?, ?)")
                ->execute([$product_id, $qty_change, $current_stock, $new_stock, $user_id]);

            $pdo->commit();
            $message = "<div class='alert alert-success'>Stock adjusted successfully for {$product['name']}!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='alert alert-danger'>" . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>Invalid quantity.</div>";
    }
}

// --- FILTERING & PAGINATION ---
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$product_filter = isset($_GET['product_id']) ? $_GET['product_id'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($product_filter !== '') {
    $whereClause .= " AND sl.product_id = ?";
    $params[] = $product_filter;
}
if ($type_filter !== '') {
    $whereClause .= " AND sl.type = ?";
    $params[] = $type_filter;
}

// Get Total Rows
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_logs sl $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Ledger Data
$query = "
    SELECT sl.*, p.name as product_name, p.sku, u.name as user_name 
    FROM stock_logs sl 
    JOIN products p ON sl.product_id = p.id 
    LEFT JOIN users u ON sl.created_by = u.id 
    $whereClause 
    ORDER BY sl.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch products for filtering and adjustment dropdown
$products = $pdo->query("SELECT id, name, sku, stock FROM products WHERE status = 'available' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Tom Select CSS -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2">Stock Ledger & Adjustments</h1>
    <button class="btn btn-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#adjModal">
        <i class="bi bi-sliders"></i> Manual Stock Adjustment
    </button>
</div>

<?php echo $message; ?>

<!-- Filters -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-body bg-light rounded">
        <form method="GET" action="" id="filterForm" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label small fw-bold">Filter by Product</label>
                <select name="product_id" id="filterProduct" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Products</option>
                    <?php foreach($products as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $product_filter == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?> (SKU: <?php echo $p['sku']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Movement Type</label>
                <select name="type" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Types</option>
                    <option value="grn_in" <?php echo $type_filter == 'grn_in' ? 'selected' : ''; ?>>Stock In (GRN)</option>
                    <option value="sale_out" <?php echo $type_filter == 'sale_out' ? 'selected' : ''; ?>>Stock Out (Sale)</option>
                    <option value="manual_adj" <?php echo $type_filter == 'manual_adj' ? 'selected' : ''; ?>>Manual Adjustment</option>
                    <option value="returned" <?php echo $type_filter == 'returned' ? 'selected' : ''; ?>>Returned/Reversed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-secondary w-100 mb-1"><i class="bi bi-funnel"></i> Filter</button>
            </div>
            <div class="col-md-2">
                <?php if($product_filter !== '' || $type_filter !== ''): ?>
                    <a href="stock_ledger.php" class="btn btn-outline-danger w-100 mb-1">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Ledger Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive bg-white rounded">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 15%;">Date & Time</th>
                    <th style="width: 30%;">Product</th>
                    <th style="width: 15%;">Movement Type</th>
                    <th style="width: 15%;">Reference</th>
                    <th class="text-center" style="width: 15%;">Stock Change</th>
                    <th class="text-end" style="width: 10%;">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td class="text-muted small">
                        <div class="fw-bold text-dark"><?php echo date('M d, Y', strtotime($log['created_at'])); ?></div>
                        <?php echo date('h:i A', strtotime($log['created_at'])); ?>
                    </td>
                    <td>
                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($log['product_name']); ?></div>
                        <div class="small text-muted"><i class="bi bi-upc-scan"></i> SKU: <?php echo htmlspecialchars($log['sku'] ?: 'N/A'); ?></div>
                    </td>
                    <td>
                        <?php 
                            if($log['type'] == 'grn_in') echo '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="bi bi-box-arrow-in-down"></i> GRN Intake</span>';
                            elseif($log['type'] == 'sale_out') echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="bi bi-cart-check"></i> Sale Out</span>';
                            elseif($log['type'] == 'returned') echo '<span class="badge bg-warning bg-opacity-10 text-dark border border-warning"><i class="bi bi-arrow-counterclockwise"></i> Reversed</span>';
                            else echo '<span class="badge bg-dark bg-opacity-10 text-dark border border-dark"><i class="bi bi-sliders"></i> Manual Adj</span>';
                        ?>
                    </td>
                    <td>
                        <?php if($log['reference_id']): ?>
                            <?php if($log['type'] == 'grn_in'): ?>
                                <a href="view_grn.php?id=<?php echo $log['reference_id']; ?>" target="_blank" class="text-decoration-none fw-bold text-muted">GRN #<?php echo str_pad($log['reference_id'], 6, '0', STR_PAD_LEFT); ?></a>
                            <?php elseif(in_array($log['type'], ['sale_out', 'returned'])): ?>
                                <a href="view_invoice.php?id=<?php echo $log['reference_id']; ?>" target="_blank" class="text-decoration-none fw-bold text-muted">INV #<?php echo str_pad($log['reference_id'], 6, '0', STR_PAD_LEFT); ?></a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small fst-italic">No Ref</span>
                        <?php endif; ?>
                        <div class="small text-muted mt-1"><i class="bi bi-person"></i> <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></div>
                    </td>
                    <td class="text-center">
                        <div class="fw-bold fs-5 <?php echo $log['qty_change'] > 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $log['qty_change'] > 0 ? '+' : ''; ?><?php echo $log['qty_change']; ?>
                        </div>
                        <div class="small text-muted"><?php echo $log['previous_stock']; ?> &rarr; <?php echo $log['new_stock']; ?></div>
                    </td>
                    <td class="text-end fw-bold fs-5 text-dark">
                        <?php echo $log['new_stock']; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($logs)): ?>
                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-journal-text fs-1 d-block mb-2"></i> No stock movements recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<nav>
    <ul class="pagination justify-content-center">
        <?php for($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $i; ?>&product_id=<?php echo $product_filter; ?>&type=<?php echo $type_filter; ?>"><?php echo $i; ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<!-- ================= MODALS ================= -->

<!-- Manual Adjustment Modal -->
<div class="modal fade" id="adjModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="bi bi-sliders"></i> Manual Stock Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="manual_adjustment">
                    
                    <div class="alert alert-warning py-2 small mb-4">
                        <i class="bi bi-exclamation-triangle"></i> Use this form to correct physical stock counts due to damages, theft, or counting errors. <strong>All adjustments are logged permanently.</strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="adjProductSelect" class="form-select" required>
                            <option value="">-- Search Product --</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" data-stock="<?php echo $p['stock']; ?>">
                                    <?php echo htmlspecialchars($p['name']); ?> (Cur: <?php echo $p['stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Adjustment Type <span class="text-danger">*</span></label>
                            <select name="adjust_type" id="adjType" class="form-select" required>
                                <option value="subtract">Subtract Stock (-)</option>
                                <option value="add">Add Stock (+)</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold">Quantity to Adjust <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" id="adjQty" class="form-control fw-bold" required min="1">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason / Notes</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g., Damaged item, Audit correction">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark fw-bold">Confirm Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tom Select JS -->
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new TomSelect('#filterProduct', { create: false, sortField: { field: "text", direction: "asc" } });
    
    let adjSelect = new TomSelect('#adjProductSelect', { create: false, sortField: { field: "text", direction: "asc" } });
    
    // Dynamic max quantity for subtract
    const adjType = document.getElementById('adjType');
    const adjQty = document.getElementById('adjQty');

    function updateMax() {
        if (adjType.value === 'subtract') {
            const selectedOption = adjSelect.options[adjSelect.items[0]];
            if (selectedOption) {
                const curStock = parseInt(selectedOption.dataset.stock);
                adjQty.max = curStock;
                adjQty.placeholder = `Max: ${curStock}`;
            }
        } else {
            adjQty.removeAttribute('max');
            adjQty.placeholder = '';
        }
    }

    adjType.addEventListener('change', updateMax);
    adjSelect.on('change', updateMax);
});
</script>

<?php include '../includes/footer.php'; ?>