<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// --- DATE & REP FILTERS ---
$period = isset($_GET['period']) ? $_GET['period'] : 'this_month';
$rep_filter = isset($_GET['rep_id']) ? $_GET['rep_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Resolve predefined periods into concrete dates
if ($period !== 'custom') {
    if ($period == 'today') {
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
    } elseif ($period == 'this_week') {
        $date_from = date('Y-m-d', strtotime('monday this week'));
        $date_to = date('Y-m-d', strtotime('sunday this week'));
    } elseif ($period == 'this_month') {
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
    } elseif ($period == 'this_year') {
        $date_from = date('Y-01-01');
        $date_to = date('Y-12-31');
    }
}

if (empty($date_from)) $date_from = date('Y-m-01');
if (empty($date_to)) $date_to = date('Y-m-t');

// Build SQL Components for filtering by Assignment Date
$whereClause = "rr.assign_date >= ? AND rr.assign_date <= ?";
$params = [$date_from, $date_to];

if ($rep_filter !== '') {
    $whereClause .= " AND rr.rep_id = ?";
    $params[] = $rep_filter;
}

// --- 1. FETCH KPIs ---
$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(id) as total_dispatches,
        SUM(CASE WHEN status IN ('accepted') AND start_meter IS NOT NULL AND end_meter IS NULL THEN 1 ELSE 0 END) as active_trips,
        SUM(CASE WHEN end_meter IS NOT NULL AND start_meter IS NOT NULL THEN 1 ELSE 0 END) as completed_trips,
        SUM(CASE WHEN end_meter IS NOT NULL AND start_meter IS NOT NULL THEN (end_meter - start_meter) ELSE 0 END) as total_distance
    FROM rep_routes rr 
    WHERE $whereClause
");
$kpiStmt->execute($params);
$kpiData = $kpiStmt->fetch();

$total_distance = (float)$kpiData['total_distance'];
$completed_trips = (int)$kpiData['completed_trips'];
$active_trips = (int)$kpiData['active_trips'];
$avg_distance = $completed_trips > 0 ? $total_distance / $completed_trips : 0;

// --- 2. FETCH LOGGED ROUTE EXPENSES (Fuel vs Other) ---
$expStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(amount), 0) as total_expenses,
        COALESCE(SUM(CASE WHEN type = 'Fuel' THEN amount ELSE 0 END), 0) as total_fuel_expenses
    FROM route_expenses 
    WHERE assignment_id IN (SELECT id FROM rep_routes rr WHERE $whereClause)
");
$expStmt->execute($params);
$expData = $expStmt->fetch();

$total_logged_expenses = (float)$expData['total_expenses'];
$total_logged_fuel = (float)$expData['total_fuel_expenses'];
$total_other_expenses = $total_logged_expenses - $total_logged_fuel;

// --- 3. FETCH CHART DATA (Distance by Date) ---
$chartQuery = "
    SELECT assign_date, SUM(end_meter - start_meter) as daily_distance 
    FROM rep_routes rr 
    WHERE $whereClause AND end_meter IS NOT NULL AND start_meter IS NOT NULL
    GROUP BY assign_date
    ORDER BY assign_date ASC
";
$stmt = $pdo->prepare($chartQuery);
$stmt->execute($params);
$chartRows = $stmt->fetchAll();

$chartLabels = [];
$chartDistance = [];

// Ensure we have a continuous timeline for smaller date ranges
$start = new DateTime($date_from);
$end = new DateTime($date_to);
$interval = $start->diff($end);
$days = $interval->days;

if ($days <= 60) {
    for ($i = 0; $i <= $days; $i++) {
        $d = clone $start;
        $d->modify("+$i days");
        $ds = $d->format('Y-m-d');
        $chartLabels[] = $d->format('M d');
        
        $found = false;
        foreach($chartRows as $row) {
            if ($row['assign_date'] == $ds) {
                $chartDistance[] = (float)$row['daily_distance'];
                $found = true; break;
            }
        }
        if (!$found) $chartDistance[] = 0;
    }
} else {
    foreach($chartRows as $row) {
        $chartLabels[] = date('M d, Y', strtotime($row['assign_date']));
        $chartDistance[] = (float)$row['daily_distance'];
    }
}

