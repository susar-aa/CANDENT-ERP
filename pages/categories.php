<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
// Only Admins and Supervisors can manage categories
requireRole(['admin', 'supervisor']);

$message = '';

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN profit_percentage DECIMAL(5,2) DEFAULT 0.00");
} catch(PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE categories ADD COLUMN main_category_id INT NULL");
    $pdo->exec("ALTER TABLE categories ADD CONSTRAINT fk_main_cat FOREIGN KEY (main_category_id) REFERENCES main_categories(id) ON DELETE SET NULL");
} catch(PDOException $e) {}
// -------------------------

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ADD CATEGORY
    if ($_POST['action'] == 'add_category') {
        $name = trim($_POST['category_name']);
        $profit = (float)($_POST['profit_percentage'] ?? 0);
        $main_category_id = !empty($_POST['main_category_id']) ? (int)$_POST['main_category_id'] : null;
        
        if (!empty($name)) {
            // Check if category already exists
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND (main_category_id = ? OR (main_category_id IS NULL AND ? IS NULL))");
            $checkStmt->execute([$name, $main_category_id, $main_category_id]);
            if ($checkStmt->fetchColumn() > 0) {
                $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-exclamation-triangle-fill me-2'></i> A category with this name already exists in this main category.</div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name, profit_percentage, main_category_id) VALUES (?, ?, ?)");
                if ($stmt->execute([$name, $profit, $main_category_id])) {
                    $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Category added successfully!</div>";
                } else {
                    $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error adding category.</div>";
                }
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-info-circle-fill me-2'></i> Category name is required.</div>";
        }
    }
    
    // EDIT CATEGORY
    if ($_POST['action'] == 'edit_category') {
        $category_id = (int)$_POST['category_id'];
        $name = trim($_POST['category_name']);
        $profit = (float)($_POST['profit_percentage'] ?? 0);
        $main_category_id = !empty($_POST['main_category_id']) ? (int)$_POST['main_category_id'] : null;
        
        if ($category_id && !empty($name)) {
            // Check for duplicates excluding current category
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND id != ? AND (main_category_id = ? OR (main_category_id IS NULL AND ? IS NULL))");
            $checkStmt->execute([$name, $category_id, $main_category_id, $main_category_id]);
            if ($checkStmt->fetchColumn() > 0) {
                $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Another category with this name already exists in this main category.</div>";
            } else {
                $stmt = $pdo->prepare("UPDATE categories SET name = ?, profit_percentage = ?, main_category_id = ? WHERE id = ?");
                if ($stmt->execute([$name, $profit, $main_category_id, $category_id])) {
                    $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Category updated successfully!</div>";
                } else {
                    $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error updating category.</div>";
                }
            }
        }
    }

    // DELETE CATEGORY
    if ($_POST['action'] == 'delete_category') {
        $category_id = (int)$_POST['category_id'];
        
        if ($category_id) {
            // The foreign key in products table has ON DELETE SET NULL, 
            // so deleting the category safely unlinks the products.
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            if ($stmt->execute([$category_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Category deleted successfully! Affected products are now uncategorized.</div>";
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting category.</div>";
            }
        }
    }
}

// Fetch Categories along with the count of products in each
$query = "
    SELECT c.*, mc.name as main_category_name, COUNT(p.id) as product_count 
    FROM categories c 
    LEFT JOIN main_categories mc ON c.main_category_id = mc.id
    LEFT JOIN products p ON c.id = p.category_id 
    GROUP BY c.id 
    ORDER BY c.name ASC
";
$categories = $pdo->query($query)->fetchAll();

// Fetch Main Categories for dropdowns
$main_categories = [];
try {
    $main_categories = $pdo->query("SELECT * FROM main_categories ORDER BY name ASC")->fetchAll();
} catch(PDOException $e) {}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Category Management</h1>
        <div class="page-subtitle">Organize and structure your product inventory.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i> Add Category
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Categories Table Card -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(48,200,138,0.1); color: var(--accent-dark);">
                <i class="bi bi-tags-fill"></i>
            </span>
            All Categories
        </span>
        
        <!-- Live JS Search Filter -->
        <div class="ios-search-wrapper" style="max-width: 250px;">
            <i class="bi bi-search"></i>
            <input type="text" id="tableSearchInput" class="ios-input" style="min-height: 36px; padding: 6px 14px 6px 38px; font-size: 0.85rem;" placeholder="Find category...">
        </div>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center" id="categoriesTable">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 25%;">Category Name</th>
                    <th style="width: 15%;">Main Category</th>
                    <th style="width: 15%;">Profit Margin</th>
                    <th style="width: 15%;">Products Count</th>
                    <th style="width: 15%;">Added On</th>
                    <th class="text-end pe-4" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($categories as $c): ?>
                <tr class="category-row">
                    <td class="text-start ps-4">
                        <div class="fw-bold" style="font-size: 1.05rem; color: var(--ios-label);">
                            <?php echo htmlspecialchars($c['name']); ?>
                        </div>
                    </td>
                    <td>
                        <?php if($c['main_category_id']): ?>
                            <span class="ios-badge" style="background: rgba(175,82,222,0.1); color: #AF52DE;">
                                <?php echo htmlspecialchars($c['main_category_name']); ?>
                            </span>
                        <?php else: ?>
                            <span class="ios-badge gray">None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="ios-badge" style="background: rgba(255,149,0,0.1); color: #C07000; font-weight: bold;">
                            <?php echo number_format((float)($c['profit_percentage'] ?? 0), 2); ?>%
                        </span>
                    </td>
                    <td>
                        <?php if($c['product_count'] > 0): ?>
                            <span class="ios-badge blue"><i class="bi bi-box-seam me-1"></i> <?php echo $c['product_count']; ?> products</span>
                        <?php else: ?>
                            <span class="ios-badge gray">Empty</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-size: 0.85rem; color: var(--ios-label-2); font-weight: 500;">
                            <?php echo date('M d, Y', strtotime($c['created_at'])); ?>
                        </span>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1">
                            <!-- Edit Button -->
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 12px;" title="Edit Category" 
                                onclick='openEditModal(<?php echo json_encode(["id" => $c['id'], "name" => $c['name'], "profit" => $c['profit_percentage'], "main_category_id" => $c['main_category_id']], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form with Verification -->
                            <form method="POST" class="d-inline" onsubmit="return confirmDelete(<?php echo $c['product_count']; ?>);">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($categories)): ?>
                <tr id="emptyRow">
                    <td colspan="4">
                        <div class="empty-state">
                            <i class="bi bi-tags" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No categories found. Click 'Add Category' to create one.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Hidden row for JS search empty state -->
                <tr id="noResultsRow" class="d-none">
                    <td colspan="4">
                        <div class="empty-state py-4">
                            <p class="mt-2" style="font-weight: 500; color: var(--ios-label-3);">No matching categories found.</p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_category">
                    <div class="mb-4">
                        <label class="ios-label-sm">Main Category</label>
                        <select name="main_category_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach($main_categories as $mc): ?>
                                <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" class="ios-input fw-bold" required placeholder="e.g., Sodas" autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Profit Percentage (%)</label>
                        <input type="number" name="profit_percentage" class="ios-input" step="0.01" min="0" max="100" placeholder="e.g., 20.00" value="0.00">
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary flex-grow-1">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_category">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    
                    <div class="mb-4">
                        <label class="ios-label-sm">Main Category</label>
                        <select name="main_category_id" id="edit_main_category_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach($main_categories as $mc): ?>
                                <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="category_name" id="edit_category_name" class="ios-input fw-bold" required>
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Profit Percentage (%)</label>
                        <input type="number" name="profit_percentage" id="edit_profit_percentage" class="ios-input" step="0.01" min="0" max="100">
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn flex-grow-1" style="background: #FF9500; color: #fff;">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript for Modals & Verification -->
<script>
// Explicit JS functions for Modals
function openAddModal() {
    new bootstrap.Modal(document.getElementById('addCategoryModal')).show();
}

function openEditModal(data) {
    document.getElementById('edit_category_id').value = data.id;
    document.getElementById('edit_category_name').value = data.name;
    document.getElementById('edit_profit_percentage').value = data.profit;
    document.getElementById('edit_main_category_id').value = data.main_category_id || '';
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

// Verification function for deletion
function confirmDelete(productCount) {
    if (productCount > 0) {
        return confirm("WARNING: This category contains " + productCount + " product(s). If you delete it, those products will lose their category association. Are you absolutely sure you want to proceed?");
    } else {
        return confirm("Are you sure you want to delete this category?");
    }
}

// Live Search Filter for Categories Table
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tableSearchInput');
    if(searchInput) {
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.category-row');
            let hasVisible = false;

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if(text.includes(filter)) {
                    row.style.display = '';
                    hasVisible = true;
                } else {
                    row.style.display = 'none';
                }
            });

            // Toggle No Results Message
            const noResultsRow = document.getElementById('noResultsRow');
            const emptyRow = document.getElementById('emptyRow');
            
            if(noResultsRow) {
                if(!hasVisible && rows.length > 0) {
                    noResultsRow.classList.remove('d-none');
                } else {
                    noResultsRow.classList.add('d-none');
                }
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>