<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin']); // Only Admins can manage users

$message = '';

// --- DEFINE THE PERMISSION MATRIX SYSTEM ---
$system_modules = [
    'Operations & Sales' => [
        'dashboard.php' => 'Main Dashboard',
        'sales_overview.php' => 'Sales Overview',
        'create_order.php' => 'Create Order (POS)',
        'online_orders.php' => 'E-Commerce Orders',
        'orders_list.php' => 'Sales History',
        'rep_targets.php' => 'Rep Targets'
    ],
    'Dispatch & Delivery' => [
        'dispatch.php' => 'Vehicle Dispatch',
        'routes.php' => 'Manage Routes',
        'route_sales.php' => 'Route Sales',
        'meter_readings.php' => 'Meter Readings'
    ],
    'CRM' => [
        'customers.php' => 'Customer Database'
    ],
    'Purchasing & GRN' => [
        'purchasing_overview.php' => 'Purchasing Overview',
        'purchase_orders.php' => 'Purchase Orders',
        'create_po.php' => 'Create PO',
        'create_grn.php' => 'Receive Goods (GRN)',
        'grn_list.php' => 'GRN History'
    ],
    'Inventory & Products' => [
        'setup_overview.php' => 'Catalogue Overview',
        'products.php' => 'Products List',
        'categories.php' => 'Categories',
        'suppliers.php' => 'Suppliers',
        'inventory.php' => 'Inventory Map',
        'stock_ledger.php' => 'Stock Ledger',
        'product_gallery.php' => 'Digital Catalog'
    ],
    'Company Finances' => [
        'finance_overview.php' => 'Finance Dashboard',
        'bank_cash.php' => 'Bank & Cash Ledgers',
        'cheques.php' => 'Manage Cheques',
        'expenses.php' => 'Company Expenses',
        'sales_returns.php' => 'Sales Returns',
        'pnl_report.php' => 'Profit & Loss'
    ],
    'HR & Team' => [
        'hr_overview.php' => 'HR Dashboard',
        'employees.php' => 'Employees',
        'attendance.php' => 'Attendance',
        'payroll.php' => 'Payroll & Salaries'
    ],
    'Marketing & Promos' => [
        'campaigns.php' => 'Email Campaigns',
        'promotions.php' => 'Promotions Engine'
    ],
    'Analytics & Reports' => [
        'reports.php' => 'Sales Analytics',
        'promo_reports.php' => 'Promo & FOC Report'
    ]
];

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // 1. ADD NEW USER
    if ($_POST['action'] == 'add_user') {
        $role = $_POST['role'];
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        $permissions = isset($_POST['permissions']) ? json_encode($_POST['permissions']) : json_encode([]);

        // Ensure email is unique
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->rowCount() > 0) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Email is already registered to another user!</div>";
        } else {
            if ($role === 'admin') {
                $name = trim($_POST['admin_name']);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, permissions) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $password, 'admin', json_encode([])]);
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-shield-check me-2'></i> Admin user created successfully!</div>";
            } else {
                $employee_id = (int)$_POST['employee_id'];
                
                // Fetch employee name
                $empStmt = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
                $empStmt->execute([$employee_id]);
                $name = $empStmt->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO users (employee_id, name, email, password, role, permissions) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employee_id, $name, $email, $password, $role, $permissions]);
                $new_user_id = $pdo->lastInsertId();

                // Link back to employee record
                $pdo->prepare("UPDATE employees SET user_id = ? WHERE id = ?")->execute([$new_user_id, $employee_id]);

                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> User account created and linked to employee successfully!</div>";
            }
        }
    }

    // 2. EDIT EXISTING USER
    if ($_POST['action'] == 'edit_user') {
        $id = (int)$_POST['user_id'];
        $role = $_POST['role'];
        $email = trim($_POST['email']);
        $password = $_POST['password']; // Raw password
        $permissions = isset($_POST['permissions']) ? json_encode($_POST['permissions']) : json_encode([]);

        // Check if new email is already taken by ANOTHER user
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $id]);
        if ($check->rowCount() > 0) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Email is already registered to another user!</div>";
        } else {
            $sql = "UPDATE users SET email = ?, role = ?, permissions = ?";
            $params = [$email, $role, $permissions];

            // If it's an independent admin, they can change the profile name
            if ($role === 'admin' && isset($_POST['admin_name'])) {
                $sql .= ", name = ?";
                $params[] = trim($_POST['admin_name']);
            }

            // Only update password if a new one was provided
            if (!empty($password)) {
                $sql .= ", password = ?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = ?";
            $params[] = $id;

            $pdo->prepare($sql)->execute($params);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> User profile updated successfully!</div>";
        }
    }

    // 3. DELETE USER
    if ($_POST['action'] == 'delete_user') {
        $id = (int)$_POST['user_id'];
        if ($id != $_SESSION['user_id']) { // Prevent self-deletion
            // Unlink from employee
            $pdo->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> User deleted successfully.</div>";
        } else {
            $message = "<div class='ios-alert' style='background: rgba(255,149,0,0.1); color: #C07000;'><i class='bi bi-info-circle-fill me-2'></i> You cannot delete your own active session account.</div>";
        }
    }
}

