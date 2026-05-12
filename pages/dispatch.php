<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// --- AJAX ENDPOINT FOR UNLOAD DATA & REPORTS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    
    if ($_POST['ajax_action'] == 'get_unload_data') {
        try {
            $asgStmt = $pdo->prepare("SELECT rep_id, assign_date, actual_cash as rep_declared_cash, 
                                            cash_5000, cash_2000, cash_1000, cash_500, cash_100, cash_50, cash_20, cash_coins 
                                     FROM rep_routes WHERE id = ?");
            $asgStmt->execute([$assignment_id]);
            $asg = $asgStmt->fetch();

            // 1. Fetch Stock Data
            $stmt = $pdo->prepare("
                SELECT 
                    p.id as product_id, p.name, p.sku, rl.loaded_qty,
                    (SELECT COALESCE(SUM(oi.quantity), 0) 
                     FROM order_items oi 
                     JOIN orders o ON oi.order_id = o.id 
                     WHERE o.assignment_id = ? AND oi.product_id = p.id
                    ) as sold_qty
                FROM route_loads rl
                JOIN products p ON rl.product_id = p.id
                WHERE rl.assignment_id = ?
            ");
            $stmt->execute([$assignment_id, $assignment_id]);
            $stock_data = $stmt->fetchAll();

            // 2. Fetch Comprehensive Sales Summary Breakdown
            $salesStmt = $pdo->prepare("
                SELECT 
                    COUNT(id) as bill_count,
                    SUM(total_amount) as total_sales,
                    SUM(paid_cash) as cash_sales,
                    SUM(paid_bank) as bank_sales,
                    SUM(paid_cheque) as cheque_sales,
                    SUM(CASE WHEN payment_status='pending' THEN (total_amount - paid_amount) ELSE 0 END) as credit_sales
                FROM orders WHERE assignment_id = ? AND total_amount > 0
            "); // Note: total_amount > 0 ignores Credit Notes (Returns) to keep Gross Sales pure
            $salesStmt->execute([$assignment_id]);
            $sales_summary = $salesStmt->fetch();

            // 3. Fetch Recorded Expenses
            $expStmt = $pdo->prepare("SELECT * FROM route_expenses WHERE assignment_id = ?");
            $expStmt->execute([$assignment_id]);
            $expenses = $expStmt->fetchAll();
            
            $total_expenses = 0;
            foreach($expenses as $exp) { $total_expenses += $exp['amount']; }

            // 4. Fetch Credit Collections
            $colStmt = $pdo->prepare("SELECT method, SUM(amount) as total FROM customer_payments WHERE assignment_id = ? GROUP BY method");
            $colStmt->execute([$assignment_id]);
            $collections = $colStmt->fetchAll();
            $credit_cash_collected = 0;
            $credit_cheque_collected = 0;
            foreach($collections as $col) {
                if ($col['method'] == 'Cash') $credit_cash_collected = (float)$col['total'];
                if ($col['method'] == 'Cheque') $credit_cheque_collected = (float)$col['total'];
            }

            // 4.1 Calculate Net Expected Cash Handover
            $expected_cash = max(0, (float)$sales_summary['cash_sales'] + $credit_cash_collected - $total_expenses);
            $expected_bank = (float)$sales_summary['bank_sales'];

            // 5. Fetch Received Cheques
            $chkStmt = $pdo->prepare("
                SELECT ch.bank_name, ch.cheque_number, ch.amount 
                FROM cheques ch JOIN orders o ON ch.order_id = o.id
                WHERE ch.type = 'incoming' AND o.assignment_id = ?
            ");
            $chkStmt->execute([$assignment_id]);
            $cheques = $chkStmt->fetchAll();

            // 6. Fetch Rep Returns to alert admin
            $retStmt = $pdo->prepare("
                SELECT p.name, sri.quantity, sri.condition_status
                FROM sales_return_items sri
                JOIN sales_returns sr ON sri.return_id = sr.id
                JOIN products p ON sri.product_id = p.id
                WHERE sr.assignment_id = ?
            ");
            $retStmt->execute([$assignment_id]);
            $customer_returns = $retStmt->fetchAll();

            echo json_encode([
                'success' => true, 
                'data' => $stock_data,
                'sales_summary' => $sales_summary,
                'expenses' => $expenses,
                'total_expenses' => $total_expenses,
                'credit_cash' => $credit_cash_collected,
                'credit_cheque' => $credit_cheque_collected,
                'expected_cash' => $expected_cash,
                'expected_bank' => $expected_bank,
                'rep_declared_cash' => $asg['rep_declared_cash'],
                'rep_cash_counts' => [
                    '5000' => $asg['cash_5000'],
                    '2000' => $asg['cash_2000'],
                    '1000' => $asg['cash_1000'],
                    '500' => $asg['cash_500'],
                    '100' => $asg['cash_100'],
                    '50' => $asg['cash_50'],
                    '20' => $asg['cash_20'],
                    'coins' => $asg['cash_coins']
                ],
                'cheques' => $cheques,
                'customer_returns' => $customer_returns
            ]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] == 'get_route_report') {
        try {
            $asgStmt = $pdo->prepare("SELECT rr.*, r.name as route_name, u.name as rep_name FROM rep_routes rr JOIN routes r ON rr.route_id = r.id JOIN users u ON rr.rep_id = u.id WHERE rr.id = ?");
            $asgStmt->execute([$assignment_id]);
            $asg = $asgStmt->fetch();

            $ordersStmt = $pdo->prepare("SELECT id, total_amount, payment_method, payment_status, created_at, paid_cash, paid_bank, paid_cheque FROM orders WHERE assignment_id = ?");
            $ordersStmt->execute([$assignment_id]);
            $orders = $ordersStmt->fetchAll();

            $shortagesStmt = $pdo->prepare("SELECT p.name, rl.short_qty FROM route_loads rl JOIN products p ON rl.product_id = p.id WHERE rl.assignment_id = ? AND rl.short_qty > 0");
            $shortagesStmt->execute([$assignment_id]);
            $shortages = $shortagesStmt->fetchAll();
            
            $expStmt = $pdo->prepare("SELECT * FROM route_expenses WHERE assignment_id = ?");
            $expStmt->execute([$assignment_id]);
            $expenses = $expStmt->fetchAll();

            echo json_encode(['success' => true, 'assignment' => $asg, 'orders' => $orders, 'shortages' => $shortages, 'expenses' => $expenses]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] == 'get_ai_stock_suggestion') {
        try {
            $route_id = (int)$_POST['route_id'];
            if (!$route_id) {
                echo json_encode(['success' => false, 'message' => 'Please select a route first.']);
                exit;
            }

            // 1. Find how many times this route was dispatched in the last 3 months
            $tripStmt = $pdo->prepare("SELECT COUNT(id) FROM rep_routes WHERE route_id = ? AND assign_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)");
            $tripStmt->execute([$route_id]);
            $total_trips = (int)$tripStmt->fetchColumn();

            if ($total_trips == 0) {
                $total_trips = 1; // avoid division by zero
            }

            // 2. Find total quantity sold for each product on this route over the last 3 months with monthly breakdown
            $salesStmt = $pdo->prepare("
                SELECT 
                    oi.product_id, 
                    p.name as product_name,
                    SUM(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN oi.quantity ELSE 0 END) as month_1,
                    SUM(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN oi.quantity ELSE 0 END) as month_2,
                    SUM(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND o.created_at < DATE_SUB(CURDATE(), INTERVAL 2 MONTH) THEN oi.quantity ELSE 0 END) as month_3,
                    SUM(oi.quantity) as total_sold
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN products p ON oi.product_id = p.id
                JOIN customers c ON o.customer_id = c.id
                WHERE c.route_id = ? 
                  AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                GROUP BY oi.product_id, p.name
                ORDER BY total_sold DESC
            ");
            $salesStmt->execute([$route_id]);
            $sales_data = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'sales_data' => $sales_data, 'trips_analyzed' => $total_trips]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
// -------------------------------------

$message = '';

// --- AUTO DB MIGRATION FOR ROUTES, DISPATCH, EXPENSES & LOADS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("ALTER TABLE employees ADD COLUMN user_id INT NULL UNIQUE AFTER id");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rep_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rep_id INT NOT NULL,
        driver_id INT NULL,
        route_id INT NOT NULL,
        assign_date DATE NOT NULL,
        status ENUM('assigned', 'accepted', 'rejected', 'completed', 'unloaded') DEFAULT 'assigned',
        start_meter DECIMAL(8,1) NULL,
        end_meter DECIMAL(8,1) NULL,
        expected_cash DECIMAL(12,2) NULL,
        actual_cash DECIMAL(12,2) NULL,
        expected_bank DECIMAL(12,2) NULL,
        actual_bank DECIMAL(12,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (rep_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES employees(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS route_loads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        assignment_id INT NOT NULL,
        product_id INT NOT NULL,
        loaded_qty INT NOT NULL,
        returned_qty INT NULL,
        short_qty INT NULL,
        FOREIGN KEY (assignment_id) REFERENCES rep_routes(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS route_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        assignment_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (assignment_id) REFERENCES rep_routes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch(PDOException $e) {}

try {
    // Drop the old unique key so reps can take multiple routes per day safely
    $pdo->exec("ALTER TABLE rep_routes DROP INDEX rep_date");
    
    // ALTER for existing tables safely
    $pdo->exec("ALTER TABLE rep_routes MODIFY COLUMN start_meter DECIMAL(8,1) NULL");
    $pdo->exec("ALTER TABLE rep_routes MODIFY COLUMN end_meter DECIMAL(8,1) NULL");
    $pdo->exec("ALTER TABLE rep_routes MODIFY COLUMN status ENUM('assigned', 'accepted', 'rejected', 'completed', 'unloaded') DEFAULT 'assigned'");
    
    $pdo->exec("ALTER TABLE route_loads ADD COLUMN returned_qty INT NULL");
    $pdo->exec("ALTER TABLE route_loads ADD COLUMN short_qty INT NULL");
    
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN expected_cash DECIMAL(12,2) NULL");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN actual_cash DECIMAL(12,2) NULL");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN expected_bank DECIMAL(12,2) NULL");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN actual_bank DECIMAL(12,2) NULL");
} catch(PDOException $e) {}
// ------------------------------------------------------

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Assign Route & Load Vehicle
    if ($_POST['action'] == 'assign_route') {
        $rep_id = (int)$_POST['rep_id'];
        $driver_id = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
        $route_id = (int)$_POST['route_id'];
        $assign_date = $_POST['assign_date'];

        try {
            $pdo->beginTransaction();

            // Insert as a completely new assignment every time to allow multiple routes per day
            $stmt = $pdo->prepare("INSERT INTO rep_routes (rep_id, driver_id, route_id, assign_date, status) VALUES (?, ?, ?, ?, 'assigned')");
            $stmt->execute([$rep_id, $driver_id, $route_id, $assign_date]);
            
            $assignment_id = $pdo->lastInsertId();

            // Stock loading is now handled via a separate process (Van Sales model)

            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Route dispatched and vehicle loaded successfully!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error assigning route: ".$e->getMessage()."</div>";
        }
    }

    // Unload Route & Log Shortages & Finances
    if ($_POST['action'] == 'unload_route') {
        $assignment_id = (int)$_POST['assignment_id'];
        $returned_qtys = $_POST['returned_qty'] ?? [];
        
        $expected_cash = (float)($_POST['expected_cash_val'] ?? 0);
        $actual_cash = (float)($_POST['actual_cash_total_input'] ?? 0);
        $expected_bank = (float)($_POST['expected_bank_val'] ?? 0);

        error_log("Route Settlement: Assignment ID $assignment_id, Expected Cash: $expected_cash, Actual Cash: $actual_cash, Expected Bank: $expected_bank");

        try {
            $pdo->beginTransaction();

            // Physical Stock verification is now handled via the weekly vehicle stock audit process

            // Update Status and Cash
            $pdo->prepare("UPDATE rep_routes SET status = 'unloaded', expected_cash = ?, actual_cash = ?, expected_bank = ? WHERE id = ?")->execute([$expected_cash, $actual_cash, $expected_bank, $assignment_id]);
            
            // FINALLY: Log to Ledger once verified on settlement
            if ($actual_cash > 0) {
                $stmt = $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand + ? WHERE id = 1");
                $stmt->execute([$actual_cash]);
                $affected = $stmt->rowCount();
                $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_in', ?, ?, ?)")->execute([$actual_cash, "Route Settlement Cash Collection - Assignment #$assignment_id", $_SESSION['user_id']]);
                error_log("Finance Update (Cash): Rows affected: " . ($affected ? 'Yes' : 'No'));
            }
            if ($expected_bank > 0) {
                $stmt = $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance + ? WHERE id = 1");
                $stmt->execute([$expected_bank]);
                $affected = $stmt->rowCount();
                $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_in', ?, ?, ?)")->execute([$expected_bank, "Route Settlement Bank Transfers - Assignment #$assignment_id", $_SESSION['user_id']]);
                error_log("Finance Update (Bank): Rows affected: " . ($affected > 0 ? 'Yes' : 'No'));
            }
            
            $pdo->commit();
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Route settled and financial records logged successfully!</div>";
        } catch(Exception $e) {
            $pdo->rollBack();
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error settling route: ".$e->getMessage()."</div>";
        }
    }

    // Delete Assignment
    if ($_POST['action'] == 'delete_assignment') {
        $id = (int)$_POST['assignment_id'];
        $pdo->prepare("DELETE FROM rep_routes WHERE id = ?")->execute([$id]);
        $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Dispatch assignment deleted successfully!</div>";
    }
}

// --- FILTERING & STATUS TABS ---
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';

$whereClause = "WHERE rr.assign_date >= CURDATE() - INTERVAL 14 DAY";
if ($status_filter === 'active') {
    $whereClause .= " AND rr.status IN ('assigned', 'accepted')";
} elseif ($status_filter === 'completed') {
    $whereClause .= " AND rr.status = 'completed'";
} elseif ($status_filter === 'unloaded') {
    $whereClause .= " AND rr.status = 'unloaded'";
}

// --- FETCH DATA ---
$routes = $pdo->query("SELECT * FROM routes ORDER BY name ASC")->fetchAll();
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();
$drivers = $pdo->query("SELECT id, name, emp_code FROM employees WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, sku, stock FROM products WHERE status = 'available' AND stock > 0 ORDER BY name ASC")->fetchAll();

$assignments = $pdo->query("
    SELECT rr.*, u.name as rep_name, r.name as route_name, e.name as driver_name,
           (SELECT SUM(loaded_qty) FROM route_loads WHERE assignment_id = rr.id) as total_loaded
    FROM rep_routes rr 
    JOIN users u ON rr.rep_id = u.id 
    JOIN routes r ON rr.route_id = r.id 
    LEFT JOIN employees e ON rr.driver_id = e.id
    $whereClause
    ORDER BY rr.assign_date DESC, u.name ASC
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* --- Specific Page Styles (Candent Theme) --- */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        margin-bottom: 24px;
    }
    .page-title { font-size: 1.8rem; font-weight: 700; letter-spacing: -0.8px; color: var(--ios-label); margin: 0; }
    .page-subtitle { font-size: 0.85rem; color: var(--ios-label-2); margin-top: 4px; }

    /* Segmented Control (Tabs Replacement) */
    .ios-segmented-control {
        display: inline-flex;
        background: rgba(118, 118, 128, 0.12);
        padding: 4px;
        border-radius: 12px;
        margin-bottom: 24px;
        width: auto;
    }
    .ios-segmented-control .nav-link {
        color: var(--ios-label);
        font-weight: 600;
        font-size: 0.85rem;
        padding: 8px 20px;
        border-radius: 8px;
        transition: all 0.2s;
        border: none;
        background: transparent;
    }
    .ios-segmented-control .nav-link:hover { color: var(--ios-label); opacity: 0.8; }
    .ios-segmented-control .nav-link.active {
        background: #fff;
        color: var(--ios-label);
        box-shadow: 0 3px 8px rgba(0,0,0,0.12), 0 1px 1px rgba(0,0,0,0.04);
    }

    /* iOS Inputs & Labels */
    .ios-input, .form-select {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.9rem;
        color: var(--ios-label);
        transition: all 0.2s ease;
        box-shadow: none;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }
    .ios-label-sm {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ios-label-2);
        margin-bottom: 6px;
        padding-left: 4px;
    }

    /* Custom Tables */
    .table-ios-header th {
        background: var(--ios-surface-2) !important;
        color: var(--ios-label-2) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        border-bottom: 1px solid var(--ios-separator);
        padding: 12px 16px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td { vertical-align: middle; padding: 12px 16px; border-bottom: 1px solid var(--ios-separator); font-size: 0.9rem;}
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }

    /* Badges */
    .ios-badge {
        font-size: 0.7rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        letter-spacing: 0.02em;
    }
    .ios-badge.green   { background: rgba(52,199,89,0.12); color: #1A9A3A; }
    .ios-badge.blue    { background: rgba(0,122,255,0.12); color: #0055CC; }
    .ios-badge.orange  { background: rgba(255,149,0,0.15); color: #C07000; }
    .ios-badge.red     { background: rgba(255,59,48,0.12); color: #CC2200; }
    .ios-badge.purple  { background: rgba(88,86,214,0.12); color: #5856D6; }
    .ios-badge.gray    { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }

    /* Modals */
    .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; }
    .modal-header { background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); padding: 18px 24px; }
    .modal-footer { border-top: 1px solid var(--ios-separator); padding: 16px 24px; background: var(--ios-surface); }
    
    /* Metrics Card (Report Modal) */
    .metrics-card {
        border-radius: 16px;
        padding: 16px 20px;
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Dispatch Management</h1>
        <div class="page-subtitle">Assign routes, load vehicles, and process end-of-day returns.</div>
    </div>
    <div>
        <button class="quick-btn quick-btn-primary" data-bs-toggle="modal" data-bs-target="#assignRouteModal">
            <i class="bi bi-truck"></i> Dispatch Vehicle
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Status Navigation Tabs (Segmented Control) -->
<div class="ios-segmented-control mb-4">
    <a class="nav-link <?php echo $status_filter == 'active' ? 'active' : ''; ?>" href="?status=active">
        <i class="bi bi-play-circle-fill me-1 text-primary"></i> Active
    </a>
    <a class="nav-link <?php echo $status_filter == 'completed' ? 'active' : ''; ?>" href="?status=completed">
        <i class="bi bi-flag-fill me-1" style="color: #FF9500;"></i> Pending Unload
    </a>
    <a class="nav-link <?php echo $status_filter == 'unloaded' ? 'active' : ''; ?>" href="?status=unloaded">
        <i class="bi bi-check-circle-fill me-1 text-success"></i> Unloaded
    </a>
    <a class="nav-link <?php echo $status_filter == 'all' ? 'active' : ''; ?>" href="?status=all">
        <i class="bi bi-collection-fill me-1 text-secondary"></i> All
    </a>
</div>

<div class="row g-4">
    <!-- Active Assignments Table -->
    <div class="col-lg-8 mb-4">
        <div class="dash-card h-100 overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-card-list"></i>
                    </span>
                    Route Assignments List
                </span>
            </div>
            <div class="table-responsive">
                <table class="ios-table">
                    <thead>
                        <tr class="table-ios-header">
                            <th style="width: 15%;">Date</th>
                            <th style="width: 25%;">Rep & Driver</th>
                            <th style="width: 25%;">Route</th>
                            <th style="width: 20%;">Status / Stock</th>
                            <th style="width: 15%; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($assignments as $a): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; font-size: 0.9rem; color: <?php echo $a['assign_date'] == date('Y-m-d') ? '#0055CC' : 'var(--ios-label)'; ?>;">
                                    <?php echo date('M d, Y', strtotime($a['assign_date'])); ?>
                                </div>
                                <?php if($a['assign_date'] == date('Y-m-d')) echo "<div style='font-size: 0.7rem; color: #0055CC; font-weight: 700; margin-top: 2px;'>TODAY</div>"; ?>
                            </td>
                            <td>
                                <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                                    <i class="bi bi-person-badge text-muted me-1"></i><?php echo htmlspecialchars($a['rep_name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                    <i class="bi bi-person me-1"></i>Drv: <?php echo htmlspecialchars($a['driver_name'] ?: 'None'); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 0.9rem; color: var(--ios-label);">
                                    <?php echo htmlspecialchars($a['route_name']); ?>
                                </div>
                            </td>
                            <td>
                                <?php if($a['status'] == 'assigned'): ?>
                                    <span class="ios-badge orange d-block mb-1"><i class="bi bi-hourglass-split"></i> Assigned</span>
                                <?php elseif($a['status'] == 'accepted'): ?>
                                    <span class="ios-badge blue d-block mb-1"><i class="bi bi-play-circle-fill"></i> On Route</span>
                                <?php elseif($a['status'] == 'completed'): ?>
                                    <span class="ios-badge purple d-block mb-1"><i class="bi bi-flag-fill"></i> Returned</span>
                                <?php elseif($a['status'] == 'unloaded'): ?>
                                    <span class="ios-badge green d-block mb-1"><i class="bi bi-check-all"></i> Settled</span>
                                <?php elseif($a['status'] == 'rejected'): ?>
                                    <span class="ios-badge red d-block mb-1"><i class="bi bi-x-circle-fill"></i> Rejected</span>
                                <?php endif; ?>
                                <div style="font-size: 0.75rem; color: var(--ios-label-2); font-weight: 600; margin-top: 4px;">
                                    <i class="bi bi-box-seam me-1"></i>Items: <?php echo $a['total_loaded'] ?: 0; ?>
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <div class="d-flex justify-content-end gap-1 flex-wrap">
                                    <?php if($a['status'] == 'completed'): ?>
                                        <button class="quick-btn quick-btn-primary" onclick="openUnloadModal(<?php echo $a['id']; ?>)" title="Settle Route & Verify Collections">
                                            Settle <i class="bi bi-check-all"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if(in_array($a['status'], ['completed', 'unloaded'])): ?>
                                        <button class="quick-btn quick-btn-secondary" onclick="openReportModal(<?php echo $a['id']; ?>)" title="View Route Report">
                                            Report <i class="bi bi-file-earmark-bar-graph"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this dispatch assignment?');">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                        <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete Assignment">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($assignments)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="bi bi-folder2-open" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                                    <p class="mt-2" style="font-weight: 500;">No dispatches found in this category.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Routes Master List -->
    <div class="col-lg-4 mb-4">
        <div class="dash-card h-100 overflow-hidden">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(48,176,199,0.1); color: #30B0C7;">
                        <i class="bi bi-map-fill"></i>
                    </span>
                    Predefined Routes
                </span>
                <a href="routes.php" class="quick-btn quick-btn-ghost" style="padding: 6px 12px; font-size: 0.75rem;">Manage</a>
            </div>
            <ul class="list-group list-group-flush" style="border: none;">
                <?php foreach($routes as $r): ?>
                <li class="list-group-item py-3" style="border-color: var(--ios-separator);">
                    <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);"><?php echo htmlspecialchars($r['name']); ?></div>
                    <div style="font-size: 0.8rem; color: var(--ios-label-2); margin-top: 2px; line-height: 1.4;"><?php echo htmlspecialchars($r['description'] ?: 'No description provided.'); ?></div>
                </li>
                <?php endforeach; ?>
                <?php if(empty($routes)): ?>
                <li class="list-group-item text-center py-4" style="color: var(--ios-label-3); border: none;">No routes created yet.</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Assign Route & Load Vehicle Modal -->
<div class="modal fade" id="assignRouteModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700;">
                        <i class="bi bi-truck text-primary me-2"></i>Dispatch & Load Vehicle
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="background: var(--ios-bg);">
                    <input type="hidden" name="action" value="assign_route">
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="ios-label-sm">Date <span class="text-danger">*</span></label>
                            <input type="date" name="assign_date" class="ios-input fw-bold text-primary" style="background: #fff;" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="ios-label-sm">Select Route <span class="text-danger">*</span></label>
                            <select name="route_id" class="form-select fw-bold" style="background: #fff;" required>
                                <option value="">-- Choose Route --</option>
                                <?php foreach($routes as $route): ?>
                                    <option value="<?php echo $route['id']; ?>"><?php echo htmlspecialchars($route['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-4 pb-4 border-bottom border-secondary border-opacity-10">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Sales Rep <span class="text-danger">*</span></label>
                            <select name="rep_id" class="form-select fw-bold" style="background: #fff;" required>
                                <option value="">-- Choose Rep --</option>
                                <?php foreach($reps as $rep): ?>
                                    <option value="<?php echo $rep['id']; ?>"><?php echo htmlspecialchars($rep['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Driver (Optional)</label>
                            <select name="driver_id" class="form-select" style="background: #fff;">
                                <option value="">-- No Driver (Self Driven) --</option>
                                <?php foreach($drivers as $drv): ?>
                                    <option value="<?php echo $drv['id']; ?>"><?php echo htmlspecialchars($drv['name']); ?> (<?php echo htmlspecialchars($drv['emp_code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Stock loading is now handled via the dedicated Van Sales Vehicle Loading process -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Dispatch Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ADVANCED Route Settlement Modal -->
<div class="modal fade" id="unloadModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header" style="background: var(--accent); border-bottom: none;">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-check-all me-2"></i>Route Settlement & Finalization</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light" style="padding: 24px;">
                    <input type="hidden" name="action" value="unload_route">
                    <input type="hidden" name="assignment_id" id="unload_assignment_id">
                    
                    <div class="row g-4">
                        <!-- Left Col: Stock Verification & Financial Summary -->
                        <div class="col-lg-7" style="border-right: 1px solid var(--ios-separator);">
                            
                            <!-- Customer Returns Alert -->
                            <div id="unload_returns_container" class="d-none ios-alert mb-4" style="background: rgba(255,59,48,0.1); border-radius: 12px; padding: 16px;">
                                <h6 class="fw-bold" style="color: #CC2200; margin-bottom: 6px;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Customer Returns in Vehicle</h6>
                                <p style="font-size: 0.8rem; color: #CC2200; margin-bottom: 12px; opacity: 0.9;">The rep accepted the following returns. Please collect these physical items from the vehicle.</p>
                                <div id="unload_returns_list" style="font-size: 0.85rem; font-weight: 600; color: #1c1c1e;"></div>
                            </div>

                            <!-- Physical Stock Verification is now handled via the weekly vehicle stock audit process -->
                            
                            <!-- Financial Overview Component -->
                            <div style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--ios-separator); box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                                <h6 class="fw-bold border-bottom pb-2 mb-3" style="color: var(--ios-label); font-size: 0.9rem;"><i class="bi bi-receipt text-success me-2"></i>Route Sales Summary</h6>
                                
                                <div class="row text-center mb-3">
                                    <div class="col-6" style="border-right: 1px solid var(--ios-separator);">
                                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em;">Bills Generated</div>
                                        <div style="font-size: 1.4rem; font-weight: 800; color: var(--ios-label);" id="unload_bill_count">0</div>
                                    </div>
                                    <div class="col-6">
                                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em;">Gross Sales</div>
                                        <div style="font-size: 1.4rem; font-weight: 800; color: #34C759;" id="unload_total_sales">0.00</div>
                                    </div>
                                </div>
                                
                                <div class="row g-2 text-center" style="border-top: 1px solid var(--ios-separator); padding-top: 12px;">
                                    <div class="col-3">
                                        <div style="font-size: 0.7rem; color: var(--ios-label-2); font-weight: 600;">Cash Sales</div>
                                        <div style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);" id="unload_cash_sales">0.00</div>
                                    </div>
                                    <div class="col-3" style="border-left: 1px solid var(--ios-separator);">
                                        <div style="font-size: 0.7rem; color: var(--ios-label-2); font-weight: 600;">Bank Trans</div>
                                        <div style="font-size: 0.85rem; font-weight: 700; color: #0055CC;" id="unload_bank_sales">0.00</div>
                                    </div>
                                    <div class="col-3" style="border-left: 1px solid var(--ios-separator);">
                                        <div style="font-size: 0.7rem; color: var(--ios-label-2); font-weight: 600;">Cheques</div>
                                        <div style="font-size: 0.85rem; font-weight: 700; color: #C07000;" id="unload_cheque_sales">0.00</div>
                                    </div>
                                    <div class="col-3" style="border-left: 1px solid var(--ios-separator);">
                                        <div style="font-size: 0.7rem; color: var(--ios-label-2); font-weight: 600;">Credit</div>
                                        <div style="font-size: 0.85rem; font-weight: 700; color: #CC2200;" id="unload_credit_sales">0.00</div>
                                    </div>
                                </div>

                                <div class="row g-2 text-center" style="border-top: 1px solid var(--ios-separator); padding-top: 12px; margin-top: 12px;">
                                    <div class="col-6">
                                        <div style="font-size: 0.7rem; color: var(--ios-label-2); font-weight: 600;">Credit Cash Collected</div>
                                        <div style="font-size: 0.85rem; font-weight: 700; color: #34C759;" id="unload_credit_cash">0.00</div>
                                    </div>
                                    <div class="col-6" style="border-left: 1px solid var(--ios-separator);">
                                        <div style="font-size: 0.7rem; color: var(--ios-label-2); font-weight: 600;">Credit Cheques Collected</div>
                                        <div style="font-size: 0.85rem; font-weight: 700; color: #C07000;" id="unload_credit_cheque">0.00</div>
                                    </div>
                                </div>
                                
                                <div id="unload_expenses_container" class="mt-3 pt-3 d-none" style="border-top: 1px dashed var(--ios-separator);">
                                    <h6 class="fw-bold mb-2" style="font-size: 0.85rem; color: #CC2200;"><i class="bi bi-wallet2 me-1"></i>Recorded Route Expenses</h6>
                                    <div id="unload_expenses_list" style="font-size: 0.8rem; margin-bottom: 8px;"></div>
                                    <div class="d-flex justify-content-between align-items-center" style="font-weight: 800; font-size: 0.85rem; color: #CC2200; background: rgba(255,59,48,0.05); padding: 8px; border-radius: 8px;">
                                        <span>Total Expenses Deducted:</span>
                                        <span>- Rs <span id="unload_total_expenses">0.00</span></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Col: Financial Verification -->
                        <div class="col-lg-5">
                            <h6 class="fw-bold mb-3" style="color: var(--ios-label); font-size: 0.95rem;"><i class="bi bi-cash-stack text-success me-2"></i>Financial Verification</h6>
                            
                            <div style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--ios-separator); box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-bottom: 24px;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label-2);">Expected Bank Transfers:</span>
                                    <span style="font-size: 1rem; font-weight: 800; color: #0055CC;">Rs <span id="display_expected_bank">0.00</span></span>
                                    <input type="hidden" name="expected_bank_val" id="expected_bank_val" value="0">
                                </div>
                                <div class="d-flex justify-content-between align-items-center pb-3" style="border-bottom: 1px solid var(--ios-separator); margin-bottom: 16px;">
                                    <span style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label-2);">Rep Declared Cash:</span>
                                    <span style="font-size: 1rem; font-weight: 800; color: var(--ios-label);">Rs <span id="display_rep_declared_cash">0.00</span></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px dashed var(--ios-separator);">
                                    <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);">Net Expected Cash (Sales - Exp):</span>
                                    <span style="font-size: 1.2rem; font-weight: 800; color: var(--ios-label);">Rs <span id="display_expected_cash">0.00</span></span>
                                    <input type="hidden" name="expected_cash_val" id="expected_cash_val" value="0">
                                </div>
                                
                                <div style="font-size: 0.7rem; font-weight: 800; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;">Denomination Calculator (Actual)</div>
                                
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">5000 x</div>
                                    <div class="col-5"><input type="number" id="denom_5000" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_5000" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">2000 x</div>
                                    <div class="col-5"><input type="number" id="denom_2000" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_2000" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">1000 x</div>
                                    <div class="col-5"><input type="number" id="denom_1000" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_1000" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">500 x</div>
                                    <div class="col-5"><input type="number" id="denom_500" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_500" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">100 x</div>
                                    <div class="col-5"><input type="number" id="denom_100" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_100" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">50 x</div>
                                    <div class="col-5"><input type="number" id="denom_50" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_50" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-1 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">20 x</div>
                                    <div class="col-5"><input type="number" id="denom_20" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_20" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0</span></div>
                                </div>
                                <div class="row g-2 mb-3 align-items-center">
                                    <div class="col-4 text-end" style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-2);">Coins +</div>
                                    <div class="col-5"><input type="number" step="0.01" id="denom_coins" class="ios-input text-center cash-calc" style="min-height: 32px; padding: 4px;" min="0" oninput="calculateCash()"></div>
                                    <div class="col-3"><span id="rep_count_coins" class="badge bg-light text-dark border w-100" style="font-size: 0.75rem; padding: 6px;">0.00</span></div>
                                </div>
                                
                                <div style="background: var(--ios-bg); padding: 12px; border-radius: 10px; border: 1px solid var(--ios-separator);">
                                        <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);">Actual Cash Counted:</span>
                                        <span style="font-size: 1.1rem; font-weight: 800; color: #34C759;">Rs <span id="actual_cash_total">0.00</span></span>
                                        <input type="hidden" name="actual_cash_total_input" id="actual_cash_total_input" value="0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span style="font-size: 0.75rem; font-weight: 600; color: var(--ios-label-2);">Difference:</span>
                                        <span style="font-size: 0.9rem; font-weight: 800;" id="cash_diff">0.00</span>
                                    </div>
                                </div>
                            </div>

                            <h6 class="fw-bold mb-2" style="color: var(--ios-label); font-size: 0.95rem;"><i class="bi bi-credit-card-2-front text-warning me-2"></i>Cheques Verification</h6>
                            <p style="font-size: 0.8rem; color: var(--ios-label-2); margin-bottom: 12px;">Check the box to verify receipt of physical cheque.</p>
                            <div id="unload_cheques_container" style="background: #fff; border: 1px solid var(--ios-separator); border-radius: 12px; padding: 12px; max-height: 150px; overflow-y: auto;">
                                <!-- Cheques injected via JS -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface); border-top: 1px solid var(--ios-separator);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4" style="background: #34C759;" onclick="return confirm('Confirm Settlement? This finalizes the route and logs Net Cash/Bank transfers to the Ledger.')">
                        Confirm Settlement & Close Day
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Route Sales Report Modal -->
<div class="modal fade" id="routeReportModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator);">
                <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-file-earmark-bar-graph text-primary me-2"></i>Route Detailed Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background: var(--ios-bg); padding: 24px;">
                
                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <div class="metrics-card">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Route Details</div>
                            <h5 class="fw-bold mb-2" style="color: var(--ios-label); font-size: 1.1rem;" id="report_route_name">Route Name</h5>
                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--ios-label);"><i class="bi bi-person me-1 text-muted"></i>Rep: <span id="report_rep_name"></span></div>
                            <div style="font-size: 0.8rem; color: var(--ios-label-2); margin-top: 4px;"><i class="bi bi-calendar me-1 text-muted"></i>Date: <span id="report_date"></span></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="metrics-card">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">Vehicle Mileage</div>
                            <div class="d-flex justify-content-between mb-1">
                                <span style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label-2);">Start Meter:</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);" id="report_start_meter">0.0</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom: 1px dashed var(--ios-separator);">
                                <span style="font-size: 0.85rem; font-weight: 500; color: var(--ios-label-2);">End Meter:</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);" id="report_end_meter">0.0</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label);">Total Distance:</span>
                                <span style="font-size: 0.95rem; font-weight: 800; color: #0055CC;" id="report_distance">0.0 km</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="metrics-card" style="background: linear-gradient(145deg, #34C759, #30D158); color: white; border: none; align-items: center; text-align: center;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Gross Sales</div>
                            <h3 style="font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px;" id="report_total_sales">Rs: 0.00</h3>
                            <span style="background: rgba(0,0,0,0.15); font-size: 0.75rem; font-weight: 700; padding: 4px 12px; border-radius: 50px;" id="report_total_invoices">0 Bills</span>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-bold mb-3" style="color: var(--ios-label); font-size: 0.95rem;"><i class="bi bi-receipt me-2 text-primary"></i>Invoices Generated</h6>
                        <div class="table-responsive rounded border" style="background: #fff; max-height: 300px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                            <table class="ios-table text-center" style="margin: 0;">
                                <thead style="position: sticky; top: 0; z-index: 5;">
                                    <tr class="table-ios-header">
                                        <th>Invoice #</th>
                                        <th>Time</th>
                                        <th>Payment Breakdown</th>
                                        <th class="text-end">Amount (Rs)</th>
                                    </tr>
                                </thead>
                                <tbody id="report_invoices_tbody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row g-4">
                            <div class="col-12 d-none" id="report_shortages_container">
                                <h6 class="fw-bold mb-3" style="color: #CC2200; font-size: 0.95rem;"><i class="bi bi-exclamation-triangle-fill me-2"></i>Identified Stock Shortages</h6>
                                <div class="table-responsive rounded border" style="background: #fff; border-color: rgba(255,59,48,0.3) !important; max-height: 150px; overflow-y: auto;">
                                    <table class="ios-table text-center" style="margin: 0;">
                                        <thead style="position: sticky; top: 0; z-index: 5;">
                                            <tr class="table-ios-header" style="background: rgba(255,59,48,0.05) !important;">
                                                <th class="text-start">Product Name</th>
                                                <th style="color: #CC2200 !important;">Missing Qty</th>
                                            </tr>
                                        </thead>
                                        <tbody id="report_shortages_tbody"></tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="col-12 d-none" id="report_expenses_container">
                                <h6 class="fw-bold mb-3" style="color: #C07000; font-size: 0.95rem;"><i class="bi bi-wallet2 me-2"></i>Recorded Expenses</h6>
                                <div class="table-responsive rounded border" style="background: #fff; max-height: 150px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                                    <table class="ios-table" style="margin: 0;">
                                        <thead style="position: sticky; top: 0; z-index: 5;">
                                            <tr class="table-ios-header">
                                                <th>Type & Description</th>
                                                <th class="text-end">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody id="report_expenses_tbody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer" style="background: var(--ios-surface); border-top: 1px solid var(--ios-separator);">
                <button type="button" class="quick-btn quick-btn-secondary px-4" data-bs-dismiss="modal">Close Report</button>
            </div>
        </div>
    </div>
</div>

<!-- AI Suggest Stock Modal -->
<div class="modal fade" id="aiSuggestModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator);">
                <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-magic text-primary me-2"></i>AI Stock Loading Suggestion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="background: var(--ios-bg); padding: 24px;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="ios-badge blue me-2" id="ai_trips_badge">0 Trips Analyzed</span>
                        <span class="text-muted" style="font-size: 0.85rem;">Data covers the last 3 months for this route.</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <label class="fw-bold me-2" style="font-size: 0.85rem; color: var(--ios-label);">Buffer Stock %:</label>
                        <input type="number" id="ai_buffer_percent" class="ios-input text-center" value="20" min="0" max="100" style="width: 80px; padding: 4px; min-height: 32px;">
                    </div>
                </div>

                <div class="table-responsive rounded border" style="background: #fff; max-height: 400px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                    <table class="ios-table text-center" style="margin: 0;">
                        <thead style="position: sticky; top: 0; z-index: 5;">
                            <tr class="table-ios-header">
                                <th class="text-start">Product Name</th>
                                <th>Month 1 (0-30d)</th>
                                <th>Month 2 (31-60d)</th>
                                <th>Month 3 (61-90d)</th>
                                <th>3-Month Total</th>
                                <th style="background: rgba(52,199,89,0.1) !important; color: #1A9A3A !important;">Suggested Qty</th>
                            </tr>
                        </thead>
                        <tbody id="ai_suggestions_tbody">
                            <!-- Injected via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer" style="background: var(--ios-surface); border-top: 1px solid var(--ios-separator);">
                <button type="button" class="quick-btn quick-btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="quick-btn quick-btn-primary px-4" id="btnApplyAiSuggestion">Apply Load Suggestion</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('addLoadRowBtn').addEventListener('click', function() {
    const container = document.getElementById('loadItemsContainer');
    const newRow = document.querySelector('.load-row').cloneNode(true);
    newRow.querySelector('select').value = '';
    newRow.querySelector('input').value = '';
    container.appendChild(newRow);
});

let currentAiData = [];
let currentTrips = 1;

document.getElementById('btnSuggestStock').addEventListener('click', function() {
    const routeSelect = document.querySelector('select[name="route_id"]');
    const routeId = routeSelect.value;
    
    if (!routeId) {
        alert('Please select a Route from the dropdown first to get AI suggestions.');
        return;
    }

    const btn = this;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Analyzing...';
    btn.disabled = true;

    fetch('dispatch.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=get_ai_stock_suggestion&route_id=${routeId}`
    })
    .then(res => res.json())
    .then(result => {
        btn.innerHTML = originalText;
        btn.disabled = false;

        if (result.success) {
            currentAiData = result.sales_data;
            currentTrips = result.trips_analyzed;
            
            if (currentAiData.length === 0) {
                alert('Not enough sales data found for this route in the last 3 months to generate suggestions.');
                return;
            }

            document.getElementById('ai_trips_badge').innerText = currentTrips + ' Trips Analyzed';
            renderAiSuggestions();
            new bootstrap.Modal(document.getElementById('aiSuggestModal')).show();
        } else {
            alert(result.message);
        }
    })
    .catch(err => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Network error while fetching AI suggestions.');
    });
});

function renderAiSuggestions() {
    const tbody = document.getElementById('ai_suggestions_tbody');
    tbody.innerHTML = '';
    const bufferPercent = parseFloat(document.getElementById('ai_buffer_percent').value) || 0;
    const multiplier = 1 + (bufferPercent / 100);

    currentAiData.forEach(item => {
        const avgPerTrip = item.total_sold / currentTrips;
        const suggestedQty = Math.ceil(avgPerTrip * multiplier);
        
        // Ensure product is actually in the dispatch dropdown
        const selectCheck = document.querySelector(`select[name="product_id[]"] option[value="${item.product_id}"]`);
        if (!selectCheck) return; // Skip if product is unavailable/out of stock
        
        tbody.innerHTML += `
            <tr data-product-id="${item.product_id}">
                <td class="text-start fw-bold" style="color: var(--ios-label); font-size: 0.9rem;">${item.product_name}</td>
                <td>${item.month_1}</td>
                <td>${item.month_2}</td>
                <td>${item.month_3}</td>
                <td class="fw-bold" style="color: #0055CC;">${item.total_sold}</td>
                <td style="background: rgba(52,199,89,0.05) !important;">
                    <input type="number" class="ios-input text-center fw-bold ai-suggested-input" value="${suggestedQty}" min="0" style="color: #1A9A3A; width: 80px; margin: 0 auto; padding: 4px; min-height: 32px;">
                </td>
            </tr>
        `;
    });
    
    if(tbody.innerHTML === '') {
        tbody.innerHTML = '<tr><td colspan="6" class="text-muted py-4">No matching available products found in inventory.</td></tr>';
    }
}

document.getElementById('ai_buffer_percent').addEventListener('input', renderAiSuggestions);

document.getElementById('btnApplyAiSuggestion').addEventListener('click', function() {
    // Save one row as template before clearing
    const templateRow = document.querySelector('.load-row').cloneNode(true);
    templateRow.querySelector('select').value = '';
    templateRow.querySelector('input').value = '';

    const container = document.getElementById('loadItemsContainer');
    container.innerHTML = '';

    const inputs = document.querySelectorAll('.ai-suggested-input');
    let added = 0;
    
    inputs.forEach(input => {
        const tr = input.closest('tr');
        const productId = tr.dataset.productId;
        const qty = parseInt(input.value) || 0;
        
        if (qty > 0) {
            const newRow = templateRow.cloneNode(true);
            newRow.querySelector('select').value = productId;
            newRow.querySelector('input').value = qty;
            container.appendChild(newRow);
            added++;
        }
    });

    if (added === 0) {
        container.appendChild(templateRow);
        alert('No products with quantity > 0 were applied.');
    } else {
        bootstrap.Modal.getInstance(document.getElementById('aiSuggestModal')).hide();
    }
});

// --- Unload Modal Logic ---
function openUnloadModal(assignmentId) {
    document.getElementById('unload_assignment_id').value = assignmentId;
    
    // Reset Cash Form
    document.querySelectorAll('.cash-calc').forEach(el => el.value = '');
    calculateCash();
    document.getElementById('unload_cheques_container').innerHTML = '<div class="py-3 text-center"><span class="spinner-border spinner-border-sm text-primary"></span></div>';
    document.getElementById('unload_expenses_container').classList.add('d-none');
    document.getElementById('unload_returns_container').classList.add('d-none');

    new bootstrap.Modal(document.getElementById('unloadModal')).show();

    fetch('dispatch.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=get_unload_data&assignment_id=${assignmentId}`
    })
    .then(res => res.json())
    .then(result => {
        if(result.success) {
            // 2. Sales Summary Data
            document.getElementById('unload_bill_count').innerText = result.sales_summary.bill_count || '0';
            document.getElementById('unload_total_sales').innerText = parseFloat(result.sales_summary.total_sales || 0).toFixed(2);
            document.getElementById('unload_cash_sales').innerText = parseFloat(result.sales_summary.cash_sales || 0).toFixed(2);
            document.getElementById('unload_bank_sales').innerText = parseFloat(result.sales_summary.bank_sales || 0).toFixed(2);
            document.getElementById('unload_cheque_sales').innerText = parseFloat(result.sales_summary.cheque_sales || 0).toFixed(2);
            document.getElementById('unload_credit_sales').innerText = parseFloat(result.sales_summary.credit_sales || 0).toFixed(2);
            document.getElementById('unload_credit_cash').innerText = parseFloat(result.credit_cash || 0).toFixed(2);
            document.getElementById('unload_credit_cheque').innerText = parseFloat(result.credit_cheque || 0).toFixed(2);

            // 3. Expenses Data
            if (result.expenses && result.expenses.length > 0) {
                document.getElementById('unload_expenses_container').classList.remove('d-none');
                let expHtml = '';
                result.expenses.forEach(e => {
                    expHtml += `<div class="d-flex justify-content-between pb-1 mb-1" style="border-bottom: 1px dashed var(--ios-separator);">
                        <span style="color: var(--ios-label-2);">${e.type} (${e.description})</span>
                        <span style="font-weight: 700; color: var(--ios-label);">Rs ${parseFloat(e.amount).toFixed(2)}</span>
                    </div>`;
                });
                document.getElementById('unload_expenses_list').innerHTML = expHtml;
                document.getElementById('unload_total_expenses').innerText = parseFloat(result.total_expenses).toFixed(2);
            }

            // 4. Expected Financial Data & Rep Declared Cash
            document.getElementById('display_rep_declared_cash').innerText = parseFloat(result.rep_declared_cash || 0).toFixed(2);
            
            document.getElementById('display_expected_cash').innerText = parseFloat(result.expected_cash).toFixed(2);
            document.getElementById('expected_cash_val').value = parseFloat(result.expected_cash).toFixed(2);
            
            document.getElementById('expected_bank_val').value = parseFloat(result.expected_bank).toFixed(2);
            
            // Populate Rep's Cash Counts
            if (result.rep_cash_counts) {
                const rcc = result.rep_cash_counts;
                [5000, 2000, 1000, 500, 100, 50, 20].forEach(d => {
                    const el = document.getElementById('rep_count_' + d);
                    if (el) el.innerText = rcc[d] || '0';
                });
                const coinsEl = document.getElementById('rep_count_coins');
                if (coinsEl) coinsEl.innerText = parseFloat(rcc.coins || 0).toFixed(2);
            }

            calculateCash();

            // 5. Cheques Data
            let chkHtml = '';
            if (result.cheques && result.cheques.length > 0) {
                result.cheques.forEach((chk, i) => {
                    chkHtml += `
                    <div class="form-check mb-2 pb-2 ${i < result.cheques.length - 1 ? 'border-bottom' : ''}" style="border-color: var(--ios-separator) !important;">
                        <input class="form-check-input" type="checkbox" value="" id="chk_${i}" style="height: 1.2rem; width: 1.2rem;" required>
                        <label class="form-check-label d-flex justify-content-between align-items-center w-100 ps-2" for="chk_${i}">
                            <div>
                                <span style="font-weight: 700; font-size: 0.85rem; color: var(--ios-label);">${chk.bank_name}</span><br>
                                <span style="color: var(--ios-label-3); font-size: 0.75rem;">No: ${chk.cheque_number}</span>
                            </div>
                            <span style="font-weight: 800; font-size: 0.9rem; color: #0055CC;">Rs ${parseFloat(chk.amount).toFixed(2)}</span>
                        </label>
                    </div>`;
                });
            } else {
                chkHtml = '<div style="font-size: 0.8rem; color: var(--ios-label-3); text-align: center; padding: 10px 0;">No cheques collected on this route.</div>';
            }
            document.getElementById('unload_cheques_container').innerHTML = chkHtml;

            // 6. Alert Admin of Rep Returns
            if (result.customer_returns && result.customer_returns.length > 0) {
                document.getElementById('unload_returns_container').classList.remove('d-none');
                let retHtml = '';
                result.customer_returns.forEach(r => {
                    let badge = r.condition_status == 'good' ? '<span class="ios-badge green">Good</span>' : (r.condition_status == 'damaged' ? '<span class="ios-badge red">Damaged</span>' : '<span class="ios-badge orange">Expired</span>');
                    retHtml += `<div class="d-flex justify-content-between align-items-center pb-2 mb-2" style="border-bottom: 1px dashed rgba(255,59,48,0.2);">
                        <span>${r.name} <span class="badge bg-secondary ms-1">x ${r.quantity}</span></span> ${badge}
                    </div>`;
                });
                document.getElementById('unload_returns_list').innerHTML = retHtml;
            } else {
                document.getElementById('unload_returns_container').classList.add('d-none');
            }

        } else {
            alert("Error fetching settlement data: " + result.message);
        }
    })
    .catch(err => {
        alert("Connection error while fetching settlement data.");
    });
}

function calculateCash() {
    console.log("Calculating cash...");
    let total = 0;
    const denominations = [5000, 2000, 1000, 500, 100, 50, 20];
    
    denominations.forEach(d => {
        const el = document.getElementById('denom_' + d);
        if (el) {
            let val = parseInt(el.value) || 0;
            total += val * d;
        }
    });

    const coinsEl = document.getElementById('denom_coins');
    if (coinsEl) {
        total += (parseFloat(coinsEl.value) || 0);
    }

    console.log("Total Calculated:", total);

    const actualEl = document.getElementById('actual_cash_total');
    const actualInput = document.getElementById('actual_cash_total_input');
    if (actualEl) actualEl.innerText = total.toFixed(2);
    if (actualInput) actualInput.value = total.toFixed(2);
    
    const expectedInput = document.getElementById('expected_cash_val');
    let expected = expectedInput ? (parseFloat(expectedInput.value) || 0) : 0;
    
    let diff = total - expected;
    let diffEl = document.getElementById('cash_diff');
    if (diffEl) {
        diffEl.innerText = (diff >= 0 ? '+Rs ' : '-Rs ') + Math.abs(diff).toFixed(2);
        diffEl.style.color = diff >= 0 ? '#34C759' : '#CC2200';
    }
}

// Automatic updates are handled via oninput in HTML

// --- Sales Report Modal Logic ---
function openReportModal(assignmentId) {
    new bootstrap.Modal(document.getElementById('routeReportModal')).show();
    
    document.getElementById('report_route_name').textContent = "Loading...";
    document.getElementById('report_invoices_tbody').innerHTML = '<tr><td colspan="4" class="py-4 text-center"><span class="spinner-border spinner-border-sm me-2 text-primary"></span><span class="fw-bold text-muted">Fetching...</span></td></tr>';
    document.getElementById('report_shortages_container').classList.add('d-none');
    document.getElementById('report_expenses_container').classList.add('d-none');

    fetch('dispatch.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=get_route_report&assignment_id=${assignmentId}`
    })
    .then(res => res.json())
    .then(result => {
        if(result.success) {
            const asg = result.assignment;
            
            document.getElementById('report_route_name').textContent = asg.route_name;
            document.getElementById('report_rep_name').textContent = asg.rep_name;
            document.getElementById('report_date').textContent = new Date(asg.assign_date).toLocaleDateString();
            
            const startM = parseFloat(asg.start_meter) || 0;
            const endM = parseFloat(asg.end_meter) || 0;
            document.getElementById('report_start_meter').textContent = startM.toFixed(1) + ' km';
            document.getElementById('report_end_meter').textContent = endM.toFixed(1) + ' km';
            document.getElementById('report_distance').textContent = (endM - startM).toFixed(1) + ' km';

            const orders = result.orders;
            document.getElementById('report_total_invoices').textContent = orders.length + ' Bills';
            
            let totalSales = 0;
            let invHtml = '';
            
            if (orders.length > 0) {
                orders.forEach(o => {
                    const amt = parseFloat(o.total_amount);
                    totalSales += amt;
                    
                    invHtml += `
                        <tr>
                            <td class="text-start ps-3">
                                <a href="view_invoice.php?id=${o.id}" target="_blank" style="font-weight: 700; font-size: 0.9rem; color: var(--accent-dark); text-decoration: none;">#${String(o.id).padStart(6, '0')}</a>
                            </td>
                            <td>
                                <div style="font-size: 0.8rem; color: var(--ios-label-2); font-weight: 600;">${new Date(o.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                            </td>
                            <td>
                                <span class="ios-badge gray" style="font-size: 0.65rem;">C: ${parseFloat(o.paid_cash).toFixed(0)} | B: ${parseFloat(o.paid_bank).toFixed(0)} | Ch: ${parseFloat(o.paid_cheque).toFixed(0)}</span>
                            </td>
                            <td class="text-end pe-3" style="font-weight: 800; font-size: 0.95rem; color: var(--ios-label);">${amt.toFixed(2)}</td>
                        </tr>
                    `;
                });
            } else {
                invHtml = '<tr><td colspan="4" class="py-4 text-center text-muted fw-bold">No invoices generated on this route.</td></tr>';
            }
            
            document.getElementById('report_total_sales').textContent = 'Rs: ' + totalSales.toFixed(2);
            document.getElementById('report_invoices_tbody').innerHTML = invHtml;

            // Render Shortages
            const shortages = result.shortages;
            if (shortages && shortages.length > 0) {
                document.getElementById('report_shortages_container').classList.remove('d-none');
                let shortHtml = '';
                shortages.forEach(s => {
                    shortHtml += `<tr><td class="text-start ps-3" style="font-weight: 600; font-size: 0.85rem; color: var(--ios-label);">${s.name}</td><td style="font-weight: 800; font-size: 0.95rem; color: #CC2200;">${s.short_qty}</td></tr>`;
                });
                document.getElementById('report_shortages_tbody').innerHTML = shortHtml;
            }

            // Render Expenses
            const expenses = result.expenses;
            if (expenses && expenses.length > 0) {
                document.getElementById('report_expenses_container').classList.remove('d-none');
                let expHtml = '';
                expenses.forEach(e => {
                    expHtml += `<tr>
                        <td class="text-start ps-3">
                            <div style="font-weight: 700; font-size: 0.85rem; color: var(--ios-label);">${e.type}</div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3);">${e.description}</div>
                        </td>
                        <td class="text-end pe-3" style="font-weight: 800; font-size: 0.95rem; color: #C07000;">- Rs ${parseFloat(e.amount).toFixed(2)}</td>
                    </tr>`;
                });
                document.getElementById('report_expenses_tbody').innerHTML = expHtml;
            }
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>