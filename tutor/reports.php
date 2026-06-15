<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/storage_helper.php';
require_once '../config/app_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

requireAuth('tutor');

$pageTitle    = 'Student Reports';
$pageSubtitle = 'Review and approve placement reports';
$activePage   = 'reports';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Handle send reminder ─────────────────────────────────────────
$actionMsg  = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $studentId    = (int)$_POST['student_id'];
    $studentEmail = trim($_POST['student_email']);
    $studentName  = trim($_POST['student_name']);
    $missingTypes = $_POST['missing_types'] ?? [];   // ['interim','final']

    if (!$studentEmail || empty($missingTypes)) {
        $actionMsg  = "Could not send reminder: student email or report type is missing.";
        $actionType = 'danger';
    } else {
        loadAppConfig($pdo);
        $mailCfg = require __DIR__ . '/../config/email_config.php';

        $missingList = implode(' and ', array_map('ucfirst', $missingTypes)) . ' Report' . (count($missingTypes) > 1 ? 's' : '');
        $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $reportsUrl  = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/inplace/login.php';
        $tutorName   = authName();

        $htmlBody = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;
                    border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;'>
          <div style='background-color:#0c1b33;padding:2rem;text-align:center;'>
            <h1 style='color:#ffffff;font-size:1.5rem;margin:0;'>InPlace</h1>
            <p style='color:rgba(255,255,255,0.8);margin:0.5rem 0 0;font-size:0.9rem;'>Placement Report Reminder</p>
          </div>
          <div style='padding:2rem;'>
            <p style='color:#374151;font-size:1rem;margin-bottom:1rem;'>Dear " . htmlspecialchars($studentName) . ",</p>
            <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
              This is a reminder that your <strong>" . htmlspecialchars($missingList) . "</strong>
              for your placement has not yet been submitted on InPlace.
            </p>
            <p style='color:#374151;font-size:1rem;margin-bottom:1.5rem;'>
              Please log in and upload your report as soon as possible.
            </p>
            <div style='text-align:center;margin:2rem 0;'>
              <a href='$reportsUrl'
                 style='display:inline-block;padding:0.875rem 2rem;background-color:#0c1b33;
                        color:#ffffff !important;text-decoration:none;border-radius:10px;
                        font-weight:700;font-size:1rem;border:2px solid #0c1b33;'>
                Submit My Report
              </a>
            </div>
            <p style='color:#6b7a8d;font-size:0.875rem;'>
              If you have any questions, please contact your tutor " . htmlspecialchars($tutorName) . " directly.
            </p>
            <p style='color:#6b7a8d;font-size:0.85rem;text-align:center;margin-top:2rem;'>
              This is an automated notification from InPlace.
            </p>
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
            $mail->addAddress($studentEmail, $studentName);
            $mail->isHTML(true);
            $mail->Subject = 'InPlace — Report Reminder: ' . $missingList . ' Required';
            $mail->Body    = $htmlBody;
            $mail->AltBody = "Reminder: Your $missingList has not been submitted. Log in at: $reportsUrl";
            $mail->send();
            $actionMsg  = "Reminder sent to " . htmlspecialchars($studentName) . " (" . htmlspecialchars($studentEmail) . ") successfully.";
            $actionType = 'success';
        } catch (MailException $e) {
            error_log('Reminder email failed: ' . $mail->ErrorInfo);
            $actionMsg  = "Email failed: " . $mail->ErrorInfo;
            $actionType = 'danger';
        } catch (\Exception $e) {
            error_log('Reminder email exception: ' . $e->getMessage());
            $actionMsg  = "Email failed: " . $e->getMessage();
            $actionType = 'danger';
        }
    }
}

