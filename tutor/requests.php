<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('tutor');

$pageTitle    = 'Authorisation Requests';
$pageSubtitle = 'Review and action student placement requests';
$activePage   = 'requests';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status = 'awaiting_tutor'");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Handle approve / reject action ──────────────────────────────
$actionMsg = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $placementId = (int)$_POST['placement_id'];
    $action      = $_POST['action'];       // 'approved' or 'rejected'
    $comments    = trim($_POST['comments'] ?? '');

    $allowed = ['approved', 'rejected'];
    if (in_array($action, $allowed)) {

        // Update placement status
        $stmt = $pdo->prepare("
            UPDATE placements
            SET status = ?, tutor_comments = ?, tutor_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$action, $comments, $userId, $placementId]);

        // get student details and company name to notify them
        $stmt = $pdo->prepare("
            SELECT p.student_id, u.full_name AS student_name, u.email AS student_email,
                   c.name AS company_name, p.role_title
            FROM placements p
            JOIN users u ON p.student_id = u.id
            JOIN companies c ON p.company_id = c.id
            WHERE p.id = ?
        ");
        $stmt->execute([$placementId]);
        $row = $stmt->fetch();

        if ($row) {
            if ($action === 'approved') {
                $msg = "Your placement request has been approved! Log in to view your placement details.";
            } else {
                $msg = "Your placement request was not approved." . ($comments ? " Tutor feedback: $comments" : " Please contact your tutor for more information.");
            }

            // send an in-app message to the student
            try {
                $tCol = null;
                $s2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages'
                    AND COLUMN_NAME IN (?,?,?,?)");
                $s2->execute(['created_at','sent_at','timestamp','date_sent']);
                $found2 = $s2->fetchAll(PDO::FETCH_COLUMN);
                foreach (['created_at','sent_at','timestamp','date_sent'] as $c) {
                    if (in_array($c, $found2, true)) { $tCol = $c; break; }
                }
                if ($tCol) {
                    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, `$tCol`, is_read) VALUES (?, ?, ?, NOW(), 0)");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, is_read) VALUES (?, ?, ?, 0)");
                }
                $stmt->execute([$userId, $row['student_id'], $msg]);
            } catch (Exception $e) {
                error_log('Tutor notify message failed: ' . $e->getMessage());
            }

            // notifications table entry
            try {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'placement_decision', ?)");
                $stmt->execute([$row['student_id'], $msg]);
            } catch (Exception $e) {}

            // send an email to the student with the tutor's decision
            if ($row['student_email']) {
                try {
                    loadAppConfig($pdo);
                    $mailCfg = require_once __DIR__ . '/../config/email_config.php';

                    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $studentUrl = $scheme . '://' . $host . '/inplace/student/dashboard.php';

                    if ($action === 'approved') {
                        $emailSubject = 'InPlace - Your Placement Has Been Approved!';
                        $headline     = 'Placement Approved';
                        $emailBody    = "
                            <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($row['student_name']) . ",</p>
                            <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                                🎉 Great news! Your placement at <strong>" . htmlspecialchars($row['company_name']) . "</strong>
                                has been <strong style='color:#059669;'>approved</strong> by your Placement Tutor.
                                You can now log in and view your full placement details.
                            </p>";
                    } else {
                        $emailSubject = 'InPlace - Placement Request Update';
                        $headline     = 'Placement Request Not Approved';
                        $emailBody    = "
                            <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($row['student_name']) . ",</p>
                            <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
                                Your placement request at <strong>" . htmlspecialchars($row['company_name']) . "</strong>
                                has not been approved at this time.
                            </p>"
                            . ($comments ? "<p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'><strong>Tutor feedback:</strong> " . nl2br(htmlspecialchars($comments)) . "</p>" : "")
                            . "<p style='color:#374151;font-size:1rem;'>Please contact your tutor if you have any questions.</p>";
                    }

                    $htmlBody = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
                      <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
                        <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
                        <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>$headline</p>
                      </div>
                      <div style='padding:2rem;'>
                        $emailBody
                        <div style='text-align:center;margin:2rem 0;'>
                          <a href='$studentUrl' style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;color:#ffffff !important;text-decoration:none;border-radius:10px;font-weight:700;font-size:1rem;'>View My Dashboard</a>
                        </div>
                        <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;'>This is an automated notification from InPlace.</p>
                      </div>
                    </div>";

                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = $mailCfg['smtp_host'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $mailCfg['smtp_user'];
                    $mail->Password   = $mailCfg['smtp_pass'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = $mailCfg['smtp_port'];
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom($mailCfg['from_email'], $mailCfg['from_name']);
                    $mail->addAddress($row['student_email'], $row['student_name']);
                    $mail->isHTML(true);
                    $mail->Subject = $emailSubject;
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $emailBody));
                    $mail->send();
                } catch (Exception $ex) {
                    error_log('Tutor decision email to student failed: ' . $ex->getMessage());
                }
            }
        }

        // audit log
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details) VALUES (?, ?, 'placements', ?, ?)");
            $stmt->execute([$userId, 'placement_' . $action, $placementId, $comments]);
        } catch (Exception $e) {}

        $actionMsg  = $action === 'approved'
            ? "✅ Placement approved! Student has been notified by email."
            : "❌ Placement rejected. Student has been notified by email.";
        $actionType = $action === 'approved' ? 'success' : 'danger';
    }
}

