<?php
/**
 * RARL — Member Login + Logout + Dashboard
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Sign In');
// Only same-site page names are accepted as a post-login destination.
$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('/^[a-z0-9\-]+\.php(\?[\w=&%.\-]*)?(#[\w\-]*)?$/i', $next)) $next = '';
if (!empty($_SESSION['member_id'])) redirect($next ?: 'dashboard.php');

$error = ''; $notice = null; $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { $error = 'Invalid request.'; }
    else {
        $email    = cleanEmail($_POST['email']    ?? '');
        $password = $_POST['password']            ?? '';

        // Look up by any linked email (primary or additional, e.g. an ORCID-affiliated address) —
        // falls back to members.email directly in case member_emails hasn't been backfilled yet.
        $stmt = db()->prepare(
            'SELECT m.id, m.password_hash, m.status, m.full_name, m.lab_name, m.type, m.email_verified_at, m.must_change_password, m.avatar_path, m.created_at
             FROM members m LEFT JOIN member_emails me ON me.member_id = m.id
             WHERE m.email = ? OR (me.email = ? AND me.verified_at IS NOT NULL) LIMIT 1'
        );
        $stmt->execute([$email, $email]);
        $member = $stmt->fetch();

        if (!$member || !password_verify($password, $member['password_hash'])) {
            sleep(1); $error = 'Invalid email or password.';
        } elseif (empty($member['email_verified_at'])) {
            $notice = ['icon' => 'fa-envelope-circle-check', 'tone' => 'blue', 'title' => 'One step left: verify your email',
                'body' => 'We sent a 6-digit code to <strong>' . htmlspecialchars($email) . '</strong>. Enter it to activate your sign-in.',
                'cta' => ['verify-email.php?email=' . urlencode($email), 'Enter verification code']];
        } elseif ($member['status'] === 'pending') {
            $notice = ['icon' => 'fa-hourglass-half', 'tone' => 'amber', 'title' => 'Your application is being reviewed',
                'body' => 'Thanks for applying on ' . date('d M Y', strtotime($member['created_at'])) . '. Our team reviews new members personally &mdash; you will get an email the moment you are approved, usually within a few days.',
                'cta' => null];
        } elseif ($member['status'] === 'inactive') {
            $notice = ['icon' => 'fa-user-lock', 'tone' => 'gray', 'title' => 'This account is not active',
                'body' => 'Your membership is currently inactive. If you think this is a mistake, contact us and we will sort it out.',
                'cta' => ['mailto:' . MAIL_REPLY_TO, 'Contact the RARL team']];
        } else {
            session_regenerate_id(true);
            $_SESSION['member_id']   = $member['id'];
            $_SESSION['member_type'] = $member['type'];
            $_SESSION['member_name'] = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
            db()->prepare('UPDATE members SET last_login_at = NOW() WHERE id = ?')->execute([$member['id']]);
            redirect(!empty($member['must_change_password']) ? 'profile.php?force_password=1'
                : ($next ?: (empty($member['avatar_path']) ? 'dashboard.php' : 'community.php')));
        }
    }
}

echo htmlHead('Member Login');
$tones = ['blue' => 'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-100', 'amber' => 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-100', 'gray' => 'bg-gray-50 border-gray-200 text-gray-800 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'];
?>
<?= publicNav() ?>

<div class="min-h-[calc(100vh-64px)] bg-gray-50 dark:bg-gray-950 grid lg:grid-cols-2">
  <!-- Brand panel -->
  <div class="hidden lg:flex relative overflow-hidden bg-rarl-navy text-white p-12 flex-col justify-between">
    <div class="absolute inset-0 opacity-40 bg-[radial-gradient(circle_at_20%_10%,#CC0703,transparent_45%),radial-gradient(circle_at_90%_90%,#CC0703,transparent_35%)]"></div>
    <div class="relative flex items-center gap-3">
      <img src="<?= BRAND_MARK_PATH ?>" alt="" class="w-10 h-10 rounded-xl object-contain bg-white/10 p-1"/>
      <span class="font-heading font-black">Robotics &amp; Automation Research Lab</span>
    </div>
    <div class="relative max-w-md">
      <h2 class="font-heading font-black text-4xl leading-tight">A global community of robotics researchers.</h2>
      <ul class="mt-8 space-y-4 text-white/80">
        <li class="flex gap-3"><i class="fa-solid fa-id-card mt-1 text-rarl-red"></i><span>Your verifiable member ID card and certificates, always one click away</span></li>
        <li class="flex gap-3"><i class="fa-solid fa-calendar-check mt-1 text-rarl-red"></i><span>Workshops, webinars and chapter events &mdash; register in one tap</span></li>
        <li class="flex gap-3"><i class="fa-solid fa-comments mt-1 text-rarl-red"></i><span>Share work and find collaborators in the member community</span></li>
      </ul>
    </div>
    <p class="relative text-xs text-white/40">&copy; <?= date('Y') ?> RARL</p>
  </div>

  <!-- Form -->
  <div class="flex items-center justify-center px-5 py-14">
    <div class="w-full max-w-sm">
      <div class="lg:hidden flex justify-center mb-6"><img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-12 h-12 rounded-xl object-contain shadow"/></div>
      <h1 class="font-heading font-black text-3xl text-gray-900 dark:text-white">Welcome back</h1>
      <p class="text-gray-500 text-sm mt-1 mb-7">Sign in to your member account.</p>

      <?= renderFlash() ?>

      <?php if ($notice): ?>
      <div class="mb-6 p-4 rounded-2xl border <?= $tones[$notice['tone']] ?>">
        <p class="font-semibold flex items-center gap-2"><i class="fa-solid <?= $notice['icon'] ?>"></i> <?= $notice['title'] ?></p>
        <p class="text-sm mt-1 opacity-90"><?= $notice['body'] ?></p>
        <?php if ($notice['cta']): ?><a href="<?= htmlspecialchars($notice['cta'][0]) ?>" class="inline-block mt-3 px-4 py-2 bg-gray-900 text-white dark:bg-white dark:text-gray-900 text-xs font-semibold rounded-lg"><?= $notice['cta'][1] ?> &rarr;</a><?php endif; ?>
      </div>
      <?php elseif ($error): ?>
      <div class="mb-5 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm" role="alert">
        <i class="fa-solid fa-triangle-exclamation"></i> <span><?= $error ?> <a href="forgot-password.php" class="underline font-semibold">Reset password</a></span>
      </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4" id="login-form">
        <?= csrfField() ?><input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>"/>
        <div>
          <label for="email" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email address</label>
          <input id="email" type="email" name="email" required autocomplete="username" value="<?= htmlspecialchars($email) ?>" <?= $email ? '' : 'autofocus' ?>
            class="w-full h-12 px-4 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-rarl-red/15 focus:border-rarl-red transition-all" />
          <p class="text-[11px] text-gray-400 mt-1">Any verified email linked to your account works.</p>
        </div>
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label for="password" class="text-xs font-semibold text-gray-700 dark:text-gray-300">Password</label>
            <a href="forgot-password.php" class="text-xs text-rarl-red hover:underline">Forgot password?</a>
          </div>
          <div class="relative">
            <input id="password" type="password" name="password" required autocomplete="current-password" <?= $email ? 'autofocus' : '' ?>
              class="w-full h-12 pl-4 pr-12 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-rarl-red/15 focus:border-rarl-red transition-all" />
            <button type="button" id="toggle-pw" class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
          </div>
          <p id="caps" class="hidden text-[11px] text-amber-600 mt-1"><i class="fa-solid fa-triangle-exclamation"></i> Caps Lock is on</p>
        </div>
        <button type="submit" class="w-full h-12 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg shadow-rarl-red/20">Sign in</button>
      </form>

      <div class="mt-8 p-4 rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 flex items-center gap-3">
        <i class="fa-solid fa-user-plus text-rarl-red"></i>
        <p class="text-sm text-gray-600 dark:text-gray-300 flex-1">New to RARL? Membership is free.</p>
        <a href="register.php" class="text-sm font-semibold text-rarl-red hover:underline whitespace-nowrap">Join now &rarr;</a>
      </div>
    </div>
  </div>
</div>
<script>
  const pw = document.getElementById('password'), tg = document.getElementById('toggle-pw');
  tg.addEventListener('click', () => { const show = pw.type === 'password'; pw.type = show ? 'text' : 'password'; tg.innerHTML = '<i class="fa-regular ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>'; tg.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); pw.focus(); });
  pw.addEventListener('keyup', e => document.getElementById('caps').classList.toggle('hidden', !(e.getModifierState && e.getModifierState('CapsLock'))));
  document.getElementById('login-form').addEventListener('submit', e => { const b = e.target.querySelector('button[type=submit]'); b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Signing in…'; });
</script>
<?= publicFooter() ?>
</body></html>
