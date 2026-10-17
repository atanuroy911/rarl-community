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

// Without this, PHP-FPM/OPcache can keep serving compiled bytecode from the
// PREVIOUS version of a changed file (e.g. a function that didn't exist yet)
// until something invalidates the cache — causing "Call to undefined
// function" errors on files that were, in fact, just updated on disk.
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache reset.\n";
}

echo "Deployed " . date('c') . "\n";

// Bring the database in line with database/schema.sql for the code that was
// just unpacked, so a deploy never needs a follow-up click in Admin → Database.
// The sync only adds what is missing. A failure is reported here and to admins
// as a banner, but doesn't fail the deploy: the files are in place.
try {
    require_once __DIR__ . '/functions.php';
    if (!dbTablesExist()) {
        echo "Database is empty — finish setup at /admin/ (first-run installer).\n";
    } else {
        $r = dbSync();
        if (!$r['ok']) echo "Database sync FAILED: {$r['error']}\n" . ($r['sql'] ? "  at: {$r['sql']}\n" : '');
        elseif (!$r['applied']) echo "Database already up to date ({$r['ms']} ms).\n";
        foreach ($r['applied'] as $d) echo "Database: {$d}\n";
        foreach (seedPremadeTemplates() as $name) echo "Added design: {$name}\n";
    }
} catch (Throwable $e) {
    echo "Migration step skipped: " . $e->getMessage() . "\n";
}
