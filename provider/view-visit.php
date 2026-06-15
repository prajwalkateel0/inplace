<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Visit Details';
$pageSubtitle = 'Tutor visit summary';
$activePage   = 'visits';
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

$visitId = (int)($_GET['id'] ?? 0);
if (!$visitId) { header('Location: visits.php'); exit; }

// Fetch visit — ensure it belongs to this provider's company
$stmt = $pdo->prepare("
    SELECT
        v.*,
        p.role_title,
        p.start_date   AS placement_start,
        p.end_date     AS placement_end,
        s.full_name    AS student_name,
        s.email        AS student_email,
        s.avatar_initials AS student_initials,
        t.full_name    AS tutor_name,
        t.email        AS tutor_email,
        t.avatar_initials AS tutor_initials,
        c.name         AS company_name,
        c.address      AS company_address,
        c.city         AS company_city
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN users s      ON p.student_id   = s.id
    JOIN users t      ON p.tutor_id     = t.id
    JOIN companies c  ON p.company_id   = c.id
    WHERE v.id = ? AND p.company_id = ?
");
$stmt->execute([$visitId, $companyId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    header('Location: visits.php?error=not_found');
    exit;
}

$statusInfo = match($visit['status']) {
    'completed'   => ['approved', 'Completed'],
    'confirmed'   => ['approved', 'Confirmed'],
    'cancelled'   => ['rejected', 'Cancelled'],
    'rescheduled' => ['review',   'Reschedule Pending'],
    default       => ['pending',  ucfirst($visit['status'])]
};

$typeLabel = match($visit['type'] ?? '') {
    'virtual'   => '🖥️ Virtual',
    'in_person' => '🏢 In-Person',
    default     => '🏢 In-Person'
};
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- Back link -->
        <a href="visits.php"
           style="display:inline-flex;align-items:center;gap:0.4rem;color:var(--muted);
                  font-size:0.875rem;text-decoration:none;margin-bottom:1.5rem;">
            ← Back to Visits
        </a>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start;">

            <!-- Left: main details -->
            <div>
                <!-- Header card -->
                <div class="panel" style="margin-bottom:1.5rem;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;
                                gap:1rem;flex-wrap:wrap;">
                        <div>
                            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;">
                                <span class="badge badge-<?= $statusInfo[0] ?>"><?= $statusInfo[1] ?></span>
                                <span style="font-size:0.875rem;color:var(--muted);"><?= $typeLabel ?></span>
                            </div>
                            <h2 style="font-family:'Playfair Display',serif;font-size:1.5rem;
                                       color:var(--navy);margin-bottom:0.25rem;">
                                Placement Visit
                            </h2>
                            <p style="color:var(--muted);font-size:0.9rem;">
                                <?= htmlspecialchars($visit['company_name']) ?>
                                <?= $visit['company_city'] ? '· ' . htmlspecialchars($visit['company_city']) : '' ?>
                            </p>
                        </div>
                        <a href="mailto:<?= htmlspecialchars($visit['tutor_email']) ?>"
                           class="btn btn-ghost btn-sm">📧 Contact Tutor</a>
                    </div>
                </div>

                <!-- Date & Time -->
                <div class="panel" style="margin-bottom:1.5rem;">
                    <div class="panel-header"><h3>📅 Date & Time</h3></div>
                    <div class="panel-body">
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;">
                            <div style="background:var(--cream);border-radius:var(--radius-sm);padding:1rem;text-align:center;">
                                <p style="font-size:0.75rem;color:var(--muted);text-transform:uppercase;
                                          font-weight:600;letter-spacing:0.05em;margin-bottom:0.4rem;">Date</p>
                                <p style="font-size:1rem;font-weight:700;color:var(--navy);">
                                    <?= date('l', strtotime($visit['visit_date'])) ?>
                                </p>
                                <p style="font-size:0.875rem;color:var(--muted);">
                                    <?= date('d M Y', strtotime($visit['visit_date'])) ?>
                                </p>
                            </div>
                            <div style="background:var(--cream);border-radius:var(--radius-sm);padding:1rem;text-align:center;">
                                <p style="font-size:0.75rem;color:var(--muted);text-transform:uppercase;
                                          font-weight:600;letter-spacing:0.05em;margin-bottom:0.4rem;">Time</p>
                                <p style="font-size:1rem;font-weight:700;color:var(--navy);">
                                    <?= date('g:i A', strtotime($visit['visit_time'])) ?>
                                </p>
                                <?php if ($visit['duration_hours']): ?>
                                <p style="font-size:0.875rem;color:var(--muted);">
                                    <?= $visit['duration_hours'] ?> hour<?= $visit['duration_hours'] > 1 ? 's' : '' ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div style="background:var(--cream);border-radius:var(--radius-sm);padding:1rem;text-align:center;">
                                <p style="font-size:0.75rem;color:var(--muted);text-transform:uppercase;
                                          font-weight:600;letter-spacing:0.05em;margin-bottom:0.4rem;">Format</p>
                                <p style="font-size:1rem;font-weight:700;color:var(--navy);"><?= $typeLabel ?></p>
                            </div>
                        </div>

                        <?php if ($visit['location']): ?>
                        <div style="margin-top:1rem;padding:0.875rem 1rem;background:var(--cream);
                                    border-radius:var(--radius-sm);display:flex;gap:0.5rem;align-items:center;">
                            <span>📍</span>
                            <span style="font-size:0.9rem;color:var(--text);">
                                <?= htmlspecialchars($visit['location']) ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if ($visit['meeting_link']): ?>
                        <div style="margin-top:0.75rem;padding:0.875rem 1rem;background:#e0f2fe;
                                    border-radius:var(--radius-sm);display:flex;gap:0.5rem;align-items:center;">
                            <span>🔗</span>
                            <a href="<?= htmlspecialchars($visit['meeting_link']) ?>" target="_blank" rel="noopener"
                               style="font-size:0.9rem;color:#0369a1;word-break:break-all;">
                                <?= htmlspecialchars($visit['meeting_link']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notes / Purpose -->
                <?php if ($visit['notes']): ?>
                <div class="panel" style="margin-bottom:1.5rem;">
                    <div class="panel-header"><h3>📋 Visit Notes / Purpose</h3></div>
                    <div class="panel-body">
                        <p style="font-size:0.9375rem;color:var(--text);line-height:1.7;white-space:pre-wrap;">
                            <?= htmlspecialchars($visit['notes']) ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: People involved -->
            <div style="position:sticky;top:1.5rem;display:flex;flex-direction:column;gap:1.25rem;">

                <!-- Student -->
                <div class="panel" style="margin-bottom:0;">
                    <div class="panel-header"><h3>👨‍🎓 Student</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;align-items:center;gap:0.875rem;margin-bottom:1rem;">
                            <div class="avatar" style="width:44px;height:44px;font-size:1rem;">
                                <?= htmlspecialchars($visit['student_initials'] ?? '??') ?>
                            </div>
                            <div>
                                <div style="font-weight:700;color:var(--navy);">
                                    <?= htmlspecialchars($visit['student_name']) ?>
                                </div>
                                <div style="font-size:0.8125rem;color:var(--muted);">
                                    <?= htmlspecialchars($visit['student_email']) ?>
                                </div>
                            </div>
                        </div>
                        <div style="font-size:0.8125rem;color:var(--muted);">
                            <div style="margin-bottom:0.35rem;">
                                💼 <strong>Role:</strong> <?= htmlspecialchars($visit['role_title'] ?? '—') ?>
                            </div>
                            <?php if ($visit['placement_start']): ?>
                            <div>
                                📅 <strong>Period:</strong>
                                <?= date('M Y', strtotime($visit['placement_start'])) ?>
                                → <?= date('M Y', strtotime($visit['placement_end'])) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tutor -->
                <div class="panel" style="margin-bottom:0;">
                    <div class="panel-header"><h3>👨‍🏫 Placement Tutor</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;align-items:center;gap:0.875rem;margin-bottom:1rem;">
                            <div class="avatar" style="width:44px;height:44px;font-size:1rem;
                                                       background:var(--navy);">
                                <?= htmlspecialchars($visit['tutor_initials'] ?? '??') ?>
                            </div>
                            <div>
                                <div style="font-weight:700;color:var(--navy);">
                                    <?= htmlspecialchars($visit['tutor_name']) ?>
                                </div>
                                <div style="font-size:0.8125rem;color:var(--muted);">
                                    <?= htmlspecialchars($visit['tutor_email']) ?>
                                </div>
                            </div>
                        </div>
                        <a href="mailto:<?= htmlspecialchars($visit['tutor_email']) ?>"
                           class="btn btn-primary" style="width:100%;text-align:center;display:block;">
                            📧 Send Email
                        </a>
                    </div>
                </div>

                <!-- Scheduled info -->
                <div class="panel" style="margin-bottom:0;padding:1rem 1.25rem;">
                    <p style="font-size:0.75rem;color:var(--muted);margin-bottom:0.25rem;">Scheduled on</p>
                    <p style="font-size:0.875rem;font-weight:600;color:var(--navy);">
                        <?= $visit['created_at'] ? date('d M Y', strtotime($visit['created_at'])) : '—' ?>
                    </p>
                </div>

            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
