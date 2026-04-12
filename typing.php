<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$typing = isset($_POST['typing']) ? (int)$_POST['typing'] : 0;

if (!$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid receiver']);
    exit;
}

$typing_key = 'typing_' . $receiver_id;
$_SESSION[$typing_key] = [
    'user_id' => $user_id,
    'is_typing' => $typing,
    'timestamp' => time()
];

echo json_encode(['success' => true]);
?>