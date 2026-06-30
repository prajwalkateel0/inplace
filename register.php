<?php
session_start();
require_once __DIR__ . '/config/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';

function sendAdminRegistrationNotification(PDO $pdo, int $newUserId, string $fullName, string $email, string $academicYear, string $programmeType): void {
    // Fetch all admin emails
    $stmt = $pdo->query("SELECT email, full_name FROM users WHERE role = 'admin' AND is_active = 1");
    $admins = $stmt->fetchAll();
    if (empty($admins)) return;

    $mailCfg = require __DIR__ . '/config/email_config.php';

    // Build base URL
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $approvalUrl = $scheme . '://' . $host . '/inplace/admin/approve-registrations.php';

    $registeredAt = date('d M Y, H:i');

    $htmlBody = "
    <div style='font-family:\"DM Sans\",Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
      <div style='background:linear-gradient(135deg,#0c1b33 0%,#1a2d4d 100%);padding:2rem;text-align:center;'>
        <h1 style='color:#ffffff;font-size:1.5rem;margin:0;font-family:Georgia,serif;'>InPlace</h1>
        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>New Student Registration — Pending Approval</p>
      </div>
      <div style='padding:2rem;'>
        <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>A new student has registered and is awaiting your approval.</p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
          <tr style='background:#f8f5f0;'>
            <td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Full Name</td>
            <td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($fullName) . "</td>
          </tr>
          <tr>
            <td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Email</td>
            <td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($email) . "</td>
          </tr>
          <tr style='background:#f8f5f0;'>
            <td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Academic Year</td>
            <td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($academicYear) . "</td>
          </tr>
          <tr>
            <td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Programme</td>
            <td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($programmeType) . "</td>
          </tr>
          <tr style='background:#f8f5f0;'>
            <td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>Registered At</td>
            <td style='padding:0.75rem 1rem;color:#374151;'>$registeredAt</td>
          </tr>
        </table>
        <div style='text-align:center;margin:2rem 0;'>
          <a href='$approvalUrl'
             style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;
                    color:#ffffff !important;text-decoration:none;border-radius:10px;
                    font-weight:700;font-size:1rem;border:2px solid #0c1b33;
                    mso-padding-alt:0;'>
            Review &amp; Approve Registration
          </a>
        </div>
        <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>
          This is an automated notification from InPlace. The student cannot log in until approved.
        </p>
      </div>
    </div>";

    $altBody = "New student registration pending approval.\n\nName: $fullName\nEmail: $email\nAcademic Year: $academicYear\nProgramme: $programmeType\nRegistered: $registeredAt\n\nApprove here: $approvalUrl";

    foreach ($admins as $admin) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $smtpHostname     = $mailCfg['smtp_host'];
            $resolvedIp       = gethostbyname($smtpHostname);
            $mail->Host       = ($resolvedIp !== $smtpHostname) ? $resolvedIp : $smtpHostname;
            if ($resolvedIp !== $smtpHostname) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'peer_name'        => $smtpHostname,
                        'verify_peer'      => true,
                        'verify_peer_name' => true,
                    ],
                ];
            }
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailCfg['smtp_user'];
            $mail->Password   = $mailCfg['smtp_pass'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $mailCfg['smtp_port'];
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
            $mail->addAddress($admin['email'], $admin['full_name']);
            $mail->isHTML(true);
            $mail->Subject = "InPlace - New Registration Pending Approval: $fullName";
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody;
            $mail->send();
        } catch (MailException $e) {
            // Log silently — don't block the student's registration
            error_log('Admin notification email failed to ' . $admin['email'] . ': ' . $mail->ErrorInfo);
        }
    }
}

$error   = $_SESSION['registration_error']   ?? '';
$success = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_error'], $_SESSION['registration_success']);

