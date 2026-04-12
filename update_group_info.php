<?php
require_once 'db.php';
requireLogin();

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
$new_name = isset($_POST['room_name']) ? trim($_POST['room_name']) : '';

if (!$room_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Room ID']);
    exit;
}


$check = $conn->prepare("SELECT role FROM chat_room_members WHERE room_id = ? AND user_id = ? AND role = 'admin'");
$check->bind_param("ii", $room_id, $user_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Only admins can update group info.']);
    exit;
}
$check->close();

$update_fields = [];
$params = [];
$types = "";


if (!empty($new_name)) {
    $update_fields[] = "room_name = ?";
    $params[] = $new_name;
    $types .= "s";
}


if (isset($_FILES['room_photo']) && $_FILES['room_photo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['room_photo'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed.']);
        exit;
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large. Max 5MB.']);
        exit;
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "group_" . $room_id . "_" . time() . "." . $ext;
    $target_path = "uploads/group_photos/" . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        $update_fields[] = "room_photo = ?";
        $params[] = $target_path;
        $types .= "s";
        
        // Also update group_photo if it exists in the user's current schema
        $update_fields[] = "group_photo = ?";
        $params[] = $target_path;
        $types .= "s";
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded photo.']);
        exit;
    }
}

if (empty($update_fields)) {
    echo json_encode(['success' => false, 'error' => 'No changes provided.']);
    exit;
}

$sql = "UPDATE chat_rooms SET " . implode(", ", $update_fields) . " WHERE id = ?";
$params[] = $room_id;
$types .= "i";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Group updated successfully',
        'room_name' => $new_name,
        'room_photo' => isset($target_path) ? $target_path : null
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to update group information.']);
}
$stmt->close();
?>
