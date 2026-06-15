<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('director');

$pageTitle    = 'Placement Statistics';
$pageSubtitle = 'Aggregate placement data by sector, region and company';
$activePage   = 'dir-placements';
$userId       = authId();
$unreadCount  = 0; $pendingRequests = 0;

// ── Filters ──────────────────────────────────────────────────────
$filterYear    = $_GET['year']    ?? '';
$filterSector  = $_GET['sector']  ?? '';
$filterStatus  = $_GET['status']  ?? '';

$where  = ["p.status != 'draft'"];
$params = [];
if ($filterYear)   { $where[] = "u.academic_year=?";    $params[] = $filterYear; }
if ($filterSector) { $where[] = "c.sector=?";           $params[] = $filterSector; }
if ($filterStatus) { $where[] = "p.status=?";           $params[] = $filterStatus; }
$wsql = 'WHERE ' . implode(' AND ', $where);

// ── All placements (filtered) ─────────────────────────────────────
$placements = $pdo->prepare("
    SELECT p.id, p.status, p.start_date, p.end_date, p.role_title, p.created_at,
           u.full_name AS student_name, u.academic_year, u.programme_type,
           c.name AS company_name, c.city, c.sector,
           t.full_name AS tutor_name
    FROM placements p
    JOIN users u ON p.student_id=u.id
    JOIN companies c ON p.company_id=c.id
    LEFT JOIN users t ON p.tutor_id=t.id
    $wsql
    ORDER BY p.created_at DESC
");
$placements->execute($params);
$allPlacements = $placements->fetchAll(PDO::FETCH_ASSOC);

// ── Filter options ────────────────────────────────────────────────
$years   = $pdo->query("SELECT DISTINCT academic_year FROM users WHERE role='student' AND academic_year IS NOT NULL ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$sectors = $pdo->query("SELECT DISTINCT sector FROM companies WHERE sector IS NOT NULL AND sector!='' ORDER BY sector")->fetchAll(PDO::FETCH_COLUMN);
$statuses = ['awaiting_provider','awaiting_tutor','approved','active','rejected','terminated'];

// ── Summary stats from filtered set ──────────────────────────────
$total    = count($allPlacements);
$active   = count(array_filter($allPlacements, fn($p) => in_array($p['status'],['approved','active'])));
$pending  = count(array_filter($allPlacements, fn($p) => in_array($p['status'],['awaiting_provider','awaiting_tutor'])));
$rejected = count(array_filter($allPlacements, fn($p) => $p['status']==='rejected'));
$rate     = $total>0 ? round($active/$total*100) : 0;

// ── Group by sector ───────────────────────────────────────────────
$bySector = [];
foreach ($allPlacements as $p) {
    $s = $p['sector'] ?: 'Unknown';
    $bySector[$s] = ($bySector[$s] ?? 0) + 1;
}
arsort($bySector);

// ── Group by city ─────────────────────────────────────────────────
$byCity = [];
foreach ($allPlacements as $p) {
    $c = $p['city'] ?: 'Unknown';
    $byCity[$c] = ($byCity[$c] ?? 0) + 1;
}
arsort($byCity);

function hs($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
            <select name="year" onchange="this.form.submit()"
                    style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;">
                <option value="">All Years</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= hs($y) ?>" <?= $filterYear===$y?'selected':'' ?>><?= hs($y) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sector" onchange="this.form.submit()"
                    style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;">
                <option value="">All Sectors</option>
                <?php foreach ($sectors as $s): ?>
                <option value="<?= hs($s) ?>" <?= $filterSector===$s?'selected':'' ?>><?= hs($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()"
                    style="padding:0.625rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;font-size:0.875rem;">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $st): ?>
                <option value="<?= hs($st) ?>" <?= $filterStatus===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterYear || $filterSector || $filterStatus): ?>
            <a href="placements.php" class="btn btn-ghost btn-sm">✕ Clear</a>
            <?php endif; ?>
            <a href="reports.php?<?= http_build_query($_GET) ?>" class="btn btn-primary btn-sm" style="margin-left:auto;">
                📥 Export →
            </a>
        </form>

        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <?php foreach ([
                ['Total',     $total,   'var(--navy)'],
                ['Active',    $active,  'var(--success)'],
                ['Pending',   $pending, 'var(--warning)'],
                ['Rejected',  $rejected,'var(--danger)'],
                ['Rate',      $rate.'%','var(--info)'],
            ] as [$lbl,$val,$col]): ?>
            <div class="panel" style="margin-bottom:0;padding:1.25rem;">
                <p style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;"><?= $lbl ?></p>
                <h3 style="font-family:'Playfair Display',serif;font-size:1.875rem;color:<?= $col ?>;"><?= $val ?></h3>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
            <!-- By Sector -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>By Sector</h3></div>
                <div style="padding:0;">
                    <?php $maxS = max(1, ...array_values($bySector)); foreach (array_slice($bySector,0,8,true) as $s=>$n): ?>
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.625rem 1.5rem;border-bottom:1px solid var(--border);">
                        <span style="flex:1;font-size:0.875rem;"><?= hs($s) ?></span>
                        <div style="width:100px;background:#e5e7eb;border-radius:4px;height:7px;">
                            <div style="width:<?= round($n/$maxS*100) ?>%;background:#0c1b33;border-radius:4px;height:7px;"></div>
                        </div>
                        <span style="font-weight:700;font-size:0.875rem;min-width:24px;text-align:right;"><?= $n ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- By City -->
            <div class="panel" style="margin-bottom:0;">
                <div class="panel-header"><h3>By Location</h3></div>
                <div style="padding:0;">
                    <?php $maxC = max(1, ...array_values($byCity)); foreach (array_slice($byCity,0,8,true) as $c=>$n): ?>
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.625rem 1.5rem;border-bottom:1px solid var(--border);">
                        <span style="flex:1;font-size:0.875rem;"><?= hs($c) ?></span>
                        <div style="width:100px;background:#e5e7eb;border-radius:4px;height:7px;">
                            <div style="width:<?= round($n/$maxC*100) ?>%;background:#2563eb;border-radius:4px;height:7px;"></div>
                        </div>
                        <span style="font-weight:700;font-size:0.875rem;min-width:24px;text-align:right;"><?= $n ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Full table -->
        <div class="panel">
            <div class="panel-header">
                <h3><?= $total ?> Placement<?= $total!==1?'s':'' ?></h3>
            </div>
            <?php if (empty($allPlacements)): ?>
            <div style="text-align:center;padding:3rem 2rem;">
                <p style="color:var(--muted);">No placements match the selected filters.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Sector</th>
                            <th>Location</th>
                            <th>Role</th>
                            <th>Year</th>
                            <th>Dates</th>
                            <th>Tutor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allPlacements as $p):
                            $badge = match($p['status']) {
                                'approved','active'         => 'approved',
                                'awaiting_provider',
                                'awaiting_tutor'            => 'pending',
                                'rejected','terminated'     => 'rejected',
                                default                     => 'open'
                            };
                        ?>
                        <tr>
                            <td style="font-weight:500;"><?= hs($p['student_name']) ?></td>
                            <td style="font-size:0.875rem;"><?= hs($p['company_name']) ?></td>
                            <td><span class="type-chip" style="font-size:0.75rem;"><?= hs($p['sector'] ?: '—') ?></span></td>
                            <td style="font-size:0.875rem;"><?= hs($p['city'] ?: '—') ?></td>
                            <td style="font-size:0.875rem;"><?= hs($p['role_title'] ?: '—') ?></td>
                            <td style="font-size:0.875rem;"><?= hs($p['academic_year'] ?: '—') ?></td>
                            <td style="font-size:0.8rem;font-family:'DM Mono',monospace;">
                                <?= $p['start_date'] ? date('d M Y', strtotime($p['start_date'])) : '—' ?>
                                <?php if ($p['end_date']): ?>
                                <br><span style="color:var(--muted);">→ <?= date('d M Y', strtotime($p['end_date'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.875rem;"><?= hs($p['tutor_name'] ?: '—') ?></td>
                            <td><span class="badge badge-<?= $badge ?>"><?= ucwords(str_replace('_',' ',$p['status'])) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
