<?php
session_start();
require_once __DIR__ . '/config/db.php';

$error   = $_SESSION['provider_reg_error']   ?? '';
$success = $_SESSION['provider_reg_success'] ?? '';
unset($_SESSION['provider_reg_error'], $_SESSION['provider_reg_success']);

// Pre-fill from query string (when coming from email link)
$prefillEmail   = htmlspecialchars($_GET['email']   ?? '');
$prefillCompany = htmlspecialchars($_GET['company']  ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_provider'])) {
    $fullName    = trim($_POST['full_name']       ?? '');
    $email       = trim($_POST['email']           ?? '');
    $companyName = trim($_POST['company_name']    ?? '');
    $password    = $_POST['password']             ?? '';
    $confirm     = $_POST['confirm_password']     ?? '';

    if (!$fullName || !$email || !$companyName || !$password) {
        $_SESSION['provider_reg_error'] = "All fields are required.";
        header("Location: provider-register.php"); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['provider_reg_error'] = "Please enter a valid email address.";
        header("Location: provider-register.php"); exit;
    }
    if ($password !== $confirm) {
        $_SESSION['provider_reg_error'] = "Passwords do not match.";
        header("Location: provider-register.php"); exit;
    }
    if (strlen($password) < 8) {
        $_SESSION['provider_reg_error'] = "Password must be at least 8 characters.";
        header("Location: provider-register.php"); exit;
    }

    try {
        // Check email not already registered
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $_SESSION['provider_reg_error'] = "An account with this email already exists. Please log in.";
            header("Location: provider-register.php"); exit;
        }

        // Find or create company
        $stmt = $pdo->prepare("SELECT id FROM companies WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$companyName]);
        $company = $stmt->fetch();
        if ($company) {
            $companyId = (int)$company['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->execute([$companyName]);
            $companyId = (int)$pdo->lastInsertId();
        }

        $parts    = preg_split('/\s+/', $fullName);
        $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, password, role, company_id, avatar_initials, approval_status, is_active, created_at)
            VALUES (?, ?, ?, 'provider', ?, ?, 'approved', 1, NOW())
        ");
        $stmt->execute([$fullName, $email, $hash, $companyId, $initials]);
        $newUserId = (int)$pdo->lastInsertId();

        // Auto-login the newly registered provider and go straight to their dashboard
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'              => $newUserId,
            'full_name'       => $fullName,
            'email'           => $email,
            'role'            => 'provider',
            'avatar_initials' => $initials,
        ];
        header("Location: /inplace/dashboard.php"); exit;
    } catch (Exception $e) {
        $_SESSION['provider_reg_error'] = "Registration failed. Please try again.";
        header("Location: provider-register.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Registration - InPlace</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'DM Sans',sans-serif;
            background:url('/inplace/assets/images/library-bg.jpg') no-repeat center center fixed;
            background-size:cover;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:2rem 1rem;
        }
        body::before {
            content:"";
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.35);
            z-index:-1;
        }
        .auth-container {
            display:grid;
            grid-template-columns:1fr 1fr;
            max-width:1100px;
            width:100%;
            background:white;
            border-radius:24px;
            overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,0.1);
        }
        .auth-left {
            background:linear-gradient(135deg,#0c1b33 0%,#1a2d4d 100%);
            padding:3rem;
            display:flex;
            flex-direction:column;
            justify-content:center;
            align-items:center;
            color:white;
        }
        .auth-left h2 { font-family:'Playfair Display',serif; font-size:2rem; margin-bottom:1rem; text-align:center; }
        .auth-left p  { text-align:center; color:rgba(255,255,255,0.85); line-height:1.6; }
        .back-link {
            position:absolute;
            top:2rem; left:2rem;
            color:white; text-decoration:none;
            display:flex; align-items:center; gap:0.5rem;
            font-weight:500; transition:all 0.3s;
        }
        .back-link:hover { transform:translateX(-5px); }
        .auth-right { padding:3rem; overflow-y:auto; max-height:90vh; }
        .form-header { margin-bottom:2rem; }
        .form-header h1 { font-family:'Playfair Display',serif; font-size:2rem; color:#0c1b33; margin-bottom:0.5rem; }
        .form-header p  { color:#6b7a8d; }
        .form-header p a { color:#e8a020; text-decoration:none; font-weight:600; }
        .alert { padding:1rem 1.5rem; border-radius:12px; margin-bottom:1.5rem; font-size:0.9375rem; }
        .alert-danger  { background:#fff5f5; border:1px solid #feb2b2; color:#c53030; }
        .alert-success { background:#f0fdf4; border:1px solid #86efac; color:#15803d; }
        .form-group { margin-bottom:1.25rem; }
        .form-group label { display:block; font-weight:600; color:#2c3e50; margin-bottom:0.5rem; font-size:0.9375rem; }
        .required { color:#e74c3c; }
        .form-input {
            width:100%; padding:0.875rem 1rem;
            border:2px solid #e8dcc8; border-radius:10px;
            font-size:1rem; font-family:inherit;
            transition:all 0.3s; background:#f8f5f0;
        }
        .form-input:focus { outline:none; border-color:#e8a020; background:white; box-shadow:0 0 0 3px rgba(232,160,32,0.1); }
        .btn-primary {
            width:100%; padding:1rem;
            background:linear-gradient(135deg,#0c1b33 0%,#1a2d4d 100%);
            color:white; border:none; border-radius:12px;
            font-size:1rem; font-weight:700; cursor:pointer;
            transition:all 0.3s; box-shadow:0 4px 15px rgba(12,27,51,0.2);
        }
        .btn-primary:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(12,27,51,0.3); }
        .steps {
            display:flex; flex-direction:column; gap:1rem;
            margin-top:2rem; width:100%;
        }
        .step {
            display:flex; align-items:flex-start; gap:0.875rem;
            background:rgba(255,255,255,0.08);
            border-radius:10px; padding:0.875rem 1rem;
        }
        .step-num {
            width:28px; height:28px; border-radius:50%;
            background:#e8a020; color:white;
            display:flex; align-items:center; justify-content:center;
            font-weight:700; font-size:0.875rem; flex-shrink:0;
        }
        .step p { font-size:0.875rem; color:rgba(255,255,255,0.85); margin:0; }
        @media (max-width:768px) {
            .auth-container { grid-template-columns:1fr; }
            .auth-left { min-height:250px; }
        }
    </style>
</head>
<body>
<div class="auth-container">
    <div class="auth-left" style="position:relative;">
        <a href="login.php" class="back-link">← Back to Login</a>
        <h2>Provider Portal</h2>
        <p>Register as a placement provider to review and authorise student placement requests at your company.</p>
        <div class="steps">
            <div class="step">
                <div class="step-num">1</div>
                <p>Create your provider account with your company details</p>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <p>Log in and review placement requests from students</p>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <p>Approve or provide feedback on placement authorisation requests</p>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="form-header">
            <h1>Create Provider Account</h1>
            <p>Already registered? <a href="login.php">Sign in here</a></p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" class="form-input"
                       placeholder="e.g., Jane Smith" required
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Company Name <span class="required">*</span></label>
                <input type="text" name="company_name" class="form-input"
                       placeholder="e.g., Deloitte UK" required
                       value="<?= htmlspecialchars($_POST['company_name'] ?? $prefillCompany) ?>">
            </div>

            <div class="form-group">
                <label>Work Email <span class="required">*</span></label>
                <input type="email" name="email" class="form-input"
                       placeholder="you@company.com" required
                       value="<?= htmlspecialchars($_POST['email'] ?? $prefillEmail) ?>">
            </div>

            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <input type="password" name="password" class="form-input"
                       placeholder="Minimum 8 characters" required minlength="8">
            </div>

            <div class="form-group">
                <label>Confirm Password <span class="required">*</span></label>
                <input type="password" name="confirm_password" class="form-input"
                       placeholder="Re-enter your password" required>
            </div>

            <button type="submit" name="register_provider" class="btn-primary">
                Create Provider Account
            </button>
        </form>
    </div>
</div>
</body>
</html>
