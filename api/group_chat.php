<?php
require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$room_id = isset($_GET['room_id']) ? (int) $_GET['room_id'] : 0;
$error = '';

// LEAD ENGINEER NOTE: Sector 9 Hardening - Schema creation removed (Natively Aligned).
// All interaction logic migrated to Secure Prepared Statements.

if (!$room_id) {
    $room_name = "SQUAD_" . date('Ymd_His');
    $stmt_new = $conn->prepare("INSERT INTO chat_rooms (room_name, created_by, max_members) VALUES (?, ?, 50)");
    $stmt_new->bind_param("si", $room_name, $user_id);
    $stmt_new->execute();
    $room_id = $conn->insert_id;
    $stmt_new->close();

    $stmt_member = $conn->prepare("INSERT INTO chat_room_members (room_id, user_id, role) VALUES (?, ?, 'admin')");
    $stmt_member->bind_param("ii", $room_id, $user_id);
    $stmt_member->execute();
    $stmt_member->close();

    header("Location: group_chat.php?room_id=$room_id");
    exit();
}

if (isset($_POST['add_member'])) {
    $member_id = (int) $_POST['member_id'];
    
    $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM chat_room_members WHERE room_id = ?");
    $stmt_count->bind_param("i", $room_id);
    $stmt_count->execute();
    $current_count = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();

    $stmt_info = $conn->prepare("SELECT max_members FROM chat_rooms WHERE id = ?");
    $stmt_info->bind_param("i", $room_id);
    $stmt_info->execute();
    $max_members = $stmt_info->get_result()->fetch_assoc()['max_members'] ?? 50;
    $stmt_info->close();

    if ($current_count >= $max_members) {
        $error = "Cannot add more members. Maximum limit reached.";
    } else {
        $stmt_check = $conn->prepare("SELECT * FROM chat_room_members WHERE room_id = ? AND user_id = ?");
        $stmt_check->bind_param("ii", $room_id, $member_id);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows === 0) {
            $stmt_add = $conn->prepare("INSERT INTO chat_room_members (room_id, user_id, role) VALUES (?, ?, 'member')");
            $stmt_add->bind_param("ii", $room_id, $member_id);
            $stmt_add->execute();
            $stmt_add->close();
        }
        $stmt_check->close();
    }
}
    header("Location: group_chat.php?room_id=$room_id" . ($success ? "&success=" . urlencode($success) : "") . ($error ? "&error=" . urlencode($error) : ""));
    exit();
}

if (isset($_POST['remove_member']) && isset($_POST['member_id'])) {
    $member_id = (int) $_POST['member_id'];

    
    if ($admin_check && $admin_check['role'] == 'admin' && $member_id != $user_id) {
        $stmt_rem = $conn->prepare("DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?");
        $stmt_rem->bind_param("ii", $room_id, $member_id);
        $stmt_rem->execute();
        $stmt_rem->close();
        $success = "Member removed from the group";
    }

    header("Location: group_chat.php?room_id=$room_id" . ($success ? "&success=" . urlencode($success) : "") . ($error ? "&error=" . urlencode($error) : ""));
    exit();
}

