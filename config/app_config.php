<?php
/**
 * Central application config loader.
 * Reads from system_settings DB table with fallback defaults.
 *
 * Usage (in any file that already has $pdo available):
 *   require_once __DIR__ . '/config/app_config.php';
 *   loadAppConfig($pdo);
 *   $val = appConfig('smtp_host', 'smtp.gmail.com');
 */

$_APP_CONFIG = null;

function loadAppConfig(PDO $pdo): void {
    global $_APP_CONFIG;
    if ($_APP_CONFIG !== null) return; // already loaded this request
    $_APP_CONFIG = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $_APP_CONFIG[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        // Table may not exist yet — silently fall through to defaults
    }
}

function appConfig(string $key, string $default = ''): string {
    global $_APP_CONFIG;
    if ($_APP_CONFIG === null) return $default;
    $val = $_APP_CONFIG[$key] ?? '';
    return ($val !== '' && $val !== null) ? $val : $default;
}
