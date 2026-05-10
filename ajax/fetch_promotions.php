<?php
/**
 * API Endpoint: Fetches active promotion rules and lightweight product catalog 
 * for the JS Cart Engine to evaluate discounts and FOC triggers live.
 */
require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// --- CRITICAL AUTO DB MIGRATION ---
// Ensures the promotions table exists so the API doesn't crash!
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS promotions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        promo_type ENUM('percentage', 'foc'),
        target_category_id INT NULL,
        target_product_id INT NULL,
        min_amount DECIMAL(12,2) DEFAULT 0.00,
        discount_percent DECIMAL(5,2) DEFAULT 0.00,
        min_qty INT DEFAULT 0,
        free_product_id INT NULL,
        free_qty INT DEFAULT 0,
        status ENUM('active', 'inactive') DEFAULT 'active',
        start_date DATE NULL,
        end_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}
// ----------------------------------

try {
    // Fetch active promotions
    $stmt = $pdo->query("SELECT * FROM promotions WHERE status = 'active'");
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch a lightweight product map so JS knows category mappings
    $prodStmt = $pdo->query("
        SELECT p.id, p.name, p.category_id, p.stock, c.main_category_id 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.status = 'available'
    ");
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'promotions' => $promotions,
        'products' => $products
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>