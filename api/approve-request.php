<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth(['tutor','admin']);

$user = current_user();

$placement_id = (int)($_POST['placement_id'] ?? 0);
$action = $_POST['action'] ?? '';
$comments = trim($_POST['comments'] ?? '');

if ($placement_id <= 0) {
  header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/inplace/dashboard.php'));
  exit;
}

$newStatus = match ($action) {
  'approve' => 'approved',
  'reject' => 'rejected',
  'awaiting_provider' => 'awaiting_provider',
  default => null
};

if ($newStatus === null) {
  header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/inplace/dashboard.php'));
  exit;
}

// Assign tutor if a tutor is approving
$tutorId = ($user['role'] === 'tutor') ? $user['id'] : null;

if ($tutorId) {
  $stmt = $pdo->prepare("UPDATE placements SET status=?, tutor_comments=?, tutor_id=? WHERE id=?");
  $stmt->execute([$newStatus, $comments, $tutorId, $placement_id]);
} else {
  $stmt = $pdo->prepare("UPDATE placements SET status=?, tutor_comments=? WHERE id=?");
  $stmt->execute([$newStatus, $comments, $placement_id]);
}

// audit (optional but matches your schema)
$stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details, ip_address) VALUES (?,?,?,?,?,?)");
$stmt->execute([$user['id'], "placement_status_change", "placements", $placement_id, "Set to $newStatus", $_SERVER['REMOTE_ADDR'] ?? '']);

header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/inplace/dashboard.php'));
exit;