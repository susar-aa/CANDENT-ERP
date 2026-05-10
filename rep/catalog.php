<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// 1. Verify Active Route / Started Day
$routeStmt = $pdo->prepare("SELECT id FROM rep_routes WHERE rep_id = ? AND assign_date = CURDATE() AND status = 'accepted' AND start_meter IS NOT NULL ORDER BY id DESC LIMIT 1");
$routeStmt->execute([$rep_id]);
$assignment_id = $routeStmt->fetchColumn();

// 2. Fetch Available Stock (Vehicle or Main Inventory)
$vehicle_stock = [];
$searchSql = "";
$params = [];

if ($search_query !== '') {
    $searchSql = " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

if ($assignment_id) {
    // If active route, fetch vehicle stock
    $stockQuery = "
        SELECT 
            p.id as product_id, p.name, p.sku, p.selling_price, p.supplier_id, p.category_id,
            vs.stock_qty as loaded_qty,
            vs.stock_qty as remaining_qty,
            (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1) as primary_image
        FROM vehicle_stock vs
        JOIN products p ON vs.product_id = p.id
        WHERE vs.rep_id = ? AND vs.stock_qty > 0 $searchSql
        ORDER BY p.name ASC
    ";
    $final_params = array_merge([$rep_id], $params);
} else {
    // If no active route, fetch main inventory stock (for General Invoicing)
    $stockQuery = "
        SELECT 
            p.id as product_id, p.name, p.sku, p.selling_price, p.supplier_id, p.category_id,
            p.stock as loaded_qty,
            p.stock as remaining_qty,
            (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1) as primary_image
        FROM products p
        WHERE p.status = 'available' AND p.stock > 0 $searchSql
        ORDER BY p.name ASC
    ";
    $final_params = $params;
}

$stmt = $pdo->prepare($stockQuery);
$stmt->execute($final_params);
$vehicle_stock = $stmt->fetchAll();

// 3. Safely map categories dynamically
$categories_map = [];
try {
    $catStmt = $pdo->query("SELECT id, name FROM categories");
    foreach($catStmt->fetchAll() as $c) {
        $categories_map[$c['id']] = $c['name'];
    }
} catch(PDOException $e) {} // Fallback if categories table doesn't exist

$active_categories = [];
foreach($vehicle_stock as &$item) {
    $cid = $item['category_id'] ?? 0;
    $cname = $categories_map[$cid] ?? 'General';
    $item['category_name'] = $cname; 
    
    if (!isset($active_categories[$cid])) {
        $active_categories[$cid] = $cname;
    }
}
asort($active_categories); // Sort categories alphabetically

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Digital Catalog - Rep App</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Google Fonts: Inter & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    
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
            --info: #0EA5E9;
            --info-bg: #E0F2FE;
            
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            
            --nav-h: 70px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            padding-bottom: 100px;
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
            text-decoration: none; cursor: pointer;
        }
        .back-btn:active { background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; margin: 0; letter-spacing: -0.01em; }
        .header-sub { font-size: 12px; color: var(--text-muted); font-weight: 500; display: block; }

        /* ── Controls (Search & Categories) ── */
        .controls-area {
            position: sticky;
            top: 76px; /* Below header */
            background: var(--bg-color);
            z-index: 99;
            padding: 16px 16px 8px;
        }

        .search-wrapper { position: relative; margin-bottom: 16px; }
        .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 16px;
        }
        .search-input {
            width: 100%; background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 12px 16px 12px 44px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s; box-shadow: var(--shadow-sm);
        }
        .search-input:focus { border-color: var(--primary); }

        .category-scroll {
            display: flex; overflow-x: auto; gap: 8px; padding-bottom: 8px;
            scrollbar-width: none; -ms-overflow-style: none; /* Hide scrollbar */
        }
        .category-scroll::-webkit-scrollbar { display: none; }
        .cat-pill {
            white-space: nowrap; padding: 8px 16px; border-radius: 100px;
            font-size: 13px; font-weight: 600; color: var(--text-muted);
            background: var(--surface); border: 1px solid var(--border);
            text-decoration: none; transition: all 0.2s; cursor: pointer;
            box-shadow: var(--shadow-sm);
        }
        .cat-pill:active { transform: scale(0.96); }
        .cat-pill.active { background: var(--text-main); color: #fff; border-color: var(--text-main); }

        /* ── Content Area ── */
        .page-content { padding: 8px 16px 20px; }

        /* ── Catalog Grid ── */
        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .catalog-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-md); overflow: hidden;
            box-shadow: var(--shadow-sm); cursor: pointer; transition: transform 0.1s;
            display: flex; flex-direction: column;
        }
        .catalog-card:active { transform: scale(0.98); background: var(--bg-color); }
        
        .cat-img-wrapper {
            height: 120px; background: var(--bg-color);
            display: flex; align-items: center; justify-content: center;
            border-bottom: 1px solid var(--border); overflow: hidden;
        }
        .cat-img-wrapper img { width: 100%; height: 100%; object-fit: cover; }
        .cat-img-placeholder { font-size: 32px; color: var(--border); }
        
        .cat-info { padding: 12px; display: flex; flex-direction: column; flex: 1; }
        .cat-name {
            font-size: 13px; font-weight: 600; color: var(--text-main); margin: 0 0 6px 0;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
            line-height: 1.3;
        }
        .cat-price-row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: auto; }
        .cat-price { font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 700; color: var(--success); }
        .cat-stock { font-size: 10px; font-weight: 600; color: var(--primary); background: var(--primary-bg); padding: 3px 6px; border-radius: 6px; }

        /* ── Floating Cart Button ── */
        .floating-cart-btn {
            position: fixed; bottom: 85px; left: 16px; right: 16px;
            background: var(--text-main); color: #fff;
            border-radius: var(--radius-lg); padding: 16px 20px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: var(--shadow-lg); border: none; z-index: 1000;
            transition: transform 0.1s; cursor: pointer;
        }
        .floating-cart-btn:active { transform: scale(0.98); }
        .fc-badge {
            background: var(--primary); color: #fff; width: 28px; height: 28px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; font-family: 'JetBrains Mono', monospace;
        }
        .fc-total { font-family: 'JetBrains Mono', monospace; font-size: 18px; font-weight: 700; }

        /* ── Modals ── */
        .modal-content { border: none; border-radius: 24px; box-shadow: var(--shadow-lg); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 20px; }
        .modal-title { font-weight: 700; font-size: 18px; color: var(--text-main); }
        .modal-body { padding: 20px; }

        .clean-input {
            width: 100%; background: var(--bg-color); border: 1px solid var(--border);
            border-radius: var(--radius-md); padding: 12px 16px; font-size: 15px;
            font-family: 'Inter', sans-serif; color: var(--text-main); outline: none;
            transition: border 0.2s;
        }
        .clean-input.mono { font-family: 'JetBrains Mono', monospace; font-weight: 600; }
        .clean-input:focus { border-color: var(--primary); background: #fff; }
        select.clean-input {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%2364748B%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E");
            background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 10px auto;
            padding-right: 40px; font-weight: 600;
        }

        .btn-full {
            width: 100%; border: none; border-radius: var(--radius-md); padding: 16px;
            font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.1s;
            text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
            background: var(--primary); color: #fff;
        }
        .btn-full:active { transform: scale(0.98); }

        /* ── Simple Alerts ── */
        .clean-alert {
            background: var(--surface); border-radius: var(--radius-md); padding: 16px;
            display: flex; gap: 12px; align-items: center; border: 1px solid var(--border);
        }
        .clean-alert.warning-alert { background: var(--warning-bg); border-color: #FDE68A; color: #92400E; }
        .clean-alert i { font-size: 24px; margin-top: -2px; }

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

        /* iOS Switch override */
        .form-switch .form-check-input { width: 3em; height: 1.5em; margin-top: 0; cursor: pointer; }
    </style>
</head>
<body>

    <header class="app-header">
        <div class="header-stack">
            <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
            <div>
                <h1 class="header-title">Digital Catalog</h1>
                <span class="header-sub"><?php echo $assignment_id ? 'Live Vehicle Stock' : 'Main Warehouse Stock'; ?></span>
            </div>
        </div>
    </header>

    <div class="controls-area">
        <!-- Search Bar -->
        <div class="search-wrapper m-0 mb-3">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="catalogSearch" class="search-input" placeholder="Search products or SKU...">
        </div>

        <!-- Category Pills -->
        <?php if(!empty($active_categories)): ?>
        <div class="category-scroll">
            <div class="cat-pill active" data-cat="all">All Items</div>
            <?php foreach($active_categories as $cid => $cname): ?>
                <div class="cat-pill" data-cat="<?php echo $cid; ?>"><?php echo htmlspecialchars($cname); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="page-content">

        <?php if(empty($vehicle_stock)): ?>
            <div class="clean-alert warning-alert flex-column text-center py-5">
                <i class="bi bi-box-seam" style="font-size: 3rem; margin: 0; color: #D97706;"></i>
                <h6 class="m-0 mt-2" style="color: #92400E;">Inventory is out of stock!</h6>
                <p>There are no products available in the current view.</p>
            </div>
        <?php else: ?>
            
            <div id="noResultsMsg" class="clean-alert text-center d-none justify-content-center">
                <p class="m-0"><i class="bi bi-search me-2"></i>No products match your search or filter.</p>
            </div>

            <!-- Product Grid -->
            <div class="catalog-grid" id="productGrid">
                <?php foreach($vehicle_stock as $p): ?>
                <div class="catalog-card catalog-item" 
                     data-cat="<?php echo $p['category_id'] ?? 0; ?>"
                     onclick='openProductModal(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                    
                    <div class="cat-img-wrapper">
                        <?php if($p['primary_image']): ?>
                            <img src="../assets/images/products/<?php echo htmlspecialchars($p['primary_image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" loading="lazy">
                        <?php else: ?>
                            <i class="bi bi-image cat-img-placeholder"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="cat-info">
                        <div class="cat-name prod-name"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="d-none prod-sku"><?php echo htmlspecialchars($p['sku']); ?></div>
                        
                        <div class="cat-price-row">
                            <span class="cat-price">Rs <?php echo number_format($p['selling_price'], 2); ?></span>
                            <span class="cat-stock"><?php echo $p['remaining_qty']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Floating Checkout Button -->
    <button type="button" class="floating-cart-btn d-none" id="mainCheckoutBtn">
        <div class="d-flex align-items-center gap-3">
            <div class="fc-badge" id="cartItemCount">0</div>
            <span class="fw-bold fs-6">Continue to POS <i class="bi bi-arrow-right ms-1"></i></span>
        </div>
        <div class="fc-total">Rs <span id="cartTotalBtn">0.00</span></div>
    </button>

    <!-- Product Entry Modal (Matches create_order.php) -->
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalProdName">Product Name</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-muted small fw-bold mb-3">Available Stock: <span id="modalProdStock" class="text-primary font-monospace fs-6 ms-1">0</span></div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Quantity</label>
                            <input type="number" id="modalQty" class="clean-input mono text-center fs-4 py-2" value="1" min="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Price (Rs)</label>
                            <input type="number" id="modalPrice" class="clean-input mono text-center fs-4 py-2 text-success" step="0.01">
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">Item Discount</label>
                        <div class="d-flex gap-2">
                            <select id="modalDisType" class="clean-input flex-shrink-0" style="width: 80px;">
                                <option value="%">%</option>
                                <option value="Rs">Rs</option>
                            </select>
                            <input type="number" id="modalDisValue" class="clean-input mono text-danger" value="0" min="0" step="0.01">
                        </div>
                    </div>
                    
                    <!-- MANUAL FOC SWITCH -->
                    <div class="mb-4 p-3 rounded-3" style="background: var(--danger-bg); border: 1px solid #FECACA;">
                        <div class="form-check form-switch d-flex align-items-center gap-3 m-0 p-0">
                            <input class="form-check-input m-0 flex-shrink-0" type="checkbox" role="switch" id="isManualFoc">
                            <label class="form-check-label fw-bold text-danger m-0" for="isManualFoc" style="cursor: pointer;">Issue as Free Item (FOC)</label>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center p-3 rounded-3" style="background: var(--bg-color); border: 1px solid var(--border);">
                        <span class="fw-bold text-muted text-uppercase small">Net Total:</span>
                        <span class="fs-3 fw-bold text-dark font-monospace">Rs <span id="modalNetTotal">0.00</span></span>
                    </div>
                    
                    <button type="button" class="btn-full mt-4" id="btnAddToCart">Add to Cart</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-tab">
            <i class="bi bi-house-door-fill"></i> Home
        </a>
        <a href="catalog.php" class="nav-tab active">
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
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        const cartBtn = document.getElementById('mainCheckoutBtn');
        const countBadge = document.getElementById('cartItemCount');
        const totalBtnText = document.getElementById('cartTotalBtn');
        
        const prodModal = new bootstrap.Modal(document.getElementById('productModal'));
        const modalQty = document.getElementById('modalQty');
        const modalPrice = document.getElementById('modalPrice');
        const modalDisType = document.getElementById('modalDisType');
        const modalDisValue = document.getElementById('modalDisValue');
        const modalNetTotal = document.getElementById('modalNetTotal');
        const isManualFoc = document.getElementById('isManualFoc'); 

        const searchInput = document.getElementById('catalogSearch');
        const pills = document.querySelectorAll('.cat-pill');
        const items = document.querySelectorAll('.catalog-item');
        const noResultsMsg = document.getElementById('noResultsMsg');

        let cart = [];
        let activeProductData = null; 
        
        let currentSearch = '';
        let currentCategory = 'all';

        // Load existing cart from localStorage if exists
        const savedCart = localStorage.getItem('fintrix_rep_pos_cart');
        if (savedCart) {
            try {
                cart = JSON.parse(savedCart);
                updateCartUI();
            } catch(e) {}
        }

        // --- Filtering Logic (Search + Category) ---
        function filterCatalog() {
            let visibleCount = 0;
            
            items.forEach(card => {
                const text = card.querySelector('.prod-name').innerText.toLowerCase();
                const sku = card.querySelector('.prod-sku').innerText.toLowerCase();
                const catId = card.dataset.cat;
                
                const matchesSearch = text.includes(currentSearch) || sku.includes(currentSearch);
                const matchesCategory = (currentCategory === 'all') || (catId === currentCategory);

                if (matchesSearch && matchesCategory) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (visibleCount === 0 && items.length > 0) {
                noResultsMsg.classList.remove('d-none');
                noResultsMsg.classList.add('d-flex');
            } else {
                noResultsMsg.classList.add('d-none');
                noResultsMsg.classList.remove('d-flex');
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                currentSearch = this.value.toLowerCase();
                filterCatalog();
            });
        }

        pills.forEach(pill => {
            pill.addEventListener('click', function(e) {
                e.preventDefault();
                pills.forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                currentCategory = this.dataset.cat;
                filterCatalog();
            });
        });


        // --- Add to Cart Logic ---
        window.openProductModal = function(product) {
            activeProductData = product;
            
            document.getElementById('modalProdName').textContent = product.name;
            document.getElementById('modalProdStock').textContent = product.remaining_qty;
            
            isManualFoc.checked = false;
            modalPrice.readOnly = false;
            modalDisValue.readOnly = false;

            const existing = cart.find(c => c.product_id == product.product_id && !c.promo_id);
            if (existing) {
                modalQty.value = existing.quantity;
                modalPrice.value = existing.sell_price.toFixed(2);
                modalDisType.value = existing.dis_type;
                modalDisValue.value = existing.dis_value;
                if(existing.is_foc) {
                    isManualFoc.checked = true;
                    modalPrice.readOnly = true;
                    modalDisValue.readOnly = true;
                }
            } else {
                modalQty.value = 1;
                modalPrice.value = parseFloat(product.selling_price).toFixed(2);
                modalDisType.value = '%';
                modalDisValue.value = 0;
            }
            
            modalQty.max = product.remaining_qty;
            calculateModalNet();
            prodModal.show();
        };

        isManualFoc.addEventListener('change', function() {
            if (this.checked) {
                modalPrice.value = "0.00";
                modalDisValue.value = "0";
                modalPrice.readOnly = true;
                modalDisValue.readOnly = true;
            } else {
                if (activeProductData) modalPrice.value = parseFloat(activeProductData.selling_price).toFixed(2);
                modalPrice.readOnly = false;
                modalDisValue.readOnly = false;
            }
            calculateModalNet();
        });

        function calculateModalNet() {
            if(!activeProductData) return;
            
            if (isManualFoc.checked) {
                modalNetTotal.textContent = "0.00";
                return;
            }

            const qty = parseFloat(modalQty.value) || 0;
            const price = parseFloat(modalPrice.value) || 0;
            const dType = modalDisType.value;
            const dVal = parseFloat(modalDisValue.value) || 0;
            
            const gross = qty * price;
            let disAmt = dType === '%' ? gross * (dVal / 100) : dVal * qty;
            
            modalNetTotal.textContent = (gross - disAmt).toFixed(2);
        }

        [modalQty, modalPrice, modalDisType, modalDisValue].forEach(el => el.addEventListener('input', calculateModalNet));

        document.getElementById('btnAddToCart').addEventListener('click', function() {
            const qty = parseInt(modalQty.value) || 0;
            const price = parseFloat(modalPrice.value) || 0;
            const maxStock = parseInt(activeProductData.remaining_qty);
            const isFoc = isManualFoc.checked;

            if (qty <= 0) { alert('Quantity must be greater than 0.'); return; }
            if (qty > maxStock) { alert(`Only ${maxStock} items available in van.`); return; }

            const existingIdx = cart.findIndex(c => c.product_id == activeProductData.product_id && c.is_foc === isFoc && !c.promo_id);
            
            const cartItem = {
                product_id: activeProductData.product_id,
                supplier_id: activeProductData.supplier_id,
                name: activeProductData.name,
                sku: activeProductData.sku || 'N/A',
                category_id: activeProductData.category_id,
                sell_price: price,
                quantity: qty,
                dis_type: modalDisType.value,
                dis_value: parseFloat(modalDisValue.value) || 0,
                max_stock: maxStock,
                is_foc: isFoc,
                promo_id: null // Promos are evaluated in create_order.php
            };

            if (existingIdx > -1) {
                cart[existingIdx] = cartItem;
            } else {
                cart.push(cartItem);
            }

            // Save to localStorage immediately so it's accessible in create_order.php
            localStorage.setItem('fintrix_rep_pos_cart', JSON.stringify(cart));
            
            updateCartUI();
            prodModal.hide();
        });

        function updateCartUI() {
            let subtotal = 0;
            let totalItems = 0;

            cart.forEach((item) => {
                totalItems++;
                const gross = item.sell_price * item.quantity;
                let itemDisAmt = item.dis_type === '%' ? (gross * (item.dis_value / 100)) : (item.dis_value * item.quantity);
                subtotal += (gross - itemDisAmt);
            });

            if (totalItems > 0) {
                cartBtn.classList.remove('d-none');
                countBadge.textContent = totalItems;
                totalBtnText.textContent = subtotal.toFixed(2);
            } else {
                cartBtn.classList.add('d-none');
            }
        }

        // Send to POS
        cartBtn.addEventListener('click', function() {
            window.location.href = 'create_order.php<?php echo !$assignment_id ? "?general=true" : ""; ?>';
        });

    });
    </script>
</body>
</html>