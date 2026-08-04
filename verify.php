<?php
/**
 * RARL — Public Certificate Verification Page
 */
require_once __DIR__ . '/functions.php';

$uuid = trim($_GET['id'] ?? '');
$cert = null;
$event = null;

if ($uuid) {
    $stmt = db()->prepare("SELECT c.*, e.title as event_title, e.type as event_type, e.event_date, e.description as event_desc FROM certificates c LEFT JOIN events e ON c.event_id = e.id WHERE c.uuid = ? AND c.is_public = 1");
    $stmt->execute([$uuid]);
    $cert = $stmt->fetch();
}

$qrDataUri = '';
if ($cert) {
    if (!class_exists('QRcode')) require_once __DIR__ . '/libs/phpqrcode/qrlib.php';
    ob_start();
    QRcode::png(CERT_VERIFY_URL . '?id=' . $cert['uuid'], false, QR_ECLEVEL_L, 4, 2);
    $qrDataUri = 'data:image/png;base64,' . base64_encode(ob_get_clean());
}

echo htmlHead('Verify Certificate');
?>
<?= publicNav() ?>

<section class="min-h-screen bg-gray-50 dark:bg-gray-950 py-16 px-4">
  <div class="max-w-2xl mx-auto">

    <div class="text-center mb-10">
      <h1 class="font-heading font-black text-3xl text-gray-900 dark:text-white mb-2">Certificate Verification</h1>
      <p class="text-gray-500 text-sm">Verify the authenticity of a RARL certificate</p>
    </div>

    <!-- Search form -->
    <form method="GET" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm mb-8">
      <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Certificate ID or Verification URL</label>
      <div class="flex gap-3">
        <input type="text" name="id" value="<?= htmlspecialchars($uuid) ?>" required
          placeholder="e.g. RARL-2025-0042 or paste UUID from QR code"
          class="flex-1 px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors flex-shrink-0">Verify</button>
      </div>
    </form>

    <!-- Result -->
    <?php if ($uuid && !$cert): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl p-8 text-center">
      <div class="text-4xl mb-3"><i class="fa-solid fa-circle-xmark text-red-500"></i></div>
      <h2 class="font-heading font-bold text-lg text-red-700 dark:text-red-300 mb-2">Certificate Not Found</h2>
      <p class="text-red-600 dark:text-red-400 text-sm">No valid certificate found for this ID. It may be invalid, revoked, or marked private.</p>
    </div>

    <?php elseif ($cert): ?>
    <div class="bg-white dark:bg-gray-900 border-2 border-green-200 dark:border-green-800 rounded-2xl overflow-hidden shadow-lg">
      <!-- Verified banner -->
      <div class="bg-green-500 px-6 py-3 flex items-center gap-3">
        <span class="text-xl"><i class="fa-solid fa-circle-check"></i></span>
        <div>
          <p class="text-white font-bold text-sm">Certificate Verified</p>
          <p class="text-white/80 text-xs">This certificate is authentic and was issued by RARL Lab</p>
        </div>
      </div>

      <!-- Certificate details -->
      <div class="p-8">
        <div class="text-center mb-7 pb-7 border-b border-gray-100 dark:border-gray-800">
          <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-16 h-16 rounded-2xl object-contain mx-auto mb-4 shadow-lg"/>
          <p class="text-xs font-bold text-rarl-red uppercase tracking-widest mb-2">Certificate of Participation</p>
          <p class="text-gray-500 text-sm mb-3">This is to certify that</p>
          <h2 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-3"><?= htmlspecialchars($cert['recipient_name']) ?></h2>
          <p class="text-gray-500 text-sm mb-1">has successfully participated in</p>
          <h3 class="font-heading font-bold text-lg text-gray-800 dark:text-gray-100"><?= htmlspecialchars($cert['event_title'] ?? 'Event') ?></h3>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Certificate ID</p>
            <p class="font-mono font-bold text-gray-800 dark:text-white"><?= htmlspecialchars($cert['certificate_no']) ?></p>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Event Type</p>
            <p class="font-semibold text-gray-800 dark:text-white capitalize"><?= htmlspecialchars($cert['event_type'] ?? '—') ?></p>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Event Date</p>
            <p class="font-semibold text-gray-800 dark:text-white"><?= $cert['event_date'] ? date('d F Y', strtotime($cert['event_date'])) : '—' ?></p>
          </div>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Issued On</p>
            <p class="font-semibold text-gray-800 dark:text-white"><?= date('d F Y', strtotime($cert['issued_at'])) ?></p>
          </div>
        </div>

        <?php if ($qrDataUri): ?>
        <div class="text-center mt-6">
          <img src="<?= $qrDataUri ?>" alt="QR Code" class="w-28 h-28 mx-auto rounded-lg border border-gray-100 dark:border-gray-800"/>
          <p class="text-[11px] text-gray-400 mt-1.5">Scan to re-verify this certificate</p>
        </div>
        <?php endif; ?>

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
