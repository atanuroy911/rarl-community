<?php
/**
 * RARL — Videos: recordings of webinars and talks (YouTube), watched in-site.
 * videos.php?v=ID sets share-preview tags for that video and opens it.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$isMember = !empty($_SESSION['member_id']);
$isActive = false;
if ($isMember) { $s = $pdo->prepare('SELECT status FROM members WHERE id = ?'); $s->execute([(int)$_SESSION['member_id']]); $isActive = $s->fetchColumn() === 'active'; }

$videos = videosReady() ? $pdo->query("SELECT v.*, e.title AS event_title, e.event_date FROM videos v LEFT JOIN events e ON e.id = v.event_id
    WHERE v.is_published = 1 ORDER BY v.created_at DESC")->fetchAll() : [];
$focus = null;
foreach ($videos as $v) if ((int)$v['id'] === (int)($_GET['v'] ?? 0)) $focus = $v;

echo htmlHead($focus ? html_entity_decode($focus['title'], ENT_QUOTES) : 'Videos', false, [
    'description' => $focus ? ($focus['description'] ? mb_strimwidth(html_entity_decode(strip_tags($focus['description']), ENT_QUOTES), 0, 200, '…') : 'Watch this recording from the Robotics & Automation Research Lab (RARL).') : 'Recordings of RARL webinars, talks and workshops on robotics, AI and automation.',
    'image' => $focus ? youtubeThumb($focus['youtube_id'], 'maxresdefault') : null,
    'type' => $focus ? 'video.other' : 'website',
]);
?>
<?= publicNav('videos') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
<section class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 py-7">
    <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">Watch</p>
    <h1 class="font-heading font-bold text-2xl md:text-3xl text-gray-900 dark:text-white">Videos</h1>
    <p class="text-sm text-gray-500 mt-1 max-w-2xl">Recordings of RARL webinars, talks and workshops.</p>
  </div>
</section>
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
  <?php if (!$videos): ?>
  <div class="rarl-panel p-12 text-center"><i class="fa-brands fa-youtube text-4xl text-gray-300"></i><p class="font-semibold text-gray-800 dark:text-gray-100 mt-3">No videos yet</p><p class="text-sm text-gray-500 mt-1">Webinar recordings will appear here after each event.</p></div>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php foreach ($videos as $v): $locked = $v['access_level'] === 'member' && !$isActive; ?>
    <button type="button" data-modal="video-<?= (int)$v['id'] ?>" class="group text-start rarl-panel overflow-hidden hover:shadow-xl transition">
      <span class="relative block aspect-video bg-black overflow-hidden">
        <img src="<?= ex(youtubeThumb($v['youtube_id'])) ?>" alt="" loading="lazy" class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500 <?= $locked ? 'opacity-60' : '' ?>"/>
        <span class="absolute inset-0 m-auto w-16 h-16 rounded-full bg-black/60 group-hover:bg-rarl-red text-white flex items-center justify-center text-2xl transition-colors"><i class="fa-solid <?= $locked ? 'fa-lock' : 'fa-play' ?> <?= $locked ? '' : 'ps-1' ?>"></i></span>
        <?php if ($v['access_level'] === 'member'): ?><span class="absolute top-3 end-3 text-xs font-semibold bg-black/70 text-white rounded-full px-2.5 py-1"><i class="fa-solid fa-lock"></i> Members</span><?php endif; ?>
      </span>
      <span class="block p-4">
        <span class="block font-heading font-bold text-gray-900 dark:text-white leading-snug line-clamp-2 group-hover:text-rarl-red"><?= ex($v['title']) ?></span>
        <span class="block text-sm text-gray-500 mt-1"><?= $v['event_title'] ? ex($v['event_title']) . ' · ' : '' ?><?= date('d M Y', strtotime($v['event_date'] ?: $v['created_at'])) ?></span>
      </span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
</div>

<?php foreach ($videos as $v): $locked = $v['access_level'] === 'member' && !$isActive; $url = SITE_URL . '/videos.php?v=' . (int)$v['id']; ?>
<dialog class="rarl-modal" id="video-<?= (int)$v['id'] ?>" aria-labelledby="video-<?= (int)$v['id'] ?>-t" data-embed="<?= $locked ? '' : ex(youtubeEmbed($v['youtube_id'])) ?>">
  <div class="rarl-modal-panel" style="--modal-w:60rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="aspect-video bg-black sm:rounded-t-[1.5rem] overflow-hidden" data-player>
      <?php if ($locked): ?>
      <div class="w-full h-full flex flex-col items-center justify-center text-center text-white p-6" style="background:linear-gradient(rgba(0,0,0,.6),rgba(0,0,0,.8)),url('<?= ex(youtubeThumb($v['youtube_id'])) ?>') center/cover">
        <i class="fa-solid fa-lock text-3xl"></i><p class="font-semibold mt-3">This recording is for RARL members</p>
        <a href="<?= $isMember ? 'membership.php' : 'register.php' ?>" class="mt-4 px-5 h-11 inline-flex items-center rounded-full bg-rarl-red font-semibold"><?= $isMember ? 'Available once your membership is approved' : 'Join free to watch' ?></a>
      </div>
      <?php endif; ?>
    </div>
    <div class="p-5 sm:p-6">
      <h2 id="video-<?= (int)$v['id'] ?>-t" class="font-heading font-bold text-xl text-gray-900 dark:text-white leading-snug"><?= ex($v['title']) ?></h2>
      <p class="text-sm text-gray-500 mt-1"><?= $v['event_title'] ? '<a class="hover:underline" href="events.php?tab=past#event-' . (int)$v['event_id'] . '">' . ex($v['event_title']) . '</a> · ' : '' ?><?= date('d M Y', strtotime($v['event_date'] ?: $v['created_at'])) ?></p>
      <?php if ($v['description']): ?><p class="text-[15px] text-gray-700 dark:text-gray-300 mt-3 whitespace-pre-line"><?= ex($v['description']) ?></p><?php endif; ?>
      <div class="flex flex-wrap gap-2 mt-4">
        <a href="https://www.youtube.com/watch?v=<?= ex($v['youtube_id']) ?>" target="_blank" rel="noopener" class="h-10 px-4 inline-flex items-center gap-2 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold hover:border-rarl-red hover:text-rarl-red"><i class="fa-brands fa-youtube text-red-600"></i> Open on YouTube</a>
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode($url) ?>" target="_blank" rel="noopener" class="h-10 px-4 inline-flex items-center gap-2 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold hover:border-[#0a66c2] hover:text-[#0a66c2]"><i class="fa-brands fa-linkedin"></i> Share</a>
      </div>
    </div>
  </div>
</dialog>
<?php endforeach; ?>
<script>
  // Load the player only when a video is opened, and stop it on close.
  document.querySelectorAll('dialog[data-embed]').forEach(function (d) {
    var holder = d.querySelector('[data-player]');
    d.addEventListener('close', function () { if (d.dataset.embed) holder.innerHTML = ''; });
    new MutationObserver(function () {
      if (d.open && d.dataset.embed && !holder.querySelector('iframe')) holder.innerHTML = '<iframe src="' + d.dataset.embed + '&autoplay=1" title="' + d.querySelector('h2').textContent.replace(/"/g, '&quot;') + '" class="w-full h-full" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen loading="lazy"></iframe>';
    }).observe(d, {attributes: true, attributeFilter: ['open']});
  });
  <?php if ($focus): ?>document.addEventListener('DOMContentLoaded', function () { rarlModal.open('video-<?= (int)$focus['id'] ?>'); });<?php endif; ?>
</script>
<?= publicFooter() ?>
</body></html>
