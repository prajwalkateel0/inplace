<?php
session_start();
require_once __DIR__ . '/../config/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["success" => false, "message" => "Invalid request method"]);
  exit;
}

$email = trim($_POST['email'] ?? '');
$emailLower = strtolower($email);

if (!filter_var($emailLower, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(["success" => false, "message" => "Invalid email format"]);
  exit;
}

if (!preg_match('/@student\.le\.ac\.uk$/i', $emailLower)) {
  echo json_encode(["success" => false, "message" => "Must use Leicester student email (@student.le.ac.uk)"]);
  exit;
}

// Check if already registered
$stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
$stmt->execute([$emailLower]);
if ($stmt->fetch()) {
  echo json_encode(["success" => false, "message" => "This email is already registered"]);
  exit;
}

// Generate OTP
$otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Store OTP in session
$_SESSION['registration_otp']   = password_hash($otp, PASSWORD_DEFAULT);
$_SESSION['registration_email'] = $emailLower;
$_SESSION['otp_timestamp']      = time();

// Mail config
$mailCfg = require __DIR__ . '/../config/email_config.php';

$mail = new PHPMailer(true);

try {
  $mail->isSMTP();
  $mail->Host       = $mailCfg['smtp_host'];
  $mail->SMTPAuth   = true;
  $mail->Username   = $mailCfg['smtp_user'];
  $mail->Password   = $mailCfg['smtp_pass'];
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = $mailCfg['smtp_port'];

  // IMPORTANT for Gmail: From should match the authenticated account
  $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
  $mail->addAddress($emailLower);

  $mail->isHTML(true);
  $mail->Subject = 'InPlace - Email Verification Code';
  $mail->Body    = "<h2>Email Verification</h2>
                    <p>Your OTP is: <b style='font-size:22px;letter-spacing:4px;'>$otp</b></p>
                    <p>Expires in 10 minutes.</p>";
  $mail->AltBody = "Your InPlace OTP is: $otp (expires in 10 minutes).";

  $mail->send();

  echo json_encode(["success" => true, "message" => "OTP sent successfully!"]);
  exit;

} catch (Exception $e) {
  echo json_encode(["success" => false, "message" => "Failed to send OTP: " . $mail->ErrorInfo]);
  exit;
}