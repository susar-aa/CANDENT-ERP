<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
// Only Admins and Supervisors can manage categories
requireRole(['admin', 'supervisor']);

$message = '';

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS main_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}
// -------------------------

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ADD MAIN CATEGORY
    if ($_POST['action'] == 'add_main_category') {
        $name = trim($_POST['main_category_name']);
        
        if (!empty($name)) {
            // Check if category already exists
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM main_categories WHERE name = ?");
            $checkStmt->execute([$name]);
            if ($checkStmt->fetchColumn() > 0) {
                $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-exclamation-triangle-fill me-2'></i> A main category with this name already exists.</div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO main_categories (name) VALUES (?)");
                if ($stmt->execute([$name])) {
                    $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Main Category added successfully!</div>";
                } else {
                    $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error adding main category.</div>";
                }
            }
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-info-circle-fill me-2'></i> Main Category name is required.</div>";
        }
    }
    
    // EDIT MAIN CATEGORY
    if ($_POST['action'] == 'edit_main_category') {
        $category_id = (int)$_POST['main_category_id'];
        $name = trim($_POST['main_category_name']);
        
        if ($category_id && !empty($name)) {
            // Check for duplicates excluding current category
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM main_categories WHERE name = ? AND id != ?");
            $checkStmt->execute([$name, $category_id]);
            if ($checkStmt->fetchColumn() > 0) {
                $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Another main category with this name already exists.</div>";
            } else {
                $stmt = $pdo->prepare("UPDATE main_categories SET name = ? WHERE id = ?");
                if ($stmt->execute([$name, $category_id])) {
                    $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Main Category updated successfully!</div>";
                } else {
                    $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error updating main category.</div>";
                }
            }
        }
    }

    // DELETE MAIN CATEGORY
    if ($_POST['action'] == 'delete_main_category') {
        $category_id = (int)$_POST['main_category_id'];
        
        if ($category_id) {
            $stmt = $pdo->prepare("DELETE FROM main_categories WHERE id = ?");
            if ($stmt->execute([$category_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Main Category deleted successfully! Affected sub-categories are now unlinked.</div>";
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error deleting main category.</div>";
            }
        }
    }
}

// Fetch Main Categories along with the count of sub-categories in each
$query = "
    SELECT mc.*, COUNT(c.id) as sub_category_count 
    FROM main_categories mc 
    LEFT JOIN categories c ON mc.id = c.main_category_id 
    GROUP BY mc.id 
    ORDER BY mc.name ASC
";
$categories = [];
try {
    $categories = $pdo->query($query)->fetchAll();
} catch (PDOException $e) {
    // If the categories table doesn't have main_category_id yet, fallback query
    $categories = $pdo->query("SELECT *, 0 as sub_category_count FROM main_categories ORDER BY name ASC")->fetchAll();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Main Categories Management</h1>
        <div class="page-subtitle">Organize your sub-categories into larger groups.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i> Add Main Category
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
            All Main Categories
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
                    <th class="text-start ps-4" style="width: 40%;">Main Category Name</th>
                    <th style="width: 20%;">Sub Categories Count</th>
                    <th style="width: 20%;">Added On</th>
                    <th class="text-end pe-4" style="width: 20%;">Actions</th>
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
                        <?php if($c['sub_category_count'] > 0): ?>
                            <span class="ios-badge blue"><i class="bi bi-diagram-2 me-1"></i> <?php echo $c['sub_category_count']; ?> sub-categories</span>
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
                                onclick='openEditModal(<?php echo json_encode(["id" => $c['id'], "name" => $c['name']], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form with Verification -->
                            <form method="POST" class="d-inline" onsubmit="return confirmDelete(<?php echo $c['sub_category_count']; ?>);">
                                <input type="hidden" name="action" value="delete_main_category">
                                <input type="hidden" name="main_category_id" value="<?php echo $c['id']; ?>">
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
                            <p class="mt-2" style="font-weight: 500;">No main categories found. Click 'Add Main Category' to create one.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Hidden row for JS search empty state -->
                <tr id="noResultsRow" class="d-none">
                    <td colspan="4">
                        <div class="empty-state py-4">
                            <p class="mt-2" style="font-weight: 500; color: var(--ios-label-3);">No matching main categories found.</p>
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
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add Main Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_main_category">
                    <div class="mb-4">
                        <label class="ios-label-sm">Main Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="main_category_name" class="ios-input fw-bold" required placeholder="e.g., Beverages" autofocus>
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
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Main Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_main_category">
                    <input type="hidden" name="main_category_id" id="edit_category_id">
                    
                    <div class="mb-4">
                        <label class="ios-label-sm">Main Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="main_category_name" id="edit_category_name" class="ios-input fw-bold" required>
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
function openAddModal() {
    new bootstrap.Modal(document.getElementById('addCategoryModal')).show();
}

function openEditModal(data) {
    document.getElementById('edit_category_id').value = data.id;
    document.getElementById('edit_category_name').value = data.name;
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function confirmDelete(subCatCount) {
    if (subCatCount > 0) {
        return confirm("WARNING: This main category is linked to " + subCatCount + " sub-category/categories. If you delete it, those sub-categories will become unlinked. Are you absolutely sure you want to proceed?");
    } else {
        return confirm("Are you sure you want to delete this main category?");
    }
}

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

            const noResultsRow = document.getElementById('noResultsRow');
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
