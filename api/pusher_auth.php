<?php
require_once 'db.php';
require_once 'pusher_config.php';
if (!isLoggedIn()) { header('', true, 403); echo 'Forbidden'; exit; }
$socket_id = $_POST['socket_id'] ?? null;
$channel_name = $_POST['channel_name'] ?? null;
if (!$socket_id || !$channel_name) { header('', true, 400); echo 'Bad Request'; exit; }
$user_id = $_SESSION['user_id'];
$expected_channel = 'private-user-' . $user_id;
if ($channel_name !== $expected_channel && strpos($channel_name, 'private-group-') !== 0) {
      header('', true, 403); echo 'Unauthorized'; exit;
}
if ($pusher) { echo $pusher->socket_auth($channel_name, $socket_id); }
else { header('', true, 500); echo 'Pusher Error'; }
?>