// ── Handle approve/reject report action ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $docId   = (int)$_POST['document_id'];
    $action  = $_POST['action'];  // 'approved' or 'revision_needed'
    $feedback = trim($_POST['feedback'] ?? '');

    if (in_array($action, ['approved', 'revision_needed'])) {
        $stmt = $pdo->prepare("
            UPDATE documents
            SET status = ?, reviewer_feedback = ?, reviewed_at = NOW(), reviewed_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$action, $feedback, $userId, $docId]);

        // Notify student
        $stmt = $pdo->prepare("SELECT uploaded_by FROM documents WHERE id = ?");
        $stmt->execute([$docId]);
        $row = $stmt->fetch();

        if ($row) {
            if ($action === 'approved') {
                $msg = "Your report has been approved by your placement tutor." . ($feedback ? " Feedback: $feedback" : "");
            } else {
                $msg = "Your report requires revisions. Tutor feedback: $feedback";
            }
            try {
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'report_reviewed', ?)");
                $stmt->execute([$row['uploaded_by'], $msg]);
            } catch (Exception $e) { /* notifications table may not exist */ }
        }

        $actionMsg  = $action === 'approved'
            ? "✅ Report approved successfully!"
            : "📝 Revision request sent to student.";
        $actionType = $action === 'approved' ? 'success' : 'warning';
    }
}

// ── Filters ──────────────────────────────────────────────────────
$filterType   = $_GET['type']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

$where  = ["d.doc_type IN ('interim_report', 'final_report')"];
$params = [];

if ($filterType) {
    $where[]  = "d.doc_type = ?";
    $params[] = $filterType;
}

if ($filterStatus) {
    $where[]  = "d.status = ?";
    $params[] = $filterStatus;
} else {
    // Default: show pending, approved and NULL status, hide rejected
    $where[] = "(d.status IS NULL OR d.status != 'rejected')";
}

