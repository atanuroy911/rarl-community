<?php
/**
 * RARL — Share RARL content on LinkedIn.
 * Opens LinkedIn's share dialog for an event, video or publication. When a
 * signed-in member uses it, the share counts towards their membership
 * standing (once per item).
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
$targets = [
    'event'       => ['SELECT id FROM events WHERE id = ? AND is_active = 1', '/events.php?event='],
    'video'       => ['SELECT id FROM videos WHERE id = ?', '/videos.php?v='],
    'publication' => ["SELECT id FROM publications WHERE id = ? AND status = 'approved'", '/publications.php?id='],
];
if (!isset($targets[$type]) || !$id) redirect('index.php');
try {
    $st = db()->prepare($targets[$type][0]);
    $st->execute([$id]);
    if (!$st->fetchColumn()) redirect('index.php');
} catch (Throwable $e) {
    redirect('index.php');
}

if (!empty($_SESSION['member_id'])) recordMemberShare((int)$_SESSION['member_id'], $type, $id);

$url = rtrim(SITE_URL, '/') . $targets[$type][1] . $id;
header('Location: https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($url), true, 302);
exit;
