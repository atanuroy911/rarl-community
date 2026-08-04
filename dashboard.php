<?php
/**
 * RARL — Member Dashboard
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in to access your dashboard.'); redirect('login.php'); }

$pdo      = db();
$memberId = (int)$_SESSION['member_id'];
$member   = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$member->execute([$memberId]);
$m = $member->fetch();
if (!$m) { session_destroy(); redirect('login.php'); }

$displayName = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];

// Fetch certificates
$certs = $pdo->prepare("
    SELECT c.*, e.title as event_title, e.type as event_type, e.event_date
    FROM certificates c
    LEFT JOIN events e ON c.event_id = e.id
    WHERE c.member_id = ? OR c.recipient_email = ?
    ORDER BY c.issued_at DESC
");
$certs->execute([$memberId, $m['email']]);
$certificates = $certs->fetchAll();

// Announcements — general (site-wide) plus this member's chapter-only ones
$annStmt = $pdo->prepare("SELECT * FROM announcements WHERE is_published = 1 AND (section_id IS NULL OR section_id = ?) ORDER BY is_pinned DESC, created_at DESC LIMIT 5");
$annStmt->execute([$m['section_id'] ?: null]);
$announcements = $annStmt->fetchAll();

// Onboarding checklist — only relevant once approved; hidden once everything's done
$hasPostedStmt = $pdo->prepare('SELECT 1 FROM community_posts WHERE member_id = ? LIMIT 1');
$hasPostedStmt->execute([$memberId]);
$checklist = [
    ['done' => !empty($m['avatar_path']), 'label' => 'Add a profile photo', 'sub' => 'Required for your ID card', 'href' => 'profile.php'],
    ['done' => !empty($m['id_card_path']), 'label' => 'Get your ID card', 'sub' => 'Generated automatically once you have a photo', 'href' => 'profile.php'],
    ['done' => (bool)($m['institution'] ?? $m['lab_website'] ?? ''), 'label' => 'Complete your profile', 'sub' => 'Institution, research interests, and more', 'href' => 'profile.php'],
    ['done' => (bool)$hasPostedStmt->fetch(), 'label' => 'Say hello in the community', 'sub' => 'Share an update or introduce yourself', 'href' => 'community.php'],
];
$checklistDone = count(array_filter($checklist, fn($c) => $c['done']));

echo htmlHead('My Dashboard');
?>
<?= publicNav('dashboard') ?>

<div class="bg-gray-50 dark:bg-gray-950 min-h-screen">

  <!-- Dashboard header -->
  <div class="bg-rarl-navy border-b border-rarl-mid">
    <div class="max-w-6xl mx-auto px-6 py-8 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
      <div class="flex items-center gap-4">
        <?php $needsAttention = memberNeedsAttention($m); ?>
        <div class="relative flex-shrink-0">
          <?php if (!empty($m['avatar_path'])): ?>
          <img src="<?= UPLOADS_URL ?>/avatars/<?= htmlspecialchars($m['avatar_path']) ?>" alt=""
            class="w-14 h-14 rounded-2xl object-cover shadow-lg<?= $needsAttention ? ' ring-2 ring-rarl-red ring-offset-2 ring-offset-rarl-navy' : '' ?>"/>
          <?php else: ?>
          <div class="w-14 h-14 bg-rarl-red rounded-2xl flex items-center justify-center text-white font-heading font-black text-xl shadow-lg<?= $needsAttention ? ' ring-2 ring-white/60 ring-offset-2 ring-offset-rarl-navy' : '' ?>">
            <?= strtoupper(substr($displayName, 0, 1)) ?>
          </div>
          <?php endif; ?>
          <?php if ($needsAttention): ?>
          <span class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-rarl-red rounded-full border-2 border-rarl-navy" title="Complete your profile to finish setup"></span>
          <?php endif; ?>
        </div>
        <div>
          <p class="text-white/50 text-xs uppercase tracking-wider mb-0.5"><?= $m['type'] === 'lab' ? '<i class="fa-solid fa-building-columns"></i> Research Lab' : '<i class="fa-solid fa-user"></i> Individual Researcher' ?></p>
          <h1 class="font-heading font-black text-xl text-white"><?= htmlspecialchars($displayName) ?></h1>
          <p class="text-white/50 text-xs mt-0.5">
            <?= htmlspecialchars($m['institution'] ?? '') ?>
            <?php if ($m['country']): ?> · <?= htmlspecialchars($m['country']) ?><?php endif; ?>
          </p>
        </div>
      </div>
      <div class="flex gap-3">
        <a href="profile.php" class="px-4 py-2 bg-white/10 hover:bg-white/20 text-white text-sm font-semibold rounded-xl border border-white/20 transition-colors">Edit Profile</a>
        <a href="logout.php" class="px-4 py-2 bg-white/5 hover:bg-white/15 text-white/60 hover:text-white text-sm rounded-xl border border-white/10 transition-colors">Sign Out</a>
      </div>
    </div>
  </div>

  <div class="max-w-6xl mx-auto px-6 py-8">
    <?= renderFlash() ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-7">

      <!-- ── LEFT: Main Content ── -->
      <div class="lg:col-span-2 space-y-7">

        <!-- Certificates -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
          <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white"><i class="fa-solid fa-trophy"></i> My Certificates</h2>
            <span class="text-xs bg-rarl-red/10 text-rarl-red font-semibold px-2.5 py-1 rounded-full"><?= count($certificates) ?> issued</span>
          </div>

          <?php if (empty($certificates)): ?>
          <div class="p-10 text-center">
            <div class="text-4xl mb-3"><i class="fa-solid fa-graduation-cap"></i></div>
            <p class="font-semibold text-gray-700 dark:text-gray-200 mb-1">No certificates yet</p>
            <p class="text-gray-400 text-sm">Attend workshops and events to earn your first certificate.</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($certificates as $cert):
              $typeIcons = ['workshop'=>'<i class="fa-solid fa-wrench"></i>','webinar'=>'<i class="fa-solid fa-laptop-code"></i>','hackathon'=>'<i class="fa-solid fa-bolt"></i>','competition'=>'<i class="fa-solid fa-trophy"></i>','volunteer'=>'<i class="fa-solid fa-handshake"></i>','conference'=>'<i class="fa-solid fa-microphone"></i>','seminar'=>'<i class="fa-solid fa-book-open"></i>','other'=>'<i class="fa-solid fa-graduation-cap"></i>'];
              $icon = $typeIcons[$cert['event_type'] ?? 'other'] ?? '<i class="fa-solid fa-graduation-cap"></i>';
            ?>
            <div class="p-5 flex items-center justify-between gap-4 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
              <div class="flex items-center gap-4">
                <div class="w-11 h-11 bg-amber-100 dark:bg-amber-900/30 rounded-xl flex items-center justify-center text-xl flex-shrink-0"><?= $icon ?></div>
                <div>
                  <p class="font-semibold text-sm text-gray-900 dark:text-white"><?= $cert['cert_type'] === 'membership' ? 'RARL Membership Certificate' : htmlspecialchars($cert['event_title'] ?? 'Event') ?></p>
                  <p class="text-xs text-gray-400 mt-0.5">
                    <?= htmlspecialchars($cert['certificate_no']) ?>
                    · <?= $cert['event_date'] ? date('d M Y', strtotime($cert['event_date'])) : date('d M Y', strtotime($cert['issued_at'])) ?>
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <a href="verify.php?id=<?= urlencode($cert['uuid']) ?>" target="_blank"
                  class="px-3 py-1.5 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300 rounded-lg transition-colors">Verify</a>
                <?php if ($cert['pdf_path'] && file_exists(UPLOADS_PATH . '/certificates/' . $cert['pdf_path'])): ?>
                <a href="download-cert.php?id=<?= urlencode($cert['uuid']) ?>"
                  class="px-3 py-1.5 text-xs font-semibold text-white bg-rarl-red hover:bg-rarl-dark rounded-lg transition-colors">Download PDF</a>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Quick links -->
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
          <?php $quickLinks = [
            ['community.php','<i class="fa-solid fa-comment"></i>','Community Feed','Join the discussion'],
            ['resources.php','<i class="fa-solid fa-book"></i>','Learning Hub','Curated resources'],
            [MAIN_SITE_URL,'<i class="fa-solid fa-flask"></i>','RARL Lab','Main website'],
          ]; foreach ($quickLinks as [$url, $ic, $title, $sub]): ?>
          <a href="<?= $url ?>" class="block p-5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl hover:-translate-y-1 hover:shadow-md transition-all">
            <div class="text-2xl mb-2"><?= $ic ?></div>
            <div class="font-semibold text-sm text-gray-900 dark:text-white"><?= $title ?></div>
            <div class="text-xs text-gray-400 mt-0.5"><?= $sub ?></div>
          </a>
          <?php endforeach; ?>
        </div>

      </div>

      <!-- ── RIGHT: Sidebar ── -->
      <div class="space-y-5">

        <!-- Onboarding checklist -->
        <?php if ($m['status'] === 'active' && $checklistDone < count($checklist)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white">Getting Started</h3>
            <span class="text-[10px] font-bold text-gray-400"><?= $checklistDone ?>/<?= count($checklist) ?></span>
          </div>
          <div class="w-full h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full mb-4 overflow-hidden">
            <div class="h-full bg-rarl-red rounded-full" style="width:<?= round($checklistDone / count($checklist) * 100) ?>%"></div>
          </div>
          <div class="space-y-2.5">
            <?php foreach ($checklist as $item): ?>
            <a href="<?= $item['done'] ? '#' : $item['href'] ?>" class="flex items-start gap-2.5 text-xs <?= $item['done'] ? 'pointer-events-none' : 'hover:text-rarl-red' ?> transition-colors">
              <i class="fa-solid <?= $item['done'] ? 'fa-circle-check text-green-500' : 'fa-circle text-gray-300' ?> mt-0.5"></i>
              <div>
                <p class="font-semibold <?= $item['done'] ? 'text-gray-400 line-through' : 'text-gray-800 dark:text-gray-200' ?>"><?= htmlspecialchars($item['label']) ?></p>
                <?php if (!$item['done']): ?><p class="text-gray-400 mt-0.5"><?= htmlspecialchars($item['sub']) ?></p><?php endif; ?>
              </div>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Status badge -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
          <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white mb-4">Account Status</h3>
          <div class="space-y-2.5 text-xs">
            <div class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-gray-800">
              <span class="text-gray-500">Status</span>
              <span class="<?= $m['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?> font-bold px-2.5 py-0.5 rounded-full">
                <?= ucfirst($m['status']) ?>
              </span>
            </div>
            <div class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-gray-800">
              <span class="text-gray-500">Type</span>
              <span class="text-gray-700 dark:text-gray-300 font-semibold"><?= $m['type'] === 'lab' ? 'Research Lab' : 'Individual' ?></span>
            </div>
            <div class="flex justify-between items-center py-1.5 border-b border-gray-100 dark:border-gray-800">
              <span class="text-gray-500">Newsletter</span>
              <span class="<?= $m['newsletter_opt_in'] ? 'text-green-600' : 'text-gray-400' ?> font-semibold">
                <?= $m['newsletter_opt_in'] ? '<i class="fa-solid fa-circle-check"></i> Subscribed' : 'Unsubscribed' ?>
              </span>
            </div>
          </div>

          <?php if ($m['status'] === 'active'): ?>
          <a href="community.php" class="block w-full text-center mt-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold rounded-xl transition-colors">
            <i class="fa-solid fa-comment"></i> Visit Community Feed
          </a>
          <?php endif; ?>
        </div>

        <!-- Announcements -->
        <?php if ($announcements): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white"><i class="fa-solid fa-thumbtack"></i> Announcements</h3>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($announcements as $ann):
              $typeColors = ['event'=>'blue','opportunity'=>'green','alert'=>'red','general'=>'gray'];
              $tc = $typeColors[$ann['type']] ?? 'gray';
            ?>
            <div class="p-4">
              <?php if ($ann['is_pinned']): ?><span class="text-[10px] font-bold text-amber-600 uppercase tracking-wider"><i class="fa-solid fa-thumbtack"></i> Pinned · </span><?php endif; ?>
              <p class="font-semibold text-sm text-gray-800 dark:text-white leading-snug mb-1"><?= htmlspecialchars($ann['title']) ?></p>
              <p class="text-xs text-gray-400 leading-relaxed"><?= htmlspecialchars(mb_strimwidth($ann['content'], 0, 120, '…')) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<?= publicFooter() ?>
</body></html>
