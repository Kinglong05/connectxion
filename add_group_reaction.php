<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false, 'error' => 'Invalid request']));
}

$user_id = $_SESSION['user_id'];
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$reaction = isset($_POST['reaction']) ? trim($_POST['reaction']) : '';

if (!$message_id || empty($reaction)) {
    exit(json_encode(['success' => false, 'error' => 'Missing data']));
}

// Check existing group reaction
$check = $conn->prepare("
    SELECT id FROM group_message_reactions 
    WHERE message_id = ? AND user_id = ? AND reaction = ?
");
$check->bind_param("iis", $message_id, $user_id, $reaction);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    // Toggle Off: Remove reaction
    $delete = $conn->prepare("
        DELETE FROM group_message_reactions 
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
    // Toggle On: Add reaction
    $insert = $conn->prepare("
        INSERT INTO group_message_reactions (message_id, user_id, reaction) 
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

$check->close();
