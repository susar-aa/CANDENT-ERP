<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- ADVANCED DB MIGRATION FOR SMART POs & SUPPLIER DAILY ALLOWANCES ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NOT NULL,
        po_date DATE NOT NULL,
        expected_date DATE NULL,
        status ENUM('pending', 'sent', 'received', 'cancelled') DEFAULT 'pending',
        subtotal DECIMAL(12,2) DEFAULT 0.00,
        claim_start_date DATE NULL,
        claim_end_date DATE NULL,
        claimed_discount DECIMAL(12,2) DEFAULT 0.00,
        claimed_foc DECIMAL(12,2) DEFAULT 0.00,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    try { $pdo->exec("ALTER TABLE purchase_order_items CHANGE cost_price unit_price DECIMAL(12,2) NOT NULL"); } catch(Exception $e){}
    
    // Legacy Migrations
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN subtotal DECIMAL(12,2) DEFAULT 0.00 AFTER status"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN claim_start_date DATE NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN claim_end_date DATE NULL"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN claimed_discount DECIMAL(12,2) DEFAULT 0.00"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN claimed_foc DECIMAL(12,2) DEFAULT 0.00"); } catch(Exception $e){}
    
    // NEW: Supplier Daily Allowance Tracking Columns
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN claimed_daily_pay DECIMAL(12,2) DEFAULT 0.00"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN working_days INT DEFAULT 0"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN daily_pay_rate DECIMAL(12,2) DEFAULT 0.00"); } catch(Exception $e){}
    
} catch(PDOException $e) {}
// -------------------------------------------

$message = '';

// --- POST SUBMISSION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_po') {
    try {
        $pdo->beginTransaction();
        
        $edit_po_id = !empty($_POST['edit_po_id']) ? (int)$_POST['edit_po_id'] : null;
        $supplier_id = (int)$_POST['supplier_id'];
        $po_date = $_POST['po_date'];
        
        $subtotal = (float)$_POST['hidden_subtotal'];
        $total_payable = (float)$_POST['hidden_total_payable'];

        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];

        if (empty($product_ids)) {
            throw new Exception("Purchase order must contain at least one item.");
        }

        if ($edit_po_id) {
            $stmt = $pdo->prepare("UPDATE purchase_orders SET supplier_id=?, po_date=?, subtotal=?, total_amount=? WHERE id=?");
            $stmt->execute([$supplier_id, $po_date, $subtotal, $total_payable, $edit_po_id]);
            $po_id = $edit_po_id;
            
            // Clear old items
            $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id = ?")->execute([$po_id]);
            $message = "<div class='alert alert-success fw-bold'><i class='bi bi-check-circle'></i> Purchase Order updated successfully!</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO purchase_orders (supplier_id, po_date, subtotal, total_amount) VALUES (?, ?, ?, ?)");
            $stmt->execute([$supplier_id, $po_date, $subtotal, $total_payable]);
            $po_id = $pdo->lastInsertId();
            $message = "<div class='alert alert-success fw-bold'><i class='bi bi-check-circle'></i> Purchase Order created successfully!</div>";
        }

        $itemStmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < count($product_ids); $i++) {
            $pid = (int)$product_ids[$i];
            $qty = (int)$quantities[$i];
            $cost = (float)$unit_prices[$i];
            if ($pid > 0 && $qty > 0) {
                $itemStmt->execute([$po_id, $pid, $qty, $cost]);
            }
        }

        $pdo->commit();
        
        // Redirect to view
        header("Location: view_po.php?id=" . $po_id);
        exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        $message = "<div class='alert alert-danger'>" . $e->getMessage() . "</div>";
    }
}

