<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth('student');
$userId  = authId();
$visitId = (int)($_POST['visit_id'] ?? 0);
$notes   = trim($_POST['notes'] ?? '');

$back = '/inplace/student/visits.php';

if ($visitId > 0) {
    // Verify visit belongs to this student
    $stmt = $pdo->prepare("
        SELECT v.id FROM visits v
        JOIN placements p ON v.placement_id = p.id
        WHERE v.id = ? AND p.student_id = ?
    ");
    $stmt->execute([$visitId, $userId]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE visits SET notes = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$notes, $visitId]);
    }
}

header("Location: $back");
exit;
