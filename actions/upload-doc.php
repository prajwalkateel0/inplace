<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';
require_once '../../includes/storage_helper.php';
requireAuth('student');

if (!empty($_FILES['document']['tmp_name'])) {
    $safe = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['document']['name']);
    $destRel = 'assets/uploads/' . $safe;
    if (storeUploadedFile($_FILES['document'], $destRel)) {
        $stmt = $pdo->prepare("INSERT INTO documents (placement_id, uploaded_by, doc_type, file_name, file_path) VALUES (?,?,?,?,?)");
        $stmt->execute([$_POST['placement_id'], authId(), $_POST['doc_type'], $_FILES['document']['name'], $destRel]);
    }
}

header("Location: /inplace/student/my-placement.php");
exit;