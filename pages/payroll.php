<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

$message = '';

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        month VARCHAR(7) NOT NULL,
        days_worked DECIMAL(5,1) DEFAULT 0,
        basic_pay DECIMAL(10,2) DEFAULT 0.00,
        bonus DECIMAL(10,2) DEFAULT 0.00,
        deduction DECIMAL(10,2) DEFAULT 0.00,
        net_pay DECIMAL(10,2) DEFAULT 0.00,
        payment_method VARCHAR(50) DEFAULT 'Cash',
        status ENUM('pending', 'paid') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY emp_month (employee_id, month),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}
// -------------------------

// Handle selected month (default to current YYYY-MM)
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Auto-Generate / Recalculate Payroll for the month
    if ($_POST['action'] == 'generate_payroll') {
        $month = $_POST['target_month'];
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, month, days_worked, basic_pay, net_pay) VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE days_worked = VALUES(days_worked), basic_pay = VALUES(basic_pay), net_pay = basic_pay + bonus - deduction");
            
            // Fetch all active employees and calculate their days worked in this month
            $emps = $pdo->query("SELECT id, daily_rate FROM employees WHERE status='active'")->fetchAll();
            
            foreach ($emps as $e) {
                // Calculate days worked (Present = 1, Half Day = 0.5)
                $attStmt = $pdo->prepare("SELECT SUM(CASE WHEN status='present' THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END) FROM attendance WHERE employee_id=? AND DATE_FORMAT(work_date, '%Y-%m') = ?");
                $attStmt->execute([$e['id'], $month]);
                $days = (float)$attStmt->fetchColumn();
                
                $basic_pay = $days * $e['daily_rate'];
                
                $stmt->execute([$e['id'], $month, $days, $basic_pay, $basic_pay]);
            }
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Payroll generated & updated successfully based on attendance!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error generating payroll.</div>";
        }
    }

    // Save Manual Edits (Bonus/Deduction)
    if ($_POST['action'] == 'save_payroll_row') {
        $id = (int)$_POST['payroll_id'];
        $bonus = (float)$_POST['bonus'];
        $deduction = (float)$_POST['deduction'];
        
        $pdo->prepare("UPDATE payroll SET bonus=?, deduction=?, net_pay = (basic_pay + ? - ?) WHERE id=?")->execute([$bonus, $deduction, $bonus, $deduction, $id]);
        $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Payroll adjustments saved successfully!</div>";
    }

    // Mark as Paid
    if ($_POST['action'] == 'mark_paid') {
        $id = (int)$_POST['payroll_id'];
        $method = $_POST['payment_method']; // Cash or Bank Transfer
        
        try {
            $pdo->beginTransaction();
            
            $pStmt = $pdo->prepare("SELECT p.net_pay, p.month, e.name FROM payroll p JOIN employees e ON p.employee_id = e.id WHERE p.id = ? FOR UPDATE");
            $pStmt->execute([$id]);
            $pay = $pStmt->fetch();
            
            if($pay) {
                // Mark Paid
                $pdo->prepare("UPDATE payroll SET status='paid', payment_method=? WHERE id=?")->execute([$method, $id]);
                
                // Deduct from Company Finances
                $desc = "Salary: {$pay['name']} ({$pay['month']})";
                if ($method == 'Cash') {
                    $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$pay['net_pay']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$pay['net_pay'], $desc, $_SESSION['user_id']]);
                } else {
                    $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$pay['net_pay']]);
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$pay['net_pay'], $desc, $_SESSION['user_id']]);
                }
            }
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Salary marked as Paid and accounts updated!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error processing payment.</div>";
        }
    }
}

// --- FETCH DATA FOR SELECTED MONTH ---
$query = "
    SELECT p.*, e.emp_code, e.name, e.designation, e.daily_rate 
    FROM payroll p 
    JOIN employees e ON p.employee_id = e.id 
    WHERE p.month = ?
    ORDER BY e.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$selected_month]);
$payroll_data = $stmt->fetchAll();

// Calculate Metrics
$total_payroll = 0;
$total_paid = 0;
$total_pending = 0;

