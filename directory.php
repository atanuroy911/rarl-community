<?php
/**
 * RARL — Public Membership Roster
 * Not a searchable profile directory (no contact info, no research
 * interests) — just membership number + name, grouped by country, visible
 * to everyone without logging in. Each country section (and the full list)
 * can be downloaded as a PDF via roster-pdf.php.
 */
require_once __DIR__ . '/functions.php';
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Membership Roster');

$pdo = db();
$rows = $pdo->query(
    "SELECT member_code, full_name, lab_name, type, country FROM members
     WHERE status = 'active' AND directory_visible = 1 AND member_code IS NOT NULL AND member_code != ''
     ORDER BY country, (type = 'lab'), full_name, lab_name"
)->fetchAll();

$byCountry = [];
foreach ($rows as $r) {
    $country = $r['country'] ?: 'Unspecified';
    $byCountry[$country][] = $r;
}
ksort($byCountry);
$totalCount = count($rows);
$customRosterPdf = setting('custom_roster_pdf_path', '');

echo htmlHead('Membership Roster');
?>
<?= publicNav() ?>

<!-- Hero -->
<section class="relative overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
  <div class="relative z-10 max-w-5xl mx-auto px-6 py-14 text-center">
    <div class="text-4xl mb-4"><i class="fa-solid fa-earth-americas"></i></div>
    <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-3">Membership Roster</h1>
    <p class="text-white/60 text-base max-w-lg mx-auto mb-6">Public record of RARL Community members by country — membership number and name only.</p>
    <div class="flex flex-wrap items-center justify-center gap-3">
      <a href="roster-pdf.php" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition-all text-sm">
        <i class="fa-solid fa-download"></i> Download Full Roster (PDF)
      </a>
      <?php if ($customRosterPdf): ?>
      <a href="uploads/roster/<?= urlencode($customRosterPdf) ?>" target="_blank" class="inline-flex items-center gap-2 px-6 py-3 bg-white/10 border border-white/20 text-white font-bold rounded-xl hover:bg-white/20 transition-all text-sm">
        <i class="fa-solid fa-file-pdf"></i> Official Roster PDF
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="max-w-4xl mx-auto px-6 py-14">
  <p class="text-sm text-gray-400 mb-7"><?= $totalCount ?> active member(s) across <?= count($byCountry) ?> countr<?= count($byCountry) === 1 ? 'y' : 'ies' ?></p>

  <?php if (empty($byCountry)): ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-14 text-center text-gray-400">
    <p class="text-4xl mb-3"><i class="fa-solid fa-people-group"></i></p>
    <p class="font-semibold">No members to show yet.</p>
  </div>
  <?php else: ?>
  <div class="space-y-6">
    <?php foreach ($byCountry as $country => $members): ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
        <h2 class="font-heading font-bold text-sm text-gray-900 dark:text-white"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($country) ?> <span class="text-gray-400 font-normal">(<?= count($members) ?>)</span></h2>
        <a href="roster-pdf.php?country=<?= urlencode($country) ?>" class="flex-shrink-0 px-3 py-1.5 text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition-colors"><i class="fa-solid fa-download"></i> PDF</a>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-800/50">
          <tr>
            <th class="text-left px-5 py-2 text-[10px] font-bold uppercase tracking-wider text-gray-400 w-32">Member ID</th>
            <th class="text-left px-5 py-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">Name</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($members as $m): ?>
          <tr>
            <td class="px-5 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">#<?= htmlspecialchars($m['member_code']) ?></td>
            <td class="px-5 py-2.5 text-gray-800 dark:text-gray-200"><?= htmlspecialchars($m['type'] === 'lab' ? $m['lab_name'] : $m['full_name']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?= publicFooter() ?>
</body></html>