// ── Handle change request approve / reject ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cr_action'])) {
    $crId     = (int)($_POST['cr_id'] ?? 0);
    $crAction = $_POST['cr_action'];
    $crComment= trim($_POST['cr_comment'] ?? '');

    if (in_array($crAction, ['approve','reject']) && $crId) {
        $stmt = $pdo->prepare("
            SELECT pcr.*,
                   u.email AS student_email, u.full_name AS student_name, u.id AS s_id
            FROM placement_change_requests pcr
            JOIN users u ON pcr.student_id = u.id
            WHERE pcr.id = ? AND pcr.status = 'pending_tutor'
        ");
        $stmt->execute([$crId]);
        $cr = $stmt->fetch();

        if ($cr) {
            $newStatus = $crAction === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare("
                UPDATE placement_change_requests
                SET status = ?, tutor_comment = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$newStatus, $crComment, $crId]);

            $msgText = $crAction === 'approve'
                ? "Your placement change request (" . ucwords(str_replace('_',' ',$cr['change_type'])) . ") has been approved! Please contact your tutor to discuss next steps." . ($crComment ? " Tutor note: $crComment" : "")
                : "Your placement change request (" . ucwords(str_replace('_',' ',$cr['change_type'])) . ") was not approved." . ($crComment ? " Tutor feedback: $crComment" : " Please contact your tutor for more information.");

            // Message student
            try {
                $tCol = null;
                $s2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME IN (?,?,?,?)");
                $s2->execute(['created_at','sent_at','timestamp','date_sent']);
                foreach (['created_at','sent_at','timestamp','date_sent'] as $c) {
                    if (in_array($c, $s2->fetchAll(PDO::FETCH_COLUMN), true)) { $tCol = $c; break; }
                }
                if ($tCol) {
                    $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, `$tCol`, is_read) VALUES (?, ?, ?, NOW(), 0)")->execute([$userId, $cr['s_id'], $msgText]);
                } else {
                    $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body, is_read) VALUES (?, ?, ?, 0)")->execute([$userId, $cr['s_id'], $msgText]);
                }
            } catch (Exception $e) { error_log('CR tutor message: ' . $e->getMessage()); }

            try {
                $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'change_request_decision', ?)")->execute([$cr['s_id'], $msgText]);
            } catch (Exception $e) {}

            $actionMsg  = $crAction === 'approve' ? '✅ Change request approved. Student has been notified.' : '❌ Change request rejected. Student has been notified.';
            $actionType = $crAction === 'approve' ? 'success' : 'danger';
        }
    }
}

// ── Fetch requests visible to tutor (never show draft/awaiting_provider) ──
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

// Tutors only see placements that have passed provider approval
$tutorVisibleStatuses = ['awaiting_tutor', 'approved', 'rejected', 'active', 'terminated'];

$where  = ["p.status IN (" . implode(',', array_fill(0, count($tutorVisibleStatuses), '?')) . ")"];
$params = $tutorVisibleStatuses;

if ($filterStatus && in_array($filterStatus, $tutorVisibleStatuses)) {
    // Replace the base status filter with the specific one
    $where  = ["p.status = ?"];
    $params = [$filterStatus];
}

