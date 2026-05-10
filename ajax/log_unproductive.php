<?php
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS unproductive_visits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rep_id INT NOT NULL,
        customer_id INT NOT NULL,
        reason VARCHAR(255) NOT NULL,
        latitude DECIMAL(10,8) NULL,
        longitude DECIMAL(11,8) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (rep_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['customer_id']) || empty($input['reason'])) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

$rep_id = $_SESSION['user_id'];
$customer_id = (int)$input['customer_id'];
$reason = trim($input['reason']);
$latitude = isset($input['latitude']) && $input['latitude'] !== null ? (float)$input['latitude'] : null;
$longitude = isset($input['longitude']) && $input['longitude'] !== null ? (float)$input['longitude'] : null;

try {
    $stmt = $pdo->prepare("INSERT INTO unproductive_visits (rep_id, customer_id, reason, latitude, longitude) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$rep_id, $customer_id, $reason, $latitude, $longitude]);
    
    // Also log in rep_location_logs if location exists
    if ($latitude !== null && $longitude !== null) {
        $locStmt = $pdo->prepare("INSERT INTO rep_location_logs (user_id, latitude, longitude, activity_type, timestamp) VALUES (?, ?, ?, 'unproductive_visit', NOW())");
        $locStmt->execute([$rep_id, $latitude, $longitude]);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
