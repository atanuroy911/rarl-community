<?php
/**
 * RARL Admin — Chapters & Chapter Leadership
 * Shown as the organisation actually is: continents, with their country
 * chapters underneath, each with its chair and member count. Changing a chair
 * can refresh that chapter's ID cards (the chair signs them); deleting a
 * chapter unassigns its members instead of leaving them orphaned.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
seedRegionalSectionsIfEmpty();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['section_id'] ?? 0);

    if ($action === 'add_section' || $action === 'update_section') {
        $scope     = ($_POST['scope'] ?? '') === 'country' ? 'country' : 'continent';
        $continent = clean($_POST['continent'] ?? '');
        $country   = $scope === 'country' ? clean($_POST['country'] ?? '') : null;
        $name      = clean($_POST['name'] ?? '');
        $chairName = clean($_POST['chair_name'] ?? '');
        $chairEmail= cleanEmail($_POST['chair_email'] ?? '');
        $chairTitle= clean($_POST['chair_title'] ?? '') ?: 'Chapter Chair';
        $order     = (int)($_POST['display_order'] ?? 0);
        $pub       = !empty($_POST['is_published']) ? 1 : 0;

        if (!$name || !$continent) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'A chapter needs a name and a continent.'];
            header('Location: sections.php'); exit;
        }
        $msg = '';
        if ($action === 'add_section') {
            $pdo->prepare("INSERT INTO regional_sections (scope,continent,country,name,chair_name,chair_email,chair_title,display_order,is_published) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$scope,$continent,$country,$name,$chairName,$chairEmail,$chairTitle,$order,$pub]);
            $msg = 'Chapter created.';
        } elseif ($id) {
            $old = $pdo->prepare("SELECT chair_name FROM regional_sections WHERE id=?"); $old->execute([$id]); $oldChair = (string)$old->fetchColumn();
            $pdo->prepare("UPDATE regional_sections SET scope=?,continent=?,country=?,name=?,chair_name=?,chair_email=?,chair_title=?,display_order=?,is_published=? WHERE id=?")
                ->execute([$scope,$continent,$country,$name,$chairName,$chairEmail,$chairTitle,$order,$pub,$id]);
            $msg = 'Chapter updated.';
            if (!empty($_POST['refresh_cards'])) {
                @set_time_limit(0);
                $n = 0;
                $ms = $pdo->prepare("SELECT id FROM members WHERE section_id = ? AND status = 'active' AND id_card_path IS NOT NULL");
                $ms->execute([$id]);
                foreach ($ms->fetchAll(PDO::FETCH_COLUMN) as $mid) if (issueIdCard((int)$mid)) $n++;
                $msg .= " Refreshed {$n} ID card(s) with the new chapter details.";
            } elseif ($oldChair !== $chairName) {
                $msg .= ' Existing ID cards still show the previous chair until regenerated.';
            }
        }
        // Every chair gets member-portal login access — no-op if this email already has an account.
        if ($chairEmail && createChairAccountIfMissing($chairEmail, $chairName)) {
            $msg .= ' A chair account was created and the temporary password emailed to ' . $chairEmail . '.';
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>$msg];
        header('Location: sections.php'); exit;
    }

    if ($action === 'delete_section' && $id) {
        $n = $pdo->prepare("UPDATE members SET section_id = NULL WHERE section_id = ?"); $n->execute([$id]);
        $pdo->prepare("DELETE FROM regional_sections WHERE id=?")->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Chapter deleted.' . ($n->rowCount() ? ' ' . $n->rowCount() . ' member(s) are now unassigned — find them under Members → “No chapter yet”.' : '')];
        header('Location: sections.php'); exit;
    }
    if ($action === 'toggle_pub' && $id) {
        $pdo->prepare("UPDATE regional_sections SET is_published = 1 - is_published WHERE id=?")->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Visibility updated.'];
        header('Location: sections.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $in = implode(',', $ids);
            if ($bulkOp === 'publish')   $pdo->exec("UPDATE regional_sections SET is_published=1 WHERE id IN ({$in})");
            if ($bulkOp === 'unpublish') $pdo->exec("UPDATE regional_sections SET is_published=0 WHERE id IN ({$in})");
            if ($bulkOp === 'delete') { $pdo->exec("UPDATE members SET section_id = NULL WHERE section_id IN ({$in})"); $pdo->exec("DELETE FROM regional_sections WHERE id IN ({$in})"); }
            $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' chapter(s) updated.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one chapter first.'];
        }
        header('Location: sections.php'); exit;
    }
}

$sections = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM members m WHERE m.section_id = s.id AND m.status = 'active') AS members,
    (SELECT COUNT(*) FROM members m WHERE m.section_id = s.id AND m.status = 'active' AND m.id_card_path IS NOT NULL) AS cards
    FROM regional_sections s ORDER BY s.continent, s.scope, s.display_order, s.name")->fetchAll();
$unassigned = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status='active' AND section_id IS NULL")->fetchColumn();
$byContinent = [];
foreach ($sections as $s) $byContinent[$s['continent']][] = $s;
$continents = array_keys($byContinent);
$countries = array_values(array_unique(array_filter(array_merge(
    array_column($sections, 'country'),
    $pdo->query("SELECT DISTINCT country FROM members WHERE country IS NOT NULL AND country != '' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN)
))));
sort($countries);

adminWrap(function() use ($sections, $byContinent, $continents, $countries, $unassigned) {
    adminFlash();
    $totalMembers = array_sum(array_column($sections, 'members'));
    $noChair = count(array_filter($sections, fn($s) => !$s['chair_name']));
?>
<div class="rarl-page-head">
  <div>
    <h1>Chapters</h1>
    <p>Your organisation by region. Chairs sign their members' ID cards and get a chair login automatically.</p>
  </div>
  <button type="button" class="rarl-btn rarl-btn-primary" data-new><i class="fa-solid fa-plus"></i> New chapter</button>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-sitemap text-gray-400"></i> Chapters</span><span class="rarl-stat-value"><?= count($sections) ?></span><span class="text-[11px] text-gray-500">across <?= count($byContinent) ?> continents</span></div>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-users text-gray-400"></i> Members in chapters</span><span class="rarl-stat-value"><?= number_format($totalMembers) ?></span></div>
  <a href="members.php?tab=active&chapter=none" class="rarl-stat <?= $unassigned ? 'is-active' : '' ?>"><span class="rarl-stat-label"><i class="fa-solid fa-user-slash text-amber-500"></i> Unassigned members</span><span class="rarl-stat-value"><?= number_format($unassigned) ?></span><span class="text-[11px] text-gray-500"><?= $unassigned ? 'Assign by country in one click →' : 'everyone has a chapter' ?></span></a>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-crown text-gray-400"></i> Without a chair</span><span class="rarl-stat-value <?= $noChair ? 'text-amber-600' : '' ?>"><?= $noChair ?></span></div>
</div>

<?php if ($sections): ?><?= bulkFormOpen() ?><?= bulkBar([
    ['label'=>'Publish','op'=>'publish','class'=>'bg-green-600 hover:bg-green-500'],
    ['label'=>'Hide','op'=>'unpublish','class'=>'bg-amber-600 hover:bg-amber-500'],
    ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete the selected chapters? Their members become unassigned.'],
]) ?><?php endif; ?>

<?php if (!$sections): ?><div class="rarl-card rarl-empty">No chapters yet. <button type="button" class="text-rarl-red font-semibold" data-new>Create the first one</button></div><?php endif; ?>

<div class="space-y-6">
<?php foreach ($byContinent as $continent => $list): $contMembers = array_sum(array_column($list, 'members')); ?>
  <section class="rarl-card overflow-hidden">
    <div class="px-5 py-3.5 bg-gray-50/70 border-b border-gray-100 flex items-center gap-3">
      <span class="w-9 h-9 rounded-xl bg-gray-900 text-white flex items-center justify-center"><i class="fa-solid fa-earth-americas"></i></span>
      <div class="flex-1"><h2 class="rarl-card-title"><?= htmlspecialchars($continent) ?></h2><p class="text-[11px] text-gray-500"><?= count($list) ?> chapter<?= count($list) === 1 ? '' : 's' ?> · <?= $contMembers ?> active members</p></div>
      <button type="button" class="rarl-btn rarl-btn-sm" data-new data-continent="<?= htmlspecialchars($continent) ?>" data-scope="country"><i class="fa-solid fa-plus"></i> Country chapter</button>
    </div>
    <div class="divide-y divide-gray-100">
      <?php foreach ($list as $s): ?>
      <div class="flex items-center gap-4 px-5 py-3.5 <?= $s['scope'] === 'country' ? 'sm:pl-12' : '' ?> hover:bg-gray-50/60">
        <?= bulkRowCheckbox((int)$s['id']) ?>
        <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 <?= $s['scope'] === 'continent' ? 'bg-rarl-red/10 text-rarl-red' : 'bg-gray-100 text-gray-500' ?>"><i class="fa-solid <?= $s['scope'] === 'continent' ? 'fa-globe' : 'fa-location-dot' ?>"></i></span>
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-sm text-gray-900 flex items-center gap-2"><?= htmlspecialchars($s['name']) ?>
            <?= $s['scope'] === 'country' ? '<span class="rarl-badge rarl-badge-gray">' . htmlspecialchars($s['country']) . '</span>' : '<span class="rarl-badge rarl-badge-red">Continental</span>' ?>
            <?= !$s['is_published'] ? '<span class="rarl-badge rarl-badge-amber"><i class="fa-solid fa-eye-slash"></i> Hidden</span>' : '' ?></p>
          <p class="text-xs mt-0.5 truncate"><?php if ($s['chair_name']): ?><span class="text-gray-500"><i class="fa-solid fa-crown text-indigo-400"></i> <?= htmlspecialchars($s['chair_title']) ?>:</span> <span class="text-gray-800"><?= htmlspecialchars($s['chair_name']) ?></span><?= $s['chair_email'] ? ' <span class="text-gray-400">· ' . htmlspecialchars($s['chair_email']) . '</span>' : '' ?>
            <?php else: ?><button type="button" class="text-amber-600 font-semibold hover:underline" data-edit="<?= (int)$s['id'] ?>"><i class="fa-solid fa-crown"></i> Appoint a chair</button><?php endif; ?></p>
        </div>
        <a href="members.php?tab=active&chapter=<?= (int)$s['id'] ?>" class="text-right flex-shrink-0 hover:text-rarl-red" title="See these members">
          <span class="block font-heading font-black text-lg leading-none"><?= (int)$s['members'] ?></span><span class="text-[10px] text-gray-500">members</span></a>
        <div class="flex flex-shrink-0">
          <button type="button" class="rarl-icon-btn" data-edit="<?= (int)$s['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></button>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pub"><input type="hidden" name="section_id" value="<?= $s['id'] ?>"><button class="rarl-icon-btn" title="<?= $s['is_published'] ? 'Hide' : 'Publish' ?>" data-no-loading><i class="fa-regular <?= $s['is_published'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
          <form method="POST" data-confirm="Delete “<?= htmlspecialchars($s['name']) ?>”?<?= $s['members'] ? ' Its ' . (int)$s['members'] . ' member(s) will become unassigned (not deleted).' : '' ?>"><?= acsrfField() ?><input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?= $s['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-regular fa-trash-can"></i></button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<datalist id="continent-list"><?php foreach (array_unique(array_merge($continents, ['Africa','Asia','Europe','North America','South America','Oceania'])) as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>
<datalist id="country-list"><?php foreach ($countries as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?></datalist>

<div class="rarl-drawer-backdrop" id="sec-bg"></div>
<aside class="rarl-drawer" id="sec-drawer">
  <form method="POST" class="flex flex-col h-full" id="sec-form">
    <?= acsrfField() ?><input type="hidden" name="action" value="add_section"><input type="hidden" name="section_id" value="">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between"><h2 class="font-heading font-black text-lg" id="sec-title">New chapter</h2><button type="button" class="rarl-icon-btn" data-close><i class="fa-solid fa-xmark"></i></button></div>
    <div class="flex-1 overflow-y-auto p-5 space-y-4">
      <div>
        <label class="rarl-label">Level</label>
        <div class="grid grid-cols-2 gap-2">
          <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-rarl-red/5"><input type="radio" name="scope" value="continent" class="accent-rarl-red"/> <span class="text-sm font-semibold">Continent</span></label>
          <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-rarl-red/5"><input type="radio" name="scope" value="country" class="accent-rarl-red"/> <span class="text-sm font-semibold">Country</span></label>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="rarl-label">Continent *</label><input name="continent" list="continent-list" required class="rarl-input" placeholder="Asia"/></div>
        <div id="country-wrap"><label class="rarl-label">Country *</label><input name="country" list="country-list" class="rarl-input" placeholder="Pakistan"/></div>
      </div>
      <div><label class="rarl-label">Chapter name *</label><input name="name" required class="rarl-input" placeholder="e.g. Pakistan Chapter"/><p class="text-[11px] text-gray-400 mt-1" id="name-hint"></p></div>
      <div class="pt-2 border-t border-gray-100">
        <p class="rarl-label !text-gray-900 !text-sm mb-2"><i class="fa-solid fa-crown text-indigo-500"></i> Chair</p>
        <div class="grid grid-cols-2 gap-3">
          <div><label class="rarl-label">Name</label><input name="chair_name" class="rarl-input"/></div>
          <div><label class="rarl-label">Title</label><input name="chair_title" class="rarl-input" placeholder="Chapter Chair"/></div>
        </div>
        <div class="mt-3"><label class="rarl-label">Email</label><input type="email" name="chair_email" class="rarl-input" placeholder="Gets a chair login automatically"/></div>
        <label id="refresh-wrap" class="hidden mt-3 flex items-start gap-2.5 p-3 bg-blue-50 border border-blue-200 rounded-xl cursor-pointer text-sm">
          <input type="checkbox" name="refresh_cards" value="1" class="w-4 h-4 mt-0.5 accent-rarl-red"/>
          <span><span class="font-semibold text-gray-800">Refresh this chapter's ID cards</span><span class="block text-xs text-gray-500" id="refresh-sub">Cards print the chair as second signer.</span></span>
        </label>
      </div>
      <div class="grid grid-cols-2 gap-3 pt-2 border-t border-gray-100">
        <div><label class="rarl-label">Display order</label><input type="number" name="display_order" class="rarl-input" value="0"/></div>
        <label class="flex items-center gap-2 mt-6 text-sm"><input type="checkbox" name="is_published" value="1" checked class="accent-rarl-red w-4 h-4"/> Visible to members</label>
      </div>
    </div>
    <div class="p-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50/70"><button type="button" class="rarl-btn" data-close>Cancel</button><button class="rarl-btn rarl-btn-primary" id="sec-submit">Create chapter</button></div>
  </form>
</aside>

<?= bulkBarScript() ?>
<script>
(function() {
  const DATA = <?= json_encode(array_column($sections, null, 'id'), JSON_HEX_TAG) ?>;
  const d = document.getElementById('sec-drawer'), bg = document.getElementById('sec-bg'), f = document.getElementById('sec-form');
  const scopeSync = () => {
    const country = f.querySelector('[name=scope]:checked')?.value === 'country';
    document.getElementById('country-wrap').style.display = country ? '' : 'none';
    f.country.required = country;
    document.getElementById('name-hint').textContent = !f.elements.section_id.value && !f.elements.name.dataset.touched ? '' : '';
  };
  function open(s, preset) {
    f.reset();
    const v = s || Object.assign({scope: 'continent', display_order: 0, is_published: 1, chair_title: 'Chapter Chair'}, preset || {});
    f.elements.action.value = s ? 'update_section' : 'add_section';
    f.elements.section_id.value = s ? s.id : '';
    f.querySelector('[name=scope][value=' + (v.scope || 'continent') + ']').checked = true;
    ['continent', 'country', 'name', 'chair_name', 'chair_title', 'chair_email', 'display_order'].forEach(k => f.elements[k].value = v[k] ?? '');
    f.elements.is_published.checked = +v.is_published === 1;
    const cards = s ? +s.cards : 0;
    document.getElementById('refresh-wrap').classList.toggle('hidden', !cards);
    document.getElementById('refresh-sub').textContent = 'Regenerates ' + cards + ' card(s) — they print the chapter name and chair as second signer.';
    document.getElementById('sec-title').textContent = s ? 'Edit chapter' : 'New chapter';
    document.getElementById('sec-submit').textContent = s ? 'Save changes' : 'Create chapter';
    scopeSync(); d.classList.add('open'); bg.classList.add('open');
    setTimeout(() => f.elements[s ? 'chair_name' : (v.continent ? 'country' : 'continent')].focus(), 250);
  }
  const close = () => { d.classList.remove('open'); bg.classList.remove('open'); };
  f.querySelectorAll('[name=scope]').forEach(r => r.addEventListener('change', scopeSync));
  // Suggest a chapter name from the country/continent as you type.
  ['country', 'continent'].forEach(k => f.elements[k].addEventListener('input', () => {
    if (f.elements.section_id.value || f.elements.name.dataset.touched) return;
    const country = f.querySelector('[name=scope]:checked').value === 'country';
    const base = country ? f.elements.country.value : f.elements.continent.value;
    f.elements.name.value = base ? base + ' Chapter' : '';
  }));
  f.elements.name.addEventListener('input', () => f.elements.name.dataset.touched = 1);
  // Chair changed → suggest refreshing the cards.
  f.elements.chair_name.addEventListener('input', () => { const s = DATA[f.elements.section_id.value]; if (s && +s.cards && f.elements.chair_name.value !== (s.chair_name || '')) f.elements.refresh_cards.checked = true; });
  document.querySelectorAll('[data-new]').forEach(b => b.addEventListener('click', () => open(null, b.dataset.continent ? {continent: b.dataset.continent, scope: b.dataset.scope} : null)));
  document.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', () => open(DATA[b.dataset.edit])));
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
  bg.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  const editId = new URLSearchParams(location.search).get('edit'); if (editId && DATA[editId]) open(DATA[editId]);
})();
</script>
<?php }, 'sections', 'Chapters');
