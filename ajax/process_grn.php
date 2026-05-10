<?php
/**
 * API Endpoint: Processes Good Receipt Notes (GRN).
 * Inserts GRN, updates Product Stock, updates Cost Prices, writes to Stock Ledger, and processes Finances.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// --- AUTO DB MIGRATION FOR GRN PAYMENTS & OUTGOING CHEQUES ---
try {
    $pdo->exec("ALTER TABLE grns ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Pending'");
    $pdo->exec("ALTER TABLE grns ADD COLUMN payment_status ENUM('paid', 'pending', 'waiting') DEFAULT 'pending'");
    $pdo->exec("ALTER TABLE grns ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0.00");
    $pdo->exec("ALTER TABLE grns ADD COLUMN po_id INT NULL AFTER supplier_id");
    
    $pdo->exec("ALTER TABLE cheques ADD COLUMN type ENUM('incoming', 'outgoing') DEFAULT 'incoming'");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN supplier_id INT NULL");
    $pdo->exec("ALTER TABLE cheques ADD COLUMN grn_id INT NULL");
} catch(PDOException $e) { /* Ignore if columns already exist */ }
// -------------------------------------------------------------

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['cart'])) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $po_id = !empty($input['po_id']) ? (int)$input['po_id'] : null;
    $supplier_id = !empty($input['supplier_id']) ? (int)$input['supplier_id'] : null;
    $reference_number = trim($input['reference_number']);
    $grn_date = $input['grn_date'];
    $payment_method = !empty($input['payment_method']) ? $input['payment_method'] : 'Pending';
    $created_by = $_SESSION['user_id'];
    
    $discount_amount = (float)$input['discount_amount'];
    $tax_amount = (float)$input['tax_amount'];
    $subtotal = 0;

    // 1. Calculate totals
    foreach ($input['cart'] as $item) {
        $subtotal += ((int)$item['quantity'] * (float)$item['unit_cost']);
    }

    if ($discount_amount > $subtotal) $discount_amount = $subtotal;
    $grand_total = $subtotal - $discount_amount + $tax_amount;

    // 2. Determine Payment Status
    $payment_status = in_array($payment_method, ['Pending', 'Cheque']) ? ($payment_method == 'Cheque' ? 'waiting' : 'pending') : 'paid';
    $paid_amount = ($payment_status === 'paid') ? $grand_total : 0;

    // 3. Insert GRN Parent Record
    $stmt = $pdo->prepare("INSERT INTO grns (po_id, supplier_id, reference_number, subtotal, discount_amount, tax_amount, total_amount, payment_method, payment_status, paid_amount, grn_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$po_id, $supplier_id, $reference_number, $subtotal, $discount_amount, $tax_amount, $grand_total, $payment_method, $payment_status, $paid_amount, $grn_date, $created_by]);
    $grn_id = $pdo->lastInsertId();

    // 4. Process Items, Update Stock, Update Cost Price, and Log movement
    $itemStmt = $pdo->prepare("INSERT INTO grn_items (grn_id, product_id, quantity, unit_cost) VALUES (?, ?, ?, ?)");
    $updateProdStmt = $pdo->prepare("UPDATE products SET stock = ?, selling_price = ? WHERE id = ?");
    $logStmt = $pdo->prepare("INSERT INTO stock_logs (product_id, type, reference_id, qty_change, previous_stock, new_stock, created_by) VALUES (?, 'grn_in', ?, ?, ?, ?, ?)");

    foreach ($input['cart'] as $item) {
        $product_id = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $unit_cost = (float)$item['unit_cost']; 

        $prodQuery = $pdo->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
        $prodQuery->execute([$product_id]);
        $current_stock = (int)$prodQuery->fetchColumn();

        $new_stock = $current_stock + $qty;

        $itemStmt->execute([$grn_id, $product_id, $qty, $unit_cost]);
        $updateProdStmt->execute([$new_stock, $unit_cost, $product_id]);
        $logStmt->execute([$product_id, $grn_id, $qty, $current_stock, $new_stock, $created_by]);
    }

    // 5. Update Company Finances if Paid immediately via Cash/Bank
    if ($payment_status === 'paid' && $paid_amount > 0) {
        if ($payment_method === 'Cash') {
            $pdo->prepare("UPDATE company_finances SET cash_on_hand = cash_on_hand - ? WHERE id = 1")->execute([$paid_amount]);
            $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('cash_out', ?, ?, ?)")->execute([$paid_amount, "Cash Purchase - GRN #$grn_id", $created_by]);
        } elseif ($payment_method === 'Bank Transfer') {
            $pdo->prepare("UPDATE company_finances SET bank_balance = bank_balance - ? WHERE id = 1")->execute([$paid_amount]);
            $pdo->prepare("INSERT INTO finance_logs (type, amount, description, created_by) VALUES ('bank_out', ?, ?, ?)")->execute([$paid_amount, "Bank Transfer Purchase - GRN #$grn_id", $created_by]);
        }
    }

    // 6. Insert Outgoing Cheque if applicable
    if ($payment_method === 'Cheque') {
        $cheque_bank = trim($input['cheque_bank'] ?? '');
        $cheque_number = trim($input['cheque_number'] ?? '');
        $cheque_date = $input['cheque_date'] ?? date('Y-m-d');
        
        if (!empty($cheque_bank) && !empty($cheque_number)) {
            $chkStmt = $pdo->prepare("INSERT INTO cheques (type, supplier_id, grn_id, bank_name, cheque_number, banking_date, amount, status) VALUES ('outgoing', ?, ?, ?, ?, ?, ?, 'pending')");
            $chkStmt->execute([$supplier_id, $grn_id, $cheque_bank, $cheque_number, $cheque_date, $grand_total]);
        }
    }

    // 7. If this was generated from a Purchase Order, Mark PO as Completed
    if ($po_id) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'completed' WHERE id = ?")->execute([$po_id]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'grn_id' => $grn_id, 'message' => 'GRN Processed & Stock Updated Successfully!']);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("GRN API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Processing Error: ' . $e->getMessage()]);
}
?>