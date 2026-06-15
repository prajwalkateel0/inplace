<?php
require_once '../../includes/auth.php';
require_once '../../config/db.php';
require_once '../../includes/storage_helper.php';

requireAuth('student');
$userId = authId();

$placementId = (int)($_POST['placement_id'] ?? 0);
$reportType  = $_POST['report_type'] ?? 'other';
$title       = trim($_POST['title'] ?? '');
$notes       = trim($_POST['notes'] ?? '');

if ($placementId <= 0) {
    header("Location: /inplace/student/reports.php?err=" . urlencode("Invalid placement."));
    exit;
}

// Ensure placement belongs to student
$stmt = $pdo->prepare("SELECT id FROM placements WHERE id = ? AND student_id = ? LIMIT 1");
$stmt->execute([$placementId, $userId]);
if (!$stmt->fetchColumn()) {
    header("Location: /inplace/student/reports.php?err=" . urlencode("Not allowed."));
    exit;
}

if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    header("Location: /inplace/student/reports.php?err=" . urlencode("Upload failed."));
    exit;
}

$maxSize = 10 * 1024 * 1024;
if ($_FILES['report_file']['size'] > $maxSize) {
    header("Location: /inplace/student/reports.php?err=" . urlencode("File too large (max 10MB)."));
    exit;
}

$origName = $_FILES['report_file']['name'];
$tmp      = $_FILES['report_file']['tmp_name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

$allowed = ['pdf','doc','docx'];
if (!in_array($ext, $allowed, true)) {
    header("Location: /inplace/student/reports.php?err=" . urlencode("Only PDF/DOC/DOCX allowed."));
    exit;
}

// Save file
$safeName = 'report_' . $placementId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$relative = 'assets/uploads/reports/' . $safeName;

if (!storeUploadedFile($_FILES['report_file'], $relative)) {
    header("Location: /inplace/student/reports.php?err=" . urlencode("Could not save file."));
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO documents (placement_id, uploaded_by, doc_type, file_name, file_path, file_size, status)
    VALUES (?, ?, ?, ?, ?, ?, 'pending_review')
");
$fileSize = round($_FILES['report_file']['size'] / 1024) . ' KB';
$stmt->execute([$placementId, $userId, $reportType, $origName, $relative, $fileSize]);

header("Location: /inplace/student/reports.php?ok=" . urlencode("Report uploaded."));
exit;