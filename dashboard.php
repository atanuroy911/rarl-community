<?php
/**
 * RARL — Member Home
 * The member portal's front page: an identity card (left), everything RARL
 * has published lately in one feed (centre), and what's coming up (right).
 * Members don't post here — RARL does; members follow, RSVP and learn.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Home');
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in to access your member home.'); redirect('login.php?next=dashboard.php'); }

$pdo      = db();
$memberId = (int)$_SESSION['member_id'];
$st = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$st->execute([$memberId]);
$m = $st->fetch();
if (!$m) { session_destroy(); redirect('login.php'); }

$isLab       = $m['type'] === 'lab';
$displayName = $isLab ? $m['lab_name'] : $m['full_name'];
$firstName   = $isLab ? $displayName : (preg_split('/\s+/', preg_replace('/^(dr|prof|mr|mrs|ms|engr)\.?\s+/i', '', trim((string)$displayName)))[0] ?? $displayName);
$headline    = $isLab ? trim(($m['pi_name'] ? 'PI: ' . $m['pi_name'] : 'Research lab')) : trim(implode(' · ', array_filter([$m['position'] ?? '', $m['institution'] ?? ''])));
$isActive    = $m['status'] === 'active';

$leaders = chapterLeaders();

$certCount = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE member_id = ? OR recipient_email = ?');
$certCount->execute([$memberId, $m['email']]);
$certCount = (int)$certCount->fetchColumn();

$goingCount = $pdo->prepare("SELECT COUNT(*) FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.member_id = ? AND r.status IN ('registered','attended') AND (e.event_date IS NULL OR e.event_date >= CURDATE())");
$goingCount->execute([$memberId]);
$goingCount = (int)$goingCount->fetchColumn();

$evStmt = $pdo->prepare("SELECT e.*, r.status AS my_status,
        (SELECT COUNT(*) FROM event_registrations x WHERE x.event_id = e.id AND x.status != 'cancelled') AS reg_count
    FROM events e LEFT JOIN event_registrations r ON r.event_id = e.id AND r.member_id = ?
    WHERE e.is_active = 1 AND (e.event_date IS NULL OR e.event_date >= CURDATE())
    ORDER BY e.event_date IS NULL, e.event_date ASC LIMIT 4");
$evStmt->execute([$memberId]);
$upcoming = $evStmt->fetchAll();

$feed  = rarlFeedItems($m['section_id'] ? (int)$m['section_id'] : null, true, 30);
$kinds = array_count_values(array_column($feed, 'kind'));

// Profile strength — what makes a complete RARL membership record (and a
// correct ID card). Deliberately no "post something" step: members don't post.
$strength = [
    ['done' => !empty($m['avatar_path']), 'label' => 'Add a profile photo', 'sub' => 'Required for your member ID card', 'href' => 'profile.php#photo'],
    ['done' => (bool)(($m['institution'] ?? '') ?: ($m['lab_website'] ?? '')), 'label' => $isLab ? 'Add your lab website' : 'Add your institution', 'sub' => 'Printed on your certificates and card', 'href' => 'profile.php'],
    ['done' => (bool)(($m['research_interests'] ?? '') ?: ($m['research_areas'] ?? '')), 'label' => 'Add research interests', 'sub' => 'So RARL can send you relevant events', 'href' => 'profile.php'],
];
$strengthDone = count(array_filter($strength, fn($c) => $c['done']));
$strengthPct  = (int)round($strengthDone / count($strength) * 100);

// Admin-controlled notice (Admin → Settings → Member portal).
$notice = trim(setting('member_notice_text', ''));
$noticeTone = setting('member_notice_tone', 'info');
$noticeLink = setting('member_notice_link', '');
$noticeLinkLabel = setting('member_notice_link_label', '') ?: 'Learn more';

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$h = fn($v) => ex($v);

echo htmlHead('Home');
?>
<?= publicNav('dashboard') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 grid grid-cols-1 md:grid-cols-[225px_minmax(0,1fr)] lg:grid-cols-[225px_minmax(0,1fr)_300px] gap-6 items-start">

  <!-- ── Left: identity ───────────────────────────────── -->
  <aside class="order-2 md:order-none space-y-4 min-w-0 md:sticky md:top-[88px]">
    <section class="rarl-panel overflow-hidden">
      <div class="h-16 bg-rarl-navy relative"><div class="absolute inset-0 opacity-50 bg-[radial-gradient(circle_at_85%_0%,#CC0703,transparent_60%)]"></div></div>
      <div class="relative px-4 pb-4 -mt-9 text-center">
        <a href="profile.php#photo" class="inline-block rounded-full ring-4 ring-white dark:ring-gray-900" title="Change photo"><?= memberAvatarHtml($m['avatar_path'], (string)$displayName, 'w-[72px] h-[72px] text-2xl') ?></a>
        <h1 class="mt-2 font-heading font-bold text-gray-900 dark:text-white leading-tight"><a href="profile.php" class="hover:underline"><?= $h($displayName) ?></a></h1>
        <?php if ($headline): ?><p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= $h($headline) ?></p><?php endif; ?>
        <div class="flex flex-wrap justify-center gap-1.5 mt-3">
          <?php if ($isActive): ?><span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300"><i class="fa-solid fa-circle-check"></i> Active member</span>
          <?php elseif ($m['status'] === 'pending'): ?><span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="fa-solid fa-hourglass-half"></i> Pending review</span>
          <?php else: ?><span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">Inactive</span><?php endif; ?>
          <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?= $isLab ? 'Lab' : 'Individual' ?></span>
        </div>
      </div>
      <dl class="border-t border-gray-100 dark:border-gray-800 text-xs">
        <?php foreach ([
          ['Member no.', $m['member_code'] ? '#' . $m['member_code'] : '—', 'membership.php', true],
          ['Member since', date('M Y', strtotime($m['created_at'])), 'membership.php', false],
        ] as [$k, $v, $href, $mono]): ?>
        <a href="<?= $href ?>" class="flex justify-between gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-800/60">
          <dt class="text-gray-500"><?= $k ?></dt><dd class="font-semibold text-gray-900 dark:text-white truncate <?= $mono ? 'font-mono' : '' ?>"><?= $h($v) ?></dd>
        </a>
        <?php endforeach; ?>
      </dl>
      <div class="grid grid-cols-2 border-t border-gray-100 dark:border-gray-800 text-center">
        <a href="membership.php#certificates" class="py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60 border-r border-gray-100 dark:border-gray-800"><span class="block font-heading font-bold text-lg text-gray-900 dark:text-white"><?= $certCount ?></span><span class="text-[11px] text-gray-500">Certificates</span></a>
        <a href="events.php" class="py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60"><span class="block font-heading font-bold text-lg text-gray-900 dark:text-white"><?= $goingCount ?></span><span class="text-[11px] text-gray-500">Events going</span></a>
      </div>
    </section>

    <section data-install hidden class="rarl-panel p-4 relative" id="install-card">
      <button type="button" class="absolute top-2 end-2 w-8 h-8 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Dismiss" onclick="try{localStorage.setItem('rarl-install-dismissed','1')}catch(e){};this.parentNode.remove()"><i class="fa-solid fa-xmark"></i></button>
      <div class="flex items-center gap-3"><img src="assets/icons/icon-192.png" alt="" class="w-11 h-11 rounded-xl border border-gray-200 dark:border-gray-700"/><div><p class="text-sm font-bold text-gray-900 dark:text-white">Get the RARL app</p><p class="text-xs text-gray-500">Your card, events and updates, one tap away.</p></div></div>
      <button type="button" data-install-go class="mt-3 w-full h-10 rounded-full bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold"><i class="fa-solid fa-download"></i> Install</button>
    </section>
    <script>try { if (localStorage.getItem('rarl-install-dismissed')) document.getElementById('install-card').remove(); } catch (e) {}</script>

    <?php if ($strengthDone < count($strength)): ?>
    <section class="rarl-panel p-4">
      <div class="flex items-center justify-between"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Profile strength</h2><span class="text-xs font-semibold text-gray-500"><?= $strengthPct ?>%</span></div>
      <div class="h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full mt-2 overflow-hidden" role="progressbar" aria-valuenow="<?= $strengthPct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Profile strength"><div class="h-full bg-rarl-red rounded-full" style="width:<?= $strengthPct ?>%"></div></div>
      <ul class="mt-3 space-y-1">
        <?php foreach ($strength as $c): if ($c['done']) continue; ?>
        <li><a href="<?= $c['href'] ?>" class="flex items-start gap-2.5 p-2 -mx-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60">
          <i class="fa-regular fa-circle text-gray-400 mt-1 text-xs"></i>
          <span><span class="block text-sm font-semibold text-gray-800 dark:text-gray-100 leading-snug"><?= $h($c['label']) ?></span><span class="block text-xs text-gray-500"><?= $h($c['sub']) ?></span></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </aside>

  <!-- ── Centre: From RARL ─────────────────────────────── -->
  <section class="order-1 md:order-none min-w-0 space-y-4" aria-labelledby="feed-title">
    <?= renderFlash() ?>

    <?php if ($m['status'] === 'pending'): ?>
    <div class="rarl-panel p-5 flex gap-4 border-amber-300 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-900/10">
      <span class="w-11 h-11 rounded-xl bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-user-clock"></i></span>
      <div><p class="font-semibold text-gray-900 dark:text-white">Your membership is being reviewed</p>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">The RARL team checks every application, usually within a few working days. You'll get an email when you're approved, then your member number, ID card and membership certificate are issued automatically. Meanwhile you can browse everything below.</p></div>
    </div>
    <?php elseif ($m['status'] === 'inactive'): ?>
    <div class="rarl-panel p-5 flex gap-4">
      <span class="w-11 h-11 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-500 flex items-center justify-center flex-shrink-0"><i class="fa-solid fa-circle-pause"></i></span>
      <div><p class="font-semibold text-gray-900 dark:text-white">Your membership is inactive</p>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">Event registration and member-only learning are paused. <a class="text-rarl-red font-semibold hover:underline" href="mailto:<?= $h(MAIL_REPLY_TO) ?>">Contact the RARL team</a> to reactivate.</p></div>
    </div>
    <?php endif; ?>

    <?php if ($notice !== ''):
      $tone = ['info' => 'border-blue-200 bg-blue-50/70 dark:border-blue-900 dark:bg-blue-900/15 [&_i]:text-blue-600', 'success' => 'border-green-200 bg-green-50/70 dark:border-green-900 dark:bg-green-900/15 [&_i]:text-green-600', 'warning' => 'border-amber-300 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-900/15 [&_i]:text-amber-600'][$noticeTone] ?? ''; ?>
    <div class="rarl-panel p-4 flex items-start gap-3 <?= $tone ?>">
      <i class="fa-solid fa-circle-info mt-1"></i>
      <p class="flex-1 text-sm text-gray-800 dark:text-gray-200"><?= $h($notice) ?></p>
      <?php if ($noticeLink): ?><a href="<?= $h($noticeLink) ?>" class="text-sm font-semibold text-rarl-red whitespace-nowrap hover:underline"><?= $h($noticeLinkLabel) ?> →</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="rarl-panel px-5 py-4">
      <p class="text-sm text-gray-500"><?= $greeting ?>, <span class="font-semibold text-gray-900 dark:text-white"><?= $h($firstName) ?></span></p>
      <h2 id="feed-title" class="font-heading font-bold text-xl text-gray-900 dark:text-white mt-0.5">What's new from RARL</h2>
      <div class="flex gap-2 mt-3 overflow-x-auto -mx-1 px-1 pb-1" role="tablist" aria-label="Filter updates">
        <?php foreach (['' => 'All', 'announcement' => 'Announcements', 'event' => 'Events', 'resource' => 'Learning', 'post' => 'Team updates', 'newsletter' => 'Newsletters'] as $k => $label):
          if ($k !== '' && empty($kinds[$k])) continue; ?>
        <button type="button" role="tab" data-filter="<?= $k ?>" aria-selected="<?= $k === '' ? 'true' : 'false' ?>" class="feed-chip flex-shrink-0 px-3.5 py-1.5 rounded-full text-sm font-semibold border transition-colors"><?= $label ?><?php if ($k !== ''): ?> <span class="opacity-60"><?= $kinds[$k] ?></span><?php endif; ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div id="feed" class="space-y-4">
      <?php foreach ($feed as $it): ?><div data-kind="<?= $it['kind'] ?>"><?= renderFeedItem($it) ?></div><?php endforeach; ?>
      <?php if (!$feed): ?>
      <div class="rarl-panel p-12 text-center"><i class="fa-regular fa-newspaper text-3xl text-gray-300"></i><p class="font-semibold text-gray-700 dark:text-gray-200 mt-3">Nothing new yet</p><p class="text-sm text-gray-500 mt-1">Announcements, events and learning material from RARL will appear here.</p></div>
      <?php endif; ?>
    </div>
    <?php if ($feed): ?><p class="text-center text-sm text-gray-500 py-4">You're all caught up · <a href="community.php" class="font-semibold text-rarl-red hover:underline">See all updates</a></p><?php endif; ?>
  </section>

  <!-- ── Right: coming up ──────────────────────────────── -->
  <aside class="order-3 md:order-none space-y-4 min-w-0 md:col-start-2 lg:col-start-auto lg:sticky lg:top-[88px]">
    <section class="rarl-panel overflow-hidden">
      <div class="px-4 pt-4 pb-2 flex items-center justify-between"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Upcoming events</h2><a href="events.php" class="text-xs font-semibold text-gray-500 hover:text-rarl-red">See all</a></div>
      <?php if (!$upcoming): ?><p class="px-4 pb-4 text-sm text-gray-500">No events scheduled right now — new ones will show up in your feed.</p><?php endif; ?>
      <ul class="divide-y divide-gray-100 dark:divide-gray-800">
        <?php foreach ($upcoming as $e):
          $mine = in_array($e['my_status'], ['registered', 'attended'], true);
          $full = $e['capacity'] && $e['reg_count'] >= $e['capacity'] && !$mine; ?>
        <li class="px-4 py-3 flex gap-3">
          <div class="w-11 text-center flex-shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 py-1 h-fit">
            <?php if ($e['event_date']): ?><div class="text-[10px] font-bold uppercase text-rarl-red leading-tight"><?= date('M', strtotime($e['event_date'])) ?></div><div class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-none"><?= date('d', strtotime($e['event_date'])) ?></div>
            <?php else: ?><div class="text-[10px] font-bold text-gray-500 py-1.5">TBA</div><?php endif; ?>
          </div>
          <div class="min-w-0 flex-1">
            <a href="events.php#event-<?= (int)$e['id'] ?>" class="block text-sm font-semibold text-gray-900 dark:text-white leading-snug hover:text-rarl-red line-clamp-2"><?= $h($e['title']) ?></a>
            <p class="text-xs text-gray-500 truncate"><?= ucfirst($e['type']) ?><?= $e['event_time'] ? ' · ' . date('g:i A', strtotime($e['event_time'])) : '' ?><?= $e['location'] ? ' · ' . $h($e['location']) : ($e['online_url'] ? ' · Online' : '') ?></p>
            <div class="mt-1.5">
              <?php if (!$isActive): ?>
              <a href="events.php#event-<?= (int)$e['id'] ?>" class="text-xs font-semibold text-gray-600 dark:text-gray-300 hover:text-rarl-red">Details</a>
              <?php elseif ($mine): ?>
              <span class="text-xs font-semibold text-green-700 dark:text-green-400"><i class="fa-solid fa-check"></i> You're going</span>
              <?php if ($e['online_url'] && $e['event_date'] === date('Y-m-d')): ?> · <a href="<?= $h($e['online_url']) ?>" target="_blank" rel="noopener" class="text-xs font-semibold text-rarl-red hover:underline">Join now</a><?php endif; ?>
              <?php elseif ($full): ?>
              <span class="text-xs font-semibold text-gray-500">Fully booked</span>
              <?php else: ?>
              <form method="POST" action="events.php"><?= csrfField() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="return" value="dashboard">
                <button class="text-xs font-semibold px-3 py-1 rounded-full border border-gray-400 dark:border-gray-600 text-gray-800 dark:text-gray-200 hover:border-rarl-red hover:text-rarl-red"><i class="fa-solid fa-plus"></i> Register</button></form>
              <?php endif; ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <?php if ($leaders): ?>
    <section class="rarl-panel p-4">
      <div class="flex items-center justify-between mb-2"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Chapter leaders</h2><a href="people.php#chapters" class="text-xs font-semibold text-gray-500 hover:text-rarl-red">See all</a></div>
      <ul class="space-y-2.5">
        <?php foreach (array_slice($leaders, 0, 4) as $c): ?>
        <li class="flex items-center gap-3">
          <span class="w-9 h-9 rounded-full bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xs font-bold flex-shrink-0"><?= $h(mb_strtoupper(mb_substr((string)$c['chair_name'], 0, 1))) ?></span>
          <span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-gray-900 dark:text-white truncate"><?= $h($c['chair_name']) ?></span><span class="block text-xs text-gray-500 truncate"><?= $h($c['chair_title'] ?: 'Chapter Chair') ?> · <?= $h($c['name']) ?></span></span>
          <?php if (!empty($c['chair_email'])): ?><a href="mailto:<?= $h($c['chair_email']) ?>" class="w-8 h-8 rounded-full text-gray-500 hover:text-rarl-red hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center" aria-label="Email <?= $h($c['chair_name']) ?>"><i class="fa-solid fa-envelope text-sm"></i></a><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <section class="rarl-panel p-4">
      <h2 class="text-sm font-bold text-gray-900 dark:text-white mb-3">Explore RARL</h2>
      <ul class="space-y-1 text-sm">
        <?php foreach ([
          ['resources.php', 'fa-graduation-cap', 'Learning hub', 'Courses, talks and reading lists', false],
          ['people.php', 'fa-people-group', 'Leadership & chapters', 'Who runs RARL around the world', false],
          ['assets/JOINRARL.pdf', 'fa-envelope-open-text', 'Welcome letter', 'Your membership, explained', true],
          ['partners.php', 'fa-handshake', 'Partner with RARL', 'Labs, industry and sponsors', false],
          [MAIN_SITE_URL, 'fa-flask', 'rarl-lab.com', 'Research, projects and news', true],
        ] as [$url, $ic, $t, $sub, $ext]): ?>
        <li><a href="<?= $url ?>" <?= $ext ? 'target="_blank" rel="noopener"' : '' ?> class="flex items-center gap-3 p-2 -mx-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800/60 group">
          <span class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 flex items-center justify-center flex-shrink-0 group-hover:text-rarl-red"><i class="fa-solid <?= $ic ?> text-sm"></i></span>
          <span class="min-w-0"><span class="block font-semibold text-gray-800 dark:text-gray-100 leading-tight"><?= $t ?><?= $ext ? ' <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-gray-400"></i>' : '' ?></span><span class="block text-xs text-gray-500 truncate"><?= $sub ?></span></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <p class="text-[11px] text-gray-500 px-2 leading-relaxed"><a href="verify.php" class="hover:underline">Verify a certificate</a> · <a href="mailto:<?= $h(MAIL_REPLY_TO) ?>" class="hover:underline">Contact RARL</a> · © <?= date('Y') ?> RARL</p>
  </aside>
</main>
</div>
<style>
  .feed-chip{border-color:#d1d5db;color:#374151;background:#fff}
  .dark .feed-chip{border-color:#374151;color:#d1d5db;background:transparent}
  .feed-chip[aria-selected=true]{background:#CC0703;border-color:#CC0703;color:#fff}
</style>
<script>
  document.querySelectorAll('[data-filter]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var k = chip.dataset.filter;
      document.querySelectorAll('[data-filter]').forEach(function (c) { c.setAttribute('aria-selected', c === chip ? 'true' : 'false'); });
      document.querySelectorAll('#feed > [data-kind]').forEach(function (el) { el.hidden = !!k && el.dataset.kind !== k; });
    });
  });
</script>
<?= publicFooter() ?>
</body></html>
