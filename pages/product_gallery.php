<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
// Allow Reps to view the gallery so they can show it to customers like a digital catalog
requireRole(['admin', 'supervisor', 'rep']);

// --- GET PARAMETERS FOR FILTERING ---
$category_filter = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : null;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// --- BUILD QUERY ---
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

// Fetch Products
$query = "
    SELECT p.*, c.name as category_name, s.company_name as supplier_name,
           (SELECT image_path FROM product_images WHERE product_id = p.id LIMIT 1) as primary_image
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN suppliers s ON p.supplier_id = s.id 
    $whereClause 
    ORDER BY p.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Fetch All Categories for the Navigation Pills
$categories = $pdo->query("
    SELECT c.*, COUNT(p.id) as product_count 
    FROM categories c 
    LEFT JOIN products p ON c.id = p.category_id 
    GROUP BY c.id 
    ORDER BY c.name ASC
")->fetchAll();

// Fetch All Images to pass to the Modal Gallery
$allImages = [];
$imgStmt = $pdo->query("SELECT product_id, image_path FROM product_images");
while($row = $imgStmt->fetch()) {
    $allImages[$row['product_id']][] = $row['image_path'];
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Specific Page Styles */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        margin-bottom: 20px;
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

    /* Search Bar */
    .ios-search-bar {
        background: var(--ios-surface);
        border: 1px solid var(--ios-separator);
        border-radius: 12px;
        padding: 6px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .ios-search-bar input {
        border: none;
        background: transparent;
        outline: none;
        width: 100%;
        font-size: 1rem;
        color: var(--ios-label);
        padding: 8px 0;
    }
    .ios-search-bar .bi-search { color: var(--ios-label-3); font-size: 1.1rem; }

    /* Category Pills */
    .ios-pills-container {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 24px;
    }
    .ios-pill {
        background: var(--ios-surface);
        color: var(--ios-label);
        border: 1px solid var(--ios-separator);
        padding: 8px 16px;
        border-radius: 50px;
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }
    .ios-pill:hover {
        background: var(--ios-surface-2);
        color: var(--ios-label);
    }
    .ios-pill.active {
        background: var(--ios-label);
        color: #fff;
        border-color: var(--ios-label);
    }
    .ios-pill-badge {
        background: var(--ios-bg);
        color: var(--ios-label-2);
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 0.7rem;
    }
    .ios-pill.active .ios-pill-badge {
        background: rgba(255,255,255,0.2);
        color: #fff;
    }

    /* Product Card */
    .catalog-card {
        background: var(--ios-surface);
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--ios-separator);
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .catalog-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.08);
    }
    .catalog-card:active {
        transform: scale(0.98);
    }
    .catalog-img-wrap {
        position: relative;
        padding-top: 100%; /* 1:1 Aspect Ratio */
        background: var(--ios-surface-2);
        overflow: hidden;
    }
    .catalog-img {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        object-fit: cover;
    }
    .catalog-img-placeholder {
        position: absolute;
        top: 0; left: 0; width: 100%; height: 100%;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        color: var(--ios-label-4);
    }
    .catalog-body {
        padding: 16px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .catalog-category {
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--ios-label-3);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }
    .catalog-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--ios-label);
        line-height: 1.3;
        margin-bottom: 6px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .catalog-sku {
        font-size: 0.75rem;
        color: var(--ios-label-2);
        margin-bottom: auto;
    }
    
    /* Modal Details */
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
        <h1 class="page-title">Product Catalog</h1>
        <div class="page-subtitle">Interactive digital gallery for sales representatives and customers.</div>
    </div>
</div>

<!-- Search Bar -->
<div class="row mb-3">
    <div class="col-12 col-md-8 mx-auto">
        <form method="GET" action="product_gallery.php" id="gallerySearchForm">
            <?php if($category_filter): ?>
                <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
            <?php endif; ?>
            <div class="ios-search-bar">
                <i class="bi bi-search"></i>
                <input type="text" name="search" id="searchInput" placeholder="Search products by name or SKU..." value="<?php echo htmlspecialchars($search_query); ?>">
                <?php if($search_query): ?>
                    <button type="button" class="btn btn-link text-muted p-0 border-0" onclick="clearSearch()"><i class="bi bi-x-circle-fill fs-5"></i></button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Category Pills -->
