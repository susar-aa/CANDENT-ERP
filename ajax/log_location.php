<?php
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'rep') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['latitude']) || !isset($input['longitude'])) {
    echo json_encode(['success' => false, 'message' => 'Missing coordinates']);
    exit;
}

$rep_id = $_SESSION['user_id'];
$lat = (float)$input['latitude'];
$lng = (float)$input['longitude'];
$activity = $input['activity'] ?? 'background_ping';

try {
    // Only log if they have an active session today
    $checkStmt = $pdo->prepare("SELECT id FROM rep_daily_sessions WHERE user_id = ? AND session_date = CURDATE() AND status = 'active'");
    $checkStmt->execute([$rep_id]);
    
    if ($checkStmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO rep_location_logs (user_id, latitude, longitude, activity_type, timestamp) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$rep_id, $lat, $lng, $activity]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active session']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>
