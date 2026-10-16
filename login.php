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
    if (!csrfCheck()) { $error = 'Your session timed out. Please submit the form again.'; }
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
                'body' => 'We sent a 6-digit code to <strong>' . ex($email) . '</strong>. Enter it to activate your sign-in.',
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
                : ($next ?: 'dashboard.php'));
        }
    }
}

// Live numbers for the brand panel (cheap counts; fall back quietly).
$stats = ['members' => 0, 'countries' => 0, 'next' => null];
try {
    $stats['members']   = (int)db()->query("SELECT COUNT(*) FROM members WHERE status = 'active' AND " . REAL_MEMBER_SQL)->fetchColumn();
    $stats['countries'] = (int)db()->query("SELECT COUNT(DISTINCT country) FROM members WHERE status = 'active' AND country <> '' AND " . REAL_MEMBER_SQL)->fetchColumn();
    $stats['next']      = db()->query("SELECT title, event_date FROM events WHERE is_active = 1 AND visibility = 'public' AND event_date >= CURDATE() ORDER BY event_date LIMIT 1")->fetch() ?: null;
} catch (Throwable $e) {}
$countriesShown = max($stats['countries'], (int)setting('stat_countries', '0'));

echo htmlHead('Sign in', false, ['description' => 'Sign in to your RARL membership: updates, events, learning, your member ID card and certificates.']);
$tones = ['blue' => 'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-100', 'amber' => 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-100', 'gray' => 'bg-gray-50 border-gray-200 text-gray-800 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100'];
?>
<?= publicNav() ?>

<div class="relative min-h-[calc(100dvh-64px)] grid lg:grid-cols-[1.05fr_1fr] bg-[#0b0d12] lg:bg-white lg:dark:bg-gray-950 overflow-hidden">
  <!-- Brand canvas -->
  <section class="login-canvas relative text-white px-6 sm:px-10 lg:px-14 pt-10 pb-28 lg:py-14 flex flex-col justify-between overflow-hidden" aria-label="About RARL membership">
    <div class="login-aurora" aria-hidden="true"></div>
    <div class="login-grid" aria-hidden="true"></div>

    <div class="relative flex items-center gap-3">
      <img src="assets/icons/icon-192.png" alt="" class="w-10 h-10 rounded-xl"/>
      <span class="font-heading font-bold leading-tight notranslate" translate="no">RARL<span class="block text-xs font-medium text-white/60">Robotics &amp; Automation Research Lab</span></span>
    </div>

    <div class="relative mt-10 lg:mt-0 max-w-xl">
      <p class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[.18em] text-white/70"><span class="w-1.5 h-1.5 rounded-full bg-[#ff6b66]"></span> Member portal</p>
      <h2 class="font-heading font-black text-[2.1rem] sm:text-5xl leading-[1.05] tracking-tight mt-3">Your research community, <span class="bg-gradient-to-r from-[#ff8a80] to-[#ff3d33] bg-clip-text text-transparent">in one place.</span></h2>
      <p class="text-white/70 text-base sm:text-lg mt-4 max-w-md">Announcements, events, learning and your verified membership, all from RARL.</p>

      <!-- Floating membership card -->
      <div class="login-card hidden sm:block mt-10 w-[340px] max-w-full aspect-[86/54] rounded-2xl p-5 text-white relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-[#CC0703] via-[#8f0502] to-[#2a0100]"></div>
        <div class="absolute -right-10 -top-10 w-44 h-44 rounded-full bg-white/10"></div>
        <div class="absolute right-6 bottom-16 w-24 h-24 rounded-full bg-white/5"></div>
        <div class="relative h-full flex flex-col justify-between">
          <div class="flex items-center justify-between"><span class="font-heading font-black tracking-wide notranslate" translate="no">RARL</span><i class="fa-solid fa-wifi rotate-90 text-white/70"></i></div>
          <div>
            <div class="w-9 h-7 rounded-md bg-gradient-to-br from-amber-200 to-amber-500 mb-3 opacity-90"></div>
            <p class="text-[11px] uppercase tracking-[.2em] text-white/60">Member</p>
            <p class="font-semibold text-lg leading-tight">Verified researcher</p>
            <p class="font-mono text-xs text-white/70 mt-0.5">#RARL-0000 · QR-verified</p>
          </div>
        </div>
      </div>
    </div>

    <dl class="relative grid grid-cols-3 gap-4 max-w-lg mt-10">
      <div><dt class="text-xs text-white/55">Members</dt><dd class="font-heading font-black text-2xl sm:text-3xl"><?= $stats['members'] ? number_format($stats['members']) : '—' ?></dd></div>
      <div><dt class="text-xs text-white/55">Countries</dt><dd class="font-heading font-black text-2xl sm:text-3xl"><?= $countriesShown ?: '—' ?></dd></div>
      <div class="min-w-0"><dt class="text-xs text-white/55">Next event</dt><dd class="text-sm font-semibold leading-snug mt-1 line-clamp-2"><?= $stats['next'] ? date('d M', strtotime($stats['next']['event_date'])) . ' · ' . ex($stats['next']['title']) : 'Coming soon' ?></dd></div>
    </dl>
  </section>

  <!-- Form -->
  <section class="relative -mt-16 lg:mt-0 bg-white dark:bg-gray-950 rounded-t-[2rem] lg:rounded-none flex items-start lg:items-center justify-center px-5 sm:px-8 pt-8 pb-12 lg:py-14">
    <div class="w-full max-w-[400px]">
      <h1 class="font-heading font-black text-3xl tracking-tight text-gray-900 dark:text-white">Welcome back</h1>
      <p class="text-gray-500 mt-1 mb-7">Sign in to your RARL membership.</p>

      <?php if (!empty($_GET['logged_out'])): ?>
      <div class="mb-5 flex items-center gap-3 p-3.5 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-900 text-green-800 dark:text-green-200 text-sm" role="status"><i class="fa-solid fa-circle-check"></i> You've signed out. See you soon.</div>
      <?php endif; ?>
      <?= renderFlash() ?>

      <?php if ($notice): ?>
      <div class="mb-6 p-4 rounded-2xl border <?= $tones[$notice['tone']] ?>" role="status">
        <p class="font-semibold flex items-center gap-2"><i class="fa-solid <?= $notice['icon'] ?>"></i> <?= $notice['title'] ?></p>
        <p class="text-sm mt-1 opacity-90"><?= $notice['body'] ?></p>
        <?php if ($notice['cta']): ?><a href="<?= ex($notice['cta'][0]) ?>" class="inline-flex items-center gap-1.5 mt-3 h-10 px-4 bg-gray-900 text-white dark:bg-white dark:text-gray-900 text-sm font-semibold rounded-full"><?= $notice['cta'][1] ?> <i class="fa-solid fa-arrow-right text-xs"></i></a><?php endif; ?>
      </div>
      <?php elseif ($error): ?>
      <div class="mb-5 flex items-start gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-0.5"></i> <span><?= $error ?> <a href="forgot-password.php" class="underline font-semibold">Reset your password</a></span>
      </div>
      <?php endif; ?>

      <form method="POST" class="space-y-5" id="login-form" novalidate>
        <?= csrfField() ?><input type="hidden" name="next" value="<?= ex($next) ?>"/>
        <div>
          <label for="email" class="block text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1.5">Email</label>
          <div class="relative">
            <i class="fa-regular fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input id="email" type="email" name="email" required autocomplete="username" inputmode="email" value="<?= ex($email) ?>" aria-describedby="email-hint"
              class="login-input pl-11" placeholder="you@university.edu"/>
          </div>
          <p id="email-hint" class="text-xs text-gray-500 mt-1.5">Any verified email linked to your account works.</p>
        </div>
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label for="password" class="text-sm font-semibold text-gray-800 dark:text-gray-200">Password</label>
            <a href="forgot-password.php" class="text-sm font-medium text-rarl-red hover:underline">Forgot?</a>
          </div>
          <div class="relative">
            <i class="fa-solid fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
            <input id="password" type="password" name="password" required autocomplete="current-password" class="login-input pl-11 pr-12"/>
            <button type="button" id="toggle-pw" class="absolute right-1.5 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full text-gray-500 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Show password" aria-pressed="false"><i class="fa-solid fa-eye"></i></button>
          </div>
          <p id="caps" hidden class="text-xs text-amber-700 dark:text-amber-400 mt-1.5"><i class="fa-solid fa-triangle-exclamation"></i> Caps Lock is on</p>
        </div>
        <button type="submit" class="group w-full h-12 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-full transition shadow-lg shadow-rarl-red/25 inline-flex items-center justify-center gap-2">Sign in <i class="fa-solid fa-arrow-right text-sm transition-transform group-hover:translate-x-0.5"></i></button>
      </form>

      <div class="flex items-center gap-3 my-7 text-xs text-gray-400" aria-hidden="true"><span class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></span>New to RARL?<span class="flex-1 h-px bg-gray-200 dark:bg-gray-800"></span></div>
      <a href="register.php" class="w-full h-12 rounded-full border border-gray-300 dark:border-gray-700 text-gray-900 dark:text-white font-semibold inline-flex items-center justify-center gap-2 hover:border-rarl-red hover:text-rarl-red transition">Create a free membership</a>
      <p class="text-xs text-gray-500 text-center mt-6">By signing in you agree to RARL's <a href="people.php" class="underline">community guidelines</a>. Need help? <a href="mailto:<?= ex(MAIL_REPLY_TO) ?>" class="underline">Contact us</a></p>
    </div>
  </section>
</div>

<style>
  .login-canvas { background:#0b0d12; }
  .login-aurora { position:absolute; inset:-30%; background:
      radial-gradient(40% 35% at 20% 25%, rgba(204,7,3,.55), transparent 70%),
      radial-gradient(35% 30% at 80% 70%, rgba(255,90,60,.35), transparent 70%),
      radial-gradient(30% 30% at 60% 15%, rgba(120,20,180,.28), transparent 70%);
    filter: blur(20px); animation: loginAurora 22s ease-in-out infinite alternate; }
  .login-grid { position:absolute; inset:0; background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px); background-size:44px 44px; mask-image:radial-gradient(ellipse at 40% 40%, #000 30%, transparent 75%); -webkit-mask-image:radial-gradient(ellipse at 40% 40%, #000 30%, transparent 75%); }
  @keyframes loginAurora { 0% { transform:translate3d(0,0,0) rotate(0deg); } 100% { transform:translate3d(4%,-3%,0) rotate(8deg); } }
  .login-card { box-shadow:0 30px 60px -20px rgba(0,0,0,.6), inset 0 1px 0 rgba(255,255,255,.2); transform:perspective(900px) rotateY(-12deg) rotateX(6deg); animation: loginFloat 7s ease-in-out infinite; }
  @keyframes loginFloat { 0%,100% { transform:perspective(900px) rotateY(-12deg) rotateX(6deg) translateY(0); } 50% { transform:perspective(900px) rotateY(-9deg) rotateX(4deg) translateY(-8px); } }
  .login-input { width:100%; height:3rem; padding-right:1rem; border-radius:.9rem; border:1px solid #c4c4ca; background:#fff; font-size:16px; color:#111827; transition:border-color .15s, box-shadow .15s; }
  .dark .login-input { background:#0f172a; border-color:#374151; color:#f3f4f6; }
  .login-input:focus { outline:none; border-color:#CC0703; box-shadow:0 0 0 4px rgba(204,7,3,.14); }
  .login-input[aria-invalid=true] { border-color:#dc2626; }
  @media (prefers-reduced-motion: reduce) { .login-aurora, .login-card { animation:none; } }
</style>
<script>
  (function () {
    var pw = document.getElementById('password'), tg = document.getElementById('toggle-pw'), form = document.getElementById('login-form');
    // Focus the first field only with a mouse/trackpad: on phones it would scroll past the header and pop the keyboard.
    if (matchMedia('(pointer: fine)').matches) (document.getElementById('email').value ? pw : document.getElementById('email')).focus({preventScroll: true});
    tg.addEventListener('click', function () { var show = pw.type === 'password'; pw.type = show ? 'text' : 'password'; tg.innerHTML = '<i class="fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>'; tg.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); tg.setAttribute('aria-pressed', show ? 'true' : 'false'); pw.focus(); });
    pw.addEventListener('keyup', function (e) { document.getElementById('caps').hidden = !(e.getModifierState && e.getModifierState('CapsLock')); });
    form.addEventListener('submit', function (e) {
      var bad = null;
      form.querySelectorAll('input[required]').forEach(function (i) { var ok = i.checkValidity(); i.setAttribute('aria-invalid', ok ? 'false' : 'true'); if (!ok && !bad) bad = i; });
      if (bad) { e.preventDefault(); bad.focus(); return; }
      var b = form.querySelector('button[type=submit]'); b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Signing in…';
    });
  })();
</script>
<?= publicFooter() ?>
</body></html>
