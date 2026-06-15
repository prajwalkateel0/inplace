<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../config/app_config.php';

$pageTitle    = 'Placement Notifications';
$pageSubtitle = 'Report early terminations or significant placement changes';
$activePage   = 'terminate';
$userId       = authId();

$stmt = $pdo->prepare("SELECT company_id, full_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$provider  = $stmt->fetch();
$companyId = $provider['company_id'] ?? null;
if (!$companyId) { header('Location: dashboard.php'); exit; }

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM placements WHERE company_id=? AND status='awaiting_provider'");
$stmt->execute([$companyId]);
$pendingRequests = (int)$stmt->fetchColumn();

// ── Ensure table ─────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS placement_notifications (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        placement_id     INT         NOT NULL,
        provider_user_id INT         NOT NULL,
        notification_type ENUM('early_termination','supervisor_change','role_change','location_change','contract_extension','other') NOT NULL,
        effective_date   DATE        DEFAULT NULL,
        reason           TEXT        NOT NULL,
        details          TEXT        DEFAULT NULL,
        status           ENUM('pending','acknowledged','actioned') DEFAULT 'pending',
        created_at       DATETIME    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$flash = ['msg' => '', 'type' => ''];

// ── POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_action'])) {
    $placementId  = (int)($_POST['placement_id']       ?? 0);
    $notifType    = $_POST['notification_type']        ?? '';
    $effectiveDate = trim($_POST['effective_date']     ?? '');
    $reason       = trim($_POST['reason']              ?? '');
    $details      = trim($_POST['details']             ?? '');

    $validTypes = ['early_termination','supervisor_change','role_change','location_change','contract_extension','other'];
    $chk = $pdo->prepare("SELECT id FROM placements WHERE id=? AND company_id=?");
    $chk->execute([$placementId, $companyId]);

    if (!$chk->fetch() || !in_array($notifType, $validTypes)) {
        $flash = ['msg' => 'Invalid request.', 'type' => 'danger'];
    } elseif (!$reason) {
        $flash = ['msg' => 'Please provide a reason.', 'type' => 'danger'];
    } else {
        $pdo->prepare("
            INSERT INTO placement_notifications
              (placement_id, provider_user_id, notification_type, effective_date, reason, details)
            VALUES (?,?,?,?,?,?)
        ")->execute([$placementId, $userId, $notifType, $effectiveDate ?: null, $reason, $details]);

        // If early termination, update placement status
        if ($notifType === 'early_termination') {
            try {
                $pdo->exec("ALTER TABLE placements ADD COLUMN terminated_reason TEXT DEFAULT NULL");
            } catch (Exception $e) {}
            $pdo->prepare("
                UPDATE placements SET status='terminated', terminated_reason=?
                WHERE id=? AND status IN ('approved','active')
            ")->execute([$reason, $placementId]);
        }

        // Email tutor
        $pi = $pdo->prepare("
            SELECT p.tutor_id, s.full_name AS student_name, s.email AS student_email,
                   t.email AS tutor_email, t.full_name AS tutor_name,
                   c.name AS company_name
            FROM placements p
            JOIN users s ON p.student_id = s.id
            LEFT JOIN users t ON p.tutor_id = t.id
            JOIN companies c ON p.company_id = c.id
            WHERE p.id = ?
        ");
        $pi->execute([$placementId]);
        $pi = $pi->fetch();

        if ($pi) {
            $typeLabels = [
                'early_termination'  => 'Early Termination',
                'supervisor_change'  => 'Supervisor Change',
                'role_change'        => 'Role Change',
                'location_change'    => 'Location Change',
                'contract_extension' => 'Contract Extension',
                'other'              => 'Other Significant Change',
            ];
            $typeLabel = $typeLabels[$notifType] ?? $notifType;

            if ($pi['tutor_id']) {
                $tutors = [['email' => $pi['tutor_email'], 'full_name' => $pi['tutor_name']]];
            } else {
                $ts = $pdo->query("SELECT email, full_name FROM users WHERE role='tutor' AND is_active=1");
                $tutors = $ts->fetchAll();
            }

            loadAppConfig($pdo);
            $mailCfg  = require __DIR__ . '/../config/email_config.php';
            $effectiveHtml = $effectiveDate ? '<tr><td style="padding:0.75rem;background:#f8f5f0;font-weight:600;">Effective Date</td><td style="padding:0.75rem;">' . date('d M Y', strtotime($effectiveDate)) . '</td></tr>' : '';
            $detailsHtml  = $details ? '<tr><td style="padding:0.75rem;background:#f8f5f0;font-weight:600;">Additional Details</td><td style="padding:0.75rem;">' . nl2br(htmlspecialchars($details)) . '</td></tr>' : '';
            $isTermination = ($notifType === 'early_termination');

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
                    $isTermination && $pi['student_email'] && $mail->addCC($pi['student_email'], $pi['student_name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'InPlace — ' . ($isTermination ? 'Early Termination' : 'Placement Change') . ': ' . $pi['student_name'];
                    $mail->Body = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                      <div style='background:" . ($isTermination ? '#7f1d1d' : '#0c1b33') . ";padding:2rem;text-align:center;'>
                        <h1 style='color:#fff;font-size:1.5rem;margin:0;'>InPlace</h1>
                        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;'>" . htmlspecialchars($typeLabel) . " Notification</p>
                      </div>
                      <div style='padding:2rem;'>
                        <p>Dear " . htmlspecialchars($tutor['full_name']) . ",</p>
                        <p>" . htmlspecialchars($pi['company_name']) . " has notified InPlace of a <strong>" . htmlspecialchars($typeLabel) . "</strong> for student <strong>" . htmlspecialchars($pi['student_name']) . "</strong>.</p>
                        <table style='width:100%;border-collapse:collapse;margin:1.5rem 0;'>
                          <tr><td style='padding:0.75rem;background:#f8f5f0;font-weight:600;'>Student</td><td style='padding:0.75rem;'>" . htmlspecialchars($pi['student_name']) . "</td></tr>
                          <tr><td style='padding:0.75rem;font-weight:600;'>Company</td><td style='padding:0.75rem;'>" . htmlspecialchars($pi['company_name']) . "</td></tr>
                          <tr><td style='padding:0.75rem;background:#f8f5f0;font-weight:600;'>Change Type</td><td style='padding:0.75rem;'>" . htmlspecialchars($typeLabel) . "</td></tr>
                          $effectiveHtml
                          <tr><td style='padding:0.75rem;font-weight:600;'>Reason</td><td style='padding:0.75rem;'>" . nl2br(htmlspecialchars($reason)) . "</td></tr>
                          $detailsHtml
                        </table>
                        <p style='color:#6b7a8d;font-size:0.85rem;'>Please log in to InPlace to review and take any necessary action.</p>
                      </div>
                    </div>";
                    $mail->send();
                } catch (MailException $ex) { error_log('Termination email: ' . $mail->ErrorInfo); }
            }
        }

        // Notify the student directly for role/location/supervisor/contract changes
        if ($pi) {
            $studentNotifMsg = match($notifType) {
                'role_change'        => "💼 Your employer has notified a Role Change for your placement. Reason: {$reason}",
                'location_change'    => "📍 Your employer has notified a Location Change for your placement. Reason: {$reason}",
                'supervisor_change'  => "👤 Your employer has notified a Supervisor Change for your placement. Reason: {$reason}",
                'contract_extension' => "📅 Good news — your employer has submitted a Contract Extension for your placement. Reason: {$reason}",
                'early_termination'  => "🔴 Your placement has been terminated early by your employer. Reason: {$reason}",
                default              => "📋 Your employer has submitted a placement change notification ({$typeLabel}). Reason: {$reason}",
            };
            $studStmt = $pdo->prepare("SELECT student_id FROM placements WHERE id=?");
            $studStmt->execute([$placementId]);
            $studentId = (int)($studStmt->fetchColumn() ?: 0);
            if ($studentId) {
                try {
                    $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'placement_change', ?)")
                        ->execute([$studentId, $studentNotifMsg]);
                } catch (Exception $e) { error_log('Terminate notification: ' . $e->getMessage()); }
            }
        }

        $flash = [
            'msg'  => $isTermination
                ? 'Early termination recorded. The student and tutor have been notified.'
                : 'Change notification submitted. The tutor and student have been notified.',
            'type' => 'success',
        ];
    }
}

// ── Load data ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.id AS placement_id, p.role_title, p.start_date, p.end_date, p.status,
           u.full_name AS student_name, u.avatar_initials
    FROM placements p
    JOIN users u ON p.student_id = u.id
    WHERE p.company_id=? AND p.status IN ('approved','active')
    ORDER BY u.full_name
");
$stmt->execute([$companyId]);
$activePlacements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT n.*, u.full_name AS student_name, p.role_title, p.status AS placement_status
    FROM placement_notifications n
    JOIN placements p ON n.placement_id = p.id
    JOIN users u ON p.student_id = u.id
    WHERE p.company_id = ?
    ORDER BY n.created_at DESC
");
$stmt->execute([$companyId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($flash['msg']): ?>
        <div style="background:var(--<?= $flash['type'] ?>-bg);
                    border:1px solid <?= $flash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $flash['type'] ?>);font-weight:500;">
                <?= htmlspecialchars($flash['msg']) ?>
            </p>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start;">

            <!-- Left: notifications log -->
            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Placement Notifications</h3>
                            <p>Terminations and significant changes submitted</p>
                        </div>
                        <button onclick="document.getElementById('notifModal').style.display='flex'"
                                class="btn btn-primary btn-sm">+ Notify Change</button>
                    </div>

                    <?php if (empty($notifications)): ?>
                    <div style="text-align:center;padding:3rem 2rem;">
                        <div style="font-size:2.5rem;margin-bottom:0.75rem;">📋</div>
                        <p style="color:var(--muted);">No notifications submitted yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Type</th>
                                    <th>Effective</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $typeLabels = [
                                    'early_termination'  => '🔴 Early Termination',
                                    'supervisor_change'  => '👤 Supervisor Change',
                                    'role_change'        => '💼 Role Change',
                                    'location_change'    => '📍 Location Change',
                                    'contract_extension' => '📅 Contract Extension',
                                    'other'              => '📝 Other',
                                ];
                                foreach ($notifications as $n):
                                    $badge = match($n['status']) {
                                        'acknowledged' => 'review',
                                        'actioned'     => 'approved',
                                        default        => 'pending',
                                    };
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:500;"><?= htmlspecialchars($n['student_name']) ?></div>
                                        <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($n['role_title'] ?? '') ?></div>
                                    </td>
                                    <td style="font-size:0.875rem;font-weight:500;">
                                        <?= htmlspecialchars($typeLabels[$n['notification_type']] ?? $n['notification_type']) ?>
                                    </td>
                                    <td style="font-size:0.8125rem;">
                                        <?= $n['effective_date'] ? date('d M Y', strtotime($n['effective_date'])) : '—' ?>
                                    </td>
                                    <td style="max-width:180px;font-size:0.8125rem;color:var(--muted);">
                                        <?= htmlspecialchars(substr($n['reason'], 0, 80)) ?><?= strlen($n['reason']) > 80 ? '…' : '' ?>
                                    </td>
                                    <td><span class="badge badge-<?= $badge ?>"><?= ucfirst($n['status']) ?></span></td>
                                    <td style="font-size:0.8125rem;color:var(--muted);">
                                        <?= date('d M Y', strtotime($n['created_at'])) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: guidance -->
            <div style="position:sticky;top:1.5rem;">
                <div class="panel">
                    <div class="panel-header"><h3>Notification Types</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;flex-direction:column;gap:0.75rem;font-size:0.875rem;">
                            <div><strong style="color:var(--navy);">🔴 Early Termination</strong><br>
                                <span style="color:var(--muted);">Placement ending before the agreed end date. Student status will be updated automatically.</span></div>
                            <div><strong style="color:var(--navy);">👤 Supervisor Change</strong><br>
                                <span style="color:var(--muted);">The student's day-to-day supervisor has changed.</span></div>
                            <div><strong style="color:var(--navy);">💼 Role Change</strong><br>
                                <span style="color:var(--muted);">Student's responsibilities or job title has significantly changed.</span></div>
                            <div><strong style="color:var(--navy);">📍 Location Change</strong><br>
                                <span style="color:var(--muted);">Student is now working from a different site or remotely.</span></div>
                            <div><strong style="color:var(--navy);">📅 Contract Extension</strong><br>
                                <span style="color:var(--muted);">Placement end date has been extended beyond originally agreed.</span></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Notification Modal -->
<div id="notifModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);
     z-index:1000;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:560px;margin:auto;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="background:#0c1b33;padding:1.5rem 2rem;border-radius:16px 16px 0 0;
                    display:flex;align-items:center;justify-content:space-between;">
            <h3 style="color:#fff;font-family:'Playfair Display',serif;font-size:1.2rem;margin:0;">
                Submit Placement Notification
            </h3>
            <button onclick="document.getElementById('notifModal').style.display='none'"
                    style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;">✕</button>
        </div>
        <form method="POST" style="padding:2rem;">
            <input type="hidden" name="notif_action" value="submit">

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Student / Placement <span style="color:var(--danger);">*</span></label>
                <select name="placement_id" required
                        style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                               width:100%;font-family:inherit;font-size:0.9375rem;">
                    <option value="">— Select student —</option>
                    <?php foreach ($activePlacements as $ap): ?>
                    <option value="<?= $ap['placement_id'] ?>">
                        <?= htmlspecialchars($ap['student_name']) ?>
                        <?= $ap['role_title'] ? '— ' . htmlspecialchars($ap['role_title']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Notification Type <span style="color:var(--danger);">*</span></label>
                    <select name="notification_type" id="notifType" required
                            style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                   width:100%;font-family:inherit;font-size:0.9375rem;"
                            onchange="toggleTermWarning(this.value)">
                        <option value="">— Select —</option>
                        <option value="early_termination">🔴 Early Termination</option>
                        <option value="supervisor_change">👤 Supervisor Change</option>
                        <option value="role_change">💼 Role Change</option>
                        <option value="location_change">📍 Location Change</option>
                        <option value="contract_extension">📅 Contract Extension</option>
                        <option value="other">📝 Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Effective Date</label>
                    <input type="date" name="effective_date"
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
            </div>

            <div id="termWarning" style="display:none;padding:1rem;background:#fef2f2;border:1px solid #fca5a5;
                                          border-radius:var(--radius-sm);margin-bottom:1.25rem;">
                <p style="color:#991b1b;font-weight:600;margin-bottom:0.25rem;">⚠️ Early Termination</p>
                <p style="color:#991b1b;font-size:0.875rem;">
                    This will mark the placement as terminated in the system. The student's tutor and the student
                    will both be notified immediately.
                </p>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Reason <span style="color:var(--danger);">*</span></label>
                <textarea name="reason" rows="4" required
                          placeholder="Explain the reason for this notification…"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Additional Details <span style="color:var(--muted);font-size:0.8rem;">(optional)</span></label>
                <textarea name="details" rows="2"
                          placeholder="Any other relevant information…"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                <button type="button" onclick="document.getElementById('notifModal').style.display='none'"
                        class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Notification →</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleTermWarning(val) {
    document.getElementById('termWarning').style.display = val === 'early_termination' ? 'block' : 'none';
}
document.getElementById('notifModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include '../includes/footer.php'; ?>
