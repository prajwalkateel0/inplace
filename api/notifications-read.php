<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth();
$userId = authId();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$id    = (int)($input['id'] ?? 0);

if ($id > 0) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")
        ->execute([$id, $userId]);
} else {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
        ->execute([$userId]);
}

echo json_encode(['ok' => true]);
