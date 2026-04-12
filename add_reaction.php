<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
$reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';

if (!$message_id || !$reaction) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$check = $conn->prepare("
    SELECT message_id FROM messages 
    WHERE message_id = ? AND deleted = 0
");
$check->bind_param("i", $message_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Message not found']);
    $check->close();
    exit;
}
$check->close();

$reaction_check = $conn->prepare("
    SELECT id FROM message_reactions 
    WHERE message_id = ? AND user_id = ? AND reaction = ?
");
$reaction_check->bind_param("iis", $message_id, $user_id, $reaction);
$reaction_check->execute();
$result = $reaction_check->get_result();

if ($result->num_rows > 0) {
    $delete = $conn->prepare("
        DELETE FROM message_reactions 
        WHERE message_id = ? AND user_id = ? AND reaction = ?
    ");
    $delete->bind_param("iis", $message_id, $user_id, $reaction);

    if ($delete->execute()) {
        echo json_encode(['success' => true, 'action' => 'removed']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to remove reaction']);
    }
    $delete->close();
} else {
    $insert = $conn->prepare("
        INSERT INTO message_reactions (message_id, user_id, reaction) 
        VALUES (?, ?, ?)
    ");
    $insert->bind_param("iis", $message_id, $user_id, $reaction);

    if ($insert->execute()) {
        echo json_encode(['success' => true, 'action' => 'added']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to add reaction']);
    }
    $insert->close();
}

$reaction_check->close();
?>