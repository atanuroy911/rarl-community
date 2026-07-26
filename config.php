<?php
/**
 * RARL Community Platform — Central Configuration
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
define('SITE_NAME',       env('SITE_NAME', 'RARL Community'));
define('SITE_TAGLINE',    env('SITE_TAGLINE', 'Robotics and Automation Research Laboratory'));
define('SITE_URL',        env('SITE_URL', 'https://rarl-lab.com/membership'));
define('MAIN_SITE_URL',   env('MAIN_SITE_URL', 'https://rarl-lab.com'));
define('UPLOADS_PATH',    __DIR__ . '/uploads');
define('UPLOADS_URL',     SITE_URL . '/uploads');

// ── Email ──────────────────────────────────────────────────
define('MAIL_FROM_NAME',  env('MAIL_FROM_NAME', 'RARL Community'));
define('MAIL_FROM_EMAIL', env('MAIL_FROM_EMAIL', 'community@rarl-lab.com'));
define('MAIL_REPLY_TO',   env('MAIL_REPLY_TO', 'info@rarl-lab.com'));
define('ADMIN_EMAIL',     env('ADMIN_EMAIL', 'admin@rarl-lab.com'));

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
