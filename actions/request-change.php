<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';
requireAuth('student');

// Store change request as a message to tutor for now
$stmt = $pdo->prepare("SELECT tutor_id FROM placements WHERE id = ? AND student_id = ?");
$stmt->execute([$_POST['placement_id'], authId()]);
$row = $stmt->fetch();

if ($row && $row['tutor_id']) {
    $body = "Change request (" . $_POST['change_type'] . "): " . $_POST['justification'];
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?,?,?)");
    $stmt->execute([authId(), $row['tutor_id'], $body]);
}

header("Location: /inplace/student/my-placement.php");
exit;