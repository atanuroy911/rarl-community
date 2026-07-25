<?php
/**
 * RARL — Reset Password: Consume OTP Code + Set New Password
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$email = cleanEmail($_GET['email'] ?? $_POST['email'] ?? '');
$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $error = 'Invalid request.';
    } else {
        $code = trim($_POST['code'] ?? '');
        $pw   = $_POST['password'] ?? '';
        $pw2  = $_POST['password_confirm'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid or expired code.';
        } elseif (strlen($pw) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pw !== $pw2) {
            $error = 'Passwords do not match.';
        } elseif (!$code || !verifyOtp($email, 'password_reset', $code)) {
            // Same generic message whether the email exists or not, and whether
            // the code is wrong/expired — avoids leaking account existence.
            $error = 'Invalid or expired code.';
        } else {
            $hash = password_hash($pw, PASSWORD_BCRYPT);
            db()->prepare('UPDATE members SET password_hash = ? WHERE email = ?')->execute([$hash, $email]);
            $done = true;
        }
    }
}

echo htmlHead('Reset Password');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center py-16 px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="inline-flex items-center gap-2 mb-3">
        <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-10 h-10 rounded-xl object-contain shadow-lg"/>
        <span class="font-heading font-black text-gray-900 dark:text-white">RARL Community</span>
      </div>
      <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1">Reset your password</h1>
      <p class="text-gray-500 text-sm">Enter the code we emailed you and choose a new password</p>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 shadow-sm">
      <?php if ($done): ?>
      <div class="text-center">
        <div class="text-4xl mb-3">✅</div>
        <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-2">Password updated</h2>
        <p class="text-gray-500 text-sm mb-5">You can now sign in with your new password.</p>
        <a href="login.php" class="inline-flex items-center gap-2 px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-xl text-sm transition-all">Sign In →</a>
      </div>
      <?php else: ?>
      <?php if ($error): ?>
      <div class="mb-5 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm">
        ⚠️ <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address</label>
          <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">6-Digit Code</label>
          <input type="text" name="code" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code"
            placeholder="000000"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-center tracking-[0.5em] font-bold focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">New Password</label>
          <input type="password" name="password" required minlength="8"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Confirm New Password</label>
          <input type="password" name="password_confirm" required minlength="8"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
        </div>
        <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg hover:-translate-y-0.5">
          Update Password →
        </button>
      </form>
      <p class="text-center text-xs text-gray-400 mt-4">
        Didn't get a code? <a href="forgot-password.php" class="text-rarl-red hover:underline font-semibold">Request again</a>
      </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
