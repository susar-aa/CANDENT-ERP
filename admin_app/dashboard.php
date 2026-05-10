<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Admin and Supervisor access

$page_title = 'Overview';

// Fetch Quick Stats for today
try {
    $today = date('Y-m-d');
    
    // Total Sales Today
    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM orders WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $today_sales = $stmt->fetchColumn() ?: 0;

    // Total Orders Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?");
    $stmt->execute([$today]);
    $today_orders = $stmt->fetchColumn() ?: 0;

    // Active Routes Today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rep_routes WHERE assign_date = ? AND status IN ('assigned', 'accepted')");
    $stmt->execute([$today]);
    $active_routes = $stmt->fetchColumn() ?: 0;

    // Pending Customers (no outstanding logic here, just count total for simplicity)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers");
    $stmt->execute();
    $total_customers = $stmt->fetchColumn() ?: 0;

} catch (PDOException $e) {
    //
}

include 'includes/header.php';
?>

<style>
    /* Stats Grid */
    .stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .stat-card {
        background: var(--surface); border-radius: var(--radius-lg); padding: 16px;
        border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.03); display: flex; flex-direction: column;
    }
    .stat-icon {
        width: 32px; height: 32px; border-radius: var(--radius-sm);
        display: flex; align-items: center; justify-content: center; margin-bottom: 12px; font-size: 16px;
    }
    .stat-icon.sales { background: var(--success-bg); color: var(--success); }
    .stat-icon.orders { background: var(--primary-bg); color: var(--primary); }
    .stat-icon.routes { background: var(--warning-bg); color: var(--warning); }
    .stat-icon.customers { background: var(--purple-bg); color: var(--purple); }
    
    .stat-label { font-size: 13px; color: var(--text-muted); font-weight: 600; margin-bottom: 4px; }
    .stat-value { font-size: 22px; font-weight: 700; font-family: 'JetBrains Mono', monospace; color: var(--text-main); line-height: 1; letter-spacing: -0.02em; }

    /* App Menu Grid */
    .app-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px 12px;
        margin-bottom: 32px;
        padding: 4px;
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
        font-size: 24px; margin-bottom: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .ag-text { font-size: 12px; font-weight: 600; line-height: 1.25; color: var(--text-main); }
    
    /* Grid Colors */
    .ag-blue { background: var(--primary-bg); color: var(--primary); }
    .ag-green { background: var(--success-bg); color: var(--success); }
    .ag-amber { background: var(--warning-bg); color: var(--warning); }
    .ag-purple { background: var(--purple-bg); color: var(--purple); }
    .ag-rose { background: #FCE7F3; color: #BE185D; }
    .ag-teal { background: #E0F2FE; color: #0369A1; }
</style>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;"><?php echo date('l, d M'); ?></div>
        <h2 class="m-0 fw-bold">Hello, <?php echo htmlspecialchars(explode(' ', trim($_SESSION['user_name']))[0]); ?></h2>
    </div>
    <div class="text-primary bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; font-weight: 800; font-size: 18px; border: 1px solid var(--border);">
        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
    </div>
</div>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon sales"><i class="bi bi-wallet2"></i></div>
        <div class="stat-content">
            <p class="stat-label">Today's Sales</p>
            <h3 class="stat-value">Rs <?php echo number_format($today_sales / 1000, 1); ?>k</h3>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orders"><i class="bi bi-receipt"></i></div>
        <div class="stat-content">
            <p class="stat-label">Orders Today</p>
            <h3 class="stat-value"><?php echo $today_orders; ?></h3>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon routes"><i class="bi bi-truck"></i></div>
        <div class="stat-content">
            <p class="stat-label">Active Routes</p>
            <h3 class="stat-value"><?php echo $active_routes; ?></h3>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon customers"><i class="bi bi-people"></i></div>
        <div class="stat-content">
            <p class="stat-label">Customers</p>
            <h3 class="stat-value"><?php echo $total_customers; ?></h3>
        </div>
    </div>
</div>

<h2 class="section-title">Admin Controls</h2>
<div class="app-grid">
    <a href="catalog.php" class="app-grid-btn">
        <div class="ag-icon-wrapper ag-blue"><i class="bi bi-box-seam"></i></div>
        <span class="ag-text">Product<br>Catalog</span>
    </a>
    <a href="dispatch.php" class="app-grid-btn">
        <div class="ag-icon-wrapper ag-amber"><i class="bi bi-send-check"></i></div>
        <span class="ag-text">Vehicle<br>Dispatch</span>
    </a>
    <a href="routes.php" class="app-grid-btn">
        <div class="ag-icon-wrapper ag-teal"><i class="bi bi-map"></i></div>
        <span class="ag-text">Route<br>Mgmt</span>
    </a>
    <a href="customers.php" class="app-grid-btn">
        <div class="ag-icon-wrapper ag-rose"><i class="bi bi-people"></i></div>
        <span class="ag-text">Customers</span>
    </a>
    <a href="sales.php" class="app-grid-btn">
        <div class="ag-icon-wrapper ag-green"><i class="bi bi-graph-up-arrow"></i></div>
        <span class="ag-text">Sales &<br>Orders</span>
    </a>
    <a href="../pages/dashboard.php" class="app-grid-btn">
        <div class="ag-icon-wrapper" style="background: #F1F5F9; color: var(--text-muted); border: 1px solid var(--border); box-shadow: none;"><i class="bi bi-pc-display"></i></div>
        <span class="ag-text">Desktop<br>ERP</span>
    </a>
</div>

<!-- Install PWA Banner -->
<div id="installBanner" class="clean-card d-none" style="background: var(--primary); color: #fff; border: none;">
    <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; color: var(--primary);">
                <i class="bi bi-download"></i>
            </div>
            <div>
                <div class="fw-bold">Install Admin App</div>
                <div class="small opacity-75">Add to your home screen</div>
            </div>
        </div>
        <button id="installBtn" class="btn btn-light btn-sm rounded-pill px-3 fw-bold">Install</button>
    </div>
</div>

<script>
    let deferredPrompt;
    const installBanner = document.getElementById('installBanner');
    const installBtn = document.getElementById('installBtn');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        installBanner.classList.remove('d-none');
    });

    installBtn.addEventListener('click', (e) => {
        installBanner.classList.add('d-none');
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the A2HS prompt');
            }
            deferredPrompt = null;
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
