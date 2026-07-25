<?php
/**
 * RARL Community Platform — Minimal .env Loader
 * No Composer dependency — parses KEY=VALUE lines so this works unmodified on any
 * plain-PHP shared host as well as in Docker (where vars are usually injected directly
 * via the environment, in which case there may be no .env file at all — that's fine,
 * env() just falls through to getenv()/the given default).
 */
function loadEnv(string $path): void {
    static $loaded = false;
    if ($loaded || !is_file($path)) { $loaded = true; return; }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === $value[-1] && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
    $loaded = true;
}

function env(string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}
