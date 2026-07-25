<?php
/**
 * RARL — Partner With Us (membership & partnership tiers)
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$set = [];
foreach ($pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'partner_%'")->fetchAll() as $r) $set[$r['key']] = $r['value'];

$memberBenefits  = array_filter(array_map('trim', explode("\n", $set['partner_benefits_member']  ?? '')));
$partnerBenefits = array_filter(array_map('trim', explode("\n", $set['partner_benefits_partner'] ?? '')));

echo htmlHead('Partner With Us');
?>
<?= publicNav('partners') ?>

<!-- Hero -->
<section class="bg-rarl-navy text-white py-14 px-6 text-center">
  <div class="max-w-2xl mx-auto">
    <div class="text-4xl mb-4">🤝</div>
    <h1 class="font-heading font-black text-3xl md:text-4xl mb-3">Partner With RARL</h1>
    <p class="text-white/60 text-sm max-w-lg mx-auto"><?= htmlspecialchars($set['partner_page_intro'] ?? '') ?></p>
  </div>
</section>

<div class="max-w-5xl mx-auto px-6 py-14">

  <!-- Member vs Partner benefits switcher -->
  <div class="mb-14">
    <div class="flex items-center justify-center gap-2 mb-7">
      <button type="button" data-switch="member" class="switch-btn text-sm font-semibold px-5 py-2.5 rounded-xl bg-rarl-red text-white transition-colors">Why become a member?</button>
      <button type="button" data-switch="partner" class="switch-btn text-sm font-semibold px-5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 transition-colors">Why become a partner?</button>
    </div>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 shadow-sm">
      <div data-panel="member">
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <?php foreach ($memberBenefits as $b): ?>
          <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300"><span class="w-5 h-5 rounded-full bg-red-50 dark:bg-red-900/30 text-rarl-red flex items-center justify-center text-[11px] font-bold flex-shrink-0 mt-0.5">✓</span><?= htmlspecialchars($b) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div data-panel="partner" class="hidden">
        <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <?php foreach ($partnerBenefits as $b): ?>
          <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300"><span class="w-5 h-5 rounded-full bg-blue-50 dark:bg-blue-900/30 text-rarl-blue flex items-center justify-center text-[11px] font-bold flex-shrink-0 mt-0.5">✓</span><?= htmlspecialchars($b) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <!-- Free membership CTA -->
  <div class="rounded-2xl p-10 mb-10 text-center" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
    <span class="inline-flex items-center gap-2 bg-red-900/30 border border-red-700/40 text-red-300 text-xs font-bold uppercase tracking-widest px-3 py-1.5 rounded-full mb-4">🎉 Free Membership</span>
    <h2 class="font-heading font-black text-2xl md:text-3xl text-white mb-3">RARL membership is free and open to all researchers and labs</h2>
    <p class="text-white/60 text-sm max-w-lg mx-auto mb-6">No tiers, no pricing — just join and get full access to the community, learning resources, and certificates.</p>
    <a href="register.php" class="inline-flex items-center gap-2 px-7 py-3.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all text-sm">
      Join here →
    </a>
  </div>

  <!-- Institutional partnership inquiry -->
  <?php if (!empty($set['partner_contact_email'])): ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-8 text-center mb-10">
    <h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-2">Interested in partnering with RARL as an organization?</h3>
    <p class="text-gray-500 dark:text-gray-400 text-sm max-w-lg mx-auto mb-5">If your institution or company wants to explore a partnership with RARL, get in touch — no fees or tiers, just a conversation.</p>
    <a href="mailto:<?= htmlspecialchars($set['partner_contact_email']) ?>?subject=<?= urlencode('RARL Partnership Inquiry') ?>"
      class="inline-flex items-center gap-1.5 text-sm font-semibold text-rarl-red hover:underline">Contact <?= htmlspecialchars($set['partner_contact_name'] ?? 'Us') ?> →</a>
  </div>
  <?php endif; ?>

  <!-- Ethics note -->
  <?php if (!empty($set['partner_ethics_note'])): ?>
  <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-6 text-center mb-10">
    <p class="text-amber-700 dark:text-amber-300 text-sm">📜 <?= htmlspecialchars($set['partner_ethics_note']) ?></p>
  </div>
  <?php endif; ?>

</div>

<script>
  document.querySelectorAll('.switch-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.switch;
      document.querySelectorAll('.switch-btn').forEach(b => {
        b.classList.toggle('bg-rarl-red', b === btn);
        b.classList.toggle('text-white', b === btn);
        b.classList.toggle('bg-gray-100', b !== btn);
        b.classList.toggle('dark:bg-gray-800', b !== btn);
        b.classList.toggle('text-gray-600', b !== btn);
        b.classList.toggle('dark:text-gray-300', b !== btn);
      });
      document.querySelectorAll('[data-panel]').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== target));
    });
  });
</script>
<?= publicFooter() ?>
</body></html>
