<?php
/**
 * RARL Admin — Sign in.
 * Step 1: username + password. Step 2 (when two-factor is on): a 6-digit code
 * from an authenticator app, or a one-time backup code. Both steps share the
 * per-IP brute-force throttle.
 */
require_once dirname(__DIR__) . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(ADMIN_SESSION_NAME); session_start(); }
if (!dbTablesExist()) { header('Location: install.php'); exit; }

$next = (string)($_GET['next'] ?? $_POST['next'] ?? $_SESSION['admin_next'] ?? '');
if (!preg_match('/^[a-z0-9\-]+\.php(\?[\w=&%.\-]*)?$/i', $next)) $next = '';
if (!empty($_SESSION['admin_ok'])) { header('Location: ' . ($next ?: 'index.php')); exit; }

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// Step 2 is pending for 5 minutes after a correct password.
$step2 = !empty($_SESSION['admin_2fa_pending']) && time() - (int)$_SESSION['admin_2fa_pending'] < 300;
if (!$step2) unset($_SESSION['admin_2fa_pending']);

$finish = function (string $u) use ($ip, $next) {
    loginThrottleReset($ip);
    unset($_SESSION['admin_2fa_pending'], $_SESSION['admin_next']);
    session_regenerate_id(true);
    $_SESSION['admin_ok'] = true;
    $_SESSION['admin_ts'] = time();
    $_SESSION['admin_user'] = $u;
    $_SESSION['acsrf'] = bin2hex(random_bytes(32));
    header('Location: ' . ($next ?: 'index.php')); exit;
};

