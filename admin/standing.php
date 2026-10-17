<?php
/**
 * RARL Admin — Membership standing.
 * Activity rules that keep membership meaningful: points for attending events,
 * sharing RARL events on LinkedIn, papers with the RARL affiliation and signing
 * in. Members below the target get two reminders, then their membership is
 * paused. Off by default; the preview shows exactly what the next daily check
 * would do before anything is sent.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $num = fn($k, $min, $max) => (string)max($min, min($max, (int)($_POST[$k] ?? 0)));
        settingSet('standing_enabled', !empty($_POST['enabled']) ? '1' : '0');
        settingSet('standing_window_days', $num('window', 30, 730));
        settingSet('standing_target_points', $num('target', 1, 100));
        settingSet('standing_pts_event', $num('pts_event', 0, 50));
        settingSet('standing_pts_share', $num('pts_share', 0, 50));
        settingSet('standing_pts_paper', $num('pts_paper', 0, 50));
        settingSet('standing_pts_login', $num('pts_login', 0, 50));
        settingSet('standing_grace_days', $num('grace', 3, 90));
        settingSet('standing_new_member_days', $num('newbie', 0, 365));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Standing rules saved.' . (!empty($_POST['enabled']) ? ' The check runs once a day.' : '')];
    } elseif ($action === 'run') {
        $c = standingRun(true)['counts'];
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Check done: {$c['warn_1']} first reminder(s), {$c['warn_2']} final reminder(s), {$c['lapse']} paused, {$c['recover']} back in good standing." . ($c['warn_1'] + $c['warn_2'] + $c['lapse'] ? ' Emails are sending in the background.' : '')];
    } elseif ($action === 'exempt' || $action === 'unexempt') {
        $pdo->prepare('UPDATE members SET standing_exempt = ? WHERE id = ?')->execute([$action === 'exempt' ? 1 : 0, (int)($_POST['member_id'] ?? 0)]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $action === 'exempt' ? 'Member exempted: they will never be reminded or paused.' : 'Exemption removed.'];
    } elseif ($action === 'reinstate') {
        standingReinstate((int)($_POST['member_id'] ?? 0));
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Member reinstated in good standing. They get a fresh ' . standingRules()['newbie'] . ' days before the next check.'];
    }
    header('Location: standing.php' . (!empty($_POST['return']) ? '?' . preg_replace('/[^\w=&%-]/', '', $_POST['return']) : '')); exit;
}

$rules = standingRules();
$enabled = standingEnabled();
$scores = standingScores();
$preview = [];
foreach ($scores as $id => $s) if ($a = standingNextAction($s, $rules)) $preview[$id] = $a;
$counts = ['good' => 0, 'warned_1' => 0, 'warned_2' => 0, 'lapsed' => 0, 'below' => 0, 'exempt' => 0];
foreach ($scores as $s) {
    if ($s['standing_exempt']) $counts['exempt']++;
    $counts[$s['standing']]++;
    if ($s['status'] === 'active' && !$s['meets'] && !$s['standing_exempt']) $counts['below']++;
}
$filter = $_GET['show'] ?? 'all';
$q = mb_strtolower(trim($_GET['q'] ?? ''));
$rows = array_filter($scores, function ($s) use ($filter, $q, $preview) {
    if ($q !== '' && !str_contains(mb_strtolower($s['full_name'] . ' ' . $s['lab_name'] . ' ' . $s['email'] . ' ' . $s['member_code']), $q)) return false;
    return match ($filter) {
        'below'   => $s['status'] === 'active' && !$s['meets'] && !$s['standing_exempt'],
        'warned'  => in_array($s['standing'], ['warned_1', 'warned_2'], true),
        'lapsed'  => $s['standing'] === 'lapsed',
        'exempt'  => (bool)$s['standing_exempt'],
        'next'    => isset($preview[$s['id']]),
        default   => true,
    };
});
usort($rows, fn($a, $b) => [$a['points'], $a['id']] <=> [$b['points'], $b['id']]);
$lastRun = setting('standing_last_run', '');

adminWrap(function () use ($rules, $enabled, $scores, $preview, $counts, $rows, $filter, $q, $lastRun) {
    adminFlash();
    $input = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red';
    $pc = array_count_values($preview);
    $actionLabel = ['warn_1' => ['First reminder', 'bg-amber-50 text-amber-800'], 'warn_2' => ['Final reminder', 'bg-red-50 text-red-700'], 'lapse' => ['Pause membership', 'bg-red-600 text-white'], 'recover' => ['Back to good standing', 'bg-green-50 text-green-700']];
    $standingLabel = ['good' => ['Good', 'bg-green-50 text-green-700'], 'warned_1' => ['Reminded', 'bg-amber-50 text-amber-800'], 'warned_2' => ['Final reminder', 'bg-red-50 text-red-700'], 'lapsed' => ['Paused', 'bg-gray-200 text-gray-700']];
    $qs = http_build_query(array_filter(['show' => $filter !== 'all' ? $filter : null, 'q' => $q ?: null]));
    $name = fn($s) => properName((string)($s['type'] === 'lab' ? ($s['lab_name'] ?: $s['full_name']) : $s['full_name']));
?>
<div class="rarl-page-head">
  <div>
    <h1>Membership standing</h1>
    <p>Keep membership meaningful: members earn points by taking part. Below the target they get two reminders, then their membership is paused, which also stops their ID card and membership certificate verifying.</p>
  </div>
  <?php if ($enabled): ?>
  <form method="POST" data-confirm="Run the check now? <?= (int)($pc['warn_1'] ?? 0) ?> first reminder(s), <?= (int)($pc['warn_2'] ?? 0) ?> final reminder(s) and <?= (int)($pc['lapse'] ?? 0) ?> pause(s) will be applied and emailed." data-confirm-ok="Run check"><?= acsrfField() ?><input type="hidden" name="action" value="run">
    <button class="rarl-btn rarl-btn-primary" <?= $preview ? '' : 'disabled' ?>><i class="fa-solid fa-play"></i> Run check now</button></form>
  <?php endif; ?>
</div>

<div class="rarl-card p-4 mb-5 text-sm flex flex-wrap items-center gap-x-4 gap-y-1 <?= $enabled ? '' : 'border-amber-300 bg-amber-50' ?>">
  <span class="font-semibold"><i class="fa-solid <?= $enabled ? 'fa-circle-check text-green-600' : 'fa-circle-pause text-amber-600' ?>"></i> <?= $enabled ? 'On: checked automatically once a day' : 'Off: nobody is reminded or paused' ?></span>
  <span class="text-gray-600">Next check would send <b><?= (int)($pc['warn_1'] ?? 0) ?></b> first reminder(s), <b><?= (int)($pc['warn_2'] ?? 0) ?></b> final reminder(s), pause <b><?= (int)($pc['lapse'] ?? 0) ?></b>, and restore <b><?= (int)($pc['recover'] ?? 0) ?></b>.</span>
  <?php if ($lastRun): ?><span class="text-xs text-gray-500">Last run: <?= ex(date('d M Y, H:i', strtotime(substr($lastRun, 0, 25)))) ?></span><?php endif; ?>
</div>

<div class="grid sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
  <?php foreach ([['all', 'Members', count($scores)], ['below', 'Below target', $counts['below']], ['warned', 'Reminded', $counts['warned_1'] + $counts['warned_2']], ['lapsed', 'Paused', $counts['lapsed']], ['exempt', 'Exempt', $counts['exempt']], ['next', 'In next check', count($preview)]] as [$k, $l, $n]): ?>
  <a href="standing.php?show=<?= $k ?>" class="rarl-card p-3 hover:border-rarl-red <?= $filter === $k ? 'ring-2 ring-rarl-red/30 border-rarl-red' : '' ?>"><span class="block text-xs text-gray-500"><?= $l ?></span><span class="block text-xl font-bold text-gray-900"><?= number_format($n) ?></span></a>
  <?php endforeach; ?>
</div>

<div class="grid xl:grid-cols-[minmax(0,1fr)_340px] gap-5 items-start">
  <section class="rarl-card overflow-hidden">
    <form method="GET" class="flex flex-wrap gap-2 p-3 border-b border-gray-100">
      <input type="hidden" name="show" value="<?= ex($filter) ?>">
      <label class="sr-only" for="q">Search</label>
      <input id="q" name="q" value="<?= ex($q) ?>" placeholder="Search name, email or member no." class="<?= $input ?> flex-1 min-w-[180px]">
      <button class="rarl-btn"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
    </form>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
        <tr><th class="px-4 py-2.5">Member</th><th class="px-2 py-2.5 text-center" title="Events attended">Events</th><th class="px-2 py-2.5 text-center" title="LinkedIn shares">Shares</th><th class="px-2 py-2.5 text-center">Papers</th><th class="px-2 py-2.5 text-center" title="Signed in during the window">Login</th><th class="px-2 py-2.5 text-center">Points</th><th class="px-3 py-2.5">Standing</th><th class="px-3 py-2.5 text-right">Actions</th></tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?><tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">No members here.</td></tr><?php endif; ?>
      <?php foreach (array_slice($rows, 0, 500) as $s): $st = $standingLabel[$s['standing']]; $next = $preview[$s['id']] ?? null; ?>
        <tr class="<?= $s['meets'] ? '' : 'bg-amber-50/30' ?>">
          <td class="px-4 py-2.5"><a href="member-edit.php?id=<?= (int)$s['id'] ?>" class="font-medium text-gray-900 hover:text-rarl-red"><?= ex($name($s)) ?></a><span class="block text-xs text-gray-500"><?= ex($s['email']) ?><?= time() < $s['protected_until'] && !$s['meets'] ? ' · new, checked from ' . date('d M Y', $s['protected_until']) : '' ?></span></td>
          <td class="px-2 py-2.5 text-center"><?= (int)$s['events'] ?></td>
          <td class="px-2 py-2.5 text-center"><?= (int)$s['shares'] ?></td>
          <td class="px-2 py-2.5 text-center"><?= (int)$s['papers'] ?></td>
          <td class="px-2 py-2.5 text-center"><?= $s['signed_in'] ? '<i class="fa-solid fa-check text-green-600" title="Signed in"></i>' : '<span class="text-gray-300">—</span>' ?></td>
          <td class="px-2 py-2.5 text-center font-semibold <?= $s['meets'] ? 'text-green-700' : 'text-amber-700' ?>"><?= (int)$s['points'] ?>/<?= (int)$rules['target'] ?></td>
          <td class="px-3 py-2.5">
            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $st[1] ?>"><?= $st[0] ?></span>
            <?php if ($s['standing_exempt']): ?><span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700">Exempt</span><?php endif; ?>
            <?php if ($next): ?><span class="block mt-1 text-[11px] font-semibold <?= str_contains($actionLabel[$next][1], 'text-white') ? 'text-red-700' : '' ?>">Next: <?= $actionLabel[$next][0] ?></span><?php endif; ?>
          </td>
          <td class="px-3 py-2.5 text-right whitespace-nowrap">
            <?php if ($s['standing'] !== 'good' || $s['status'] === 'inactive'): ?>
            <form method="POST" class="inline" data-confirm="Reinstate <?= ex($name($s)) ?> in good standing<?= $s['standing'] === 'lapsed' ? ' and reactivate their membership' : '' ?>?"><?= acsrfField() ?><input type="hidden" name="action" value="reinstate"><input type="hidden" name="member_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="return" value="<?= ex($qs) ?>"><button class="rarl-btn rarl-btn-sm" title="Reinstate"><i class="fa-solid fa-rotate-left"></i> Reinstate</button></form>
            <?php endif; ?>
            <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $s['standing_exempt'] ? 'unexempt' : 'exempt' ?>"><input type="hidden" name="member_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="return" value="<?= ex($qs) ?>">
              <button class="rarl-btn rarl-btn-sm" title="<?= $s['standing_exempt'] ? 'Remove exemption' : 'Never remind or pause this member' ?>"><i class="fa-solid <?= $s['standing_exempt'] ? 'fa-user-xmark' : 'fa-user-shield' ?>"></i> <?= $s['standing_exempt'] ? 'Unexempt' : 'Exempt' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="rarl-card p-5">
    <h2 class="font-semibold text-gray-900 mb-3">Rules</h2>
    <form method="POST" class="space-y-3 text-sm">
      <?= acsrfField() ?><input type="hidden" name="action" value="save">
      <label class="flex items-start gap-3 cursor-pointer p-3 rounded-xl bg-gray-50">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-rarl-red">
        <span><span class="block font-semibold text-gray-900">Turn on reminders and pausing</span><span class="block text-xs text-gray-500">Checked once a day. Check the preview first.</span></span>
      </label>
      <div class="grid grid-cols-2 gap-2">
        <label><span class="block text-xs font-semibold text-gray-600 mb-1">Look back (days)</span><input type="number" name="window" min="30" max="730" value="<?= (int)$rules['window'] ?>" class="<?= $input ?>"></label>
        <label><span class="block text-xs font-semibold text-gray-600 mb-1">Target points</span><input type="number" name="target" min="1" max="100" value="<?= (int)$rules['target'] ?>" class="<?= $input ?>"></label>
      </div>
      <p class="text-xs font-semibold text-gray-600 pt-1">Points for each</p>
      <div class="grid grid-cols-2 gap-2">
        <label><span class="block text-xs text-gray-600 mb-1">Event attended</span><input type="number" name="pts_event" min="0" max="50" value="<?= (int)$rules['event'] ?>" class="<?= $input ?>"></label>
        <label><span class="block text-xs text-gray-600 mb-1">LinkedIn share</span><input type="number" name="pts_share" min="0" max="50" value="<?= (int)$rules['share'] ?>" class="<?= $input ?>"></label>
        <label><span class="block text-xs text-gray-600 mb-1">Paper (RARL affiliation)</span><input type="number" name="pts_paper" min="0" max="50" value="<?= (int)$rules['paper'] ?>" class="<?= $input ?>"></label>
        <label><span class="block text-xs text-gray-600 mb-1">Signed in</span><input type="number" name="pts_login" min="0" max="50" value="<?= (int)$rules['login'] ?>" class="<?= $input ?>"></label>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <label><span class="block text-xs font-semibold text-gray-600 mb-1">Days between steps</span><input type="number" name="grace" min="3" max="90" value="<?= (int)$rules['grace'] ?>" class="<?= $input ?>"></label>
        <label><span class="block text-xs font-semibold text-gray-600 mb-1">New members free for (days)</span><input type="number" name="newbie" min="0" max="365" value="<?= (int)$rules['newbie'] ?>" class="<?= $input ?>"></label>
      </div>
      <p class="text-xs text-gray-500">Below target: first reminder → <?= (int)$rules['grace'] ?> days → final reminder → <?= (int)$rules['grace'] ?> days → membership paused. Reaching the target at any point stops it. Shares count when members use the LinkedIn button on an event (once per event).</p>
      <button class="rarl-btn rarl-btn-primary w-full justify-center"><i class="fa-solid fa-floppy-disk"></i> Save rules</button>
    </form>
  </section>
</div>
<?php }, 'standing', 'Membership standing');
