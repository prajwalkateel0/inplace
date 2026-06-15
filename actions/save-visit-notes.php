<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';

requireAuth('tutor');

$visitId = (int)$_POST['visit_id'];
$notes   = trim($_POST['notes'] ?? '');

// Verify this visit belongs to the tutor
$stmt = $pdo->prepare("SELECT id FROM visits WHERE id = ? AND tutor_id = ?");
$stmt->execute([$visitId, authId()]);

if ($stmt->fetch()) {
    $stmt = $pdo->prepare("UPDATE visits SET notes = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$notes, $visitId]);
}

header("Location: /inplace/tutor/visits.php?success=notes_saved");
exit;