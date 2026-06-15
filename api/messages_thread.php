<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth();
$userId = authId();

$withId = (int)($_GET['with'] ?? 0);
$since  = (int)($_GET['since'] ?? 0);

if ($withId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing with']);
    exit;
}

// Auto-detect column names
$stmt2 = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages'
    AND COLUMN_NAME IN (?,?,?,?,?,?,?,?)");
$stmt2->execute(['created_at','sent_at','timestamp','date_sent','sent_on','body','message','content']);
$found = $stmt2->fetchAll(PDO::FETCH_COLUMN);

$timeCol = null;
foreach (['created_at','sent_at','timestamp','date_sent','sent_on'] as $c) {
    if (in_array($c, $found, true)) { $timeCol = $c; break; }
}
$textCol = 'body';
foreach (['body','message','content'] as $c) {
    if (in_array($c, $found, true)) { $textCol = $c; break; }
}

// mark as read
$stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
$stmt->execute([$userId, $withId]);

// fetch messages newer than last id (only messages SENT BY OTHER PERSON)
$timeSelect = $timeCol ? "`$timeCol` AS sent_at" : "NULL AS sent_at";
$stmt = $pdo->prepare("
    SELECT id, `$textCol` AS body, $timeSelect
    FROM messages
    WHERE sender_id = ? AND receiver_id = ? AND id > ?
    ORDER BY id ASC
    LIMIT 100
");
$stmt->execute([$withId, $userId, $since]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
foreach ($rows as $r) {
    $messages[] = [
        'id' => (int)$r['id'],
        'body' => (string)$r['body'],
        'time' => ($r['sent_at'] ? date('g:i A', strtotime($r['sent_at'])) : '')
    ];
}

// Online logic (optional)
$onlineCol = null;
$stmt = $pdo->query("SHOW COLUMNS FROM users");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
foreach (['last_seen_at','last_active_at','last_seen','online_at'] as $c) {
    if (in_array($c, $cols, true)) { $onlineCol = $c; break; }
}

$online = true; // default
if ($onlineCol) {
    $stmt = $pdo->prepare("SELECT `$onlineCol` FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$withId]);
    $last = $stmt->fetchColumn();
    $online = $last ? (time() - strtotime($last) <= 5*60) : false;
}

echo json_encode(['ok' => true, 'messages' => $messages, 'online' => $online]);