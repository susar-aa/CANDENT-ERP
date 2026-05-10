<?php
// Enable error reporting for easier debugging of 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Accounts management restricted

$message = '';

// --- AUTO DB MIGRATION FOR FINANCE TABLES ---
// Placed here to ensure tables exist even if user visits this page directly
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_finances (
        id INT PRIMARY KEY,
        cash_on_hand DECIMAL(12,2) DEFAULT 0.00,
        bank_balance DECIMAL(12,2) DEFAULT 0.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("INSERT IGNORE INTO company_finances (id, cash_on_hand, bank_balance) VALUES (1, 0.00, 0.00)");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('cash_in', 'cash_out', 'bank_in', 'bank_out', 'transfer') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description VARCHAR(255),
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) { 
    error_log("Database Migration Error in bank_cash.php: " . $e->getMessage()); 
}
// ------------------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $amount = (float)$_POST['amount'];
    $desc = trim($_POST['description']);
    $user_id = $_SESSION['user_id'];

    if ($amount <= 0) {
        $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Amount must be greater than zero.</div>";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Lock the row to prevent race conditions during updates
            $fin = $pdo->query("SELECT * FROM company_finances WHERE id = 1 FOR UPDATE")->fetch();
            if (!$fin) {
                // Failsafe initialization
                $pdo->exec("INSERT INTO company_finances (id, cash_on_hand, bank_balance) VALUES (1, 0, 0)");
                $fin = ['cash_on_hand' => 0, 'bank_balance' => 0];
            }

            if ($_POST['action'] == 'deposit_to_bank') {
                if ($amount > $fin['cash_on_hand']) throw new Exception("Insufficient Cash on Hand for this deposit.");
                
                $pdo->exec("UPDATE company_finances SET cash_on_hand = cash_on_hand - $amount, bank_balance = bank_balance + $amount WHERE id = 1");
                $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('transfer', ?, ?, ?)")
                    ->execute([$amount, "Deposit to Bank: $desc", $user_id]);
                
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Cash successfully deposited to Bank!</div>";
            }
            elseif ($_POST['action'] == 'withdraw_from_bank') {
                if ($amount > $fin['bank_balance']) throw new Exception("Insufficient Bank Balance for this withdrawal.");
                
                $pdo->exec("UPDATE company_finances SET bank_balance = bank_balance - $amount, cash_on_hand = cash_on_hand + $amount WHERE id = 1");
                $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('transfer', ?, ?, ?)")
                    ->execute([$amount, "Withdrawal from Bank: $desc", $user_id]);
                
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Cash successfully withdrawn from Bank!</div>";
            }
            elseif ($_POST['action'] == 'manual_adjustment') {
                $account = $_POST['account']; // 'cash' or 'bank'
                $adj_type = $_POST['adj_type']; // 'in' or 'out'
                
                if ($account == 'cash') {
                    if ($adj_type == 'out' && $amount > $fin['cash_on_hand']) throw new Exception("Insufficient Cash for this outgoing adjustment.");
                    $sign = $adj_type == 'in' ? '+' : '-';
                    $log_type = $adj_type == 'in' ? 'cash_in' : 'cash_out';
                    
                    $pdo->exec("UPDATE company_finances SET cash_on_hand = cash_on_hand $sign $amount WHERE id = 1");
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES (?, ?, ?, ?)")
                        ->execute([$log_type, $amount, "Manual Cash Adj: $desc", $user_id]);
                } else {
                    if ($adj_type == 'out' && $amount > $fin['bank_balance']) throw new Exception("Insufficient Bank Balance for this outgoing adjustment.");
                    $sign = $adj_type == 'in' ? '+' : '-';
                    $log_type = $adj_type == 'in' ? 'bank_in' : 'bank_out';
                    
                    $pdo->exec("UPDATE company_finances SET bank_balance = bank_balance $sign $amount WHERE id = 1");
                    $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES (?, ?, ?, ?)")
                        ->execute([$log_type, $amount, "Manual Bank Adj: $desc", $user_id]);
                }
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Manual adjustment saved successfully!</div>";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// --- FETCH DATA (Wrapped in Try-Catch to prevent any isolated 500 crashes) ---
try {
    // Fetch Balances
    $finStmt = $pdo->query("SELECT cash_on_hand, bank_balance FROM company_finances WHERE id = 1");
    $balances = $finStmt->fetch() ?: ['cash_on_hand' => 0, 'bank_balance' => 0];

    // Pagination & Logs
    $limit = 15;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $totalRows = $pdo->query("SELECT COUNT(*) FROM finance_logs")->fetchColumn();
    $totalPages = ceil($totalRows / $limit);

    $logsQuery = "
        SELECT f.*, u.name as user_name 
        FROM finance_logs f 
        LEFT JOIN users u ON f.created_by = u.id 
        ORDER BY f.created_at DESC 
        LIMIT $limit OFFSET $offset
    ";
    $logs = $pdo->query($logsQuery)->fetchAll();
} catch (PDOException $e) {
    die("<div class='ios-alert m-4' style='background: rgba(255,59,48,0.1); color: #CC2200;'>Fatal Database Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .metrics-card {
        border-radius: 16px;
        padding: 24px;
        color: #fff;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        position: relative;
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .metrics-bg-icon {
        position: absolute;
        right: -20px;
        bottom: -20px;
        font-size: 8rem;
        opacity: 0.15;
        z-index: 1;
    }
    .metrics-content {
        position: relative;
        z-index: 2;
        flex: 1;
        display: flex;
        flex-direction: column;
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
        <h1 class="page-title">Bank & Cash Management</h1>
        <div class="page-subtitle">Track liquid assets, record deposits, and manage manual adjustments.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-secondary" data-bs-toggle="modal" data-bs-target="#manualAdjModal">
            <i class="bi bi-sliders"></i> Manual Adj.
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Main Account Balances -->
<div class="row g-3 mb-4">
    <!-- Cash on Hand -->
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158);">
            <i class="bi bi-cash-stack metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.9); margin-bottom: 4px;">
                    <i class="bi bi-wallet2 me-1"></i> Cash on Hand
                </div>
                <div style="font-size: 2.4rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 20px;">
                    Rs <?php echo number_format($balances['cash_on_hand'], 2); ?>
                </div>
                
                <div class="mt-auto pt-3 border-top" style="border-color: rgba(255,255,255,0.2) !important;">
                    <button class="quick-btn w-100" style="background: rgba(255,255,255,0.25); color: #fff; font-size: 0.95rem; padding: 12px;" data-bs-toggle="modal" data-bs-target="#depositModal">
                        <i class="bi bi-box-arrow-in-right"></i> Deposit to Bank
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bank Balance -->
    <div class="col-md-6">
        <div class="metrics-card" style="background: linear-gradient(145deg, #007AFF, #0055CC);">
            <i class="bi bi-bank metrics-bg-icon"></i>
            <div class="metrics-content">
                <div style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.9); margin-bottom: 4px;">
                    <i class="bi bi-building me-1"></i> Company Bank Account
                </div>
                <div style="font-size: 2.4rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 20px;">
                    Rs <?php echo number_format($balances['bank_balance'], 2); ?>
                </div>
                
                <div class="mt-auto pt-3 border-top" style="border-color: rgba(255,255,255,0.2) !important;">
                    <button class="quick-btn w-100" style="background: rgba(255,255,255,0.25); color: #fff; font-size: 0.95rem; padding: 12px;" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                        <i class="bi bi-cash"></i> Withdraw to Cash
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Transaction History -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(88,86,214,0.1); color: #5856D6;">
                <i class="bi bi-list-columns-reverse"></i>
            </span>
            Financial Transaction Ledger
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center" style="margin: 0;">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start ps-4" style="width: 15%;">Date & Time</th>
                    <th style="width: 15%;">Type</th>
                    <th class="text-start" style="width: 40%;">Description</th>
                    <th style="width: 15%;">Processed By</th>
                    <th class="text-end pe-4" style="width: 15%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td class="text-start ps-4">
                        <div style="font-weight: 700; font-size: 0.9rem; color: var(--ios-label);">
                            <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                            <?php echo date('h:i A', strtotime($log['created_at'])); ?>
                        </div>
                    </td>
                    <td>
                        <?php 
                            if($log['type'] == 'cash_in') echo '<span class="ios-badge green"><i class="bi bi-arrow-down-left"></i> Cash In</span>';
                            elseif($log['type'] == 'cash_out') echo '<span class="ios-badge red"><i class="bi bi-arrow-up-right"></i> Cash Out</span>';
                            elseif($log['type'] == 'bank_in') echo '<span class="ios-badge blue"><i class="bi bi-arrow-down-left"></i> Bank In</span>';
                            elseif($log['type'] == 'bank_out') echo '<span class="ios-badge orange"><i class="bi bi-arrow-up-right"></i> Bank Out</span>';
                            elseif($log['type'] == 'transfer') echo '<span class="ios-badge purple"><i class="bi bi-arrow-left-right"></i> Transfer</span>';
                        ?>
                    </td>
                    <td class="text-start">
                        <div style="font-weight: 500; font-size: 0.9rem; color: var(--ios-label-2); max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($log['description']); ?>">
                            <?php echo htmlspecialchars($log['description']); ?>
                        </div>
                    </td>
                    <td>
                        <span class="ios-badge gray outline"><i class="bi bi-person me-1"></i> <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></span>
                    </td>
                    <td class="text-end pe-4" style="font-weight: 800; font-size: 1rem; color: <?php echo in_array($log['type'], ['cash_in', 'bank_in']) ? '#1A9A3A' : (in_array($log['type'], ['cash_out', 'bank_out']) ? '#CC2200' : 'var(--ios-label)'); ?>;">
                        <?php echo in_array($log['type'], ['cash_out', 'bank_out']) ? '- ' : ''; ?>Rs <?php echo number_format($log['amount'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($logs)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-journal-x" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No financial transactions recorded yet.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<ul class="ios-pagination mb-4">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- ================= MODALS ================= -->

<!-- Deposit to Bank Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #1A9A3A;"><i class="bi bi-box-arrow-in-right me-2"></i>Deposit Cash to Bank</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="deposit_to_bank">
                    
                    <div class="ios-alert text-center mb-4" style="background: rgba(52,199,89,0.1); color: #1A9A3A; display: block;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Max Available to Deposit</div>
                        <div style="font-size: 1.4rem; font-weight: 800;">Rs <?php echo number_format($balances['cash_on_hand'], 2); ?></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Amount to Deposit (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="ios-input fw-bold text-success" style="font-size: 1.2rem; height: 48px;" max="<?php echo $balances['cash_on_hand']; ?>" required placeholder="0.00">
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Reference / Description <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="ios-input" required placeholder="e.g. Daily Cash Deposit - Slip #1234">
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Confirm Deposit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Withdraw from Bank Modal -->
<div class="modal fade" id="withdrawModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #0055CC;"><i class="bi bi-cash me-2"></i>Withdraw from Bank</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="withdraw_from_bank">
                    
                    <div class="ios-alert text-center mb-4" style="background: rgba(0,122,255,0.1); color: #0055CC; display: block;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Max Available to Withdraw</div>
                        <div style="font-size: 1.4rem; font-weight: 800;">Rs <?php echo number_format($balances['bank_balance'], 2); ?></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Amount to Withdraw (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="ios-input fw-bold text-primary" style="font-size: 1.2rem; height: 48px;" max="<?php echo $balances['bank_balance']; ?>" required placeholder="0.00">
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Reference / Description <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="ios-input" required placeholder="e.g. Petty Cash Withdrawal - ATM">
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #0055CC; color: #fff;">Confirm Withdrawal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manual Adjustment Modal -->
<div class="modal fade" id="manualAdjModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-sliders text-primary me-2"></i>Manual Financial Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="manual_adjustment">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="ios-label-sm">Target Account <span class="text-danger">*</span></label>
                            <select name="account" class="form-select fw-bold" required>
                                <option value="cash">Cash on Hand</option>
                                <option value="bank">Bank Account</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="ios-label-sm">Adjustment Type <span class="text-danger">*</span></label>
                            <select name="adj_type" class="form-select fw-bold" required>
                                <option value="in">Funds In (+)</option>
                                <option value="out">Funds Out / Expense (-)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Amount (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="ios-input fw-bold" style="font-size: 1.2rem; height: 48px;" required placeholder="0.00">
                    </div>
                    <div class="mb-4">
                        <label class="ios-label-sm">Reason / Description <span class="text-danger">*</span></label>
                        <input type="text" name="description" class="ios-input" required placeholder="e.g. Owner Investment, Office Expense, etc.">
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4" style="background: #1c1c1e; color: #fff;">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>