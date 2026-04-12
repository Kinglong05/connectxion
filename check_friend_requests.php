<?php

require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

$column_check = $conn->query("SHOW COLUMNS FROM friend_requests LIKE 'receiver_read'");
$has_receiver_read = $column_check && $column_check->num_rows > 0;

if ($has_receiver_read) {
    
    $query = "
        SELECT COUNT(*) as count FROM friend_requests 
        WHERE receiver_id = ? AND status = 'pending' AND receiver_read = 0
    ";
} else {
    
    $query = "
        SELECT COUNT(*) as count FROM friend_requests 
        WHERE receiver_id = ? AND status = 'pending'
    ";
}

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'];
$stmt->close();

if ($has_receiver_read && $count > 0) {
    $update = $conn->prepare("
        UPDATE friend_requests 
        SET receiver_read = 1 
        WHERE receiver_id = ? AND status = 'pending' AND receiver_read = 0
    ");
    $update->bind_param("i", $user_id);
    $update->execute();
    $update->close();
}

echo json_encode([
    'has_new' => $count > 0,
    'count' => $count
]);
?>