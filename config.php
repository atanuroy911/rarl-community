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
define('SITE_URL',        env('SITE_URL', 'https://rarl-lab.com/membership'));
define('MAIN_SITE_URL',   env('MAIN_SITE_URL', 'https://rarl-lab.com'));
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
define('SMTP_SECURE', env('SMTP_SECURE', 'tls')); // 'tls' (STARTTLS, port 587) or 'ssl' (implicit TLS, port 465)

// ── Admin Auth ─────────────────────────────────────────────
// Generate hash: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
define('ADMIN_USERNAME',      env('ADMIN_USERNAME', 'admin'));
define('ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD_HASH', '$2y$10$Yb0ps2g8MNeSvehh6B.oWeiXWG95GRJcNwahFIPJEM5ZnP2.7aLuS'));
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

require_once __DIR__ . '/brand.php';
