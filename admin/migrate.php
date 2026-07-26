<?php
/**
 * RARL Admin — Database Migrations
 * Runs sql/schema.sql and sql/002_v2_features.sql against the live database.
 * Both files use CREATE TABLE IF NOT EXISTS / ON DUPLICATE KEY UPDATE, so this
 * is safe to run repeatedly (e.g. after every deploy) without wiping data.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'run_migrations') {
    $results = runAllMigrations();
    $failed = array_filter($results, fn($r) => !$r['ok']);
    $_SESSION['flash'] = $failed
        ? ['type' => 'error', 'msg' => 'Migration finished with errors — see details below.']
        : ['type' => 'success', 'msg' => 'Migrations ran successfully.'];
    $_SESSION['migrate_results'] = $results;
    header('Location: migrate.php'); exit;
}

$results = $_SESSION['migrate_results'] ?? null;
unset($_SESSION['migrate_results']);

adminWrap(function() use ($results) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Database Migrations</h1>
<p class="text-gray-500 text-sm mb-7">Runs the SQL files in <code>sql/</code> against this database. Safe to run any time — every statement is idempotent (won't duplicate or wipe existing data).</p>

<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mb-6">
  <ul class="text-xs text-gray-600 space-y-2 mb-5">
    <?php foreach (RARL_MIGRATIONS as $file => $desc): ?>
    <li class="flex items-center gap-2">
      <span class="font-mono font-semibold text-gray-800"><?= htmlspecialchars($file) ?></span>
      <span class="text-gray-400">— <?= htmlspecialchars($desc) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <form method="POST" onsubmit="return confirm('Run all migrations now?');">
    <?= acsrfField() ?><input type="hidden" name="action" value="run_migrations">
    <button type="submit" class="px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-xs rounded-xl transition-colors">Run Migrations</button>
  </form>
</div>

<?php if ($results): ?>
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
  <h2 class="font-heading font-bold text-sm text-gray-800 mb-4">Last Run</h2>
  <div class="space-y-3">
    <?php foreach ($results as $file => $r): ?>
    <div class="flex items-start gap-2 text-xs">
      <span><?= $r['ok'] ? '✅' : '❌' ?></span>
      <div>
        <span class="font-mono font-semibold text-gray-800"><?= htmlspecialchars($file) ?></span>
        <?php if ($r['ok']): ?>
          <span class="text-gray-400"> — <?= (int)$r['statements'] ?> statements executed</span>
        <?php else: ?>
          <div class="text-red-600 mt-1"><?= htmlspecialchars($r['error']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<?php }, 'migrate', 'Migrations');
