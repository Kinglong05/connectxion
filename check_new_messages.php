<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$receiver_id = isset($_GET['receiver_id']) ? (int)$_GET['receiver_id'] : 0;
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if (!$receiver_id) {
    echo json_encode(['has_new' => false, 'count' => 0]);
    exit;
}

$stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM messages 
    WHERE ((sender_id = ? AND receiver_id = ?)
       OR (sender_id = ? AND receiver_id = ?))
       AND message_id > ?
");

if (!$stmt) {
    echo json_encode(['has_new' => false, 'count' => 0]);
    exit;
}

$stmt->bind_param("iiiii", $user_id, $receiver_id, $receiver_id, $user_id, $last_id);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'];
$stmt->close();

echo json_encode([
    'has_new' => $count > 0,
    'count' => $count
]);
?>