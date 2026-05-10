<?php
/**
 * API Endpoint: Fetch suppliers mapped to a specific product that have stock available.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (isset($_GET['product_id'])) {
    $product_id = (int)$_GET['product_id'];

    $stmt = $pdo->prepare("
        SELECT ps.supplier_id, ps.price, ps.stock, s.company_name
        FROM product_suppliers ps
        JOIN suppliers s ON ps.supplier_id = s.id
        WHERE ps.product_id = ? AND ps.stock > 0
        ORDER BY ps.price ASC
    ");
    $stmt->execute([$product_id]);
    $suppliers = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $suppliers]);
} else {
    echo json_encode(['success' => false, 'error' => 'Product ID missing']);
}
?>