if ($filterSearch) {
    $where[]  = "(u.full_name LIKE ? OR c.name LIKE ? OR c.city LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.full_name   AS student_name,
        u.email       AS student_email,
        u.avatar_initials AS student_initials,
        c.name        AS company_name,
        c.city        AS company_city,
        c.sector      AS company_sector,
        (SELECT COUNT(*) FROM documents d WHERE d.placement_id = p.id) AS doc_count
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    $whereSQL
    ORDER BY
        FIELD(p.status,'awaiting_tutor','approved','active','rejected','terminated') ASC,
        p.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ── Change requests awaiting tutor ──────────────────────────────
$changeRequests = [];
try {
    $stmt = $pdo->query("
        SELECT pcr.*,
               u.full_name AS student_name, u.email AS student_email,
               c.name AS company_name
        FROM placement_change_requests pcr
        JOIN placements p ON pcr.placement_id = p.id
        JOIN users u ON pcr.student_id = u.id
        JOIN companies c ON p.company_id = c.id
        ORDER BY FIELD(pcr.status,'pending_tutor','pending_provider','approved','rejected'), pcr.created_at DESC
    ");
    $changeRequests = $stmt->fetchAll();
} catch (Exception $e) { /* table may not exist yet */ }

$pendingChangeTutor = 0;
foreach ($changeRequests as $cr) {
    if ($cr['status'] === 'pending_tutor') $pendingChangeTutor++;
}

// Status counts — only for tutor-visible statuses
$stmt = $pdo->query("
    SELECT status, COUNT(*) as cnt
    FROM placements
    WHERE status IN ('awaiting_tutor','approved','rejected','active','terminated')
    GROUP BY status
");
$counts = [];
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = $row['cnt'];
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);border:1px solid <?= $actionType==='success'?'#6ee7b7':($actionType==='danger'?'#fca5a5':'#fcd34d') ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- ── Status Tab Counts ─────────────────────────────── -->
        <div style="display:flex;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <?php
            $tabs = [
                ''               => ['All',             array_sum($counts)],
                'awaiting_tutor' => ['Awaiting Approval', $counts['awaiting_tutor'] ?? 0],
                'approved'       => ['Approved',         $counts['approved'] ?? 0],
                'active'         => ['Active',           $counts['active'] ?? 0],
                'rejected'       => ['Rejected',         $counts['rejected'] ?? 0],
            ];
            foreach ($tabs as $val => [$label, $cnt]):
                $active = ($filterStatus === $val);
            ?>
            <a href="?status=<?= urlencode($val) ?>&search=<?= urlencode($filterSearch) ?>"
               style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.625rem 1.25rem;
                      border-radius:50px;font-size:0.875rem;font-weight:600;text-decoration:none;
                      transition:all 0.2s;
                      background:<?= $active ? 'var(--navy)' : 'var(--white)' ?>;
                      color:<?= $active ? 'var(--white)' : 'var(--muted)' ?>;
                      border:2px solid <?= $active ? 'var(--navy)' : 'var(--border)' ?>;">
                <?= $label ?>
                <span style="background:<?= $active ? 'rgba(255,255,255,0.2)' : 'var(--cream)' ?>;
                             color:<?= $active ? 'var(--white)' : 'var(--text)' ?>;
                             padding:0.1rem 0.5rem;border-radius:50px;font-size:0.75rem;">
                    <?= $cnt ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ── Search / Filter Bar ───────────────────────────── -->
        <form method="GET" style="display:flex;gap:0.875rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <input type="text" name="search"
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   placeholder="🔍  Search student name, company, city..."
                   style="padding:0.6875rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);
                          font-family:inherit;font-size:0.875rem;background:var(--white);min-width:300px;">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($filterSearch || $filterStatus): ?>
                <a href="requests.php" class="btn btn-ghost btn-sm">✕ Clear</a>
            <?php endif; ?>
        </form>

        <!-- ── Requests Table ────────────────────────────────── -->
        <div class="panel">
            <div class="panel-header">
                <h3><?= count($requests) ?> Request<?= count($requests) !== 1 ? 's' : '' ?></h3>
                <p><?= $filterStatus ? ucwords(str_replace('_',' ',$filterStatus)) : 'All statuses' ?></p>
            </div>

            <?php if (empty($requests)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">📋</div>
                <p style="color:var(--muted);font-size:1rem;">No requests found.</p>
            </div>

            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Role</th>
                            <th>Dates</th>
                            <th>Docs</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <!-- Student -->
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?= htmlspecialchars($req['student_initials'] ?? '??') ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($req['student_name']) ?></h4>
                                        <p><?= htmlspecialchars($req['student_email']) ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- Company -->
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($req['company_name']) ?></div>
                                <div style="font-size:0.8125rem;color:var(--muted);">
                                    <?= htmlspecialchars($req['company_city'] ?? '') ?>
                                    <?= $req['company_sector'] ? ' · ' . htmlspecialchars($req['company_sector']) : '' ?>
                                </div>
                            </td>

                            <!-- Role -->
                            <td>
                                <span class="type-chip"><?= htmlspecialchars($req['role_title'] ?? 'N/A') ?></span>
                            </td>

                            <!-- Dates -->
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;color:var(--muted);">
                                <?= date('d M Y', strtotime($req['start_date'])) ?><br>
                                → <?= date('d M Y', strtotime($req['end_date'])) ?>
                            </td>

                            <!-- Docs -->
                            <td style="text-align:center;">
                                <span style="font-size:0.875rem;font-weight:600;color:<?= $req['doc_count'] > 0 ? 'var(--success)' : 'var(--muted)' ?>">
                                    <?= $req['doc_count'] > 0 ? '📎 ' . $req['doc_count'] : '—' ?>
                                </span>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php
                                $badgeClass = match($req['status']) {
                                    'approved'          => 'approved',
                                    'rejected'          => 'rejected',
                                    'submitted',
                                    'awaiting_provider' => 'open',
                                    'awaiting_tutor'    => 'review',
                                    default             => 'pending'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucwords(str_replace('_', ' ', $req['status'])) ?>
                                </span>
                            </td>

                            <!-- Submitted date -->
                            <td style="font-size:0.875rem;color:var(--muted);">
                                <?= date('d M Y', strtotime($req['created_at'])) ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <!-- Always: View Details button -->
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="openDetail(<?= $req['id'] ?>)">
                                        View
                                    </button>

                                    <?php if ($req['status'] === 'awaiting_tutor'): ?>
                                        <!-- Approve -->
                                        <button class="btn btn-success btn-sm"
                                                onclick="openApprove(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['student_name'])) ?>', '<?= htmlspecialchars(addslashes($req['company_name'])) ?>')">
                                            ✓ Approve
                                        </button>
                                        <!-- Reject -->
                                        <button class="btn btn-danger btn-sm"
                                                onclick="openReject(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['student_name'])) ?>')">
                                            ✗ Reject
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- ── Expandable Detail Row ─────────── -->
                        <tr id="detail-<?= $req['id'] ?>" style="display:none;">
                            <td colspan="8" style="background:var(--cream);padding:1.5rem 2rem;">
                                <div class="info-grid" style="margin-bottom:1rem;">
                                    <div class="info-item"><label>Supervisor</label><p><?= htmlspecialchars($req['supervisor_name'] ?? 'N/A') ?></p></div>
                                    <div class="info-item"><label>Supervisor Email</label><p><?= htmlspecialchars($req['supervisor_email'] ?? 'N/A') ?></p></div>
                                    <div class="info-item"><label>Salary</label><p><?= htmlspecialchars($req['salary'] ?? 'Not stated') ?></p></div>
                                    <div class="info-item"><label>Working Pattern</label><p><?= htmlspecialchars($req['working_pattern'] ?? 'N/A') ?></p></div>
                                    <?php if ($req['tutor_comments']): ?>
                                    <div class="info-item"><label>Tutor Comments</label><p><?= htmlspecialchars($req['tutor_comments']) ?></p></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($req['job_description']): ?>
                                <div>
                                    <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">Job Description</p>
                                    <p style="font-size:0.9rem;line-height:1.6;color:var(--text);"><?= nl2br(htmlspecialchars($req['job_description'])) ?></p>
                                </div>
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
                    <p>Students requesting changes to approved placements</p>
                </div>
                <?php if ($pendingChangeTutor > 0): ?>
                <span class="badge badge-review"><?= $pendingChangeTutor ?> Awaiting You</span>
                <?php endif; ?>
            </div>

            <?php if (empty($changeRequests)): ?>
            <div style="text-align:center;padding:3rem 2rem;">
                <div style="font-size:2.5rem;margin-bottom:0.75rem;">🔄</div>
                <p style="color:var(--muted);">No change requests submitted yet.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Change Type</th>
                            <th>Justification</th>
                            <th>Proposed Details</th>
                            <th>Provider Comment</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changeRequests as $cr):
                            $crBadge = match($cr['status']) {
                                'pending_provider' => 'open',
                                'pending_tutor'    => 'review',
                                'approved'         => 'approved',
                                'rejected'         => 'rejected',
                                default            => 'pending'
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
                            <td style="font-size:0.875rem;"><?= htmlspecialchars($cr['company_name']) ?></td>
                            <td><span class="type-chip"><?= htmlspecialchars($crTypeLabel) ?></span></td>
                            <td style="max-width:180px;font-size:0.875rem;"><?= nl2br(htmlspecialchars($cr['justification'])) ?></td>
                            <td style="max-width:160px;font-size:0.875rem;color:var(--muted);">
                                <?= $cr['proposed_details'] ? nl2br(htmlspecialchars($cr['proposed_details'])) : '—' ?>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= $cr['provider_comment'] ? htmlspecialchars($cr['provider_comment']) : '—' ?>
                            </td>
                            <td><span class="badge badge-<?= $crBadge ?>"><?= ucwords(str_replace('_',' ',$cr['status'])) ?></span></td>
                            <td>
                                <?php if ($cr['status'] === 'pending_tutor'): ?>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <button class="btn btn-success btn-sm"
                                            onclick="openTutorCr(<?= $cr['id'] ?>,'approve')">
                                        ✓ Approve
                                    </button>
                                    <button class="btn btn-danger btn-sm"
                                            onclick="openTutorCr(<?= $cr['id'] ?>,'reject')">
                                        ✗ Reject
                                    </button>
                                </div>
                                <?php else: ?>
                                <span style="font-size:0.8125rem;color:var(--muted);">
                                    <?= $cr['tutor_comment'] ? htmlspecialchars($cr['tutor_comment']) : '—' ?>
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

    </div><!-- /page-content -->
</div><!-- /main -->


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Approve
══════════════════════════════════════════════════════════════ -->
<div id="approveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--navy);margin-bottom:0.5rem;">
            ✅ Approve Placement
        </h3>
        <p id="approveSubtitle" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
        <form method="POST">
            <input type="hidden" name="placement_id" id="approvePlacementId">
            <input type="hidden" name="action" value="approved">
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Comments for student (optional)</label>
                <textarea name="comments" rows="3"
                          placeholder="e.g., Approved — all details verified. Good luck with your placement!"
                          style="padding:0.875rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;background:var(--cream);"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-success">✓ Confirm Approval</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Reject
══════════════════════════════════════════════════════════════ -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--danger);margin-bottom:0.5rem;">
            ✗ Reject Request
        </h3>
        <p id="rejectSubtitle" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
        <form method="POST">
            <input type="hidden" name="placement_id" id="rejectPlacementId">
            <input type="hidden" name="action" value="rejected">
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Reason for rejection <span style="color:var(--danger);">*</span></label>
                <textarea name="comments" rows="4" required
                          placeholder="Explain clearly why this request is being rejected and what the student should do next..."
                          style="padding:0.875rem;border:2px solid #fca5a5;border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;background:#fff8f8;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeModals()">Cancel</button>
                <button type="submit" class="btn btn-danger">✗ Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>


<script>
function openDetail(id) {
    const row = document.getElementById('detail-' + id);
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

function openApprove(id, student, company) {
    document.getElementById('approvePlacementId').value = id;
    document.getElementById('approveSubtitle').textContent =
        'You are about to approve ' + student + '\'s placement at ' + company + '.';
    document.getElementById('approveModal').style.display = 'flex';
}

function openReject(id, student) {
    document.getElementById('rejectPlacementId').value = id;
    document.getElementById('rejectSubtitle').textContent =
        'You are about to reject ' + student + '\'s placement request. This action will notify the student.';
    document.getElementById('rejectModal').style.display = 'flex';
}

function closeModals() {
    document.getElementById('approveModal').style.display = 'none';
    document.getElementById('rejectModal').style.display  = 'none';
    const cm = document.getElementById('tutorCrModal');
    if (cm) cm.style.display = 'none';
}

// Close on outside click
['approveModal','rejectModal','tutorCrModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === this) closeModals(); });
});

function openTutorCr(crId, action) {
    document.getElementById('tutorCrId').value     = crId;
    document.getElementById('tutorCrAction').value = action;
    const title  = document.getElementById('tutorCrTitle');
    const submit = document.getElementById('tutorCrSubmit');
    if (action === 'approve') {
        title.textContent  = '✅ Approve Change Request';
        submit.textContent = '✓ Confirm Approval';
        submit.className   = 'btn btn-success';
    } else {
        title.textContent  = '❌ Reject Change Request';
        submit.textContent = '✗ Confirm Rejection';
        submit.className   = 'btn btn-danger';
    }
    document.getElementById('tutorCrModal').style.display = 'flex';
}
</script>

<!-- Tutor Change Request Modal -->
<div id="tutorCrModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1001;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 id="tutorCrTitle" style="font-family:'Playfair Display',serif;font-size:1.375rem;color:var(--navy);margin-bottom:1.5rem;"></h3>
        <form method="POST">
            <input type="hidden" name="cr_id"     id="tutorCrId">
            <input type="hidden" name="cr_action" id="tutorCrAction">
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Comment for student (optional)</label>
                <textarea name="cr_comment" rows="4"
                          placeholder="Add a note for the student..."
                          style="padding:0.875rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;background:var(--cream);"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeModals()">Cancel</button>
                <button type="submit" id="tutorCrSubmit" class="btn btn-success">Confirm</button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>