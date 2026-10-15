<?php
/**
 * RARL Admin — Resources Management
 * Manage categories and curated external links
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    // ── Categories ──
    if ($action === 'add_category') {
        $name   = clean($_POST['cat_name'] ?? '');
        $slug   = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $desc   = clean($_POST['cat_desc'] ?? '');
        $icon   = clean($_POST['cat_icon'] ?? '📚');
        $pub    = !empty($_POST['cat_pub']) ? 1 : 0;
        $access = ($_POST['cat_access'] ?? '') === 'member' ? 'member' : 'public';
        if ($name) {
            $pdo->prepare("INSERT INTO resource_categories (name,slug,description,icon_emoji,is_published,access_level) VALUES (?,?,?,?,?,?)")
                ->execute([$name, trim($slug, '-'), $desc, $icon, $pub, $access]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Topic created.'];
        }
        header('Location: resources.php'); exit;
    }
    if ($action === 'update_category') {
        $id = (int)($_POST['cat_id'] ?? 0); $name = clean($_POST['cat_name'] ?? '');
        if ($id && $name) {
            $pdo->prepare('UPDATE resource_categories SET name=?, description=?, icon_emoji=?, is_published=?, access_level=? WHERE id=?')
                ->execute([$name, clean($_POST['cat_desc'] ?? ''), clean($_POST['cat_icon'] ?? '📚'), !empty($_POST['cat_pub']) ? 1 : 0, ($_POST['cat_access'] ?? '') === 'member' ? 'member' : 'public', $id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Topic updated.'];
        }
        header('Location: resources.php'); exit;
    }
    if ($action === 'toggle_cat_access') {
        $pdo->prepare("UPDATE resource_categories SET access_level = IF(access_level='member','public','member') WHERE id=?")->execute([(int)($_POST['cat_id']??0)]);
        header('Location: resources.php'); exit;
    }
    if ($action === 'delete_cat') {
        $pdo->prepare("DELETE FROM resource_categories WHERE id=?")->execute([(int)($_POST['cat_id']??0)]);
        header('Location: resources.php'); exit;
    }

    // ── Resources ──
    if ($action === 'add_resource') {
        $title  = clean($_POST['res_title'] ?? '');
        $catId  = (int)($_POST['cat_id'] ?? 0);
        $url    = cleanUrl($_POST['res_url'] ?? '');
        $type   = clean($_POST['res_type'] ?? 'other');
        $desc   = clean($_POST['res_desc'] ?? '');
        $feat   = !empty($_POST['res_feat']) ? 1 : 0;
        $access = ($_POST['res_access'] ?? '') === 'member' ? 'member' : 'public';

        if ($title && $url && $catId) {
            $pdo->prepare("INSERT INTO resources (category_id,title,description,url,type,is_featured,access_level) VALUES (?,?,?,?,?,?,?)")
                ->execute([$catId, $title, $desc, $url, $type, $feat, $access]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Resource added. Members see it in the Learning hub and their Home feed.'];
        }
        header('Location: resources.php'); exit;
    }
    if ($action === 'update_resource') {
        $id = (int)($_POST['res_id'] ?? 0);
        $title = clean($_POST['res_title'] ?? ''); $url = cleanUrl($_POST['res_url'] ?? ''); $catId = (int)($_POST['cat_id'] ?? 0);
        if ($id && $title && $url && $catId) {
            $pdo->prepare('UPDATE resources SET category_id=?, title=?, description=?, url=?, type=?, is_featured=?, access_level=? WHERE id=?')
                ->execute([$catId, $title, clean($_POST['res_desc'] ?? ''), $url, clean($_POST['res_type'] ?? 'other'), !empty($_POST['res_feat']) ? 1 : 0, ($_POST['res_access'] ?? '') === 'member' ? 'member' : 'public', $id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Resource updated.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Title, link and topic are required.'];
        }
        header('Location: resources.php'); exit;
    }
    if ($action === 'toggle_access') {
        $pdo->prepare("UPDATE resources SET access_level = IF(access_level='member','public','member') WHERE id=?")->execute([(int)($_POST['res_id']??0)]);
        header('Location: resources.php'); exit;
    }
    if ($action === 'delete_res') {
        $pdo->prepare("DELETE FROM resources WHERE id=?")->execute([(int)($_POST['res_id']??0)]);
        header('Location: resources.php'); exit;
    }
    if ($action === 'toggle_feat') {
        $pdo->prepare("UPDATE resources SET is_featured = 1 - is_featured WHERE id=?")->execute([(int)($_POST['res_id']??0)]);
        header('Location: resources.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'delete') {
                $pdo->exec("DELETE FROM resources WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' resource(s) deleted.'];
            } elseif ($bulkOp === 'feature') {
                $pdo->exec("UPDATE resources SET is_featured = 1 WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' resource(s) featured.'];
            } elseif ($bulkOp === 'unfeature') {
                $pdo->exec("UPDATE resources SET is_featured = 0 WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' resource(s) unfeatured.'];
            } elseif ($bulkOp === 'make_public') {
                $pdo->exec("UPDATE resources SET access_level = 'public' WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' resource(s) made public.'];
            } elseif ($bulkOp === 'make_premium') {
                $pdo->exec("UPDATE resources SET access_level = 'member' WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' resource(s) made members-only.'];
            }
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one resource first.'];
        }
        header('Location: resources.php'); exit;
    }
}

$categories = $pdo->query("SELECT * FROM resource_categories ORDER BY display_order")->fetchAll();
$resources  = $pdo->query("SELECT r.*, c.name as cat_name FROM resources r LEFT JOIN resource_categories c ON r.category_id = c.id ORDER BY r.created_at DESC")->fetchAll();

$catCounts = $pdo->query("SELECT category_id, COUNT(*) FROM resources GROUP BY category_id")->fetchAll(PDO::FETCH_KEY_PAIR);

adminWrap(function() use ($categories, $resources, $catCounts) {
    adminFlash();
    $types = ['youtube_playlist' => 'YouTube playlist', 'youtube_video' => 'YouTube video', 'article' => 'Article', 'book' => 'Book', 'tool' => 'Tool', 'course' => 'Course', 'other' => 'Other'];
    $icons = ['youtube_playlist' => 'fa-brands fa-youtube', 'youtube_video' => 'fa-brands fa-youtube', 'article' => 'fa-solid fa-file-lines', 'book' => 'fa-solid fa-book', 'tool' => 'fa-solid fa-wrench', 'course' => 'fa-solid fa-graduation-cap', 'other' => 'fa-solid fa-link'];
    $cat = (int)($_GET['cat'] ?? 0);
    $shown = $cat ? array_values(array_filter($resources, fn($r) => (int)$r['category_id'] === $cat)) : $resources;
    // One form, two modes: the modal is filled from a row's data-res JSON for editing.
    $resField = function () use ($categories, $types) { ?>
      <div><label class="rarl-label" for="res_title">Title <span class="text-rarl-red">*</span></label><input id="res_title" name="res_title" required class="rarl-input" placeholder="Modern Robotics (Lynch &amp; Park)"/></div>
      <div><label class="rarl-label" for="res_url">Link <span class="text-rarl-red">*</span></label><input id="res_url" name="res_url" type="url" required class="rarl-input" placeholder="https://…"/></div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="rarl-label" for="cat_id">Category <span class="text-rarl-red">*</span></label><select id="cat_id" name="cat_id" required class="rarl-input"><option value="">Choose…</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= ex($c['name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="rarl-label" for="res_type">Kind</label><select id="res_type" name="res_type" class="rarl-input"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div><label class="rarl-label" for="res_desc">Short description</label><textarea id="res_desc" name="res_desc" rows="3" class="rarl-input" placeholder="Why it's worth a member's time"></textarea></div>
      <div class="grid sm:grid-cols-2 gap-3">
        <label class="flex items-start gap-2.5 p-3 rounded-[var(--radius)] border border-[color:var(--border)] cursor-pointer"><input type="checkbox" name="res_feat" value="1" class="accent-rarl-red w-4 h-4 mt-0.5"><span class="text-sm"><span class="block font-semibold"><i class="fa-solid fa-star text-amber-500"></i> Featured</span><span class="text-xs text-[color:var(--muted-fg)]">Shown first in its topic</span></span></label>
        <label class="flex items-start gap-2.5 p-3 rounded-[var(--radius)] border border-[color:var(--border)] cursor-pointer"><input type="checkbox" name="res_access" value="member" class="accent-rarl-red w-4 h-4 mt-0.5"><span class="text-sm"><span class="block font-semibold"><i class="fa-solid fa-lock text-amber-600"></i> Members only</span><span class="text-xs text-[color:var(--muted-fg)]">Hidden from visitors</span></span></label>
      </div>
    <?php };
?>
<div class="rarl-page-head">
  <div><h1>Learning</h1><p>Courses, talks, books and tools members see in the Learning hub, grouped by topic.</p></div>
  <div class="flex flex-wrap gap-2">
    <a href="../resources.php" target="_blank" class="rarl-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> View hub</a>
    <button type="button" class="rarl-btn" data-amodal="cat-modal" data-cat-new><i class="fa-solid fa-folder-plus"></i> New topic</button>
    <button type="button" class="rarl-btn rarl-btn-primary" data-amodal="res-modal" data-res-new <?= $categories ? '' : 'disabled title="Create a topic first"' ?>><i class="fa-solid fa-plus"></i> Add resource</button>
  </div>
</div>

<?php if (!$categories): ?>
<div class="rarl-card rarl-empty"><i class="fa-solid fa-folder-open text-3xl mb-3 block"></i><p class="font-semibold text-[color:var(--fg)]">Start with a topic</p><p class="mt-1">Topics group resources in the hub, e.g. "Motion Planning" or "ROS 2".</p><button type="button" class="rarl-btn rarl-btn-primary mt-4" data-amodal="cat-modal" data-cat-new><i class="fa-solid fa-folder-plus"></i> Create the first topic</button></div>
<?php else: ?>
<div class="flex gap-2 overflow-x-auto pb-1 mb-5">
  <a href="resources.php" class="rarl-btn rarl-btn-sm <?= $cat ? '' : 'rarl-btn-dark' ?>">All <span class="opacity-70"><?= count($resources) ?></span></a>
  <?php foreach ($categories as $c): ?>
  <span class="inline-flex items-center rounded-[var(--radius)] border <?= $cat === (int)$c['id'] ? 'border-gray-900 bg-gray-900 text-white' : 'border-[color:var(--border)] bg-white' ?> shrink-0">
    <a href="resources.php?cat=<?= (int)$c['id'] ?>" class="ps-3 pe-2 h-8 inline-flex items-center gap-1.5 text-sm font-medium whitespace-nowrap"><?= $c['icon_emoji'] ?> <?= ex($c['name']) ?> <span class="opacity-60"><?= (int)($catCounts[$c['id']] ?? 0) ?></span><?= ($c['access_level'] ?? 'public') === 'member' ? ' <i class="fa-solid fa-lock text-[10px]"></i>' : '' ?><?= $c['is_published'] ? '' : ' <span class="text-[10px] uppercase">draft</span>' ?></a>
    <button type="button" class="h-8 px-2 opacity-60 hover:opacity-100" title="Edit topic" aria-label="Edit topic <?= ex($c['name']) ?>" data-amodal="cat-modal" data-cat='<?= ex(json_encode(['id' => (int)$c['id'], 'name' => html_entity_decode($c['name'], ENT_QUOTES), 'desc' => html_entity_decode((string)$c['description'], ENT_QUOTES), 'icon' => $c['icon_emoji'], 'pub' => (int)$c['is_published'], 'member' => ($c['access_level'] ?? 'public') === 'member'])) ?>'><i class="fa-solid fa-pen text-xs"></i></button>
  </span>
  <?php endforeach; ?>
</div>

<div class="rarl-card overflow-hidden">
  <div class="px-5 py-4 border-b border-[color:var(--border)] flex items-center justify-between gap-3">
    <h2 class="rarl-card-title"><?= $cat ? 'Resources in this topic' : 'All resources' ?> <span class="font-normal text-[color:var(--muted-fg)]"><?= count($shown) ?></span></h2>
    <?php if ($shown): ?><label class="flex items-center gap-1.5 text-xs text-[color:var(--muted-fg)]"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
  </div>
  <?php if (!$shown): ?><div class="rarl-empty">No resources here yet. <button type="button" class="underline font-semibold" data-amodal="res-modal" data-res-new>Add one</button></div><?php else: ?>
  <?= bulkFormOpen() ?>
  <?= bulkBar([
      ['label'=>'<i class="fa-solid fa-star"></i> Feature','op'=>'feature','class'=>'bg-amber-600 hover:bg-amber-500'],
      ['label'=>'Unfeature','op'=>'unfeature','class'=>'bg-gray-600 hover:bg-gray-500'],
      ['label'=>'Make public','op'=>'make_public','class'=>'bg-green-600 hover:bg-green-500'],
      ['label'=>'<i class="fa-solid fa-lock"></i> Members only','op'=>'make_premium','class'=>'bg-purple-600 hover:bg-purple-500'],
      ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected resources?'],
  ]) ?>
  <ul class="divide-y divide-[color:var(--border)]">
    <?php foreach ($shown as $r): $member = ($r['access_level'] ?? 'public') === 'member'; ?>
    <li class="px-4 sm:px-5 py-3.5 flex items-center gap-3 sm:gap-4">
      <?= bulkRowCheckbox((int)$r['id']) ?>
      <span class="w-10 h-10 rounded-[var(--radius)] bg-[color:var(--muted)] flex items-center justify-center flex-shrink-0 text-[color:var(--muted-fg)]"><i class="<?= $icons[$r['type']] ?? 'fa-solid fa-link' ?>"></i></span>
      <button type="button" class="flex-1 min-w-0 text-start" data-amodal="res-modal" data-res='<?= ex(json_encode(['id' => (int)$r['id'], 'title' => html_entity_decode($r['title'], ENT_QUOTES), 'url' => $r['url'], 'cat' => (int)$r['category_id'], 'type' => $r['type'], 'desc' => html_entity_decode((string)$r['description'], ENT_QUOTES), 'feat' => (int)$r['is_featured'], 'member' => $member])) ?>'>
        <span class="flex flex-wrap items-center gap-1.5">
          <span class="font-semibold text-sm hover:underline"><?= ex($r['title']) ?></span>
          <?php if ($r['is_featured']): ?><span class="rarl-badge rarl-badge-amber"><i class="fa-solid fa-star"></i> Featured</span><?php endif; ?>
          <?php if ($member): ?><span class="rarl-badge rarl-badge-purple"><i class="fa-solid fa-lock"></i> Members</span><?php endif; ?>
        </span>
        <span class="block text-xs text-[color:var(--muted-fg)] truncate mt-0.5"><?= ex($r['cat_name'] ?? 'No topic') ?> · <?= $types[$r['type']] ?? 'Link' ?> · <?= ex(parse_url($r['url'], PHP_URL_HOST) ?: $r['url']) ?></span>
      </button>
      <a href="<?= ex($r['url']) ?>" target="_blank" rel="noopener" class="rarl-icon-btn" title="Open link"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
      <form method="POST" data-confirm="Delete “<?= ex($r['title']) ?>”?"><?= acsrfField() ?><input type="hidden" name="action" value="delete_res"><input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php adminModalOpen('res-modal', '<span data-title-new>Add a resource</span><span data-title-edit hidden>Edit resource</span>', 'Appears in the Learning hub and in members\' Home feed.', ['form' => true, 'width' => '600px']); ?>
  <input type="hidden" name="action" value="add_resource"><input type="hidden" name="res_id" value="">
  <?php $resField(); ?>
<?php adminModalClose('Save resource', '', true); ?>

<?php adminModalOpen('cat-modal', '<span data-title-new>New topic</span><span data-title-edit hidden>Edit topic</span>', 'Topics group resources in the Learning hub.', ['form' => true, 'width' => '480px']); ?>
  <input type="hidden" name="action" value="add_category"><input type="hidden" name="cat_id" value="">
  <div class="grid grid-cols-[72px_1fr] gap-3">
    <div><label class="rarl-label" for="cat_icon">Emoji</label><input id="cat_icon" name="cat_icon" value="📚" maxlength="4" class="rarl-input text-center text-lg"/></div>
    <div><label class="rarl-label" for="cat_name">Name <span class="text-rarl-red">*</span></label><input id="cat_name" name="cat_name" required class="rarl-input" placeholder="Motion Planning"/></div>
  </div>
  <div><label class="rarl-label" for="cat_desc">Description</label><textarea id="cat_desc" name="cat_desc" rows="2" class="rarl-input"></textarea></div>
  <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="cat_pub" value="1" checked class="accent-rarl-red w-4 h-4"> Published</label>
  <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="cat_access" value="member" class="accent-rarl-red w-4 h-4"> <i class="fa-solid fa-lock text-amber-600"></i> Members-only topic</label>
<?php adminModalClose('Save topic', '<button type="submit" form="cat-del" class="rarl-btn text-red-600" data-cat-del hidden><i class="fa-solid fa-trash"></i> Delete</button>', true); ?>
<form id="cat-del" method="POST" data-confirm="Delete this topic? Its resources stay but lose their topic."><?= acsrfField() ?><input type="hidden" name="action" value="delete_cat"><input type="hidden" name="cat_id" value=""></form>

<script>
(function () {
  function mode(dialog, edit) {
    dialog.querySelectorAll('[data-title-new]').forEach(function (e) { e.hidden = edit; });
    dialog.querySelectorAll('[data-title-edit]').forEach(function (e) { e.hidden = !edit; });
  }
  var rm = document.getElementById('res-modal'), rf = rm.querySelector('form');
  document.querySelectorAll('[data-res-new]').forEach(function (b) { b.addEventListener('click', function () {
    rf.reset(); rf.action.value = 'add_resource'; rf.res_id.value = ''; <?php if ($cat): ?>rf.cat_id.value = '<?= $cat ?>';<?php endif; ?> mode(rm, false);
  }); });
  document.querySelectorAll('[data-res]').forEach(function (b) { b.addEventListener('click', function () {
    var d = JSON.parse(b.dataset.res); rf.reset(); mode(rm, true);
    rf.action.value = 'update_resource'; rf.res_id.value = d.id; rf.res_title.value = d.title; rf.res_url.value = d.url;
    rf.cat_id.value = d.cat; rf.res_type.value = d.type; rf.res_desc.value = d.desc; rf.res_feat.checked = !!d.feat; rf.res_access.checked = d.member;
  }); });
  var cm = document.getElementById('cat-modal'), cf = cm.querySelector('form'), del = cm.querySelector('[data-cat-del]'), delForm = document.getElementById('cat-del');
  document.querySelectorAll('[data-cat-new]').forEach(function (b) { b.addEventListener('click', function () {
    cf.reset(); cf.action.value = 'add_category'; cf.cat_id.value = ''; del.hidden = true; mode(cm, false);
  }); });
  document.querySelectorAll('[data-cat]').forEach(function (b) { b.addEventListener('click', function () {
    var d = JSON.parse(b.dataset.cat); cf.reset(); mode(cm, true); del.hidden = false; delForm.cat_id.value = d.id;
    cf.action.value = 'update_category'; cf.cat_id.value = d.id; cf.cat_name.value = d.name; cf.cat_desc.value = d.desc; cf.cat_icon.value = d.icon; cf.cat_pub.checked = !!d.pub; cf.cat_access.checked = d.member;
  }); });
})();
</script>
<?= bulkBarScript() ?>
<?php }, 'resources', 'Learning');
