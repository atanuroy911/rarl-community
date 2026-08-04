<?php
/**
 * RARL — Community Portal (Feed + Regional Leadership)
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();

// ── POST handling (create_post / create_comment / toggle_like) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck() && !empty($_SESSION['member_id'])) {
    $memberId = (int)$_SESSION['member_id'];
    $action   = $_POST['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM members WHERE id=?');
    $stmt->execute([$memberId]);
    $me = $stmt->fetch();

    if ($action === 'create_post' && $me && $me['status'] === 'active') {
        // Rich HTML from the Quill editor — sanitized here, this is the only
        // place community_posts.body is allowed to contain raw HTML (body_format='html').
        $bodyHtml = sanitizeRichHtml($_POST['body_html'] ?? '');
        $hasText  = trim(strip_tags($bodyHtml)) !== '';

        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $imagePath = validateUpload($_FILES['image'], ['jpg','jpeg','png','webp'], 5 * 1024 * 1024, UPLOADS_PATH . '/community');
        }

        if ($hasText || $imagePath) {
            $pdo->prepare('INSERT INTO community_posts (member_id, body, body_format, image_path) VALUES (?,?,?,?)')
                ->execute([$memberId, $bodyHtml, 'html', $imagePath]);
        }
        header('Location: community.php#feed'); exit;
    }

    if ($action === 'create_comment' && $me && $me['status'] === 'active') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $body   = mb_substr(trim($_POST['body'] ?? ''), 0, 2000);
        if ($postId && $body !== '') {
            $pdo->prepare('INSERT INTO community_comments (post_id, member_id, body) VALUES (?,?,?)')->execute([$postId, $memberId, $body]);

            $ps = $pdo->prepare('SELECT community_posts.*, members.full_name, members.lab_name, members.type, members.community_notify, members.email
                                  FROM community_posts JOIN members ON members.id = community_posts.member_id
                                  WHERE community_posts.id = ?');
            $ps->execute([$postId]);
            $post = $ps->fetch();

            if ($post && (int)$post['member_id'] !== $memberId && (int)$post['community_notify'] === 1) {
                $authorEmail   = $post['email'];
                $authorName    = $post['type'] === 'lab' ? $post['lab_name'] : $post['full_name'];
                $commenterName = $me['type'] === 'lab' ? $me['lab_name'] : $me['full_name'];
                $memberName    = $authorName;
                $postExcerpt   = communityBodyExcerpt($post['body']);
                $feedUrl       = SITE_URL . '/community.php#post-' . $postId;
                ob_start();
                require __DIR__ . '/emails/community-comment.php';
                $emailBody = ob_get_clean();
                sendEmail($authorEmail, $authorName, 'New comment on your RARL Community post', $emailBody);
            }
        }
        header('Location: community.php#post-' . $postId); exit;
    }

    if ($action === 'edit_comment' && $me && $me['status'] === 'active') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $body = mb_substr(trim($_POST['body'] ?? ''), 0, 2000);
        if ($body !== '') {
            $pdo->prepare('UPDATE community_comments SET body=? WHERE id=? AND member_id=?')->execute([$body, $commentId, $memberId]);
        }
        $postId = (int)($_POST['post_id'] ?? 0);
        header('Location: community.php#post-' . $postId); exit;
    }

    if ($action === 'delete_own_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $postId    = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare('DELETE FROM community_comments WHERE id=? AND member_id=?')->execute([$commentId, $memberId]);
        header('Location: community.php#post-' . $postId); exit;
    }

    if ($action === 'edit_post' && $me && $me['status'] === 'active') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $own = $pdo->prepare('SELECT * FROM community_posts WHERE id=? AND member_id=?');
        $own->execute([$postId, $memberId]);
        $existing = $own->fetch();
        if ($existing) {
            $bodyHtml = sanitizeRichHtml($_POST['body_html'] ?? '');
            $hasText  = trim(strip_tags($bodyHtml)) !== '';

            $imagePath = $existing['image_path'];
            if (!empty($_FILES['image']['name'])) {
                $uploaded = validateUpload($_FILES['image'], ['jpg','jpeg','png','webp'], 5 * 1024 * 1024, UPLOADS_PATH . '/community');
                if ($uploaded) $imagePath = $uploaded;
            } elseif (!empty($_POST['remove_image'])) {
                $imagePath = null;
            }

            if ($hasText || $imagePath) {
                $pdo->prepare('UPDATE community_posts SET body=?, body_format=?, image_path=? WHERE id=?')
                    ->execute([$bodyHtml, 'html', $imagePath, $postId]);
            }
        }
        header('Location: community.php#post-' . $postId); exit;
    }

    if ($action === 'delete_own_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $own = $pdo->prepare('SELECT id FROM community_posts WHERE id=? AND member_id=?');
        $own->execute([$postId, $memberId]);
        if ($own->fetch()) {
            $pdo->prepare('DELETE FROM community_comments WHERE post_id=?')->execute([$postId]);
            $pdo->prepare('DELETE FROM community_likes WHERE post_id=?')->execute([$postId]);
            $pdo->prepare('DELETE FROM community_posts WHERE id=?')->execute([$postId]);
        }
        header('Location: community.php#feed'); exit;
    }

    if ($action === 'toggle_like') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId) {
            $chk = $pdo->prepare('SELECT 1 FROM community_likes WHERE post_id=? AND member_id=?');
            $chk->execute([$postId, $memberId]);
            if ($chk->fetch()) {
                $pdo->prepare('DELETE FROM community_likes WHERE post_id=? AND member_id=?')->execute([$postId, $memberId]);
            } else {
                try {
                    $pdo->prepare('INSERT INTO community_likes (post_id, member_id) VALUES (?,?)')->execute([$postId, $memberId]);
                } catch (PDOException $e) { /* already liked, ignore duplicate */ }
            }
        }
        header('Location: community.php#post-' . $postId); exit;
    }
}

