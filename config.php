<?php
/**
 * Robotics & Automation Research Lab (RARL) Platform — Central Configuration
 * Values come from environment variables (.env locally, or injected directly by
 * cPanel) with sensible defaults for local dev. This file holds no secrets itself
 * and is safe to commit — put real values in .env (gitignored).
 */
require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/.env');

// ── Database ───────────────────────────────────────────────
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', 'rarl_community'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ── Site ───────────────────────────────────────────────────
define('SITE_NAME',       env('SITE_NAME', 'Robotics & Automation Research Lab (RARL)'));
define('SITE_TAGLINE',    env('SITE_TAGLINE', 'Robotics & Automation Research Lab'));
define('SITE_URL',        env('SITE_URL', 'https://community.rarl-lab.com/membership'));
define('MAIN_SITE_URL',   env('MAIN_SITE_URL', 'https://community.rarl-lab.com'));
define('UPLOADS_PATH',    __DIR__ . '/uploads');
define('UPLOADS_URL',     SITE_URL . '/uploads');

// ── Email ──────────────────────────────────────────────────
define('MAIL_FROM_NAME',  env('MAIL_FROM_NAME', 'Robotics & Automation Research Lab (RARL)'));
define('MAIL_FROM_EMAIL', env('MAIL_FROM_EMAIL', 'community@rarl-lab.com'));
define('MAIL_REPLY_TO',   env('MAIL_REPLY_TO', 'info@rarl-lab.com'));
define('ADMIN_EMAIL',     env('ADMIN_EMAIL', 'admin@rarl-lab.com'));

// SMTP (optional) — set SMTP_HOST to send via a real cPanel mailbox instead of
// PHP's mail(). Leave SMTP_HOST blank to keep using mail() (fine for local dev).
define('SMTP_HOST',   env('SMTP_HOST', ''));
define('SMTP_PORT',   (int) env('SMTP_PORT', '587'));
define('SMTP_USER',   env('SMTP_USER', MAIL_FROM_EMAIL));
define('SMTP_PASS',   env('SMTP_PASS', ''));
// Hosting providers cap outgoing mail (Namecheap shared hosting: 50 per hour per
// domain). The outbox stays under this; 0 turns the limit off.
define('MAIL_HOURLY_LIMIT', max(0, (int) env('MAIL_HOURLY_LIMIT', '50')));
define('SMTP_SECURE', env('SMTP_SECURE', 'tls')); // 'tls' (STARTTLS, port 587) or 'ssl' (implicit TLS, port 465)

// ── Admin Auth ─────────────────────────────────────────────
// Generate hash: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
define('ADMIN_USERNAME',      env('ADMIN_USERNAME', 'admin'));
// No default: without ADMIN_PASSWORD_HASH in .env, admin sign-in stays closed.
define('ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD_HASH', ''));
define('ADMIN_SESSION_NAME',  'rarl_admin');
define('ADMIN_TIMEOUT',       7200); // 2 hours

// ── Member Session ─────────────────────────────────────────
define('MEMBER_SESSION_NAME', 'rarl_member');
define('MEMBER_TIMEOUT',      86400); // 24 hours
define('RESET_TOKEN_TTL',     3600);  // password reset link validity, seconds

// ── Certificates ───────────────────────────────────────────
define('CERT_PREFIX',     env('CERT_PREFIX', 'RARL'));  // e.g. RARL-2025-0042
define('CERT_VERIFY_URL', SITE_URL . '/verify.php');

// ── Membership ─────────────────────────────────────────────
define('REQUIRE_APPROVAL', env('REQUIRE_APPROVAL', '1') === '1');  // '0' = auto-approve on signup

// ── Misc ───────────────────────────────────────────────────
define('SECRET_SALT', env('SECRET_SALT', '2643f6b4ac09b9c0c5f3083d0d55c447d2d409995cf71464710f7f076f4a478f'));
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));
error_reporting(E_ALL);
ini_set('display_errors', env('APP_DEBUG', '0') === '1' ? '1' : '0');
ini_set('log_errors', 1);

// Visitors never see a blank page or a PHP message: an uncaught error shows a
// short "something went wrong" page with a reference code, and the full error
// is logged with that code so it can be found in the server's error log.
if (PHP_SAPI !== 'cli' && env('APP_DEBUG', '0') !== '1') {
    $rarlErrorPage = function (string $ref): void {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store'); }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title><meta name="robots" content="noindex">'
           . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f9fafb;color:#111827}'
           . '.c{max-width:440px;margin:24px;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;text-align:center}h1{font-size:20px;margin:0 0 8px}p{color:#4b5563;line-height:1.6;margin:0 0 16px}'
           . 'a{display:inline-block;padding:10px 18px;background:#CC0703;color:#fff;border-radius:10px;text-decoration:none;font-weight:600}code{font-size:12px;color:#6b7280}</style></head>'
           . '<body><div class="c"><h1>Something went wrong</h1><p>Sorry, this page could not be loaded. Please try again in a moment. If it keeps happening, let us know and mention the code below.</p>'
           . '<a href="/">Go to the home page</a><p style="margin:16px 0 0"><code>Reference ' . htmlspecialchars($ref) . '</code></p></div></body></html>';
    };
    set_exception_handler(function (Throwable $e) use ($rarlErrorPage) {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        error_log("[ref {$ref}] Uncaught " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
        $rarlErrorPage($ref);
    });
    register_shutdown_function(function () use ($rarlErrorPage) {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        $ref = strtoupper(bin2hex(random_bytes(4)));
        error_log("[ref {$ref}] Fatal: {$err['message']} in {$err['file']}:{$err['line']}");
        $rarlErrorPage($ref);
    });
}

require_once __DIR__ . '/brand.php';
