<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- AUTO DB MIGRATION FOR GRN SYSTEM ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS grns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        po_id INT NULL,
        reference_no VARCHAR(100) NULL,
        grn_date DATE NOT NULL,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        payment_method VARCHAR(50) DEFAULT 'Credit',
        payment_status ENUM('paid', 'pending', 'waiting') DEFAULT 'pending',
        paid_amount DECIMAL(12,2) DEFAULT 0.00,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
        FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS grn_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grn_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_cost DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (grn_id) REFERENCES grns(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $pdo->exec("ALTER TABLE grn_items DROP COLUMN cost_price");
} catch(PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE grns ADD COLUMN reference_no VARCHAR(100) NULL AFTER po_id");
} catch(PDOException $e) {}
// ----------------------------------------

$message = '';
$po_data = null;

// --- 1. HANDLE PO DATA INJECTION VIA GET ---
if (isset($_GET['po_id'])) {
    $po_id = (int)$_GET['po_id'];
    
    $poStmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND status != 'completed' AND status != 'cancelled'");
    $poStmt->execute([$po_id]);
    $po = $poStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($po) {
        $itemsStmt = $pdo->prepare("
            SELECT poi.*, p.name, p.sku, p.selling_price as current_sell_price 
            FROM purchase_order_items poi 
            JOIN products p ON poi.product_id = p.id 
            WHERE poi.po_id = ?
        ");
        $itemsStmt->execute([$po_id]);
        $po_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $po_data = json_encode([
            'po' => $po,
            'items' => $po_items
        ]);
        
        $message = "<div class='ios-alert' style='background: rgba(0,122,255,0.1); color: #0055CC;'><i class='bi bi-info-circle-fill me-2'></i> Pre-loaded from Purchase Order #".str_pad($po_id, 6, '0', STR_PAD_LEFT).". You can adjust quantities if the received amount differs from the ordered amount.</div>";
    } else {
        $message = "<div class='ios-alert' style='background: rgba(255,59,48,0.1); color: #CC2200;'><i class='bi bi-exclamation-triangle-fill me-2'></i> Purchase Order not found, or it has already been completed/cancelled.</div>";
    }
}

// --- 2. HANDLE GRN SAVING VIA POST API ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_grn') {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();
        
        $supplier_id = (int)$_POST['supplier_id'];
        $po_id_ref = !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null;
        $grn_date = $_POST['grn_date'];
        $ref_no = $_POST['reference_no'] ?? '';
        
        $subtotal = (float)$_POST['subtotal'];
        $discount = (float)$_POST['discount_amount'];
        $total_amount = (float)$_POST['net_amount'];
        
        $paid_cash = (float)$_POST['paid_cash'];
        $paid_bank = (float)$_POST['paid_bank'];
        $paid_cheque = (float)$_POST['paid_cheque'];
        
        $cleared_paid = $paid_cash + $paid_bank;
        $total_paid = $cleared_paid + $paid_cheque;
        $cart = json_decode($_POST['cart'], true);
        
        if (empty($cart)) throw new Exception("GRN must contain at least one item.");
        
        // Determine Payment Status
        $payment_status = 'pending';
        $payment_method_arr = [];
        
        if ($total_paid >= $total_amount && $total_amount > 0) $payment_status = 'paid';
        if ($paid_cheque > 0) $payment_status = 'waiting'; 
        
        if ($paid_cash > 0) $payment_method_arr[] = 'Cash';
        if ($paid_bank > 0) $payment_method_arr[] = 'Bank';
        if ($paid_cheque > 0) $payment_method_arr[] = 'Cheque';
        
        $payment_method = empty($payment_method_arr) ? 'Credit' : implode('+', $payment_method_arr);

        // 1. Insert GRN
        $stmt = $pdo->prepare("INSERT INTO grns (supplier_id, po_id, reference_no, grn_date, subtotal, discount_amount, total_amount, payment_method, payment_status, paid_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$supplier_id, $po_id_ref, $ref_no, $grn_date, $subtotal, $discount, $total_amount, $payment_method, $payment_status, $cleared_paid, $_SESSION['user_id']]);
        $grn_id = $pdo->lastInsertId();
        
        $grn_no = "GRN-" . str_pad($grn_id, 6, '0', STR_PAD_LEFT);

        // 2. Insert Items, Update Stock & Price
        $itemStmt = $pdo->prepare("INSERT INTO grn_items (grn_id, product_id, quantity, unit_cost) VALUES (?, ?, ?, ?)");
        $stockStmt = $pdo->prepare("UPDATE products SET stock = stock + ?, selling_price = ? WHERE id = ?");
        $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'grn_in', ?, ?, (SELECT stock - ? FROM products WHERE id = ?), (SELECT stock FROM products WHERE id = ?), ?)");
        
        foreach ($cart as $item) {
            $pid = (int)$item['id'];
            $qty = (int)$item['qty'];
            $sell = (float)$item['sell'];
            
            $itemStmt->execute([$grn_id, $pid, $qty, $sell]);
            $stockStmt->execute([$qty, $sell, $pid]);
            $logStmt->execute([$pid, $grn_id, $qty, $qty, $pid, $pid, $_SESSION['user_id']]);
        }

        // 3. Mark PO as Completed
        if ($po_id_ref) {
            $pdo->prepare("UPDATE purchase_orders SET status = 'completed' WHERE id = ?")->execute([$po_id_ref]);
        }

        // 4. Financial Records
        if ($paid_cash > 0) {
            $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$paid_cash]);
            $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$paid_cash, "Supplier Payment - $grn_no", $_SESSION['user_id']]);
        }
        if ($paid_bank > 0) {
            $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$paid_bank]);
            $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$paid_bank, "Supplier Bank Transfer - $grn_no", $_SESSION['user_id']]);
        }
        if ($paid_cheque > 0) {
            $chkBank = $_POST['cheque_bank'] ?? 'Unknown Bank';
            $chkNum = $_POST['cheque_number'] ?? 'Unknown Check';
            $chkDate = $_POST['cheque_date'] ?? date('Y-m-d');
            
            $pdo->prepare("INSERT INTO cheques (type, supplier_id, grn_id, bank_name, cheque_number, banking_date, amount, status) VALUES ('outgoing', ?, ?, ?, ?, ?, ?, 'pending')")->execute([
                $supplier_id, $grn_id, $chkBank, $chkNum, $chkDate, $paid_cheque
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'grn_id' => $grn_id, 'message' => 'GRN Processed Successfully!']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch Master Data
$suppliers = $pdo->query("SELECT id, company_name FROM suppliers WHERE status = 'active' ORDER BY company_name ASC")->fetchAll();
$products = $pdo->query("SELECT id, name, sku, selling_price, supplier_id, stock FROM products WHERE status = 'available' ORDER BY name ASC")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Tom Select CSS -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

<div class="page-header">
    <div>
        <h1 class="page-title">Goods Receipt Note (GRN)</h1>
        <div class="page-subtitle">Receive inventory from suppliers, update costs, and process payments.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="grn_list.php" class="quick-btn quick-btn-secondary"><i class="bi bi-x-lg"></i> Cancel</a>
        <button type="button" id="btnSaveGrn" class="quick-btn quick-btn-primary px-4"><i class="bi bi-box-arrow-in-down"></i> Save GRN & Update Stock</button>
    </div>
</div>

<div id="alertBox"><?php echo $message; ?></div>

<div class="row g-3 align-items-start">
    
    <!-- Top Details Card -->
    <div class="col-12">
        <div class="dash-card p-4 mb-2">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="ios-label-sm">Supplier <span class="text-danger">*</span></label>
                    <select id="supplierSelect" class="form-select fw-bold border-dark">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="ios-label-sm">Receipt Date <span class="text-danger">*</span></label>
                    <input type="date" id="grnDate" class="ios-input fw-bold border-dark" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="ios-label-sm">Supplier Inv / Ref No</label>
                    <input type="text" id="referenceNo" class="ios-input border-dark" placeholder="e.g. INV-2024-001">
                </div>
                <div class="col-md-3">
                    <label class="ios-label-sm">Linked PO Number</label>
                    <input type="text" id="linkedPoDisplay" class="ios-input bg-light fw-bold" readonly placeholder="N/A">
                    <input type="hidden" id="linkedPoId" value="">
                </div>
            </div>
        </div>
    </div>

    <!-- Main Left Column (Products & Cart) -->
    <div class="col-lg-8">
        <!-- Product Entry Bar -->
        <div class="dash-card p-3 mb-3" style="background: var(--ios-surface-2); overflow: visible; position: relative; z-index: 10;">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="ios-label-sm">Search Product</label>
                    <select id="productSelect" class="form-select" placeholder="Type name or SKU...">
                        <option value="">Search product...</option>
                        <?php foreach($products as $p): ?>
                            <option value="<?php echo $p['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                    data-sku="<?php echo htmlspecialchars($p['sku']); ?>"
                                    data-sell="<?php echo $p['selling_price']; ?>"
                                    data-stock="<?php echo $p['stock']; ?>"
                                    data-supplier="<?php echo $p['supplier_id']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['sku']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="ios-label-sm">Recv. Qty</label>
                    <input type="number" id="entryQty" class="ios-input text-center fw-bold" value="1" min="1">
                </div>
                <div class="col-md-4">
                    <label class="ios-label-sm">Dist. Price (Rs)</label>
                    <input type="number" id="entrySell" class="ios-input text-end fw-bold" style="color: #1A9A3A;" step="0.01" title="Updates Product Database Selling Price">
                </div>
                <div class="col-md-2">
                    <button type="button" id="btnAddItem" class="quick-btn quick-btn-primary w-100" style="padding: 10px 14px; min-height: 42px;">
                        <i class="bi bi-plus-lg"></i> Add
                    </button>
                </div>
            </div>
        </div>

        <!-- Cart Table -->
        <div class="dash-card mb-4 overflow-hidden" style="z-index: 1;">
            <div class="table-responsive" style="min-height: 300px;">
                <table class="ios-table" id="cartTable" style="margin: 0;">
                    <thead>
                        <tr class="table-ios-header">
                            <th class="ps-3">Product Name</th>
                            <th class="text-center" style="width: 12%;">Qty</th>
                            <th class="text-end" style="width: 25%;">Dist. Price</th>
                            <th class="text-end pe-3" style="width: 25%;">Line Total</th>
                            <th class="text-center" style="width: 5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="grnCartBody">
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="empty-state" style="padding: 20px;">
                                    <i class="bi bi-cart2" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                                    <p class="mt-2" style="font-weight: 500;">No items added to receipt yet.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Column (Totals & Payments) -->
    <div class="col-lg-4">
        <!-- Totals Box -->
        <div class="dash-card p-4 mb-3">
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-calculator text-primary me-2"></i> Invoice Totals</h6>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted fw-bold">Gross Subtotal</span>
                <span class="fw-bold text-dark fs-5">Rs <span id="ui_subtotal">0.00</span></span>
            </div>
            

            
            <hr class="my-3 border-secondary border-opacity-25">
            
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold" style="font-size: 0.9rem; color: var(--ios-label); text-transform: uppercase; letter-spacing: 0.05em;">Net Payable</span>
                <span class="fw-bold text-success" style="font-size: 1.6rem; letter-spacing: -0.5px;">Rs <span id="ui_net">0.00</span></span>
            </div>
        </div>

        <!-- Payment Allocation Box -->
        <div class="dash-card p-4 bg-white border">
            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-wallet2 text-success me-2"></i> Outgoing Payment</h6>
            
            <div class="row align-items-center mb-2">
                <div class="col-4 fw-bold small" style="color: var(--ios-label-2);">Cash Paid</div>
                <div class="col-8"><input type="number" id="payCash" class="ios-input text-end fw-bold" style="min-height: 36px; padding: 6px 12px;" placeholder="0.00" step="0.01"></div>
            </div>
            <div class="row align-items-center mb-2">
                <div class="col-4 fw-bold small" style="color: var(--ios-label-2);">Bank Trans.</div>
                <div class="col-8"><input type="number" id="payBank" class="ios-input text-end fw-bold" style="min-height: 36px; padding: 6px 12px;" placeholder="0.00" step="0.01"></div>
            </div>
            <div class="row align-items-center mb-3">
                <div class="col-4 fw-bold small" style="color: var(--ios-label-2);">Cheque</div>
                <div class="col-8"><input type="number" id="payCheque" class="ios-input text-end fw-bold" style="min-height: 36px; padding: 6px 12px;" placeholder="0.00" step="0.01"></div>
            </div>

            <div id="chequeFields" class="d-none mb-3" style="background: rgba(255,149,0,0.08); border-radius: 12px; padding: 12px; border: 1px solid rgba(255,149,0,0.2);">
                <input type="text" id="chkBank" class="ios-input mb-2 border-warning" style="background: #fff; min-height: 36px; padding: 6px 12px;" placeholder="Bank Name">
                <input type="text" id="chkNum" class="ios-input mb-2 border-warning" style="background: #fff; min-height: 36px; padding: 6px 12px;" placeholder="Cheque Number">
                <input type="date" id="chkDate" class="ios-input border-warning" style="background: #fff; min-height: 36px; padding: 6px 12px;">
            </div>

            <div class="d-flex justify-content-between align-items-center pt-3 border-top border-secondary border-opacity-10 mt-2">
                <span class="fw-bold small text-muted" id="balanceLabel">Balance (Credit)</span>
                <span class="fw-bold fs-4 text-danger" id="ui_balance" style="letter-spacing: -0.5px;">0.00</span>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    
    const prodSelectEl = document.getElementById('productSelect');
    let prodSelect = new TomSelect('#productSelect', { 
        create: false,
        dropdownClass: 'ts-dropdown custom-product-dropdown',
        searchField: ['text'],
        render: {
            option: function (data, escape) {
                const opt = prodSelectEl.querySelector('option[value="' + escape(data.value) + '"]');
                if (!opt || !data.value) return `<div class="p-2 text-muted">${escape(data.text)}</div>`;
                const name  = opt.getAttribute('data-name')  || data.text;
                const sku   = opt.getAttribute('data-sku')   || '';
                const cost = opt.getAttribute('data-cost')  || '0.00';
                const stock = parseInt(opt.getAttribute('data-stock') || '0');
                const sCol  = stock > 10 ? 'var(--accent-dark)' : (stock > 0 ? '#C07000' : '#CC2200');
                return `
                    <div class="d-flex justify-content-between px-3 py-2" style="border-bottom:1px solid var(--ios-separator);cursor:pointer;">
                        <div style="flex:1;min-width:0;padding-right:12px;">
                            <div style="font-weight:700;font-size:0.88rem;color:#1c1c1e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escape(name)}</div>
                            <div style="font-size:0.74rem;color:#8e8e93;margin-top:2px;">SKU: ${escape(sku)}</div>
                        </div>
                        <div style="text-align:right;white-space:nowrap;flex-shrink:0;">
                            <div style="font-weight:700;font-size:0.88rem;color:var(--ios-label-2);">Price: Rs ${escape(cost)}</div>
                            <div style="font-size:0.74rem;font-weight:600;color:${sCol};margin-top:2px;">${stock} in stock</div>
                        </div>
                    </div>`;
            },
            item: function (data, escape) {
                const opt = prodSelectEl.querySelector('option[value="' + escape(data.value) + '"]');
                if (!opt || !data.value) return `<div>${escape(data.text)}</div>`;
                const name = opt.getAttribute('data-name') || data.text;
                const sku  = opt.getAttribute('data-sku')  || '';
                return `<div style="font-weight:700;font-size:0.85rem;color:#1c1c1e;">${escape(name)} <span style="font-weight:500;color:#8e8e93;">(${escape(sku)})</span></div>`;
            }
        }
    });
    
    const uiSub = document.getElementById('ui_subtotal');
    const uiNet = document.getElementById('ui_net');
    const uiBal = document.getElementById('ui_balance');
    const balLabel = document.getElementById('balanceLabel');
    const cartBody = document.getElementById('grnCartBody');

    const payCash = document.getElementById('payCash');
    const payBank = document.getElementById('payBank');
    const payCheque = document.getElementById('payCheque');
    const chkFields = document.getElementById('chequeFields');
    const supplierSel = document.getElementById('supplierSelect');

    const poInjection = <?php echo $po_data ? $po_data : 'null'; ?>;
    if (poInjection && poInjection.po) {
        supplierSel.value = poInjection.po.supplier_id;
        document.getElementById('linkedPoDisplay').value = "PO-" + String(poInjection.po.id).padStart(6, '0');
        document.getElementById('linkedPoId').value = poInjection.po.id;
        


        cart = poInjection.items.map(i => ({
            id: i.product_id,
            name: i.name,
            sku: i.sku,
            qty: parseInt(i.quantity),
            sell: parseFloat(i.current_sell_price) || parseFloat(i.unit_price)
        }));
        
        renderCart();
    }

    document.getElementById('productSelect').addEventListener('change', function() {
        if(!this.value) return;
        const opt = this.options[this.selectedIndex];
        
        if (supplierSel.value && supplierSel.value !== opt.dataset.supplier) {
            alert("Warning: This product usually belongs to a different supplier.");
        }
        
        document.getElementById('entryQty').value = 1;
        document.getElementById('entrySell').value = parseFloat(opt.dataset.sell).toFixed(2);
    });

    document.getElementById('btnAddItem').addEventListener('click', function() {
        const sel = document.getElementById('productSelect');
        const val = sel.value;
        if (!val) { alert("Select a product."); return; }
        
        const opt = sel.options[sel.selectedIndex];
        const q = parseInt(document.getElementById('entryQty').value) || 0;
        const s = parseFloat(document.getElementById('entrySell').value) || 0;

        if (q <= 0) return alert("Quantity must be positive.");

        const exists = cart.find(x => x.id == val);
        if (exists) {
            exists.qty += q;
            exists.sell = s;
        } else {
            cart.push({
                id: val, name: opt.dataset.name, sku: opt.dataset.sku,
                qty: q, sell: s
            });
        }
        
        prodSelect.clear();
        document.getElementById('entryQty').value = 1;
        document.getElementById('entrySell').value = '';
        renderCart();
    });

    function renderCart() {
        cartBody.innerHTML = '';
        let sub = 0;

        if (cart.length === 0) {
            cartBody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state" style="padding: 20px;">
                            <i class="bi bi-cart2" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                            <p class="mt-2" style="font-weight: 500;">No items added to receipt yet.</p>
                        </div>
                    </td>
                </tr>`;
        } else {
            cart.forEach((item, idx) => {
                let lineTotal = item.qty * item.sell;
                sub += lineTotal;
                
                cartBody.innerHTML += `
                    <tr>
                        <td class="ps-3">
                            <div class="fw-bold" style="font-size: 0.9rem; color: var(--ios-label);">${item.name}</div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">${item.sku}</div>
                        </td>
                        <td><input type="number" class="ios-input text-center upd-qty px-1" style="min-height: 32px; padding: 4px;" data-idx="${idx}" value="${item.qty}" min="1"></td>
                        <td><input type="number" class="ios-input text-end upd-sell px-1" style="min-height: 32px; padding: 4px; color: #1A9A3A; font-weight: 700;" data-idx="${idx}" value="${item.sell.toFixed(2)}" step="0.01" title="Will update system retail price"></td>
                        <td class="text-end fw-bold pe-3" style="color: var(--ios-label); font-size: 0.95rem;">Rs ${lineTotal.toFixed(2)}</td>
                        <td class="text-center">
                            <button class="quick-btn remove-btn" style="padding: 6px; background: rgba(255,59,48,0.1); color: #CC2200; min-height: 32px;" data-idx="${idx}">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        document.querySelectorAll('.upd-qty').forEach(el => el.addEventListener('change', function() {
            cart[this.dataset.idx].qty = parseInt(this.value) || 1; renderCart();
        }));
        document.querySelectorAll('.upd-sell').forEach(el => el.addEventListener('change', function() {
            cart[this.dataset.idx].sell = parseFloat(this.value) || 0; renderCart();
        }));
        document.querySelectorAll('.remove-btn').forEach(el => el.addEventListener('click', function() {
            cart.splice(this.dataset.idx, 1); renderCart();
        }));

        uiSub.textContent = sub.toFixed(2);
        calculateNet();
    }

    function calculateNet() {
        const sub = parseFloat(uiSub.textContent) || 0;
        let net = sub;
        if (net < 0) net = 0; 
        
        uiNet.textContent = net.toFixed(2);
        window.netAmount = net;
        calculateBalance();
    }

    function calculateBalance() {
        const cash = parseFloat(payCash.value) || 0;
        const bank = parseFloat(payBank.value) || 0;
        const chq = parseFloat(payCheque.value) || 0;
        
        if (chq > 0) chkFields.classList.remove('d-none');
        else chkFields.classList.add('d-none');

        const paid = cash + bank + chq;
        const net = window.netAmount || 0;
        const bal = paid - net;

        if (bal >= 0) {
            balLabel.textContent = "Change Due";
            uiBal.textContent = bal.toFixed(2);
            uiBal.className = "fw-bold fs-4 text-dark";
        } else {
            balLabel.textContent = "Remaining Credit";
            uiBal.textContent = Math.abs(bal).toFixed(2);
            uiBal.className = "fw-bold fs-4 text-danger";
        }
    }

    [payCash, payBank, payCheque].forEach(el => el.addEventListener('input', calculateBalance));

    document.getElementById('btnSaveGrn').addEventListener('click', async function() {
        if (cart.length === 0) return alert("Add items to GRN first.");
        if (!supplierSel.value) return alert("Supplier must be selected.");
        
        const chq = parseFloat(payCheque.value) || 0;
        if (chq > 0 && (!document.getElementById('chkBank').value || !document.getElementById('chkNum').value)) {
            return alert("Please fill Cheque Bank and Number.");
        }

        if(!confirm("Warning: Saving this GRN will instantly add these quantities to your physical Stock and update Selling Prices. Proceed?")) return;

        const btn = this;
        const orig = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
        btn.disabled = true;

        const fd = new FormData();
        fd.append('action', 'save_grn');
        fd.append('supplier_id', supplierSel.value);
        fd.append('po_id', document.getElementById('linkedPoId').value);
        fd.append('reference_no', document.getElementById('referenceNo').value);
        fd.append('grn_date', document.getElementById('grnDate').value);
        fd.append('subtotal', parseFloat(uiSub.textContent));
        fd.append('discount_amount', 0);
        fd.append('net_amount', window.netAmount);
        fd.append('paid_cash', parseFloat(payCash.value) || 0);
        fd.append('paid_bank', parseFloat(payBank.value) || 0);
        fd.append('paid_cheque', chq);
        fd.append('cheque_bank', document.getElementById('chkBank').value);
        fd.append('cheque_number', document.getElementById('chkNum').value);
        fd.append('cheque_date', document.getElementById('chkDate').value);
        fd.append('cart', JSON.stringify(cart));

        try {
            const res = await fetch('create_grn.php', { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success) {
                document.getElementById('alertBox').innerHTML = `<div class="ios-alert" style="background: rgba(52,199,89,0.1); color: #1A9A3A;"><i class="bi bi-check-circle-fill me-2"></i> ${data.message} Redirecting...</div>`;
                setTimeout(() => window.location.href = 'grn_list.php', 1500);
            } else {
                document.getElementById('alertBox').innerHTML = `<div class="ios-alert" style="background: rgba(255,59,48,0.1); color: #CC2200;"><i class="bi bi-exclamation-triangle-fill me-2"></i> Error: ${data.message}</div>`;
                btn.innerHTML = orig; btn.disabled = false;
            }
        } catch (e) {
            document.getElementById('alertBox').innerHTML = `<div class="ios-alert" style="background: rgba(255,59,48,0.1); color: #CC2200;"><i class="bi bi-wifi-off me-2"></i> Network Error saving GRN.</div>`;
            btn.innerHTML = orig; btn.disabled = false;
        }
    });

});
</script>
<?php include '../includes/footer.php'; ?>