// --- FETCH EDIT DATA ---
$edit_mode = false;
$po_data = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $stmt->execute([$edit_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($po) {
        $edit_mode = true;
        $itemsStmt = $pdo->prepare("SELECT poi.*, p.name, p.sku FROM purchase_order_items poi JOIN products p ON poi.product_id = p.id WHERE poi.po_id = ?");
        $itemsStmt->execute([$edit_id]);
        $po['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $po_data = json_encode($po);
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

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-file-earmark-text text-warning text-dark me-2"></i> <?php echo $edit_mode ? "Edit Purchase Order #".str_pad($edit_id,6,'0',STR_PAD_LEFT) : "Create Purchase Order"; ?></h1>
    <div>
        <button class="btn btn-warning text-dark fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#aiGeneratorModal">
            <i class="bi bi-stars"></i> Smart AI Generator
        </button>
    </div>
</div>

<?php echo $message; ?>

<form method="POST" id="poForm">
    <input type="hidden" name="action" value="save_po">
    <?php if($edit_mode): ?><input type="hidden" name="edit_po_id" value="<?php echo $edit_id; ?>"><?php endif; ?>
    
    <input type="hidden" name="hidden_subtotal" id="form_subtotal" value="0">
    <input type="hidden" name="hidden_total_payable" id="form_total_payable" value="0">

    <div class="row g-4">
        <!-- Left: Cart Data -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4 rounded-4">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-end">
                    <h6 class="fw-bold m-0 text-dark"><i class="bi bi-box-seam"></i> Order Items</h6>
                    
                    <!-- Manual Item Adder -->
                    <div class="d-flex gap-2" style="width: 60%;">
                        <select id="manualProductSelect" class="form-select form-select-sm">
                            <option value="">Search to add manually...</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-price="<?php echo $p['selling_price']; ?>" data-supplier="<?php echo $p['supplier_id']; ?>">
                                    <?php echo htmlspecialchars($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-sm btn-success fw-bold px-3" id="btnManualAdd"><i class="bi bi-plus"></i></button>
                    </div>
                </div>
                
                <div class="table-responsive p-3 pt-0">
                    <table class="table table-hover align-middle mb-0 border">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40%;">Product</th>
                                <th class="text-center" style="width: 20%;">Order Qty</th>
                                <th class="text-end" style="width: 20%;">Unit Rate (Rs)</th>
                                <th class="text-end" style="width: 15%;">Line Total</th>
                                <th class="text-center" style="width: 5%;">Del</th>
                            </tr>
                        </thead>
                        <tbody id="poCartBody">
                            <tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-robot fs-1 d-block mb-2 opacity-50"></i> Use the Smart AI Generator, or add items manually.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right: Sidebar Meta -->
        <div class="col-lg-4">
            <!-- Header Info -->
            <div class="card shadow-sm border-0 mb-4 rounded-4">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Supplier <span class="text-danger">*</span></label>
                        <select name="supplier_id" id="supplierSelect" class="form-select fw-bold" required <?php echo $edit_mode ? 'readonly' : ''; ?>>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-bold text-muted">Order Date <span class="text-danger">*</span></label>
                        <input type="date" name="po_date" id="poDate" class="form-control fw-bold" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
            </div>

            <!-- Financial Summary & Claims -->
            <div class="card shadow-sm border-0 border-top border-warning border-4 rounded-4 mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-receipt"></i> PO Financial Summary</h6>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted fw-bold">Subtotal:</span>
                        <span class="fw-bold text-dark fs-5">Rs <span id="ui_subtotal">0.00</span></span>
                    </div>

                    <div class="d-flex justify-content-between border-top pt-3 mt-2">
                        <span class="fw-bold text-dark fs-5">NET PAYABLE:</span>
                        <span class="fw-bold text-success fs-4">Rs <span id="ui_net">0.00</span></span>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 p-3">
                    <button type="submit" class="btn btn-warning w-100 rounded-pill fw-bold shadow-sm py-2 text-dark">
                        <i class="bi bi-save"></i> Save Purchase Order
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- AI Generator Modal -->
<div class="modal fade" id="aiGeneratorModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header bg-warning border-bottom-0 pb-3">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-stars"></i> Smart PO Generator</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-warning border-warning bg-white shadow-sm small fw-bold">
                    This tool analyzes past sales velocity and automatically calculates supplier reimbursement claims for FOC items, discounts, and daily allowances.
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Target Supplier</label>
                    <select id="aiSupplier" class="form-select fw-bold border-dark">
                        <option value="">Select Supplier to analyze...</option>
                        <?php foreach($suppliers as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>



                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-bold text-muted">Sales Analysis Range</label>
                        <select id="aiSalesPeriod" class="form-select border-dark">
                            <option value="7">Last 7 Days Velocity</option>
                            <option value="14">Last 14 Days Velocity</option>
                            <option value="30" selected>Last 30 Days Velocity</option>
                            <option value="custom">Custom Date Range</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-muted">Buffer Stock %</label>
                        <div class="input-group">
                            <input type="number" id="aiBuffer" class="form-control border-dark fw-bold text-center" value="20" min="0" max="100">
                            <span class="input-group-text bg-white border-dark fw-bold">%</span>
                        </div>
                    </div>
                </div>

                <div class="row g-2 d-none mb-3" id="aiCustomDates">
                    <div class="col-6">
                        <label class="small text-muted fw-bold">From</label>
                        <input type="date" id="aiSalesStart" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label class="small text-muted fw-bold">To</label>
                        <input type="date" id="aiSalesEnd" class="form-control form-control-sm">
                    </div>
                </div>
                

                
                <button type="button" id="btnRunAi" class="btn btn-dark w-100 fw-bold rounded-pill shadow-sm py-2 mt-2">
                    <i class="bi bi-cpu"></i> Analyze & Generate PO
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let poCart = [];
    let claimData = { start: '', end: '', foc: 0, dis: 0, working_days: 0, daily_rate: 0, daily_pay: 0 };
    
    const uiSub = document.getElementById('ui_subtotal');
    const uiNet = document.getElementById('ui_net');
    const cartBody = document.getElementById('poCartBody');

    // Init Tom Select
    let manualSelect = new TomSelect('#manualProductSelect', { create: false, sortField: { field: "text", direction: "asc" } });

    // Load Edit Data if exists
    const editDataRaw = <?php echo $po_data ? $po_data : 'null'; ?>;
    if (editDataRaw) {
        document.getElementById('supplierSelect').value = editDataRaw.supplier_id;
        document.getElementById('poDate').value = editDataRaw.po_date;
        


        poCart = editDataRaw.items.map(i => ({
            id: i.product_id,
            name: i.name,
            price: parseFloat(i.unit_price),
            qty: parseInt(i.quantity)
        }));
        
        renderCart();
    }

    // Manual Add Item
    document.getElementById('btnManualAdd').addEventListener('click', function() {
        const sel = document.getElementById('manualProductSelect');
        const val = sel.value;
        if (!val) return;
        
        const option = sel.options[sel.selectedIndex];
        const suppId = document.getElementById('supplierSelect').value;

        if (suppId && option.dataset.supplier !== suppId) {
            alert("Warning: This product belongs to a different supplier.");
        }

        const existing = poCart.find(p => p.id == val);
        if (existing) {
            existing.qty += 1;
        } else {
            poCart.push({
                id: val,
                name: option.dataset.name,
                price: parseFloat(option.dataset.price),
                qty: 1
            });
        }
        manualSelect.clear();
        renderCart();
    });

    // Smart Generator Logic
    document.getElementById('aiSalesPeriod').addEventListener('change', function() {
        document.getElementById('aiCustomDates').classList.toggle('d-none', this.value !== 'custom');
    });

    document.getElementById('btnRunAi').addEventListener('click', async function() {
        const suppId = document.getElementById('aiSupplier').value;
        if (!suppId) { alert("Please select a supplier first."); return; }

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Analyzing Data...';

        const formData = new FormData();
        formData.append('action', 'generate');
        formData.append('supplier_id', suppId);
        formData.append('sales_period', document.getElementById('aiSalesPeriod').value);
        formData.append('sales_start', document.getElementById('aiSalesStart').value);
        formData.append('sales_end', document.getElementById('aiSalesEnd').value);
        formData.append('buffer_percent', document.getElementById('aiBuffer').value);

        try {
            const res = await fetch('../ajax/generate_po_suggestions.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                document.getElementById('supplierSelect').value = suppId;
                


                poCart = data.products.map(p => ({
                    id: p.id,
                    name: p.name,
                    price: parseFloat(p.selling_price),
                    qty: parseInt(p.suggested_qty)
                }));

                renderCart();
                bootstrap.Modal.getInstance(document.getElementById('aiGeneratorModal')).hide();
            } else {
                alert("Error: " + data.message);
            }
        } catch(e) {
            alert("Network Error");
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cpu"></i> Analyze & Generate PO';
    });

    function renderCart() {
        let subtotal = 0;
        
        if (poCart.length === 0) {
            cartBody.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-cart-x fs-1 d-block mb-2"></i> Cart is empty.</td></tr>';
        } else {
            cartBody.innerHTML = '';
            poCart.forEach((item, idx) => {
                let lineTotal = item.qty * item.price;
                subtotal += lineTotal;

                cartBody.innerHTML += `
                    <tr>
                        <td class="fw-bold text-dark">
                            ${item.name}
                            <input type="hidden" name="product_id[]" value="${item.id}">
                        </td>
                        <td class="text-center">
                            <input type="number" name="quantity[]" class="form-control form-control-sm text-center fw-bold update-qty" data-idx="${idx}" value="${item.qty}" min="1">
                        </td>
                        <td class="text-end">
                            <input type="number" name="unit_price[]" class="form-control form-control-sm text-end fw-bold update-cost" data-idx="${idx}" value="${item.price.toFixed(2)}" step="0.01">
                        </td>
                        <td class="text-end fw-bold text-success">Rs ${lineTotal.toFixed(2)}</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm text-danger remove-btn" data-idx="${idx}"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        // Attach listeners
        document.querySelectorAll('.update-qty').forEach(el => el.addEventListener('input', function() {
            poCart[this.dataset.idx].qty = parseInt(this.value) || 0;
            renderCart();
        }));
        document.querySelectorAll('.update-cost').forEach(el => el.addEventListener('change', function() {
            poCart[this.dataset.idx].price = parseFloat(this.value) || 0;
            renderCart();
        }));
        document.querySelectorAll('.remove-btn').forEach(el => el.addEventListener('click', function() {
            poCart.splice(this.dataset.idx, 1);
            renderCart();
        }));

        // Update UI Summaries
        uiSub.textContent = subtotal.toFixed(2);
        
        let net = subtotal;
        if (net < 0) net = 0; // Prevent negative POs physically paying us
        uiNet.textContent = net.toFixed(2);

        // Update Hidden Form Fields for POST
        document.getElementById('form_subtotal').value = subtotal.toFixed(2);
        document.getElementById('form_total_payable').value = net.toFixed(2);
    }

    <?php if(isset($_GET['smart']) && $_GET['smart'] == 'true' && !$edit_mode): ?>
    // Auto-open AI generator if chosen from previous screen
    setTimeout(() => {
        new bootstrap.Modal(document.getElementById('aiGeneratorModal')).show();
    }, 400);
    <?php endif; ?>

});
</script>

<?php include '../includes/footer.php'; ?>