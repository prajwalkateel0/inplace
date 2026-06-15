<?php
// Copy this file to db.php and fill in your own credentials
define('DB_HOST', 'your-host');
define('DB_USER', 'your-username');
define('DB_PASS', 'your-password');
define('DB_NAME', 'inplace_db');
define('DB_PORT', 3306);

$pdo = new PDO(
  "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
  DB_USER,
  DB_PASS,
  [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]
);
?>