// --- 4. FETCH DETAILED LEDGER WITH PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$totalRowsStmt = $pdo->prepare("SELECT COUNT(*) FROM rep_routes rr WHERE $whereClause AND start_meter IS NOT NULL");
$totalRowsStmt->execute($params);
$totalRows = $totalRowsStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

$ledgerQuery = "
    SELECT rr.*, r.name as route_name, u.name as rep_name, e.name as driver_name 
    FROM rep_routes rr
    JOIN routes r ON rr.route_id = r.id
    JOIN users u ON rr.rep_id = u.id
    LEFT JOIN employees e ON rr.driver_id = e.id
    WHERE $whereClause AND rr.start_meter IS NOT NULL
    ORDER BY rr.assign_date DESC, rr.id DESC
    LIMIT $limit OFFSET $offset
";
$ledgerStmt = $pdo->prepare($ledgerQuery);
$ledgerStmt->execute($params);
$readings = $ledgerStmt->fetchAll();

// Fetch Reps for the Filter
$reps = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC")->fetchAll();

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
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
    }
    .page-title {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
    }
    .page-subtitle {
        font-size: 0.85rem;
        color: var(--ios-label-2);
        margin-top: 4px;
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

    /* Metrics Card */
    .metrics-card {
        border-radius: 16px;
        padding: 20px;
        background: var(--ios-surface);
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        border: 1px solid var(--ios-separator);
        display: flex;
        align-items: center;
        gap: 16px;
        height: 100%;
        transition: transform 0.2s ease;
    }
    .metrics-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
    .metrics-icon {
        width: 50px; height: 50px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
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
        padding: 14px 20px;
    }
    .ios-table { width: 100%; border-collapse: collapse; }
    .ios-table td {
        vertical-align: middle;
        padding: 14px 20px;
        border-bottom: 1px solid var(--ios-separator);
    }
    .ios-table tr:last-child td { border-bottom: none; }
    .ios-table tr:hover td { background: var(--ios-bg); }

    /* iOS Badges */
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
    .ios-badge.gray    { background: rgba(60,60,67,0.1); color: var(--ios-label-2); }
    .ios-badge.outline { background: transparent; border: 1px solid var(--ios-separator); color: var(--ios-label-2); }

    /* Pagination */
    .ios-pagination { display: flex; gap: 4px; list-style: none; padding: 0; justify-content: center; margin-top: 20px; }
    .ios-pagination .page-link {
        border: none;
        color: var(--ios-label);
        background: var(--ios-surface);
        border-radius: 8px;
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 0.9rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .ios-pagination .page-item.active .page-link {
        background: var(--accent); color: #fff; box-shadow: 0 4px 10px rgba(48,200,138,0.3);
    }
    .ios-pagination .page-link:hover:not(.active) { background: var(--ios-surface-2); }

    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .dash-card { box-shadow: none !important; border: 1px solid #ddd; }
    }
</style>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Vehicle Mileage & Fuel</h1>
        <div class="page-subtitle">Analyze fleet meter readings, track active trips, and estimate fuel expenses.</div>
    </div>
    <div>
        <button onclick="window.print()" class="quick-btn quick-btn-secondary">
            <i class="bi bi-printer"></i> Print Report
        </button>
    </div>
</div>

<div class="d-none d-print-block text-center mb-4 pb-3" style="border-bottom: 2px solid #000;">
    <h3 style="font-weight: 800; margin: 0;">Vehicle Mileage & Fuel Report</h3>
    <p style="color: #666; margin: 5px 0 0;">Period: <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
</div>

<!-- Dynamic Filters -->
<div class="dash-card mb-4 no-print" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="ios-label-sm">Sales Rep / Vehicle</label>
                <select name="rep_id" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Representatives</option>
                    <?php foreach($reps as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo $rep_filter == $r['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="ios-label-sm">Time Period</label>
                <select name="period" id="periodSelect" class="form-select" onchange="toggleCustomDates();">
                    <option value="today" <?php echo $period == 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="this_week" <?php echo $period == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="this_month" <?php echo $period == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="this_year" <?php echo $period == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            
            <div class="col-md-2 custom-date-block" style="<?php echo $period != 'custom' ? 'display:none;' : ''; ?>">
                <label class="ios-label-sm">From</label>
                <input type="date" name="date_from" id="dateFrom" class="ios-input" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2 custom-date-block" style="<?php echo $period != 'custom' ? 'display:none;' : ''; ?>">
                <label class="ios-label-sm">To</label>
                <input type="date" name="date_to" id="dateTo" class="ios-input" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            
            <div class="col-md-2 custom-date-block" style="<?php echo $period != 'custom' ? 'display:none;' : ''; ?>">
                <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 42px;"><i class="bi bi-funnel-fill"></i> Apply</button>
            </div>
        </form>
    </div>
</div>

<!-- Primary KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card">
            <div class="metrics-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;"><i class="bi bi-signpost-split-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Total Ride Distance</div>
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--ios-label); line-height: 1;">
                    <?php echo number_format($total_distance, 1); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--ios-label-3);">km</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card">
            <div class="metrics-icon" style="background: rgba(52,199,89,0.1); color: #34C759;"><i class="bi bi-truck"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Completed Trips</div>
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--ios-label); line-height: 1;">
                    <?php echo number_format($completed_trips); ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card">
            <div class="metrics-icon" style="background: rgba(48,176,199,0.1); color: #30B0C7;"><i class="bi bi-speedometer"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Avg Distance / Trip</div>
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--ios-label); line-height: 1;">
                    <?php echo number_format($avg_distance, 1); ?> <span style="font-size: 0.9rem; font-weight: 600; color: var(--ios-label-3);">km</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="metrics-card">
            <div class="metrics-icon" style="background: rgba(255,149,0,0.1); color: #FF9500;"><i class="bi bi-geo-alt-fill"></i></div>
            <div>
                <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-3); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px;">Active Trips Now</div>
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--ios-label); line-height: 1;">
                    <?php echo number_format($active_trips); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Financial & Fuel Analysis -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="dash-card h-100">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-calculator-fill"></i>
                    </span>
                    Estimated Fuel Cost Calculator
                </span>
            </div>
            <div class="p-4" style="background: var(--ios-surface);">
                <div class="ios-alert mb-4" style="background: rgba(0,122,255,0.08); color: #0055CC; padding: 12px 16px; border-radius: 12px; font-size: 0.85rem;">
                    <i class="bi bi-info-circle-fill me-2"></i>Dynamically calculate expected fuel expenses based on the total <strong><?php echo number_format($total_distance, 1); ?> km</strong> recorded.
                </div>
                
                <div class="row g-3 align-items-center mb-4">
                    <div class="col-6">
                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Total Distance</div>
                        <div style="font-size: 1.6rem; font-weight: 800; color: var(--ios-label);"><?php echo number_format($total_distance, 1); ?> <span style="font-size: 1rem; font-weight: 600; color: var(--ios-label-3);">km</span></div>
                    </div>
                    <div class="col-6">
                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--ios-label-2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Estimated Cost</div>
                        <div style="font-size: 1.6rem; font-weight: 800; color: #CC2200;">Rs <span id="estimatedFuelCost">0.00</span></div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="ios-label-sm">Avg. Efficiency (km/L)</label>
                        <input type="number" step="0.1" id="fuelEfficiency" class="ios-input text-center fw-bold" style="font-size: 1.1rem; height: 46px;" value="10.0">
                    </div>
                    <div class="col-6">
                        <label class="ios-label-sm">Fuel Price (Rs/L)</label>
                        <input type="number" step="0.01" id="fuelPrice" class="ios-input text-center fw-bold" style="font-size: 1.1rem; height: 46px;" value="350.00">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="dash-card h-100">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(255,59,48,0.1); color: #CC2200;">
                        <i class="bi bi-wallet2"></i>
                    </span>
                    Logged Route Expenses (Actuals)
                </span>
            </div>
            <div class="p-4 d-flex flex-column justify-content-center" style="background: var(--ios-surface); height: calc(100% - 65px);">
                
                <div class="d-flex justify-content-between align-items-center mb-4 pb-4" style="border-bottom: 1px solid var(--ios-separator);">
                    <div>
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">Reported Fuel Expenses</div>
                        <div style="font-size: 0.8rem; color: var(--ios-label-3); margin-top: 2px;">Claimed by reps via mobile app</div>
                    </div>
                    <div style="font-size: 1.4rem; font-weight: 800; color: #FF9500;">
                        Rs <?php echo number_format($total_logged_fuel, 2); ?>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-4 pb-4" style="border-bottom: 1px solid var(--ios-separator);">
                    <div>
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label);">Other Route Expenses</div>
                        <div style="font-size: 0.8rem; color: var(--ios-label-3); margin-top: 2px;">Meals, repairs, tolls, etc.</div>
                    </div>
                    <div style="font-size: 1.4rem; font-weight: 800; color: var(--ios-label-2);">
                        Rs <?php echo number_format($total_other_expenses, 2); ?>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--ios-label); text-transform: uppercase; letter-spacing: 0.05em;">Total Cash Outflow:</div>
                    <div style="font-size: 1.6rem; font-weight: 800; color: #CC2200;">Rs <?php echo number_format($total_logged_expenses, 2); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Trend Chart -->
