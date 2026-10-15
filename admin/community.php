<?php
/**
 * RARL Admin — Community: what members see in their feed.
 * Publish team updates, run announcements (draft → publish, call-to-action,
 * auto-expiry), moderate, and set how the member Updates page behaves.
 * Members don't post; everything in their feed comes from here.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
$annV2 = announcementsHaveControls();
$back = fn(string $tab) => 'community.php?tab=' . $tab;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        foreach (['community_guidelines', 'community_feed_intro'] as $k) {
            $v = clean($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$k, $v, $v]);
        }
        $v = !empty($_POST['community_comments_enabled']) ? '1' : '0';
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('community_comments_enabled',?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$v, $v]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Updates page settings saved.'];
        header('Location: ' . $back('settings')); exit;
    }

    if ($action === 'save_announcement') {
        $id      = (int)($_POST['ann_id'] ?? 0);
        $title   = clean($_POST['ann_title'] ?? '');
        $content = clean($_POST['ann_content'] ?? '');
        $type    = in_array($_POST['ann_type'] ?? '', ['general', 'event', 'opportunity', 'alert'], true) ? $_POST['ann_type'] : 'general';
        $pinned  = !empty($_POST['ann_pinned']) ? 1 : 0;
        $publish = ($_POST['publish'] ?? '1') === '1' ? 1 : 0;
        $link    = cleanUrl($_POST['ann_link_url'] ?? '') ?: null;
        $label   = mb_substr(clean($_POST['ann_link_label'] ?? ''), 0, 80) ?: null;
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ann_expires_at'] ?? '') ? $_POST['ann_expires_at'] : null;
        if ($title === '' || $content === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'An announcement needs a title and a message.'];
            header('Location: ' . $back('announcements') . ($id ? '&edit=' . $id : '')); exit;
        }
        $cols = ['title' => $title, 'content' => $content, 'type' => $type, 'is_pinned' => $pinned, 'is_published' => $publish];
        if ($annV2) $cols += ['link_url' => $link, 'link_label' => $label, 'expires_at' => $expires];
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($cols)));
            $pdo->prepare("UPDATE announcements SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
        } else {
            $pdo->prepare('INSERT INTO announcements (`' . implode('`,`', array_keys($cols)) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')')->execute(array_values($cols));
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $publish ? ($id ? 'Announcement updated — members see the change now.' : 'Announcement published to every member\'s Home feed.') : 'Saved as a draft. Members won\'t see it until you publish.'];
        header('Location: ' . $back('announcements')); exit;
    }
    if ($action === 'toggle_publish') {
        $pdo->prepare('UPDATE announcements SET is_published = 1 - is_published WHERE id = ?')->execute([(int)($_POST['ann_id'] ?? 0)]);
        header('Location: ' . $back('announcements')); exit;
    }
    if ($action === 'delete_announcement') {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([(int)($_POST['ann_id'] ?? 0)]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Announcement deleted.'];
        header('Location: ' . $back('announcements')); exit;
    }
    if ($action === 'toggle_pin') {
        $pdo->prepare('UPDATE announcements SET is_pinned = 1 - is_pinned WHERE id = ?')->execute([(int)($_POST['ann_id'] ?? 0)]);
        header('Location: ' . $back('announcements')); exit;
    }

    if ($action === 'add_feed_post') {
        // Posted as the "RARL Team" system account (sql/009_admin_only_posts.sql).
        $body = clean($_POST['post_body'] ?? '');
        $imagePath = null;
        if (!empty($_FILES['post_image']['name'])) {
            $imagePath = validateUpload($_FILES['post_image'], ['jpg', 'jpeg', 'png', 'webp'], 5 * 1024 * 1024, UPLOADS_PATH . '/community');
        }
        $posterId = systemPosterMemberId();
        if ($posterId && ($body !== '' || $imagePath)) {
            $pdo->prepare("INSERT INTO community_posts (member_id, body, body_format, image_path, is_pinned) VALUES (?,?,'markdown',?,?)")
                ->execute([$posterId, $body, $imagePath, !empty($_POST['post_pinned']) ? 1 : 0]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Posted. It\'s at the top of every member\'s Home and Updates feed.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Write something or attach an image first.'];
        }
        header('Location: ' . $back('publish')); exit;
    }

    $postActions = ['pin_post' => 'is_pinned=1', 'unpin_post' => 'is_pinned=0', 'hide_post' => 'is_hidden=1', 'unhide_post' => 'is_hidden=0'];
    if (isset($postActions[$action])) {
        $pdo->prepare("UPDATE community_posts SET {$postActions[$action]} WHERE id=?")->execute([(int)($_POST['post_id'] ?? 0)]);
        header('Location: ' . $back('moderation')); exit;
    }
    if ($action === 'delete_post') {
        $pid = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare('DELETE FROM community_comments WHERE post_id=?')->execute([$pid]);
        $pdo->prepare('DELETE FROM community_likes WHERE post_id=?')->execute([$pid]);
        $pdo->prepare('DELETE FROM community_posts WHERE id=?')->execute([$pid]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Post deleted.'];
        header('Location: ' . $back('moderation')); exit;
    }
    $commentActions = ['hide_comment' => 'is_hidden=1', 'unhide_comment' => 'is_hidden=0'];
    if (isset($commentActions[$action])) {
        $pdo->prepare("UPDATE community_comments SET {$commentActions[$action]} WHERE id=?")->execute([(int)($_POST['comment_id'] ?? 0)]);
        header('Location: ' . $back('moderation')); exit;
    }
    if ($action === 'delete_comment') {
        $pdo->prepare('DELETE FROM community_comments WHERE id=?')->execute([(int)($_POST['comment_id'] ?? 0)]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Reply deleted.'];
        header('Location: ' . $back('moderation')); exit;
    }

    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        $bulkGroup = $_POST['bulk_group'] ?? '';
        $tab = $bulkGroup === 'announcements' ? 'announcements' : 'moderation';
        if ($ids && $bulkGroup) {
            $in = implode(',', $ids);
            $ops = [
                'announcements' => ['pin' => ["UPDATE announcements SET is_pinned=1 WHERE id IN ($in)"], 'unpin' => ["UPDATE announcements SET is_pinned=0 WHERE id IN ($in)"],
                                    'publish' => ["UPDATE announcements SET is_published=1 WHERE id IN ($in)"], 'unpublish' => ["UPDATE announcements SET is_published=0 WHERE id IN ($in)"],
                                    'delete' => ["DELETE FROM announcements WHERE id IN ($in)"]],
                'posts'    => ['hide' => ["UPDATE community_posts SET is_hidden=1 WHERE id IN ($in)"], 'unhide' => ["UPDATE community_posts SET is_hidden=0 WHERE id IN ($in)"],
                               'delete' => ["DELETE FROM community_comments WHERE post_id IN ($in)", "DELETE FROM community_likes WHERE post_id IN ($in)", "DELETE FROM community_posts WHERE id IN ($in)"]],
                'comments' => ['hide' => ["UPDATE community_comments SET is_hidden=1 WHERE id IN ($in)"], 'unhide' => ["UPDATE community_comments SET is_hidden=0 WHERE id IN ($in)"],
                               'delete' => ["DELETE FROM community_comments WHERE id IN ($in)"]],
            ];
            foreach ($ops[$bulkGroup][$bulkOp] ?? [] as $sql) $pdo->exec($sql);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => count($ids) . ' item(s) updated.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Select at least one item first.'];
        }
        header('Location: ' . $back($tab)); exit;
    }
}

$settings = [];
foreach ($pdo->query('SELECT `key`,`value` FROM settings')->fetchAll() as $r) $settings[$r['key']] = $r['value'];
$announcements = $pdo->query('SELECT * FROM announcements ORDER BY is_published DESC, is_pinned DESC, created_at DESC')->fetchAll();
$posts = $pdo->query('SELECT p.*, m.full_name, m.lab_name, m.type,
        (SELECT COUNT(*) FROM community_likes l WHERE l.post_id = p.id) AS like_count,
        (SELECT COUNT(*) FROM community_comments c WHERE c.post_id = p.id) AS comment_count
    FROM community_posts p JOIN members m ON m.id = p.member_id ORDER BY p.created_at DESC LIMIT 100')->fetchAll();
$comments = $pdo->query('SELECT c.*, m.full_name, m.lab_name, m.type FROM community_comments c JOIN members m ON m.id = c.member_id ORDER BY c.created_at DESC LIMIT 100')->fetchAll();
$memberCount = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn();

$tab  = in_array($_GET['tab'] ?? '', ['publish', 'announcements', 'moderation', 'settings'], true) ? $_GET['tab'] : 'publish';
$edit = null;
if (!empty($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM announcements WHERE id = ?'); $q->execute([(int)$_GET['edit']]);
    $edit = $q->fetch() ?: null;
    if ($edit) $tab = 'announcements';
}

adminWrap(function () use ($settings, $announcements, $posts, $comments, $tab, $edit, $annV2, $memberCount) {
    adminFlash();
    $today = date('Y-m-d');
    $live = fn($a) => $a['is_published'] && (empty($a['expires_at']) || $a['expires_at'] >= $today);
    $liveCount = count(array_filter($announcements, $live));
    $commentsOn = ($settings['community_comments_enabled'] ?? '0') === '1';
    $typeBadge = ['general' => ['General', 'gray'], 'event' => ['Event', 'blue'], 'opportunity' => ['Opportunity', 'green'], 'alert' => ['Important', 'red']];
    ?>
<div class="rarl-page-head">
  <div>
    <h1>Community</h1>
    <p>Everything members see in their Home and Updates feeds comes from here. Members follow, like and RSVP; they don't post.</p>
  </div>
  <a href="../community.php" target="_blank" class="rarl-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Updates page</a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php foreach ([
    ['Reach', number_format($memberCount), 'active members', 'fa-users'],
    ['Live announcements', $liveCount, 'of ' . count($announcements) . ' total', 'fa-bullhorn'],
    ['Team posts', count(array_filter($posts, fn($p) => !$p['is_hidden'])), 'visible in feed', 'fa-newspaper'],
    ['Member replies', $commentsOn ? 'On' : 'Off', $commentsOn ? count($comments) . ' replies' : 'members can only like', 'fa-comments'],
  ] as [$label, $val, $sub, $icon]): ?>
  <div class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?></span><span class="rarl-stat-value"><?= $val ?></span><span class="text-xs text-[color:var(--muted-fg)]"><?= $sub ?></span></div>
  <?php endforeach; ?>
</div>

<div class="rarl-tabs mb-6 w-fit max-w-full">
  <?php foreach (['publish' => ['fa-pen-to-square', 'Post an update'], 'announcements' => ['fa-bullhorn', 'Announcements'], 'moderation' => ['fa-shield-halved', 'Moderation'], 'settings' => ['fa-sliders', 'Settings']] as $k => [$ic, $label]): ?>
  <a href="community.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><i class="fa-solid <?= $ic ?>"></i> <?= $label ?><?php if ($k === 'announcements'): ?> <span class="count"><?= $liveCount ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'publish'): ?>
<div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
  <form method="POST" enctype="multipart/form-data" class="rarl-card p-6 space-y-4">
    <?= acsrfField() ?><input type="hidden" name="action" value="add_feed_post">
    <div>
      <h2 class="rarl-card-title">Post a team update</h2>
      <p class="text-xs text-[color:var(--muted-fg)] mt-0.5">Appears as "RARL Team" at the top of every member's feed. Good for news, wins, photos and milestones.</p>
    </div>
    <div>
      <label class="rarl-label" for="post_body">Message</label>
      <textarea id="post_body" name="post_body" rows="7" class="rarl-input" placeholder="What's new at RARL?" data-preview="post"></textarea>
      <p class="text-xs text-[color:var(--muted-fg)] mt-1">Formatting: **bold**, *italic*, [link text](https://…), and "- " for bullet points.</p>
    </div>
    <div>
      <span class="rarl-label">Photo <span class="font-normal text-[color:var(--muted-fg)]">(optional)</span></span>
      <?= adminImageDrop('post_image', null, 'JPG, PNG or WebP · up to 5 MB · shown full width', 'wide') ?>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="post_pinned" value="1" class="accent-rarl-red w-4 h-4"> Pin to the top of the feed</label>
    <div class="flex justify-end"><button class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-paper-plane"></i> Publish to members</button></div>
  </form>
  <div class="lg:sticky lg:top-6">
    <p class="rarl-label">Preview — as members see it</p>
    <div class="rarl-card p-5">
      <div class="flex items-center gap-3 mb-3"><span class="w-10 h-10 rounded-xl bg-red-50 text-rarl-red flex items-center justify-center"><i class="fa-solid fa-flask"></i></span><div class="leading-tight"><p class="text-sm font-semibold">RARL · Team update</p><p class="text-xs text-[color:var(--muted-fg)]">Just now</p></div></div>
      <div id="preview-post" class="text-[15px] leading-relaxed whitespace-pre-line text-gray-800"><span class="text-[color:var(--muted-fg)]">Start typing to see your post…</span></div>
      <img id="preview-post-img" alt="" class="hidden mt-3 rounded-lg w-full max-h-64 object-cover"/>
    </div>
  </div>
</div>

<?php elseif ($tab === 'announcements'): ?>
<dialog class="rarl-amodal" id="ann-modal" aria-labelledby="ann-modal-t" style="--modal-w:600px"<?= ($edit || !empty($_GET['new'])) ? ' data-autoopen' : '' ?>>
  <form method="POST" class="rarl-amodal-form" id="ann-form">
    <?= acsrfField() ?><input type="hidden" name="action" value="save_announcement"><input type="hidden" name="ann_id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="rarl-amodal-head"><div class="flex-1 min-w-0"><h2 id="ann-modal-t"><?= $edit ? 'Edit announcement' : 'New announcement' ?></h2><p>Shown on every member's Home feed and the Updates page.</p></div>
      <button type="button" class="rarl-icon-btn" data-amodal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="rarl-amodal-body space-y-4">
    <div>
      <label class="rarl-label" for="ann_title">Title</label>
      <input id="ann_title" name="ann_title" required maxlength="200" class="rarl-input" value="<?= ex($edit['title'] ?? '') ?>" placeholder="e.g. Call for papers: RARL Symposium"/>
    </div>
    <div>
      <label class="rarl-label" for="ann_content">Message</label>
      <textarea id="ann_content" name="ann_content" required rows="5" class="rarl-input" placeholder="What do members need to know?"><?= ex($edit['content'] ?? '') ?></textarea>
    </div>
    <div>
      <span class="rarl-label">Kind</span>
      <div class="grid grid-cols-2 gap-2">
        <?php foreach ($typeBadge as $k => [$label, $c]): ?>
        <label class="flex items-center gap-2 px-3 h-10 rounded-[var(--radius)] border border-[color:var(--border)] cursor-pointer text-sm has-[:checked]:border-rarl-red has-[:checked]:bg-red-50/50">
          <input type="radio" name="ann_type" value="<?= $k ?>" <?= ($edit['type'] ?? 'general') === $k ? 'checked' : '' ?> class="accent-rarl-red"> <?= $label ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($annV2): ?>
    <div class="grid grid-cols-[1fr_140px] gap-3">
      <div><label class="rarl-label" for="ann_link_url">Button link <span class="font-normal text-[color:var(--muted-fg)]">(optional)</span></label><input id="ann_link_url" name="ann_link_url" type="url" class="rarl-input" value="<?= ex($edit['link_url'] ?? '') ?>" placeholder="https://…"/></div>
      <div><label class="rarl-label" for="ann_link_label">Button text</label><input id="ann_link_label" name="ann_link_label" maxlength="40" class="rarl-input" value="<?= ex($edit['link_label'] ?? '') ?>" placeholder="Learn more"/></div>
    </div>
    <div>
      <label class="rarl-label" for="ann_expires_at">Hide automatically after <span class="font-normal text-[color:var(--muted-fg)]">(optional)</span></label>
      <input id="ann_expires_at" name="ann_expires_at" type="date" min="<?= $today ?>" class="rarl-input" value="<?= ex($edit['expires_at'] ?? '') ?>"/>
      <p class="text-xs text-[color:var(--muted-fg)] mt-1">Good for deadlines: members stop seeing it the day after this date.</p>
    </div>
    <?php else: ?>
    <p class="text-xs p-3 rounded-[var(--radius)] bg-amber-50 text-amber-800"><i class="fa-solid fa-circle-info"></i> Run <a class="underline font-semibold" href="migrate.php">migration 013</a> to add call-to-action buttons and auto-expiry.</p>
    <?php endif; ?>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="ann_pinned" value="1" <?= !empty($edit['is_pinned']) ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4"> <i class="fa-solid fa-thumbtack text-amber-600"></i> Pin above everything else</label>
    </div>
    <div class="rarl-amodal-foot">
      <button type="button" class="rarl-btn me-auto" data-amodal-close>Cancel</button>
      <button name="publish" value="0" class="rarl-btn">Save as draft</button>
      <button name="publish" value="1" class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-bullhorn"></i> <?= $edit && $edit['is_published'] ? 'Update' : 'Publish' ?></button>
    </div>
  </form>
</dialog>
<script>document.getElementById('ann-modal').addEventListener('close', function () { if (/[?&](edit|new)=/.test(location.search)) history.replaceState(null, '', 'community.php?tab=announcements'); });</script>

  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)] flex flex-wrap items-center justify-between gap-3">
      <h2 class="rarl-card-title">All announcements <span class="font-normal text-[color:var(--muted-fg)]"><?= count($announcements) ?></span></h2>
      <div class="flex items-center gap-3">
        <?php if ($announcements): ?><label class="flex items-center gap-1.5 text-xs text-[color:var(--muted-fg)]"><?= bulkSelectAllCheckbox('ann') ?> Select all</label><?php endif; ?>
        <a href="community.php?tab=announcements&new=1" class="rarl-btn rarl-btn-sm rarl-btn-primary"><i class="fa-solid fa-plus"></i> New announcement</a>
      </div>
    </div>
    <?php if (!$announcements): ?>
    <div class="rarl-empty"><i class="fa-solid fa-bullhorn text-2xl mb-2 block"></i>No announcements yet. <a class="underline font-semibold" href="community.php?tab=announcements&new=1">Write the first one</a>.</div>
    <?php else: ?>
    <?= bulkFormOpen('ann', ['bulk_group' => 'announcements']) ?>
    <?= bulkBar([
        ['label' => 'Publish', 'op' => 'publish', 'class' => 'bg-green-600 hover:bg-green-500'],
        ['label' => 'Unpublish', 'op' => 'unpublish', 'class' => 'bg-gray-600 hover:bg-gray-500'],
        ['label' => 'Pin', 'op' => 'pin', 'class' => 'bg-amber-600 hover:bg-amber-500'],
        ['label' => 'Unpin', 'op' => 'unpin', 'class' => 'bg-gray-600 hover:bg-gray-500'],
        ['label' => 'Delete', 'op' => 'delete', 'class' => 'bg-red-600 hover:bg-red-500', 'confirm' => 'Delete all selected announcements?'],
    ], 'ann') ?>
    <ul class="divide-y divide-[color:var(--border)]">
      <?php foreach ($announcements as $a):
        $expired = !empty($a['expires_at']) && $a['expires_at'] < $today;
        [$state, $tone] = !$a['is_published'] ? ['Draft', 'gray'] : ($expired ? ['Expired', 'amber'] : ['Live', 'green']);
        [$tl, $tc] = $typeBadge[$a['type']] ?? $typeBadge['general']; ?>
      <li class="px-5 py-4 flex items-start gap-3 <?= $edit && (int)$edit['id'] === (int)$a['id'] ? 'bg-red-50/40' : '' ?>">
        <span class="pt-1"><?= bulkRowCheckbox((int)$a['id'], 'ann') ?></span>
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-1.5 mb-1">
            <span class="rarl-badge rarl-badge-<?= $tone ?>"><?= $state ?></span>
            <span class="rarl-badge rarl-badge-<?= $tc ?>"><?= $tl ?></span>
            <?php if ($a['is_pinned']): ?><span class="rarl-badge rarl-badge-amber"><i class="fa-solid fa-thumbtack"></i> Pinned</span><?php endif; ?>
            <?php if (!empty($a['section_id'])): ?><span class="rarl-badge rarl-badge-purple">Chapter</span><?php endif; ?>
          </div>
          <p class="font-semibold text-sm"><?= ex($a['title']) ?></p>
          <p class="text-xs text-[color:var(--muted-fg)] mt-0.5 line-clamp-2"><?= ex($a['content']) ?></p>
          <p class="text-xs text-[color:var(--muted-fg)] mt-1.5">
            <?= date('d M Y', strtotime($a['created_at'])) ?>
            <?php if (!empty($a['expires_at'])): ?> · <?= $expired ? 'expired' : 'until' ?> <?= date('d M Y', strtotime($a['expires_at'])) ?><?php endif; ?>
            <?php if (!empty($a['link_url'])): ?> · <i class="fa-solid fa-link"></i> <?= ex($a['link_label'] ?: 'Learn more') ?><?php endif; ?>
          </p>
        </div>
        <div class="flex items-center gap-1 flex-shrink-0">
          <a href="community.php?tab=announcements&edit=<?= (int)$a['id'] ?>" class="rarl-icon-btn" title="Edit"><i class="fa-solid fa-pen"></i></a>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_publish"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>"><button class="rarl-icon-btn" title="<?= $a['is_published'] ? 'Unpublish' : 'Publish' ?>" data-no-loading><i class="fa-solid <?= $a['is_published'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>"><button class="rarl-icon-btn" title="<?= $a['is_pinned'] ? 'Unpin' : 'Pin' ?>" data-no-loading><i class="fa-solid fa-thumbtack <?= $a['is_pinned'] ? 'text-amber-600' : '' ?>"></i></button></form>
          <form method="POST" data-confirm="Delete this announcement? This can't be undone."><?= acsrfField() ?><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="ann_id" value="<?= (int)$a['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'moderation'): ?>
<div class="grid grid-cols-1 <?= $comments ? 'xl:grid-cols-2' : '' ?> gap-6">
  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)] flex items-center justify-between gap-3">
      <h2 class="rarl-card-title">Feed posts <span class="font-normal text-[color:var(--muted-fg)]"><?= count($posts) ?></span></h2>
      <?php if ($posts): ?><label class="flex items-center gap-1.5 text-xs text-[color:var(--muted-fg)]"><?= bulkSelectAllCheckbox('posts') ?> Select all</label><?php endif; ?>
    </div>
    <?php if (!$posts): ?><div class="rarl-empty">No posts yet.</div><?php else: ?>
    <?= bulkFormOpen('posts', ['bulk_group' => 'posts']) ?>
    <?= bulkBar([
        ['label' => 'Hide', 'op' => 'hide', 'class' => 'bg-gray-600 hover:bg-gray-500'],
        ['label' => 'Unhide', 'op' => 'unhide', 'class' => 'bg-blue-600 hover:bg-blue-500'],
        ['label' => 'Delete', 'op' => 'delete', 'class' => 'bg-red-600 hover:bg-red-500', 'confirm' => 'Delete all selected posts and their replies/likes?'],
    ], 'posts') ?>
    <ul class="divide-y divide-[color:var(--border)] max-h-[70vh] overflow-y-auto">
      <?php foreach ($posts as $post): ?>
      <li class="px-5 py-4 flex gap-3">
        <span class="pt-1"><?= bulkRowCheckbox((int)$post['id'], 'posts') ?></span>
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-1.5 mb-1 text-xs text-[color:var(--muted-fg)]">
            <?php if ($post['is_hidden']): ?><span class="rarl-badge rarl-badge-red">Hidden</span><?php endif; ?>
            <?php if ($post['is_pinned']): ?><span class="rarl-badge rarl-badge-amber"><i class="fa-solid fa-thumbtack"></i> Pinned</span><?php endif; ?>
            <span><?= date('d M Y', strtotime($post['created_at'])) ?></span> · <span><i class="fa-solid fa-thumbs-up"></i> <?= (int)$post['like_count'] ?></span><?php if ($post['comment_count']): ?> · <span><?= (int)$post['comment_count'] ?> replies</span><?php endif; ?>
          </div>
          <p class="text-sm line-clamp-3"><?= ex(communityBodyExcerpt($post['body'], 220)) ?></p>
          <?php if (!empty($post['image_path'])): ?><img src="<?= UPLOADS_URL ?>/community/<?= ex($post['image_path']) ?>" alt="" class="max-h-24 rounded-lg mt-2"/><?php endif; ?>
        </div>
        <div class="flex items-start gap-1 flex-shrink-0">
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $post['is_pinned'] ? 'unpin_post' : 'pin_post' ?>"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><button class="rarl-icon-btn" title="<?= $post['is_pinned'] ? 'Unpin' : 'Pin' ?>" data-no-loading><i class="fa-solid fa-thumbtack <?= $post['is_pinned'] ? 'text-amber-600' : '' ?>"></i></button></form>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $post['is_hidden'] ? 'unhide_post' : 'hide_post' ?>"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><button class="rarl-icon-btn" title="<?= $post['is_hidden'] ? 'Show' : 'Hide' ?>" data-no-loading><i class="fa-solid <?= $post['is_hidden'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button></form>
          <form method="POST" data-confirm="Delete this post and all its replies and likes?"><?= acsrfField() ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <?php if ($comments): ?>
  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)] flex items-center justify-between gap-3">
      <h2 class="rarl-card-title">Member replies <span class="font-normal text-[color:var(--muted-fg)]"><?= count($comments) ?></span><?php if (!$commentsOn): ?> <span class="rarl-badge rarl-badge-gray ml-1">Hidden from members — replies are off</span><?php endif; ?></h2>
      <label class="flex items-center gap-1.5 text-xs text-[color:var(--muted-fg)]"><?= bulkSelectAllCheckbox('comments') ?> Select all</label>
    </div>
    <?= bulkFormOpen('comments', ['bulk_group' => 'comments']) ?>
    <?= bulkBar([
        ['label' => 'Hide', 'op' => 'hide', 'class' => 'bg-gray-600 hover:bg-gray-500'],
        ['label' => 'Unhide', 'op' => 'unhide', 'class' => 'bg-blue-600 hover:bg-blue-500'],
        ['label' => 'Delete', 'op' => 'delete', 'class' => 'bg-red-600 hover:bg-red-500', 'confirm' => 'Delete all selected replies?'],
    ], 'comments') ?>
    <ul class="divide-y divide-[color:var(--border)] max-h-[70vh] overflow-y-auto">
      <?php foreach ($comments as $c): $cAuthor = $c['type'] === 'lab' ? $c['lab_name'] : $c['full_name']; ?>
      <li class="px-5 py-4 flex gap-3">
        <span class="pt-1"><?= bulkRowCheckbox((int)$c['id'], 'comments') ?></span>
        <div class="flex-1 min-w-0">
          <p class="text-xs text-[color:var(--muted-fg)] mb-0.5"><?php if ($c['is_hidden']): ?><span class="rarl-badge rarl-badge-red">Hidden</span> <?php endif; ?><a class="font-semibold text-[color:var(--fg)] hover:underline" href="member-edit.php?id=<?= (int)$c['member_id'] ?>"><?= ex($cAuthor) ?></a> · <?= date('d M Y', strtotime($c['created_at'])) ?></p>
          <p class="text-sm"><?= ex(communityBodyExcerpt($c['body'], 200)) ?></p>
        </div>
        <div class="flex items-start gap-1 flex-shrink-0">
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="<?= $c['is_hidden'] ? 'unhide_comment' : 'hide_comment' ?>"><input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>"><button class="rarl-icon-btn" title="<?= $c['is_hidden'] ? 'Show' : 'Hide' ?>" data-no-loading><i class="fa-solid <?= $c['is_hidden'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button></form>
          <form method="POST" data-confirm="Delete this reply?"><?= acsrfField() ?><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<form method="POST" class="rarl-card p-6 space-y-5 max-w-2xl">
  <?= acsrfField() ?><input type="hidden" name="action" value="save_settings">
  <h2 class="rarl-card-title">Updates page</h2>
  <div>
    <label class="rarl-label" for="community_feed_intro">Intro line</label>
    <input id="community_feed_intro" name="community_feed_intro" class="rarl-input" value="<?= ex($settings['community_feed_intro'] ?? '') ?>" placeholder="News, announcements and highlights from the RARL team."/>
    <p class="text-xs text-[color:var(--muted-fg)] mt-1">Shown under the "Updates" heading.</p>
  </div>
  <div>
    <label class="rarl-label" for="community_guidelines">Community guidelines</label>
    <textarea id="community_guidelines" name="community_guidelines" rows="5" class="rarl-input"><?= ex($settings['community_guidelines'] ?? '') ?></textarea>
  </div>
  <label class="flex items-start gap-3 p-4 rounded-[var(--radius)] border border-[color:var(--border)] cursor-pointer">
    <input type="checkbox" name="community_comments_enabled" value="1" <?= $commentsOn ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4 mt-0.5">
    <span><span class="block text-sm font-semibold">Let members reply to team posts</span>
    <span class="block text-xs text-[color:var(--muted-fg)] mt-0.5">Off by default: members follow and like, but don't post. Turning this on shows a reply box under each team post, and replies show the member's name and photo to other members.</span></span>
  </label>
  <div class="flex justify-end"><button class="rarl-btn rarl-btn-primary">Save settings</button></div>
</form>
<?php endif; ?>

<script>
(function () {
  var body = document.getElementById('post_body'), out = document.getElementById('preview-post');
  if (body && out) {
    var esc = function (s) { return s.replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
    var render = function () {
      var t = body.value.trim();
      if (!t) { out.innerHTML = '<span class="text-[color:var(--muted-fg)]">Start typing to see your post…</span>'; return; }
      out.innerHTML = esc(t).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/\[(.+?)\]\((https?:[^)\s]+)\)/g, '<a class="text-rarl-red underline" href="$2" target="_blank" rel="noopener">$1</a>').replace(/^- /gm, '• ');
    };
    body.addEventListener('input', render);
    var file = document.querySelector('input[name=post_image]'), img = document.getElementById('preview-post-img');
    if (file && img) file.addEventListener('change', function () {
      if (file.files[0]) { img.src = URL.createObjectURL(file.files[0]); img.classList.remove('hidden'); } else img.classList.add('hidden');
    });
  }
})();
</script>
<?= bulkBarScript(['ann', 'posts', 'comments']) ?>
<?php }, 'community', 'Community');
