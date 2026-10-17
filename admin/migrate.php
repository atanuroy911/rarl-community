<?php
/**
 * RARL Admin — Database
 * The database is synced to database/schema.sql automatically on every deploy.
 * This page shows whether anything is waiting, lets an admin sync by hand
 * (e.g. after restoring a backup), and gives a health overview.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'sync') {
    @set_time_limit(120);
    $r = dbSync();
    $_SESSION['flash'] = !$r['ok']
        ? ['type' => 'error', 'msg' => 'The database update stopped — details below. Changes before it were kept.']
        : ['type' => 'success', 'msg' => $r['applied'] ? 'Database updated: ' . count($r['applied']) . ' change' . (count($r['applied']) === 1 ? '' : 's') . '.' : 'Database is already up to date.'];
    $_SESSION['sync_result'] = $r;
    header('Location: migrate.php'); exit;
}

$result = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);
$pending = pendingMigrations();
$last = dbLastSync();

$pdo = db();
$server = $pdo->query('SELECT VERSION()')->fetchColumn();
$tables = $pdo->query("SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024) AS kb
                       FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC")->fetchAll();
$totalKb = array_sum(array_column($tables, 'kb'));
$defined = count(schemaDesired());

adminWrap(function () use ($pending, $result, $last, $server, $tables, $totalKb, $defined) {
    adminFlash();
    ?>
<div class="rarl-page-head">
  <div>
    <h1>Database</h1>
    <p>The database is kept in step with the code automatically on every deploy. Use this page to check it, or to sync by hand after restoring a backup. Syncing only adds what is missing; it never deletes data.</p>
  </div>
  <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="sync">
    <button class="rarl-btn <?= $pending ? 'rarl-btn-primary' : '' ?>"><i class="fa-solid fa-rotate"></i> <?= $pending ? 'Apply ' . count($pending) . ' change' . (count($pending) === 1 ? '' : 's') : 'Check &amp; sync' ?></button></form>
</div>

<div class="grid sm:grid-cols-3 gap-3 mb-6">
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid <?= $pending ? 'fa-circle-exclamation text-amber-600' : 'fa-circle-check text-green-600' ?>"></i> Schema</span>
    <span class="rarl-stat-value"><?= $pending ? count($pending) . ' waiting' : 'Up to date' ?></span>
    <span class="text-xs text-[color:var(--muted-fg)]"><?= $defined ?> tables defined<?= $last['at'] ? ' · last synced ' . date('d M Y, H:i', strtotime($last['at'])) : '' ?></span></div>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-server"></i> Server</span><span class="rarl-stat-value text-lg"><?= ex(preg_replace('/-log$/', '', (string)$server)) ?></span><span class="text-xs text-[color:var(--muted-fg)]"><?= stripos((string)$server, 'mariadb') !== false ? 'MariaDB' : 'MySQL' ?> · <?= count($tables) ?> tables</span></div>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-hard-drive"></i> Size</span><span class="rarl-stat-value"><?= $totalKb > 1024 ? number_format($totalKb / 1024, 1) . ' MB' : $totalKb . ' KB' ?></span><span class="text-xs text-[color:var(--muted-fg)]">data and indexes</span></div>
</div>

<?php if ($result): ?>
<div class="rarl-card p-5 mb-6">
  <h2 class="rarl-card-title mb-3">Last run</h2>
  <?php if ($result['applied']): ?>
  <ul class="space-y-1.5 text-sm">
    <?php foreach ($result['applied'] as $d): ?><li class="flex gap-2"><i class="fa-solid fa-circle-check text-green-600 mt-1"></i><?= ex($d) ?></li><?php endforeach; ?>
  </ul>
  <?php elseif ($result['ok']): ?><p class="text-sm text-[color:var(--muted-fg)]">Nothing needed changing (<?= (int)$result['ms'] ?> ms).</p><?php endif; ?>
  <?php if (!$result['ok']): ?>
  <p class="text-sm text-red-700 mt-2"><i class="fa-solid fa-circle-xmark"></i> <?= ex($result['error']) ?></p>
  <?php if (!empty($result['sql'])): ?><pre class="mt-2 p-2 rounded bg-gray-50 text-xs whitespace-pre-wrap break-all"><?= ex($result['sql']) ?></pre><?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid xl:grid-cols-[minmax(0,1fr)_360px] gap-6 items-start">
  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)]">
      <h2 class="rarl-card-title"><?= $pending ? 'Waiting to be applied' : 'Schema' ?></h2>
      <p class="text-xs text-[color:var(--muted-fg)] mt-0.5">Defined in <code>database/schema.sql</code>. Starter data in <code>database/seed.sql</code>; one-off data fixes in <code>database/changes.php</code>.</p>
    </div>
    <?php if ($pending): ?>
    <ul class="divide-y divide-[color:var(--border)] text-sm">
      <?php foreach ($pending as $d): ?><li class="px-5 py-2.5 flex gap-2"><i class="fa-solid fa-circle-plus text-amber-600 mt-1"></i><?= ex($d) ?></li><?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="px-5 py-6 text-sm text-[color:var(--muted-fg)]"><i class="fa-solid fa-circle-check text-green-600"></i> Every table, column and index in the schema is present.</p>
    <?php endif; ?>
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
