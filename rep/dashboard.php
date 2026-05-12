<?php
// Enable error reporting for easier debugging of 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];

// --- AGGRESSIVE DB MIGRATION FOR ORDERS ---
try {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `assignment_id` INT NULL AFTER `rep_id` ");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE `orders` ADD COLUMN `assignment_id` INT NULL AFTER `rep_id` "); } catch (PDOException $e2) {}
}
try { $pdo->exec("ALTER TABLE `orders` ADD COLUMN `paid_cash` DECIMAL(12,2) DEFAULT 0.00 AFTER `paid_amount` "); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `orders` ADD COLUMN `paid_bank` DECIMAL(12,2) DEFAULT 0.00 AFTER `paid_cash` "); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `orders` ADD COLUMN `paid_cheque` DECIMAL(12,2) DEFAULT 0.00 AFTER `paid_bank` "); } catch (PDOException $e) {}

// --- AGGRESSIVE DB MIGRATION FOR EXPENSES & LOADS ---
try {
    $pdo->exec("ALTER TABLE `route_expenses` CHANGE `dispatch_id` `assignment_id` INT NOT NULL");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE `route_expenses` ADD COLUMN `assignment_id` INT NOT NULL AFTER `id` "); } catch (PDOException $e2) {}
}

// Clean up old foreign keys that might point to delivery_dispatches instead of rep_routes
try { $pdo->exec("ALTER TABLE `route_expenses` DROP FOREIGN KEY `route_expenses_ibfk_1` "); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `route_expenses` ADD CONSTRAINT `fk_route_expenses_rep_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `rep_routes`(`id`) ON DELETE CASCADE"); } catch (PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE `route_loads` CHANGE `dispatch_id` `assignment_id` INT NOT NULL");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE `route_loads` ADD COLUMN `assignment_id` INT NOT NULL AFTER `id` "); } catch (PDOException $e2) {}
}
try { $pdo->exec("ALTER TABLE `route_loads` DROP FOREIGN KEY `route_loads_ibfk_1` "); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `route_loads` ADD CONSTRAINT `fk_route_loads_rep_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `rep_routes`(`id`) ON DELETE CASCADE"); } catch (PDOException $e) {}
// ------------------------------------------

// --- AUTO DB MIGRATION FOR ROUTES & EXPENSES ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

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
        cash_5000 INT DEFAULT 0,
        cash_2000 INT DEFAULT 0,
        cash_1000 INT DEFAULT 0,
        cash_500 INT DEFAULT 0,
        cash_100 INT DEFAULT 0,
        cash_50 INT DEFAULT 0,
        cash_20 INT DEFAULT 0,
        cash_coins DECIMAL(12,2) DEFAULT 0.00,
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

    try {
        $pdo->query("SELECT user_id FROM rep_daily_sessions LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("DROP TABLE IF EXISTS rep_daily_sessions");
        $pdo->exec("CREATE TABLE rep_daily_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_date DATE NOT NULL,
            start_time DATETIME NULL,
            end_time DATETIME NULL,
            status ENUM('active', 'ended') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY(user_id, session_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rep_location_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        activity_type VARCHAR(50) NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch(PDOException $e) { /* Ignored if already exists */ }

try {
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_5000 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_2000 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_1000 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_500 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_100 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_50 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_20 INT DEFAULT 0");
    $pdo->exec("ALTER TABLE rep_routes ADD COLUMN IF NOT EXISTS cash_coins DECIMAL(12,2) DEFAULT 0.00");
} catch(PDOException $e) {}

try { $pdo->exec("ALTER TABLE customer_payments ADD COLUMN assignment_id INT NULL AFTER customer_id"); } catch(PDOException $e) {}
// ------------------------------------

// --- Handle Post Actions for Routes & Starting/Ending Day ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['route_action'])) {
    $action = $_POST['route_action'];
    $assignment_id = (int)$_POST['assignment_id'];

    if ($action == 'accept') {
        $pdo->prepare("UPDATE rep_routes SET status = 'accepted' WHERE id = ? AND rep_id = ?")->execute([$assignment_id, $rep_id]);
        
        // --- Auto Attendance for Driver & Rep upon Acceptance ---
        try {
            // 1. Fetch Driver ID (Robust lookup)
            $routeDataStmt = $pdo->prepare("SELECT driver_id FROM rep_routes WHERE id = ?");
            $routeDataStmt->execute([$assignment_id]);
            $assigned_driver_id = $routeDataStmt->fetchColumn();

            // Mark Driver Present
            if ($assigned_driver_id) {
                $pdo->prepare("INSERT INTO attendance (employee_id, work_date, status) VALUES (?, CURDATE(), 'present') ON DUPLICATE KEY UPDATE status = 'present'")->execute([$assigned_driver_id]);
            }

            // 2. Mark Rep Present
            $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? OR (name = ? AND designation = 'Sales Rep') LIMIT 1");
            $empStmt->execute([$rep_id, $_SESSION['user_name']]);
            $rep_emp_id = $empStmt->fetchColumn();

            if (!$rep_emp_id) {
                $empCode = 'REP-' . str_pad($rep_id, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO employees (user_id, emp_code, name, designation, daily_rate, status) VALUES (?, ?, ?, 'Sales Rep', 0.00, 'active')")->execute([$rep_id, $empCode, $_SESSION['user_name']]);
                $rep_emp_id = $pdo->lastInsertId();
            }

            if ($rep_emp_id) {
                $pdo->prepare("INSERT INTO attendance (employee_id, work_date, status) VALUES (?, CURDATE(), 'present') ON DUPLICATE KEY UPDATE status = 'present'")->execute([$rep_emp_id]);
            }
        } catch (Exception $e) { /* Attendance non-critical */ }

    } elseif ($action == 'reject') {
        $pdo->prepare("UPDATE rep_routes SET status = 'rejected' WHERE id = ? AND rep_id = ?")->execute([$assignment_id, $rep_id]);
    } elseif ($action == 'start_day') {
        $meter = (float)$_POST['start_meter'];
        $driver_id = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
        $lat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $lng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        $pdo->prepare("UPDATE rep_routes SET start_meter = ? WHERE id = ? AND rep_id = ?")->execute([$meter, $assignment_id, $rep_id]);
        $pdo->prepare("INSERT INTO rep_daily_sessions (user_id, session_date, start_time, status) VALUES (?, CURDATE(), NOW(), 'active') ON DUPLICATE KEY UPDATE start_time = NOW(), status = 'active'")->execute([$rep_id]);
        
        if ($lat && $lng) {
            $pdo->prepare("INSERT INTO rep_location_logs (user_id, latitude, longitude, activity_type, timestamp) VALUES (?, ?, ?, 'day_started', NOW())")->execute([$rep_id, $lat, $lng]);
        }

        // Attendance Safety Check
        try {
            // Fetch Driver ID from DB if missing from POST
            if (!$driver_id) {
                $stmt = $pdo->prepare("SELECT driver_id FROM rep_routes WHERE id = ?");
                $stmt->execute([$assignment_id]);
                $driver_id = $stmt->fetchColumn();
            }

            if ($driver_id) {
                $pdo->prepare("INSERT INTO attendance (employee_id, work_date, status) VALUES (?, CURDATE(), 'present') ON DUPLICATE KEY UPDATE status = 'present'")->execute([$driver_id]);
            }
        } catch (Exception $e) {}

        $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? OR (name = ? AND designation = 'Sales Rep') LIMIT 1");
        $empStmt->execute([$rep_id, $_SESSION['user_name']]);
        $rep_emp_id = $empStmt->fetchColumn();

        if (!$rep_emp_id) {
            $empCode = 'REP-' . str_pad($rep_id, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO employees (user_id, emp_code, name, designation, daily_rate, status) VALUES (?, ?, ?, 'Sales Rep', 0.00, 'active')")->execute([$rep_id, $empCode, $_SESSION['user_name']]);
            $rep_emp_id = $pdo->lastInsertId();
        }

        if ($rep_emp_id) {
            $pdo->prepare("INSERT INTO attendance (employee_id, work_date, status) VALUES (?, CURDATE(), 'present') ON DUPLICATE KEY UPDATE status = 'present'")->execute([$rep_emp_id]);
        }
    } elseif ($action == 'add_expense') {
        $type = $_POST['expense_type'];
        $amount = (float)$_POST['expense_amount'];
        $desc = trim($_POST['expense_desc']);
        $pdo->prepare("INSERT INTO route_expenses (assignment_id, type, amount, description) VALUES (?, ?, ?, ?)")->execute([$assignment_id, $type, $amount, $desc]);
    } elseif ($action == 'delete_expense') {
        $expense_id = (int)$_POST['expense_id'];
        $pdo->prepare("DELETE FROM route_expenses WHERE id = ? AND assignment_id = ?")->execute([$expense_id, $assignment_id]);
    } elseif ($action == 'end_day') {
        $end_meter = (float)$_POST['end_meter'];
        $actual_cash = (float)($_POST['actual_cash_total_input'] ?? 0);
        $expected_cash = (float)($_POST['expected_cash_val'] ?? 0);
        $lat = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
        $lng = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

        $c5000 = (int)($_POST['cash_5000'] ?? 0);
        $c2000 = (int)($_POST['cash_2000'] ?? 0);
        $c1000 = (int)($_POST['cash_1000'] ?? 0);
        $c500 = (int)($_POST['cash_500'] ?? 0);
        $c100 = (int)($_POST['cash_100'] ?? 0);
        $c50 = (int)($_POST['cash_50'] ?? 0);
        $c20 = (int)($_POST['cash_20'] ?? 0);
        $ccoins = (float)($_POST['cash_coins'] ?? 0);
        
        $pdo->prepare("UPDATE rep_routes SET end_meter = ?, actual_cash = ?, expected_cash = ?, 
                       cash_5000 = ?, cash_2000 = ?, cash_1000 = ?, cash_500 = ?, cash_100 = ?, cash_50 = ?, cash_20 = ?, cash_coins = ?,
                       status = 'completed' WHERE id = ? AND rep_id = ?")
            ->execute([$end_meter, $actual_cash, $expected_cash, $c5000, $c2000, $c1000, $c500, $c100, $c50, $c20, $ccoins, $assignment_id, $rep_id]);
        $pdo->prepare("UPDATE rep_daily_sessions SET end_time = NOW(), status = 'ended' WHERE user_id = ? AND session_date = CURDATE()")->execute([$rep_id]);
        
        if ($lat && $lng) {
            $pdo->prepare("INSERT INTO rep_location_logs (user_id, latitude, longitude, activity_type, timestamp) VALUES (?, ?, ?, 'day_ended', NOW())")->execute([$rep_id, $lat, $lng]);
        }
    } elseif ($action == 'record_credit_payment') {
        $customer_id = (int)$_POST['customer_id'];
        $pay_amount = (float)$_POST['payment_amount'];
        $payment_method = $_POST['payment_method'];
        $reference = trim($_POST['payment_reference'] ?? "");

        if ($pay_amount > 0) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT id, total_amount, paid_amount FROM orders WHERE customer_id = ? AND total_amount > paid_amount ORDER BY created_at ASC FOR UPDATE");
                $stmt->execute([$customer_id]);
                $unpaid_orders = $stmt->fetchAll();

                $remaining_payment = $pay_amount;
                $first_order_id = null;

                foreach ($unpaid_orders as $order) {
                    if ($remaining_payment <= 0) break;
                    if (!$first_order_id) $first_order_id = $order['id'];

                    $amount_due = $order['total_amount'] - $order['paid_amount'];
                    $amount_to_apply = min($amount_due, $remaining_payment);
                    $new_paid_amount = $order['paid_amount'] + $amount_to_apply;
                    
                    $new_status = ($payment_method === 'Cheque') ? 'waiting' : (($new_paid_amount >= $order['total_amount']) ? 'paid' : 'pending');

                    $pdo->prepare("UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?")
                        ->execute([$new_paid_amount, $new_status, $order['id']]);

                    $remaining_payment -= $amount_to_apply;
                }

                if ($payment_method === 'Cheque' && $first_order_id) {
                    $bank_name = trim($_POST['cheque_bank']);
                    $cheque_number = trim($_POST['cheque_number']);
                    $banking_date = $_POST['cheque_date'];
                    if(empty($reference)) $reference = "$bank_name - $cheque_number";
                    
                    $pdo->prepare("INSERT INTO cheques (order_id, bank_name, cheque_number, banking_date, amount, status) VALUES (?, ?, ?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE bank_name=VALUES(bank_name), cheque_number=VALUES(cheque_number), banking_date=VALUES(banking_date), amount=amount+VALUES(amount)")
                        ->execute([$first_order_id, $bank_name, $cheque_number, $banking_date, $pay_amount]);
                }
                
                $pdo->prepare("INSERT INTO customer_payments (customer_id, assignment_id, amount, method, reference, notes) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$customer_id, $assignment_id, $pay_amount, $payment_method, $reference, "Rep Collected Payment"]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }
    }
    header("Location: dashboard.php");
    exit;
}

// --- Fetch Key Metrics ---
$route_expenses = [];
$total_expenses = 0;
$cash_sales = 0;
$collected_cheques = [];
$credit_cash_collected = 0;
$customers_with_dues = [];

try {
    $routeStmt = $pdo->prepare("
        SELECT rr.*, r.name as route_name, r.description, e.name as driver_name 
        FROM rep_routes rr 
        JOIN routes r ON rr.route_id = r.id 
        LEFT JOIN employees e ON rr.driver_id = e.id
        WHERE rr.rep_id = ? 
          AND (rr.assign_date = CURDATE() OR rr.status IN ('assigned', 'accepted'))
        ORDER BY (CASE WHEN rr.status IN ('assigned', 'accepted') THEN 0 ELSE 1 END) ASC, rr.id DESC LIMIT 1
    ");
    $routeStmt->execute([$rep_id]);
    $todays_route = $routeStmt->fetch();

    $assignment_id = $todays_route ? $todays_route['id'] : null;

    if ($assignment_id) {
        $stmt = $pdo->prepare("SELECT SUM(`total_amount`) FROM `orders` WHERE `orders`.`assignment_id` = ?");
        $stmt->execute([$assignment_id]);
        $today_sales = $stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `orders` WHERE `orders`.`assignment_id` = ?");
        $stmt->execute([$assignment_id]);
        $today_bills = $stmt->fetchColumn() ?: 0;
        
        $stmt = $pdo->prepare("SELECT SUM(`paid_cash`) FROM `orders` WHERE `orders`.`assignment_id` = ?");
        $stmt->execute([$assignment_id]);
        $cash_sales = (float)$stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT SUM(amount) FROM customer_payments WHERE assignment_id = ? AND method = 'Cash'");
        $stmt->execute([$assignment_id]);
        $credit_cash_collected = (float)$stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT * FROM `route_expenses` WHERE `route_expenses`.`assignment_id` = ? ORDER BY `route_expenses`.`created_at` DESC");
        $stmt->execute([$assignment_id]);
        $route_expenses = $stmt->fetchAll();
        foreach($route_expenses as $exp) { $total_expenses += $exp['amount']; }
        
        $stmt = $pdo->prepare("
            SELECT `ch`.`bank_name`, `ch`.`amount` 
            FROM `cheques` `ch` JOIN `orders` `o` ON `ch`.`order_id` = `o`.`id` 
            WHERE `o`.`assignment_id` = ? AND `ch`.`type` = 'incoming'
        ");
        $stmt->execute([$assignment_id]);
        $collected_cheques = $stmt->fetchAll();

        // Fetch customers with dues for the modal
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, SUM(o.total_amount - o.paid_amount) as outstanding 
            FROM customers c 
            JOIN orders o ON c.id = o.customer_id 
            WHERE o.total_amount > o.paid_amount
            GROUP BY c.id 
            ORDER BY c.name ASC
        ");
        $stmt->execute();
        $customers_with_dues = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT SUM(`total_amount`) FROM `orders` WHERE `rep_id` = ? AND DATE(`created_at`) = CURDATE()");
        $stmt->execute([$rep_id]);
        $today_sales = $stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `orders` WHERE `rep_id` = ? AND DATE(`created_at`) = CURDATE()");
        $stmt->execute([$rep_id]);
        $today_bills = $stmt->fetchColumn() ?: 0;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `orders` WHERE `rep_id` = ? AND `payment_status` != 'paid'");
    $stmt->execute([$rep_id]);
    $pending_orders = $stmt->fetchColumn() ?: 0;

    $load_manifest = [];
    if ($assignment_id) {
        $loadStmt = $pdo->prepare("
            SELECT rl.loaded_qty, p.name, p.sku 
            FROM route_loads rl 
            JOIN products p ON rl.product_id = p.id 
            WHERE rl.assignment_id = ?
        ");
        $loadStmt->execute([$assignment_id]);
        $load_manifest = $loadStmt->fetchAll();
    }

} catch(PDOException $e) {
    die("<div style='padding: 20px; color: red;'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard — Fintrix</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Fintrix Rep">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <script>
        console.log("Dashboard Data:", {
            rep_id: <?php echo json_encode($rep_id); ?>,
            assignment_id: <?php echo json_encode($assignment_id); ?>,
            status: <?php echo json_encode($todays_route['status'] ?? 'none'); ?>
        });
    </script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            /* Clean UI Color Palette */
            --bg-color: #F8FAFC;         
            --surface: #FFFFFF;          
            --text-main: #0F172A;        
            --text-muted: #64748B;       
            --border: #E2E8F0;           
            
            --primary: #2563EB;          
            --primary-bg: #EFF6FF;
            --success: #10B981;          
            --success-bg: #ECFDF5;
            --danger: #EF4444;           
            --danger-bg: #FEF2F2;
            --warning: #F59E0B;          
            --warning-bg: #FFFBEB;
            
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            
            --nav-h: 70px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: calc(var(--nav-h) + 20px);
            -webkit-font-smoothing: antialiased;
            margin: 0;
        }

        /* ── Modern Clean Header ── */
        .app-header {
            background: var(--surface);
            padding: 40px 20px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: var(--shadow-sm);
        }
        .header-text { display: flex; flex-direction: column; }
        .header-date { font-size: 12px; font-weight: 600; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .header-name { font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0; letter-spacing: -0.02em; }
        .header-avatar {
            width: 44px; height: 44px; border-radius: 50%; background: var(--primary-bg); color: var(--primary);
            display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;
            border: 1px solid var(--border); text-decoration: none;
        }

        /* ── Page Content ── */
        .page-content { padding: 20px 16px 0; }

        /* ── Stats Grid ── */
        .stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
        .stat-card {
            background: var(--surface); border-radius: var(--radius-lg); padding: 16px;
            border: 1px solid var(--border); box-shadow: var(--shadow-sm); display: flex; flex-direction: column;
        }
        .stat-icon {
            width: 32px; height: 32px; border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center; margin-bottom: 12px; font-size: 16px;
        }
        .stat-icon.sales { background: var(--success-bg); color: var(--success); }
        .stat-icon.bills { background: var(--primary-bg); color: var(--primary); }
        
        .stat-label { font-size: 13px; color: var(--text-muted); font-weight: 500; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; font-family: 'JetBrains Mono', monospace; color: var(--text-main); line-height: 1; letter-spacing: -0.02em; }

        /* ── Section Title ── */
        .section-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0 0 16px 4px; letter-spacing: -0.01em; }

        /* ── Route Card ── */
        .route-card {
            background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border);
            box-shadow: var(--shadow-sm); margin-bottom: 24px; overflow: hidden;
        }
        .route-header { padding: 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .route-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        .route-badge.pending { background: var(--warning-bg); color: var(--warning); }
        .route-badge.active { background: var(--success-bg); color: var(--success); }
        .route-badge.completed { background: var(--primary-bg); color: var(--primary); }
        .route-badge.rejected { background: var(--danger-bg); color: var(--danger); }
        
        .route-body { padding: 16px; }
        .route-name { font-size: 18px; font-weight: 700; margin-bottom: 6px; }
        .route-desc { font-size: 14px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; margin-bottom: 16px; }
        
        .btn-action {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size: 15px; font-weight: 600; padding: 14px;
            border-radius: var(--radius-md); border: none; width: 100%; transition: transform 0.1s; cursor: pointer;
        }
        .btn-action:active { transform: scale(0.98); }
        .btn-primary-action { background: var(--text-main); color: #fff; }
        .btn-success-action { background: var(--success); color: #fff; }
        .btn-danger-action { background: var(--danger-bg); color: var(--danger); border: 1px solid #FECACA; }
        
        .action-grid-buttons { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        /* Unified Form Inputs */
        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 14px 16px; font-size: 16px;
            font-family: 'Inter', sans-serif; font-weight: 500;
            color: var(--text-main); outline: none; transition: border 0.2s; margin-bottom: 16px;
        }
        .clean-input.mono { font-family: 'JetBrains Mono', monospace; }
        .clean-input:focus { border-color: var(--primary); background: #fff; }

        /* ── APP GRID (New Addition) ── */
        .app-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px 12px;
            margin-bottom: 32px;
            padding: 10px 4px;
        }
        .app-grid-btn {
            display: flex; flex-direction: column; align-items: center; text-align: center;
            text-decoration: none; color: var(--text-main);
            transition: transform 0.1s; cursor: pointer;
        }
        .app-grid-btn:active { transform: scale(0.95); }
        .ag-icon-wrapper {
            width: 58px; height: 58px; border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .ag-text { font-size: 12px; font-weight: 600; line-height: 1.25; color: var(--text-main); }
        
        /* Grid Colors */
        .ag-green { background: var(--success-bg); color: var(--success); }
        .ag-blue { background: var(--primary-bg); color: var(--primary); }
        .ag-amber { background: var(--warning-bg); color: var(--warning); }
        .ag-purple { background: #F3E8FF; color: #7E22CE; }
        .ag-rose { background: #FCE7F3; color: #BE185D; }
        .ag-gray { background: #F1F5F9; color: var(--text-muted); box-shadow: none; border: 1px solid var(--border); }

        /* ── List Menu Group ── */
        .menu-group {
            background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border);
            box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 24px;
        }
        .menu-item {
            display: flex; align-items: center; gap: 14px; padding: 16px; border-bottom: 1px solid var(--border);
            text-decoration: none; color: var(--text-main); background: var(--surface); transition: background 0.1s;
        }
        .menu-item:active { background: var(--bg-color); }
        .menu-item:last-child { border-bottom: none; }
        
        .menu-icon {
            width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0; background: var(--bg-color); color: var(--text-muted);
        }
        .menu-icon.red { color: var(--danger); background: var(--danger-bg); }
        .menu-icon.amber { color: var(--warning); background: var(--warning-bg); }
        .menu-icon.gray { color: var(--text-muted); background: var(--bg-color); border: 1px solid var(--border); }
        
        .menu-content { flex: 1; }
        .menu-title { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
        .menu-sub { font-size: 13px; color: var(--text-muted); }
        .menu-chevron { color: #CBD5E1; font-size: 16px; }

        /* ── Info Box ── */
        .info-box {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px; margin-bottom: 16px;
        }
        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 0; border-bottom: 1px dashed var(--border); font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: var(--text-muted); }
        .info-value { font-weight: 600; color: var(--text-main); font-family: 'JetBrains Mono', monospace; }
        .info-value.money { color: var(--success); }
        .info-value.expense { color: var(--danger); }

        /* ── Alert ── */
        .clean-alert {
            background: var(--warning-bg); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; margin-bottom: 24px; border: 1px solid #FEF3C7;
        }
        .clean-alert i { color: var(--warning); font-size: 20px; }
        .clean-alert h6 { margin: 0 0 4px 0; font-weight: 700; font-size: 14px; color: #92400E; }
        .clean-alert p { margin: 0; font-size: 13px; color: #B45309; }

        /* ── Bottom Nav (Glassmorphism) ── */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid rgba(226, 232, 240, 0.8);
            display: flex; justify-content: space-around; align-items: center;
            height: var(--nav-h); z-index: 1000; padding-bottom: env(safe-area-inset-bottom, 0);
        }
        .nav-tab {
            flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
            text-decoration: none; color: var(--text-muted); font-size: 11px; font-weight: 500;
            padding: 8px 0; transition: color 0.2s;
        }
        .nav-tab i { font-size: 22px; }
        .nav-tab.active { color: var(--primary); }
        .nav-fab-wrapper { position: relative; top: -16px; flex: 1; display: flex; flex-direction: column; align-items: center; text-decoration: none;}
        .nav-fab {
            width: 52px; height: 52px; border-radius: 50%;
            background: var(--primary); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; box-shadow: 0 8px 16px rgba(37, 99, 235, 0.25);
            transition: transform 0.1s;
        }
        .nav-fab:active { transform: scale(0.95); }
        .nav-fab-label { font-size: 11px; font-weight: 600; color: var(--text-main); margin-top: 6px; }

        /* Modals Overrides */
        .modal-content { border: none; border-radius: 24px 24px 0 0; }
        .modal-header { border-bottom: 1px solid var(--border); padding: 20px; }
        .modal-title { font-weight: 700; font-size: 18px; }
        .modal-body { padding: 20px; }

        /* Cash denominations inside Modal */
        .denom-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .denom-label { font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 600; width: 60px; text-align: right; }
        .denom-input { flex: 1; background: var(--bg-color); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px; font-family: 'JetBrains Mono', monospace; font-size: 16px; outline: none; }
        .denom-input:focus { border-color: var(--primary); background: #fff; }
    </style>
</head>
<body>

    <!-- ── Header ── -->
    <header class="app-header">
        <div class="header-text">
            <span class="header-date"><?php echo date('l, d M'); ?></span>
            <h1 class="header-name">Hi, <?php echo htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]); ?></h1>
        </div>
        <div class="header-actions d-flex gap-2 align-items-center">
<button id="installAppBtn" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">            <a href="../logout.php" class="header-avatar shadow-sm">
                <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
            </a>
        </div>
    </header>

    <main class="page-content">

        <!-- ── Stats Grid ── -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon sales"><i class="bi bi-wallet2"></i></div>
                <div class="stat-content">
                    <p class="stat-label">Route Sales</p>
                    <h3 class="stat-value">
                        <?php echo $today_sales >= 1000 ? 'Rs ' . number_format($today_sales / 1000, 1) . 'k' : 'Rs ' . number_format($today_sales); ?>
                    </h3>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bills"><i class="bi bi-receipt"></i></div>
                <div class="stat-content">
                    <p class="stat-label">Bills Issued</p>
                    <h3 class="stat-value"><?php echo $today_bills; ?></h3>
                </div>
            </div>
        </div>

        <!-- ── Dynamic Route Card ── -->
        <h2 class="section-title">Today's Route</h2>
        <?php if ($todays_route): ?>

            <!-- State 1: Assigned (Pending) -->
            <?php if ($todays_route['status'] == 'assigned'): ?>
            <div class="route-card">
                <div class="route-header">
                    <span class="route-badge pending"><i class="bi bi-clock-history"></i> Awaiting Accept</span>
                </div>
                <div class="route-body">
                    <h3 class="route-name"><?php echo htmlspecialchars($todays_route['route_name']); ?></h3>
                    <div class="route-desc">
                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($todays_route['driver_name'] ?: 'Self-Driven'); ?>
                    </div>

                    <?php if(!empty($load_manifest)): ?>
                    <div class="info-box mb-3">
                        <p class="text-muted fw-bold small text-uppercase mb-2">Load Manifest</p>
                        <div style="max-height:120px; overflow-y:auto;">
                            <?php foreach($load_manifest as $m): ?>
                            <div class="info-row" style="padding: 4px 0;">
                                <span class="info-label" style="font-size: 13px; font-family: 'Inter', sans-serif;"><?php echo htmlspecialchars($m['name']); ?></span>
                                <span class="info-value" style="font-size: 13px;"><?php echo $m['loaded_qty']; ?>x</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="action-grid-buttons">
                        <form method="POST">
                            <input type="hidden" name="route_action" value="reject">
                            <input type="hidden" name="assignment_id" value="<?php echo $todays_route['id']; ?>">
                            <button type="submit" class="btn-action btn-danger-action">Reject</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="route_action" value="accept">
                            <input type="hidden" name="assignment_id" value="<?php echo $todays_route['id']; ?>">
                            <button type="submit" class="btn-action btn-success-action">Accept</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- State 2: Accepted (Needs Meter) -->
            <?php elseif ($todays_route['status'] == 'accepted' && is_null($todays_route['start_meter'])): ?>
            <div class="route-card">
                <div class="route-header">
                    <span class="route-badge active"><i class="bi bi-key"></i> Ready to Start</span>
                </div>
                <div class="route-body">
                    <h3 class="route-name"><?php echo htmlspecialchars($todays_route['route_name']); ?></h3>
                    
                    <form method="POST" id="startDayForm" class="mt-3">
                        <input type="hidden" name="route_action" value="start_day">
                        <input type="hidden" name="assignment_id" value="<?php echo $todays_route['id']; ?>">
                        <input type="hidden" name="driver_id" value="<?php echo $todays_route['driver_id']; ?>">
                        <input type="hidden" name="latitude" id="start_lat" value="">
                        <input type="hidden" name="longitude" id="start_lng" value="">
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Start Meter (km)</label>
                        <input type="text" inputmode="numeric" name="start_meter" class="clean-input mono" required placeholder="e.g. 45200.5">
                        
                        <button type="button" class="btn-action btn-primary-action" onclick="processStartDay(this);">Start Day</button>
                    </form>
                </div>
            </div>

            <!-- State 3: Active Route -->
            <?php elseif ($todays_route['status'] == 'accepted' && !is_null($todays_route['start_meter'])): ?>
            <div class="route-card">
                <div class="route-header">
                    <span class="route-badge active"><i class="bi bi-broadcast"></i> Route Active</span>
                    <span class="text-muted small fw-bold" style="font-family: 'JetBrains Mono', monospace;">Start: <?php echo number_format($todays_route['start_meter'], 1); ?></span>
                </div>
                <div class="route-body">
                    <h3 class="route-name mb-4"><?php echo htmlspecialchars($todays_route['route_name']); ?></h3>
                    <button type="button" class="btn-action btn-danger-action" data-bs-toggle="modal" data-bs-target="#endDayModal" style="background: var(--danger); color: white;">
                        End Route & Day
                    </button>
                </div>
            </div>

            <!-- State 4: Completed -->
            <?php elseif (in_array($todays_route['status'], ['completed', 'unloaded'])): ?>
            <div class="route-card">
                <div class="route-header">
                    <span class="route-badge completed"><i class="bi bi-check2-all"></i> Completed</span>
                    <?php if($todays_route['status'] == 'unloaded'): ?>
                        <span class="badge bg-success border-0 px-2 py-1"><i class="bi bi-shield-check"></i> Verified</span>
                    <?php endif; ?>
                </div>
                <div class="route-body">
                    <h3 class="route-name mb-3"><?php echo htmlspecialchars($todays_route['route_name']); ?></h3>
                    <div class="info-box mb-3">
                        <div class="info-row">
                            <span class="info-label">Distance</span>
                            <span class="info-value"><?php echo number_format($todays_route['end_meter'] - $todays_route['start_meter'], 1); ?> km</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Gross Sales</span>
                            <span class="info-value money">Rs <?php echo number_format($today_sales, 2); ?></span>
                        </div>
                    </div>
                    <a href="route_summary.php?assignment_id=<?php echo $todays_route['id']; ?>" class="btn-full" style="display: block; text-decoration: none;">
                        <i class="bi bi-file-earmark-text"></i> View Route Summary
                    </a>
                </div>
            </div>

            <!-- State 5: Rejected -->
            <?php elseif ($todays_route['status'] == 'rejected'): ?>
            <div class="clean-alert" style="background: var(--danger-bg); border-color: #FECACA;">
                <i class="bi bi-x-circle-fill" style="color: var(--danger);"></i>
                <div>
                    <h6 style="color: var(--danger);">Load Rejected</h6>
                    <p style="color: #991B1B;">You declined today's dispatch assignment.</p>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="clean-alert" style="background: var(--surface); border-color: var(--border);">
                <i class="bi bi-truck text-muted"></i>
                <div>
                    <h6 class="text-dark">No Active Route</h6>
                    <p class="text-muted">You haven't been assigned a vehicle dispatch for today.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Pending Orders Alert -->
        <?php if($pending_orders > 0): ?>
        <div class="clean-alert">
            <i class="bi bi-exclamation-circle-fill"></i>
            <div>
                <h6>Action Required</h6>
                <p>You have <?php echo $pending_orders; ?> pending payment collections.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── NEW APP GRID SECTIONS ── -->
        <h2 class="section-title mt-4">Sales Tools</h2>
        <div class="app-grid">
            
            <!-- Row 1 -->
            <a href="create_order.php" class="app-grid-btn">
                <div class="ag-icon-wrapper ag-green"><i class="bi bi-receipt"></i></div>
                <span class="ag-text">Create<br>Invoice</span>
            </a>

            <a href="vehicle_stock.php" class="app-grid-btn">
                <div class="ag-icon-wrapper ag-blue"><i class="bi bi-truck-front"></i></div>
                <span class="ag-text">Vehicle<br>Stock</span>
            </a>

            <?php if($assignment_id && $todays_route['status'] == 'accepted' && !is_null($todays_route['start_meter'])): ?>
                <a href="#" class="app-grid-btn" data-bs-toggle="modal" data-bs-target="#expensesModal">
                    <div class="ag-icon-wrapper ag-amber"><i class="bi bi-fuel-pump"></i></div>
                    <span class="ag-text">Route<br>Expenses</span>
                </a>
            <?php else: ?>
                <div class="app-grid-btn" style="opacity: 0.5;" onclick="alert('Start your route first to log expenses.')">
                    <div class="ag-icon-wrapper ag-gray"><i class="bi bi-fuel-pump"></i></div>
                    <span class="ag-text">Route<br>Expenses</span>
                </div>
            <?php endif; ?>

            <!-- Row 2 -->
            <a href="todays_bills.php<?php echo $assignment_id ? '?assignment_id='.$assignment_id : ''; ?>" class="app-grid-btn">
                <div class="ag-icon-wrapper ag-purple"><i class="bi bi-list-check"></i></div>
                <span class="ag-text">Active<br>Bills</span>
            </a>

            <a href="customers.php?filter=route" class="app-grid-btn">
                <div class="ag-icon-wrapper ag-rose"><i class="bi bi-people"></i></div>
                <span class="ag-text">Route<br>Customers</span>
            </a>

            <a href="create_order.php?general=true" class="app-grid-btn">
                <div class="ag-icon-wrapper ag-gray"><i class="bi bi-boxes"></i></div>
                <span class="ag-text">General<br>Invoice</span>
            </a>

            <a href="../pages/create_quotation.php" class="app-grid-btn">
                <div class="ag-icon-wrapper" style="background: #E0F2FE; color: #0369A1;"><i class="bi bi-file-earmark-medical"></i></div>
                <span class="ag-text">Product<br>Quotation</span>
            </a>

            <?php if($assignment_id && $todays_route['status'] == 'accepted' && !is_null($todays_route['start_meter'])): ?>
                <a href="#" class="app-grid-btn" data-bs-toggle="modal" data-bs-target="#creditCollectionModal">
                    <div class="ag-icon-wrapper" style="background: #FEF3C7; color: #D97706;"><i class="bi bi-cash-coin"></i></div>
                    <span class="ag-text">Credit<br>Collection</span>
                </a>
            <?php else: ?>
                <div class="app-grid-btn" style="opacity: 0.5;" onclick="alert('Start your route first to collect payments.')">
                    <div class="ag-icon-wrapper ag-gray"><i class="bi bi-cash-coin"></i></div>
                    <span class="ag-text">Credit<br>Collection</span>
                </div>
            <?php endif; ?>

        </div>

        <h2 class="section-title">Other Tools</h2>
        <div class="menu-group mb-5">
            <?php if($assignment_id && $todays_route['status'] == 'accepted' && !is_null($todays_route['start_meter'])): ?>
            <a href="log_unproductive.php" class="menu-item">
                <div class="menu-icon red"><i class="bi bi-x-circle-fill"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Log Unproductive</div>
                    <div class="menu-sub">Record shops that didn't buy</div>
                </div>
                <i class="bi bi-chevron-right menu-chevron"></i>
            </a>
            <?php else: ?>
            <div class="menu-item" style="opacity: 0.5; cursor: pointer;" onclick="alert('Please start your route first to log unproductive visits.')">
                <div class="menu-icon red"><i class="bi bi-x-circle-fill"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Log Unproductive</div>
                    <div class="menu-sub">Record shops that didn't buy</div>
                </div>
                <i class="bi bi-lock-fill menu-chevron"></i>
            </div>
            <?php endif; ?>

            <a href="process_return.php" class="menu-item">
                <div class="menu-icon red"><i class="bi bi-arrow-return-left"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Process Returns</div>
                    <div class="menu-sub">Handle damaged goods</div>
                </div>
                <i class="bi bi-chevron-right menu-chevron"></i>
            </a>

            <a href="analytics.php" class="menu-item">
                <div class="menu-icon amber"><i class="bi bi-bar-chart-fill"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Analytics</div>
                    <div class="menu-sub">View KPIs and targets</div>
                </div>
                <i class="bi bi-chevron-right menu-chevron"></i>
            </a>

            <a href="route_history.php" class="menu-item">
                <div class="menu-icon gray"><i class="bi bi-clock-history"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Route History</div>
                    <div class="menu-sub">View past trips & records</div>
                </div>
                <i class="bi bi-chevron-right menu-chevron"></i>
            </a>

            <a href="../pages/create_quotation.php" class="menu-item">
                <div class="menu-icon" style="background: #E0F2FE; color: #0369A1;"><i class="bi bi-file-earmark-pdf-fill"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Generate Quotation</div>
                    <div class="menu-sub">Create PDF for supermarkets</div>
                </div>
                <i class="bi bi-chevron-right menu-chevron"></i>
            </a>
        </div>

    </main>


    <!-- ═══════════════════════════════════════
         EXPENSES MODAL
    ═══════════════════════════════════════ -->
    <?php if ($assignment_id): ?>
    <div class="modal fade" id="expensesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-bottom">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Log Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    
                    <?php if(count($route_expenses) > 0): ?>
                    <div class="info-box mb-4">
                        <?php foreach($route_expenses as $exp): ?>
                        <div class="info-row align-items-start" style="font-family: 'Inter', sans-serif;">
                            <div>
                                <span class="d-block fw-bold text-dark"><?php echo htmlspecialchars($exp['type']); ?></span>
                                <span class="d-block text-muted small"><?php echo htmlspecialchars($exp['description']); ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-danger fw-bold" style="font-family: 'JetBrains Mono', monospace;">-Rs <?php echo number_format($exp['amount'], 2); ?></span>
                                <form method="POST" onsubmit="return confirm('Delete expense?');" style="margin:0;">
                                    <input type="hidden" name="route_action" value="delete_expense">
                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                                    <input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-muted p-0"><i class="bi bi-x-circle-fill" style="font-size: 16px;"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="route_action" value="add_expense">
                        <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Type</label>
                        <select name="expense_type" class="clean-input" required>
                            <option value="Fuel">Fuel</option>
                            <option value="Meals">Meals / Refreshments</option>
                            <option value="Vehicle Repair">Vehicle Repair / Tolls</option>
                            <option value="Other">Other</option>
                        </select>
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Amount (Rs)</label>
                        <input type="number" step="0.01" name="expense_amount" class="clean-input mono" required placeholder="0.00">
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Note</label>
                        <input type="text" name="expense_desc" class="clean-input mb-4" placeholder="Optional context">
                        
                        <button type="submit" class="btn-action btn-primary-action">Save Expense</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>


    <!-- ═══════════════════════════════════════
         CREDIT COLLECTION MODAL
    ═══════════════════════════════════════ -->
    <?php if ($assignment_id): ?>
    <div class="modal fade" id="creditCollectionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-bottom">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Credit Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="route_action" value="record_credit_payment">
                        <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Customer (With Outstanding)</label>
                        <select name="customer_id" class="clean-input mb-3" required>
                            <option value="">Select Customer...</option>
                            <?php foreach($customers_with_dues as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?> (Due: Rs <?php echo number_format($c['outstanding'], 2); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Amount (Rs)</label>
                        <input type="number" step="0.01" name="payment_amount" class="clean-input mono mb-3" required placeholder="0.00">
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Payment Method</label>
                        <select name="payment_method" class="clean-input mb-3" required onchange="toggleChequeFields(this.value)">
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                        </select>

                        <div id="chequeFields" style="display: none;">
                            <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Bank Name</label>
                            <input type="text" name="cheque_bank" class="clean-input mb-3" placeholder="e.g. BOC">
                            
                            <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Cheque Number</label>
                            <input type="text" name="cheque_number" class="clean-input mb-3" placeholder="123456">
                            
                            <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Banking Date</label>
                            <input type="date" name="cheque_date" class="clean-input mb-3">
                        </div>
                        
                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">Reference Note (Optional)</label>
                        <input type="text" name="payment_reference" class="clean-input mb-4" placeholder="Note or receipt number">
                        
                        <button type="submit" class="btn-action btn-success-action" style="background: var(--success); color: white;">Record Payment</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════
         END DAY MODAL
    ═══════════════════════════════════════ -->
    <?php if ($todays_route && $todays_route['status'] == 'accepted' && !is_null($todays_route['start_meter'])): ?>
    <div class="modal fade" id="endDayModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-bottom">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">End Route</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Handover Summary -->
                    <div class="info-box mb-4">
                        <h6 class="fw-bold mb-3" style="font-family: 'Inter', sans-serif;">Expected Handover</h6>
                        <div class="info-row" style="font-family: 'Inter', sans-serif;">
                            <span class="info-label">Direct Cash Sales</span>
                            <span class="info-value text-success money">Rs <?php echo number_format($cash_sales, 2); ?></span>
                        </div>
                        <div class="info-row" style="font-family: 'Inter', sans-serif;">
                            <span class="info-label">Credit Collected (Cash)</span>
                            <span class="info-value text-success money">Rs <?php echo number_format($credit_cash_collected, 2); ?></span>
                        </div>
                        <div class="info-row" style="font-family: 'Inter', sans-serif;">
                            <span class="info-label">Expenses</span>
                            <span class="info-value text-danger expense">-Rs <?php echo number_format($total_expenses, 2); ?></span>
                        </div>
                        <div class="info-row" style="border-top: 1px dashed var(--border); margin-top: 4px; font-family: 'Inter', sans-serif;">
                            <span class="info-label fw-bold text-dark">Net Cash</span>
                            <span class="info-value fw-bold text-primary fs-5 money">Rs <?php echo number_format(max(0, $cash_sales + $credit_cash_collected - $total_expenses), 2); ?></span>
                            <input type="hidden" id="expected_cash_val" value="<?php echo max(0, $cash_sales + $credit_cash_collected - $total_expenses); ?>">
                        </div>
                    </div>

                    <form method="POST" id="endDayForm">
                        <input type="hidden" name="route_action" value="end_day">
                        <input type="hidden" name="assignment_id" value="<?php echo $todays_route['id']; ?>">
                        <input type="hidden" name="latitude" id="end_lat" value="">
                        <input type="hidden" name="longitude" id="end_lng" value="">

                        <!-- Cash denomination calculator -->
                        <div class="info-box mb-4 bg-light border-0">
                            <h6 class="fw-bold mb-3" style="font-family: 'Inter', sans-serif;">Count Cash Drawer</h6>
                            <?php
                            $denoms = [5000 => '5,000', 2000 => '2,000', 1000 => '1,000', 500 => '500', 100 => '100', 50 => '50', 20 => '20'];
                            foreach($denoms as $val => $label): ?>
                            <div class="denom-row">
                                <span class="denom-label"><?php echo $label; ?></span>
                                <span class="text-muted small">x</span>
                                <input type="number" id="denom_<?php echo $val; ?>" name="cash_<?php echo $val; ?>" class="denom-input cash-calc" min="0" inputmode="numeric" placeholder="0">
                            </div>
                            <?php endforeach; ?>
                            <div class="denom-row">
                                <span class="denom-label text-muted">Coins</span>
                                <span class="text-muted small">+</span>
                                <input type="number" step="0.01" id="denom_coins" name="cash_coins" class="denom-input cash-calc" min="0" inputmode="decimal" placeholder="0.00">
                            </div>
                            
                            <div class="info-row mt-3 border-top pt-2" style="font-family: 'Inter', sans-serif;">
                                <span class="info-label fw-bold">Actual Count</span>
                                <span class="info-value text-success fs-5 money" id="actual_cash_total">0.00</span>
                                <input type="hidden" name="actual_cash_total_input" id="actual_cash_total_input" value="0">
                            </div>
                            <div class="info-row" style="font-family: 'Inter', sans-serif;">
                                <span class="info-label">Variance</span>
                                <span class="info-value money" id="cash_diff">0.00</span>
                            </div>
                        </div>
                        
                        <input type="hidden" name="expected_cash_val" id="expected_cash_val_form" value="<?php echo max(0, $cash_sales + $credit_cash_collected - $total_expenses); ?>">

                        <label class="text-muted fw-bold small text-uppercase mb-2 d-block">End Meter (km)</label>
                        <input type="text" inputmode="numeric" name="end_meter" class="clean-input mono mb-4" required placeholder="e.g. 45250.5">

                        <button type="button" class="btn-action btn-danger-action" style="background: var(--danger); color: white;" onclick="processEndDay(this);">Complete Day</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Updated Bottom Nav (Glassmorphism) ── -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-tab active">
            <i class="bi bi-house-door-fill"></i> Home
        </a>
        <a href="catalog.php" class="nav-tab">
            <i class="bi bi-grid"></i> Catalog
        </a>
        <div class="nav-fab-wrapper">
            <a href="create_order.php" class="nav-fab">
                <i class="bi bi-plus-lg"></i>
            </a>
            <span class="nav-fab-label">POS</span>
        </div>
        <a href="customers.php" class="nav-tab">
            <i class="bi bi-people-fill"></i> Customers
        </a>
        <a href="analytics.php" class="nav-tab">
            <i class="bi bi-bar-chart-line-fill"></i> Stats
        </a>
    </nav>


    <script>
        let deferredPrompt = null;

// ALWAYS keep button visible
const installBtn = document.getElementById('installAppBtn');

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
});

function installApp() {
    if (deferredPrompt) {
        deferredPrompt.prompt();

        deferredPrompt.userChoice.then((r) => {
            deferredPrompt = null;
        });

    } else {
        // fallback for unsupported devices
        alert("To install:\nTap menu (⋮) → Add to Home screen");
    }
}

        // ── Meter formatter ──
        document.querySelectorAll('.clean-input[name*="meter"]').forEach(input => {
            input.addEventListener('input', function() {
                let val = this.value.replace(/[^0-9]/g, '');
                if (val.length > 6) val = val.substring(0, 6);
                this.value = val.length === 6 ? val.substring(0, 5) + '.' + val.substring(5) : val;
            });
        });

        // ── Cash Calculator ──
        function calculateCash() {
            let total = 0;
            [5000, 2000, 1000, 500, 100, 50, 20].forEach(d => {
                total += (parseInt(document.getElementById('denom_' + d)?.value) || 0) * d;
            });
            total += parseFloat(document.getElementById('denom_coins')?.value) || 0;

            document.getElementById('actual_cash_total').innerText = total.toFixed(2);
            document.getElementById('actual_cash_total_input').value = total.toFixed(2);

            const expected = parseFloat(document.getElementById('expected_cash_val')?.value) || 0;
            const diff = total - expected;
            const el = document.getElementById('cash_diff');
            if (el) {
                el.innerText = (diff >= 0 ? '+' : '') + diff.toFixed(2);
                el.style.color = diff >= 0 ? 'var(--success)' : 'var(--danger)';
            }
        }
        document.querySelectorAll('.cash-calc').forEach(i => i.addEventListener('input', calculateCash));

        // ── Start Day ──
        function processStartDay(btn) {
            if (!document.querySelector('input[name="start_meter"]').value) {
                alert('Please enter start meter.'); return;
            }
            if (!confirm('Start day and log attendance?')) return;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btn.disabled = true;
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    pos => { document.getElementById('start_lat').value = pos.coords.latitude; document.getElementById('start_lng').value = pos.coords.longitude; document.getElementById('startDayForm').submit(); },
                    () => document.getElementById('startDayForm').submit(),
                    { enableHighAccuracy: true, timeout: 5000 }
                );
            } else { document.getElementById('startDayForm').submit(); }
        }

        // ── End Day ──
        function processEndDay(btn) {
            if (!document.querySelector('input[name="end_meter"]').value) {
                alert('Please enter end meter.'); return;
            }
            if (!confirm('Finalize route and end day?')) return;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btn.disabled = true;
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    pos => { document.getElementById('end_lat').value = pos.coords.latitude; document.getElementById('end_lng').value = pos.coords.longitude; document.getElementById('endDayForm').submit(); },
                    () => document.getElementById('endDayForm').submit(),
                    { enableHighAccuracy: true, timeout: 5000 }
                );
            } else { document.getElementById('endDayForm').submit(); }
        }

        function toggleChequeFields(method) {
            const chequeFields = document.getElementById('chequeFields');
            if (method === 'Cheque') {
                chequeFields.style.display = 'block';
                chequeFields.querySelectorAll('input').forEach(el => el.required = true);
            } else {
                chequeFields.style.display = 'none';
                chequeFields.querySelectorAll('input').forEach(el => el.required = false);
            }
        }

        // ── Background Location Ping ──
        <?php if ($todays_route && $todays_route['status'] == 'accepted' && !is_null($todays_route['start_meter'])): ?>
        function sendLocationPing() {
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition(pos => {
                fetch('../ajax/log_location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ latitude: pos.coords.latitude, longitude: pos.coords.longitude, activity: 'background_ping' })
                }).catch(() => {});
            }, () => {}, { enableHighAccuracy: true });
        }
        sendLocationPing();
        setInterval(sendLocationPing, 120000);
        <?php endif; ?>
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>