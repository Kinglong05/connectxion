<?php
require_once 'db.php';
requireLogin();

$friend_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'friends.php';

$is_ajax = isset($_GET['ajax']) || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
           strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

$allowed_redirects = ['home.php', 'friend_requests.php', 'friends.php', 'profile.php'];
$redirect_path = parse_url($referer, PHP_URL_PATH);
$redirect_file = basename($redirect_path);
$redirect_url = in_array($redirect_file, $allowed_redirects) ? $redirect_file : 'friends.php';

if (!$friend_id) {
    if ($is_ajax) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid player ID.']);
        exit();
    }
    $_SESSION['friend_error'] = "Invalid player ID.";
    header("Location: " . $redirect_url);
    exit();
}

try {
    $conn->begin_transaction();

    // Delete both rows from friends table
    $stmt = prepareAndExecute(
        $conn,
        "DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)",
        "iiii",
        $user_id, $friend_id,
        $friend_id, $user_id
    );

    // Also update friend_requests to rejected so it doesn't stay 'accepted'
    prepareAndExecute(
        $conn,
        "UPDATE friend_requests 
         SET status = 'rejected' 
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
         AND status = 'accepted'",
        "iiii",
        $user_id, $friend_id,
        $friend_id, $user_id
    );

    $conn->commit();
    logActivity("unfriend", "Unfriended user ID: " . $friend_id);
    
    if ($is_ajax) {
        echo json_encode(['status' => 'success', 'message' => 'Contender removed from your squad.']);
        exit();
    }
    
    $_SESSION['friend_success'] = "Player removed from squad.";
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Error unfriending player: " . $e->getMessage());
    
    if ($is_ajax) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to remove player. Please try again.']);
        exit();
    }
    
    $_SESSION['friend_error'] = "Failed to remove player.";
}

header("Location: " . $redirect_url);
exit();
?>