$guidelines    = setting('community_guidelines');
$feedIntro     = setting('community_feed_intro', 'Share updates, ask questions, and connect with fellow RARL researchers.');
$isMember      = !empty($_SESSION['member_id']);
$myMemberId    = (int)($_SESSION['member_id'] ?? 0);
$myAvatarHtml  = '';
if ($isMember) {
    $meStmt = $pdo->prepare('SELECT full_name, lab_name, type, avatar_path FROM members WHERE id=?');
    $meStmt->execute([$myMemberId]);
    $meRow = $meStmt->fetch();
    if ($meRow) {
        $myName = $meRow['type'] === 'lab' ? $meRow['lab_name'] : $meRow['full_name'];
        $myAvatarHtml = memberAvatarHtml($meRow['avatar_path'], $myName ?: '?', 'w-10 h-10 text-sm');
    }
}

const COMMUNITY_PAGE_SIZE = 8;

// ── Lazy-load AJAX endpoint (?ajax=posts&offset=N) — returns just the next
// batch of rendered post cards, called by the IntersectionObserver below.
// Kept above the announcements/regions queries so a lazy-load request doesn't
// do any extra work beyond fetching its own page of posts.
if ($isMember && ($_GET['ajax'] ?? '') === 'posts') {
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $stmt = $pdo->prepare("SELECT community_posts.*, members.full_name, members.lab_name, members.type, members.avatar_path
                            FROM community_posts JOIN members ON members.id = community_posts.member_id
                            WHERE community_posts.is_hidden = 0
                            ORDER BY is_pinned DESC, community_posts.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, COMMUNITY_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $page = $stmt->fetchAll();

    header('Content-Type: text/html; charset=UTF-8');
    foreach ($page as $p) echo renderCommunityPost($pdo, $p, $myMemberId);
    echo '<!-- has_more:' . (count($page) === COMMUNITY_PAGE_SIZE ? '1' : '0') . ' -->';
    exit;
}

$announcements = $pdo->query("SELECT * FROM announcements WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();

// ── Feed data — first page only, the rest lazy-loads via the endpoint above ──
$posts = [];
$hasMorePosts = false;
if ($isMember) {
    $stmt = $pdo->prepare("SELECT community_posts.*, members.full_name, members.lab_name, members.type, members.avatar_path
                            FROM community_posts JOIN members ON members.id = community_posts.member_id
                            WHERE community_posts.is_hidden = 0
                            ORDER BY is_pinned DESC, community_posts.created_at DESC LIMIT ?");
    $stmt->bindValue(1, COMMUNITY_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();
    $hasMorePosts = count($posts) === COMMUNITY_PAGE_SIZE;
}

// ── Community stats (sidebar social proof) ──
$communityStats = [
    'members'   => (int) $pdo->query("SELECT COUNT(*) FROM members WHERE status='active'")->fetchColumn(),
    'posts'     => (int) $pdo->query("SELECT COUNT(*) FROM community_posts WHERE is_hidden=0")->fetchColumn(),
    'countries' => (int) $pdo->query("SELECT COUNT(DISTINCT country) FROM members WHERE status='active' AND country IS NOT NULL AND country != ''")->fetchColumn(),
];

// ── Regional sections, grouped by continent ──
$sections = $pdo->query("SELECT * FROM regional_sections WHERE is_published = 1 ORDER BY continent, display_order")->fetchAll();
$regionGroups = [];
foreach ($sections as $s) {
    $continent = $s['continent'];
    if (!isset($regionGroups[$continent])) $regionGroups[$continent] = ['continent' => [], 'country' => []];
    $regionGroups[$continent][$s['scope']][] = $s;
}

// ── Left sidebar profile card data ──
$myFull = null; $mySection = null;
if ($isMember) {
    $myFullStmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $myFullStmt->execute([$myMemberId]);
    $myFull = $myFullStmt->fetch();
    if ($myFull && $myFull['section_id']) {
        $ms = $pdo->prepare('SELECT name FROM regional_sections WHERE id = ?');
        $ms->execute([$myFull['section_id']]);
        $mySection = $ms->fetch();
    }
}
$upcomingEvents = $pdo->query("SELECT id, title, event_date FROM events WHERE is_active = 1 AND (event_date IS NULL OR event_date >= CURDATE()) ORDER BY event_date ASC LIMIT 3")->fetchAll();

echo htmlHead('Community Portal');
?>
<?= publicNav('community') ?>

<!-- Hero -->
<section class="relative overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 60%,#5865f2 100%);">
  <div class="absolute inset-0" style="background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:40px 40px;"></div>
  <div class="relative z-10 max-w-5xl mx-auto px-6 py-20 text-center">
    <div class="text-6xl mb-5"><i class="fa-solid fa-earth-americas"></i></div>
    <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-4">RARL Community Feed</h1>
    <p class="text-white/65 text-base max-w-lg mx-auto mb-8"><?= htmlspecialchars($feedIntro) ?></p>
    <?php if ($isMember): ?>
    <a href="#feed" class="inline-flex items-center gap-3 px-8 py-4 bg-white text-rarl-red font-black rounded-xl shadow-2xl hover:-translate-y-1 hover:shadow-3xl transition-all text-sm">
      Go to the Feed ↓
    </a>
    <?php else: ?>
    <div class="inline-flex flex-col items-center gap-3">
      <a href="register.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-rarl-red font-black rounded-xl shadow-xl hover:-translate-y-1 transition-all text-sm">
        <i class="fa-solid fa-lock"></i> Join as a Member to Post &amp; Comment
      </a>
      <p class="text-white/40 text-xs">Membership is free and open to all researchers</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 py-10">
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- Left sidebar -->
    <div class="hidden lg:block lg:col-span-3 space-y-5">
      <?php if ($isMember && $myFull): ?>
      <?php $myDisplayName = $myFull['type'] === 'lab' ? $myFull['lab_name'] : $myFull['full_name']; ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm sticky top-4">
        <div class="h-14" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);"></div>
        <div class="px-5 pb-5 -mt-7">
          <?= memberAvatarHtml($myFull['avatar_path'], $myDisplayName ?: '?', 'w-14 h-14 text-lg border-4 border-white dark:border-gray-900 shadow') ?>
          <p class="font-heading font-bold text-sm text-gray-900 dark:text-white mt-2 truncate"><?= htmlspecialchars($myDisplayName ?: '(unnamed)') ?></p>
          <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
            <?= $myFull['type'] === 'lab' ? htmlspecialchars($myFull['pi_name'] ?? '') : htmlspecialchars($myFull['position'] ?? '') ?>
            <?= $myFull['institution'] ? ' · ' . htmlspecialchars(mb_strimwidth($myFull['institution'], 0, 24, '…')) : '' ?>
          </p>
          <?php if ($myFull['country']): ?><p class="text-[11px] text-gray-400 mt-0.5"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($myFull['country']) ?></p><?php endif; ?>
          <?php if ($mySection): ?>
          <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 text-[11px] text-gray-500 dark:text-gray-400">
            <i class="fa-solid fa-earth-americas"></i> <?= htmlspecialchars($mySection['name']) ?>
          </div>
          <?php endif; ?>
        </div>
        <nav class="border-t border-gray-100 dark:border-gray-800 py-2">
          <a href="profile.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-user"></i> My Profile</a>
          <a href="dashboard.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-chart-simple"></i> Dashboard</a>
          <?php if (!empty($myFull['id_card_path'])): ?>
          <a href="uploads/id-cards/<?= urlencode($myFull['id_card_path']) ?>" target="_blank" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-id-card"></i> My ID Card</a>
          <?php endif; ?>
          <a href="directory.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-magnifying-glass"></i> Member Directory</a>
        </nav>
      </div>
      <?php else: ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm sticky top-4">
        <p class="text-sm font-semibold text-gray-800 dark:text-white mb-1">Join the RARL Community</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Create a free account to post, comment, and connect with researchers worldwide.</p>
        <a href="register.php" class="block text-center px-4 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-xs font-bold rounded-xl transition-colors">Join Free →</a>
      </div>
      <?php endif; ?>

      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800">
          <h3 class="font-heading font-bold text-xs text-gray-800 dark:text-white">Explore</h3>
        </div>
        <nav class="py-2">
          <a href="events.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-calendar-days"></i> Events</a>
          <a href="resources.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-book"></i> Resources</a>
          <a href="people.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-people-group"></i> Leadership</a>
          <a href="partners.php" class="flex items-center gap-2.5 px-5 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 hover:text-rarl-red transition-colors"><i class="fa-solid fa-handshake"></i> Partnerships</a>
        </nav>
      </div>
    </div>

    <div class="lg:col-span-6 space-y-10">

      <?php if ($isMember): ?>
      <!-- Community Feed -->
      <div id="feed">
        <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-5"><i class="fa-solid fa-comments"></i> Community Feed</h2>

        <!-- Composer -->
        <form method="POST" enctype="multipart/form-data" id="post-composer" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-6">
          <?= csrfField() ?><input type="hidden" name="action" value="create_post">
          <input type="hidden" name="body_html" id="post-body-html">
          <div class="flex items-center gap-2.5 mb-3">
            <?= $myAvatarHtml ?>
            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">Share something with the community</span>
          </div>
          <div id="post-quill-toolbar">
            <span class="ql-formats">
              <button class="ql-bold"></button>
              <button class="ql-italic"></button>
              <button class="ql-underline"></button>
            </span>
            <span class="ql-formats">
              <button class="ql-list" value="ordered"></button>
              <button class="ql-list" value="bullet"></button>
            </span>
            <span class="ql-formats">
              <button class="ql-link"></button>
              <button class="ql-blockquote"></button>
            </span>
          </div>
          <div id="post-quill-editor" style="min-height:90px;"></div>

          <div id="post-image-preview" class="hidden mt-3 relative inline-block">
            <img id="post-image-preview-img" src="" class="max-h-48 rounded-xl border border-gray-200 dark:border-gray-700"/>
            <button type="button" id="post-image-remove" class="absolute -top-2 -right-2 w-6 h-6 bg-gray-900 text-white rounded-full text-xs flex items-center justify-center"><i class="fa-solid fa-xmark"></i></button>
          </div>

          <div class="flex items-center justify-between mt-3">
            <label class="inline-flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 cursor-pointer hover:text-rarl-red transition-colors">
              <input type="file" name="image" id="post-image-input" accept=".jpg,.jpeg,.png,.webp" class="hidden"/>
              <i class="fa-solid fa-image"></i> Add a photo
            </label>
            <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Post</button>
          </div>
        </form>

        <?php if (empty($posts)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-10 text-center text-gray-400">
          <p class="text-3xl mb-2"><i class="fa-solid fa-inbox text-gray-300"></i></p><p class="text-sm">No posts yet. Be the first to share something!</p>
        </div>
        <?php else: ?>
        <div id="post-list" class="space-y-5">
          <?php foreach ($posts as $post) echo renderCommunityPost($pdo, $post, $myMemberId); ?>
        </div>
        <div id="feed-sentinel" class="py-6 text-center text-xs text-gray-400" data-offset="<?= count($posts) ?>" data-has-more="<?= $hasMorePosts ? '1' : '0' ?>">
          <?php if ($hasMorePosts): ?>Loading more…<?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Announcements -->
      <div>
        <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-5"><i class="fa-solid fa-thumbtack"></i> Community Announcements</h2>
        <?php if (empty($announcements)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-10 text-center text-gray-400">
          <p class="text-3xl mb-2"><i class="fa-solid fa-inbox text-gray-300"></i></p><p class="text-sm">No announcements yet. Check back soon.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
          <?php foreach ($announcements as $ann):
            $colors = ['event'=>'bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-900/20 dark:border-blue-800 dark:text-blue-300',
                       'opportunity'=>'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:border-green-800 dark:text-green-300',
                       'alert'=>'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300',
                       'general'=>'bg-gray-50 border-gray-200 text-gray-600 dark:bg-gray-800 dark:border-gray-700 dark:text-gray-300'];
            $cc = $colors[$ann['type']] ?? $colors['general'];
          ?>
          <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
            <div class="flex items-start justify-between gap-3 mb-3">
              <div class="flex items-center gap-2">
                <?php if ($ann['is_pinned']): ?><span class="text-xs font-bold text-amber-500"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
                <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full border <?= $cc ?>"><?= ucfirst($ann['type']) ?></span>
              </div>
              <span class="text-xs text-gray-400"><?= date('d M Y', strtotime($ann['created_at'])) ?></span>
            </div>
            <h3 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-2"><?= htmlspecialchars($ann['title']) ?></h3>
            <p class="text-gray-500 dark:text-gray-400 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($ann['content'])) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Regional Leadership -->
      <div>
        <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-5"><i class="fa-solid fa-map-location-dot"></i> Regional Leadership</h2>
        <?php if (empty($regionGroups)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-10 text-center text-gray-400">
          <p class="text-sm">Regional leadership information coming soon.</p>
        </div>
        <?php else: ?>
        <div class="space-y-6">
          <?php foreach ($regionGroups as $continent => $grp): ?>
          <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
            <h3 class="font-heading font-bold text-sm text-rarl-red uppercase tracking-wider mb-4"><?= htmlspecialchars($continent) ?></h3>
            <?php foreach ($grp['continent'] as $row): ?>
            <div class="mb-3 pb-3 border-b border-gray-100 dark:border-gray-800 last:border-0 last:pb-0 last:mb-0">
              <p class="font-semibold text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($row['name']) ?></p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= htmlspecialchars($row['chair_name']) ?> — <?= htmlspecialchars($row['chair_title']) ?></p>
              <?php if (!empty($row['chair_email'])): ?>
              <a href="mailto:<?= htmlspecialchars($row['chair_email']) ?>" class="text-xs text-rarl-red hover:underline"><?= htmlspecialchars($row['chair_email']) ?></a>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (!empty($grp['country'])): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
              <?php foreach ($grp['country'] as $row): ?>
              <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-4">
                <p class="font-semibold text-xs text-gray-800 dark:text-white"><?= htmlspecialchars($row['name']) ?></p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5"><?= htmlspecialchars($row['chair_name']) ?> — <?= htmlspecialchars($row['chair_title']) ?></p>
                <?php if (!empty($row['chair_email'])): ?>
                <a href="mailto:<?= htmlspecialchars($row['chair_email']) ?>" class="text-[11px] text-rarl-red hover:underline"><?= htmlspecialchars($row['chair_email']) ?></a>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Right sidebar -->
    <div class="lg:col-span-3 space-y-5">
      <?php if ($upcomingEvents): ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
          <h3 class="font-heading font-bold text-xs text-gray-800 dark:text-white"><i class="fa-solid fa-calendar-days"></i> Upcoming Events</h3>
          <a href="events.php" class="text-[10px] text-rarl-red hover:underline">See all</a>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($upcomingEvents as $ev): ?>
          <a href="events.php#event-<?= $ev['id'] ?>" class="block px-5 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
            <p class="text-xs font-semibold text-gray-800 dark:text-white truncate"><?= htmlspecialchars($ev['title']) ?></p>
            <p class="text-[11px] text-gray-400 mt-0.5"><?= $ev['event_date'] ? date('d M Y', strtotime($ev['event_date'])) : 'Date TBA' ?></p>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Community Stats -->
      <div class="rounded-2xl p-6 shadow-sm text-white" style="background:linear-gradient(135deg,#12213a 0%,#1c3358 100%);">
        <h3 class="font-heading font-bold text-sm mb-4"><i class="fa-solid fa-earth-americas"></i> RARL Community</h3>
        <div class="grid grid-cols-3 gap-2 text-center">
          <div>
            <div class="font-heading font-black text-xl"><?= $communityStats['members'] ?></div>
            <div class="text-white/50 text-[10px] uppercase tracking-wider mt-0.5">Members</div>
          </div>
          <div>
            <div class="font-heading font-black text-xl"><?= $communityStats['posts'] ?></div>
            <div class="text-white/50 text-[10px] uppercase tracking-wider mt-0.5">Posts</div>
          </div>
          <div>
            <div class="font-heading font-black text-xl"><?= $communityStats['countries'] ?></div>
            <div class="text-white/50 text-[10px] uppercase tracking-wider mt-0.5">Countries</div>
          </div>
        </div>
      </div>

      <!-- Guidelines -->
      <?php if ($guidelines): ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
        <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white mb-3"><i class="fa-solid fa-clipboard-list"></i> Community Guidelines</h3>
        <p class="text-gray-500 dark:text-gray-400 text-xs leading-relaxed"><?= nl2br(htmlspecialchars($guidelines)) ?></p>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php if ($isMember): ?>
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<style>
  /* Quill toolbar/editor styled to match the rest of the composer card */
  #post-quill-toolbar.ql-toolbar { border: 1px solid rgb(209 213 219); border-radius: 0.75rem 0.75rem 0 0; background: rgb(249 250 251); }
  #post-quill-editor.ql-container { border: 1px solid rgb(209 213 219); border-top: none; border-radius: 0 0 0.75rem 0.75rem; font-size: 0.875rem; background: rgb(249 250 251); }
  .dark #post-quill-toolbar.ql-toolbar, .dark #post-quill-editor.ql-container { border-color: rgb(75 85 99); background: rgb(31 41 55); }
  .dark #post-quill-editor .ql-editor.ql-blank::before { color: rgb(107 114 128); }
  /* Rendered post/comment rich content — Quill's own list/heading margins got stripped by Tailwind's reset */
  .rich-content p { margin: 0 0 0.5em; }
  .rich-content p:last-child { margin-bottom: 0; }
  .rich-content ul, .rich-content ol { margin: 0 0 0.5em 1.25em; }
  .rich-content ul { list-style: disc; }
  .rich-content ol { list-style: decimal; }
  .rich-content blockquote { border-left: 3px solid currentColor; opacity: 0.8; padding-left: 0.75em; margin: 0 0 0.5em; }
  .rich-content a { text-decoration: underline; }
  /* Per-post edit toolbars/editors, IDs are dynamic (edit-quill-toolbar-123) so
     these are attribute-prefix selectors instead of one rule per post. */
  [id^="edit-quill-toolbar-"].ql-toolbar { border: 1px solid rgb(209 213 219); border-radius: 0.5rem 0.5rem 0 0; background: #fff; }
  [id^="edit-quill-editor-"].ql-container { border: 1px solid rgb(209 213 219); border-top: none; border-radius: 0 0 0.5rem 0.5rem; font-size: 0.8125rem; background: #fff; }
  .dark [id^="edit-quill-toolbar-"].ql-toolbar, .dark [id^="edit-quill-editor-"].ql-container { border-color: rgb(75 85 99); background: rgb(17 24 39); }
</style>
<script>
  const quill = new Quill('#post-quill-editor', {
    theme: 'snow',
    modules: { toolbar: '#post-quill-toolbar' },
    placeholder: 'Share an update, ask a question…',
  });

  const composer = document.getElementById('post-composer');
  composer.addEventListener('submit', () => {
    document.getElementById('post-body-html').value = quill.root.innerHTML;
  });

  const imageInput   = document.getElementById('post-image-input');
  const imagePreview = document.getElementById('post-image-preview');
  const imagePreviewImg = document.getElementById('post-image-preview-img');
  imageInput.addEventListener('change', () => {
    const file = imageInput.files[0];
    if (!file) return;
    imagePreviewImg.src = URL.createObjectURL(file);
    imagePreview.classList.remove('hidden');
  });
  document.getElementById('post-image-remove').addEventListener('click', () => {
    imageInput.value = '';
    imagePreview.classList.add('hidden');
  });

  // ── Per-post edit panels — Quill instance created lazily on first open ──
  const editQuillInstances = {};
  function rarlToggleEditPanel(postId) {
    const panel = document.getElementById('edit-panel-' + postId);
    if (!panel) return;
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden') && !editQuillInstances[postId]) {
      const editorEl = document.getElementById('edit-quill-editor-' + postId);
      const q = new Quill(editorEl, {
        theme: 'snow',
        modules: { toolbar: '#edit-quill-toolbar-' + postId },
      });
      q.root.innerHTML = editorEl.dataset.content || '';
      editQuillInstances[postId] = q;
    }
  }
  function rarlSubmitEdit(postId) {
    const q = editQuillInstances[postId];
    if (q) document.getElementById('edit-body-html-' + postId).value = q.root.innerHTML;
    return true;
  }

  // ── "…more" toggle for long post text (mobile-style clamp) ──
  function rarlExpandPost(postId) {
    document.querySelectorAll('.post-clamp-' + postId).forEach(el => el.classList.remove('line-clamp-4'));
    document.querySelectorAll('.post-more-' + postId).forEach(el => el.classList.add('hidden'));
  }
  function rarlInitClampButtons(root) {
    root.querySelectorAll('[class*="post-clamp-"]').forEach(el => {
      if (el.scrollHeight > el.clientHeight + 2) {
        const idClass = [...el.classList].find(c => c.startsWith('post-clamp-'));
        const id = idClass.replace('post-clamp-', '');
        document.querySelectorAll('.post-more-' + id).forEach(btn => btn.classList.remove('hidden'));
      }
    });
  }
  rarlInitClampButtons(document);

  // ── Lazy-load more posts as the sentinel scrolls into view ──
  const sentinel = document.getElementById('feed-sentinel');
  if (sentinel) {
    const observer = new IntersectionObserver((entries) => {
      const entry = entries[0];
      if (!entry.isIntersecting) return;
      if (sentinel.dataset.hasMore !== '1' || sentinel.dataset.loading === '1') return;
      sentinel.dataset.loading = '1';
      sentinel.textContent = 'Loading more…';

      const offset = parseInt(sentinel.dataset.offset, 10) || 0;
      fetch('community.php?ajax=posts&offset=' + offset)
        .then(r => r.text())
        .then(html => {
          const hasMore = html.includes('<!-- has_more:1 -->');
          const cleanHtml = html.replace(/<!-- has_more:[01] -->/, '');
          document.getElementById('post-list').insertAdjacentHTML('beforeend', cleanHtml);
          rarlInitClampButtons(document.getElementById('post-list'));
          sentinel.dataset.offset = offset + <?= COMMUNITY_PAGE_SIZE ?>;
          sentinel.dataset.hasMore = hasMore ? '1' : '0';
          sentinel.dataset.loading = '0';
          sentinel.textContent = hasMore ? '' : '— End of feed —';
        })
        .catch(() => { sentinel.dataset.loading = '0'; });
    }, { rootMargin: '200px' });
    observer.observe(sentinel);
  }
</script>
<?php endif; ?>

<?= publicFooter() ?>
</body></html>
