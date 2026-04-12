<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$sender_id = isset($_POST['sender_id']) ? (int)$_POST['sender_id'] : 0;

if ($sender_id) {
    $stmt = $conn->prepare("
        UPDATE messages 
        SET is_read = 1, read_status = 'read', read_at = NOW() 
        WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
    ");
    $stmt->bind_param("ii", $sender_id, $user_id);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    
    echo json_encode(['success' => true, 'count' => $count]);
} else {
    echo json_encode(['success' => false]);
}
?>