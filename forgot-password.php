<?php
/**
 * RARL — Forgot Password: Request Reset Link
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!empty($_SESSION['member_id'])) redirect('dashboard.php');

$error = '';
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $error = 'Invalid request.';
    } else {
        $email = cleanEmail($_POST['email'] ?? '');
        $stmt = db()->prepare('SELECT id, full_name, lab_name, type FROM members WHERE email = ?');
        $stmt->execute([$email]);
        $member = $stmt->fetch();

        // Rate-limit actual sends even though the response is identical either way —
        // otherwise this form could be used to spam a victim's inbox with codes.
        if ($member && otpCooldownSecondsLeft($email, 'password_reset') === 0) {
            $code       = generateOtp($email, 'password_reset');
            $memberName = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
            ob_start(); require __DIR__ . '/emails/otp-reset.php'; $body = ob_get_clean();
            sendEmail($email, $memberName, 'Reset your RARL password', $body);
        }
        // Always show the same confirmation, whether or not the email matched,
        // so this endpoint can't be used to check which emails are registered.
        $sent = true;
        redirect('reset-password.php?email=' . urlencode($email));
    }
}

echo htmlHead('Forgot Password');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center py-16 px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="inline-flex items-center gap-2 mb-3">
        <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-10 h-10 rounded-xl object-contain shadow-lg"/>
        <span class="font-heading font-black text-gray-900 dark:text-white">RARL Community</span>
      </div>
      <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1">Forgot password?</h1>
      <p class="text-gray-500 text-sm">We'll email you a link to reset it</p>
    </div>

    <?php if ($error): ?>
    <div class="mb-5 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm">
      ⚠️ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 shadow-sm">
      <?php if ($sent): ?>
      <div class="text-center">
        <div class="text-4xl mb-3">📬</div>
        <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-2">Check your inbox</h2>
        <p class="text-gray-500 text-sm">If an account exists for that email, a password reset link is on its way. The link expires in 1 hour.</p>
      </div>
      <?php else: ?>
      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address</label>
          <input type="email" name="email" required autofocus
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
        </div>
        <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg hover:-translate-y-0.5">
          Send Reset Link →
        </button>
      </form>
      <?php endif; ?>
    </div>

    <p class="text-center text-xs text-gray-400 mt-5">
      <a href="login.php" class="text-rarl-red hover:underline font-semibold">← Back to Sign In</a>
    </p>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
