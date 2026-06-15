<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';

requireAuth('tutor');

$placementId = (int)$_POST['placement_id'];
$reason      = trim($_POST['reason'] ?? '');

if (!$reason) {
    header("Location: /inplace/tutor/all-placements.php?error=reason_required");
    exit;
}

// Update placement status
$stmt = $pdo->prepare("
    UPDATE placements
    SET status = 'terminated', tutor_comments = ?, updated_at = NOW()
    WHERE id = ?
");
$stmt->execute([$reason, $placementId]);

// Notify student
$stmt = $pdo->prepare("SELECT student_id FROM placements WHERE id = ?");
$stmt->execute([$placementId]);
$row = $stmt->fetch();

if ($row) {
    $msg = "⚠️ Your placement has been terminated. Reason: " . $reason . " Please contact your placement tutor for further information.";

    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'placement_terminated', ?)");
    $stmt->execute([$row['student_id'], $msg]);

    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)");
    $stmt->execute([authId(), $row['student_id'], $msg]);
}

// Audit log
$stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details) VALUES (?, 'placement_terminated', 'placements', ?, ?)");
$stmt->execute([authId(), $placementId, $reason]);

header("Location: /inplace/tutor/all-placements.php?success=terminated");
exit;