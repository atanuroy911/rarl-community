<?php
/**
 * RARL — Email Unsubscribe
 */
require_once __DIR__ . '/functions.php';

$token = $_GET['token'] ?? '';
$msg = '';

if ($token) {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE members SET newsletter_opt_in = 0 WHERE unsubscribe_token = ?");
    $stmt->execute([$token]);
    if ($stmt->rowCount() > 0) {
        $msg = 'You have been successfully unsubscribed from the RARL newsletter.';
    } else {
        $msg = 'Invalid or expired unsubscribe link.';
    }
} else {
    $msg = 'No unsubscribe token provided.';
}

echo htmlHead('Unsubscribe');
?>
<?= publicNav() ?>
<div class="min-h-[calc(100vh-64px)] flex items-center justify-center bg-gray-50 dark:bg-gray-950 px-4">
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-8 rounded-2xl shadow-sm text-center max-w-sm w-full">
    <div class="text-4xl mb-4">📬</div>
    <h1 class="font-heading font-bold text-xl text-gray-900 dark:text-white mb-2">Newsletter</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6"><?= htmlspecialchars($msg) ?></p>
    <a href="index.php" class="text-sm text-rarl-red font-semibold hover:underline">Return to Home</a>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
