<?php
require_once 'config/db.php';
requireAdmin();

$id = $_GET['id'] ?? 0;
$type = $_GET['type'] ?? '';

$table = '';
switch($type) {
    case 'looking_for': $table = 'requests'; break;
    case 'service': $table = 'services'; break;
    case 'event': $table = 'events'; break;
    case 'announcement': $table = 'announcements'; break;
    default: die('Invalid type');
}

$stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
$stmt->execute([$id]);
redirect('index.php');