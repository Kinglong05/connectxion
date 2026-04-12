<?php
require_once 'db.php';

if (isLoggedIn()) {
    header("Location: home.php");
    exit();
}

if (!isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $otp = trim($_POST['otp']);
    
    if (empty($otp)) {
        $error = "Please enter the OTP";
    } else {
        $user_id = $_SESSION['reset_user_id'];
        $result = prepareAndExecute($conn, "SELECT reset_otp, reset_otp_expiry FROM users WHERE user_id = ?", "i", $user_id);
        
        if ($result && $user = $result->get_result()->fetch_assoc()) {
            if ($user['reset_otp'] === $otp) {
                
                if (strtotime($user['reset_otp_expiry']) > time()) {
                    
                    $_SESSION['otp_verified'] = true;
                    header("Location: reset_password.php");
                    exit();
                } else {
                    $error = "OTP has expired. Please request a new one.";
                }
            } else {
                $error = "Invalid OTP.";
            }
        } else {
            $error = "User not found.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VERIFY OTP | CONNECTXION</title>

    <!-- Use same styling as login.php -->
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', 'Poppins', system-ui, sans-serif;
            background: var(--bg-primary); min-height: 100vh; display: flex; align-items: center; justify-content: center;
            position: relative; overflow: hidden; color: var(--text-primary);
        }
        }
        body::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: repeating-linear-gradient(0deg, rgba(0,0,0,0.15) 0px, rgba(0,0,0,0.15) 1px, transparent 1px, transparent 2px);
            pointer-events: none; z-index: 1;
        }
        .orb { position: absolute; width: 300px; height: 300px; border-radius: 50%; filter: blur(80px); opacity: 0.15; z-index: 0; }
        .orb-1 { background: var(--accent); top: -100px; left: -100px; }
        .orb-2 { background: var(--accent-secondary); bottom: -100px; right: -100px; }
        
        .auth-container { width: 500px; max-width: 90%; position: relative; z-index: 10; }
        .gaming-panel {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px; overflow: hidden; box-shadow: 0 30px 60px rgba(0,0,0,0.7);
            border: 1px solid var(--border); position: relative; padding: 40px;
        }
        .gaming-panel::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px;
            background: var(--gradient-primary); z-index: 2;
        }
        .form-header { margin-bottom: 30px; text-align: center; }
        .form-header h3 { font-size: 24px; font-weight: 700; color: var(--text-primary); text-transform: uppercase; margin-bottom: 8px; }
        .form-header p { color: var(--text-muted); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        
        .form-group { margin-bottom: 25px; }
        .form-label { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; color: var(--accent); font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
        
        .input-wrapper input {
            width: 100%; padding: 16px 18px; background: var(--bg-card); border: 2px solid var(--border);
            border-radius: 12px; font-size: 15px; color: var(--text-primary); transition: all 0.3s; font-family: 'Poppins', sans-serif;
            text-align: center; letter-spacing: 5px; font-weight: bold; font-size: 20px;
        }
        .input-wrapper input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px var(--accent-glow); }
        .input-wrapper input::placeholder { color: var(--text-muted); text-transform: uppercase; letter-spacing: 2px; font-size: 14px; font-weight: normal; }
        
        .game-button {
            width: 100%; padding: 16px; background: var(--gradient-primary); border: none; border-radius: 12px;
            color: white; font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 2px;
            cursor: pointer; position: relative; overflow: hidden; transition: all 0.3s; box-shadow: var(--glow-effect); padding-top: 15px;
        }
        .game-button:hover { transform: translateY(-2px); box-shadow: 0 10px 25px var(--accent-glow); }
        
        .message { padding: 16px 20px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; font-weight: 500; border-left: 4px solid; }
        .message.error { background: rgba(240, 71, 71, 0.1); border-left-color: var(--danger); color: var(--danger); }
        .message.success { background: rgba(67, 181, 129, 0.1); border-left-color: var(--success); color: var(--success); }
        
        .resend-link {
            display: block; text-align: center; margin-top: 20px; color: var(--text-secondary); text-decoration: none;
            font-size: 13px; text-transform: uppercase; font-weight: 600; letter-spacing: 1px; transition: color 0.3s;
        }
        .resend-link:hover { color: var(--accent); }
    </style>
    <!-- Global Mobile Responsive Overrides -->
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="loading.css">
    <link rel="stylesheet" href="themes.css">
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    
    <div class="auth-container">
        <div class="gaming-panel">
            <div class="form-header">
                <h3>VERIFY SIGNAL</h3>
                <p>Check your email for the 6-digit OTP code</p>
            </div>
            
            <div class="message success"><span>✉️</span>OTP has been dispatched.</div>
            
            <?php if ($error): ?>
                <div class="message error"><span>⚠️</span><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <div class="form-label"><span>🔢</span> 6-DIGIT CODE</div>
                    <div class="input-wrapper">
                        <input type="text" name="otp" placeholder="000000" maxlength="6" required pattern="\d{6}">
                    </div>
                </div>
                
                <button type="submit" class="game-button">VERIFY CODE</button>
            </form>
            
            <a href="forgot_password.php" class="resend-link">REQUEST NEW CODE</a>
        </div>
    </div>
</body>
</html>
