<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];

// --- FILTERING ---
$route_filter = isset($_GET['route_id']) ? $_GET['route_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$whereClause = "rr.rep_id = ?";
$params = [$rep_id];

if ($route_filter !== '') {
    $whereClause .= " AND rr.route_id = ?";
    $params[] = $route_filter;
}
if ($date_from !== '') {
    $whereClause .= " AND rr.assign_date >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $whereClause .= " AND rr.assign_date <= ?";
    $params[] = $date_to;
}

// Fetch Rep's Route History
$query = "
    SELECT rr.*, r.name as route_name,
           (SELECT COUNT(id) FROM orders WHERE assignment_id = rr.id) as bills_count,
           (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE assignment_id = rr.id) as gross_sales,
           (SELECT COALESCE(SUM(amount), 0) FROM route_expenses WHERE assignment_id = rr.id) as total_expenses
    FROM rep_routes rr 
    JOIN routes r ON rr.route_id = r.id 
    WHERE $whereClause 
    ORDER BY rr.assign_date DESC, rr.id DESC
    LIMIT 50
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll();

// Fetch Routes for Dropdown
$routes = $pdo->query("SELECT id, name FROM routes ORDER BY name ASC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Route History - Rep App</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Google Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    
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

        /* ── Modern Header ── */
        .app-header {
            background: var(--surface);
            padding: 20px 20px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .header-stack { display: flex; align-items: center; gap: 12px; }
        .back-btn {
            color: var(--text-main); font-size: 20px;
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: var(--bg-color); transition: background 0.2s;
            text-decoration: none;
        }
        .back-btn:active { background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: -0.01em; }
        .header-sub { font-size: 12px; color: var(--text-muted); font-weight: 500; display: block; }

        /* ── Content Area ── */
        .page-content { padding: 16px; }
        
        .results-meta {
            font-size: 11px; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;
            display: flex; justify-content: space-between; align-items: center;
        }

        /* ── Filters ── */
        .filter-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .filter-title {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.05em;
        }
        
        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px 14px; font-size: 14px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s;
        }
        .clean-input.mono { font-family: 'JetBrains Mono', monospace; font-size: 13px; }
        .clean-input:focus { border-color: var(--primary); background: #fff; }
        select.clean-input {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2364748B%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 10px auto;
            padding-right: 40px; font-weight: 500;
        }

        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 12px;
            font-size: 14px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: inline-block;
        }
        .btn-full:active { transform: scale(0.98); }
        .btn-full.outline { background: var(--surface); border: 1px solid var(--border); color: var(--text-main); }
        .btn-full.danger-outline { background: var(--danger-bg); border: 1px solid #FECACA; color: var(--danger); }

        /* ── Route Card ── */
        .route-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }
        .route-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 12px;
        }
        .route-name { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 4px 0 0 0; }
        .route-date { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-muted); font-weight: 500; }
        
        .badge-custom {
            display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 6px;
        }
        .badge-custom.warning { background: var(--warning-bg); color: var(--warning); }
        .badge-custom.success { background: var(--success-bg); color: var(--success); }
        .badge-custom.danger { background: var(--danger-bg); color: var(--danger); }

        /* ── Info Box ── */
        .info-box {
            background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px; margin-bottom: 16px;
        }
        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 6px 0; font-size: 13px;
        }
        .info-label { color: var(--text-muted); font-weight: 500; }
        .info-value { font-weight: 600; color: var(--text-main); }
        .info-value.money { font-family: 'JetBrains Mono', monospace; color: var(--success); }
        .info-value.expenses { font-family: 'JetBrains Mono', monospace; color: var(--danger); }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--primary-bg); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid #BFDBFE; margin-bottom: 20px;
        }
        .clean-alert i { font-size: 24px; color: var(--primary); }
        .clean-alert h6 { margin: 0 0 4px 0; font-weight: 700; font-size: 15px; color: #1E3A8A; }
        .clean-alert p { margin: 0; font-size: 13px; color: #1E40AF; }

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
    </style>
</head>
<body>

    <!-- Header -->
    <header class="app-header">
        <div class="header-stack">
            <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <div>
                <h1 class="header-title">Route History</h1>
                <span class="header-sub">My Past Trips</span>
            </div>
        </div>
    </header>

    <div class="page-content">
        
        <!-- Filter Section -->
        <div class="filter-card">
            <h6 class="filter-title"><i class="bi bi-funnel"></i> Filter Records</h6>
            <form method="GET" action="" id="filterForm">
                <select name="route_id" class="clean-input mb-3" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Routes</option>
                    <?php foreach($routes as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo $route_filter == $r['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <div class="row g-2">
                    <div class="col-6">
                        <input type="date" name="date_from" class="clean-input mono" value="<?php echo htmlspecialchars($date_from); ?>" onchange="document.getElementById('filterForm').submit();">
                    </div>
                    <div class="col-6">
                        <input type="date" name="date_to" class="clean-input mono" value="<?php echo htmlspecialchars($date_to); ?>" onchange="document.getElementById('filterForm').submit();">
                    </div>
                </div>
                
                <?php if($route_filter || $date_from || $date_to): ?>
                    <a href="route_history.php" class="btn-full danger-outline mt-3">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($history)): ?>
            <div class="clean-alert">
                <i class="bi bi-journal-x"></i>
                <div>
                    <h6>No History Found</h6>
                    <p>You have no assigned routes matching these filters.</p>
                </div>
            </div>
        <?php else: ?>
            
            <div class="results-meta">
                <span>Records (<?php echo count($history); ?>)</span>
            </div>

            <?php foreach ($history as $h): 
                // Determine Badge Style
                if(in_array($h['status'], ['assigned', 'accepted'])) {
                    $badgeClass = 'warning';
                    $badgeIcon = 'bi-play-circle';
                    $badgeText = 'Active';
                } elseif(in_array($h['status'], ['completed', 'unloaded'])) {
                    $badgeClass = 'success';
                    $badgeIcon = 'bi-check-circle';
                    $badgeText = 'Closed';
                } else {
                    $badgeClass = 'danger';
                    $badgeIcon = 'bi-x-circle';
                    $badgeText = 'Rejected';
                }
            ?>
            <div class="route-card">
                <div class="route-header">
                    <div>
                        <div class="route-date"><i class="bi bi-calendar-event me-1"></i><?php echo date('D, M d, Y', strtotime($h['assign_date'])); ?></div>
                        <h3 class="route-name"><?php echo htmlspecialchars($h['route_name']); ?></h3>
                    </div>
                    <span class="badge-custom <?php echo $badgeClass; ?>">
                        <i class="bi <?php echo $badgeIcon; ?>"></i> <?php echo $badgeText; ?>
                    </span>
                </div>

                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label text-uppercase" style="font-size: 11px;">Bills Generated</span>
                        <span class="info-value fs-6"><?php echo $h['bills_count']; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label text-uppercase" style="font-size: 11px;">Gross Sales</span>
                        <span class="info-value money fs-6">Rs <?php echo number_format($h['gross_sales'], 2); ?></span>
                    </div>
                    
                    <?php if($h['total_expenses'] > 0): ?>
                    <div class="info-row" style="border-top: 1px dashed var(--border); margin-top: 6px; padding-top: 8px;">
                        <span class="info-label text-danger text-uppercase" style="font-size: 11px;">Recorded Expenses</span>
                        <span class="info-value expenses">- Rs <?php echo number_format($h['total_expenses'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if($h['status'] != 'rejected'): ?>
                    <a href="todays_bills.php?assignment_id=<?php echo $h['id']; ?>" class="btn-full outline">
                        <i class="bi bi-receipt me-1"></i> View Route Bills
                    </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <!-- Bottom Nav -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-tab">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>