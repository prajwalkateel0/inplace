<?php
session_start();
require_once 'config/db.php';
require_once 'config/app_config.php';
loadAppConfig($pdo);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';

// Create password_resets table if it doesn't exist
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

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';
    } else {
        // Check user exists and is approved
        $stmt = $pdo->prepare("SELECT id, full_name, approval_status FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always show success message (don't reveal if email exists)
        $message = 'If that email is registered, you will receive a password reset link shortly.';
        $messageType = 'success';

        if ($user && $user['approval_status'] === 'approved') {
            // Delete any old unused tokens for this email
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);

            // Generate token
            $token = bin2hex(random_bytes(32)); // 64 char hex
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expires]);

            // Build reset URL
            $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetUrl = $scheme . '://' . $host . '/inplace/reset-password.php?token=' . $token;

            // Send email
            $mailCfg = require __DIR__ . '/config/email_config.php';

            $htmlBody = "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;
                        border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
              <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Password Reset Request</p>
              </div>
              <div style='padding:2rem;'>
                <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>
                    Dear " . htmlspecialchars($user['full_name']) . ",
                </p>
                <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                    We received a request to reset your InPlace password. Click the button below to set a new password.
                    This link will expire in <strong>1 hour</strong>.
                </p>
                <div style='text-align:center;margin:2rem 0;'>
                  <a href='$resetUrl'
                     style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;
                            color:#ffffff !important;text-decoration:none;border-radius:10px;
                            font-weight:700;font-size:1rem;border:2px solid #0c1b33;'>
                    Reset My Password
                  </a>
                </div>
                <p style='color:#6b7a8d;font-size:0.875rem;'>
                    If you did not request a password reset, you can safely ignore this email. Your password will not change.
                </p>
                <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;margin-top:2rem;'>
                    This is an automated notification from InPlace.
                </p>
              </div>
            </div>";

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $mailCfg['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $mailCfg['smtp_user'];
                $mail->Password   = $mailCfg['smtp_pass'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $mailCfg['smtp_port'];
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                $mail->addAddress($email, $user['full_name']);
                $mail->isHTML(true);
                $mail->Subject = 'InPlace - Password Reset Request';
                $mail->Body    = $htmlBody;
                $mail->AltBody = "Reset your InPlace password: $resetUrl (expires in 1 hour)";
                $mail->send();
            } catch (MailException $e) {
                error_log('Password reset email failed: ' . $mail->ErrorInfo);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - InPlace</title>
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
        .back-link {
            position:absolute; top:2rem; left:2rem;
            color:white; text-decoration:none;
            display:flex; align-items:center; gap:0.5rem;
            font-weight:500; transition:all 0.3s;
        }
        .back-link:hover { transform:translateX(-5px); }
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
        .form-header p a { color:#e8a020; text-decoration:none; font-weight:600; }
        .form-header p a:hover { text-decoration:underline; }
        .alert {
            padding:1rem 1.5rem; border-radius:12px;
            margin-bottom:1.5rem; font-size:0.9375rem;
        }
        .alert-error { background:#fff5f5; border:1px solid #feb2b2; color:#c53030; }
        .alert-success { background:#f0fdf4; border:1px solid #86efac; color:#15803d; }
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
            <a href="login.php" class="back-link">← Back to Login</a>
            <div style="position:relative;z-index:1;text-align:center;">
                <div style="font-size:5rem;margin-bottom:1.5rem;">🔑</div>
            </div>
            <h2>Reset Password</h2>
            <p>Enter your email address and we'll send you a link to reset your password.</p>
        </div>

        <div class="auth-right">
            <div class="form-header">
                <h1>Forgot Password?</h1>
                <p>Remember it? <a href="login.php">Back to login</a></p>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <?php if ($messageType !== 'success'): ?>
            <form method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-input"
                           placeholder="your.email@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required autofocus>
                </div>
                <button type="submit" class="btn-primary">Send Reset Link</button>
            </form>
            <?php else: ?>
            <p style="text-align:center;margin-top:1rem;">
                <a href="login.php" style="color:#e8a020;font-weight:600;text-decoration:none;">
                    ← Back to Login
                </a>
            </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
