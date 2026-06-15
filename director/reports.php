<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('director');

$unreadCount = 0; $pendingRequests = 0;

// ── CSV Export handler ───────────────────────────────────────────
if (isset($_GET['export'])) {
    $type = $_GET['export'];

    $filename = 'inplace_' . $type . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");

    if ($type === 'placements') {
        fputcsv($out, ['Student Name','Student Email','Academic Year','Programme','Company','Sector','City',
                       'Role','Start Date','End Date','Salary','Status','Tutor','Submitted']);
        $rows = $pdo->query("
            SELECT u.full_name, u.email, u.academic_year, u.programme_type,
                   c.name, c.sector, c.city, p.role_title, p.start_date, p.end_date,
                   p.salary, p.status, t.full_name AS tutor, p.created_at
            FROM placements p
            JOIN users u ON p.student_id=u.id
            JOIN companies c ON p.company_id=c.id
            LEFT JOIN users t ON p.tutor_id=t.id
            WHERE p.status != 'draft'
            ORDER BY p.created_at DESC
        ")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) fputcsv($out, $r);

    } elseif ($type === 'visits') {
        fputcsv($out, ['Visit Date','Visit Time','Student','Company','Tutor','Type','Status','Duration (hrs)']);
        try {
            $rows = $pdo->query("
                SELECT v.visit_date, v.visit_time, u.full_name, c.name, t.full_name,
                       v.type, v.status, v.duration_hours
                FROM visits v
                JOIN placements p ON v.placement_id=p.id
                JOIN users u ON p.student_id=u.id
                JOIN companies c ON p.company_id=c.id
                LEFT JOIN users t ON v.tutor_id=t.id
                ORDER BY v.visit_date DESC
            ")->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) fputcsv($out, $r);
        } catch (Exception $e) {}

    } elseif ($type === 'at_risk') {
        fputcsv($out, ['Student','Email','Company','Role','Risk Level','Notes','Flagged At','Flagged By']);
        try {
            $rows = $pdo->query("
                SELECT u.full_name, u.email, c.name, p.role_title,
                       p.risk_level, p.risk_notes, p.risk_flagged_at, f.full_name AS flagged_by
                FROM placements p
                JOIN users u ON p.student_id=u.id
                JOIN companies c ON p.company_id=c.id
                LEFT JOIN users f ON p.risk_flagged_by=f.id
                WHERE p.risk_flag=1
                ORDER BY FIELD(p.risk_level,'high','medium','low')
            ")->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) fputcsv($out, $r);
        } catch (Exception $e) {}

    } elseif ($type === 'evaluations') {
        fputcsv($out, ['Student','Company','Period','Attendance','Punctuality','Professionalism',
                       'Technical Skills','Communication','Initiative','Overall','Recommend','Submitted']);
        try {
            $rows = $pdo->query("
                SELECT u.full_name, c.name, e.eval_period,
                       e.attendance, e.punctuality, e.professionalism, e.technical_skills,
                       e.communication, e.initiative, e.overall_rating,
                       e.recommend_future, e.created_at
                FROM provider_evaluations e
                JOIN placements p ON e.placement_id=p.id
                JOIN users u ON p.student_id=u.id
                JOIN companies c ON p.company_id=c.id
                ORDER BY e.created_at DESC
            ")->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $r) fputcsv($out, $r);
        } catch (Exception $e) {}

    } elseif ($type === 'summary') {
        // Summary report: one row per sector with counts
        fputcsv($out, ['Sector','Total Placements','Active','Pending','Rejected','Avg Duration (days)']);
        $rows = $pdo->query("
            SELECT COALESCE(c.sector,'Unknown') AS sector,
                   COUNT(*) AS total,
                   SUM(p.status IN ('approved','active')) AS active_cnt,
                   SUM(p.status IN ('awaiting_provider','awaiting_tutor')) AS pending_cnt,
                   SUM(p.status='rejected') AS rejected_cnt,
                   ROUND(AVG(DATEDIFF(p.end_date, p.start_date))) AS avg_days
            FROM placements p JOIN companies c ON p.company_id=c.id
            WHERE p.status != 'draft'
            GROUP BY sector ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) fputcsv($out, $r);
    }

    fclose($out);
    exit;
}

// ── Page render ──────────────────────────────────────────────────
$pageTitle    = 'Reports & Exports';
$pageSubtitle = 'Download data exports and summary reports';
$activePage   = 'dir-reports';

// Quick stats for display
$placementCount = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE status!='draft'")->fetchColumn();
$visitCount     = (int)$pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();
$evalCount      = 0;
try { $evalCount = (int)$pdo->query("SELECT COUNT(*) FROM provider_evaluations")->fetchColumn(); } catch (Exception $e) {}
$atRiskCount    = 0;
try { $atRiskCount = (int)$pdo->query("SELECT COUNT(*) FROM placements WHERE risk_flag=1")->fetchColumn(); } catch (Exception $e) {}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start;">

            <!-- Export cards -->
            <div style="display:flex;flex-direction:column;gap:1.25rem;">

                <?php
                $exports = [
                    [
                        'key'   => 'placements',
                        'icon'  => '🏢',
                        'title' => 'All Placements',
                        'desc'  => 'Complete list of all placement records including student, company, role, dates, status and assigned tutor.',
                        'count' => $placementCount . ' records',
                        'color' => '#0c1b33',
                    ],
                    [
                        'key'   => 'summary',
                        'icon'  => '📊',
                        'title' => 'Summary by Sector',
                        'desc'  => 'Aggregate summary — total, active, pending and rejected placements grouped by industry sector.',
                        'count' => 'Aggregated',
                        'color' => '#2563eb',
                    ],
                    [
                        'key'   => 'visits',
                        'icon'  => '🗓',
                        'title' => 'Visit Log',
                        'desc'  => 'All scheduled and completed tutor visits with student, company, date, purpose and status.',
                        'count' => $visitCount . ' records',
                        'color' => '#059669',
                    ],
                    [
                        'key'   => 'at_risk',
                        'icon'  => '⚠️',
                        'title' => 'At-Risk Students',
                        'desc'  => 'Students flagged as at-risk, with risk level, notes and who raised the flag.',
                        'count' => $atRiskCount . ' flagged',
                        'color' => '#dc2626',
                    ],
                    [
                        'key'   => 'evaluations',
                        'icon'  => '⭐',
                        'title' => 'Employer Evaluations',
                        'desc'  => 'Provider performance evaluations including all rating criteria and comments.',
                        'count' => $evalCount . ' submitted',
                        'color' => '#d97706',
                    ],
                ];
                foreach ($exports as $exp):
                ?>
                <div class="panel" style="margin-bottom:0;">
                    <div style="padding:1.5rem;display:flex;align-items:center;gap:1.5rem;">
                        <div style="width:56px;height:56px;border-radius:12px;background:<?= $exp['color'] ?>18;
                                    display:flex;align-items:center;justify-content:center;font-size:1.75rem;flex-shrink:0;">
                            <?= $exp['icon'] ?>
                        </div>
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.25rem;">
                                <h4 style="font-size:1rem;font-weight:700;color:var(--navy);"><?= $exp['title'] ?></h4>
                                <span style="font-size:0.75rem;background:#f3f4f6;color:#6b7280;padding:0.15rem 0.6rem;border-radius:20px;">
                                    <?= $exp['count'] ?>
                                </span>
                            </div>
                            <p style="font-size:0.875rem;color:var(--muted);"><?= $exp['desc'] ?></p>
                        </div>
                        <a href="reports.php?export=<?= $exp['key'] ?>"
                           class="btn btn-primary btn-sm" style="white-space:nowrap;flex-shrink:0;">
                            📥 Download CSV
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>

            <!-- Right: info panel -->
            <div style="position:sticky;top:1.5rem;">
                <div class="panel">
                    <div class="panel-header"><h3>About Exports</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;flex-direction:column;gap:1rem;font-size:0.875rem;">
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">📂 Format</p>
                                <p style="color:var(--muted);">All files are exported as <strong>CSV</strong> with UTF-8 BOM encoding, compatible with Microsoft Excel, Google Sheets and LibreOffice Calc.</p>
                            </div>
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">🔒 Read-Only</p>
                                <p style="color:var(--muted);">Downloads are for reporting purposes only. No data is modified when exporting.</p>
                            </div>
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">🕒 Real-Time</p>
                                <p style="color:var(--muted);">Each download reflects current live data at the time of export.</p>
                            </div>
                            <p style="color:var(--muted);font-size:0.8125rem;">
                                For GDPR compliance, ensure exported files are handled in accordance with your institution's data protection policy.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
