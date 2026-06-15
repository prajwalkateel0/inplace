<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('director');

$pageTitle    = 'At-Risk Students';
$pageSubtitle = 'Read-only view of flagged students requiring attention';
$activePage   = 'dir-at-risk';
$userId       = authId();
$unreadCount  = 0; $pendingRequests = 0;

// ── Flagged students ─────────────────────────────────────────────
$flagged = [];
try {
    $flagged = $pdo->query("
        SELECT p.id AS placement_id, p.risk_level, p.risk_notes, p.risk_flagged_at,
               p.start_date, p.end_date, p.role_title, p.status,
               u.full_name AS student_name, u.email AS student_email,
               u.academic_year, u.programme_type,
               c.name AS company_name, c.sector, c.city,
               t.full_name AS tutor_name, t.email AS tutor_email,
               f.full_name AS flagged_by_name
        FROM placements p
        JOIN users u ON p.student_id=u.id
        JOIN companies c ON p.company_id=c.id
        LEFT JOIN users t ON p.tutor_id=t.id
        LEFT JOIN users f ON p.risk_flagged_by=f.id
        WHERE p.risk_flag=1
        ORDER BY FIELD(p.risk_level,'high','medium','low'), p.risk_flagged_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$high   = count(array_filter($flagged, fn($p) => $p['risk_level']==='high'));
$medium = count(array_filter($flagged, fn($p) => $p['risk_level']==='medium'));
$low    = count(array_filter($flagged, fn($p) => $p['risk_level']==='low'));

// ── All active students (for at-a-glance overview) ───────────────
$allActive = $pdo->query("
    SELECT u.full_name AS student_name, u.academic_year, u.programme_type,
           c.name AS company_name, p.role_title, p.status,
           p.risk_flag, p.risk_level
    FROM placements p
    JOIN users u ON p.student_id=u.id
    JOIN companies c ON p.company_id=c.id
    WHERE p.status IN ('approved','active')
    ORDER BY p.risk_flag DESC, FIELD(p.risk_level,'high','medium','low'), u.full_name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- Read-only notice -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius);
                    padding:0.875rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
            <span>👁</span>
            <p style="color:#1e40af;font-size:0.875rem;font-weight:500;margin:0;">
                Read-only view. Flags are managed by the placement tutor.
                <a href="/inplace/director/reports.php?export=at_risk" style="color:#1e40af;font-weight:700;">
                    📥 Download CSV
                </a>
            </p>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Total Flagged</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--navy);"><?= count($flagged) ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">High Risk</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:#dc2626;"><?= $high ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Medium Risk</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:#d97706;"><?= $medium ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Low Risk</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:#059669;"><?= $low ?></h3>
            </div>
        </div>

        <!-- Flagged students detail -->
        <?php if (!empty($flagged)): ?>
        <div class="panel" style="margin-bottom:1.5rem;">
            <div class="panel-header">
                <h3>⚠️ Flagged Students</h3>
            </div>
            <div style="display:flex;flex-direction:column;gap:0;">
                <?php foreach ($flagged as $s):
                    $borderColor = match($s['risk_level']) {
                        'high'   => '#dc2626',
                        'medium' => '#d97706',
                        default  => '#059669',
                    };
                    $levelLabel = match($s['risk_level']) {
                        'high'   => '🔴 High Risk',
                        'medium' => '🟡 Medium Risk',
                        default  => '🟢 Low Risk',
                    };
                ?>
                <div style="border-left:4px solid <?= $borderColor ?>;padding:1.25rem 1.5rem;
                             border-bottom:1px solid var(--border);">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.4rem;">
                                <h4 style="font-size:1rem;font-weight:700;color:var(--navy);">
                                    <?= htmlspecialchars($s['student_name']) ?>
                                </h4>
                                <span style="font-weight:700;color:<?= $borderColor ?>;font-size:0.875rem;">
                                    <?= $levelLabel ?>
                                </span>
                            </div>
                            <p style="font-size:0.875rem;color:var(--muted);margin-bottom:0.5rem;">
                                <?= htmlspecialchars($s['company_name']) ?>
                                <?= $s['role_title'] ? '· '.htmlspecialchars($s['role_title']) : '' ?>
                                <?= $s['city'] ? '· '.htmlspecialchars($s['city']) : '' ?>
                            </p>
                            <?php if ($s['risk_notes']): ?>
                            <p style="font-size:0.875rem;color:var(--text);background:var(--cream);
                                      padding:0.625rem 0.875rem;border-radius:var(--radius-sm);
                                      line-height:1.5;margin-bottom:0.5rem;">
                                <?= nl2br(htmlspecialchars($s['risk_notes'])) ?>
                            </p>
                            <?php endif; ?>
                            <p style="font-size:0.78rem;color:var(--muted);">
                                Flagged by <?= htmlspecialchars($s['flagged_by_name'] ?? 'tutor') ?>
                                <?= $s['risk_flagged_at'] ? '· ' . date('d M Y', strtotime($s['risk_flagged_at'])) : '' ?>
                                · Tutor: <strong><?= htmlspecialchars($s['tutor_name'] ?? 'Unassigned') ?></strong>
                                <?= $s['tutor_email'] ? "(<a href='mailto:{$s['tutor_email']}' style='color:var(--navy);'>{$s['tutor_email']}</a>)" : '' ?>
                            </p>
                        </div>
                        <div style="text-align:right;font-size:0.8125rem;">
                            <p style="color:var(--muted);"><?= htmlspecialchars($s['academic_year'] ?? '—') ?></p>
                            <p style="color:var(--muted);"><?= htmlspecialchars($s['programme_type'] ?? '') ?></p>
                            <span class="badge badge-<?= in_array($s['status'],['approved','active'])?'approved':'pending' ?>">
                                <?= ucwords(str_replace('_',' ',$s['status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="panel" style="text-align:center;padding:3rem 2rem;margin-bottom:1.5rem;">
            <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
            <h3 style="color:var(--navy);">No at-risk students flagged</h3>
            <p style="color:var(--muted);">All students are currently on track.</p>
        </div>
        <?php endif; ?>

        <!-- All active students overview -->
        <div class="panel">
            <div class="panel-header">
                <h3>All Active Students Overview</h3>
                <p><?= count($allActive) ?> placements</p>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Year</th>
                            <th>Programme</th>
                            <th>Status</th>
                            <th>Risk</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allActive as $s): ?>
                        <tr>
                            <td style="font-weight:500;"><?= htmlspecialchars($s['student_name']) ?></td>
                            <td style="font-size:0.875rem;"><?= htmlspecialchars($s['company_name']) ?></td>
                            <td style="font-size:0.875rem;"><?= htmlspecialchars($s['academic_year'] ?? '—') ?></td>
                            <td style="font-size:0.875rem;"><?= htmlspecialchars($s['programme_type'] ?? '—') ?></td>
                            <td><span class="badge badge-<?= in_array($s['status'],['approved','active'])?'approved':'pending' ?>"><?= ucwords(str_replace('_',' ',$s['status'])) ?></span></td>
                            <td>
                                <?php if ($s['risk_flag']): ?>
                                    <?php $rc = match($s['risk_level']) { 'high'=>'#dc2626', 'medium'=>'#d97706', default=>'#059669' }; ?>
                                    <span style="font-weight:700;color:<?= $rc ?>;">
                                        <?= ucfirst($s['risk_level'] ?? 'Flagged') ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-size:0.8125rem;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
