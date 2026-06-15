<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth('student');

$userId = authId();

$placementId = isset($_POST['placement_id']) ? (int)$_POST['placement_id'] : 0;
$weekLabel   = trim($_POST['week_label'] ?? '');
$content     = trim($_POST['content'] ?? '');

$redirectBase = "../student/my-placement.php";

if ($placementId <= 0 || $weekLabel === '' || $content === '') {
    header("Location: {$redirectBase}?err=" . urlencode("Please fill all fields."));
    exit;
}

// Security: placement must belong to this student
$stmt = $pdo->prepare("SELECT id FROM placements WHERE id = ? AND student_id = ? LIMIT 1");
$stmt->execute([$placementId, $userId]);

if (!$stmt->fetchColumn()) {
    header("Location: {$redirectBase}?err=" . urlencode("Invalid placement."));
    exit;
}

// Insert reflection
$stmt = $pdo->prepare("
    INSERT INTO reflections (placement_id, student_id, week_label, content, created_at)
    VALUES (?, ?, ?, ?, NOW())
");
$stmt->execute([$placementId, $userId, $weekLabel, $content]);

header("Location: {$redirectBase}?ok=" . urlencode("Reflection saved."));
exit;