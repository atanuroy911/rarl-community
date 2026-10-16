<?php
/**
 * RARL Admin — Auth Guard
 * Include at the top of every admin page.
 */
require_once dirname(__DIR__) . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(ADMIN_SESSION_NAME);
    session_start();
}

if (!dbTablesExist()) {
    header('Location: install.php'); exit;
}

if (empty($_SESSION['admin_ok']) || (time() - ($_SESSION['admin_ts'] ?? 0)) > ADMIN_TIMEOUT) {
    // Remember where they were going (e.g. a scanned check-in link) for after sign-in.
    $back = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) . (($q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY)) ? '?' . $q : '') : '';
    session_unset(); session_destroy();
    if ($back && preg_match('/^[a-z0-9\-]+\.php(\?[\w=&%.\-]*)?$/i', $back) && $back !== 'index.php') { header('Location: login.php?e=expired&next=' . rawurlencode($back)); exit; }
    header('Location: login.php?e=expired'); exit;
}
$_SESSION['admin_ts'] = time();

// Admin CSRF helper
if (empty($_SESSION['acsrf'])) $_SESSION['acsrf'] = bin2hex(random_bytes(32));
$GLOBALS['acsrf'] = $_SESSION['acsrf'];

function adminCsrfOk(): bool {
    return hash_equals($_SESSION['acsrf'] ?? '', $_POST['acsrf'] ?? '');
}
function acsrfField(): string {
    return '<input type="hidden" name="acsrf" value="' . ex($GLOBALS['acsrf']) . '">';
}
