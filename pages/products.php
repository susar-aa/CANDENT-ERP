<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// Image Compression & Resizing Helper
function compressAndResizeImage($source, $destination, $quality = 85, $maxWidth = 1200) {
    $info = getimagesize($source);
    if (!$info) return false;
    
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    
    $newWidth = ($width > $maxWidth) ? $maxWidth : $width;
    $newHeight = floor($height * ($newWidth / $width));
    
    $imageResized = imagecreatetruecolor($newWidth, $newHeight);
    
    if ($mime == 'image/png' || $mime == 'image/gif') {
        imagealphablending($imageResized, false);
        imagesavealpha($imageResized, true);
        $transparent = imagecolorallocatealpha($imageResized, 255, 255, 255, 127);
        imagefilledrectangle($imageResized, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png': $image = imagecreatefrompng($source); break;
        case 'image/gif': $image = imagecreatefromgif($source); break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
        default: return false; // Unsupported type for compression
    }
    
    imagecopyresampled($imageResized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    switch ($mime) {
        case 'image/jpeg': imagejpeg($imageResized, $destination, $quality); break;
        case 'image/png': 
            $pngQuality = round((100 - $quality) / 10);
            imagepng($imageResized, $destination, $pngQuality); 
            break;
        case 'image/gif': imagegif($imageResized, $destination); break;
        case 'image/webp': imagewebp($imageResized, $destination, $quality); break;
    }
    
    imagedestroy($image);
    imagedestroy($imageResized);
    return true;
}

// --- AJAX ENDPOINTS (No page reload) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // 1. Toggle Status
    if ($_POST['ajax_action'] == 'toggle_status') {
        $product_id = (int)$_POST['product_id'];
        $new_status = $_POST['new_status'];
        try {
            $stmt = $pdo->prepare("UPDATE products SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $product_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // 2. Delete Single Image
    if ($_POST['ajax_action'] == 'delete_image') {
        $image_id = (int)$_POST['image_id'];
        try {
            $stmt = $pdo->prepare("SELECT image_path, product_id, is_thumbnail FROM product_images WHERE id = ?");
            $stmt->execute([$image_id]);
            $img = $stmt->fetch();
            
            if ($img) {
                $path = '../assets/images/products/' . $img['image_path'];
                if(file_exists($path)) {
                    unlink($path); 
                }
                $pdo->prepare("DELETE FROM product_images WHERE id = ?")->execute([$image_id]);
                // If the deleted image was the thumbnail, auto-assign to first remaining
                if ($img['is_thumbnail']) {
                    $pdo->prepare("UPDATE product_images SET is_thumbnail = 1 WHERE product_id = ? ORDER BY id ASC LIMIT 1")->execute([$img['product_id']]);
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Image not found in database.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 2b. Set Thumbnail
    if ($_POST['ajax_action'] == 'set_thumbnail') {
        $image_id = (int)$_POST['image_id'];
        $product_id = (int)$_POST['product_id'];
        try {
            $pdo->prepare("UPDATE product_images SET is_thumbnail = 0 WHERE product_id = ?")->execute([$product_id]);
            $pdo->prepare("UPDATE product_images SET is_thumbnail = 1 WHERE id = ?")->execute([$image_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 3. Async Image Upload (with compression & fallback)
    if ($_POST['ajax_action'] == 'upload_images') {
        ini_set('memory_limit', '256M'); // Increase memory limit for processing large images
        
        $uploadedFiles = [];
        $errors = [];
        $uploadDir = '../assets/images/products/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        if (isset($_FILES['async_images']['name'])) {
            foreach ($_FILES['async_images']['tmp_name'] as $key => $tmp_name) {
                $errorCode = $_FILES['async_images']['error'][$key];
                $originalName = $_FILES['async_images']['name'][$key];

                if ($errorCode !== UPLOAD_ERR_OK || empty($tmp_name)) {
                    $errors[] = "File '$originalName' rejected (Error Code: $errorCode). It might exceed server limits.";
                    continue;
                }
                
                // Keep original extension if available
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $ext = 'jpg';
                }

                $fileName = time() . '_' . uniqid() . '.' . $ext;
                $targetFilePath = $uploadDir . $fileName;
                
                // Try compression. If it fails (unsupported type, memory limit), fallback to a direct move
                if (compressAndResizeImage($tmp_name, $targetFilePath, 85, 1200)) {
                    $uploadedFiles[] = $fileName;
                } else if (move_uploaded_file($tmp_name, $targetFilePath)) {
                    $uploadedFiles[] = $fileName;
                } else {
                    $errors[] = "Failed to process or save '$originalName'.";
                }
            }
        }
        echo json_encode(['success' => true, 'files' => $uploadedFiles, 'errors' => $errors]);
        exit;
    }
}

$message = '';

// --- AUTO DB MIGRATION FOR ENHANCED FEATURES ---
try {
    $pdo->exec("ALTER TABLE products ADD COLUMN status ENUM('available', 'unavailable') DEFAULT 'available'");
} catch(PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE products ADD COLUMN supplier_id INT NULL");
    $pdo->exec("ALTER TABLE products ADD COLUMN sku VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE products DROP COLUMN cost_price");
    $pdo->exec("ALTER TABLE products ADD COLUMN selling_price DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE products ADD COLUMN stock INT DEFAULT 0");
    $pdo->exec("ALTER TABLE products ADD COLUMN mrp DECIMAL(10,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE products ADD COLUMN pcs_per_pack INT DEFAULT 1");
} catch(PDOException $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        is_thumbnail TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE product_images ADD COLUMN is_thumbnail TINYINT(1) DEFAULT 0");
} catch(PDOException $e) {}
// ------------------------------------------

$uploadDir = '../assets/images/products/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Handle POST Actions (Add/Edit/Delete)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // ADD PRODUCT
    if ($_POST['action'] == 'add_product') {
        $prod_name = trim($_POST['product_name']);
        $cat_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $sku = trim($_POST['sku']);
        $selling_price = (float)$_POST['selling_price'];
        $mrp = (float)$_POST['mrp'];
        $pcs_per_pack = max(1, (int)$_POST['pcs_per_pack']);
        $stock = (int)$_POST['stock'];
        $status = $_POST['status'] ?? 'available';
        
        if (!empty($prod_name) && $cat_id) {
            $stmt = $pdo->prepare("INSERT INTO products (name, category_id, supplier_id, sku, selling_price, stock, status, mrp, pcs_per_pack) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$prod_name, $cat_id, $supplier_id, $sku, $selling_price, $stock, $status, $mrp, $pcs_per_pack])) {
                $product_id = $pdo->lastInsertId();
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Product added successfully!</div>";
                
                // Link already uploaded images; mark the first as thumbnail
                if (!empty($_POST['uploaded_images'])) {
                    $isFirst = true;
                    $imgStmt = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_thumbnail) VALUES (?, ?, ?)");
                    foreach ($_POST['uploaded_images'] as $fileName) {
                        $imgStmt->execute([$product_id, $fileName, $isFirst ? 1 : 0]);
                        $isFirst = false;
                    }
                    // Honor explicit thumbnail choice
                    if (!empty($_POST['thumbnail_image'])) {
                        $pdo->prepare("UPDATE product_images SET is_thumbnail = 0 WHERE product_id = ?")->execute([$product_id]);
                        $pdo->prepare("UPDATE product_images SET is_thumbnail = 1 WHERE product_id = ? AND image_path = ?")->execute([$product_id, $_POST['thumbnail_image']]);
                    }
                }
            } else {
                $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error adding product. Ensure SKU is unique.</div>";
            }
        }
    }
    
    // EDIT PRODUCT
    if ($_POST['action'] == 'edit_product') {
        $product_id = (int)$_POST['product_id'];
        $prod_name = trim($_POST['product_name']);
        $cat_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $sku = trim($_POST['sku']);
        $selling_price = (float)$_POST['selling_price'];
        $mrp = (float)$_POST['mrp'];
        $pcs_per_pack = max(1, (int)$_POST['pcs_per_pack']);
        $stock = (int)$_POST['stock'];
        $status = $_POST['status'] ?? 'available';
        
        if ($product_id && !empty($prod_name) && $cat_id) {
            $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, supplier_id = ?, sku = ?, selling_price = ?, stock = ?, status = ?, mrp = ?, pcs_per_pack = ? WHERE id = ?");
            if ($stmt->execute([$prod_name, $cat_id, $supplier_id, $sku, $selling_price, $stock, $status, $mrp, $pcs_per_pack, $product_id])) {
                $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-check-circle-fill me-2'></i> Product updated successfully!</div>";
                
                // Link newly uploaded images
                if (!empty($_POST['uploaded_images'])) {
                    // Check if there's already a thumbnail
                    $hasThumbnail = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_thumbnail = 1");
                    $hasThumbnail->execute([$product_id]);
                    $thumbExists = (int)$hasThumbnail->fetchColumn() > 0;

                    $imgStmt = $pdo->prepare("INSERT INTO product_images (product_id, image_path, is_thumbnail) VALUES (?, ?, ?)");
                    foreach ($_POST['uploaded_images'] as $i => $fileName) {
                        $isThumb = (!$thumbExists && $i === 0) ? 1 : 0;
                        $imgStmt->execute([$product_id, $fileName, $isThumb]);
                        if ($isThumb) $thumbExists = true;
                    }
                    // Honor explicit thumbnail choice from edit form
                    if (!empty($_POST['thumbnail_image'])) {
                        $pdo->prepare("UPDATE product_images SET is_thumbnail = 0 WHERE product_id = ?")->execute([$product_id]);
                        $pdo->prepare("UPDATE product_images SET is_thumbnail = 1 WHERE product_id = ? AND image_path = ?")->execute([$product_id, $_POST['thumbnail_image']]);
                    }
                }
            }
        }
    }

    // DELETE PRODUCT
    if ($_POST['action'] == 'delete_product') {
        $product_id = (int)$_POST['product_id'];
        $imgs = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
        $imgs->execute([$product_id]);
        foreach($imgs->fetchAll() as $img) {
            $path = $uploadDir . $img['image_path'];
            if(file_exists($path)) unlink($path);
        }
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        if ($stmt->execute([$product_id])) {
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A;'><i class='bi bi-trash3-fill me-2'></i> Product deleted successfully!</div>";
        }
    }
}

// --- FILTERING, SEARCH & PAGINATION ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$category_filter = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereClause = "WHERE 1=1";
$params = [];

if ($category_filter) {
    $whereClause .= " AND p.category_id = ?";
    $params[] = $category_filter;
}
if ($search_query !== '') {
    $whereClause .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

// Get Total Rows
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Products
$query = "
    SELECT p.*, c.name as category_name, s.company_name as supplier_name,
           (SELECT image_path FROM product_images WHERE product_id = p.id AND is_thumbnail = 1 LIMIT 1) as thumbnail_image,
           (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1) as primary_image,
           (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) as image_count
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN suppliers s ON p.supplier_id = s.id 
    $whereClause 
    ORDER BY p.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Fetch All Images for Modal Gallery & Edit Panel
$allImages = [];
$imgStmt = $pdo->query("SELECT id, product_id, image_path, is_thumbnail FROM product_images ORDER BY is_thumbnail DESC, id ASC");
while($row = $imgStmt->fetch()) {
    $allImages[$row['product_id']][] = [
        'id' => $row['id'],
        'path' => $row['image_path'],
        'is_thumbnail' => (int)$row['is_thumbnail']
    ];
}

// Fetch Dropdowns
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY company_name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .info-card {
        background: var(--ios-surface);
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--ios-separator);
        margin-bottom: 16px;
    }
    .info-row {
        padding: 10px 14px;
        border-bottom: 1px solid var(--ios-separator);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label {
        font-weight: 600;
        font-size: 0.8rem;
        color: var(--ios-label-2);
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .info-value {
        font-weight: 700;
        font-size: 0.95rem;
        color: var(--ios-label);
    }

    /* Thumbnail Scroll Hide */
    .thumb-scroll::-webkit-scrollbar {
        height: 6px;
    }
    .thumb-scroll::-webkit-scrollbar-thumb {
        background: rgba(60,60,67,0.15);
        border-radius: 10px;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Products Management</h1>
        <div class="page-subtitle">Manage inventory, categories, and pricing details.</div>
    </div>
    <div>
        <!-- ADD BUTTON FIX: Explicit JS trigger instead of data attributes -->
        <button class="quick-btn quick-btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-lg"></i> Add New Product
        </button>
    </div>
</div>

<?php echo $message; ?>

<!-- Filter & Live Search Bar -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="ios-label-sm">Search Products</label>
                <div class="ios-search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" id="searchInput" class="ios-input" placeholder="Search by Name or SKU..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <label class="ios-label-sm">Filter by Category</label>
                <select name="category_id" id="categorySelect" class="form-select" onchange="document.getElementById('filterForm').submit();">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $category_filter == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="quick-btn quick-btn-secondary w-100" style="min-height: 42px;">
                    <i class="bi bi-funnel-fill"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 20px;">
        <span class="card-title">
            <span class="card-title-icon" style="background: rgba(48,200,138,0.1); color: var(--accent-dark);">
                <i class="bi bi-box-seam-fill"></i>
            </span>
            Product Inventory
        </span>
    </div>
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 80px; text-align: center;">Image</th>
                    <th style="width: 35%;">Product Info</th>
                    <th style="width: 25%;">Pricing & Stock</th>
                    <th style="width: 15%; text-align: center;">Status</th>
                    <th style="width: 15%; text-align: right; padding-right: 20px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($products as $p): ?>
                <tr>
                    <td style="text-align: center;">
                        <?php 
                        $displayImg = $p['thumbnail_image'] ?: $p['primary_image'];
                        if($displayImg): ?>
                            <img src="../assets/images/products/<?php echo htmlspecialchars($displayImg); ?>" alt="Img" style="width: 50px; height: 50px; border-radius: 12px; object-fit: cover; border: 1px solid var(--ios-separator);">
                        <?php else: ?>
                            <div style="width: 50px; height: 50px; border-radius: 12px; background: var(--ios-surface-2); display: flex; align-items: center; justify-content: center; color: var(--ios-label-4); border: 1px solid var(--ios-separator); margin: 0 auto;">
                                <i class="bi bi-image" style="font-size: 1.4rem;"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight: 700; font-size: 1rem; color: var(--ios-label); line-height: 1.2; margin-bottom: 4px;">
                            <?php echo htmlspecialchars($p['name']); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); font-weight: 600;">
                            <i class="bi bi-tag-fill me-1" style="color: var(--ios-label-3);"></i> <?php echo htmlspecialchars($p['category_name']); ?>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--ios-label-3); margin-top: 2px;">
                            SKU: <?php echo htmlspecialchars($p['sku'] ?: 'N/A'); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 800; font-size: 0.95rem; color: #1A9A3A; margin-bottom: 6px;">
                            Rs <?php echo number_format($p['selling_price'], 2); ?>
                        </div>
                        <div>
                            <?php if($p['stock'] > 10): ?>
                                <span class="ios-badge outline" style="border-color: #1A9A3A; color: #1A9A3A;">Stock: <?php echo $p['stock']; ?></span>
                            <?php elseif($p['stock'] > 0): ?>
                                <span class="ios-badge orange">Low Stock: <?php echo $p['stock']; ?></span>
                            <?php else: ?>
                                <span class="ios-badge red">Out of Stock</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="text-align: center; vertical-align: middle;">
                        <!-- AJAX Toggle Switch -->
                        <div class="form-check form-switch d-inline-block m-0">
                            <input class="form-check-input status-toggle" type="checkbox" role="switch" 
                                   data-id="<?php echo $p['id']; ?>" 
                                   <?php echo $p['status'] == 'available' ? 'checked' : ''; ?>
                                   title="Toggle Availability">
                        </div>
                    </td>
                    <td style="text-align: right; padding-right: 20px;">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            
                            <!-- VIEW BUTTON FIX: Using safe htmlspecialchars JSON representation -->
                            <button class="quick-btn quick-btn-ghost" style="padding: 6px 10px;" title="View Details" onclick="openViewModal(<?php echo htmlspecialchars(json_encode([
                                'name' => $p['name'],
                                'sku' => $p['sku'],
                                'category' => $p['category_name'],
                                'supplier' => $p['supplier_name'] ?: 'None',
                                'price' => number_format($p['selling_price'], 2),
                                'mrp' => number_format($p['mrp'], 2),
                                'pcs' => $p['pcs_per_pack'],
                                'stock' => $p['stock'],
                                'status' => $p['status'],
                                'images' => $allImages[$p['id']] ?? []
                            ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="bi bi-eye-fill" style="color: #0055CC;"></i>
                            </button>
                            
                            <!-- EDIT BUTTON FIX: Explicit JS trigger to prevent data attribute parsing errors -->
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="Edit Product" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                'id' => $p['id'],
                                'name' => $p['name'],
                                'sku' => $p['sku'],
                                'category_id' => $p['category_id'],
                                'supplier_id' => $p['supplier_id'],
                                'selling_price' => $p['selling_price'],
                                'mrp' => $p['mrp'],
                                'pcs_per_pack' => $p['pcs_per_pack'],
                                'stock' => $p['stock'],
                                'status' => $p['status'],
                                'images' => $allImages[$p['id']] ?? []
                            ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="bi bi-pencil-square" style="color: #FF9500;"></i>
                            </button>

                            <!-- Delete Form -->
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this product completely?');">
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                <button type="submit" class="quick-btn" style="padding: 6px 10px; background: rgba(255,59,48,0.1); color: #CC2200;" title="Delete">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($products)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-box-seam" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No products found matching your criteria.</p>
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
<ul class="ios-pagination mb-4">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&category_id=<?php echo $category_filter; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>

<!-- ==================== MODALS ==================== -->

<!-- View Product Modal (Horizontal Redesign) -->
<div class="modal fade" id="viewProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <div class="modal-header" style="background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator);">
                <h5 class="modal-title fw-bold" style="font-size: 1.1rem;" id="view_name">Product Name</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="row g-4">
                    <!-- Left Column: Image Viewer -->
                    <div class="col-md-5">
                        <div class="d-flex flex-column h-100" style="background: var(--ios-surface); border: 1px solid var(--ios-separator); border-radius: 14px; padding: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                            <!-- Main Large Image -->
                            <div style="flex: 1; min-height: 220px; background: var(--ios-surface-2); border-radius: 10px; overflow: hidden; display: flex; align-items: center; justify-content: center; margin-bottom: 12px;" id="main_image_container">
                                <img id="main_image_view" src="" style="width: 100%; height: 100%; object-fit: contain; display: none;">
                                <i id="main_image_placeholder" class="bi bi-image" style="font-size: 3.5rem; color: var(--ios-label-4);"></i>
                            </div>
                            
                            <!-- Scrollable Thumbnails -->
                            <div id="thumbnails_container" class="d-flex gap-2 thumb-scroll" style="overflow-x: auto; padding-bottom: 4px;">
                                <!-- JS injects thumbnails here -->
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Details -->
                    <div class="col-md-7">
                        <div class="info-card mb-3" style="margin-bottom: 12px;">
                            <div class="info-row">
                                <span class="info-label">Category</span>
                                <span class="info-value text-dark" id="view_category"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Supplier</span>
                                <span class="info-value text-dark" id="view_supplier"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">SKU</span>
                                <span class="info-value text-dark" id="view_sku" style="font-family: monospace;"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status</span>
                                <span id="view_status"></span>
                            </div>
                        </div>

                        <div class="info-card" style="margin-bottom: 0;">
                            <div class="info-row">
                                <span class="info-label">Selling Price</span>
                                <span class="info-value" style="color: #1A9A3A; font-size: 1.4rem; font-weight: 800;" id="view_price"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Retail Price (MRP)</span>
                                <span class="info-value text-dark" id="view_mrp"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Pcs per Pack</span>
                                <span class="info-value text-dark" id="view_pcs"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Stock Available</span>
                                <span class="info-value text-dark" id="view_stock" style="font-size: 1.1rem;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="background: var(--ios-surface);">
                <button type="button" class="quick-btn quick-btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="" id="addForm">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem;"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="add_product">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" class="ios-input fw-bold" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">SKU (Barcode/Ref)</label>
                            <input type="text" name="sku" class="ios-input" style="font-family: monospace;">
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select fw-bold" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach($categories as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">-- No Specific Supplier --</option>
                                <?php foreach($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3 pb-3 border-bottom border-secondary border-opacity-10">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Wholesale Price (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="selling_price" class="ios-input text-end fw-bold text-success" value="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Retail Price / MRP (Rs)</label>
                            <input type="number" step="0.01" name="mrp" class="ios-input text-end fw-bold" value="0.00">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Pcs per Pack</label>
                            <input type="number" name="pcs_per_pack" class="ios-input text-center fw-bold" value="1" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Initial Stock</label>
                            <input type="number" name="stock" class="ios-input text-center fw-bold" value="0">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Status</label>
                            <select name="status" class="form-select">
                                <option value="available">Available</option>
                                <option value="unavailable">Unavailable</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Upload Images</label>
                            <input type="file" class="ios-input async-image-upload" style="padding: 7px 10px;" accept="image/*" multiple>
                            
                            <div class="progress mt-2 d-none" style="height: 6px; border-radius: 50px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 0%; transition: width 0.3s;"></div>
                            </div>
                            <p class="text-muted" style="font-size: 0.72rem; margin-top: 6px; margin-bottom: 4px;"><i class="bi bi-info-circle me-1"></i>The first uploaded image will be used as the thumbnail on the store.</p>
                            <div class="uploaded-preview-container d-flex flex-wrap gap-2 mt-2">
                                <!-- Previews injected via JS -->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4 submit-btn">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <form method="POST" action="" id="editForm">
                <div class="modal-header" style="background: var(--ios-surface);">
                    <h5 class="modal-title fw-bold" style="font-size: 1.1rem; color: #C07000;"><i class="bi bi-pencil-square me-2"></i>Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pb-0">
                    <input type="hidden" name="action" value="edit_product">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="product_name" id="edit_product_name" class="ios-input fw-bold" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">SKU (Barcode/Ref)</label>
                            <input type="text" name="sku" id="edit_sku" class="ios-input" style="font-family: monospace;">
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Category <span class="text-danger">*</span></label>
                            <select name="category_id" id="edit_category_id" class="form-select fw-bold" required>
                                <?php foreach($categories as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Supplier</label>
                            <select name="supplier_id" id="edit_supplier_id" class="form-select">
                                <option value="">-- No Specific Supplier --</option>
                                <?php foreach($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3 pb-3 border-bottom border-secondary border-opacity-10">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Wholesale Price (Rs) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="selling_price" id="edit_selling" class="ios-input text-end fw-bold text-success" required>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Retail Price / MRP (Rs)</label>
                            <input type="number" step="0.01" name="mrp" id="edit_mrp" class="ios-input text-end fw-bold">
                        </div>
                    </div>

                    <div class="row g-3 mb-3 pb-3 border-bottom border-secondary border-opacity-10">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Pcs per Pack</label>
                            <input type="number" name="pcs_per_pack" id="edit_pcs" class="ios-input text-center fw-bold" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Stock Quantity</label>
                            <input type="number" name="stock" id="edit_stock" class="ios-input text-center fw-bold">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="ios-label-sm">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="available">Available</option>
                                <option value="unavailable">Unavailable</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="ios-label-sm">Add New Images</label>
                            <input type="file" class="ios-input async-image-upload" style="padding: 7px 10px;" accept="image/*" multiple>
                            
                            <div class="progress mt-2 d-none" style="height: 6px; border-radius: 50px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 0%; transition: width 0.3s;"></div>
                            </div>
                            
                            <div class="uploaded-preview-container d-flex flex-wrap gap-2 mt-2">
                                <!-- Previews injected via JS -->
                            </div>
                        </div>
                    </div>

                    <!-- Manage Uploaded Images -->
                    <div class="mb-4 bg-white p-3 rounded border">
                        <label class="ios-label-sm mb-2" style="color: #C07000;"><i class="bi bi-images me-1"></i>Currently Uploaded Images</label>
                        <div class="d-flex flex-wrap gap-2" id="edit_existing_images">
                            <!-- Populated via JS -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="background: var(--ios-surface);">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn px-4 submit-btn" style="background: #FF9500; color: #fff;">Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Open Modals explicitly via JS to guarantee parsing safety
function openAddModal() {
    resetUploads();
    new bootstrap.Modal(document.getElementById('addProductModal')).show();
}

function openEditModal(data) {
    document.getElementById('edit_product_id').value = data.id;
    document.getElementById('edit_product_name').value = data.name;
    document.getElementById('edit_sku').value = data.sku;
    document.getElementById('edit_category_id').value = data.category_id;
    document.getElementById('edit_supplier_id').value = data.supplier_id;
    document.getElementById('edit_selling').value = data.selling_price;
    document.getElementById('edit_mrp').value = data.mrp;
    document.getElementById('edit_pcs').value = data.pcs_per_pack;
    document.getElementById('edit_stock').value = data.stock;
    document.getElementById('edit_status').value = data.status;

    // Load existing images with delete & thumbnail buttons
    var images = data.images || [];
    var imgContainer = document.getElementById('edit_existing_images');
    imgContainer.innerHTML = '';
    
    if (images.length > 0) {
        images.forEach(img => {
            const isThumbnail = img.is_thumbnail == 1;
            imgContainer.innerHTML += `
                <div class="position-relative" id="img_wrapper_${img.id}" style="display:inline-block;">
                    <img src="../assets/images/products/${img.path}" class="rounded border shadow-sm object-fit-cover" style="width: 70px; height: 70px; display:block;">
                    ${isThumbnail ? `<span class="position-absolute bottom-0 start-0 w-100 text-center" style="background:rgba(0,122,255,0.88); color:#fff; font-size:9px; font-weight:700; letter-spacing:0.04em; padding:2px 0; border-radius:0 0 4px 4px;">THUMBNAIL</span>` : `<button type="button" onclick="setProductThumbnail(${img.id}, ${data.id})" title="Set as Thumbnail" style="position:absolute; bottom:0; left:0; width:100%; background:rgba(0,0,0,0.45); color:#fff; font-size:9px; font-weight:700; border:none; padding:2px 0; border-radius:0 0 4px 4px; cursor:pointer; letter-spacing:0.03em;">Set Thumbnail</button>`}
                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 translate-middle shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 22px; height: 22px; padding: 0; font-size: 0.7rem;" onclick="deleteProductImage(${img.id})" title="Delete Image">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            `;
        });
    } else {
        imgContainer.innerHTML = '<div class="text-muted small" style="font-style: italic;">No images uploaded yet.</div>';
    }
    
    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

// --- 5. Populate & Open View Modal (Horizontal Redesign) ---
function openViewModal(data) {
    document.getElementById('view_name').innerText = data.name;
    document.getElementById('view_category').innerText = data.category;
    document.getElementById('view_supplier').innerText = data.supplier;
    document.getElementById('view_sku').innerText = data.sku || 'N/A';
    document.getElementById('view_price').innerText = 'Rs ' + data.price;
    document.getElementById('view_mrp').innerText = 'Rs ' + (data.mrp || '0.00');
    document.getElementById('view_pcs').innerText = data.pcs || '1';
    document.getElementById('view_stock').innerText = data.stock;
    document.getElementById('view_status').innerHTML = data.status === 'available' ? '<span class="ios-badge green">Available</span>' : '<span class="ios-badge red">Unavailable</span>';

    // Populate Images Grid
    const mainImage = document.getElementById('main_image_view');
    const placeholder = document.getElementById('main_image_placeholder');
    const thumbsContainer = document.getElementById('thumbnails_container');
    
    thumbsContainer.innerHTML = ''; 
    
    if (data.images && data.images.length > 0) {
        mainImage.style.display = 'block';
        placeholder.style.display = 'none';
        // Set the first image as main
        mainImage.src = '../assets/images/products/' + data.images[0].path;

        data.images.forEach((img, index) => {
            const thumb = document.createElement('img');
            thumb.src = '../assets/images/products/' + img.path;
            thumb.className = 'rounded border shadow-sm object-fit-cover';
            thumb.style.width = '60px';
            thumb.style.height = '60px';
            thumb.style.cursor = 'pointer';
            thumb.style.transition = 'all 0.2s';
            thumb.style.opacity = index === 0 ? '1' : '0.5';
            
            thumb.onclick = function() {
                mainImage.src = this.src;
                // Update opacity for active state
                Array.from(thumbsContainer.children).forEach(c => {
                    c.style.opacity = '0.5';
                    c.style.borderColor = 'var(--ios-separator)';
                });
                this.style.opacity = '1';
                this.style.borderColor = 'var(--accent)';
            };
            
            thumbsContainer.appendChild(thumb);
        });
    } else {
        mainImage.style.display = 'none';
        placeholder.style.display = 'block';
        thumbsContainer.innerHTML = '<div class="text-muted small w-100 text-center py-2">No Images Uploaded</div>';
    }

    new bootstrap.Modal(document.getElementById('viewProductModal')).show();
}

// Prevent visual bug when adding new products
function resetUploads() {
    document.querySelectorAll('.uploaded-preview-container').forEach(el => el.innerHTML = '');
    document.querySelectorAll('.dynamic-hidden-img').forEach(el => el.remove());
}

document.addEventListener('DOMContentLoaded', function() {
    
    // --- 1. Live Search Debounce & Focus Restoration ---
    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');

    if (searchInput && searchInput.value !== '') {
        searchInput.focus();
        const val = searchInput.value;
        searchInput.value = '';
        searchInput.value = val;
    }

    let searchTimer;
    if(searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { filterForm.submit(); }, 700); 
        });
    }

    // --- 2. AJAX Status Toggle Slider ---
    document.querySelectorAll('.status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const productId = this.dataset.id;
            const newStatus = this.checked ? 'available' : 'unavailable';
            
            fetch('products.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ajax_action=toggle_status&product_id=${productId}&new_status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if(!data.success) {
                    alert('Failed to update status: ' + data.error);
                    this.checked = !this.checked; 
                }
            });
        });
    });

    // --- 3. Async Image Upload SEQUENTIAL & Progress Bar ---
    document.querySelectorAll('.async-image-upload').forEach(input => {
        input.addEventListener('change', function() {
            const files = Array.from(this.files);
            if (files.length === 0) return;
            
            const form = this.closest('form');
            const submitBtn = form.querySelector('.submit-btn');
            const progressBarContainer = form.querySelector('.progress');
            const progressBar = form.querySelector('.progress-bar');
            const previewContainer = form.querySelector('.uploaded-preview-container');
            const originalBtnText = submitBtn.innerHTML;
            
            // Setup UI for uploading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Uploading...';
            progressBarContainer.classList.remove('d-none');
            progressBar.style.width = '0%';
            
            let currentFileIndex = 0;
            const totalFiles = files.length;

            function uploadNextFile() {
                if (currentFileIndex >= totalFiles) {
                    // All files uploaded completely
                    progressBar.style.width = '100%';
                    setTimeout(() => { progressBarContainer.classList.add('d-none'); }, 1000);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    input.value = ''; // clear input so more can be added if needed
                    return;
                }

                const file = files[currentFileIndex];
                const formData = new FormData();
                formData.append('ajax_action', 'upload_images');
                formData.append('async_images[]', file);
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'products.php', true);
                
                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        const baseProgress = (currentFileIndex / totalFiles) * 100;
                        const currentProgress = (e.loaded / e.total) * (100 / totalFiles);
                        const totalProgress = Math.round(baseProgress + currentProgress);
                        progressBar.style.width = totalProgress + '%';
                    }
                };
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                if (res.files && res.files.length > 0) {
                                    res.files.forEach(uploadedFileName => {
                                        // Add hidden input to pass to main form submission
                                        const hiddenInput = document.createElement('input');
                                        hiddenInput.type = 'hidden';
                                        hiddenInput.name = 'uploaded_images[]';
                                        hiddenInput.value = uploadedFileName;
                                        hiddenInput.classList.add('dynamic-hidden-img');
                                        form.appendChild(hiddenInput);
                                        
                                        // Show preview
                                        previewContainer.innerHTML += `
                                            <div class="position-relative">
                                                <img src="../assets/images/products/${uploadedFileName}" class="rounded border shadow-sm object-fit-cover" style="width: 50px; height: 50px;">
                                                <span class="position-absolute top-0 end-0 translate-middle p-1 bg-success border border-light rounded-circle" style="width: 15px; height: 15px; display: inline-block;"></span>
                                            </div>
                                        `;
                                    });
                                }
                                if (res.errors && res.errors.length > 0) {
                                    alert(`Issues uploading file ${currentFileIndex + 1}:\n` + res.errors.join('\n'));
                                }
                            } else {
                                alert(`Upload failed for file ${currentFileIndex + 1}. Maybe too large.`);
                            }
                        } catch (e) {
                            alert('Invalid response from server. File might exceed server limits.');
                        }
                    } else {
                        alert(`Server error ${xhr.status} for file ${currentFileIndex + 1}. File might exceed server limits.`);
                    }
                    
                    // Trigger next file upload regardless of success or failure of current
                    currentFileIndex++;
                    uploadNextFile();
                };

                xhr.onerror = function() {
                    alert(`Network error while uploading file ${currentFileIndex + 1}`);
                    currentFileIndex++;
                    uploadNextFile();
                };
                
                xhr.send(formData);
            }

            // Start the sequence
            uploadNextFile();
        });
    });

    // Cleanup dynamically added previews/inputs when modals are closed
    ['addProductModal', 'editProductModal'].forEach(modalId => {
        const modalEl = document.getElementById(modalId);
        if(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                form.querySelector('.uploaded-preview-container').innerHTML = '';
                form.querySelectorAll('.dynamic-hidden-img').forEach(el => el.remove());
            });
        }
    });

});

