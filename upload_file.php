<?php
require_once 'db.php';
requireLogin();
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit();
}

$receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
$reply_to = isset($_POST['reply_to']) && !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

if (!$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid receiver']);
    exit();
}

$check_friend = $conn->prepare("
    SELECT * FROM friends 
    WHERE (user_id = ? AND friend_id = ?) 
       OR (user_id = ? AND friend_id = ?)
");
$check_friend->bind_param("iiii", $user_id, $receiver_id, $receiver_id, $user_id);
$check_friend->execute();
if ($check_friend->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'You are not friends with this user']);
    $check_friend->close();
    exit();
}
$check_friend->close();

$file = $_FILES['file'];

$allowed_types = [
    
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg',
    
    'application/pdf', 'application/msword', 
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv', 
    
    'application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed',
    
    'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/webm',
    
    'video/mp4', 'video/webm', 'video/ogg'
];

$max_size = 50 * 1024 * 1024; 

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
    ];
    $error_msg = isset($errors[$file['error']]) ? $errors[$file['error']] : 'Unknown upload error';
    echo json_encode(['success' => false, 'error' => $error_msg]);
    exit();
}

if ($file['size'] > $max_size) {
    $size_mb = round($file['size'] / (1024 * 1024), 2);
    echo json_encode(['success' => false, 'error' => "File too large (max 50MB). Your file: {$size_mb}MB"]);
    exit();
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types) && strpos($mime_type, 'image/') !== 0) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit();
}

$message_type = 'file';
if (strpos($mime_type, 'image/') === 0) {
    $message_type = 'image';
} elseif (strpos($mime_type, 'audio/') === 0) {
    $message_type = 'voice';
}

$upload_dir = 'uploads/' . date('Y/m/d');
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
        exit();
    }
}

$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '_' . time() . '.' . $extension;
$filepath = $upload_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit();
}

$message_text = '📎 ' . $file['name'];
if ($message_type === 'image') {
    $message_text = '📷 Photo';
} elseif ($message_type === 'voice') {
    $message_text = '🎤 Voice message';
}

if ($reply_to) {
    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, message_type, file_path, file_size, reply_to, read_status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW())
    ");
    $stmt->bind_param("iisssii", $user_id, $receiver_id, $message_text, $message_type, $filepath, $file['size'], $reply_to);
} else {
    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, message_type, file_path, file_size, read_status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, 'sent', NOW())
    ");
    $stmt->bind_param("iisssi", $user_id, $receiver_id, $message_text, $message_type, $filepath, $file['size']);
}

if ($stmt && $stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message_id' => $stmt->insert_id,
        'file_path' => $filepath,
        'message_type' => $message_type,
        'file_name' => $file['name'],
        'file_size' => $file['size']
    ]);
} else {
    
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    echo json_encode(['success' => false, 'error' => 'Database error: ' . ($conn->error ?? 'Unknown')]);
}

if ($stmt) $stmt->close();
?>