$lockedFor = loginThrottleCheck($ip);
if ($lockedFor > 0) {
    $error = 'Too many failed attempts. Try again in ' . ceil($lockedFor / 60) . ' minute(s).';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'code' && $step2) {
    $code = trim($_POST['code'] ?? '');
    $ok = strlen(preg_replace('/\D/', '', $code)) === 6 && strlen(preg_replace('/[\s-]/', '', $code)) === 6
        ? totpVerify(setting('admin_totp_secret'), $code) !== null
        : adminUseBackupCode($code);
    if ($ok) $finish(ADMIN_USERNAME);
    loginThrottleRecordFailure($ip);
    sleep(1); $error = 'That code didn\'t work. Check your authenticator app\'s current code and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? 'password') === 'password') {
    $u = trim($_POST['u'] ?? ''); $p = $_POST['p'] ?? '';
    if ($u === ADMIN_USERNAME && password_verify($p, ADMIN_PASSWORD_HASH)) {
        if (!adminTwoFactorEnabled()) $finish($u);
        session_regenerate_id(true);
        $_SESSION['admin_2fa_pending'] = time();
        $_SESSION['admin_next'] = $next;
        $step2 = true;
    } else {
        loginThrottleRecordFailure($ip);
        sleep(1); $error = 'Incorrect username or password.';
    }
} elseif (($_GET['restart'] ?? '') === '1') {
    unset($_SESSION['admin_2fa_pending']); $step2 = false;
}
$e = $_GET['e'] ?? '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover"/>
<title>Admin sign in — RARL</title><meta name="robots" content="noindex,nofollow"/>
<link rel="icon" href="../assets/icons/favicon-32.png"/>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:<?= brandTailwindConfigJson() ?>,fontFamily:{heading:["<?= BRAND_FONT_HEADING ?>","<?= BRAND_FONT_SANS ?>","system-ui","sans-serif"]}}}}</script>
<link href="<?= BRAND_FONT_GOOGLE_URL ?>" rel="stylesheet"/>
<link href="<?= FONTAWESOME_CDN_URL ?>" rel="stylesheet"/>
<style>
  body{font-family:"<?= BRAND_FONT_SANS ?>",system-ui,sans-serif;background:#0b0d12}
  h1{font-family:"<?= BRAND_FONT_HEADING ?>","<?= BRAND_FONT_SANS ?>",sans-serif;letter-spacing:-.02em}
  .aurora{position:fixed;inset:-30%;background:radial-gradient(35% 30% at 25% 25%,rgba(204,7,3,.5),transparent 70%),radial-gradient(30% 30% at 80% 75%,rgba(255,90,60,.3),transparent 70%);filter:blur(20px);animation:a 24s ease-in-out infinite alternate}
  @keyframes a{to{transform:translate3d(4%,-3%,0) rotate(8deg)}}
  @media (prefers-reduced-motion:reduce){.aurora{animation:none}}
  .inp{width:100%;height:3rem;padding:0 1rem;border-radius:.9rem;border:1px solid #c4c4ca;background:#fff;font-size:16px;color:#111827}
  .inp:focus{outline:none;border-color:#CC0703;box-shadow:0 0 0 4px rgba(204,7,3,.14)}
  .code{letter-spacing:.5em;text-align:center;font:600 1.5rem ui-monospace,monospace}
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="aurora" aria-hidden="true"></div>
<main class="relative w-full max-w-sm">
  <div class="flex items-center gap-3 mb-6 text-white">
    <img src="../assets/icons/icon-192.png" alt="" class="w-11 h-11 rounded-xl"/>
    <div><div class="font-heading font-black">RARL Admin</div><div class="text-white/50 text-xs">Community platform</div></div>
  </div>

  <?php if ($flash): ?><div class="mb-4 p-3.5 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm" role="status"><i class="fa-solid fa-circle-check"></i> <?= ex($flash['msg']) ?></div><?php endif; ?>
  <?php if ($e === 'purged'): ?><div class="mb-4 p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-sm" role="status"><i class="fa-solid fa-triangle-exclamation"></i> Database purged. Please sign in again.</div>
  <?php elseif ($e === 'logged_out'): ?><div class="mb-4 p-3.5 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm" role="status"><i class="fa-solid fa-circle-check"></i> You have been signed out.</div>
  <?php elseif ($e && !$step2): ?><div class="mb-4 p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-sm" role="status"><i class="fa-solid fa-clock"></i> Your session expired. Please sign in again<?= $next ? ' to continue' : '' ?>.</div><?php endif; ?>

  <div class="bg-white rounded-3xl shadow-2xl p-7">
    <?php if ($step2): ?>
    <div class="w-12 h-12 rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xl"><i class="fa-solid fa-shield-halved"></i></div>
    <h1 class="text-2xl font-black text-gray-900 mt-4">Two-step verification</h1>
    <p class="text-gray-500 text-sm mt-1 mb-5">Enter the 6-digit code from your authenticator app.</p>
    <?php if ($error): ?><div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm" role="alert"><?= ex($error) ?></div><?php endif; ?>
    <form method="POST" class="space-y-4" id="code-form">
      <input type="hidden" name="step" value="code"><input type="hidden" name="next" value="<?= ex($next) ?>">
      <label for="code" class="sr-only">Authentication code</label>
      <input id="code" name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="11" class="inp code" placeholder="000000" autofocus/>
      <button class="w-full h-12 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-full">Verify</button>
    </form>
    <details class="mt-5 text-sm text-gray-600">
      <summary class="cursor-pointer font-medium">Can't use your app?</summary>
      <p class="mt-2">Type one of your backup codes (e.g. <span class="font-mono">AB12-CD34</span>) in the box above instead. Each works once.</p>
    </details>
    <a href="login.php?restart=1" class="block text-center text-xs text-gray-500 hover:text-gray-800 mt-5">Start over</a>
    <script>
      // Submit as soon as six digits are typed or pasted.
      document.getElementById('code').addEventListener('input', function (e) { var d = e.target.value.replace(/\D/g, ''); if (/^\d{6}$/.test(e.target.value.trim()) || (d.length === 6 && !/[a-z]/i.test(e.target.value))) document.getElementById('code-form').requestSubmit(); });
    </script>
    <?php else: ?>
    <h1 class="text-2xl font-black text-gray-900">Sign in</h1>
    <p class="text-gray-500 text-sm mt-1 mb-5">Manage the RARL community platform.</p>
    <?php if ($error): ?><div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm" role="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?= ex($error) ?></div><?php endif; ?>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="step" value="password"><input type="hidden" name="next" value="<?= ex($next) ?>">
      <div><label for="u" class="block text-sm font-semibold text-gray-800 mb-1.5">Username</label>
        <input id="u" type="text" name="u" required autocomplete="username" autocapitalize="none" class="inp" autofocus/></div>
      <div><label for="p" class="block text-sm font-semibold text-gray-800 mb-1.5">Password</label>
        <input id="p" type="password" name="p" required autocomplete="current-password" class="inp"/></div>
      <button class="w-full h-12 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-full shadow-lg shadow-rarl-red/25">Continue <i class="fa-solid fa-arrow-right text-sm"></i></button>
    </form>
    <?php if (!adminTwoFactorEnabled()): ?><p class="mt-5 text-xs text-amber-800 bg-amber-50 rounded-xl p-3"><i class="fa-solid fa-shield-halved"></i> Two-step verification is off. Turn it on in <strong>Settings → Security</strong> after signing in.</p><?php endif; ?>
    <?php endif; ?>
  </div>
  <p class="text-center mt-5"><a href="../index.php" class="text-white/50 hover:text-white text-xs">← Public site</a></p>
</main>
</body></html>
