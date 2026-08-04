<?php
/**
 * RARL — Confirm a secondary/linked email added via Profile → Linked Emails.
 * Uses the same OTP mechanism as primary-email verification (generateOtp/verifyOtp,
 * purpose 'verify'); on success flips member_emails.verified_at so the address
 * becomes usable for login.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in first.'); redirect('login.php'); }

$memberId = (int)$_SESSION['member_id'];
$email = cleanEmail($_GET['email'] ?? $_POST['email'] ?? '');
$row = null;
if ($email) {
    $q = db()->prepare('SELECT * FROM member_emails WHERE email = ? AND member_id = ?');
    $q->execute([$email, $memberId]);
    $row = $q->fetch();
}
if (!$row) { flash('error', 'That linked email was not found on your account.'); redirect('profile.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $code = trim($_POST['code'] ?? '');
    if (verifyOtp($email, 'verify', $code)) {
        db()->prepare('UPDATE member_emails SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]);
        flash('success', $email . ' is now verified and can be used to sign in.');
        redirect('profile.php');
    }
    $error = 'Invalid or expired code.';
}

echo htmlHead('Verify Linked Email');
?>
<?= publicNav() ?>
<div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center py-16 px-4">
  <div class="w-full max-w-sm">
    <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1 text-center">Verify Linked Email</h1>
    <p class="text-gray-500 text-sm text-center mb-6">Enter the code sent to <?= htmlspecialchars($email) ?></p>
    <?php if ($error): ?><div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm text-center"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 shadow-sm">
      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <input type="text" name="code" required maxlength="6" placeholder="6-digit code" autofocus
          class="w-full px-4 py-3 text-center text-2xl tracking-[0.5em] bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl text-sm">Verify</button>
      </form>
    </div>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
