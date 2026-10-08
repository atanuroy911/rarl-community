<?php
/**
 * RARL — Member Dashboard
 * Everything a member comes back for in one place: their ID card, upcoming
 * events (one-click RSVP), certificates, chapter, and what to do next.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Dashboard');
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in to access your dashboard.'); redirect('login.php?next=dashboard.php'); }

$pdo      = db();
$memberId = (int)$_SESSION['member_id'];
$member   = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$member->execute([$memberId]);
$m = $member->fetch();
if (!$m) { session_destroy(); redirect('login.php'); }

$displayName = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
$firstName = $m['type'] === 'lab' ? $displayName : (preg_split('/\s+/', preg_replace('/^(dr|prof|mr|mrs|ms|engr)\.?\s+/i', '', trim((string)$displayName)))[0] ?? $displayName);

$certs = $pdo->prepare("
    SELECT c.*, e.title as event_title, e.type as event_type, e.event_date
    FROM certificates c LEFT JOIN events e ON c.event_id = e.id
    WHERE c.member_id = ? OR c.recipient_email = ?
    ORDER BY c.issued_at DESC");
$certs->execute([$memberId, $m['email']]);
$certificates = $certs->fetchAll();

$annStmt = $pdo->prepare("SELECT * FROM announcements WHERE is_published = 1 AND (section_id IS NULL OR section_id = ?) ORDER BY is_pinned DESC, created_at DESC LIMIT 4");
$annStmt->execute([$m['section_id'] ?: null]);
$announcements = $annStmt->fetchAll();

$chapter = null;
if ($m['section_id']) {
    $s = $pdo->prepare('SELECT * FROM regional_sections WHERE id = ?'); $s->execute([$m['section_id']]);
    $chapter = $s->fetch() ?: null;
}

$evStmt = $pdo->prepare("SELECT e.*, r.status AS my_status,
        (SELECT COUNT(*) FROM event_registrations x WHERE x.event_id = e.id AND x.status != 'cancelled') AS reg_count
    FROM events e LEFT JOIN event_registrations r ON r.event_id = e.id AND r.member_id = ?
    WHERE e.is_active = 1 AND (e.event_date IS NULL OR e.event_date >= CURDATE())
    ORDER BY e.event_date IS NULL, e.event_date ASC LIMIT 4");
$evStmt->execute([$memberId]);
$upcoming = $evStmt->fetchAll();

// Live preview of their card from the designed template (the PDF is the official copy).
$cardHtml = null;
$cardTemplate = getDefaultTemplate('id_card');
if ($cardTemplate && !empty($m['id_card_path'])) {
    $cardHtml = renderTemplateHtml($cardTemplate, [
        'name' => $displayName, 'member_code' => '#' . $m['member_code'], 'section' => $chapter['name'] ?? '—',
        'since_date' => date('Y/m/d', strtotime($m['created_at'])),
        'signer1' => setting('idcard_signer1_name', 'RARL President'),
        'signer2' => ($chapter['chair_name'] ?? '') ?: setting('idcard_signer2_name', 'Chapter Chair'),
        'avatar_url' => UPLOADS_URL . '/avatars/' . $m['avatar_path'],
    ]);
}
$cardExpired = !empty($m['id_card_expires_at']) && strtotime($m['id_card_expires_at']) < time();

$hasPostedStmt = $pdo->prepare('SELECT 1 FROM community_posts WHERE member_id = ? LIMIT 1');
$hasPostedStmt->execute([$memberId]);
$checklist = [
    ['done' => !empty($m['avatar_path']), 'label' => 'Add a profile photo', 'sub' => 'Your ID card is created from it automatically', 'href' => 'profile.php#photo', 'icon' => 'fa-camera'],
    ['done' => (bool)(($m['institution'] ?? '') ?: ($m['lab_website'] ?? '')), 'label' => 'Complete your profile', 'sub' => 'Institution and research interests help others find you', 'href' => 'profile.php', 'icon' => 'fa-user-pen'],
    ['done' => (bool)$chapter, 'label' => 'Join a chapter', 'sub' => 'Connect with members in your region', 'href' => 'chapter.php', 'icon' => 'fa-earth-americas'],
    ['done' => (bool)$hasPostedStmt->fetch(), 'label' => 'Say hello in the community', 'sub' => 'Introduce yourself and your research', 'href' => 'community.php', 'icon' => 'fa-hand'],
];
$checklistDone = count(array_filter($checklist, fn($c) => $c['done']));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

echo htmlHead('My Dashboard');
?>
<?= publicNav('dashboard') ?>
<style>
  .db-card{background:#fff;border:1px solid #e8eaee;border-radius:1.25rem;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px -16px rgba(15,23,42,.12)}
  .dark .db-card{background:#0f172a;border-color:#1e293b}
  .db-title{font-family:inherit;font-weight:800;font-size:1rem;letter-spacing:-.01em}
</style>

<div class="bg-gray-50 dark:bg-gray-950 min-h-screen pb-16">
  <!-- Hero -->
  <div class="relative overflow-hidden bg-rarl-navy">
    <div class="absolute inset-0 opacity-30 bg-[radial-gradient(circle_at_85%_-10%,#CC0703,transparent_45%)]"></div>
    <div class="relative max-w-6xl mx-auto px-5 sm:px-6 pt-8 pb-20">
      <div class="flex flex-col sm:flex-row sm:items-center gap-5">
        <a href="profile.php#photo" class="relative flex-shrink-0 group" title="Change photo">
          <?php if (!empty($m['avatar_path'])): ?>
          <img src="<?= UPLOADS_URL ?>/avatars/<?= htmlspecialchars($m['avatar_path']) ?>" alt="" class="w-20 h-20 rounded-2xl object-cover shadow-xl ring-4 ring-white/10"/>
          <?php else: ?>
          <div class="w-20 h-20 bg-rarl-red rounded-2xl flex items-center justify-center text-white font-heading font-black text-3xl shadow-xl ring-4 ring-white/10"><?= htmlspecialchars(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></div>
          <span class="absolute -bottom-1 -right-1 w-7 h-7 rounded-full bg-white text-rarl-red flex items-center justify-center text-xs shadow"><i class="fa-solid fa-camera"></i></span>
          <?php endif; ?>
        </a>
        <div class="flex-1 min-w-0">
          <p class="text-white/60 text-sm"><?= $greeting ?>,</p>
          <h1 class="font-heading font-black text-2xl sm:text-3xl text-white leading-tight truncate"><?= htmlspecialchars($firstName) ?></h1>
          <div class="flex flex-wrap items-center gap-2 mt-2 text-xs">
            <?php if (!empty($m['member_code'])): ?><span class="px-2.5 py-1 rounded-full bg-white/10 text-white font-mono">#<?= htmlspecialchars($m['member_code']) ?></span><?php endif; ?>
            <span class="px-2.5 py-1 rounded-full bg-white/10 text-white/80"><?= $m['type'] === 'lab' ? '<i class="fa-solid fa-building-columns"></i> Research lab' : '<i class="fa-solid fa-user"></i> Researcher' ?></span>
            <?php if ($chapter): ?><a href="chapter.php" class="px-2.5 py-1 rounded-full bg-white/10 text-white/80 hover:bg-white/20"><i class="fa-solid fa-earth-americas"></i> <?= htmlspecialchars($chapter['name']) ?></a><?php endif; ?>
            <span class="px-2.5 py-1 rounded-full bg-white/10 text-white/60">Member since <?= date('M Y', strtotime($m['created_at'])) ?></span>
          </div>
        </div>
        <div class="flex gap-2">
          <a href="profile.php" class="px-4 py-2.5 bg-white text-gray-900 text-sm font-semibold rounded-xl hover:bg-gray-100 shadow"><i class="fa-solid fa-pen"></i> Edit profile</a>
          <a href="community.php" class="px-4 py-2.5 bg-white/10 hover:bg-white/20 text-white text-sm font-semibold rounded-xl border border-white/15"><i class="fa-solid fa-comments"></i> Community</a>
        </div>
      </div>
    </div>
  </div>

  <div class="max-w-6xl mx-auto px-5 sm:px-6 -mt-12 relative">
    <?= renderFlash() ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

      <div class="lg:col-span-2 space-y-6">
        <!-- ID card -->
        <section class="db-card p-5 sm:p-6">
          <div class="flex items-center justify-between mb-4">
            <h2 class="db-title text-gray-900 dark:text-white"><i class="fa-solid fa-id-card text-rarl-red"></i> Member ID card</h2>
            <?php if (!empty($m['id_card_path'])): ?><span class="text-xs <?= $cardExpired ? 'text-red-600 font-semibold' : 'text-gray-500' ?>"><?= $cardExpired ? 'Expired ' : 'Valid until ' ?><?= date('d M Y', strtotime($m['id_card_expires_at'])) ?></span><?php endif; ?>
          </div>
          <?php if (!empty($m['id_card_path'])): ?>
          <div class="grid sm:grid-cols-[minmax(0,1fr)_200px] gap-5 items-center">
            <div class="rounded-xl overflow-hidden [&>div]:!shadow-lg [&>div]:!rounded-xl">
              <?= $cardHtml ?: '<div class="aspect-[86/54] rounded-xl bg-gradient-to-br from-gray-800 to-gray-950 text-white p-5 flex flex-col justify-between"><span class="font-heading font-black">RARL</span><span><span class="block font-bold text-lg">' . htmlspecialchars($displayName) . '</span><span class="font-mono text-sm text-white/70">#' . htmlspecialchars($m['member_code'] ?? '') . '</span></span></div>' ?>
            </div>
            <div class="space-y-2">
              <a href="uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="flex items-center justify-center gap-2 w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold rounded-xl"><i class="fa-solid fa-download"></i> Download PDF</a>
              <a href="id-card-verify.php?code=<?= urlencode($m['member_code']) ?>" target="_blank" class="flex items-center justify-center gap-2 w-full py-2.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm font-semibold rounded-xl"><i class="fa-solid fa-shield-halved"></i> Verification page</a>
              <p class="text-[11px] text-gray-500 leading-relaxed">The QR code on your card links to this verification page, so anyone can confirm your membership.</p>
            </div>
          </div>
          <?php elseif ($m['status'] === 'active'): ?>
          <div class="flex flex-col sm:flex-row items-center gap-5 p-5 rounded-xl bg-gradient-to-br from-rarl-red/5 to-transparent border border-dashed border-rarl-red/30">
            <div class="w-16 h-16 rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-2xl flex-shrink-0"><i class="fa-solid fa-camera"></i></div>
            <div class="flex-1 text-center sm:text-left">
              <p class="font-semibold text-gray-900 dark:text-white">Add a photo to get your ID card</p>
              <p class="text-sm text-gray-500 mt-0.5">Your card is generated instantly once you upload a clear headshot.</p>
            </div>
            <a href="profile.php#photo" class="px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold rounded-xl whitespace-nowrap">Upload photo</a>
          </div>
          <?php endif; ?>
        </section>

        <!-- Upcoming events -->
        <section class="db-card overflow-hidden">
          <div class="px-5 sm:px-6 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-800">
            <h2 class="db-title text-gray-900 dark:text-white"><i class="fa-solid fa-calendar-days text-rarl-red"></i> Upcoming events</h2>
            <a href="events.php" class="text-xs font-semibold text-rarl-red hover:underline">All events →</a>
          </div>
          <?php if (!$upcoming): ?>
          <p class="p-8 text-center text-sm text-gray-500">No upcoming events right now — we'll announce the next one in the community.</p>
          <?php endif; ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($upcoming as $e):
              $mine = in_array($e['my_status'], ['registered','attended'], true);
              $full = $e['capacity'] && $e['reg_count'] >= $e['capacity'] && !$mine; ?>
            <div class="p-4 sm:px-6 flex items-center gap-4">
              <div class="w-14 text-center flex-shrink-0 rounded-xl border border-gray-200 dark:border-gray-700 py-1.5">
                <?php if ($e['event_date']): ?><div class="text-[10px] font-bold uppercase text-rarl-red"><?= date('M', strtotime($e['event_date'])) ?></div><div class="font-heading font-black text-xl text-gray-900 dark:text-white leading-none"><?= date('d', strtotime($e['event_date'])) ?></div>
                <?php else: ?><div class="text-[10px] font-bold text-gray-400 py-2">TBA</div><?php endif; ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-sm text-gray-900 dark:text-white truncate"><?= htmlspecialchars($e['title']) ?></p>
                <p class="text-xs text-gray-500 truncate"><?= ucfirst($e['type']) ?><?= $e['event_time'] ? ' · ' . date('g:i A', strtotime($e['event_time'])) : '' ?><?= $e['location'] ? ' · ' . htmlspecialchars($e['location']) : ($e['online_url'] ? ' · Online' : '') ?></p>
              </div>
              <?php if ($m['status'] !== 'active'): ?>
              <a href="events.php#event-<?= $e['id'] ?>" class="text-xs font-semibold text-gray-500 hover:text-rarl-red">Details</a>
              <?php elseif ($mine): ?>
              <span class="flex items-center gap-2">
                <span class="text-xs font-semibold text-green-700 bg-green-50 dark:bg-green-900/30 dark:text-green-300 px-2.5 py-1 rounded-full"><i class="fa-solid fa-check"></i> Going</span>
                <?php if ($e['online_url'] && $e['event_date'] === date('Y-m-d')): ?><a href="<?= htmlspecialchars($e['online_url']) ?>" target="_blank" rel="noopener" class="text-xs font-semibold text-white bg-rarl-red px-3 py-1.5 rounded-lg">Join</a><?php endif; ?>
              </span>
              <?php elseif ($full): ?>
              <span class="text-xs font-semibold text-gray-400">Full</span>
              <?php else: ?>
              <form method="POST" action="events.php"><?= csrfField() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="event_id" value="<?= $e['id'] ?>"><input type="hidden" name="return" value="dashboard">
                <button class="text-xs font-semibold text-white bg-gray-900 hover:bg-black dark:bg-white dark:text-gray-900 px-3.5 py-1.5 rounded-lg">Register</button></form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- Certificates -->
        <section class="db-card overflow-hidden">
          <div class="px-5 sm:px-6 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-800">
            <h2 class="db-title text-gray-900 dark:text-white"><i class="fa-solid fa-trophy text-rarl-red"></i> My certificates</h2>
            <span class="text-xs bg-rarl-red/10 text-rarl-red font-semibold px-2.5 py-1 rounded-full"><?= count($certificates) ?></span>
          </div>
          <?php if (!$certificates): ?>
          <div class="p-8 text-center"><p class="font-semibold text-gray-700 dark:text-gray-200">No certificates yet</p><p class="text-sm text-gray-500 mt-1">Attend an event to earn your first one — it arrives by email and appears here.</p></div>
          <?php endif; ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($certificates as $cert): $isMembership = ($cert['cert_type'] ?? '') === 'membership'; ?>
            <div class="p-4 sm:px-6 flex items-center gap-4">
              <div class="w-11 h-11 <?= $isMembership ? 'bg-purple-100 text-purple-600 dark:bg-purple-900/30' : 'bg-amber-100 text-amber-600 dark:bg-amber-900/30' ?> rounded-xl flex items-center justify-center text-lg flex-shrink-0"><i class="fa-solid <?= $isMembership ? 'fa-award' : 'fa-trophy' ?>"></i></div>
              <div class="flex-1 min-w-0">
                <p class="font-semibold text-sm text-gray-900 dark:text-white truncate"><?= $isMembership ? 'Certificate of Membership' : htmlspecialchars($cert['event_title'] ?? 'Event') ?></p>
                <p class="text-xs text-gray-500"><span class="font-mono"><?= htmlspecialchars($cert['certificate_no']) ?></span> · <?= date('d M Y', strtotime($cert['event_date'] ?: $cert['issued_at'])) ?></p>
              </div>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <button type="button" class="w-9 h-9 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" data-share="<?= htmlspecialchars(CERT_VERIFY_URL . '?id=' . $cert['uuid']) ?>" title="Copy verification link (great for LinkedIn)"><i class="fa-solid fa-share-nodes"></i></button>
                <?php if ($cert['pdf_path'] && file_exists(UPLOADS_PATH . '/certificates/' . $cert['pdf_path'])): ?>
                <a href="download-cert.php?id=<?= urlencode($cert['uuid']) ?>" class="px-3 py-2 text-xs font-semibold text-white bg-rarl-red hover:bg-rarl-dark rounded-lg"><i class="fa-solid fa-download"></i> PDF</a>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <aside class="space-y-6">
        <?php if ($m['status'] === 'active' && $checklistDone < count($checklist)): ?>
        <section class="db-card p-5">
          <div class="flex items-center justify-between mb-1">
            <h3 class="db-title text-gray-900 dark:text-white">Finish setting up</h3>
            <span class="text-xs font-bold text-gray-400"><?= $checklistDone ?>/<?= count($checklist) ?></span>
          </div>
          <div class="h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full my-3 overflow-hidden"><div class="h-full bg-rarl-red rounded-full" style="width:<?= round($checklistDone / count($checklist) * 100) ?>%"></div></div>
          <div class="space-y-1">
            <?php foreach ($checklist as $item): ?>
            <a href="<?= $item['done'] ? '#' : $item['href'] ?>" class="flex items-start gap-3 p-2 -mx-2 rounded-xl <?= $item['done'] ? 'pointer-events-none opacity-60' : 'hover:bg-gray-50 dark:hover:bg-gray-800' ?>">
              <span class="w-8 h-8 rounded-lg flex items-center justify-center text-sm flex-shrink-0 <?= $item['done'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 dark:bg-gray-800 text-gray-500' ?>"><i class="fa-solid <?= $item['done'] ? 'fa-check' : $item['icon'] ?>"></i></span>
              <span class="min-w-0"><span class="block text-sm font-semibold <?= $item['done'] ? 'line-through text-gray-400' : 'text-gray-900 dark:text-white' ?>"><?= htmlspecialchars($item['label']) ?></span><?php if (!$item['done']): ?><span class="block text-xs text-gray-500"><?= htmlspecialchars($item['sub']) ?></span><?php endif; ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

        <section class="db-card p-5">
          <h3 class="db-title text-gray-900 dark:text-white mb-3"><i class="fa-solid fa-earth-americas text-rarl-red"></i> My chapter</h3>
          <?php if ($chapter): ?>
          <p class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($chapter['name']) ?></p>
          <?php if (!empty($chapter['chair_name'])): ?>
          <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($chapter['chair_title'] ?? 'Chapter Chair') ?>: <span class="text-gray-800 dark:text-gray-200"><?= htmlspecialchars($chapter['chair_name']) ?></span></p>
          <?php if (!empty($chapter['chair_email'])): ?><a href="mailto:<?= htmlspecialchars($chapter['chair_email']) ?>" class="inline-flex items-center gap-1.5 mt-3 text-xs font-semibold text-rarl-red hover:underline"><i class="fa-regular fa-envelope"></i> Contact your chair</a><?php endif; ?>
          <?php endif; ?>
          <a href="chapter.php" class="block mt-3 text-xs font-semibold text-gray-500 hover:text-rarl-red">Chapter page →</a>
          <?php else: ?>
          <p class="text-sm text-gray-500">You're not in a chapter yet. Chapters run local meetups and events.</p>
          <a href="chapter.php" class="inline-block mt-3 px-4 py-2 bg-gray-900 dark:bg-white dark:text-gray-900 text-white text-xs font-semibold rounded-lg">Find my chapter</a>
          <?php endif; ?>
        </section>

        <?php if ($announcements): ?>
        <section class="db-card overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800"><h3 class="db-title text-gray-900 dark:text-white"><i class="fa-solid fa-bullhorn text-rarl-red"></i> Announcements</h3></div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($announcements as $ann): ?>
            <div class="p-4">
              <?php if ($ann['is_pinned']): ?><span class="text-[10px] font-bold text-amber-600 uppercase tracking-wider"><i class="fa-solid fa-thumbtack"></i> Pinned</span><?php endif; ?>
              <p class="font-semibold text-sm text-gray-900 dark:text-white leading-snug"><?= htmlspecialchars($ann['title']) ?></p>
              <p class="text-xs text-gray-500 leading-relaxed mt-1"><?= htmlspecialchars(mb_strimwidth($ann['content'], 0, 140, '…')) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

        <section class="grid grid-cols-2 gap-3">
          <?php foreach ([
            ['resources.php', 'fa-book', 'Learning hub'], ['directory.php', 'fa-magnifying-glass', 'Directory'],
            ['assets/JOINRARL.pdf', 'fa-envelope-open-text', 'Welcome letter'], [MAIN_SITE_URL, 'fa-flask', 'RARL Lab'],
          ] as [$url, $ic, $label]): ?>
          <a href="<?= $url ?>" <?= str_starts_with($url, 'assets/') || str_starts_with($url, 'http') ? 'target="_blank" rel="noopener"' : '' ?> class="db-card p-4 hover:-translate-y-0.5 transition-transform">
            <i class="fa-solid <?= $ic ?> text-rarl-red"></i>
            <span class="block text-sm font-semibold text-gray-900 dark:text-white mt-2"><?= $label ?></span>
          </a>
          <?php endforeach; ?>
          <a href="logout.php" class="col-span-2 text-center text-xs text-gray-500 hover:text-rarl-red py-2">Sign out</a>
        </section>
      </aside>
    </div>
  </div>
</div>
<script>
  document.querySelectorAll('[data-share]').forEach(b => b.addEventListener('click', async () => {
    const url = b.dataset.share;
    try { if (navigator.share) await navigator.share({title: 'My RARL certificate', url}); else { await navigator.clipboard.writeText(url); b.innerHTML = '<i class="fa-solid fa-check text-green-600"></i>'; setTimeout(() => b.innerHTML = '<i class="fa-solid fa-share-nodes"></i>', 1500); } } catch (e) {}
  }));
</script>
<?= publicFooter() ?>
</body></html>
