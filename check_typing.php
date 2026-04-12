<?php

require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$chat_partner_id = isset($_GET['chat_partner_id']) ? (int)$_GET['chat_partner_id'] : 0;

if (!$chat_partner_id) {
    echo json_encode(['typing' => false]);
    exit;
}

$typing_key = 'typing_' . $user_id . '_to_' . $chat_partner_id;
if (isset($_SESSION[$typing_key])) {
    $typing_data = $_SESSION[$typing_key];
    
    if (time() - $typing_data['timestamp'] < 3) {
        echo json_encode(['typing' => $typing_data['is_typing']]);
        exit;
    }
}

echo json_encode(['typing' => false]);
?>