<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('tutor');

$userId = authId();

$placementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($placementId <= 0) {
    die("Invalid placement ID.");
}

// Fetch placement
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS student_name, c.name AS company_name
    FROM placements p
    JOIN users u ON p.student_id = u.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$placementId]);
$placement = $stmt->fetch();

if (!$placement) {
    die("Placement not found.");
}
?>

<?php include '../includes/header.php'; ?>

<div class="main">
<?php include '../includes/topbar.php'; ?>

<div class="page-content">

<h2>Edit Placement</h2>

<form method="POST" action="/inplace/tutor/actions/update-placement.php">

    <input type="hidden" name="placement_id" value="<?= $placement['id'] ?>">

    <div class="form-group">
        <label>Student</label>
        <input type="text" value="<?= htmlspecialchars($placement['student_name']) ?>" disabled>
    </div>

    <div class="form-group">
        <label>Company</label>
        <input type="text" value="<?= htmlspecialchars($placement['company_name']) ?>" disabled>
    </div>

    <div class="form-group">
        <label>Role Title</label>
        <input type="text" name="role_title"
               value="<?= htmlspecialchars($placement['role_title']) ?>">
    </div>

    <div class="form-group">
        <label>Start Date</label>
        <input type="date" name="start_date"
               value="<?= htmlspecialchars($placement['start_date']) ?>">
    </div>

    <div class="form-group">
        <label>End Date</label>
        <input type="date" name="end_date"
               value="<?= htmlspecialchars($placement['end_date']) ?>">
    </div>

    <div class="form-group">
        <label>Salary</label>
        <input type="text" name="salary"
               value="<?= htmlspecialchars($placement['salary']) ?>">
    </div>

    <div class="form-group">
        <label>Working Pattern</label>
        <input type="text" name="working_pattern"
               value="<?= htmlspecialchars($placement['working_pattern']) ?>">
    </div>

    <div class="form-group">
        <label>Job Description</label>
        <textarea name="job_description" rows="5"><?= htmlspecialchars($placement['job_description']) ?></textarea>
    </div>

    <div style="margin-top:20px;">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="/inplace/tutor/all-placements.php" class="btn btn-ghost">Cancel</a>
    </div>

</form>

</div>
</div>

<?php include '../includes/footer.php'; ?>