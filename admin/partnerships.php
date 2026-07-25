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
<h1 class="text-2xl font-black text-gray-900 mb-1">Partnerships</h1>
<p class="text-gray-500 text-sm mb-7">Manage paid membership/partnership tiers and the public "Partner With Us" page</p>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Left: tier form -->
  <div class="xl:col-span-1 space-y-6">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4"><?= $editTier ? '✏️ Edit Tier' : '➕ Add Tier' ?></h2>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?>
        <input type="hidden" name="action" value="<?= $editTier ? 'update_tier' : 'add_tier' ?>">
        <?php if ($editTier): ?><input type="hidden" name="tier_id" value="<?= $editTier['id'] ?>"><?php endif; ?>

        <select name="category" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
          <option value="membership" <?= ($editTier['category'] ?? '') === 'membership' ? 'selected' : '' ?>>Membership</option>
          <option value="partnership" <?= ($editTier['category'] ?? 'partnership') === 'partnership' ? 'selected' : '' ?>>Partnership</option>
        </select>
        <input type="text" name="name" required value="<?= htmlspecialchars($editTier['name'] ?? '') ?>" placeholder="Tier Name"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <textarea name="description" rows="2" placeholder="Description"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"><?= htmlspecialchars($editTier['description'] ?? '') ?></textarea>

        <div class="grid grid-cols-3 gap-2">
          <input type="number" step="0.01" name="fee_amount" value="<?= htmlspecialchars($editTier['fee_amount'] ?? '') ?>" placeholder="Fee"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          <input type="text" name="fee_currency" value="<?= htmlspecialchars($editTier['fee_currency'] ?? 'EUR') ?>" placeholder="EUR"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          <input type="text" name="fee_period" value="<?= htmlspecialchars($editTier['fee_period'] ?? 'year') ?>" placeholder="year"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        </div>
        <p class="text-[10px] text-gray-400">Leave Fee blank to hide pricing on this tier's card.</p>

        <div class="grid grid-cols-2 gap-2">
          <input type="number" name="display_order" value="<?= htmlspecialchars($editTier['display_order'] ?? 0) ?>" placeholder="Order"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        </div>

        <label class="flex items-center gap-2 p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer">
          <input type="checkbox" name="is_addon" value="1" <?= !empty($editTier['is_addon']) ? 'checked' : '' ?> class="accent-rarl-red w-3.5 h-3.5"/>
          <span class="text-xs text-gray-600">Show as separate add-on card (e.g. "High Visibility")</span>
        </label>
        <label class="flex items-center gap-2 p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer">
          <input type="checkbox" name="is_published" value="1" <?= ($editTier['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-3.5 h-3.5"/>
          <span class="text-xs text-gray-600">Published (visible on partners.php)</span>
        </label>

        <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors"><?= $editTier ? 'Save Changes' : 'Add Tier' ?></button>
        <?php if ($editTier): ?><a href="partnerships.php" class="block text-center text-xs text-gray-400 hover:text-gray-600 mt-1">Cancel edit</a><?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Right: tier list -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Tiers <span class="text-gray-400 font-normal text-xs">(<?= count($tiers) ?>)</span></h2>
      </div>
      <div class="divide-y divide-gray-100">
        <?php if (empty($tiers)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No tiers yet. Add one on the left.</p>
        <?php endif; ?>
        <?php foreach ($tiers as $t): ?>
        <div class="p-4 flex items-center gap-4 hover:bg-gray-50 group">
          <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center text-lg flex-shrink-0"><?= $t['is_addon'] ? '✨' : ($t['category'] === 'membership' ? '🎓' : '🤝') ?></div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= ucfirst($t['category']) ?></span>
              <?php if (!$t['is_published']): ?><span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">Draft</span><?php endif; ?>
              <?php if ($t['fee_amount'] !== null): ?><span class="text-[10px] font-bold text-gray-500"><?= number_format($t['fee_amount'], 0) ?> <?= htmlspecialchars($t['fee_currency']) ?>/<?= htmlspecialchars($t['fee_period']) ?></span><?php endif; ?>
            </div>
            <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($t['name']) ?></p>
            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($t['description']) ?></p>
          </div>
          <div class="flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
            <a href="partnerships.php?edit=<?= $t['id'] ?>" class="px-2 py-1 text-[10px] font-semibold bg-blue-50 text-blue-600 rounded text-center">Edit</a>
            <form method="POST" onsubmit="return confirm('Delete this tier?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_tier"><input type="hidden" name="tier_id" value="<?= $t['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Page copy -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mt-6">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4">📝 Partners Page Content</h2>
      <form method="POST" class="space-y-4">
        <?= acsrfField() ?><input type="hidden" name="action" value="save_copy">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Intro Paragraph</label>
          <textarea name="partner_page_intro" rows="2" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"><?= htmlspecialchars($set['partner_page_intro'] ?? '') ?></textarea>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Member Benefits <span class="text-gray-400 font-normal">(one per line)</span></label>
            <textarea name="partner_benefits_member" rows="4" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"><?= htmlspecialchars($set['partner_benefits_member'] ?? '') ?></textarea>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Partner Benefits <span class="text-gray-400 font-normal">(one per line)</span></label>
            <textarea name="partner_benefits_partner" rows="4" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"><?= htmlspecialchars($set['partner_benefits_partner'] ?? '') ?></textarea>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Ethics Note</label>
          <input type="text" name="partner_ethics_note" value="<?= htmlspecialchars($set['partner_ethics_note'] ?? '') ?>"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Contact Name</label>
            <input type="text" name="partner_contact_name" value="<?= htmlspecialchars($set['partner_contact_name'] ?? '') ?>"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Contact Email</label>
            <input type="email" name="partner_contact_email" value="<?= htmlspecialchars($set['partner_contact_email'] ?? '') ?>"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          </div>
        </div>
        <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Save Content</button>
      </form>
    </div>
  </div>

</div>
<?php }, 'partnerships', 'Partnerships');
