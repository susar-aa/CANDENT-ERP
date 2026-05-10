<?php
// Ensure database columns are ready for e-commerce
if(isset($pdo)) {
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN order_status ENUM('pending', 'processing', 'dispatched', 'delivered', 'cancelled') DEFAULT 'pending' AFTER payment_status");
    } catch(PDOException $e) {}
}

// Execute Notification Queries ONLY for Admins & Supervisors
$alertCount = 0;
$alerts = [];
if(isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'supervisor']) && isset($pdo)) {
    
    // 1. E-Commerce Online Orders
    $onlineOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE shipping_name IS NOT NULL AND order_status = 'pending'")->fetchColumn();
    if($onlineOrders > 0) {
        $alertCount += $onlineOrders;
        $alerts[] = ['icon' => 'bi-cart-check-fill', 'color' => 'success', 'hex' => '#34C759', 'text' => "$onlineOrders new online order(s) pending processing.", 'link' => 'online_orders.php'];
    }

    // 2. Low Stock Alerts
    $lowStock = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'available' AND stock <= 5")->fetchColumn();
    if($lowStock > 0) {
        $alertCount += $lowStock;
        $alerts[] = ['icon' => 'bi-exclamation-triangle-fill', 'color' => 'warning', 'hex' => '#FF9500', 'text' => "$lowStock product(s) dropped below minimum stock levels.", 'link' => 'products.php'];
    }

    // 3. Cheques to Bank Today
    $chequesToday = $pdo->query("SELECT COUNT(*) FROM cheques WHERE banking_date = CURDATE() AND status = 'pending'")->fetchColumn();
    if($chequesToday > 0) {
        $alertCount += $chequesToday;
        $alerts[] = ['icon' => 'bi-bank2', 'color' => 'danger', 'hex' => '#FF3B30', 'text' => "$chequesToday cheque(s) scheduled for banking today.", 'link' => 'cheques.php?status=pending'];
    }

    // 4. Bounced / Returned Cheques (Critical Finance)
    $bouncedCheques = $pdo->query("SELECT COUNT(*) FROM cheques WHERE status = 'returned'")->fetchColumn();
    if($bouncedCheques > 0) {
        $alertCount += $bouncedCheques;
        $alerts[] = ['icon' => 'bi-x-octagon-fill', 'color' => 'danger', 'hex' => '#FF3B30', 'text' => "$bouncedCheques cheque(s) have bounced or returned! Immediate action required.", 'link' => 'cheques.php?status=returned'];
    }

    // 5. Supplier Payments Due (Cashflow Warning)
    $apData = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount - paid_amount), 0) as total_due FROM grns WHERE payment_status != 'paid'")->fetch();
    if($apData['count'] > 0) {
        $alertCount++;
        $alerts[] = ['icon' => 'bi-wallet2', 'color' => 'danger', 'hex' => '#FF3B30', 'text' => "You have Rs " . number_format($apData['total_due'], 2) . " in Supplier Payments due this week, helping you manage your bank balances before issuing cheques.", 'link' => 'aging_reports.php'];
    }

    // 6. Critical Customer Debt (AR Aging > 60 Days Warning)
    $criticalDebt = $pdo->query("SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE payment_status != 'paid' AND DATEDIFF(CURDATE(), created_at) > 60")->fetchColumn();
    if($criticalDebt > 0) {
        $alertCount++;
        $alerts[] = ['icon' => 'bi-exclamation-triangle-fill', 'color' => 'warning', 'hex' => '#FF9500', 'text' => "Warning: Rs " . number_format($criticalDebt, 2) . " in customer debt is over 60 days overdue.", 'link' => 'aging_reports.php'];
    }

    // 7. Routes Pending Unload (Operational Bottleneck)
    $pendingUnload = $pdo->query("SELECT COUNT(*) FROM rep_routes WHERE status = 'completed'")->fetchColumn();
    if($pendingUnload > 0) {
        $alertCount += $pendingUnload;
        $alerts[] = ['icon' => 'bi-truck', 'color' => 'info', 'hex' => '#30B0C7', 'text' => "$pendingUnload dispatched route(s) returned and waiting for stock unload verification.", 'link' => 'dispatch.php?status=completed'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candent - Command Center</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">

    <style>
        /* Candent Topbar iOS Styling */
        .candent-topbar {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(60, 60, 67, 0.12);
            padding: 8px 16px;
            z-index: 1040; /* Above sidebar */
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .candent-brand img {
            height: 32px;
            object-fit: contain;
        }

        /* Nav Icon Buttons */
        .nav-icon-btn {
            color: #1c1c1e;
            background: transparent;
            border: none;
            padding: 6px 10px;
            border-radius: 8px;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .nav-icon-btn:hover { background: rgba(60,60,67,0.08); }
        .nav-icon-btn:active { background: rgba(60,60,67,0.12); }

        /* Notification Badge */
        .bell-icon-wrapper {
            position: relative;
            display: inline-flex;
        }
        .candent-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #FF3B30; /* iOS Red */
            color: white;
            font-size: 0.6rem;
            font-weight: 800;
            padding: 2px 5px;
            border-radius: 50px;
            border: 2px solid #ffffff;
            transform: translate(30%, -30%);
            line-height: 1;
        }

        /* Dropdown Styling */
        .candent-dropdown {
            border-radius: 18px;
            border: 1px solid rgba(60, 60, 67, 0.08);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12), 0 2px 10px rgba(0,0,0,0.04);
            padding: 8px;
            min-width: 340px;
            margin-top: 10px !important;
        }
        .candent-dropdown-header {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1c1c1e;
            padding: 8px 12px 12px;
            border-bottom: 1px solid rgba(60,60,67,0.1);
            margin-bottom: 8px;
        }
        .candent-dropdown .dropdown-item {
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 4px;
            transition: background 0.15s;
            white-space: normal; /* Allow text wrapping */
        }
        .candent-dropdown .dropdown-item:hover { background: rgba(60,60,67,0.04); }
        .candent-dropdown .dropdown-item:active { background: rgba(60,60,67,0.08); }

        .alert-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 12px;
        }

        /* Topbar User Profile */
        .topbar-user {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1c1c1e;
            display: flex;
            align-items: center;
            gap: 8px;
            padding-right: 16px;
            border-right: 1px solid rgba(60,60,67,0.15);
            margin-right: 16px;
        }
        .topbar-user span { color: rgba(60,60,67,0.6); font-weight: 500; font-size: 0.75rem; }

        /* Logout Button */
        .logout-btn {
            font-size: 0.85rem;
            font-weight: 600;
            color: #FF3B30;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 50px;
            background: rgba(255,59,48,0.08);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .logout-btn:hover { background: rgba(255,59,48,0.15); color: #CC2200; }
        .logout-btn:active { transform: scale(0.96); }
    </style>
</head>
<body class="bg-light">
<script>
    // Execute immediately before render to prevent flickering when Sidebar is minimized
    if (localStorage.getItem('sidebar_minimized') === 'true') {
        document.body.classList.add('sidebar-minimized');
    }
</script>

<!-- Top Navigation -->
<nav class="navbar navbar-expand-lg candent-topbar sticky-top">
    <div class="container-fluid px-2">
        
        <div class="d-flex align-items-center">
            <!-- Mobile Sidebar Toggle -->
            <button class="nav-icon-btn d-md-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu">
                <i class="bi bi-list fs-4"></i>
            </button>
            
            <!-- Desktop Sidebar Minimize Button -->
            <button class="nav-icon-btn d-none d-md-inline-flex me-3" type="button" id="sidebarMinimizeBtn" title="Toggle Sidebar">
                <i class="bi bi-layout-sidebar fs-5"></i>
            </button>
            
            <!-- Candent Brand Logo -->
            <a class="navbar-brand candent-brand ms-1" href="dashboard.php">
                <img src="/images/logo/logo.png" alt="Candent" onerror="this.src='https://via.placeholder.com/140x32/ffffff/30C88A?text=CANDENT+SYS'">
            </a>
        </div>
        
        <div class="d-flex ms-auto align-items-center">
            
            <!-- Smart Notification Bell (Admins Only) -->
            <?php if(isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'supervisor'])): ?>
            <div class="dropdown me-3">
                <button class="nav-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="position: relative;">
                    <div class="bell-icon-wrapper">
                        <i class="bi bi-bell-fill fs-5" style="color: #1c1c1e;"></i>
                        <?php if($alertCount > 0): ?>
                        <span class="candent-badge">
                            <?php echo $alertCount > 99 ? '99+' : $alertCount; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </button>
                
                <ul class="dropdown-menu dropdown-menu-end candent-dropdown">
                    <li class="candent-dropdown-header">
                        <i class="bi bi-bell me-1"></i> Notifications
                    </li>
                    
                    <?php if(empty($alerts)): ?>
                        <li>
                            <div class="dropdown-item text-center py-4">
                                <i class="bi bi-check2-circle d-block mb-2" style="font-size: 2rem; color: #34C759;"></i>
                                <span style="font-size: 0.85rem; color: rgba(60,60,67,0.6); font-weight: 500;">You're all caught up!</span>
                            </div>
                        </li>
                    <?php else: ?>
                        <?php foreach($alerts as $alert): ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-start" href="<?php echo $alert['link']; ?>">
                                    <div class="alert-icon-box" style="background: <?php echo $alert['hex']; ?>20; color: <?php echo $alert['hex']; ?>;">
                                        <i class="bi <?php echo $alert['icon']; ?> fs-6"></i>
                                    </div>
                                    <div style="flex: 1; padding-top: 2px;">
                                        <span style="font-size: 0.82rem; font-weight: 500; color: #1c1c1e; line-height: 1.3; display: block;">
                                            <?php echo $alert['text']; ?>
                                        </span>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider my-2" style="border-color: rgba(60,60,67,0.08);"></li>
                        <li>
                            <a href="dashboard.php" class="dropdown-item text-center" style="color: #30C88A; font-weight: 700; font-size: 0.8rem;">
                                Go to Dashboard
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- User Info & Logout -->
            <div class="d-none d-sm-flex align-items-center">
                <div class="topbar-user">
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                    <span>(<?php echo ucfirst($_SESSION['user_role']); ?>)</span>
                </div>
            </div>
            
            <a href="../logout.php" class="logout-btn">
                <span>Logout</span>
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</nav>

<div class="d-flex w-100 position-relative">