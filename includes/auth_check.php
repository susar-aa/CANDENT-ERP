<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Original Role Checker (Kept for backward compatibility)
function hasRole($allowed_roles) {
    if (!isset($_SESSION['user_role'])) return false;
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    return in_array($_SESSION['user_role'], $allowed_roles);
}

function requireRole($allowed_roles) {
    if (!hasRole($allowed_roles)) {
        header("Location: unauthorized.php");
        exit;
    }
}

// NEW: Granular Permission Checker
function hasAccess($page_name) {
    global $pdo;
    
    if (!isset($_SESSION['user_role'])) return false;
    
    // Admins always have absolute access
    if ($_SESSION['user_role'] === 'admin') return true;

    // Cache permissions for the duration of the page load to prevent spamming the database
    static $user_permissions = null;
    
    if ($user_permissions === null) {
        if (!isset($pdo)) {
            require '../config/db.php'; // Ensure DB is accessible
        }
        $stmt = $pdo->prepare("SELECT permissions FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $permsJson = $stmt->fetchColumn();
        $user_permissions = json_decode($permsJson, true) ?: [];
    }

    return in_array($page_name, $user_permissions);
}

// NEW: Group Access Checker (If a user has access to at least one page in a group)
function canViewGroup($pages_array) {
    foreach ($pages_array as $page) {
        if (hasAccess($page)) return true;
    }
    return false;
}
?>