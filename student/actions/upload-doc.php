<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/storage_helper.php';

requireAuth('student');
$userId = authId();

$placementId = (int)($_POST['placement_id'] ?? 0);
$docType     = $_POST['doc_type'] ?? 'other';

$allowedTypes = ['offer_letter','job_description','interim_report','final_report','other'];
if (!in_array($docType, $allowedTypes, true)) {
    $docType = 'other';
}

$back = '/inplace/student/my-placement.php';

// check the placement belongs to this student
if ($placementId <= 0) {
    header("Location: $back?error=invalid");
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM placements WHERE id = ? AND student_id = ? LIMIT 1");
$stmt->execute([$placementId, $userId]);
if (!$stmt->fetch()) {
    header("Location: $back?error=invalid");
    exit;
}

// validate the uploaded file
if (empty($_FILES['document']['name']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    header("Location: $back?error=upload_failed");
    exit;
}

$allowed = ['application/pdf'];
$mime    = mime_content_type($_FILES['document']['tmp_name']);
if (!in_array($mime, $allowed, true)) {
    header("Location: $back?error=invalid_type");
    exit;
}

if ($_FILES['document']['size'] > 10 * 1024 * 1024) {
    header("Location: $back?error=too_large");
    exit;
}

// save the file to the uploads folder
$original = $_FILES['document']['name'];
$safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
$destRel  = 'assets/uploads/' . $safeName;

if (!storeUploadedFile($_FILES['document'], $destRel)) {
    header("Location: $back?error=upload_failed");
    exit;
}

$fileSize = round($_FILES['document']['size'] / 1024) . ' KB';

// reports need the tutor to review them; other documents like offer letters are auto-approved
$status = in_array($docType, ['interim_report', 'final_report']) ? 'pending_review' : 'approved';

$pdo->prepare("
    INSERT INTO documents (placement_id, uploaded_by, doc_type, file_name, file_path, file_size, status)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([$placementId, $userId, $docType, $original, $destRel, $fileSize, $status]);

header("Location: $back?success=uploaded");
exit;
