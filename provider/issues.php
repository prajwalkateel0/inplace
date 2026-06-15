<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Report an Issue';
$pageSubtitle = 'Raise concerns or issues about a student placement';
$activePage   = 'issues';
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

// ── Ensure issues table ──────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS provider_issues (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        placement_id     INT          NOT NULL,
        provider_user_id INT          NOT NULL,
        issue_type       VARCHAR(60)  NOT NULL,
        severity         ENUM('low','medium','high') DEFAULT 'medium',
        description      TEXT         NOT NULL,
        desired_outcome  TEXT         DEFAULT NULL,
        status           ENUM('open','acknowledged','resolved') DEFAULT 'open',
        tutor_response   TEXT         DEFAULT NULL,
        resolved_at      DATETIME     DEFAULT NULL,
        created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$flash = ['msg' => '', 'type' => ''];

// ── POST: submit issue ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_action'])) {
    $placementId    = (int)($_POST['placement_id'] ?? 0);
    $issueType      = trim($_POST['issue_type']     ?? '');
    $severity       = in_array($_POST['severity'] ?? '', ['low','medium','high']) ? $_POST['severity'] : 'medium';
    $description    = trim($_POST['description']    ?? '');
    $desiredOutcome = trim($_POST['desired_outcome'] ?? '');

    $chk = $pdo->prepare("SELECT id FROM placements WHERE id=? AND company_id=?");
    $chk->execute([$placementId, $companyId]);

    if (!$chk->fetch()) {
        $flash = ['msg' => 'Invalid placement.', 'type' => 'danger'];
    } elseif (!$issueType || !$description) {
        $flash = ['msg' => 'Issue type and description are required.', 'type' => 'danger'];
    } else {
        $pdo->prepare("
            INSERT INTO provider_issues
              (placement_id, provider_user_id, issue_type, severity, description, desired_outcome)
            VALUES (?,?,?,?,?,?)
        ")->execute([$placementId, $userId, $issueType, $severity, $description, $desiredOutcome]);

        // Message all active tutors
        $tutor_stmt = $pdo->prepare("
            SELECT t.id AS tutor_id, t.full_name AS tutor_name
            FROM placements p
            LEFT JOIN users t ON p.tutor_id = t.id
            WHERE p.id = ?
        ");
        $tutor_stmt->execute([$placementId]);
        $tutorRow = $tutor_stmt->fetch(PDO::FETCH_ASSOC);

        if ($tutorRow && $tutorRow['tutor_id']) {
            $tutorIds = [$tutorRow['tutor_id']];
        } else {
            $ts = $pdo->query("SELECT id FROM users WHERE role='tutor' AND is_active=1");
            $tutorIds = $ts->fetchAll(PDO::FETCH_COLUMN);
        }

        try {
            $tCol = null;
            $s2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME IN (?,?,?,?)");
            $s2->execute(['created_at','sent_at','timestamp','date_sent']);
            foreach (['created_at','sent_at','timestamp','date_sent'] as $c) {
                if (in_array($c, $s2->fetchAll(PDO::FETCH_COLUMN), true)) { $tCol = $c; break; }
            }
            $sLabel = match($severity) { 'high' => '🔴 High', 'medium' => '🟡 Medium', default => '🟢 Low' };
            $msgText = "⚠️ Issue reported by provider ({$sLabel} severity): {$issueType}\n\n{$description}"
                     . ($desiredOutcome ? "\n\nDesired outcome: {$desiredOutcome}" : '');

            foreach ($tutorIds as $tid) {
                if ($tCol) {
                    $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,body,`$tCol`,is_read) VALUES (?,?,?,NOW(),0)")
                        ->execute([$userId, $tid, $msgText]);
                } else {
                    $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,body,is_read) VALUES (?,?,?,0)")
                        ->execute([$userId, $tid, $msgText]);
                }
            }
        } catch (Exception $e) { error_log('Issue message: ' . $e->getMessage()); }

        // Notification for tutor(s)
        $sLabel = match($severity) { 'high' => '🔴 High', 'medium' => '🟡 Medium', default => '🟢 Low' };
        $notifMsg = "⚠️ Provider reported a {$sLabel} issue for a student: {$issueType}."
                  . ($desiredOutcome ? " Desired outcome: {$desiredOutcome}" : '');
        foreach ($tutorIds as $tid) {
            try {
                $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'provider_issue', ?)")
                    ->execute([$tid, $notifMsg]);
            } catch (Exception $e) { error_log('Issue notification: ' . $e->getMessage()); }
        }

        $flash = ['msg' => 'Issue reported. The tutor has been notified and will follow up shortly.', 'type' => 'success'];
    }
}

// ── Load students + existing issues ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.id AS placement_id, p.role_title,
           u.full_name AS student_name, u.avatar_initials
    FROM placements p
    JOIN users u ON p.student_id = u.id
    WHERE p.company_id=? AND p.status IN ('approved','active')
    ORDER BY u.full_name
");
$stmt->execute([$companyId]);
$activePlacements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT i.*, u.full_name AS student_name, p.role_title
    FROM provider_issues i
    JOIN placements p ON i.placement_id = p.id
    JOIN users u ON p.student_id = u.id
    WHERE p.company_id = ?
    ORDER BY FIELD(i.status,'open','acknowledged','resolved'), i.created_at DESC
");
$stmt->execute([$companyId]);
$allIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);

