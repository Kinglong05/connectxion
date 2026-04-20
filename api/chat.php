<?php
require_once '../db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$friend_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$friend_id) {
            header("Location: ../home.php");
    exit();
}

$result = $conn->query("SELECT * FROM users WHERE user_id = $friend_id");

if (!$result || $result->num_rows === 0) {
            header("Location: ../home.php?error=user_not_found");
    exit();
}

$friend = $result->fetch_assoc();

$user_data = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

$messages_count = 0;
$count_result = $conn->query("
    SELECT COUNT(*) as total 
    FROM messages 
    WHERE (sender_id = $user_id AND receiver_id = $friend_id)
       OR (sender_id = $friend_id AND receiver_id = $user_id)
");
if ($count_result && $count_result->num_rows > 0) {
    $messages_count = $count_result->fetch_assoc()['total'];
}

$is_online = false;
if (isset($friend['last_active']) && !empty($friend['last_active'])) {
    $last_active_time = strtotime($friend['last_active']);
    if ($last_active_time !== false) {
        $is_online = (time() - $last_active_time) < 300;
    }
}

$unread_result = $conn->query("
    SELECT COUNT(*) as count FROM messages 
    WHERE receiver_id = $user_id AND is_read = 0
");
$total_unread = $unread_result->fetch_assoc()['count'] ?? 0;

$requests_count = 0;
$requests_result = $conn->query("
    SELECT COUNT(*) as count FROM friend_requests 
    WHERE receiver_id = $user_id AND status = 'pending'
");
if ($requests_result) {
    $requests_count = $requests_result->fetch_assoc()['count'];
}

function getAvatarLetter($username) {
    return strtoupper(substr($username, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CHAT | CONNECTXION</title>

    <!-- Socket.IO Client -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/themes.css">
    <link rel="stylesheet" href="system_dialogs.css">
    <script src="system_dialogs.js" defer></script>
    <style>
        /* ========== YOUR EXISTING CSS STYLES GO HERE ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            max-width: 100%;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
            height: 100vh;
        }

        body {
            font-family: 'Segoe UI', 'Poppins', system-ui, sans-serif;
            background: #0a0c0f;
            height: 100vh;
            overflow: hidden;
            color: #e0e0e0;
        }

        }

        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
            100% { opacity: 1; transform: scale(1); }
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
            100% { transform: translateY(0px); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-10px); }
        }

        .app-container {
            display: flex;
            height: 100vh;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
            width: 100%;
        }

        .app-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(
                0deg,
                rgba(0, 0, 0, 0.15) 0px,
                rgba(0, 0, 0, 0.15) 1px,
                transparent 1px,
                transparent 2px
            );
            pointer-events: none;
            z-index: 5;
        }

        .nav-sidebar {
            width: 80px;
            flex-shrink: 0;
            background: linear-gradient(180deg, var(--bg-secondary) 0%, #0f1317 100%);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
            position: relative;
            z-index: 10;
            box-shadow: 5px 0 20px rgba(0, 0, 0, 0.5);
        }

        .nav-sidebar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--gradient-primary);
            box-shadow: var(--glow-effect);
        }

        .logo {
            width: 52px;
            height: 52px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 32px;
            position: relative;
            box-shadow: var(--glow-effect);
            animation: float 3s ease-in-out infinite;
            overflow: hidden;
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo span {
            transform: rotate(-45deg);
        }

        .nav-item {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 24px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            background: transparent;
            border: 1px solid transparent;
        }

        .nav-item:hover {
            background: var(--bg-hover);
            color: var(--accent);
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 70, 85, 0.2);
        }

        .nav-item.active {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--glow-effect);
            animation: pulse 2s infinite;
        }

        .nav-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--danger);
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
            border: 2px solid var(--bg-secondary);
            box-shadow: 0 0 10px rgba(240, 71, 71, 0.5);
        }

        .nav-footer {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 20px 0;
        }

        .avatar {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 22px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            overflow: hidden;
            border: 2px solid transparent;
        }

        .avatar:hover {
            transform: scale(1.1);
            border-color: var(--accent-secondary);
            box-shadow: 0 0 20px var(--accent-glow-secondary);
        }

        .avatar.online::before {
            content: '';
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border: 2px solid var(--bg-secondary);
            border-radius: 50%;
            z-index: 2;
        }

        .avatar.online::after {
            content: '';
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
            opacity: 0.7;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logout-btn {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: rgba(240, 71, 71, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--danger);
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid transparent;
            margin-top: 8px;
        }

        .logout-btn:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(240, 71, 71, 0.4);
            border-color: var(--danger);
        }

        .chat-container {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            position: relative;
            width: calc(100% - 80px);
        }

        .chat-header {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 10;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
        }

        .chat-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 22px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .back-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateX(-3px);
        }

        .chat-user {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .chat-avatar {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
            position: relative;
            text-transform: uppercase;
            border: 2px solid transparent;
            transition: all 0.3s;
        }

        .chat-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border: 2px solid var(--bg-secondary);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }

        .chat-user-info {
            display: flex;
            flex-direction: column;
        }

        .chat-user-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .chat-user-status {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: <?php echo $is_online ? 'var(--success)' : 'var(--text-muted)'; ?>;
            animation: <?php echo $is_online ? 'pulse 1.5s infinite' : 'none'; ?>;
        }

        .chat-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        .search-container {
            padding: 0 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .search-container.show {
            padding: 16px 24px;
            max-height: 100px;
        }

        .search-box {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.5);
        }

        .search-box:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow), inset 0 2px 5px rgba(0, 0, 0, 0.5);
        }

        .search-box span {
            color: var(--accent);
            font-size: 20px;
        }

        .search-box input {
            flex: 1;
            background: none;
            border: none;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            font-family: 'Poppins', sans-serif;
        }

        .search-box input::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }

        .search-results {
            position: absolute;
            top: 180px;
            left: 104px;
            right: 24px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            width: auto;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
        }

        .search-result-item:hover {
            background: var(--bg-hover);
            border-left: 3px solid var(--accent);
        }

        .search-result-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .search-result-info {
            flex: 1;
        }

        .search-result-name {
            color: var(--text-primary);
            font-weight: 600;
            margin-bottom: 3px;
        }

        .search-result-preview {
            color: var(--text-muted);
            font-size: 12px;
        }

        .search-result-date {
            color: var(--text-muted);
            font-size: 11px;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: var(--bg-primary);
            scroll-behavior: smooth;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .messages-container::-webkit-scrollbar {
            width: 4px;
        }

        .messages-container::-webkit-scrollbar-track {
            background: transparent;
        }

        .messages-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }

        .messages-container::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
            background-clip: padding-box;
        }

        .date-separator {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 20px 0 10px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .date-separator::before,
        .date-separator::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .message {
            max-width: 99%;
            position: relative;
            animation: fadeIn 0.2s;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .message-own {
            align-self: flex-end;
        }

        .message-other {
            align-self: flex-start;
        }

        .message-sender {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 8px;
        }

        .message-sender {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 4px;
        }

        .message-own .message-sender {
            text-align: right;
            color: var(--accent);
        }

        .message-other .message-sender {
            text-align: left;
            color: var(--accent-secondary);
        }

        .message-content-wrapper {
            display: flex;
            flex-direction: column;
            max-width: calc(100% - 44px);
            width: fit-content;
        }

        .message-own .message-content-wrapper {
            align-items: flex-end;
        }

        .message-other .message-content-wrapper {
            align-items: flex-start;
        }

        .message-wrapper {
            position: relative;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }

        .message-own .message-wrapper {
            flex-direction: row-reverse;
        }

        .message-avatar-container {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            flex-shrink: 0;
            overflow: hidden;
            background: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .message-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .message-avatar-letter {
            text-transform: uppercase;
        }

        .message-own .message-avatar-container {
            border-color: var(--accent-secondary);
        }

        .message-other .message-avatar-container {
            border-color: var(--accent);
        }

        .message-wrapper:hover .message-actions {
            opacity: 1;
            transform: translateY(0);
        }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 15px;
            line-height: 1.5;
            border: 1px solid transparent;
            transition: all 0.2s;
            width: fit-content;
            max-width: 100%;
            box-sizing: border-box;
        }

        .message-own .message-bubble {
            background: var(--message-own);
            color: var(--message-own-text);
            border-bottom-right-radius: 6px;
            border-color: rgba(255, 70, 85, 0.3);
        }

        .message-other .message-bubble {
            background: var(--message-other);
            color: var(--message-other-text);
            border-bottom-left-radius: 6px;
            border-color: var(--border);
        }

        .message-own .message-bubble:hover {
            border-color: var(--accent);
            box-shadow: -3px 0 0 var(--accent);
        }

        .message-other .message-bubble:hover {
            border-color: var(--accent-secondary);
            box-shadow: 3px 0 0 var(--accent-secondary);
        }

        .message-text {
            margin-right: 10px;
        }

        .message-image {
            max-width: 250px;
            max-height: 200px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            object-fit: cover;
        }

        .message-own .message-image {
            border-color: rgba(255, 70, 85, 0.3);
        }

        .message-other .message-image {
            border-color: var(--border);
        }

        .message-image:hover {
            transform: scale(1.05);
            border-color: var(--accent);
            box-shadow: var(--glow-effect);
        }

        .image-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .image-preview-modal.show {
            opacity: 1;
            visibility: visible;
        }

        .preview-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
        }

        .preview-content img {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 16px;
            border: 3px solid var(--accent);
            box-shadow: 0 0 50px rgba(255, 70, 85, 0.3);
        }

        .close-preview {
            position: absolute;
            top: -40px;
            right: 0;
            background: none;
            border: none;
            color: white;
            font-size: 30px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 70, 85, 0.2);
            transition: all 0.3s;
        }

        .close-preview:hover {
            background: var(--accent);
            transform: scale(1.1);
        }

        .message-file {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-tertiary);
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .message-file:hover {
            border-color: var(--accent);
            transform: translateX(5px);
        }

        .file-icon {
            font-size: 28px;
            color: var(--accent-secondary);
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-size {
            font-size: 11px;
            color: var(--text-muted);
        }

        .file-download {
            color: var(--accent);
            font-size: 20px;
            text-decoration: none;
            padding: 5px;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .file-download:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }

        /* Enhanced Voice Message Styles */
        .message-voice {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-tertiary);
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid var(--border);
            min-width: 280px;
            max-width: 100%;
            transition: all 0.3s;
        }

        .message-voice:hover {
            border-color: var(--accent);
            box-shadow: var(--glow-effect);
            transform: scale(1.02);
        }

        .voice-play {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent);
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .voice-play:hover {
            transform: scale(1.1);
            box-shadow: var(--glow-effect);
        }

        .voice-play.playing {
            background: var(--danger);
            animation: pulse 1s infinite;
        }

        .voice-wave-container {
            flex: 1;
            height: 40px;
            display: flex;
            align-items: center;
            gap: 2px;
            cursor: pointer;
            padding: 0 5px;
        }

        .voice-wave-bar {
            flex: 1;
            background: linear-gradient(to top, var(--accent), var(--accent-secondary));
            border-radius: 2px;
            min-height: 4px;
            transition: all 0.2s;
            opacity: 0.5;
        }

        .voice-wave-bar.active {
            opacity: 1;
            background: var(--accent);
            transform: scaleY(1.2);
        }

        .voice-duration {
            font-family: monospace;
            font-size: 12px;
            color: var(--text-muted);
            min-width: 45px;
            text-align: right;
        }

        .message-own .message-voice {
            background: var(--message-own);
        }

        .message-own .voice-wave-bar {
            background: linear-gradient(to top, var(--accent-secondary), var(--accent));
        }

        /* Voice Preview Styles */
        .voice-preview {
            margin-top: 15px;
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 16px;
            border: 1px solid var(--border);
            animation: slideUp 0.3s ease;
        }

        .voice-preview-content {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .voice-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-secondary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .voice-preview-header .close-preview {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 18px;
            cursor: pointer;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .voice-preview-header .close-preview:hover {
            color: var(--danger);
            transform: scale(1.2);
        }

        .voice-preview-waveform {
            padding: 10px 0;
        }

        .waveform-preview {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2px;
            height: 40px;
            background: var(--bg-tertiary);
            border-radius: 20px;
            padding: 0 10px;
        }

        .waveform-bar {
            flex: 1;
            background: linear-gradient(to top, var(--accent), var(--accent-secondary));
            border-radius: 2px;
            min-height: 4px;
            transition: height 0.2s;
        }

        .voice-preview-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .voice-play-preview {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: var(--accent);
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .voice-play-preview:hover {
            transform: scale(1.1);
            box-shadow: var(--glow-effect);
        }

        .preview-duration {
            font-family: monospace;
            font-size: 16px;
            color: var(--text-primary);
        }

        .voice-send {
            margin-left: auto;
            padding: 10px 24px;
            background: var(--gradient-primary);
            border: none;
            border-radius: 22px;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .voice-send:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        .voice-re-record {
            padding: 10px 24px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 22px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .voice-re-record:hover {
            border-color: var(--warning);
            color: var(--warning);
            transform: translateY(-2px);
        }

        .message-actions {
            position: absolute;
            bottom: -25px;
            display: flex;
            gap: 6px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 25px;
            padding: 6px;
            opacity: 0;
            transform: translateY(5px);
            transition: all 0.2s;
            z-index: 100;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
        }

        .message-own .message-actions {
            right: 0;
        }

        .message-other .message-actions {
            left: 0;
        }

        .msg-action {
            width: 36px;
            height: 36px;
            border-radius: 18px;
            border: none;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .msg-action:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }

        .msg-action.delete:hover {
            background: var(--danger);
        }

        .msg-action.react:hover {
            background: var(--accent-secondary);
        }

        .message-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-muted);
            width: 100%;
            padding: 0 8px;
        }

        .message-own .message-meta {
            justify-content: flex-end;
        }

        .message-other .message-meta {
            justify-content: flex-start;
        }

        .message-time {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .message-status {
            color: var(--accent);
        }

        .message-status.read {
            color: var(--success);
        }

        .edited-indicator {
            font-size: 10px;
            color: var(--text-muted);
            margin-left: 4px;
            font-style: italic;
        }

        .message-reactions {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
            width: 100%;
            padding: 0 8px;
        }

        .message-own .message-reactions {
            justify-content: flex-end;
        }

        .message-other .message-reactions {
            justify-content: flex-start;
        }

        .reaction {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 12px;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .reaction:hover {
            background: var(--accent-light);
            border-color: var(--accent);
            transform: scale(1.05);
        }

        .reaction-count {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 600;
        }

        /* Improved Reaction Picker Positioning */
        .reaction-picker {
            position: fixed;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 40px;
            padding: 10px 15px;
            display: flex;
            gap: 10px;
            z-index: 10000;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7), 0 0 0 1px var(--accent-glow);
            backdrop-filter: blur(10px);
            animation: slideUp 0.2s ease;
        }

        .reaction-emoji {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            border: none;
            background: var(--bg-tertiary);
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
        }

        .reaction-emoji:hover {
            background: var(--accent);
            transform: scale(1.2) translateY(-5px);
            border-color: white;
            box-shadow: 0 5px 15px var(--accent-glow);
        }

        .reply-indicator {
            background: var(--bg-tertiary);
            border-left: 3px solid var(--accent);
            padding: 8px 12px;
            border-radius: 12px;
            margin-bottom: 6px;
            font-size: 12px;
        }

        .reply-sender {
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 2px;
            font-size: 11px;
            text-transform: uppercase;
        }

        .reply-content {
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
            font-size: 12px;
        }

        .reply-preview {
            background: var(--bg-tertiary);
            border-left: 3px solid var(--accent);
            padding: 12px 16px;
            margin: 0 24px 12px 24px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideUp 0.2s;
        }

        .reply-preview-content {
            flex: 1;
        }

        .reply-preview-header {
            color: var(--accent);
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 3px;
            text-transform: uppercase;
        }

        .reply-preview-text {
            color: var(--text-secondary);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 400px;
        }

        .cancel-reply {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 20px;
            cursor: pointer;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .cancel-reply:hover {
            color: var(--danger);
            transform: scale(1.2);
        }

        .chat-input-area {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            width: 100%;
            box-sizing: border-box;
        }

        .chat-form {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 4px 4px 4px 20px;
            transition: all 0.3s;
            width: 100%;
            box-sizing: border-box;
        }

        .chat-form:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .chat-form input[type="text"] {
            flex: 1;
            min-width: 0;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 15px;
            padding: 14px 0;
            outline: none;
            font-family: 'Poppins', sans-serif;
        }

        .chat-form input[type="text"]:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .chat-form input[type="text"]::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }

        .input-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }

        .input-btn {
            width: 46px;
            height: 46px;
            border-radius: 23px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 22px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .input-btn:hover {
            background: var(--accent-light);
            color: var(--accent);
            transform: scale(1.1);
        }

        .input-btn.recording {
            background: var(--danger);
            color: white;
            animation: pulse 1s infinite;
        }

        .send-btn {
            width: 46px;
            height: 46px;
            border-radius: 23px;
            border: none;
            background: var(--gradient-primary);
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-effect);
            flex-shrink: 0;
        }

        .send-btn:hover:not(:disabled) {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        .voice-recording-indicator {
            margin-top: 10px;
            padding: 15px 20px;
            background: var(--bg-tertiary);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--danger);
            animation: pulse 2s infinite;
        }

        .voice-recording-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .voice-recording-left span:first-child {
            color: var(--danger);
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .voice-timer {
            font-family: monospace;
            font-size: 20px;
            color: var(--text-primary);
            background: var(--bg-card);
            padding: 5px 15px;
            border-radius: 20px;
            border: 1px solid var(--border);
        }

        .voice-stop-btn {
            background: var(--danger);
            border: none;
            color: white;
            padding: 10px 25px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            border: 1px solid transparent;
        }

        .voice-stop-btn:hover {
            background: transparent;
            border-color: var(--danger);
            color: var(--danger);
            transform: scale(1.05);
        }

        .typing-indicator {
            display: flex;
            gap: 6px;
            padding: 12px 20px;
            background: var(--message-other);
            border-radius: 20px;
            border-bottom-left-radius: 6px;
            width: fit-content;
            margin: 0 24px 12px 24px;
            border: 1px solid var(--border);
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        .scroll-bottom {
            position: fixed;
            bottom: 100px;
            right: 30px;
            width: 48px;
            height: 48px;
            border-radius: 24px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: var(--glow-effect);
            transition: all 0.3s;
            z-index: 100;
            animation: float 2s infinite;
        }

        .scroll-bottom:hover {
            transform: scale(1.1);
        }

        .scroll-bottom.visible {
            display: flex;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px;
            padding: 35px;
            max-width: 450px;
            width: 90%;
            transform: translateY(20px) scale(0.95);
            transition: all 0.3s;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
        }

        .modal.show .modal-content {
            transform: translateY(0) scale(1);
        }

        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--gradient-primary);
        }

        .modal-content h3 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .modal-content p {
            color: var(--text-secondary);
            margin-bottom: 25px;
            font-size: 14px;
        }

        .edit-message-input {
            width: 100%;
            padding: 16px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 16px;
            color: var(--text-primary);
            font-size: 15px;
            margin-bottom: 20px;
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
        }

        .edit-message-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .modal-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Player Info Modal Specifics */
        .player-info-modal {
            max-width: 400px;
        }

        .player-info-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .player-modal-avatar {
            width: 80px;
            height: 80px;
            border-radius: 20px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            color: white;
            overflow: hidden;
            box-shadow: var(--glow-effect);
        }

        .player-modal-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .player-modal-title h3 {
            margin: 0;
            margin-bottom: 5px;
        }

        .player-modal-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            letter-spacing: 1px;
        }

        .player-modal-status .status-dot {
            width: 10px;
            height: 10px;
            background: var(--text-muted);
            border-radius: 50%;
        }

        .player-modal-status .status-dot.online {
            background: var(--success);
            box-shadow: 0 0 10px var(--success);
        }

        .player-info-body {
            margin-bottom: 30px;
        }

        .info-section {
            margin-bottom: 20px;
        }

        .info-section label, .info-item label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        .info-text {
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.6;
            background: rgba(255, 255, 255, 0.05);
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
        }

        .modal-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }

        .modal-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .modal-btn:hover::before {
            left: 100%;
        }

        .modal-btn.primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--glow-effect);
        }

        .modal-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        .modal-btn.secondary {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border);
        }

        .modal-btn.secondary:hover {
            background: var(--bg-hover);
            border-color: var(--accent);
        }

        .modal-btn.danger {
            background: var(--danger);
            color: white;
        }

        .modal-btn.danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(240, 71, 71, 0.4);
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 16px 24px;
            border-radius: 14px;
            z-index: 2000;
            animation: slideInRight 0.3s;
            border-left: 4px solid var(--accent);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .toast.success {
            border-left-color: var(--success);
        }

        .toast.error {
            border-left-color: var(--danger);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .app-container {
                flex-direction: column-reverse;
            }
            .nav-sidebar {
                width: 100%;
                height: 60px;
                flex-direction: row;
                padding: 0 8px;
                justify-content: space-around;
                border-right: none;
                border-top: 1px solid var(--border);
                box-shadow: 0 -5px 20px rgba(0, 0, 0, 0.5);
                flex-shrink: 0;
            }
            .nav-sidebar::after {
                height: 2px;
                width: 100%;
                top: 0;
            }
            .logo {
                display: none;
            }
            .nav-footer {
                margin: 0;
                padding: 0;
                flex-direction: row;
                width: auto;
                gap: 10px;
            }
            .nav-item {
                margin-bottom: 0;
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
            .avatar {
                width: 40px;
                height: 40px;
            }
            .logout-btn {
                width: 40px;
                height: 40px;
                margin-top: 0;
                font-size: 20px;
            }
            .chat-container {
                height: calc(100vh - 60px);
            }

            .chat-header {
                padding: 10px 15px;
            }

            .chat-header-left {
                gap: 10px;
                max-width: 60%;
            }

            .chat-user-name {
                font-size: 16px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 120px;
            }

            .chat-actions {
                gap: 6px;
                flex-shrink: 0;
            }

            .action-btn {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }

            .back-btn {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                font-size: 18px;
            }

            .chat-input-area {
                padding: 10px 15px;
            }

            .chat-form {
                gap: 8px;
                padding-left: 15px;
            }

            .input-btn, .send-btn {
                width: 38px;
                height: 38px;
                font-size: 18px;
            }

            .messages-container {
                padding: 15px;
                gap: 12px;
            }

            .message-sender {
                padding: 0 4px;
                margin-bottom: 2px;
            }

            .message-own .message-sender {
                padding-right: 44px;
            }

            .message-other .message-sender {
                padding-left: 44px;
            }

            .scroll-bottom {
                bottom: 80px;
                right: 15px;
                width: 45px;
                height: 45px;
            }

            .nav-footer .avatar {
                width: 40px;
                height: 40px;
                border-radius: 10px;
            }

            .nav-footer .avatar.online::before,
            .nav-footer .avatar.online::after {
                width: 10px;
                height: 10px;
                bottom: 2px;
                right: 2px;
            }

            /* Responsive Player Info Modal */
            .player-info-modal {
                width: 95%;
                max-width: none;
                padding: 20px;
            }

            .player-info-header {
                gap: 12px;
                margin-bottom: 15px;
                padding-bottom: 12px;
            }

            .player-modal-avatar {
                width: 60px;
                height: 60px;
                font-size: 22px;
                border-radius: 14px;
            }

            .player-modal-title h3 {
                font-size: 18px;
            }

            .player-info-body {
                margin-bottom: 15px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .info-text {
                padding: 8px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .chat-user-name {
                max-width: 90px;
                font-size: 14px;
            }
            .chat-user-status {
                font-size: 11px;
            }
            .chat-header {
                padding: 8px 10px;
            }
            .input-btn, .send-btn {
                width: 34px;
                height: 34px;
                font-size: 16px;
            }
            .messages-container {
                padding: 10px;
            }
        }

    </style>
    <!-- Global Mobile Responsive Overrides -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/responsive.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/loading.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/themes.css">
</head>
<body>
    <div class="app-container">
        <canvas id="bg-canvas"></canvas>
        <div class="nav-sidebar">
            <div class="logo">
                <img src="photos/logo.png" alt="CONNECTXION">
            </div>
            
            <div class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'home.php' ? 'active' : ''; ?>" title="CHAT HUB" onclick="window.location.href='home.php'">
                💬
                <?php if ($total_unread > 0): ?>
                <span class="nav-badge"><?php echo min($total_unread, 99); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="nav-item" title="SQUAD" onclick="window.location.href='friends.php'">
                👥
                <?php if ($requests_count > 0): ?>
                <span class="nav-badge"><?php echo $requests_count; ?></span>
                <?php endif; ?>
            </div>

            <div class="nav-item" title="GROUPS" onclick="window.location.href='groups.php'">
                👪
            </div>
            
            <div class="nav-item" title="SETTINGS" onclick="window.location.href='settings.php'">
                ⚙️
            </div>
            
            <div class="nav-footer">
                <div class="avatar <?php echo (isset($user_data['last_active']) && !empty($user_data['last_active']) && (time() - strtotime($user_data['last_active']) < 300)) ? 'online' : ''; ?>" onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo $user_data['avatar']; ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user_data['username']); ?>
                    <?php endif; ?>
                </div>
                
                <div class="logout-btn" title="LOGOUT" onclick="showLogoutModal()">
                    ⏻
                </div>
            </div>
        </div>
        
        <div class="chat-container">
            <div class="chat-header">
                <div class="chat-header-left">
                    <button class="back-btn" onclick="window.location.href='home.php'" title="BACK TO HUB">←</button>
                    <div class="chat-user">
                        <div class="chat-avatar <?php echo $is_online ? 'online' : ''; ?>">
                            <?php if (!empty($friend['avatar']) && file_exists($friend['avatar'])): ?>
                                <img src="<?php echo $friend['avatar']; ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit;">
                            <?php else: ?>
                                <?php echo getAvatarLetter($friend['username']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-user-info">
                            <span class="chat-user-name"><?php echo htmlspecialchars($friend['username']); ?></span>
                            <span class="chat-user-status">
                                <span class="status-dot"></span>
                                <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="chat-actions">
                    <button class="action-btn" onclick="toggleSearch()" title="SEARCH MESSAGES">🔍</button>
                    <button class="action-btn" onclick="showUserInfo()" title="PLAYER INFO">ℹ️</button>
                </div>
            </div>
            
            <div class="search-container" id="searchContainer">
                <div class="search-box">
                    <span>🔍</span>
                    <input type="text" id="searchInput" placeholder="SEARCH MESSAGES..." onkeyup="searchMessages(this.value)">
                </div>
            </div>
            
            <div class="search-results" id="searchResults"></div>
            
            <div class="messages-container" id="messagesContainer">
                <!-- Messages will be loaded here -->
            </div>
            
            <div class="typing-indicator" id="typingIndicator" style="display: none;">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
            
            <div class="reply-preview" id="replyPreview" style="display: none;">
                <div class="reply-preview-content">
                    <div class="reply-preview-header" id="replyPreviewHeader">REPLYING TO</div>
                    <div class="reply-preview-text" id="replyPreviewText">Message preview</div>
                </div>
                <button class="cancel-reply" onclick="cancelReply()">✕</button>
            </div>
            
            <div class="chat-input-area">
                <form class="chat-form" id="sendForm">
                    <input type="hidden" name="receiver_id" value="<?php echo $friend_id; ?>">
                    <input type="hidden" name="reply_to" id="replyToInput">
                    <input type="text" name="message" id="messageInput" placeholder="TYPE YOUR MESSAGE..." autocomplete="off">
                    
                    <div class="input-actions">
                        <button type="button" class="input-btn" onclick="showEmojiPicker()" title="EMOJI">😊</button>
                        <button type="button" class="input-btn" onclick="showAttachMenu()" title="ATTACH">📎</button>
                        <button type="button" class="input-btn" id="voiceBtn" onclick="toggleVoiceRecording()" title="VOICE MESSAGE">🎤</button>
                        <button type="submit" class="send-btn" id="sendBtn">➤</button>
                    </div>
                </form>
                
                <div id="voiceRecordingIndicator" class="voice-recording-indicator" style="display: none;">
                    <div class="voice-recording-left">
                        <span>🔴 RECORDING...</span>
                        <span class="voice-timer" id="voiceTimer">00:00</span>
                    </div>
                    <button type="button" class="voice-stop-btn" onclick="stopVoiceRecording()">STOP</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal" id="emojiModal">
        <div class="modal-content" style="max-width: 400px;">
            <h3>CHOOSE EMOJI</h3>
            <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin: 25px 0;">
                <button class="reaction-emoji" onclick="insertEmoji('😊')">😊</button>
                <button class="reaction-emoji" onclick="insertEmoji('😂')">😂</button>
                <button class="reaction-emoji" onclick="insertEmoji('❤️')">❤️</button>
                <button class="reaction-emoji" onclick="insertEmoji('👍')">👍</button>
                <button class="reaction-emoji" onclick="insertEmoji('😢')">😢</button>
                <button class="reaction-emoji" onclick="insertEmoji('🎉')">🎉</button>
                <button class="reaction-emoji" onclick="insertEmoji('😍')">😍</button>
                <button class="reaction-emoji" onclick="insertEmoji('🔥')">🔥</button>
                <button class="reaction-emoji" onclick="insertEmoji('✨')">✨</button>
                <button class="reaction-emoji" onclick="insertEmoji('⭐')">⭐</button>
                <button class="reaction-emoji" onclick="insertEmoji('🍕')">🍕</button>
                <button class="reaction-emoji" onclick="insertEmoji('🎮')">🎮</button>
                <button class="reaction-emoji" onclick="insertEmoji('😎')">😎</button>
                <button class="reaction-emoji" onclick="insertEmoji('🥺')">🥺</button>
                <button class="reaction-emoji" onclick="insertEmoji('😡')">😡</button>
                <button class="reaction-emoji" onclick="insertEmoji('💀')">💀</button>
                <button class="reaction-emoji" onclick="insertEmoji('✅')">✅</button>
                <button class="reaction-emoji" onclick="insertEmoji('❌')">❌</button>
            </div>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideEmojiPicker()">CLOSE</button>
            </div>
        </div>
    </div>
    
    <div class="modal" id="editModal">
        <div class="modal-content">
            <h3>EDIT MESSAGE</h3>
            <textarea class="edit-message-input" id="editMessageInput" placeholder="Edit your message..."></textarea>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideEditModal()">CANCEL</button>
                <button class="modal-btn primary" onclick="saveEdit()">SAVE</button>
            </div>
        </div>
    </div>
    
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <h3>DELETE MESSAGE</h3>
            <p>Are you sure you want to delete this message? This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideDeleteModal()">CANCEL</button>
                <button class="modal-btn danger" onclick="confirmDelete()">DELETE</button>
            </div>
        </div>
    </div>
    
    <div class="image-preview-modal" id="imagePreviewModal">
        <div class="preview-content">
            <button class="close-preview" onclick="hideImagePreview()">✕</button>
            <img id="previewImage" src="" alt="Preview">
        </div>
    </div>
    
    <div class="modal" id="logoutModal">
        <div class="modal-content">
            <h3>EXIT GAME?</h3>
            <p>Are you sure you want to logout?</p>
            <div class="modal-actions">
                <button type="button" class="modal-btn secondary" onclick="hideLogoutModal()">CANCEL</button>
                <button type="button" class="modal-btn danger" onclick="confirmLogout()">LOGOUT</button>
            </div>
        </div>
    </div>
    
    <!-- User Info Modal -->
    <div class="modal" id="userInfoModal">
        <div class="modal-content player-info-modal">
            <div class="player-info-header">
                <div class="player-modal-avatar">
                   <?php if (!empty($friend['avatar']) && file_exists($friend['avatar'])): ?>
                        <img src="<?php echo $friend['avatar']; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo getAvatarLetter($friend['username']); ?>
                    <?php endif; ?>
                </div>
                <div class="player-modal-title">
                    <h3><?php echo htmlspecialchars($friend['username']); ?></h3>
                    <div class="player-modal-status">
                        <span class="status-dot <?php echo $is_online ? 'online' : ''; ?>"></span>
                        <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                    </div>
                </div>
            </div>

            <div class="player-info-body">
                <div class="info-section">
                    <label>📝 BIO / DESCRIPTION</label>
                    <div class="info-text"><?php echo nl2br(htmlspecialchars($friend['bio'] ?? 'No bio set.')); ?></div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <label>📅 JOINED</label>
                        <div class="info-text"><?php echo date('M Y', strtotime($friend['created_at'])); ?></div>
                    </div>
                    <div class="info-item">
                        <label>🆔 PLAYER ID</label>
                    <div class="info-text">#<?php echo $friend_id; ?></div>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="modal-btn secondary" onclick="hideUserInfo()">CLOSE</button>
            </div>
        </div>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-container">
            <div class="loading-logo-wrapper">
                <div class="tech-ring outer"></div>
                <div class="tech-ring middle"></div>
                <div class="tech-ring inner"></div>
                <div class="loading-logo">
                    <img src="photos/logo.png" alt="Logo">
                </div>
            </div>
            <div class="loading-info">
                <div class="loading-text" id="loadingText">INITIALIZING PLAYER SESSION...</div>
                <div class="loading-bar">
                    <div class="loading-bar-inner"></div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="logout.php" id="logoutForm" style="display: none;">
        <input type="hidden" name="logout" value="1">
    </form>
    
    <form id="fileUploadForm" style="display: none;">
        <input type="file" id="fileInput" name="file" onchange="uploadFile(this)">
    </form>
    
    <button class="scroll-bottom" id="scrollBottomBtn" onclick="scrollToBottom()">↓</button>
    
    <audio id="voicePlayer" style="display: none;"></audio>
    
    <script>
    // ============================================
    // REAL-TIME CHAT WITH SOCKET.IO
    // ============================================

    let socket;
    let socketConnected = false;
    const userId = <?php echo $_SESSION['user_id']; ?>;
    const friendId = <?php echo $friend_id; ?>;
    const userAvatar = "<?php echo !empty($user_data['avatar']) && file_exists($user_data['avatar']) ? $user_data['avatar'] : ''; ?>";
    const friendAvatar = "<?php echo !empty($friend['avatar']) && file_exists($friend['avatar']) ? $friend['avatar'] : ''; ?>";
    const userName = "<?php echo addslashes($user_data['username']); ?>";
    const friendName = "<?php echo addslashes($friend['username']); ?>";

    // Connect to real-time messaging server
    function connectSocket() {
        // Connect to your Node.js server using the configured SOCKET_URL
        socket = io('<?php echo SOCKET_URL; ?>');
        
        socket.on('connect', () => {
            console.log('Connected to real-time server');
            socketConnected = true;
            
            // Join a room based on user ID for private messages
            socket.emit('join-room', `user_${userId}`);
            
            // Also join room for this specific chat
            socket.emit('join-room', `chat_${userId}_${friendId}`);
        });
        
        socket.on('disconnect', () => {
            console.log('Disconnected from real-time server');
            socketConnected = false;
        });
        
        // Listen for new messages
        socket.on('new-message', (data) => {
            console.log('New message received:', data);
            
            // Check if this message is for current chat
            if (data.sender_id == friendId && data.receiver_id == userId) {
                // Add message to chat without reloading
                addMessageToChat(data);
                
                // Mark as read
                markMessagesAsRead(friendId);
                
                // Play notification sound (optional)
                playNotificationSound();
            } else {
                // Update unread badge in sidebar
                updateUnreadBadge(data.sender_id);
            }
        });
        
        // Listen for message status updates (delivered, read)
        socket.on('message-status', (data) => {
            if (data.message_id) {
                updateMessageStatus(data.message_id, data.status);
            }
        });
        
        // Listen for typing indicators
        socket.on('typing', (data) => {
            if (data.user_id == friendId) {
                showTypingIndicator(data.typing);
            }
        });
    }

    // Send message via Socket.IO (in addition to AJAX)
    function sendRealTimeMessage(message, messageId) {
        if (socketConnected) {
            socket.emit('send-message', {
                message_id: messageId,
                sender_id: userId,
                receiver_id: friendId,
                message: message,
                timestamp: new Date().toISOString()
            });
        }
    }

    // Add message to chat without page reload
    function addMessageToChat(message) {
        const container = document.getElementById('messagesContainer');
        const isOwn = (message.sender_id == userId);
        const messageClass = isOwn ? 'message-own' : 'message-other';
        
        // Use the injected avatar paths
        const avatarPath = isOwn ? userAvatar : friendAvatar;
        const senderName = isOwn ? userName : (message.sender_name || friendName);
        const senderInitial = senderName.charAt(0).toUpperCase();
        
        const avatarHtml = `
            <div class="message-avatar-container">
                ${avatarPath ? `<img src="${avatarPath}" class="message-avatar-img" alt="Avatar">` : `<div class="message-avatar-letter">${senderInitial}</div>`}
            </div>
        `;
        
        const messageHtml = `
            <div class="message ${messageClass}" data-id="${message.message_id}">
                <div class="message-wrapper">
                    ${avatarHtml}
                    <div class="message-content-wrapper">
                        <div class="message-sender">${isOwn ? 'You' : senderName}</div>
                        <div class="message-bubble">
                            <span class="message-text">${escapeHtml(message.message)}</span>
                        </div>
                        <div class="message-meta">
                            <span class="message-time">${message.time || new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</span>
                            ${isOwn ? '<span class="message-status">✓</span>' : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', messageHtml);
        scrollToBottom();
        if (typeof attachMessageEvents === 'function') attachMessageEvents();
    }

    // Typing indicator
    let typingTimeout;
    let isCurrentlyTyping = false;

    function sendTypingStatus(isTyping) {
        if (socketConnected) {
            socket.emit('typing', {
                user_id: userId,
                receiver_id: friendId,
                typing: isTyping
            });
        }
    }

    function showTypingIndicator(typing) {
        const indicator = document.getElementById('typingIndicator');
        if (typing) {
            indicator.style.display = 'flex';
        } else {
            indicator.style.display = 'none';
        }
    }

    // Update message status (sent, delivered, read)
    function updateMessageStatus(messageId, status) {
        const messageElement = document.querySelector(`.message[data-id="${messageId}"] .message-status`);
        if (messageElement) {
            if (status == 'read') {
                messageElement.innerHTML = '✓✓';
                messageElement.classList.add('read');
            } else if (status == 'delivered') {
                messageElement.innerHTML = '✓✓';
            }
        }
    }

    // Play notification sound
    function playNotificationSound() {
        // Optional: add a beep sound
        try {
            const audio = new Audio('sounds/notification.mp3');
            audio.play().catch(e => console.log('Sound not played'));
        } catch(e) {}
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Mark messages as read
    function markMessagesAsRead(senderId) {
        fetch('update_read_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `sender_id=${senderId}`
        });
        
        // Notify via socket that messages are read
        if (socketConnected) {
            socket.emit('messages-read', {
                reader_id: userId,
                sender_id: senderId
            });
        }
    }

    // Update unread badge in sidebar
    function updateUnreadBadge(senderId) {
        const badge = document.querySelector('.nav-badge');
        if (badge) {
            let currentCount = parseInt(badge.textContent) || 0;
            badge.textContent = currentCount + 1;
        }
    }

    // Initialize Socket.IO connection
    connectSocket();

    // ============================================
    // SIMPLE MESSAGE SENDING - FIXED
    // ============================================

    // Single form submit handler - THIS IS THE FIXED VERSION
    document.getElementById('sendForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const input = document.getElementById('messageInput');
        const message = input.value.trim();
        
        console.log('Send button clicked', { message, friendId });
        
        if (!message) {
            console.log('Message is empty');
            return;
        }
        
        const sendBtn = document.getElementById('sendBtn');
        sendBtn.disabled = true;
        
        const formData = new FormData(this);
        console.log('Form data:', Array.from(formData.entries()));
        
        try {
            console.log('Sending to send.php...');
            const response = await fetch('send.php', {
                method: 'POST',
                body: formData
            });
            
            console.log('Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const text = await response.text();
            console.log('Raw response:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Response is not JSON:', text);
                throw new Error('Server returned invalid response');
            }
            
            if (data.success) {
                input.value = '';
                cancelReply();
                
                // Send real-time notification
                if (socketConnected) {
                    sendRealTimeMessage(message, data.message_id);
                }
                
                // Reload messages
                loadMessages(true);
                showToast('✅ MESSAGE SENT', 'success');
            } else {
                showToast('❌ ' + (data.error || 'Failed to send message'), 'error');
            }
        } catch (err) {
            console.error('Error sending message:', err);
            showToast('❌ Failed to send message: ' + err.message, 'error');
        } finally {
            sendBtn.disabled = false;
        }
    });

    // Typing event on input
    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.addEventListener('input', function() {
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = !this.value.trim();
            
            if (!isCurrentlyTyping && this.value.trim()) {
                isCurrentlyTyping = true;
                sendTypingStatus(true);
            }
            
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                if (isCurrentlyTyping) {
                    isCurrentlyTyping = false;
                    sendTypingStatus(false);
                }
            }, 2000);
        });
    }

    // ============================================
    // VOICE MESSAGE RECORDING
    // ============================================

    let mediaRecorder;
    let audioChunks = [];
    let recordingStartTime;
    let recordingTimer;
    let isRecording = false;
    let audioContext;
    let analyser;
    let mediaStream;
    let waveformData = [];
    let waveformInterval;
    let recordedBlob = null;

    // Check if browser supports voice recording
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        console.log('Voice recording not supported');
        const voiceBtn = document.getElementById('voiceBtn');
        if (voiceBtn) voiceBtn.style.display = 'none';
    }

    async function toggleVoiceRecording() {
        if (isRecording) {
            stopVoiceRecording();
        } else {
            await startVoiceRecording();
        }
    }

    async function startVoiceRecording() {
        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    channelCount: 1,
                    sampleRate: 48000,
                    echoCancellation: true,
                    noiseSuppression: true
                } 
            });
            
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 256;
            
            const source = audioContext.createMediaStreamSource(mediaStream);
            source.connect(analyser);
            
            waveformData = [];
            
            const bufferLength = analyser.frequencyBinCount;
            const dataArray = new Uint8Array(bufferLength);
            
            waveformInterval = setInterval(() => {
                analyser.getByteFrequencyData(dataArray);
                
                let sum = 0;
                for (let i = 0; i < bufferLength; i++) {
                    sum += dataArray[i];
                }
                const average = sum / bufferLength;
                
                const normalized = Math.min(100, Math.round((average / 255) * 100));
                waveformData.push(normalized);
                
                if (waveformData.length > 100) {
                    waveformData = waveformData.slice(-100);
                }
            }, 100);
            
            const mimeTypes = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4',
                ''
            ];
            
            let options = {};
            for (let type of mimeTypes) {
                if (MediaRecorder.isTypeSupported(type)) {
                    options = { mimeType: type };
                    break;
                }
            }
            
            mediaRecorder = new MediaRecorder(mediaStream, options);
            audioChunks = [];
            
            mediaRecorder.ondataavailable = event => {
                if (event.data.size > 0) {
                    audioChunks.push(event.data);
                }
            };
            
            mediaRecorder.onstop = () => {
                recordedBlob = new Blob(audioChunks, { type: options.mimeType || 'audio/webm' });
                
                clearInterval(waveformInterval);
                
                showVoicePreview(recordedBlob, waveformData);
                
                mediaStream.getTracks().forEach(track => track.stop());
                if (audioContext) {
                    audioContext.close();
                }
            };
            
            mediaRecorder.start();
            isRecording = true;
            
            document.getElementById('voiceBtn').classList.add('recording');
            document.getElementById('voiceRecordingIndicator').style.display = 'flex';
            document.getElementById('messageInput').disabled = true;
            document.getElementById('sendBtn').disabled = true;
            
            recordingStartTime = Date.now();
            recordingTimer = setInterval(updateVoiceTimer, 1000);
            
            showToast('🎤 RECORDING... Speak now', 'info');
            
        } catch (err) {
            console.error('Error accessing microphone:', err);
            showToast('❌ Could not access microphone. Please check permissions.', 'error');
        }
    }

    function stopVoiceRecording() {
        if (mediaRecorder && isRecording) {
            mediaRecorder.stop();
            isRecording = false;
            
            document.getElementById('voiceBtn').classList.remove('recording');
            document.getElementById('voiceRecordingIndicator').style.display = 'none';
            document.getElementById('messageInput').disabled = false;
            
            clearInterval(recordingTimer);
        }
    }

    function updateVoiceTimer() {
        const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        document.getElementById('voiceTimer').textContent = 
            String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        
        if (elapsed >= 120) {
            stopVoiceRecording();
            showToast('⏱️ Maximum recording time reached (2 minutes)', 'info');
        }
    }

    function showVoicePreview(audioBlob, waveform) {
        let previewContainer = document.getElementById('voicePreview');
        if (!previewContainer) {
            previewContainer = document.createElement('div');
            previewContainer.id = 'voicePreview';
            previewContainer.className = 'voice-preview';
            document.querySelector('.chat-input-area').appendChild(previewContainer);
        }
        
        const audioUrl = URL.createObjectURL(audioBlob);
        
        let waveformHtml = '';
        if (waveform && waveform.length > 0) {
            waveformHtml = '<div class="waveform-preview">';
            for (let i = 0; i < waveform.length; i += 2) {
                const height = Math.max(4, Math.min(40, waveform[i] / 2.5));
                waveformHtml += `<div class="waveform-bar" style="height: ${height}px;"></div>`;
            }
            waveformHtml += '</div>';
        }
        
        const duration = Math.floor((Date.now() - recordingStartTime) / 1000);
        const minutes = Math.floor(duration / 60);
        const seconds = duration % 60;
        
        previewContainer.innerHTML = `
            <div class="voice-preview-content">
                <div class="voice-preview-header">
                    <span>🎤 VOICE MESSAGE PREVIEW</span>
                    <button class="close-preview" onclick="cancelVoicePreview()">✕</button>
                </div>
                <div class="voice-preview-waveform">
                    ${waveformHtml}
                </div>
                <div class="voice-preview-controls">
                    <button class="voice-play-preview" onclick="playPreviewAudio(this, '${audioUrl}')">▶</button>
                    <span class="preview-duration">${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}</span>
                    <button class="voice-send" onclick="sendVoicePreview()">SEND</button>
                    <button class="voice-re-record" onclick="reRecordVoice()">RE-RECORD</button>
                </div>
            </div>
        `;
    }

    function playPreviewAudio(button, audioUrl) {
        let audio = document.getElementById('previewAudio');
        if (!audio) {
            audio = new Audio();
            audio.id = 'previewAudio';
            document.body.appendChild(audio);
        }
        
        if (audio.src !== audioUrl) {
            audio.src = audioUrl;
        }
        
        if (audio.paused) {
            audio.play();
            button.textContent = '⏸';
            button.classList.add('playing');
            
            audio.onended = () => {
                button.textContent = '▶';
                button.classList.remove('playing');
            };
        } else {
            audio.pause();
            button.textContent = '▶';
            button.classList.remove('playing');
        }
    }

    function cancelVoicePreview() {
        const preview = document.getElementById('voicePreview');
        if (preview) preview.remove();
        recordedBlob = null;
        waveformData = [];
        
        document.getElementById('messageInput').disabled = false;
        const messageInputVal = document.getElementById('messageInput').value.trim();
        document.getElementById('sendBtn').disabled = !messageInputVal;
    }

    function reRecordVoice() {
        cancelVoicePreview();
        startVoiceRecording();
    }

    function sendVoicePreview() {
        if (!recordedBlob) {
            showToast('❌ No voice message to send', 'error');
            return;
        }
        
        if (recordedBlob.size === 0) {
            showToast('❌ No audio recorded', 'error');
            return;
        }
        
        if (recordedBlob.size > 10 * 1024 * 1024) {
            showToast('❌ Voice message too large (max 10MB)', 'error');
            return;
        }
        
        const duration = Math.floor((Date.now() - recordingStartTime) / 1000);
        const formData = new FormData();
        formData.append('voice', recordedBlob, 'voice_message.webm');
        formData.append('receiver_id', friendId);
        formData.append('duration', duration);
        
        if (replyToMessageId) {
            formData.append('reply_to', replyToMessageId);
        }
        
        if (waveformData.length > 0) {
            formData.append('waveform', JSON.stringify(waveformData));
        }
        
        showToast('📤 SENDING VOICE MESSAGE...', 'info');
        
        fetch('send_voice.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showToast('✅ VOICE MESSAGE SENT', 'success');
                cancelReply();
                cancelVoicePreview();
                loadMessages(true);
                // Also send real-time notification for voice message
                if (socketConnected) {
                    socket.emit('send-message', {
                        message_id: data.message_id,
                        sender_id: userId,
                        receiver_id: friendId,
                        message: '[Voice Message]',
                        timestamp: new Date().toISOString()
                    });
                }
            } else {
                showToast('❌ ' + (data.error || 'Failed to send voice message'), 'error');
            }
        })
        .catch(err => {
            console.error('Error sending voice message:', err);
            showToast('❌ Failed to send voice message', 'error');
        });
    }

    // ============================================
    // VOICE MESSAGE PLAYBACK
    // ============================================

    let currentAudio = null;
    let currentlyPlaying = null;
    let progressInterval = null;

    function playVoiceMessage(audioUrl, button, messageId, waveform) {
        const audio = document.getElementById('voicePlayer');
        
        if (currentlyPlaying === messageId && !audio.paused) {
            audio.pause();
            updatePlayButton(button, false);
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
            return;
        }
        
        if (currentlyPlaying) {
            const oldButton = document.querySelector(`[data-message-id="${currentlyPlaying}"]`);
            if (oldButton) updatePlayButton(oldButton, false);
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }
        
        audio.src = audioUrl;
        audio.load();
        
        audio.play()
            .then(() => {
                currentlyPlaying = messageId;
                currentAudio = audio;
                updatePlayButton(button, true);
                
                const waveformBars = document.querySelectorAll(`.voice-wave-bar[data-message-id="${messageId}"]`);
                if (progressInterval) clearInterval(progressInterval);
                
                progressInterval = setInterval(() => {
                    if (audio && !audio.paused && !audio.ended) {
                        const progress = (audio.currentTime / audio.duration) * 100;
                        
                        const progressBar = document.getElementById(`voice-progress-${messageId}`);
                        if (progressBar) {
                            progressBar.style.width = progress + '%';
                        }
                        
                        if (waveformBars.length > 0) {
                            const activeIndex = Math.floor((waveformBars.length) * (audio.currentTime / audio.duration));
                            waveformBars.forEach((bar, index) => {
                                if (index <= activeIndex) {
                                    bar.classList.add('active');
                                } else {
                                    bar.classList.remove('active');
                                }
                            });
                        }
                        
                        const timeDisplay = document.getElementById(`voice-time-${messageId}`);
                        if (timeDisplay) {
                            const current = formatTime(audio.currentTime);
                            const total = formatTime(audio.duration);
                            timeDisplay.textContent = `${current}/${total}`;
                        }
                    }
                }, 100);
            })
            .catch(error => {
                console.error('Playback failed:', error);
                showToast('❌ Failed to play voice message', 'error');
            });
        
        audio.onended = function() {
            updatePlayButton(button, false);
            currentlyPlaying = null;
            currentAudio = null;
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
            
            const waveformBars = document.querySelectorAll(`.voice-wave-bar[data-message-id="${messageId}"]`);
            waveformBars.forEach(bar => bar.classList.remove('active'));
            
            const progressBar = document.getElementById(`voice-progress-${messageId}`);
            if (progressBar) {
                progressBar.style.width = '0%';
            }
            
            const timeDisplay = document.getElementById(`voice-time-${messageId}`);
            if (timeDisplay) {
                const duration = formatTime(audio.duration);
                timeDisplay.textContent = `00:00/${duration}`;
            }
        };
        
        audio.onerror = function() {
            showToast('❌ Error playing voice message', 'error');
            updatePlayButton(button, false);
            currentlyPlaying = null;
            currentAudio = null;
        };
    }

    function updatePlayButton(button, isPlaying) {
        if (!button) return;
        
        if (isPlaying) {
            button.classList.add('playing');
            button.innerHTML = '⏸️';
            button.title = 'PAUSE';
        } else {
            button.classList.remove('playing');
            button.innerHTML = '▶️';
            button.title = 'PLAY';
        }
    }

    function formatTime(seconds) {
        if (isNaN(seconds) || !isFinite(seconds)) return '00:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    // ============================================
    // TYPING INDICATOR (Backup AJAX method)
    // ============================================

    let typingTimeoutAjax;
    let isTypingAjax = false;
    let lastTypingUpdate = 0;

    function sendTypingStatusAjax(typing) {
        const now = Date.now();
        if (now - lastTypingUpdate < 1000 && typing === isTypingAjax) {
            return;
        }
        
        lastTypingUpdate = now;
        
        fetch('typing.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `receiver_id=<?php echo $friend_id; ?>&typing=${typing ? 1 : 0}`
        })
        .catch(err => console.error('Error sending typing status:', err));
    }

    function checkTyping() {
        fetch(`check_typing.php?chat_partner_id=<?php echo $friend_id; ?>`)
        .then(response => response.json())
        .then(data => {
            const indicator = document.getElementById('typingIndicator');
            if (data.typing) {
                indicator.style.display = 'flex';
            } else {
                indicator.style.display = 'none';
            }
        })
        .catch(err => console.error('Error checking typing status:', err));
    }

    // ============================================
    // MESSAGING FUNCTIONALITY
    // ============================================

    let currentMessageId = null;
    let currentReactionMessageId = null;
    let replyToMessageId = null;
    let replyToMessageText = '';
    let replyToMessageSender = '';
    let editingMessageId = null;
    let lastMessageId = 0;
    let isLoading = false;
    let checkInterval;
    
    function showLogoutModal() {
        document.getElementById('logoutModal').classList.add('show');
    }
    
    function hideLogoutModal() {
        document.getElementById('logoutModal').classList.remove('show');
    }
    
    function confirmLogout() {
        const overlay = document.getElementById('loadingOverlay');
        const loadingText = document.getElementById('loadingText');
        if (loadingText) loadingText.textContent = "TERMINATING SESSION...";
        if (overlay) overlay.classList.add('active');
        document.body.classList.add('is-loading');
        
        setTimeout(() => {
            document.getElementById('logoutForm').submit();
        }, 800);
    }
    
    // ============================================
    // REACTION SYSTEM - FIXED
    // ============================================

    function showReactionPicker(messageId, button) {
        event?.stopPropagation();
        event?.preventDefault();
        
        // Remove any existing reaction picker
        document.querySelector('.reaction-picker')?.remove();
        
        currentReactionMessageId = messageId;
        
        const picker = document.createElement('div');
        picker.className = 'reaction-picker';
        picker.innerHTML = `
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '👍'); event.stopPropagation(); event.preventDefault();">👍</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '❤️'); event.stopPropagation(); event.preventDefault();">❤️</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '😂'); event.stopPropagation(); event.preventDefault();">😂</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '😮'); event.stopPropagation(); event.preventDefault();">😮</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '😢'); event.stopPropagation(); event.preventDefault();">😢</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '🔥'); event.stopPropagation(); event.preventDefault();">🔥</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '🎮'); event.stopPropagation(); event.preventDefault();">🎮</button>
        `;
        
        // Position the picker near the button
        const rect = button.getBoundingClientRect();
        
        picker.style.position = 'fixed';
        picker.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
        picker.style.left = (rect.left - 140) + 'px';
        picker.style.zIndex = '10000';
        
        document.body.appendChild(picker);
        
        // Close picker when clicking outside
        setTimeout(() => {
            function closePicker(e) {
                if (!picker.contains(e.target) && e.target !== button) {
                    picker.remove();
                    document.removeEventListener('click', closePicker);
                }
            }
            
            document.addEventListener('click', closePicker);
        }, 100);
    }

    function addReaction(messageId, reaction) {
        event?.stopPropagation();
        event?.preventDefault();
        
        fetch('add_reaction.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `message_id=${messageId}&reaction=${encodeURIComponent(reaction)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadMessages(true);
                showToast('✅ Reaction added', 'success');
            } else {
                showToast('❌ ' + (data.error || 'Failed to add reaction'), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('❌ Failed to add reaction', 'error');
        });
        
        // Remove the picker
        document.querySelector('.reaction-picker')?.remove();
    }

    // ============================================
    // ATTACH MESSAGE EVENTS - FIXED
    // ============================================

    function attachMessageEvents() {
        console.log('Attaching message events...');
        
        // Attach reaction button events
        document.querySelectorAll('.msg-action.react').forEach(btn => {
            // Remove old listeners
            btn.replaceWith(btn.cloneNode(true));
        });
        
        // Re-attach fresh event listeners
        document.querySelectorAll('.msg-action.react').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();
                
                // Find the message element that contains this button
                const messageDiv = btn.closest('.message');
                if (messageDiv && messageDiv.dataset.id) {
                    const messageId = parseInt(messageDiv.dataset.id);
                    console.log('React button clicked for message ID:', messageId);
                    showReactionPicker(messageId, btn);
                } else {
                    console.error('Could not find message ID for reaction button');
                }
            };
        });
        
        // Attach reply button events
        document.querySelectorAll('.msg-action.reply').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();
                
                const messageDiv = btn.closest('.message');
                if (messageDiv && messageDiv.dataset.id) {
                    const messageId = messageDiv.dataset.id;
                    const messageText = messageDiv.querySelector('.message-text')?.textContent || 'Media message';
                    const sender = messageDiv.classList.contains('message-own') ? 'YOU' : '<?php echo strtoupper(addslashes($friend['username'])); ?>';
                    setReply(messageId, messageText, sender);
                }
            };
        });
        
        // Attach edit button events (only for own messages)
        document.querySelectorAll('.msg-action.edit').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();
                
                const messageDiv = btn.closest('.message');
                if (messageDiv && messageDiv.dataset.id) {
                    const messageId = messageDiv.dataset.id;
                    const messageText = messageDiv.querySelector('.message-text')?.textContent || '';
                    editMessage(messageId, messageText);
                }
            };
        });
        
        // Attach delete button events
        document.querySelectorAll('.msg-action.delete').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();
                
                const messageDiv = btn.closest('.message');
                if (messageDiv && messageDiv.dataset.id) {
                    deleteMessage(parseInt(messageDiv.dataset.id));
                }
            };
        });
        
        // Attach image click events
        document.querySelectorAll('.message-image').forEach(img => {
            img.onclick = (e) => {
                e.stopPropagation();
                showImagePreview(img.src);
            };
        });
        
        // Attach existing reaction clicks — shows who reacted (popup)
        document.querySelectorAll('.reaction').forEach(reaction => {
            reaction.onclick = (e) => {
                e.stopPropagation();
                e.preventDefault();

                const usersAttr = reaction.getAttribute('data-users');
                const emojiChar = reaction.textContent.trim().charAt(0);
                let users = [];
                try { users = usersAttr ? JSON.parse(usersAttr) : []; } catch(err) {}
                showReactorList(e, emojiChar, users);
            };
        });
        
        console.log('Message events attached');
    }

    // ============================================
    // LOAD MESSAGES
    // ============================================

    function loadMessages(initial = true) {
        if (isLoading) return;
        
        isLoading = true;
        
        fetch(`<?= BASE_URL ?>/api/<?= BASE_URL ?>/api/load_messages.php?id=<?php echo $friend_id; ?>`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(data => {
            document.getElementById('messagesContainer').innerHTML = data;
            scrollToBottom();
            updateLastMessageId();
            
            // CRITICAL: Attach events AFTER loading messages
            setTimeout(() => {
                attachMessageEvents();
            }, 100);
            
            isLoading = false;
            checkScroll();
        })
        .catch(err => {
            console.error('Error loading messages:', err);
            isLoading = false;
            showToast('Failed to load messages: ' + err.message, 'error');
        });
    }

    function updateLastMessageId() {
        const lastMsg = document.querySelector('.message:last-child');
        if (lastMsg && lastMsg.dataset.id) {
            lastMessageId = parseInt(lastMsg.dataset.id);
        }
    }
    
    function checkForNewMessages() {
        // No-op: Polling disabled in favor of Socket.io real-time
        return;
    }
    
    function showImagePreview(src) {
        document.getElementById('previewImage').src = src;
        document.getElementById('imagePreviewModal').classList.add('show');
    }
    
    function hideImagePreview() {
        document.getElementById('imagePreviewModal').classList.remove('show');
    }
    
    function setReply(messageId, messageText, sender) {
        replyToMessageId = messageId;
        replyToMessageText = messageText;
        replyToMessageSender = sender;
        
        document.getElementById('replyPreviewHeader').textContent = `REPLYING TO ${sender}`;
        document.getElementById('replyPreviewText').textContent = messageText.substring(0, 60) + (messageText.length > 60 ? '...' : '');
        document.getElementById('replyPreview').style.display = 'flex';
        document.getElementById('replyToInput').value = messageId;
        document.getElementById('messageInput').focus();
    }
    
    function cancelReply() {
        replyToMessageId = null;
        document.getElementById('replyPreview').style.display = 'none';
        document.getElementById('replyToInput').value = '';
    }
    
    function editMessage(messageId, messageText) {
        editingMessageId = messageId;
        document.getElementById('editMessageInput').value = messageText;
        document.getElementById('editModal').classList.add('show');
    }
    
    function hideEditModal() {
        document.getElementById('editModal').classList.remove('show');
        editingMessageId = null;
    }
    
    function saveEdit() {
        const newText = document.getElementById('editMessageInput').value.trim();
        if (!newText || !editingMessageId) return;
        
        fetch('edit_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `message_id=${editingMessageId}&message=${encodeURIComponent(newText)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                hideEditModal();
                loadMessages(true);
                showToast('MESSAGE EDITED', 'success');
            } else {
                showToast(data.error || 'Failed to edit message', 'error');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showToast('Failed to edit message', 'error');
        });
    }
    
    function deleteMessage(messageId) {
        currentMessageId = messageId;
        document.getElementById('deleteModal').classList.add('show');
    }
    
    function hideDeleteModal() {
        document.getElementById('deleteModal').classList.remove('show');
        currentMessageId = null;
    }
    
    function confirmDelete() {
        if (!currentMessageId) return;
        
        fetch('delete_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `message_id=${currentMessageId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                hideDeleteModal();
                loadMessages(true);
                showToast('MESSAGE DELETED', 'success');
            } else {
                showToast(data.error || 'Failed to delete message', 'error');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showToast('Failed to delete message', 'error');
        });
    }
    
    function showAttachMenu() {
        document.getElementById('fileInput').click();
    }
    
    function uploadFile(input) {
        const file = input.files[0];
        if (!file) return;
        
        const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
        const MAX_FILE_SIZE = 50 * 1024 * 1024;
        
        if (file.size > MAX_FILE_SIZE) {
            showToast(`FILE TOO LARGE. Maximum size is 50MB (Your file: ${fileSizeMB}MB)`, 'error');
            input.value = '';
            return;
        }
        
        const allowedTypes = [
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
        
        const isAllowed = allowedTypes.includes(file.type) || file.type.startsWith('image/');
        
        if (!isAllowed) {
            showToast('FILE TYPE NOT ALLOWED', 'error');
            input.value = '';
            return;
        }
        
        const formData = new FormData();
        formData.append('file', file);
        formData.append('receiver_id', friendId);
        if (replyToMessageId) {
            formData.append('reply_to', replyToMessageId);
        }
        
        showToast(`📤 UPLOADING: ${file.name} (${fileSizeMB} MB)...`, 'info');
        
        const sendBtn = document.getElementById('sendBtn');
        sendBtn.disabled = true;
        
        fetch('upload_file.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (result.success) {
                showToast('✅ FILE UPLOADED SUCCESSFULLY', 'success');
                cancelReply();
                loadMessages(true);
                document.getElementById('messageInput').value = '';
                // Send real-time notification for file
                if (socketConnected) {
                    socket.emit('send-message', {
                        message_id: result.message_id,
                        sender_id: userId,
                        receiver_id: friendId,
                        message: '[File] ' + file.name,
                        timestamp: new Date().toISOString()
                    });
                }
            } else {
                showToast('❌ UPLOAD FAILED: ' + (result.error || 'Unknown error'), 'error');
            }
        })
        .catch(err => {
            console.error('Upload error:', err);
            showToast('❌ UPLOAD FAILED. Please try again.', 'error');
        })
        .finally(() => {
            input.value = '';
            sendBtn.disabled = false;
        });
    }
    
    function scrollToBottom() {
        const container = document.getElementById('messagesContainer');
        container.scrollTop = container.scrollHeight;
    }
    
    function checkScroll() {
        const container = document.getElementById('messagesContainer');
        const scrollBtn = document.getElementById('scrollBottomBtn');
        if (!scrollBtn) return;
        
        const isAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
        scrollBtn.classList.toggle('visible', !isAtBottom);
    }
    
    function toggleSearch() {
        const searchContainer = document.getElementById('searchContainer');
        searchContainer.classList.toggle('show');
        
        if (!searchContainer.classList.contains('show')) {
            document.getElementById('searchResults').classList.remove('show');
        } else {
            const results = document.getElementById('searchResults');
            results.style.left = '104px';
            results.style.right = '28px';
        }
    }
    
    function searchMessages(query) {
        if (query.length < 2) {
            document.getElementById('searchResults').classList.remove('show');
            return;
        }
        
        fetch(`search_messages.php?friend_id=<?php echo $friend_id; ?>&q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(results => {
            const resultsDiv = document.getElementById('searchResults');
            
            if (results.length === 0) {
                resultsDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--text-muted);">NO MESSAGES FOUND</div>';
            } else {
                let html = '';
                results.forEach(msg => {
                    html += `
                        <div class="search-result-item" onclick="jumpToMessage(${msg.id})">
                            <div class="search-result-avatar">
                                ${msg.sender_id == userId ? 'YOU' : '<?php echo getAvatarLetter($friend['username']); ?>'}
                            </div>
                            <div class="search-result-info">
                                <div class="search-result-name">${msg.sender_id == userId ? 'YOU' : '<?php echo strtoupper(addslashes($friend['username'])); ?>'}</div>
                                <div class="search-result-preview">${msg.message.substring(0, 50)}${msg.message.length > 50 ? '...' : ''}</div>
                            </div>
                            <div class="search-result-date">${msg.created_at}</div>
                        </div>
                    `;
                });
                resultsDiv.innerHTML = html;
            }
            
            resultsDiv.classList.add('show');
        });
    }
    
    function jumpToMessage(messageId) {
        window.location.href = `chat.php?id=<?php echo $friend_id; ?>&message_id=${messageId}`;
    }
    
    function showEmojiPicker() {
        document.getElementById('emojiModal').classList.add('show');
    }
    
    function hideEmojiPicker() {
        document.getElementById('emojiModal').classList.remove('show');
    }
    
    function insertEmoji(emoji) {
        const input = document.getElementById('messageInput');
        input.value += emoji;
        input.focus();
        document.getElementById('sendBtn').disabled = false;
        hideEmojiPicker();
    }
    
    function showUserInfo() {
        document.getElementById('userInfoModal').classList.add('show');
    }
    
    function hideUserInfo() {
        document.getElementById('userInfoModal').classList.remove('show');
    }
    
    function showToast(message, type = 'info') {
        return;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <span style="flex:1;">${message}</span>
            <button onclick="this.parentElement.remove()" style="background:none; border:none; color:white; cursor:pointer; font-size:18px;">✕</button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
    
    function showNewMessageNotice() {
        if (!document.getElementById('newMessageNotice')) {
            const notice = document.createElement('div');
            notice.id = 'newMessageNotice';
            notice.className = 'scroll-bottom';
            notice.style.bottom = '150px';
            notice.innerHTML = '↓ NEW MESSAGES';
            notice.onclick = () => {
                loadMessages(true);
                notice.remove();
            };
            document.body.appendChild(notice);
        }
    }
    
    window.onload = function() {
        loadMessages(true);
        
        const container = document.getElementById('messagesContainer');
        container.addEventListener('scroll', checkScroll);
        
        // Legacy polling disabled in favor of Socket.io
        // checkInterval = setInterval(() => {
        //     checkForNewMessages();
        //     checkTyping();
        // }, 1500);
    };
    
    window.onbeforeunload = function() {
        if (checkInterval) {
            clearInterval(checkInterval);
        }
        const audio = document.getElementById('voicePlayer');
        if (audio) audio.pause();
        if (isCurrentlyTyping) {
            sendTypingStatus(false);
        }
        // Disconnect socket
        if (socket) {
            socket.disconnect();
        }
    };
    
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
            e.target.classList.remove('show');
        }
        
        if (e.target.classList.contains('image-preview-modal')) {
            e.target.classList.remove('show');
        }
        
        if (!e.target.closest('.search-container') && !e.target.closest('.search-results') && !e.target.closest('.action-btn')) {
            document.getElementById('searchResults').classList.remove('show');
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                modal.classList.remove('show');
            });
            document.getElementById('imagePreviewModal').classList.remove('show');
            document.getElementById('searchResults').classList.remove('show');
            cancelReply();
            
            if (isRecording) {
                stopVoiceRecording();
            }
            
            const audio = document.getElementById('voicePlayer');
            if (audio) audio.pause();
        }
    });
    </script>

    <!-- Reactor List Popup -->
    <div id="reactorPopup" style="
        display: none;
        position: fixed;
        z-index: 99999;
        background: var(--bg-card, #1a1d24);
        border: 1px solid var(--border, rgba(255,255,255,0.06));
        border-radius: 16px;
        padding: 0;
        min-width: 200px;
        max-width: 280px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,70,85,0.15);
        backdrop-filter: blur(20px);
        animation: popupFadeIn 0.15s ease;
        overflow: hidden;
    ">
        <div id="reactorPopupHeader" style="
            padding: 12px 16px 10px;
            background: linear-gradient(135deg, rgba(255,70,85,0.15), rgba(255,70,85,0.05));
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 2px;
            color: var(--accent, #ff4655);
            text-transform: uppercase;
        "></div>
        <div id="reactorPopupList" style="padding: 8px 0; max-height: 200px; overflow-y: auto;"></div>
    </div>

    <style>
        @keyframes popupFadeIn {
            from { opacity: 0; transform: scale(0.92) translateY(5px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .reactor-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary, #fff);
            letter-spacing: 0.5px;
            transition: background 0.15s;
        }
        .reactor-item:hover { background: rgba(255,255,255,0.04); }
        .reactor-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--gradient-primary, linear-gradient(135deg,#ff4655,#ff7b72));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            color: white;
            flex-shrink: 0;
        }
    </style>

    <script>
    function showReactorList(event, emoji, users) {
        event.stopPropagation();
        const popup = document.getElementById('reactorPopup');
        const header = document.getElementById('reactorPopupHeader');
        const list = document.getElementById('reactorPopupList');

        header.textContent = emoji + '  ' + users.length + ' reaction' + (users.length !== 1 ? 's' : '');

        list.innerHTML = '';
        if (users.length === 0) {
            list.innerHTML = '<div class="reactor-item" style="color:var(--text-muted)">No reactors found</div>';
        } else {
            users.forEach(name => {
                const item = document.createElement('div');
                item.className = 'reactor-item';
                item.innerHTML = `
                    <div class="reactor-avatar">${name.charAt(0).toUpperCase()}</div>
                    <span>${name}</span>
                `;
                list.appendChild(item);
            });
        }

        const x = event.clientX;
        const y = event.clientY;
        popup.style.display = 'block';

        const popupW = popup.offsetWidth || 220;
        const popupH = popup.offsetHeight || 150;
        const winW = window.innerWidth;
        const winH = window.innerHeight;

        let left = x + 10;
        let top = y + 10;
        if (left + popupW > winW - 10) left = x - popupW - 10;
        if (top + popupH > winH - 10) top = y - popupH - 10;

        popup.style.left = left + 'px';
        popup.style.top = top + 'px';
    }

    document.addEventListener('click', function(e) {
        const popup = document.getElementById('reactorPopup');
        if (popup && !popup.contains(e.target)) {
            popup.style.display = 'none';
        }
    });
    </script>

    <!-- Realtime Integration -->
    <script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
    <script src=""></script>
    <script>
        // Initialize Realtime API with current user context
        const realtime = new RealtimeAPI({
            userId: <?php echo $user_id; ?>,
            socketUrl: '<?php echo SOCKET_URL; ?>'
        });
        
        // Handle incoming messages
        realtime.on('new_messages', (messages) => {
            messages.forEach(msg => {
                // If message is from the person we're currently chatting with
                if (msg.sender_id == <?php echo $friend_id; ?>) {
                    const container = document.getElementById('messagesContainer');
                    const isAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
                    
                    // Fetch the formatted message HTML
                    fetch(`get_new_messages.php?friend_id=<?php echo $friend_id; ?>&after_id=${lastMessageId}`)
                    .then(r => r.text())
                    .then(html => {
                        if (html.trim()) {
                            // Deduplication: Check if message ID already exists in DOM
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = html;
                            const newMsgId = tempDiv.firstElementChild?.dataset.id;
                            
                            if (newMsgId && !document.querySelector(`.message[data-id="${newMsgId}"]`)) {
                                container.insertAdjacentHTML('beforeend', html);
                                updateLastMessageId();
                                if (isAtBottom) scrollToBottom();
                                attachMessageEvents();
                            }
                        }
                    });
                } else {
                    // Show a notification for message from someone else
                    showToast(`<b>${msg.sender_name}</b>: ${msg.message.substring(0, 30)}...`, 'info');
                }
            });
        });
        
        // Typing indicator sync via Socket.io
        realtime.on('typing', (typingUsers) => {
            const statusText = document.querySelector('.chat-user-status');
            if (typingUsers.includes(<?php echo $friend_id; ?>)) {
                statusText.innerHTML = '<span class="status-dot" style="background: var(--success); animation: pulse 1s infinite;"></span> TYPING...';
            } else {
                statusText.innerHTML = '<span class="status-dot"></span> <?php echo $is_online ? "ONLINE" : "OFFLINE"; ?>';
            }
        });
../        
        // Overwrite the manual checkInterval with a much slower one as a rare backup
        if (typeof checkInterval !== 'undefined') {
            clearInterval(checkInterval);
            checkInterval = setInterval(() => {
                // Only sync unread counts as a backup
                realtime.syncStatus();
            }, 30000); 
        }

        // Hook into existing typing logic to use Socket.io
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            let typingTimeout;
            messageInput.addEventListener('input', () => {
                realtime.sendTypingIndicator(<?php echo $friend_id; ?>, true);
                
                clearTimeout(typingTimeout);
                typingTimeout = setTimeout(() => {
                    realtime.sendTypingIndicator(<?php echo $friend_id; ?>, false);
                }, 3000);
            });
        }
    </script>
    <script src="particles.js"></script>
</body>
</html>
