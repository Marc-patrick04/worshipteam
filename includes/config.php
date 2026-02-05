<?php
// Database configuration (MySQL) - Local development
$pdo = new PDO(
    "mysql:host=localhost;dbname=reverence;charset=utf8mb4",
    "root",
    ""
);

// Set PDO attributes
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Configure session for deployment
if (getenv('SESSION_SAVE_PATH')) {
    session_save_path(getenv('SESSION_SAVE_PATH'));
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function logAction($action, $details = '') {
    global $pdo;
    if (isLoggedIn()) {
        $stmt = $pdo->prepare(
            "INSERT INTO logs (user_id, action, details, created_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$_SESSION['user_id'], $action, $details]);
    }
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
?>
