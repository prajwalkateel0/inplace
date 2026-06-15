<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'Cycle Settings';
$pageSubtitle = 'Academic year, report deadlines and configuration';
$activePage   = 'tutor-settings';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor','awaiting_provider')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Ensure table exists ──────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tutor_settings (
        setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT         NOT NULL DEFAULT '',
        updated_by    INT          DEFAULT NULL,
        updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Defaults ─────────────────────────────────────────────────────
$defaults = [
    'cycle_label'                   => '2025/26',
    'cycle_start_date'              => '',
    'cycle_end_date'                => '',
    'interim_report_months'         => '4',
    'final_report_months_before'    => '1',
    'deadline_reminder_days'        => '14',
    'max_placement_months'          => '12',
    'min_placement_months'          => '6',
    'allowed_sectors'               => '',
    'placement_notes'               => '',
];

// Seed defaults
$ins = $pdo->prepare("INSERT IGNORE INTO tutor_settings (setting_key, setting_value) VALUES (?,?)");
foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

$flash = ['msg'=>'','type'=>''];

// ── POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upsert = $pdo->prepare("
        INSERT INTO tutor_settings (setting_key, setting_value, updated_by, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()
    ");
    foreach (array_keys($defaults) as $key) {
        $val = trim($_POST[$key] ?? '');
        $upsert->execute([$key, $val, $userId]);
    }
    $flash = ['msg'=>'Settings saved successfully.','type'=>'success'];
}

// ── Load current values ──────────────────────────────────────────
$stmt = $pdo->query("SELECT setting_key, setting_value FROM tutor_settings");
$cfg  = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cfg[$r['setting_key']] = $r['setting_value'];
$cfg  = array_merge($defaults, $cfg);

function tsVal(array $cfg, string $key): string {
    return htmlspecialchars($cfg[$key] ?? '');
}

