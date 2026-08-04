<?php
/**
 * RARL — Manual cache-clear + diagnostics
 * Same DEPLOY_TOKEN as deploy-extract.php. Visit this URL directly any time
 * you suspect stale OPcache (e.g. a "Call to undefined function" for
 * something that's definitely in the file on disk). Reports exactly what
 * OPcache is doing on this server instead of guessing blind.
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

echo "=== OPcache diagnostics ===\n";
echo "opcache extension loaded: " . (extension_loaded('Zend OPcache') ? 'yes' : 'no') . "\n";
echo "opcache_reset() available: " . (function_exists('opcache_reset') ? 'yes' : 'NO — likely blocked by disable_functions in php.ini') . "\n";
echo "opcache.enable: " . (ini_get('opcache.enable') ? 'On' : 'Off') . "\n";
echo "opcache.enable_cli: " . (ini_get('opcache.enable_cli') ? 'On' : 'Off') . "\n";
echo "opcache.validate_timestamps: " . (ini_get('opcache.validate_timestamps') ? 'On' : 'Off (files only refresh on explicit reset!)') . "\n";
echo "opcache.revalidate_freq: " . ini_get('opcache.revalidate_freq') . "s\n";

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status) {
        echo "opcache currently enabled & running: " . ($status['opcache_enabled'] ? 'yes' : 'no') . "\n";
        echo "cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? '?') . "\n";
        $layoutFile = __DIR__ . '/admin/layout.php';
        $cached = $status['scripts'][$layoutFile] ?? $status['scripts'][realpath($layoutFile)] ?? null;
        if ($cached) {
            echo "admin/layout.php IS in the opcode cache — cached at " . date('c', $cached['timestamp']) . ", file on disk last modified " . date('c', filemtime($layoutFile)) . "\n";
            echo ($cached['timestamp'] < filemtime($layoutFile)) ? "=> STALE: cached copy predates the file on disk.\n" : "=> Cache timestamp looks current.\n";
        } else {
            echo "admin/layout.php not currently in the opcode cache (fresh compile will happen on next request).\n";
        }
    } else {
        echo "opcache_get_status() returned false (opcache may be disabled for this SAPI).\n";
    }
}

echo "\n=== Attempting reset ===\n";
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo $ok ? "opcache_reset() succeeded.\n" : "opcache_reset() returned false.\n";
} else {
    echo "Cannot reset — opcache_reset() is not available (see above). You'll need to restart PHP-FPM from cPanel (MultiPHP Manager / Select PHP Version, or ask your host) instead.\n";
}

// Also clear any realpath cache, which can independently cause "file not found"-style staleness
clearstatcache(true);
if (function_exists('realpath_cache_size')) {
    echo "realpath cache cleared (was " . realpath_cache_size() . " bytes).\n";
}

echo "\n=== Direct file check (bypasses everything above) ===\n";
$layoutPath = __DIR__ . '/admin/layout.php';
if (!is_file($layoutPath)) {
    echo "admin/layout.php does not exist at {$layoutPath}!\n";
} else {
    $bytes = file_get_contents($layoutPath);
    echo "File size: " . strlen($bytes) . " bytes\n";
    echo "MD5: " . md5($bytes) . "\n";
    echo "Last modified: " . date('c', filemtime($layoutPath)) . "\n";
    $hasDefinition = (bool) preg_match('/function\s+bulkRowCheckbox\s*\(/', $bytes);
    echo "Contains 'function bulkRowCheckbox(' (real definition, not just the word in a comment): " . ($hasDefinition ? 'YES' : 'NO — this file was never actually updated with the fix') . "\n";

    // Fresh-include in an isolated function scope, bypassing any opcode cache
    // entry that might exist under a different resolved path, then check.
    $probe = function() use ($layoutPath) {
        if (!function_exists('bulkRowCheckbox')) {
            require $layoutPath;
        }
        return function_exists('bulkRowCheckbox');
    };
    echo "function_exists('bulkRowCheckbox') after fresh require: " . ($probe() ? 'YES — the function loads correctly in isolation' : 'NO — even a direct require of this exact file does not define it') . "\n";
}

echo "\nDone at " . date('c') . "\n";
