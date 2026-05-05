<?php
session_start();


$host = 'localhost';
$dbname = 'bayanlink_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isGuest() {
    return !isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// ========== NEW HELPER FUNCTIONS ==========
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        die('Access denied. Admin only.');
    }
}

function createNotification($user_id, $type, $message, $link = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$user_id, $type, $message, $link]);
}

function getUnreadNotificationCount($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

function getNotifications($user_id, $limit = 5) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)$limit);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}
?>