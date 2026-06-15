<?php
require_once '../includes/auth.php';
require_once '../config/db.php';

requireAuth();
$userId = authId();

// detect column
$stmt = $pdo->query("SHOW COLUMNS FROM users");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

$col = null;
foreach (['last_seen_at','last_active_at','last_seen','online_at'] as $c) {
    if (in_array($c, $cols, true)) { $col = $c; break; }
}

if ($col) {
    $stmt = $pdo->prepare("UPDATE users SET `$col` = NOW() WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
}

echo json_encode(['ok' => true]);