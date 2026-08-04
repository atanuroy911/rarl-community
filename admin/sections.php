<?php
/**
 * RARL Admin — Regional Sections / Chapter Leadership
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
seedRegionalSectionsIfEmpty();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

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

        if ($name && $continent) {
            if ($action === 'add_section') {
                $pdo->prepare("INSERT INTO regional_sections (scope,continent,country,name,chair_name,chair_email,chair_title,display_order,is_published)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$scope,$continent,$country,$name,$chairName,$chairEmail,$chairTitle,$order,$pub]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Section created.'];
            } else {
                $id = (int)($_POST['section_id'] ?? 0);
                if ($id) {
                    $pdo->prepare("UPDATE regional_sections SET scope=?,continent=?,country=?,name=?,chair_name=?,chair_email=?,chair_title=?,display_order=?,is_published=? WHERE id=?")
                        ->execute([$scope,$continent,$country,$name,$chairName,$chairEmail,$chairTitle,$order,$pub,$id]);
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Section updated.'];
                }
            }
            // Every chair gets member-portal login access — no-op if this email already has an account.
            if ($chairEmail && createChairAccountIfMissing($chairEmail, $chairName)) {
                $_SESSION['flash'] = ['type'=>'success','msg'=>$_SESSION['flash']['msg'] . ' A chair account was created and the temporary password emailed to ' . $chairEmail . '.'];
            }
        }
        header('Location: sections.php'); exit;
    }

    if ($action === 'delete_section') {
        $pdo->prepare("DELETE FROM regional_sections WHERE id=?")->execute([(int)($_POST['section_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Section deleted.'];
        header('Location: sections.php'); exit;
    }
    if ($action === 'toggle_pub') {
        $pdo->prepare("UPDATE regional_sections SET is_published = 1 - is_published WHERE id=?")->execute([(int)($_POST['section_id']??0)]);
        header('Location: sections.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'publish') { $pdo->exec("UPDATE regional_sections SET is_published=1 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' section(s) published.']; }
            elseif ($bulkOp === 'unpublish') { $pdo->exec("UPDATE regional_sections SET is_published=0 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' section(s) unpublished.']; }
            elseif ($bulkOp === 'delete') { $pdo->exec("DELETE FROM regional_sections WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' section(s) deleted.']; }
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one section first.'];
        }
        header('Location: sections.php'); exit;
    }
}

$sections = $pdo->query("SELECT * FROM regional_sections ORDER BY continent, scope, display_order, id")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
if ($editId) {
    $q = $pdo->prepare("SELECT * FROM regional_sections WHERE id=?");
    $q->execute([$editId]);
    $edit = $q->fetch();
}

adminWrap(function() use ($sections, $edit) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Regional Sections</h1>
<p class="text-gray-500 text-sm mb-7">Manage continent/country chapter leadership shown on Community and used for auto "nearest chair" assignment at registration.</p>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Left: form -->
  <div class="xl:col-span-1 space-y-6">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4"><?= $edit ? '<i class="fa-solid fa-pen"></i> Edit Section' : '<i class="fa-solid fa-plus"></i> Add Section' ?></h2>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update_section' : 'add_section' ?>">
        <?php if ($edit): ?><input type="hidden" name="section_id" value="<?= $edit['id'] ?>"><?php endif; ?>

        <select name="scope" id="scope-select" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
          <option value="continent" <?= ($edit['scope'] ?? 'continent') === 'continent' ? 'selected' : '' ?>>Continent</option>
          <option value="country" <?= ($edit['scope'] ?? '') === 'country' ? 'selected' : '' ?>>Country</option>
        </select>

        <input type="text" name="continent" required value="<?= htmlspecialchars($edit['continent'] ?? '') ?>" placeholder="Continent (e.g. Asia)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <input type="text" name="country" id="country-input" value="<?= htmlspecialchars($edit['country'] ?? '') ?>" placeholder="Country (only when scope = Country)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <p class="text-[10px] text-gray-400">Country is ignored server-side when scope is Continent.</p>

        <input type="text" name="name" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>" placeholder="Display Name (e.g. Asia Section)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>

        <div class="grid grid-cols-2 gap-2">
          <input type="text" name="chair_name" value="<?= htmlspecialchars($edit['chair_name'] ?? '') ?>" placeholder="Chair Name"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          <input type="email" name="chair_email" value="<?= htmlspecialchars($edit['chair_email'] ?? '') ?>" placeholder="Chair Email"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        </div>
        <input type="text" name="chair_title" value="<?= htmlspecialchars($edit['chair_title'] ?? 'Chapter Chair') ?>" placeholder="Chair Title"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>

        <input type="number" name="display_order" value="<?= htmlspecialchars($edit['display_order'] ?? 0) ?>" placeholder="Order"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>

        <label class="flex items-center gap-2 p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer">
          <input type="checkbox" name="is_published" value="1" <?= ($edit['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-3.5 h-3.5"/>
          <span class="text-xs text-gray-600">Published</span>
        </label>

        <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors"><?= $edit ? 'Save Changes' : 'Add Section' ?></button>
        <?php if ($edit): ?><a href="sections.php" class="block text-center text-xs text-gray-400 hover:text-gray-600 mt-1">Cancel edit</a><?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Right: list -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Sections <span class="text-gray-400 font-normal text-xs">(<?= count($sections) ?>)</span></h2>
        <?php if ($sections): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
      </div>
      <?php if ($sections): ?>
      <?= bulkFormOpen() ?>
      <?= bulkBar([
          ['label'=>'Publish','op'=>'publish','class'=>'bg-green-600 hover:bg-green-500'],
          ['label'=>'Unpublish','op'=>'unpublish','class'=>'bg-amber-600 hover:bg-amber-500'],
          ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected sections?'],
      ]) ?>
      <?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php if (empty($sections)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No sections yet. Add one on the left.</p>
        <?php endif; ?>
        <?php foreach ($sections as $s): ?>
        <div class="p-4 flex items-center gap-4 hover:bg-gray-50 group">
          <?= bulkRowCheckbox((int)$s['id']) ?>
          <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center text-lg flex-shrink-0"><?= $s['scope'] === 'continent' ? '<i class="fa-solid fa-earth-americas"></i>' : '<i class="fa-solid fa-location-dot"></i>' ?></div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars($s['continent']) ?><?= $s['country'] ? ' / ' . htmlspecialchars($s['country']) : '' ?></span>
              <?php if (!$s['is_published']): ?><span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">Draft</span><?php endif; ?>
            </div>
            <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($s['name']) ?></p>
            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($s['chair_title']) ?>: <?= htmlspecialchars($s['chair_name'] ?: '—') ?><?= $s['chair_email'] ? ' (' . htmlspecialchars($s['chair_email']) . ')' : '' ?></p>
          </div>
          <div class="flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
            <a href="sections.php?edit=<?= $s['id'] ?>" class="px-2 py-1 text-[10px] font-semibold bg-blue-50 text-blue-600 rounded text-center">Edit</a>
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pub"><input type="hidden" name="section_id" value="<?= $s['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold <?= $s['is_published']?'bg-gray-100 text-gray-600':'bg-green-50 text-green-600' ?> rounded"><?= $s['is_published']?'Unpublish':'Publish' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this section?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_section"><input type="hidden" name="section_id" value="<?= $s['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<script>
  const scopeSel = document.getElementById('scope-select');
  const countryInput = document.getElementById('country-input');
  function syncCountryField() {
    const isCountry = scopeSel.value === 'country';
    countryInput.disabled = !isCountry;
    countryInput.classList.toggle('opacity-50', !isCountry);
  }
  scopeSel.addEventListener('change', syncCountryField);
  syncCountryField();
</script>
<?= bulkBarScript() ?>
<?php }, 'sections', 'Regional Sections');
