<?php

require_once 'db.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$friend_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 50;

if (!$friend_id) {
    echo '<div class="empty-state"><p>Please select a chat</p></div>';
    exit;
}

$check_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
$check_stmt->bind_param("i", $friend_id);
$check_stmt->execute();
$check = $check_stmt->get_result();

if (!$check || $check->num_rows === 0) {
    echo '<div class="empty-state"><p>User not found</p></div>';
    exit;
}
$check_stmt->close();

$stmt = $conn->prepare("
    UPDATE messages 
    SET is_read = 1, read_status = 'read', read_at = NOW() 
    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
");
$stmt->bind_param("ii", $friend_id, $user_id);
$stmt->execute();
$stmt->close();

$count_stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM messages 
    WHERE (sender_id = ? AND receiver_id = ?)
       OR (sender_id = ? AND receiver_id = ?)
");
$count_stmt->bind_param("iiii", $user_id, $friend_id, $friend_id, $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_messages = $count_result ? ($count_result->fetch_assoc()['total'] ?? 0) : 0;
$count_stmt->close();

$sql = "
    SELECT m.*, 
           u.username as sender_name,
           u.avatar as sender_avatar,
           GROUP_CONCAT(
               CONCAT(mr.reaction, ':', COALESCE(u2.username, ''))
               SEPARATOR '|'
           ) as reactions_data
    FROM messages m
    JOIN users u ON u.user_id = m.sender_id
    LEFT JOIN message_reactions mr ON m.message_id = mr.message_id
    LEFT JOIN users u2 ON mr.user_id = u2.user_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    GROUP BY m.message_id, u.username, u.avatar
    ORDER BY m.created_at ASC
    LIMIT ? OFFSET ?
";

$list_stmt = $conn->prepare($sql);
$list_stmt->bind_param("iiiiii", $user_id, $friend_id, $friend_id, $user_id, $limit, $offset);
$list_stmt->execute();
$result = $list_stmt->get_result();

if (!$result || $result->num_rows === 0) {
    if ($offset === 0) {
        echo '<div class="empty-state"><p>No messages yet. Start the conversation!</p></div>';
    }
    exit;
}

$reply_ids = [];
$result->data_seek(0);
while ($msg = $result->fetch_assoc()) {
    if (!empty($msg['reply_to'])) {
        $reply_ids[] = (int)$msg['reply_to'];
    }
}
$result->data_seek(0);

$reply_messages = [];
if (!empty($reply_ids)) {
    $ids_str = implode(',', array_unique($reply_ids));
    $reply_result = $conn->query("
        SELECT m.message_id, m.message, m.message_type, m.file_path, 
               u.username as sender_name, u.user_id as sender_id
        FROM messages m
        JOIN users u ON u.user_id = m.sender_id
        WHERE m.message_id IN ($ids_str)
    ");
    
    if ($reply_result) {
        while ($r = $reply_result->fetch_assoc()) {
            $reply_messages[$r['message_id']] = $r;
        }
    }
}

function formatDate($timestamp) {
    $date = date('Y-m-d', strtotime($timestamp));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    if ($date == $today) return 'Today';
    if ($date == $yesterday) return 'Yesterday';
    return date('F j, Y', strtotime($timestamp));
}

function getFileIcon($file_path) {
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    $icons = [
        'pdf' => '📕', 'doc' => '📘', 'docx' => '📘',
        'xls' => '📗', 'xlsx' => '📗', 'ppt' => '📙',
        'pptx' => '📙', 'txt' => '📄', 'zip' => '📦',
        'rar' => '📦', '7z' => '📦', 'mp3' => '🎵',
        'wav' => '🎵', 'mp4' => '🎬', 'mov' => '🎬',
        'avi' => '🎬', 'jpg' => '🖼️', 'jpeg' => '🖼️',
        'png' => '🖼️', 'gif' => '🖼️', 'webp' => '🖼️'
    ];
    
    return $icons[$extension] ?? '📎';
}

function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}

function formatDuration($seconds) {
    $minutes = floor($seconds / 60);
    $seconds = $seconds % 60;
    return sprintf("%02d:%02d", $minutes, $seconds);
}

$current_date = '';

while ($msg = $result->fetch_assoc()) {
    $msg_date = date('Y-m-d', strtotime($msg['created_at']));
    
    
    if ($msg_date != $current_date) {
        $current_date = $msg_date;
        echo '<div class="date-separator"><span>' . formatDate($msg['created_at']) . '</span></div>';
    }
    
    $is_own = ($msg['sender_id'] == $user_id);
    $class = $is_own ? 'message-own' : 'message-other';
    $message_id = $msg['message_id'];
    
    
    $reactions = [];
    if (!empty($msg['reactions_data'])) {
        $reaction_parts = explode('|', $msg['reactions_data']);
        foreach ($reaction_parts as $part) {
            if (!empty($part)) {
                $parts = explode(':', $part);
                if (count($parts) >= 2) {
                    $emoji = $parts[0];
                    $username = $parts[1];
                    
                    if (!isset($reactions[$emoji])) {
                        $reactions[$emoji] = ['count' => 0, 'users' => []];
                    }
                    $reactions[$emoji]['count']++;
                    if (!empty($username)) {
                        $reactions[$emoji]['users'][] = $username;
                    }
                }
            }
        }
    }
    
    
    $is_deleted = isset($msg['deleted']) && $msg['deleted'] == 1;
    
    echo "<div class='message-group'>";
    
    echo "<div class='message $class' data-id='$message_id'>";
    echo "<div class='message-wrapper'>";
    
    
    $sender_avatar = $msg['sender_avatar'];
    $sender_initial = strtoupper(substr($msg['sender_name'], 0, 1));
    
    echo "<div class='message-avatar-container'>";
    if (!empty($sender_avatar) && file_exists($sender_avatar)) {
        echo "<img src='$sender_avatar' class='message-avatar-img' alt='Avatar'>";
    } else {
        echo "<div class='message-avatar-letter'>" . $sender_initial . "</div>";
    }
    echo "</div>"; 

    echo "<div class='message-content-wrapper'>";

    
    if (!$is_deleted) {
        echo "<div class='message-actions'>";
        
        echo "<button class='msg-action react' onclick='showReactionPicker($message_id, event)' title='Add reaction'>😊</button>";
        
        $safe_message = addslashes(htmlspecialchars($msg['message'] ?? 'Media message'));
        $sender_name = addslashes(htmlspecialchars($msg['sender_name']));
        
        echo "<button class='msg-action reply' onclick='setReply($message_id, \"$safe_message\", \"$sender_name\")' title='Reply'>↩️</button>";
        
        
        if ($is_own) {
            echo "<button class='msg-action edit' onclick='editMessage($message_id, \"$safe_message\")' title='Edit message'>✏️</button>";
            echo "<button class='msg-action delete' onclick='deleteMessage($message_id)' title='Unsend message'>🗑️</button>";
        }
        echo "</div>";
    }
    
    
    if (!$is_deleted && !empty($msg['reply_to']) && isset($reply_messages[$msg['reply_to']])) {
        $reply = $reply_messages[$msg['reply_to']];
        $reply_sender = $reply['sender_id'] == $user_id ? 'You' : htmlspecialchars($reply['sender_name']);
        $reply_content = '';
        
        if ($reply['message_type'] == 'image') {
            $reply_content = '📷 Photo';
        } elseif ($reply['message_type'] == 'file') {
            $reply_content = '📎 ' . basename($reply['file_path']);
        } elseif ($reply['message_type'] == 'voice') {
            $reply_content = '🎤 Voice message';
        } else {
            $reply_content = substr($reply['message'], 0, 50) . (strlen($reply['message']) > 50 ? '...' : '');
        }
        
        echo "<div class='reply-indicator' onclick='scrollToMessage(" . $msg['reply_to'] . ")'>";
        echo "<div class='reply-sender'>↪ Replying to " . $reply_sender . "</div>";
        echo "<div class='reply-content'>" . htmlspecialchars($reply_content) . "</div>";
        echo "</div>";
    }
    
    
    echo "<div class='message-sender'>";
    if ($is_deleted) {
        
        if ($is_own) {
            echo "You";
        } else {
            echo htmlspecialchars($msg['sender_name']);
        }
    } else {
        if ($is_own) {
            echo "You";
        } else {
            echo htmlspecialchars($msg['sender_name']);
        }
    }
    echo "</div>";
    
    echo "<div class='message-bubble'>";
    
    
    if ($is_deleted) {
        
        if ($is_own) {
            $unsent_text = "You unsent a message";
        } else {
            $unsent_text = htmlspecialchars($msg['sender_name']) . " unsent a message";
        }
        echo "<span class='message-text unsent-message'><i>" . $unsent_text . "</i></span>";
    } else {
        $message_type = $msg['message_type'] ?? 'text';
        
        
        if (!empty($msg['message']) && $message_type == 'text') {
            $message_text = htmlspecialchars($msg['message']);
            echo "<span class='message-text'>" . nl2br($message_text) . "</span>";
            
            if (isset($msg['edited']) && $msg['edited'] == 1) {
                echo "<span class='edited-indicator'>(edited)</span>";
            }
        }
        
        
        if ($message_type == 'image' && !empty($msg['file_path']) && file_exists($msg['file_path'])) {
            echo "<div class='message-image-container'>";
            echo "<img src='" . htmlspecialchars($msg['file_path']) . "' class='message-image' onclick='showImagePreview(\"" . htmlspecialchars($msg['file_path']) . "\")' loading='lazy'>";
            if (!empty($msg['message']) && $msg['message'] != '📷 Photo') {
                echo "<div class='image-caption'>" . htmlspecialchars($msg['message']) . "</div>";
            }
            echo "</div>";
        }
        
        
        if ($message_type == 'file' && !empty($msg['file_path']) && file_exists($msg['file_path'])) {
            $file_name = basename($msg['file_path']);
            $file_icon = getFileIcon($msg['file_path']);
            $file_size = !empty($msg['file_size']) ? formatFileSize($msg['file_size']) : 'Unknown size';
            
            echo "<a href='" . htmlspecialchars($msg['file_path']) . "' class='message-file' download target='_blank'>";
            echo "<span class='file-icon'>" . $file_icon . "</span>";
            echo "<div class='file-info'>";
            echo "<div class='file-name'>" . htmlspecialchars($file_name) . "</div>";
            echo "<div class='file-size'>" . $file_size . "</div>";
            echo "</div>";
            echo "<span class='download-icon'>⬇️</span>";
            echo "</a>";
            
            if (!empty($msg['message']) && $msg['message'] != '📎 ' . $file_name) {
                echo "<div class='file-caption'>" . htmlspecialchars($msg['message']) . "</div>";
            }
        }
        
        
        if ($message_type == 'voice' && !empty($msg['file_path']) && file_exists($msg['file_path'])) {
            
            $voice_query = "SELECT waveform_data, duration FROM voice_messages WHERE message_id = ?";
            $voice_stmt = $conn->prepare($voice_query);
            $voice_stmt->bind_param("i", $msg['message_id']);
            $voice_stmt->execute();
            $voice_result = $voice_stmt->get_result();
            $voice_data = $voice_result->fetch_assoc();
            
            $duration = $voice_data['duration'] ?? 0;
            $duration_formatted = formatDuration($duration);
            $audio_url = htmlspecialchars($msg['file_path']);
            $message_id = $msg['message_id'];
            $waveform_data = $voice_data && $voice_data['waveform_data'] ? json_decode($voice_data['waveform_data'], true) : [];
            
            echo '<div class="message-voice">';
            echo '<button class="voice-play" onclick="playVoiceMessage(\'' . $audio_url . '\', this, ' . $message_id . ')" data-message-id="' . $message_id . '" title="PLAY">▶️</button>';
            
            
            echo '<div class="voice-wave-container" data-message-id="' . $message_id . '">';
            if (!empty($waveform_data)) {
                
                $bars = array_slice($waveform_data, 0, 30);
                foreach ($bars as $value) {
                    $height = max(4, min(40, $value / 2.5));
                    echo '<div class="voice-wave-bar" data-message-id="' . $message_id . '" style="height: ' . $height . 'px;"></div>';
                }
            } else {
                
                for ($i = 0; $i < 30; $i++) {
                    $height = rand(8, 30);
                    echo '<div class="voice-wave-bar" data-message-id="' . $message_id . '" style="height: ' . $height . 'px;"></div>';
                }
            }
            echo '</div>';
            
            echo '<span class="voice-duration" id="voice-time-' . $message_id . '">00:00/' . $duration_formatted . '</span>';
            echo '</div>';
        }
    }
    
    echo "</div>"; 
    
    
    echo "<div class='message-meta'>";
    echo "<span class='message-time'>" . date('h:i A', strtotime($msg['created_at'])) . "</span>";
    
    if ($is_own && !$is_deleted) {
        $status = $msg['read_status'] ?? 'sent';
        if ($status == 'read') {
            echo "<span class='message-status read' title='Read'>✓✓</span>";
        } elseif ($status == 'delivered') {
            echo "<span class='message-status' title='Delivered'>✓✓</span>";
        } else {
            echo "<span class='message-status' title='Sent'>✓</span>";
        }
    }
    echo "</div>";
    
    
    if (!$is_deleted && !empty($reactions)) {
        echo "<div class='message-reactions'>";
        foreach ($reactions as $emoji => $data) {
            $user_list_json = htmlspecialchars(json_encode($data['users']), ENT_QUOTES);
            echo "<span class='reaction' data-users='$user_list_json' onclick='showReactorList(event, \"$emoji\", $user_list_json)' title='Click to see who reacted'>";
            echo "$emoji <span class='reaction-count'>" . $data['count'] . "</span>";
            echo "</span>";
        }
        echo "</div>";
    }
    
    echo "</div>"; 
    echo "</div>"; 
    echo "</div>"; 
    echo "</div>"; 
}

if ($offset + $limit < $total_messages) {
    echo "<div class='load-more-container' style='text-align: center; margin: 20px 0;'>";
    echo "<button class='load-more-btn' onclick='loadMoreMessages(" . ($offset + $limit) . ")'>LOAD MORE MESSAGES</button>";
    echo "</div>";
}
?>

<style>
.load-more-btn {
    background: var(--bg-tertiary);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    padding: 12px 30px;
    border-radius: 30px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    transition: all 0.3s;
}

.load-more-btn:hover {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--glow-effect);
}

/* Unsent message style */
.message-text.unsent-message {
    font-style: italic;
    opacity: 0.7;
    color: var(--text-muted);
}

.message-text.unsent-message i {
    font-style: italic;
}

/* Delete icon indicator */
.message-text.unsent-message::before {
    content: '🚫 ';
    font-style: normal;
    opacity: 0.8;
}

/* Voice message styles */
.message-voice {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 220px;
    max-width: 280px;
    padding: 5px 0;
}

.voice-play {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--accent);
    border: none;
    color: white;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    flex-shrink: 0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

.voice-play:hover {
    transform: scale(1.1);
    background: var(--accent-hover);
    box-shadow: 0 3px 8px rgba(0,0,0,0.3);
}

.voice-play.playing {
    background: #ff4444;
}

.voice-wave-container {
    flex: 1;
    height: 40px;
    display: flex;
    align-items: center;
    gap: 2px;
    cursor: pointer;
    padding: 0 2px;
    background: var(--bg-secondary);
    border-radius: 20px;
    overflow: hidden;
}

.voice-wave-bar {
    flex: 1;
    background: var(--accent);
    opacity: 0.5;
    border-radius: 2px;
    min-width: 2px;
    transition: opacity 0.2s, background 0.2s;
}

.voice-wave-bar.active {
    opacity: 1;
    background: var(--accent);
}

.message-own .voice-wave-bar {
    background: #00ff88;
}

.message-own .voice-wave-bar.active {
    background: #00ff88;
}

.voice-wave-container:hover .voice-wave-bar {
    opacity: 0.8;
}

.voice-duration {
    font-size: 12px;
    color: var(--text-muted);
    min-width: 70px;
    text-align: right;
    font-family: monospace;
}
</style>

<script>
// Voice playback with waveform animation
let currentPlayingAudio = null;
let currentPlayingMessageId = null;
let audioUpdateInterval = null;

function playVoiceMessage(audioSrc, button, messageId) {
    // Create audio element if it doesn't exist
    let audio = document.getElementById('voice-audio-' + messageId);
    
    if (!audio) {
        audio = new Audio(audioSrc);
        audio.id = 'voice-audio-' + messageId;
        audio.preload = 'none';
        document.body.appendChild(audio);
    }
    
    // If there's a currently playing audio and it's not this one, pause it
    if (currentPlayingAudio && currentPlayingAudio !== audio) {
        currentPlayingAudio.pause();
        const prevButton = document.querySelector(`.voice-play[data-message-id="${currentPlayingMessageId}"]`);
        if (prevButton) {
            prevButton.textContent = '▶️';
            prevButton.classList.remove('playing');
        }
        stopWaveformAnimation(currentPlayingMessageId);
    }
    
    if (audio.paused) {
        // Play audio
        audio.play()
            .then(() => {
                button.textContent = '⏸';
                button.classList.add('playing');
                currentPlayingAudio = audio;
                currentPlayingMessageId = messageId;
                
                // Update duration display
                audio.addEventListener('loadedmetadata', function() {
                    updateDuration(messageId, audio.currentTime, audio.duration);
                });
                
                // Start waveform animation
                startWaveformAnimation(messageId, audio);
                
                // Update time display
                audio.addEventListener('timeupdate', function() {
                    updateDuration(messageId, audio.currentTime, audio.duration);
                });
                
                audio.onended = function() {
                    button.textContent = '▶️';
                    button.classList.remove('playing');
                    stopWaveformAnimation(messageId);
                    updateDuration(messageId, 0, audio.duration);
                    currentPlayingAudio = null;
                    currentPlayingMessageId = null;
                };
            })
            .catch(err => {
                console.error('Error playing audio:', err);
                ConnectXion.alert('Failed to play voice message');
            });
    } else {
        // Pause audio
        audio.pause();
        button.textContent = '▶️';
        button.classList.remove('playing');
        stopWaveformAnimation(messageId);
        currentPlayingAudio = null;
        currentPlayingMessageId = null;
    }
}

function startWaveformAnimation(messageId, audio) {
    const waveContainer = document.querySelector(`.voice-wave-container[data-message-id="${messageId}"]`);
    if (!waveContainer) return;
    
    const bars = waveContainer.querySelectorAll('.voice-wave-bar');
    if (bars.length === 0) return;
    
    // Clear any existing interval
    stopWaveformAnimation(messageId);
    
    const intervalId = setInterval(() => {
        if (audio.paused || audio.ended) {
            stopWaveformAnimation(messageId);
            return;
        }
        
        const currentTime = audio.currentTime;
        const duration = audio.duration;
        
        if (duration && duration > 0) {
            const progress = (currentTime / duration) * 100;
            const activeBarIndex = Math.floor((progress / 100) * bars.length);
            
            bars.forEach((bar, index) => {
                if (index <= activeBarIndex) {
                    bar.classList.add('active');
                } else {
                    bar.classList.remove('active');
                }
            });
        }
    }, 50);
    
    // Store interval ID on the container
    waveContainer.dataset.intervalId = intervalId;
}

function stopWaveformAnimation(messageId) {
    const waveContainer = document.querySelector(`.voice-wave-container[data-message-id="${messageId}"]`);
    if (waveContainer && waveContainer.dataset.intervalId) {
        clearInterval(parseInt(waveContainer.dataset.intervalId));
        waveContainer.dataset.intervalId = '';
    }
    
    // Reset all bars for this message only
    if (waveContainer) {
        waveContainer.querySelectorAll('.voice-wave-bar').forEach(bar => {
            bar.classList.remove('active');
        });
    }
}

function updateDuration(messageId, current, total) {
    const durationSpan = document.getElementById('voice-time-' + messageId);
    if (durationSpan) {
        const currentFormatted = formatTime(current);
        const totalFormatted = formatTime(total);
        durationSpan.textContent = currentFormatted + '/' + totalFormatted;
    }
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '00:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return (mins < 10 ? '0' + mins : mins) + ':' + (secs < 10 ? '0' + secs : secs);
}

// Clean up on page unload
window.addEventListener('beforeunload', function() {
    if (currentPlayingAudio) {
        currentPlayingAudio.pause();
    }
    if (audioUpdateInterval) {
        clearInterval(audioUpdateInterval);
    }
});
</script>
