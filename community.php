<?php
/**
 * RARL — Community Portal (Feed + Regional Leadership + Discord)
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
        // Stored raw (Markdown syntax preserved) — escaping happens once, at render time, in markdownToHtml().
        $body = mb_substr(trim($_POST['body'] ?? ''), 0, 2000);
        if ($body !== '') {
            $pdo->prepare('INSERT INTO community_posts (member_id, body) VALUES (?,?)')->execute([$memberId, $body]);
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
                $postExcerpt   = mb_strimwidth($post['body'], 0, 120, '…');
                $feedUrl       = SITE_URL . '/community.php#post-' . $postId;
                ob_start();
                require __DIR__ . '/emails/community-comment.php';
                $emailBody = ob_get_clean();
                sendEmail($authorEmail, $authorName, 'New comment on your RARL Community post', $emailBody);
            }
        }
        header('Location: community.php#post-' . $postId); exit;
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

$discordUrl    = setting('discord_invite_url');
$discordName   = setting('discord_server_name', 'RARL Community');
$guidelines    = setting('community_guidelines');
$feedIntro     = setting('community_feed_intro', 'Share updates, ask questions, and connect with fellow RARL researchers.');
$isMember      = !empty($_SESSION['member_id']);
$myMemberId    = (int)($_SESSION['member_id'] ?? 0);
$announcements = $pdo->query("SELECT * FROM announcements WHERE is_published = 1 ORDER BY is_pinned DESC, created_at DESC")->fetchAll();

// ── Feed data ──
$posts = [];
if ($isMember) {
    $posts = $pdo->query("SELECT community_posts.*, members.full_name, members.lab_name, members.type
                           FROM community_posts JOIN members ON members.id = community_posts.member_id
                           WHERE community_posts.is_hidden = 0
                           ORDER BY is_pinned DESC, community_posts.created_at DESC LIMIT 50")->fetchAll();

    $likeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM community_likes WHERE post_id=?');
    $likedStmt     = $pdo->prepare('SELECT 1 FROM community_likes WHERE post_id=? AND member_id=?');
    $commentsStmt  = $pdo->prepare('SELECT community_comments.*, members.full_name, members.lab_name, members.type
                                     FROM community_comments JOIN members ON members.id = community_comments.member_id
                                     WHERE post_id=? AND is_hidden=0 ORDER BY created_at ASC');

    foreach ($posts as &$p) {
        $likeCountStmt->execute([$p['id']]);
        $p['like_count'] = (int)$likeCountStmt->fetchColumn();
        $likedStmt->execute([$p['id'], $myMemberId]);
        $p['liked_by_me'] = (bool)$likedStmt->fetch();
        $commentsStmt->execute([$p['id']]);
        $p['comments'] = $commentsStmt->fetchAll();
    }
    unset($p);
}

// ── Regional sections, grouped by continent ──
$sections = $pdo->query("SELECT * FROM regional_sections WHERE is_published = 1 ORDER BY continent, display_order")->fetchAll();
$regionGroups = [];
foreach ($sections as $s) {
    $continent = $s['continent'];
    if (!isset($regionGroups[$continent])) $regionGroups[$continent] = ['continent' => [], 'country' => []];
    $regionGroups[$continent][$s['scope']][] = $s;
}

echo htmlHead('Community Portal');
?>
<?= publicNav('community') ?>

<!-- Hero -->
<section class="relative overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 60%,#5865f2 100%);">
  <div class="absolute inset-0" style="background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:40px 40px;"></div>
  <div class="relative z-10 max-w-5xl mx-auto px-6 py-20 text-center">
    <div class="text-6xl mb-5">🌐</div>
    <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-4">RARL Community Feed</h1>
    <p class="text-white/65 text-base max-w-lg mx-auto mb-8"><?= htmlspecialchars($feedIntro) ?></p>
    <?php if ($isMember): ?>
    <a href="#feed" class="inline-flex items-center gap-3 px-8 py-4 bg-white text-rarl-red font-black rounded-xl shadow-2xl hover:-translate-y-1 hover:shadow-3xl transition-all text-sm">
      Go to the Feed ↓
    </a>
    <?php else: ?>
    <div class="inline-flex flex-col items-center gap-3">
      <a href="register.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-rarl-red font-black rounded-xl shadow-xl hover:-translate-y-1 transition-all text-sm">
        🔐 Join as a Member to Post &amp; Comment
      </a>
      <p class="text-white/40 text-xs">Membership is free and open to all researchers</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="max-w-5xl mx-auto px-6 py-14">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <div class="lg:col-span-2 space-y-10">

      <?php if ($isMember): ?>
      <!-- Community Feed -->
      <div id="feed">
        <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-5">🗨️ Community Feed</h2>

        <!-- Composer -->
        <form method="POST" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-6">
          <?= csrfField() ?><input type="hidden" name="action" value="create_post">
          <div class="md-toolbar flex items-center gap-1 mb-2"></div>
          <textarea name="body" required maxlength="2000" rows="3" placeholder="Share an update, ask a question…"
            class="md-editor w-full px-4 py-3 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"></textarea>
          <div class="flex items-center justify-between mt-3">
            <p class="text-[11px] text-gray-400">Markdown supported — **bold**, *italic*, `code`, [link](url), - lists</p>
            <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Post</button>
          </div>
        </form>

        <?php if (empty($posts)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-10 text-center text-gray-400">
          <p class="text-3xl mb-2">📭</p><p class="text-sm">No posts yet. Be the first to share something!</p>
        </div>
        <?php else: ?>
        <div class="space-y-5">
          <?php foreach ($posts as $post):
            $authorName = $post['type'] === 'lab' ? $post['lab_name'] : $post['full_name'];
            $bodyHtml   = markdownToHtml($post['body']);
          ?>
          <div id="post-<?= (int)$post['id'] ?>" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
            <div class="flex items-start justify-between gap-3 mb-3">
              <div class="flex items-center gap-2">
                <?php if ($post['is_pinned']): ?><span class="text-xs font-bold text-amber-500">📌</span><?php endif; ?>
                <span class="font-semibold text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($authorName) ?></span>
                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500"><?= htmlspecialchars(ucfirst($post['type'])) ?></span>
              </div>
              <span class="text-xs text-gray-400"><?= date('d M Y, H:i', strtotime($post['created_at'])) ?></span>
            </div>
            <div class="md-content text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-4"><?= $bodyHtml ?></div>

            <div class="flex items-center gap-4 mb-4">
              <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="toggle_like"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-semibold <?= $post['liked_by_me'] ? 'text-rarl-red' : 'text-gray-400 hover:text-rarl-red' ?> transition-colors">
                  <?= $post['liked_by_me'] ? '❤️' : '🤍' ?> <?= (int)$post['like_count'] ?>
                </button>
              </form>
              <span class="text-xs text-gray-400">💬 <?= count($post['comments']) ?></span>
            </div>

            <?php if (!empty($post['comments'])): ?>
            <div class="space-y-3 border-t border-gray-100 dark:border-gray-800 pt-4 mb-4">
              <?php foreach ($post['comments'] as $c):
                $cAuthor = $c['type'] === 'lab' ? $c['lab_name'] : $c['full_name'];
                $cHtml   = markdownToHtml($c['body']);
              ?>
              <div class="bg-gray-50 dark:bg-gray-800 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between mb-1">
                  <span class="font-semibold text-xs text-gray-800 dark:text-white"><?= htmlspecialchars($cAuthor) ?></span>
                  <span class="text-[10px] text-gray-400"><?= date('d M Y, H:i', strtotime($c['created_at'])) ?></span>
                </div>
                <div class="md-content text-gray-500 dark:text-gray-400 text-xs leading-relaxed"><?= $cHtml ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="flex gap-2" title="Markdown supported — **bold**, *italic*, [link](url)">
              <?= csrfField() ?><input type="hidden" name="action" value="create_comment"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
              <input type="text" name="body" required maxlength="2000" placeholder="Write a reply… (markdown supported)"
                class="md-editor flex-1 px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
              <button type="submit" class="px-4 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-white font-semibold text-xs rounded-lg transition-colors">Reply</button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Announcements -->
      <div>
        <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-5">📌 Community Announcements</h2>
        <?php if (empty($announcements)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-10 text-center text-gray-400">
          <p class="text-3xl mb-2">📭</p><p class="text-sm">No announcements yet. Check back soon.</p>
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
                <?php if ($ann['is_pinned']): ?><span class="text-xs font-bold text-amber-500">📌</span><?php endif; ?>
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
        <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-5">🗺️ Regional Leadership</h2>
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

    <!-- Sidebar -->
    <div class="space-y-5">
      <!-- Guidelines -->
      <?php if ($guidelines): ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
        <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white mb-3">📋 Community Guidelines</h3>
        <p class="text-gray-500 dark:text-gray-400 text-xs leading-relaxed"><?= nl2br(htmlspecialchars($guidelines)) ?></p>
      </div>
      <?php endif; ?>

      <!-- Discord (secondary, only if configured) -->
      <?php if (!empty($discordUrl)): ?>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
        <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white mb-3">💬 Also on Discord</h3>
        <p class="text-gray-500 dark:text-gray-400 text-xs leading-relaxed mb-4">Join <?= htmlspecialchars($discordName) ?> for real-time discussion alongside the feed.</p>
        <?php if ($isMember): ?>
        <a href="<?= htmlspecialchars($discordUrl) ?>" target="_blank" rel="noopener"
          class="inline-flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-[#5865f2] hover:bg-[#4752c4] text-white font-bold rounded-xl transition-colors text-xs">
          Join Discord Server
        </a>
        <?php else: ?>
        <a href="register.php" class="inline-flex items-center justify-center gap-2 w-full px-4 py-2.5 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-white font-semibold rounded-xl transition-colors text-xs">
          Join as a Member for Discord Access
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
  // Lightweight Markdown formatting toolbar — wraps the current selection in
  // the adjacent .md-editor with the given syntax (or inserts a placeholder).
  function mdWrap(editor, before, after, placeholder) {
    const start = editor.selectionStart, end = editor.selectionEnd;
    const val = editor.value;
    const selected = val.slice(start, end) || placeholder;
    editor.value = val.slice(0, start) + before + selected + after + val.slice(end);
    const pos = start + before.length + selected.length + after.length;
    editor.focus();
    editor.setSelectionRange(pos, pos);
  }
  document.querySelectorAll('.md-toolbar').forEach(bar => {
    const editor = bar.parentElement.querySelector('.md-editor');
    if (!editor) return;
    const buttons = [
      ['B', 'Bold', () => mdWrap(editor, '**', '**', 'bold text')],
      ['I', 'Italic', () => mdWrap(editor, '*', '*', 'italic text')],
      ['</>', 'Code', () => mdWrap(editor, '`', '`', 'code')],
      ['🔗', 'Link', () => mdWrap(editor, '[', '](https://)', 'link text')],
      ['•', 'List item', () => mdWrap(editor, '- ', '', 'list item')],
    ];
    buttons.forEach(([label, title, fn]) => {
      const btn = document.createElement('button');
      btn.type = 'button'; btn.title = title; btn.textContent = label;
      btn.className = 'px-2.5 py-1 text-xs font-semibold bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-lg transition-colors';
      btn.addEventListener('click', fn);
      bar.appendChild(btn);
    });
  });
</script>

<?= publicFooter() ?>
</body></html>
