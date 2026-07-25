<?php
/**
 * RARL — Public Member ID Card Verification Page
 */
require_once __DIR__ . '/functions.php';

$code = trim($_GET['code'] ?? '');
$member = null;
$section = null;

if ($code) {
    $stmt = db()->prepare("SELECT * FROM members WHERE member_code = ? AND status = 'active'");
    $stmt->execute([$code]);
    $member = $stmt->fetch();
    if ($member && $member['section_id']) {
        $s = db()->prepare("SELECT name, chair_name FROM regional_sections WHERE id = ?");
        $s->execute([$member['section_id']]);
        $section = $s->fetch();
    }
}

$isExpired = $member && $member['id_card_expires_at'] && strtotime($member['id_card_expires_at']) < time();

echo htmlHead('Verify Member ID');
?>
<?= publicNav() ?>

<section class="min-h-screen bg-gray-50 dark:bg-gray-950 py-16 px-4">
  <div class="max-w-2xl mx-auto">

    <div class="text-center mb-10">
      <h1 class="font-heading font-black text-3xl text-gray-900 dark:text-white mb-2">Member ID Verification</h1>
      <p class="text-gray-500 text-sm">Verify the authenticity of a RARL member ID card</p>
    </div>

    <form method="GET" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm mb-8">
      <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Member ID Code</label>
      <div class="flex gap-3">
        <input type="text" name="code" value="<?= htmlspecialchars($code) ?>" required
          placeholder="e.g. FRE0042 — scan the QR code on the ID card"
          class="flex-1 px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors flex-shrink-0">Verify</button>
      </div>
    </form>

    <?php if ($code && !$member): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl p-8 text-center">
      <div class="text-4xl mb-3">❌</div>
      <h2 class="font-heading font-bold text-lg text-red-700 dark:text-red-300 mb-2">ID Not Found</h2>
      <p class="text-red-600 dark:text-red-400 text-sm">No active member matches this ID code.</p>
    </div>

    <?php elseif ($member):
      $name = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
    ?>
    <div class="bg-white dark:bg-gray-900 border-2 <?= $isExpired ? 'border-amber-200 dark:border-amber-800' : 'border-green-200 dark:border-green-800' ?> rounded-2xl overflow-hidden shadow-lg">
      <div class="<?= $isExpired ? 'bg-amber-500' : 'bg-green-500' ?> px-6 py-3 flex items-center gap-3">
        <span class="text-xl"><?= $isExpired ? '⚠️' : '✅' ?></span>
        <div>
          <p class="text-white font-bold text-sm"><?= $isExpired ? 'ID Card Expired' : 'Member ID Verified' ?></p>
          <p class="text-white/80 text-xs"><?= $isExpired ? 'This card is past its 3-year validity period.' : 'This member ID is authentic and current.' ?></p>
        </div>
      </div>

      <div class="p-8">
        <div class="text-center mb-7 pb-7 border-b border-gray-100 dark:border-gray-800">
          <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-16 h-16 rounded-2xl object-contain mx-auto mb-4 shadow-lg"/>
          <h2 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($name) ?></h2>
          <p class="text-gray-500 text-sm">RARL Member</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Member ID</p>
            <p class="font-mono font-bold text-gray-800 dark:text-white">#<?= htmlspecialchars($member['member_code']) ?></p>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Section</p>
            <p class="font-semibold text-gray-800 dark:text-white"><?= htmlspecialchars($section['name'] ?? '—') ?></p>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Member Since</p>
            <p class="font-semibold text-gray-800 dark:text-white"><?= date('d F Y', strtotime($member['created_at'])) ?></p>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Valid Until</p>
            <p class="font-semibold <?= $isExpired ? 'text-amber-600' : 'text-gray-800 dark:text-white' ?>"><?= $member['id_card_expires_at'] ? date('d F Y', strtotime($member['id_card_expires_at'])) : '—' ?></p>
          </div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">
          Issued by <strong class="text-gray-600 dark:text-gray-300"><?= SITE_NAME ?></strong> ·
          <a href="<?= MAIN_SITE_URL ?>" class="text-rarl-red hover:underline"><?= MAIN_SITE_URL ?></a>
        </p>
      </div>
    </div>
    <?php endif; ?>

  </div>
</section>
<?= publicFooter() ?>
</body></html>
