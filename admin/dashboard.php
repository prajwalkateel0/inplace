<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('admin');

$pageTitle    = 'System Administration';
$pageSubtitle = 'Manage users, settings and system health';
$activePage   = 'dashboard';
$userId       = authId();

$unreadCount = 0;
$pendingRequests = 0;

// System stats
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$totalUsers = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('approved','active')");
$activePlacements = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM companies");
$totalCompanies = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM visits WHERE visit_date >= CURDATE()");
$upcomingVisits = (int)$stmt->fetchColumn();

// User breakdown
$stmt = $pdo->query("
    SELECT role, COUNT(*) as cnt
    FROM users
    GROUP BY role
");
$usersByRole = [];
foreach ($stmt->fetchAll() as $row) {
    $usersByRole[$row['role']] = $row['cnt'];
}

// Recent activity (audit log)
$stmt = $pdo->query("
    SELECT a.*, u.full_name
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC
    LIMIT 10
");
$recentActivity = $stmt->fetchAll();

// System health checks
$dbSize = $pdo->query("
    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
")->fetchColumn();

$uploadsDirSize = 0;
$uploadsPath = realpath(__DIR__ . '/../assets/uploads');
if ($uploadsPath && is_dir($uploadsPath)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsPath));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $uploadsDirSize += $file->getSize();
        }
    }
}
$uploadsDirSize = round($uploadsDirSize / 1024 / 1024, 2);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-icon">👥</span>
                <h3><?= $totalUsers ?></h3>
                <p>Total Users</p>
                <div class="stat-trend trend-up">
                    <?= $usersByRole['student']??0 ?> students,
                    <?= $usersByRole['tutor']??0 ?> tutors
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">🏢</span>
                <h3><?= $activePlacements ?></h3>
                <p>Active Placements</p>
                <div class="stat-trend trend-up">Currently running</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">🏭</span>
                <h3><?= $totalCompanies ?></h3>
                <p>Registered Companies</p>
                <div class="stat-trend trend-neutral">Partner organizations</div>
            </div>
            <div class="stat-card">
                <span class="stat-icon">💾</span>
                <h3><?= $dbSize ?> MB</h3>
                <p>Database Size</p>
                <div class="stat-trend trend-neutral">
                    <?= $uploadsDirSize ?> MB uploads
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="quick-action" onclick="window.location='/inplace/admin/users.php'">
                <div class="qa-icon">👥</div>
                <div class="qa-label">Manage Users</div>
                <div class="qa-desc"><?= $totalUsers ?> total</div>
            </div>
            <div class="quick-action" onclick="window.location='/inplace/admin/settings.php'">
                <div class="qa-icon">⚙️</div>
                <div class="qa-label">System Settings</div>
                <div class="qa-desc">Configure system</div>
            </div>
            <div class="quick-action" onclick="window.location='/inplace/admin/logs.php'">
                <div class="qa-icon">📊</div>
                <div class="qa-label">Audit Logs</div>
                <div class="qa-desc">View activity</div>
            </div>
            <div class="quick-action" onclick="if(confirm('Backup database now?')) window.location='/inplace/admin/actions/backup.php'">
                <div class="qa-icon">💾</div>
                <div class="qa-label">Backup</div>
                <div class="qa-desc">Export database</div>
            </div>
        </div>

        <!-- Two Column -->
        <div class="two-col">

            <!-- User Breakdown -->
            <div class="panel">
                <div class="panel-header">
                    <h3>User Breakdown</h3>
                    <a href="/inplace/admin/users.php" class="btn btn-primary btn-sm">Manage →</a>
                </div>
                <div class="panel-body">
                    <div style="display:flex;flex-direction:column;gap:1rem;">
                        
                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    padding:0.875rem 1.25rem;background:var(--cream);
                                    border-radius:var(--radius-sm);border:1px solid var(--border);">
                            <div>
                                <p style="font-weight:600;font-size:0.9375rem;">Students</p>
                                <p style="font-size:0.75rem;color:var(--muted);">Active accounts</p>
                            </div>
                            <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--navy);">
                                <?= $usersByRole['student']??0 ?>
                            </h3>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    padding:0.875rem 1.25rem;background:var(--cream);
                                    border-radius:var(--radius-sm);border:1px solid var(--border);">
                            <div>
                                <p style="font-weight:600;font-size:0.9375rem;">Placement Tutors</p>
                                <p style="font-size:0.75rem;color:var(--muted);">Staff accounts</p>
                            </div>
                            <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--navy);">
                                <?= $usersByRole['tutor']??0 ?>
                            </h3>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    padding:0.875rem 1.25rem;background:var(--cream);
                                    border-radius:var(--radius-sm);border:1px solid var(--border);">
                            <div>
                                <p style="font-weight:600;font-size:0.9375rem;">Placement Providers</p>
                                <p style="font-size:0.75rem;color:var(--muted);">Company accounts</p>
                            </div>
                            <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--navy);">
                                <?= $usersByRole['provider']??0 ?>
                            </h3>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    padding:0.875rem 1.25rem;background:var(--cream);
                                    border-radius:var(--radius-sm);border:1px solid var(--border);">
                            <div>
                                <p style="font-weight:600;font-size:0.9375rem;">Administrators</p>
                                <p style="font-size:0.75rem;color:var(--muted);">Admin accounts</p>
                            </div>
                            <h3 style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--navy);">
                                <?= $usersByRole['admin']??0 ?>
                            </h3>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="panel">
                <div class="panel-header">
                    <h3>Recent Activity</h3>
                    <a href="/inplace/admin/logs.php" class="btn btn-ghost btn-sm">View All →</a>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($recentActivity)): ?>
                        <div style="text-align:center;padding:3rem 2rem;">
                            <p style="color:var(--muted);">No activity logged yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $log): ?>
                        <div style="padding:0.875rem 1.5rem;border-bottom:1px solid var(--border);">
                            <div style="display:flex;align-items:start;gap:0.75rem;">
                                <span style="font-size:1.25rem;">📝</span>
                                <div style="flex:1;">
                                    <p style="font-size:0.875rem;font-weight:500;">
                                        <?= htmlspecialchars($log['full_name']??'System') ?>
                                    </p>
                                    <p style="font-size:0.8125rem;color:var(--muted);margin-top:0.125rem;">
                                        <?= htmlspecialchars($log['action']) ?>
                                        <?php if ($log['table_affected']): ?>
                                            — <?= htmlspecialchars($log['table_affected']) ?>
                                        <?php endif; ?>
                                    </p>
                                    <p style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem;">
                                        <?= date('d M Y, g:i A', strtotime($log['created_at'])) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- System Health -->
        <div class="panel">
            <div class="panel-header">
                <h3>System Health</h3>
            </div>
            <div class="panel-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.25rem;">
                    
                    <div style="text-align:center;padding:1.25rem;background:var(--success-bg);
                                border-radius:var(--radius-sm);border:1px solid #6ee7b7;">
                        <p style="font-size:2rem;margin-bottom:0.5rem;">✅</p>
                        <p style="font-weight:600;color:var(--success);">Database Online</p>
                        <p style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem;">
                            <?= $dbSize ?> MB used
                        </p>
                    </div>

                    <div style="text-align:center;padding:1.25rem;background:var(--success-bg);
                                border-radius:var(--radius-sm);border:1px solid #6ee7b7;">
                        <p style="font-size:2rem;margin-bottom:0.5rem;">📁</p>
                        <p style="font-weight:600;color:var(--success);">Uploads Directory</p>
                        <p style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem;">
                            <?= $uploadsDirSize ?> MB stored
                        </p>
                    </div>

                    <div style="text-align:center;padding:1.25rem;background:var(--info-bg);
                                border-radius:var(--radius-sm);border:1px solid #bae6fd;">
                        <p style="font-size:2rem;margin-bottom:0.5rem;">🔐</p>
                        <p style="font-weight:600;color:var(--info);">Security</p>
                        <p style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem;">
                            Password encryption active
                        </p>
                    </div>

                    <div style="text-align:center;padding:1.25rem;background:var(--info-bg);
                                border-radius:var(--radius-sm);border:1px solid #bae6fd;">
                        <p style="font-size:2rem;margin-bottom:0.5rem;">⏰</p>
                        <p style="font-weight:600;color:var(--info);">Server Time</p>
                        <p style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem;">
                            <?= date('d M Y, g:i A') ?>
                        </p>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>