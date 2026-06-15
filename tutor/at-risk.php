<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$pageTitle    = 'At-Risk Students';
$pageSubtitle = 'Flag and monitor students needing attention';
$activePage   = 'at-risk';
$userId       = authId();

// Sidebar badges
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM placements WHERE status IN ('submitted','awaiting_tutor','awaiting_provider')");
$pendingRequests = (int)$stmt->fetchColumn();

// ── Add risk columns to placements if missing ─────────────────────
foreach (['risk_flag TINYINT(1) DEFAULT 0', "risk_level ENUM('low','medium','high') DEFAULT NULL", 'risk_notes TEXT DEFAULT NULL', 'risk_flagged_at DATETIME DEFAULT NULL', 'risk_flagged_by INT DEFAULT NULL'] as $colDef) {
    try { $pdo->exec("ALTER TABLE placements ADD COLUMN $colDef"); } catch (Exception $e) {}
}

$flash = ['msg'=>'','type'=>''];

// ── POST: flag / unflag / update ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid    = (int)($_POST['placement_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($pid) {
        if ($action === 'flag') {
            $level = in_array($_POST['risk_level']??'', ['low','medium','high']) ? $_POST['risk_level'] : 'medium';
            $notes = trim($_POST['risk_notes'] ?? '');
            $pdo->prepare("UPDATE placements SET risk_flag=1, risk_level=?, risk_notes=?, risk_flagged_at=NOW(), risk_flagged_by=? WHERE id=?")
                ->execute([$level, $notes, $userId, $pid]);

            // Message student
            try {
                $tCol = null;
                $s2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND COLUMN_NAME IN (?,?,?)");
                $s2->execute(['created_at','sent_at','timestamp']);
                foreach(['created_at','sent_at','timestamp'] as $c){
                    if(in_array($c,$s2->fetchAll(PDO::FETCH_COLUMN),true)){$tCol=$c;break;}
                }
                $stmt = $pdo->prepare("SELECT student_id FROM placements WHERE id=?"); $stmt->execute([$pid]);
                $sid = (int)$stmt->fetchColumn();
                $msgText = "Your tutor has flagged your placement as requiring attention (" . strtoupper($level) . " priority). Please check in with your tutor." . ($notes ? " Note: $notes" : "");
                if ($sid) {
                    if ($tCol) {
                        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,body,`$tCol`,is_read) VALUES (?,?,?,NOW(),0)")->execute([$userId,$sid,$msgText]);
                    } else {
                        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,body,is_read) VALUES (?,?,?,0)")->execute([$userId,$sid,$msgText]);
                    }
                }
            } catch (Exception $e) {}

            $flash = ['msg'=>'Student flagged as at-risk. They have been notified via message.','type'=>'success'];

        } elseif ($action === 'update') {
            $level = in_array($_POST['risk_level']??'', ['low','medium','high']) ? $_POST['risk_level'] : 'medium';
            $notes = trim($_POST['risk_notes'] ?? '');
            $pdo->prepare("UPDATE placements SET risk_level=?, risk_notes=?, risk_flagged_by=?, risk_flagged_at=NOW() WHERE id=? AND risk_flag=1")
                ->execute([$level, $notes, $userId, $pid]);
            $flash = ['msg'=>'Risk flag updated.','type'=>'success'];

        } elseif ($action === 'unflag') {
            $pdo->prepare("UPDATE placements SET risk_flag=0, risk_level=NULL, risk_notes=NULL, risk_flagged_at=NULL, risk_flagged_by=NULL WHERE id=?")
                ->execute([$pid]);
            $flash = ['msg'=>'Flag removed. Student is no longer marked at-risk.','type'=>'success'];
        }
    }
}

