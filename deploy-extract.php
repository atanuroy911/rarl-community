<?php
/**
 * RARL — Deploy Zip Extractor
 * Called once per deploy by .github/workflows/deploy.yml right after the zip
 * upload. Unzips deploy.zip into place (overwriting app files, never
 * user-uploaded content since that's excluded from the zip at build time)
 * and deletes both the zip and, on success, itself is left in place for the
 * next deploy — only the zip is removed.
 */
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/.env');

header('Content-Type: text/plain');

$token = env('DEPLOY_TOKEN', '');
$given = $_GET['token'] ?? '';
if ($token === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$zipPath = __DIR__ . '/deploy.zip';
if (!is_file($zipPath)) {
    http_response_code(404);
    echo "deploy.zip not found\n";
    exit;
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    http_response_code(500);
    echo "Failed to open deploy.zip\n";
    exit;
}

$zip->extractTo(__DIR__);
$zip->close();
unlink($zipPath);

echo "Deployed " . date('c') . "\n";
