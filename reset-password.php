<?php
session_start();
require_once 'config/db.php';

// Create table if not exist (safety net)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

// Validate token
$resetRow = null;
if ($token) {
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE token = ? AND used = 0 AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $resetRow = $stmt->fetch();
}

if (!$token || !$resetRow) {
    $error = 'This password reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetRow) {
    $newPassword     = $_POST['password']         ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update user password
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$hashed, $resetRow['email']]);

        // Mark token as used
        $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $stmt->execute([$token]);

        $_SESSION['registration_success'] = 'Your password has been reset successfully. Please log in with your new password.';
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - InPlace</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'DM Sans',sans-serif;
            background-image:url("assets/images/library-bg.jpg");
            background-size:cover; background-position:center;
            background-repeat:no-repeat; background-attachment:fixed;
            min-height:100vh; display:flex; align-items:center;
            justify-content:center; padding:2rem 1rem;
        }
        body::before {
            content:""; position:fixed; inset:0;
            background:rgba(0,0,0,0.35); z-index:-1;
        }
        .auth-container {
            display:grid; grid-template-columns:1fr 1fr;
            max-width:1100px; width:100%; background:white;
            border-radius:24px; overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,0.1);
        }
        .auth-left {
            background:linear-gradient(135deg,#0c1b33 0%,#1a2d4d 100%);
            padding:3rem; display:flex; flex-direction:column;
            justify-content:center; align-items:center;
            position:relative; color:white;
        }
        .auth-left::before {
            content:''; position:absolute; top:-50%; right:-30%;
            width:600px; height:600px;
            background:radial-gradient(circle,rgba(232,160,32,0.15) 0%,transparent 70%);
            border-radius:50%;
        }
        .auth-left h2 {
            font-family:'Playfair Display',serif; font-size:2rem;
            margin-bottom:1rem; text-align:center; position:relative; z-index:1;
        }
        .auth-left p {
            text-align:center; color:rgba(255,255,255,0.9);
            line-height:1.6; position:relative; z-index:1;
        }
        .auth-right {
            padding:3rem; display:flex;
            flex-direction:column; justify-content:center;
        }
        .form-header { margin-bottom:2rem; }
        .form-header h1 {
            font-family:'Playfair Display',serif; font-size:2rem;
            color:#0c1b33; margin-bottom:0.5rem;
        }
        .form-header p { color:#6b7a8d; }
        .alert {
            padding:1rem 1.5rem; border-radius:12px;
            margin-bottom:1.5rem; font-size:0.9375rem;
        }
        .alert-error { background:#fff5f5; border:1px solid #feb2b2; color:#c53030; }
        .form-group { margin-bottom:1.25rem; }
        .form-group label {
            display:block; font-weight:600; color:#2c3e50;
            margin-bottom:0.5rem; font-size:0.9375rem;
        }
        .form-input {
            width:100%; padding:0.875rem 1rem; border:2px solid #e8dcc8;
            border-radius:10px; font-size:1rem; font-family:inherit;
            transition:all 0.3s; background:#f8f5f0;
        }
        .form-input:focus {
            outline:none; border-color:#e8a020; background:white;
            box-shadow:0 0 0 3px rgba(232,160,32,0.1);
        }
        .password-wrapper { position:relative; }
        .password-wrapper input { padding-right:3rem; }
        .password-toggle {
            position:absolute; right:1rem; top:50%;
            transform:translateY(-50%); background:none; border:none;
            cursor:pointer; padding:0.5rem; color:#6b7a8d; transition:color 0.3s;
        }
        .password-toggle:hover { color:#0c1b33; }
        .password-toggle svg { width:20px; height:20px; display:block; }
        .strength-bar {
            height:4px; border-radius:2px; margin-top:0.5rem;
            background:#e8dcc8; overflow:hidden;
        }
        .strength-fill {
            height:100%; width:0%; border-radius:2px;
            transition:all 0.3s;
        }
        .strength-label {
            font-size:0.78rem; margin-top:0.3rem; color:#6b7a8d;
        }
        .btn-primary {
            width:100%; padding:1rem;
            background:linear-gradient(135deg,#0c1b33 0%,#1a2d4d 100%);
            color:white; border:none; border-radius:12px;
            font-size:1rem; font-weight:700; cursor:pointer;
            transition:all 0.3s; box-shadow:0 4px 15px rgba(12,27,51,0.2);
        }
        .btn-primary:hover {
            transform:translateY(-2px);
            box-shadow:0 6px 20px rgba(12,27,51,0.3);
        }
        @media (max-width:968px) {
            .auth-container { grid-template-columns:1fr; }
            .auth-left { padding:2rem; min-height:260px; }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-left">
            <div style="position:relative;z-index:1;text-align:center;">
                <div style="font-size:5rem;margin-bottom:1.5rem;">🔒</div>
            </div>
            <h2>New Password</h2>
            <p>Choose a strong password that you haven't used before.</p>
        </div>

        <div class="auth-right">
            <div class="form-header">
                <h1>Reset Password</h1>
                <?php if (!$resetRow && !$_POST): ?>
                <p><a href="forgot-password.php" style="color:#e8a020;font-weight:600;text-decoration:none;">
                    Request a new reset link
                </a></p>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
                <?php if (!$resetRow): ?>
                <br><a href="forgot-password.php" style="color:#c53030;font-weight:600;">
                    Request a new reset link →
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($resetRow): ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="form-group">
                    <label>New Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="newPwd"
                               class="form-input" placeholder="Minimum 8 characters"
                               required minlength="8" oninput="checkStrength(this.value)">
                        <button type="button" class="password-toggle"
                                onclick="togglePwd('newPwd','eye1','eyeSlash1')">
                            <svg id="eye1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="eyeSlash1" style="display:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel"></div>
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="confirmPwd"
                               class="form-input" placeholder="Repeat your new password"
                               required minlength="8">
                        <button type="button" class="password-toggle"
                                onclick="togglePwd('confirmPwd','eye2','eyeSlash2')">
                            <svg id="eye2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="eyeSlash2" style="display:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary">Set New Password</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function togglePwd(inputId, eyeId, slashId) {
        const inp = document.getElementById(inputId);
        const eye = document.getElementById(eyeId);
        const slash = document.getElementById(slashId);
        if (inp.type === 'password') {
            inp.type = 'text'; eye.style.display = 'none'; slash.style.display = 'block';
        } else {
            inp.type = 'password'; eye.style.display = 'block'; slash.style.display = 'none';
        }
    }

    function checkStrength(val) {
        const fill  = document.getElementById('strengthFill');
        const label = document.getElementById('strengthLabel');
        let score = 0;
        if (val.length >= 8)  score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const levels = [
            { pct: '20%', color: '#ef4444', text: 'Very weak' },
            { pct: '40%', color: '#f97316', text: 'Weak' },
            { pct: '60%', color: '#eab308', text: 'Fair' },
            { pct: '80%', color: '#84cc16', text: 'Strong' },
            { pct: '100%', color: '#10b981', text: 'Very strong' },
        ];
        const lvl = levels[Math.max(0, score - 1)] || { pct: '0%', color: '#e8dcc8', text: '' };
        fill.style.width = val ? lvl.pct : '0%';
        fill.style.background = lvl.color;
        label.textContent = val ? lvl.text : '';
    }
    </script>
</body>
</html>
