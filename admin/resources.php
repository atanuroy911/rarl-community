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
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Category created.'];
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
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Resource added.'];
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
}

$categories = $pdo->query("SELECT * FROM resource_categories ORDER BY display_order")->fetchAll();
$resources  = $pdo->query("SELECT r.*, c.name as cat_name FROM resources r LEFT JOIN resource_categories c ON r.category_id = c.id ORDER BY r.created_at DESC")->fetchAll();

adminWrap(function() use ($categories, $resources) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Learning Resources</h1>
<p class="text-gray-500 text-sm mb-7">Curate YouTube playlists, articles, tools, and books</p>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Left Col: Forms -->
  <div class="xl:col-span-1 space-y-6">

    <!-- Add Resource -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4">🔗 Add Resource Link</h2>
      <?php if (empty($categories)): ?>
      <p class="text-xs text-amber-600 bg-amber-50 p-3 rounded-lg border border-amber-200">Please create a category first before adding resources.</p>
      <?php else: ?>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="add_resource">
        <input type="text" name="res_title" required placeholder="Resource Title"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <input type="url" name="res_url" required placeholder="https://..."
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>

        <div class="grid grid-cols-2 gap-2">
          <select name="cat_id" required class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <option value="">— Category —</option>
            <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
          </select>
          <select name="res_type" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <?php foreach (['youtube_playlist','youtube_video','article','book','tool','course','other'] as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst(str_replace('_',' ',$t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <textarea name="res_desc" rows="2" placeholder="Brief description (optional)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"></textarea>

        <label class="flex items-center gap-2 p-3 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer mt-2 hover:border-amber-300">
          <input type="checkbox" name="res_feat" value="1" class="accent-amber-500 w-4 h-4"/>
          <span class="text-xs font-semibold text-gray-700">⭐ Feature this resource</span>
        </label>
        <label class="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-xl cursor-pointer mt-2">
          <input type="checkbox" name="res_access" value="member" class="accent-amber-600 w-4 h-4"/>
          <span class="text-xs font-semibold text-amber-700">🔒 Members-only (premium content)</span>
        </label>
        <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors">Add Resource</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Add Category -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-4">📁 Add Category</h2>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="add_category">
        <div class="flex gap-2">
          <input type="text" name="cat_icon" value="📚" class="w-12 text-center px-2 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
          <input type="text" name="cat_name" required placeholder="Category Name" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <textarea name="cat_desc" rows="2" placeholder="Description" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 resize-none"></textarea>
        <label class="flex items-center gap-2 mb-2"><input type="checkbox" name="cat_pub" value="1" checked class="accent-rarl-red w-3.5 h-3.5"/><span class="text-xs text-gray-600">Publish immediately</span></label>
        <label class="flex items-center gap-2 mb-2"><input type="checkbox" name="cat_access" value="member" class="accent-amber-600 w-3.5 h-3.5"/><span class="text-xs text-amber-700 font-semibold">🔒 Members-only category</span></label>
        <button type="submit" class="w-full py-2 bg-gray-800 hover:bg-gray-900 text-white font-semibold text-xs rounded-xl transition-colors">Create Category</button>
      </form>

      <div class="mt-5 space-y-2">
        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Existing Categories</p>
        <?php foreach ($categories as $c): ?>
        <div class="flex items-center justify-between p-2 bg-gray-50 border border-gray-100 rounded-lg group">
          <div class="flex items-center gap-2">
            <span class="text-base"><?= $c['icon_emoji'] ?></span>
            <span class="text-xs font-semibold text-gray-700"><?= htmlspecialchars($c['name']) ?></span>
            <?php if (($c['access_level'] ?? 'public') === 'member'): ?><span class="text-[9px] font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded">🔒</span><?php endif; ?>
          </div>
          <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_cat_access"><input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
              <button class="text-[10px] text-amber-600 hover:underline"><?= ($c['access_level']??'public')==='member' ? 'Make Public' : 'Make Premium' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete category? This will not delete its resources, but they will be orphaned.')">
              <?= acsrfField() ?><input type="hidden" name="action" value="delete_cat"><input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
              <button class="text-[10px] text-red-500 hover:underline">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Right Col: Resources List -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Resources <span class="text-gray-400 font-normal text-xs">(<?= count($resources) ?>)</span></h2>
      </div>
      <div class="divide-y divide-gray-100">
        <?php if (empty($resources)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No resources added yet.</p>
        <?php endif; ?>
        <?php foreach ($resources as $r):
          $icons = ['youtube_playlist'=>'▶️','youtube_video'=>'🎬','article'=>'📄','book'=>'📚','tool'=>'🔧','course'=>'🎓','other'=>'🔗'];
        ?>
        <div class="p-4 flex gap-4 hover:bg-gray-50 group">
          <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center text-lg flex-shrink-0"><?= $icons[$r['type']] ?? '🔗' ?></div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <?php if ($r['is_featured']): ?><span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">⭐ Featured</span><?php endif; ?>
              <?php if (($r['access_level']??'public')==='member'): ?><span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded">🔒 Premium</span><?php endif; ?>
              <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars($r['cat_name']??'Unknown') ?></span>
              <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank" class="text-[10px] text-blue-500 hover:underline truncate max-w-[150px]"><?= htmlspecialchars($r['url']) ?></a>
            </div>
            <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($r['title']) ?></p>
            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($r['description']) ?></p>
          </div>
          <div class="flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_feat"><input type="hidden" name="res_id" value="<?= $r['id'] ?>">
              <button type="submit" class="w-full text-left px-2 py-1 text-[10px] font-semibold <?= $r['is_featured']?'bg-gray-100 text-gray-600':'bg-amber-50 text-amber-600' ?> rounded"><?= $r['is_featured']?'Unfeature':'Feature' ?></button>
            </form>
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_access"><input type="hidden" name="res_id" value="<?= $r['id'] ?>">
              <button type="submit" class="w-full text-left px-2 py-1 text-[10px] font-semibold <?= ($r['access_level']??'public')==='member' ? 'bg-gray-100 text-gray-600' : 'bg-amber-50 text-amber-600' ?> rounded"><?= ($r['access_level']??'public')==='member' ? 'Make Public' : 'Make Premium' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this resource?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_res"><input type="hidden" name="res_id" value="<?= $r['id'] ?>">
              <button type="submit" class="w-full text-left px-2 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?php }, 'resources', 'Resources');
