<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('director');

$pageTitle    = 'Programme Director Dashboard';
$pageSubtitle = 'Read-only oversight of the placement year programme';
$activePage   = 'dashboard';
$userId       = authId();

// Sidebar badges — director has no unread/pending counts (read-only)
$unreadCount     = 0;
$pendingRequests = 0;

// ── Aggregate KPIs ───────────────────────────────────────────────
$totalStudents  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
$totalPlacements = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE status != 'draft'")->fetchColumn();
$activePlacements = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('approved','active')")->fetchColumn();
$pendingApproval  = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_provider','awaiting_tutor')")->fetchColumn();
$rejectedCount    = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE status='rejected'")->fetchColumn();
$approvalRate     = $totalPlacements > 0 ? round(($activePlacements / $totalPlacements) * 100) : 0;

// Visit stats
$totalVisits     = (int)$pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
$completedVisits = (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status='completed'")->fetchColumn();
$visitCompletion = $totalVisits > 0 ? round(($completedVisits / $totalVisits) * 100) : 0;

// At-risk
$atRiskHigh = 0; $atRiskAll = 0;
try {
    $atRiskHigh = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE risk_flag=1 AND risk_level='high'")->fetchColumn();
    $atRiskAll  = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE risk_flag=1")->fetchColumn();
} catch (Exception $e) {}

// Evaluations submitted
$evalCount = 0;
try {
    $evalCount = (int)$pdo->query("SELECT COUNT(*) FROM provider_evaluations")->fetchColumn();
} catch (Exception $e) {}

// ── Placements by sector ─────────────────────────────────────────
$bySector = $pdo->query("
    SELECT COALESCE(c.sector,'Unknown') AS label, COUNT(*) AS cnt
    FROM placements p
    JOIN companies c ON p.company_id=c.id
    WHERE p.status IN ('approved','active')
    GROUP BY label ORDER BY cnt DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Placements by city/region ─────────────────────────────────────
$byCity = $pdo->query("
    SELECT COALESCE(NULLIF(c.city,''),'Unknown') AS label, COUNT(*) AS cnt
    FROM placements p
    JOIN companies c ON p.company_id=c.id
    WHERE p.status IN ('approved','active')
    GROUP BY label ORDER BY cnt DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Top companies ─────────────────────────────────────────────────
$topCompanies = $pdo->query("
    SELECT c.name AS label, COUNT(*) AS cnt
    FROM placements p
    JOIN companies c ON p.company_id=c.id
    WHERE p.status IN ('approved','active')
    GROUP BY c.id ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Status breakdown ─────────────────────────────────────────────
$statusBreakdown = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM placements WHERE status!='draft' GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// ── Year-on-year (by academic year) ─────────────────────────────
$yoy = $pdo->query("
    SELECT COALESCE(u.academic_year,'Unknown') AS yr, COUNT(p.id) AS cnt
    FROM placements p
    JOIN users u ON p.student_id=u.id
    WHERE p.status IN ('approved','active','rejected','terminated')
    GROUP BY yr ORDER BY yr
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent activity ──────────────────────────────────────────────
$recent = $pdo->query("
    SELECT p.status, p.created_at,
           u.full_name AS student_name,
           c.name AS company_name,
           p.role_title
    FROM placements p
    JOIN users u ON p.student_id=u.id
    JOIN companies c ON p.company_id=c.id
    WHERE p.status != 'draft'
    ORDER BY p.created_at DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- Read-only banner -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius);
                    padding:0.875rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
            <span style="font-size:1.25rem;">👁</span>
            <p style="color:#1e40af;font-size:0.875rem;font-weight:500;margin:0;">
                You are viewing the <strong>Programme Director Dashboard</strong>.
                This is a read-only overview — no changes can be made from here.
            </p>
        </div>

        <!-- KPI Grid -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <?php
            $kpis = [
                ['Total Students',      $totalStudents,    'var(--navy)',   '👥'],
                ['Active Placements',   $activePlacements, 'var(--success)','✅'],
                ['Pending Approval',    $pendingApproval,  'var(--warning)','⏳'],
                ['Approval Rate',       $approvalRate.'%', 'var(--info)',   '📊'],
                ['Total Visits',        $totalVisits,      'var(--navy)',   '🗓'],
                ['Visit Completion',    $visitCompletion.'%','var(--success)','📋'],
                ['At-Risk Students',    $atRiskAll,        $atRiskHigh>0?'var(--danger)':'var(--warning)','⚠️'],
                ['Evaluations Submitted',$evalCount,       'var(--info)',   '⭐'],
            ];
            foreach ($kpis as [$label, $value, $color, $icon]):
            ?>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;">
                    <div>
                        <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;
                                  letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">
                            <?= $label ?>
                        </p>
                        <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:<?= $color ?>;">
                            <?= $value ?>
                        </h3>
                    </div>
                    <span style="font-size:1.75rem;opacity:0.6;"><?= $icon ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts Row -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

            <!-- Placements by Sector -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Placements by Sector</h3></div>
                <div style="padding:1.5rem;">
                    <canvas id="sectorChart" height="220"></canvas>
                </div>
            </div>

            <!-- Status Breakdown -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Placement Status Breakdown</h3></div>
                <div style="padding:1.5rem;">
                    <canvas id="statusChart" height="220"></canvas>
                </div>
            </div>

        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">

            <!-- By City -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Top Placement Locations</h3></div>
                <div style="padding:1.5rem;">
                    <canvas id="cityChart" height="220"></canvas>
                </div>
            </div>

            <!-- Year-on-Year -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Year-on-Year Placements</h3></div>
                <div style="padding:1.5rem;">
                    <canvas id="yoyChart" height="220"></canvas>
                </div>
            </div>

        </div>

        <!-- Top Companies + Recent Activity -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

            <!-- Top Companies -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Top Placement Companies</h3></div>
                <div style="padding:0 1.5rem 1.5rem;">
                    <?php foreach ($topCompanies as $i => $co): ?>
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.625rem 0;
                                border-bottom:1px solid var(--border);">
                        <span style="font-weight:700;color:var(--muted);font-size:0.8rem;min-width:20px;">
                            <?= $i+1 ?>
                        </span>
                        <span style="flex:1;font-size:0.9rem;color:var(--navy);font-weight:500;">
                            <?= htmlspecialchars($co['label']) ?>
                        </span>
                        <span style="font-weight:700;color:var(--success);"><?= $co['cnt'] ?></span>
                        <div style="width:80px;background:#e5e7eb;border-radius:4px;height:6px;">
                            <div style="width:<?= min(100, round($co['cnt']/$topCompanies[0]['cnt']*100)) ?>%;
                                         background:var(--success);border-radius:4px;height:6px;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Recent Placement Activity</h3></div>
                <div style="padding:0 1.5rem 1.5rem;">
                    <?php foreach ($recent as $r):
                        $badgeClass = match($r['status']) {
                            'approved','active'   => 'approved',
                            'awaiting_provider',
                            'awaiting_tutor'      => 'pending',
                            'rejected','terminated'=>'rejected',
                            default               => 'open'
                        };
                    ?>
                    <div style="padding:0.75rem 0;border-bottom:1px solid var(--border);">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <p style="font-weight:600;font-size:0.875rem;color:var(--navy);">
                                    <?= htmlspecialchars($r['student_name']) ?>
                                </p>
                                <p style="font-size:0.78rem;color:var(--muted);">
                                    <?= htmlspecialchars($r['company_name']) ?>
                                    <?= $r['role_title'] ? '· '.htmlspecialchars($r['role_title']) : '' ?>
                                </p>
                            </div>
                            <div style="text-align:right;">
                                <span class="badge badge-<?= $badgeClass ?>" style="font-size:0.72rem;">
                                    <?= ucwords(str_replace('_',' ',$r['status'])) ?>
                                </span>
                                <p style="font-size:0.72rem;color:var(--muted);margin-top:0.25rem;">
                                    <?= date('d M Y', strtotime($r['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div style="padding-top:1rem;">
                        <a href="/inplace/director/placements.php" class="btn btn-ghost btn-sm">
                            View All Placements →
                        </a>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color = '#6b7a8d';

const palette = [
    '#0c1b33','#1e3a5f','#2563eb','#059669','#d97706',
    '#7c3aed','#db2777','#0891b2','#65a30d','#dc2626'
];

// Sector chart
new Chart(document.getElementById('sectorChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($bySector, 'label')) ?>,
        datasets: [{
            label: 'Active Placements',
            data: <?= json_encode(array_column($bySector, 'cnt')) ?>,
            backgroundColor: palette,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// Status doughnut
const statusLabels = <?= json_encode(array_column($statusBreakdown, 'status')) ?>;
const statusData   = <?= json_encode(array_column($statusBreakdown, 'cnt')) ?>;
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels.map(l => l.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())),
        datasets: [{ data: statusData, backgroundColor: palette, hoverOffset: 6 }]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 12 } } },
        cutout: '60%'
    }
});

// City bar
new Chart(document.getElementById('cityChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($byCity, 'label')) ?>,
        datasets: [{
            label: 'Placements',
            data: <?= json_encode(array_column($byCity, 'cnt')) ?>,
            backgroundColor: '#2563eb', borderRadius: 6,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// Year-on-year line
new Chart(document.getElementById('yoyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($yoy, 'yr')) ?>,
        datasets: [{
            label: 'Placements',
            data: <?= json_encode(array_column($yoy, 'cnt')) ?>,
            borderColor: '#0c1b33', backgroundColor: 'rgba(12,27,51,0.08)',
            borderWidth: 2.5, tension: 0.35, fill: true, pointRadius: 5,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
</script>

<?php include '../includes/footer.php'; ?>
