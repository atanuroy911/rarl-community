<?php
/**
 * RARL Admin — Membership standing.
 * Activity rules that keep membership meaningful: points for attending events,
 * sharing RARL events on LinkedIn, papers with the RARL affiliation and signing
 * in. Members below the target are due a first reminder, then a final one,
 * then a pause. Nothing happens automatically: admins pick members and apply
 * their next step, with or without an email, when they choose.
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
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Standing rules saved.'];
    } elseif ($action === 'apply') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (!$ids) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Select the members first.'];
        } else {
            $email = !empty($_POST['email']);
            $c = standingApply($ids, $email);
            $parts = array_filter([$c['warn_1'] ? "{$c['warn_1']} first reminder(s)" : '', $c['warn_2'] ? "{$c['warn_2']} final reminder(s)" : '', $c['lapse'] ? "{$c['lapse']} paused" : '', $c['recover'] ? "{$c['recover']} back in good standing" : '']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => ($parts ? 'Done: ' . implode(', ', $parts) . '.' : 'Nothing to do for the selected members.')
                . ($c['emailed'] ? " {$c['emailed']} email(s) are sending in the background." : ($parts && !$email ? ' No emails were sent.' : ''))];
        }
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
</div>

<div class="rarl-card p-4 mb-5 text-sm flex flex-wrap items-center gap-x-4 gap-y-1">
  <span class="font-semibold"><i class="fa-solid fa-hand text-gray-500"></i> Nothing is automatic: you choose who to remind or pause, and when.</span>
  <span class="text-gray-600">Due now: <b><?= (int)($pc['warn_1'] ?? 0) ?></b> first reminder(s), <b><?= (int)($pc['warn_2'] ?? 0) ?></b> final reminder(s), <b><?= (int)($pc['lapse'] ?? 0) ?></b> pause(s)<?= !empty($pc['recover']) ? ', <b>' . (int)$pc['recover'] . '</b> back on track' : '' ?>.</span>
  <?php if ($lastRun): ?><span class="text-xs text-gray-500">Last applied: <?= ex(date('d M Y, H:i', strtotime(substr($lastRun, 0, 25)))) ?></span><?php endif; ?>
</div>

<div class="grid sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
  <?php foreach ([['all', 'Members', count($scores)], ['below', 'Below target', $counts['below']], ['warned', 'Reminded', $counts['warned_1'] + $counts['warned_2']], ['lapsed', 'Paused', $counts['lapsed']], ['exempt', 'Exempt', $counts['exempt']], ['next', 'Next step due', count($preview)]] as [$k, $l, $n]): ?>
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
    <form method="POST" id="apply-form" data-confirm="Apply the next step to the selected members?" data-confirm-ok="Apply"><?= acsrfField() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="return" value="<?= ex($qs) ?>"></form>
    <div id="apply-bar" class="hidden sticky top-2 z-20 mx-3 mt-3 px-4 py-3 rounded-2xl bg-gray-900 text-white text-sm flex flex-wrap items-center gap-3 shadow-lg">
      <span><b id="apply-n">0</b> selected</span>
      <label class="inline-flex items-center gap-2"><input type="checkbox" name="email" value="1" form="apply-form" checked class="w-4 h-4 accent-rarl-red"> Email them</label>
      <button form="apply-form" class="ml-auto px-3 py-1.5 rounded-lg bg-rarl-red hover:bg-rarl-dark font-semibold"><i class="fa-solid fa-forward-step"></i> Apply next step</button>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
        <tr><th class="pl-4 py-2.5 w-8"><input type="checkbox" id="apply-all" class="w-4 h-4 accent-rarl-red" aria-label="Select everyone with a step due"></th><th class="px-3 py-2.5">Member</th><th class="px-2 py-2.5 text-center" title="Events attended">Events</th><th class="px-2 py-2.5 text-center" title="LinkedIn shares">Shares</th><th class="px-2 py-2.5 text-center">Papers</th><th class="px-2 py-2.5 text-center" title="Signed in during the window">Login</th><th class="px-2 py-2.5 text-center">Points</th><th class="px-3 py-2.5">Standing</th><th class="px-3 py-2.5 text-right">Actions</th></tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php if (!$rows): ?><tr><td colspan="9" class="px-4 py-10 text-center text-gray-500">No members here.</td></tr><?php endif; ?>
      <?php foreach (array_slice($rows, 0, 500) as $s): $st = $standingLabel[$s['standing']]; $next = $preview[$s['id']] ?? null; ?>
        <tr class="<?= $s['meets'] ? '' : 'bg-amber-50/30' ?>">
          <td class="pl-4 py-2.5"><?php if ($next): ?><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" form="apply-form" class="apply-row w-4 h-4 accent-rarl-red" aria-label="Select <?= ex($name($s)) ?>"><?php endif; ?></td>
          <td class="px-3 py-2.5"><a href="member-edit.php?id=<?= (int)$s['id'] ?>" class="font-medium text-gray-900 hover:text-rarl-red"><?= ex($name($s)) ?></a><span class="block text-xs text-gray-500"><?= ex($s['email']) ?><?= time() < $s['protected_until'] && !$s['meets'] ? ' · new, checked from ' . date('d M Y', $s['protected_until']) : '' ?></span></td>
          <td class="px-2 py-2.5 text-center"><?= (int)$s['events'] ?></td>
          <td class="px-2 py-2.5 text-center"><?= (int)$s['shares'] ?></td>
          <td class="px-2 py-2.5 text-center"><?= (int)$s['papers'] ?></td>
          <td class="px-2 py-2.5 text-center"><?= $s['signed_in'] ? '<i class="fa-solid fa-check text-green-600" title="Signed in"></i>' : '<span class="text-gray-300">—</span>' ?></td>
          <td class="px-2 py-2.5 text-center font-semibold <?= $s['meets'] ? 'text-green-700' : 'text-amber-700' ?>"><?= (int)$s['points'] ?>/<?= (int)$rules['target'] ?></td>
          <td class="px-3 py-2.5">
            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $st[1] ?>"><?= $st[0] ?></span>
            <?php if ($s['standing_exempt']): ?><span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700">Exempt</span><?php endif; ?>
            <?php if ($next): ?><span class="block mt-1 text-[11px] font-semibold <?= str_contains($actionLabel[$next][1], 'text-white') ? 'text-red-700' : '' ?>">Due: <?= $actionLabel[$next][0] ?></span><?php endif; ?>
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
        <span><span class="block font-semibold text-gray-900">Show members their activity points</span><span class="block text-xs text-gray-500">Adds an "Activity &amp; standing" card to each member's Membership page. Nothing is sent automatically.</span></span>
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
      <p class="text-xs text-gray-500">Steps for members below target: first reminder → at least <?= (int)$rules['grace'] ?> days → final reminder → at least <?= (int)$rules['grace'] ?> days → pause. Each step is applied only when you choose. Shares count when members use the LinkedIn button on an event (once per event).</p>
      <button class="rarl-btn rarl-btn-primary w-full justify-center"><i class="fa-solid fa-floppy-disk"></i> Save rules</button>
    </form>
  </section>
</div>
<script>
(function () {
  const rows = () => [...document.querySelectorAll('.apply-row')], bar = document.getElementById('apply-bar'), all = document.getElementById('apply-all');
  const sync = () => { const n = rows().filter(r => r.checked).length; document.getElementById('apply-n').textContent = n; bar.classList.toggle('hidden', !n); if (all) { all.checked = n && n === rows().length; all.indeterminate = n > 0 && n < rows().length; } };
  document.addEventListener('change', e => { if (e.target.classList.contains('apply-row')) sync(); });
  all?.addEventListener('change', () => { rows().forEach(r => r.checked = all.checked); sync(); });
  if (all && !rows().length) all.disabled = true;
})();
</script>
<?php }, 'standing', 'Membership standing');
