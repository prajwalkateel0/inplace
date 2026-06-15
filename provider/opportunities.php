<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Placement Opportunities';
$pageSubtitle = 'Post and manage available placement roles for future students';
$activePage   = 'opportunities';
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

// Company info
$stmt = $pdo->prepare("SELECT name, city, sector FROM companies WHERE id=?");
$stmt->execute([$companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Ensure table ─────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS placement_opportunities (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        company_id       INT          NOT NULL,
        posted_by        INT          NOT NULL,
        title            VARCHAR(200) NOT NULL,
        description      TEXT         DEFAULT NULL,
        requirements     TEXT         DEFAULT NULL,
        salary_range     VARCHAR(100) DEFAULT NULL,
        start_date_est   DATE         DEFAULT NULL,
        duration_months  TINYINT      DEFAULT NULL,
        positions        TINYINT      DEFAULT 1,
        skills_required  VARCHAR(500) DEFAULT NULL,
        is_active        TINYINT(1)   DEFAULT 1,
        deadline         DATE         DEFAULT NULL,
        created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$flash = ['msg' => '', 'type' => ''];

// ── POST: create / update / toggle / delete ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opp_action'])) {
    $oppAction = $_POST['opp_action'];
    $oppId     = (int)($_POST['opp_id'] ?? 0);

    if (in_array($oppAction, ['create', 'update'])) {
        $title       = trim($_POST['title']           ?? '');
        $description = trim($_POST['description']     ?? '');
        $requirements = trim($_POST['requirements']   ?? '');
        $salary      = trim($_POST['salary_range']    ?? '');
        $startEst    = trim($_POST['start_date_est']  ?? '');
        $duration    = max(1, (int)($_POST['duration_months'] ?? 12));
        $positions   = max(1, (int)($_POST['positions']       ?? 1));
        $skills      = trim($_POST['skills_required'] ?? '');
        $deadline    = trim($_POST['deadline']        ?? '');

        if (!$title) {
            $flash = ['msg' => 'Title is required.', 'type' => 'danger'];
        } elseif ($oppAction === 'create') {
            $pdo->prepare("
                INSERT INTO placement_opportunities
                  (company_id, posted_by, title, description, requirements, salary_range,
                   start_date_est, duration_months, positions, skills_required, deadline)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([$companyId, $userId, $title, $description, $requirements, $salary,
                         $startEst ?: null, $duration, $positions, $skills, $deadline ?: null]);
            // Notify all approved students
            $companyName = $company['name'] ?? 'A company';
            $notifMsg = "💡 New placement opportunity posted by {$companyName}: {$title}"
                      . ($duration ? " ({$duration} months)" : '')
                      . ($skills ? " — Skills: {$skills}" : '');
            try {
                $allStudents = $pdo->query("SELECT id FROM users WHERE role='student' AND approval_status='approved'");
                $insertNotif = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'opportunity', ?)");
                foreach ($allStudents->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                    $insertNotif->execute([$sid, $notifMsg]);
                }
            } catch (Exception $e) { error_log('Opportunity notification: ' . $e->getMessage()); }

            $flash = ['msg' => 'Opportunity posted successfully. All students have been notified.', 'type' => 'success'];
        } else {
            // Verify ownership
            $pdo->prepare("
                UPDATE placement_opportunities
                SET title=?, description=?, requirements=?, salary_range=?,
                    start_date_est=?, duration_months=?, positions=?, skills_required=?, deadline=?
                WHERE id=? AND company_id=?
            ")->execute([$title, $description, $requirements, $salary,
                         $startEst ?: null, $duration, $positions, $skills, $deadline ?: null,
                         $oppId, $companyId]);
            $flash = ['msg' => 'Opportunity updated.', 'type' => 'success'];
        }
    } elseif ($oppAction === 'toggle' && $oppId) {
        $pdo->prepare("UPDATE placement_opportunities SET is_active = NOT is_active WHERE id=? AND company_id=?")
            ->execute([$oppId, $companyId]);
        $flash = ['msg' => 'Status updated.', 'type' => 'success'];
    } elseif ($oppAction === 'delete' && $oppId) {
        $pdo->prepare("DELETE FROM placement_opportunities WHERE id=? AND company_id=?")->execute([$oppId, $companyId]);
        $flash = ['msg' => 'Opportunity removed.', 'type' => 'success'];
    }
}

// ── Load opportunities ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM placement_opportunities WHERE company_id=?
    ORDER BY is_active DESC, created_at DESC
");
$stmt->execute([$companyId]);
$opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);

