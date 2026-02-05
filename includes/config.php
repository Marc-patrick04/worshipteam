<?php
// ================================
// Database configuration (PostgreSQL - Neon)
// ================================

try {
    $pdo = new PDO(
        "pgsql:host=" . getenv('DB_HOST') .
        ";port=" . getenv('DB_PORT') .
        ";dbname=" . getenv('DB_NAME') .
        ";sslmode=require",
        getenv('DB_USER'),
        getenv('DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ================================
// Session configuration (Wasmer-safe)
// ================================

// Optional custom session path (useful on some platforms)
if (getenv('SESSION_SAVE_PATH')) {
    session_save_path(getenv('SESSION_SAVE_PATH'));
}

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ================================
// Helper functions
// ================================

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
             VALUES (:user_id, :action, :details, CURRENT_TIMESTAMP)"
        );

        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':action'  => $action,
            ':details' => $details
        ]);
    }
}

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
