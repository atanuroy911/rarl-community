<?php
/**
 * RARL Admin — General Settings
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? 'save_settings';

    if ($action === 'reseed_defaults') {
        seedDefaults();
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Defaults reseeded.'];
        header('Location: settings.php'); exit;
    }

    // Checkboxes (e.g. popup_enabled) must be explicitly zeroed when unchecked
    if (isset($_POST['settings'])) {
        $checkboxKeys = ['popup_enabled'];
        foreach ($checkboxKeys as $ck) {
            if (!isset($_POST['settings'][$ck])) $_POST['settings'][$ck] = '0';
        }
    }

    foreach ($_POST['settings'] ?? [] as $k => $v) {
        $v = clean($v);
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$k,$v,$v]);
    }

    // Popup image upload — only overwrite popup_image_path if a new file was uploaded
    if (!empty($_FILES['popup_image']['name'])) {
        $filename = validateUpload($_FILES['popup_image'], ['jpg','jpeg','png','webp'], 3*1024*1024, UPLOADS_PATH.'/popups');
        if ($filename) {
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('popup_image_path',?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$filename,$filename]);
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Settings saved, but the popup image upload failed (check file type/size).'];
            header('Location: settings.php'); exit;
        }
    }

    $_SESSION['flash'] = ['type'=>'success','msg'=>'Settings saved successfully.'];
    header('Location: settings.php'); exit;
}

$set = [];
foreach ($pdo->query("SELECT `key`,`value` FROM settings")->fetchAll() as $r) $set[$r['key']] = $r['value'];

adminWrap(function() use ($set) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Global Settings</h1>
<p class="text-gray-500 text-sm mb-7">Manage core platform configuration</p>

<!-- Reseed Defaults -->
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mb-6 flex items-center justify-between gap-4">
  <div>
    <h2 class="font-heading font-bold text-sm text-gray-800 mb-1">Reseed Defaults</h2>
    <p class="text-xs text-gray-400">Re-creates any missing default membership plans. Safe to run any time — won't duplicate or overwrite existing custom rows. (Chapter leadership is managed on the <a href="sections.php" class="underline">Regional Sections</a> page and is never touched here.)</p>
  </div>
  <form method="POST">
    <?= acsrfField() ?><input type="hidden" name="action" value="reseed_defaults">
    <button type="submit" class="px-5 py-2.5 bg-gray-900 hover:bg-black text-white font-semibold text-xs rounded-xl transition-colors whitespace-nowrap">Reseed Defaults</button>
  </form>
</div>

<form method="POST" enctype="multipart/form-data" class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-7 shadow-sm space-y-6">
  <?= acsrfField() ?>

  <div>
    <h2 class="font-heading font-bold text-sm text-gray-800 mb-4 border-b border-gray-100 pb-2">Certificate Generation</h2>
    <div class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Certificate ID Counter</label>
        <input type="number" name="settings[cert_id_counter]" value="<?= htmlspecialchars($set['cert_id_counter']??'0') ?>"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <p class="text-[10px] text-gray-400 mt-1">The numeric counter for the next certificate. e.g., 42 becomes RARL-2025-0043.</p>
      </div>
    </div>
  </div>

  <div>
    <h2 class="font-heading font-bold text-sm text-gray-800 mb-4 border-b border-gray-100 pb-2">Site Content</h2>
    <div class="space-y-4">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Homepage Hero Badge</label>
        <input type="text" name="settings[home_hero_badge]" value="<?= htmlspecialchars($set['home_hero_badge']??'') ?>" placeholder="Now Open for Membership"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Homepage Hero Title</label>
        <input type="text" name="settings[home_hero_title]" value="<?= htmlspecialchars($set['home_hero_title']??'') ?>" placeholder="Join the Global RARL Research Community"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Homepage Hero Subtitle</label>
        <textarea name="settings[home_hero_subtitle]" rows="3"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"><?= htmlspecialchars($set['home_hero_subtitle']??'') ?></textarea>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Community Feed Intro</label>
        <textarea name="settings[community_feed_intro]" rows="2"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"><?= htmlspecialchars($set['community_feed_intro']??'') ?></textarea>
      </div>
    </div>
  </div>

  <div>
    <h2 class="font-heading font-bold text-sm text-gray-800 mb-4 border-b border-gray-100 pb-2">Hero Stats Override</h2>
    <p class="text-[10px] text-gray-400 mb-3">Leave Members/Labs/Certificates blank to use the live database count. Countries has no live source, so it always uses this value.</p>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Members Override</label>
        <input type="text" name="settings[stat_members_override]" value="<?= htmlspecialchars($set['stat_members_override']??'') ?>" placeholder="Blank = live count"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Labs Override</label>
        <input type="text" name="settings[stat_labs_override]" value="<?= htmlspecialchars($set['stat_labs_override']??'') ?>" placeholder="Blank = live count"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Certificates Override</label>
        <input type="text" name="settings[stat_certs_override]" value="<?= htmlspecialchars($set['stat_certs_override']??'') ?>" placeholder="Blank = live count"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Countries</label>
        <input type="text" name="settings[stat_countries]" value="<?= htmlspecialchars($set['stat_countries']??'5') ?>"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
    </div>
  </div>

  <div>
    <h2 class="font-heading font-bold text-sm text-gray-800 mb-4 border-b border-gray-100 pb-2">Homepage Popup</h2>
    <div class="space-y-4">
      <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-xl cursor-pointer hover:border-rarl-red/30 transition-colors">
        <input type="checkbox" name="settings[popup_enabled]" value="1" <?= ($set['popup_enabled']??'0')==='1'?'checked':'' ?> class="accent-rarl-red w-4 h-4"/>
        <span class="text-xs font-semibold text-gray-700">Enable popup on homepage</span>
      </label>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Popup Image</label>
        <?php if (!empty($set['popup_image_path'])): ?>
        <img src="<?= UPLOADS_URL ?>/popups/<?= htmlspecialchars($set['popup_image_path']) ?>" class="h-20 rounded-lg border border-gray-200 mb-2"/>
        <?php endif; ?>
        <input type="file" name="popup_image" accept=".jpg,.jpeg,.png,.webp"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <p class="text-[10px] text-gray-400 mt-1">JPG/PNG/WEBP, max 3MB. Leave blank to keep the current image.</p>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Popup Link URL</label>
        <input type="url" name="settings[popup_link_url]" value="<?= htmlspecialchars($set['popup_link_url']??'') ?>" placeholder="https://…"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Popup Alt Text</label>
        <input type="text" name="settings[popup_alt_text]" value="<?= htmlspecialchars($set['popup_alt_text']??'') ?>"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
    </div>
  </div>

  <div class="pt-4">
    <button type="submit" class="px-6 py-3 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors shadow">Save Settings</button>
  </div>
</form>
<?php }, 'settings', 'Settings');
