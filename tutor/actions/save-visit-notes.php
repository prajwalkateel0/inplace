<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth('tutor');
$userId  = authId();
$visitId = (int)($_POST['visit_id'] ?? 0);
$notes   = trim($_POST['notes']    ?? '');

$back = '/inplace/tutor/visits.php';

if ($visitId > 0) {
    // Verify visit belongs to this tutor
    $stmt = $pdo->prepare("SELECT id FROM visits WHERE id = ? AND tutor_id = ?");
    $stmt->execute([$visitId, $userId]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE visits SET notes = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$notes, $visitId]);
    }
}

header("Location: $back");
exit;
