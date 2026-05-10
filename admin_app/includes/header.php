<?php
$title = $page_title ?? 'Admin App';
$cur_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($title); ?> - Fintrix Admin</title>
    
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <link rel="manifest" href="manifest.json">
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('Admin SW Registered'))
                .catch(err => console.log('Admin SW Failed', err));
        }
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            --bg-color: #F8FAFC;         
            --surface: #FFFFFF;          
            --text-main: #0F172A;        
            --text-muted: #64748B;       
            --border: #E2E8F0;           
            
            --primary: #4F46E5; /* Indigo for Admin */
            --primary-bg: #EEF2FF;
            --success: #10B981;          
            --success-bg: #ECFDF5;
            --danger: #EF4444;           
            --danger-bg: #FEF2F2;
            --warning: #F59E0B;          
            --warning-bg: #FFFBEB;
            --info: #0EA5E9;
            --info-bg: #E0F2FE;
            --purple: #8B5CF6;
            --purple-bg: #F5F3FF;
            
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
            
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

        /* ── Header ── */
        .app-header {
            background: var(--surface);
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .header-title { font-size: 20px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px;}
        .header-title i { color: var(--primary); }
        .back-btn { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--bg-color); color: var(--text-main); text-decoration: none; font-size: 20px; margin-right: 12px;}

        /* ── General UI ── */
        .page-content { padding: 16px; }
        .section-title { font-size: 16px; font-weight: 700; margin: 0 0 16px 4px; color: var(--text-main); }
        
        .clean-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        /* ── Input ── */
        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px 16px; font-size: 15px;
            outline: none; transition: border 0.2s;
        }
        .clean-input:focus { border-color: var(--primary); background: #fff; }

        /* ── Bottom Nav ── */
        .bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-top: 1px solid rgba(226, 232, 240, 0.8);
            display: flex; justify-content: space-around; align-items: center;
            height: var(--nav-h); z-index: 1000; padding-bottom: env(safe-area-inset-bottom, 0);
        }
        .nav-tab {
            flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;
            text-decoration: none; color: var(--text-muted); font-size: 11px; font-weight: 600;
            padding: 8px 0; transition: color 0.2s;
        }
        .nav-tab i { font-size: 22px; }
        .nav-tab.active { color: var(--primary); }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="d-flex align-items-center">
            <?php if($cur_page !== 'dashboard.php'): ?>
                <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <?php endif; ?>
            <h1 class="header-title">
                <?php if($cur_page === 'dashboard.php') echo '<i class="bi bi-shield-lock-fill"></i>'; ?>
                <?php echo htmlspecialchars($title); ?>
            </h1>
        </div>
        <a href="../logout.php" class="text-danger fw-bold text-decoration-none" style="font-size: 14px;"><i class="bi bi-box-arrow-right"></i> Exit</a>
    </header>
    <main class="page-content">
