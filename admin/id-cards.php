<?php
/**
 * RARL Admin — ID cards.
 * One place for member ID cards: the default card design with a preview,
 * who has a card / needs a photo / is expiring, and batch actions to issue,
 * renew (3 more years) or refresh cards with the current design (same dates).
 * Cards verify only while the membership is active.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

const IDCARD_SOON_DAYS = 60;
$real = "m.status = 'active' AND m.email NOT LIKE '%@rarl.internal'";
$sets = [
    'issue'   => "$real AND (m.id_card_path IS NULL OR m.id_card_path = '') AND m.avatar_path IS NOT NULL AND m.avatar_path <> ''",
    'renew'   => "$real AND m.id_card_path IS NOT NULL AND m.id_card_path <> '' AND m.id_card_expires_at IS NOT NULL AND m.id_card_expires_at < DATE_ADD(CURDATE(), INTERVAL " . IDCARD_SOON_DAYS . " DAY)",
    'refresh' => "$real AND m.id_card_path IS NOT NULL AND m.id_card_path <> ''",
];
$count = fn(string $where) => (int)$pdo->query("SELECT COUNT(*) FROM members m WHERE $where")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if (!adminCsrfOk()) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']); exit; }
        header('Location: id-cards.php'); exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'batch') {
        // A few cards per request; 'refresh' walks members after the last id done so it always finishes.
        session_write_close();
        @set_time_limit(60);
        $op = $_POST['op'] ?? '';
        if (!isset($sets[$op])) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit; }
        $after = (int)($_POST['after'] ?? 0);
        $ids = $pdo->query("SELECT m.id FROM members m WHERE {$sets[$op]}" . ($op === 'refresh' ? " AND m.id > {$after}" : '') . " ORDER BY m.id LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
        $done = 0; $last = $after;
        foreach ($ids as $id) { if (issueIdCard((int)$id, $op === 'refresh')) $done++; $last = (int)$id; }
        $left = $op === 'refresh' ? (int)$pdo->query("SELECT COUNT(*) FROM members m WHERE {$sets['refresh']} AND m.id > {$last}")->fetchColumn() : $count($sets[$op]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'done' => $done, 'tried' => count($ids), 'left' => $left, 'after' => $last]);
        exit;
    }
    if ($action === 'set_design') {
        $id = (int)($_POST['template_id'] ?? 0);
        $t = $pdo->prepare("SELECT id FROM certificate_templates WHERE id = ? AND type = 'id_card'");
        $t->execute([$id]);
        if ($t->fetchColumn()) {
            $pdo->exec("UPDATE certificate_templates SET is_default = 0 WHERE type = 'id_card'");
            $pdo->prepare("UPDATE certificate_templates SET is_default = 1 WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Default ID card design updated. Use "Update all cards" to apply it to existing cards.'];
        }
    }
    header('Location: id-cards.php'); exit;
}

$stats = [
    'active'   => $count($real),
    'cards'    => $count($sets['refresh']),
    'issue'    => $count($sets['issue']),
    'nophoto'  => $count("$real AND (m.id_card_path IS NULL OR m.id_card_path = '') AND (m.avatar_path IS NULL OR m.avatar_path = '')"),
    'renew'    => $count($sets['renew']),
];
$templates = $pdo->query("SELECT * FROM certificate_templates WHERE type = 'id_card' ORDER BY is_default DESC, name")->fetchAll();
$sample = templateSampleData('id_card');
$filter = $_GET['show'] ?? 'cards';
$listWhere = match ($filter) {
    'issue'   => $sets['issue'],
    'nophoto' => "$real AND (m.id_card_path IS NULL OR m.id_card_path = '') AND (m.avatar_path IS NULL OR m.avatar_path = '')",
    'renew'   => $sets['renew'],
    default   => $sets['refresh'],
};
$list = $pdo->query("SELECT m.id, m.type, m.full_name, m.lab_name, m.email, m.member_code, m.avatar_path, m.id_card_path, m.id_card_issued_at, m.id_card_expires_at
                     FROM members m WHERE $listWhere ORDER BY m.id_card_expires_at IS NULL, m.id_card_expires_at, m.id DESC LIMIT 300")->fetchAll();

adminWrap(function () use ($stats, $templates, $sample, $list, $filter) {
    adminFlash();
    $input = 'w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red';
    $default = null; foreach ($templates as $t) if ($t['is_default']) $default = $t;
?>
<div class="rarl-page-head">
  <div>
    <h1>ID cards</h1>
    <p>Member ID cards are valid for 3 years and verify only while the membership is active. A card needs the member's photo.</p>
  </div>
</div>

<div class="grid sm:grid-cols-5 gap-3 mb-5">
  <?php foreach ([['cards', 'Cards issued', $stats['cards']], ['issue', 'Ready to issue', $stats['issue']], ['nophoto', 'Waiting for a photo', $stats['nophoto']], ['renew', 'Expired or due in ' . IDCARD_SOON_DAYS . ' days', $stats['renew']], ['', 'Active members', $stats['active']]] as [$k, $l, $n]): ?>
  <?php if ($k): ?><a href="id-cards.php?show=<?= $k ?>" class="rarl-card p-4 hover:border-rarl-red <?= $filter === $k ? 'ring-2 ring-rarl-red/30 border-rarl-red' : '' ?>"><?php else: ?><div class="rarl-card p-4"><?php endif; ?>
    <span class="block text-xs text-gray-500"><?= $l ?></span><span class="block text-2xl font-bold text-gray-900"><?= number_format($n) ?></span>
  <?= $k ? '</a>' : '</div>' ?>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-5 items-start">
  <section class="rarl-card overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900"><?= ['issue' => 'Ready to issue (photo added)', 'nophoto' => 'Waiting for a photo', 'renew' => 'Expired or due soon'][$filter] ?? 'Cards issued' ?></h2></div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500"><tr><th class="px-4 py-2.5">Member</th><th class="px-3 py-2.5">Member no.</th><th class="px-3 py-2.5">Valid until</th><th class="px-3 py-2.5 text-right"></th></tr></thead>
      <tbody class="divide-y divide-gray-100">
      <?php if (!$list): ?><tr><td colspan="4" class="px-4 py-10 text-center text-gray-500">Nobody here.</td></tr><?php endif; ?>
      <?php foreach ($list as $m): $exp = $m['id_card_expires_at'] ? strtotime($m['id_card_expires_at']) : null; ?>
        <tr>
          <td class="px-4 py-2.5"><a href="member-edit.php?id=<?= (int)$m['id'] ?>" class="font-medium text-gray-900 hover:text-rarl-red"><?= ex(properName((string)($m['type'] === 'lab' ? ($m['lab_name'] ?: $m['full_name']) : $m['full_name']))) ?></a><span class="block text-xs text-gray-500"><?= ex($m['email']) ?></span></td>
          <td class="px-3 py-2.5 font-mono text-xs"><?= $m['member_code'] ? '#' . ex($m['member_code']) : '—' ?></td>
          <td class="px-3 py-2.5 text-xs <?= $exp && $exp < time() ? 'text-red-600 font-semibold' : ($exp && $exp < strtotime('+' . IDCARD_SOON_DAYS . ' days') ? 'text-amber-700 font-semibold' : 'text-gray-600') ?>"><?= $exp ? date('d M Y', $exp) . ($exp < time() ? ' (expired)' : '') : '—' ?></td>
          <td class="px-3 py-2.5 text-right"><?php if ($m['id_card_path']): ?><a class="rarl-icon-btn" title="Open card PDF" target="_blank" href="../uploads/id-cards/<?= rawurlencode(basename($m['id_card_path'])) ?>"><i class="fa-solid fa-id-card"></i></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

  <div class="space-y-5">
    <section class="rarl-card p-5">
      <div class="flex items-center justify-between gap-2 mb-3">
        <h2 class="font-semibold text-gray-900">Design</h2>
        <?php if ($default): ?><a href="templates.php?edit=<?= (int)$default['id'] ?>" class="text-sm font-semibold text-rarl-red hover:underline"><i class="fa-solid fa-pen-ruler"></i> Edit</a><?php endif; ?>
      </div>
      <?php if (!$templates): ?>
        <p class="text-sm text-gray-600">No ID card design yet, so cards use a simple built-in layout. Install a premade card in <a class="text-rarl-red font-semibold" href="templates.php#premade">Designs</a>.</p>
      <?php else: ?>
      <form method="POST" class="flex gap-2 mb-3"><?= acsrfField() ?><input type="hidden" name="action" value="set_design">
        <label class="sr-only" for="card-design">Default design</label>
        <select id="card-design" name="template_id" class="<?= $input ?>"><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['is_default'] ? 'selected' : '' ?>><?= ex($t['name']) ?><?= $t['is_default'] ? ' (current)' : '' ?></option><?php endforeach; ?></select>
        <button id="card-save" class="rarl-btn rarl-btn-primary" hidden>Use</button>
      </form>
      <div class="rounded-xl overflow-hidden border border-gray-100 bg-gray-50 max-w-sm mx-auto">
        <?php foreach ($templates as $t): ?><div data-cardpv="<?= (int)$t['id'] ?>" class="<?= $t['is_default'] ? '' : 'hidden' ?> pointer-events-none"><?= renderTemplateHtml($t, $sample) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="rarl-card p-5 space-y-3">
      <h2 class="font-semibold text-gray-900">Actions</h2>
      <button type="button" class="rarl-btn w-full justify-center" data-run="issue" data-n="<?= $stats['issue'] ?>" <?= $stats['issue'] ? '' : 'disabled' ?>><i class="fa-solid fa-id-card"></i> Issue <?= $stats['issue'] ?> card(s) to members with a photo</button>
      <button type="button" class="rarl-btn w-full justify-center" data-run="renew" data-n="<?= $stats['renew'] ?>" <?= $stats['renew'] ? '' : 'disabled' ?>><i class="fa-solid fa-calendar-plus"></i> Renew <?= $stats['renew'] ?> expired / due card(s) for 3 years</button>
      <button type="button" class="rarl-btn w-full justify-center" data-run="refresh" data-n="<?= $stats['cards'] ?>" <?= $stats['cards'] ? '' : 'disabled' ?>><i class="fa-solid fa-rotate"></i> Update all <?= $stats['cards'] ?> card(s) to the current design</button>
      <div id="run-progress" hidden><div class="h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-rarl-red transition-all" style="width:0"></div></div><p class="text-xs text-gray-600 mt-1.5" data-label></p></div>
      <p class="text-xs text-gray-500">Updating keeps each card's dates; renewing starts a new 3-year period. Members without a photo are asked for one on their Membership page.</p>
    </section>
  </div>
</div>

<script>
(function () {
  const sel = document.getElementById('card-design'), save = document.getElementById('card-save'), cur = sel ? sel.value : null;
  sel?.addEventListener('change', () => { document.querySelectorAll('[data-cardpv]').forEach(p => p.classList.toggle('hidden', p.dataset.cardpv !== sel.value)); save.hidden = sel.value === cur; });
  const box = document.getElementById('run-progress'), bar = box.querySelector('div > div'), label = box.querySelector('[data-label]');
  const csrf = <?= json_encode($GLOBALS['acsrf'] ?? '') ?>;
  document.querySelectorAll('[data-run]').forEach(b => b.addEventListener('click', async () => {
    const op = b.dataset.run, total = Math.max(1, +b.dataset.n);
    document.querySelectorAll('[data-run]').forEach(x => x.disabled = true);
    box.hidden = false;
    let done = 0, tried = 0, after = 0;
    try {
      for (;;) {
        const fd = new FormData(); fd.append('action', 'batch'); fd.append('op', op); fd.append('after', after); fd.append('acsrf', csrf);
        const d = await (await fetch('id-cards.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
        if (!d.ok) throw new Error(d.error || 'Failed');
        done += d.done; tried += d.tried; after = d.after;
        bar.style.width = Math.min(100, Math.round(tried / total * 100)) + '%';
        label.textContent = done + ' of ' + total + ' done…';
        if (!d.left || !d.tried || (op !== 'refresh' && !d.done)) break;
      }
      label.textContent = done + ' card(s) done' + (tried > done ? ', ' + (tried - done) + ' could not be made (check the photo)' : '') + '.';
      setTimeout(() => location.reload(), 1500);
    } catch (e) { label.textContent = 'Stopped: ' + e.message; document.querySelectorAll('[data-run]').forEach(x => x.disabled = false); }
  }));
})();
</script>
<?php }, 'id-cards', 'ID cards');
