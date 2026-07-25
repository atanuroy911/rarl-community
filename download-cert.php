<?php
/**
 * RARL — Certificate Downloader
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$uuid = $_GET['id'] ?? '';
if (!$uuid) { die('No certificate specified.'); }

$pdo = db();

// If user is NOT admin, verify they own this cert
$isAdmin = !empty($_SESSION['admin_ok']);

if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT * FROM certificates WHERE uuid = ?");
    $stmt->execute([$uuid]);
} else {
    // Member must be logged in
    if (empty($_SESSION['member_id'])) {
        header('Location: login.php'); exit;
    }
    
    // Fetch member to check their email
    $m = $pdo->prepare("SELECT email FROM members WHERE id = ?");
    $m->execute([$_SESSION['member_id']]);
    $member = $m->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM certificates WHERE uuid = ? AND (member_id = ? OR recipient_email = ?)");
    $stmt->execute([$uuid, $_SESSION['member_id'], $member['email'] ?? '']);
}

$cert = $stmt->fetch();
if (!$cert || !$cert['pdf_path']) {
    die('Certificate not found or not generated.');
}

$file = UPLOADS_PATH . '/certificates/' . $cert['pdf_path'];
if (!file_exists($file)) {
    die('Certificate file is missing from server.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="RARL_Certificate_' . htmlspecialchars($cert['certificate_no']) . '.pdf"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
