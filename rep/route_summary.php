<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Step 1: PHP is running. <br>";

require_once '../config/db.php';
echo "Step 2: DB connected. <br>";

require_once '../includes/auth_check.php';
echo "Step 3: Auth loaded. <br>";

requireRole(['rep', 'admin', 'supervisor']);
echo "Step 4: Role verified. User role: " . $_SESSION['user_role'] . " <br>";

echo "Success! If you see this, the core requirements are working.";
?>