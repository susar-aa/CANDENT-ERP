<?php
session_start();
require_once 'config/db.php';

// Handle Customer Auth
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] == 'customer_login') {
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            
            if (empty($email) || empty($password)) {
                throw new Exception("Please provide both email and password.");
            }

            $stmt = $pdo->prepare("SELECT id, password FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer && password_verify($password, $customer['password'])) {
                $_SESSION['customer_id'] = $customer['id'];
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("Invalid email or password.");
            }
        } elseif ($_POST['action'] == 'customer_register') {
            $name = trim($_POST['name']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $password = $_POST['password'];
            $confirm_claim = isset($_POST['confirm_claim']) && $_POST['confirm_claim'] == '1';
            
            if (empty($name) || empty($phone) || empty($email) || empty($password)) {
                throw new Exception("Please fill all required fields.");
            }
            
            $stmt = $pdo->prepare("SELECT id, name, phone, password FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                if (!empty($existing['password'])) {
                    throw new Exception("Email address is already registered. Please log in.");
                }
                
                if (!$confirm_claim) {
                    echo json_encode([
                        'success' => false, 
                        'requires_claim' => true, 
                        'data' => [
                            'name' => $existing['name'],
                            'phone' => $existing['phone']
                        ]
                    ]);
                    exit;
                }
                
                // Update existing record (Claim)
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE customers SET phone = ?, password = ?, owner_name = ? WHERE id = ?");
                $stmt->execute([$phone, $hashed, $name, $existing['id']]);
                $_SESSION['customer_id'] = $existing['id'];
                
                echo json_encode(['success' => true]);
                exit;
            }
            
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $email, $hashed]);
            $_SESSION['customer_id'] = $pdo->lastInsertId();
            
            echo json_encode(['success' => true]);
        } elseif ($_POST['action'] == 'customer_logout') {
            unset($_SESSION['customer_id']);
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch Categories
$categories = [];
try {
    $catStmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch Products
$products = [];
try {
    $prodStmt = $pdo->query("
        SELECT p.*, 
        (SELECT image_path FROM product_images WHERE product_id = p.id AND is_thumbnail = 1 LIMIT 1) as thumbnail_image,
        (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1) as primary_image 
        FROM products p 
        WHERE p.status = 'available' AND (p.stock > 0 OR (SELECT COALESCE(SUM(stock_qty), 0) FROM vehicle_stock WHERE product_id = p.id) > 0)
        ORDER BY p.name ASC
    ");
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch all product images for gallery
$allProductImages = [];
try {
    $imgStmt = $pdo->query("SELECT product_id, image_path FROM product_images ORDER BY is_thumbnail DESC, id ASC");
    while ($imgRow = $imgStmt->fetch(PDO::FETCH_ASSOC)) {
        $allProductImages[$imgRow['product_id']][] = $imgRow['image_path'];
    }
} catch (PDOException $e) {}

// Group products by category for unified card display
$productsByCategory = [];
foreach ($products as $p) {
    $productsByCategory[$p['category_id']][] = $p;
}

// Helper: Minimal Emoji Icons
function categoryIcon($name) {
    $n = strtolower($name);
    if (str_contains($n, 'energy') || str_contains($n, 'power') || str_contains($n, 'boost')) return '⚡';
    if (str_contains($n, 'choco') || str_contains($n, 'cocoa')) return '🍫';
    if (str_contains($n, 'coffee') || str_contains($n, 'tea')) return '☕';
    if (str_contains($n, 'juice') || str_contains($n, 'soft') || str_contains($n, 'soda') || str_contains($n, 'drink')) return '🥤';
    if (str_contains($n, 'water')) return '💧';
    if (str_contains($n, 'candy') || str_contains($n, 'sweet')) return '🍬';
    if (str_contains($n, 'gum')) return '🎈';
    return '✨';
}

$is_logged_in = isset($_SESSION['customer_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candent | Premium Store</title>
    
    <!-- Fonts: Inter for iOS System-like UI -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            /* Ultra-Clean iOS Minimal Palette */
            --ios-bg: #F2F2F7;
            --ios-surface: #FFFFFF;
            --ios-surface-2: #F9F9F9;
            --ios-text: #1C1C1E;
            --ios-text-muted: #8E8E93;
            --ios-blue: #007AFF;
            --ios-blue-hover: #0055CC;
            --ios-red: #FF3B30;
            --ios-separator: rgba(60,60,67,0.12);
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 8px;
            --shadow-card: 0 4px 16px rgba(0,0,0,0.03);
            --shadow-hover: 0 12px 28px rgba(0,0,0,0.06);
        }

        * { -webkit-font-smoothing: antialiased; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
            background-color: var(--ios-bg);
            color: var(--ios-text);
            overflow-x: hidden;
        }

        /* ── Navbar ── */
        .navbar {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--ios-separator);
            padding: 12px 0;
            z-index: 1030;
        }
        .navbar-brand img { height: 38px; object-fit: contain; }
        
        .nav-icon-btn {
            position: relative;
            background: var(--ios-bg);
            border: none;
            border-radius: 50%;
            width: 42px; height: 42px;
            display: flex; align-items: center; justify-content: center;
            color: var(--ios-text);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .nav-icon-btn:hover { background: #E5E5EA; }
        
        .cart-badge {
            position: absolute;
            top: -2px; right: -2px;
            background: var(--ios-red);
            color: #fff;
            font-size: 11px; font-weight: 800;
            width: 20px; height: 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid var(--ios-surface);
        }

        .search-wrap { position: relative; width: 300px; }
        .search-wrap i {
            position: absolute; top: 50%; left: 14px;
            transform: translateY(-50%);
            color: var(--ios-text-muted);
        }
        .search-wrap input {
            background: var(--ios-bg);
            border: 1px solid transparent;
            border-radius: 10px;
            padding: 10px 14px 10px 38px;
            font-weight: 500;
            font-size: 14px;
            width: 100%;
            color: var(--ios-text);
            transition: all 0.2s;
        }
        .search-wrap input:focus {
            outline: none;
            background: #fff;
            border-color: var(--ios-blue);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.15);
        }

        /* ── Full Width Hero ── */
        .hero {
            position: relative;
            width: 100%;
            height: 60vh;
            min-height: 400px;
            background-color: var(--ios-text);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .hero img {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            z-index: 0;
        }
        .hero-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.35); /* Subtle dark overlay for text readability */
            z-index: 1;
        }
        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            color: #fff;
            padding: 0 20px;
        }
        .hero-title {
            font-weight: 800;
            font-size: clamp(2.5rem, 6vw, 4.5rem);
            letter-spacing: -1.5px;
            margin-bottom: 16px;
        }
        .hero-subtitle {
            font-size: 1.15rem;
            font-weight: 500;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto 30px auto;
        }
        .btn-ios-primary {
            background: var(--ios-blue);
            color: #fff;
            border: none;
            border-radius: 50px;
            padding: 14px 32px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.2s;
            display: inline-block;
            text-decoration: none;
        }
        .btn-ios-primary:hover { background: var(--ios-blue-hover); color: #fff; transform: scale(0.98); }

        /* ── Flexible Masonry Category Layout ── */
        .store-section { padding: 60px 0; }
        
        .masonry-grid {
            column-count: 1;
            column-gap: 24px;
        }
        @media (min-width: 768px) { .masonry-grid { column-count: 2; } }
        /* Kept at 2 columns for large screens to allow internal flex items to expand beautifully */
        @media (min-width: 1200px) { .masonry-grid { column-count: 2; } } 

        .category-wrapper {
            break-inside: avoid;
            page-break-inside: avoid;
            background: var(--ios-surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--ios-separator);
            margin-bottom: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }
        
        .category-header {
            padding: 20px 24px 16px;
            background: var(--ios-surface);
            border-bottom: 1px solid var(--ios-separator);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .category-header h2 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--ios-text);
            letter-spacing: -0.5px;
        }

        /* ── Flexible Product Grid Inside Category ── */
        .category-product-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            padding: 20px;
            background: var(--ios-bg); /* Subtle contrast to separate cards from category wrapper */
        }

        .product-card {
            flex: 1 1 240px; /* FLEX MAGIC: Automatically grows to fill empty space! Base width 240px. */
            background: var(--ios-surface);
            border-radius: var(--radius-md);
            border: 1px solid var(--ios-separator);
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-color: rgba(0, 122, 255, 0.3);
        }
        
        .product-img-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1; /* Maintains perfect square regardless of how much flex expands it */
            background: #fff;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 1px solid var(--ios-separator);
        }
        .product-img-wrap img {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            object-fit: contain;
            padding: 24px; /* Sufficient padding so image doesn't hit edges when expanded */
            transition: transform 0.5s ease;
        }
        .product-card:hover .product-img-wrap img {
            transform: scale(1.08);
        }
        
        .product-info {
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .product-title {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--ios-text);
            margin-bottom: 12px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .product-price-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            border-top: 1px dashed var(--ios-separator);
            padding-top: 12px;
        }
        .product-price {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--ios-text);
        }
        .product-price small {
            display: block;
            font-size: 10px;
            color: var(--ios-text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: -2px;
        }

        /* ── Add to Cart Buttons ── */
        .btn-add-cart {
            background: var(--ios-bg);
            color: var(--ios-blue);
            border: none;
            border-radius: 50px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-add-cart:hover { background: #E5E5EA; }

        .cart-controls {
            display: none; align-items: center;
            background: var(--ios-bg);
            border-radius: 50px;
            padding: 3px;
        }
        .cart-controls.active { display: flex; }
        .btn-add-cart.hidden { display: none; }
        .qty-btn {
            width: 28px; height: 28px;
            border-radius: 50%;
            border: none;
            background: var(--ios-surface);
            color: var(--ios-blue);
            display: flex; align-items: center; justify-content: center;
            font-weight: bold;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .qty-btn:hover { background: var(--ios-blue); color: #fff; }
        .qty-val { min-width: 26px; text-align: center; font-weight: 700; font-size: 13px; color: var(--ios-text); }

        /* No results */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--ios-text-muted); }
        .empty-state i { font-size: 3rem; color: var(--ios-text-muted); opacity: 0.5; }
        .empty-state h5 { font-weight: 700; color: var(--ios-text); margin-top: 16px; }

        /* ── Offcanvas Cart & Modals ── */
        .offcanvas-end {
            width: 400px;
            border-left: 1px solid var(--ios-separator);
            background: var(--ios-bg);
        }
        .offcanvas-header { background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); }
        .offcanvas-title { font-weight: 700; font-size: 1.1rem; }

        .cart-item {
            display: flex; gap: 16px;
            padding: 16px;
            border-bottom: 1px solid var(--ios-separator);
            background: var(--ios-surface);
        }
        .cart-item:last-child { border-bottom: none; }
        .cart-item-img {
            width: 70px; height: 70px;
            background: var(--ios-bg);
            border-radius: var(--radius-sm);
            padding: 6px;
            object-fit: contain;
            border: 1px solid var(--ios-separator);
        }
        .cart-item-title { font-size: 14px; font-weight: 600; color: var(--ios-text); margin-bottom: 4px; line-height: 1.3; }
        .cart-item-price { font-weight: 700; color: var(--ios-text-muted); font-size: 14px; }
        
        .btn-checkout {
            background: var(--ios-blue);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            width: 100%;
            transition: background 0.2s;
        }
        .btn-checkout:hover { background: var(--ios-blue-hover); }

        .modal-content {
            border-radius: var(--radius-lg);
            border: none;
            background: var(--ios-surface);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        .modal-header { border-bottom: none; padding: 24px 24px 0; }
        .modal-body { padding: 24px; }
        
        .form-control-custom {
            background: var(--ios-bg);
            border: 1px solid transparent;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 15px;
            color: var(--ios-text);
            transition: all 0.2s;
            width: 100%;
        }
        .form-control-custom:focus {
            background: #fff;
            border-color: var(--ios-blue);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.15);
            outline: none;
        }
        .form-label { color: var(--ios-text-muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }

        .modal .btn-primary { background: var(--ios-blue) !important; border: none; border-radius: 10px; font-weight: 600; }
        .modal .btn-primary:hover { background: var(--ios-blue-hover) !important; }

        /* ── Promise Band (Minimal Redesign) ── */
        .promise-band {
            background: var(--ios-surface);
            border: 1px solid var(--ios-separator);
            border-radius: var(--radius-lg);
            padding: 32px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-top: 40px;
        }
        .promise-item { display: flex; gap: 16px; align-items: flex-start; }
        .promise-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            background: var(--ios-bg);
            color: var(--ios-text);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
            border: 1px solid var(--ios-separator);
        }
        .promise-item h6 { margin: 0 0 4px; color: var(--ios-text); font-weight: 700; font-size: 1rem; letter-spacing: -0.2px; }
        .promise-item p { margin: 0; color: var(--ios-text-muted); font-size: 0.85rem; line-height: 1.4; }

        /* ── Footer ── */
        .ios-footer {
            background: var(--ios-surface);
            border-top: 1px solid var(--ios-separator);
            padding: 60px 0 40px;
            margin-top: 60px;
        }
        .footer-brand { height: 35px; object-fit: contain; margin-bottom: 20px; }
        .footer-text { color: var(--ios-text-muted); font-size: 0.9rem; line-height: 1.6; }
        .footer-heading { font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ios-text); margin-bottom: 16px; }
        .footer-links { list-style: none; padding: 0; margin: 0; }
        .footer-links li { margin-bottom: 10px; }
        .footer-links a { color: var(--ios-text-muted); text-decoration: none; font-weight: 500; font-size: 0.9rem; transition: color 0.2s; }
        .footer-links a:hover { color: var(--ios-text); }
        
        .footer-bottom {
            border-top: 1px solid var(--ios-separator);
            padding-top: 24px; margin-top: 40px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
        }

        @media (max-width: 768px) {
            .promise-band { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .promise-band { grid-template-columns: 1fr; }
        }

        /* ── Product Image Gallery Overlay ── */
        .product-img-wrap {
            position: relative;
            cursor: pointer;
        }
        .img-gallery-badge {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.55);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 4px;
            backdrop-filter: blur(4px);
            transition: all 0.2s;
            z-index: 3;
        }
        .product-card:hover .img-gallery-badge {
            background: rgba(0, 122, 255, 0.85);
        }
        .product-img-wrap .zoom-icon {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0);
            display: flex; align-items: center; justify-content: center;
            transition: background 0.3s;
            z-index: 2;
            border-radius: 0;
        }
        .product-img-wrap .zoom-icon i {
            font-size: 1.8rem;
            color: #fff;
            opacity: 0;
            transform: scale(0.8);
            transition: all 0.3s ease;
        }
        .product-card:hover .product-img-wrap .zoom-icon {
            background: rgba(0,0,0,0.18);
        }
        .product-card:hover .product-img-wrap .zoom-icon i {
            opacity: 1;
            transform: scale(1);
        }

        /* ── Lightbox Modal ── */
        #productLightbox .modal-content {
            background: #111;
            border-radius: 20px;
            border: none;
            overflow: hidden;
        }
        #productLightbox .modal-header {
            background: rgba(255,255,255,0.04);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 16px 20px;
        }
        #productLightbox .modal-title {
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
        }
        #productLightbox .btn-close {
            filter: invert(1);
        }
        .lightbox-main-img {
            width: 100%;
            max-height: 460px;
            object-fit: contain;
            background: #111;
            display: block;
            border-radius: 0;
            transition: opacity 0.2s ease;
        }
        .lightbox-thumbs {
            display: flex;
            gap: 10px;
            padding: 14px 20px;
            overflow-x: auto;
            background: rgba(255,255,255,0.03);
        }
        .lightbox-thumbs::-webkit-scrollbar { height: 4px; }
        .lightbox-thumbs::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius:10px; }
        .lightbox-thumb {
            width: 70px; height: 70px;
            border-radius: 10px;
            object-fit: cover;
            cursor: pointer;
            opacity: 0.5;
            border: 2px solid transparent;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .lightbox-thumb:hover { opacity: 0.8; }
        .lightbox-thumb.active {
            opacity: 1;
            border-color: var(--ios-blue);
        }
        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(8px);
            border: none;
            color: #fff;
            width: 44px; height: 44px;
            border-radius: 50%;
            font-size: 1.2rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            z-index: 10;
        }
        .lightbox-nav:hover { background: rgba(0,122,255,0.6); }
        .lightbox-nav.prev { left: 16px; }
        .lightbox-nav.next { right: 16px; }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar sticky-top">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="index.php" class="navbar-brand">
                <img src="/images/logo/croped-bg-removed-logo.png" alt="Candent Logo" onerror="this.src='https://via.placeholder.com/150x40/ffffff/000000?text=CANDENT'">
            </a>

            <div class="d-flex align-items-center gap-2 gap-md-3">
                <div class="search-wrap d-none d-md-block">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Search catalog...">
                </div>

                <button class="nav-icon-btn" onclick="openProfile()" title="Account">
                    <i class="bi bi-person-fill"></i>
                </button>
                <button class="nav-icon-btn" data-bs-toggle="offcanvas" data-bs-target="#cartOffcanvas" title="Cart">
                    <i class="bi bi-bag-fill"></i>
                    <span class="cart-badge" id="navCartCount">0</span>
                </button>
            </div>
        </div>
    </nav>

    <!-- Hero Section (Full Width Image) -->
    <section class="hero">
        <img src="./images/hero.png" alt="Candent Premium Products" onerror="this.style.display='none';">
        <div class="hero-overlay"></div>
        <div class="hero-content container">
            <h1 class="hero-title">Premium Distribution.</h1>
            <p class="hero-subtitle">Discover the finest selection of energy drinks and artisanal chocolates, available for immediate delivery to your store.</p>
            <a href="#storeSection" class="btn-ios-primary">Shop the Catalog</a>
        </div>
    </section>

    <!-- Store Section (Flexible Masonry Layout) -->
    <section id="storeSection" class="store-section container">
        <div id="noResultsMsg" class="empty-state" style="display: none;">
            <i class="bi bi-search"></i>
            <h5>No products found.</h5>
            <p>Try searching for something else.</p>
        </div>

        <!-- Masonry Container -->
        <div class="masonry-grid">
            <?php foreach($categories as $cat): 
                $catProducts = $productsByCategory[$cat['id']] ?? [];
                if(empty($catProducts)) continue;
                $emoji = categoryIcon($cat['name']);
            ?>
            <div class="category-wrapper masonry-item">
                <div class="category-header">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size: 1.5rem; line-height: 1;"><?php echo $emoji; ?></span>
                        <h2 class="m-0 fw-bold"><?php echo htmlspecialchars($cat['name']); ?></h2>
                    </div>
                    <span class="badge" style="background: var(--ios-bg); color: var(--ios-text-muted); font-size: 0.75rem; padding: 6px 12px; border-radius: 50px; border: 1px solid var(--ios-separator);"><?php echo count($catProducts); ?> Items</span>
                </div>
                
                <div class="category-product-grid">
                    <?php foreach($catProducts as $p): ?>
                        <?php 
                            $thumbSrc = $p['thumbnail_image'] ?: $p['primary_image'];
                            $imgSrc = $thumbSrc ? 'assets/images/products/' . $thumbSrc : 'https://via.placeholder.com/400x400/F2F2F7/8E8E93?text=Candent';
                            $galleryImages = $allProductImages[$p['id']] ?? [];
                            $imgCount = count($galleryImages);
                            $galleryJson = htmlspecialchars(json_encode($galleryImages), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="product-card" data-name="<?php echo strtolower(htmlspecialchars($p['name'])); ?>">
                            <div class="product-img-wrap" onclick="openLightbox(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>', <?php echo $galleryJson; ?>)">
                                <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                                <div class="zoom-icon"><i class="bi bi-zoom-in"></i></div>
                                <?php if($imgCount > 1): ?>
                                <div class="img-gallery-badge">
                                    <i class="bi bi-images" style="font-size:10px;"></i> <?php echo $imgCount; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-info">
                                <div class="product-title"><?php echo htmlspecialchars($p['name']); ?></div>
                                <div class="product-price-row">
                                    <div class="product-price">
                                        <small>Price</small>
                                        Rs <?php echo number_format($p['selling_price'], 2); ?>
                                    </div>
                                    <div class="cart-actions" data-id="<?php echo $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-price="<?php echo $p['selling_price']; ?>" data-img="<?php echo htmlspecialchars($imgSrc); ?>">
                                        <button class="btn-add-cart" onclick="addToCart(this)">Add</button>
                                        <div class="cart-controls">
                                            <button class="qty-btn" onclick="updateQty(this, -1)"><i class="bi bi-dash"></i></button>
                                            <span class="qty-val">1</span>
                                            <button class="qty-btn" onclick="updateQty(this, 1)"><i class="bi bi-plus"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Promise band (Minimal Theme) -->
    <div class="container mb-5 pb-4">
        <div class="promise-band">
            <div class="promise-item">
                <div class="promise-icon"><i class="bi bi-truck"></i></div>
                <div><h6>Swift Delivery</h6><p>Doorstep delivery within 48 hours island-wide.</p></div>
            </div>
            <div class="promise-item">
                <div class="promise-icon"><i class="bi bi-award"></i></div>
                <div><h6>Curated Quality</h6><p>Every brand is hand-picked before it joins our pantry.</p></div>
            </div>
            <div class="promise-item">
                <div class="promise-icon"><i class="bi bi-shield-check"></i></div>
                <div><h6>Freshness Promise</h6><p>Stock rotated weekly so nothing sits on the shelf.</p></div>
            </div>
            <div class="promise-item">
                <div class="promise-icon"><i class="bi bi-headset"></i></div>
                <div><h6>Real Humans</h6><p>Our team is one call away — always.</p></div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="ios-footer">
        <div class="container">
            <div class="row g-5 mb-4">
                <div class="col-lg-4 col-md-6">
                    <img src="/images/logo/logo.png" alt="Candent" class="footer-brand" onerror="this.src='https://via.placeholder.com/150x40/ffffff/000000?text=CANDENT'">
                    <p class="footer-text mt-3 mb-4">
                        Seamlessly connecting premium energy and chocolate brands directly to retailers through a modern, efficient supply chain ecosystem.
                    </p>
                </div>

                <div class="col-lg-3 col-md-6 offset-lg-1">
                    <h5 class="footer-heading">Quick Links</h5>
                    <ul class="footer-links">
                        <li><a href="#">Home</a></li>
                        <li><a href="#storeSection">Catalog</a></li>
                        <li><a href="#" onclick="openProfile(); return false;">My Account</a></li>
                        <li><a href="login.php">Staff Login</a></li>
                    </ul>
                </div>

                <div class="col-lg-4 col-md-12">
                    <h5 class="footer-heading">Contact</h5>
                    <ul class="footer-links">
                        <li class="d-flex gap-2"><i class="bi bi-geo-alt-fill text-muted"></i> <span>79, Dambakanda Estate,<br>Boyagane, Kurunegala</span></li>
                        <li class="d-flex gap-2"><i class="bi bi-telephone-fill text-muted"></i> <a href="tel:0761407876">076 140 7876</a></li>
                        <li class="d-flex gap-2"><i class="bi bi-envelope-fill text-muted"></i> <a href="mailto:candentlk@gmail.com">candentlk@gmail.com</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <div class="footer-text">
                    &copy; <?php echo date('Y'); ?> <strong>Candent</strong>. All rights reserved.
                </div>
                <div class="footer-text">
                    Developed by <a href="https://suzxlabs.com" target="_blank" style="color: var(--ios-blue); font-weight: 600; text-decoration: none;">Suzxlabs</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Cart Offcanvas -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="cartOffcanvas">
        <div class="offcanvas-header py-4 px-4">
            <h5 class="offcanvas-title m-0">Your Cart</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body px-0 pb-0 d-flex flex-column">
            <div id="cartItemsContainer" class="flex-grow-1 overflow-auto">
                <!-- Cart items injected here -->
            </div>

            <div class="p-4 mt-auto" style="background: var(--ios-surface); border-top: 1px solid var(--ios-separator);">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span style="font-weight: 600; color: var(--ios-text-muted);">Subtotal</span>
                    <span class="fw-bold fs-5" id="cartSubtotal">Rs 0.00</span>
                </div>
                <button class="btn-checkout" onclick="proceedToCheckout()">
                    Checkout
                </button>
            </div>
        </div>
    </div>

    <!-- Login/Register Modal -->
    <div class="modal fade" id="authModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header d-flex flex-column align-items-center text-center pb-0">
                    <h4 class="fw-bold m-0" id="authModalTitle">Welcome</h4>
                    <p class="small mt-2 m-0 text-muted" id="authModalDesc">Please sign in to continue.</p>
                    <button type="button" class="btn-close position-absolute" style="top: 20px; right: 20px;" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Login Form -->
                    <form id="loginForm" onsubmit="handleLogin(event)">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" id="loginEmail" class="form-control-custom" placeholder="user@example.com" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <input type="password" id="loginPassword" class="form-control-custom" placeholder="••••••••" required>
                        </div>
                        <button type="submit" id="btnLogin" class="btn btn-primary w-100 py-3 mb-3">
                            Sign In
                        </button>
                        <div class="text-center">
                            <a href="#" onclick="toggleAuthMode('register'); return false;" class="small fw-semibold text-decoration-none" style="color: var(--ios-blue);">Create an account</a>
                        </div>
                    </form>

                    <!-- Register Form -->
                    <form id="registerForm" onsubmit="handleRegister(event)" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" id="regName" class="form-control-custom" placeholder="John Doe" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" id="regPhone" class="form-control-custom" placeholder="07XXXXXXXX" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" id="regEmail" class="form-control-custom" placeholder="user@example.com" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <input type="password" id="regPassword" class="form-control-custom" placeholder="••••••••" required>
                        </div>
                        <button type="submit" id="btnRegister" class="btn btn-primary w-100 py-3 mb-3">
                            Sign Up
                        </button>
                        <div class="text-center">
                            <a href="#" onclick="toggleAuthMode('login'); return false;" class="small fw-semibold text-decoration-none" style="color: var(--ios-blue);">Already have an account?</a>
                        </div>
                    </form>

                    <!-- Claim Profile Form -->
                    <div id="claimForm" style="display: none;" class="text-center">
                        <div class="p-3 mb-4 rounded-3" style="background: var(--ios-bg); border: 1px solid var(--ios-separator);">
                            <h6 class="fw-bold mb-2">Is this you?</h6>
                            <p class="small text-muted mb-3">We found a matching profile from store visits.</p>
                            <div class="text-start bg-white p-2 rounded border">
                                <div class="mb-1"><small class="text-muted fw-bold" style="font-size:10px;">NAME</small><br><span id="claimNameText" class="fw-bold" style="font-size:14px;"></span></div>
                                <div><small class="text-muted fw-bold" style="font-size:10px;">PHONE</small><br><span id="claimPhoneText" class="fw-bold" style="font-size:14px;"></span></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary w-100 py-3 mb-3" onclick="handleRegister(event, true)">Yes, link account</button>
                        <button type="button" class="btn btn-link text-muted small text-decoration-none p-0" onclick="toggleAuthMode('register')">No, go back</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header border-bottom py-3">
                    <h6 class="m-0 fw-bold">My Account</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px; background: var(--ios-bg); color: var(--ios-text-muted);">
                        <i class="bi bi-person-fill fs-2"></i>
                    </div>
                    
                    <button class="btn btn-primary w-100 py-2 mb-2" onclick="viewFullProfile()">Account Details</button>
                    <button class="btn w-100 py-2 mb-2" style="background: var(--ios-bg); color: var(--ios-text);" onclick="window.location.href='checkout.php'">Go to Checkout</button>
                    <button class="btn w-100 py-2 mt-2" style="color: var(--ios-red); font-weight: 600;" onclick="handleLogout()">Sign Out</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Profile View Modal -->
    <div class="modal fade" id="customerProfileViewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content overflow-hidden" style="height: 90vh;">
                <div class="modal-header py-3 px-4 border-bottom">
                    <h5 class="m-0 fw-bold">Account History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 bg-light">
                    <iframe id="profileIframe" src="" frameborder="0" style="width: 100%; height: 100%;"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Product Lightbox Modal -->
    <div class="modal fade" id="productLightbox" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="lightboxProductName">Product Images</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="position:relative;">
                    <button class="lightbox-nav prev" id="lightboxPrev" onclick="lightboxNav(-1)"><i class="bi bi-chevron-left"></i></button>
                    <button class="lightbox-nav next" id="lightboxNext" onclick="lightboxNav(1)"><i class="bi bi-chevron-right"></i></button>
                    <img id="lightboxMainImg" src="" alt="" class="lightbox-main-img">
                </div>
                <div class="lightbox-thumbs" id="lightboxThumbs"></div>
            </div>
        </div>
    </div>

    <script>
        let publicCart = JSON.parse(localStorage.getItem('fintrix_public_cart')) || [];
        const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
        const customerId = <?php echo $_SESSION['customer_id'] ?? 'null'; ?>;
        const authModal = new bootstrap.Modal(document.getElementById('authModal'));
        const cartOffcanvas = new bootstrap.Offcanvas(document.getElementById('cartOffcanvas'));

        // --- Lightbox Logic ---
        let _lbImages = [];
        let _lbIndex = 0;

        function openLightbox(productId, productName, images) {
            if (!images || images.length === 0) return;
            _lbImages = images;
            _lbIndex = 0;

            document.getElementById('lightboxProductName').textContent = productName;
            renderLightbox();

            new bootstrap.Modal(document.getElementById('productLightbox')).show();
        }

        function renderLightbox() {
            const mainImg = document.getElementById('lightboxMainImg');
            const thumbsEl = document.getElementById('lightboxThumbs');
            const prevBtn = document.getElementById('lightboxPrev');
            const nextBtn = document.getElementById('lightboxNext');

            mainImg.style.opacity = '0';
            setTimeout(() => {
                mainImg.src = 'assets/images/products/' + _lbImages[_lbIndex];
                mainImg.style.opacity = '1';
            }, 120);

            // Render thumbnails
            thumbsEl.innerHTML = '';
            _lbImages.forEach((img, i) => {
                const thumb = document.createElement('img');
                thumb.src = 'assets/images/products/' + img;
                thumb.className = 'lightbox-thumb' + (i === _lbIndex ? ' active' : '');
                thumb.onclick = () => { _lbIndex = i; renderLightbox(); };
                thumbsEl.appendChild(thumb);
            });

            // Show/hide nav arrows
            prevBtn.style.display = _lbImages.length > 1 ? 'flex' : 'none';
            nextBtn.style.display = _lbImages.length > 1 ? 'flex' : 'none';

            // Hide thumbs row if only 1 image
            thumbsEl.style.display = _lbImages.length > 1 ? 'flex' : 'none';
        }

        function lightboxNav(dir) {
            _lbIndex = (_lbIndex + dir + _lbImages.length) % _lbImages.length;
            renderLightbox();
        }

        // Keyboard navigation for lightbox
        document.addEventListener('keydown', function(e) {
            const lb = document.getElementById('productLightbox');
            if (!lb.classList.contains('show')) return;
            if (e.key === 'ArrowRight') lightboxNav(1);
            if (e.key === 'ArrowLeft') lightboxNav(-1);
        });

        document.addEventListener('DOMContentLoaded', () => {
            renderStoreCartUI();

            const searchInput = document.getElementById('searchInput');
            if(searchInput) {
                searchInput.addEventListener('input', function() {
                    const term = this.value.toLowerCase();
                    let globalFound = false;

                    // Loop through category wrappers (Masonry Items)
                    document.querySelectorAll('.category-wrapper').forEach(catWrap => {
                        let catFound = false;
                        
                        // Loop through product cards inside the flex grid
                        catWrap.querySelectorAll('.product-card').forEach(card => {
                            const name = card.dataset.name;
                            if (name.includes(term)) {
                                card.style.display = 'flex'; // Uses flex to maintain expansion logic
                                catFound = true;
                                globalFound = true;
                            } else {
                                card.style.display = 'none';
                            }
                        });
                        
                        // Hide entire category block if no matching products inside
                        catWrap.style.display = catFound ? 'block' : 'none';
                    });

                    const noResultsMsg = document.getElementById('noResultsMsg');
                    if (noResultsMsg) noResultsMsg.style.display = globalFound ? 'none' : 'block';
                });
            }
        });

        function addToCart(btnElement) {
            const parent = btnElement.closest('.cart-actions');
            const id = parent.dataset.id;
            const name = parent.dataset.name;
            const price = parseFloat(parent.dataset.price);
            const img = parent.dataset.img;

            const existing = publicCart.find(item => item.id == id);
            if (!existing) {
                publicCart.push({ id, name, price, image: img, qty: 1 });
                saveCart();
                renderStoreCartUI();
            }
        }

        function updateQty(btnElement, change) {
            const parent = btnElement.closest('.cart-actions');
            const id = parent.dataset.id;

            const index = publicCart.findIndex(item => item.id == id);
            if (index > -1) {
                publicCart[index].qty += change;
                if (publicCart[index].qty <= 0) {
                    publicCart.splice(index, 1);
                }
                saveCart();
                renderStoreCartUI();
            }
        }

        function updateCartQtyFromSidebar(id, change) {
            const index = publicCart.findIndex(item => item.id == id);
            if (index > -1) {
                publicCart[index].qty += change;
                if (publicCart[index].qty <= 0) {
                    publicCart.splice(index, 1);
                }
                saveCart();
                renderStoreCartUI();
                renderSidebarCart();
            }
        }

        function removeCartItem(id) {
            publicCart = publicCart.filter(item => item.id != id);
            saveCart();
            renderStoreCartUI();
            renderSidebarCart();
        }

        function saveCart() {
            localStorage.setItem('fintrix_public_cart', JSON.stringify(publicCart));
        }

        function renderStoreCartUI() {
            const totalItems = publicCart.reduce((sum, item) => sum + item.qty, 0);
            document.getElementById('navCartCount').innerText = totalItems;

            document.querySelectorAll('.cart-actions').forEach(el => {
                const id = el.dataset.id;
                const item = publicCart.find(i => i.id == id);
                const addBtn = el.querySelector('.btn-add-cart');
                const controls = el.querySelector('.cart-controls');

                if (item) {
                    addBtn.classList.add('hidden');
                    controls.classList.add('active');
                    controls.querySelector('.qty-val').innerText = item.qty;
                } else {
                    addBtn.classList.remove('hidden');
                    controls.classList.remove('active');
                }
            });
        }

        document.getElementById('cartOffcanvas').addEventListener('show.bs.offcanvas', renderSidebarCart);

        function renderSidebarCart() {
            const container = document.getElementById('cartItemsContainer');
            let total = 0;

            if (publicCart.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5 h-100 d-flex flex-column justify-content-center text-muted">
                        <i class="bi bi-cart fs-1 d-block mb-2"></i>
                        <h6 class="fw-bold">Cart is empty</h6>
                        <button class="btn btn-sm btn-light fw-bold mt-3 mx-auto w-50" data-bs-dismiss="offcanvas">Shop Now</button>
                    </div>`;
                document.getElementById('cartSubtotal').innerText = 'Rs 0.00';
                return;
            }

            container.innerHTML = '';
            publicCart.forEach(item => {
                const lineTotal = item.price * item.qty;
                total += lineTotal;
                container.innerHTML += `
                    <div class="cart-item">
                        <img src="${item.image}" class="cart-item-img" alt="${item.name}">
                        <div class="cart-item-details">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="cart-item-title">${item.name}</div>
                                <button class="btn btn-link p-0 ms-2 text-danger" onclick="removeCartItem('${item.id}')"><i class="bi bi-x-circle-fill"></i></button>
                            </div>
                            <div class="cart-item-price mb-2">Rs ${parseFloat(item.price).toFixed(2)}</div>
                            
                            <div class="d-inline-flex align-items-center rounded-pill px-1 py-1" style="background: var(--ios-bg);">
                                <button class="btn btn-sm p-0 px-2 border-0 text-dark" onclick="updateCartQtyFromSidebar('${item.id}', -1)"><i class="bi bi-dash fw-bold"></i></button>
                                <span class="mx-2 fw-bold" style="font-size:13px; min-width:20px; text-align:center;">${item.qty}</span>
                                <button class="btn btn-sm p-0 px-2 border-0 text-dark" onclick="updateCartQtyFromSidebar('${item.id}', 1)"><i class="bi bi-plus fw-bold"></i></button>
                            </div>
                        </div>
                    </div>
                `;
            });
            document.getElementById('cartSubtotal').innerText = 'Rs ' + total.toFixed(2);
        }

        function proceedToCheckout() {
            if (publicCart.length === 0) {
                alert("Your cart is empty!");
                return;
            }
            if (isLoggedIn) {
                window.location.href = 'checkout.php';
            } else {
                cartOffcanvas.hide();
                toggleAuthMode('login');
                authModal.show();
            }
        }

        function openProfile() {
            if (isLoggedIn) {
                new bootstrap.Modal(document.getElementById('profileModal')).show();
            } else {
                toggleAuthMode('login');
                authModal.show();
            }
        }

        function viewFullProfile() {
            if (!customerId) return;
            const iframe = document.getElementById('profileIframe');
            iframe.src = `pages/view_customer.php?id=${customerId}&modal=true`;

            const profileModalEl = document.getElementById('profileModal');
            bootstrap.Modal.getInstance(profileModalEl).hide();

            new bootstrap.Modal(document.getElementById('customerProfileViewModal')).show();
        }

        function toggleAuthMode(mode) {
            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('registerForm').style.display = 'none';
            document.getElementById('claimForm').style.display = 'none';

            if (mode === 'login') {
                document.getElementById('loginForm').style.display = 'block';
                document.getElementById('authModalTitle').innerText = 'Sign In';
                document.getElementById('authModalDesc').innerText = 'Welcome back.';
            } else if (mode === 'register') {
                document.getElementById('registerForm').style.display = 'block';
                document.getElementById('authModalTitle').innerText = 'Create Account';
                document.getElementById('authModalDesc').innerText = 'Join to checkout securely.';
            } else if (mode === 'claim') {
                document.getElementById('claimForm').style.display = 'block';
                document.getElementById('authModalTitle').innerText = 'Verify Profile';
                document.getElementById('authModalDesc').innerText = 'We found a matching profile.';
            }
        }

        async function handleLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('btnLogin');
            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>...';

            const formData = new FormData();
            formData.append('action', 'customer_login');
            formData.append('email', document.getElementById('loginEmail').value);
            formData.append('password', document.getElementById('loginPassword').value);

            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    window.location.href = 'checkout.php';
                } else {
                    alert("Error: " + result.error);
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            } catch (err) {
                alert("Network error.");
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        }

        async function handleRegister(e, isClaim = false) {
            if (e) e.preventDefault();

            const activeBtn = isClaim ? document.querySelector('#claimForm .btn-primary') : document.getElementById('btnRegister');

            const origText = activeBtn.innerHTML;
            activeBtn.disabled = true;
            activeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>...';

            const formData = new FormData();
            formData.append('action', 'customer_register');
            formData.append('name', document.getElementById('regName').value);
            formData.append('phone', document.getElementById('regPhone').value);
            formData.append('email', document.getElementById('regEmail').value);
            formData.append('password', document.getElementById('regPassword').value);
            if (isClaim) formData.append('confirm_claim', '1');

            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    window.location.href = 'checkout.php';
                } else if (result.requires_claim) {
                    document.getElementById('claimNameText').innerText = result.data.name || 'Not Provided';
                    document.getElementById('claimPhoneText').innerText = result.data.phone || 'Not Provided';
                    toggleAuthMode('claim');
                } else {
                    alert("Error: " + result.error);
                    activeBtn.disabled = false;
                    activeBtn.innerHTML = origText;
                }
            } catch (err) {
                alert("Network error.");
                activeBtn.disabled = false;
                activeBtn.innerHTML = origText;
            }
        }

        async function handleLogout() {
            if (!confirm('Sign out of your account?')) return;
            const formData = new FormData();
            formData.append('action', 'customer_logout');
            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    window.location.reload();
                }
            } catch (err) {
                alert('Failed to logout.');
            }
        }
    </script>
</body>
</html>