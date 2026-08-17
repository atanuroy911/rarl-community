<?php
/**
 * RARL Admin — First-Run Setup
 * Shown instead of a fatal error when the database has no tables yet (fresh
 * deploy, empty DB). Runs the same migrations as Admin → Migrations, then
 * sends the admin back to login. Once tables exist, this page refuses to
 * run again and redirects to login — mirrors how wp-admin/install.php
 * becomes inert after WordPress is already installed.
 */
require_once dirname(__DIR__) . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(ADMIN_SESSION_NAME);
    session_start();
}

if (dbTablesExist()) {
    header('Location: login.php'); exit;
}

if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));

$results = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['install_csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Security check failed — please reload and try again.';
    } else {
        $results = runAllMigrations();
        $failed = array_filter($results, fn($r) => !$r['ok']);
        if (!$failed) {
            session_unset(); session_destroy();
            session_name(ADMIN_SESSION_NAME);
            session_start();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Setup complete — please log in.'];
            header('Location: login.php'); exit;
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>First-Run Setup — RARL Admin</title><meta name="robots" content="noindex,nofollow"/>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:<?= brandTailwindConfigJson() ?>,fontFamily:{heading:["<?= BRAND_FONT_HEADING ?>","<?= BRAND_FONT_SANS ?>","system-ui","sans-serif"]}}}}</script>
<link href="<?= BRAND_FONT_GOOGLE_URL ?>" rel="stylesheet"/>
<link href="<?= FONTAWESOME_CDN_URL ?>" rel="stylesheet"/>
<style>body{font-family:"<?= BRAND_FONT_SANS ?>",sans-serif;}h1{font-family:"<?= BRAND_FONT_HEADING ?>","<?= BRAND_FONT_SANS ?>",sans-serif;letter-spacing:-0.01em;}<?= rarlFontSizeCss() ?></style>
</head>
<body class="min-h-screen flex items-center justify-center py-10" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
<div class="w-full max-w-lg mx-4">
  <div class="text-center mb-8">
    <div class="inline-flex items-center gap-3">
      <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-11 h-11 rounded-xl object-contain shadow-lg"/>
      <div class="text-left"><div class="font-heading font-black text-white text-base">RARL Admin</div><div class="text-white/35 text-xs">First-Run Setup</div></div>
    </div>
  </div>
  <div class="bg-white rounded-2xl shadow-2xl p-8">
    <h1 class="text-xl font-black text-gray-900 mb-1">No database tables found</h1>
    <p class="text-gray-400 text-sm mb-5">This looks like a fresh install. Click below to create all tables and seed the default data — safe to run, it won't overwrite anything if tables already partially exist.</p>

    <?php if ($error): ?>
    <div class="mb-4 p-3.5 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($results): ?>
    <div class="mb-4 space-y-2">
      <?php foreach ($results as $file => $r): ?>
      <div class="flex items-start gap-2 text-xs">
        <span><?= $r['ok'] ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-xmark"></i>' ?></span>
        <div>
          <span class="font-mono font-semibold text-gray-800"><?= htmlspecialchars($file) ?></span>
          <?php if (!$r['ok']): ?><div class="text-red-600 mt-1"><?= htmlspecialchars($r['error']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <ul class="text-xs text-gray-500 space-y-1.5 mb-6">
      <?php foreach (RARL_MIGRATIONS as $file => $desc): ?>
      <li><span class="font-mono font-semibold text-gray-700"><?= htmlspecialchars($file) ?></span> — <?= htmlspecialchars($desc) ?></li>
      <?php endforeach; ?>
    </ul>

    <form method="POST">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['install_csrf']) ?>">
      <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl text-sm transition-all shadow-lg hover:-translate-y-0.5">Set Up Database →</button>
    </form>
  </div>
</div>
</body></html>
