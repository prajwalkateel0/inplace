<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('provider');

$pageTitle = 'Authorization Requests';
$pageSubtitle = 'Review and respond to placement requests';
$activePage = 'auth-requests';
$userId = authId();

// Get provider's company
$stmt = $pdo->prepare("SELECT company_id, full_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$provider = $stmt->fetch();

// Handle approval/feedback
$actionMsg = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $placementId = $_POST['placement_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $stmt = $pdo->prepare("
            UPDATE placements
            SET status = 'awaiting_tutor',
                provider_approved_at = NOW(),
                provider_approved_by = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$userId, $placementId, $provider['company_id']]);

        // get student, tutor and company details to send notification emails
        $stmt = $pdo->prepare("
            SELECT p.tutor_id,
                   u.full_name AS student_name, u.email AS student_email,
                   c.name AS company_name, p.role_title,
                   t.email AS tutor_email, t.full_name AS tutor_name
            FROM placements p
            JOIN users u ON p.student_id = u.id
            JOIN companies c ON p.company_id = c.id
            LEFT JOIN users t ON p.tutor_id = t.id
            WHERE p.id = ?
        ");
        $stmt->execute([$placementId]);
        $placementInfo = $stmt->fetch();

        if ($placementInfo) {
            $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $tutorUrl    = $scheme . '://' . $host . '/inplace/tutor/requests.php';
            $studentUrl  = $scheme . '://' . $host . '/inplace/student/dashboard.php';

            loadAppConfig($pdo);
            $mailCfg = require __DIR__ . '/../config/email_config.php';

            // email the tutor (or all tutors if none assigned)
            if ($placementInfo['tutor_email']) {
                $tutors = [['email' => $placementInfo['tutor_email'], 'full_name' => $placementInfo['tutor_name']]];
            } else {
                $tStmt  = $pdo->query("SELECT email, full_name FROM users WHERE role='tutor' AND is_active=1");
                $tutors = $tStmt->fetchAll();
            }

            foreach ($tutors as $tutor) {
                $htmlBody = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Awaiting Your Approval</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($tutor['full_name']) . ",</p>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                      A placement request has been approved by the provider and now requires <strong>your approval</strong>.
                    </p>
                    <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Student</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($placementInfo['student_name']) . "</td></tr>
                      <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Company</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($placementInfo['company_name']) . "</td></tr>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>Role</td><td style='padding:0.75rem 1rem;color:#374151;'>" . htmlspecialchars($placementInfo['role_title']) . "</td></tr>
                    </table>
                    <div style='text-align:center;margin:2rem 0;'>
                      <a href='$tutorUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>Review &amp; Approve Placement</a>
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
                    $mail->addAddress($tutor['email'], $tutor['full_name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'InPlace - Placement Awaiting Your Approval: ' . $placementInfo['student_name'];
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = "Placement from {$placementInfo['student_name']} at {$placementInfo['company_name']} needs your approval. Review at: $tutorUrl";
                    $mail->send();
                } catch (MailException $ex) {
                    error_log('Tutor notification email failed: ' . $mail->ErrorInfo);
                }
            }

            // email the student to let them know the provider has confirmed
            if ($placementInfo['student_email']) {
                $studentHtml = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Update</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($placementInfo['student_name']) . ",</p>
                    <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                      Good news! <strong>" . htmlspecialchars($placementInfo['company_name']) . "</strong> has confirmed your placement details.
                      Your request has now been passed to your <strong>Placement Tutor</strong> for final approval.
                    </p>
                    <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                      <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Company</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($placementInfo['company_name']) . "</td></tr>
                      <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>Role</td><td style='padding:0.75rem 1rem;color:#374151;'>" . htmlspecialchars($placementInfo['role_title']) . "</td></tr>
                    </table>
                    <div style='text-align:center;margin:2rem 0;'>
                      <a href='$studentUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>View My Dashboard</a>
                    </div>
                    <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
                  </div>
                </div>";

                $mail2 = new PHPMailer(true);
                try {
                    $mail2->isSMTP();
                    $mail2->Host       = $mailCfg['smtp_host'];
                    $mail2->SMTPAuth   = true;
                    $mail2->Username   = $mailCfg['smtp_user'];
                    $mail2->Password   = $mailCfg['smtp_pass'];
                    $mail2->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail2->Port       = $mailCfg['smtp_port'];
                    $mail2->CharSet    = 'UTF-8';
                    $mail2->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                    $mail2->addAddress($placementInfo['student_email'], $placementInfo['student_name']);
                    $mail2->isHTML(true);
                    $mail2->Subject = 'InPlace - Provider Confirmed Your Placement at ' . $placementInfo['company_name'];
                    $mail2->Body    = $studentHtml;
                    $mail2->AltBody = "Good news! {$placementInfo['company_name']} has confirmed your placement. It is now with your tutor for final approval.";
                    $mail2->send();
                } catch (MailException $ex) {
                    error_log('Student provider-approval email failed: ' . $mail2->ErrorInfo);
                }
            }
        }

        $actionMsg = "Placement approved! The tutor and student have been notified.";
        $actionType = 'success';
        
    } elseif ($action === 'provide_feedback') {
        $feedback = trim($_POST['feedback']);

        $stmt = $pdo->prepare("
            UPDATE placements
            SET provider_feedback = ?,
                provider_feedback_at = NOW()
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$feedback, $placementId, $provider['company_id']]);

        $actionMsg = "✅ Feedback submitted successfully!";
        $actionType = 'success';

    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');

        // Safely add rejection columns
        foreach (['provider_rejection_reason TEXT DEFAULT NULL', 'provider_rejected_at DATETIME DEFAULT NULL'] as $colDef) {
            try { $pdo->exec("ALTER TABLE placements ADD COLUMN $colDef"); } catch (Exception $e) {}
        }

        $stmt = $pdo->prepare("
            UPDATE placements
            SET status='rejected', provider_rejection_reason=?, provider_rejected_at=NOW()
            WHERE id=? AND company_id=? AND status='awaiting_provider'
        ");
        $stmt->execute([$reason, $placementId, $provider['company_id']]);

        // Email student + tutor
        $stmt = $pdo->prepare("
            SELECT p.student_id, p.tutor_id,
                   s.email AS student_email, s.full_name AS student_name,
                   t.email AS tutor_email, t.full_name AS tutor_name,
                   c.name AS company_name, p.role_title
            FROM placements p
            JOIN users s ON p.student_id = s.id
            LEFT JOIN users t ON p.tutor_id = t.id
            JOIN companies c ON p.company_id = c.id
            WHERE p.id = ?
        ");
        $stmt->execute([$placementId]);
        $pi = $stmt->fetch();

        if ($pi) {
            loadAppConfig($pdo);
            $mailCfg  = require __DIR__ . '/../config/email_config.php';
            $reasonHtml = $reason ? '<p style="color:#374151;margin-top:1rem;"><strong>Reason:</strong> ' . nl2br(htmlspecialchars($reason)) . '</p>' : '';
            $recipients = array_filter([
                $pi['student_email'] ? ['email' => $pi['student_email'], 'name' => $pi['student_name']] : null,
                $pi['tutor_email']   ? ['email' => $pi['tutor_email'],   'name' => $pi['tutor_name']]   : null,
            ]);
            foreach ($recipients as $rec) {
                $htmlBody = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                  <div style='background:#0c1b33;padding:2rem;text-align:center;'>
                    <h1 style='color:#fff;font-size:1.5rem;margin:0;'>InPlace</h1>
                    <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Request Not Approved</p>
                  </div>
                  <div style='padding:2rem;'>
                    <p>Dear " . htmlspecialchars($rec['name']) . ",</p>
                    <p>The placement request for <strong>" . htmlspecialchars($pi['student_name']) . "</strong>
                       at <strong>" . htmlspecialchars($pi['company_name']) . "</strong>
                       (" . htmlspecialchars($pi['role_title']) . ") has been <strong style='color:#dc2626;'>rejected</strong> by the provider.</p>
                    $reasonHtml
                    <p style='color:#6b7a8d;font-size:0.85rem;margin-top:1.5rem;'>This is an automated notification from InPlace.</p>
                  </div>
                </div>";
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP(); $mail->Host = $mailCfg['smtp_host']; $mail->SMTPAuth = true;
                    $mail->Username = $mailCfg['smtp_user']; $mail->Password = $mailCfg['smtp_pass'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mailCfg['smtp_port'];
                    $mail->CharSet = 'UTF-8';
                    $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                    $mail->addAddress($rec['email'], $rec['name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'InPlace — Placement Request Rejected: ' . $pi['student_name'];
                    $mail->Body = $htmlBody;
                    $mail->send();
                } catch (MailException $ex) { error_log('Reject email failed: ' . $mail->ErrorInfo); }
            }
        }

        $actionMsg  = 'Placement request rejected. The student and tutor have been notified.';
        $actionType = 'danger';
    }
}

// ── Handle change request approve/reject ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cr_action'])) {
    $crId      = (int)($_POST['cr_id'] ?? 0);
    $crAction  = $_POST['cr_action'];
    $crComment = trim($_POST['cr_comment'] ?? '');

    if (in_array($crAction, ['approve','reject']) && $crId) {
        // Verify this change request belongs to this provider's company
        $stmt = $pdo->prepare("
            SELECT pcr.*, p.company_id, p.tutor_id,
                   u.email AS student_email, u.full_name AS student_name
            FROM placement_change_requests pcr
            JOIN placements p ON pcr.placement_id = p.id
            JOIN users u ON pcr.student_id = u.id
            WHERE pcr.id = ? AND p.company_id = ? AND pcr.status = 'pending_provider'
        ");
        $stmt->execute([$crId, $provider['company_id']]);
        $cr = $stmt->fetch();

        if ($cr) {
            if ($crAction === 'approve') {
                $stmt = $pdo->prepare("
                    UPDATE placement_change_requests
                    SET status = 'pending_tutor', provider_comment = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$crComment, $crId]);
                $actionMsg  = 'Change request approved and forwarded to the tutor for final review.';
                $actionType = 'success';

                // Email tutor(s)
                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $tutorUrl = $scheme . '://' . $host . '/inplace/tutor/requests.php';

                if ($cr['tutor_id']) {
                    $ts = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
                    $ts->execute([$cr['tutor_id']]);
                    $tutors = [$ts->fetch()];
                } else {
                    $ts = $pdo->query("SELECT email, full_name FROM users WHERE role='tutor' AND is_active=1");
                    $tutors = $ts->fetchAll();
                }

                if (!empty($tutors)) {
                    loadAppConfig($pdo);
                    $mailCfg = require __DIR__ . '/../config/email_config.php';
                    $changeTypeLabel = ucwords(str_replace('_', ' ', $cr['change_type']));

                    foreach ($tutors as $tutor) {
                        if (!$tutor || !$tutor['email']) continue;
                        $htmlBody = "
                        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                          <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                            <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                            <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Change Request — Awaiting Your Approval</p>
                          </div>
                          <div style='padding:2rem;'>
                            <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($tutor['full_name']) . ",</p>
                            <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                              The placement provider has approved a change request submitted by
                              <strong>" . htmlspecialchars($cr['student_name']) . "</strong>.
                              It now requires <strong>your approval</strong> to proceed.
                            </p>
                            <table style='width:100%;border-collapse:collapse;margin-bottom:1.5rem;'>
                              <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;width:40%;border-bottom:1px solid #e2e8f0;'>Student</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($cr['student_name']) . "</td></tr>
                              <tr><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;border-bottom:1px solid #e2e8f0;'>Change Type</td><td style='padding:0.75rem 1rem;color:#374151;border-bottom:1px solid #e2e8f0;'>" . htmlspecialchars($changeTypeLabel) . "</td></tr>
                              <tr style='background:#f8f5f0;'><td style='padding:0.75rem 1rem;font-weight:600;color:#0c1b33;'>Justification</td><td style='padding:0.75rem 1rem;color:#374151;'>" . nl2br(htmlspecialchars($cr['justification'])) . "</td></tr>
                            </table>
                            <div style='text-align:center;margin:2rem 0;'>
                              <a href='$tutorUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>Review Change Request</a>
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
                            $mail->addAddress($tutor['email'], $tutor['full_name']);
                            $mail->isHTML(true);
                            $mail->Subject = 'InPlace - Placement Change Request Awaiting Your Approval: ' . $cr['student_name'];
                            $mail->Body    = $htmlBody;
                            $mail->AltBody = "Change request from {$cr['student_name']} needs your approval. Review at: $tutorUrl";
                            $mail->send();
                        } catch (MailException $ex) {
                            error_log('Tutor change request email failed: ' . $mail->ErrorInfo);
                        }
                    }
                }

            } else {
                // Reject
                $stmt = $pdo->prepare("
                    UPDATE placement_change_requests
                    SET status = 'rejected', provider_comment = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$crComment, $crId]);
                $actionMsg  = 'Change request rejected. The student has been notified.';
                $actionType = 'danger';

                // Notify student via message
                try {
                    $tCol = null;
                    $s2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME IN (?,?,?,?)");
                    $s2->execute(['created_at','sent_at','timestamp','date_sent']);
                    foreach (['created_at','sent_at','timestamp','date_sent'] as $c) {
                        if (in_array($c, $s2->fetchAll(PDO::FETCH_COLUMN), true)) { $tCol = $c; break; }
                    }
                    $msgText = "Your placement change request (" . ucwords(str_replace('_',' ',$cr['change_type'])) . ") was not approved by the provider." . ($crComment ? " Reason: $crComment" : "");
                    if ($tCol) {
                        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, `$tCol`, is_read) VALUES (?, ?, ?, NOW(), 0)")->execute([$userId, $cr['student_id'], $msgText]);
                    } else {
                        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, is_read) VALUES (?, ?, ?, 0)")->execute([$userId, $cr['student_id'], $msgText]);
                    }
                } catch (Exception $e) { error_log('CR reject message: ' . $e->getMessage()); }
            }
        }
    }
}

