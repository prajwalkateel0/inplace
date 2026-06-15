<?php
/**
 * provider/confirm.php
 * NF03 / F02 — Token-based placement confirmation without requiring full login.
 *
 * Flow:
 *  1. Tutor emails provider with a unique link: provider/confirm.php?token=xxx
 *  2. This page shows placement details + Approve / Reject buttons (no login needed)
 *  3. On action, updates placement status and emails the tutor
 *  4. Token is single-use and expires in 7 days
 *
 * To generate a token from anywhere, call generateProviderToken($pdo, $placementId, $email)
 * and embed the returned URL in the notification email.
 */

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

// ── Ensure token table exists ────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS provider_tokens (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        token        VARCHAR(64)  NOT NULL UNIQUE,
        placement_id INT          NOT NULL,
        email        VARCHAR(255) NOT NULL,
        expires_at   DATETIME     NOT NULL,
        used_at      DATETIME     DEFAULT NULL,
        created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$flash   = ['msg' => '', 'type' => ''];
$placement = null;
$tokenRow  = null;
$done      = false;

// ── Validate token ───────────────────────────────────────────────
if ($token) {
    $stmt = $pdo->prepare("SELECT * FROM provider_tokens WHERE token=? AND used_at IS NULL AND expires_at >= NOW()");
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tokenRow) {
        $stmt = $pdo->prepare("
            SELECT p.*, s.full_name AS student_name, s.email AS student_email,
                   s.academic_year, s.programme_type,
                   c.name AS company_name, c.address AS company_address, c.city AS company_city,
                   t.full_name AS tutor_name, t.email AS tutor_email
            FROM placements p
            JOIN users s ON p.student_id = s.id
            JOIN companies c ON p.company_id = c.id
            LEFT JOIN users t ON p.tutor_id = t.id
            WHERE p.id = ?
        ");
        $stmt->execute([$tokenRow['placement_id']]);
        $placement = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// ── POST: process action ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow && $placement) {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($action === 'approve' && $placement['status'] === 'awaiting_provider') {
        $pdo->prepare("
            UPDATE placements SET status='awaiting_tutor', provider_approved_at=NOW()
            WHERE id=? AND status='awaiting_provider'
        ")->execute([$tokenRow['placement_id']]);

        $pdo->prepare("UPDATE provider_tokens SET used_at=NOW() WHERE token=?")
            ->execute([$token]);

        $flash = ['msg' => '✅ Placement approved! The tutor has been notified and will complete the final review.', 'type' => 'success'];
        $done  = true;

        // Email tutors
        if ($placement['tutor_email']) {
            $tutors = [['email' => $placement['tutor_email'], 'full_name' => $placement['tutor_name']]];
        } else {
            $ts = $pdo->query("SELECT email, full_name FROM users WHERE role='tutor' AND is_active=1");
            $tutors = $ts->fetchAll();
        }
        loadAppConfig($pdo);
        $mailCfg  = require __DIR__ . '/../config/email_config.php';
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $tutorUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/inplace/tutor/requests.php';

        foreach ($tutors as $tutor) {
            if (!$tutor['email']) continue;
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP(); $mail->Host = $mailCfg['smtp_host']; $mail->SMTPAuth = true;
                $mail->Username = $mailCfg['smtp_user']; $mail->Password = $mailCfg['smtp_pass'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mailCfg['smtp_port'];
                $mail->CharSet = 'UTF-8';
                $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                $mail->addAddress($tutor['email'], $tutor['full_name']);
                $mail->isHTML(true);
                $mail->Subject = 'InPlace — Placement Awaiting Your Approval: ' . $placement['student_name'];
                $mail->Body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#fff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;'>Placement Awaiting Tutor Approval</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p>Dear " . htmlspecialchars($tutor['full_name']) . ",</p>
                    <p>Provider has approved the placement for <strong>" . htmlspecialchars($placement['student_name']) . "</strong>
                       at <strong>" . htmlspecialchars($placement['company_name']) . "</strong>. Your approval is now required.</p>
                    <div style='text-align:center;margin:2rem 0;'>
                      <a href='$tutorUrl' style='padding:0.875rem 2rem;background:#0c1b33;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;'>
                        Review &amp; Approve
                      </a>
                    </div>
                  </div>
                </div>";
                $mail->send();
            } catch (MailException $ex) { error_log('Token approve email: ' . $mail->ErrorInfo); }
        }

    } elseif ($action === 'reject') {
        foreach (['provider_rejection_reason TEXT DEFAULT NULL', 'provider_rejected_at DATETIME DEFAULT NULL'] as $cd) {
            try { $pdo->exec("ALTER TABLE placements ADD COLUMN $cd"); } catch (Exception $e) {}
        }
        $pdo->prepare("
            UPDATE placements SET status='rejected', provider_rejection_reason=?, provider_rejected_at=NOW()
            WHERE id=? AND status='awaiting_provider'
        ")->execute([$reason, $tokenRow['placement_id']]);

        $pdo->prepare("UPDATE provider_tokens SET used_at=NOW() WHERE token=?")
            ->execute([$token]);

        $flash = ['msg' => 'Placement request declined. The student and tutor have been notified.', 'type' => 'danger'];
        $done  = true;

        // Email student + tutor
        loadAppConfig($pdo);
        $mailCfg = require __DIR__ . '/../config/email_config.php';
        $reasonHtml = $reason ? '<p><strong>Reason:</strong> ' . nl2br(htmlspecialchars($reason)) . '</p>' : '';
        $recipients = array_filter([
            $placement['student_email'] ? ['email' => $placement['student_email'], 'name' => $placement['student_name']] : null,
            $placement['tutor_email']   ? ['email' => $placement['tutor_email'],   'name' => $placement['tutor_name']]   : null,
        ]);
        foreach ($recipients as $rec) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP(); $mail->Host = $mailCfg['smtp_host']; $mail->SMTPAuth = true;
                $mail->Username = $mailCfg['smtp_user']; $mail->Password = $mailCfg['smtp_pass'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mailCfg['smtp_port'];
                $mail->CharSet = 'UTF-8';
                $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                $mail->addAddress($rec['email'], $rec['name']);
                $mail->isHTML(true);
                $mail->Subject = 'InPlace — Placement Request Not Approved: ' . $placement['student_name'];
                $mail->Body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#fff;font-size:1.5rem;margin:0;'>InPlace</h1>
                  </div>
                  <div style='padding:2rem;'>
                    <p>Dear " . htmlspecialchars($rec['name']) . ",</p>
                    <p>The placement request for <strong>" . htmlspecialchars($placement['student_name']) . "</strong>
                       at <strong>" . htmlspecialchars($placement['company_name']) . "</strong> has been <strong style='color:#dc2626;'>declined</strong> by the provider.</p>
                    $reasonHtml
                  </div>
                </div>";
                $mail->send();
            } catch (MailException $ex) { error_log('Token reject email: ' . $mail->ErrorInfo); }
        }
    }
}

function hs($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Placement Confirmation — InPlace</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'DM Sans',sans-serif; background:#f8f5f0; min-height:100vh;
               display:flex; flex-direction:column; align-items:center; justify-content:flex-start;
               padding:2rem 1rem; }
        .card { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(12,27,51,0.10);
                width:100%; max-width:640px; overflow:hidden; }
        .card-header { background:#0c1b33; padding:2rem; text-align:center; }
        .card-header h1 { color:#fff; font-family:'Playfair Display',serif; font-size:1.5rem; margin:0 0 0.25rem; }
        .card-header p  { color:rgba(255,255,255,0.75); font-size:0.9rem; margin:0; }
        .card-body { padding:2rem; }
        .info-table { width:100%; border-collapse:collapse; margin:1.25rem 0; }
        .info-table td { padding:0.75rem 1rem; border-bottom:1px solid #e5e7eb; font-size:0.9375rem; }
        .info-table td:first-child { font-weight:600; color:#0c1b33; width:38%; background:#f8f5f0; }
        .btn { display:inline-block; padding:0.875rem 1.75rem; border-radius:10px; font-weight:700;
               font-size:1rem; cursor:pointer; border:2px solid transparent; text-decoration:none; }
        .btn-approve { background:#059669; color:#fff; border-color:#059669; }
        .btn-reject  { background:#dc2626; color:#fff; border-color:#dc2626; }
        .btn-ghost   { background:transparent; color:#6b7a8d; border-color:#d1d5db; }
        .btn:hover { opacity:0.88; }
        .alert { padding:1rem 1.5rem; border-radius:10px; margin-bottom:1.5rem; font-weight:500; }
        .alert-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-danger  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
        .badge { display:inline-block; padding:0.25rem 0.75rem; border-radius:20px; font-size:0.8rem; font-weight:600; }
        .badge-pending { background:#fef3c7; color:#92400e; }
        label { display:block; font-weight:600; margin-bottom:0.4rem; font-size:0.9rem; color:#374151; }
        textarea { width:100%; padding:0.75rem 1rem; border:1.5px solid #d1d5db; border-radius:8px;
                   font-family:inherit; font-size:0.9375rem; resize:vertical; }
        textarea:focus { outline:none; border-color:#0c1b33; }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <h1>InPlace</h1>
        <p>Placement Authorisation Request</p>
    </div>
    <div class="card-body">

        <?php if ($flash['msg']): ?>
        <div class="alert alert-<?= $flash['type'] ?>"><?= $flash['msg'] ?></div>
        <?php endif; ?>

        <?php if (!$token || !$tokenRow): ?>
            <!-- Invalid / expired token -->
            <div style="text-align:center;padding:2rem 0;">
                <div style="font-size:3rem;margin-bottom:1rem;">🔗</div>
                <h2 style="color:#0c1b33;margin-bottom:0.75rem;">Link Invalid or Expired</h2>
                <p style="color:#6b7a8d;">This confirmation link is not valid or has already been used.</p>
                <p style="margin-top:1rem;">
                    <a href="/inplace/login.php" style="color:#0c1b33;font-weight:600;">Log in to InPlace →</a>
                </p>
            </div>

        <?php elseif ($done): ?>
            <!-- Action completed -->
            <div style="text-align:center;padding:2rem 0;">
                <div style="font-size:3rem;margin-bottom:1rem;"><?= $flash['type']==='success'?'✅':'❌' ?></div>
                <h2 style="color:#0c1b33;margin-bottom:0.75rem;">Response Recorded</h2>
                <p style="color:#6b7a8d;margin-bottom:1.5rem;">
                    <?= $flash['type']==='success'
                        ? 'The placement has been approved and forwarded to the tutor.'
                        : 'The placement request has been declined. The student and tutor have been notified.' ?>
                </p>
                <a href="/inplace/login.php" class="btn btn-ghost">Log in to InPlace</a>
            </div>

        <?php elseif ($placement): ?>
            <!-- Show placement details + decision form -->
            <h2 style="font-family:'Playfair Display',serif;color:#0c1b33;margin-bottom:0.25rem;">
                Review Placement Request
            </h2>
            <p style="color:#6b7a8d;font-size:0.9rem;margin-bottom:1.5rem;">
                Please review the details below and approve or decline this request.
            </p>

            <?php if ($placement['status'] !== 'awaiting_provider'): ?>
            <div class="alert alert-<?= in_array($placement['status'],['approved','awaiting_tutor','active'])? 'success':'danger' ?>">
                This placement has already been <?= hs(str_replace('_',' ',$placement['status'])) ?>.
                No further action is needed.
            </div>
            <?php else: ?>

            <table class="info-table">
                <tr><td>Student</td><td><?= hs($placement['student_name']) ?></td></tr>
                <tr><td>Role / Position</td><td><?= hs($placement['role_title'] ?? 'Not specified') ?></td></tr>
                <tr><td>Company</td><td><?= hs($placement['company_name']) ?></td></tr>
                <tr><td>Location</td><td><?= hs($placement['company_city'] ?? '—') ?></td></tr>
                <tr><td>Start Date</td><td><?= date('d M Y', strtotime($placement['start_date'])) ?></td></tr>
                <tr><td>End Date</td><td><?= date('d M Y', strtotime($placement['end_date'])) ?></td></tr>
                <tr><td>Academic Year</td><td><?= hs($placement['academic_year'] ?? '—') ?></td></tr>
                <tr><td>Programme</td><td><?= hs($placement['programme_type'] ?? '—') ?></td></tr>
                <tr><td>Assigned Tutor</td><td><?= hs($placement['tutor_name'] ?? 'TBC') ?></td></tr>
                <tr><td>Status</td><td><span class="badge badge-pending">Awaiting Your Response</span></td></tr>
            </table>

            <!-- Approve -->
            <form method="POST" style="margin-bottom:1.5rem;">
                <input type="hidden" name="token" value="<?= hs($token) ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-approve" style="width:100%;font-size:1.05rem;">
                    ✓ Approve This Placement
                </button>
            </form>

            <!-- Reject with reason -->
            <details style="border:1.5px solid #e5e7eb;border-radius:10px;padding:1rem;">
                <summary style="cursor:pointer;font-weight:600;color:#dc2626;font-size:0.9375rem;">
                    ✗ Decline / Reject This Request
                </summary>
                <form method="POST" style="margin-top:1rem;">
                    <input type="hidden" name="token" value="<?= hs($token) ?>">
                    <input type="hidden" name="action" value="reject">
                    <div style="margin-bottom:1rem;">
                        <label>Reason for declining <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                        <textarea name="reason" rows="3"
                                  placeholder="e.g. Position has been filled, capacity constraints…"></textarea>
                    </div>
                    <button type="submit" class="btn btn-reject"
                            onclick="return confirm('Confirm decline? This will notify the student and their tutor.')">
                        Confirm Decline
                    </button>
                </form>
            </details>

            <p style="color:#9ca3af;font-size:0.8rem;text-align:center;margin-top:1.5rem;">
                This link is single-use and expires <?= date('d M Y', strtotime($tokenRow['expires_at'])) ?>.
                If you need to log in to manage all placements,
                <a href="/inplace/login.php" style="color:#0c1b33;">click here</a>.
            </p>

            <?php endif; // status check ?>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
