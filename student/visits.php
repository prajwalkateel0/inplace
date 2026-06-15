<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('student');

$pageTitle    = 'My Visits';
$pageSubtitle = 'Welcome back, ' . explode(' ', authName())[0];
$activePage   = 'visits';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount     = (int)$stmt->fetchColumn();
$pendingRequests = 0;

// handle POST actions: confirm, reschedule, or decline a visit
$actionMsg  = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitId = (int)$_POST['visit_id'];

    // Verify this visit belongs to the student
    $stmt = $pdo->prepare("
        SELECT v.id FROM visits v
        JOIN placements p ON v.placement_id = p.id
        WHERE v.id = ? AND p.student_id = ?
    ");
    $stmt->execute([$visitId, $userId]);
    $allowed = $stmt->fetch();

    if ($allowed) {
        if (isset($_POST['confirm'])) {
            $stmt = $pdo->prepare("UPDATE visits SET status = 'confirmed' WHERE id = ?");
            $stmt->execute([$visitId]);
            $actionMsg  = "✅ Visit confirmed successfully!";
            $actionType = "success";

        } elseif (isset($_POST['reschedule'])) {
            $note = trim($_POST['reschedule_note'] ?? '');
            // Send message to tutor asking for reschedule
            $stmt = $pdo->prepare("SELECT v.tutor_id FROM visits v WHERE v.id = ?");
            $stmt->execute([$visitId]);
            $row = $stmt->fetch();
            if ($row && $row['tutor_id']) {
                $body = "Reschedule request for visit #$visitId" . ($note ? ": $note" : ".");
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)");
                $stmt->execute([$userId, $row['tutor_id'], $body]);
            }
            $actionMsg  = "ℹ️ Reschedule request sent to your tutor.";
            $actionType = "warning";

        } elseif (isset($_POST['decline'])) {
            $note = trim($_POST['decline_note'] ?? '');
            $pdo->prepare("UPDATE visits SET status = 'declined', updated_at = NOW() WHERE id = ?")
                ->execute([$visitId]);
            // Notify tutor
            $stmt = $pdo->prepare("SELECT v.tutor_id FROM visits v WHERE v.id = ?");
            $stmt->execute([$visitId]);
            $row = $stmt->fetch();
            if ($row && $row['tutor_id']) {
                $body = "Student declined visit #$visitId" . ($note ? ". Reason: $note" : ".");
                $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)")
                    ->execute([$userId, $row['tutor_id'], $body]);
            }
            $actionMsg  = "Visit declined. Your tutor has been notified.";
            $actionType = "warning";
        }
    }
}

$filterType = $_GET['type']   ?? '';
$filterDate = $_GET['date']   ?? '';

// get all visits for this student, applying any active filters
$where  = ["p.student_id = ?"];
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