// Restore form data after a failed OTP attempt (keeps fields filled)
$savedForm = $_SESSION['registration_form'] ?? [];
unset($_SESSION['registration_form']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $email          = trim($_POST['email'] ?? '');
    $fullName       = trim($_POST['full_name'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirmPassword= $_POST['confirm_password'] ?? '';
    $academicYear   = $_POST['academic_year'] ?? '';
    $programmeType  = $_POST['programme_type'] ?? '';
    $enteredOtp     = trim($_POST['otp'] ?? '');

    // Session OTP values
    $storedOtpHash = $_SESSION['registration_otp'] ?? '';
    $otpTimestamp  = $_SESSION['otp_timestamp'] ?? 0;
    $sessionEmail  = $_SESSION['registration_email'] ?? '';

    // ── OTP verification with 3-attempt limit ────────────────────
    $enteredOtp    = str_pad(trim($_POST['otp'] ?? ''), 6, '0', STR_PAD_LEFT);
    $storedOtpHash = $_SESSION['registration_otp']   ?? '';
    $otpTimestamp  = $_SESSION['otp_timestamp']       ?? 0;
    $emailLower    = strtolower(trim($email));
    $sessionEmail  = strtolower(trim($_SESSION['registration_email'] ?? ''));
    $otpAttempts   = (int)($_SESSION['otp_attempts'] ?? 0);

    // Helper: save form data so it survives the redirect
    $formSnapshot = [
        'email'            => $email,
        'full_name'        => $fullName,
        'academic_year'    => $academicYear,
        'programme_type'   => $programmeType,
    ];

    // 1) OTP session missing
    if (!$storedOtpHash || !$otpTimestamp || !$sessionEmail) {
        $_SESSION['registration_error'] = "OTP session not found. Please request a new code.";
        $_SESSION['registration_form']  = $formSnapshot;
        header("Location: register.php");
        exit;
    }

    // 2) Expired
    if (time() - $otpTimestamp > 600) {
        unset($_SESSION['registration_otp'], $_SESSION['otp_timestamp'],
              $_SESSION['registration_email'], $_SESSION['otp_attempts']);
        $_SESSION['registration_error'] = "OTP expired. Please click Send OTP to get a new code.";
        $_SESSION['registration_form']  = $formSnapshot;
        header("Location: register.php");
        exit;
    }

    // 3) Wrong OTP or email mismatch
    if (!password_verify($enteredOtp, $storedOtpHash) || $emailLower !== $sessionEmail) {
        $otpAttempts++;
        $remaining = 3 - $otpAttempts;

        if ($otpAttempts >= 3) {
            // Lock out — clear OTP session, force new code
            unset($_SESSION['registration_otp'], $_SESSION['otp_timestamp'],
                  $_SESSION['registration_email'], $_SESSION['otp_attempts']);
            $_SESSION['registration_error'] = "Too many incorrect attempts. Please click Send OTP to request a new code.";
        } else {
            $_SESSION['otp_attempts']       = $otpAttempts;
            $_SESSION['registration_error'] = "Incorrect OTP. You have $remaining attempt" . ($remaining === 1 ? '' : 's') . " remaining.";
            // Keep OTP session alive so they can retry
        }

        $_SESSION['registration_form'] = $formSnapshot;
        $_SESSION['otp_visible']       = true; // signal page to re-show OTP field
        header("Location: register.php");
        exit;
    }

    // OTP correct — clear attempt counter
    unset($_SESSION['otp_attempts'], $_SESSION['otp_visible']);

    // Validate Leicester email
    if (!preg_match('/@student\.le\.ac\.uk$/i', $email)) {
        $_SESSION['registration_error'] = "You must use your Leicester student email (@student.le.ac.uk).";
        header("Location: register.php");
        exit;
    }

    // Password match check
    if ($password !== $confirmPassword) {
        $_SESSION['registration_error'] = "Passwords do not match.";
        header("Location: register.php");
        exit;
    }

    // Basic password strength (same rules as JS)
    $passwordStrong = strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password);

    if (!$passwordStrong) {
        $_SESSION['registration_error'] = "Password must be at least 8 characters and include uppercase, lowercase, and a number.";
        header("Location: register.php");
        exit;
    }

    // Check if email already exists in users table
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $_SESSION['registration_error'] = "An account with this email already exists.";
            header("Location: register.php");
            exit;
        }

        // Create account (pending approval)
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Simple initials calculation
        $parts = preg_split('/\s+/', $fullName);
        if (count($parts) >= 2) {
            $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
        } else {
            $initials = strtoupper(substr($fullName, 0, 2));
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (
                full_name, email, password, role,
                academic_year, programme_type,
                approval_status, avatar_initials, created_at
            )
            VALUES (?, ?, ?, 'student', ?, ?, 'pending', ?, NOW())
        ");

        $stmt->execute([
            $fullName,
            $email,
            $passwordHash,
            $academicYear,
            $programmeType,
            $initials
        ]);

        $newUserId = (int)$pdo->lastInsertId();

        // Notify all admins about the new registration
        sendAdminRegistrationNotification($pdo, $newUserId, $fullName, $email, $academicYear, $programmeType);

        // Clear OTP session data
        unset($_SESSION['registration_otp'], $_SESSION['registration_email'], $_SESSION['otp_timestamp']);

        $_SESSION['registration_success'] = "Registration successful! Your account is pending admin approval. You will receive an email once approved.";
        header("Location: login.php");
        exit;
    } catch (Exception $e) {
        // error_log('Registration error: ' . $e->getMessage());
        $_SESSION['registration_error'] = "Registration failed. Please try again.";
        header("Location: register.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - InPlace</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

      body {
    font-family: 'DM Sans', sans-serif;
    background: url('/inplace/assets/images/library-bg.jpg') no-repeat center center fixed;
    background-size: cover;
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
    background: rgba(0, 0, 0, 0.35); /* dark overlay */
    z-index: -1;
}
        .auth-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 1200px;
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
            max-width: 300px;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .illustration svg {
            width: 100%;
            height: auto;
            filter: drop-shadow(0 10px 30px rgba(0, 0, 0, 0.2));
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
            overflow-y: auto;
            max-height: 90vh;
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

        .form-group label .required {
            color: #e74c3c;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid #e8dcc8;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s;
            background: #f8f5f0;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #e8a020;
            background: white;
            box-shadow: 0 0 0 3px rgba(232, 160, 32, 0.1);
        }

        .otp-group {
            display: flex;
            gap: 0.75rem;
        }

        .otp-group .form-input {
            flex: 1;
        }

        .btn-send-otp {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            font-size: 0.9375rem;
        }

        .btn-send-otp:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-send-otp:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .status {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status.ok {
            color: #10b981;
        }

        .status.err {
            color: #ef4444;
        }

        .password-strength {
            margin-top: 0.5rem;
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            transition: all 0.3s;
            border-radius: 2px;
        }

        #otp-field {
            display: none;
        }

        #otp-field.active {
            display: block;
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
            opacity: 0.5;
            cursor: not-allowed;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 968px) {
            .auth-container {
                grid-template-columns: 1fr;
            }

            .auth-left {
                padding: 2rem;
                min-height: 300px;
            }

            .form-row {
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
                    <!-- Student with Laptop -->
                    <circle cx="200" cy="180" r="60" fill="#e8a020"/>
                    <ellipse cx="200" cy="150" rx="40" ry="45" fill="white"/>
                    <rect x="160" y="240" width="80" height="100" rx="10" fill="#e8a020"/>
                    <!-- Laptop -->
                    <rect x="120" y="280" width="160" height="100" rx="8" fill="#1a2d4d"/>
                    <rect x="130" y="290" width="140" height="80" rx="4" fill="#10b981" opacity="0.2"/>
                    <line x1="160" y1="320" x2="240" y2="320" stroke="#10b981" stroke-width="4" stroke-linecap="round"/>
                    <!-- Documents -->
                    <rect x="300" y="200" width="60" height="80" rx="4" fill="white" transform="rotate(10 330 240)"/>
                    <line x1="315" y1="220" x2="345" y2="225" stroke="#e8a020" stroke-width="2"/>
                    <line x1="315" y1="235" x2="340" y2="239" stroke="#e8a020" stroke-width="2"/>
                    <!-- Checkmark -->
                    <circle cx="100" cy="120" r="35" fill="#10b981"/>
                    <path d="M85 120 L95 130 L115 105" stroke="white" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>

            <h2>Join InPlace Today</h2>
            <p>Register your account to begin your industrial placement journey at the University of Leicester.</p>
        </div>

        <div class="auth-right">
            <div class="form-header">
                <h1>Create Your Account</h1>
                <p>Already registered? <a href="login.php">Sign in here</a></p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="registrationForm">
                <?php
                $otpVisible   = !empty($_SESSION['otp_visible']);
                $savedEmail   = htmlspecialchars($savedForm['email'] ?? '');
                unset($_SESSION['otp_visible']);
                ?>
                <!-- Email with OTP -->
                <div class="form-group">
                    <label>University Email <span class="required">*</span></label>
                    <div class="otp-group">
                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-input"
                            placeholder="your.name@student.le.ac.uk"
                            value="<?= $savedEmail ?>"
                            <?= $otpVisible ? 'readonly' : '' ?>
                            required
                        >
                        <button type="button" class="btn-send-otp" id="sendOtpBtn" onclick="sendOTP()">
                            <?= $otpVisible ? 'Resend OTP' : 'Send OTP' ?>
                        </button>
                    </div>
                    <small id="emailMsg" class="status"></small>
                </div>

                <!-- OTP Field (hidden initially, shown on retry) -->
                <div class="form-group" id="otp-field" <?= $otpVisible ? '' : 'style="display:none;"' ?>>
                    <label>Enter OTP <span class="required">*</span></label>
                    <input
                        type="text"
                        name="otp"
                        id="otp"
                        class="form-input"
                        placeholder="Enter 6-digit code"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        <?= $otpVisible ? 'autofocus' : '' ?>
                    >
                    <small id="otpMsg" class="status"></small>
                </div>

                <!-- Full Name -->
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input
                        type="text"
                        name="full_name"
                        class="form-input"
                        placeholder="John Smith"
                        value="<?= htmlspecialchars($savedForm['full_name'] ?? '') ?>"
                        required
                    >
                </div>

                <!-- Academic Year & Programme Type -->
                <?php
                $savedYear = $savedForm['academic_year']  ?? '';
                $savedProg = $savedForm['programme_type'] ?? '';
                ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Academic Year <span class="required">*</span></label>
                        <select name="academic_year" class="form-select" required>
                            <option value="">Select year</option>
                            <option value="1st Year"  <?= $savedYear==='1st Year'?'selected':'' ?>>1st Year</option>
                            <option value="2nd Year"  <?= $savedYear==='2nd Year'?'selected':'' ?>>2nd Year</option>
                            <option value="3rd Year"  <?= $savedYear==='3rd Year'?'selected':'' ?>>3rd Year</option>
                            <option value="4th Year"  <?= $savedYear==='4th Year'?'selected':'' ?>>4th Year (Integrated Masters)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Programme Type <span class="required">*</span></label>
                        <select name="programme_type" class="form-select" required>
                            <option value="">Select programme</option>
                            <option value="BSc"  <?= $savedProg==='BSc'?'selected':''  ?>>BSc (Bachelors)</option>
                            <option value="MEng" <?= $savedProg==='MEng'?'selected':'' ?>>MEng (Integrated Masters)</option>
                            <option value="MSc"  <?= $savedProg==='MSc'?'selected':''  ?>>MSc (Masters)</option>
                        </select>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input 
                        type="password" 
                        name="password" 
                        id="password" 
                        class="form-input" 
                        placeholder="Create a strong password"
                        required
                    >
                    <div class="password-strength">
                        <div class="password-strength-bar" id="strengthBar"></div>
                    </div>
                    <small id="pwdMsg" class="status"></small>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label>Confirm Password <span class="required">*</span></label>
                    <input 
                        type="password" 
                        name="confirm_password" 
                        id="confirm_password" 
                        class="form-input" 
                        placeholder="Re-enter your password"
                        required
                    >
                    <small id="confirmMsg" class="status"></small>
                </div>

                <button type="submit" name="register" class="btn-primary" id="submitBtn" disabled>
                    Create Account
                </button>
            </form>
        </div>
    </div>

    <script>
    // In retry mode the OTP field is already visible — treat as pending (user must re-enter OTP)
    const retryMode = <?= ($otpVisible ?? false) ? 'true' : 'false' ?>;
    let otpVerified = false;

    // Send OTP
    function sendOTP() {
        // In retry mode, unlock email so user can change it if needed
        document.getElementById('email').readOnly = false;
        const email = document.getElementById('email').value.trim();
        const sendBtn = document.getElementById('sendOtpBtn');
        const emailMsg = document.getElementById('emailMsg');
        
        if (!email) {
            emailMsg.textContent = "✗ Please enter your email";
            emailMsg.className = "status err";
            return;
        }
        
        // Validate Leicester email
        if (!email.match(/@student\.le\.ac\.uk$/i)) {
            emailMsg.textContent = "✗ Must be a Leicester student email (@student.le.ac.uk)";
            emailMsg.className = "status err";
            return;
        }
        
        sendBtn.disabled = true;
        sendBtn.textContent = "Sending...";
        
        const formData = new FormData();
        formData.append('email', email);


fetch('/inplace/api/send-otp.php', {
  method: 'POST',
  body: formData,
  credentials: 'same-origin'   // IMPORTANT: ensures PHP session works
})
.then(async (res) => {
  const text = await res.text();      // read raw response first
  let data = null;

  try {
    data = JSON.parse(text);          // try parse JSON
  } catch (e) {
    // Not JSON => show raw server output (usually PHP error)
    throw new Error(text);
  }

  if (!res.ok) {
    throw new Error(data.message || 'Request failed');
  }

  return data;
})
.then(data => {
  if (data.success) {
    emailMsg.textContent = "✓ " + data.message;
    emailMsg.className = "status ok";
    document.getElementById('otp-field').style.display = 'block';
    document.getElementById('email').readOnly = true;

    let countdown = 60;
    const interval = setInterval(() => {
      countdown--;
      sendBtn.textContent = `Resend (${countdown}s)`;
      if (countdown <= 0) {
        clearInterval(interval);
        sendBtn.disabled = false;
        sendBtn.textContent = "Resend OTP";
      }
    }, 1000);
  } else {
    emailMsg.textContent = "✗ " + data.message;
    emailMsg.className = "status err";
    sendBtn.disabled = false;
    sendBtn.textContent = "Send OTP";
  }
})
.catch(err => {
  // This will now show the REAL error from PHP (super useful)
  emailMsg.textContent = "✗ " + (err.message || "Error sending OTP");
  emailMsg.className = "status err";
  sendBtn.disabled = false;
  sendBtn.textContent = "Send OTP";
});
    }

    // Verify OTP
    document.getElementById('otp').addEventListener('input', function() {
        const otp = this.value;
        const otpMsg = document.getElementById('otpMsg');
        
        if (otp.length === 6) {
            otpMsg.textContent = "✓ OTP entered";
            otpMsg.className = "status ok";
            otpVerified = true;
            checkFormValidity();
        } else {
            otpMsg.textContent = "";
            otpVerified = false;
            checkFormValidity();
        }
    });

    // Password strength
    document.getElementById('password').addEventListener('input', function() {
        const pwd = this.value;
        const msg = document.getElementById('pwdMsg');
        const bar = document.getElementById('strengthBar');
        
        if (!pwd) {
            msg.textContent = "";
            bar.style.width = "0%";
            return;
        }
        
        let strength = 0;
        if (pwd.length >= 8) strength++;
        if (/[A-Z]/.test(pwd)) strength++;
        if (/[a-z]/.test(pwd)) strength++;
        if (/\d/.test(pwd)) strength++;
        if (/[^A-Za-z0-9]/.test(pwd)) strength++;
        
        const colors = ['#ef4444', '#f59e0b', '#eab308', '#84cc16', '#10b981'];
        const widths = ['20%', '40%', '60%', '80%', '100%'];
        const labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
        
        bar.style.width = widths[strength - 1] || '0%';
        bar.style.background = colors[strength - 1] || '#ef4444';
        
        if (strength >= 4) {
            msg.textContent = "✓ " + labels[strength - 1];
            msg.className = "status ok";
        } else {
            msg.textContent = "✗ Use 8+ chars, uppercase, lowercase, number & symbol";
            msg.className = "status err";
        }
        
        checkFormValidity();
    });

    // Confirm password
    document.getElementById('confirm_password').addEventListener('input', function() {
        const pwd = document.getElementById('password').value;
        const confirm = this.value;
        const msg = document.getElementById('confirmMsg');
        
        if (!confirm) {
            msg.textContent = "";
            return;
        }
        
        if (pwd === confirm) {
            msg.textContent = "✓ Passwords match";
            msg.className = "status ok";
        } else {
            msg.textContent = "✗ Passwords do not match";
            msg.className = "status err";
        }
        
        checkFormValidity();
    });

    function checkFormValidity() {
        const form = document.getElementById('registrationForm');
        const submitBtn = document.getElementById('submitBtn');
        
        const pwd = document.getElementById('password').value;
        const confirm = document.getElementById('confirm_password').value;
        
        const passwordStrong = pwd.length >= 8 && /[A-Z]/.test(pwd) && /[a-z]/.test(pwd) && /\d/.test(pwd);
        const passwordsMatch = pwd === confirm && confirm !== '';
        
        if (otpVerified && passwordStrong && passwordsMatch) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }
    </script>
</body>
</html>