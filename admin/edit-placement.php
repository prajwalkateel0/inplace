<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('admin');

$pageTitle    = 'Edit Placement';
$pageSubtitle = 'Update placement details';
$activePage   = 'placements';
$userId       = authId();
$unreadCount  = 0;
$pendingRequests = 0;

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: placements.php'); exit; }

$actionMsg  = '';
$actionType = '';

// Handle POST save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_placement'])) {
    $roleTitle      = trim($_POST['role_title']       ?? '');
    $jobDesc        = trim($_POST['job_description']  ?? '');
    $startDate      = $_POST['start_date']            ?? '';
    $endDate        = $_POST['end_date']              ?? '';
    $salary         = trim($_POST['salary']           ?? '');
    $workingPattern = trim($_POST['working_pattern']  ?? '');
    $supName        = trim($_POST['supervisor_name']  ?? '');
    $supEmail       = trim($_POST['supervisor_email'] ?? '');
    $supPhone       = trim($_POST['supervisor_phone'] ?? '');
    $status         = $_POST['status']                ?? 'submitted';
    $tutorId        = $_POST['tutor_id']              ? (int)$_POST['tutor_id'] : null;

    try {
        $stmt = $pdo->prepare("
            UPDATE placements SET
                role_title        = ?,
                job_description   = ?,
                start_date        = ?,
                end_date          = ?,
                salary            = ?,
                working_pattern   = ?,
                supervisor_name   = ?,
                supervisor_email  = ?,
                supervisor_phone  = ?,
                status            = ?,
                tutor_id          = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $roleTitle, $jobDesc, $startDate, $endDate,
            $salary, $workingPattern,
            $supName, $supEmail, $supPhone,
            $status, $tutorId, $id
        ]);
        $actionMsg  = "Placement updated successfully.";
        $actionType = 'success';
    } catch (Exception $e) {
        $actionMsg  = "Error updating placement: " . $e->getMessage();
        $actionType = 'danger';
    }
}

// Fetch placement
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS student_name, u.email AS student_email,
           c.name AS company_name
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { header('Location: placements.php'); exit; }

// Fetch tutors
$tutors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'tutor' AND is_active = 1 ORDER BY full_name")->fetchAll();

$statuses = ['submitted','awaiting_provider','awaiting_tutor','approved','active','rejected','terminated'];
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <a href="placements.php" class="btn btn-ghost btn-sm">← Back to Placements</a>
            <a href="view-placement.php?id=<?= $id ?>" class="btn btn-ghost btn-sm">View Details</a>
        </div>

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3>Edit Placement</h3>
                    <p style="color:var(--muted);font-size:0.875rem;margin:0;">
                        <?= htmlspecialchars($p['student_name']) ?> &nbsp;·&nbsp;
                        <?= htmlspecialchars($p['company_name']) ?>
                    </p>
                </div>
            </div>

            <form method="POST" style="padding:1.5rem;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

                    <div class="form-group full-col" style="grid-column:1/-1;">
                        <label>Role / Job Title <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="role_title" required
                               value="<?= htmlspecialchars($p['role_title'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Start Date <span style="color:var(--danger);">*</span></label>
                        <input type="date" name="start_date" required
                               value="<?= htmlspecialchars($p['start_date'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>End Date <span style="color:var(--danger);">*</span></label>
                        <input type="date" name="end_date" required
                               value="<?= htmlspecialchars($p['end_date'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Salary</label>
                        <input type="text" name="salary" placeholder="e.g. £18,000 p/a"
                               value="<?= htmlspecialchars($p['salary'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Working Pattern</label>
                        <input type="text" name="working_pattern" placeholder="e.g. 9am–5pm, Mon–Fri"
                               value="<?= htmlspecialchars($p['working_pattern'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Status <span style="color:var(--danger);">*</span></label>
                        <select name="status" required>
                            <?php foreach ($statuses as $s): ?>
                            <option value="<?= $s ?>" <?= $p['status']===$s?'selected':'' ?>>
                                <?= ucwords(str_replace('_', ' ', $s)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Assign Tutor</label>
                        <select name="tutor_id">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($tutors as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $p['tutor_id']==$t['id']?'selected':'' ?>>
                                <?= htmlspecialchars($t['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Job Description</label>
                        <textarea name="job_description" rows="4"
                                  style="width:100%;padding:0.875rem 1rem;border:2px solid var(--border);
                                         border-radius:var(--radius-sm);font-family:inherit;
                                         font-size:0.9375rem;background:var(--cream);resize:vertical;"><?= htmlspecialchars($p['job_description'] ?? '') ?></textarea>
                    </div>

                    <div style="grid-column:1/-1;">
                        <h4 style="color:var(--navy);font-size:1rem;margin-bottom:1rem;
                                   padding-bottom:0.5rem;border-bottom:1px solid var(--border);">
                            Supervisor Details
                        </h4>
                    </div>

                    <div class="form-group">
                        <label>Supervisor Name</label>
                        <input type="text" name="supervisor_name"
                               value="<?= htmlspecialchars($p['supervisor_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Supervisor Email</label>
                        <input type="email" name="supervisor_email"
                               value="<?= htmlspecialchars($p['supervisor_email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Supervisor Phone</label>
                        <input type="text" name="supervisor_phone"
                               value="<?= htmlspecialchars($p['supervisor_phone'] ?? '') ?>">
                    </div>

                </div>

                <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:1.5rem;
                            padding-top:1.25rem;border-top:1px solid var(--border);">
                    <a href="placements.php" class="btn btn-ghost">Cancel</a>
                    <button type="submit" name="save_placement" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
