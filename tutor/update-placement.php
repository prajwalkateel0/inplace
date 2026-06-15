<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';

requireAuth('tutor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request.");
}

$placementId   = (int)$_POST['placement_id'];
$roleTitle     = trim($_POST['role_title']);
$startDate     = $_POST['start_date'];
$endDate       = $_POST['end_date'];
$salary        = trim($_POST['salary']);
$workingPattern= trim($_POST['working_pattern']);
$jobDescription= trim($_POST['job_description']);

$stmt = $pdo->prepare("
    UPDATE placements
    SET role_title = ?,
        start_date = ?,
        end_date = ?,
        salary = ?,
        working_pattern = ?,
        job_description = ?
    WHERE id = ?
");

$stmt->execute([
    $roleTitle,
    $startDate,
    $endDate,
    $salary,
    $workingPattern,
    $jobDescription,
    $placementId
]);

header("Location: /inplace/tutor/all-placements.php");
exit;