// ── Fetch all active placements for flagging ──────────────────────
$all = $pdo->query("
    SELECT p.id, p.status, p.start_date, p.end_date, p.role_title,
           p.risk_flag, p.risk_level, p.risk_notes, p.risk_flagged_at,
           u.full_name AS student_name, u.email AS student_email, u.avatar_initials,
           c.name AS company_name, c.city AS company_city,
           fu.full_name AS flagged_by_name,
           (SELECT COUNT(*) FROM documents d WHERE d.placement_id=p.id AND d.doc_type IN ('interim_report','final_report')) AS report_count,
           (SELECT MAX(v.visit_date) FROM visits v WHERE v.placement_id=p.id AND v.status='completed') AS last_visit
    FROM placements p
    JOIN users u ON p.student_id=u.id
    JOIN companies c ON p.company_id=c.id
    LEFT JOIN users fu ON fu.id=p.risk_flagged_by
    WHERE p.status IN ('approved','active','awaiting_tutor','awaiting_provider')
    ORDER BY p.risk_flag DESC, p.risk_level ASC, u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$flagged   = array_filter($all, fn($p) => $p['risk_flag']);
$unflagged = array_filter($all, fn($p) => !$p['risk_flag']);

$riskColors = ['high'=>'#ef4444','medium'=>'#f97316','low'=>'#eab308'];
$riskBg     = ['high'=>'#fff5f5','medium'=>'#fff7ed','low'=>'#fefce8'];
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($flash['msg']): ?>
        <div style="background:var(--<?= $flash['type'] ?>-bg);border:1px solid <?= $flash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $flash['type'] ?>);font-weight:500;"><?= htmlspecialchars($flash['msg']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <?php
            $highCount = count(array_filter($flagged, fn($p)=>$p['risk_level']==='high'));
            $medCount  = count(array_filter($flagged, fn($p)=>$p['risk_level']==='medium'));
            $lowCount  = count(array_filter($flagged, fn($p)=>$p['risk_level']==='low'));
            ?>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;border-top:3px solid #ef4444;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">High Risk</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:#ef4444;"><?= $highCount ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;border-top:3px solid #f97316;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Medium Risk</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:#f97316;"><?= $medCount ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;border-top:3px solid #eab308;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Low Risk</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:#eab308;"><?= $lowCount ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Total Active</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--navy);"><?= count($all) ?></h3>
            </div>
        </div>

        <!-- ── FLAGGED STUDENTS ──────────────────────────────────── -->
        <div class="panel" style="margin-bottom:1.5rem;">
            <div class="panel-header">
                <div><h3>⚠️ Flagged Students (<?= count($flagged) ?>)</h3><p>Students currently marked as needing attention</p></div>
            </div>

            <?php if (empty($flagged)): ?>
            <div style="text-align:center;padding:3rem 2rem;">
                <div style="font-size:3rem;margin-bottom:0.75rem;">✅</div>
                <p style="color:var(--muted);">No students currently flagged as at-risk.</p>
            </div>
            <?php else: ?>
            <div style="padding:0;">
                <?php foreach ($flagged as $p):
                    $color = $riskColors[$p['risk_level']] ?? '#6b7280';
                    $bg    = $riskBg[$p['risk_level']] ?? 'var(--cream)';
                ?>
                <div style="padding:1.5rem 2rem;border-bottom:1px solid var(--border);
                            border-left:4px solid <?= $color ?>;background:<?= $bg ?>20;">
                    <div style="display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                        <div class="avatar" style="background:<?= $color ?>20;color:<?= $color ?>;flex-shrink:0;">
                            <?= htmlspecialchars($p['avatar_initials']??'??') ?>
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.25rem;">
                                <span style="font-weight:600;color:var(--navy);"><?= htmlspecialchars($p['student_name']) ?></span>
                                <span style="font-size:0.7rem;font-weight:700;
                                             background:<?= $color ?>;color:white;
                                             padding:0.15rem 0.5rem;border-radius:4px;text-transform:uppercase;">
                                    <?= $p['risk_level'] ?> RISK
                                </span>
                            </div>
                            <p style="font-size:0.8125rem;color:var(--muted);margin-bottom:0.4rem;">
                                <?= htmlspecialchars($p['company_name']) ?>
                                <?= $p['company_city']?' · '.htmlspecialchars($p['company_city']):'' ?>
                                · <?= htmlspecialchars($p['role_title']??'N/A') ?>
                            </p>
                            <?php if ($p['risk_notes']): ?>
                            <div style="background:white;border:1px solid var(--border);border-radius:6px;
                                        padding:0.625rem 0.875rem;font-size:0.875rem;color:var(--text);
                                        line-height:1.5;margin-bottom:0.5rem;">
                                <span style="font-weight:600;color:var(--navy);">Note: </span>
                                <?= nl2br(htmlspecialchars($p['risk_notes'])) ?>
                            </div>
                            <?php endif; ?>
                            <p style="font-size:0.78rem;color:var(--muted);">
                                Flagged <?= $p['risk_flagged_at'] ? date('d M Y', strtotime($p['risk_flagged_at'])) : '—' ?>
                                <?= $p['flagged_by_name'] ? ' by '.$p['flagged_by_name'] : '' ?>
                                · Reports: <?= $p['report_count'] ?>/2
                                · Last visit: <?= $p['last_visit'] ? date('d M Y',strtotime($p['last_visit'])) : 'None' ?>
                            </p>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:0.4rem;flex-shrink:0;">
                            <button class="btn btn-ghost btn-sm"
                                    onclick="openUpdate(<?= $p['id'] ?>,'<?= $p['risk_level'] ?>',<?= htmlspecialchars(json_encode($p['risk_notes']??'')) ?>)">
                                ✏️ Update
                            </button>
                            <form method="POST" onsubmit="return confirm('Remove at-risk flag for <?= htmlspecialchars(addslashes($p['student_name'])) ?>?')">
                                <input type="hidden" name="placement_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="action" value="unflag">
                                <button type="submit" class="btn btn-ghost btn-sm" style="width:100%;color:var(--success);">✅ Remove Flag</button>
                            </form>
                            <a href="/inplace/tutor/edit-placement.php?id=<?= $p['id'] ?>"
                               class="btn btn-primary btn-sm">View Placement</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── ALL ACTIVE STUDENTS (to flag) ──────────────────────── -->
        <div class="panel">
            <div class="panel-header">
                <div><h3>All Active Students (<?= count($unflagged) ?>)</h3><p>Click "Flag" to mark a student as needing attention</p></div>
            </div>

            <?php if (empty($unflagged)): ?>
            <div style="text-align:center;padding:2.5rem 2rem;">
                <p style="color:var(--muted);">All active students are currently flagged above.</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Company</th>
                            <th>Role</th>
                            <th>Dates</th>
                            <th>Reports</th>
                            <th>Last Visit</th>
                            <th>Flag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unflagged as $p): ?>
                        <tr>
                            <td>
                                <div class="avatar-cell">
                                    <div class="avatar"><?= htmlspecialchars($p['avatar_initials']??'??') ?></div>
                                    <div>
                                        <h4><?= htmlspecialchars($p['student_name']) ?></h4>
                                        <p style="font-size:0.78rem;"><?= htmlspecialchars($p['student_email']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:500;"><?= htmlspecialchars($p['company_name']) ?></div>
                                <div style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($p['company_city']??'') ?></div>
                            </td>
                            <td><span class="type-chip"><?= htmlspecialchars($p['role_title']??'N/A') ?></span></td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= date('d M Y',strtotime($p['start_date'])) ?><br>→ <?= date('d M Y',strtotime($p['end_date'])) ?>
                            </td>
                            <td style="text-align:center;">
                                <span style="font-weight:700;color:<?= $p['report_count']>=2?'var(--success)':($p['report_count']===1?'var(--warning)':'var(--danger)') ?>;">
                                    <?= $p['report_count'] ?>/2
                                </span>
                            </td>
                            <td style="font-size:0.8125rem;color:var(--muted);">
                                <?= $p['last_visit'] ? date('d M Y',strtotime($p['last_visit'])) : '—' ?>
                            </td>
                            <td>
                                <button class="btn btn-ghost btn-sm"
                                        style="border-color:#f97316;color:#f97316;"
                                        onclick="openFlag(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['student_name'])) ?>')">
                                    ⚠️ Flag
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Flag Modal -->
<div id="flagModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--white);border-radius:var(--radius);padding:2.5rem;
                width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Playfair Display',serif;font-size:1.25rem;color:var(--navy);margin-bottom:0.25rem;">
            ⚠️ Flag Student as At-Risk
        </h3>
        <p id="flagStudentName" style="color:var(--muted);font-size:0.875rem;margin-bottom:1.5rem;"></p>
        <form method="POST">
            <input type="hidden" name="action" id="flagAction" value="flag">
            <input type="hidden" name="placement_id" id="flagPid">
            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Risk Level <span style="color:var(--danger);">*</span></label>
                <select name="risk_level" id="flagLevel"
                        style="padding:0.875rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                               width:100%;font-family:inherit;font-size:0.9375rem;background:var(--cream);">
                    <option value="low">🟡 Low — Monitor, no immediate action needed</option>
                    <option value="medium" selected>🟠 Medium — Requires follow-up soon</option>
                    <option value="high">🔴 High — Immediate attention required</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Notes / Reason</label>
                <textarea name="risk_notes" id="flagNotes" rows="4"
                          placeholder="e.g., Missed two visits, not responding to emails, concerns raised by provider…"
                          style="padding:0.875rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;background:var(--cream);resize:vertical;"></textarea>
                <small style="color:var(--muted);">The student will receive a message notification.</small>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="closeFlagModal()">Cancel</button>
                <button type="submit" id="flagSubmitBtn" class="btn btn-primary">⚠️ Flag Student</button>
            </div>
        </form>
    </div>
</div>

<script>
function openFlag(pid, name) {
    document.getElementById('flagPid').value         = pid;
    document.getElementById('flagAction').value      = 'flag';
    document.getElementById('flagStudentName').textContent = name;
    document.getElementById('flagLevel').value       = 'medium';
    document.getElementById('flagNotes').value       = '';
    document.getElementById('flagSubmitBtn').textContent  = '⚠️ Flag Student';
    document.getElementById('flagModal').style.display = 'flex';
}

function openUpdate(pid, level, notes) {
    document.getElementById('flagPid').value         = pid;
    document.getElementById('flagAction').value      = 'update';
    document.getElementById('flagStudentName').textContent = 'Update risk details';
    document.getElementById('flagLevel').value       = level || 'medium';
    document.getElementById('flagNotes').value       = notes || '';
    document.getElementById('flagSubmitBtn').textContent  = 'Save Changes →';
    document.getElementById('flagModal').style.display = 'flex';
}

function closeFlagModal() {
    document.getElementById('flagModal').style.display = 'none';
}

document.getElementById('flagModal').addEventListener('click', function(e) {
    if (e.target === this) closeFlagModal();
});
</script>

<?php include '../includes/footer.php'; ?>
