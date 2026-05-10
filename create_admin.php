<?php
/**
 * Run this script ONCE in your browser to fix the admin password.
 * URL: http://your-domain/fintrix/create_admin.php
 */
require_once 'config/db.php';

$email = 'admin@fintrix.com';
$password = 'admin123';

// Create a valid bcrypt hash
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    // Update the existing admin user with the correct hash
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->execute([$hashed_password, $email]);

    echo "<div style='font-family: sans-serif; padding: 20px;'>";
    echo "<h2 style='color: green;'>Success!</h2>";
    echo "<p>The password for <b>$email</b> has been successfully updated to <b>$password</b>.</p>";
    echo "<p><a href='login.php'>Click here to go to the Login Page</a></p>";
    echo "<p style='color: red; font-size: 0.9em;'>⚠️ Security Note: Please delete this <b>create_admin.php</b> file from your server after logging in.</p>";
    echo "</div>";

} catch (PDOException $e) {
    echo "Error updating password: " . $e->getMessage();
}
?>