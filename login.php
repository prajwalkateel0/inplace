<?php
session_start();
require_once 'config/db.php';
require_once 'config/app_config.php';
loadAppConfig($pdo);

// reCAPTCHA keys — configured via Admin → Settings
define('RECAPTCHA_SITE_KEY',   appConfig('recaptcha_site_key',   ''));
define('RECAPTCHA_SECRET_KEY', appConfig('recaptcha_secret_key', ''));

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    
    // ═══════════════════════════════════════════════════════
    // VERIFY reCAPTCHA (skipped if keys not yet configured in Settings)
    // ═══════════════════════════════════════════════════════
    $recaptchaConfigured = RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SECRET_KEY !== '';
    $recaptchaOk = true;

    if ($recaptchaConfigured) {
        if (empty($recaptchaResponse)) {
            $error = "Please complete the reCAPTCHA verification.";
            $recaptchaOk = false;
        } else {
            $verifyURL  = 'https://www.google.com/recaptcha/api/siteverify';
            $context    = stream_context_create(['http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query([
                    'secret'   => RECAPTCHA_SECRET_KEY,
                    'response' => $recaptchaResponse,
                    'remoteip' => $_SERVER['REMOTE_ADDR']
                ])
            ]]);
            $responseData = json_decode(file_get_contents($verifyURL, false, $context));
            if (!$responseData->success) {
                $error       = "reCAPTCHA verification failed. Please try again.";
                $recaptchaOk = false;
            }
        }
    }

    if ($recaptchaOk) {
        if ($email && $password) {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, password, role, avatar_initials, approval_status, rejection_reason
                FROM users WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['approval_status'] === 'pending') {
                    $error = "Your account is pending admin approval. You will receive an email once your account is approved.";
                } elseif ($user['approval_status'] === 'rejected') {
                    $reason = $user['rejection_reason'] ? " Reason: " . $user['rejection_reason'] : "";
                    $error  = "Your account registration was not approved.$reason Please contact the placement office for more information.";
                } else {
                    $_SESSION['user'] = [
                        'id'              => (int)$user['id'],
                        'full_name'       => $user['full_name'],
                        'email'           => $user['email'],
                        'role'            => $user['role'],
                        'avatar_initials' => $user['avatar_initials']
                    ];
                    header("Location: dashboard.php");
                    exit;
                }
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Please enter both email and password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - InPlace</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <?php if (RECAPTCHA_SITE_KEY !== ''): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-image: url("assets/images/library-bg.jpg");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
        }

        .auth-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 1100px;
            width: 100%;
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
        }

        .auth-left {
            background: linear-gradient(135deg, #0c1b33 0%, #1a2d4d 100%);
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            color: white;
        }

        .auth-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(232, 160, 32, 0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .back-link {
            position: absolute;
            top: 2rem;
            left: 2rem;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        .back-link:hover {
            transform: translateX(-5px);
        }

        .illustration {
            width: 100%;
            max-width: 280px;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .illustration svg {
            width: 100%;
            height: auto;
        }

        .auth-left h2 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            margin-bottom: 1rem;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .auth-left p {
            text-align: center;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        .auth-right {
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 2rem;
        }

        .form-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            color: #0c1b33;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            color: #6b7a8d;
        }

        .form-header p a {
            color: #e8a020;
            text-decoration: none;
            font-weight: 600;
        }

        .form-header p a:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.9375rem;
        }

        .alert-danger {
            background: #fff5f5;
            border: 1px solid #feb2b2;
            color: #c53030;
        }

        .alert-warning {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            color: #92400e;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #15803d;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 0.9375rem;
        }

        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #e8dcc8;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s;
            background: #f8f5f0;
        }

        .form-input:focus {
            outline: none;
            border-color: #e8a020;
            background: white;
            box-shadow: 0 0 0 3px rgba(232, 160, 32, 0.1);
        }

        /* ⭐ Password Toggle Styling */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 3rem;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            color: #6b7a8d;
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: #0c1b33;
        }

        .password-toggle svg {
            width: 20px;
            height: 20px;
            display: block;
        }

        /* ⭐ reCAPTCHA Styling */
        .recaptcha-wrapper {
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* ⭐ reCAPTCHA Error Message Styling */
        .recaptcha-error {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            padding: 0.875rem 1rem;
            background: #fff5f5;
            border: 1px solid #feb2b2;
            border-radius: 8px;
            color: #c53030;
            font-size: 0.875rem;
            font-weight: 500;
            width: 100%;
        }

        .recaptcha-error svg {
            flex-shrink: 0;
        }

        /* Shake animation */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .shake {
            animation: shake 0.5s;
        }

        .btn-primary {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #0c1b33 0%, #1a2d4d 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(12, 27, 51, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(12, 27, 51, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        @media (max-width: 968px) {
            .auth-container {
                grid-template-columns: 1fr;
            }

            .auth-left {
                padding: 2rem;
                min-height: 300px;
            }
        }

        /* Demo accounts */
        .demo-section {
            margin-top: 1.5rem;
            border-top: 1px solid #e8dcc8;
            padding-top: 1.25rem;
        }

        .demo-heading {
            text-align: center;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #6b7a8d;
            margin-bottom: 0.75rem;
        }

        .demo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
        }

        .demo-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.6rem 0.75rem;
            border-radius: 10px;
            border: 1.5px solid #e8dcc8;
            background: #f8f5f0;
            cursor: pointer;
            font-family: inherit;
            text-align: left;
            transition: all 0.2s;
        }

        .demo-item:hover {
            border-color: #e8a020;
            background: #fff8ec;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(232, 160, 32, 0.15);
        }

        .demo-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
            background: rgba(232, 160, 32, 0.15);
        }

        .demo-info {
            flex: 1;
            min-width: 0;
        }

        .demo-role {
            font-weight: 700;
            font-size: 0.8rem;
            color: #0c1b33;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .demo-email {
            font-size: 0.7rem;
            color: #6b7a8d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        @media (max-width: 480px) {
            .demo-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-left">
            <a href="index.html" class="back-link">
                ← Back to Home
            </a>

            <div class="illustration">
                <svg viewBox="0 0 400 400" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Login Shield -->
                    <path d="M200 100 L280 130 L280 220 Q280 280 200 320 Q120 280 120 220 L120 130 Z" fill="#e8a020" opacity="0.2"/>
                    <path d="M200 120 L260 140 L260 210 Q260 260 200 290 Q140 260 140 210 L140 140 Z" fill="#e8a020"/>
                    <!-- Checkmark -->
                    <path d="M170 200 L190 220 L230 170" stroke="white" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
                    <!-- Decorative elements -->
                    <circle cx="100" cy="120" r="20" fill="white" opacity="0.1"/>
                    <circle cx="300" cy="280" r="30" fill="white" opacity="0.1"/>
                    <circle cx="320" cy="150" r="15" fill="white" opacity="0.15"/>
                </svg>
            </div>

            <h2>Welcome Back!</h2>
            <p>Sign in to access your placement management dashboard and continue your professional journey.</p>
        </div>

        <div class="auth-right">
            <div class="form-header">
                <h1>Sign In</h1>
                <p>Don't have an account? <a href="register.php">Register here</a></p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert <?= strpos($error, 'pending') !== false ? 'alert-warning' : 'alert-danger' ?>">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label>Email</label>
                    <input 
                        type="email" 
                        name="email" 
                        class="form-input" 
                        placeholder="your.name@student.le.ac.uk"
                        required
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="password-wrapper">
                        <input 
                            type="password" 
                            name="password" 
                            id="passwordInput"
                            class="form-input" 
                            placeholder="Enter your password"
                            required
                        >
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="Toggle password visibility">
                            <!-- Eye Icon (Hidden) -->
                            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <!-- Eye Slash Icon (Visible) -->
                            <svg id="eyeSlashIcon" style="display:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- ⭐ reCAPTCHA Widget -->
                <?php if (RECAPTCHA_SITE_KEY !== ''): ?>
                <div class="recaptcha-wrapper">
                    <div class="g-recaptcha"
                         data-sitekey="<?= RECAPTCHA_SITE_KEY ?>"
                         data-callback="onRecaptchaSuccess"></div>
                    
                    <!-- Error Message (hidden by default) -->
                    <div id="recaptchaError" class="recaptcha-error" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8 1a7 7 0 100 14A7 7 0 008 1zm0 13A6 6 0 118 2a6 6 0 010 12zm.93-9.412l-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 110-2 1 1 0 010 2z"/>
                        </svg>
                        Please complete the reCAPTCHA verification
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-primary" id="submitBtn">
                    Sign In
                </button>

                <p style="text-align:center;margin-top:1.25rem;font-size:0.9rem;color:#6b7a8d;">
                    <a href="forgot-password.php" style="color:#e8a020;font-weight:600;text-decoration:none;">
                        Forgot your password?
                    </a>
                </p>
            </form>

            <!-- Demo accounts -->
            <div class="demo-section">
                <div class="demo-heading">Demo Accounts — click to fill</div>
                <div class="demo-grid">
                    <?php
                    $demoAccounts = [
                        ['icon' => '🛡️', 'role' => 'Admin',    'email' => 'admin.leicester.ac.uk@gmail.com'],
                        ['icon' => '👩‍🏫', 'role' => 'Tutor',    'email' => 'tutor.leicester.ac.uk@gmail.com'],
                        ['icon' => '🏢', 'role' => 'Provider', 'email' => 'provider.deloitte.ac.uk@gmail.com'],
                        ['icon' => '🎓', 'role' => 'Director', 'email' => 'Director.leicester.ac.uk@gmail.com'],
                    ];
                    foreach ($demoAccounts as $demo): ?>
                    <button type="button" class="demo-item" onclick="fillDemoLogin('<?= htmlspecialchars($demo['email'], ENT_QUOTES) ?>')">
                        <div class="demo-avatar"><?= $demo['icon'] ?></div>
                        <div class="demo-info">
                            <div class="demo-role"><?= htmlspecialchars($demo['role']) ?></div>
                            <div class="demo-email"><?= htmlspecialchars($demo['email']) ?></div>
                        </div>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ═══════════════════════════════════════════════════════
        // Demo Account Auto-fill
        // ═══════════════════════════════════════════════════════
        function fillDemoLogin(email) {
            document.querySelector('[name="email"]').value = email;
            document.getElementById('passwordInput').value = 'password';
        }

        // ═══════════════════════════════════════════════════════
        // Password Toggle Functionality
        // ═══════════════════════════════════════════════════════
        function togglePassword() {
            const passwordInput = document.getElementById('passwordInput');
            const eyeIcon = document.getElementById('eyeIcon');
            const eyeSlashIcon = document.getElementById('eyeSlashIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.style.display = 'none';
                eyeSlashIcon.style.display = 'block';
            } else {
                passwordInput.type = 'password';
                eyeIcon.style.display = 'block';
                eyeSlashIcon.style.display = 'none';
            }
        }

        // reCAPTCHA validation (only when widget is rendered)
        <?php if (RECAPTCHA_SITE_KEY !== ''): ?>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const recaptchaResponse = grecaptcha.getResponse();
            const errorMsg = document.getElementById('recaptchaError');
            if (recaptchaResponse.length === 0) {
                e.preventDefault();
                errorMsg.style.display = 'flex';
                errorMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
                errorMsg.classList.add('shake');
                setTimeout(() => errorMsg.classList.remove('shake'), 500);
            } else {
                errorMsg.style.display = 'none';
            }
        });
        function onRecaptchaSuccess() {
            document.getElementById('recaptchaError').style.display = 'none';
        }
        <?php endif; ?>
    </script>
</body>
</html>