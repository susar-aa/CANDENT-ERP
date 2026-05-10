<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];
$message = '';

// --- AUTO DB MIGRATION FOR LOCATION FIELDS ---
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN latitude DECIMAL(10, 8) NULL");
    $pdo->exec("ALTER TABLE customers ADD COLUMN longitude DECIMAL(11, 8) NULL");
} catch(PDOException $e) {}

// --- Handle Add Customer POST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_customer') {
    $name = trim($_POST['name']);
    $owner_name = trim($_POST['owner_name']);
    $phone = trim($_POST['phone']);
    $whatsapp = trim($_POST['whatsapp']);
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address']);
    $route_id = !empty($_POST['route_id']) ? (int)$_POST['route_id'] : null;
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO customers (name, owner_name, phone, whatsapp, email, address, rep_id, route_id, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $owner_name, $phone, $whatsapp, $email, $address, $rep_id, $route_id, $latitude, $longitude])) {
            $message = "<div class='clean-alert success-alert mb-3'><i class='bi bi-check-circle-fill'></i> <div><h6 class='m-0 fw-bold'>Success</h6><p class='m-0 small'>Customer added successfully!</p></div></div>";
        } else {
            $message = "<div class='clean-alert error-alert mb-3'><i class='bi bi-exclamation-triangle-fill'></i> <div><h6 class='m-0 fw-bold'>Error</h6><p class='m-0 small'>Error adding customer.</p></div></div>";
        }
    }
}

// --- Handle Edit Customer POST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_customer') {
    $cust_id = (int)$_POST['customer_id'];
    $name = trim($_POST['name']);
    $owner_name = trim($_POST['owner_name']);
    $phone = trim($_POST['phone']);
    $whatsapp = trim($_POST['whatsapp']);
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address']);
    $route_id = !empty($_POST['route_id']) ? (int)$_POST['route_id'] : null;
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    if (!empty($name) && $cust_id > 0) {
        $stmt = $pdo->prepare("UPDATE customers SET name=?, owner_name=?, phone=?, whatsapp=?, email=?, address=?, route_id=?, latitude=?, longitude=? WHERE id=? AND rep_id=?");
        if ($stmt->execute([$name, $owner_name, $phone, $whatsapp, $email, $address, $route_id, $latitude, $longitude, $cust_id, $rep_id])) {
            $message = "<div class='clean-alert success-alert mb-3'><i class='bi bi-check-circle-fill'></i> <div><h6 class='m-0 fw-bold'>Updated</h6><p class='m-0 small'>Customer details updated successfully!</p></div></div>";
        } else {
            $message = "<div class='clean-alert error-alert mb-3'><i class='bi bi-exclamation-triangle-fill'></i> <div><h6 class='m-0 fw-bold'>Error</h6><p class='m-0 small'>Error updating customer.</p></div></div>";
        }
    }
}

// Check which filter is active
$filter = isset($_GET['filter']) && $_GET['filter'] === 'all' ? 'all' : 'route';

