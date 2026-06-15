<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth(); // allow student/tutor/admin if your auth supports it
$userId = authId();

$input = json_decode(file_get_contents('php://input'), true);
$toId  = (int)($input['to_id'] ?? 0);
$body  = trim((string)($input['body'] ?? ''));

if ($toId <= 0 || $body === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid message']);
    exit;
}

// Auto-detect column names
function pickCol(PDO $pdo, string $table, array $candidates): ?string {
    $p = implode(',', array_fill(0, count($candidates), '?'));
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN ($p)");
    $stmt->execute(array_merge([$table], $candidates));
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($candidates as $c) { if (in_array($c, $found, true)) return $c; }
    return null;
}

$timeCol = pickCol($pdo, 'messages', ['created_at','sent_at','timestamp','date_sent','sent_on','created_on']);
$textCol = pickCol($pdo, 'messages', ['body','message','content','text']) ?? 'body';

if ($timeCol) {
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, `$textCol`, `$timeCol`, is_read) VALUES (?, ?, ?, NOW(), 0)");
    $stmt->execute([$userId, $toId, $body]);
} else {
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, `$textCol`, is_read) VALUES (?, ?, ?, 0)");
    $stmt->execute([$userId, $toId, $body]);
}

$id = (int)$pdo->lastInsertId();

echo json_encode([
    'ok' => true,
    'message' => [
        'id'   => $id,
        'body' => $body,
        'time' => date('g:i A')
    ]
]);