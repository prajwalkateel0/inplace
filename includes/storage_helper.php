<?php
/**
 * File storage helper.
 *
 * Locally (XAMPP) uploads are written to assets/uploads/... and served
 * directly by Apache. On Render the filesystem is ephemeral, so when the
 * R2_* environment variables are set, uploads are pushed to a Cloudflare R2
 * bucket instead and served from R2_PUBLIC_URL.
 *
 * In both cases the DB stores the same relative path, e.g.
 * "assets/uploads/reports/xxx.pdf" — fileUrl() turns that into a usable link.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function storageConfigured(): bool {
    return getenv('R2_ACCOUNT_ID')
        && getenv('R2_ACCESS_KEY_ID')
        && getenv('R2_SECRET_ACCESS_KEY')
        && getenv('R2_BUCKET');
}

function r2Client(): \Aws\S3\S3Client {
    return new \Aws\S3\S3Client([
        'version'     => 'latest',
        'region'      => 'auto',
        'endpoint'    => 'https://' . getenv('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com',
        'credentials' => [
            'key'    => getenv('R2_ACCESS_KEY_ID'),
            'secret' => getenv('R2_SECRET_ACCESS_KEY'),
        ],
    ]);
}

/**
 * Store an uploaded file (from $_FILES[...]) under $relativePath
 * (e.g. "assets/uploads/reports/xxx.pdf"), either to R2 or local disk.
 */
function storeUploadedFile(array $file, string $relativePath): bool {
    if (storageConfigured() && class_exists(\Aws\S3\S3Client::class)) {
        $tmpPath = $file['tmp_name'];

        try {
            r2Client()->putObject([
                'Bucket'      => getenv('R2_BUCKET'),
                'Key'         => $relativePath,
                'SourceFile'  => $tmpPath,
                'ContentType' => mime_content_type($tmpPath) ?: 'application/octet-stream',
            ]);
        } catch (\Throwable $e) {
            error_log('R2 upload failed: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    $destFs = __DIR__ . '/../' . $relativePath;
    $destDir = dirname($destFs);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }

    return move_uploaded_file($file['tmp_name'], $destFs);
}

/**
 * Resolve a relative file path (as stored in the documents table) to a URL
 * the browser can load.
 */
function fileUrl(string $relativePath): string {
    if (storageConfigured()) {
        $publicUrl = getenv('R2_PUBLIC_URL');
        if ($publicUrl) {
            return rtrim($publicUrl, '/') . '/' . ltrim($relativePath, '/');
        }
    }

    return '/inplace/' . ltrim($relativePath, '/');
}
