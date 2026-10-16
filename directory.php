<?php
/**
 * RARL — Public Membership Roster
 * Shows only the official roster PDF that admins upload via admin/settings.php
 * (stored in uploads/roster). No per-member list is queried from the database.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Membership Roster');

$customRosterPdf = setting('custom_roster_pdf_path', '');

echo htmlHead('Membership Roster', false, ['description' => 'The official public roster of Robotics & Automation Research Lab (RARL) members around the world.']);
?>
<?= publicNav('directory') ?>

<!-- Hero -->
<section class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
  <div class="max-w-5xl mx-auto px-6 py-7 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">Roster</p>
      <h1 class="font-heading font-bold text-2xl md:text-3xl text-gray-900 dark:text-white">Membership roster</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">The official public record of RARL members.</p>
    </div>
    <?php if ($customRosterPdf): ?>
    <a href="uploads/roster/<?= urlencode($customRosterPdf) ?>" target="_blank" class="inline-flex items-center gap-2 px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-full text-sm"><i class="fa-solid fa-file-pdf"></i> Download roster (PDF)</a>
    <?php endif; ?>
  </div>
</section>


<!-- Global Presence Map -->
<section class="py-14 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800">
  <div class="max-w-5xl mx-auto px-6">
    <div class="text-center mb-10">
      <span class="text-xs font-bold uppercase tracking-widest text-rarl-red">Global Presence</span>
      <h2 class="text-2xl md:text-3xl font-black text-gray-900 dark:text-white mt-2">RARL Member Countries</h2>
    </div>
    <img src="assets/Rarl Member Countries Map.jpg" alt="Map of RARL Member Countries" class="w-full rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 mb-10"/>
    <?php $sections = [
      ['Asia', ['Bangladesh','China','India','Iraq','Lebanon','Malaysia','Nepal','Pakistan','Saudi Arabia','South Korea','Sri Lanka','Turkey','United Arab Emirates','Vietnam']],
      ['Europe', ['France','Germany','Portugal','United Kingdom']],
      ['Africa', ['Algeria','Democratic Republic of Congo','Egypt','Ethiopia','Nigeria','South Africa','Tunisia']],
      ['North America', ['Canada','United States']],
      ['Oceania', ['Australia','New Zealand']],
    ]; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      <?php foreach ($sections as [$name, $countries]): ?>
      <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
          <h3 class="font-heading font-bold text-sm text-gray-900 dark:text-white"><?= $name ?></h3>
          <span class="text-[10px] font-bold text-rarl-red bg-rarl-red/10 px-2 py-0.5 rounded-full"><?= count($countries) ?></span>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed"><?= ex(implode(', ', $countries)) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
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
