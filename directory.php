<?php
/**
 * RARL — Public Membership Roster
 * Shows only the official roster PDF that admins upload via admin/settings.php
 * (stored in uploads/roster). No per-member list is queried from the database.
 */
require_once __DIR__ . '/functions.php';
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Membership Roster');

$customRosterPdf = setting('custom_roster_pdf_path', '');

echo htmlHead('Membership Roster');
?>
<?= publicNav() ?>

<!-- Hero -->
<section class="relative overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
  <div class="relative z-10 max-w-5xl mx-auto px-6 py-14 text-center">
    <div class="text-4xl mb-4"><i class="fa-solid fa-earth-americas"></i></div>
    <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-3">Membership Roster</h1>
    <p class="text-white/60 text-base max-w-lg mx-auto mb-6">Official public record of Robotics & Automation Research Lab (RARL) members.</p>
    <?php if ($customRosterPdf): ?>
    <a href="uploads/roster/<?= urlencode($customRosterPdf) ?>" target="_blank" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition-all text-sm">
      <i class="fa-solid fa-file-pdf"></i> Download Membership Roster (PDF)
    </a>
    <?php endif; ?>
  </div>
</section>

<div class="max-w-3xl mx-auto px-6 py-14 text-center">
  <?php if ($customRosterPdf): ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-14 text-gray-500 dark:text-gray-400">
    <p class="text-4xl mb-3 text-rarl-red"><i class="fa-solid fa-file-pdf"></i></p>
    <p class="font-semibold text-gray-800 dark:text-gray-100 mb-1">Official Roster PDF</p>
    <p class="text-sm mb-5">The current membership roster is available as a downloadable PDF above.</p>
    <a href="uploads/roster/<?= urlencode($customRosterPdf) ?>" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-xl transition-colors text-sm">
      <i class="fa-solid fa-download"></i> Download PDF
    </a>
  </div>
  <?php else: ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-14 text-center text-gray-400">
    <p class="text-4xl mb-3"><i class="fa-solid fa-file-circle-question"></i></p>
    <p class="font-semibold">No roster has been uploaded yet.</p>
    <p class="text-sm mt-1">Check back soon.</p>
  </div>
  <?php endif; ?>
</div>

<?= publicFooter() ?>
</body></html>
