<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

requireAuth('admin');

// Get DB credentials from PDO DSN
$dsn = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);

// Use config/db.php constants or fall back to common defaults
$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$dbName = defined('DB_NAME') ? DB_NAME : 'inplace_db';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

$filename  = 'inplace_backup_' . date('Y-m-d_His') . '.sql';
$backupDir = __DIR__ . '/../../assets/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
$filepath  = $backupDir . $filename;

// Generate SQL dump using PDO (no mysqldump dependency)
try {
    $sql  = "-- InPlace Database Backup\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Table structure
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql .= $create[1] . ";\n\n";

        // Table data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $values = array_map(function($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote($v);
            }, array_values($row));
            $cols = implode('`, `', array_keys($row));
            $sql .= "INSERT INTO `$table` (`$cols`) VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    file_put_contents($filepath, $sql);

    // Serve as download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);

    // Clean up file after sending
    unlink($filepath);
    exit;

} catch (Exception $e) {
    header('Location: /inplace/admin/dashboard.php?error=backup_failed');
    exit;
}
