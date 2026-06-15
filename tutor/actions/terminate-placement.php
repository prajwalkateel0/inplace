<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth('tutor');
$userId      = authId();
$placementId = (int)($_POST['placement_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');

$back = '/inplace/tutor/all-placements.php';

if ($placementId <= 0 || !$reason) {
    header("Location: $back?error=missing");
    exit;
}

// Update placement status to terminated
$pdo->prepare("
    UPDATE placements
    SET status = 'terminated',
        termination_reason = ?,
        terminated_at = NOW(),
        terminated_by = ?
    WHERE id = ?
")->execute([$reason, $userId, $placementId]);

// Notify student
$stmt = $pdo->prepare("SELECT student_id FROM placements WHERE id = ?");
$stmt->execute([$placementId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    try {
        $msg = "Your placement has been terminated by your tutor. Reason: $reason";
        $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'placement_terminated', ?)")
            ->execute([$row['student_id'], $msg]);
    } catch (Exception $e) {}
}

header("Location: $back?success=terminated");
exit;
