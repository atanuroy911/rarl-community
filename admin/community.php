<?php
/**
 * RARL Admin — Community Management (Guidelines + Announcements)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $settingKeys = ['community_guidelines'];
        foreach ($settingKeys as $k) {
            $v = clean($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$k,$v,$v]);
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Community settings saved.'];
        header('Location: community.php'); exit;
    }
    if ($action === 'add_announcement') {
        $title   = clean($_POST['ann_title']   ?? '');
        $content = clean($_POST['ann_content'] ?? '');
        $type    = clean($_POST['ann_type']    ?? 'general');
        $pinned  = !empty($_POST['ann_pinned']) ? 1 : 0;
        if ($title && $content) {
            $pdo->prepare("INSERT INTO announcements (title,content,type,is_pinned) VALUES (?,?,?,?)")->execute([$title,$content,$type,$pinned]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Announcement published.'];
        }
        header('Location: community.php'); exit;
    }
    if ($action === 'delete_announcement') {
        $pdo->prepare("DELETE FROM announcements WHERE id=?")->execute([(int)($_POST['ann_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Announcement deleted.'];
        header('Location: community.php'); exit;
    }
    if ($action === 'toggle_pin') {
        $pdo->prepare("UPDATE announcements SET is_pinned = 1 - is_pinned WHERE id=?")->execute([(int)($_POST['ann_id']??0)]);
        header('Location: community.php'); exit;
    }

    if ($action === 'pin_post') {
        $pdo->prepare("UPDATE community_posts SET is_pinned=1 WHERE id=?")->execute([(int)($_POST['post_id']??0)]);
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'unpin_post') {
        $pdo->prepare("UPDATE community_posts SET is_pinned=0 WHERE id=?")->execute([(int)($_POST['post_id']??0)]);
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'hide_post') {
        $pdo->prepare("UPDATE community_posts SET is_hidden=1 WHERE id=?")->execute([(int)($_POST['post_id']??0)]);
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'unhide_post') {
        $pdo->prepare("UPDATE community_posts SET is_hidden=0 WHERE id=?")->execute([(int)($_POST['post_id']??0)]);
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'delete_post') {
        $pid = (int)($_POST['post_id']??0);
        $pdo->prepare("DELETE FROM community_comments WHERE post_id=?")->execute([$pid]);
        $pdo->prepare("DELETE FROM community_likes WHERE post_id=?")->execute([$pid]);
        $pdo->prepare("DELETE FROM community_posts WHERE id=?")->execute([$pid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Post deleted.'];
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'hide_comment') {
        $pdo->prepare("UPDATE community_comments SET is_hidden=1 WHERE id=?")->execute([(int)($_POST['comment_id']??0)]);
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'unhide_comment') {
        $pdo->prepare("UPDATE community_comments SET is_hidden=0 WHERE id=?")->execute([(int)($_POST['comment_id']??0)]);
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'delete_comment') {
        $pdo->prepare("DELETE FROM community_comments WHERE id=?")->execute([(int)($_POST['comment_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Comment deleted.'];
        header('Location: community.php#moderation'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        $bulkGroup = $_POST['bulk_group'] ?? '';
        if ($ids && $bulkGroup) {
            $inClause = implode(',', $ids);
            if ($bulkGroup === 'announcements') {
                if ($bulkOp === 'pin') $pdo->exec("UPDATE announcements SET is_pinned=1 WHERE id IN ({$inClause})");
                elseif ($bulkOp === 'unpin') $pdo->exec("UPDATE announcements SET is_pinned=0 WHERE id IN ({$inClause})");
                elseif ($bulkOp === 'delete') $pdo->exec("DELETE FROM announcements WHERE id IN ({$inClause})");
            } elseif ($bulkGroup === 'posts') {
                if ($bulkOp === 'hide') $pdo->exec("UPDATE community_posts SET is_hidden=1 WHERE id IN ({$inClause})");
                elseif ($bulkOp === 'unhide') $pdo->exec("UPDATE community_posts SET is_hidden=0 WHERE id IN ({$inClause})");
                elseif ($bulkOp === 'delete') {
                    $pdo->exec("DELETE FROM community_comments WHERE post_id IN ({$inClause})");
                    $pdo->exec("DELETE FROM community_likes WHERE post_id IN ({$inClause})");
                    $pdo->exec("DELETE FROM community_posts WHERE id IN ({$inClause})");
                }
            } elseif ($bulkGroup === 'comments') {
                if ($bulkOp === 'hide') $pdo->exec("UPDATE community_comments SET is_hidden=1 WHERE id IN ({$inClause})");
                elseif ($bulkOp === 'unhide') $pdo->exec("UPDATE community_comments SET is_hidden=0 WHERE id IN ({$inClause})");
                elseif ($bulkOp === 'delete') $pdo->exec("DELETE FROM community_comments WHERE id IN ({$inClause})");
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' item(s) updated.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one item first.'];
        }
        header('Location: community.php#moderation'); exit;
    }
}

$settings = [];
foreach ($pdo->query("SELECT `key`,`value` FROM settings")->fetchAll() as $r) $settings[$r['key']] = $r['value'];
$announcements = $pdo->query("SELECT * FROM announcements ORDER BY is_pinned DESC, created_at DESC")->fetchAll();
$posts = $pdo->query("SELECT community_posts.*, members.full_name, members.lab_name, members.type
                       FROM community_posts JOIN members ON members.id = community_posts.member_id
                       ORDER BY community_posts.created_at DESC LIMIT 100")->fetchAll();
$comments = $pdo->query("SELECT community_comments.*, members.full_name, members.lab_name, members.type
                          FROM community_comments JOIN members ON members.id = community_comments.member_id
                          ORDER BY community_comments.created_at DESC LIMIT 100")->fetchAll();

adminWrap(function() use ($settings, $announcements, $posts, $comments) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Community</h1>
<p class="text-gray-500 text-sm mb-7">Manage community guidelines and announcements</p>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-7">
  <!-- Community Guidelines -->
  <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
    <h2 class="font-heading font-bold text-base text-gray-800 mb-5"><i class="fa-solid fa-file-lines"></i> Community Guidelines</h2>
    <form method="POST" class="space-y-4">
      <?= acsrfField() ?><input type="hidden" name="action" value="save_settings">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Community Guidelines</label>
        <textarea name="community_guidelines" rows="5"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"><?= htmlspecialchars($settings['community_guidelines']??'') ?></textarea>
      </div>
      <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Save Settings</button>
    </form>
  </div>

  <!-- Add Announcement -->
  <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
    <h2 class="font-heading font-bold text-base text-gray-800 mb-5"><i class="fa-solid fa-bullhorn"></i> New Announcement</h2>
    <form method="POST" class="space-y-4">
      <?= acsrfField() ?><input type="hidden" name="action" value="add_announcement">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Title</label>
        <input type="text" name="ann_title" required placeholder="Announcement title"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Content</label>
        <textarea name="ann_content" required rows="5" placeholder="Announcement details…"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"></textarea>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Type</label>
          <select name="ann_type" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <?php foreach (['general','event','opportunity','alert'] as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-xl cursor-pointer hover:border-rarl-red/30 transition-colors mt-5">
          <input type="checkbox" name="ann_pinned" value="1" class="accent-rarl-red w-4 h-4"/>
          <span class="text-xs font-semibold text-gray-700"><i class="fa-solid fa-thumbtack"></i> Pin this</span>
        </label>
      </div>
      <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Publish Announcement</button>
    </form>
  </div>
</div>

<!-- Announcements list -->
<div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
    <h2 class="font-heading font-bold text-sm text-gray-800">All Announcements <span class="text-gray-400 font-normal text-xs">(<?= count($announcements) ?>)</span></h2>
    <?php if ($announcements): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox('ann') ?> Select all</label><?php endif; ?>
  </div>
  <?php if (empty($announcements)): ?>
  <p class="p-8 text-center text-gray-400 text-sm">No announcements yet.</p>
  <?php else: ?>
  <?= bulkFormOpen('ann', ['bulk_group' => 'announcements']) ?>
  <?= bulkBar([
      ['label'=>'<i class="fa-solid fa-thumbtack"></i> Pin','op'=>'pin','class'=>'bg-amber-600 hover:bg-amber-500'],
      ['label'=>'Unpin','op'=>'unpin','class'=>'bg-gray-600 hover:bg-gray-500'],
      ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected announcements?'],
  ], 'ann') ?>
  <div class="divide-y divide-gray-100">
    <?php foreach ($announcements as $ann): ?>
    <div class="p-5 flex items-start gap-4 hover:bg-gray-50 group">
      <div class="flex items-center pt-0.5"><?= bulkRowCheckbox((int)$ann['id'], 'ann') ?></div>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <?php if ($ann['is_pinned']): ?><span class="text-amber-500 text-xs"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
          <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= ucfirst($ann['type']) ?></span>
          <span class="text-[10px] text-gray-400">· <?= date('d M Y', strtotime($ann['created_at'])) ?></span>
        </div>
        <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($ann['title']) ?></p>
        <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(mb_strimwidth($ann['content'],0,100,'…')) ?></p>
      </div>
      <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
          <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-amber-100 text-amber-700 hover:bg-amber-200 rounded-lg"><?= $ann['is_pinned']?'Unpin':'Pin' ?></button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this announcement?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
          <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">Delete</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Posts & Comments Moderation -->
<div id="moderation" class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-7">
  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
      <h2 class="font-heading font-bold text-sm text-gray-800">Community Posts <span class="text-gray-400 font-normal text-xs">(<?= count($posts) ?>)</span></h2>
      <?php if ($posts): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox('posts') ?> Select all</label><?php endif; ?>
    </div>
    <?php if (empty($posts)): ?>
    <p class="p-8 text-center text-gray-400 text-sm">No posts yet.</p>
    <?php else: ?>
    <?= bulkFormOpen('posts', ['bulk_group' => 'posts']) ?>
    <?= bulkBar([
        ['label'=>'Hide','op'=>'hide','class'=>'bg-gray-600 hover:bg-gray-500'],
        ['label'=>'Unhide','op'=>'unhide','class'=>'bg-blue-600 hover:bg-blue-500'],
        ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected posts and their comments/likes?'],
    ], 'posts') ?>
    <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
      <?php foreach ($posts as $post): $authorName = $post['type']==='lab' ? $post['lab_name'] : $post['full_name']; ?>
      <div class="p-5 hover:bg-gray-50 group">
        <div class="flex items-center gap-2 mb-1">
          <?= bulkRowCheckbox((int)$post['id'], 'posts') ?>
          <?php if ($post['is_pinned']): ?><span class="text-amber-500 text-xs"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
          <?php if ($post['is_hidden']): ?><span class="text-[10px] font-bold uppercase tracking-wider text-red-500">Hidden</span><?php endif; ?>
          <span class="font-semibold text-xs text-gray-900"><?= htmlspecialchars($authorName) ?></span>
          <span class="text-[10px] text-gray-400">· <?= date('d M Y', strtotime($post['created_at'])) ?></span>
        </div>
        <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars(communityBodyExcerpt($post['body'], 140)) ?></p>
        <?php if (!empty($post['image_path'])): ?>
        <img src="<?= UPLOADS_URL ?>/community/<?= htmlspecialchars($post['image_path']) ?>" alt="" class="max-h-24 rounded-lg mb-2"/>
        <?php endif; ?>
        <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $post['is_pinned']?'unpin_post':'pin_post' ?>"><input type="hidden" name="post_id" value="<?= $post['id'] ?>">
            <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-amber-100 text-amber-700 hover:bg-amber-200 rounded-lg"><?= $post['is_pinned']?'Unpin':'Pin' ?></button>
          </form>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $post['is_hidden']?'unhide_post':'hide_post' ?>"><input type="hidden" name="post_id" value="<?= $post['id'] ?>">
            <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg"><?= $post['is_hidden']?'Unhide':'Hide' ?></button>
          </form>
          <form method="POST" onsubmit="return confirm('Delete this post and all its comments/likes?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?= $post['id'] ?>">
            <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">Delete</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
      <h2 class="font-heading font-bold text-sm text-gray-800">Community Comments <span class="text-gray-400 font-normal text-xs">(<?= count($comments) ?>)</span></h2>
      <?php if ($comments): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox('comments') ?> Select all</label><?php endif; ?>
    </div>
    <?php if (empty($comments)): ?>
    <p class="p-8 text-center text-gray-400 text-sm">No comments yet.</p>
    <?php else: ?>
    <?= bulkFormOpen('comments', ['bulk_group' => 'comments']) ?>
    <?= bulkBar([
        ['label'=>'Hide','op'=>'hide','class'=>'bg-gray-600 hover:bg-gray-500'],
        ['label'=>'Unhide','op'=>'unhide','class'=>'bg-blue-600 hover:bg-blue-500'],
        ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected comments?'],
    ], 'comments') ?>
    <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
      <?php foreach ($comments as $c): $cAuthor = $c['type']==='lab' ? $c['lab_name'] : $c['full_name']; ?>
      <div class="p-5 hover:bg-gray-50 group">
        <div class="flex items-center gap-2 mb-1">
          <?= bulkRowCheckbox((int)$c['id'], 'comments') ?>
          <?php if ($c['is_hidden']): ?><span class="text-[10px] font-bold uppercase tracking-wider text-red-500">Hidden</span><?php endif; ?>
          <span class="font-semibold text-xs text-gray-900"><?= htmlspecialchars($cAuthor) ?></span>
          <span class="text-[10px] text-gray-400">· <?= date('d M Y', strtotime($c['created_at'])) ?></span>
        </div>
        <p class="text-xs text-gray-500 mb-2"><?= htmlspecialchars(communityBodyExcerpt($c['body'], 140)) ?></p>
        <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $c['is_hidden']?'unhide_comment':'hide_comment' ?>"><input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
            <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg"><?= $c['is_hidden']?'Unhide':'Hide' ?></button>
          </form>
          <form method="POST" onsubmit="return confirm('Delete this comment?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
            <button type="submit" class="px-2.5 py-1 text-xs font-semibold bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">Delete</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?= bulkBarScript(['ann', 'posts', 'comments']) ?>
<?php }, 'community', 'Community');
