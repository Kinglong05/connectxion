<?php
require_once 'db.php';

if (isLoggedIn()) {
    header("Location: home.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "All fields are required";
    } else {
        $result = $conn->query("SELECT * FROM users WHERE email = '$email' OR username = '$email'");
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                header("Location: home.php");
                exit();
            } else {
                $error = "Invalid password";
            }
        } else {
            $error = "User not found";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LOGIN | CONNECTXION</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0a0b10">
    <link rel="apple-touch-icon" href="photos/app-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="themes.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Poppins', system-ui, sans-serif;
            background: var(--bg-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            color: var(--text-primary);
        }

        /* Gaming grid overlay */
        body::before {
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
            z-index: 1;
        }

        /* Static orbs - NO ANIMATION */
        .orb {
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            z-index: 0;
        }
        
        .orb-1 {
            background: var(--accent);
            top: -100px;
            left: -100px;
        }
        
        .orb-2 {
            background: var(--accent-secondary);
            bottom: -100px;
            right: -100px;
        }
        
        .orb-3 {
            background: #9966ff;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 500px;
            height: 500px;
            opacity: 0.1;
        }

        /* Main Container */
        .auth-container {
            width: 1000px;
            max-width: 90%;
            position: relative;
            z-index: 10;
        }

        /* Gaming Panel - Matching chat.php card style */
        .gaming-panel {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-tertiary));
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            border: 1px solid var(--border);
            position: relative;
        }

        .gaming-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: var(--gradient-primary);
            z-index: 2;
        }

        /* Panel Grid */
        .panel-grid {
            display: flex;
            min-height: 550px;
        }

        /* Left Side - Gaming Visual with Big Logo */
        .visual-side {
            flex: 1;
            background: linear-gradient(145deg, #1a1f25, #14181c);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1px solid var(--border);
        }

        .visual-side::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 50%, var(--accent-glow) 0%, transparent 70%);
            pointer-events: none;
        }

        .gaming-logo {
            text-align: center;
            position: relative;
            z-index: 2;
        }

        /* Big Logo Image */
        .logo-image {
            width: 350px;
            height: 350px;
            margin: 0 auto;
            position: relative;
            filter: drop-shadow(var(--glow-effect));
        }

        .logo-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Right Side - Login Form */
        .form-side {
            flex: 1;
            padding: 50px;
            background: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 35px;
        }

        .form-header h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-label span {
            font-size: 16px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input {
            width: 100%;
            padding: 16px 18px;
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            color: var(--text-primary);
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .input-wrapper input::placeholder {
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }

        /* Gaming Button - Matching chat.php send button */
        .game-button {
            width: 100%;
            padding: 16px;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: var(--glow-effect);
            margin-top: 15px;
        }

        .game-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .game-button:hover::before {
            left: 100%;
        }

        .game-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--accent-glow);
        }

        /* Error/Success Messages - Matching toast style */
        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border-left: 4px solid;
        }

        .message.error {
            background: rgba(240, 71, 71, 0.1);
            border-left-color: var(--danger);
            color: var(--danger);
        }

        .message.success {
            background: rgba(67, 181, 129, 0.1);
            border-left-color: var(--success);
            color: var(--success);
        }

        .message-icon {
            font-size: 20px;
        }

        /* Account Deleted Message - New Addition */
        .message.goodbye {
            background: rgba(14, 211, 199, 0.1);
            border-left-color: var(--accent-secondary);
            color: var(--accent-secondary);
        }

        /* Sign Up Link */
        .signup-section {
            margin-top: 30px;
            text-align: center;
            padding-top: 25px;
            border-top: 1px solid var(--border);
        }

        .signup-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 12px 30px;
            border: 2px solid var(--border);
            border-radius: 30px;
            transition: all 0.3s;
        }

        .signup-link:hover {
            border-color: var(--accent-secondary);
            color: var(--accent-secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--accent-glow-secondary);
        }

        .signup-link span {
            font-size: 18px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .auth-container {
                width: 100%;
                max-width: 100%;
                padding: 0 12px;
            }

            .gaming-panel {
                border-radius: 20px;
            }

            .panel-grid {
                flex-direction: column;
                min-height: auto;
            }

            .visual-side {
                padding: 24px 16px;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }

            .logo-image {
                width: 120px;
                height: 120px;
            }

            .form-side {
                padding: 24px 20px;
            }

            .form-header {
                margin-bottom: 20px;
            }

            .form-header h3 {
                font-size: 22px;
                letter-spacing: 1px;
            }

            .form-header p {
                font-size: 12px;
            }

            .form-group {
                margin-bottom: 18px;
            }

            .input-wrapper input {
                padding: 14px 16px;
                font-size: 16px; /* Prevents iOS zoom */
            }

            .game-button {
                padding: 14px;
                font-size: 15px;
                letter-spacing: 1px;
                margin-top: 10px;
            }

            .signup-section {
                margin-top: 20px;
                padding-top: 18px;
            }

            .signup-link {
                padding: 10px 20px;
                font-size: 13px;
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .visual-side {
                display: none; /* Hide logo panel on very small screens */
            }

            .auth-container {
                padding: 0 8px;
            }

            .form-side {
                padding: 28px 18px;
            }

            .gaming-panel::before {
                height: 4px; /* Thicker accent bar to replace hidden logo */
            }
        }
    </style>
    <!-- Global Mobile Responsive Overrides -->
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="loading.css">
    <link rel="stylesheet" href="themes.css">
</head>
<body>
    <!-- Static Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    
    <div class="auth-container">
        <div class="gaming-panel">
            <div class="panel-grid">
                <!-- Left Side - Only Big Logo -->
                <div class="visual-side">
                    <div class="gaming-logo">
                        <div class="logo-image">
                            <img src="photos/logo.png" alt="CONNECTXION GAMING">
                        </div>
                    </div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="form-side">
                    <div class="form-header">
                        <h3>PLAYER LOGIN</h3>
                        <p>Enter your credentials to start</p>
                    </div>
                    
                    <?php if (isset($_GET['registered'])): ?>
                        <div class="message success">
                            <span class="message-icon">✅</span>
                            REGISTRATION SUCCESSFUL! READY TO PLAY?
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['account_deleted'])): ?>
                        <div class="message goodbye">
                            <span class="message-icon">👋</span>
                            ACCOUNT DELETED SUCCESSFULLY. WE HOPE TO SEE YOU AGAIN!
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="message error">
                            <span class="message-icon">⚠️</span>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <div class="form-label">
                                <span>🎮</span>
                                GAMER TAG / EMAIL
                            </div>
                            <div class="input-wrapper">
                                <input type="text" name="email" placeholder="Enter your username or email" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-label">
                                <span>🔐</span>
                                ACCESS KEY
                            </div>
                            <div class="input-wrapper">
                                <input type="password" name="password" placeholder="Enter your password" required>
                            </div>
                            <div style="text-align: right; margin-top: 10px;">
                                <a href="forgot_password.php" style="color: var(--accent); font-size: 13px; text-decoration: none; text-transform: uppercase; font-weight: 600; letter-spacing: 1px; transition: color 0.3s;">Forgot Password?</a>
                            </div>
                        </div>
                        
                        <button type="submit" class="game-button">
                            ▶ LAUNCH GAME
                        </button>
                    </form>
                    
                    <div class="signup-section">
                        <a href="register.php" class="signup-link">
                            <span>➕</span>
                            CREATE NEW PLAYER
                        </a>
                    </div>
                </div>
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

    <script>
        document.querySelector('form').addEventListener('submit', function(e) {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.add('active');
            document.body.classList.add('is-loading');
        });
    </script>
    <script>
        // PWA Service Worker Registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('service-worker.js')
                    .then(reg => console.log('Service Worker registered'))
                    .catch(err => console.log('Service Worker registration failed', err));
            });
        }
    </script>
</body>
</html>