// ── Compute preview deadlines ────────────────────────────────────
$previewRows = [];
if ($cfg['cycle_start_date'] && $cfg['cycle_end_date']) {
    $cs  = new DateTime($cfg['cycle_start_date']);
    $ce  = new DateTime($cfg['cycle_end_date']);
    $im  = max(1, (int)$cfg['interim_report_months']);
    $fm  = max(1, (int)$cfg['final_report_months_before']);
    $rd  = max(1, (int)$cfg['deadline_reminder_days']);

    $interimDue = (clone $cs)->modify("+{$im} months");
    $finalDue   = (clone $ce)->modify("-{$fm} months");
    $interimReminder = (clone $interimDue)->modify("-{$rd} days");
    $finalReminder   = (clone $finalDue)->modify("-{$rd} days");

    $previewRows = [
        ['Cycle Start',             $cs->format('d M Y'),              '🟢'],
        ['Interim Report Due',      $interimDue->format('d M Y'),      '📋'],
        ['Interim Reminder Sent',   $interimReminder->format('d M Y'), '🔔'],
        ['Final Report Due',        $finalDue->format('d M Y'),        '📝'],
        ['Final Reminder Sent',     $finalReminder->format('d M Y'),   '🔔'],
        ['Cycle End',               $ce->format('d M Y'),              '🏁'],
    ];
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($flash['msg']): ?>
        <div style="background:var(--<?= $flash['type'] ?>-bg);border:1px solid #6ee7b7;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $flash['type'] ?>);font-weight:500;">✅ <?= htmlspecialchars($flash['msg']) ?></p>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;">

            <!-- ── Left: Settings form ───────────────────────── -->
            <form method="POST">
                <!-- Academic Cycle -->
                <div class="panel" style="margin-bottom:1.5rem;">
                    <div class="panel-header">
                        <div><h3>Academic Cycle</h3><p>Define the current placement year boundaries</p></div>
                    </div>
                    <div class="panel-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Cycle Label</label>
                                <input type="text" name="cycle_label"
                                       placeholder="e.g., 2025/26"
                                       value="<?= tsVal($cfg,'cycle_label') ?>">
                                <small style="color:var(--muted);">Displayed on headers and reports.</small>
                            </div>
                            <div class="form-group"></div>
                            <div class="form-group">
                                <label>Cycle Start Date</label>
                                <input type="date" name="cycle_start_date"
                                       value="<?= tsVal($cfg,'cycle_start_date') ?>">
                            </div>
                            <div class="form-group">
                                <label>Cycle End Date</label>
                                <input type="date" name="cycle_end_date"
                                       value="<?= tsVal($cfg,'cycle_end_date') ?>">
                            </div>
                            <div class="form-group">
                                <label>Minimum Placement Length (months)</label>
                                <input type="number" name="min_placement_months" min="1" max="24"
                                       value="<?= tsVal($cfg,'min_placement_months') ?>">
                            </div>
                            <div class="form-group">
                                <label>Maximum Placement Length (months)</label>
                                <input type="number" name="max_placement_months" min="1" max="24"
                                       value="<?= tsVal($cfg,'max_placement_months') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Deadlines -->
                <div class="panel" style="margin-bottom:1.5rem;">
                    <div class="panel-header">
                        <div><h3>Report Deadlines</h3><p>Offsets are calculated relative to each student's placement dates</p></div>
                    </div>
                    <div class="panel-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Interim Report Due (months after start)</label>
                                <input type="number" name="interim_report_months" min="1" max="12"
                                       value="<?= tsVal($cfg,'interim_report_months') ?>">
                                <small style="color:var(--muted);">e.g., 4 → due 4 months after placement start</small>
                            </div>
                            <div class="form-group">
                                <label>Final Report Due (months before end)</label>
                                <input type="number" name="final_report_months_before" min="1" max="6"
                                       value="<?= tsVal($cfg,'final_report_months_before') ?>">
                                <small style="color:var(--muted);">e.g., 1 → due 1 month before placement end</small>
                            </div>
                            <div class="form-group">
                                <label>Reminder Email Days Before Deadline</label>
                                <input type="number" name="deadline_reminder_days" min="1" max="60"
                                       value="<?= tsVal($cfg,'deadline_reminder_days') ?>">
                                <small style="color:var(--muted);">Students receive a reminder this many days before each deadline.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Config -->
                <div class="panel" style="margin-bottom:1.5rem;">
                    <div class="panel-header">
                        <div><h3>Additional Configuration</h3></div>
                    </div>
                    <div class="panel-body">
                        <div class="form-grid">
                            <div class="form-group full-col">
                                <label>Allowed Industry Sectors (comma-separated, leave blank to allow all)</label>
                                <input type="text" name="allowed_sectors"
                                       placeholder="e.g., Technology &amp; Software, Engineering &amp; Manufacturing"
                                       value="<?= tsVal($cfg,'allowed_sectors') ?>">
                            </div>
                            <div class="form-group full-col">
                                <label>Placement Year Notes / Guidance for Students</label>
                                <textarea name="placement_notes" rows="4"
                                          placeholder="Any notes or guidance visible to students on their dashboard…"><?= tsVal($cfg,'placement_notes') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary">Save Settings →</button>
                </div>
            </form>

            <!-- ── Right: Deadline preview ───────────────────── -->
            <div style="position:sticky;top:1.5rem;">
                <div class="panel">
                    <div class="panel-header">
                        <div><h3>Deadline Preview</h3><p>Based on current cycle dates</p></div>
                    </div>
                    <?php if (empty($previewRows)): ?>
                    <div style="text-align:center;padding:2rem 1.5rem;">
                        <div style="font-size:2rem;margin-bottom:0.5rem;">📅</div>
                        <p style="color:var(--muted);font-size:0.875rem;">Set the cycle start and end dates to see a deadline preview.</p>
                    </div>
                    <?php else: ?>
                    <div style="padding:0;">
                        <?php foreach ($previewRows as [$label, $date, $icon]): ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;
                                    padding:0.875rem 1.5rem;border-bottom:1px solid var(--border);">
                            <div style="display:flex;align-items:center;gap:0.6rem;">
                                <span><?= $icon ?></span>
                                <span style="font-size:0.875rem;color:var(--text);"><?= htmlspecialchars($label) ?></span>
                            </div>
                            <span style="font-size:0.875rem;font-weight:600;color:var(--navy);
                                         font-family:'DM Mono',monospace;">
                                <?= $date ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="padding:1rem 1.5rem;background:var(--cream);border-radius:0 0 var(--radius) var(--radius);">
                        <p style="font-size:0.78rem;color:var(--muted);">
                            These dates apply to the cycle as a whole. Each student's individual deadlines are offset from their own start/end dates using the same month offsets above.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Current cycle summary -->
                <div class="panel" style="margin-top:1rem;">
                    <div class="panel-header"><h3>Active Cycle</h3></div>
                    <div class="panel-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <label>Label</label>
                                <p style="font-weight:700;color:var(--navy);"><?= tsVal($cfg,'cycle_label') ?: '—' ?></p>
                            </div>
                            <div class="info-item">
                                <label>Duration</label>
                                <p><?= $cfg['min_placement_months'] ?>–<?= $cfg['max_placement_months'] ?> months</p>
                            </div>
                            <div class="info-item">
                                <label>Interim Report</label>
                                <p>Month <?= tsVal($cfg,'interim_report_months') ?></p>
                            </div>
                            <div class="info-item">
                                <label>Final Report</label>
                                <p><?= tsVal($cfg,'final_report_months_before') ?> month(s) before end</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
