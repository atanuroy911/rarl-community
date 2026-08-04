<?php
/**
 * RARL Admin — Membership Plans
 * Internal back-office CRUD for membership_plans (no public pricing page uses this yet).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_plan' || $action === 'update_plan') {
        $slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($_POST['slug'] ?? '')));
        $slug     = trim($slug, '-');
        $name     = clean($_POST['name'] ?? '');
        $tagline  = clean($_POST['tagline'] ?? '');
        $desc     = clean($_POST['description'] ?? '');
        $features = clean($_POST['features'] ?? '');
        $audience = in_array($_POST['audience'] ?? '', ['individual','lab','both'], true) ? $_POST['audience'] : 'both';
        $price    = trim($_POST['regular_price'] ?? '') !== '' ? (float)$_POST['regular_price'] : null;
        $currency = clean($_POST['currency'] ?? 'EUR') ?: 'EUR';
        $period   = clean($_POST['billing_period'] ?? 'year') ?: 'year';
        $freeNow  = !empty($_POST['is_free_now']) ? 1 : 0;
        $freeNote = clean($_POST['free_note'] ?? '');
        $order    = (int)($_POST['display_order'] ?? 0);
        $pub      = !empty($_POST['is_published']) ? 1 : 0;

        if ($name && $slug) {
            if ($action === 'add_plan') {
                $pdo->prepare("INSERT INTO membership_plans (slug,name,tagline,description,features,audience,regular_price,currency,billing_period,is_free_now,free_note,display_order,is_published)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$slug,$name,$tagline,$desc,$features,$audience,$price,$currency,$period,$freeNow,$freeNote,$order,$pub]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Plan created.'];
            } else {
                $id = (int)($_POST['plan_id'] ?? 0);
                if ($id) {
                    $pdo->prepare("UPDATE membership_plans SET slug=?,name=?,tagline=?,description=?,features=?,audience=?,regular_price=?,currency=?,billing_period=?,is_free_now=?,free_note=?,display_order=?,is_published=? WHERE id=?")
                        ->execute([$slug,$name,$tagline,$desc,$features,$audience,$price,$currency,$period,$freeNow,$freeNote,$order,$pub,$id]);
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Plan updated.'];
                }
            }
        }
        header('Location: plans.php'); exit;
    }

    if ($action === 'delete_plan') {
        $pdo->prepare("DELETE FROM membership_plans WHERE id=?")->execute([(int)($_POST['plan_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Plan deleted.'];
        header('Location: plans.php'); exit;
    }
    if ($action === 'toggle_pub') {
        $pdo->prepare("UPDATE membership_plans SET is_published = 1 - is_published WHERE id=?")->execute([(int)($_POST['plan_id']??0)]);
        header('Location: plans.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'publish') { $pdo->exec("UPDATE membership_plans SET is_published=1 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' plan(s) published.']; }
            elseif ($bulkOp === 'unpublish') { $pdo->exec("UPDATE membership_plans SET is_published=0 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' plan(s) unpublished.']; }
            elseif ($bulkOp === 'delete') { $pdo->exec("DELETE FROM membership_plans WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' plan(s) deleted.']; }
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one plan first.'];
        }
        header('Location: plans.php'); exit;
    }
}

$plans = $pdo->query("SELECT * FROM membership_plans ORDER BY display_order, id")->fetchAll();
$editPlanId = (int)($_GET['edit'] ?? 0);
$editPlan   = null;
if ($editPlanId) {
    $q = $pdo->prepare("SELECT * FROM membership_plans WHERE id=?");
    $q->execute([$editPlanId]);
    $editPlan = $q->fetch();
}

adminWrap(function() use ($plans, $editPlan) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Membership Plans</h1>
<p class="text-gray-500 text-sm mb-7">Internal categorization for members (Free / Student / Organization). Everyone gets identical access — this is not a public pricing page.</p>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Left: form -->
  <div class="xl:col-span-1 space-y-6">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4"><?= $editPlan ? '<i class="fa-solid fa-pen-to-square"></i> Edit Plan' : '<i class="fa-solid fa-plus"></i> Add Plan' ?></h2>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?>
        <input type="hidden" name="action" value="<?= $editPlan ? 'update_plan' : 'add_plan' ?>">
        <?php if ($editPlan): ?><input type="hidden" name="plan_id" value="<?= $editPlan['id'] ?>"><?php endif; ?>

        <div class="grid grid-cols-2 gap-2">
          <input type="text" name="name" required value="<?= htmlspecialchars($editPlan['name'] ?? '') ?>" placeholder="Plan Name"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
          <input type="text" name="slug" required value="<?= htmlspecialchars($editPlan['slug'] ?? '') ?>" placeholder="slug"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <input type="text" name="tagline" value="<?= htmlspecialchars($editPlan['tagline'] ?? '') ?>" placeholder="Tagline"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <textarea name="description" rows="2" placeholder="Description"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"><?= htmlspecialchars($editPlan['description'] ?? '') ?></textarea>
        <textarea name="features" rows="3" placeholder="Features — one per line"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"><?= htmlspecialchars($editPlan['features'] ?? '') ?></textarea>

        <select name="audience" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
          <?php foreach (['individual'=>'Individual','lab'=>'Lab','both'=>'Both'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($editPlan['audience'] ?? 'both') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>

        <div class="grid grid-cols-3 gap-2">
          <input type="number" step="0.01" name="regular_price" value="<?= htmlspecialchars($editPlan['regular_price'] ?? '') ?>" placeholder="Price"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          <input type="text" name="currency" value="<?= htmlspecialchars($editPlan['currency'] ?? 'EUR') ?>" placeholder="EUR"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          <input type="text" name="billing_period" value="<?= htmlspecialchars($editPlan['billing_period'] ?? 'year') ?>" placeholder="year"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        </div>
        <p class="text-[10px] text-gray-400">Regular price is stored for future reference only — not shown publicly while free.</p>

        <input type="text" name="free_note" value="<?= htmlspecialchars($editPlan['free_note'] ?? 'Free for a limited time') ?>" placeholder="Free note"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>

        <input type="number" name="display_order" value="<?= htmlspecialchars($editPlan['display_order'] ?? 0) ?>" placeholder="Order"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>

        <label class="flex items-center gap-2 p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer">
          <input type="checkbox" name="is_free_now" value="1" <?= ($editPlan['is_free_now'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-3.5 h-3.5"/>
          <span class="text-xs text-gray-600">Free right now</span>
        </label>
        <label class="flex items-center gap-2 p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer">
          <input type="checkbox" name="is_published" value="1" <?= ($editPlan['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-3.5 h-3.5"/>
          <span class="text-xs text-gray-600">Published</span>
        </label>

        <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors"><?= $editPlan ? 'Save Changes' : 'Add Plan' ?></button>
        <?php if ($editPlan): ?><a href="plans.php" class="block text-center text-xs text-gray-400 hover:text-gray-600 mt-1">Cancel edit</a><?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Right: list -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Plans <span class="text-gray-400 font-normal text-xs">(<?= count($plans) ?>)</span></h2>
        <?php if ($plans): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
      </div>
      <?php if ($plans): ?>
      <?= bulkFormOpen() ?>
      <?= bulkBar([
          ['label'=>'Publish','op'=>'publish','class'=>'bg-green-600 hover:bg-green-500'],
          ['label'=>'Unpublish','op'=>'unpublish','class'=>'bg-amber-600 hover:bg-amber-500'],
          ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected plans?'],
      ]) ?>
      <?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php if (empty($plans)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No plans yet. Add one on the left.</p>
        <?php endif; ?>
        <?php foreach ($plans as $p): ?>
        <div class="p-4 flex items-center gap-4 hover:bg-gray-50 group">
          <?= bulkRowCheckbox((int)$p['id']) ?>
          <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center text-lg flex-shrink-0"><i class="fa-solid fa-graduation-cap"></i></div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= ucfirst($p['audience']) ?></span>
              <?php if (!$p['is_published']): ?><span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">Draft</span><?php endif; ?>
              <?php if ($p['is_free_now']): ?><span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded">Free now</span><?php endif; ?>
              <?php if ($p['regular_price'] !== null): ?><span class="text-[10px] font-bold text-gray-400"><?= number_format((float)$p['regular_price'], 0) ?> <?= htmlspecialchars($p['currency']) ?>/<?= htmlspecialchars($p['billing_period']) ?></span><?php endif; ?>
            </div>
            <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($p['name']) ?> <span class="text-gray-400 font-normal text-xs">(<?= htmlspecialchars($p['slug']) ?>)</span></p>
            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($p['tagline']) ?></p>
          </div>
          <div class="flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
            <a href="plans.php?edit=<?= $p['id'] ?>" class="px-2 py-1 text-[10px] font-semibold bg-blue-50 text-blue-600 rounded text-center">Edit</a>
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pub"><input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold <?= $p['is_published']?'bg-gray-100 text-gray-600':'bg-green-50 text-green-600' ?> rounded"><?= $p['is_published']?'Unpublish':'Publish' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this plan?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?= bulkBarScript() ?>
<?php }, 'plans', 'Membership Plans');
