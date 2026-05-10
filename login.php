<?php
session_start();
require_once 'config/db.php';

function isMobile() {
    return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]);
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'rep') {
        header("Location: rep/dashboard.php");
    } else {
        if (isMobile()) {
            header("Location: admin_app/dashboard.php");
        } else {
            header("Location: pages/dashboard.php");
        }
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        // Fetch user from DB
        $stmt = $pdo->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Verify password
        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            
            if ($user['role'] === 'rep') {
                header("Location: rep/dashboard.php");
            } else {
                if (isMobile()) {
                    header("Location: admin_app/dashboard.php");
                } else {
                    header("Location: pages/dashboard.php");
                }
            }
            exit;
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candent - Command Center Login</title>
    <!-- Keep Bootstrap for structure if needed, but styling is custom iOS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap');

        :root {
            --ios-bg: #F2F2F7;
            --ios-surface: #FFFFFF;
            --ios-label: #000000;
            --ios-label-2: rgba(60,60,67,0.6);
            --accent: #30C88A;
            --accent-dark: #25A872;
            --error-bg: rgba(255,59,48,0.1);
            --error-text: #CC2200;
        }

        body { 
            background-color: var(--ios-bg); 
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Helvetica Neue', sans-serif;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0; 
            -webkit-font-smoothing: antialiased;
            position: relative;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 20px;
            z-index: 10;
        }

        .login-card { 
            background: var(--ios-surface); 
            padding: 48px 40px; 
            border-radius: 28px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.06), 0 2px 10px rgba(0,0,0,0.04);
            text-align: center;
        }

        .brand-logo {
            max-height: 56px;
            margin-bottom: 24px;
            /* In case logo doesn't load right away, give it a nice default look */
            object-fit: contain;
        }

        .welcome-title { 
            font-size: 1.6rem; 
            font-weight: 700; 
            color: var(--ios-label); 
            letter-spacing: -0.5px;
            margin-bottom: 8px; 
        }

        .welcome-sub {
            font-size: 0.9rem;
            color: var(--ios-label-2);
            margin-bottom: 32px;
        }

        .ios-input-group {
            text-align: left;
            margin-bottom: 16px;
        }

        .ios-input-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ios-label-2);
            margin-bottom: 8px;
            padding-left: 4px;
        }

        .ios-input {
            width: 100%;
            background: var(--ios-bg);
            border: 2px solid transparent;
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 0.95rem;
            color: var(--ios-label);
            transition: all 0.2s ease;
            outline: none;
        }

        .ios-input:focus {
            background: #fff;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(48,200,138,0.15);
        }

        .ios-btn {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 50px;
            padding: 14px;
            width: 100%;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(48,200,138,0.35);
        }

        .ios-btn:hover { background: var(--accent-dark); }
        .ios-btn:active { transform: scale(0.97); }

        .ios-alert {
            background: var(--error-bg);
            color: var(--error-text);
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 24px;
            text-align: left;
        }

        .dev-credit {
            position: absolute;
            bottom: 24px;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 0.75rem;
            color: var(--ios-label-2);
            font-weight: 500;
        }

        .dev-credit a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.2s;
        }
        
        .dev-credit a:hover { opacity: 0.7; }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Candent Logo -->
        <img src="/images/logo/logo.png" alt="Candent Logo" class="brand-logo" onerror="this.src='https://via.placeholder.com/200x60/ffffff/30C88A?text=CANDENT'">
        
        <h2 class="welcome-title">Welcome Back</h2>
        <p class="welcome-sub">Sign in to your Command Center</p>
        
        <?php if($error): ?>
            <div class="ios-alert">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-circle-fill" viewBox="0 0 16 16" style="margin-right:6px; vertical-align:-3px;">
                  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4zm.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/>
                </svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="ios-input-group">
                <label>Email Address</label>
                <input type="email" name="email" class="ios-input" placeholder="name@example.com" required autofocus>
            </div>
            <div class="ios-input-group">
                <label>Password</label>
                <input type="password" name="password" class="ios-input" placeholder="••••••••" required>
            </div>
            <button type="submit" class="ios-btn">Log In</button>
        </form>
    </div>
</div>

<!-- Global System Footer with Suzxlabs Credit -->
<div class="dev-credit">
    System Developed & Maintained by <a href="https://suzxlabs.com" target="_blank">Suzxlabs</a>
</div>

</body>
</html>