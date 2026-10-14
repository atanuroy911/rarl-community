<?php
/**
 * RARL — "View as member" hand-off (admin → member portal, read-only).
 *
 * admin/member-edit.php mints a single-use token (stored hashed in settings,
 * valid 60 seconds) and redirects here; this page swaps it for a member
 * session flagged `view_as`. While that flag is set, csrfCheck() refuses
 * every member write, a banner shows on every page, and the session expires
 * by itself after 30 minutes. last_login_at is deliberately not touched.
 *
 *   impersonate.php?t=<token>   start (from admin)
 *   impersonate.php?end=1       stop and return to the member in admin
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

const VIEW_AS_MINUTES = 30;

if (!empty($_GET['end'])) {
    $back = (int)($_SESSION['view_as']['member_id'] ?? 0);
    if (!empty($_SESSION['view_as'])) { session_unset(); session_destroy(); }
    redirect($back ? 'admin/member-edit.php?id=' . $back : 'admin/');
}

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) redirect('login.php');

$pdo = db();
$key = 'viewas_' . hash('sha256', $token);
$row = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
$row->execute([$key]);
$data = json_decode((string)$row->fetchColumn(), true);
$pdo->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);   // single use
if (!$data || ($data['exp'] ?? 0) < time()) {
    flash('error', 'That "view as member" link has expired. Start it again from the admin panel.');
    redirect('login.php');
}

$m = $pdo->prepare('SELECT id, type, full_name, lab_name FROM members WHERE id = ?');
$m->execute([(int)$data['member_id']]);
$member = $m->fetch();
if (!$member) redirect('admin/members.php');

session_regenerate_id(true);
$_SESSION = [];
$_SESSION['member_id']   = $member['id'];
$_SESSION['member_type'] = $member['type'];
$_SESSION['member_name'] = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
$_SESSION['view_as']     = ['member_id' => (int)$member['id'], 'exp' => time() + VIEW_AS_MINUTES * 60];
redirect('dashboard.php');
