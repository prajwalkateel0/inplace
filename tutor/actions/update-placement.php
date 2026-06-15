<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth('tutor');
$userId      = authId();
$placementId = (int)($_POST['placement_id'] ?? 0);

$back = '/inplace/tutor/all-placements.php';

if ($placementId <= 0) {
    header("Location: $back?error=invalid");
    exit;
}

$roleTitle      = trim($_POST['role_title']      ?? '');
$startDate      = $_POST['start_date']           ?? '';
$endDate        = $_POST['end_date']             ?? '';
$salary         = trim($_POST['salary']          ?? '');
$workingPattern = trim($_POST['working_pattern'] ?? '');
$jobDescription = trim($_POST['job_description'] ?? '');

$pdo->prepare("
    UPDATE placements
    SET role_title      = ?,
        start_date      = ?,
        end_date        = ?,
        salary          = ?,
        working_pattern = ?,
        job_description = ?,
        updated_at      = NOW()
    WHERE id = ?
")->execute([$roleTitle, $startDate, $endDate, $salary, $workingPattern, $jobDescription, $placementId]);

header("Location: $back?success=updated");
exit;
