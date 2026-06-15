<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';
require_once '../../config/app_config.php';
loadAppConfig($pdo);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../../PHPMailer-master/src/SMTP.php';

requireAuth('student');
$userId = authId();

// create the change requests table if it doesn't exist yet
$pdo->exec("
    CREATE TABLE IF NOT EXISTS placement_change_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        placement_id INT NOT NULL,
        student_id INT NOT NULL,
        change_type VARCHAR(50) NOT NULL,
        justification TEXT NOT NULL,
        proposed_details TEXT,
        status ENUM('pending_provider','pending_tutor','approved','rejected') DEFAULT 'pending_provider',
        provider_comment TEXT,
        tutor_comment TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_placement (placement_id),
        INDEX idx_student (student_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inplace/student/my-placement.php');
    exit;
}

$placementId     = (int)($_POST['placement_id'] ?? 0);
$changeType      = trim($_POST['change_type'] ?? '');
$justification   = trim($_POST['justification'] ?? '');
$proposedDetails = trim($_POST['proposed_details'] ?? '');

// make sure this placement belongs to the student and is currently active
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS company_name, c.id AS company_id
    FROM placements p
    JOIN companies c ON p.company_id = c.id
    WHERE p.id = ? AND p.student_id = ? AND p.status IN ('approved','active')
");
$stmt->execute([$placementId, $userId]);
$placement = $stmt->fetch();

if (!$placement || !$changeType || !$justification) {
    $_SESSION['change_error'] = 'Invalid request. Please fill in all required fields.';
    header('Location: /inplace/student/my-placement.php');
    exit;
}

// only allow one pending change request at a time
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM placement_change_requests
    WHERE placement_id = ? AND status IN ('pending_provider','pending_tutor')
");
$stmt->execute([$placementId]);
if ((int)$stmt->fetchColumn() > 0) {
    $_SESSION['change_error'] = 'You already have a pending change request for this placement. Please wait for it to be reviewed.';
    header('Location: /inplace/student/my-placement.php');
    exit;
}

// save the change request to the database
$stmt = $pdo->prepare("
    INSERT INTO placement_change_requests
        (placement_id, student_id, change_type, justification, proposed_details, status)
    VALUES (?, ?, ?, ?, ?, 'pending_provider')
");
$stmt->execute([$placementId, $userId, $changeType, $justification, $proposedDetails]);

// email the provider to notify them about the change request
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$studentName = $stmt->fetchColumn();

$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

// find the provider account linked to the company
$stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE role='provider' AND company_id=? AND is_active=1 LIMIT 1");
$stmt->execute([$placement['company_id']]);
$providerUser = $stmt->fetch();

// if there's no provider account, fall back to the supervisor email from the placement form
$toEmail  = $providerUser ? $providerUser['email'] : ($placement['supervisor_email'] ?? '');
$toName   = $providerUser ? $providerUser['full_name'] : ($placement['supervisor_name'] ?? 'Provider');

$reviewUrl = $scheme . '://' . $host . '/inplace/provider/requests.php';

$changeTypeLabel = match($changeType) {
    'end_date'   => 'Extend / Change End Date',
    'start_date' => 'Change Start Date',
    'role'       => 'Change Role (same company)',
    'supervisor' => 'Change Supervisor',
    'transfer'   => 'Transfer to Different Company',
    'salary'     => 'Change Salary / Terms',
    default      => ucwords(str_replace('_', ' ', $changeType)),
};

if ($toEmail) {
    $mailCfg = require __DIR__ . '/../../config/email_config.php';
    $htmlBody = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;
                border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
      <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
        <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Change Request</p>
      </div>
      <div style='padding:2rem;'>
        <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($toName) . ",</p>
        <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
            A student has submitted a <strong>change request</strong> for their placement at
            <strong>" . htmlspecialchars($placement['company_name']) . "</strong>
            and requires your approval before it can proceed.
        </p>
        <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
          <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Student</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($studentName) . "</td></tr>
          <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Change Type</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($changeTypeLabel) . "</td></tr>
          <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Justification</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . nl2br(htmlspecialchars($justification)) . "</td></tr>
          " . ($proposedDetails ? "<tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>Proposed Details</td><td style='padding:0.75rem 1rem;color:#374151;'>" . nl2br(htmlspecialchars($proposedDetails)) . "</td></tr>" : "") . "
        </table>
        <div style='text-align:center;margin:2rem 0;'>
          <a href='$reviewUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;
             color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>
            Review Change Request
          </a>
        </div>
        <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
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
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'InPlace - Placement Change Request from ' . $studentName;
        $mail->Body    = $htmlBody;
        $mail->AltBody = "Change request from $studentName ($changeTypeLabel). Review at: $reviewUrl";
        $mail->send();
    } catch (MailException $e) {
        error_log('Change request email failed: ' . $mail->ErrorInfo);
    }
}

$_SESSION['change_success'] = 'Your change request has been submitted. The placement provider has been notified.';
header('Location: /inplace/student/my-placement.php');
exit;