// --- 1. Fetch Today's Active Route ---
$routeStmt = $pdo->prepare("
    SELECT r.id as route_id, r.name as route_name 
    FROM rep_routes rr 
    JOIN routes r ON rr.route_id = r.id 
    WHERE rr.rep_id = ? AND rr.assign_date = CURDATE() AND rr.status = 'accepted' AND rr.start_meter IS NOT NULL
    ORDER BY rr.id DESC LIMIT 1
");
$routeStmt->execute([$rep_id]);
$active_route = $routeStmt->fetch();

// --- 2. Build Query & Fetch Customers ---
$whereClauseParts = [];
$params = [];

if ($filter === 'route') {
    if ($active_route) {
        $whereClauseParts[] = "c.route_id = ?";
        $params[] = $active_route['route_id'];
    } else {
        $whereClauseParts[] = "1 = 0"; // Force 0 results if no active route
    }
}

$whereSql = "";
if (count($whereClauseParts) > 0) {
    $whereSql = "WHERE " . implode(' AND ', $whereClauseParts);
}

$custStmt = $pdo->prepare("
    SELECT c.*, 
           (SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE customer_id = c.id) as outstanding 
    FROM customers c 
    $whereSql 
    ORDER BY c.name ASC
");
$custStmt->execute($params);
$customers = $custStmt->fetchAll();

// Fetch all routes for the Add Customer form
$routes = $pdo->query("SELECT id, name FROM routes ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Customers — Fintrix</title>
    
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
        .btn-add {
            background: var(--primary-bg); color: var(--primary);
            border: none; width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; transition: transform 0.1s;
        }
        .btn-add:active { transform: scale(0.95); }

        /* ── Sticky Search & Filters ── */
        .search-area {
            background: var(--bg-color);
            padding: 16px;
            position: sticky;
            top: 76px; /* Below header */
            z-index: 99;
        }
        .filter-pills {
            display: flex; background: var(--surface); border: 1px solid var(--border);
            border-radius: 100px; padding: 4px; margin-bottom: 16px; box-shadow: var(--shadow-sm);
        }
        .filter-pill {
            flex: 1; text-align: center; padding: 8px 0; font-size: 13px; font-weight: 600;
            border-radius: 100px; text-decoration: none; color: var(--text-muted);
            transition: all 0.2s;
        }
        .filter-pill.active { background: var(--text-main); color: #fff; }
        
        .search-wrapper { position: relative; }
        .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 16px; }
        .search-input {
            width: 100%; background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 12px 40px 12px 44px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s; box-shadow: var(--shadow-sm);
        }
        .search-input:focus { border-color: var(--primary); }
        .clear-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); background: none; border: none; font-size: 18px; padding: 4px;
        }

        /* ── Content Area ── */
        .page-content { padding: 0 16px; }
        .results-meta {
            font-size: 11px; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;
        }

        /* ── Customer Card ── */
        .cust-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }
        .cust-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 16px;
        }
        .cust-name { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0; }
        .cust-address {
            font-size: 13px; color: var(--text-muted); display: flex; align-items: flex-start; gap: 6px;
            line-height: 1.4; margin: 0;
        }
        
        .badge-custom {
            display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 6px; font-family: 'JetBrains Mono', monospace;
        }
        .badge-custom.danger { background: var(--danger-bg); color: var(--danger); }
        .badge-custom.success { background: var(--success-bg); color: var(--success); }

        .action-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .btn-act {
            text-decoration: none; border: none; padding: 10px 0; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px;
            transition: transform 0.1s;
        }
        .btn-act:active { transform: scale(0.96); }
        .btn-act.disabled { opacity: 0.4; pointer-events: none; }
        
        .btn-act.blue { background: var(--primary-bg); color: var(--primary); }
        .btn-act.green { background: var(--success-bg); color: var(--success); }
        .btn-act.gray { background: var(--bg-color); color: var(--text-main); border: 1px solid var(--border); }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border); margin-bottom: 20px;
        }
        .clean-alert.success-alert { background: var(--success-bg); border-color: #A7F3D0; color: var(--success); }
        .clean-alert.error-alert { background: var(--danger-bg); border-color: #FECACA; color: var(--danger); }

        /* ── Offcanvas Form ── */
        .offcanvas-bottom { border-radius: 24px 24px 0 0 !important; border: none; height: 85vh !important; box-shadow: 0 -8px 40px rgba(0,0,0,0.12); }
        .offcanvas-header { padding: 20px; border-bottom: 1px solid var(--border); }
        .offcanvas-title { font-size: 18px; font-weight: 700; color: var(--text-main); }
        
        .form-label-sm { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; display: block; letter-spacing: 0.05em; }
        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 14px 16px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none; transition: border 0.2s;
        }
        .clean-input:focus { border-color: var(--primary); background: #fff; }
        textarea.clean-input { resize: none; }
        select.clean-input { appearance: none; background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2364748B%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E"); background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 10px auto; padding-right: 40px; }

        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 14px; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: transform 0.1s;
        }
        .btn-full:active { transform: scale(0.98); }
        .btn-full.dark { background: var(--text-main); color: #fff; }
        .btn-full.outline { background: var(--surface); border: 1px solid var(--border); color: var(--text-main); }

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
                <h1 class="header-title"><?php echo $filter === 'all' ? 'All Customers' : 'Route Customers'; ?></h1>
                <span class="header-sub"><?php echo ($filter === 'route' && $active_route) ? htmlspecialchars($active_route['route_name']) : 'Company Directory'; ?></span>
            </div>
        </div>
        <button class="btn-add" data-bs-toggle="offcanvas" data-bs-target="#addCustomerCanvas">
            <i class="bi bi-person-plus-fill"></i>
        </button>
    </header>

    <!-- Toggles & Search -->
    <div class="search-area">
        <?php echo $message; ?>
        <div class="filter-pills">
            <a href="customers.php?filter=route" class="filter-pill <?php echo $filter === 'route' ? 'active' : ''; ?>">Active Route</a>
            <a href="customers.php?filter=all" class="filter-pill <?php echo $filter === 'all' ? 'active' : ''; ?>">All Clients</a>
        </div>
        <div class="search-wrapper">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="liveSearchInput" class="search-input" placeholder="Search name, address...">
            <button type="button" class="clear-btn d-none" id="clearSearchBtn"><i class="bi bi-x-circle-fill"></i></button>
        </div>
    </div>

    <!-- Content Area -->
    <div class="page-content">
        
        <?php if ($filter === 'route' && !$active_route): ?>
            <div class="clean-alert">
                <i class="bi bi-signpost-split fs-3 text-muted"></i>
                <div>
                    <h6 class="m-0 fw-bold">No Active Route</h6>
                    <p class="m-0 small text-muted mt-1">Accept a route and start your day to view assigned customers.</p>
                </div>
            </div>
        <?php elseif (empty($customers)): ?>
            <div class="clean-alert">
                <i class="bi bi-people fs-3 text-muted"></i>
                <div>
                    <h6 class="m-0 fw-bold">No Customers Found</h6>
                    <p class="m-0 small text-muted mt-1">There are no customers available in this view.</p>
                </div>
            </div>
        <?php else: ?>
            
            <div class="results-meta">
                Showing <span id="resultsCount"><?php echo count($customers); ?></span> Customers
            </div>

            <div id="customersContainer">
                <?php foreach ($customers as $c): 
                    $whatsapp_raw = $c['whatsapp'] ?? '';
                    $whatsapp_clean = preg_replace('/[^0-9]/', '', $whatsapp_raw);
                    if (strlen($whatsapp_clean) == 10 && str_starts_with($whatsapp_clean, '0')) {
                        $whatsapp_clean = '94' . substr($whatsapp_clean, 1); 
                    }
                ?>
                <div class="cust-card">
                    <div class="cust-header">
                        <div>
                            <h3 class="cust-name"><?php echo htmlspecialchars($c['name']); ?></h3>
                            <p class="cust-address text-truncate" style="max-width: 200px;">
                                <i class="bi bi-geo-alt mt-1"></i> 
                                <?php echo htmlspecialchars($c['address'] ?: 'No Address'); ?>
                            </p>
                            <div class="d-none customer-phone"><?php echo htmlspecialchars($c['phone']); ?></div>
                        </div>
                        <?php if($c['outstanding'] > 0): ?>
                            <div class="badge-custom danger"><i class="bi bi-exclamation-circle-fill"></i> Rs <?php echo number_format($c['outstanding']); ?></div>
                        <?php else: ?>
                            <div class="badge-custom success"><i class="bi bi-check-circle-fill"></i> Cleared</div>
                        <?php endif; ?>
                    </div>

                    <div class="action-grid">
                        <a href="tel:<?php echo htmlspecialchars($c['phone']); ?>" class="btn-act blue <?php echo !$c['phone'] ? 'disabled' : ''; ?>">
                            <i class="bi bi-telephone-fill"></i> Call
                        </a>
                        <a href="https://wa.me/<?php echo $whatsapp_clean; ?>" target="_blank" class="btn-act green <?php echo !$c['whatsapp'] ? 'disabled' : ''; ?>">
                            <i class="bi bi-whatsapp"></i> Chat
                        </a>
                        <a href="../pages/view_customer.php?id=<?php echo $c['id']; ?>" class="btn-act gray">
                            <i class="bi bi-person-vcard"></i> Profile
                        </a>
                        <button type="button" class="btn-act gray" 
                                onclick='openEditCustomer(<?php echo json_encode($c); ?>)'>
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div id="noResultsMsg" class="clean-alert d-none mt-3">
                <i class="bi bi-search fs-3 text-muted"></i>
                <div>
                    <h6 class="m-0 fw-bold">No Matches</h6>
                    <p class="m-0 small text-muted mt-1">No customers match your search query.</p>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- Offcanvas Add Customer -->
    <div class="offcanvas offcanvas-bottom" tabindex="-1" id="addCustomerCanvas">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">New Customer</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <form method="POST">
                <input type="hidden" name="action" value="add_customer">
                
                <div class="mb-3">
                    <label class="form-label-sm">Business Name *</label>
                    <input type="text" name="name" class="clean-input" required placeholder="e.g. City Mart" style="font-weight:600;">
                </div>
                
                <div class="mb-3">
                    <label class="form-label-sm">Owner Name</label>
                    <input type="text" name="owner_name" class="clean-input" placeholder="Optional">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label-sm">Phone</label>
                        <input type="tel" name="phone" class="clean-input" placeholder="07xxxxxxxx">
                    </div>
                    <div class="col-6">
                        <label class="form-label-sm">WhatsApp</label>
                        <input type="tel" name="whatsapp" class="clean-input" placeholder="07xxxxxxxx">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label-sm">Email Address</label>
                    <input type="email" name="email" class="clean-input" placeholder="Optional">
                </div>

                <div class="mb-3">
                    <label class="form-label-sm">Assign to Route</label>
                    <select name="route_id" class="clean-input">
                        <option value="">-- Select Route --</option>
                        <?php foreach($routes as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo ($active_route && $active_route['route_id'] == $r['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label-sm">Address</label>
                    <textarea name="address" class="clean-input" rows="2" placeholder="Street, City..."></textarea>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label-sm">Latitude</label>
                        <input type="text" name="latitude" id="cust_lat" class="clean-input" style="font-family:'JetBrains Mono', monospace;" placeholder="Lat">
                    </div>
                    <div class="col-6">
                        <label class="form-label-sm">Longitude</label>
                        <input type="text" name="longitude" id="cust_lng" class="clean-input" style="font-family:'JetBrains Mono', monospace;" placeholder="Lng">
                    </div>
                </div>
                
                <button type="button" class="btn-full outline mb-4" onclick="getLocation()">
                    <i class="bi bi-geo-alt me-1"></i> Get Current Location
                </button>

                <button type="submit" class="btn-full dark">Save Customer</button>
            </form>
        </div>
    </div>
    
    <!-- Offcanvas Edit Customer -->
    <div class="offcanvas offcanvas-bottom" tabindex="-1" id="editCustomerCanvas">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Edit Customer</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <form method="POST">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="customer_id" id="edit_cust_id">
                
                <div class="mb-3">
                    <label class="form-label-sm">Business Name *</label>
                    <input type="text" name="name" id="edit_name" class="clean-input" required placeholder="e.g. City Mart" style="font-weight:600;">
                </div>
                
                <div class="mb-3">
                    <label class="form-label-sm">Owner Name</label>
                    <input type="text" name="owner_name" id="edit_owner_name" class="clean-input" placeholder="Optional">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label-sm">Phone</label>
                        <input type="tel" name="phone" id="edit_phone" class="clean-input" placeholder="07xxxxxxxx">
                    </div>
                    <div class="col-6">
                        <label class="form-label-sm">WhatsApp</label>
                        <input type="tel" name="whatsapp" id="edit_whatsapp" class="clean-input" placeholder="07xxxxxxxx">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label-sm">Email Address</label>
                    <input type="email" name="email" id="edit_email" class="clean-input" placeholder="Optional">
                </div>

                <div class="mb-3">
                    <label class="form-label-sm">Assign to Route</label>
                    <select name="route_id" id="edit_route_id" class="clean-input">
                        <option value="">-- Select Route --</option>
                        <?php foreach($routes as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo htmlspecialchars($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label-sm">Address</label>
                    <textarea name="address" id="edit_address" class="clean-input" rows="2" placeholder="Street, City..."></textarea>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label-sm">Latitude</label>
                        <input type="text" name="latitude" id="edit_cust_lat" class="clean-input" style="font-family:'JetBrains Mono', monospace;" placeholder="Lat">
                    </div>
                    <div class="col-6">
                        <label class="form-label-sm">Longitude</label>
                        <input type="text" name="longitude" id="edit_cust_lng" class="clean-input" style="font-family:'JetBrains Mono', monospace;" placeholder="Lng">
                    </div>
                </div>
                
                <button type="button" class="btn-full outline mb-4" onclick="getLocation('edit')">
                    <i class="bi bi-geo-alt me-1"></i> Get Current Location
                </button>

                <button type="submit" class="btn-full dark">Update Customer</button>
            </form>
        </div>
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
        <a href="customers.php" class="nav-tab active">
            <i class="bi bi-people-fill"></i> Customers
        </a>
        <a href="analytics.php" class="nav-tab">
            <i class="bi bi-bar-chart-line-fill"></i> Stats
        </a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('liveSearchInput');
            const clearBtn = document.getElementById('clearSearchBtn');
            const cards = document.querySelectorAll('.cust-card');
            const countEl = document.getElementById('resultsCount');
            const noResultsMsg = document.getElementById('noResultsMsg');

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const term = this.value.toLowerCase();
                    
                    if (term.length > 0) {
                        clearBtn.classList.remove('d-none');
                    } else {
                        clearBtn.classList.add('d-none');
                    }

                    let visibleCount = 0;
                    cards.forEach(card => {
                        const text = card.innerText.toLowerCase();
                        if (text.includes(term)) {
                            card.style.display = 'block';
                            visibleCount++;
                        } else {
                            card.style.display = 'none';
                        }
                    });
                    
                    if (countEl) countEl.textContent = visibleCount;
                    
                    if (visibleCount === 0 && cards.length > 0) {
                        noResultsMsg.classList.remove('d-none');
                    } else if (noResultsMsg) {
                        noResultsMsg.classList.add('d-none');
                    }
                });

                clearBtn.addEventListener('click', function() {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                    searchInput.focus();
                });
            }

            const addCustomerCanvas = document.getElementById('addCustomerCanvas');
            if (addCustomerCanvas) {
                addCustomerCanvas.addEventListener('shown.bs.offcanvas', function () {
                    if (!document.getElementById('cust_lat').value) getLocation();
                });
            }
        });

        function openEditCustomer(cust) {
            document.getElementById('edit_cust_id').value = cust.id;
            document.getElementById('edit_name').value = cust.name;
            document.getElementById('edit_owner_name').value = cust.owner_name || '';
            document.getElementById('edit_phone').value = cust.phone || '';
            document.getElementById('edit_whatsapp').value = cust.whatsapp || '';
            document.getElementById('edit_email').value = cust.email || '';
            document.getElementById('edit_route_id').value = cust.route_id || '';
            document.getElementById('edit_address').value = cust.address || '';
            document.getElementById('edit_cust_lat').value = cust.latitude || '';
            document.getElementById('edit_cust_lng').value = cust.longitude || '';

            const editCanvas = new bootstrap.Offcanvas(document.getElementById('editCustomerCanvas'));
            editCanvas.show();
        }

        function getLocation(mode = 'add') {
            const latId = mode === 'edit' ? 'edit_cust_lat' : 'cust_lat';
            const lngId = mode === 'edit' ? 'edit_cust_lng' : 'cust_lng';

            if (navigator.geolocation) {
                document.getElementById(latId).value = 'Locating...';
                document.getElementById(lngId).value = 'Locating...';
                navigator.geolocation.getCurrentPosition(function(position) {
                    document.getElementById(latId).value = position.coords.latitude.toFixed(6);
                    document.getElementById(lngId).value = position.coords.longitude.toFixed(6);
                }, function(error) {
                    alert("Error fetching location. You can enter it manually.");
                    document.getElementById(latId).value = '';
                    document.getElementById(lngId).value = '';
                }, { enableHighAccuracy: true });
            } else {
                alert("Geolocation is not supported by this browser.");
            }
        }
    </script>
</body>
</html>