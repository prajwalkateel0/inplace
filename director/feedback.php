<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('director');

$pageTitle    = 'Employer Feedback';
$pageSubtitle = 'Summary of provider performance evaluations';
$activePage   = 'dir-feedback';
$userId       = authId();
$unreadCount  = 0; $pendingRequests = 0;

// ── Load evaluations ─────────────────────────────────────────────
$evaluations = [];
$evalExists  = false;
try {
    $evaluations = $pdo->query("
        SELECT e.*,
               u.full_name AS student_name, u.academic_year, u.programme_type,
               c.name AS company_name, c.sector, c.city,
               p.role_title
        FROM provider_evaluations e
        JOIN placements p ON e.placement_id=p.id
        JOIN users u ON p.student_id=u.id
        JOIN companies c ON p.company_id=c.id
        ORDER BY FIELD(e.eval_period,'final','interim','ad_hoc'), e.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $evalExists = true;
} catch (Exception $e) {}

// ── Aggregate averages ────────────────────────────────────────────
$avgAll = [];
if (!empty($evaluations)) {
    $keys = ['attendance','punctuality','professionalism','technical_skills','communication','initiative','overall_rating'];
    foreach ($keys as $k) {
        $vals = array_filter(array_column($evaluations, $k), fn($v) => $v !== null);
        $avgAll[$k] = count($vals) ? round(array_sum($vals) / count($vals), 1) : null;
    }
}

// ── By company averages ───────────────────────────────────────────
$byCompany = [];
foreach ($evaluations as $e) {
    $co = $e['company_name'];
    if (!isset($byCompany[$co])) {
        $byCompany[$co] = ['ratings' => [], 'count' => 0, 'recommend' => 0, 'sector' => $e['sector']];
    }
    $byCompany[$co]['ratings'][]  = $e['overall_rating'];
    $byCompany[$co]['count']++;
    $byCompany[$co]['recommend'] += (int)$e['recommend_future'];
}
foreach ($byCompany as $co => &$data) {
    $data['avg'] = count($data['ratings']) ? round(array_sum($data['ratings']) / count($data['ratings']), 1) : null;
}
unset($data);
uasort($byCompany, fn($a,$b) => ($b['avg'] ?? 0) <=> ($a['avg'] ?? 0));

$totalEvals       = count($evaluations);
$avgOverall       = $avgAll['overall_rating'] ?? null;
$recommendCount   = count(array_filter($evaluations, fn($e) => $e['recommend_future']));
$recommendRate    = $totalEvals > 0 ? round($recommendCount / $totalEvals * 100) : 0;
$interimCount     = count(array_filter($evaluations, fn($e) => $e['eval_period']==='interim'));
$finalCount       = count(array_filter($evaluations, fn($e) => $e['eval_period']==='final'));

function stars(float $rating): string {
    $full  = floor($rating);
    $html  = '<span style="color:#f59e0b;">' . str_repeat('★', $full) . '</span>';
    $html .= '<span style="color:#d1d5db;">' . str_repeat('★', 5 - $full) . '</span>';
    return $html;
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Total Evaluations</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--navy);"><?= $totalEvals ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Avg Overall Rating</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:#f59e0b;">
                    <?= $avgOverall !== null ? number_format($avgOverall, 1) . '/5' : '—' ?>
                </h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Would Recommend</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--success);"><?= $recommendRate ?>%</h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Interim / Final</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:var(--info);">
                    <?= $interimCount ?> / <?= $finalCount ?>
                </h3>
            </div>
        </div>

        <?php if (!$evalExists || empty($evaluations)): ?>
        <div class="panel" style="text-align:center;padding:4rem 2rem;">
            <div style="font-size:3rem;margin-bottom:1rem;">⭐</div>
            <h3 style="color:var(--navy);">No evaluations submitted yet</h3>
            <p style="color:var(--muted);">Provider evaluations will appear here once submitted.</p>
        </div>
        <?php else: ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;margin-bottom:1.5rem;align-items:start;">

            <!-- Average ratings breakdown -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>Average Ratings Across All Evaluations</h3></div>
                <div style="padding:1.5rem;">
                    <?php
                    $criteriaLabels = [
                        'attendance'       => 'Attendance',
                        'punctuality'      => 'Punctuality',
                        'professionalism'  => 'Professionalism',
                        'technical_skills' => 'Technical Skills',
                        'communication'    => 'Communication',
                        'initiative'       => 'Initiative',
                        'overall_rating'   => 'Overall',
                    ];
                    foreach ($criteriaLabels as $key => $label):
                        $avg = $avgAll[$key] ?? null;
                        $pct = $avg ? ($avg / 5) * 100 : 0;
                    ?>
                    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
                        <span style="min-width:130px;font-size:0.875rem;font-weight:<?= $key==='overall_rating'?'700':'400' ?>;
                                     color:var(--<?= $key==='overall_rating'?'navy':'text' ?>);">
                            <?= $label ?>
                        </span>
                        <div style="flex:1;background:#e5e7eb;border-radius:6px;height:10px;">
                            <div style="width:<?= round($pct) ?>%;background:<?= $key==='overall_rating'?'#f59e0b':'#2563eb' ?>;
                                          border-radius:6px;height:10px;transition:width 0.3s;"></div>
                        </div>
                        <span style="min-width:36px;text-align:right;font-weight:700;font-size:0.875rem;
                                     color:<?= $key==='overall_rating'?'#f59e0b':'var(--navy)' ?>;">
                            <?= $avg !== null ? number_format($avg, 1) : '—' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- By company -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>By Company</h3></div>
                <div style="padding:0;">
                    <?php foreach (array_slice($byCompany, 0, 8, true) as $co => $data): ?>
                    <div style="padding:0.875rem 1.5rem;border-bottom:1px solid var(--border);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.2rem;">
                            <span style="font-size:0.875rem;font-weight:600;color:var(--navy);">
                                <?= htmlspecialchars($co) ?>
                            </span>
                            <span style="font-size:0.875rem;font-weight:700;color:#f59e0b;">
                                <?= $data['avg'] !== null ? number_format($data['avg'],1) : '—' ?>/5
                            </span>
                        </div>
                        <div style="display:flex;gap:1rem;font-size:0.78rem;color:var(--muted);">
                            <span><?= $data['count'] ?> eval<?= $data['count']!==1?'s':'' ?></span>
                            <span><?= $data['recommend'] ?>/<?= $data['count'] ?> recommend</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- Individual evaluations table -->
        <div class="panel">
            <div class="panel-header">
                <h3>All Evaluations</h3>
                <a href="/inplace/director/reports.php?export=evaluations" class="btn btn-ghost btn-sm">📥 Export CSV</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Period</th>
                            <th>Overall</th>
                            <th>Attendance</th>
                            <th>Professionalism</th>
                            <th>Technical</th>
                            <th>Communication</th>
                            <th>Recommend</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($evaluations as $e): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500;font-size:0.875rem;"><?= htmlspecialchars($e['student_name']) ?></div>
                                <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($e['academic_year'] ?? '') ?></div>
                            </td>
                            <td style="font-size:0.875rem;"><?= htmlspecialchars($e['company_name']) ?></td>
                            <td>
                                <span class="badge <?= $e['eval_period']==='final'?'badge-approved':'badge-pending' ?>">
                                    <?= ucfirst($e['eval_period']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($e['overall_rating']): ?>
                                <span style="font-weight:700;color:#f59e0b;"><?= $e['overall_rating'] ?>/5</span>
                                <?php else: ?><span style="color:var(--muted);">—</span><?php endif; ?>
                            </td>
                            <?php foreach (['attendance','professionalism','technical_skills','communication'] as $k): ?>
                            <td style="text-align:center;font-size:0.875rem;">
                                <?= $e[$k] ?? '—' ?>
                            </td>
                            <?php endforeach; ?>
                            <td style="text-align:center;">
                                <?= $e['recommend_future'] ? '<span style="color:var(--success);font-weight:700;">Yes</span>' : '<span style="color:var(--muted);">No</span>' ?>
                            </td>
                            <td style="font-size:0.8rem;color:var(--muted);">
                                <?= date('d M Y', strtotime($e['created_at'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
