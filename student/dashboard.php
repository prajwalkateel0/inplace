<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('student');

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . explode(' ', authName())[0];
$activePage   = 'dashboard';

$userId = authId();

// get unread message count for the sidebar badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

// get the student's active/approved placement
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS company_name, c.city
    FROM placements p
    JOIN companies c ON p.company_id = c.id
    WHERE p.student_id = ? AND p.status IN ('approved','active')
    ORDER BY p.created_at DESC LIMIT 1
");
$stmt->execute([$userId]);
$placement = $stmt->fetch();

// how many reports has the student submitted
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents
    WHERE uploaded_by = ? AND doc_type IN ('interim_report','final_report')
");
$stmt->execute([$userId]);
$reportCount = (int)$stmt->fetchColumn();

// get the next upcoming confirmed visit
$stmt = $pdo->prepare("
    SELECT v.visit_date, v.visit_time, v.type, v.location, v.meeting_link
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    WHERE p.student_id = ?
      AND v.visit_date >= CURDATE()
      AND v.status = 'confirmed'
    ORDER BY v.visit_date ASC LIMIT 1
");
$stmt->execute([$userId]);
$nextVisit = $stmt->fetch();

// for the status tracker: if the student already has an approved/active placement,
// always show that one — not a newer draft/pending request which would override it
if ($placement) {
    $latestRequest = $placement;
} else {
    $stmt = $pdo->prepare("
        SELECT p.status, p.created_at, p.id, c.name AS company_name
        FROM placements p
        JOIN companies c ON p.company_id = c.id
        WHERE p.student_id = ?
        ORDER BY p.created_at DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $latestRequest = $stmt->fetch();
}

// work out the interim and final report due dates from placement dates
// interim is 4 months in, final is 1 month before end
$interimDue = null;
$finalDue   = null;
if ($placement) {
    $start = new DateTime($placement['start_date']);
    $end   = new DateTime($placement['end_date']);
    $interimDue = (clone $start)->modify('+4 months');
    $finalDue   = (clone $end)->modify('-1 month');
}

// calculate how far through the placement the student is
$progressPct = 0;
if ($placement) {
    $start   = new DateTime($placement['start_date']);
    $end     = new DateTime($placement['end_date']);
    $today   = new DateTime();
    $total   = $start->diff($end)->days;
    $elapsed = $start->diff($today)->days;
    $progressPct = $total > 0 ? min(100, round(($elapsed / $total) * 100)) : 0;
}

// get any unread announcements to show in the top banner (max 3)
$unreadAnnouncements = [];
try {
    $stmt = $pdo->prepare("SELECT academic_year, programme_type FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $meInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT a.id, a.title, a.body, a.is_pinned, a.created_at,
               u.full_name AS author_name
        FROM announcements a
        JOIN users u ON a.author_id = u.id
        WHERE (a.expires_at IS NULL OR a.expires_at >= CURDATE())
          AND (a.audience = 'all'
               OR (a.audience = 'year'      AND a.target_value = ?)
               OR (a.audience = 'programme' AND a.target_value = ?))
          AND a.id NOT IN (
              SELECT announcement_id FROM announcement_reads WHERE student_id = ?
          )
        ORDER BY a.is_pinned DESC, a.created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$meInfo['academic_year'] ?? '', $meInfo['programme_type'] ?? '', $userId]);
    $unreadAnnouncements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// map each placement status to a step number for the visual tracker
$statusMap = [
    'draft'              => 0,
    'submitted'          => 1,
    'awaiting_provider'  => 1,
    'awaiting_tutor'     => 2,
    'approved'           => 3,
    'active'             => 4,
    'rejected'           => 4,
];
$currentStep = $latestRequest ? ($statusMap[$latestRequest['status']] ?? 0) : 0;
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- show a banner if the student has unread announcements -->
        <?php if (!empty($unreadAnnouncements)): ?>
        <div style="background:linear-gradient(135deg,#0c1b33,#1a2d4d);border-radius:var(--radius);
                    padding:1.25rem 1.75rem;margin-bottom:1.5rem;display:flex;
                    align-items:flex-start;gap:1.25rem;">
            <span style="font-size:2rem;flex-shrink:0;margin-top:0.1rem;">📢</span>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;
                            gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                    <p style="font-weight:700;color:white;font-size:0.9375rem;">
                        <?= count($unreadAnnouncements) ?> new announcement<?= count($unreadAnnouncements) > 1 ? 's' : '' ?> from your placement team
                    </p>
                    <a href="/inplace/student/announcements.php"
                       style="font-size:0.8125rem;color:rgba(255,255,255,0.7);text-decoration:none;
                              white-space:nowrap;font-weight:500;"
                       onmouseover="this.style.color='white'"
                       onmouseout="this.style.color='rgba(255,255,255,0.7)'">
                        View all →
                    </a>
                </div>
                <?php foreach ($unreadAnnouncements as $ann): ?>
                <a href="/inplace/student/announcements.php"
                   style="display:block;background:rgba(255,255,255,0.08);border-radius:8px;
                          padding:0.75rem 1rem;margin-bottom:0.5rem;text-decoration:none;
                          border:1px solid rgba(255,255,255,0.1);transition:background 0.2s;"
                   onmouseover="this.style.background='rgba(255,255,255,0.14)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.08)'">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.2rem;">
                        <?php if ($ann['is_pinned']): ?>
                        <span style="font-size:0.7rem;background:rgba(232,160,32,0.3);color:#fcd34d;
                                     font-weight:700;padding:0.1rem 0.4rem;border-radius:3px;">📌 PINNED</span>
                        <?php endif; ?>
                        <span style="font-size:0.8rem;color:rgba(255,255,255,0.5);">
                            <?= htmlspecialchars($ann['author_name']) ?> · <?= date('d M', strtotime($ann['created_at'])) ?>
                        </span>
                    </div>
                    <p style="font-weight:600;color:white;font-size:0.9rem;margin-bottom:0.2rem;">
                        <?= htmlspecialchars($ann['title']) ?>
                    </p>
                    <p style="font-size:0.8125rem;color:rgba(255,255,255,0.65);
                              overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($ann['body']) ?>
                    </p>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- stats cards at the top of the page -->
        <div class="stats-grid">

            <div class="stat-card">
                <span class="stat-icon">🏢</span>
                <h3><?= $placement ? 'Active' : 'None' ?></h3>
                <p>Placement Status</p>
                <div class="stat-trend <?= $placement ? 'trend-up' : 'trend-neutral' ?>">
                    <?= $placement
                        ? '✓ ' . htmlspecialchars($placement['company_name'])
                        : 'No active placement yet' ?>
                </div>
            </div>

            <div class="stat-card">
                <span class="stat-icon">📄</span>
                <h3><?= $reportCount ?>/2</h3>
                <p>Reports Submitted</p>
                <div class="stat-trend trend-neutral">
                    <?php if ($reportCount >= 2): ?>
                        All reports submitted ✓
                    <?php elseif ($finalDue): ?>
                        Final due <?= $finalDue->format('d M Y') ?>
                    <?php else: ?>
                        No deadlines set yet
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card">
                <span class="stat-icon">🗓</span>
                <h3><?= $nextVisit ? date('M d', strtotime($nextVisit['visit_date'])) : 'None' ?></h3>
                <p>Next Visit</p>
                <div class="stat-trend trend-up">
                    <?= $nextVisit
                        ? ucfirst($nextVisit['type']) . ' · ' . date('g:i A', strtotime($nextVisit['visit_time']))
                        : 'No upcoming visits' ?>
                </div>
            </div>

            <div class="stat-card">
                <span class="stat-icon">💬</span>
                <h3><?= $unreadCount ?></h3>
                <p>Unread Messages</p>
                <div class="stat-trend trend-neutral">
                    <?= $unreadCount > 0 ? 'From your tutor' : 'All caught up!' ?>
                </div>
            </div>

        </div><!-- /stats-grid -->


        <!-- quick action buttons row -->
        <div class="quick-actions">

            <div class="quick-action" onclick="window.location='/inplace/student/my-placement.php'">
                <div class="qa-icon">🏢</div>
                <div class="qa-label">My Placement</div>
                <div class="qa-desc">View full details</div>
            </div>

            <div class="quick-action" onclick="window.location='/inplace/student/submit-request.php'">
                <div class="qa-icon">📋</div>
                <div class="qa-label">New Request</div>
                <div class="qa-desc">Submit authorisation</div>
            </div>

            <div class="quick-action" onclick="window.location='/inplace/student/reports.php'">
                <div class="qa-icon">📄</div>
                <div class="qa-label">Submit Report</div>
                <div class="qa-desc">
                    <?= $reportCount < 2 ? 'Upload placement report' : 'All reports submitted' ?>
                </div>
            </div>

            <div class="quick-action" onclick="window.location='/inplace/student/messages.php'">
                <div class="qa-icon">💬</div>
                <div class="qa-label">Messages</div>
                <div class="qa-desc">
                    <?= $unreadCount > 0 ? $unreadCount . ' unread' : 'No new messages' ?>
                </div>
            </div>

        </div><!-- /quick-actions -->


        <!-- two column layout: status tracker on left, deadlines on right -->
        <div class="two-col">

            <!-- left: placement request status tracker -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Request Status Tracker</h3>
                    <?php if ($latestRequest): ?>
                        <span class="badge badge-<?= in_array($latestRequest['status'], ['approved','active']) ? 'approved' : (in_array($latestRequest['status'],['rejected']) ? 'rejected' : 'pending') ?>">
                            <?= ucwords(str_replace('_', ' ', $latestRequest['status'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="panel-body">

                    <?php if ($latestRequest): ?>

                        <!-- Status Step Track -->
                        <div class="status-track">

                            <?php
                            $steps = [
                                ['label' => 'Submitted',          'icon' => '✓'],
                                ['label' => 'Provider Confirmed', 'icon' => '✓'],
                                ['label' => 'Tutor Review',       'icon' => '▶'],
                                ['label' => 'Approved',           'icon' => '★'],                            
                            ];
                            foreach ($steps as $i => $step):
                                if ($i < $currentStep) $cls = 'done';
                                elseif ($i === $currentStep) $cls = 'active';
                                else $cls = '';
                            ?>
                                <div class="status-step <?= $cls ?>">
                                    <div class="step-circle">
                                        <?= ($cls === 'done') ? '✓' : ($cls === 'active' ? $step['icon'] : '') ?>
                                    </div>
                                    <div class="step-label"><?= $step['label'] ?></div>
                                </div>
                            <?php endforeach; ?>

                        </div><!-- /status-track -->

                        <!-- Status message below tracker -->
                        <div style="margin-top:1.5rem;padding:1.25rem;background:var(--cream);border-radius:var(--radius-sm);border:1px solid var(--border);">
                            <?php if ($latestRequest['status'] === 'draft'): ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
                                    <p style="font-size:0.875rem;color:var(--muted);">Your request is saved as a <strong style="color:var(--text)">draft</strong>. Submit it when you're ready.</p>
                                    <a href="/inplace/student/submit-request.php?edit=<?= (int)$latestRequest['id'] ?>"
                                       class="btn btn-primary btn-sm">
                                        ✏️ Continue Editing
                                    </a>
                                </div>
                            <?php elseif ($latestRequest['status'] === 'submitted' || $latestRequest['status'] === 'awaiting_provider'): ?>
                                <p style="font-size:0.875rem;color:var(--muted);">Waiting for <strong style="color:var(--text)"><?= htmlspecialchars($latestRequest['company_name']) ?></strong> to confirm your placement details.</p>
                            <?php elseif ($latestRequest['status'] === 'awaiting_tutor'): ?>
                                <p style="font-size:0.875rem;color:var(--muted);">Your request is with your <strong style="color:var(--text)">Placement Tutor</strong> for final approval. You'll be notified by email when a decision is made.</p>
                            <?php elseif ($latestRequest['status'] === 'approved' || $latestRequest['status'] === 'active'): ?>
                                <p style="font-size:0.875rem;color:var(--success);">🎉 Your placement at <strong><?= htmlspecialchars($latestRequest['company_name']) ?></strong> has been approved!</p>
                            <?php elseif ($latestRequest['status'] === 'rejected'): ?>
                                <p style="font-size:0.875rem;color:var(--danger);">Your request was not approved. Please check your messages for feedback from your tutor.</p>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <!-- No request yet -->
                        <div style="text-align:center;padding:2rem;">
                            <div style="font-size:3rem;margin-bottom:1rem;">📋</div>
                            <p style="color:var(--muted);margin-bottom:1.25rem;">You haven't submitted a placement request yet.</p>
                            <a href="/inplace/student/submit-request.php" class="btn btn-primary">Submit a Request →</a>
                        </div>
                    <?php endif; ?>

                </div>
            </div><!-- /panel left -->


            <!-- right: upcoming deadlines panel -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Upcoming Deadlines</h3>
                    <a href="/inplace/student/reports.php" class="btn btn-ghost btn-sm">View Reports →</a>
                </div>
                <div class="panel-body">
                    <div style="display:flex;flex-direction:column;gap:1rem;">

                        <?php if ($placement): ?>

                            <!-- Final Report Deadline -->
                            <?php
                            $today    = new DateTime();
                            $daysLeft = $today->diff($finalDue)->days;
                            $isPast   = $today > $finalDue;
                            $isUrgent = !$isPast && $daysLeft <= 30;
                            $finalSubmitted = $reportCount >= 2;
                            ?>
                            <div style="display:flex;align-items:center;gap:1rem;padding:1rem;
                                background:<?= $finalSubmitted ? 'var(--success-bg)' : ($isUrgent ? 'var(--danger-bg)' : 'var(--warning-bg)') ?>;
                                border-radius:var(--radius-sm);
                                border:1px solid <?= $finalSubmitted ? '#6ee7b7' : ($isUrgent ? '#fca5a5' : '#fcd34d') ?>;">
                                <span style="font-size:1.5rem;"><?= $finalSubmitted ? '✅' : '📄' ?></span>
                                <div>
                                    <p style="font-weight:600;font-size:0.9375rem;">Final Placement Report</p>
                                    <p style="font-size:0.8125rem;color:<?= $finalSubmitted ? 'var(--success)' : ($isUrgent ? 'var(--danger)' : 'var(--warning)') ?>;">
                                        <?php if ($finalSubmitted): ?>
                                            Submitted ✓
                                        <?php elseif ($isPast): ?>
                                            OVERDUE — was due <?= $finalDue->format('d M Y') ?>
                                        <?php else: ?>
                                            Due in <?= $daysLeft ?> days · <?= $finalDue->format('d M Y') ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Interim Report Deadline -->
                            <?php
                            $interimSubmitted = $reportCount >= 1;
                            $intDaysLeft = $today->diff($interimDue)->days;
                            $intPast     = $today > $interimDue;
                            ?>
                            <div style="display:flex;align-items:center;gap:1rem;padding:1rem;
                                background:<?= $interimSubmitted ? 'var(--success-bg)' : ($intPast ? 'var(--danger-bg)' : 'var(--warning-bg)') ?>;
                                border-radius:var(--radius-sm);
                                border:1px solid <?= $interimSubmitted ? '#6ee7b7' : ($intPast ? '#fca5a5' : '#fcd34d') ?>;">
                                <span style="font-size:1.5rem;"><?= $interimSubmitted ? '✅' : '📋' ?></span>
                                <div>
                                    <p style="font-weight:600;font-size:0.9375rem;">Interim Report</p>
                                    <p style="font-size:0.8125rem;color:<?= $interimSubmitted ? 'var(--success)' : ($intPast ? 'var(--danger)' : 'var(--warning)') ?>;">
                                        <?php if ($interimSubmitted): ?>
                                            Submitted · Marked as reviewed ✓
                                        <?php elseif ($intPast): ?>
                                            OVERDUE — was due <?= $interimDue->format('d M Y') ?>
                                        <?php else: ?>
                                            Due in <?= $intDaysLeft ?> days · <?= $interimDue->format('d M Y') ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Placement End Date -->
                            <?php
                            $endDate  = new DateTime($placement['end_date']);
                            $daysToEnd = $today->diff($endDate)->days;
                            ?>
                            <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:var(--info-bg);border-radius:var(--radius-sm);border:1px solid #bae6fd;">
                                <span style="font-size:1.5rem;">🗓</span>
                                <div>
                                    <p style="font-weight:600;font-size:0.9375rem;">Placement End Date</p>
                                    <p style="font-size:0.8125rem;color:var(--info);">
                                        <?= $endDate->format('d M Y') ?> ·
                                        <?= $daysToEnd ?> days remaining
                                    </p>
                                </div>
                            </div>

                        <?php else: ?>
                            <div style="text-align:center;padding:2rem;">
                                <div style="font-size:2rem;margin-bottom:0.75rem;">📅</div>
                                <p style="color:var(--muted);font-size:0.9375rem;">No deadlines yet. Submit a placement request to get started.</p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div><!-- /panel right -->

        </div><!-- /two-col -->


        <!-- progress bar showing how far through the placement the student is -->
        <?php if ($placement): ?>
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3>Placement at <?= htmlspecialchars($placement['company_name']) ?></h3>
                    <p><?= htmlspecialchars($placement['city']) ?> · <?= date('d M Y', strtotime($placement['start_date'])) ?> → <?= date('d M Y', strtotime($placement['end_date'])) ?></p>
                </div>
                <a href="/inplace/student/my-placement.php" class="btn btn-ghost btn-sm">View Full Details →</a>
            </div>
            <div class="panel-body">
                <div style="display:flex;align-items:center;gap:1.25rem;margin-bottom:0.75rem;">
                    <div style="flex:1;">
                        <div class="progress-bar" style="height:12px;">
                            <div class="progress-fill" style="width:<?= $progressPct ?>%;height:100%;"></div>
                        </div>
                    </div>
                    <div style="font-size:1rem;font-weight:700;color:var(--navy);white-space:nowrap;">
                        <?= $progressPct ?>% Complete
                    </div>
                </div>
                <p style="font-size:0.875rem;color:var(--muted);">
                    <?php
                    $start   = new DateTime($placement['start_date']);
                    $end     = new DateTime($placement['end_date']);
                    $today   = new DateTime();
                    $elapsed = round($start->diff($today)->days / 30, 1);
                    $total   = round($start->diff($end)->days / 30, 1);
                    echo "{$elapsed} months completed of a {$total}-month placement";
                    ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /page-content -->
</div><!-- /main -->

<?php include '../includes/footer.php'; ?>