<div class="ios-pills-container">
    <a class="ios-pill <?php echo $category_filter === null ? 'active' : ''; ?>" 
       href="product_gallery.php<?php echo $search_query ? '?search='.urlencode($search_query) : ''; ?>">
        All Products
    </a>
    <?php foreach($categories as $cat): ?>
        <?php if($cat['product_count'] > 0): ?>
        <a class="ios-pill <?php echo $category_filter == $cat['id'] ? 'active' : ''; ?>" 
           href="product_gallery.php?category=<?php echo $cat['id']; ?><?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>">
           <?php echo htmlspecialchars($cat['name']); ?> 
           <span class="ios-pill-badge"><?php echo $cat['product_count']; ?></span>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- Products Grid -->
<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4 mb-5">
    <?php foreach($products as $p): ?>
    <div class="col">
        <div class="catalog-card" onclick="openGalleryModal(<?php echo htmlspecialchars(json_encode([
            'name' => $p['name'],
            'sku' => $p['sku'],
            'category' => $p['category_name'],
            'supplier' => $p['supplier_name'] ?: 'None',
            'price' => number_format($p['selling_price'], 2),
            'stock' => $p['stock'],
            'status' => $p['status'],
            'images' => $allImages[$p['id']] ?? []
        ]), ENT_QUOTES, 'UTF-8'); ?>)">
            
            <!-- Product Primary Image -->
            <div class="catalog-img-wrap border-bottom border-light">
                <?php if($p['primary_image']): ?>
                    <img src="../assets/images/products/<?php echo htmlspecialchars($p['primary_image']); ?>" class="catalog-img" alt="<?php echo htmlspecialchars($p['name']); ?>">
                <?php else: ?>
                    <div class="catalog-img-placeholder">
                        <i class="bi bi-image fs-1 mb-2"></i>
                        <span style="font-size: 0.8rem; font-weight: 600;">No Image</span>
                    </div>
                <?php endif; ?>
                
                <!-- Status Overlay Badge -->
                <?php if($p['status'] == 'unavailable'): ?>
                    <div style="position: absolute; top: 10px; right: 10px; background: rgba(255,59,48,0.9); color: white; padding: 4px 10px; border-radius: 50px; font-size: 0.7rem; font-weight: 700; backdrop-filter: blur(4px);">Unavailable</div>
                <?php endif; ?>
            </div>

            <!-- Product Info -->
            <div class="catalog-body">
                <div class="catalog-category"><?php echo htmlspecialchars($p['category_name']); ?></div>
                <div class="catalog-title"><?php echo htmlspecialchars($p['name']); ?></div>
                <div class="catalog-sku">SKU: <?php echo htmlspecialchars($p['sku'] ?: 'N/A'); ?></div>
                
                <div class="d-flex justify-content-between align-items-end pt-3 mt-2 border-top border-light">
                    <div>
                        <div style="font-size: 1.2rem; font-weight: 800; color: var(--ios-label);">Rs <?php echo number_format($p['selling_price'], 2); ?></div>
                    </div>
                    <div class="text-end">
                        <?php if($p['stock'] > 10): ?>
                            <span class="ios-badge outline" style="border-color: #1A9A3A; color: #1A9A3A;">Stock: <?php echo $p['stock']; ?></span>
                        <?php elseif($p['stock'] > 0): ?>
                            <span class="ios-badge orange">Low: <?php echo $p['stock']; ?></span>
                        <?php else: ?>
                            <span class="ios-badge red">Out of Stock</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if(empty($products)): ?>
    <div class="col-12 py-5 text-center">
        <div class="empty-state">
            <i class="bi bi-search" style="font-size: 3rem; color: var(--ios-label-4);"></i>
            <h4 class="mt-3 fw-bold">No products found</h4>
            <p class="text-muted">Try adjusting your search or category filter.</p>
            <a href="product_gallery.php" class="quick-btn quick-btn-secondary mt-3 px-4">Clear All Filters</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ==================== MODAL ==================== -->
