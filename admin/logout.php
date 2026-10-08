<?php
/**
 * RARL Admin — Logout. Ends only the admin session (members stay signed in).
 */
require_once dirname(__DIR__) . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(ADMIN_SESSION_NAME); session_start(); }
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php?e=logged_out');
exit;