$stmt = $pdo->prepare("
    SELECT
        v.*,
        c.name      AS company_name,
        c.city      AS company_city,
        u.full_name AS tutor_name
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN companies  c ON p.company_id   = c.id
    LEFT JOIN users u ON v.tutor_id     = u.id
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
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':($actionType==='danger'?'#fca5a5':'#fcd34d') ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <!-- filter bar for visit type and date -->
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
                <option value="virtual"  <?= $filterType==='virtual' ?'selected':'' ?>>🖥 Virtual</option>
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
        </form>


        <!-- upcoming and past visits -->
        <?php if (empty($upcoming) && empty($past)): ?>

            <!-- No visits at all -->
            <div class="panel">
                <div class="panel-body" style="text-align:center;padding:4rem 2rem;">
                    <div style="font-size:3.5rem;margin-bottom:1rem;">🗓</div>
                    <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;
                               color:var(--navy);margin-bottom:0.75rem;">No Visits Scheduled</h3>
                    <p style="color:var(--muted);max-width:380px;margin:0 auto;">
                        Your placement tutor will schedule visits once your placement is approved.
                        You'll be notified by email and in-app when a visit is proposed.
                    </p>
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
                            <h4><?= htmlspecialchars(authName()) ?></h4>
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
                            👤 <?= htmlspecialchars($v['tutor_name'] ?? 'Your Tutor') ?>
                        </div>
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

                        <?php if ($v['status'] === 'proposed'): ?>
                            <!-- Confirm button -->
                            <form method="POST" style="flex:1;">
                                <input type="hidden" name="visit_id" value="<?= $v['id'] ?>">
                                <button type="submit" name="confirm" value="1"
                                        class="btn btn-success"
                                        style="width:100%;justify-content:center;">
                                    ✓ Confirm
                                </button>
                            </form>

                        <?php elseif ($v['status'] === 'confirmed'): ?>
                            <!-- Add notes (view detail) -->
                            <button class="btn btn-primary"
                                    style="flex:1;justify-content:center;"
                                    onclick="openNotes(<?= $v['id'] ?>, '<?= htmlspecialchars(date('d M Y', strtotime($v['visit_date'])), ENT_QUOTES) ?>')">
                                Add Notes
                            </button>
                        <?php endif; ?>

                        <!-- Reschedule button (always shown for upcoming) -->
                        <?php if ($v['status'] !== 'cancelled'): ?>
                        <button class="btn btn-ghost"
                                style="flex:1;justify-content:center;"
                                onclick="openReschedule(<?= $v['id'] ?>)">
                            Reschedule
                        </button>
                        <?php endif; ?>

                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <!-- past visits section -->
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
                <div class="visit-card" style="opacity:0.75;">
                    <div class="visit-date-block">
                        <div class="date-box" style="background:#6b7a8d;">
                            <div class="day"><?= date('d', strtotime($v['visit_date'])) ?></div>
                            <div class="month"><?= date('M', strtotime($v['visit_date'])) ?></div>
                        </div>
                        <div class="visit-date-info">
                            <h4><?= htmlspecialchars(authName()) ?></h4>
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
                        <div class="visit-meta-row">
                            👤 <?= htmlspecialchars($v['tutor_name'] ?? 'Your Tutor') ?>
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
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end no visits check ?>

    </div><!-- /page-content -->
</div><!-- /main -->


<!-- modal: request to reschedule a visit -->
<div id="rescheduleModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">Request Reschedule</h3>
        <p style="color:var(--muted);font-size:0.9rem;margin-bottom:1.5rem;">
            A message will be sent to your placement tutor requesting a new time.
        </p>
        <form method="POST">
            <input type="hidden" name="visit_id" id="rescheduleVisitId">
            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Preferred alternative date (optional)
                </label>
                <input type="date" name="preferred_date"
                       style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                              border-radius:var(--radius-sm);font-family:inherit;
                              font-size:0.9375rem;background:var(--cream);">
            </div>
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Reason / Note
                </label>
                <textarea name="reschedule_note" rows="3"
                          placeholder="e.g., I have a sprint review on that date, could we move to the following week?"
                          style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                                 border-radius:var(--radius-sm);font-family:inherit;
                                 font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('rescheduleModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" name="reschedule" value="1" class="btn btn-primary">
                    Send Request
                </button>
            </div>
        </form>
    </div>
</div>


<!-- modal: add preparation notes for a confirmed visit -->
<div id="notesModal" style="display:none;position:fixed;inset:0;
     background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.375rem;
                   color:var(--navy);margin-bottom:0.5rem;">
            Visit Notes
        </h3>
        <p id="notesSubtitle" style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"></p>
        <form method="POST" action="/inplace/student/actions/save-visit-note.php">
            <input type="hidden" name="visit_id" id="notesVisitId">
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;
                              color:var(--text);margin-bottom:0.5rem;">
                    Your preparation notes / questions for this visit
                </label>
                <textarea name="notes" rows="5"
                          placeholder="Things to discuss, questions for your tutor, achievements to highlight..."
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


<script>
function openReschedule(visitId) {
    document.getElementById('rescheduleVisitId').value = visitId;
    document.getElementById('rescheduleModal').style.display = 'flex';
}

function openNotes(visitId, dateStr) {
    document.getElementById('notesVisitId').value = visitId;
    document.getElementById('notesSubtitle').textContent = 'Visit on ' + dateStr;
    document.getElementById('notesModal').style.display = 'flex';
}

// Close modals on outside click
['rescheduleModal','notesModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>