<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';

header('Content-Type: application/json');

if (!hasRole(['admin', 'supervisor'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$route_id = isset($_GET['route_id']) ? (int)$_GET['route_id'] : 0;

try {
    // Outstanding calculation logic consistent with aging reports and rep dashboard
    $sql = "
        SELECT 
            c.id, 
            c.name, 
            c.address, 
            c.latitude, 
            c.longitude, 
            c.route_id,
            COALESCE((SELECT SUM(total_amount - paid_amount) FROM orders WHERE customer_id = c.id AND order_status != 'cancelled'), 0) as outstanding
        FROM customers c 
        WHERE c.latitude IS NOT NULL AND c.longitude IS NOT NULL
    ";
    $params = [];

    if ($route_id > 0) {
        $sql .= " AND c.route_id = ?";
        $params[] = $route_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $customers]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
