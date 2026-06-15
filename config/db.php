<?php
// Reads from environment variables (set these on Render / your host).
// Falls back to the existing values for local XAMPP development.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'inplace_db');
define('DB_PORT', getenv('DB_PORT') ?: 3306);

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

// Aiven (and most managed MySQL providers) require TLS. mysqlnd negotiates
// SSL automatically when the server requires it; we just skip verifying the
// server certificate against a CA unless one is explicitly provided.
$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;

$sslCa = getenv('DB_SSL_CA');
if ($sslCa && is_file($sslCa)) {
  $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
}

$pdo = new PDO(
  "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
  DB_USER,
  DB_PASS,
  $options
);
?>