<div class="dash-card mb-4 no-print">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(48,176,199,0.1); color: #30B0C7;">
                <i class="bi bi-bar-chart-fill"></i>
            </span>
            Daily Distance Trend (KM)
        </span>
    </div>
    <div class="p-4" style="background: var(--ios-surface); position: relative; height: 320px;">
        <canvas id="mileageChart"></canvas>
    </div>
</div>

<!-- Detailed Ledger -->
<div class="dash-card mb-5 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(52,199,89,0.1); color: #34C759;">
                <i class="bi bi-list-columns-reverse"></i>
            </span>
            Detailed Route Mileage Log
        </span>
        <span class="ios-badge gray outline" style="font-weight: 600;">Showing Dispatches with Readings</span>
    </div>
    <div class="table-responsive">
        <table class="ios-table text-center">
            <thead>
                <tr class="table-ios-header">
                    <th class="text-start" style="width: 15%;">Date</th>
                    <th class="text-start" style="width: 25%;">Personnel & Route</th>
                    <th style="width: 15%;">Start Meter</th>
                    <th style="width: 15%;">End Meter</th>
                    <th style="width: 15%;">Total Distance</th>
                    <th style="width: 15%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($readings as $r): 
                    $distance = ($r['end_meter'] !== null && $r['start_meter'] !== null) ? ($r['end_meter'] - $r['start_meter']) : 0;
                ?>
                <tr>
                    <td class="text-start">
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);"><?php echo date('M d, Y', strtotime($r['assign_date'])); ?></div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">Disp #<?php echo str_pad($r['id'], 5, '0', STR_PAD_LEFT); ?></div>
                    </td>
                    <td class="text-start">
                        <div style="font-weight: 700; font-size: 0.9rem; color: #0055CC; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;"><?php echo htmlspecialchars($r['route_name']); ?></div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;"><i class="bi bi-person-badge me-1"></i>Rep: <?php echo htmlspecialchars($r['rep_name']); ?></div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin-top: 2px;"><i class="bi bi-person me-1"></i>Drv: <?php echo htmlspecialchars($r['driver_name'] ?: 'Self'); ?></div>
                    </td>
                    <td>
                        <span class="ios-badge outline gray fs-6 px-3"><?php echo number_format($r['start_meter'], 1); ?></span>
                    </td>
                    <td>
                        <?php if($r['end_meter'] !== null): ?>
                            <span class="ios-badge outline gray fs-6 px-3"><?php echo number_format($r['end_meter'], 1); ?></span>
                        <?php else: ?>
                            <span class="ios-badge orange"><i class="bi bi-hourglass-split"></i> Running</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 800; font-size: 1.05rem; color: <?php echo $distance > 0 ? '#1A9A3A' : 'var(--ios-label-3)'; ?>;">
                        <?php if($r['end_meter'] !== null): ?>
                            <?php echo number_format($distance, 1); ?> <span style="font-size: 0.8rem; font-weight: 600; color: var(--ios-label-3);">km</span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if(in_array($r['status'], ['completed', 'unloaded'])): ?>
                            <span class="ios-badge green"><i class="bi bi-check-circle-fill"></i> Closed</span>
                        <?php elseif($r['status'] == 'accepted'): ?>
                            <span class="ios-badge blue"><i class="bi bi-play-circle-fill"></i> On Route</span>
                        <?php else: ?>
                            <span class="ios-badge gray"><?php echo ucfirst($r['status']); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($readings)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-speedometer2" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No meter readings found for this period.</p>
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
<ul class="ios-pagination no-print mb-5">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&rep_id=<?php echo urlencode($rep_filter); ?>&period=<?php echo urlencode($period); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    function toggleCustomDates() {
        const period = document.getElementById('periodSelect').value;
        const customBlocks = document.querySelectorAll('.custom-date-block');
        
        if (period === 'custom') {
            customBlocks.forEach(b => b.style.display = 'block');
        } else {
            customBlocks.forEach(b => b.style.display = 'none');
            document.getElementById('filterForm').submit();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Dynamic Fuel Calculator Logic
        const totalDistance = <?php echo $total_distance; ?>;
        const efficiencyInput = document.getElementById('fuelEfficiency');
        const priceInput = document.getElementById('fuelPrice');
        const estimatedCostDisplay = document.getElementById('estimatedFuelCost');

        function calculateFuelCost() {
            if (!efficiencyInput || !priceInput || !estimatedCostDisplay) return;
            const efficiency = parseFloat(efficiencyInput.value) || 1; // Fallback to 1 to prevent division by zero
            const price = parseFloat(priceInput.value) || 0;
            
            const estimatedLiters = totalDistance / efficiency;
            const estimatedCost = estimatedLiters * price;
            
            estimatedCostDisplay.textContent = estimatedCost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        if(efficiencyInput && priceInput) {
            efficiencyInput.addEventListener('input', calculateFuelCost);
            priceInput.addEventListener('input', calculateFuelCost);
            calculateFuelCost(); // Initial calculation on load
        }

        // Chart Initialization (Candent Styling)
        const ctx = document.getElementById('mileageChart').getContext('2d');
        
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(0, 122, 255, 0.4)'); // iOS Blue
        gradient.addColorStop(1, 'rgba(0, 122, 255, 0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Total Distance (km)',
                        data: <?php echo json_encode($chartDistance); ?>,
                        borderColor: '#0055CC',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#0055CC',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(28, 28, 30, 0.9)', // iOS Dark Gray
                        padding: 12,
                        titleFont: { size: 12, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: 'normal' },
                        bodyFont: { size: 14, family: '-apple-system, BlinkMacSystemFont, sans-serif', weight: 'bold' },
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1}) + ' km';
                            }
                        }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { borderDash: [4, 4], color: 'rgba(60,60,67,0.08)' },
                        ticks: {
                            color: 'rgba(60,60,67,0.5)',
                            font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 11 },
                            callback: function(value) { return value + ' km'; }
                        },
                        border: { display: false }
                    },
                    x: { 
                        grid: { display: false },
                        ticks: {
                            color: 'rgba(60,60,67,0.5)',
                            font: { family: '-apple-system, BlinkMacSystemFont, sans-serif', size: 11 }
                        },
                        border: { display: false }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
            }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>