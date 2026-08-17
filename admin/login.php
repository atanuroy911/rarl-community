<?php
require_once dirname(__DIR__) . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(ADMIN_SESSION_NAME); session_start(); }
if (!dbTablesExist()) { header('Location: install.php'); exit; }
if (!empty($_SESSION['admin_ok'])) { header('Location: index.php'); exit; }
$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$lockedFor = loginThrottleCheck($ip);
if ($lockedFor > 0) {
    $error = 'Too many failed attempts. Try again in ' . ceil($lockedFor / 60) . ' minute(s).';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['u'] ?? ''); $p = $_POST['p'] ?? '';
    if ($u === ADMIN_USERNAME && password_verify($p, ADMIN_PASSWORD_HASH)) {
        loginThrottleReset($ip);
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_ts'] = time();
        $_SESSION['admin_user'] = $u;
        $_SESSION['acsrf'] = bin2hex(random_bytes(32));
        header('Location: index.php'); exit;
    }
    loginThrottleRecordFailure($ip);
    sleep(1); $error = 'Invalid credentials.';
}
$expired = !empty($_GET['e']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Admin Login — RARL</title><meta name="robots" content="noindex,nofollow"/>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:<?= brandTailwindConfigJson() ?>,fontFamily:{heading:["<?= BRAND_FONT_HEADING ?>","<?= BRAND_FONT_SANS ?>","system-ui","sans-serif"]}}}}</script>
<link href="<?= BRAND_FONT_GOOGLE_URL ?>" rel="stylesheet"/>
<link href="<?= FONTAWESOME_CDN_URL ?>" rel="stylesheet"/>
<style>body{font-family:"<?= BRAND_FONT_SANS ?>",sans-serif;}h1{font-family:"<?= BRAND_FONT_HEADING ?>","<?= BRAND_FONT_SANS ?>",sans-serif;letter-spacing:-0.01em;}<?= rarlFontSizeCss() ?></style>
</head>
<body class="min-h-screen flex items-center justify-center" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
<div class="w-full max-w-sm mx-4">
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-3">
      <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-11 h-11 rounded-xl object-contain shadow-lg"/>
      <div class="text-left"><div class="font-heading font-black text-white text-base">RARL Admin</div><div class="text-white/35 text-xs">Community Platform</div></div>
    </div>
  </div>
  <?php if ($flash): ?><div class="mb-4 p-3.5 bg-green-50 border border-green-200 rounded-xl text-green-700 text-sm"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
  <?php if (($_GET['e'] ?? '') === 'purged'): ?><div class="mb-4 p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-amber-700 text-sm"><i class="fa-solid fa-triangle-exclamation"></i> Database purged. Please sign in again.</div>
  <?php elseif ($expired): ?><div class="mb-4 p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-amber-700 text-sm"><i class="fa-regular fa-clock"></i> Session expired. Please sign in again.</div><?php endif; ?>
  <?php if ($error): ?><div class="mb-4 p-3.5 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="bg-white rounded-2xl shadow-2xl p-8">
    <h1 class="text-xl font-black text-gray-900 mb-1">Sign In</h1>
    <p class="text-gray-400 text-sm mb-5">Access the admin dashboard</p>
    <form method="POST" class="space-y-4">
      <div><label class="block text-xs font-semibold text-gray-700 mb-1.5">Username</label>
      <input type="text" name="u" required autofocus class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/></div>
      <div><label class="block text-xs font-semibold text-gray-700 mb-1.5">Password</label>
      <input type="password" name="p" required class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/></div>
      <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl text-sm transition-all shadow-lg hover:-translate-y-0.5">Sign In →</button>
    </form>
  </div>
  <p class="text-center mt-5"><a href="../index.php" class="text-white/30 hover:text-white/60 text-xs transition-colors">← Public Site</a></p>
</div>
</body></html>