if ($filterSearch) {
    $where[]  = "(u.full_name LIKE ? OR c.name LIKE ?)";
    $params[] = "%$filterSearch%";
    $params[] = "%$filterSearch%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Fetch all reports ────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        d.*,
        u.full_name       AS student_name,
        u.email           AS student_email,
        u.avatar_initials AS student_initials,
        c.name            AS company_name,
        p.role_title,
        p.start_date,
        p.end_date
    FROM documents d
    JOIN placements p ON d.placement_id = p.id
    JOIN users u      ON p.student_id   = u.id
    JOIN companies c  ON p.company_id   = c.id
    $whereSQL
    ORDER BY
        FIELD(d.status,'pending_review','approved','revision_needed') ASC,
        d.uploaded_at DESC
");
$stmt->execute($params);
$reports = $stmt->fetchAll();

// ── Stats for tabs ───────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT
        d.doc_type,
        d.status,
        COUNT(*) as cnt
    FROM documents d
    WHERE d.doc_type IN ('interim_report','final_report')
    GROUP BY d.doc_type, d.status
");
$statsRaw = $stmt->fetchAll();
$stats = [
    'interim_pending' => 0,
    'interim_approved' => 0,
    'final_pending' => 0,
    'final_approved' => 0,
    'revision_needed' => 0,
];
foreach ($statsRaw as $s) {
    if ($s['doc_type'] === 'interim_report' && $s['status'] === 'pending_review') {
        $stats['interim_pending'] = $s['cnt'];
    } elseif ($s['doc_type'] === 'interim_report' && $s['status'] === 'approved') {
        $stats['interim_approved'] = $s['cnt'];
    } elseif ($s['doc_type'] === 'final_report' && $s['status'] === 'pending_review') {
        $stats['final_pending'] = $s['cnt'];
    } elseif ($s['doc_type'] === 'final_report' && $s['status'] === 'approved') {
        $stats['final_approved'] = $s['cnt'];
    } elseif ($s['status'] === 'revision_needed') {
        $stats['revision_needed'] += $s['cnt'];
    }
}

// ── Students who haven't submitted one or more reports ──────────
$stmt = $pdo->query("
    SELECT
        u.id          AS student_id,
        u.full_name   AS student_name,
        u.email       AS student_email,
        c.name        AS company_name,
        p.start_date,
        p.end_date,
        (SELECT COUNT(*) FROM documents WHERE placement_id = p.id AND doc_type = 'interim_report') AS interim_submitted,
        (SELECT COUNT(*) FROM documents WHERE placement_id = p.id AND doc_type = 'final_report')   AS final_submitted
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.status IN ('approved','active')
    HAVING interim_submitted = 0 OR final_submitted = 0
    ORDER BY p.end_date ASC
");
$missing = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':($actionType==='danger'?'#fca5a5':'#fcd34d') ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- ── Stats Dashboard ───────────────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
                    gap:1.25rem;margin-bottom:2rem;">

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Interim Reports
                </p>
                <div style="display:flex;align-items:baseline;gap:0.75rem;">
                    <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">
                        <?= $stats['interim_approved'] ?>
                    </h3>
                    <span style="font-size:0.875rem;color:var(--muted);">approved</span>
                </div>
                <?php if ($stats['interim_pending'] > 0): ?>
                <p style="font-size:0.8125rem;color:var(--warning);margin-top:0.5rem;">
                    <?= $stats['interim_pending'] ?> pending review
                </p>
                <?php endif; ?>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Final Reports
                </p>
                <div style="display:flex;align-items:baseline;gap:0.75rem;">
                    <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;color:var(--navy);">
                        <?= $stats['final_approved'] ?>
                    </h3>
                    <span style="font-size:0.875rem;color:var(--muted);">approved</span>
                </div>
                <?php if ($stats['final_pending'] > 0): ?>
                <p style="font-size:0.8125rem;color:var(--warning);margin-top:0.5rem;">
                    <?= $stats['final_pending'] ?> pending review
                </p>
                <?php endif; ?>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Revisions Requested
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;
                           color:<?= $stats['revision_needed']>0?'var(--warning)':'var(--navy)' ?>;">
                    <?= $stats['revision_needed'] ?>
                </h3>
                <p style="font-size:0.8125rem;color:var(--muted);margin-top:0.5rem;">
                    awaiting resubmission
                </p>
            </div>

            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.75rem;">
                <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;
                          letter-spacing:0.05em;color:var(--muted);margin-bottom:0.5rem;">
                    Missing Reports
                </p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.625rem;
                           color:<?= count($missing)>0?'var(--danger)':'var(--navy)' ?>;">
                    <?= count($missing) ?>
                </h3>
                <p style="font-size:0.8125rem;color:var(--muted);margin-top:0.5rem;">
                    students overdue
                </p>
            </div>

        </div>

        <!-- ── Filter Bar ────────────────────────────────────── -->
        <form method="GET" style="display:flex;gap:0.875rem;margin-bottom:1.5rem;flex-wrap:wrap;">

            <input type="text" name="search"
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   placeholder="🔍  Search by student or company..."
                   style="padding:0.6875rem 1rem;border:1.5px solid var(--border);
                          border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                          background:var(--white);min-width:280px;">

            <select name="type" onchange="this.form.submit()"
                    style="padding:0.6875rem 2.5rem 0.6875rem 1rem;border:1.5px solid var(--border);
                           border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                           background:var(--white);appearance:none;
                           background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16' fill='none'%3E%3Cpath d='M4 6L8 10L12 6' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\");
                           background-repeat:no-repeat;background-position:right 0.75rem center;">
                <option value="" <?= !$filterType?'selected':'' ?>>All Report Types</option>
                <option value="interim_report" <?= $filterType==='interim_report'?'selected':'' ?>>Interim Reports</option>
                <option value="final_report" <?= $filterType==='final_report'?'selected':'' ?>>Final Reports</option>
            </select>

            <select name="status" onchange="this.form.submit()"
                    style="padding:0.6875rem 2.5rem 0.6875rem 1rem;border:1.5px solid var(--border);
                           border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                           background:var(--white);appearance:none;
                           background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16' fill='none'%3E%3Cpath d='M4 6L8 10L12 6' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\");
                           background-repeat:no-repeat;background-position:right 0.75rem center;">
                <option value="" <?= !$filterStatus?'selected':'' ?>>All Statuses</option>
                <option value="pending_review" <?= $filterStatus==='pending_review'?'selected':'' ?>>Pending Review</option>
                <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>Approved</option>
                <option value="revision_needed" <?= $filterStatus==='revision_needed'?'selected':'' ?>>Needs Revision</option>
            </select>

            <div style="margin-left:auto;display:flex;gap:0.75rem;">
                <?php if ($filterSearch || $filterType || $filterStatus): ?>
                    <a href="reports.php" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </div>

        </form>


        <!-- ═══════════════════════════════════════════════════════
             REPORTS TABLE
        ════════════════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-header">
                <h3><?= count($reports) ?> Report<?= count($reports)!==1?'s':'' ?></h3>
            </div>

            <?php if (empty($reports)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">📄</div>
                <p style="color:var(--muted);font-size:1rem;">No reports found.</p>
            </div>

            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Report Type</th>
                            <th>Submitted</th>
                            <th>File Size</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <!-- Student -->
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?= htmlspecialchars($r['student_initials']??'??') ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($r['student_name']) ?></h4>
                                        <p><?= htmlspecialchars($r['student_email']) ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- Company -->
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($r['company_name']) ?></div>
                                <div style="font-size:0.75rem;color:var(--muted);">
                                    <span class="type-chip" style="padding:0.15rem 0.5rem;">
                                        <?= htmlspecialchars($r['role_title']??'N/A') ?>
                                    </span>
                                </div>
                            </td>

                            <!-- Report Type -->
                            <td>
                                <span class="badge badge-<?= $r['doc_type']==='interim_report'?'review':'approved' ?>">
                                    <?= $r['doc_type']==='interim_report'?'Interim':'Final' ?>
                                </span>
                            </td>

                            <!-- Submitted -->
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;color:var(--muted);">
                                <?= date('d M Y', strtotime($r['uploaded_at'])) ?>
                            </td>

                            <!-- File Size -->
                            <td style="font-size:0.875rem;color:var(--muted);">
                                <?= htmlspecialchars($r['file_size']??'—') ?>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php
                                $badgeClass = match($r['status']) {
                                    'approved'        => 'approved',
                                    'revision_needed' => 'open',
                                    'pending_review'  => 'pending',
                                    default           => 'pending'
                                };
                                ?>
                                <span class="badge badge-<?= $badgeClass ?>">
                                    <?= ucwords(str_replace('_',' ',$r['status']??'Pending')) ?>
                                </span>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                    <!-- Download -->
                                    <a href="<?= htmlspecialchars(fileUrl($r['file_path'])) ?>"
                                       download
                                       class="btn btn-ghost btn-sm">
                                        ⬇ Download
                                    </a>

                                    <!-- Review (if pending) -->
                                    <?php if ($r['status'] === 'pending_review'): ?>
                                    <button class="btn btn-primary btn-sm"
                                            onclick="openReview(<?= $r['id'] ?>, '<?= htmlspecialchars($r['student_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['doc_type']==='interim_report'?'Interim':'Final', ENT_QUOTES) ?>')">
                                        Review
                                    </button>
                                    <?php endif; ?>

                                    <!-- View Feedback (if has feedback) -->
                                    <?php if ($r['reviewer_feedback']): ?>
                                    <button class="btn btn-ghost btn-sm"
                                            onclick="openFeedback(<?= $r['id'] ?>, '<?= htmlspecialchars($r['student_name'], ENT_QUOTES) ?>', <?= json_encode($r['reviewer_feedback'], JSON_HEX_TAG|JSON_HEX_AMP) ?>)">
                                        View Feedback
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>


        <!-- ═══════════════════════════════════════════════════════
             MISSING REPORTS PANEL
        ════════════════════════════════════════════════════════ -->
        <?php if (!empty($missing)): ?>
        <div class="panel">
            <div class="panel-header">
                <h3>⚠️ Missing Reports (<?= count($missing) ?>)</h3>
                <p>Students who haven't submitted one or more reports</p>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Placement Period</th>
                            <th>Missing</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($missing as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['student_name']) ?></td>
                            <td><?= htmlspecialchars($m['company_name']) ?></td>
                            <td style="font-family:'DM Mono',monospace;font-size:0.8125rem;">
                                <?= date('M Y', strtotime($m['start_date'])) ?>
                                → <?= date('M Y', strtotime($m['end_date'])) ?>
                            </td>
                            <td>
                                <?php if ($m['interim_submitted'] == 0): ?>
                                    <span class="badge badge-open">Interim</span>
                                <?php endif; ?>
                                <?php if ($m['final_submitted'] == 0): ?>
                                    <span class="badge badge-rejected">Final</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-primary btn-sm"
                                        onclick="openReminder(<?= (int)$m['student_id'] ?>, '<?= htmlspecialchars(addslashes($m['student_email'])) ?>', '<?= htmlspecialchars(addslashes($m['student_name'])) ?>', <?= $m['interim_submitted'] > 0 ? 'true' : 'false' ?>, <?= $m['final_submitted'] > 0 ? 'true' : 'false' ?>)">
                                    📧 Send Reminder
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /page-content -->
</div><!-- /main -->


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Review Report
══════════════════════════════════════════════════════════════ -->
<div id="reviewModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">
            Review Report
        </h3>
        <p id="reviewSubtitle" style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"></p>

        <form method="POST">
            <input type="hidden" name="document_id" id="reviewDocId">

            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Feedback for student
                </label>
                <textarea name="feedback" rows="5"
                          placeholder="Provide constructive feedback on the report quality, structure, reflection depth..."
                          style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('reviewModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" name="action" value="revision_needed"
                        class="btn btn-warning">
                    Request Revisions
                </button>
                <button type="submit" name="action" value="approved"
                        class="btn btn-success">
                    ✓ Approve
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL: View Feedback
══════════════════════════════════════════════════════════════ -->
<div id="feedbackModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">
            Your Feedback
        </h3>
        <p id="feedbackSubtitle" style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"></p>

        <div id="feedbackContent"
             style="background:var(--cream);border-radius:var(--radius-sm);
                    padding:1.25rem;border:1px solid var(--border);
                    font-size:0.9375rem;line-height:1.6;color:var(--text);
                    white-space:pre-wrap;"></div>

        <div style="display:flex;justify-content:flex-end;margin-top:1.5rem;">
            <button class="btn btn-primary"
                    onclick="document.getElementById('feedbackModal').style.display='none'">
                Close
            </button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Send Reminder
══════════════════════════════════════════════════════════════ -->
<div id="reminderModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">📧 Send Report Reminder</h3>
        <p id="reminderSubtitle" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>

        <form method="POST" id="reminderForm">
            <input type="hidden" name="send_reminder" value="1">
            <input type="hidden" name="student_id"    id="reminderStudentId">
            <input type="hidden" name="student_email" id="reminderStudentEmail">
            <input type="hidden" name="student_name"  id="reminderStudentName">
            <div id="reminderMissingInputs"></div>

            <div style="background:var(--cream);border-radius:var(--radius-sm);padding:1rem 1.25rem;
                        margin-bottom:1.5rem;border:1px solid var(--border);font-size:0.9rem;color:var(--text);">
                An email reminder will be sent to the student asking them to submit their missing report(s).
            </div>

            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeReminder()">Cancel</button>
                <button type="submit" class="btn btn-primary">Send Reminder Email</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReminder(studentId, studentEmail, studentName, interimDone, finalDone) {
    document.getElementById('reminderStudentId').value    = studentId;
    document.getElementById('reminderStudentEmail').value = studentEmail;
    document.getElementById('reminderStudentName').value  = studentName;

    // Build missing type hidden inputs
    let inputs = '';
    let missing = [];
    if (!interimDone) { inputs += '<input type="hidden" name="missing_types[]" value="interim">'; missing.push('Interim'); }
    if (!finalDone)   { inputs += '<input type="hidden" name="missing_types[]" value="final">'; missing.push('Final'); }
    document.getElementById('reminderMissingInputs').innerHTML = inputs;

    document.getElementById('reminderSubtitle').textContent =
        studentName + ' — Missing: ' + missing.join(' & ') + ' Report' + (missing.length > 1 ? 's' : '');

    document.getElementById('reminderModal').style.display = 'flex';
}

function closeReminder() {
    document.getElementById('reminderModal').style.display = 'none';
}

document.getElementById('reminderModal').addEventListener('click', function(e) {
    if (e.target === this) closeReminder();
});

function openReview(docId, studentName, reportType) {
    document.getElementById('reviewDocId').value = docId;
    document.getElementById('reviewSubtitle').textContent = studentName + ' — ' + reportType + ' Report';
    document.getElementById('reviewModal').style.display = 'flex';
}

function openFeedback(docId, studentName, feedback) {
    document.getElementById('feedbackSubtitle').textContent = studentName;
    document.getElementById('feedbackContent').textContent = feedback;
    document.getElementById('feedbackModal').style.display = 'flex';
}

// Close on outside click
['reviewModal','feedbackModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>