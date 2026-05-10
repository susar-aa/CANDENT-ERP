<?php
/**
 * Fintrix - Database Backup Utility
 * Generates a full .sql dump of the database and forces a secure download.
 */
// Disable error reporting during execution to prevent corrupting the SQL file
ini_set('display_errors', 0);
error_reporting(0);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin']); // Extremely restricted to System Admins only

try {
    // 1. Get all tables
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sqlScript = "-- Fintrix Distribution Management System Database Backup\n";
    $sqlScript .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sqlScript .= "-- Server Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n\n";
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n"; // Disable FK checks during import

    // 2. Loop through tables and generate DDL and Data
    foreach ($tables as $table) {
        // Table Structure
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $createTable = $stmt->fetch(PDO::FETCH_NUM);
        $sqlScript .= "-- --------------------------------------------------------\n";
        $sqlScript .= "-- Table structure for table `$table`\n";
        $sqlScript .= "-- --------------------------------------------------------\n";
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        $sqlScript .= $createTable[1] . ";\n\n";

        // Table Data
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            $sqlScript .= "-- Dumping data for table `$table`\n";
            foreach ($rows as $row) {
                $keys = array_keys($row);
                $values = array_values($row);
                $escapedValues = array_map(function($val) use ($pdo) {
                    if ($val === null) return 'NULL';
                    return $pdo->quote($val);
                }, $values);
                
                $sqlScript .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $escapedValues) . ");\n";
            }
            $sqlScript .= "\n";
        }
    }

    $sqlScript .= "SET FOREIGN_KEY_CHECKS=1;\n"; // Re-enable FK checks

    // 3. Force Download
    $backup_file_name = 'fintrix_backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $backup_file_name . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $sqlScript;
    exit;

} catch (Exception $e) {
    die("Database Backup Failed: " . $e->getMessage());
}
?>