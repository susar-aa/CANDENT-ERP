<?php
/**
 * API Endpoint: Analyzes Sales Velocity, FOC/Discount Claims, and Working Days for Smart PO Generation.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$supplier_id = (int)($_POST['supplier_id'] ?? 0);

if (!$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required.']);
    exit;
}

// --- 1. DETERMINE CLAIM DATE RANGE ---
// Fetch the end date of the last non-cancelled PO for this supplier
$last_claim = $pdo->prepare("SELECT MAX(claim_end_date) FROM purchase_orders WHERE supplier_id = ? AND status != 'cancelled'");
$last_claim->execute([$supplier_id]);
$last_date = $last_claim->fetchColumn();

// If a previous claim exists, start the day after. Otherwise, default to 30 days ago.
$claim_start = $last_date ? date('Y-m-d', strtotime($last_date . ' + 1 day')) : date('Y-m-d', strtotime('-30 days'));
$claim_end = date('Y-m-d'); // Ends today

// --- 2. CALCULATE ACTUAL WORKING DAYS IN RANGE ---
// Check dispatch records for actual days worked
$workDaysStmt = $pdo->prepare("SELECT COUNT(DISTINCT assign_date) FROM rep_routes WHERE assign_date >= ? AND assign_date <= ?");
$workDaysStmt->execute([$claim_start, $claim_end]);
$working_days = (int)$workDaysStmt->fetchColumn();

// Fallback: If no routes dispatched, check days where sales actually happened
if ($working_days === 0) {
    $workDaysStmt2 = $pdo->prepare("SELECT COUNT(DISTINCT DATE(created_at)) FROM orders WHERE DATE(created_at) >= ? AND DATE(created_at) <= ? AND total_amount > 0");
    $workDaysStmt2->execute([$claim_start, $claim_end]);
    $working_days = (int)$workDaysStmt2->fetchColumn();
}

if ($action == 'get_dates') {
    echo json_encode([
        'success' => true, 
        'claim_start' => $claim_start, 
        'claim_end' => $claim_end,
        'working_days' => $working_days
    ]);
    exit;
}

if ($action == 'generate') {
    $sales_period = $_POST['sales_period'] ?? '7';
    
    // Determine Sales Analysis Range
    $sales_end = date('Y-m-d');
    if ($sales_period == 'custom') {
        $sales_start = $_POST['sales_start'];
        $sales_end = $_POST['sales_end'];
    } else {
        $days = (int)$sales_period;
        $sales_start = date('Y-m-d', strtotime("-$days days"));
    }
    
    $buffer_percent = (float)($_POST['buffer_percent'] ?? 0);

    try {


        // --- 4. ANALYZE SALES VELOCITY & GENERATE SUGGESTIONS ---
        $prodStmt = $pdo->prepare("
            SELECT p.id, p.name, p.sku, p.selling_price, p.stock, 
                   COALESCE(SUM(oi.quantity), 0) as sold_qty
            FROM products p
            LEFT JOIN order_items oi ON p.id = oi.product_id 
            LEFT JOIN orders o ON oi.order_id = o.id AND DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
            WHERE p.supplier_id = ? AND p.status = 'available'
            GROUP BY p.id
            ORDER BY sold_qty DESC, p.name ASC
        ");
        $prodStmt->execute([$sales_start, $sales_end, $supplier_id]);
        $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

        $suggestions = [];
        foreach($products as $p) {
            // Formula: (Sold Qty + Buffer %) - Current Stock
            $expected_need = $p['sold_qty'] * (1 + ($buffer_percent / 100));
            $suggested = ceil($expected_need) - $p['stock'];
            
            // Never suggest negative quantities
            if ($suggested < 0) $suggested = 0;
            
            // Include it if suggested > 0, OR if stock is low anyway
            if ($suggested > 0 || $p['stock'] <= 5) {
                $p['suggested_qty'] = $suggested;
                $suggestions[] = $p;
            }
        }

        echo json_encode([
            'success' => true,

            'products' => $suggestions
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}
?>