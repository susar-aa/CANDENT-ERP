<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    if ($_POST['ajax_action'] == 'get_vehicle_stock') {
        $rep_id = (int)$_POST['rep_id'];

        $stmt = $pdo->prepare("
            SELECT 
                vs.product_id, vs.stock_qty, vs.last_audit_date,
                p.name, p.sku 
            FROM vehicle_stock vs
            JOIN products p ON vs.product_id = p.id
            WHERE vs.rep_id = ? AND vs.stock_qty > 0
            ORDER BY p.name ASC
        ");
        $stmt->execute([$rep_id]);
        
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
}

echo json_encode([]);
