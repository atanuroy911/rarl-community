<?php
/**
 * RARL — People / Leadership Directory
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$people = $pdo->query("SELECT * FROM people WHERE is_published = 1 ORDER BY display_order, id")->fetchAll();
$leaders = chapterLeaders();
$byRegion = [];
foreach ($leaders as $c) $byRegion[$c['continent']][] = $c;

echo htmlHead('Our People', false, ['description' => 'Meet the leadership and regional chapter chairs of the Robotics & Automation Research Lab (RARL), an international robotics research community.']);
?>
<?= publicNav('people') ?>

<!-- Hero -->
<section class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
  <div class="max-w-6xl mx-auto px-6 py-7 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">About RARL</p>
      <h1 class="font-heading font-bold text-2xl md:text-3xl text-gray-900 dark:text-white">Leadership &amp; chapters</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">The leadership and chapter chairs behind RARL's global research community.</p>
    </div>
  </div>
</section>

<div class="max-w-6xl mx-auto px-6 py-10">
  <?php if (empty($people)): ?>
  <p class="text-center text-gray-400 text-sm py-12">No one has been added to the directory yet — check back soon.</p>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($people as $p): ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 text-center shadow-sm hover:-translate-y-1 hover:shadow-lg transition">
      <?php if (!empty($p['photo_path'])): ?>
      <img src="<?= UPLOADS_URL ?>/people/<?= ex($p['photo_path']) ?>" alt="<?= ex($p['name']) ?>"
        class="w-24 h-24 rounded-full object-cover mx-auto mb-4 border-2 border-gray-100 dark:border-gray-800"/>
      <?php else: ?>
      <div class="w-24 h-24 rounded-full bg-rarl-red text-white flex items-center justify-center text-3xl font-bold mx-auto mb-4">
        <?= ex(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?>
      </div>
      <?php endif; ?>
      <h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-1"><?= ex($p['name']) ?></h3>
      <?php if (!empty($p['designation'])): ?>
      <p class="text-gray-500 dark:text-gray-400 text-sm mb-4"><?= ex($p['designation']) ?></p>
      <?php endif; ?>
      <div class="flex items-center justify-center gap-3">
        <?php if (!empty($p['email'])): ?>
        <a href="mailto:<?= ex($p['email']) ?>" title="Email" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm transition-colors"><i class="fa-solid fa-envelope"></i></a>
        <?php endif; ?>
        <?php if (!empty($p['linkedin_url'])): ?>
        <a href="<?= ex($p['linkedin_url']) ?>" target="_blank" rel="noopener" title="LinkedIn" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm transition-colors">in</a>
        <?php endif; ?>
        <?php if (!empty($p['google_scholar_url'])): ?>
        <a href="<?= ex($p['google_scholar_url']) ?>" target="_blank" rel="noopener" title="Google Scholar" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm transition-colors"><i class="fa-solid fa-graduation-cap"></i></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($byRegion): ?>
  <section id="chapters" class="mt-16 scroll-mt-24">
    <div class="text-center mb-8">
      <p class="text-xs font-bold uppercase tracking-widest text-rarl-red">Around the world</p>
      <h2 class="font-heading font-black text-2xl md:text-3xl text-gray-900 dark:text-white mt-1">Chapter leaders</h2>
      <p class="text-gray-500 dark:text-gray-400 text-sm mt-2">RARL's regional chapters and the people who lead them.</p>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php foreach ($byRegion as $region => $list): ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5">
        <h3 class="font-heading font-bold text-sm text-rarl-red uppercase tracking-wider mb-3"><?= ex($region) ?></h3>
        <ul class="space-y-3">
          <?php foreach ($list as $c): ?>
          <li class="flex items-center gap-3">
            <span class="w-10 h-10 rounded-full bg-rarl-red/10 text-rarl-red flex items-center justify-center font-bold flex-shrink-0"><?= ex(mb_strtoupper(mb_substr((string)$c['chair_name'], 0, 1))) ?></span>
            <span class="min-w-0 flex-1"><span class="block font-semibold text-gray-900 dark:text-white truncate"><?= ex($c['chair_name']) ?></span><span class="block text-xs text-gray-500 truncate"><?= ex($c['chair_title'] ?: 'Chapter Chair') ?> · <?= ex($c['name']) ?></span></span>
            <?php if (!empty($c['chair_email'])): ?><a href="mailto:<?= ex($c['chair_email']) ?>" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm" aria-label="Email <?= ex($c['chair_name']) ?>"><i class="fa-solid fa-envelope"></i></a><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?= publicFooter() ?>
</body></html>
