<?php
/**
 * RARL Admin — People / Leadership Directory
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_person' || $action === 'update_person') {
        $name       = clean($_POST['name'] ?? '');
        $designation= clean($_POST['designation'] ?? '');
        $email      = cleanEmail($_POST['email'] ?? '');
        $linkedin   = cleanUrl($_POST['linkedin_url'] ?? '');
        $scholar    = cleanUrl($_POST['google_scholar_url'] ?? '');
        $order      = (int)($_POST['display_order'] ?? 0);
        $pub        = !empty($_POST['is_published']) ? 1 : 0;

        $photoFilename = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoFilename = validateUpload($_FILES['photo'], ['jpg','jpeg','png','webp'], 3*1024*1024, UPLOADS_PATH . '/people');
        }

        if ($name) {
            if ($action === 'add_person') {
                $pdo->prepare("INSERT INTO people (name,designation,email,linkedin_url,google_scholar_url,photo_path,display_order,is_published)
                    VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name,$designation,$email,$linkedin,$scholar,$photoFilename,$order,$pub]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Person added.'];
            } else {
                $id = (int)($_POST['person_id'] ?? 0);
                if ($id) {
                    if ($photoFilename) {
                        $pdo->prepare("UPDATE people SET name=?,designation=?,email=?,linkedin_url=?,google_scholar_url=?,photo_path=?,display_order=?,is_published=? WHERE id=?")
                            ->execute([$name,$designation,$email,$linkedin,$scholar,$photoFilename,$order,$pub,$id]);
                    } else {
                        $pdo->prepare("UPDATE people SET name=?,designation=?,email=?,linkedin_url=?,google_scholar_url=?,display_order=?,is_published=? WHERE id=?")
                            ->execute([$name,$designation,$email,$linkedin,$scholar,$order,$pub,$id]);
                    }
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Person updated.'];
                }
            }
        }
        header('Location: people.php'); exit;
    }

    if ($action === 'delete_person') {
        $pdo->prepare("DELETE FROM people WHERE id=?")->execute([(int)($_POST['person_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Person deleted.'];
        header('Location: people.php'); exit;
    }
    if ($action === 'toggle_pub') {
        $pdo->prepare("UPDATE people SET is_published = 1 - is_published WHERE id=?")->execute([(int)($_POST['person_id']??0)]);
        header('Location: people.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'publish') { $pdo->exec("UPDATE people SET is_published=1 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' person/people published.']; }
            elseif ($bulkOp === 'unpublish') { $pdo->exec("UPDATE people SET is_published=0 WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' person/people unpublished.']; }
            elseif ($bulkOp === 'delete') { $pdo->exec("DELETE FROM people WHERE id IN ({$inClause})"); $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids).' person/people deleted.']; }
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one person first.'];
        }
        header('Location: people.php'); exit;
    }
}

$people = $pdo->query("SELECT * FROM people ORDER BY display_order, id")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
if ($editId) {
    $q = $pdo->prepare("SELECT * FROM people WHERE id=?");
    $q->execute([$editId]);
    $edit = $q->fetch();
}

adminWrap(function() use ($people, $edit) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">People &amp; Leadership</h1>
<p class="text-gray-500 text-sm mb-7">Photo-led directory of president, chairs, and other featured people shown on the public People page.</p>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Left: form -->
  <div class="xl:col-span-1 space-y-6">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4"><?= $edit ? '<i class="fa-solid fa-pen-to-square"></i> Edit Person' : '<i class="fa-solid fa-plus"></i> Add Person' ?></h2>
      <form method="POST" enctype="multipart/form-data" class="space-y-3">
        <?= acsrfField() ?>
        <input type="hidden" name="action" value="<?= $edit ? 'update_person' : 'add_person' ?>">
        <?php if ($edit): ?><input type="hidden" name="person_id" value="<?= $edit['id'] ?>"><?php endif; ?>

        <?php if ($edit && !empty($edit['photo_path'])): ?>
        <img src="<?= UPLOADS_URL ?>/people/<?= htmlspecialchars($edit['photo_path']) ?>" alt="" class="w-16 h-16 rounded-full object-cover border border-gray-200"/>
        <?php endif; ?>
        <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        <p class="text-[10px] text-gray-400">JPG/PNG/WebP, max 3MB. Leave blank on edit to keep the current photo.</p>

        <input type="text" name="name" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>" placeholder="Full Name"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <input type="text" name="designation" value="<?= htmlspecialchars($edit['designation'] ?? '') ?>" placeholder="Designation (e.g. RARL President)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <input type="email" name="email" value="<?= htmlspecialchars($edit['email'] ?? '') ?>" placeholder="Email (optional)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        <input type="url" name="linkedin_url" value="<?= htmlspecialchars($edit['linkedin_url'] ?? '') ?>" placeholder="LinkedIn URL (optional)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        <input type="url" name="google_scholar_url" value="<?= htmlspecialchars($edit['google_scholar_url'] ?? '') ?>" placeholder="Google Scholar URL (optional)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>

        <input type="number" name="display_order" value="<?= htmlspecialchars($edit['display_order'] ?? 0) ?>" placeholder="Order"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>

        <label class="flex items-center gap-2 p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer">
          <input type="checkbox" name="is_published" value="1" <?= ($edit['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-3.5 h-3.5"/>
          <span class="text-xs text-gray-600">Published</span>
        </label>

        <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl transition-colors"><?= $edit ? 'Save Changes' : 'Add Person' ?></button>
        <?php if ($edit): ?><a href="people.php" class="block text-center text-xs text-gray-400 hover:text-gray-600 mt-1">Cancel edit</a><?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Right: list -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
        <h2 class="font-heading font-bold text-sm text-gray-800">All People <span class="text-gray-400 font-normal text-xs">(<?= count($people) ?>)</span></h2>
        <?php if ($people): ?><label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
      </div>
      <?php if ($people): ?>
      <?= bulkFormOpen() ?>
      <?= bulkBar([
          ['label'=>'Publish','op'=>'publish','class'=>'bg-green-600 hover:bg-green-500'],
          ['label'=>'Unpublish','op'=>'unpublish','class'=>'bg-amber-600 hover:bg-amber-500'],
          ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected people?'],
      ]) ?>
      <?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php if (empty($people)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No people added yet.</p>
        <?php endif; ?>
        <?php foreach ($people as $p): ?>
        <div class="p-4 flex items-center gap-4 hover:bg-gray-50 group">
          <?= bulkRowCheckbox((int)$p['id']) ?>
          <?php if (!empty($p['photo_path'])): ?>
          <img src="<?= UPLOADS_URL ?>/people/<?= htmlspecialchars($p['photo_path']) ?>" alt="" class="w-10 h-10 rounded-full object-cover flex-shrink-0"/>
          <?php else: ?>
          <div class="w-10 h-10 bg-rarl-red text-white rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0"><?= htmlspecialchars(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></div>
          <?php endif; ?>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <?php if (!$p['is_published']): ?><span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded">Draft</span><?php endif; ?>
            </div>
            <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($p['name']) ?></p>
            <p class="text-xs text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($p['designation']) ?></p>
          </div>
          <div class="flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
            <a href="people.php?edit=<?= $p['id'] ?>" class="px-2 py-1 text-[10px] font-semibold bg-blue-50 text-blue-600 rounded text-center">Edit</a>
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pub"><input type="hidden" name="person_id" value="<?= $p['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold <?= $p['is_published']?'bg-gray-100 text-gray-600':'bg-green-50 text-green-600' ?> rounded"><?= $p['is_published']?'Unpublish':'Publish' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this person?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_person"><input type="hidden" name="person_id" value="<?= $p['id'] ?>">
              <button type="submit" class="w-full px-2 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?= bulkBarScript() ?>
<?php }, 'people', 'People & Leadership');
