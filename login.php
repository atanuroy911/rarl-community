<?php
/**
 * RARL — Member Login + Logout + Dashboard
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Sign In');
if (!empty($_SESSION['member_id'])) redirect('community.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { $error = 'Invalid request.'; }
    else {
        $email    = cleanEmail($_POST['email']    ?? '');
        $password = $_POST['password']            ?? '';

        // Look up by any linked email (primary or additional, e.g. an ORCID-affiliated address) —
        // falls back to members.email directly in case member_emails hasn't been backfilled yet.
        $stmt = db()->prepare(
            'SELECT m.id, m.password_hash, m.status, m.full_name, m.lab_name, m.type, m.email_verified_at, m.must_change_password
             FROM members m LEFT JOIN member_emails me ON me.member_id = m.id
             WHERE m.email = ? OR (me.email = ? AND me.verified_at IS NOT NULL) LIMIT 1'
        );
        $stmt->execute([$email, $email]);
        $member = $stmt->fetch();

        if (!$member || !password_verify($password, $member['password_hash'])) {
            sleep(1); $error = 'Invalid email or password.';
        } elseif (empty($member['email_verified_at'])) {
            $error = 'Please verify your email before signing in. <a href="verify-email.php?email=' . urlencode($email) . '" class="underline font-semibold">Verify now →</a>';
        } elseif ($member['status'] === 'pending') {
            $error = 'Your account is pending approval. We\'ll notify you by email once approved.';
        } elseif ($member['status'] === 'inactive') {
            $error = 'Your account has been deactivated. Contact us for assistance.';
        } else {
            session_regenerate_id(true);
            $_SESSION['member_id']   = $member['id'];
            $_SESSION['member_type'] = $member['type'];
            $_SESSION['member_name'] = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
            db()->prepare('UPDATE members SET last_login_at = NOW() WHERE id = ?')->execute([$member['id']]);
            redirect(!empty($member['must_change_password']) ? 'profile.php?force_password=1' : 'community.php');
        }
    }
}

echo htmlHead('Member Login');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex items-center justify-center py-16 px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="inline-flex items-center gap-2 mb-3">
        <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-10 h-10 rounded-xl object-contain shadow-lg"/>
        <span class="font-heading font-black text-gray-900 dark:text-white">RARL Community</span>
      </div>
      <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1">Welcome back</h1>
      <p class="text-gray-500 text-sm">Sign in to your member account</p>
    </div>

    <?= renderFlash() ?>

    <?php if ($error): ?>
    <div class="mb-5 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm">
      <i class="fa-solid fa-triangle-exclamation"></i> <?= $error ?>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 shadow-sm">
      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address</label>
          <input type="email" name="email" required autofocus
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Password</label>
          <input type="password" name="password" required
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all" />
          <a href="forgot-password.php" class="block text-right text-xs text-rarl-red hover:underline mt-1.5">Forgot password?</a>
        </div>
        <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg hover:-translate-y-0.5">
          Sign In →
        </button>
      </form>
    </div>

    <p class="text-center text-xs text-gray-400 mt-5">
      Not a member yet? <a href="register.php" class="text-rarl-red hover:underline font-semibold">Join Free</a>
    </p>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
