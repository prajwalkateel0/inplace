<?php
/**
 * Helper: generate a single-use provider confirmation token.
 * Returns the full URL to embed in notification emails.
 *
 * Usage:
 *   require_once '../includes/provider_token_helper.php';
 *   $url = generateProviderToken($pdo, $placementId, $providerEmail);
 */

function generateProviderToken(PDO $pdo, int $placementId, string $email): string
{
    // Ensure table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_tokens (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            token        VARCHAR(64)  NOT NULL UNIQUE,
            placement_id INT          NOT NULL,
            email        VARCHAR(255) NOT NULL,
            expires_at   DATETIME     NOT NULL,
            used_at      DATETIME     DEFAULT NULL,
            created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $token = bin2hex(random_bytes(32)); // 64-char hex token
    $pdo->prepare("
        INSERT INTO provider_tokens (token, placement_id, email, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
    ")->execute([$token, $placementId, $email]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/inplace/provider/confirm.php?token=' . $token;
}
