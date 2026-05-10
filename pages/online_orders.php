<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); // Restricted to management

// --- AUTO DB MIGRATION FOR ONLINE ORDERS ---
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN order_status ENUM('pending', 'processing', 'dispatched', 'delivered', 'cancelled') DEFAULT 'pending' AFTER payment_status");
} catch(PDOException $e) { /* Ignored if column exists */ }
// -------------------------------------------

$message = '';

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Update Status
    if ($_POST['action'] == 'update_status') {
        $order_id = (int)$_POST['order_id'];
        $order_status = $_POST['order_status'];
        $payment_status = $_POST['payment_status'];
        
        try {
            $pdo->prepare("UPDATE orders SET order_status = ?, payment_status = ? WHERE id = ?")->execute([$order_status, $payment_status, $order_id]);
            $message = "<div class='ios-alert' style='background: rgba(52,199,89,0.1); color: #1A9A3A; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-check-circle-fill me-2'></i> Order #".str_pad($order_id, 6, '0', STR_PAD_LEFT)." status updated successfully!</div>";
        } catch (Exception $e) {
            $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200; padding: 12px 16px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; font-size: 0.9rem;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Error updating status: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// --- FILTERING & PAGINATION ---
$limit = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Isolate strictly E-commerce Online Orders (Those with a shipping_name)
$whereClause = "WHERE o.shipping_name IS NOT NULL";
$params = [];

if ($search_query !== '') {
    $whereClause .= " AND (o.id LIKE ? OR o.shipping_name LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if ($status_filter !== '') {
    $whereClause .= " AND o.order_status = ?";
    $params[] = $status_filter;
}

// Get Total Rows
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id = c.id $whereClause");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch Online Orders
$query = "
    SELECT o.*, c.name as customer_name 
    FROM orders o 
    LEFT JOIN customers c ON o.customer_id = c.id 
    $whereClause 
    ORDER BY o.created_at DESC 
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

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

    /* Search Bar with Icon */
    .ios-search-wrapper { position: relative; }
    .ios-search-wrapper .bi-search {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ios-label-3);
    }
    .ios-search-wrapper .ios-input { padding-left: 38px; }

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
    .ios-badge.red     { background: rgba(255,59,48,0.12); color: #CC2200; }
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

    /* Modals */
    .modal-content { border-radius: 20px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.2); overflow: hidden; }
    .modal-header { background: var(--ios-surface); border-bottom: 1px solid var(--ios-separator); padding: 18px 24px; }
    .modal-footer { border-top: 1px solid var(--ios-separator); padding: 16px 24px; background: var(--ios-surface); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">E-Commerce Orders</h1>
        <div class="page-subtitle">Manage, track, and fulfill online store purchases.</div>
    </div>
</div>

<?php echo $message; ?>

<!-- Filters -->
<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" action="" id="filterForm">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="ios-label-sm">Search Orders</label>
                    <div class="ios-search-wrapper">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" class="ios-input" placeholder="Search by ID, Name, or Address..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="ios-label-sm">Fulfillment Status</label>
                    <select name="status" class="form-select" onchange="document.getElementById('filterForm').submit();">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending Action</option>
                        <option value="processing" <?php echo $status_filter == 'processing' ? 'selected' : ''; ?>>Processing</option>
                        <option value="dispatched" <?php echo $status_filter == 'dispatched' ? 'selected' : ''; ?>>Dispatched</option>
                        <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="quick-btn quick-btn-secondary w-100" style="padding: 10px 14px; min-height: 42px;">
                        <i class="bi bi-funnel-fill"></i> Apply Filters
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Orders Table -->
<div class="dash-card mb-4 overflow-hidden">
    <div class="table-responsive">
        <table class="ios-table">
            <thead>
                <tr class="table-ios-header">
                    <th style="width: 10%;">Order #</th>
                    <th style="width: 30%;">Date & Customer</th>
                    <th style="width: 25%;">Amount & Payment</th>
                    <th style="width: 20%;">Current Status</th>
                    <th style="width: 15%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $o): ?>
                <tr>
                    <td>
                        <span style="font-weight: 800; font-size: 0.95rem; color: var(--accent-dark);">
                            #<?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">
                            <a href="view_customer.php?id=<?php echo $o['customer_id']; ?>" class="text-decoration-none" style="color: var(--ios-label);" title="View Customer Profile">
                                <?php echo htmlspecialchars($o['shipping_name'] ?: $o['customer_name']); ?>
                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.65rem; color: var(--ios-label-3);"></i>
                            </a>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-2); margin: 2px 0;">
                            <?php echo date('M d, Y h:i A', strtotime($o['created_at'])); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--ios-label-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px;">
                            <i class="bi bi-geo-alt-fill"></i> <?php echo htmlspecialchars($o['shipping_address']); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 800; font-size: 0.95rem; color: #1c1c1e;">
                            Rs <?php echo number_format($o['total_amount'], 2); ?>
                        </div>
                        <div class="mt-1">
                            <?php if($o['payment_status'] == 'paid'): ?>
                                <span class="ios-badge green"><i class="bi bi-check-circle-fill"></i> Paid via <?php echo htmlspecialchars($o['payment_method']); ?></span>
                            <?php elseif($o['payment_status'] == 'waiting'): ?>
                                <span class="ios-badge orange"><i class="bi bi-hourglass-split"></i> Verifying <?php echo htmlspecialchars($o['payment_method']); ?></span>
                            <?php else: ?>
                                <span class="ios-badge red"><i class="bi bi-exclamation-circle-fill"></i> Unpaid</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php 
                            $statusMap = [
                                'pending' => ['class' => 'red', 'icon' => 'bi-clock', 'text' => 'Pending'],
                                'processing' => ['class' => 'orange', 'icon' => 'bi-box-seam', 'text' => 'Processing'],
                                'dispatched' => ['class' => 'blue', 'icon' => 'bi-truck', 'text' => 'Dispatched'],
                                'delivered' => ['class' => 'green', 'icon' => 'bi-check2-all', 'text' => 'Delivered'],
                                'cancelled' => ['class' => 'gray', 'icon' => 'bi-x-circle', 'text' => 'Cancelled']
                            ];
                            $stat = $statusMap[$o['order_status']] ?? $statusMap['pending'];
                        ?>
                        <span class="ios-badge <?php echo $stat['class']; ?>" style="font-size: 0.75rem; padding: 6px 12px;">
                            <i class="bi <?php echo $stat['icon']; ?>"></i> <?php echo $stat['text']; ?>
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <div class="d-flex justify-content-end gap-1">
                            <!-- View Receipt Button -->
                            <?php if($o['payment_receipt']): ?>
                            <button class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="View Payment Proof" 
                                onclick="openReceiptModal('../assets/images/receipts/<?php echo htmlspecialchars($o['payment_receipt']); ?>')">
                                <i class="bi bi-image" style="color: #FF9500;"></i>
                            </button>
                            <?php endif; ?>

                            <!-- View Invoice -->
                            <a href="view_invoice.php?id=<?php echo $o['id']; ?>" class="quick-btn quick-btn-secondary" style="padding: 6px 10px;" title="View/Print Invoice" target="_blank">
                                <i class="bi bi-receipt"></i>
                            </a>

                            <!-- Update Status Button -->
                            <button class="quick-btn quick-btn-primary" style="padding: 6px 10px;" title="Update Status" 
                                onclick='openStatusModal(<?php echo json_encode([
                                    "id" => $o['id'],
                                    "order_status" => $o['order_status'],
                                    "payment_status" => $o['payment_status']
                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                <i class="bi bi-pencil-square"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($orders)): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <i class="bi bi-globe" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No online e-commerce orders found.</p>
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
<ul class="ios-pagination">
    <?php for($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&status=<?php echo $status_filter; ?>"><?php echo $i; ?></a>
    </li>
    <?php endfor; ?>
</ul>
<?php endif; ?>


<!-- ================= MODALS ================= -->

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size: 1.1rem; font-weight: 700;">
                        <i class="bi bi-arrow-repeat text-primary me-2"></i>Update Order Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="background: var(--ios-bg);">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="order_id" id="modal_order_id">
                    
                    <div class="mb-3">
                        <label class="ios-label-sm">Fulfillment Status</label>
                        <select name="order_status" id="modal_order_status" class="form-select fw-bold" style="background: #fff;" required>
                            <option value="pending">Pending Action</option>
                            <option value="processing">Processing Order</option>
                            <option value="dispatched">Dispatched to Courier</option>
                            <option value="delivered">Delivered to Customer</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="ios-label-sm">Payment Status</label>
                        <select name="payment_status" id="modal_payment_status" class="form-select fw-bold" style="background: #fff;" required>
                            <option value="pending">Pending / Unpaid</option>
                            <option value="waiting">Waiting Verification (Uploaded Proof)</option>
                            <option value="paid">Payment Verified & Paid</option>
                        </select>
                    </div>

                    <div class="ios-alert mt-4" style="background: rgba(0,122,255,0.08); color: #0055CC; padding: 12px; border-radius: 10px; font-size: 0.8rem; font-weight: 500;">
                        <i class="bi bi-info-circle-fill me-1"></i> Updating the status immediately reflects on the customer's live tracking portal.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="quick-btn quick-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="quick-btn quick-btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receipt Viewer Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: #1c1c1e; color: #fff; border-bottom: none;">
                <h5 class="modal-title" style="font-size: 1rem; font-weight: 600;"><i class="bi bi-card-image me-2"></i> Payment Proof / Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-center" style="background: #e5e5ea;">
                <img id="receiptImage" src="" class="img-fluid object-fit-contain" style="max-height: 75vh; width: 100%;" alt="Receipt">
                <div id="receiptPdfLink" class="p-5 d-none bg-white h-100 d-flex flex-column align-items-center justify-content-center">
                    <i class="bi bi-file-earmark-pdf-fill text-danger" style="font-size: 5rem;"></i>
                    <h5 class="mt-3 fw-bold" style="color: var(--ios-label);">PDF Document Uploaded</h5>
                    <a id="pdfDownloadBtn" href="" target="_blank" class="quick-btn quick-btn-primary mt-3">
                        <i class="bi bi-box-arrow-up-right"></i> Open PDF Document
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openStatusModal(order) {
    document.getElementById('modal_order_id').value = order.id;
    document.getElementById('modal_order_status').value = order.order_status;
    document.getElementById('modal_payment_status').value = order.payment_status;
    
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

function openReceiptModal(url) {
    const imgEl = document.getElementById('receiptImage');
    const pdfEl = document.getElementById('receiptPdfLink');
    const pdfBtn = document.getElementById('pdfDownloadBtn');

    if (url.toLowerCase().endsWith('.pdf')) {
        imgEl.classList.add('d-none');
        pdfEl.classList.remove('d-none');
        pdfBtn.href = url;
    } else {
        pdfEl.classList.add('d-none');
        imgEl.classList.remove('d-none');
        imgEl.src = url;
    }

    new bootstrap.Modal(document.getElementById('receiptModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>