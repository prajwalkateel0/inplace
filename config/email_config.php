<?php
/**
 * Email / SMTP configuration.
 * Values come from system_settings (configured via Admin → Settings).
 * Hardcoded strings below are only used as last-resort fallbacks
 * if the DB row is missing.
 */
require_once __DIR__ . '/app_config.php';

global $pdo;
if ($pdo instanceof PDO) {
    loadAppConfig($pdo);
}

$smtpUser = appConfig('smtp_user', '');
$fromEmail = appConfig('from_email', '');
if ($fromEmail === '') $fromEmail = $smtpUser; // Gmail requires From == authenticated user

return [
    'smtp_host'  => appConfig('smtp_host',  'smtp.gmail.com'),
    'smtp_port'  => (int) appConfig('smtp_port', '587'),
    'smtp_user'  => $smtpUser,
    'smtp_pass'  => appConfig('smtp_pass',  ''),
    'from_email' => $fromEmail,
    'from_name'  => appConfig('from_name',  'InPlace'),
];
