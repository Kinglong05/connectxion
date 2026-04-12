<?php
require_once 'db.php';
requireLogin();

ini_set('display_errors', 0);
error_reporting(0);

$user_id = $_SESSION['user_id'];
$friend_id = isset($_POST['friend_id']) ? (int)$_POST['friend_id'] : 0;
$friend_username = isset($_POST['friend_username']) ? trim($_POST['friend_username']) : '';

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($is_ajax) {
    ob_start();
}

if ($is_ajax) {
    header('Content-Type: application/json');
}

if ($friend_id > 0) {
    $check = $conn->prepare("SELECT user_id, username FROM users WHERE user_id = ?");
    $check->bind_param("i", $friend_id);
} elseif (!empty($friend_username)) {
    $check = $conn->prepare("SELECT user_id, username FROM users WHERE username = ?");
    $check->bind_param("s", $friend_username);
} else {
    $error = "User not found";
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error]);
        exit();
    } else {
        $_SESSION['friend_error'] = $error;
        header("Location: home.php");
        exit();
    }
}

$check->execute();
$result = $check->get_result();

if ($result->num_rows == 0) {
    $error = "Player does not exist";
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error]);
        exit();
    } else {
        $_SESSION['friend_error'] = $error;
        header("Location: home.php");
        exit();
    }
}

$friend_data = $result->fetch_assoc();
$friend_id = $friend_data['user_id'];
$check->close();

if ($friend_id == $user_id) {
    $error = "You cannot add yourself as a friend";
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error]);
        exit();
    } else {
        $_SESSION['friend_error'] = $error;
        header("Location: home.php");
        exit();
    }
}

$friends = $conn->prepare("
    SELECT * FROM friends 
    WHERE (user_id = ? AND friend_id = ?)
       OR (user_id = ? AND friend_id = ?)
");
$friends->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
$friends->execute();
$friends_result = $friends->get_result();

if ($friends_result->num_rows > 0) {
    $error = "You are already friends with this player";
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error]);
        exit();
    } else {
        $_SESSION['friend_error'] = $error;
        header("Location: home.php");
        exit();
    }
}
$friends->close();

$request = $conn->prepare("
    SELECT * FROM friend_requests 
    WHERE (sender_id = ? AND receiver_id = ? AND status = 'pending')
       OR (sender_id = ? AND receiver_id = ? AND status = 'pending')
");
$request->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
$request->execute();
$request_result = $request->get_result();

if ($request_result->num_rows > 0) {
    $error = "Friend request already pending";
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error]);
        exit();
    } else {
        $_SESSION['friend_error'] = $error;
        header("Location: home.php");
        exit();
    }
}
$request->close();

$column_check = $conn->query("SHOW COLUMNS FROM friend_requests LIKE 'receiver_read'");
$has_receiver_read = $column_check && $column_check->num_rows > 0;

if ($has_receiver_read) {
    $stmt = $conn->prepare("
        INSERT INTO friend_requests (sender_id, receiver_id, status, receiver_read, created_at) 
        VALUES (?, ?, 'pending', 0, NOW())
    ");
    $stmt->bind_param("ii", $user_id, $friend_id);
} else {
    $stmt = $conn->prepare("
        INSERT INTO friend_requests (sender_id, receiver_id, status, created_at) 
        VALUES (?, ?, 'pending', NOW())
    ");
    $stmt->bind_param("ii", $user_id, $friend_id);
}

if ($stmt->execute()) {
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Friend request sent successfully!']);
    } else {
        $_SESSION['friend_success'] = "Friend request sent successfully!";
        header("Location: home.php");
    }
} else {
    $error = "Failed to send friend request: " . $conn->error;
    if ($is_ajax) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error]);
    } else {
        $_SESSION['friend_error'] = $error;
        header("Location: home.php");
    }
}

$stmt->close();
exit();
?>