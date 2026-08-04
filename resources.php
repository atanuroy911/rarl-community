<?php
/**
 * RARL — Learning Resources Hub
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$isActiveMember = false;
if (!empty($_SESSION['member_id'])) {
    $s = $pdo->prepare('SELECT status FROM members WHERE id = ?');
    $s->execute([(int)$_SESSION['member_id']]);
    $isActiveMember = $s->fetchColumn() === 'active';
}

$categories = $pdo->query("SELECT * FROM resource_categories WHERE is_published = 1 ORDER BY display_order")->fetchAll();

// If viewing a category
$catSlug    = trim($_GET['cat'] ?? '');
$activecat  = null;
$resources  = [];
if ($catSlug) {
    $cs = $pdo->prepare("SELECT * FROM resource_categories WHERE slug = ? AND is_published = 1");
    $cs->execute([$catSlug]);
    $activecat = $cs->fetch();
    if ($activecat) {
        $rs = $pdo->prepare("SELECT * FROM resources WHERE category_id = ? ORDER BY is_featured DESC, display_order ASC");
        $rs->execute([$activecat['id']]);
        $resources = $rs->fetchAll();
    }
}

$typeIcons = ['youtube_playlist'=>'<i class="fa-solid fa-play"></i>','youtube_video'=>'<i class="fa-solid fa-film"></i>','article'=>'<i class="fa-solid fa-file-lines"></i>','book'=>'<i class="fa-solid fa-book"></i>','tool'=>'<i class="fa-solid fa-wrench"></i>','course'=>'<i class="fa-solid fa-graduation-cap"></i>','other'=>'<i class="fa-solid fa-link"></i>'];
$typeLabels = ['youtube_playlist'=>'YouTube Playlist','youtube_video'=>'YouTube Video','article'=>'Article','book'=>'Free Book','tool'=>'Tool','course'=>'Course','other'=>'Resource'];

echo htmlHead($activecat ? $activecat['name'] . ' Resources' : 'Learning Hub');
?>
<?= publicNav('resources') ?>

<!-- Hero -->
<section class="bg-rarl-navy text-white py-14 px-6 text-center">
  <div class="max-w-3xl mx-auto">
    <div class="text-4xl mb-4"><i class="fa-solid fa-book"></i></div>
    <h1 class="font-heading font-black text-3xl md:text-4xl mb-3">Learning Hub</h1>
    <p class="text-white/60 text-sm max-w-lg mx-auto">Curated YouTube playlists, free books, articles, and tools for researchers — organised by topic. All free, all external.</p>
  </div>
</section>

<div class="max-w-6xl mx-auto px-6 py-12">

  <?php if (!$activecat): ?>
  <!-- Category grid -->
  <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-7">Browse by Topic</h2>
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
    <?php foreach ($categories as $cat): ?>
    <a href="resources.php?cat=<?= urlencode($cat['slug']) ?>"
      class="group block bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 hover:-translate-y-1 hover:shadow-lg hover:border-rarl-red/40 transition-all relative">
      <?php if (($cat['access_level'] ?? 'public') === 'member'): ?>
      <span class="absolute top-3 right-3 text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400"><i class="fa-solid fa-lock"></i> Premium</span>
      <?php endif; ?>
      <div class="text-3xl mb-3"><?= $cat['icon_emoji'] ?></div>
      <h3 class="font-heading font-bold text-sm text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($cat['name']) ?></h3>
      <p class="text-gray-400 text-xs leading-relaxed mb-3"><?= htmlspecialchars(mb_strimwidth($cat['description'] ?? '', 0, 70, '…')) ?></p>
      <span class="text-[10px] font-bold text-rarl-red uppercase tracking-wider">View Resources →</span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php else: ?>
  <!-- Category detail -->
  <div class="mb-8">
    <a href="resources.php" class="text-sm text-gray-400 hover:text-rarl-red transition-colors">← All Categories</a>
    <div class="flex items-center gap-3 mt-3">
      <div class="text-3xl"><?= $activecat['icon_emoji'] ?></div>
      <div>
        <h2 class="font-heading font-black text-2xl text-gray-900 dark:text-white"><?= htmlspecialchars($activecat['name']) ?></h2>
        <p class="text-gray-500 text-sm mt-0.5"><?= htmlspecialchars($activecat['description'] ?? '') ?></p>
      </div>
    </div>
  </div>

  <?php if (empty($resources)): ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-12 text-center text-gray-400">
    <i class="fa-solid fa-inbox text-4xl mb-3 text-gray-300"></i>
    <p class="font-semibold mb-1">No resources yet</p>
    <p class="text-sm">Resources are being added. Check back soon.</p>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <?php foreach ($resources as $r):
      $icon  = $typeIcons[$r['type']] ?? '<i class="fa-solid fa-link"></i>';
      $label = $typeLabels[$r['type']] ?? 'Resource';
      $isYT  = in_array($r['type'], ['youtube_playlist','youtube_video']);
      $isPremium = ($r['access_level'] ?? 'public') === 'member' || ($activecat['access_level'] ?? 'public') === 'member';
      $isLocked  = $isPremium && !$isActiveMember;
      $tag = $isLocked ? 'div' : 'a';
    ?>
    <<?= $tag ?> <?= $isLocked ? '' : 'href="' . htmlspecialchars($r['url']) . '" target="_blank" rel="noopener"' ?>
      class="group flex gap-4 bg-white dark:bg-gray-900 border rounded-2xl p-5 transition-all <?= $isLocked ? 'border-amber-200 dark:border-amber-800 bg-amber-50/30 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-rarl-red/40 hover:-translate-y-0.5 hover:shadow-md' ?>">
      <div class="w-12 h-12 rounded-xl <?= $isLocked ? 'bg-amber-100 dark:bg-amber-900/30' : ($isYT ? 'bg-red-100 dark:bg-red-900/30' : 'bg-gray-100 dark:bg-gray-800') ?> flex items-center justify-center text-xl flex-shrink-0">
        <?= $isLocked ? '<i class="fa-solid fa-lock"></i>' : $icon ?>
      </div>
      <div class="flex-1 min-w-0">
        <?php if ($isPremium): ?><span class="text-[10px] font-bold text-amber-600 uppercase tracking-wider"><i class="fa-solid fa-lock"></i> Premium · </span><?php endif; ?>
        <?php if ($r['is_featured']): ?><span class="text-[10px] font-bold text-amber-600 uppercase tracking-wider"><i class="fa-solid fa-star"></i> Featured · </span><?php endif; ?>
        <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"><?= $label ?></span>
        <h3 class="font-semibold text-sm text-gray-900 dark:text-white mt-1 mb-1 leading-snug <?= $isLocked ? '' : 'group-hover:text-rarl-red transition-colors' ?>"><?= htmlspecialchars($r['title']) ?></h3>
        <?php if ($r['description']): ?>
        <p class="text-xs text-gray-400 leading-relaxed line-clamp-2"><?= htmlspecialchars($r['description']) ?></p>
        <?php endif; ?>
        <?php if ($isLocked): ?>
        <a href="register.php" class="inline-flex items-center gap-1 mt-2 text-xs text-amber-600 font-bold hover:underline">Join free to unlock →</a>
        <?php else: ?>
        <div class="flex items-center gap-1 mt-2 text-xs text-rarl-red font-semibold opacity-0 group-hover:opacity-100 transition-opacity">
          Open →
        </div>
        <?php endif; ?>
      </div>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>
<?= publicFooter() ?>
</body></html>
