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
    adminFlash();
    $v = fn($k, $d = '') => ex($editPlan[$k] ?? $d); ?>
<div class="rarl-page-head">
  <div><h1>Membership plans</h1><p>Internal categories for members (e.g. Student, Researcher, Lab). Everyone gets the same access — this is not a public pricing page.</p></div>
  <a href="plans.php?new=1" class="rarl-btn rarl-btn-primary" data-amodal="plan-modal" <?= $editPlan ? '' : 'data-plan-new' ?>><i class="fa-solid fa-plus"></i> New plan</a>
</div>

<div class="rarl-card overflow-hidden">
  <div class="px-5 py-4 border-b border-[color:var(--border)] flex items-center justify-between gap-3">
    <h2 class="rarl-card-title">All plans <span class="font-normal text-[color:var(--muted-fg)]"><?= count($plans) ?></span></h2>
    <?php if ($plans): ?><label class="flex items-center gap-1.5 text-xs text-[color:var(--muted-fg)]"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
  </div>
  <?php if (!$plans): ?><div class="rarl-empty">No plans yet. <a class="underline font-semibold" href="plans.php?new=1">Create the first one</a>.</div><?php else: ?>
  <?= bulkFormOpen() ?>
  <?= bulkBar([
      ['label'=>'Publish','op'=>'publish','class'=>'bg-green-600 hover:bg-green-500'],
      ['label'=>'Unpublish','op'=>'unpublish','class'=>'bg-amber-600 hover:bg-amber-500'],
      ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected plans?'],
  ]) ?>
  <ul class="divide-y divide-[color:var(--border)]">
    <?php foreach ($plans as $p): ?>
    <li class="px-4 sm:px-5 py-3.5 flex items-center gap-3 sm:gap-4">
      <?= bulkRowCheckbox((int)$p['id']) ?>
      <span class="w-10 h-10 rounded-[var(--radius)] bg-[color:var(--muted)] flex items-center justify-center flex-shrink-0 text-[color:var(--muted-fg)]"><i class="fa-solid fa-id-badge"></i></span>
      <a href="plans.php?edit=<?= (int)$p['id'] ?>" class="flex-1 min-w-0">
        <span class="flex flex-wrap items-center gap-1.5"><span class="font-semibold text-sm hover:underline"><?= ex($p['name']) ?></span>
          <span class="rarl-badge rarl-badge-gray"><?= ['individual' => 'Individuals', 'lab' => 'Labs', 'both' => 'Everyone'][$p['audience']] ?? ucfirst($p['audience']) ?></span>
          <?php if (!$p['is_published']): ?><span class="rarl-badge rarl-badge-amber">Draft</span><?php endif; ?>
          <?php if ($p['is_free_now']): ?><span class="rarl-badge rarl-badge-green">Free now</span><?php endif; ?></span>
        <span class="block text-xs text-[color:var(--muted-fg)] truncate mt-0.5"><?= ex($p['tagline'] ?: $p['slug']) ?><?= $p['regular_price'] !== null ? ' · later ' . number_format((float)$p['regular_price'], 0) . ' ' . ex($p['currency']) . '/' . ex($p['billing_period']) : '' ?></span>
      </a>
      <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pub"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>"><button class="rarl-icon-btn" title="<?= $p['is_published'] ? 'Unpublish' : 'Publish' ?>" data-no-loading><i class="fa-solid <?= $p['is_published'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
      <a href="plans.php?edit=<?= (int)$p['id'] ?>" class="rarl-icon-btn" title="Edit"><i class="fa-solid fa-pen"></i></a>
      <form method="POST" data-confirm="Delete the “<?= ex($p['name']) ?>” plan?"><?= acsrfField() ?><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<?php adminModalOpen('plan-modal', $editPlan ? 'Edit plan' : 'New plan', 'Members are assigned a plan by admins; it never changes what they can access.', ['form' => true, 'width' => '620px', 'autoopen' => $editPlan || !empty($_GET['new'])]); ?>
  <input type="hidden" name="action" value="<?= $editPlan ? 'update_plan' : 'add_plan' ?>">
  <?php if ($editPlan): ?><input type="hidden" name="plan_id" value="<?= (int)$editPlan['id'] ?>"><?php endif; ?>
  <div class="grid sm:grid-cols-2 gap-3">
    <div><label class="rarl-label" for="pl-name">Name <span class="text-rarl-red">*</span></label><input id="pl-name" name="name" required class="rarl-input" value="<?= $v('name') ?>" placeholder="Researcher"/></div>
    <div><label class="rarl-label" for="pl-slug">Short ID <span class="text-rarl-red">*</span></label><input id="pl-slug" name="slug" required pattern="[a-z0-9-]+" class="rarl-input font-mono" value="<?= $v('slug') ?>" placeholder="researcher"/><p class="text-xs text-[color:var(--muted-fg)] mt-1">Lowercase letters, numbers and dashes.</p></div>
  </div>
  <div><label class="rarl-label" for="pl-tag">Tagline</label><input id="pl-tag" name="tagline" class="rarl-input" value="<?= $v('tagline') ?>"/></div>
  <div><label class="rarl-label" for="pl-desc">Description</label><textarea id="pl-desc" name="description" rows="2" class="rarl-input"><?= $v('description') ?></textarea></div>
  <div><label class="rarl-label" for="pl-feat">What's included <span class="font-normal text-[color:var(--muted-fg)]">— one per line</span></label><textarea id="pl-feat" name="features" rows="3" class="rarl-input"><?= $v('features') ?></textarea></div>
  <div class="grid sm:grid-cols-2 gap-3">
    <div><label class="rarl-label" for="pl-aud">For</label><select id="pl-aud" name="audience" class="rarl-input"><?php foreach (['both' => 'Everyone', 'individual' => 'Individuals', 'lab' => 'Research labs'] as $k => $l): ?><option value="<?= $k ?>" <?= ($editPlan['audience'] ?? 'both') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label class="rarl-label" for="pl-free">Note shown while free</label><input id="pl-free" name="free_note" class="rarl-input" value="<?= $v('free_note', 'Free for a limited time') ?>"/></div>
  </div>
  <fieldset class="p-3 rounded-[var(--radius)] border border-[color:var(--border)]">
    <legend class="px-1 text-xs text-[color:var(--muted-fg)]">Future price (stored only, never shown while free)</legend>
    <div class="grid grid-cols-3 gap-2">
      <div><label class="rarl-label" for="pl-price">Amount</label><input id="pl-price" type="number" step="0.01" min="0" name="regular_price" class="rarl-input" value="<?= $v('regular_price') ?>"/></div>
      <div><label class="rarl-label" for="pl-cur">Currency</label><input id="pl-cur" name="currency" maxlength="3" class="rarl-input uppercase" value="<?= $v('currency', 'EUR') ?>"/></div>
      <div><label class="rarl-label" for="pl-per">Per</label><input id="pl-per" name="billing_period" class="rarl-input" value="<?= $v('billing_period', 'year') ?>"/></div>
    </div>
  </fieldset>
  <input type="hidden" name="display_order" value="<?= (int)($editPlan['display_order'] ?? 0) ?>">
  <div class="flex flex-wrap gap-5 text-sm">
    <label class="flex items-center gap-2"><input type="checkbox" name="is_free_now" value="1" <?= ($editPlan['is_free_now'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4"> Free right now</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="is_published" value="1" <?= ($editPlan['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4"> Published</label>
  </div>
<?php adminModalClose($editPlan ? 'Save changes' : 'Create plan', '', true); ?>
<script>
  // Closing the edit modal returns to the plain list URL.
  document.getElementById('plan-modal').addEventListener('close', function () { if (/[?&](edit|new)=/.test(location.search)) history.replaceState(null, '', 'plans.php'); });
  // "New plan" while an edit is loaded: go to a clean form.
  document.querySelectorAll('a[href="plans.php?new=1"]').forEach(function (a) { a.addEventListener('click', function (e) { <?php if ($editPlan): ?>e.stopImmediatePropagation(); location.href = a.href;<?php endif; ?> }, true); });
  // Short ID follows the name until edited by hand.
  (function () { var n = document.getElementById('pl-name'), s = document.getElementById('pl-slug'), touched = !!s.value;
    s.addEventListener('input', function () { touched = true; });
    n.addEventListener('input', function () { if (!touched) s.value = n.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); }); })();
</script>
<?= bulkBarScript() ?>
<?php }, 'plans', 'Membership Plans');
