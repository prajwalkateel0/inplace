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

$pageTitle = 'Scheduled Visits';
$pageSubtitle = 'Upcoming tutor visits to your workplace';
$activePage = 'visits';
$userId = authId();

// Get provider's company
$stmt = $pdo->prepare("SELECT company_id, full_name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$provider = $stmt->fetch();

// Fetch all visits for this company's placements
$stmt = $pdo->prepare("
    SELECT 
        v.*,
        p.role_title,
        s.full_name AS student_name,
        s.avatar_initials AS student_initials,
        t.full_name AS tutor_name,
        t.avatar_initials AS tutor_initials,
        t.email AS tutor_email,
        c.name AS company_name,
        c.address AS company_address,
        c.city AS company_city
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN users s ON p.student_id = s.id
    JOIN users t ON p.tutor_id = t.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.company_id = ?
    ORDER BY v.visit_date ASC, v.visit_time ASC
");
$stmt->execute([$provider['company_id']]);
$allVisits = $stmt->fetchAll();

// Also fetch provider meetings scheduled by tutors for this company
try {
    $stmt = $pdo->prepare("
        SELECT
            pm.id,
            pm.meeting_date  AS visit_date,
            pm.meeting_time  AS visit_time,
            pm.duration_hours,
            pm.type,
            pm.location,
            pm.meeting_link,
            pm.agenda        AS purpose,
            pm.status,
            pm.contact_name,
            pm.contact_email,
            t.full_name      AS tutor_name,
            t.avatar_initials AS tutor_initials,
            t.email          AS tutor_email,
            c.name           AS company_name,
            NULL             AS student_name,
            NULL             AS student_initials,
            NULL             AS role_title,
            'provider_meeting' AS record_type
        FROM provider_meetings pm
        JOIN users t ON pm.tutor_id = t.id
        JOIN companies c ON pm.company_id = c.id
        WHERE pm.company_id = ?
        ORDER BY pm.meeting_date ASC, pm.meeting_time ASC
    ");
    $stmt->execute([$provider['company_id']]);
    $providerMeetings = $stmt->fetchAll();
} catch (Exception $e) {
    $providerMeetings = [];
}

// Tag regular visits and merge
foreach ($allVisits as &$v) { $v['record_type'] = 'visit'; }
unset($v);
$allVisits = array_merge($allVisits, $providerMeetings);
usort($allVisits, fn($a, $b) => strcmp($a['visit_date'].' '.$a['visit_time'], $b['visit_date'].' '.$b['visit_time']));

// ── POST: confirm / decline / reschedule ────────────────────────
$visitFlash = ['msg' => '', 'type' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v_action'])) {
    $visitId = (int)($_POST['visit_id'] ?? 0);
    $vAction = $_POST['v_action'];

    // Safely add provider confirmation column
    try { $pdo->exec("ALTER TABLE visits ADD COLUMN provider_confirmed_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}

    // Verify visit belongs to this provider's company
    $chk = $pdo->prepare("SELECT v.id FROM visits v JOIN placements p ON v.placement_id=p.id WHERE v.id=? AND p.company_id=?");
    $chk->execute([$visitId, $provider['company_id']]);

    if ($chk->fetch()) {
        if ($vAction === 'confirm') {
            $pdo->prepare("UPDATE visits SET status='confirmed', provider_confirmed_at=NOW() WHERE id=?")->execute([$visitId]);
            $visitFlash = ['msg' => 'Visit confirmed. The tutor has been notified.', 'type' => 'success'];

        } elseif ($vAction === 'decline') {
            $pdo->prepare("UPDATE visits SET status='cancelled' WHERE id=?")->execute([$visitId]);
            $visitFlash = ['msg' => 'Visit declined. Please contact the tutor to arrange an alternative.', 'type' => 'danger'];

        } elseif ($vAction === 'reschedule') {
            // Create reschedule_proposals table
            $pdo->exec("CREATE TABLE IF NOT EXISTS visit_reschedule_proposals (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                visit_id       INT NOT NULL,
                proposed_by    INT NOT NULL,
                proposed_date  DATE NOT NULL,
                proposed_time  TIME NOT NULL,
                notes          TEXT DEFAULT NULL,
                status         ENUM('pending','accepted','rejected') DEFAULT 'pending',
                created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $propDate = $_POST['proposed_date'] ?? '';
            $propTime = $_POST['proposed_time'] ?? '';
            $notes    = trim($_POST['reschedule_notes'] ?? '');

            if ($propDate && $propTime) {
                $pdo->prepare("INSERT INTO visit_reschedule_proposals (visit_id, proposed_by, proposed_date, proposed_time, notes) VALUES (?,?,?,?,?)")
                    ->execute([$visitId, $userId, $propDate, $propTime, $notes]);
                $pdo->prepare("UPDATE visits SET status='rescheduled' WHERE id=?")->execute([$visitId]);
                $visitFlash = ['msg' => 'Reschedule proposal submitted. The tutor will be notified.', 'type' => 'success'];

                // Email tutor
                $vi = $pdo->prepare("SELECT v.*, s.full_name AS student_name, t.email AS tutor_email, t.full_name AS tutor_name, c.name AS company_name
                    FROM visits v JOIN placements p ON v.placement_id=p.id
                    JOIN users s ON p.student_id=s.id JOIN users t ON p.tutor_id=t.id JOIN companies c ON p.company_id=c.id WHERE v.id=?");
                $vi->execute([$visitId]);
                $vi = $vi->fetch();
                if ($vi && $vi['tutor_email']) {
                    try {
                        loadAppConfig($pdo);
                        $mailCfg = require __DIR__ . '/../config/email_config.php';
                        $mail = new PHPMailer(true);
                        $mail->isSMTP(); $mail->Host = $mailCfg['smtp_host']; $mail->SMTPAuth = true;
                        $mail->Username = $mailCfg['smtp_user']; $mail->Password = $mailCfg['smtp_pass'];
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = $mailCfg['smtp_port'];
                        $mail->CharSet = 'UTF-8';
                        $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                        $mail->addAddress($vi['tutor_email'], $vi['tutor_name']);
                        $mail->isHTML(true);
                        $mail->Subject = 'InPlace — Visit Reschedule Proposal: ' . $vi['company_name'];
                        $mail->Body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                          <div style='background:#0c1b33;padding:1.5rem;text-align:center;'>
                            <h2 style='color:#fff;margin:0;'>Visit Reschedule Request</h2>
                          </div>
                          <div style='padding:2rem;'>
                            <p>Dear " . htmlspecialchars($vi['tutor_name']) . ",</p>
                            <p><strong>" . htmlspecialchars($vi['company_name']) . "</strong> has proposed a new date for the visit with <strong>" . htmlspecialchars($vi['student_name']) . "</strong>.</p>
                            <table style='width:100%;border-collapse:collapse;margin:1rem 0;'>
                              <tr><td style='padding:0.75rem;background:#f8f5f0;font-weight:600;'>Proposed Date</td><td style='padding:0.75rem;'>" . date('d M Y', strtotime($propDate)) . "</td></tr>
                              <tr><td style='padding:0.75rem;background:#f8f5f0;font-weight:600;'>Proposed Time</td><td style='padding:0.75rem;'>" . date('g:i A', strtotime($propTime)) . "</td></tr>
                              " . ($notes ? "<tr><td style='padding:0.75rem;background:#f8f5f0;font-weight:600;'>Notes</td><td style='padding:0.75rem;'>" . nl2br(htmlspecialchars($notes)) . "</td></tr>" : "") . "
                            </table>
                            <p>Please log in to InPlace to accept or arrange an alternative.</p>
                          </div>
                        </div>";
                        $mail->send();
                    } catch (Exception $ex) { error_log('Reschedule email: ' . $ex->getMessage()); }
                }
            }
        }
    }
}

// Separate into upcoming and past
$upcomingVisits = [];
$pastVisits = [];
$today = date('Y-m-d');

foreach ($allVisits as $visit) {
    if ($visit['visit_date'] >= $today) {
        $upcomingVisits[] = $visit;
    } else {
        $pastVisits[] = $visit;
    }
}

$unreadCount = 0;
$pendingRequests = 0;
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($visitFlash['msg']): ?>
        <div style="background:var(--<?= $visitFlash['type'] ?>-bg);
                    border:1px solid <?= $visitFlash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $visitFlash['type'] ?>);font-weight:500;">
                <?= htmlspecialchars($visitFlash['msg']) ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:2rem;">
            
            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Upcoming Visits
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--info);">
                    <?= count($upcomingVisits) ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    This Month
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--navy);">
                    <?php
                    $thisMonth = 0;
                    $currentMonth = date('Y-m');
                    foreach ($upcomingVisits as $v) {
                        if (substr($v['visit_date'], 0, 7) === $currentMonth) {
                            $thisMonth++;
                        }
                    }
                    echo $thisMonth;
                    ?>
                </h3>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.5rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Completed
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--success);">
                    <?php
                    $completed = 0;
                    foreach ($pastVisits as $v) {
                        if ($v['status'] === 'completed') $completed++;
                    }
                    echo $completed;
                    ?>
                </h3>
            </div>

        </div>

        <!-- Upcoming Visits -->
        <div class="panel" style="margin-bottom:2rem;">
            <div class="panel-header">
                <h3>📅 Upcoming Visits</h3>
                <p>Scheduled tutor visits to your workplace</p>
            </div>

            <?php if (empty($upcomingVisits)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">📅</div>
                <p style="color:var(--muted);font-size:1rem;">No upcoming visits scheduled.</p>
            </div>

            <?php else: ?>
            <div class="visit-grid" style="padding:2rem;">
                <?php foreach ($upcomingVisits as $visit): ?>
                <div class="visit-card">
                    
                    <!-- Date Block -->
                    <div class="visit-date-block">
                        <div class="date-box">
                            <div class="day"><?= date('d', strtotime($visit['visit_date'])) ?></div>
                            <div class="month"><?= date('M', strtotime($visit['visit_date'])) ?></div>
                        </div>
                        <div class="visit-date-info">
                            <h4><?= date('l', strtotime($visit['visit_date'])) ?></h4>
                            <p>
                                <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                <?php if ($visit['duration_hours']): ?>
                                    · <?= $visit['duration_hours'] ?>h
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Visit / Meeting Info -->
                    <div class="visit-meta">
                        <?php if (($visit['record_type'] ?? 'visit') === 'provider_meeting'): ?>
                        <div class="visit-meta-row">
                            <span>🤝</span>
                            <strong>Meeting with:</strong>
                            <?= htmlspecialchars($visit['contact_name'] ?: 'Provider Contact') ?>
                        </div>
                        <div class="visit-meta-row">
                            <span>👨‍🏫</span>
                            <strong>Tutor:</strong>
                            <?= htmlspecialchars($visit['tutor_name']) ?>
                        </div>
                        <?php else: ?>
                        <div class="visit-meta-row">
                            <span>👨‍🎓</span>
                            <strong>Student:</strong>
                            <?= htmlspecialchars($visit['student_name']) ?>
                        </div>
                        <div class="visit-meta-row">
                            <span>👨‍🏫</span>
                            <strong>Tutor:</strong>
                            <?= htmlspecialchars($visit['tutor_name']) ?>
                        </div>
                        <div class="visit-meta-row">
                            <span>💼</span>
                            <strong>Role:</strong>
                            <?= htmlspecialchars($visit['role_title']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($visit['location']): ?>
                        <div class="visit-meta-row">
                            <span>📍</span>
                            <strong>Location:</strong>
                            <?= htmlspecialchars($visit['location']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($visit['meeting_link'])): ?>
                        <div class="visit-meta-row">
                            <span>🔗</span>
                            <strong>Link:</strong>
                            <a href="<?= htmlspecialchars($visit['meeting_link']) ?>" target="_blank" rel="noopener">
                                Join Meeting
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Purpose / Agenda -->
                    <?php if (!empty($visit['purpose'] ?? '')): ?>
                    <div style="padding:0.875rem;background:var(--cream);
                                border-radius:var(--radius-sm);margin-bottom:1rem;">
                        <p style="font-size:0.8125rem;color:var(--text);line-height:1.5;">
                            <strong><?= ($visit['record_type'] ?? 'visit') === 'provider_meeting' ? 'Agenda' : 'Purpose' ?>:</strong><br>
                            <?= nl2br(htmlspecialchars($visit['purpose'])) ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- Status Badge + Actions -->
                    <?php
                    $statusBadge = match($visit['status']) {
                        'scheduled'    => ['pending',  'Scheduled'],
                        'confirmed'    => ['approved', 'Confirmed'],
                        'completed'    => ['approved', 'Completed'],
                        'cancelled'    => ['rejected', 'Cancelled'],
                        'rescheduled'  => ['review',   'Reschedule Pending'],
                        default        => ['open',     ucfirst($visit['status'])]
                    };
                    $isProviderMeeting = ($visit['record_type'] ?? 'visit') === 'provider_meeting';
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
                        <span class="badge badge-<?= $statusBadge[0] ?>">
                            <?= $statusBadge[1] ?>
                        </span>
                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                            <?php if (!$isProviderMeeting && $visit['status'] === 'scheduled'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                <input type="hidden" name="v_action" value="confirm">
                                <button type="submit" class="btn btn-success btn-sm">✓ Confirm</button>
                            </form>
                            <button onclick="openReschedule(<?= $visit['id'] ?>)"
                                    class="btn btn-ghost btn-sm">📅 Reschedule</button>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Decline this visit?')">
                                <input type="hidden" name="visit_id" value="<?= $visit['id'] ?>">
                                <input type="hidden" name="v_action" value="decline">
                                <button type="submit" class="btn btn-danger btn-sm">✗ Decline</button>
                            </form>
                            <?php else: ?>
                            <a href="mailto:<?= htmlspecialchars($visit['tutor_email']) ?>"
                               class="btn btn-ghost btn-sm">📧 Contact Tutor</a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Past Visits -->
        <?php if (!empty($pastVisits)): ?>
        <div class="panel">
            <div class="panel-header">
                <h3>📋 Past Visits & Meetings</h3>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Contact / Student</th>
                            <th>Tutor</th>
                            <th>Purpose / Agenda</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($pastVisits, 0, 10) as $visit): ?>
                        <?php $isPM = ($visit['record_type'] ?? 'visit') === 'provider_meeting'; ?>
                        <tr>
                            <td style="font-family:'DM Mono',monospace;font-size:0.875rem;">
                                <?= date('M j, Y', strtotime($visit['visit_date'])) ?><br>
                                <span style="color:var(--muted);font-size:0.75rem;">
                                    <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                </span>
                            </td>

                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar" style="width:32px;height:32px;">
                                        <?= $isPM
                                            ? htmlspecialchars(mb_strtoupper(mb_substr($visit['contact_name'] ?? 'P', 0, 2)))
                                            : htmlspecialchars($visit['student_initials']) ?>
                                    </div>
                                    <div>
                                        <h4 style="font-size:0.875rem;">
                                            <?= $isPM
                                                ? htmlspecialchars($visit['contact_name'] ?: 'Provider Contact')
                                                : htmlspecialchars($visit['student_name']) ?>
                                        </h4>
                                        <?php if ($isPM): ?>
                                        <p style="font-size:0.75rem;color:var(--muted);">Tutor meeting</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td style="font-size:0.875rem;">
                                <?= htmlspecialchars($visit['tutor_name']) ?>
                            </td>

                            <td style="max-width:200px;">
                                <p style="font-size:0.8125rem;color:var(--muted);
                                          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= htmlspecialchars($visit['purpose'] ?? 'General check-in') ?>
                                </p>
                            </td>

                            <td>
                                <?php
                                $statusBadge = match($visit['status']) {
                                    'completed' => ['approved', 'Completed'],
                                    'cancelled' => ['rejected', 'Cancelled'],
                                    default => ['open', ucfirst($visit['status'])]
                                };
                                ?>
                                <span class="badge badge-<?= $statusBadge[0] ?>">
                                    <?= $statusBadge[1] ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!$isPM): ?>
                                <a href="view-visit.php?id=<?= $visit['id'] ?>"
                                   class="btn btn-ghost btn-sm">View Details</a>
                                <?php else: ?>
                                <a href="mailto:<?= htmlspecialchars($visit['tutor_email']) ?>"
                                   class="btn btn-ghost btn-sm">📧 Tutor</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Reschedule Modal -->
<div id="rescheduleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;">
    <div class="panel" style="width:90%;max-width:460px;margin:0;">
        <div class="panel-header">
            <h3>📅 Propose Alternative Date</h3>
            <button onclick="document.getElementById('rescheduleModal').style.display='none'"
                    style="background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--muted);">✕</button>
        </div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="visit_id" id="rescheduleVisitId">
                <input type="hidden" name="v_action" value="reschedule">
                <div class="form-group">
                    <label>Proposed Date <span style="color:var(--danger);">*</span></label>
                    <input type="date" name="proposed_date" required
                           min="<?= date('Y-m-d') ?>"
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
                <div class="form-group">
                    <label>Proposed Time <span style="color:var(--danger);">*</span></label>
                    <input type="time" name="proposed_time" required
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
                <div class="form-group">
                    <label>Notes <span style="color:var(--muted);font-size:0.8rem;">(optional)</span></label>
                    <textarea name="reschedule_notes" rows="3"
                              placeholder="Reason for reschedule or any constraints…"
                              style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                     width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.5rem;">
                    <button type="button" onclick="document.getElementById('rescheduleModal').style.display='none'"
                            class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Proposal →</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openReschedule(visitId) {
    document.getElementById('rescheduleVisitId').value = visitId;
    document.getElementById('rescheduleModal').style.display = 'flex';
}
document.getElementById('rescheduleModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include '../includes/footer.php'; ?>