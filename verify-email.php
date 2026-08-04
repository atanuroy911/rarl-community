<?php
/**
 * RARL — Verify Email: Consume OTP Code
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!empty($_SESSION['member_id'])) redirect('dashboard.php');

$email = cleanEmail($_GET['email'] ?? $_POST['email'] ?? '');
$error = '';
$resendMsg = '';

// Generic message used whenever we don't want to reveal whether the account
// exists or is already verified — avoids email enumeration.
$genericRedirect = function (string $msg) {
    flash('info', $msg);
    redirect('login.php');
};

if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $stmt = db()->prepare('SELECT id, full_name, lab_name, type, email_verified_at FROM members WHERE email = ?');
    $stmt->execute([$email]);
    $member = $stmt->fetch();
} else {
    $member = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $error = 'Invalid request. Please try again.';
    } elseif (isset($_POST['resend'])) {
        if (!$member || $member['email_verified_at']) {
            $genericRedirect('If that account needs verifying, a new code has been sent to its email address.');
        } else {
            $wait = otpCooldownSecondsLeft($email, 'verify_email');
            if ($wait > 0) {
                $error = "Please wait {$wait}s before requesting another code.";
            } else {
                $code = generateOtp($email, 'verify_email');
                $memberName = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
                ob_start(); require __DIR__ . '/emails/otp-verify.php'; $body = ob_get_clean();
                sendEmail($email, $memberName, 'Your RARL verification code', $body);
                $resendMsg = 'A new code has been sent to your email.';
            }
        }
    } else {
        $code = trim($_POST['code'] ?? '');
        if (!$member || $member['email_verified_at']) {
            $genericRedirect('If that account needed verifying, this step is complete — you can sign in.');
        } elseif (!$code || !verifyOtp($email, 'verify_email', $code)) {
            $error = 'Invalid or expired code.';
        } else {
            db()->prepare('UPDATE members SET email_verified_at = NOW() WHERE email = ?')->execute([$email]);
            flash('success', 'Email verified! You can now sign in.');
            redirect('login.php');
        }
    }
}

$cooldown = ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? otpCooldownSecondsLeft($email, 'verify_email') : 0;

echo htmlHead('Verify Your Email');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center py-16 px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="inline-flex items-center gap-2 mb-3">
        <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-10 h-10 rounded-xl object-contain shadow-lg"/>
        <span class="font-heading font-black text-gray-900 dark:text-white">RARL Community</span>
      </div>
      <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1">Verify your email</h1>
      <p class="text-gray-500 text-sm">Enter the 6-digit code we emailed you</p>
    </div>

    <?= renderFlash() ?>

    <?php if ($error): ?>
    <div class="mb-5 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm">
      <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($resendMsg): ?>
    <div class="mb-5 flex items-center gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl text-blue-700 dark:text-blue-300 text-sm">
      <i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($resendMsg) ?>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 shadow-sm">
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
        <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg hover:-translate-y-0.5">
          Verify Email →
        </button>
      </form>

      <form method="POST" class="mt-4 text-center">
        <?= csrfField() ?>
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>"/>
        <input type="hidden" name="resend" value="1"/>
        <?php if ($cooldown > 0): ?>
        <p class="text-xs text-gray-400">You can resend a code in <?= (int)$cooldown ?>s.</p>
        <?php else: ?>
        <button type="submit" class="text-xs text-rarl-red hover:underline font-semibold">Resend code</button>
        <?php endif; ?>
      </form>
    </div>

    <p class="text-center text-xs text-gray-400 mt-5">
      <a href="login.php" class="text-rarl-red hover:underline font-semibold">← Back to Sign In</a>
    </p>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