if (isset($_GET['success']) && !empty($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error']) && !empty($_GET['error'])) {
    $error = $_GET['error'];
}

$stmt_room = $conn->prepare("
    SELECT cr.*, u.username as creator_name,
           (SELECT COUNT(*) FROM chat_room_members WHERE room_id = cr.id) as member_count
    FROM chat_rooms cr
    JOIN users u ON u.user_id = cr.created_by
    WHERE cr.id = ?
");
$stmt_room->bind_param("i", $room_id);
$stmt_room->execute();
$room_result = $stmt_room->get_result();

if (!$room_result || $room_result->num_rows === 0) {
    $error = "Room not found";
} else {
    $room = $room_result->fetch_assoc();
}
$stmt_room->close();

$stmt_mbr_chk = $conn->prepare("SELECT * FROM chat_room_members WHERE room_id = ? AND user_id = ?");
$stmt_mbr_chk->bind_param("ii", $room_id, $user_id);
$stmt_mbr_chk->execute();
$member_check = $stmt_mbr_chk->get_result();
$is_member = ($member_check->num_rows > 0);
$stmt_mbr_chk->close();

if (!$is_member && !$error) {
    $error = "You are not a member of this group. Ask the host to add you or use the invite link.";
}

$user_role = 'member';
if ($is_member) {
    $stmt_role = $conn->prepare("SELECT role FROM chat_room_members WHERE room_id = ? AND user_id = ?");
    $stmt_role->bind_param("ii", $room_id, $user_id);
    $stmt_role->execute();
    $user_role = $stmt_role->get_result()->fetch_assoc()['role'] ?? 'member';
    $stmt_role->close();
}

$members_result = null;
$total_members = 0;
$friends_result = null;
$total_friends = 0;

if ($is_member) {
    $stmt_mbrs = $conn->prepare("
        SELECT u.user_id, u.username, u.avatar, u.last_active, crm.role, crm.joined_at
        FROM chat_room_members crm
        JOIN users u ON u.user_id = crm.user_id
        WHERE crm.room_id = ?
        ORDER BY (crm.role = 'admin') DESC, u.username ASC
    ");
    $stmt_mbrs->bind_param("i", $room_id);
    $stmt_mbrs->execute();
    $members_result = $stmt_mbrs->get_result();
    $total_members = $members_result ? $members_result->num_rows : 0;
}

    
    $stmt_frnds = $conn->prepare("
        SELECT u.user_id, u.username, u.last_active
        FROM friends f
        JOIN users u ON u.user_id = f.friend_id
        WHERE f.user_id = ?
        AND u.user_id NOT IN (
            SELECT user_id FROM chat_room_members WHERE room_id = ?
        )
        ORDER BY u.username ASC
    ");
    $stmt_frnds->bind_param("ii", $user_id, $room_id);
    $stmt_frnds->execute();
    $friends_result = $stmt_frnds->get_result();
    $total_friends = $friends_result ? $friends_result->num_rows : 0;
}

$stmt_u = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt_u->bind_param("i", $user_id);
$stmt_u->execute();
$user_data = $stmt_u->get_result()->fetch_assoc();
$stmt_u->close();

$stmt_ur = $conn->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt_ur->bind_param("i", $user_id);
$stmt_ur->execute();
$total_unread = $stmt_ur->get_result()->fetch_assoc()['count'] ?? 0;
$stmt_ur->close();

$stmt_frq = $conn->prepare("SELECT COUNT(*) as count FROM friend_requests WHERE receiver_id = ? AND status = 'pending'");
$stmt_frq->bind_param("i", $user_id);
$stmt_frq->execute();
$requests_count = $stmt_frq->get_result()->fetch_assoc()['count'] ?? 0;
$stmt_frq->close();

$stmt_mc = $conn->prepare("SELECT COUNT(*) as count FROM calls WHERE receiver_id = ? AND status = 'missed'");
$stmt_mc->bind_param("i", $user_id);
$stmt_mc->execute();
$missed_calls = $stmt_mc->get_result()->fetch_assoc()['count'] ?? 0;
$stmt_mc->close();

function getAvatarLetter($username)
{
    return strtoupper(substr($username, 0, 1));
}

function timeAgo($timestamp)
{
    if (!$timestamp)
        return '';

    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60)
        return 'now';
    if ($diff < 3600)
        return floor($diff / 60) . 'm';
    if ($diff < 86400)
        return floor($diff / 3600) . 'h';
    if ($diff < 604800)
        return floor($diff / 86400) . 'd';
    return date('M d', $time);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SQUAD CHAT | CONNECTXION</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Poppins', system-ui, sans-serif;
            background: var(--bg-primary);
            height: 100vh;
            overflow: hidden;
            color: var(--text-primary);
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

        /* Custom Scrollbar Styles */
        .scroll-panel {
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-track);
        }

        .scroll-panel::-webkit-scrollbar {
            width: 6px;
        }

        .scroll-panel::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
            border-radius: 10px;
        }

        .scroll-panel::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 10px;
            transition: all 0.3s;
        }

        .scroll-panel::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
            box-shadow: 0 0 10px var(--accent-glow);
        }

        /* For Firefox */
        .scroll-panel {
            scrollbar-width: thin;
            scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-track);
        }

        .app-container {
            display: flex;
            height: 100vh;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
        }

        /* Gaming Overlay Effect */
        .app-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(0deg,
                    rgba(0, 0, 0, 0.15) 0px,
                    rgba(0, 0, 0, 0.15) 1px,
                    transparent 1px,
                    transparent 2px);
            pointer-events: none;
            z-index: 5;
        }

        /* Left Sidebar - Gaming Navigation */
        .nav-sidebar {
            width: 80px;
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

        /* Alert Messages */
        .alert {
            padding: 16px 24px;
            border-radius: 14px;
            margin: 20px 30px 0;
            animation: slideIn 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid transparent;
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .alert.success {
            border-left-color: var(--success);
            color: var(--success);
        }

        .alert.error {
            border-left-color: var(--danger);
            color: var(--danger);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .room-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .room-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--bg-tertiary, #1f232b);
            border: 1px solid var(--border, rgba(255,255,255,0.06));
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            position: relative;
        }

        .room-avatar span {
            color: var(--text-muted, rgba(255,255,255,0.4));
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .room-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .room-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            overflow: hidden;
        }

        /* Chat Container */
        .chat-container {
            flex: 1;
            display: flex;
            background: var(--bg-primary);
            position: relative;
            overflow: hidden;
        }

        /* Messages Area */
        .messages-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-primary);
            overflow: hidden;
            height: 100%;
        }

        /* Chat Header */
        .chat-header {
            padding: 16px 24px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
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

        .room-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            min-width: 0;
        }

        .room-name {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary, #fff);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .room-meta {
            font-size: 11px;
            color: var(--text-muted, rgba(255,255,255,0.4));
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .member-limit {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            color: var(--accent);
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

        /* Messages Container - Scroll Panel */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: var(--bg-primary);
            min-height: 0;
            height: 100%;
        }

        /* Message Styles */
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

        .message-own { align-self: flex-end; }
        .message-other { align-self: flex-start; }

        .message-sender {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 4px;
        }
        
        .message-own .message-sender { text-align: right; color: var(--accent); }
        .message-other .message-sender { text-align: left; color: var(--accent-secondary); }

        .message-content-wrapper {
            display: flex;
            flex-direction: column;
            max-width: calc(100% - 44px);
            width: fit-content;
        }

        .message-own .message-content-wrapper { align-items: flex-end; }
        .message-other .message-content-wrapper { align-items: flex-start; }

        .message-wrapper {
            position: relative;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }

        .message-own .message-wrapper { flex-direction: row-reverse; }

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

        .message-avatar-img { width: 100%; height: 100%; object-fit: cover; }
        .message-avatar-letter { text-transform: uppercase; }

        .message-own .message-avatar-container { border-color: var(--accent-secondary); }
        .message-other .message-avatar-container { border-color: var(--accent); }

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

        .message-meta {
            display: block;
            margin-top: 2px;
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1;
        }

        .message-own .message-meta { text-align: right; margin-right: 4px; }
        .message-other .message-meta { text-align: left; margin-left: 4px; }

        /* Reply Preview in Message */
        .reply-preview-message {
            background: rgba(255, 255, 255, 0.05);
            border-left: 3px solid var(--accent);
            padding: 8px 12px;
            margin-bottom: 8px;
            border-radius: 8px;
            font-size: 12px;
        }

        .reply-sender {
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .reply-text {
            color: var(--text-muted);
            font-style: italic;
        }

        /* Message Actions */
        .message-actions {
            position: absolute;
            bottom: 100%;
            right: 0;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s;
            z-index: 10;
            margin-bottom: 5px;
            box-shadow: var(--shadow-lg);
        }

        .message-other .message-actions {
            right: auto;
            left: 0;
        }

        .message-wrapper:hover .message-actions {
            opacity: 1;
            visibility: visible;
        }

        .msg-action {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .msg-action:hover {
            background: var(--accent);
            color: white;
            transform: scale(1.1);
        }

        /* Reactions */
        .message-reactions {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 4px;
            padding: 0 8px;
        }

        .reaction {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .reaction:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .reaction.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .reaction-count {
            font-weight: 600;
        }

        /* Reaction Picker */
        .reaction-picker {
            position: fixed;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 8px;
            display: flex;
            gap: 4px;
            z-index: 1000;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.2s;
        }

        .reaction-emoji {
            width: 36px;
            height: 36px;
            border-radius: 18px;
            border: none;
            background: var(--bg-tertiary);
            color: white;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reaction-emoji:hover {
            background: var(--accent);
            transform: scale(1.2);
        }

        /* Edited Indicator */
        .edited-indicator {
            font-size: 10px;
            color: var(--text-muted);
            font-style: italic;
            margin-left: 4px;
        }

        /* Deleted Message */
        .message-deleted {
            padding: 12px 16px;
            border-radius: 18px;
            background: var(--bg-tertiary);
            color: var(--text-muted);
            font-style: italic;
            border: 1px dashed var(--border);
        }

        /* Reply Preview */
        .reply-preview {
            background: var(--bg-secondary);
            border-left: 4px solid var(--accent);
            padding: 12px 20px;
            margin: 0 24px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideIn 0.2s;
            flex-shrink: 0;
        }

        .reply-preview-content {
            flex: 1;
        }

        .reply-preview-header {
            font-size: 11px;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .reply-preview-text {
            font-size: 13px;
            color: var(--text-muted);
        }

        .cancel-reply {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: var(--danger);
            color: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cancel-reply:hover {
            transform: scale(1.1);
            background: #d32f2f;
        }

        /* Sidebar */
        .chat-sidebar {
            width: 320px;
            background: var(--bg-secondary);
            border-left: 1px solid var(--border);
            display: none; /* Hidden by default */
            flex-direction: column;
            padding: 20px;
            gap: 20px;
            overflow-y: auto;
            height: 100%;
            animation: slideInRight 0.3s ease;
        }

        .chat-sidebar.active {
            display: flex;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .room-details {
            padding: 16px;
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }

        .room-description {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
            line-height: 1.5;
        }

        .members-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-transform: uppercase;
            letter-spacing: 1px;
            flex-shrink: 0;
        }

        .member-count {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            color: var(--accent);
        }

        .members-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-right: 5px;
            min-height: 0;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .member-item:hover {
            border-color: var(--accent-secondary);
            transform: translateX(5px);
        }

        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--gradient-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            position: relative;
            flex-shrink: 0;
        }

        .member-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 10px;
            height: 10px;
            background: var(--success);
            border: 2px solid var(--bg-tertiary);
            border-radius: 50%;
        }

        .member-info {
            flex: 1;
            min-width: 0;
        }

        .member-name {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .member-role {
            font-size: 10px;
            color: var(--accent);
            background: var(--bg-card);
            padding: 2px 6px;
            border-radius: 10px;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .member-status {
            font-size: 10px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .remove-member {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            background: var(--danger);
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            flex-shrink: 0;
        }

        .member-item:hover .remove-member {
            opacity: 1;
        }

        .remove-member:hover {
            transform: scale(1.1);
            background: #d32f2f;
        }

        /* Add Member Section */
        .add-member-section {
            padding: 16px;
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }

        .friends-list {
            max-height: 200px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 10px 0;
            padding-right: 5px;
        }

        .friend-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            border: 1px solid var(--border);
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .friend-item:hover {
            border-color: var(--accent);
        }

        .friend-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }

        .friend-info {
            flex: 1;
            min-width: 0;
        }

        .friend-name {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .friend-status {
            font-size: 10px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .add-friend-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: var(--accent);
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .add-friend-btn:hover {
            background: var(--accent-hover);
            transform: scale(1.1);
        }

        .no-friends {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .invite-section {
            padding: 16px;
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            flex-shrink: 0;
        }

        .invite-link {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            margin: 10px 0;
            font-size: 11px;
            color: var(--text-secondary);
            word-break: break-all;
            font-family: monospace;
        }

        .copy-btn {
            width: 100%;
            padding: 10px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .copy-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: var(--glow-effect);
        }

        /* Chat Input */
        .chat-input-area {
            padding: 20px 24px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            flex-shrink: 0;
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
        }

        .chat-form:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        .chat-form input[type="text"] {
            flex: 1;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 14px;
            padding: 12px 0;
            outline: none;
            font-family: 'Poppins', sans-serif;
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
        }

        .input-btn {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 20px;
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

        .send-btn {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            border: none;
            background: var(--gradient-primary);
            color: white;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-effect);
        }

        .send-btn:hover:not(:disabled) {
            transform: scale(1.1);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Error Message */
        .error-message {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
            margin: 30px;
        }

        .error-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
        }

        .error-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .error-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .back-btn {
            display: inline-block;
            padding: 12px 30px;
            background: var(--gradient-primary);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 70, 85, 0.4);
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
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
            font-size: 24px;
            color: var(--text-primary);
            margin-bottom: 20px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 2px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .edit-message-input {
            width: 100%;
            padding: 15px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 14px;
            color: var(--text-primary);
            font-size: 14px;
            margin-bottom: 20px;
            resize: vertical;
            min-height: 100px;
        }

        .edit-message-input:focus {
            border-color: var(--accent);
            outline: none;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .modal-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .modal-btn.primary {
            background: var(--accent);
            color: white;
        }

        .modal-btn.primary:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
        }

        .modal-btn.secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .modal-btn.secondary:hover {
            background: var(--bg-hover);
        }

        .modal-btn.danger {
            background: var(--danger);
            color: white;
        }

        .modal-btn.danger:hover {
            background: #d32f2f;
            transform: translateY(-2px);
        }

        /* Not Member Message */
        .not-member {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
            margin: 30px;
        }

        .not-member-icon {
            font-size: 80px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 20px var(--accent-glow));
        }

        .not-member-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .not-member-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 14px;
            z-index: 2000;
            animation: slideInRight 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            text-transform: uppercase;
            color: var(--text-primary);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            border-left: 4px solid transparent;
        }

        .toast.success {
            background: var(--success);
            border-left-color: var(--success-dark);
        }

        .toast.error {
            background: var(--danger);
            border-left-color: var(--danger-dark);
        }

        .toast.info {
            background: var(--bg-secondary);
            border-left-color: var(--accent);
        }

        .toast.warning {
            background: var(--warning);
            border-left-color: var(--warning-dark);
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Scroll to bottom button */
        .scroll-bottom {
            position: fixed;
            bottom: 140px;
            right: 340px;
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: var(--accent);
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
            transition: all 0.3s;
            opacity: 0;
            visibility: hidden;
            box-shadow: var(--glow-effect);
            z-index: 100;
        }

        .scroll-bottom.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-bottom:hover {
            transform: scale(1.1);
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
                gap: 8px;
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

            .action-btn {
                width: 32px;
                height: 32px;
                font-size: 16px;
            }

            .main-content {
                flex: 1;
                overflow: hidden;
            }

            .chat-container {
                flex-direction: column;
                height: calc(100vh - 60px);
            }

            /* Move sidebar to bottom on mobile */
            .chat-sidebar {
                width: 100%;
                max-height: none;
                height: auto;
                border-left: none;
                border-top: 1px solid var(--border);
                display: none; /* hidden by default, toggled by JS */
                overflow-y: auto;
                max-height: 50vh;
            }

            .chat-sidebar.visible {
                display: block;
            }

            .messages-area {
                flex: 1;
                display: flex;
                flex-direction: column;
                min-height: 0;
            }

            .chat-header {
                padding: 10px 12px;
                flex-shrink: 0;
            }

            .chat-header-left {
                gap: 8px;
                overflow: hidden;
            }

            .room-name {
                font-size: 15px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 160px;
            }

            .room-meta {
                font-size: 11px;
            }

            .back-btn {
                width: 34px;
                height: 34px;
                font-size: 16px;
                flex-shrink: 0;
            }

            .messages-container {
                padding: 12px;
                gap: 10px;
            }

            .message-bubble {
                max-width: 82%;
            }

            .chat-input-area {
                padding: 8px 12px;
                flex-shrink: 0;
            }

            .input-btn, .send-btn {
                width: 38px;
                height: 38px;
                font-size: 18px;
            }

            .members-list {
                max-height: 180px;
            }

            .scroll-bottom {
                right: 15px;
                bottom: 80px;
            }
        }

        @media (max-width: 480px) {
            .room-name {
                max-width: 120px;
                font-size: 14px;
            }
            .chat-header {
                padding: 8px 10px;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/css/system_dialogs.css">
    <script src="../assets/js/system_dialogs.js" defer></script>
    <link rel="stylesheet" href="../assets/css/loading.css">
    <link rel="stylesheet" href="../assets/css/themes.css">
    <link rel="stylesheet" href="../assets/css/hyper-flux.css">
</head>

<body>
    <!-- Hyper-Flux Orbs -->
    <div class="hyper-flux-orb hyper-flux-orb-1"></div>
    <div class="hyper-flux-orb hyper-flux-orb-2"></div>
    <div class="hyper-flux-orb hyper-flux-orb-3"></div>

    <div class="app-container">
        <canvas id="bg-canvas"></canvas>
        <!-- Left Navigation Sidebar -->
        <div class="nav-sidebar">
            <div class="logo">
                <img src="../assets/photos/logo.png" alt="CONNECTXION">
            </div>

            <div class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'home.php' ? 'active' : ''; ?>"
                title="CHAT HUB" onclick="window.location.href='home.php'">
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
                <div class="avatar <?php echo (isset($user_data['last_active']) && $user_data['last_active'] && (time() - strtotime($user_data['last_active']) < 300)) ? 'online' : ''; ?>"
                    onclick="window.location.href='profile.php'">
                    <?php if (!empty($user_data['avatar']) && file_exists($user_data['avatar'])): ?>
                        <img src="<?php echo $user_data['avatar']; ?>" alt="Avatar">
                    <?php else: ?>
                        <?php echo getAvatarLetter($user_data['username']); ?>
                    <?php endif; ?>
                </div>

                <div class="logout-btn" title="LOGOUT" onclick="showLogoutModal()">
                    ⏻
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <?php if ($error && !$is_member): ?>
                <div class="not-member">
                    <div class="not-member-icon">🔒</div>
                    <div class="not-member-title">PRIVATE SQUAD</div>
                    <div class="not-member-text"><?php echo $error; ?></div>
                    <a href="home.php" class="back-btn">BACK TO HUB</a>
                </div>
            <?php elseif ($error): ?>
                <div class="alert error">
                    <span>⚠️</span>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($success) && !empty($success) && $is_member): ?>
                <div class="alert success">
                    <span>✓</span>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($is_member): ?>
                <!-- Chat Container -->
                <div class="chat-container">
                    <!-- Messages Area -->
                    <div class="messages-area">
                        <!-- Chat Header -->
                        <div class="chat-header">
                            <div class="chat-header-left">
                                <button class="back-btn" onclick="window.location.href='home.php'"
                                    title="BACK TO HUB">←</button>
                                <div class="room-info">
                                    <div class="room-avatar" id="headerRoomAvatar">
                                        <?php if (!empty($room['room_photo']) && file_exists($room['room_photo'])): ?>
                                            <img src="<?php echo $room['room_photo']; ?>" alt="Group Photo">
                                        <?php else: ?>
                                            <span>👥</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="room-details">
                                        <div class="room-name" id="headerRoomName"><?php echo htmlspecialchars($room['room_name']); ?></div>
                                        <div class="room-meta">
                                            <span>👥 <?php echo $total_members; ?> / <?php echo $room['max_members'] ?? 50; ?>
                                                members</span>
                                            <span>•</span>
                                            <span>Host: <?php echo htmlspecialchars($room['creator_name']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="chat-actions">
                                <button class="action-btn" onclick="toggleMemberList()" title="TOGGLE MEMBERS">👥</button>
                                <?php if ($user_role === 'admin'): ?>
                                    <button class="action-btn" onclick="showGroupSettings()" title="GROUP SETTINGS">⚙️</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Reply Preview -->
                        <div class="reply-preview" id="replyPreview" style="display: none;">
                            <div class="reply-preview-content">
                                <div class="reply-preview-header" id="replyPreviewHeader">REPLYING TO</div>
                                <div class="reply-preview-text" id="replyPreviewText">Message preview</div>
                            </div>
                            <button class="cancel-reply" onclick="cancelReply()">✕</button>
                        </div>

                        <!-- Messages Container - Scroll Panel -->
                        <div class="messages-container scroll-panel" id="messagesContainer">
                            <!-- Messages will be loaded here -->
                            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <div style="font-size: 60px; margin-bottom: 20px;">💬</div>
                                <h3>WELCOME TO THE SQUAD!</h3>
                                <p>Start the conversation</p>
                            </div>
                        </div>

                        <!-- Chat Input -->
                        <div class="chat-input-area">
                            <form class="chat-form" id="sendForm">
                                <input type="hidden" name="room_id" value="<?php echo $room_id; ?>">
                                <input type="hidden" name="reply_to" id="replyToInput">
                                <input type="text" name="message" id="messageInput" placeholder="TYPE YOUR MESSAGE..."
                                    autocomplete="off">

                                <div class="input-actions">
                                    <button type="button" class="input-btn" onclick="showEmojiPicker()"
                                        title="EMOJI">😊</button>
                                    <button type="button" class="input-btn" onclick="showAttachMenu()"
                                        title="ATTACH">📎</button>
                                    <button type="submit" class="send-btn" id="sendBtn" disabled>➤</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="chat-sidebar scroll-panel" id="memberSidebar">
                        <?php if ($user_role === 'admin'): ?>
                            <!-- Group Management Settings (Integrated) -->
                            <div class="room-details" id="sidebarSettings" style="border-bottom: 2px solid var(--accent); margin-bottom: 25px;">
                                <div class="section-title" style="color: var(--accent);">
                                    <span>⚙️</span> SQUAD SETTINGS
                                </div>
                                <form id="sidebarGroupSettingsForm" onsubmit="updateGroupInfo(event)">
                                    <input type="hidden" name="room_id" value="<?php echo $room_id; ?>">
                                    
                                    <div class="form-group" style="margin: 15px 0;">
                                        <label style="font-size: 10px; color: var(--text-muted); font-weight: 800; display: block; margin-bottom: 5px; letter-spacing: 1px;">SQUAD NAME</label>
                                        <input type="text" name="room_name" id="settingsRoomName" value="<?php echo htmlspecialchars($room['room_name']); ?>" 
                                            style="width: 100%; padding: 12px; background: rgba(0,0,0,0.3); border: 1px solid var(--border); border-radius: 12px; color: white; font-size: 14px; transition: all 0.3s;"
                                            onfocus="this.style.borderColor='var(--accent)'; this.style.boxShadow='0 0 10px var(--accent-glow)';"
                                            onblur="this.style.borderColor='var(--border)'; this.style.boxShadow='none';">
                                    </div>

                                    <div class="form-group" style="margin-bottom: 20px;">
                                        <label style="font-size: 10px; color: var(--text-muted); font-weight: 800; display: block; margin-bottom: 8px; letter-spacing: 1px;">SQUAD PHOTO</label>
                                        <div style="display: flex; align-items: center; gap: 15px; padding: 10px; background: rgba(255,255,255,0.03); border-radius: 12px;">
                                            <div id="settingsPhotoPreview" style="width: 50px; height: 50px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                                                <?php if (!empty($room['room_photo']) && file_exists($room['room_photo'])): ?>
                                                    <img src="<?php echo $room['room_photo']; ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <span style="font-size: 20px;">👥</span>
                                                <?php endif; ?>
                                            </div>
                                            <input type="file" name="room_photo" id="settingsRoomPhoto" accept="image/*" onchange="previewGroupPhoto(this)" style="display: none;">
                                            <button type="button" class="action-btn" onclick="document.getElementById('settingsRoomPhoto').click()" 
                                                style="width: auto; height: auto; padding: 8px 15px; font-size: 11px; font-weight: 800; background: var(--bg-tertiary); border: 1px solid var(--border);">CHOOSE FILE</button>
                                        </div>
                                    </div>

                                    <button type="submit" class="action-btn" style="width: 100%; height: 45px; background: var(--gradient-primary); color: white; border: none; font-size: 13px; font-weight: 900; letter-spacing: 2px; border-radius: 12px; box-shadow: var(--glow-effect); transition: all 0.3s;"
                                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 20px var(--accent-glow)';"
                                        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='var(--glow-effect)';">SAVE CHANGES</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="room-details">
                            <div class="section-title">
                                <span>📋</span> ABOUT ROOM
                            </div>
                            <div class="room-description">
                                <?php echo htmlspecialchars($room['room_description'] ?? 'No description yet'); ?>
                            </div>
                        </div>

                        <div class="members-section">
                            <div class="section-title">
                                <span>👥</span> MEMBERS
                                <span
                                    class="member-count"><?php echo $total_members; ?>/<?php echo $room['max_members'] ?? 50; ?></span>
                            </div>

                            <!-- Members List - Scroll Panel -->
                            <div class="members-list scroll-panel" id="membersList">
                                <?php if ($members_result && $members_result->num_rows > 0): ?>
                                    <?php
                                    $members_result->data_seek(0);
                                    while ($member = $members_result->fetch_assoc()):
                                        $is_online = isset($member['last_active']) && $member['last_active'] && (time() - strtotime($member['last_active']) < 300);
                                        ?>
                                        <div class="member-item">
                                            <div class="member-avatar <?php echo $is_online ? 'online' : ''; ?>">
                                                <?php if (!empty($member['avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars($member['avatar']); ?>" 
                                                         alt="<?php echo htmlspecialchars($member['username']); ?>" 
                                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">
                                                <?php else: ?>
                                                    <?php echo getAvatarLetter($member['username']); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="member-info">
                                                <div class="member-name">
                                                    <?php echo htmlspecialchars($member['username']); ?>
                                                    <?php if ($member['role'] == 'admin'): ?>
                                                        <span class="member-role">HOST</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="member-status">
                                                    <span class="status-dot"
                                                        style="background: <?php echo $is_online ? 'var(--success)' : 'var(--text-muted)'; ?>;"></span>
                                                    <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                                                </div>
                                            </div>
                                            <?php if ($user_role == 'admin' && $member['user_id'] != $user_id): ?>
                                                <form method="POST" style="margin:0;"
                                                    onsubmit="return ConnectXion.confirmForm(event, 'Remove <?php echo htmlspecialchars($member['username']); ?> from the group?')">
                                                    <input type="hidden" name="member_id" value="<?php echo $member['user_id']; ?>">
                                                    <button type="submit" name="remove_member" class="remove-member"
                                                        title="REMOVE">✕</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Add Member Section (only for admins) -->
                        <?php if ($user_role == 'admin' && $total_members < ($room['max_members'] ?? 50)): ?>
                            <div class="add-member-section">
                                <div class="section-title">
                                    <span>➕</span> ADD MEMBERS
                                    <?php if ($total_friends > 0): ?>
                                        <span class="member-count"><?php echo $total_friends; ?> available</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($friends_result && $friends_result->num_rows > 0): ?>
                                    <!-- Friends List - Scroll Panel -->
                                    <div class="friends-list scroll-panel">
                                        <?php
                                        $friends_result->data_seek(0);
                                        while ($friend = $friends_result->fetch_assoc()):
                                            $is_online = isset($friend['last_active']) && $friend['last_active'] && (time() - strtotime($friend['last_active']) < 300);
                                            ?>
                                            <div class="friend-item">
                                                <div class="friend-avatar">
                                                    <?php echo getAvatarLetter($friend['username']); ?>
                                                </div>
                                                <div class="friend-info">
                                                    <div class="friend-name"><?php echo htmlspecialchars($friend['username']); ?></div>
                                                    <div class="friend-status">
                                                        <span class="status-dot"
                                                            style="background: <?php echo $is_online ? 'var(--success)' : 'var(--text-muted)'; ?>; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px;"></span>
                                                        <?php echo $is_online ? 'ONLINE' : 'OFFLINE'; ?>
                                                    </div>
                                                </div>
                                                <form method="POST" style="margin: 0;"
                                                    onsubmit="return ConnectXion.confirmForm(event, 'Add <?php echo htmlspecialchars($friend['username']); ?> to this group?')">
                                                    <input type="hidden" name="member_id" value="<?php echo $friend['user_id']; ?>">
                                                    <button type="submit" name="add_member" class="add-friend-btn"
                                                        title="ADD TO GROUP">+</button>
                                                </form>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-friends">
                                        No friends available to add.<br>
                                        <a href="friends.php" style="color: var(--accent); text-decoration: none;">Add friends
                                            first</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($user_role == 'admin' && $total_members >= ($room['max_members'] ?? 50)): ?>
                            <div class="add-member-section">
                                <div class="no-friends" style="color: var(--warning);">
                                    ⚠️ Maximum member limit reached (<?php echo $room['max_members'] ?? 50; ?>)
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="invite-section">
                            <div class="section-title">
                                <span>🔗</span> INVITE LINK
                            </div>
                            <div class="invite-link" id="inviteLink">
                                <?php echo "http://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . "/join_room.php?room_id=$room_id"; ?>
                            </div>
                            <button class="copy-btn" onclick="copyInviteLink()">COPY LINK</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Emoji Picker Modal -->
    <div class="modal" id="emojiModal">
        <div class="modal-content" style="max-width: 400px;">
            <h3>CHOOSE EMOJI</h3>
            <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin: 25px 0;">
                <?php
                $emojis = ['😊', '😂', '❤️', '👍', '😢', '🎉', '😍', '🔥', '✨', '⭐', '🍕', '🎮', '😎', '🥺', '😡', '💀', '✅', '❌'];
                foreach ($emojis as $emoji) {
                    echo "<button class=\"reaction-emoji\" onclick=\"insertEmoji('$emoji')\">$emoji</button>";
                }
                ?>
            </div>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="hideEmojiPicker()">CLOSE</button>
            </div>
        </div>
    </div>

    <!-- Edit Message Modal -->
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

    <!-- Delete Message Modal -->
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

    <!-- Logout Confirmation Modal -->
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

    <!-- Hidden Logout Form -->
    <form method="POST" action="logout.php" id="logoutForm" style="display: none;">
        <input type="hidden" name="logout" value="1">
    </form>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-container">
            <div class="loading-logo-wrapper">
                <div class="tech-ring outer"></div>
                <div class="tech-ring middle"></div>
                <div class="tech-ring inner"></div>
                <div class="loading-logo">
                    <img src="../assets/photos/logo.png" alt="Logo">
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

    <!-- File Upload Form -->
    <form id="fileUploadForm" style="display: none;">
        <input type="file" id="fileInput" name="file" onchange="uploadFile(this)">
    </form>

    <!-- Scroll to bottom button -->
    <button class="scroll-bottom" id="scrollBottomBtn" onclick="scrollToBottom()">↓</button>

    <script>
        // Global variables
        let currentMessageId = null;
        let currentReactionMessageId = null;
        let replyToMessageId = null;
        let replyToMessageText = '';
        let replyToMessageSender = '';
        let editingMessageId = null;
        let lastMessageId = 0;
        let isLoading = false;
        let checkInterval;
        const roomId = <?php echo $room_id; ?>;
        const userId = <?php echo $user_id; ?>;

        // Logout modal functions
        function showLogoutModal() {
            document.getElementById('logoutModal').classList.add('show');
        }

        function hideLogoutModal() {
            document.getElementById('logoutModal').classList.remove('show');
        }

        // Group Settings functions (Updated for Sidebar)
        function showGroupSettings() {
            const sidebar = document.getElementById('memberSidebar');
            if (!sidebar.classList.contains('active')) {
                toggleMemberList();
            }
            // Scroll to settings section if it exists
            const settingsSection = document.getElementById('sidebarSettings');
            if (settingsSection) {
                settingsSection.scrollIntoView({ behavior: 'smooth' });
            }
        }

        function hideGroupSettings() {
            const sidebar = document.getElementById('memberSidebar');
            if (sidebar.classList.contains('active')) {
                toggleMemberList();
            }
        }

        function previewGroupPhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('settingsPhotoPreview').innerHTML = `<img src="${e.target.result}" style="width: 100%; height: 100%; object-fit: cover;">`;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateGroupInfo(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.textContent;

            btn.disabled = true;
            btn.textContent = 'SAVING...';

            fetch('update_group_info.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = originalText;

                if (data.success) {
                    showToast('✅ Group updated successfully', 'success');
                    if (data.room_name) {
                        document.getElementById('headerRoomName').textContent = data.room_name;
                        document.getElementById('settingsRoomName').value = data.room_name;
                    }
                    if (data.room_photo) {
                        const avatarHtml = `<img src="${data.room_photo}" alt="Group Photo">`;
                        document.getElementById('headerRoomAvatar').innerHTML = avatarHtml;
                        document.getElementById('settingsPhotoPreview').innerHTML = `<img src="${data.room_photo}" style="width: 100%; height: 100%; object-fit: cover;">`;
                    }
                    // No need to hide modal anymore as it's part of sidebar
                    // hideGroupSettings(); 
                } else {
                    showToast('❌ ' + (data.error || 'Failed to update group'), 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = originalText;
                console.error('Error:', err);
                showToast('❌ System error', 'error');
            });
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

        // Load messages
        function loadMessages() {
            if (isLoading) return;

            isLoading = true;
            console.log('Loading messages for room:', roomId);

            fetch(`get_group_messages.php?room_id=${roomId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Messages loaded:', data);

                    if (data.success) {
                        const container = document.getElementById('messagesContainer');

                        if (data.messages.length > 0) {
                            container.innerHTML = '';

                            data.messages.forEach(msg => {
                                const isOwnMsg = msg.user_id == userId;
                                const messageWrapper = document.createElement('div');
                                messageWrapper.className = `message ${isOwnMsg ? 'message-own' : 'message-other'}`;
                                messageWrapper.dataset.id = msg.id;

                                // Avatar generation
                                let avatarHtml = '';
                                if (msg.avatar && msg.avatar.trim() !== '') {
                                    avatarHtml = `<img src="${msg.avatar}" class="message-avatar-img" alt="Avatar">`;
                                } else {
                                    const initial = msg.username ? msg.username.charAt(0).toUpperCase() : '?';
                                    avatarHtml = `<div class="message-avatar-letter">${initial}</div>`;
                                }
                                
                                const isOwn = (msg.user_id == userId);
                                const senderName = isOwn ? 'YOU' : msg.username;
                                
                                // Build message HTML
                                let messageHTML = `<div class="message-wrapper">`;

                                // Avatar Container
                                messageHTML += `
                                    <div class="message-avatar-container">
                                        ${avatarHtml}
                                    </div>
                                    <div class="message-content-wrapper">
                                        <div class="message-sender">${senderName}</div>
                                `;

                                // Reply preview if this is a reply
                                if (msg.reply_to) {
                                    messageHTML += `
                                    <div class="reply-preview-message">
                                        <div class="reply-sender">REPLY TO ${msg.reply_to_sender || 'MESSAGE'}</div>
                                        <div class="reply-text">${msg.reply_to_text || ''}</div>
                                    </div>
                                `;
                                }

                                // Main message content
                                if (msg.is_deleted) {
                                    const deleteText = isOwnMsg ? 'You unsent a message' : 'This message was unsent';
                                    messageHTML += `
                                        <div class="message-bubble message-deleted" style="opacity: 0.6; font-style: italic;">
                                            <span style="margin-right: 8px;">🚫</span> ${deleteText}
                                        </div>
                                    `;
                                } else {
                                    messageHTML += `
                                        <div class="message-bubble">
                                            <span class="message-text">${msg.message}</span>
                                            ${msg.is_edited ? '<span class="edited-indicator" style="font-size:10px;opacity:0.5;margin-left:5px;">(edited)</span>' : ''}
                                        </div>
                                `;
                                }

                                // Reactions
                                if (msg.reactions && Object.keys(msg.reactions).length > 0) {
                                    messageHTML += `<div class="message-reactions" id="reactions-${msg.id}">`;
                                    for (const [reaction, reactors] of Object.entries(msg.reactions)) {
                                        const userReacted = reactors.some(r => r.user_id == userId);
                                        const names = reactors.map(r => r.username);
                                        const namesJson = JSON.stringify(names).replace(/"/g, '&quot;');
                                        messageHTML += `
                                        <span class="reaction ${userReacted ? 'active' : ''}" 
                                              data-users="${namesJson}" 
                                              onclick="showReactorList(event, '${reaction}', ${namesJson})">
                                            ${reaction} <span class="reaction-count">${reactors.length}</span>
                                        </span>
                                    `;
                                    }
                                    messageHTML += `</div>`;
                                }

                                // Message actions (only for non-deleted messages)
                                if (!msg.is_deleted) {
                                    messageHTML += `
                                    <div class="message-actions">
                                        <button class="msg-action reply" onclick="setReply(${msg.id}, '${msg.message.replace(/'/g, "\\'")}', '${senderName}')" title="REPLY">↩️</button>
                                        <button class="msg-action react" onclick="showReactionPicker(${msg.id}, this)" title="REACT">😊</button>
                                        ${isOwn ? `
                                            <button class="msg-action edit" onclick="editMessage(${msg.id}, '${msg.message.replace(/'/g, "\\'")}')" title="EDIT">✏️</button>
                                            <button class="msg-action delete" onclick="deleteMessage(${msg.id})" title="DELETE">🗑️</button>
                                        ` : ''}
                                    </div>
                                `;
                                }

                                // Meta / Time (Now inside content-wrapper)
                                messageHTML += `
                                    <div class="message-meta">
                                        <span class="message-time">${msg.time}</span>
                                    </div>
                                `;

                                // Close content wrapper
                                messageHTML += `</div>`;
                                
                                // Close main message-wrapper
                                messageHTML += `</div>`;

                                messageWrapper.innerHTML = messageHTML;
                                container.appendChild(messageWrapper);
                            });

                            scrollToBottom();
                        } else {
                            container.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <div style="font-size: 60px; margin-bottom: 20px;">💬</div>
                                <h3>WELCOME TO THE SQUAD!</h3>
                                <p>Start the conversation</p>
                            </div>
                        `;
                        }
                    }

                    isLoading = false;
                })
                .catch(err => {
                    console.error('Error loading messages:', err);
                    isLoading = false;
                    showToast('Failed to load messages: ' + err.message, 'error');
                });
        }

        // Send message
        document.getElementById('sendForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            const sendBtn = document.getElementById('sendBtn');

            if (!message) return;

            sendBtn.disabled = true;

            const formData = new FormData(this);

            showToast('📤 SENDING...', 'info');

            fetch('send_group_message.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        input.value = '';
                        cancelReply();
                        loadMessages();

                        if (data.was_censored) {
                            showToast('⚠️ Message contained inappropriate words and was filtered', 'warning');
                        } else {
                            showToast('✅ MESSAGE SENT', 'success');
                        }
                    } else {
                        showToast('❌ ' + (data.error || 'Failed to send message'), 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showToast('❌ Failed to send message', 'error');
                })
                .finally(() => {
                    sendBtn.disabled = false;
                });
        });

        // Message input handler
        document.getElementById('messageInput').addEventListener('input', function () {
            document.getElementById('sendBtn').disabled = !this.value.trim();
        });

        // Set reply
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

        // Cancel reply
        function cancelReply() {
            replyToMessageId = null;
            document.getElementById('replyPreview').style.display = 'none';
            document.getElementById('replyToInput').value = '';
        }

        // Edit message
        function editMessage(messageId, messageText) {
            editingMessageId = messageId;
            document.getElementById('editMessageInput').value = messageText;
            document.getElementById('editModal').classList.add('show');
        }

        // Hide edit modal
        function hideEditModal() {
            document.getElementById('editModal').classList.remove('show');
            editingMessageId = null;
        }

        // Save edit
        function saveEdit() {
            const newText = document.getElementById('editMessageInput').value.trim();
            if (!newText || !editingMessageId) return;

            fetch('edit_group_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `message_id=${editingMessageId}&message=${encodeURIComponent(newText)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        hideEditModal();
                        loadMessages();
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

        // Delete message
        function deleteMessage(messageId) {
            currentMessageId = messageId;
            document.getElementById('deleteModal').classList.add('show');
        }

        // Hide delete modal
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            currentMessageId = null;
        }

        // Confirm delete
        function confirmDelete() {
            if (!currentMessageId) return;

            fetch('delete_group_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `message_id=${currentMessageId}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        hideDeleteModal();
                        loadMessages();
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

        // Show reaction picker
        function showReactionPicker(messageId, button) {
            currentReactionMessageId = messageId;

            const existingPicker = document.querySelector('.reaction-picker');
            if (existingPicker) existingPicker.remove();

            const picker = document.createElement('div');
            picker.className = 'reaction-picker';
            picker.innerHTML = `
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '👍')">👍</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '❤️')">❤️</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '😂')">😂</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '😮')">😮</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '😢')">😢</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '🔥')">🔥</button>
            <button class="reaction-emoji" onclick="addReaction(${messageId}, '🎮')">🎮</button>
        `;

            const rect = button.getBoundingClientRect();
            picker.style.position = 'fixed';
            picker.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
            picker.style.left = (rect.left - 100) + 'px';

            document.body.appendChild(picker);

            setTimeout(() => {
                document.addEventListener('click', function closePicker(e) {
                    if (!picker.contains(e.target)) {
                        picker.remove();
                        document.removeEventListener('click', closePicker);
                    }
                });
            }, 100);
        }

        // Add reaction
        function addReaction(messageId, reaction) {
            fetch('add_group_reaction.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `message_id=${messageId}&reaction=${reaction}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadMessages();
                    } else {
                        showToast(data.error || 'Failed to add reaction', 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showToast('Failed to add reaction', 'error');
                });

            document.querySelector('.reaction-picker')?.remove();
        }

        function showReactorList(event, emoji, users) {
            event?.stopPropagation();
            const popup = document.getElementById('reactorPopup');
            const header = document.getElementById('reactorPopupHeader');
            const list = document.getElementById('reactorPopupList');

            if (!popup) return;

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

        // Hide reactor popup when clicking outside
        document.addEventListener('click', function (e) {
            const popup = document.getElementById('reactorPopup');
            if (popup && !popup.contains(e.target)) {
                popup.style.display = 'none';
            }
        });

        // File upload
        function showAttachMenu() {
            document.getElementById('fileInput').click();
        }

        function uploadFile(input) {
            const file = input.files[0];
            if (!file) return;

            const MAX_FILE_SIZE = 50 * 1024 * 1024;

            if (file.size > MAX_FILE_SIZE) {
                showToast(`FILE TOO LARGE. Maximum size is 50MB`, 'error');
                input.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('file', file);
            formData.append('room_id', roomId);
            if (replyToMessageId) {
                formData.append('reply_to', replyToMessageId);
            }

            showToast(`📤 UPLOADING: ${file.name}...`, 'info');

            fetch('upload_group_file.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showToast('✅ FILE UPLOADED', 'success');
                        cancelReply();
                        loadMessages();
                    } else {
                        showToast('❌ UPLOAD FAILED: ' + (result.error || 'Unknown error'), 'error');
                    }
                })
                .catch(err => {
                    console.error('Upload error:', err);
                    showToast('❌ UPLOAD FAILED', 'error');
                })
                .finally(() => {
                    input.value = '';
                });
        }

        // Emoji picker
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

        // Scroll functions
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

        // Toggle member sidebar (Desktop & Mobile)
        function toggleMemberList() {
            const sidebar = document.getElementById('memberSidebar');
            sidebar.classList.toggle('active');
        }

        // Copy invite link
        function copyInviteLink() {
            const link = document.getElementById('inviteLink').textContent.trim();
            navigator.clipboard.writeText(link).then(() => {
                showToast('INVITE LINK COPIED!');
            });
        }

        // Toast notification
        function showToast(message, type = 'info') {
            return; // Disabled for clean UI
        }

        // Check for new messages
        function checkForNewMessages() {
            if (isLoading) return;

            const lastMsg = document.querySelector('.message-wrapper:last-child');
            const lastId = lastMsg ? lastMsg.dataset.id : 0;

            fetch(`check_new_group_messages.php?room_id=${roomId}&last_id=${lastId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.has_new) {
                        loadMessages();
                    }
                })
                .catch(err => console.error('Error checking new messages:', err));
        }

        // Initialize
        window.onload = function () {
            loadMessages();

            const container = document.getElementById('messagesContainer');
            container.addEventListener('scroll', checkScroll);

            checkInterval = setInterval(checkForNewMessages, 3000);
        };

        // Clean up on page unload
        window.onbeforeunload = function () {
            if (checkInterval) {
                clearInterval(checkInterval);
            }
        };

        // Close modals on outside click
        window.addEventListener('click', function (e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
            }
        });

        // Handle escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
                cancelReply();
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

    <script src="particles.js"></script>
</body>

</html>