// --- FETCH DATA ---
$users = $pdo->query("
    SELECT u.*, e.emp_code, e.designation 
    FROM users u 
    LEFT JOIN employees e ON u.employee_id = e.id 
    ORDER BY u.role ASC, u.name ASC
")->fetchAll();

$employees = $pdo->query("SELECT id, name, emp_code FROM employees WHERE status = 'active' AND user_id IS NULL ORDER BY name ASC")->fetchAll();

// Metrics Calculation
$total_users = count($users);
$admin_count = 0;
$rep_count = 0;

foreach($users as $u) {
    if($u['role'] === 'admin') $admin_count++;
    if($u['role'] === 'rep') $rep_count++;
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
    
    /* iOS Setting Blocks (For Permission Matrix) */
    .ios-setting-group {
        background: #fff;
        border-radius: 12px;
        border: 1px solid var(--ios-separator);
        overflow: hidden;
        margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .ios-setting-header {
        background: var(--ios-surface-2);
        padding: 12px 16px;
        font-weight: 800;
        font-size: 0.85rem;
        color: var(--ios-label);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-setting-item {
        padding: 12px 16px;
        border-bottom: 1px solid var(--ios-separator);
        display: flex;
        align-items: center;
    }
    .ios-setting-item:last-child { border-bottom: none; }
    
    .form-check-input:checked {
        background-color: var(--accent);
        border-color: var(--accent);
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">System User Management</h1>
        <div class="page-subtitle">Configure software access, manage roles, and link employee accounts.</div>
    </div>
    <div>
        <button class="quick-btn px-3" style="background: #CC2200; color: #fff; box-shadow: 0 4px 14px rgba(255,59,48,0.3);" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill me-1"></i> Create User Account
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
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total System Users</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $total_users; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Accounts</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF3B30, #CC1500);">
            <div class="metrics-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Admin Accounts</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $admin_count; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Unrestricted</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-person-vcard-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Sales Rep Accounts</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;"><?php echo $rep_count; ?> <span style="font-size: 0.9rem; font-weight: 600; opacity: 0.8;">Active</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Users List -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(255,59,48,0.1); color: #CC2200;">
                <i class="bi bi-shield-check"></i>
            </span>
            Authorized System Users
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table align-middle" style="margin: 0;">
            <thead>
                <tr class="table-ios-header">
                    <th class="ps-4" style="width: 30%;">User Profile</th>
                    <th style="width: 15%;">System Role</th>
                    <th style="width: 20%;">Linked HR Record</th>
                    <th style="width: 15%;">Permissions</th>
                    <th class="text-end pe-4" style="width: 20%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];
                foreach($users as $u): 
                    $perms = !empty($u['permissions']) ? (json_decode($u['permissions'], true) ?: []) : [];
                    $permCount = count($perms);
                    
                    $words = explode(" ", $u['name']);
                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    $color = $colors[$u['id'] % count($colors)];
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center">
                            <div class="contact-avatar-circle" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                                    <?php echo htmlspecialchars($u['name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                    <i class="bi bi-envelope-fill me-1"></i><?php echo htmlspecialchars($u['email']); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if($u['role'] == 'admin'): ?>
                            <span class="ios-badge red outline"><i class="bi bi-star-fill"></i> Admin</span>
                        <?php elseif($u['role'] == 'rep'): ?>
                            <span class="ios-badge blue outline"><i class="bi bi-briefcase-fill"></i> Sales Rep</span>
                        <?php elseif($u['role'] == 'supervisor'): ?>
                            <span class="ios-badge purple outline"><i class="bi bi-eye-fill"></i> Supervisor</span>
                        <?php else: ?>
                            <span class="ios-badge gray outline"><?php echo htmlspecialchars(ucfirst($u['role'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($u['employee_id']): ?>
                            <span style="font-weight: 700; font-size: 0.85rem; color: #0055CC;"><i class="bi bi-person-badge me-1"></i><?php echo $u['emp_code']; ?></span>
                            <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;"><?php echo htmlspecialchars($u['designation']); ?></div>
                        <?php else: ?>
                            <span class="text-muted small fst-italic">Independent Account</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($u['role'] == 'admin'): ?>
                            <span style="font-weight: 700; font-size: 0.85rem; color: #CC2200;"><i class="bi bi-infinity me-1"></i> Absolute Access</span>
                        <?php else: ?>
                            <span class="ios-badge gray"><i class="bi bi-shield-lock-fill"></i> <?php echo $permCount; ?> Modules Granted</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <!-- View/Edit Button -->
                            <button type="button" class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="View / Edit Profile" onclick='openEditModal(<?php echo htmlspecialchars(json_encode([
                                "id" => $u['id'],
                                "name" => $u['name'],
                                "email" => $u['email'],
                                "role" => $u['role'],
                                "employee_id" => $u['employee_id'],
                                "emp_code" => $u['emp_code'] ?? '',
                                "permissions" => $perms
                            ]), ENT_QUOTES, 'UTF-8'); ?>)'>
                                <i class="bi bi-pencil-square" style="color: #007AFF;"></i>
                            </button>

                            <?php if($u['id'] != $_SESSION['user_id']): ?>
                                <!-- Delete Button -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this user account? (This does not delete the HR Employee record).');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="quick-btn quick-btn-ghost" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete User">
                                        <i class="bi bi-trash3-fill"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="ios-badge green outline" style="border: none; background: transparent;"><i class="bi bi-person-check-fill"></i> You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- Create User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" id="userCreationForm">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #CC2200;"><i class="bi bi-person-plus-fill me-2"></i>Configure New System User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div style="background: #FFFFFF; border: 1px solid var(--ios-separator); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="ios-label-sm">System Role <span class="text-danger">*</span></label>
                                <select name="role" id="roleSelect" class="form-select fw-bold" style="color: #CC2200; border-color: rgba(255,59,48,0.3) !important;" required>
                                    <option value="">-- Select Role --</option>
                                    <option value="admin">System Administrator</option>
                                    <option value="supervisor">Supervisor / Manager</option>
                                    <option value="accountant">Accountant / Finance</option>
                                    <option value="rep">Sales Representative</option>
                                    <option value="general">General Staff</option>
                                </select>
                            </div>
                            
                            <!-- Admin Specific Field -->
                            <div class="col-md-8 d-none" id="blockAdminName">
                                <label class="ios-label-sm">Admin Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="admin_name" id="admin_name" class="ios-input fw-bold" placeholder="e.g. John Doe">
                            </div>

                            <!-- HR Employee Linking Field -->
                            <div class="col-md-8 d-none" id="blockEmployeeLink">
                                <label class="ios-label-sm">Link to HR Employee Record <span class="text-danger">*</span></label>
                                <select name="employee_id" id="employeeSelect" class="form-select fw-bold">
                                    <option value="">-- Choose Employee without an account --</option>
                                    <?php foreach($employees as $e): ?>
                                        <option value="<?php echo $e['id']; ?>">
                                            <?php echo htmlspecialchars($e['name']); ?> (<?php echo $e['emp_code']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="font-size: 0.7rem; color: var(--ios-label-3); display: block; margin-top: 6px;"><i class="bi bi-info-circle-fill me-1"></i> The system will automatically use their HR name.</small>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-4 d-none" id="blockCredentials">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Login Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="userEmail" class="ios-input fw-bold" required placeholder="name@company.com">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Secure Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="userPassword" class="ios-input fw-bold" required minlength="6" placeholder="Minimum 6 characters">
                        </div>
                    </div>

                    <!-- Granular Permissions Matrix -->
                    <div id="blockPermissions" class="d-none">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3" style="border-color: var(--ios-separator) !important;">
                            <h6 class="fw-bold mb-0" style="color: #0055CC;"><i class="bi bi-ui-checks-grid me-2"></i>Granular Module Permissions</h6>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); font-weight: 600; text-transform: uppercase;">Tick sections to grant visibility</div>
                        </div>

                        <div class="row g-3">
                            <?php foreach($system_modules as $group_name => $pages): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="ios-setting-group">
                                    <div class="ios-setting-header">
                                        <span><?php echo $group_name; ?></span>
                                        <div class="form-check form-switch m-0" style="min-height: auto;">
                                            <input class="form-check-input group-select-all m-0" type="checkbox" data-group="<?php echo md5($group_name); ?>" title="Select All in Group">
                                        </div>
                                    </div>
                                    <div style="padding: 4px 0;">
                                        <?php foreach($pages as $filename => $label): ?>
                                        <div class="ios-setting-item">
                                            <div class="form-check w-100 d-flex align-items-center m-0">
                                                <input class="form-check-input perm-checkbox chk-<?php echo md5($group_name); ?> me-3 mt-0" type="checkbox" name="permissions[]" value="<?php echo $filename; ?>" id="chk_<?php echo md5($filename); ?>" style="width: 1.2rem; height: 1.2rem;">
                                                <label class="form-check-label fw-bold w-100" for="chk_<?php echo md5($filename); ?>" style="font-size: 0.85rem; color: var(--ios-label-2); cursor: pointer; padding-top: 2px;">
                                                    <?php echo $label; ?>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="blockAdminAlert" class="ios-alert d-none text-center d-flex flex-column align-items-center py-4 mt-3" style="background: rgba(255,59,48,0.08); color: #CC2200; border-radius: 16px;">
                        <i class="bi bi-infinity mb-2" style="font-size: 2.5rem;"></i>
                        <span style="font-weight: 700; font-size: 0.95rem;">System Administrators automatically bypass the permission matrix and receive full access to all modules, settings, and finances.</span>
                    </div>

                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-5" style="background: #CC2200; color: #fff;" id="btnSubmitUser" disabled>Create User Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View / Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" id="userEditForm">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #007AFF;"><i class="bi bi-pencil-square me-2"></i>View / Edit User Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div style="background: #FFFFFF; border: 1px solid var(--ios-separator); border-radius: 16px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="ios-label-sm">System Role <span class="text-danger">*</span></label>
                                <select name="role" id="edit_roleSelect" class="form-select fw-bold" style="color: #007AFF; border-color: rgba(0,122,255,0.3) !important;" required>
                                    <option value="admin">System Administrator</option>
                                    <option value="supervisor">Supervisor / Manager</option>
                                    <option value="accountant">Accountant / Finance</option>
                                    <option value="rep">Sales Representative</option>
                                    <option value="general">General Staff</option>
                                </select>
                            </div>
                            
                            <!-- Admin / Profile Name -->
                            <div class="col-md-8" id="edit_blockAdminName">
                                <label class="ios-label-sm">Profile Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="admin_name" id="edit_admin_name" class="ios-input fw-bold" required>
                            </div>

                            <!-- HR Employee Linking Display (Read-Only to prevent relationship breaks) -->
                            <div class="col-md-8 d-none" id="edit_blockEmployeeLink">
                                <label class="ios-label-sm">Linked HR Employee Record (Locked)</label>
                                <input type="text" id="edit_employee_display" class="ios-input fw-bold" style="background: var(--ios-bg); color: var(--ios-label-2);" readonly>
                                <small style="font-size: 0.7rem; color: var(--ios-label-3); display: block; margin-top: 6px;"><i class="bi bi-shield-lock-fill me-1"></i> Identity locked to HR record. You can change their role or permissions below.</small>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-4" id="edit_blockCredentials">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Login Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="edit_userEmail" class="ios-input fw-bold" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Update Password</label>
                            <input type="password" name="password" id="edit_userPassword" class="ios-input fw-bold" minlength="6" placeholder="Leave blank to keep current password">
                        </div>
                    </div>

                    <!-- Edit Permissions Matrix -->
                    <div id="edit_blockPermissions" class="d-none">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3" style="border-color: var(--ios-separator) !important;">
                            <h6 class="fw-bold mb-0" style="color: #0055CC;"><i class="bi bi-ui-checks-grid me-2"></i>Granular Module Permissions</h6>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); font-weight: 600; text-transform: uppercase;">Tick sections to grant visibility</div>
                        </div>

                        <div class="row g-3">
                            <?php foreach($system_modules as $group_name => $pages): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="ios-setting-group">
                                    <div class="ios-setting-header">
                                        <span><?php echo $group_name; ?></span>
                                        <div class="form-check form-switch m-0" style="min-height: auto;">
                                            <input class="form-check-input group-select-all m-0" type="checkbox" data-group="edit_<?php echo md5($group_name); ?>" title="Select All in Group">
                                        </div>
                                    </div>
                                    <div style="padding: 4px 0;">
                                        <?php foreach($pages as $filename => $label): ?>
                                        <div class="ios-setting-item">
                                            <div class="form-check w-100 d-flex align-items-center m-0">
                                                <input class="form-check-input edit-perm-checkbox chk-edit_<?php echo md5($group_name); ?> me-3 mt-0" type="checkbox" name="permissions[]" value="<?php echo $filename; ?>" id="chk_edit_<?php echo md5($filename); ?>" style="width: 1.2rem; height: 1.2rem;">
                                                <label class="form-check-label fw-bold w-100" for="chk_edit_<?php echo md5($filename); ?>" style="font-size: 0.85rem; color: var(--ios-label-2); cursor: pointer; padding-top: 2px;">
                                                    <?php echo $label; ?>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div id="edit_blockAdminAlert" class="ios-alert d-none text-center d-flex flex-column align-items-center py-4 mt-3" style="background: rgba(0,122,255,0.08); color: #007AFF; border-radius: 16px;">
                        <i class="bi bi-infinity mb-2" style="font-size: 2.5rem;"></i>
                        <span style="font-weight: 700; font-size: 0.95rem;">This user is a System Administrator. They automatically bypass the permission matrix and receive full access to all modules.</span>
                    </div>

                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-5" style="background: #007AFF; color: #fff;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- ADD MODAL LOGIC ---
    const roleSel = document.getElementById('roleSelect');
    const empSel = document.getElementById('employeeSelect');
    const emailInput = document.getElementById('userEmail');
    
    const bAdminName = document.getElementById('blockAdminName');
    const bEmpLink = document.getElementById('blockEmployeeLink');
    const bCreds = document.getElementById('blockCredentials');
    const bPerms = document.getElementById('blockPermissions');
    const bAdminAlert = document.getElementById('blockAdminAlert');
    const btnSubmit = document.getElementById('btnSubmitUser');

    roleSel.addEventListener('change', function() {
        const role = this.value;
        
        bCreds.classList.add('d-none');
        bPerms.classList.add('d-none');
        bAdminAlert.classList.add('d-none');
        bAdminName.classList.add('d-none');
        bEmpLink.classList.add('d-none');
        btnSubmit.disabled = true;

        document.getElementById('admin_name').required = false;
        empSel.required = false;

        if (role === '') return;

        bCreds.classList.remove('d-none');
        btnSubmit.disabled = false;

        if (role === 'admin') {
            bAdminName.classList.remove('d-none');
            document.getElementById('admin_name').required = true;
            bAdminAlert.classList.remove('d-none');
        } else {
            bEmpLink.classList.remove('d-none');
            empSel.required = true;
            bPerms.classList.remove('d-none');
        }
    });

    // --- EDIT MODAL LOGIC ---
    const editRoleSel = document.getElementById('edit_roleSelect');
    const eAdminName = document.getElementById('edit_blockAdminName');
    const eEmpLink = document.getElementById('edit_blockEmployeeLink');
    const ePerms = document.getElementById('edit_blockPermissions');
    const eAdminAlert = document.getElementById('edit_blockAdminAlert');

    editRoleSel.addEventListener('change', function() {
        const role = this.value;
        
        ePerms.classList.add('d-none');
        eAdminAlert.classList.add('d-none');

        if (role === 'admin') {
            eAdminAlert.classList.remove('d-none');
        } else {
            ePerms.classList.remove('d-none');
        }
    });

    // Handle "Select All" Ticks in Permission Matrices
    document.querySelectorAll('.group-select-all').forEach(headerCheck => {
        headerCheck.addEventListener('change', function() {
            const groupHash = this.dataset.group;
            const isChecked = this.checked;
            
            document.querySelectorAll('.chk-' + groupHash).forEach(childCheck => {
                childCheck.checked = isChecked;
            });
        });
    });

});

// Global function to trigger Edit Modal Population
function openEditModal(data) {
    // 1. Set ID and Core Info
    document.getElementById('edit_user_id').value = data.id;
    document.getElementById('edit_userEmail').value = data.email;
    document.getElementById('edit_roleSelect').value = data.role;
    
    const adminNameBlock = document.getElementById('edit_blockAdminName');
    const empLinkBlock = document.getElementById('edit_blockEmployeeLink');

    // 2. Handle Name / Employee Link visibility safely
    if (data.employee_id) {
        // Tied to an HR Record
        adminNameBlock.classList.add('d-none');
        document.getElementById('edit_admin_name').required = false;
        
        empLinkBlock.classList.remove('d-none');
        document.getElementById('edit_employee_display').value = data.name + ' (' + data.emp_code + ')';
    } else {
        // Independent Account (Admin)
        empLinkBlock.classList.add('d-none');
        adminNameBlock.classList.remove('d-none');
        document.getElementById('edit_admin_name').required = true;
        document.getElementById('edit_admin_name').value = data.name;
    }

    // 3. Clear Password Field (Optional to update)
    document.getElementById('edit_userPassword').value = '';

    // 4. Reset & Populate Permissions Matrix
    document.querySelectorAll('.edit-perm-checkbox').forEach(chk => chk.checked = false);

    if (data.permissions && data.permissions.length > 0) {
        data.permissions.forEach(perm => {
            const chk = document.querySelector(`.edit-perm-checkbox[value="${perm}"]`);
            if (chk) chk.checked = true;
        });
    }

    // 5. Trigger role change to show/hide the matrix vs admin alert
    document.getElementById('edit_roleSelect').dispatchEvent(new Event('change'));

    // 6. Open Modal
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>