foreach($payroll_data as $row) {
    $total_payroll += $row['net_pay'];
    if($row['status'] == 'paid') {
        $total_paid += $row['net_pay'];
    } else {
        $total_pending += $row['net_pay'];
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

    /* Modal & Table Inputs */
    .ios-input, .form-select {
        background: #FFFFFF !important;
        border: 1px solid #C7C7CC !important;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.95rem;
        color: #000000 !important;
        width: 100%;
        outline: none;
        box-shadow: inset 0 1px 3px rgba(0,0,0,0.03);
        transition: border 0.2s;
    }
    .ios-input:focus, .form-select:focus { 
        border-color: var(--accent) !important; 
        box-shadow: 0 0 0 3px rgba(48,200,138,0.2) !important;
    }
    
    /* Table specific small input */
    .ios-input-sm {
        padding: 6px 10px;
        font-size: 0.85rem;
        border-radius: 8px;
        min-height: 32px;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Payroll & Salaries</h1>
        <div class="page-subtitle">Manage employee wages, attendance bonuses, and payouts.</div>
    </div>
</div>

<?php echo $message; ?>

<!-- Top Metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <div class="metrics-icon"><i class="bi bi-wallet2"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Payroll (<?php echo date('M Y', strtotime($selected_month . '-01')); ?>)</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($total_payroll, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <div class="metrics-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Cleared / Paid</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($total_paid, 2); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metrics-card" style="background: linear-gradient(145deg, #FF9500, #E07800);">
            <div class="metrics-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Pending Payables</div>
                <div style="font-size: 1.8rem; font-weight: 800; line-height: 1;">Rs <?php echo number_format($total_pending, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Top Controls -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <form method="GET" id="monthForm" class="d-flex align-items-center gap-2">
            <label class="ios-label-sm m-0"><i class="bi bi-calendar-month me-1"></i> Salary Month</label>
            <input type="month" name="month" class="ios-input fw-bold m-0" style="width: auto; min-height: 40px;" value="<?php echo htmlspecialchars($selected_month); ?>" onchange="document.getElementById('monthForm').submit();">
        </form>

        <form method="POST" class="m-0" onsubmit="return confirm('Generate/Recalculate payroll for this month based on Attendance data?');">
            <input type="hidden" name="action" value="generate_payroll">
            <input type="hidden" name="target_month" value="<?php echo htmlspecialchars($selected_month); ?>">
            <button type="submit" class="quick-btn px-4" style="background: #FF9500; color: #fff; min-height: 40px; box-shadow: 0 4px 14px rgba(255,149,0,0.3);">
                <i class="bi bi-arrow-clockwise"></i> Generate / Sync Payroll
            </button>
        </form>
    </div>
</div>

<div class="ios-alert mb-4" style="background: rgba(0,122,255,0.08); color: #0055CC; font-size: 0.85rem; border-radius: 12px; padding: 12px 16px;">
    <i class="bi bi-info-circle-fill me-2"></i> <strong>Basic Pay</strong> is automatically calculated as: (Days Worked × Daily Wage Rate) based on the Attendance register.
</div>

<!-- Payroll Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(48,200,138,0.1); color: var(--accent-dark);">
                <i class="bi bi-cash-stack"></i>
            </span>
            Monthly Payroll Ledger
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center align-middle" style="margin: 0;">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 25%;">Employee</th>
                    <th style="width: 10%;">Days Worked</th>
                    <th class="text-end" style="width: 12%;">Basic Pay</th>
                    <th class="text-center" style="width: 12%;">Bonus</th>
                    <th class="text-center" style="width: 12%;">Deduction</th>
                    <th class="text-end" style="width: 14%;">Net Salary</th>
                    <th style="width: 8%;">Status</th>
                    <th class="text-end pe-4" style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];
                foreach($payroll_data as $row): 
                    $words = explode(" ", $row['name']);
                    $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                    $color = $colors[$row['employee_id'] % count($colors)];
                ?>
                <tr>
                    <td class="text-start ps-4">
                        <div class="d-flex align-items-center">
                            <div class="contact-avatar-circle" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>; width: 38px; height: 38px; font-size: 0.95rem; margin-right: 12px;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                    <?php echo htmlspecialchars($row['emp_code']); ?> • Rs <?php echo number_format($row['daily_rate'], 2); ?>/day
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="font-weight: 800; font-size: 1rem; color: var(--ios-label);"><?php echo (float)$row['days_worked']; ?></span>
                    </td>
                    <td class="text-end">
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--ios-label-2);">
                            Rs <?php echo number_format($row['basic_pay'], 2); ?>
                        </div>
                    </td>
                    
                    <?php if($row['status'] == 'pending'): ?>
                        <!-- Form declaration outside td using HTML5 form attribute -->
                        <form id="save_payroll_<?php echo $row['id']; ?>" method="POST" class="d-none">
                            <input type="hidden" name="action" value="save_payroll_row">
                            <input type="hidden" name="payroll_id" value="<?php echo $row['id']; ?>">
                        </form>
                        
                        <td class="text-center">
                            <input type="number" step="0.01" name="bonus" form="save_payroll_<?php echo $row['id']; ?>" class="ios-input ios-input-sm text-center fw-bold" style="color: #1A9A3A;" value="<?php echo $row['bonus']; ?>">
                        </td>
                        <td class="text-center">
                            <input type="number" step="0.01" name="deduction" form="save_payroll_<?php echo $row['id']; ?>" class="ios-input ios-input-sm text-center fw-bold" style="color: #CC2200;" value="<?php echo $row['deduction']; ?>">
                        </td>
                        <td class="text-end">
                            <div style="font-weight: 800; font-size: 1.05rem; color: #0055CC;">
                                Rs <?php echo number_format($row['net_pay'], 2); ?>
                            </div>
                        </td>
                        <td>
                            <span class="ios-badge orange"><i class="bi bi-hourglass-split"></i> Pending</span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                <button type="submit" form="save_payroll_<?php echo $row['id']; ?>" class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="Save Adjustments">
                                    <i class="bi bi-save"></i>
                                </button>
                                <button type="button" class="quick-btn" style="background: rgba(52,199,89,0.15); color: #1A9A3A; padding: 6px 12px;" onclick="openPayModal(<?php echo $row['id']; ?>, <?php echo $row['net_pay']; ?>, '<?php echo addslashes($row['name']); ?>')" title="Mark as Paid">
                                    Pay <i class="bi bi-cash-coin ms-1"></i>
                                </button>
                            </div>
                        </td>
                    <?php else: ?>
                        <!-- Display mode if Paid -->
                        <td class="text-center">
                            <span style="font-weight: 700; color: #1A9A3A; font-size: 0.9rem;">+ <?php echo number_format($row['bonus'], 2); ?></span>
                        </td>
                        <td class="text-center">
                            <span style="font-weight: 700; color: #CC2200; font-size: 0.9rem;">- <?php echo number_format($row['deduction'], 2); ?></span>
                        </td>
                        <td class="text-end">
                            <div style="font-weight: 800; font-size: 1.05rem; color: #1A9A3A;">
                                Rs <?php echo number_format($row['net_pay'], 2); ?>
                            </div>
                        </td>
                        <td>
                            <span class="ios-badge green d-block mb-1"><i class="bi bi-check2-all"></i> Paid</span>
                            <span class="ios-badge gray outline" style="font-size: 0.6rem;"><?php echo $row['payment_method']; ?></span>
                        </td>
                        <td class="text-end pe-4">
                            <button class="quick-btn quick-btn-ghost disabled" style="padding: 6px 12px; color: var(--ios-label-3); cursor: not-allowed;">
                                <i class="bi bi-check-all me-1"></i> Cleared
                            </button>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($payroll_data)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state py-5">
                            <i class="bi bi-cash-stack" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No payroll data generated for this month.<br>Click 'Generate / Sync' above.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #1A9A3A;"><i class="bi bi-cash-coin me-2"></i>Process Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0 text-center">
                    <input type="hidden" name="action" value="mark_paid">
                    <input type="hidden" name="payroll_id" id="pay_payroll_id">
                    
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label-2); margin-bottom: 4px;" id="pay_emp_name">Employee Name</div>
                    <div class="fw-bold mb-4" style="font-size: 1.8rem; color: #1A9A3A; letter-spacing: -0.5px;" id="pay_amount_display">Rs 0.00</div>

                    <div class="text-start mb-4">
                        <label class="ios-label-sm">Deduct From Account</label>
                        <select name="payment_method" class="form-select fw-bold border-dark" required>
                            <option value="Cash">Cash on Hand</option>
                            <option value="Bank Transfer">Company Bank Account</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary w-100 mb-2" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn w-100" style="background: #1A9A3A; color: #fff;" onclick="return confirm('This will instantly deduct funds from your chosen Company Account. Proceed?');">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openPayModal(id, amount, name) {
    document.getElementById('pay_payroll_id').value = id;
    document.getElementById('pay_emp_name').textContent = name;
    document.getElementById('pay_amount_display').textContent = 'Rs ' + parseFloat(amount).toFixed(2);
    new bootstrap.Modal(document.getElementById('payModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>