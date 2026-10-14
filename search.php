<?php
/**
 * RARL — Search
 * Searches what RARL publishes (events, learning, announcements, team
 * updates, newsletters). It deliberately does not search members: the
 * platform promotes RARL, not individual members. Visitors see only public
 * content; members also see member-only material.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$isMember = !empty($_SESSION['member_id']);
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$results = mb_strlen($q) >= 2 ? rarlFeedItems(null, $isMember, 60, $q) : [];
$kinds = array_count_values(array_column($results, 'kind'));
$labels = ['announcement' => 'Announcements', 'event' => 'Events', 'resource' => 'Learning', 'post' => 'Team updates', 'newsletter' => 'Newsletters'];

echo htmlHead($q !== '' ? 'Search: ' . $q : 'Search');
?>
<?= publicNav('search') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
  <main class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
    <form action="search.php" method="GET" role="search" class="relative">
      <label for="search-q" class="sr-only">Search RARL</label>
      <i class="fa-solid fa-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-gray-400"></i>
      <input id="search-q" type="search" name="q" value="<?= ex($q) ?>" autofocus placeholder="Search events, learning, announcements…" class="w-full h-14 pl-12 pr-28 rounded-full bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 text-base focus:outline-none focus:border-rarl-red focus:ring-4 focus:ring-rarl-red/10"/>
      <button class="absolute right-2 top-2 h-10 px-5 rounded-full bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold">Search</button>
    </form>

    <?php if ($q === ''): ?>
    <div class="mt-10 grid sm:grid-cols-3 gap-3">
      <?php foreach ([['events.php', 'fa-calendar-days', 'Events', 'Webinars, workshops, hackathons'], ['resources.php', 'fa-graduation-cap', 'Learning', 'Courses, talks, reading lists'], ['community.php', 'fa-newspaper', 'Updates', 'News and announcements']] as [$u, $i, $t, $s]): ?>
      <a href="<?= $u ?>" class="rarl-panel p-5 hover:border-rarl-red/40"><i class="fa-solid <?= $i ?> text-rarl-red text-xl"></i><p class="font-semibold text-gray-900 dark:text-white mt-3"><?= $t ?></p><p class="text-sm text-gray-500"><?= $s ?></p></a>
      <?php endforeach; ?>
    </div>
    <?php elseif (mb_strlen($q) < 2): ?>
    <p class="mt-8 text-center text-gray-500">Type at least two characters.</p>
    <?php else: ?>
    <div class="flex flex-wrap items-center gap-2 mt-6 mb-4 text-sm">
      <span class="text-gray-600 dark:text-gray-400"><strong class="text-gray-900 dark:text-white"><?= count($results) ?></strong> result<?= count($results) === 1 ? '' : 's' ?> for “<?= ex($q) ?>”</span>
      <?php foreach ($kinds as $k => $n): ?><span class="px-2.5 py-0.5 rounded-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-xs text-gray-600 dark:text-gray-300"><?= $labels[$k] ?? $k ?> · <?= $n ?></span><?php endforeach; ?>
    </div>
    <div class="space-y-4">
      <?php foreach ($results as $it) echo renderFeedItem($it); ?>
    </div>
    <?php if (!$results): ?>
    <div class="rarl-panel p-12 text-center mt-4"><i class="fa-solid fa-magnifying-glass text-3xl text-gray-300"></i><p class="font-semibold text-gray-800 dark:text-gray-200 mt-3">No matches</p><p class="text-sm text-gray-500 mt-1">Try a broader word, or browse <a class="text-rarl-red font-semibold" href="events.php">events</a> and <a class="text-rarl-red font-semibold" href="resources.php">learning</a>.</p>
      <?php if (!$isMember): ?><p class="text-sm text-gray-500 mt-3">Members can also search member-only learning and updates — <a class="text-rarl-red font-semibold" href="login.php">sign in</a>.</p><?php endif; ?></div>
    <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
<?= publicFooter() ?>
</body></html>