function hs($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>
    <div class="page-content">

        <?php if ($flash['msg']): ?>
        <div style="background:var(--<?= $flash['type'] ?>-bg);
                    border:1px solid <?= $flash['type']==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $flash['type'] ?>);font-weight:500;">
                <?= htmlspecialchars($flash['msg']) ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <?php
        $activeCount   = count(array_filter($opportunities, fn($o) => $o['is_active']));
        $inactiveCount = count($opportunities) - $activeCount;
        $expiredCount  = count(array_filter($opportunities, fn($o) => $o['deadline'] && $o['deadline'] < date('Y-m-d')));
        ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:1.5rem;">
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Active Postings</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--success);"><?= $activeCount ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Inactive / Filled</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--muted);"><?= $inactiveCount ?></h3>
            </div>
            <div class="panel" style="margin-bottom:0;padding:1.25rem 1.5rem;">
                <p style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:0.4rem;">Past Deadline</p>
                <h3 style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--warning);"><?= $expiredCount ?></h3>
            </div>
        </div>

        <!-- List -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3>Placement Opportunities</h3>
                    <p><?= hs($company['name']) ?> · <?= hs($company['sector'] ?? 'All sectors') ?></p>
                </div>
                <button onclick="openOppModal()" class="btn btn-primary btn-sm">+ Post Opportunity</button>
            </div>

            <?php if (empty($opportunities)): ?>
            <div style="text-align:center;padding:4rem 2rem;">
                <div style="font-size:3rem;margin-bottom:1rem;">💼</div>
                <h3 style="color:var(--navy);margin-bottom:0.5rem;">No opportunities posted yet</h3>
                <p style="color:var(--muted);margin-bottom:1.5rem;">
                    Post placement opportunities to let students know about available roles.
                </p>
                <button onclick="openOppModal()" class="btn btn-primary">Post First Opportunity →</button>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:0;">
                <?php foreach ($opportunities as $opp):
                    $isExpired = $opp['deadline'] && $opp['deadline'] < date('Y-m-d');
                    $borderColor = !$opp['is_active'] ? '#d1d5db' : ($isExpired ? '#fbbf24' : '#059669');
                ?>
                <div style="border-left:4px solid <?= $borderColor ?>;padding:1.25rem 1.5rem;
                             border-bottom:1px solid var(--border);">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                        <div style="flex:1;">
                            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.4rem;">
                                <h4 style="font-size:1.0625rem;color:var(--navy);font-weight:700;">
                                    <?= hs($opp['title']) ?>
                                </h4>
                                <?php if (!$opp['is_active']): ?>
                                <span class="badge" style="background:#f3f4f6;color:#6b7280;">Inactive</span>
                                <?php elseif ($isExpired): ?>
                                <span class="badge badge-pending">Deadline Passed</span>
                                <?php else: ?>
                                <span class="badge badge-approved">Active</span>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;font-size:0.8125rem;color:var(--muted);margin-bottom:0.75rem;">
                                <?php if ($opp['duration_months']): ?>
                                <span>📅 <?= $opp['duration_months'] ?> months</span>
                                <?php endif; ?>
                                <?php if ($opp['positions']): ?>
                                <span>👥 <?= $opp['positions'] ?> position<?= $opp['positions'] > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                                <?php if ($opp['salary_range']): ?>
                                <span>💷 <?= hs($opp['salary_range']) ?></span>
                                <?php endif; ?>
                                <?php if ($opp['start_date_est']): ?>
                                <span>🗓 Est. start: <?= date('M Y', strtotime($opp['start_date_est'])) ?></span>
                                <?php endif; ?>
                                <?php if ($opp['deadline']): ?>
                                <span>⏰ Apply by <?= date('d M Y', strtotime($opp['deadline'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($opp['description']): ?>
                            <p style="font-size:0.875rem;color:var(--text);line-height:1.6;margin-bottom:0.5rem;">
                                <?= nl2br(hs($opp['description'])) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($opp['skills_required']): ?>
                            <p style="font-size:0.8125rem;color:var(--muted);">
                                <strong>Skills:</strong> <?= hs($opp['skills_required']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:0.4rem;min-width:120px;">
                            <button class="btn btn-ghost btn-sm"
                                    onclick="openOppModal(<?= htmlspecialchars(json_encode($opp)) ?>)">
                                ✏️ Edit
                            </button>
                            <form method="POST" style="display:contents;">
                                <input type="hidden" name="opp_action" value="toggle">
                                <input type="hidden" name="opp_id" value="<?= $opp['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm">
                                    <?= $opp['is_active'] ? '⏸ Deactivate' : '▶ Activate' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:contents;"
                                  onsubmit="return confirm('Delete this opportunity?')">
                                <input type="hidden" name="opp_action" value="delete">
                                <input type="hidden" name="opp_id" value="<?= $opp['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Create/Edit Modal -->
<div id="oppModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);
     z-index:1000;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:640px;margin:auto;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="background:#0c1b33;padding:1.5rem 2rem;border-radius:16px 16px 0 0;
                    display:flex;align-items:center;justify-content:space-between;">
            <h3 style="color:#fff;font-family:'Playfair Display',serif;font-size:1.2rem;margin:0;"
                id="oppModalTitle">Post Placement Opportunity</h3>
            <button onclick="document.getElementById('oppModal').style.display='none'"
                    style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;">✕</button>
        </div>
        <form method="POST" style="padding:2rem;">
            <input type="hidden" name="opp_action" id="oppActionInput" value="create">
            <input type="hidden" name="opp_id"     id="oppIdInput"     value="0">

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Role Title <span style="color:var(--danger);">*</span></label>
                <input type="text" name="title" id="oppTitle" required
                       placeholder="e.g. Software Developer Placement"
                       style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                              width:100%;font-family:inherit;font-size:0.9375rem;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
                <div class="form-group">
                    <label>Number of Positions</label>
                    <input type="number" name="positions" id="oppPositions" min="1" max="50" value="1"
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
                <div class="form-group">
                    <label>Duration (months)</label>
                    <input type="number" name="duration_months" id="oppDuration" min="1" max="24" value="12"
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
                <div class="form-group">
                    <label>Estimated Start Date</label>
                    <input type="date" name="start_date_est" id="oppStartDate"
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
                <div class="form-group">
                    <label>Application Deadline</label>
                    <input type="date" name="deadline" id="oppDeadline"
                           style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                  width:100%;font-family:inherit;font-size:0.9375rem;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Salary / Remuneration</label>
                <input type="text" name="salary_range" id="oppSalary"
                       placeholder="e.g. £18,000–£22,000 per year, or Competitive"
                       style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                              width:100%;font-family:inherit;font-size:0.9375rem;">
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Description</label>
                <textarea name="description" id="oppDescription" rows="4"
                          placeholder="Describe the role, day-to-day responsibilities, team, and work environment…"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label>Requirements / Preferred Degree Subjects</label>
                <textarea name="requirements" id="oppRequirements" rows="2"
                          placeholder="e.g. Computer Science, Engineering, any STEM discipline…"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Key Skills Required</label>
                <input type="text" name="skills_required" id="oppSkills"
                       placeholder="e.g. Python, teamwork, problem-solving, Microsoft Office"
                       style="padding:0.75rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                              width:100%;font-family:inherit;font-size:0.9375rem;">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                <button type="button" onclick="document.getElementById('oppModal').style.display='none'"
                        class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" id="oppSubmitBtn">Post Opportunity →</button>
            </div>
        </form>
    </div>
</div>

<script>
function openOppModal(opp) {
    const isEdit = opp !== undefined;
    document.getElementById('oppModalTitle').textContent = isEdit ? 'Edit Opportunity' : 'Post Placement Opportunity';
    document.getElementById('oppActionInput').value = isEdit ? 'update' : 'create';
    document.getElementById('oppSubmitBtn').textContent  = isEdit ? 'Save Changes →' : 'Post Opportunity →';
    document.getElementById('oppIdInput').value     = opp?.id     || 0;
    document.getElementById('oppTitle').value       = opp?.title  || '';
    document.getElementById('oppDescription').value = opp?.description || '';
    document.getElementById('oppRequirements').value = opp?.requirements || '';
    document.getElementById('oppSalary').value      = opp?.salary_range || '';
    document.getElementById('oppStartDate').value   = opp?.start_date_est || '';
    document.getElementById('oppDeadline').value    = opp?.deadline || '';
    document.getElementById('oppDuration').value    = opp?.duration_months || 12;
    document.getElementById('oppPositions').value   = opp?.positions || 1;
    document.getElementById('oppSkills').value      = opp?.skills_required || '';
    document.getElementById('oppModal').style.display = 'flex';
}
document.getElementById('oppModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include '../includes/footer.php'; ?>
