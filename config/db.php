<?php
/**
 * Fintrix - Database Connection Configuration
 * Uses PDO for secure, prepared statement interactions.
 */

// Database credentials
$host = 'localhost';
$db_name = 'fintrix_db';
$username = 'suzxlabs';
$password = 'Susara@200611003614';

// Set DSN (Data Source Name)
$dsn = "mysql:host=" . $host . ";dbname=" . $db_name . ";charset=utf8mb4";

// PDO Options for error handling and data fetching
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays by default
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Prevent SQL injection by turning off emulation
];

try {
    // Create a PDO instance
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // In a production environment on Plesk, you might want to log this to a file
    // instead of displaying it on the screen to prevent leaking DB details.
    die("Database Connection Failed: " . $e->getMessage());
}
?>