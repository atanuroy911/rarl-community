<?php
/**
 * RARL Admin — Database
 * Schema updates (normally applied automatically on deploy by
 * deploy-extract.php) and a health overview of the live database.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $results = match ($action) {
        'apply_pending' => applyMigrations(),
        'repair_all'    => runAllMigrations(),
        'apply_one'     => isset(RARL_MIGRATIONS[$_POST['file'] ?? '']) ? applyMigrations([$_POST['file']]) : [],
        default         => [],
    };
    $failed = array_filter($results, fn($r) => !$r['ok']);
    $changes = array_sum(array_column($results, 'statements'));
    $_SESSION['flash'] = $failed
        ? ['type' => 'error', 'msg' => 'An update failed — details below. Nothing after it was run.']
        : ['type' => 'success', 'msg' => $results ? 'Database updated (' . count($results) . ' file' . (count($results) === 1 ? '' : 's') . ', ' . $changes . ' changes).' : 'Database is already up to date.'];
    $_SESSION['migrate_results'] = $results;
    header('Location: migrate.php'); exit;
}

$results = $_SESSION['migrate_results'] ?? null;
unset($_SESSION['migrate_results']);
$status = migrationStatus();
$pending = array_filter($status, fn($m) => $m['state'] !== 'applied' && !$m['missing']);

$pdo = db();
$server = $pdo->query('SELECT VERSION()')->fetchColumn();
$tables = $pdo->query("SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024) AS kb
                       FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC")->fetchAll();
$totalKb = array_sum(array_column($tables, 'kb'));

adminWrap(function () use ($status, $pending, $results, $server, $tables, $totalKb) {
    adminFlash();
    $badge = ['applied' => ['Applied', 'green'], 'pending' => ['Pending', 'amber'], 'changed' => ['Updated file', 'blue']];
    ?>
<div class="rarl-page-head">
  <div>
    <h1>Database</h1>
    <p>Schema updates are applied automatically on every deploy. Use this page to check status, apply an update by hand, or repair a database that was restored from a backup.</p>
  </div>
</div>

<div class="grid sm:grid-cols-3 gap-3 mb-6">
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-circle-check"></i> Schema</span><span class="rarl-stat-value"><?= $pending ? count($pending) . ' pending' : 'Up to date' ?></span><span class="text-xs text-[color:var(--muted-fg)]"><?= count($status) - count($pending) ?> of <?= count($status) ?> updates applied</span></div>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-server"></i> Server</span><span class="rarl-stat-value text-lg"><?= ex(preg_replace('/-log$/', '', (string)$server)) ?></span><span class="text-xs text-[color:var(--muted-fg)]"><?= stripos((string)$server, 'mariadb') !== false ? 'MariaDB' : 'MySQL' ?> · <?= count($tables) ?> tables</span></div>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-hard-drive"></i> Size</span><span class="rarl-stat-value"><?= $totalKb > 1024 ? number_format($totalKb / 1024, 1) . ' MB' : $totalKb . ' KB' ?></span><span class="text-xs text-[color:var(--muted-fg)]">data and indexes</span></div>
</div>

<?php if ($results): ?>
<div class="rarl-card p-5 mb-6">
  <h2 class="rarl-card-title mb-3">Last run</h2>
  <ul class="space-y-2 text-sm">
    <?php foreach ($results as $file => $r): ?>
    <li class="flex gap-2">
      <i class="fa-solid <?= $r['ok'] ? 'fa-circle-check text-green-600' : 'fa-circle-xmark text-red-600' ?> mt-1"></i>
      <div class="min-w-0">
        <span class="font-mono font-semibold"><?= ex($file) ?></span>
        <?php if ($r['ok']): ?><span class="text-[color:var(--muted-fg)]"> — <?= (int)$r['statements'] ?> change<?= $r['statements'] == 1 ? '' : 's' ?>, <?= (int)$r['ms'] ?> ms</span>
        <?php else: ?><p class="text-red-700 mt-1"><?= ex($r['error']) ?></p><?php if (!empty($r['sql'])): ?><pre class="mt-1 p-2 rounded bg-gray-50 text-xs whitespace-pre-wrap break-all"><?= ex($r['sql']) ?></pre><?php endif; ?><?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="grid xl:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start">
  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)] flex flex-wrap items-center justify-between gap-3">
      <h2 class="rarl-card-title">Schema updates</h2>
      <div class="flex gap-2">
        <form method="POST" data-confirm="Re-run every update? Each one is safe to repeat; use this after restoring a backup."><?= acsrfField() ?><input type="hidden" name="action" value="repair_all"><button class="rarl-btn rarl-btn-sm"><i class="fa-solid fa-screwdriver-wrench"></i> Repair</button></form>
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="apply_pending"><button class="rarl-btn rarl-btn-sm rarl-btn-primary" <?= $pending ? '' : 'disabled' ?>><i class="fa-solid fa-play"></i> Apply <?= $pending ? count($pending) . ' pending' : '' ?></button></form>
      </div>
    </div>
    <table class="rarl-table" data-no-sort>
      <thead><tr><th>Update</th><th>Status</th><th class="hidden md:table-cell">Applied</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($status as $m): [$label, $tone] = $badge[$m['state']]; ?>
        <tr>
          <td><span class="block font-medium"><?= ex($m['desc']) ?></span><span class="block font-mono text-xs text-[color:var(--muted-fg)]"><?= ex($m['file']) ?></span></td>
          <td><?php if ($m['missing']): ?><span class="rarl-badge rarl-badge-red">File missing</span><?php else: ?><span class="rarl-badge rarl-badge-<?= $tone ?>"><?= $label ?></span><?php endif; ?></td>
          <td class="hidden md:table-cell text-xs text-[color:var(--muted-fg)]"><?= $m['applied_at'] ? date('d M Y, H:i', strtotime($m['applied_at'])) : '—' ?></td>
          <td class="text-right"><?php if (!$m['missing']): ?><form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="apply_one"><input type="hidden" name="file" value="<?= ex($m['file']) ?>"><button class="rarl-btn rarl-btn-sm" title="Run this update again"><?= $m['state'] === 'applied' ? 'Re-run' : 'Apply' ?></button></form><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)]"><h2 class="rarl-card-title">Largest tables</h2><p class="text-xs text-[color:var(--muted-fg)] mt-0.5">Row counts are the server's estimate.</p></div>
    <ul class="divide-y divide-[color:var(--border)] text-sm max-h-[460px] overflow-y-auto">
      <?php foreach ($tables as $t): ?>
      <li class="px-5 py-2.5 flex justify-between gap-3"><span class="font-mono text-xs truncate"><?= ex($t['name']) ?></span><span class="text-xs text-[color:var(--muted-fg)] whitespace-nowrap">~<?= number_format((int)$t['approx_rows']) ?> rows · <?= (int)$t['kb'] ?> KB</span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php }, 'migrate', 'Database');
