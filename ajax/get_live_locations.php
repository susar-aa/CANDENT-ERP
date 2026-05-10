<?php
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'supervisor'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get all reps with an active session today and their latest location
    $query = "
        SELECT u.id as rep_id, u.name as rep_name,
               l.latitude, l.longitude, l.timestamp, l.activity_type
        FROM rep_daily_sessions s
        JOIN users u ON s.user_id = u.id
        LEFT JOIN (
            SELECT rl1.user_id, rl1.latitude, rl1.longitude, rl1.timestamp, rl1.activity_type
            FROM rep_location_logs rl1
            INNER JOIN (
                SELECT user_id, MAX(id) as max_id
                FROM rep_location_logs
                WHERE DATE(timestamp) = CURDATE()
                GROUP BY user_id
            ) rl2 ON rl1.id = rl2.max_id
        ) l ON u.id = l.user_id
        WHERE s.session_date = CURDATE() AND s.status = 'active'
    ";
    
    $stmt = $pdo->query($query);
    $active_reps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $active_reps]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
?>
