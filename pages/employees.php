<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

$message = '';

// --- AUTO DB MIGRATION FOR HR MODULE ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        emp_code VARCHAR(20) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        designation VARCHAR(50),
        daily_rate DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) { error_log("HR Migration Error: " . $e->getMessage()); }
// ---------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'add_employee') {
        $emp_code = trim($_POST['emp_code']);
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $designation = trim($_POST['designation']);
        $daily_rate = (float)$_POST['daily_rate'];
        $status = $_POST['status'] ?? 'active';

        try {
            $stmt = $pdo->prepare("INSERT INTO employees (emp_code, name, phone, designation, daily_rate, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$emp_code, $name, $phone, $designation, $daily_rate, $status]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Employee added successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: Employee Code might already exist.</div>";
        }
    }
    
    if ($_POST['action'] == 'edit_employee') {
        $id = (int)$_POST['employee_id'];
        $emp_code = trim($_POST['emp_code']);
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $designation = trim($_POST['designation']);
        $daily_rate = (float)$_POST['daily_rate'];
        $status = $_POST['status'];

        try {
            $stmt = $pdo->prepare("UPDATE employees SET emp_code=?, name=?, phone=?, designation=?, daily_rate=?, status=? WHERE id=?");
            $stmt->execute([$emp_code, $name, $phone, $designation, $daily_rate, $status, $id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Employee updated successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if ($_POST['action'] == 'delete_employee') {
        $id = (int)$_POST['employee_id'];
        try {
            $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Employee deleted successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Cannot delete employee due to existing payroll or attendance records.</div>";
        }
    }
}

// --- FETCH DATA ---
$employees = $pdo->query("SELECT * FROM employees ORDER BY name ASC")->fetchAll();

// Calculate Metrics
$total_emps = count($employees);
$active_emps = 0;
$total_daily_wage = 0;
foreach($employees as $e) {
    if($e['status'] === 'active') {
        $active_emps++;
        $total_daily_wage += $e['daily_rate'];
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .contact-avatar-circle {
        width: 44px; height: 44px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 1.1rem;
        flex-shrink: 0; margin-right: 14px;
    }
    
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
    .metrics-icon {
        width: 54px; height: 54px;
        border-radius: 14px;
        background: rgba(255,255,255,0.2);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
    }

    /* Explicit Modal Inputs Visibility */
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
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Employee Management</h1>
        <div class="page-subtitle">Manage personnel, roles, and daily wage rates.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-person-plus-fill"></i> Add Employee
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Top Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <div class="metrics-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Employees</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $total_emps; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Registered</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <div class="metrics-icon"><i class="bi bi-person-check-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Active Workforce</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $active_emps; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Working</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Daily Wage (Active)</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($total_daily_wage, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Employees Table Card -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                <i class="bi bi-person-badge-fill"></i>
            </span>
            Personnel Directory
        </span>
        
        <!-- Live JS Search Filter -->
        <div class="ios-search-wrapper" style="max-width: 300px;">
            <i class="bi bi-search"></i>
            <input type="text" id="tableSearchInput" class="ios-input" style="min-height: 36px; padding: 6px 14px 6px 38px; font-size: 0.85rem;" placeholder="Search by Name, Role, or Code...">
        </div>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center" id="employeesTable">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 30%;">Employee Info</th>
                    <th style="width: 20%;">Contact</th>
                    <th style="width: 20%;">Daily Wage Rate</th>
                    <th style="width: 15%;">Status</th>
                    <th class="text-end pe-4" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];
                foreach($employees as $e): 
                    // Generate initials & color
                    $words = explode(" ", $e['name']);
                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    $color = $colors[$e['id'] % count($colors)];
                ?>
                <tr class="employee-row <?php echo $e['status'] == 'inactive' ? 'opacity-50' : ''; ?>">
                    <td class="text-start ps-4">
                        <div class="d-flex align-items-center">
                            <div class="contact-avatar-circle" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">
                                    <?php echo htmlspecialchars($e['name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                    <span class="fw-bold text-muted me-2"><?php echo htmlspecialchars($e['emp_code']); ?></span>
                                    <i class="bi bi-briefcase-fill me-1"></i><?php echo htmlspecialchars($e['designation']); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if($e['phone']): ?>
                            <span style="font-size: 0.9rem; font-weight: 500; color: var(--ios-label);">
                                <i class="bi bi-telephone-fill text-muted me-1"></i> <?php echo htmlspecialchars($e['phone']); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small fst-italic">No Phone</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-weight: 800; font-size: 1rem; color: #1A9A3A;">
                            Rs <?php echo number_format($e['daily_rate'], 2); ?>
                        </span>
                    </td>
                    <td>
                        <?php if($e['status'] == 'active'): ?>
                            <span class="ios-badge green"><i class="bi bi-check-circle-fill"></i> Active</span>
                        <?php else: ?>
                            <span class="ios-badge red"><i class="bi bi-x-circle-fill"></i> Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <!-- Edit Button -->
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 12px;" title="Edit Employee" 
                                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($e), ENT_QUOTES, 'UTF-8'); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this employee?');">
                                <input type="hidden" name="action" value="delete_employee">
                                <input type="hidden" name="employee_id" value="<?php echo $e['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($employees)): ?>
                <tr id="emptyRow">
                    <td colspan="5">
                        <div class="empty-state py-5">
                            <i class="bi bi-people" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No employees registered yet.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- Hidden row for JS search empty state -->
                <tr id="noResultsRow" class="d-none">
                    <td colspan="5">
                        <div class="empty-state py-4">
                            <p class="mt-2" style="font-weight: 500; color: var(--ios-label-3);">No matching employees found.</p>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>


<!-- ==================== MODALS ==================== -->

<!-- Add Employee Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-person-plus-fill text-primary me-2"></i>Add Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_employee">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Emp Code <span class="text-danger">*</span></label>
                            <input type="text" name="emp_code" class="ios-input fw-bold" placeholder="EMP-001" style="font-family: monospace;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Designation</label>
                            <input type="text" name="designation" class="ios-input" placeholder="e.g. Driver, Helper">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="ios-input fw-bold" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Phone Number</label>
                        <input type="text" name="phone" class="ios-input">
                    </div>
                    
                    <div class="mb-4">
                        <label class="ios-label-sm">Daily Wage Rate (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="daily_rate" class="ios-input fw-bold" style="color: #1A9A3A; font-size: 1.1rem;" required placeholder="0.00">
                        <small class="text-muted d-block mt-2" style="font-size: 0.75rem;"><i class="bi bi-info-circle-fill me-1"></i> Used to calculate payroll based on attendance.</small>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_employee">
                    <input type="hidden" name="employee_id" id="edit_id">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Emp Code <span class="text-danger">*</span></label>
                            <input type="text" name="emp_code" id="edit_code" class="ios-input fw-bold" style="font-family: monospace;" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Designation</label>
                            <input type="text" name="designation" id="edit_designation" class="ios-input">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="ios-input fw-bold" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Phone Number</label>
                        <input type="text" name="phone" id="edit_phone" class="ios-input">
                    </div>
                    
                    <div class="mb-4">
                        <label class="ios-label-sm">Daily Wage Rate (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="daily_rate" id="edit_rate" class="ios-input fw-bold" style="color: #1A9A3A; font-size: 1.1rem;" required>
                    </div>

                    <div class="mb-4">
                        <label class="ios-label-sm">Status</label>
                        <select name="status" id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #FF9500; color: #fff;">Update Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Explicit JS functions for Modals
function openAddModal() {
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function openEditModal(e) {
    document.getElementById('edit_id').value = e.id;
    document.getElementById('edit_code').value = e.emp_code;
    document.getElementById('edit_name').value = e.name;
    document.getElementById('edit_phone').value = e.phone;
    document.getElementById('edit_designation').value = e.designation;
    document.getElementById('edit_rate').value = e.daily_rate;
    document.getElementById('edit_status').value = e.status;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// Live Search Filter for Employees Table
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tableSearchInput');
    if(searchInput) {
        searchInput.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('.employee-row');
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