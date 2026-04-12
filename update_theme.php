<?php
require_once 'db.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $theme = isset($_POST['theme']) ? $_POST['theme'] : 'dark';
    
    
    if (!in_array($theme, ['dark', 'light'])) {
        $theme = 'dark';
    }
    
    $stmt = $conn->prepare("UPDATE users SET theme = ? WHERE user_id = ?");
    $stmt->bind_param("si", $theme, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['theme'] = $theme;
        echo json_encode(['success' => true, 'theme' => $theme]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit();
}
?>