// Fetch all placement requests for this company
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        s.full_name AS student_name,
        s.email AS student_email,
        s.avatar_initials,
        s.academic_year,
        s.programme_type,
        t.full_name AS tutor_name,
        c.name AS company_name
    FROM placements p
    JOIN users s ON p.student_id = s.id
    LEFT JOIN users t ON p.tutor_id = t.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.company_id = ?
    ORDER BY 
        CASE p.status
            WHEN 'awaiting_provider' THEN 1
            WHEN 'awaiting_tutor' THEN 2
            WHEN 'approved' THEN 3
            WHEN 'active' THEN 4
            ELSE 5
        END,
        p.created_at DESC
");
$stmt->execute([$provider['company_id']]);
$requests = $stmt->fetchAll();

// Count pending
$pendingCount = 0;
foreach ($requests as $req) {
    if ($req['status'] === 'awaiting_provider') {
        $pendingCount++;
    }
}

$unreadCount = 0;
$pendingRequests = $pendingCount;

// Fetch change requests for this company
$changeRequests = [];
$pendingChangeCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT pcr.*,
               u.full_name AS student_name,
               u.email     AS student_email
        FROM placement_change_requests pcr
        JOIN placements p ON pcr.placement_id = p.id
        JOIN users u ON pcr.student_id = u.id
        WHERE p.company_id = ?
        ORDER BY FIELD(pcr.status,'pending_provider','pending_tutor','approved','rejected'), pcr.created_at DESC
    ");
    $stmt->execute([$provider['company_id']]);
    $changeRequests = $stmt->fetchAll();
    foreach ($changeRequests as $cr) {
        if ($cr['status'] === 'pending_provider') $pendingChangeCount++;
    }
} catch (Exception $e) { /* table may not exist yet */ }
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:2rem;">
            
            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Pending Approval
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--warning);">
                    <?= $pendingCount ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Total Requests
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--navy);">
                    <?= count($requests) ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Approved
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--success);">
                    <?php
                    $approved = 0;
                    foreach ($requests as $r) {
                        if (in_array($r['status'], ['approved', 'active'])) $approved++;
                    }
                    echo $approved;
                    ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Active Placements
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--info);">
                    <?php
                    $active = 0;
                    foreach ($requests as $r) {
                        if ($r['status'] === 'active') $active++;
                    }
                    echo $active;
                    ?>
                </h3>
            </div>

        </div>

        <!-- Requests Table -->
        <div class="panel">
            <div class="panel-header">
                <h3>📋 All Placement Requests</h3>
            </div>

            <?php if (empty($requests)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">📋</div>
                <p style="color:var(--muted);font-size:1rem;">No placement requests yet.</p>
            </div>

            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Role</th>
                            <th>Dates</th>
                            <th>Year/Programme</th>
                            <th>Tutor</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <!-- Student -->
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?= htmlspecialchars($req['avatar_initials']) ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($req['student_name']) ?></h4>
                                        <p><?= htmlspecialchars($req['student_email']) ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- Role -->
                            <td>
                                <span class="type-chip">
                                    <?= htmlspecialchars($req['role_title'] ?? 'Not specified') ?>
                                </span>
                            </td>

                            <!-- Dates -->
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('d M Y', strtotime($req['start_date'])) ?><br>
                                <span style="color:var(--muted);">to</span><br>
                                <?= date('d M Y', strtotime($req['end_date'])) ?>
                            </td>

                            <!-- Year/Programme -->
                            <td>
                                <?= htmlspecialchars($req['academic_year'] ?? 'N/A') ?><br>
                                <span style="font-size:0.75rem;color:var(--muted);">
                                    <?= htmlspecialchars($req['programme_type'] ?? '') ?>
                                </span>
                            </td>

                            <!-- Tutor -->
                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($req['tutor_name'] ?? 'Unassigned') ?>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php
                                $badgeClass = match($req['status']) {
                                    'awaiting_provider' => 'pending',
                                    'awaiting_tutor' => 'review',
                                    'approved', 'active' => 'approved',
                                    'rejected' => 'rejected',
                                    default => 'open'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucwords(str_replace('_', ' ', $req['status'])) ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td>
                                <?php if ($req['status'] === 'awaiting_provider'): ?>
                                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="placement_id" value="<?= $req['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-success btn-sm">✓ Approve</button>
                                        </form>
                                        <button onclick="showRejectModal(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['student_name'])) ?>')"
                                                class="btn btn-danger btn-sm">✗ Reject</button>
                                        <button onclick="showFeedbackModal(<?= $req['id'] ?>)"
                                                class="btn btn-ghost btn-sm">💬 Comment</button>
                                    </div>
                                <?php elseif ($req['status'] === 'rejected' && $req['provider_rejection_reason'] ?? null): ?>
                                    <span style="font-size:0.8rem;color:var(--danger);"
                                          title="<?= htmlspecialchars($req['provider_rejection_reason'] ?? '') ?>">
                                        Rejected
                                    </span>
                                <?php else: ?>
                                    <a href="view-placement.php?id=<?= $req['id'] ?>"
                                       class="btn btn-ghost btn-sm">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Change Requests Panel ────────────────────────────── -->
        <div class="panel" style="margin-top:2rem;">
            <div class="panel-header">
                <div>
                    <h3>🔄 Placement Change Requests</h3>
                    <p>Students requesting changes to approved placements at your company</p>
                </div>
                <?php if ($pendingChangeCount > 0): ?>
                <span class="badge badge-pending"><?= $pendingChangeCount ?> Pending</span>
                <?php endif; ?>
            </div>

            <?php if (empty($changeRequests)): ?>
            <div style="text-align:center;padding:3rem 2rem;">
                <div style="font-size:2.5rem;margin-bottom:0.75rem;">🔄</div>
                <p style="color:var(--muted);">No change requests yet.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Change Type</th>
                            <th>Justification</th>
                            <th>Proposed Details</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changeRequests as $cr):
                            $crBadge = match($cr['status']) {
                                'pending_provider' => 'pending',
                                'pending_tutor'    => 'review',
                                'approved'         => 'approved',
                                'rejected'         => 'rejected',
                                default            => 'open'
                            };
                            $crTypeLabel = match($cr['change_type']) {
                                'end_date'   => 'Change End Date',
                                'start_date' => 'Change Start Date',
                                'role'       => 'Change Role',
                                'supervisor' => 'Change Supervisor',
                                'salary'     => 'Change Salary / Terms',
                                'transfer'   => 'Transfer Company',
                                default      => ucwords(str_replace('_',' ',$cr['change_type'])),
                            };
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($cr['student_name']) ?></div>
                                <div style="font-size:0.8125rem;color:var(--muted);"><?= htmlspecialchars($cr['student_email']) ?></div>
                            </td>
                            <td><span class="type-chip"><?= htmlspecialchars($crTypeLabel) ?></span></td>
                            <td style="max-width:200px;font-size:0.875rem;"><?= nl2br(htmlspecialchars($cr['justification'])) ?></td>
                            <td style="max-width:180px;font-size:0.875rem;color:var(--muted);">
                                <?= $cr['proposed_details'] ? nl2br(htmlspecialchars($cr['proposed_details'])) : '—' ?>
                            </td>
                            <td><span class="badge badge-<?= $crBadge ?>"><?= ucwords(str_replace('_',' ',$cr['status'])) ?></span></td>
                            <td style="font-size:0.8125rem;color:var(--muted);"><?= date('d M Y', strtotime($cr['created_at'])) ?></td>
                            <td>
                                <?php if ($cr['status'] === 'pending_provider'): ?>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <button class="btn btn-success btn-sm"
                                            onclick="openCrModal(<?= $cr['id'] ?>,'approve')">
                                        ✓ Approve
                                    </button>
                                    <button class="btn btn-danger btn-sm"
                                            onclick="openCrModal(<?= $cr['id'] ?>,'reject')">
                                        ✗ Reject
                                    </button>
                                </div>
                                <?php else: ?>
                                <span style="font-size:0.8125rem;color:var(--muted);">
                                    <?= $cr['provider_comment'] ? htmlspecialchars($cr['provider_comment']) : '—' ?>
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Feedback Modal -->
<div id="feedbackModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
                                z-index:1000;align-items:center;justify-content:center;">
    <div class="panel" style="width:90%;max-width:500px;margin:0;">
        <div class="panel-header">
            <h3>💬 Provide Feedback</h3>
        </div>
        <div class="panel-body">
            <form method="POST" id="feedbackForm">
                <input type="hidden" name="placement_id" id="feedbackPlacementId">
                <input type="hidden" name="action" value="provide_feedback">
                
                <div class="form-group">
                    <label>Feedback / Comments</label>
                    <textarea name="feedback" class="form-input" rows="5" 
                              placeholder="Share your thoughts about this placement request..." required></textarea>
                    <small style="color:var(--muted);display:block;margin-top:0.5rem;">
                        This will be shared with the student and their tutor.
                    </small>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
                    <button type="button" onclick="hideFeedbackModal()" class="btn btn-ghost">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Submit Feedback
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Request Modal -->
<div id="crModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
                          z-index:1001;align-items:center;justify-content:center;">
    <div class="panel" style="width:90%;max-width:480px;margin:0;">
        <div class="panel-header">
            <h3 id="crModalTitle">Review Change Request</h3>
        </div>
        <div class="panel-body">
            <form method="POST" id="crForm">
                <input type="hidden" name="cr_id" id="crId">
                <input type="hidden" name="cr_action" id="crActionInput">
                <div class="form-group">
                    <label>Comment (optional)</label>
                    <textarea name="cr_comment" rows="4"
                              placeholder="Add a comment for the student and tutor..."
                              style="padding:0.875rem 1rem;border:2px solid var(--border);
                                     border-radius:var(--radius-sm);width:100%;font-family:inherit;
                                     font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
                    <button type="button" onclick="document.getElementById('crModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                    <button type="submit" id="crSubmitBtn" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
                              z-index:1002;align-items:center;justify-content:center;">
    <div class="panel" style="width:90%;max-width:480px;margin:0;">
        <div class="panel-header">
            <h3 style="color:var(--danger);">✗ Reject Placement Request</h3>
        </div>
        <div class="panel-body">
            <p id="rejectStudentLabel" style="margin-bottom:1rem;color:var(--muted);font-size:0.9rem;"></p>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="placement_id" id="rejectPlacementId">
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Reason for rejection <span style="color:var(--muted);font-size:0.8rem;">(optional)</span></label>
                    <textarea name="rejection_reason" rows="4"
                              placeholder="Explain why this placement cannot be accommodated…"
                              style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                     width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
                    <small style="color:var(--muted);">This will be shared with the student and their tutor.</small>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
                    <button type="button" onclick="document.getElementById('rejectModal').style.display='none'"
                            class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showRejectModal(placementId, studentName) {
    document.getElementById('rejectPlacementId').value = placementId;
    document.getElementById('rejectStudentLabel').textContent = 'Rejecting request for: ' + studentName;
    document.getElementById('rejectModal').style.display = 'flex';
}
document.getElementById('rejectModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

function openCrModal(crId, action) {
    document.getElementById('crId').value = crId;
    document.getElementById('crActionInput').value = action;
    const title  = document.getElementById('crModalTitle');
    const submit = document.getElementById('crSubmitBtn');
    if (action === 'approve') {
        title.textContent  = '✅ Approve Change Request';
        submit.textContent = 'Approve & Forward to Tutor';
        submit.className   = 'btn btn-success';
    } else {
        title.textContent  = '❌ Reject Change Request';
        submit.textContent = 'Reject Request';
        submit.className   = 'btn btn-danger';
    }
    document.getElementById('crModal').style.display = 'flex';
}
document.getElementById('crModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

function showFeedbackModal(placementId) {
    document.getElementById('feedbackPlacementId').value = placementId;
    document.getElementById('feedbackModal').style.display = 'flex';
}

function hideFeedbackModal() {
    document.getElementById('feedbackModal').style.display = 'none';
}

// Close modal on background click
document.getElementById('feedbackModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideFeedbackModal();
    }
});
</script>

<?php include '../includes/footer.php'; ?>