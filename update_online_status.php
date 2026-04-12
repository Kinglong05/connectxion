<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

$column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'last_active'");
$last_active_exists = $column_check && $column_check->num_rows > 0;

if ($last_active_exists) {
    $conn->query("UPDATE users SET last_active = NOW() WHERE user_id = $user_id");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'last_active column not found']);
}
?>