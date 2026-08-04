<?php
/**
 * RARL — People / Leadership Directory
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$people = $pdo->query("SELECT * FROM people WHERE is_published = 1 ORDER BY display_order, id")->fetchAll();

echo htmlHead('Our People');
?>
<?= publicNav('people') ?>

<!-- Hero -->
<section class="bg-rarl-navy text-white py-14 px-6 text-center">
  <div class="max-w-2xl mx-auto">
    <div class="text-4xl mb-4"><i class="fa-solid fa-people-group"></i></div>
    <h1 class="font-heading font-black text-3xl md:text-4xl mb-3">Our People</h1>
    <p class="text-white/60 text-sm max-w-lg mx-auto">Meet the leadership and section chairs behind RARL's global research community.</p>
  </div>
</section>

<div class="max-w-6xl mx-auto px-6 py-14">
  <?php if (empty($people)): ?>
  <p class="text-center text-gray-400 text-sm py-12">No one has been added to the directory yet — check back soon.</p>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($people as $p): ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 text-center shadow-sm hover:-translate-y-1 hover:shadow-lg transition-all">
      <?php if (!empty($p['photo_path'])): ?>
      <img src="<?= UPLOADS_URL ?>/people/<?= htmlspecialchars($p['photo_path']) ?>" alt="<?= htmlspecialchars($p['name']) ?>"
        class="w-24 h-24 rounded-full object-cover mx-auto mb-4 border-2 border-gray-100 dark:border-gray-800"/>
      <?php else: ?>
      <div class="w-24 h-24 rounded-full bg-rarl-red text-white flex items-center justify-center text-3xl font-bold mx-auto mb-4">
        <?= htmlspecialchars(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?>
      </div>
      <?php endif; ?>
      <h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($p['name']) ?></h3>
      <?php if (!empty($p['designation'])): ?>
      <p class="text-gray-500 dark:text-gray-400 text-sm mb-4"><?= htmlspecialchars($p['designation']) ?></p>
      <?php endif; ?>
      <div class="flex items-center justify-center gap-3">
        <?php if (!empty($p['email'])): ?>
        <a href="mailto:<?= htmlspecialchars($p['email']) ?>" title="Email" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm transition-colors"><i class="fa-solid fa-envelope"></i></a>
        <?php endif; ?>
        <?php if (!empty($p['linkedin_url'])): ?>
        <a href="<?= htmlspecialchars($p['linkedin_url']) ?>" target="_blank" rel="noopener" title="LinkedIn" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm transition-colors">in</a>
        <?php endif; ?>
        <?php if (!empty($p['google_scholar_url'])): ?>
        <a href="<?= htmlspecialchars($p['google_scholar_url']) ?>" target="_blank" rel="noopener" title="Google Scholar" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-sm transition-colors"><i class="fa-solid fa-graduation-cap"></i></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?= publicFooter() ?>
</body></html>
