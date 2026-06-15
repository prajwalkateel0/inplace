<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Provider Dashboard';
$pageSubtitle = 'Manage placement authorizations';
$activePage   = 'dashboard';
$userId       = authId();

// Get provider's company ID
$stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();
$companyId = $row['company_id'] ?? null;

if (!$companyId) {
    die("Error: No company associated with this provider account.");
}

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM placements
    WHERE company_id = ? AND status = 'awaiting_provider'
");
$stmt->execute([$companyId]);
$pendingRequests = (int)$stmt->fetchColumn();

// Stats
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM placements
    WHERE company_id = ? AND status IN ('approved','active')
");
$stmt->execute([$companyId]);
$activePlacements = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM visits v
    JOIN placements p ON v.placement_id = p.id
    WHERE p.company_id = ? AND v.visit_date >= CURDATE() AND v.status IN ('proposed','confirmed')
");
$stmt->execute([$companyId]);
$upcomingVisits = (int)$stmt->fetchColumn();

// Pending requests
$stmt = $pdo->prepare("
    SELECT
        p.*,
        u.full_name AS student_name,
        u.email AS student_email,
        u.avatar_initials
    FROM placements p
    JOIN users u ON p.student_id = u.id
    WHERE p.company_id = ? AND p.status = 'awaiting_provider'
    ORDER BY p.created_at DESC
    LIMIT 5
");
$stmt->execute([$companyId]);
$pendingList = $stmt->fetchAll();

// Upcoming visits
$stmt = $pdo->prepare("
    SELECT
        v.*,
        u.full_name AS student_name,
        ut.full_name AS tutor_name
    FROM visits v
    JOIN placements p ON v.placement_id = p.id
    JOIN users u ON p.student_id = u.id
    LEFT JOIN users ut ON v.tutor_id = ut.id
    WHERE p.company_id = ? AND v.visit_date >= CURDATE() AND v.status IN ('proposed','confirmed')
    ORDER BY v.visit_date ASC
    LIMIT 5
");
$stmt->execute([$companyId]);
$visitsList = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon">📋</span>
                <h3><?= $pendingRequests ?></h3>
                <p>Pending Authorizations</p>
                <div class="stat-trend <?= $pendingRequests>0?'trend-neutral':'trend-up' ?>">
                    <?= $pendingRequests>0?'Requires your confirmation':'All clear!' ?>
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">👥</span>
                <h3><?= $activePlacements ?></h3>
                <p>Active Students</p>
                <div class="stat-trend trend-up">Currently on placement</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">🗓</span>
                <h3><?= $upcomingVisits ?></h3>
                <p>Upcoming Visits</p>
                <div class="stat-trend trend-neutral">Scheduled visits</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">💬</span>
                <h3><?= $unreadCount ?></h3>
                <p>Unread Messages</p>
                <div class="stat-trend trend-neutral">
                    <?= $unreadCount>0?'From tutors':'All caught up!' ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="quick-action" onclick="window.location='/inplace/provider/requests.php'">
                <div class="qa-icon">📋</div>
                <div class="qa-label">Auth Requests</div>
                <div class="qa-desc"><?= $pendingRequests ?> pending</div>
            </div>
            <div class="quick-action" onclick="window.location='/inplace/provider/students.php'">
                <div class="qa-icon">👥</div>
                <div class="qa-label">My Students</div>
                <div class="qa-desc"><?= $activePlacements ?> active</div>
            </div>
            <div class="quick-action" onclick="window.location='/inplace/provider/visits.php'">
                <div class="qa-icon">🗓</div>
                <div class="qa-label">Visits</div>
                <div class="qa-desc">View schedule</div>
            </div>
            <div class="quick-action" onclick="window.location='/inplace/provider/settings.php'">
                <div class="qa-icon">⚙️</div>
                <div class="qa-label">Company Details</div>
                <div class="qa-desc">Update info</div>
            </div>
        </div>

        <!-- Two column -->
        <div class="two-col">

            <!-- Pending Requests -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Pending Authorization Requests</h3>
                    <a href="/inplace/provider/requests.php" class="btn btn-primary btn-sm">View All →</a>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($pendingList)): ?>
                        <div style="text-align:center;padding:3rem 2rem;">
                            <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
                            <p style="color:var(--muted);">No pending requests</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pendingList as $req): ?>
                        <div style="padding:1.125rem 2rem;border-bottom:1px solid var(--border);">
                            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:0.5rem;">
                                <div class="avatar"><?= htmlspecialchars($req['avatar_initials']??'??') ?></div>
                                <div style="flex:1;">
                                    <h4 style="font-size:0.9375rem;font-weight:600;">
                                        <?= htmlspecialchars($req['student_name']) ?>
                                    </h4>
                                    <p style="font-size:0.8125rem;color:var(--muted);">
                                        <?= htmlspecialchars($req['role_title']??'N/A') ?>
                                        · <?= date('d M Y', strtotime($req['start_date'])) ?>
                                        - <?= date('d M Y', strtotime($req['end_date'])) ?>
                                    </p>
                                </div>
                            </div>
                            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;">
                                <a href="/inplace/provider/requests.php?id=<?= $req['id'] ?>"
                                   class="btn btn-primary btn-sm">Review →</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upcoming Visits -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Upcoming Visits</h3>
                    <a href="/inplace/provider/visits.php" class="btn btn-ghost btn-sm">View All →</a>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($visitsList)): ?>
                        <div style="text-align:center;padding:3rem 2rem;">
                            <div style="font-size:2.5rem;margin-bottom:0.75rem;">🗓</div>
                            <p style="color:var(--muted);">No upcoming visits</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($visitsList as $v): ?>
                        <div style="padding:1.125rem 2rem;border-bottom:1px solid var(--border);">
                            <div style="display:flex;gap:1rem;align-items:start;">
                                <div class="date-box" style="width:50px;height:50px;">
                                    <div class="day" style="font-size:1rem;"><?= date('d', strtotime($v['visit_date'])) ?></div>
                                    <div class="month" style="font-size:0.65rem;"><?= date('M', strtotime($v['visit_date'])) ?></div>
                                </div>
                                <div style="flex:1;">
                                    <h4 style="font-size:0.9375rem;font-weight:600;">
                                        <?= htmlspecialchars($v['student_name']) ?>
                                    </h4>
                                    <p style="font-size:0.8125rem;color:var(--muted);">
                                        <?= date('g:i A', strtotime($v['visit_time'])) ?>
                                        · <?= ucfirst($v['type']) ?>
                                        · <?= htmlspecialchars($v['tutor_name']??'Tutor') ?>
                                    </p>
                                    <span class="badge badge-<?= $v['status']==='confirmed'?'approved':'pending' ?>"
                                          style="margin-top:0.5rem;font-size:0.7rem;">
                                        <?= ucfirst($v['status']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>