<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('provider');

$pageTitle    = 'Student Evaluations';
$pageSubtitle = 'Submit performance feedback for your placement students';
$activePage   = 'evaluate';
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

// ── Ensure evaluations table ─────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS provider_evaluations (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        placement_id        INT  NOT NULL,
        provider_user_id    INT  NOT NULL,
        eval_period         ENUM('interim','final','ad_hoc') DEFAULT 'ad_hoc',
        attendance          TINYINT(1) DEFAULT NULL COMMENT '1-5 rating',
        punctuality         TINYINT(1) DEFAULT NULL,
        professionalism     TINYINT(1) DEFAULT NULL,
        technical_skills    TINYINT(1) DEFAULT NULL,
        communication       TINYINT(1) DEFAULT NULL,
        initiative          TINYINT(1) DEFAULT NULL,
        overall_rating      TINYINT(1) DEFAULT NULL,
        strengths           TEXT DEFAULT NULL,
        areas_for_improvement TEXT DEFAULT NULL,
        additional_comments TEXT DEFAULT NULL,
        recommend_future    TINYINT(1) DEFAULT NULL,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_placement_period (placement_id, eval_period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$flash = ['msg' => '', 'type' => ''];

// ── POST: save evaluation ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eval_action'])) {
    $placementId = (int)($_POST['placement_id'] ?? 0);
    $period      = in_array($_POST['eval_period'] ?? '', ['interim','final','ad_hoc'])
                   ? $_POST['eval_period'] : 'ad_hoc';

    // Verify placement belongs to this company
    $chk = $pdo->prepare("SELECT id FROM placements WHERE id=? AND company_id=?");
    $chk->execute([$placementId, $companyId]);
    if ($chk->fetch()) {
        $rating = fn($k) => max(1, min(5, (int)($_POST[$k] ?? 3)));
        $pdo->prepare("
            INSERT INTO provider_evaluations
              (placement_id, provider_user_id, eval_period,
               attendance, punctuality, professionalism, technical_skills,
               communication, initiative, overall_rating,
               strengths, areas_for_improvement, additional_comments, recommend_future)
            VALUES (?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?)
            ON DUPLICATE KEY UPDATE
              attendance=VALUES(attendance), punctuality=VALUES(punctuality),
              professionalism=VALUES(professionalism), technical_skills=VALUES(technical_skills),
              communication=VALUES(communication), initiative=VALUES(initiative),
              overall_rating=VALUES(overall_rating), strengths=VALUES(strengths),
              areas_for_improvement=VALUES(areas_for_improvement),
              additional_comments=VALUES(additional_comments),
              recommend_future=VALUES(recommend_future), updated_at=NOW()
        ")->execute([
            $placementId, $userId, $period,
            $rating('attendance'), $rating('punctuality'), $rating('professionalism'),
            $rating('technical_skills'), $rating('communication'), $rating('initiative'),
            $rating('overall_rating'),
            trim($_POST['strengths'] ?? ''), trim($_POST['areas_for_improvement'] ?? ''),
            trim($_POST['additional_comments'] ?? ''),
            isset($_POST['recommend_future']) ? 1 : 0,
        ]);
        // Notify the student
        $stud = $pdo->prepare("SELECT student_id FROM placements WHERE id=?");
        $stud->execute([$placementId]);
        $studentId = (int)($stud->fetchColumn() ?: 0);
        if ($studentId) {
            $stars     = $rating('overall_rating');
            $starStr   = str_repeat('★', $stars) . str_repeat('☆', 5 - $stars);
            $periodLbl = ucfirst($period);
            try {
                $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'evaluation', ?)")
                    ->execute([$studentId,
                        "Your {$periodLbl} evaluation has been submitted by your employer. Overall rating: {$starStr} ({$stars}/5)."
                    ]);
            } catch (Exception $e) { error_log('Eval notification: ' . $e->getMessage()); }
        }

        $flash = ['msg' => 'Evaluation saved successfully.', 'type' => 'success'];
    }
}

// ── Load active students ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.id AS placement_id, p.role_title, p.start_date, p.end_date, p.status,
           u.full_name AS student_name, u.email AS student_email, u.avatar_initials,
           e_i.overall_rating AS interim_rating, e_i.created_at AS interim_date,
           e_f.overall_rating AS final_rating,   e_f.created_at AS final_date
    FROM placements p
    JOIN users u ON p.student_id = u.id
    LEFT JOIN provider_evaluations e_i ON e_i.placement_id=p.id AND e_i.eval_period='interim'
    LEFT JOIN provider_evaluations e_f ON e_f.placement_id=p.id AND e_f.eval_period='final'
    WHERE p.company_id=? AND p.status IN ('approved','active')
    ORDER BY u.full_name