$issueTypes = [
    'Attendance / Absence',
    'Attitude / Conduct',
    'Performance Below Expectations',
    'Health & Wellbeing Concern',
    'Communication Breakdown',
    'Workload / Capacity Issue',
    'Safeguarding Concern',
    'Other',
];
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

        <div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start;">

            <!-- Left: Issue history -->
            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Reported Issues</h3>
                            <p>All concerns raised for your placement students</p>
                        </div>
                        <button onclick="document.getElementById('issueModal').style.display='flex'"
                                class="btn btn-primary btn-sm">+ Report Issue</button>
                    </div>

                    <?php if (empty($allIssues)): ?>
                    <div style="text-align:center;padding:3rem 2rem;">
                        <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
                        <p style="color:var(--muted);">No issues reported. Everything looks good!</p>
                    </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Issue</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <th>Reported</th>
                                    <th>Tutor Response</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allIssues as $iss):
                                    $sevColor = match($iss['severity']) {
                                        'high'   => '#dc2626',
                                        'medium' => '#d97706',
                                        default  => '#059669',
                                    };
                                    $statusBadge = match($iss['status']) {
                                        'acknowledged' => 'review',
                                        'resolved'     => 'approved',
                                        default        => 'pending',
                                    };
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:500;"><?= htmlspecialchars($iss['student_name']) ?></div>
                                        <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($iss['role_title'] ?? '') ?></div>
                                    </td>
                                    <td style="font-size:0.875rem;"><?= htmlspecialchars($iss['issue_type']) ?></td>
                                    <td>
                                        <span style="font-weight:700;color:<?= $sevColor ?>;">
                                            <?= ucfirst($iss['severity']) ?>
                                        </span>
                                    </td>
                                    <td><span class="badge badge-<?= $statusBadge ?>"><?= ucfirst($iss['status']) ?></span></td>
                                    <td style="font-size:0.8125rem;color:var(--muted);">
                                        <?= date('d M Y', strtotime($iss['created_at'])) ?>
                                    </td>
                                    <td style="max-width:180px;font-size:0.8125rem;color:var(--muted);">
                                        <?= $iss['tutor_response'] ? nl2br(htmlspecialchars($iss['tutor_response'])) : '—' ?>
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
                    <div class="panel-header"><h3>When to Report</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;flex-direction:column;gap:0.875rem;">
                            <div style="padding:0.875rem;border-left:3px solid #dc2626;background:#fff5f5;border-radius:0 var(--radius-sm) var(--radius-sm) 0;">
                                <p style="font-weight:700;color:#dc2626;margin-bottom:0.25rem;">🔴 High</p>
                                <p style="font-size:0.8125rem;color:var(--muted);">Safeguarding concern, serious conduct, immediate risk to student or staff.</p>
                            </div>
                            <div style="padding:0.875rem;border-left:3px solid #d97706;background:#fffbeb;border-radius:0 var(--radius-sm) var(--radius-sm) 0;">
                                <p style="font-weight:700;color:#d97706;margin-bottom:0.25rem;">🟡 Medium</p>
                                <p style="font-size:0.8125rem;color:var(--muted);">Persistent attendance issues, performance well below expectations.</p>
                            </div>
                            <div style="padding:0.875rem;border-left:3px solid #059669;background:#f0fdf4;border-radius:0 var(--radius-sm) var(--radius-sm) 0;">
                                <p style="font-weight:700;color:#059669;margin-bottom:0.25rem;">🟢 Low</p>
                                <p style="font-size:0.8125rem;color:var(--muted);">Minor concerns worth documenting, early conversation starters.</p>
                            </div>
                            <p style="font-size:0.8125rem;color:var(--muted);margin-top:0.5rem;">
                                All reports are forwarded to the student's tutor. For urgent situations, please also contact the university directly.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- New Issue Modal -->
<div id="issueModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);
     z-index:1000;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:560px;margin:auto;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="background:#0c1b33;padding:1.5rem 2rem;border-radius:16px 16px 0 0;
                    display:flex;align-items:center;justify-content:space-between;">
            <h3 style="color:#fff;font-family:'Playfair Display',serif;font-size:1.2rem;margin:0;">
                Report an Issue or Concern
            </h3>
            <button onclick="document.getElementById('issueModal').style.display='none'"
                    style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;">✕</button>
        </div>
        <form method="POST" style="padding:2rem;">
            <input type="hidden" name="issue_action" value="submit">

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
                    <label>Issue Type <span style="color:var(--danger);">*</span></label>
                    <select name="issue_type" required
                            style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                   width:100%;font-family:inherit;font-size:0.9375rem;">
                        <option value="">— Select type —</option>
                        <?php foreach ($issueTypes as $it): ?>
                        <option value="<?= htmlspecialchars($it) ?>"><?= htmlspecialchars($it) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Severity</label>
                    <select name="severity"
                            style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                   width:100%;font-family:inherit;font-size:0.9375rem;">
                        <option value="low">🟢 Low</option>
                        <option value="medium" selected>🟡 Medium</option>
                        <option value="high">🔴 High</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Description <span style="color:var(--danger);">*</span></label>
                <textarea name="description" rows="4" required
                          placeholder="Describe the issue in detail. Include dates, incidents, and relevant context."
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Desired Outcome <span style="color:var(--muted);font-size:0.8rem;">(optional)</span></label>
                <textarea name="desired_outcome" rows="2"
                          placeholder="What would you like to happen as a result of raising this?"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                <button type="button" onclick="document.getElementById('issueModal').style.display='none'"
                        class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Report →</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('issueModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include '../includes/footer.php'; ?>
