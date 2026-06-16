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

// TiDB Serverless (and most managed MySQL providers) require TLS.
// Use an explicit CA bundle so mysqlnd actually initiates SSL.
// Priority: DB_SSL_CA env var → system CA bundle (Linux/Docker) → skip-verify fallback.
$sslCa = getenv('DB_SSL_CA');
if ($sslCa && is_file($sslCa)) {
  $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
} elseif (is_file('/etc/ssl/certs/ca-certificates.crt')) {
  $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
} else {
  $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

$pdo = new PDO(
  "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
  DB_USER,
  DB_PASS,
  $options
);
?>
