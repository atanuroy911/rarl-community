<?php
/**
 * RARL Admin — Partnerships & Membership Tiers
 * Manage the paid membership/partnership tiers and the copy shown on partners.php
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_tier') {
        $category = ($_POST['category'] ?? '') === 'membership' ? 'membership' : 'partnership';
        $name     = clean($_POST['name'] ?? '');
        $desc     = clean($_POST['description'] ?? '');
        $fee      = trim($_POST['fee_amount'] ?? '') !== '' ? (float)$_POST['fee_amount'] : null;
        $currency = clean($_POST['fee_currency'] ?? 'EUR') ?: 'EUR';
        $period   = clean($_POST['fee_period'] ?? 'year') ?: 'year';
        $addon    = !empty($_POST['is_addon']) ? 1 : 0;
        $order    = (int)($_POST['display_order'] ?? 0);
        $pub      = !empty($_POST['is_published']) ? 1 : 0;

        if ($name) {
            $pdo->prepare("INSERT INTO partnership_tiers (category,name,description,fee_amount,fee_currency,fee_period,is_addon,display_order,is_published)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$category, $name, $desc, $fee, $currency, $period, $addon, $order, $pub]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Tier created.'];
        }
        header('Location: partnerships.php'); exit;
    }

    if ($action === 'update_tier') {
        $id       = (int)($_POST['tier_id'] ?? 0);
        $category = ($_POST['category'] ?? '') === 'membership' ? 'membership' : 'partnership';
        $name     = clean($_POST['name'] ?? '');
        $desc     = clean($_POST['description'] ?? '');
        $fee      = trim($_POST['fee_amount'] ?? '') !== '' ? (float)$_POST['fee_amount'] : null;
        $currency = clean($_POST['fee_currency'] ?? 'EUR') ?: 'EUR';
        $period   = clean($_POST['fee_period'] ?? 'year') ?: 'year';
        $addon    = !empty($_POST['is_addon']) ? 1 : 0;
        $order    = (int)($_POST['display_order'] ?? 0);
        $pub      = !empty($_POST['is_published']) ? 1 : 0;

        if ($id && $name) {
            $pdo->prepare("UPDATE partnership_tiers SET category=?,name=?,description=?,fee_amount=?,fee_currency=?,fee_period=?,is_addon=?,display_order=?,is_published=? WHERE id=?")
                ->execute([$category, $name, $desc, $fee, $currency, $period, $addon, $order, $pub, $id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Tier updated.'];
        }
        header('Location: partnerships.php'); exit;
    }

    if ($action === 'delete_tier') {
        $pdo->prepare("DELETE FROM partnership_tiers WHERE id=?")->execute([(int)($_POST['tier_id'] ?? 0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Tier deleted.'];
        header('Location: partnerships.php'); exit;
    }

    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'publish') { $pdo->exec("UPDATE partnership_tiers SET is_published=1 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' tier(s) published.']; }
            elseif ($bulkOp === 'unpublish') { $pdo->exec("UPDATE partnership_tiers SET is_published=0 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' tier(s) unpublished.']; }
            elseif ($bulkOp === 'delete') { $pdo->exec("DELETE FROM partnership_tiers WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' tier(s) deleted.']; }
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one tier first.'];
        }
        header('Location: partnerships.php'); exit;
    }

    if ($action === 'save_copy') {
        $keys = ['partner_page_intro', 'partner_benefits_member', 'partner_benefits_partner', 'partner_ethics_note', 'partner_contact_name', 'partner_contact_email'];
        foreach ($keys as $k) {
            $v = clean($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$k, $v, $v]);
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Page content saved.'];
        header('Location: partnerships.php'); exit;
    }
}

$tiers = $pdo->query("SELECT * FROM partnership_tiers ORDER BY display_order, id")->fetchAll();
$set = [];
foreach ($pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'partner_%'")->fetchAll() as $r) $set[$r['key']] = $r['value'];
$editTierId = (int)($_GET['edit'] ?? 0);
$editTier   = null;
if ($editTierId) {
    $q = $pdo->prepare("SELECT * FROM partnership_tiers WHERE id=?");
    $q->execute([$editTierId]);
    $editTier = $q->fetch();
}

adminWrap(function() use ($tiers, $set, $editTier) {
    adminFlash(); ?>
<div class="rarl-page-head">
  <div><h1>Partnerships</h1><p>Tiers and copy for the public <a href="../partners.php" target="_blank" class="underline">Partner with RARL</a> page.</p></div>
  <a href="partnerships.php?new=1" class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-plus"></i> New tier</a>
</div>
<?php $v = fn($k, $d = '') => ex($editTier[$k] ?? $d); ?>
<?php adminModalOpen('tier-modal', $editTier ? 'Edit tier' : 'New tier', 'Shown as a card on the public partners page.', ['form' => true, 'width' => '560px', 'autoopen' => $editTier || !empty($_GET['new'])]); ?>
  <input type="hidden" name="action" value="<?= $editTier ? 'update_tier' : 'add_tier' ?>">
  <?php if ($editTier): ?><input type="hidden" name="tier_id" value="<?= (int)$editTier['id'] ?>"><?php endif; ?>
  <div class="grid sm:grid-cols-[1fr_180px] gap-3">
    <div><label class="rarl-label" for="t-name">Name <span class="text-rarl-red">*</span></label><input id="t-name" name="name" required class="rarl-input" value="<?= $v('name') ?>" placeholder="Gold partner"/></div>
    <div><label class="rarl-label" for="t-cat">Section</label><select id="t-cat" name="category" class="rarl-input"><option value="partnership" <?= ($editTier['category'] ?? 'partnership') === 'partnership' ? 'selected' : '' ?>>Partnership</option><option value="membership" <?= ($editTier['category'] ?? '') === 'membership' ? 'selected' : '' ?>>Membership</option></select></div>
  </div>
  <div><label class="rarl-label" for="t-desc">Description</label><textarea id="t-desc" name="description" rows="3" class="rarl-input"><?= $v('description') ?></textarea></div>
  <div class="grid grid-cols-3 gap-2">
    <div><label class="rarl-label" for="t-fee">Fee</label><input id="t-fee" type="number" step="0.01" min="0" name="fee_amount" class="rarl-input" value="<?= $v('fee_amount') ?>" placeholder="Hidden"/></div>
    <div><label class="rarl-label" for="t-cur">Currency</label><input id="t-cur" name="fee_currency" maxlength="3" class="rarl-input uppercase" value="<?= $v('fee_currency', 'EUR') ?>"/></div>
    <div><label class="rarl-label" for="t-per">Per</label><input id="t-per" name="fee_period" class="rarl-input" value="<?= $v('fee_period', 'year') ?>"/></div>
  </div>
  <p class="text-xs text-[color:var(--muted-fg)] -mt-2">Leave the fee empty to show the card without a price.</p>
  <input type="hidden" name="display_order" value="<?= (int)($editTier['display_order'] ?? 0) ?>">
  <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_addon" value="1" <?= !empty($editTier['is_addon']) ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4"> Show as a separate add-on card (e.g. "High visibility")</label>
  <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_published" value="1" <?= ($editTier['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4"> Published</label>
<?php adminModalClose($editTier ? 'Save changes' : 'Create tier', '', true); ?>
<script>document.getElementById('tier-modal').addEventListener('close', function () { if (/[?&](edit|new)=/.test(location.search)) history.replaceState(null, '', 'partnerships.php'); });</script>

<div class="space-y-6">
  <div>
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Tiers <span class="text-gray-400 font-normal text-xs">(<?= count($tiers) ?>)</span></h2>
        <?php if ($tiers): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
      </div>
      <?php if ($tiers): ?>
      <?= bulkFormOpen() ?>
      <?= bulkBar([
          ['label'=>'Publish','op'=>'publish','class'=>'bg-green-600 hover:bg-green-500'],
          ['label'=>'Unpublish','op'=>'unpublish','class'=>'bg-amber-600 hover:bg-amber-500'],
          ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected tiers?'],
      ]) ?>
      <?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php if (empty($tiers)): ?>
        <p class="rarl-empty">No tiers yet. <a class="underline font-semibold" href="partnerships.php?new=1">Create the first one</a>.</p>
        <?php endif; ?>
        <?php foreach ($tiers as $t): ?>
        <div class="p-4 flex items-center gap-4 hover:bg-gray-50 group">
          <?= bulkRowCheckbox((int)$t['id']) ?>
          <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center text-lg flex-shrink-0"><?= $t['is_addon'] ? '<i class="fa-solid fa-sparkles"></i>' : ($t['category'] === 'membership' ? '<i class="fa-solid fa-graduation-cap"></i>' : '<i class="fa-solid fa-handshake"></i>') ?></div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= ucfirst($t['category']) ?></span>
              <?php if (!$t['is_published']): ?><span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">Draft</span><?php endif; ?>
              <?php if ($t['fee_amount'] !== null): ?><span class="text-[10px] font-bold text-gray-500"><?= number_format($t['fee_amount'], 0) ?> <?= ex($t['fee_currency']) ?>/<?= ex($t['fee_period']) ?></span><?php endif; ?>
            </div>
            <p class="font-semibold text-sm text-gray-900"><?= ex($t['name']) ?></p>
            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= ex($t['description']) ?></p>
          </div>
          <div class="flex items-center gap-1 flex-shrink-0">
            <a href="partnerships.php?edit=<?= $t['id'] ?>" class="rarl-icon-btn" title="Edit"><i class="fa-solid fa-pen"></i></a>
            <form method="POST" data-confirm="Delete the “<?= ex($t['name']) ?>” tier?"><?= acsrfField() ?><input type="hidden" name="action" value="delete_tier"><input type="hidden" name="tier_id" value="<?= $t['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Page copy -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mt-6">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4"><i class="fa-solid fa-file-lines"></i> Partners Page Content</h2>
      <form method="POST" class="space-y-4">
        <?= acsrfField() ?><input type="hidden" name="action" value="save_copy">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Intro Paragraph</label>
          <textarea name="partner_page_intro" rows="2" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"><?= ex($set['partner_page_intro'] ?? '') ?></textarea>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Member Benefits <span class="text-gray-400 font-normal">(one per line)</span></label>
            <textarea name="partner_benefits_member" rows="4" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"><?= ex($set['partner_benefits_member'] ?? '') ?></textarea>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Partner Benefits <span class="text-gray-400 font-normal">(one per line)</span></label>
            <textarea name="partner_benefits_partner" rows="4" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"><?= ex($set['partner_benefits_partner'] ?? '') ?></textarea>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Ethics Note</label>
          <input type="text" name="partner_ethics_note" value="<?= ex($set['partner_ethics_note'] ?? '') ?>"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Contact Name</label>
            <input type="text" name="partner_contact_name" value="<?= ex($set['partner_contact_name'] ?? '') ?>"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Contact Email</label>
            <input type="email" name="partner_contact_email" value="<?= ex($set['partner_contact_email'] ?? '') ?>"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          </div>
        </div>
        <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Save Content</button>
      </form>
    </div>
  </div>

</div>
<?= bulkBarScript() ?>
<?php }, 'partnerships', 'Partnerships');