// AJAX function to delete individual image from DB and Storage
function deleteProductImage(imageId) {
    if(!confirm('Are you sure you want to delete this image permanently?')) return;
    
    fetch('products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=delete_image&image_id=${imageId}`
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            document.getElementById('img_wrapper_' + imageId).remove();
            const container = document.getElementById('edit_existing_images');
            if(container.querySelectorAll('[id^="img_wrapper_"]').length === 0) {
                container.innerHTML = '<div class="text-muted small" style="font-style: italic;">No images left.</div>';
            }
        } else {
            alert('Failed to delete image: ' + data.error);
        }
    });
}

// AJAX function to set a specific image as the store thumbnail
function setProductThumbnail(imageId, productId) {
    fetch('products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_action=set_thumbnail&image_id=${imageId}&product_id=${productId}`
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            // Re-render all image wrappers: update thumbnail badges without closing modal
            const container = document.getElementById('edit_existing_images');
            container.querySelectorAll('[id^="img_wrapper_"]').forEach(wrapper => {
                const wId = parseInt(wrapper.id.replace('img_wrapper_', ''));
                const overlay = wrapper.querySelector('button:not(.btn-danger), span.position-absolute.bottom-0');
                if (overlay) overlay.remove();
                
                const img = wrapper.querySelector('img');
                const newOverlay = document.createElement(wId === imageId ? 'span' : 'button');
                if (wId === imageId) {
                    newOverlay.className = 'position-absolute bottom-0 start-0 w-100 text-center';
                    newOverlay.style.cssText = 'background:rgba(0,122,255,0.88); color:#fff; font-size:9px; font-weight:700; letter-spacing:0.04em; padding:2px 0; border-radius:0 0 4px 4px;';
                    newOverlay.innerText = 'THUMBNAIL';
                } else {
                    newOverlay.type = 'button';
                    newOverlay.onclick = function() { setProductThumbnail(wId, productId); };
                    newOverlay.title = 'Set as Thumbnail';
                    newOverlay.style.cssText = 'position:absolute; bottom:0; left:0; width:100%; background:rgba(0,0,0,0.45); color:#fff; font-size:9px; font-weight:700; border:none; padding:2px 0; border-radius:0 0 4px 4px; cursor:pointer; letter-spacing:0.03em;';
                    newOverlay.innerText = 'Set Thumbnail';
                }
                wrapper.appendChild(newOverlay);
            });
        } else {
            alert('Failed to set thumbnail: ' + data.error);
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>