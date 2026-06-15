<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'Visit Planner';
$pageSubtitle = 'Schedule and manage placement visits';
$activePage   = 'visits';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Handle mark as completed action ──────────────────────────────
$actionMsg  = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_visit'])) {
    $visitId = (int)$_POST['visit_id'];
    $notes   = trim($_POST['visit_notes'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE visits
        SET status = 'completed', notes = ?, updated_at = NOW()
        WHERE id = ? AND tutor_id = ?
    ");
    $stmt->execute([$notes, $visitId, $userId]);

    $actionMsg  = "✅ Visit marked as completed.";
    $actionType = "success";
}

// ── Handle cancel visit action ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_visit'])) {
    $visitId = (int)$_POST['visit_id'];
    $reason  = trim($_POST['cancel_reason'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE visits
        SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\n[CANCELLED] ', ?), updated_at = NOW()
        WHERE id = ? AND tutor_id = ?
    ");
    $stmt->execute([$reason ?: 'Cancelled by tutor', $visitId, $userId]);

    // Notify student
    $stmt = $pdo->prepare("
        SELECT p.student_id, v.visit_date, v.visit_time
        FROM visits v
        JOIN placements p ON v.placement_id = p.id
        WHERE v.id = ?
    ");
    $stmt->execute([$visitId]);
    $row = $stmt->fetch();

    if ($row) {
        $msg = "⚠️ Your visit scheduled for " . date('d M Y', strtotime($row['visit_date'])) . " at " . date('g:i A', strtotime($row['visit_time'])) . " has been cancelled by your tutor." . ($reason ? " Reason: $reason" : "");
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'visit_cancelled', ?)");
        $stmt->execute([$row['student_id'], $msg]);
    }

    $actionMsg  = "Visit cancelled successfully. Student has been notified.";
    $actionType = "warning";
}

// ── Filters ──────────────────────────────────────────────────────
$filterType = $_GET['type'] ?? '';
$filterDate = $_GET['date'] ?? '';

$where  = ["v.tutor_id = ?"];
$params = [$userId];

if ($filterType) {
    $where[]  = "v.type = ?";
    $params[] = $filterType;
}

if ($filterDate) {
    $where[]  = "v.visit_date = ?";
    $params[] = $filterDate;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Fetch all visits ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        v.*,
        u.full_name       AS student_name,
        u.email           AS student_email,
        u.avatar_initials AS student_initials,
        c.name            AS company_name,
        c.city            AS company_city,
        p.role_title
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN users      u ON p.student_id   = u.id
    JOIN companies  c ON p.company_id   = c.id
    $whereSQL
    ORDER BY v.visit_date ASC, v.visit_time ASC
");
$stmt->execute($params);
$visits = $stmt->fetchAll();

// Split into upcoming and past
$upcoming = [];
$past     = [];
$today    = date('Y-m-d');
foreach ($visits as $v) {
    if ($v['visit_date'] >= $today) {
        $upcoming[] = $v;
    } else {
        $past[] = $v;
    }
}
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

        <!-- ── Filter Bar ────────────────────────────────────── -->
        <form method="GET" style="display:flex;gap:0.875rem;align-items:center;
                                   margin-bottom:2rem;flex-wrap:wrap;">
            <select name="type"
                    style="padding:0.6875rem 2.5rem 0.6875rem 1rem;border:1.5px solid var(--border);
                           border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                           background:var(--white);color:var(--text);appearance:none;
                           background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16' fill='none'%3E%3Cpath d='M4 6L8 10L12 6' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E\");
                           background-repeat:no-repeat;background-position:right 0.75rem center;"
                    onchange="this.form.submit()">
                <option value="" <?= !$filterType?'selected':'' ?>>All Visit Types</option>
                <option value="virtual"  <?= $filterType==='virtual'?'selected':'' ?>>🖥 Virtual</option>
                <option value="physical" <?= $filterType==='physical'?'selected':'' ?>>📍 Physical</option>
            </select>

            <input type="date" name="date"
                   value="<?= htmlspecialchars($filterDate) ?>"
                   onchange="this.form.submit()"
                   style="padding:0.6875rem 1rem;border:1.5px solid var(--border);
                          border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;
                          background:var(--white);color:var(--text);">

            <?php if ($filterType || $filterDate): ?>
                <a href="visits.php" class="btn btn-ghost btn-sm">✕ Clear</a>
            <?php endif; ?>

            <div style="margin-left:auto;display:flex;gap:0.75rem;">
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="window.location='/inplace/tutor/provider-meeting.php'">
                    🤝 Provider Meeting
                </button>
                <button type="button" class="btn btn-primary btn-sm"
                        onclick="window.location='/inplace/tutor/schedule-visit.php'">
                    + Schedule New Visit
                </button>
            </div>
        </form>


        <!-- ═══════════════════════════════════════════════════════
             UPCOMING VISITS
        ════════════════════════════════════════════════════════ -->
        <?php if (empty($upcoming) && empty($past)): ?>

            <!-- No visits at all -->
            <div class="panel">
                <div class="panel-body" style="text-align:center;padding:4rem 2rem;">
                    <div style="font-size:3.5rem;margin-bottom:1rem;">🗓</div>
                    <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;
                               color:var(--navy);margin-bottom:0.75rem;">No Visits Scheduled</h3>
                    <p style="color:var(--muted);max-width:380px;margin:0 auto 1.5rem;">
                        You haven't scheduled any placement visits yet. Start by scheduling
                        a visit with one of your students.
                    </p>
                    <button class="btn btn-primary"
                            onclick="window.location='/inplace/tutor/schedule-visit.php'">
                        + Schedule First Visit
                    </button>
                </div>
            </div>

        <?php else: ?>

        <?php if (!empty($upcoming)): ?>
        <div style="margin-bottom:1.75rem;">
            <h3 style="font-family:'Playfair Display',serif;font-size:1.25rem;
                       color:var(--navy);margin-bottom:1.25rem;">
                Upcoming Visits
                <span style="font-size:0.875rem;font-weight:400;color:var(--muted);
                             margin-left:0.5rem;font-family:'DM Sans',sans-serif;">
                    (<?= count($upcoming) ?>)
                </span>
            </h3>

            <div class="visit-grid">
                <?php foreach ($upcoming as $v): ?>
                <?php
                    $statusClass = match($v['status']) {
                        'confirmed'   => 'approved',
                        'proposed'    => 'pending',
                        'rescheduled' => 'open',
                        'cancelled'   => 'rejected',
                        default       => 'pending'
                    };
                    $statusLabel = match($v['status']) {
                        'confirmed'   => 'Confirmed',
                        'proposed'    => 'Pending Confirmation',
                        'rescheduled' => 'Reschedule Requested',
                        'cancelled'   => 'Cancelled',
                        default       => 'Pending'
                    };
                    $typeIcon  = $v['type'] === 'virtual' ? '🖥' : '📍';
                    $typeLabel = $v['type'] === 'virtual' ? 'Virtual' : 'Physical';
                ?>
                <div class="visit-card">

                    <!-- Date + Student name block -->
                    <div class="visit-date-block">
                        <div class="date-box">
                            <div class="day"><?= date('d', strtotime($v['visit_date'])) ?></div>
                            <div class="month"><?= date('M', strtotime($v['visit_date'])) ?></div>
                        </div>
                        <div class="visit-date-info">
                            <h4><?= htmlspecialchars($v['student_name']) ?></h4>
                            <p><?= date('g:i A', strtotime($v['visit_time'])) ?></p>
                        </div>
                    </div>

                    <!-- Visit meta -->
                    <div class="visit-meta">
                        <div class="visit-meta-row">
                            🏢 <strong><?= htmlspecialchars($v['company_name']) ?>,
                               <?= htmlspecialchars($v['company_city'] ?? '') ?></strong>
                        </div>
                        <div class="visit-meta-row">
                            <?= $typeIcon ?> <?= $typeLabel ?>
                            <?php if ($v['type'] === 'virtual' && $v['meeting_link']): ?>
                                · <a href="<?= htmlspecialchars($v['meeting_link']) ?>"
                                     target="_blank"
                                     style="color:var(--info);font-size:0.8125rem;">Join Link</a>
                            <?php elseif ($v['type'] === 'physical' && $v['location']): ?>
                                · <?= htmlspecialchars($v['location']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="visit-meta-row">
                            📧 <a href="mailto:<?= htmlspecialchars($v['student_email']) ?>"
                                  style="color:var(--text);text-decoration:none;">
                                <?= htmlspecialchars($v['student_email']) ?>
                            </a>
                        </div>
                        <?php if ($v['role_title']): ?>
                        <div class="visit-meta-row">
                            <span class="type-chip" style="padding:0.2rem 0.6rem;font-size:0.7rem;">
                                <?= htmlspecialchars($v['role_title']) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Status badge -->
                    <div style="margin-bottom:1rem;">
                        <span class="badge badge-<?= $statusClass ?>"><?= $statusLabel ?></span>
                    </div>

                    <!-- Notes (if any) -->
                    <?php if ($v['notes']): ?>
                    <div style="background:var(--cream);border-radius:8px;padding:0.75rem;
                                margin-bottom:1rem;font-size:0.875rem;color:var(--muted);
                                border:1px solid var(--border);">
                        📝 <?= nl2br(htmlspecialchars($v['notes'])) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Action buttons -->
                    <div class="visit-actions">

                        <?php if ($v['status'] === 'confirmed'): ?>
                            <!-- Add notes -->
                            <button class="btn btn-primary"
                                    style="flex:1;justify-content:center;"
                                    onclick="openNotes(<?= $v['id'] ?>, '<?= htmlspecialchars($v['student_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars(date('d M Y', strtotime($v['visit_date'])), ENT_QUOTES) ?>')">
                                Add Notes
                            </button>
                        <?php endif; ?>

                        <!-- Edit/Reschedule -->
                        <button class="btn btn-ghost"
                                style="flex:1;justify-content:center;"
                                onclick="window.location='/inplace/tutor/edit-visit.php?id=<?= $v['id'] ?>'">
                            Edit
                        </button>

                        <!-- Cancel -->
                        <?php if ($v['status'] !== 'cancelled'): ?>
                        <button class="btn btn-danger btn-sm"
                                onclick="openCancelModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['student_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars(date('d M Y', strtotime($v['visit_date'])), ENT_QUOTES) ?>')">
                            ✕
                        </button>
                        <?php endif; ?>

                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <!-- ═══════════════════════════════════════════════════════
             PAST VISITS
        ════════════════════════════════════════════════════════ -->
        <?php if (!empty($past)): ?>
        <div>
            <h3 style="font-family:'Playfair Display',serif;font-size:1.25rem;
                       color:var(--navy);margin-bottom:1.25rem;">
                Past Visits
                <span style="font-size:0.875rem;font-weight:400;color:var(--muted);
                             margin-left:0.5rem;font-family:'DM Sans',sans-serif;">
                    (<?= count($past) ?>)
                </span>
            </h3>

            <div class="visit-grid">
                <?php foreach ($past as $v): ?>
                <div class="visit-card" style="opacity:0.8;">
                    <div class="visit-date-block">
                        <div class="date-box" style="background:#6b7a8d;">
                            <div class="day"><?= date('d', strtotime($v['visit_date'])) ?></div>
                            <div class="month"><?= date('M', strtotime($v['visit_date'])) ?></div>
                        </div>
                        <div class="visit-date-info">
                            <h4><?= htmlspecialchars($v['student_name']) ?></h4>
                            <p><?= date('g:i A', strtotime($v['visit_time'])) ?></p>
                        </div>
                    </div>
                    <div class="visit-meta">
                        <div class="visit-meta-row">
                            🏢 <strong><?= htmlspecialchars($v['company_name']) ?></strong>
                        </div>
                        <div class="visit-meta-row">
                            <?= $v['type'] === 'virtual' ? '🖥 Virtual' : '📍 Physical' ?>
                        </div>
                    </div>
                    <span class="badge badge-<?= $v['status']==='completed'?'approved':'open' ?>">
                        <?= ucfirst($v['status']) ?>
                    </span>
                    <?php if ($v['notes']): ?>
                    <div style="margin-top:0.875rem;background:var(--cream);border-radius:8px;
                                padding:0.75rem;font-size:0.875rem;color:var(--muted);
                                border:1px solid var(--border);">
                        📝 <?= nl2br(htmlspecialchars($v['notes'])) ?>
                    </div>
                    <?php else: ?>
                    <button class="btn btn-primary btn-sm"
                            style="width:100%;margin-top:0.875rem;justify-content:center;"
                            onclick="openComplete(<?= $v['id'] ?>)">
                        ✓ Mark as Completed
                    </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end no visits check ?>

    </div><!-- /page-content -->
</div><!-- /main -->


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Cancel Visit
══════════════════════════════════════════════════════════════ -->
<div id="cancelModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--danger);margin-bottom:0.5rem;">
            ⚠️ Cancel Visit
        </h3>
        <p id="cancelSubtitle" style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;"></p>
        <form method="POST">
            <input type="hidden" name="visit_id" id="cancelVisitId">
            <input type="hidden" name="cancel_visit" value="1">
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Reason for cancellation (optional)
                </label>
                <textarea name="cancel_reason" rows="3"
                          placeholder="e.g., Student requested reschedule, scheduling conflict..."
                          style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('cancelModal').style.display='none'">
                    Keep Visit
                </button>
                <button type="submit" class="btn btn-danger">
                    ✕ Cancel Visit
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Add Visit Notes
══════════════════════════════════════════════════════════════ -->
<div id="notesModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">
            Visit Notes
        </h3>
        <p id="notesSubtitle" style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"></p>
        <form method="POST" action="/inplace/tutor/actions/save-visit-notes.php">
            <input type="hidden" name="visit_id" id="notesVisitId">
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Visit outcome and observations
                </label>
                <textarea name="notes" rows="6"
                          placeholder="Record what was discussed, student progress, any concerns or action items..."
                          style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('notesModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">Save Notes</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     MODAL: Mark as Completed
══════════════════════════════════════════════════════════════ -->
<div id="completeModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:1.5rem;">
            ✓ Mark Visit as Completed
        </h3>
        <form method="POST">
            <input type="hidden" name="visit_id" id="completeVisitId">
            <input type="hidden" name="complete_visit" value="1">
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Visit notes (optional)
                </label>
                <textarea name="visit_notes" rows="5"
                          placeholder="Summary of visit, student progress, any follow-up actions..."
                          style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('completeModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-success">✓ Mark Completed</button>
            </div>
        </form>
    </div>
</div>


<script>
function openCancelModal(visitId, studentName, dateStr) {
    document.getElementById('cancelVisitId').value = visitId;
    document.getElementById('cancelSubtitle').textContent = 
        'Cancel visit with ' + studentName + ' on ' + dateStr + '? The student will be notified.';
    document.getElementById('cancelModal').style.display = 'flex';
}

function openNotes(visitId, studentName, dateStr) {
    document.getElementById('notesVisitId').value = visitId;
    document.getElementById('notesSubtitle').textContent = studentName + ' · ' + dateStr;
    document.getElementById('notesModal').style.display = 'flex';
}

function openComplete(visitId) {
    document.getElementById('completeVisitId').value = visitId;
    document.getElementById('completeModal').style.display = 'flex';
}

// Close modals on outside click
['cancelModal','notesModal','completeModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>