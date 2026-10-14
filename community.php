<?php
/**
 * RARL — Community Portal (Feed + Regional Leadership)
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Community');

$pdo = db();

// ── POST handling (create_post / create_comment / toggle_like) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck() && !empty($_SESSION['member_id'])) {
    $memberId = (int)$_SESSION['member_id'];
    $action   = $_POST['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM members WHERE id=?');
    $stmt->execute([$memberId]);
    $me = $stmt->fetch();

    // Member post creation is paused for now — admins post via admin/community.php
    // ("Robotics & Automation Research Lab (RARL) Team"), members can still comment/like below. Deliberately
    // no create_post handler here anymore so a direct POST can't slip through
    // even if old composer markup somehow got submitted.

    if ($action === 'create_comment' && $me && $me['status'] === 'active' && communityCommentsEnabled()) {
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
                sendEmail($authorEmail, $authorName, 'New comment on your Robotics & Automation Research Lab (RARL) post', $emailBody);
            }
        }
        header('Location: community.php#post-' . $postId); exit;
    }

    if ($action === 'edit_comment' && $me && $me['status'] === 'active' && communityCommentsEnabled()) {
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
$feedIntro     = setting('community_feed_intro', 'News, announcements and highlights from the RARL team.');
$isMember      = !empty($_SESSION['member_id']);
$myMemberId    = (int)($_SESSION['member_id'] ?? 0);
$myAvatarHtml  = '';
$mySectionId   = null;
if ($isMember) {
    $meStmt = $pdo->prepare('SELECT full_name, lab_name, type, avatar_path, section_id FROM members WHERE id=?');
    $meStmt->execute([$myMemberId]);
    $meRow = $meStmt->fetch();
    if ($meRow) {
        $myName = $meRow['type'] === 'lab' ? $meRow['lab_name'] : $meRow['full_name'];
        $myAvatarHtml = memberAvatarHtml($meRow['avatar_path'], $myName ?: '?', 'w-10 h-10 text-sm');
        $mySectionId = $meRow['section_id'] ? (int)$meRow['section_id'] : null;
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

$annStmt = $pdo->prepare('SELECT * FROM announcements WHERE ' . liveAnnouncementsSql() . ' AND (section_id IS NULL OR section_id <=> ?) ORDER BY is_pinned DESC, created_at DESC');
$annStmt->execute([$mySectionId]);
$announcements = $annStmt->fetchAll();

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

echo htmlHead('Updates');
$tab = ($_GET['tab'] ?? '') === 'announcements' || !$isMember ? 'announcements' : 'posts';
?>
<?= publicNav('community') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
  <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-6">
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">From the RARL team</p>
      <h1 class="font-heading font-bold text-2xl text-gray-900 dark:text-white">Updates</h1>
      <p class="text-sm text-gray-500 mt-1 max-w-2xl"><?= ex($feedIntro) ?></p>
      <nav class="flex gap-6 mt-4 text-sm font-semibold" aria-label="Updates sections">
        <?php if ($isMember): ?>
        <a href="community.php" <?= $tab === 'posts' ? 'aria-current="page"' : '' ?> class="pb-3 border-b-2 <?= $tab === 'posts' ? 'border-rarl-red text-gray-900 dark:text-white' : 'border-transparent text-gray-500 hover:text-gray-900 dark:hover:text-white' ?>">Team updates <span class="text-gray-400 font-medium"><?= $communityStats['posts'] ?></span></a>
        <?php endif; ?>
        <a href="community.php?tab=announcements" <?= $tab === 'announcements' ? 'aria-current="page"' : '' ?> class="pb-3 border-b-2 <?= $tab === 'announcements' ? 'border-rarl-red text-gray-900 dark:text-white' : 'border-transparent text-gray-500 hover:text-gray-900 dark:hover:text-white' ?>">Announcements <span class="text-gray-400 font-medium"><?= count($announcements) ?></span></a>
      </nav>
    </div>
  </div>

  <main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 grid lg:grid-cols-[minmax(0,1fr)_300px] gap-6 items-start">
    <div class="min-w-0 space-y-4">
      <?= renderFlash() ?>

      <?php if (!$isMember): ?>
      <div class="rarl-panel p-5 flex flex-col sm:flex-row items-center gap-4">
        <span class="w-12 h-12 rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xl flex-shrink-0"><i class="fa-solid fa-lock"></i></span>
        <div class="flex-1 text-center sm:text-left"><p class="font-semibold text-gray-900 dark:text-white">Members see every team update</p><p class="text-sm text-gray-500">Join free to follow RARL's news, events and learning in one place.</p></div>
        <a href="register.php" class="px-5 py-2.5 rounded-full bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold whitespace-nowrap">Join RARL</a>
      </div>
      <?php endif; ?>

      <?php if ($tab === 'posts'): ?>
      <div id="feed">
        <?php if (empty($posts)): ?>
        <div class="rarl-panel p-12 text-center"><i class="fa-regular fa-newspaper text-3xl text-gray-300"></i><p class="font-semibold text-gray-700 dark:text-gray-200 mt-3">No team updates yet</p><p class="text-sm text-gray-500 mt-1">News from the RARL team will appear here.</p></div>
        <?php else: ?>
        <div id="post-list" class="space-y-4">
          <?php foreach ($posts as $post) echo renderCommunityPost($pdo, $post, $myMemberId); ?>
        </div>
        <div id="feed-sentinel" class="py-6 text-center text-xs text-gray-500" data-offset="<?= count($posts) ?>" data-has-more="<?= $hasMorePosts ? '1' : '0' ?>"><?php if ($hasMorePosts): ?>Loading more…<?php endif; ?></div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div id="announcements" class="space-y-4">
        <?php if (empty($announcements)): ?>
        <div class="rarl-panel p-12 text-center"><i class="fa-solid fa-bullhorn text-3xl text-gray-300"></i><p class="font-semibold text-gray-700 dark:text-gray-200 mt-3">No announcements right now</p></div>
        <?php endif; ?>
        <?php foreach ($announcements as $ann) echo renderFeedItem(['kind' => 'announcement', 'at' => $ann['created_at'], 'pinned' => (bool)$ann['is_pinned'], 'row' => $ann]); ?>
      </div>
      <?php endif; ?>
    </div>

    <aside class="space-y-4 min-w-0 lg:sticky lg:top-[88px]">
      <?php if ($upcomingEvents): ?>
      <section class="rarl-panel overflow-hidden">
        <div class="px-4 pt-4 pb-2 flex items-center justify-between"><h2 class="text-sm font-bold text-gray-900 dark:text-white">Upcoming events</h2><a href="events.php" class="text-xs font-semibold text-gray-500 hover:text-rarl-red">See all</a></div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($upcomingEvents as $ev): ?>
          <li><a href="events.php#event-<?= (int)$ev['id'] ?>" class="flex gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60">
            <span class="w-11 text-center flex-shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 py-1 h-fit"><?php if ($ev['event_date']): ?><span class="block text-[10px] font-bold uppercase text-rarl-red leading-tight"><?= date('M', strtotime($ev['event_date'])) ?></span><span class="block font-heading font-bold text-lg text-gray-900 dark:text-white leading-none"><?= date('d', strtotime($ev['event_date'])) ?></span><?php else: ?><span class="block text-[10px] font-bold text-gray-500 py-1.5">TBA</span><?php endif; ?></span>
            <span class="text-sm font-semibold text-gray-900 dark:text-white leading-snug line-clamp-2"><?= ex($ev['title']) ?></span>
          </a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="rounded-2xl p-5 text-white bg-rarl-navy relative overflow-hidden">
        <div class="absolute inset-0 opacity-40 bg-[radial-gradient(circle_at_100%_0%,#CC0703,transparent_55%)]"></div>
        <div class="relative">
          <h2 class="font-heading font-bold text-sm">RARL worldwide</h2>
          <div class="grid grid-cols-2 gap-3 mt-3">
            <div><div class="font-heading font-black text-2xl"><?= number_format($communityStats['members']) ?></div><div class="text-white/60 text-xs">Members</div></div>
            <div><div class="font-heading font-black text-2xl"><?= number_format($communityStats['countries']) ?></div><div class="text-white/60 text-xs">Countries</div></div>
          </div>
          <a href="people.php" class="inline-block mt-4 text-xs font-semibold text-white/80 hover:text-white">Leadership &amp; chapters →</a>
        </div>
      </section>

      <?php if ($guidelines): ?>
      <section class="rarl-panel p-4">
        <h2 class="text-sm font-bold text-gray-900 dark:text-white mb-2">Community guidelines</h2>
        <p class="text-xs text-gray-500 leading-relaxed"><?= nl2br(ex($guidelines)) ?></p>
      </section>
      <?php endif; ?>
    </aside>
  </main>
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
  // ── Per-post edit panels (member editing their own pre-existing post from
  // before posting was paused) — Quill instance created lazily on first open ──
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