<!-- View Product Details Modal (Horizontal Redesign) -->
<div class="modal fade" id="galleryProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="background: var(--ios-bg);">
            <div class="modal-header" style="background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator);">
                <h5 class="modal-title fw-bold" style="font-size: 1.2rem;" id="modal_name">Product Name</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="row g-4">
                    
                    <!-- Left: Images Viewer -->
                    <div class="col-md-6">
                        <div class="d-flex flex-column h-100" style="background: var(--ios-surface); border: 1px solid var(--ios-separator); border-radius: 16px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                            <!-- Main Large Image -->
                            <div style="flex: 1; min-height: 350px; background: var(--ios-surface-2); border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; margin-bottom: 16px;" id="main_image_container">
                                <img id="main_image_view" src="" style="width: 100%; height: 100%; object-fit: contain; display: none;">
                                <i id="main_image_placeholder" class="bi bi-image" style="font-size: 4rem; color: var(--ios-label-4);"></i>
                            </div>
                            
                            <!-- Scrollable Thumbnails -->
                            <div id="thumbnails_container" class="d-flex gap-2 thumb-scroll" style="overflow-x: auto; padding-bottom: 6px;">
                                <!-- JS injects thumbnails here -->
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Product Details -->
                    <div class="col-md-6 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom border-light">
                            <span id="modal_category" class="ios-badge blue outline px-3 py-2" style="font-size: 0.8rem;">Category</span>
                            <span id="modal_status"></span>
                        </div>
                        
                        <div class="info-card mb-4">
                            <div class="info-row">
                                <span class="info-label">Selling Price</span>
                                <span class="info-value" style="color: #1A9A3A; font-size: 1.8rem; font-weight: 800;" id="modal_price">Rs: 0.00</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Available Stock</span>
                                <span class="info-value text-dark" id="modal_stock" style="font-size: 1.2rem;">0</span>
                            </div>
                        </div>

                        <div class="info-card mb-auto">
                            <div class="info-row">
                                <span class="info-label">SKU (Barcode/Ref)</span>
                                <span class="info-value text-dark" id="modal_sku" style="font-family: monospace;"></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Supplier</span>
                                <span class="info-value text-dark" id="modal_supplier"></span>
                            </div>
                        </div>
                        
                        <?php if(hasRole(['admin', 'rep'])): ?>
                        <div class="mt-4 pt-3 border-top border-light">
                            <a href="create_order.php" class="quick-btn quick-btn-primary w-100" style="padding: 14px; font-size: 1rem;">
                                <i class="bi bi-cart-plus-fill me-2"></i> Go to POS / Order Creation
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// --- Live Search Debounce ---
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const searchForm = document.getElementById('gallerySearchForm');

    // Restore focus to end of text
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
            searchTimer = setTimeout(() => { searchForm.submit(); }, 700); 
        });
    }
});

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('gallerySearchForm').submit();
}

// --- Populate & Open Gallery Modal (Horizontal View) ---
function openGalleryModal(data) {
    document.getElementById('modal_name').textContent = data.name;
    document.getElementById('modal_category').textContent = data.category;
    document.getElementById('modal_supplier').textContent = data.supplier;
    document.getElementById('modal_sku').textContent = data.sku || 'N/A';
    document.getElementById('modal_price').textContent = 'Rs ' + data.price;
    document.getElementById('modal_stock').textContent = data.stock;
    
    document.getElementById('modal_status').innerHTML = data.status === 'available' 
        ? '<span class="ios-badge green px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i> Available</span>' 
        : '<span class="ios-badge red px-3 py-2"><i class="bi bi-x-circle-fill me-1"></i> Unavailable</span>';

    // Populate Images Grid
    const mainImage = document.getElementById('main_image_view');
    const placeholder = document.getElementById('main_image_placeholder');
    const thumbsContainer = document.getElementById('thumbnails_container');
    
    thumbsContainer.innerHTML = ''; 
    
    if (data.images && data.images.length > 0) {
        mainImage.style.display = 'block';
        placeholder.style.display = 'none';
        // Set the first image as main
        mainImage.src = '../assets/images/products/' + data.images[0];

        data.images.forEach((img, index) => {
            const thumb = document.createElement('img');
            thumb.src = '../assets/images/products/' + img;
            thumb.className = 'rounded border shadow-sm object-fit-cover';
            thumb.style.width = '70px';
            thumb.style.height = '70px';
            thumb.style.cursor = 'pointer';
            thumb.style.transition = 'all 0.2s';
            thumb.style.opacity = index === 0 ? '1' : '0.5';
            thumb.style.borderColor = index === 0 ? 'var(--accent)' : 'var(--ios-separator)';
            
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
        thumbsContainer.innerHTML = '<div class="text-muted small w-100 text-center py-2" style="font-weight: 500;">No Images Uploaded</div>';
    }

    new bootstrap.Modal(document.getElementById('galleryProductModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>