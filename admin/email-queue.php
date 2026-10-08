<?php
/**
 * RARL Admin — Email queue monitor.
 * POST action=tick (fetch) sends the next small batch and returns progress
 * JSON; the floating progress pill in layout.php calls it while work remains.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
$ready = emailQueueReady();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if (!adminCsrfOk()) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
        header('Location: email-queue.php'); exit;
    }
    // Release the session lock so other admin pages stay responsive while sending.
    session_write_close();
    $action = $_POST['action'] ?? '';
    if ($action === 'tick') {
        @set_time_limit(60);
        $done = processEmailQueue(8, 15);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'done' => $done] + emailQueueSummary());
        exit;
    }
    session_start();
    $batch = preg_replace('/[^a-f0-9]/', '', $_POST['batch'] ?? '');
    if ($action === 'retry') {
        $sql = "UPDATE email_queue SET status = 'queued', attempts = 0, claim = NULL WHERE status = 'failed'" . ($batch ? ' AND batch = ?' : '');
        $s = $pdo->prepare($sql); $s->execute($batch ? [$batch] : []);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $s->rowCount() . ' email(s) queued for another try.'];
    } elseif ($action === 'retry_one') {
        $pdo->prepare("UPDATE email_queue SET status = 'queued', attempts = 0, claim = NULL WHERE id = ? AND status IN ('failed','cancelled')")->execute([(int)($_POST['id'] ?? 0)]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Email queued again.'];
    } elseif ($action === 'cancel' && $batch) {
        $s = $pdo->prepare("UPDATE email_queue SET status = 'cancelled' WHERE batch = ? AND status = 'queued'"); $s->execute([$batch]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $s->rowCount() . ' unsent email(s) cancelled.'];
    } elseif ($action === 'purge') {
        $n = $pdo->exec("DELETE FROM email_queue WHERE status IN ('sent','cancelled') AND created_at < NOW() - INTERVAL 30 DAY");
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Removed {$n} old record(s)."];
    }
    header('Location: email-queue.php' . (!empty($_POST['status']) ? '?status=' . urlencode($_POST['status']) : '')); exit;
}

$filter = in_array($_GET['status'] ?? '', ['queued','sending','sent','failed','cancelled'], true) ? $_GET['status'] : '';
$rows = [];
$counts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
$batches = [];
if ($ready) {
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM email_queue GROUP BY status") as $r) $counts[$r['status']] = (int)$r['n'];
    $s = $pdo->prepare("SELECT id, batch_label, to_email, to_name, subject, status, attempts, last_error, created_at, sent_at FROM email_queue" . ($filter ? ' WHERE status = ?' : '') . " ORDER BY id DESC LIMIT 100");
    $s->execute($filter ? [$filter] : []);
    $rows = $s->fetchAll();
    $batches = $pdo->query("SELECT batch, MAX(batch_label) label, COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed,
        SUM(status IN ('queued','sending')) pending, SUM(status='cancelled') cancelled, MIN(created_at) created_at
        FROM email_queue GROUP BY batch ORDER BY MIN(id) DESC LIMIT 12")->fetchAll();
}

adminWrap(function() use ($ready, $rows, $counts, $batches, $filter) {
    adminFlash(); ?>
<div class="rarl-page-head">
  <div>
    <h1>Email queue</h1>
    <p>Bulk emails are sent in the background, a few at a time, so large sends never time out. Keep any admin page open, or add the cron job below, while a batch is sending.</p>
  </div>
  <div class="flex gap-2">
    <?php if ($counts['failed']): ?>
    <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="retry">
      <button class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-rotate-right"></i> Retry <?= $counts['failed'] ?> failed</button></form>
    <?php endif; ?>
    <form method="POST" data-confirm="Delete sent and cancelled records older than 30 days?" data-confirm-ok="Clean up"><?= acsrfField() ?><input type="hidden" name="action" value="purge">
      <button class="rarl-btn"><i class="fa-solid fa-broom"></i> Clean up</button></form>
  </div>
</div>

<?php if (!$ready): ?>
<div class="rarl-card p-6 text-sm text-red-700">The email queue table could not be created. Run <a href="migrate.php" class="underline font-semibold">Migrations</a>; until then emails are sent immediately.</div>
<?php else: ?>
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
  <?php foreach (['queued' => ['Waiting', 'fa-hourglass-half', 'text-blue-600'], 'sending' => ['Sending', 'fa-paper-plane', 'text-indigo-600'], 'sent' => ['Sent', 'fa-circle-check', 'text-green-600'], 'failed' => ['Failed', 'fa-triangle-exclamation', 'text-red-600'], 'cancelled' => ['Cancelled', 'fa-ban', 'text-gray-500']] as $k => [$l, $ic, $tone]): ?>
  <a href="email-queue.php<?= $filter === $k ? '' : '?status=' . $k ?>" class="rarl-stat <?= $filter === $k ? 'is-active' : '' ?>">
    <span class="rarl-stat-label"><i class="fa-solid <?= $ic ?> <?= $tone ?>"></i> <?= $l ?></span>
    <span class="rarl-stat-value <?= $tone ?>"><?= number_format($counts[$k]) ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[380px_minmax(0,1fr)] gap-6">
  <div class="space-y-4">
    <div class="rarl-card p-5">
      <h2 class="rarl-card-title mb-3">Recent batches</h2>
      <?php if (!$batches): ?><p class="text-sm text-gray-400">Nothing has been queued yet.</p><?php endif; ?>
      <div class="space-y-4">
      <?php foreach ($batches as $b): $pct = $b['total'] ? round(($b['sent'] + $b['failed'] + $b['cancelled']) / $b['total'] * 100) : 100; ?>
        <div>
          <div class="flex items-center justify-between gap-2 mb-1">
            <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($b['label'] ?: 'Email batch') ?></p>
            <span class="text-[11px] text-gray-400 whitespace-nowrap"><?= date('d M H:i', strtotime($b['created_at'])) ?></span>
          </div>
          <div class="h-2 rounded-full bg-gray-100 overflow-hidden flex">
            <div class="bg-green-500" style="width:<?= $b['total'] ? $b['sent'] / $b['total'] * 100 : 0 ?>%"></div>
            <div class="bg-red-500" style="width:<?= $b['total'] ? $b['failed'] / $b['total'] * 100 : 0 ?>%"></div>
          </div>
          <div class="flex items-center justify-between mt-1 text-[11px] text-gray-500">
            <span><?= (int)$b['sent'] ?>/<?= (int)$b['total'] ?> sent<?= $b['failed'] ? ' · <span class="text-red-600">' . (int)$b['failed'] . ' failed</span>' : '' ?><?= $b['pending'] ? ' · ' . (int)$b['pending'] . ' waiting' : '' ?></span>
            <span class="flex gap-2">
              <?php if ($b['failed']): ?><form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="batch" value="<?= htmlspecialchars($b['batch']) ?>"><button class="text-rarl-red font-semibold hover:underline" data-no-loading>Retry</button></form><?php endif; ?>
              <?php if ($b['pending']): ?><form method="POST" data-confirm="Stop sending the remaining <?= (int)$b['pending'] ?> email(s) in this batch?" data-confirm-ok="Stop batch"><?= acsrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="batch" value="<?= htmlspecialchars($b['batch']) ?>"><button class="text-gray-500 font-semibold hover:underline" data-no-loading>Stop</button></form><?php endif; ?>
              <span><?= $pct ?>%</span>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <div class="rarl-card p-5 text-xs text-gray-600 space-y-2">
      <h2 class="rarl-card-title">Optional: send without a browser open</h2>
      <p>Add a cron job in cPanel (every minute):</p>
      <code class="block bg-gray-900 text-green-300 rounded-lg p-3 text-[11px] break-all">php <?= htmlspecialchars(dirname(__DIR__)) ?>/cron-email-queue.php</code>
    </div>
  </div>

  <div class="rarl-card overflow-hidden min-w-0">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h2 class="rarl-card-title"><?= $filter ? ucfirst($filter) . ' emails' : 'Latest emails' ?></h2>
      <?php if ($filter): ?><a href="email-queue.php" class="text-xs text-gray-500 hover:text-rarl-red">Show all</a><?php endif; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="rarl-table">
        <thead><tr><th>Recipient</th><th>Subject</th><th>Status</th><th>When</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?><tr><td colspan="5" class="py-12 text-center text-gray-400">No emails here.</td></tr><?php endif; ?>
          <?php foreach ($rows as $r):
            $badge = ['queued' => 'rarl-badge-blue', 'sending' => 'rarl-badge-indigo', 'sent' => 'rarl-badge-green', 'failed' => 'rarl-badge-red', 'cancelled' => 'rarl-badge-gray'][$r['status']]; ?>
          <tr>
            <td><p class="font-medium text-gray-900 truncate max-w-[200px]"><?= htmlspecialchars($r['to_name'] ?: $r['to_email']) ?></p><p class="text-[11px] text-gray-400 truncate max-w-[200px]"><?= htmlspecialchars($r['to_email']) ?></p></td>
            <td><p class="truncate max-w-[260px] text-gray-700"><?= htmlspecialchars($r['subject']) ?></p><p class="text-[11px] text-gray-400 truncate max-w-[260px]"><?= htmlspecialchars($r['batch_label']) ?></p></td>
            <td><span class="rarl-badge <?= $badge ?>"><?= ucfirst($r['status']) ?></span>
              <?php if ($r['last_error'] && $r['status'] !== 'sent'): ?><p class="text-[10px] text-red-600 mt-1 max-w-[220px] truncate" title="<?= htmlspecialchars($r['last_error']) ?>"><?= htmlspecialchars($r['last_error']) ?></p><?php endif; ?></td>
            <td class="text-[11px] text-gray-500 whitespace-nowrap" data-sort="<?= htmlspecialchars($r['sent_at'] ?: $r['created_at']) ?>"><?= date('d M H:i', strtotime($r['sent_at'] ?: $r['created_at'])) ?></td>
            <td class="text-right"><?php if (in_array($r['status'], ['failed','cancelled'], true)): ?>
              <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="retry_one"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"><button class="rarl-icon-btn" title="Retry"><i class="fa-solid fa-rotate-right"></i></button></form>
            <?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php }, 'email-queue', 'Email queue');