");
$stmt->execute([$companyId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load existing evaluation for pre-fill
$prefill = null;
if ($_GET['placement_id'] ?? null) {
    $pid = (int)$_GET['placement_id'];
    $per = in_array($_GET['period'] ?? '', ['interim','final','ad_hoc']) ? $_GET['period'] : 'ad_hoc';
    $stmt = $pdo->prepare("SELECT * FROM provider_evaluations WHERE placement_id=? AND eval_period=?");
    $stmt->execute([$pid, $per]);
    $prefill = $stmt->fetch(PDO::FETCH_ASSOC);
}

function starRating(string $name, int $val = 3): string {
    $html = "<div style='display:flex;gap:0.25rem;align-items:center;'>";
    for ($i = 1; $i <= 5; $i++) {
        $html .= "<label style='cursor:pointer;font-size:1.4rem;color:" . ($i <= $val ? '#f59e0b' : '#d1d5db') . ";'
                        title='$i'>"
               . "<input type='radio' name='$name' value='$i' " . ($i === $val ? 'checked' : '') . "
                         style='display:none;' onchange='updateStars(this)'>"
               . "★</label>";
    }
    $html .= "<span style='margin-left:0.5rem;font-size:0.875rem;color:var(--muted);'>"
           . "(1 = Poor, 5 = Excellent)</span></div>";
    return $html;
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

        <div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">

            <!-- Left: student list -->
            <div>
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Active Students</h3>
                            <p>Click a student to submit or update their evaluation</p>
                        </div>
                    </div>
                    <?php if (empty($students)): ?>
                    <div style="text-align:center;padding:3rem 2rem;">
                        <div style="font-size:2.5rem;margin-bottom:0.75rem;">👥</div>
                        <p style="color:var(--muted);">No active students to evaluate.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Role</th>
                                    <th>Interim</th>
                                    <th>Final</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $s): ?>
                                <tr>
                                    <td>
                                        <div class="avatar-cell">
                                            <div class="avatar"><?= htmlspecialchars($s['avatar_initials'] ?? '??') ?></div>
                                            <div>
                                                <h4><?= htmlspecialchars($s['student_name']) ?></h4>
                                                <p style="font-size:0.78rem;"><?= htmlspecialchars($s['student_email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="type-chip"><?= htmlspecialchars($s['role_title'] ?? '—') ?></span></td>
                                    <td>
                                        <?php if ($s['interim_rating']): ?>
                                        <span style="color:#f59e0b;font-weight:700;"><?= str_repeat('★', $s['interim_rating']) ?></span>
                                        <?php else: ?>
                                        <span style="color:var(--muted);font-size:0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['final_rating']): ?>
                                        <span style="color:#f59e0b;font-weight:700;"><?= str_repeat('★', $s['final_rating']) ?></span>
                                        <?php else: ?>
                                        <span style="color:var(--muted);font-size:0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                                            <button class="btn btn-primary btn-sm"
                                                    onclick="openEvalModal(<?= $s['placement_id'] ?>, '<?= htmlspecialchars(addslashes($s['student_name'])) ?>', 'interim')">
                                                Interim
                                            </button>
                                            <button class="btn btn-ghost btn-sm"
                                                    onclick="openEvalModal(<?= $s['placement_id'] ?>, '<?= htmlspecialchars(addslashes($s['student_name'])) ?>', 'final')">
                                                Final
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: info panel -->
            <div style="position:sticky;top:1.5rem;">
                <div class="panel">
                    <div class="panel-header"><h3>About Evaluations</h3></div>
                    <div class="panel-body">
                        <div style="display:flex;flex-direction:column;gap:1rem;">
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">📋 Interim</p>
                                <p style="font-size:0.875rem;color:var(--muted);">Mid-placement review — typically around month 4. Identifies strengths and areas for improvement early.</p>
                            </div>
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">🏁 Final</p>
                                <p style="font-size:0.875rem;color:var(--muted);">End-of-placement summary. This forms part of the student's academic record.</p>
                            </div>
                            <div style="padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                                <p style="font-weight:600;color:var(--navy);margin-bottom:0.25rem;">⭐ Ratings</p>
                                <p style="font-size:0.875rem;color:var(--muted);">1 = Poor &nbsp;·&nbsp; 3 = Meets expectations &nbsp;·&nbsp; 5 = Excellent</p>
                            </div>
                            <p style="font-size:0.8125rem;color:var(--muted);">Evaluations are shared with the student's tutor and contribute to their placement year assessment.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Evaluation Modal -->
<div id="evalModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);
     z-index:1000;align-items:flex-start;justify-content:center;padding:1rem;overflow-y:auto;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:640px;margin:auto;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="background:#0c1b33;padding:1.5rem 2rem;border-radius:16px 16px 0 0;
                    display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3 style="color:#fff;font-family:'Playfair Display',serif;font-size:1.2rem;margin:0;"
                    id="evalModalTitle">Evaluation</h3>
                <p style="color:rgba(255,255,255,0.65);font-size:0.85rem;margin:0.2rem 0 0;"
                   id="evalModalSubtitle"></p>
            </div>
            <button onclick="document.getElementById('evalModal').style.display='none'"
                    style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;">✕</button>
        </div>
        <form method="POST" style="padding:2rem;">
            <input type="hidden" name="eval_action" value="save">
            <input type="hidden" name="placement_id" id="evalPlacementId">
            <input type="hidden" name="eval_period"  id="evalPeriod">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;">
                <?php
                $criteria = [
                    'attendance'       => 'Attendance',
                    'punctuality'      => 'Punctuality',
                    'professionalism'  => 'Professionalism',
                    'technical_skills' => 'Technical Skills',
                    'communication'    => 'Communication',
                    'initiative'       => 'Initiative',
                ];
                foreach ($criteria as $key => $label):
                ?>
                <div>
                    <label style="font-weight:600;font-size:0.875rem;color:#374151;margin-bottom:0.4rem;display:block;">
                        <?= $label ?>
                    </label>
                    <div class="star-group" data-field="<?= $key ?>" style="display:flex;gap:0.15rem;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label style="cursor:pointer;font-size:1.6rem;color:#d1d5db;transition:color 0.15s;"
                               class="star-label">
                            <input type="radio" name="<?= $key ?>" value="<?= $i ?>"
                                   <?= $i === 3 ? 'checked' : '' ?>
                                   style="display:none;">
                            ★
                        </label>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="font-weight:700;font-size:0.9375rem;color:#0c1b33;margin-bottom:0.4rem;display:block;">
                    Overall Rating
                </label>
                <div class="star-group" data-field="overall_rating" style="display:flex;gap:0.2rem;">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <label style="cursor:pointer;font-size:2rem;color:#d1d5db;transition:color 0.15s;" class="star-label">
                        <input type="radio" name="overall_rating" value="<?= $i ?>"
                               <?= $i === 3 ? 'checked' : '' ?> style="display:none;">
                        ★
                    </label>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1rem;">
                <label>Key Strengths</label>
                <textarea name="strengths" rows="3"
                          placeholder="What does the student do particularly well?"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:1rem;">
                <label>Areas for Improvement</label>
                <textarea name="areas_for_improvement" rows="3"
                          placeholder="Where can the student develop further?"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:1.5rem;">
                <label>Additional Comments</label>
                <textarea name="additional_comments" rows="2"
                          placeholder="Any other observations…"
                          style="padding:0.875rem 1rem;border:2px solid var(--border);border-radius:var(--radius-sm);
                                 width:100%;font-family:inherit;font-size:0.9375rem;resize:vertical;"></textarea>
            </div>

            <label style="display:flex;align-items:center;gap:0.75rem;cursor:pointer;margin-bottom:1.5rem;
                          padding:1rem;background:var(--cream);border-radius:var(--radius-sm);">
                <input type="checkbox" name="recommend_future" value="1" style="width:18px;height:18px;">
                <span style="font-weight:600;color:var(--navy);">
                    I would recommend accepting future students from this programme
                </span>
            </label>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;">
                <button type="button" onclick="document.getElementById('evalModal').style.display='none'"
                        class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Evaluation →</button>
            </div>
        </form>
    </div>
</div>

<style>
.star-group:hover .star-label { color:#fbbf24; }
.star-group .star-label:hover ~ .star-label { color:#d1d5db; }
.star-group input:checked ~ label.star-label { color:#d1d5db; }
</style>
<script>
// Initialise star colours from checked radio
document.querySelectorAll('.star-group').forEach(group => {
    const labels = group.querySelectorAll('.star-label');
    function refresh() {
        let checked = 0;
        group.querySelectorAll('input[type=radio]').forEach((r,i) => { if (r.checked) checked = i+1; });
        labels.forEach((l,i) => l.style.color = i < checked ? '#f59e0b' : '#d1d5db');
    }
    group.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', refresh));
    refresh();
});

function openEvalModal(placementId, studentName, period) {
    document.getElementById('evalPlacementId').value = placementId;
    document.getElementById('evalPeriod').value      = period;
    document.getElementById('evalModalTitle').textContent   = (period === 'interim' ? 'Interim' : 'Final') + ' Evaluation';
    document.getElementById('evalModalSubtitle').textContent = studentName;
    document.getElementById('evalModal').style.display = 'flex';
    // Reset stars to 3
    document.querySelectorAll('#evalModal input[type=radio][value="3"]').forEach(r => {
        r.checked = true; r.dispatchEvent(new Event('change', {bubbles:true}));
    });
}
document.getElementById('evalModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include '../includes/footer.php'; ?>
