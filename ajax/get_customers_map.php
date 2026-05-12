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
    $sql = "SELECT id, name, address, latitude, longitude, route_id FROM customers WHERE latitude IS NOT NULL AND longitude IS NOT NULL";
    $params = [];

    if ($route_id > 0) {
        $sql .= " AND route_id = ?";
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
