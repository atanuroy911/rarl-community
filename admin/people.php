<?php
/**
 * RARL Admin — People & Leadership
 * The public "Leadership & chapters" page: RARL's president, officers and
 * other featured people. Drag cards to set the order they appear in.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reorder') {
        // Sent by the drag-and-drop list as ids in their new order.
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $st = $pdo->prepare('UPDATE people SET display_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) $st->execute([($i + 1) * 10, $id]);
        header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'add_person' || $action === 'update_person') {
        $id          = (int)($_POST['person_id'] ?? 0);
        $name        = clean($_POST['name'] ?? '');
        $designation = clean($_POST['designation'] ?? '');
        $email       = cleanEmail($_POST['email'] ?? '');
        $linkedin    = cleanUrl($_POST['linkedin_url'] ?? '');
        $scholar     = cleanUrl($_POST['google_scholar_url'] ?? '');
        $pub         = !empty($_POST['is_published']) ? 1 : 0;

        if ($name === '') {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'A name is required.'];
            header('Location: people.php' . ($id ? '?edit=' . $id : '')); exit;
        }
        $photo = null;
        if (!empty($_FILES['photo']['name'])) {
            $photo = validateUpload($_FILES['photo'], ['jpg', 'jpeg', 'png', 'webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/people');
            if (!$photo) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'The photo must be a JPG, PNG or WebP under 3 MB. Nothing was saved.'];
                header('Location: people.php' . ($id ? '?edit=' . $id : '')); exit;
            }
        }

        if ($action === 'add_person') {
            // New people go to the end of the list; drag to move them.
            $order = (int)$pdo->query('SELECT COALESCE(MAX(display_order), 0) + 10 FROM people')->fetchColumn();
            $pdo->prepare('INSERT INTO people (name, designation, email, linkedin_url, google_scholar_url, photo_path, display_order, is_published) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $designation, $email, $linkedin, $scholar, $photo, $order, $pub]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $name . ' added' . ($pub ? ' and visible on the public page.' : ' as hidden.')];
        } elseif ($id) {
            $sql = 'UPDATE people SET name=?, designation=?, email=?, linkedin_url=?, google_scholar_url=?, is_published=?' . ($photo ? ', photo_path=?' : '') . ' WHERE id=?';
            $params = [$name, $designation, $email, $linkedin, $scholar, $pub];
            if ($photo) $params[] = $photo;
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Changes to ' . $name . ' saved.'];
        }
        header('Location: people.php'); exit;
    }

    if ($action === 'delete_person') {
        $pdo->prepare('DELETE FROM people WHERE id=?')->execute([(int)($_POST['person_id'] ?? 0)]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Person removed.'];
        header('Location: people.php'); exit;
    }
    if ($action === 'toggle_pub') {
        $pdo->prepare('UPDATE people SET is_published = 1 - is_published WHERE id=?')->execute([(int)($_POST['person_id'] ?? 0)]);
        header('Location: people.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $op = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $in = implode(',', $ids);
            $sql = ['publish' => "UPDATE people SET is_published=1 WHERE id IN ($in)", 'unpublish' => "UPDATE people SET is_published=0 WHERE id IN ($in)", 'delete' => "DELETE FROM people WHERE id IN ($in)"][$op] ?? null;
            if ($sql) $pdo->exec($sql);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => count($ids) . ' updated.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Select at least one person first.'];
        }
        header('Location: people.php'); exit;
    }
}

$people = $pdo->query('SELECT * FROM people ORDER BY display_order, id')->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM people WHERE id = ?'); $q->execute([(int)$_GET['edit']]);
    $edit = $q->fetch() ?: null;
}
$chapterCount = count(chapterLeaders());

adminWrap(function () use ($people, $edit, $chapterCount) {
    adminFlash();
    $photoUrl = fn($p) => !empty($p['photo_path']) ? UPLOADS_URL . '/people/' . $p['photo_path'] : null;
    $published = count(array_filter($people, fn($p) => $p['is_published']));
    ?>
<div class="rarl-page-head">
  <div>
    <h1>People &amp; Leadership</h1>
    <p>The people shown on the public <a href="../people.php" target="_blank" class="underline">Leadership &amp; chapters</a> page. Chapter leaders (<?= $chapterCount ?>) are listed there automatically from <a href="sections.php" class="underline">Chapters</a>.</p>
  </div>
  <div class="flex gap-2">
    <a href="../people.php" target="_blank" class="rarl-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> View public page</a>
    <a href="people.php?new=1" class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-plus"></i> Add person</a>
  </div>
</div>

<dialog class="rarl-amodal" id="person-modal" aria-labelledby="person-modal-t" style="--modal-w:860px"<?= ($edit || !empty($_GET['new'])) ? ' data-autoopen' : '' ?>>
  <form method="POST" enctype="multipart/form-data" class="rarl-amodal-form" data-no-autolabel>
    <?= acsrfField() ?>
    <input type="hidden" name="action" value="<?= $edit ? 'update_person' : 'add_person' ?>">
    <?php if ($edit): ?><input type="hidden" name="person_id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="rarl-amodal-head"><div class="flex-1 min-w-0">
      <h2 id="person-modal-t"><?= $edit ? 'Edit ' . ex($edit['name']) : 'Add a person' ?></h2>
      <p><?= $edit ? 'Changes go live on the public page as soon as you save.' : 'New people are added to the end of the list; drag to reorder.' ?></p>
    </div><button type="button" class="rarl-icon-btn" data-amodal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="rarl-amodal-body grid md:grid-cols-[minmax(0,1fr)_260px] gap-6 items-start">
    <div class="space-y-4">

    <div>
      <span class="rarl-label">Photo</span>
      <?= adminImageDrop('photo', $edit ? $photoUrl($edit) : null, 'Square headshot works best · JPG, PNG or WebP · up to 3 MB') ?>
    </div>
    <div>
      <label class="rarl-label" for="p-name">Full name <span class="text-rarl-red">*</span></label>
      <input id="p-name" name="name" required class="rarl-input" value="<?= ex($edit['name'] ?? '') ?>" placeholder="Dr. Jane Smith" data-live="name"/>
    </div>
    <div>
      <label class="rarl-label" for="p-role">Role or title</label>
      <input id="p-role" name="designation" class="rarl-input" value="<?= ex($edit['designation'] ?? '') ?>" placeholder="RARL President" data-live="role"/>
    </div>
    <div>
      <label class="rarl-label" for="p-email">Public email</label>
      <input id="p-email" name="email" type="email" class="rarl-input" value="<?= ex($edit['email'] ?? '') ?>" placeholder="name@rarl-lab.com" data-live="email"/>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="rarl-label" for="p-li"><i class="fa-brands fa-linkedin text-[#0a66c2]"></i> LinkedIn</label><input id="p-li" name="linkedin_url" type="url" class="rarl-input" value="<?= ex($edit['linkedin_url'] ?? '') ?>" placeholder="linkedin.com/in/…" data-live="li"/></div>
      <div><label class="rarl-label" for="p-gs"><i class="fa-solid fa-graduation-cap"></i> Google Scholar</label><input id="p-gs" name="google_scholar_url" type="url" class="rarl-input" value="<?= ex($edit['google_scholar_url'] ?? '') ?>" placeholder="scholar.google.com/…" data-live="gs"/></div>
    </div>
    <label class="flex items-center justify-between gap-3 p-3 rounded-[var(--radius)] border border-[color:var(--border)] cursor-pointer">
      <span><span class="block text-sm font-semibold">Show on the public page</span><span class="block text-xs text-[color:var(--muted-fg)]">Turn off to keep someone here without showing them.</span></span>
      <input type="checkbox" name="is_published" value="1" <?= ($edit['is_published'] ?? 1) ? 'checked' : '' ?> class="accent-rarl-red w-5 h-5">
    </label>

    </div>
    <div class="md:sticky md:top-0">
      <span class="rarl-label">Preview</span>
      <div class="rounded-[calc(var(--radius)+4px)] border border-[color:var(--border)] p-5 text-center bg-[color:var(--bg)]">
        <div id="pv-photo" class="w-20 h-20 rounded-full mx-auto mb-3 overflow-hidden bg-rarl-red text-white flex items-center justify-center text-2xl font-bold"><?php if ($edit && $photoUrl($edit)): ?><img src="<?= ex($photoUrl($edit)) ?>" alt="" class="w-full h-full object-cover"><?php else: ?><?= ex(mb_strtoupper(mb_substr($edit['name'] ?? '?', 0, 1))) ?><?php endif; ?></div>
        <p id="pv-name" class="font-heading font-bold"><?= ex($edit['name'] ?? 'Full name') ?></p>
        <p id="pv-role" class="text-sm text-[color:var(--muted-fg)]"><?= ex($edit['designation'] ?? 'Role or title') ?></p>
        <div id="pv-links" class="flex justify-center gap-2 mt-3 text-[color:var(--muted-fg)] text-sm"></div>
      </div>
    </div>

    </div>
    <div class="rarl-amodal-foot">
      <?php if ($edit): ?><button type="submit" form="del-person" class="rarl-btn text-red-600 me-auto"><i class="fa-solid fa-trash"></i> Remove</button><?php endif; ?>
      <button type="button" class="rarl-btn" data-amodal-close>Cancel</button>
      <button type="submit" class="rarl-btn rarl-btn-primary"><?= $edit ? 'Save changes' : '<i class="fa-solid fa-plus"></i> Add person' ?></button>
    </div>
  </form>
</dialog>
  <?php if ($edit): ?>
  <form id="del-person" method="POST" class="hidden" data-confirm="Remove <?= ex($edit['name']) ?> from the leadership page?"><?= acsrfField() ?><input type="hidden" name="action" value="delete_person"><input type="hidden" name="person_id" value="<?= (int)$edit['id'] ?>"></form>
  <?php endif; ?>

  <!-- List -->
  <div class="rarl-card overflow-hidden">
    <div class="px-5 py-4 border-b border-[color:var(--border)] flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="rarl-card-title">On the leadership page <span class="font-normal text-[color:var(--muted-fg)]"><?= $published ?> shown · <?= count($people) - $published ?> hidden</span></h2>
        <?php if (count($people) > 1): ?><p class="text-xs text-[color:var(--muted-fg)] mt-0.5"><i class="fa-solid fa-grip-vertical"></i> Drag to change the order on the public page.</p><?php endif; ?>
      </div>
      <?php if ($people): ?><label class="flex items-center gap-1.5 text-xs text-[color:var(--muted-fg)]"><?= bulkSelectAllCheckbox() ?> Select all</label><?php endif; ?>
    </div>
    <?php if (!$people): ?>
    <div class="rarl-empty"><i class="fa-solid fa-people-group text-3xl mb-3 block"></i><p class="font-semibold text-[color:var(--fg)]">No one here yet</p><p class="mt-1">Add RARL's president and officers using the form. They appear on the public page in the order you set here.</p></div>
    <?php else: ?>
    <?= bulkFormOpen() ?>
    <?= bulkBar([
        ['label' => 'Show', 'op' => 'publish', 'class' => 'bg-green-600 hover:bg-green-500'],
        ['label' => 'Hide', 'op' => 'unpublish', 'class' => 'bg-gray-600 hover:bg-gray-500'],
        ['label' => 'Remove', 'op' => 'delete', 'class' => 'bg-red-600 hover:bg-red-500', 'confirm' => 'Remove all selected people?'],
    ]) ?>
    <ul id="people-list" class="divide-y divide-[color:var(--border)]">
      <?php foreach ($people as $p): $url = $photoUrl($p); ?>
      <li draggable="true" data-id="<?= (int)$p['id'] ?>" class="flex items-center gap-3 px-4 py-3 bg-white <?= $edit && (int)$edit['id'] === (int)$p['id'] ? 'bg-red-50/50' : '' ?> <?= $p['is_published'] ? '' : 'opacity-60' ?>">
        <span class="cursor-grab text-[color:var(--muted-fg)] px-1" title="Drag to reorder" aria-hidden="true"><i class="fa-solid fa-grip-vertical"></i></span>
        <?= bulkRowCheckbox((int)$p['id']) ?>
        <?php if ($url): ?><img src="<?= ex($url) ?>" alt="" class="w-11 h-11 rounded-full object-cover flex-shrink-0"/>
        <?php else: ?><span class="w-11 h-11 rounded-full bg-rarl-red text-white flex items-center justify-center font-bold flex-shrink-0"><?= ex(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span><?php endif; ?>
        <a href="people.php?edit=<?= (int)$p['id'] ?>" class="flex-1 min-w-0">
          <span class="block font-semibold text-sm truncate"><?= ex($p['name']) ?></span>
          <span class="block text-xs text-[color:var(--muted-fg)] truncate"><?= ex($p['designation'] ?: 'No role set') ?></span>
        </a>
        <span class="hidden sm:flex gap-1.5 text-[color:var(--muted-fg)] text-sm">
          <?php if ($p['email']): ?><i class="fa-solid fa-envelope" title="Email"></i><?php endif; ?>
          <?php if ($p['linkedin_url']): ?><i class="fa-brands fa-linkedin" title="LinkedIn"></i><?php endif; ?>
          <?php if ($p['google_scholar_url']): ?><i class="fa-solid fa-graduation-cap" title="Google Scholar"></i><?php endif; ?>
          <?php if (!$url): ?><span class="rarl-badge rarl-badge-amber">No photo</span><?php endif; ?>
        </span>
        <span class="rarl-badge <?= $p['is_published'] ? 'rarl-badge-green' : 'rarl-badge-gray' ?>"><?= $p['is_published'] ? 'Shown' : 'Hidden' ?></span>
        <a href="people.php?edit=<?= (int)$p['id'] ?>" class="rarl-icon-btn" title="Edit"><i class="fa-solid fa-pen"></i></a>
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_pub"><input type="hidden" name="person_id" value="<?= (int)$p['id'] ?>"><button class="rarl-icon-btn" title="<?= $p['is_published'] ? 'Hide from public page' : 'Show on public page' ?>" data-no-loading><i class="fa-solid <?= $p['is_published'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

<script>
  document.getElementById('person-modal').addEventListener('close', function () { if (/[?&](edit|new)=/.test(location.search)) history.replaceState(null, '', 'people.php'); });
(function () {
  // Live preview of the public card.
  var $ = function (s) { return document.querySelector(s); };
  var name = $('[data-live=name]'), role = $('[data-live=role]'), photo = $('#pv-photo'), links = $('#pv-links');
  function sync() {
    $('#pv-name').textContent = name.value.trim() || 'Full name';
    $('#pv-role').textContent = role.value.trim() || 'Role or title';
    if (!photo.querySelector('img')) photo.textContent = (name.value.trim()[0] || '?').toUpperCase();
    links.innerHTML = ($('[data-live=email]').value ? '<i class="fa-solid fa-envelope"></i>' : '') + ($('[data-live=li]').value ? '<i class="fa-brands fa-linkedin"></i>' : '') + ($('[data-live=gs]').value ? '<i class="fa-solid fa-graduation-cap"></i>' : '');
  }
  document.querySelectorAll('[data-live]').forEach(function (el) { el.addEventListener('input', sync); });
  var file = document.querySelector('input[name=photo]');
  if (file) file.addEventListener('change', function () { if (file.files[0]) photo.innerHTML = '<img src="' + URL.createObjectURL(file.files[0]) + '" alt="" class="w-full h-full object-cover">'; });
  sync();

  // Drag to reorder; saves immediately.
  var list = document.getElementById('people-list'); if (!list) return;
  var dragging = null;
  list.addEventListener('dragstart', function (e) { dragging = e.target.closest('li'); dragging.classList.add('opacity-40'); e.dataTransfer.effectAllowed = 'move'; });
  list.addEventListener('dragover', function (e) {
    e.preventDefault(); var over = e.target.closest('li'); if (!over || over === dragging) return;
    var r = over.getBoundingClientRect(); list.insertBefore(dragging, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
  });
  list.addEventListener('dragend', function () {
    dragging.classList.remove('opacity-40'); dragging = null;
    var fd = new FormData(); fd.append('action', 'reorder'); fd.append('acsrf', <?= json_encode($GLOBALS['acsrf']) ?>);
    list.querySelectorAll('li[data-id]').forEach(function (li) { fd.append('ids[]', li.dataset.id); });
    fetch('people.php', {method: 'POST', body: fd}).then(function (r) { return r.json(); })
      .then(function () { window.rarlToast && rarlToast('Order saved', 'success'); })
      .catch(function () { window.rarlToast && rarlToast('Could not save the new order — refresh and try again', 'error'); });
  });
})();
</script>
<?= bulkBarScript() ?>
<?php }, 'people', 'People & Leadership');
