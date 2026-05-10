<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];

// Fetch Live Vehicle Stock
$stockQuery = "
    SELECT 
        p.name, p.sku, 
        vs.stock_qty as remaining,
        vs.last_audit_date
    FROM vehicle_stock vs
    JOIN products p ON vs.product_id = p.id
    WHERE vs.rep_id = ? AND vs.stock_qty > 0
    ORDER BY p.name ASC
";
$stmt = $pdo->prepare($stockQuery);
$stmt->execute([$rep_id]);
$vehicle_stock = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Vehicle Stock - Rep App</title>
    
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
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: 40px;
            -webkit-font-smoothing: antialiased;
            margin: 0;
        }

        /* ── Modern Header ── */
        .app-header {
            background: var(--surface);
            padding: 20px 20px 16px;
            display: flex;
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

        /* ── Content Area ── */
        .page-content { padding: 20px 16px; }

        /* ── Search Bar ── */
        .search-wrapper {
            position: relative;
            margin-bottom: 24px;
        }
        .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 16px;
        }
        .search-input {
            width: 100%; background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 14px 16px 14px 44px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s; box-shadow: var(--shadow-sm);
        }
        .search-input:focus { border-color: var(--primary); }

        /* ── Stock Card ── */
        .stock-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 16px; margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }
        .stock-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 12px;
        }
        .stock-name { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0; }
        .stock-sku { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-muted); }
        
        .badge-custom {
            display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 6px; white-space: nowrap;
        }
        .badge-custom.warning { background: var(--warning-bg); color: var(--warning); }
        .badge-custom.success { background: var(--success-bg); color: var(--success); }
        .badge-custom.danger { background: var(--danger-bg); color: var(--danger); }

        .stock-stats {
            display: flex; justify-content: space-between;
            font-size: 12px; font-weight: 600; color: var(--text-muted);
            margin-bottom: 8px;
        }
        
        .progress-track {
            height: 6px; background: var(--bg-color); border-radius: 10px;
            overflow: hidden; margin-bottom: 12px; border: 1px solid var(--border);
        }
        .progress-fill {
            height: 100%; border-radius: 10px; transition: width 0.3s ease;
        }
        .progress-fill.bg-primary { background: var(--primary); }
        .progress-fill.bg-danger { background: var(--danger); }
        .progress-fill.bg-warning { background: var(--warning); }

        .stock-remaining { text-align: right; }
        .remaining-value { font-family: 'JetBrains Mono', monospace; font-size: 20px; font-weight: 700; }
        .remaining-label { font-size: 12px; color: var(--text-muted); font-weight: 500; margin-left: 4px; }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 20px;
            display: flex; gap: 16px; align-items: flex-start; border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .clean-alert.warning-alert { background: var(--warning-bg); border-color: #FDE68A; color: #92400E; }
        .clean-alert.info-alert { background: var(--primary-bg); border-color: #BFDBFE; color: #1E3A8A; }
        .clean-alert i { font-size: 24px; margin-top: -2px; }
        .clean-alert.warning-alert i { color: var(--warning); }
        .clean-alert.info-alert i { color: var(--primary); }
        
        .clean-alert h6 { margin: 0 0 6px 0; font-weight: 700; font-size: 16px; }
        .clean-alert p { margin: 0; font-size: 14px; line-height: 1.5; opacity: 0.9; }

        /* ── Button ── */
        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 14px;
            font-size: 15px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: inline-block;
            background: var(--primary); color: #fff; margin-top: 16px;
        }
        .btn-full:active { transform: scale(0.98); }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="app-header">
        <div class="header-stack">
            <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <h1 class="header-title">Live Vehicle Stock</h1>
        </div>
    </header>

    <div class="page-content">
        <?php if (empty($vehicle_stock)): ?>
            <div class="clean-alert info-alert">
                <i class="bi bi-box-seam"></i>
                <div>
                    <h6>Vehicle is Empty</h6>
                    <p>Your vehicle inventory does not contain any products currently.</p>
                </div>
            </div>
        <?php else: ?>
            
            <!-- Live Search Bar -->
            <div class="search-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="stockSearchInput" class="search-input" placeholder="Search product or SKU...">
            </div>

            <p class="text-muted small fw-bold text-uppercase mb-3 ps-1" style="letter-spacing: 0.05em;">Current Inventory</p>

            <div id="stockList">
                <?php foreach ($vehicle_stock as $item): 
                    $remaining = $item['remaining'];
                    $last_audit = $item['last_audit_date'] ? date('M d, Y', strtotime($item['last_audit_date'])) : 'Never audited';
                    
                    if ($remaining <= 0) { $statusColor = 'danger'; $statusText = 'Out of Stock'; }
                    elseif ($remaining <= 5) { $statusColor = 'warning'; $statusText = 'Low Stock'; }
                    else { $statusColor = 'success'; $statusText = 'In Stock'; }
                ?>
                <div class="stock-card">
                    <div class="stock-header">
                        <div>
                            <h3 class="stock-name prod-name"><?php echo htmlspecialchars($item['name']); ?></h3>
                            <span class="stock-sku prod-sku">SKU: <?php echo htmlspecialchars($item['sku'] ?: 'N/A'); ?></span>
                        </div>
                        <span class="badge-custom <?php echo $statusColor; ?>"><?php echo $statusText; ?></span>
                    </div>
                    
                    <div class="stock-stats">
                        <span>Last Audit: <?php echo $last_audit; ?></span>
                    </div>
                    
                    <div class="stock-remaining">
                        <span class="remaining-value text-<?php echo $statusColor; ?>"><?php echo $remaining; ?></span>
                        <span class="remaining-label">Remaining in Van</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div id="noResultsMsg" class="clean-alert info-alert d-none mt-4" style="background: var(--surface); border-color: var(--border); color: var(--text-muted);">
                <i class="bi bi-search" style="color: var(--text-muted);"></i>
                <div>
                    <h6 style="color: var(--text-main);">No matches found</h6>
                    <p>No products match your search query.</p>
                </div>
            </div>
            
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('stockSearchInput');
        if(searchInput) {
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                let visibleCount = 0;
                
                document.querySelectorAll('.stock-card').forEach(card => {
                    const text = card.innerText.toLowerCase();
                    if(text.includes(term)) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                const noResults = document.getElementById('noResultsMsg');
                if(visibleCount === 0 && term !== '') {
                    noResults.classList.remove('d-none');
                } else {
                    noResults.classList.add('d-none');
                }
            });
        }
    });
    </script>
</body>
</html>