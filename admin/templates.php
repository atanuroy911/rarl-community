<?php
/**
 * RARL Admin — ID Card / Certificate Templates
 * Upload a background image, then visually place text/QR/photo/signature fields
 * in the designer. The saved config drives both the preview and the actual PDF
 * output (renderTemplateHtml / renderTemplatePdf in functions.php), so what you
 * design here is what gets generated and emailed.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$FIELD_KEYS = [
    'certificate' => ['name'=>'Recipient Name','event'=>'Event Title','date'=>'Date','cert_no'=>'Certificate No.','qr'=>'QR Code (verify link)'],
    'id_card'     => ['name'=>'Member Name','member_code'=>'Member ID','since_date'=>'Member Since','section'=>'Chapter/Section','signer1'=>'Signer 1','signer2'=>'Signer 2 (Chair)','qr'=>'QR Code (verify link)','avatar'=>'Member Photo'],
    'membership'  => ['name'=>'Member Name','member_code'=>'Member ID','since_date'=>'Member Since','section'=>'Chapter/Section','cert_no'=>'Certificate No.','signer1'=>'Signer 1 (President)','qr'=>'QR Code (verify link)'],
];
$TYPE_LABELS = ['certificate'=>'Event Certificate','id_card'=>'ID Card','membership'=>'Certificate of Membership'];
$SIZE_PRESETS = [
    'a4l'     => ['A4 landscape', 297, 210],
    'a4p'     => ['A4 portrait', 210, 297],
    'letterl' => ['US Letter landscape', 279.4, 215.9],
    'letterp' => ['US Letter portrait', 215.9, 279.4],
    'cardl'   => ['ID card horizontal (86 × 54 mm)', 86, 54],
    'cardp'   => ['ID card vertical (54 × 86 mm)', 54, 86],
];

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
function tplJson(array $payload): void { header('Content-Type: application/json'); echo json_encode($payload); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminCsrfOk()) {
        if ($isAjax) tplJson(['ok'=>false,'error'=>'Your session expired — reload the page.']);
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Session expired — please try again.'];
        header('Location: templates.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_template') {
        $name = clean($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['id_card','membership'], true) ? $_POST['type'] : 'certificate';
        $size = $_POST['size'] ?? '';
        if (isset($SIZE_PRESETS[$size])) { [, $pw, $ph] = $SIZE_PRESETS[$size]; }
        elseif ($size === 'custom') {
            $pw = max(20, min(1000, (float)($_POST['custom_w'] ?? 0)));
            $ph = max(20, min(1000, (float)($_POST['custom_h'] ?? 0)));
        } else { [$pw, $ph] = $type === 'id_card' ? [86.0, 54.0] : [297.0, 210.0]; }

        if (!$name || empty($_FILES['background']['tmp_name'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Name and background image are required.'];
            header('Location: templates.php'); exit;
        }
        $filename = validateUpload($_FILES['background'], ['jpg','jpeg','png','webp'], 8*1024*1024, UPLOADS_PATH.'/templates');
        if (!$filename) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Image upload failed (check file type/size, max 8MB).'];
            header('Location: templates.php'); exit;
        }
        $pdo->prepare("INSERT INTO certificate_templates (name,type,background_path,config,page_width_mm,page_height_mm) VALUES (?,?,?,?,?,?)")
            ->execute([$name, $type, $filename, '[]', $pw, $ph]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Template created — now place your fields.'];
        header('Location: templates.php?edit=' . $pdo->lastInsertId()); exit;
    }

    if ($action === 'upload_field_image') {
        $filename = validateUpload($_FILES['field_image'] ?? [], ['jpg','jpeg','png','webp'], 3*1024*1024, UPLOADS_PATH.'/templates');
        tplJson($filename ? ['ok'=>true,'filename'=>$filename] : ['ok'=>false,'error'=>'Upload failed — jpg/png/webp, max 3MB.']);
    }

    if ($action === 'replace_background') {
        $id = (int)($_POST['template_id'] ?? 0);
        $filename = validateUpload($_FILES['background'] ?? [], ['jpg','jpeg','png','webp'], 8*1024*1024, UPLOADS_PATH.'/templates');
        if (!$filename) tplJson(['ok'=>false,'error'=>'Upload failed — jpg/png/webp, max 8MB.']);
        $old = $pdo->prepare("SELECT background_path FROM certificate_templates WHERE id = ?"); $old->execute([$id]); $oldBg = $old->fetchColumn();
        $pdo->prepare("UPDATE certificate_templates SET background_path = ? WHERE id = ?")->execute([$filename, $id]);
        if ($oldBg) {
            $still = $pdo->prepare("SELECT COUNT(*) FROM certificate_templates WHERE background_path = ?"); $still->execute([$oldBg]);
            if (!(int)$still->fetchColumn()) @unlink(UPLOADS_PATH . '/templates/' . basename($oldBg));
        }
        tplJson(['ok'=>true,'filename'=>$filename]);
    }

    if ($action === 'save_config') {
        $id = (int)($_POST['template_id'] ?? 0);
        $config = $_POST['config_json'] ?? '[]';
        $decoded = json_decode($config, true);
        $name = clean($_POST['name'] ?? '');
        if (!is_array($decoded)) {
            if ($isAjax) tplJson(['ok'=>false,'error'=>'Invalid field config — not saved.']);
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Invalid field config — not saved.'];
            header('Location: templates.php?edit=' . $id); exit;
        }
        // Editor-only state (selection etc.) never reaches the stored config.
        $decoded = array_values(array_map(fn($f) => array_diff_key((array)$f, ['_id'=>1]), $decoded));
        $pdo->prepare("UPDATE certificate_templates SET config = ? WHERE id = ?")->execute([json_encode($decoded), $id]);
        if ($name) $pdo->prepare("UPDATE certificate_templates SET name = ? WHERE id = ?")->execute([$name, $id]);
        if ($isAjax) tplJson(['ok'=>true,'saved_at'=>date('H:i')]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Field layout saved.'];
        header('Location: templates.php?edit=' . $id); exit;
    }

    if ($action === 'duplicate_template') {
        $id = (int)($_POST['template_id'] ?? 0);
        $s = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ?"); $s->execute([$id]);
        if ($t = $s->fetch()) {
            $pdo->prepare("INSERT INTO certificate_templates (name,type,background_path,config,page_width_mm,page_height_mm) VALUES (?,?,?,?,?,?)")
                ->execute([$t['name'] . ' (copy)', $t['type'], $t['background_path'], $t['config'], $t['page_width_mm'], $t['page_height_mm']]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Template duplicated.'];
            header('Location: templates.php?edit=' . $pdo->lastInsertId()); exit;
        }
        header('Location: templates.php'); exit;
    }

    if ($action === 'set_default') {
        $id = (int)($_POST['template_id'] ?? 0);
        $t  = $pdo->prepare("SELECT type FROM certificate_templates WHERE id = ?"); $t->execute([$id]); $type = $t->fetchColumn();
        if ($type) {
            $pdo->prepare("UPDATE certificate_templates SET is_default = 0 WHERE type = ?")->execute([$type]);
            $pdo->prepare("UPDATE certificate_templates SET is_default = 1 WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Set as default ' . ($TYPE_LABELS[$type] ?? $type) . ' template.'];
        }
        header('Location: ' . (!empty($_POST['return_edit']) ? 'templates.php?edit=' . $id : 'templates.php')); exit;
    }

    if ($action === 'delete_template') {
        $id = (int)($_POST['template_id'] ?? 0);
        $row = $pdo->prepare("SELECT background_path FROM certificate_templates WHERE id = ?"); $row->execute([$id]);
        $bg = $row->fetchColumn();
        $pdo->prepare("DELETE FROM certificate_templates WHERE id = ?")->execute([$id]);
        // Duplicated templates share one background file — only remove it once unused.
        if ($bg) {
            $still = $pdo->prepare("SELECT COUNT(*) FROM certificate_templates WHERE background_path = ?"); $still->execute([$bg]);
            if (!(int)$still->fetchColumn()) @unlink(UPLOADS_PATH . '/templates/' . basename($bg));
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Template deleted.'];
        header('Location: templates.php'); exit;
    }
}

$templates = $pdo->query("SELECT * FROM certificate_templates ORDER BY type, is_default DESC, id DESC")->fetchAll();
$usage = [];
try {
    foreach ($pdo->query("SELECT template_id, COUNT(*) n FROM certificates WHERE template_id IS NOT NULL GROUP BY template_id") as $r) $usage[(int)$r['template_id']] = (int)$r['n'];
} catch (Throwable $e) {}
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $e = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ?"); $e->execute([$editId]); $edit = $e->fetch();
}

adminWrap(function() use ($templates, $edit, $usage, $FIELD_KEYS, $TYPE_LABELS, $SIZE_PRESETS) {
    adminFlash();
    if (!$edit):
      $counts = array_count_values(array_column($templates, 'type'));
?>
<div class="flex flex-wrap items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-black text-gray-900 mb-1">Templates</h1>
    <p class="text-gray-500 text-sm">Design the certificates and ID cards members receive. What you see in the designer is exactly what gets generated.</p>
  </div>
  <button type="button" onclick="document.getElementById('new-tpl').showModal()" class="px-4 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl shadow-sm"><i class="fa-solid fa-plus"></i> New template</button>
</div>

<div class="flex flex-wrap gap-2 mb-5" id="tpl-tabs">
  <?php foreach (['all' => 'All'] + $TYPE_LABELS as $k => $label): $n = $k === 'all' ? count($templates) : ($counts[$k] ?? 0); ?>
  <button type="button" data-tab="<?= $k ?>" class="tpl-tab px-3.5 py-1.5 rounded-full text-xs font-semibold border transition-colors <?= $k === 'all' ? 'bg-gray-900 text-white border-gray-900' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400' ?>"><?= htmlspecialchars($label) ?> <span class="opacity-60"><?= $n ?></span></button>
  <?php endforeach; ?>
</div>

<?php if (empty($templates)): ?>
<div class="bg-white border-2 border-dashed border-gray-200 rounded-2xl p-12 text-center">
  <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-2xl"><i class="fa-solid fa-pen-ruler"></i></div>
  <p class="font-heading font-bold text-gray-800">No templates yet</p>
  <p class="text-sm text-gray-500 mt-1 mb-4">Upload your certificate or ID card artwork, then drag fields onto it.</p>
  <button type="button" onclick="document.getElementById('new-tpl').showModal()" class="px-4 py-2 bg-rarl-red text-white text-sm font-semibold rounded-xl">Create your first template</button>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5" id="tpl-grid">
  <?php foreach ($templates as $t): $nFields = count(json_decode($t['config'] ?: '[]', true) ?: []); $used = $usage[(int)$t['id']] ?? 0; ?>
  <div class="tpl-card group bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow flex flex-col" data-type="<?= htmlspecialchars($t['type']) ?>">
    <a href="templates.php?edit=<?= $t['id'] ?>" class="block relative bg-[repeating-conic-gradient(#f3f4f6_0_25%,#fff_0_50%)] bg-[length:16px_16px] p-4">
      <div class="mx-auto pointer-events-none" style="max-width:<?= $t['page_width_mm'] >= $t['page_height_mm'] ? '100%' : '55%' ?>">
        <?= renderTemplateHtml($t, templateSampleData($t['type'])) ?>
      </div>
      <span class="absolute inset-0 bg-gray-900/0 group-hover:bg-gray-900/40 transition-colors flex items-center justify-center">
        <span class="opacity-0 group-hover:opacity-100 transition-opacity px-4 py-2 bg-white rounded-xl text-sm font-semibold text-gray-900 shadow"><i class="fa-solid fa-pen-ruler"></i> Open designer</span>
      </span>
    </a>
    <div class="p-4 flex-1 flex flex-col">
      <div class="flex items-start gap-2 mb-1">
        <p class="font-semibold text-sm text-gray-900 flex-1 min-w-0 truncate" title="<?= htmlspecialchars($t['name']) ?>"><?= htmlspecialchars($t['name']) ?></p>
        <?php if ($t['is_default']): ?><span class="text-[10px] font-bold text-green-700 bg-green-50 border border-green-200 px-2 py-0.5 rounded-full flex-shrink-0"><i class="fa-solid fa-star"></i> Default</span><?php endif; ?>
      </div>
      <p class="text-xs text-gray-500"><?= htmlspecialchars($TYPE_LABELS[$t['type']] ?? $t['type']) ?> · <?= rtrim(rtrim(number_format((float)$t['page_width_mm'], 1), '0'), '.') ?>×<?= rtrim(rtrim(number_format((float)$t['page_height_mm'], 1), '0'), '.') ?> mm</p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $nFields ?> field<?= $nFields === 1 ? '' : 's' ?><?= $used ? " · used by {$used} certificate" . ($used === 1 ? '' : 's') : '' ?><?= $nFields === 0 ? ' · <span class="text-amber-600 font-semibold">needs fields</span>' : '' ?></p>
      <div class="flex flex-wrap gap-1.5 mt-auto pt-3">
        <a href="templates.php?edit=<?= $t['id'] ?>" class="px-3 py-1.5 text-xs font-semibold bg-gray-900 text-white rounded-lg hover:bg-gray-700">Design</a>
        <a href="preview-template.php?id=<?= $t['id'] ?>" class="px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"><i class="fa-solid fa-eye"></i> Preview</a>
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="duplicate_template"><input type="hidden" name="template_id" value="<?= $t['id'] ?>">
          <button type="submit" class="px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" title="Duplicate"><i class="fa-regular fa-copy"></i></button>
        </form>
        <?php if (!$t['is_default']): ?>
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="set_default"><input type="hidden" name="template_id" value="<?= $t['id'] ?>">
          <button type="submit" class="px-3 py-1.5 text-xs font-semibold bg-green-50 text-green-700 rounded-lg hover:bg-green-100" title="Use this template by default">Set default</button>
        </form>
        <?php endif; ?>
        <form method="POST" class="ml-auto" data-confirm="Delete “<?= htmlspecialchars($t['name']) ?>”? This cannot be undone." data-confirm-ok="Delete"><?= acsrfField() ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?= $t['id'] ?>">
          <button type="submit" class="px-2.5 py-1.5 text-xs font-semibold text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete"><i class="fa-solid fa-trash"></i></button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<dialog id="new-tpl" class="rarl-dialog w-full max-w-2xl">
  <form method="POST" enctype="multipart/form-data" class="p-6" id="new-tpl-form">
    <?= acsrfField() ?><input type="hidden" name="action" value="create_template">
    <div class="flex items-center justify-between mb-5">
      <h2 class="font-heading font-bold text-lg text-gray-900">New template</h2>
      <button type="button" onclick="this.closest('dialog').close()" class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
      <div class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Name</label>
          <input type="text" name="name" required placeholder="e.g. 2026 Gold Certificate" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Used for</label>
          <div class="grid grid-cols-1 gap-1.5" id="type-choices">
            <?php foreach (['certificate' => ['fa-trophy','Event certificate','Issued to event participants'], 'membership' => ['fa-award','Membership certificate','Issued when a member is approved'], 'id_card' => ['fa-id-card','Member ID card','Wallet card with photo & QR']] as $k => [$ic, $lbl, $sub]): ?>
            <label class="flex items-center gap-3 p-2.5 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-rarl-red/5">
              <input type="radio" name="type" value="<?= $k ?>" <?= $k === 'certificate' ? 'checked' : '' ?> class="accent-rarl-red"/>
              <i class="fa-solid <?= $ic ?> text-gray-500 w-4 text-center"></i>
              <span class="text-sm"><span class="font-semibold text-gray-800"><?= $lbl ?></span><span class="block text-[11px] text-gray-500"><?= $sub ?></span></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Page size</label>
          <select name="size" id="size-select" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm">
            <?php foreach ($SIZE_PRESETS as $k => [$lbl]): ?><option value="<?= $k ?>"><?= $lbl ?></option><?php endforeach; ?>
            <option value="custom">Custom size…</option>
          </select>
          <div id="custom-size" class="hidden grid grid-cols-2 gap-2 mt-2">
            <input type="number" step="0.1" name="custom_w" placeholder="Width mm" class="px-3 py-2 border border-gray-300 rounded-xl text-sm"/>
            <input type="number" step="0.1" name="custom_h" placeholder="Height mm" class="px-3 py-2 border border-gray-300 rounded-xl text-sm"/>
          </div>
          <p id="size-hint" class="hidden text-[11px] text-amber-600 mt-1.5"></p>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Background artwork</label>
        <label id="bg-drop" class="relative flex flex-col items-center justify-center text-center border-2 border-dashed border-gray-300 hover:border-rarl-red/60 rounded-2xl p-4 cursor-pointer min-h-[220px] bg-gray-50 transition-colors">
          <img id="bg-preview" class="hidden max-h-56 w-auto rounded-lg shadow" alt=""/>
          <span id="bg-empty">
            <i class="fa-solid fa-cloud-arrow-up text-3xl text-gray-400"></i>
            <span class="block text-sm font-semibold text-gray-700 mt-2">Drop image or click to browse</span>
            <span class="block text-[11px] text-gray-500 mt-0.5">JPG, PNG or WebP · max 8 MB · design at 300 DPI for crisp print</span>
          </span>
          <span id="bg-meta" class="hidden text-[11px] text-gray-500 mt-2"></span>
          <input type="file" name="background" id="bg-input" accept=".jpg,.jpeg,.png,.webp" required class="sr-only" data-no-chip/>
        </label>
      </div>
    </div>
    <div class="flex justify-end gap-2 mt-6">
      <button type="button" onclick="this.closest('dialog').close()" class="px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">Cancel</button>
      <button type="submit" class="px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl">Create & open designer →</button>
    </div>
  </form>
</dialog>

<script>
(function() {
  const tabs = document.querySelectorAll('.tpl-tab');
  tabs.forEach(tab => tab.addEventListener('click', () => {
    tabs.forEach(t => t.className = t.className.replace('bg-gray-900 text-white border-gray-900', 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'));
    tab.className = tab.className.replace('bg-white text-gray-600 border-gray-200 hover:border-gray-400', 'bg-gray-900 text-white border-gray-900');
    document.querySelectorAll('.tpl-card').forEach(c => c.classList.toggle('hidden', tab.dataset.tab !== 'all' && c.dataset.type !== tab.dataset.tab));
  }));

  const PRESETS = <?= json_encode(array_map(fn($p) => [$p[1], $p[2]], $SIZE_PRESETS)) ?>;
  const sizeSel = document.getElementById('size-select');
  const hint = document.getElementById('size-hint');
  let imgRatio = null;
  function syncSizeHint() {
    document.getElementById('custom-size').classList.toggle('hidden', sizeSel.value !== 'custom');
    const p = PRESETS[sizeSel.value];
    if (!imgRatio || !p) { hint.classList.add('hidden'); return; }
    const diff = Math.abs(imgRatio - p[0] / p[1]) / (p[0] / p[1]);
    hint.classList.toggle('hidden', diff < 0.03);
    hint.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Image shape differs from this page size by ' + Math.round(diff * 100) + '% — it will be stretched.';
  }
  sizeSel.addEventListener('change', syncSizeHint);
  document.querySelectorAll('#type-choices input').forEach(r => r.addEventListener('change', () => {
    if (r.value === 'id_card' && !sizeSel.value.startsWith('card')) sizeSel.value = imgRatio && imgRatio < 1 ? 'cardp' : 'cardl';
    if (r.value !== 'id_card' && sizeSel.value.startsWith('card')) sizeSel.value = imgRatio && imgRatio < 1 ? 'a4p' : 'a4l';
    syncSizeHint();
  }));

  const input = document.getElementById('bg-input'), drop = document.getElementById('bg-drop');
  function showFile(file) {
    if (!file) return;
    const url = URL.createObjectURL(file);
    const img = document.getElementById('bg-preview');
    img.onload = () => {
      imgRatio = img.naturalWidth / img.naturalHeight;
      const type = document.querySelector('#type-choices input:checked').value;
      // Pick the preset whose shape best matches the uploaded artwork.
      const family = type === 'id_card' ? ['cardl','cardp'] : ['a4l','a4p','letterl','letterp'];
      let best = family[0], bestDiff = 9;
      family.forEach(k => { const d = Math.abs(PRESETS[k][0] / PRESETS[k][1] - imgRatio); if (d < bestDiff) { bestDiff = d; best = k; } });
      sizeSel.value = best;
      const meta = document.getElementById('bg-meta');
      meta.textContent = file.name + ' · ' + img.naturalWidth + '×' + img.naturalHeight + ' px · ' + (file.size / 1048576).toFixed(1) + ' MB';
      meta.classList.remove('hidden');
      syncSizeHint();
    };
    img.src = url; img.classList.remove('hidden');
    document.getElementById('bg-empty').classList.add('hidden');
    const nameInput = document.querySelector('#new-tpl-form [name=name]');
    if (!nameInput.value) nameInput.value = file.name.replace(/\.[a-z]+$/i, '').replace(/[-_]+/g, ' ');
  }
  input.addEventListener('change', () => showFile(input.files[0]));
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('border-rarl-red','bg-rarl-red/5'); }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('border-rarl-red','bg-rarl-red/5'); }));
  drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) { input.files = e.dataTransfer.files; showFile(input.files[0]); } });
  if (location.hash === '#new') document.getElementById('new-tpl').showModal();
})();
</script>

<?php else:
  $keys   = $FIELD_KEYS[$edit['type']] ?? [];
  $config = json_decode($edit['config'] ?: '[]', true) ?: [];
  $sample = templateSampleData($edit['type']);
  unset($sample['verify_url'], $sample['avatar_url'], $sample['avatar_path']);
?>
<style>
  .tpl-shell{display:grid;grid-template-columns:1fr;gap:1rem}
  @media (min-width:1200px){.tpl-shell{grid-template-columns:240px minmax(0,1fr) 280px;height:calc(100vh - 150px)}.tpl-shell>aside{overflow-y:auto}}
  .tpl-panel{background:#fff;border:1px solid #e5e7eb;border-radius:1rem;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .tpl-h{font-size:10.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#6b7280}
  .tpl-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;height:32px;min-width:32px;padding:0 .55rem;border-radius:.6rem;font-size:12px;font-weight:600;color:#374151}
  .tpl-btn:hover:not(:disabled){background:#f3f4f6}
  .tpl-btn:disabled{opacity:.35;cursor:not-allowed}
  .tpl-btn.on{background:#111827;color:#fff}
  .tpl-input{width:100%;padding:.4rem .55rem;border:1px solid #d1d5db;border-radius:.55rem;font-size:12px;background:#fff}
  .tpl-input:focus{outline:none;border-color:<?= BRAND_RED ?>;box-shadow:0 0 0 3px rgba(225,29,42,.12)}
  .tpl-label{display:block;font-size:10.5px;font-weight:600;color:#6b7280;margin-bottom:3px}
  #stage{position:relative;overflow:auto;background:#e5e7eb;background-image:radial-gradient(#cbd5e1 1px,transparent 1px);background-size:18px 18px;border-radius:.9rem;min-height:420px}
  #stage-inner{display:flex;align-items:center;justify-content:center;min-width:100%;min-height:100%;padding:40px;box-sizing:border-box;width:max-content}
  #canvas{position:relative;flex-shrink:0;background:#fff;box-shadow:0 10px 40px rgba(15,23,42,.18);user-select:none;touch-action:none}
  #canvas>img.bg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
  .fld{position:absolute;cursor:move;white-space:nowrap;line-height:1.134}
  .fld.img{display:block}
  .fld.img>img{width:100%;height:100%;display:block;pointer-events:none}
  .fld::after{content:"";position:absolute;inset:-2px;border:1px dashed rgba(225,29,42,.0);border-radius:2px;pointer-events:none}
  #canvas:not(.clean) .fld::after{border-color:rgba(225,29,42,.45)}
  #canvas:not(.clean) .fld:hover::after{border-style:solid;border-color:rgba(37,99,235,.8)}
  .fld.sel::after{border:1.5px solid #2563eb !important;background:rgba(37,99,235,.06)}
  .fld.locked{cursor:default}
  .fld .maxw{position:absolute;top:-3px;bottom:-3px;border-left:1px dotted #f59e0b;border-right:1px dotted #f59e0b;pointer-events:none}
  .fld.overflow{color:#dc2626 !important}
  .hdl{position:absolute;width:10px;height:10px;background:#fff;border:1.5px solid #2563eb;border-radius:3px;z-index:5}
  .guide{position:absolute;background:#ec4899;pointer-events:none;z-index:20}
  .guide.v{width:1px;top:0;bottom:0}.guide.h{height:1px;left:0;right:0}
  #grid-ov{position:absolute;inset:0;pointer-events:none;display:none}
  #canvas.show-grid #grid-ov{display:block}
  #safe-ov{position:absolute;pointer-events:none;border:1px dashed rgba(16,185,129,.7);display:none}
  #canvas.show-safe #safe-ov{display:block}
  .layer{display:flex;align-items:center;gap:.45rem;padding:.4rem .5rem;border-radius:.55rem;font-size:12px;cursor:pointer;color:#374151}
  .layer:hover{background:#f3f4f6}
  .layer.sel{background:#eff6ff;color:#1d4ed8}
  .layer .lk{opacity:0;color:#9ca3af}.layer:hover .lk,.layer .lk.on{opacity:1}
  .add-btn{display:flex;align-items:center;gap:.55rem;width:100%;text-align:left;padding:.45rem .6rem;font-size:12px;font-weight:500;border-radius:.6rem;color:#374151;border:1px solid transparent}
  .add-btn:hover{background:#fef2f2;color:<?= BRAND_RED ?>;border-color:#fecaca}
  .add-btn .used{margin-left:auto;font-size:10px;color:#10b981}
  .seg{display:flex;background:#f3f4f6;border-radius:.6rem;padding:2px}
  .seg button{flex:1;height:28px;border-radius:.5rem;font-size:12px;color:#4b5563}
  .seg button.on{background:#fff;color:#111827;box-shadow:0 1px 2px rgba(0,0,0,.12)}
  .swatch{width:20px;height:20px;border-radius:6px;border:1px solid rgba(0,0,0,.12);cursor:pointer}
  .swatch:hover{transform:scale(1.12)}
  .chip{font-size:10.5px;padding:2px 7px;border-radius:999px;background:#f3f4f6;color:#374151;cursor:pointer;font-family:ui-monospace,monospace}
  .chip:hover{background:#fee2e2;color:#b91c1c}
  .kbd{font-family:ui-monospace,monospace;font-size:10px;padding:1px 5px;border:1px solid #d1d5db;border-bottom-width:2px;border-radius:4px;background:#fff;color:#374151}
  #ctx{position:fixed;z-index:60;min-width:190px;background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;box-shadow:0 12px 32px rgba(0,0,0,.16);padding:4px;display:none}
  #ctx button{display:flex;width:100%;align-items:center;gap:.6rem;padding:.45rem .6rem;font-size:12px;border-radius:.5rem;color:#374151;text-align:left}
  #ctx button:hover{background:#f3f4f6}
  #ctx button span{margin-left:auto;color:#9ca3af;font-size:10.5px}
  #ctx hr{margin:3px 0;border-color:#f3f4f6}
</style>

<!-- Top bar -->
<div class="flex flex-wrap items-center gap-2 mb-3">
  <a href="templates.php" class="tpl-btn bg-white border border-gray-200" title="Back to templates"><i class="fa-solid fa-arrow-left"></i></a>
  <div class="min-w-0 flex-1 flex items-center gap-2">
    <input id="tpl-name" value="<?= htmlspecialchars($edit['name']) ?>" class="font-heading font-black text-lg text-gray-900 bg-transparent border border-transparent hover:border-gray-300 focus:border-rarl-red focus:bg-white rounded-lg px-2 py-0.5 min-w-0 w-full max-w-md focus:outline-none" aria-label="Template name"/>
    <span class="hidden sm:inline text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full whitespace-nowrap"><?= htmlspecialchars($TYPE_LABELS[$edit['type']] ?? $edit['type']) ?> · <?= (float)$edit['page_width_mm'] ?>×<?= (float)$edit['page_height_mm'] ?>mm</span>
    <?php if ($edit['is_default']): ?><span class="hidden sm:inline text-[10px] font-bold text-green-700 bg-green-50 px-2 py-0.5 rounded-full">Default</span><?php endif; ?>
  </div>
  <span id="save-state" class="text-xs text-gray-400 mr-1"><i class="fa-solid fa-check"></i> All changes saved</span>
  <?php if (!$edit['is_default']): ?>
  <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="set_default"><input type="hidden" name="return_edit" value="1"><input type="hidden" name="template_id" value="<?= $edit['id'] ?>">
    <button type="submit" class="tpl-btn bg-white border border-gray-200" title="Make this the template used by default"><i class="fa-regular fa-star"></i><span class="hidden lg:inline">Set default</span></button>
  </form>
  <?php endif; ?>
  <button type="button" id="btn-test-pdf" class="tpl-btn bg-white border border-gray-200" title="Generate a real PDF from the current (unsaved) layout"><i class="fa-solid fa-file-pdf"></i><span class="hidden lg:inline">Test PDF</span></button>
  <button type="button" id="btn-save" class="tpl-btn !text-white bg-rarl-red hover:!bg-rarl-dark px-4" title="Save (Ctrl+S)"><i class="fa-solid fa-floppy-disk"></i> Save</button>
</div>

<div class="tpl-shell">
  <!-- Left: elements + layers -->
  <aside class="space-y-3">
    <div class="tpl-panel p-3">
      <p class="tpl-h mb-2 px-1">Add element</p>
      <div class="space-y-0.5" id="add-list">
        <?php foreach ($keys as $k => $label):
          $icon = ['qr'=>'fa-qrcode','avatar'=>'fa-user','name'=>'fa-signature','date'=>'fa-calendar','since_date'=>'fa-calendar','cert_no'=>'fa-hashtag','member_code'=>'fa-hashtag','event'=>'fa-calendar-days','section'=>'fa-earth-americas'][$k] ?? 'fa-font'; ?>
        <button type="button" class="add-btn" data-add="<?= $k ?>"><i class="fa-solid <?= $icon ?> w-4 text-center text-gray-400"></i><?= htmlspecialchars($label) ?><span class="used hidden"><i class="fa-solid fa-check"></i></span></button>
        <?php endforeach; ?>
        <div class="border-t border-gray-100 my-1.5"></div>
        <button type="button" class="add-btn" data-add="custom_text"><i class="fa-solid fa-font w-4 text-center text-blue-500"></i>Custom text</button>
        <button type="button" class="add-btn" id="btn-add-image"><i class="fa-solid fa-signature w-4 text-center text-purple-500"></i>Signature / stamp / logo</button>
        <input type="file" id="field-image-upload" accept=".jpg,.jpeg,.png,.webp" class="hidden" data-no-chip/>
      </div>
    </div>
    <div class="tpl-panel p-3">
      <div class="flex items-center justify-between mb-1 px-1"><p class="tpl-h">Layers</p><span class="text-[10px] text-gray-400">top first</span></div>
      <div id="layers" class="space-y-0.5"></div>
    </div>
    <div class="tpl-panel p-3">
      <div class="flex items-center justify-between mb-2 px-1">
        <p class="tpl-h">Sample data</p>
        <button type="button" id="btn-long-name" class="text-[10px] font-semibold text-rarl-red hover:underline" title="Check that long names still fit">Test long name</button>
      </div>
      <div class="space-y-1.5" id="sample-fields">
        <?php foreach ($sample as $k => $v): if (!isset($keys[$k])) continue; ?>
        <div><label class="tpl-label"><?= htmlspecialchars($keys[$k]) ?></label><input class="tpl-input" data-sample="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"/></div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

  <!-- Centre: canvas -->
  <section class="flex flex-col min-w-0 min-h-[480px]">
    <div class="tpl-panel px-2 py-1.5 mb-2 flex flex-wrap items-center gap-0.5">
      <button type="button" class="tpl-btn" id="btn-undo" title="Undo (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
      <button type="button" class="tpl-btn" id="btn-redo" title="Redo (Ctrl+Shift+Z)"><i class="fa-solid fa-rotate-right"></i></button>
      <span class="w-px h-5 bg-gray-200 mx-1"></span>
      <button type="button" class="tpl-btn" data-align="left" title="Align left edges (to page if one item)"><i class="fa-solid fa-align-left"></i></button>
      <button type="button" class="tpl-btn" data-align="hcenter" title="Centre horizontally"><i class="fa-solid fa-align-center"></i></button>
      <button type="button" class="tpl-btn" data-align="right" title="Align right edges"><i class="fa-solid fa-align-right"></i></button>
      <button type="button" class="tpl-btn" data-align="top" title="Align tops"><i class="fa-solid fa-arrow-up-long"></i></button>
      <button type="button" class="tpl-btn" data-align="vcenter" title="Centre vertically"><i class="fa-solid fa-grip-lines"></i></button>
      <button type="button" class="tpl-btn" data-align="bottom" title="Align bottoms"><i class="fa-solid fa-arrow-down-long"></i></button>
      <button type="button" class="tpl-btn" data-align="vdist" title="Distribute vertically (3+ items)"><i class="fa-solid fa-arrows-up-down"></i></button>
      <span class="w-px h-5 bg-gray-200 mx-1"></span>
      <button type="button" class="tpl-btn" id="btn-dup" title="Duplicate (Ctrl+D)"><i class="fa-regular fa-copy"></i></button>
      <button type="button" class="tpl-btn" id="btn-del" title="Delete (Del)"><i class="fa-regular fa-trash-can"></i></button>
      <span class="w-px h-5 bg-gray-200 mx-1"></span>
      <button type="button" class="tpl-btn" id="btn-grid" title="Grid & snap to 1 mm"><i class="fa-solid fa-border-all"></i></button>
      <button type="button" class="tpl-btn on" id="btn-snap" title="Smart guides (hold Alt to bypass)"><i class="fa-solid fa-magnet"></i></button>
      <button type="button" class="tpl-btn" id="btn-safe" title="Show print safe margin (5 mm / 3 mm on cards)"><i class="fa-regular fa-square"></i></button>
      <button type="button" class="tpl-btn" id="btn-clean" title="Clean preview — hide outlines (P)"><i class="fa-regular fa-eye"></i></button>
      <button type="button" class="tpl-btn" id="btn-tokens" title="Show field names instead of sample data"><i class="fa-solid fa-code"></i></button>
      <div class="ml-auto flex items-center gap-0.5">
        <button type="button" class="tpl-btn" id="zoom-out" title="Zoom out (Ctrl −)"><i class="fa-solid fa-minus"></i></button>
        <button type="button" class="tpl-btn w-14" id="zoom-label" title="Fit to screen (Ctrl 0)">100%</button>
        <button type="button" class="tpl-btn" id="zoom-in" title="Zoom in (Ctrl +)"><i class="fa-solid fa-plus"></i></button>
        <button type="button" class="tpl-btn" id="btn-bg" title="Replace background image"><i class="fa-regular fa-image"></i></button>
        <input type="file" id="bg-replace" accept=".jpg,.jpeg,.png,.webp" class="hidden" data-no-chip/>
        <button type="button" class="tpl-btn" id="btn-help" title="Keyboard shortcuts (?)"><i class="fa-regular fa-keyboard"></i></button>
      </div>
    </div>
    <div id="stage" class="flex-1">
      <div id="stage-inner">
        <div id="canvas">
          <img class="bg" id="bg-img" src="../uploads/templates/<?= htmlspecialchars(rawurlencode($edit['background_path'])) ?>" alt="" crossorigin="anonymous"/>
          <svg id="grid-ov"></svg>
          <div id="safe-ov"></div>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-between text-[11px] text-gray-400 mt-1.5 px-1">
      <span id="cursor-pos">—</span>
      <span class="hidden md:inline">Drag to move · Shift-click to multi-select · Arrows nudge (Shift = 5 mm) · Right-click for more</span>
    </div>
  </section>

  <!-- Right: properties -->
  <aside>
    <div class="tpl-panel p-4" id="props-empty">
      <p class="tpl-h mb-2">Properties</p>
      <div class="text-center py-6">
        <div class="w-11 h-11 mx-auto rounded-xl bg-gray-100 text-gray-400 flex items-center justify-center text-lg mb-2"><i class="fa-solid fa-arrow-pointer"></i></div>
        <p class="text-xs text-gray-500">Select an element on the canvas or in Layers to edit it.</p>
      </div>
      <div class="border-t border-gray-100 pt-3 mt-1 space-y-1.5 text-xs text-gray-600" id="checklist"></div>
    </div>
    <div class="tpl-panel p-4 hidden" id="props-multi">
      <p class="tpl-h mb-2">Properties</p>
      <p class="text-sm text-gray-700"><strong id="multi-count">0</strong> elements selected</p>
      <p class="text-xs text-gray-500 mt-1">Use the toolbar to align or distribute them, drag to move together, or press Delete.</p>
    </div>
    <div class="tpl-panel p-4 hidden space-y-3.5" id="props">
      <div class="flex items-center justify-between">
        <p class="tpl-h" id="p-title">Field</p>
        <button type="button" id="p-lock" class="tpl-btn !h-7 !min-w-7" title="Lock position"><i class="fa-solid fa-lock-open"></i></button>
      </div>
      <div id="p-text-wrap">
        <label class="tpl-label">Text <span class="font-normal text-gray-400">— click a token to insert it</span></label>
        <textarea id="p-text" rows="2" class="tpl-input resize-y"></textarea>
        <div class="flex flex-wrap gap-1 mt-1.5" id="p-tokens"></div>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="tpl-label">X (mm)</label><input type="number" step="0.5" id="p-x" class="tpl-input"/></div>
        <div><label class="tpl-label">Y (mm)</label><input type="number" step="0.5" id="p-y" class="tpl-input"/></div>
      </div>
      <div id="p-textprops" class="space-y-3.5">
        <div class="grid grid-cols-[1fr_76px] gap-2">
          <div><label class="tpl-label">Font</label>
            <select id="p-font" class="tpl-input"><option value="helvetica">Helvetica (sans)</option><option value="times">Times (serif)</option><option value="courier">Courier (mono)</option></select></div>
          <div><label class="tpl-label">Size (pt)</label><input type="number" step="0.5" min="3" max="200" id="p-size" class="tpl-input"/></div>
        </div>
        <div class="flex gap-2">
          <div class="seg flex-1" id="p-style">
            <button type="button" data-style="bold" title="Bold"><i class="fa-solid fa-bold"></i></button>
            <button type="button" data-style="italic" title="Italic"><i class="fa-solid fa-italic"></i></button>
            <button type="button" data-style="uppercase" title="UPPERCASE"><span class="font-bold text-[11px]">AA</span></button>
          </div>
          <div class="seg flex-1" id="p-align">
            <button type="button" data-v="left" title="Anchor left"><i class="fa-solid fa-align-left"></i></button>
            <button type="button" data-v="center" title="Anchor centre"><i class="fa-solid fa-align-center"></i></button>
            <button type="button" data-v="right" title="Anchor right"><i class="fa-solid fa-align-right"></i></button>
          </div>
        </div>
        <div>
          <label class="tpl-label">Colour</label>
          <div class="flex items-center gap-2">
            <input type="color" id="p-color" class="w-9 h-8 border border-gray-300 rounded-lg cursor-pointer p-0.5"/>
            <input type="text" id="p-color-hex" class="tpl-input font-mono" maxlength="7"/>
          </div>
          <div class="flex flex-wrap gap-1.5 mt-2" id="swatches"></div>
          <p class="text-[10px] text-gray-400 mt-1" id="swatch-note">Colours sampled from your artwork are shown first.</p>
        </div>
        <div>
          <label class="tpl-label">Max width (mm) <span class="font-normal text-gray-400">— shrinks long text to fit · 0 = off</span></label>
          <div class="flex gap-2"><input type="number" step="1" min="0" id="p-maxw" class="tpl-input"/><button type="button" id="p-maxw-auto" class="tpl-btn border border-gray-200 !h-[30px] whitespace-nowrap" title="Use the space available from the anchor to the page margin">Auto</button></div>
        </div>
      </div>
      <div id="p-imgprops" class="space-y-3">
        <div class="grid grid-cols-[1fr_auto_1fr] gap-1.5 items-end">
          <div><label class="tpl-label">Width (mm)</label><input type="number" step="0.5" min="2" id="p-w" class="tpl-input"/></div>
          <button type="button" id="p-ratio" class="tpl-btn !h-[30px] on" title="Keep proportions"><i class="fa-solid fa-link"></i></button>
          <div><label class="tpl-label">Height (mm)</label><input type="number" step="0.5" min="2" id="p-h" class="tpl-input"/></div>
        </div>
        <button type="button" id="p-img-replace" class="hidden w-full tpl-btn border border-gray-200"><i class="fa-solid fa-arrow-up-from-bracket"></i> Replace image</button>
        <p id="p-img-note" class="text-[10.5px] text-gray-500"></p>
      </div>
      <div class="flex gap-1.5 pt-1 border-t border-gray-100">
        <button type="button" class="tpl-btn flex-1 border border-gray-200" id="p-front" title="Bring to front"><i class="fa-solid fa-arrow-up-wide-short"></i></button>
        <button type="button" class="tpl-btn flex-1 border border-gray-200" id="p-back" title="Send to back"><i class="fa-solid fa-arrow-down-short-wide"></i></button>
        <button type="button" class="tpl-btn flex-1 border border-gray-200" id="p-dup" title="Duplicate"><i class="fa-regular fa-copy"></i></button>
        <button type="button" class="tpl-btn flex-1 border border-red-200 !text-red-600 hover:!bg-red-50" id="p-del" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
      </div>
    </div>
  </aside>
</div>

<div id="ctx"></div>
<dialog id="help-dlg" class="rarl-dialog w-full max-w-md">
  <div class="p-6">
    <div class="flex items-center justify-between mb-4"><h2 class="font-heading font-bold text-lg">Keyboard shortcuts</h2><button type="button" onclick="this.closest('dialog').close()" class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="grid grid-cols-[1fr_auto] gap-y-2 text-sm text-gray-700">
      <span>Save</span><span><span class="kbd">Ctrl</span> <span class="kbd">S</span></span>
      <span>Undo / redo</span><span><span class="kbd">Ctrl</span> <span class="kbd">Z</span> / <span class="kbd">Ctrl</span> <span class="kbd">Shift</span> <span class="kbd">Z</span></span>
      <span>Duplicate</span><span><span class="kbd">Ctrl</span> <span class="kbd">D</span></span>
      <span>Copy / paste</span><span><span class="kbd">Ctrl</span> <span class="kbd">C</span> / <span class="kbd">V</span></span>
      <span>Select all</span><span><span class="kbd">Ctrl</span> <span class="kbd">A</span></span>
      <span>Delete</span><span><span class="kbd">Del</span></span>
      <span>Nudge 0.5 mm / 5 mm</span><span><span class="kbd">←↑→↓</span> / + <span class="kbd">Shift</span></span>
      <span>Zoom in / out / fit</span><span><span class="kbd">Ctrl</span> <span class="kbd">+</span> <span class="kbd">−</span> <span class="kbd">0</span></span>
      <span>Bypass snapping while dragging</span><span><span class="kbd">Alt</span></span>
      <span>Clean preview</span><span><span class="kbd">P</span></span>
      <span>Edit custom text</span><span>double-click</span>
      <span>Deselect</span><span><span class="kbd">Esc</span></span>
    </div>
  </div>
</dialog>
<form id="pdf-form" method="POST" action="preview-template.php" target="_blank" class="hidden" data-no-loading>
  <?= acsrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"/>
  <input type="hidden" name="config_json"/><input type="hidden" name="sample_json"/>
</form>

<script>
(function() {
'use strict';
const ACSRF = <?= json_encode($GLOBALS['acsrf'] ?? '') ?>;
const TEMPLATE_ID = <?= (int)$edit['id'] ?>;
const PW = <?= (float)$edit['page_width_mm'] ?>, PH = <?= (float)$edit['page_height_mm'] ?>;
const IS_CARD = <?= $edit['type'] === 'id_card' ? 'true' : 'false' ?>;
const LABELS = Object.assign(<?= json_encode($keys) ?>, {custom_text: 'Custom text', signature: 'Image'});
const IMAGE_KEYS = ['qr', 'avatar', 'signature'];
const FONT_CSS = {helvetica: 'Helvetica, Arial, sans-serif', times: '"Times New Roman", Times, serif', courier: '"Courier New", Courier, monospace'};
const PT_MM = 25.4 / 72;
const SAFE = IS_CARD ? 3 : 5;
const BRAND = ['#111111', '#ffffff', '<?= BRAND_RED ?>', '#1f2937', '#6b7280', '#b45309', '#1e3a8a'];
const sample = {};
document.querySelectorAll('[data-sample]').forEach(i => sample[i.dataset.sample] = i.value);
const ORIG_NAME = sample.name || '';

let uid = 1;
const withId = f => { const {_id, ...rest} = f; return Object.assign({_id: uid++}, rest); };
let fields = (<?= json_encode(array_values($config)) ?>).map(withId);
let sel = [];             // selected _ids
let scale = 3;            // px per mm
let showTokens = false, snapOn = true, gridOn = false;
let clipboard = null;
const $ = id => document.getElementById(id);
const canvas = $('canvas'), stage = $('stage');

// ── History ─────────────────────────────────────────────
let undoStack = [], redoStack = [], savedSnap = '';
const snap = () => JSON.stringify(fields.map(({_id, ...f}) => f));
function commit() {
  const s = snap();
  if (undoStack[undoStack.length - 1] !== s) { undoStack.push(s); if (undoStack.length > 150) undoStack.shift(); redoStack = []; }
  syncDirty();
}
function restore(s) { fields = JSON.parse(s).map(withId); sel = []; render(); syncDirty(); }
function undo() { if (undoStack.length < 2) return; redoStack.push(undoStack.pop()); restore(undoStack[undoStack.length - 1]); }
function redo() { if (!redoStack.length) return; const s = redoStack.pop(); undoStack.push(s); restore(s); }
function isDirty() { return snap() !== savedSnap || $('tpl-name').value.trim() !== $('tpl-name').defaultValue; }
function syncDirty() {
  $('btn-undo').disabled = undoStack.length < 2; $('btn-redo').disabled = !redoStack.length;
  const st = $('save-state');
  if (isDirty()) { st.innerHTML = '<i class="fa-solid fa-circle text-[7px] align-middle"></i> Unsaved changes'; st.className = 'text-xs text-amber-600 font-semibold mr-1'; }
  else { st.innerHTML = '<i class="fa-solid fa-check"></i> All changes saved'; st.className = 'text-xs text-gray-400 mr-1'; }
}
window.addEventListener('beforeunload', e => { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

// ── Geometry helpers ────────────────────────────────────
const byId = id => fields.find(f => f._id === id);
const selFields = () => sel.map(byId).filter(Boolean);
const r2 = v => Math.round(v * 100) / 100;
const xmm = f => f.x / 100 * PW, ymm = f => f.y / 100 * PH;
function setMm(f, x, y) { f.x = r2(Math.max(-50, Math.min(150, x / PW * 100))); f.y = r2(Math.max(-50, Math.min(150, y / PH * 100))); }
// Bounding box in mm (from the rendered element, so text width is real).
function box(f) {
  const el = canvas.querySelector('[data-id="' + f._id + '"]');
  if (!el) return {l: xmm(f), t: ymm(f), r: xmm(f), b: ymm(f)};
  const cr = canvas.getBoundingClientRect(), er = el.getBoundingClientRect();
  const l = (er.left - cr.left) / scale, t = (er.top - cr.top) / scale;
  return {l, t, r: l + er.width / scale, b: t + er.height / scale};
}
function textOf(f) {
  let t;
  if (f.key === 'custom_text') t = showTokens ? (f.text || '') : (f.text || '').replace(/\{([a-z0-9_]+)\}/g, (m, k) => sample[k] !== undefined ? sample[k] : m);
  else t = showTokens ? '{' + f.key + '}' : (sample[f.key] ?? '{' + f.key + '}');
  if (!t) t = f.key === 'custom_text' ? 'Double-click to type…' : '';
  return f.uppercase ? t.toUpperCase() : t;
}

// ── Placeholder art ─────────────────────────────────────
const svgUrl = s => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(s);
const AVATAR = svgUrl('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid slice"><rect width="100" height="100" fill="#d1d5db"/><circle cx="50" cy="38" r="18" fill="#9ca3af"/><ellipse cx="50" cy="90" rx="32" ry="26" fill="#9ca3af"/></svg>');
const QR = (() => { // deterministic fake QR so the slot reads as a code
  let s = '', seed = 7; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
  for (let y = 0; y < 25; y++) for (let x = 0; x < 25; x++) {
    const finder = (x < 7 && y < 7) || (x > 17 && y < 7) || (x < 7 && y > 17);
    if (!finder && rnd() > .5) s += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>';
  }
  const fp = (x, y) => '<rect x="' + x + '" y="' + y + '" width="7" height="7"/><rect x="' + (x + 1) + '" y="' + (y + 1) + '" width="5" height="5" fill="#fff"/><rect x="' + (x + 2) + '" y="' + (y + 2) + '" width="3" height="3"/>';
  return svgUrl('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-1 -1 27 27" shape-rendering="crispEdges"><rect x="-1" y="-1" width="27" height="27" fill="#fff"/><g fill="#111">' + s + fp(0, 0) + fp(18, 0) + fp(0, 18) + '</g></svg>');
})();
const IMG_MISSING = svgUrl('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40"><rect width="60" height="40" fill="#f3f4f6" stroke="#d1d5db"/><text x="30" y="24" font-size="7" text-anchor="middle" fill="#9ca3af" font-family="sans-serif">image</text></svg>');

// ── Render ──────────────────────────────────────────────
function render() {
  canvas.style.width = PW * scale + 'px'; canvas.style.height = PH * scale + 'px';
  $('zoom-label').textContent = Math.round(scale / baseScale() * 100) + '%';
  canvas.querySelectorAll('.fld,.hdl').forEach(el => el.remove());
  fields.forEach(f => {
    const el = document.createElement('div');
    el.dataset.id = f._id;
    el.className = 'fld' + (sel.includes(f._id) ? ' sel' : '') + (f.locked ? ' locked' : '');
    if (IMAGE_KEYS.includes(f.key)) {
      el.classList.add('img');
      const w = +f.w || 15, h = +f.h || 15;
      Object.assign(el.style, {left: xmm(f) * scale + 'px', top: ymm(f) * scale + 'px', width: w * scale + 'px', height: h * scale + 'px'});
      const img = document.createElement('img');
      img.src = f.key === 'qr' ? QR : f.key === 'avatar' ? AVATAR : (f.image ? '../uploads/templates/' + encodeURIComponent(f.image) : IMG_MISSING);
      img.style.objectFit = f.key === 'avatar' ? 'cover' : 'fill';
      el.appendChild(img);
    } else {
      const sizeMm = (+f.font_size || 12) * PT_MM;
      Object.assign(el.style, {
        left: (xmm(f) + 1) * scale + 'px', top: ymm(f) * scale + 'px',
        fontSize: sizeMm * scale + 'px', color: f.color || '#111', fontFamily: FONT_CSS[f.font] || FONT_CSS.helvetica,
        fontWeight: f.bold ? 'bold' : 'normal', fontStyle: f.italic ? 'italic' : 'normal',
        transform: f.align === 'center' ? 'translateX(-50%)' : f.align === 'right' ? 'translateX(-100%)' : 'none',
      });
      el.textContent = textOf(f);
      if (f.key === 'custom_text' && !f.text) el.style.opacity = .55;
      const mw = +f.max_width || 0;
      if (mw > 0) {
        // Mirror the PDF's shrink-to-fit so the designer shows the real result.
        let size = +f.font_size || 12;
        el.style.whiteSpace = 'nowrap';
        canvas.appendChild(el);
        while (size > 4 && el.getBoundingClientRect().width / scale > mw) { size -= 0.5; el.style.fontSize = size * PT_MM * scale + 'px'; }
        el.style.top = (ymm(f) + ((+f.font_size || 12) - size) * 0.2) * scale + 'px';
        el.title = size < (+f.font_size || 12) ? 'Shrunk to ' + size + ' pt to fit max width' : '';
        if (sel.includes(f._id)) {
          const g = document.createElement('div'); g.className = 'maxw';
          const elW = el.getBoundingClientRect().width;
          const off = f.align === 'center' ? (elW - mw * scale) / 2 : f.align === 'right' ? elW - mw * scale : 0;
          Object.assign(g.style, {left: off + 'px', width: mw * scale + 'px'});
          el.appendChild(g);
        }
      }
    }
    el.addEventListener('pointerdown', onPointerDown);
    if (!el.parentNode) canvas.appendChild(el);
  });
  // Resize handles for a single selected image
  const one = sel.length === 1 ? byId(sel[0]) : null;
  if (one && IMAGE_KEYS.includes(one.key) && !one.locked) {
    [['se', 1, 1], ['sw', 0, 1], ['ne', 1, 0], ['nw', 0, 0]].forEach(([c, cx, cy]) => {
      const h = document.createElement('div'); h.className = 'hdl'; h.dataset.corner = c;
      Object.assign(h.style, {left: (xmm(one) + cx * one.w) * scale - 5 + 'px', top: (ymm(one) + cy * one.h) * scale - 5 + 'px', cursor: c + '-resize'});
      h.addEventListener('pointerdown', onResizeDown);
      canvas.appendChild(h);
    });
  }
  renderGrid(); renderLayers(); renderProps(); renderChecklist();
  document.querySelectorAll('#add-list [data-add]').forEach(b => b.querySelector('.used')?.classList.toggle('hidden', !fields.some(f => f.key === b.dataset.add)));
}

function renderGrid() {
  const g = $('grid-ov');
  if (gridOn) {
    const step = scale * (scale < 3 ? 5 : 1) * (PW > 150 ? 5 : 1);
    g.setAttribute('width', PW * scale); g.setAttribute('height', PH * scale);
    g.innerHTML = '<defs><pattern id="gp" width="' + step + '" height="' + step + '" patternUnits="userSpaceOnUse"><path d="M ' + step + ' 0 L 0 0 0 ' + step + '" fill="none" stroke="rgba(59,130,246,.22)" stroke-width="1"/></pattern></defs><rect width="100%" height="100%" fill="url(#gp)"/><line x1="50%" y1="0" x2="50%" y2="100%" stroke="rgba(236,72,153,.35)"/><line x1="0" y1="50%" x2="100%" y2="50%" stroke="rgba(236,72,153,.35)"/>';
  }
  Object.assign($('safe-ov').style, {left: SAFE * scale + 'px', top: SAFE * scale + 'px', right: SAFE * scale + 'px', bottom: SAFE * scale + 'px'});
}

function renderLayers() {
  const L = $('layers'); L.innerHTML = '';
  if (!fields.length) { L.innerHTML = '<p class="text-xs text-gray-400 px-1 py-2">Nothing placed yet — add elements above.</p>'; return; }
  [...fields].reverse().forEach(f => {
    const row = document.createElement('div');
    row.className = 'layer' + (sel.includes(f._id) ? ' sel' : '');
    const icon = f.key === 'qr' ? 'fa-qrcode' : f.key === 'avatar' ? 'fa-user' : f.key === 'signature' ? 'fa-image' : 'fa-font';
    const label = f.key === 'custom_text' ? (f.text || 'Custom text') : LABELS[f.key] || f.key;
    row.innerHTML = '<i class="fa-solid ' + icon + ' w-4 text-center text-gray-400"></i><span class="truncate flex-1"></span><button type="button" class="lk ' + (f.locked ? 'on' : '') + '" title="' + (f.locked ? 'Unlock' : 'Lock') + '"><i class="fa-solid ' + (f.locked ? 'fa-lock' : 'fa-lock-open') + ' text-[11px]"></i></button>';
    row.querySelector('span').textContent = label;
    row.addEventListener('click', e => { if (e.target.closest('.lk')) { f.locked = !f.locked; commit(); render(); return; } select(f._id, e.shiftKey); });
    L.appendChild(row);
  });
}

function renderChecklist() {
  const c = $('checklist'); if (!c) return;
  const items = [];
  const has = k => fields.some(f => f.key === k);
  items.push([has('name'), 'Name placed']);
  if (LABELS.qr) items.push([has('qr'), 'QR verification code placed']);
  if (LABELS.avatar) items.push([has('avatar'), 'Member photo placed']);
  const outside = fields.filter(f => { const b = box(f); return b.l < 0 || b.t < 0 || b.r > PW || b.b > PH; });
  items.push([!outside.length, outside.length ? outside.length + ' element(s) run off the page' : 'Everything inside the page']);
  const nameF = fields.find(f => f.key === 'name');
  if (nameF) items.push([+nameF.max_width > 0, 'Name shrinks to fit long names']);
  c.innerHTML = '<p class="tpl-h mb-1">Checklist</p>' + items.map(([ok, t]) => '<div class="flex items-center gap-2"><i class="fa-solid ' + (ok ? 'fa-circle-check text-green-500' : 'fa-circle-exclamation text-amber-500') + '"></i><span>' + t + '</span></div>').join('');
}

// ── Properties panel ────────────────────────────────────
let ratioLock = true;
function renderProps() {
  const many = sel.length > 1, f = sel.length === 1 ? byId(sel[0]) : null;
  $('props-empty').classList.toggle('hidden', !!sel.length);
  $('props-multi').classList.toggle('hidden', !many);
  $('props').classList.toggle('hidden', !f);
  if (many) $('multi-count').textContent = sel.length;
  if (!f) return;
  const isImg = IMAGE_KEYS.includes(f.key);
  $('p-title').textContent = f.key === 'custom_text' ? 'Custom text' : (LABELS[f.key] || f.key);
  $('p-lock').innerHTML = '<i class="fa-solid ' + (f.locked ? 'fa-lock' : 'fa-lock-open') + '"></i>';
  $('p-lock').classList.toggle('on', !!f.locked);
  $('p-text-wrap').classList.toggle('hidden', f.key !== 'custom_text');
  $('p-textprops').classList.toggle('hidden', isImg);
  $('p-imgprops').classList.toggle('hidden', !isImg);
  const setVal = (id, v) => { const el = $(id); if (document.activeElement !== el) el.value = v; };
  setVal('p-x', r2(xmm(f))); setVal('p-y', r2(ymm(f)));
  if (f.key === 'custom_text') setVal('p-text', f.text || '');
  if (!isImg) {
    setVal('p-font', f.font || 'helvetica'); setVal('p-size', f.font_size || 12);
    setVal('p-color', f.color || '#111111'); setVal('p-color-hex', f.color || '#111111');
    setVal('p-maxw', f.max_width || 0);
    document.querySelectorAll('#p-style button').forEach(b => b.classList.toggle('on', !!f[b.dataset.style]));
    document.querySelectorAll('#p-align button').forEach(b => b.classList.toggle('on', (f.align || 'left') === b.dataset.v));
  } else {
    setVal('p-w', f.w || 15); setVal('p-h', f.h || 15);
    $('p-ratio').classList.toggle('on', ratioLock);
    $('p-img-replace').classList.toggle('hidden', f.key !== 'signature');
    $('p-img-note').textContent = f.key === 'qr' ? 'Links to the live verification page. Keep it at least 12 mm and square so phones can scan it.'
      : f.key === 'avatar' ? "The member's photo is centre-cropped to fill this box — it is never stretched."
      : 'Tip: use a PNG with a transparent background for signatures and stamps.';
  }
}
function updateSel(mut, doCommit = true) { selFields().forEach(mut); render(); if (doCommit) commit(); }
const num = id => parseFloat($(id).value);
$('p-x').addEventListener('change', () => updateSel(f => setMm(f, num('p-x') || 0, ymm(f))));
$('p-y').addEventListener('change', () => updateSel(f => setMm(f, xmm(f), num('p-y') || 0)));
$('p-text').addEventListener('input', () => updateSel(f => f.text = $('p-text').value, false));
$('p-text').addEventListener('change', commit);
$('p-font').addEventListener('change', () => updateSel(f => f.font = $('p-font').value));
$('p-size').addEventListener('input', () => { const v = num('p-size'); if (v >= 3) updateSel(f => f.font_size = v, false); });
$('p-size').addEventListener('change', commit);
$('p-color').addEventListener('input', () => updateSel(f => f.color = $('p-color').value, false));
$('p-color').addEventListener('change', commit);
$('p-color-hex').addEventListener('change', () => { let v = $('p-color-hex').value.trim(); if (!v.startsWith('#')) v = '#' + v; if (/^#[0-9a-f]{6}$/i.test(v)) updateSel(f => f.color = v.toLowerCase()); });
$('p-maxw').addEventListener('change', () => updateSel(f => f.max_width = Math.max(0, num('p-maxw') || 0)));
$('p-maxw-auto').addEventListener('click', () => updateSel(f => {
  const x = xmm(f), avail = f.align === 'center' ? 2 * Math.min(x - SAFE, PW - SAFE - x) : f.align === 'right' ? x - SAFE : PW - SAFE - x;
  f.max_width = Math.max(10, Math.round(avail));
}));
document.querySelectorAll('#p-style button').forEach(b => b.addEventListener('click', () => { const k = b.dataset.style; const v = !byId(sel[0])[k]; updateSel(f => f[k] = v); }));
document.querySelectorAll('#p-align button').forEach(b => b.addEventListener('click', () => updateSel(f => {
  // Keep the text visually in place when switching anchor.
  const bx = box(f), w = bx.r - bx.l, oldA = f.align || 'left', newA = b.dataset.v;
  const left = bx.l - 1;
  const nx = newA === 'center' ? left + w / 2 : newA === 'right' ? left + w : left;
  if (oldA !== newA) setMm(f, nx, ymm(f));
  f.align = newA;
})));
$('p-w').addEventListener('change', () => updateSel(f => { const w = Math.max(2, num('p-w') || f.w); if (ratioLock) f.h = r2(f.h * w / f.w); f.w = w; }));
$('p-h').addEventListener('change', () => updateSel(f => { const h = Math.max(2, num('p-h') || f.h); if (ratioLock) f.w = r2(f.w * h / f.h); f.h = h; }));
$('p-ratio').addEventListener('click', () => { ratioLock = !ratioLock; renderProps(); });
$('p-lock').addEventListener('click', () => updateSel(f => f.locked = !f.locked));
$('p-front').addEventListener('click', () => reorder(1));
$('p-back').addEventListener('click', () => reorder(-1));
$('p-dup').addEventListener('click', duplicate);
$('p-del').addEventListener('click', removeSel);
$('p-img-replace').addEventListener('click', () => { replacingImage = true; $('field-image-upload').click(); });

// Token chips for custom text
const tokenWrap = $('p-tokens');
Object.keys(LABELS).filter(k => !IMAGE_KEYS.includes(k) && !['custom_text','signature'].includes(k)).forEach(k => {
  const c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.textContent = '{' + k + '}'; c.title = 'Insert ' + LABELS[k];
  c.addEventListener('click', () => {
    const ta = $('p-text'), s = ta.selectionStart ?? ta.value.length;
    ta.value = ta.value.slice(0, s) + '{' + k + '}' + ta.value.slice(ta.selectionEnd ?? s);
    ta.dispatchEvent(new Event('input')); commit(); ta.focus();
  });
  tokenWrap.appendChild(c);
});

// Swatches: brand colours + a palette sampled from the background artwork
function paintSwatches(extra) {
  const S = $('swatches'); S.innerHTML = '';
  [...new Set([...(extra || []), ...BRAND])].slice(0, 14).forEach(c => {
    const s = document.createElement('button'); s.type = 'button'; s.className = 'swatch'; s.style.background = c; s.title = c;
    s.addEventListener('click', () => updateSel(f => { if (!IMAGE_KEYS.includes(f.key)) f.color = c; }));
    S.appendChild(s);
  });
}
function samplePalette() {
  try {
    const img = $('bg-img'), c = document.createElement('canvas'); c.width = 48; c.height = Math.max(1, Math.round(48 * PH / PW));
    const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0, c.width, c.height);
    const d = ctx.getImageData(0, 0, c.width, c.height).data, buckets = {};
    for (let i = 0; i < d.length; i += 4) {
      const k = [d[i], d[i + 1], d[i + 2]].map(v => Math.round(v / 32) * 32 - (v > 223 ? 1 : 0)).map(v => Math.max(0, Math.min(255, v)));
      const key = k.join(','); buckets[key] = (buckets[key] || 0) + 1;
    }
    const hex = k => '#' + k.split(',').map(v => (+v).toString(16).padStart(2, '0')).join('');
    paintSwatches(Object.entries(buckets).sort((a, b) => b[1] - a[1]).slice(0, 7).map(e => hex(e[0])));
  } catch (e) { paintSwatches(); $('swatch-note').classList.add('hidden'); }
}

// ── Selection ───────────────────────────────────────────
function select(id, additive) {
  if (id == null) sel = [];
  else if (additive) sel = sel.includes(id) ? sel.filter(s => s !== id) : [...sel, id];
  else sel = [id];
  render();
}
stage.addEventListener('pointerdown', e => { if (!e.target.closest('.fld,.hdl')) { select(null); startMarquee(e); } });

// Rubber-band selection on empty canvas area
function startMarquee(e) {
  if (e.button !== 0) return;
  const cr = canvas.getBoundingClientRect(), x0 = e.clientX, y0 = e.clientY;
  const m = document.createElement('div');
  m.style.cssText = 'position:fixed;border:1px solid #2563eb;background:rgba(37,99,235,.08);z-index:30;pointer-events:none';
  document.body.appendChild(m);
  const move = ev => Object.assign(m.style, {left: Math.min(x0, ev.clientX) + 'px', top: Math.min(y0, ev.clientY) + 'px', width: Math.abs(ev.clientX - x0) + 'px', height: Math.abs(ev.clientY - y0) + 'px'});
  const up = ev => {
    document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); m.remove();
    if (Math.abs(ev.clientX - x0) < 4 && Math.abs(ev.clientY - y0) < 4) return;
    const l = (Math.min(x0, ev.clientX) - cr.left) / scale, r = (Math.max(x0, ev.clientX) - cr.left) / scale;
    const t = (Math.min(y0, ev.clientY) - cr.top) / scale, b = (Math.max(y0, ev.clientY) - cr.top) / scale;
    sel = fields.filter(f => { const bx = box(f); return bx.l < r && bx.r > l && bx.t < b && bx.b > t; }).map(f => f._id);
    render();
  };
  document.addEventListener('pointermove', move); document.addEventListener('pointerup', up);
}

// ── Dragging with smart guides ──────────────────────────
let lastDown = {};
function clearGuides() { canvas.querySelectorAll('.guide').forEach(g => g.remove()); }
function guide(dir, mm) { const g = document.createElement('div'); g.className = 'guide ' + dir; g.style[dir === 'v' ? 'left' : 'top'] = mm * scale + 'px'; canvas.appendChild(g); }
function onPointerDown(e) {
  if (e.button === 2) return;
  e.stopPropagation();
  const id = +e.currentTarget.dataset.id;
  if (e.shiftKey) { select(id, true); return; }
  // Double-click detection by hand: render() swaps elements between clicks, so native dblclick is unreliable.
  const now = Date.now();
  if (lastDown.id === id && now - lastDown.t < 350 && byId(id)?.key === 'custom_text') { lastDown = {}; setTimeout(() => { $('p-text').focus(); $('p-text').select(); }); return; }
  lastDown = {id, t: now};
  if (!sel.includes(id)) select(id);
  const moving = selFields().filter(f => !f.locked);
  if (!moving.length) return;
  const start = {x: e.clientX, y: e.clientY};
  const origin = moving.map(f => ({f, x: xmm(f), y: ymm(f)}));
  const boxes = moving.map(box);
  const group = {l: Math.min(...boxes.map(b => b.l)), t: Math.min(...boxes.map(b => b.t)), r: Math.max(...boxes.map(b => b.r)), b: Math.max(...boxes.map(b => b.b))};
  const others = fields.filter(f => !moving.includes(f)).map(box);
  const tx = [0, PW / 2, PW, SAFE, PW - SAFE], ty = [0, PH / 2, PH, SAFE, PH - SAFE];
  others.forEach(b => { tx.push(b.l, (b.l + b.r) / 2, b.r); ty.push(b.t, (b.t + b.b) / 2, b.b); });
  let moved = false;
  const move = ev => {
    let dx = (ev.clientX - start.x) / scale, dy = (ev.clientY - start.y) / scale;
    if (!moved && Math.hypot(dx, dy) * scale < 3) return;
    moved = true; clearGuides();
    if (gridOn && !ev.altKey) { dx = Math.round(group.l + dx) - group.l; dy = Math.round(group.t + dy) - group.t; }
    if (snapOn && !ev.altKey) {
      const thr = 6 / scale;
      const snapAxis = (edges, targets, dir) => {
        let best = null;
        edges.forEach(ed => targets.forEach(t => { const d = t - ed; if (Math.abs(d) <= thr && (!best || Math.abs(d) < Math.abs(best.d))) best = {d, t}; }));
        if (best) guide(dir, best.t);
        return best ? best.d : 0;
      };
      const w = group.r - group.l, h = group.b - group.t;
      dx += snapAxis([group.l + dx, group.l + dx + w / 2, group.l + dx + w], tx, 'v');
      dy += snapAxis([group.t + dy, group.t + dy + h / 2, group.t + dy + h], ty, 'h');
    }
    origin.forEach(o => setMm(o.f, o.x + dx, o.y + dy));
    const saved = [...canvas.querySelectorAll('.guide')];
    render(); saved.forEach(g => canvas.appendChild(g));
  };
  const up = () => { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); clearGuides(); if (moved) commit(); };
  document.addEventListener('pointermove', move); document.addEventListener('pointerup', up);
}

function onResizeDown(e) {
  e.stopPropagation(); e.preventDefault();
  const f = byId(sel[0]), c = e.currentTarget.dataset.corner;
  const o = {x: xmm(f), y: ymm(f), w: +f.w, h: +f.h}, start = {x: e.clientX, y: e.clientY};
  const keep = (f.key === 'qr' || ratioLock) !== e.shiftKey;
  const move = ev => {
    let dw = (ev.clientX - start.x) / scale * (c.includes('w') ? -1 : 1);
    let dh = (ev.clientY - start.y) / scale * (c.includes('n') ? -1 : 1);
    let w = Math.max(3, o.w + dw), h = Math.max(3, o.h + dh);
    if (keep) { const k = Math.max(w / o.w, h / o.h); w = o.w * k; h = o.h * k; }
    f.w = r2(w); f.h = r2(h);
    setMm(f, c.includes('w') ? o.x + o.w - w : o.x, c.includes('n') ? o.y + o.h - h : o.y);
    render();
  };
  const up = () => { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); commit(); };
  document.addEventListener('pointermove', move); document.addEventListener('pointerup', up);
}

canvas.addEventListener('pointermove', e => {
  const cr = canvas.getBoundingClientRect();
  const x = (e.clientX - cr.left) / scale, y = (e.clientY - cr.top) / scale;
  $('cursor-pos').textContent = 'x ' + x.toFixed(1) + ' mm · y ' + y.toFixed(1) + ' mm';
});
canvas.addEventListener('pointerleave', () => $('cursor-pos').textContent = PW + ' × ' + PH + ' mm');

// ── Actions ─────────────────────────────────────────────
function addField(key, extra = {}) {
  const isImg = IMAGE_KEYS.includes(key);
  const base = isImg
    ? {key, w: key === 'qr' ? (IS_CARD ? 14 : 25) : key === 'avatar' ? (IS_CARD ? 18 : 30) : (IS_CARD ? 20 : 45), h: key === 'qr' ? (IS_CARD ? 14 : 25) : key === 'avatar' ? (IS_CARD ? 22 : 36) : (IS_CARD ? 10 : 18)}
    : {key, font_size: key === 'name' ? (IS_CARD ? 10 : 30) : (IS_CARD ? 6 : 14), color: '#111111', align: IS_CARD ? 'left' : 'center', bold: key === 'name', font: 'helvetica'};
  const f = withId(Object.assign(base, extra));
  // Drop new elements in the middle of the visible page, staggered so they don't stack.
  const n = fields.length % 6;
  setMm(f, isImg ? PW / 2 - f.w / 2 + n * 2 : (f.align === 'center' ? PW / 2 : PW * 0.3) + n * 2, isImg ? PH / 2 - f.h / 2 + n * 2 : PH * 0.4 + n * 3);
  if (key === 'name' && !IS_CARD) f.max_width = Math.round(PW - 4 * SAFE * 2);
  fields.push(f); sel = [f._id]; render(); commit();
  if (key === 'custom_text') { $('p-text').focus(); }
}
document.querySelectorAll('#add-list [data-add]').forEach(b => b.addEventListener('click', () => addField(b.dataset.add, b.dataset.add === 'custom_text' ? {text: '', bold: false} : {})));

let replacingImage = false;
$('btn-add-image').addEventListener('click', () => { replacingImage = false; $('field-image-upload').click(); });
$('field-image-upload').addEventListener('change', async e => {
  const file = e.target.files[0]; if (!file) return;
  const fd = new FormData(); fd.append('action', 'upload_field_image'); fd.append('acsrf', ACSRF); fd.append('field_image', file);
  rarlToast('Uploading image…', 'info');
  try {
    const data = await (await fetch('templates.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
    if (!data.ok) throw new Error(data.error || 'Upload failed');
    const img = new Image(); img.src = URL.createObjectURL(file); await img.decode().catch(() => {});
    const ratio = img.naturalWidth && img.naturalHeight ? img.naturalHeight / img.naturalWidth : 0.4;
    if (replacingImage && sel.length === 1) { updateSel(f => { f.image = data.filename; f.h = r2(f.w * ratio); }); }
    else { const w = IS_CARD ? 20 : 45; addField('signature', {image: data.filename, w, h: r2(w * ratio)}); }
    rarlToast('Image added', 'success');
  } catch (err) { rarlToast(err.message || 'Upload failed — jpg/png/webp, max 3MB.', 'error'); }
  e.target.value = '';
});

$('btn-bg').addEventListener('click', () => $('bg-replace').click());
$('bg-replace').addEventListener('change', async e => {
  const file = e.target.files[0]; if (!file) return;
  if (!await rarlConfirm('Replace the background artwork? Field positions are kept.', {ok: 'Replace'})) { e.target.value = ''; return; }
  const fd = new FormData(); fd.append('action', 'replace_background'); fd.append('acsrf', ACSRF); fd.append('template_id', TEMPLATE_ID); fd.append('background', file);
  try {
    const data = await (await fetch('templates.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
    if (!data.ok) throw new Error(data.error);
    $('bg-img').src = '../uploads/templates/' + encodeURIComponent(data.filename);
    rarlToast('Background replaced', 'success');
  } catch (err) { rarlToast(err.message || 'Upload failed', 'error'); }
  e.target.value = '';
});

function removeSel() { if (!sel.length) return; fields = fields.filter(f => !sel.includes(f._id) || f.locked); sel = []; render(); commit(); }
function duplicate() {
  if (!sel.length) return;
  const copies = selFields().map(f => { const c = withId(JSON.parse(JSON.stringify(Object.assign({}, f, {_id: undefined, locked: false})))); setMm(c, xmm(f) + 3, ymm(f) + 3); return c; });
  fields.push(...copies); sel = copies.map(c => c._id); render(); commit();
}
function reorder(dir) {
  const moving = selFields(); if (!moving.length) return;
  fields = fields.filter(f => !moving.includes(f));
  fields = dir > 0 ? [...fields, ...moving] : [...moving, ...fields];
  render(); commit();
}
function align(mode) {
  const fs = selFields().filter(f => !f.locked); if (!fs.length) return;
  const bs = fs.map(box);
  const ref = fs.length === 1 ? {l: 0, t: 0, r: PW, b: PH}
    : {l: Math.min(...bs.map(b => b.l)), t: Math.min(...bs.map(b => b.t)), r: Math.max(...bs.map(b => b.r)), b: Math.max(...bs.map(b => b.b))};
  if (mode === 'vdist') {
    if (fs.length < 3) { rarlToast('Select 3 or more elements to distribute', 'info'); return; }
    const order = fs.map((f, i) => ({f, b: bs[i]})).sort((a, b) => a.b.t - b.b.t);
    const first = order[0].b.t, last = order[order.length - 1].b.t, step = (last - first) / (order.length - 1);
    order.forEach((o, i) => setMm(o.f, xmm(o.f), ymm(o.f) + first + step * i - o.b.t));
  } else fs.forEach((f, i) => {
    const b = bs[i], w = b.r - b.l, h = b.b - b.t;
    let dx = 0, dy = 0;
    if (mode === 'left') dx = ref.l - b.l + (fs.length === 1 ? SAFE : 0);
    if (mode === 'right') dx = ref.r - b.r - (fs.length === 1 ? SAFE : 0);
    if (mode === 'hcenter') dx = (ref.l + ref.r) / 2 - (b.l + w / 2);
    if (mode === 'top') dy = ref.t - b.t + (fs.length === 1 ? SAFE : 0);
    if (mode === 'bottom') dy = ref.b - b.b - (fs.length === 1 ? SAFE : 0);
    if (mode === 'vcenter') dy = (ref.t + ref.b) / 2 - (b.t + h / 2);
    setMm(f, xmm(f) + dx, ymm(f) + dy);
  });
  render(); commit();
}
document.querySelectorAll('[data-align]').forEach(b => b.addEventListener('click', () => align(b.dataset.align)));
$('btn-undo').addEventListener('click', undo); $('btn-redo').addEventListener('click', redo);
$('btn-dup').addEventListener('click', duplicate); $('btn-del').addEventListener('click', removeSel);
const toggle = (btn, on) => $(btn).classList.toggle('on', on);
$('btn-grid').addEventListener('click', () => { gridOn = !gridOn; canvas.classList.toggle('show-grid', gridOn); toggle('btn-grid', gridOn); render(); });
$('btn-snap').addEventListener('click', () => { snapOn = !snapOn; toggle('btn-snap', snapOn); });
$('btn-safe').addEventListener('click', () => toggle('btn-safe', canvas.classList.toggle('show-safe')));
$('btn-clean').addEventListener('click', () => toggle('btn-clean', canvas.classList.toggle('clean')));
$('btn-tokens').addEventListener('click', () => { showTokens = !showTokens; toggle('btn-tokens', showTokens); render(); });
$('btn-help').addEventListener('click', () => $('help-dlg').showModal());

// Sample data
document.querySelectorAll('[data-sample]').forEach(i => i.addEventListener('input', () => { sample[i.dataset.sample] = i.value; render(); }));
$('btn-long-name').addEventListener('click', () => {
  const inp = document.querySelector('[data-sample=name]'); if (!inp) return;
  const LONG = 'Prof. Dr. Maximiliana Alexandra Rodríguez-Montgomery';
  inp.value = inp.value === LONG ? ORIG_NAME : LONG; inp.dispatchEvent(new Event('input'));
  $('btn-long-name').textContent = inp.value === LONG ? 'Reset name' : 'Test long name';
});

// ── Zoom ────────────────────────────────────────────────
function baseScale() {
  const r = stage.getBoundingClientRect();
  return Math.max(0.5, Math.min((r.width - 80) / PW, (Math.max(r.height, 420) - 80) / PH));
}
function setZoom(s) { scale = Math.max(0.5, Math.min(40, s)); render(); }
function fit() { setZoom(baseScale()); }
$('zoom-in').addEventListener('click', () => setZoom(scale * 1.2));
$('zoom-out').addEventListener('click', () => setZoom(scale / 1.2));
$('zoom-label').addEventListener('click', fit);
stage.addEventListener('wheel', e => { if (e.ctrlKey || e.metaKey) { e.preventDefault(); setZoom(scale * (e.deltaY < 0 ? 1.1 : 1 / 1.1)); } }, {passive: false});
let resizeT; window.addEventListener('resize', () => { clearTimeout(resizeT); resizeT = setTimeout(fit, 120); });

// ── Context menu ────────────────────────────────────────
const ctx = $('ctx');
canvas.addEventListener('contextmenu', e => {
  const el = e.target.closest('.fld'); if (!el) return;
  e.preventDefault();
  const id = +el.dataset.id; if (!sel.includes(id)) select(id);
  const f = byId(id);
  const items = [
    ['fa-regular fa-copy', 'Duplicate', 'Ctrl D', duplicate],
    ['fa-solid fa-copy', 'Copy', 'Ctrl C', () => { clipboard = JSON.stringify(selFields()); }],
    ['fa-solid fa-arrow-up-wide-short', 'Bring to front', '', () => reorder(1)],
    ['fa-solid fa-arrow-down-short-wide', 'Send to back', '', () => reorder(-1)],
    ['fa-solid fa-align-center', 'Centre on page', '', () => { const s = sel; align('hcenter'); sel = s; }],
    [f.locked ? 'fa-solid fa-lock-open' : 'fa-solid fa-lock', f.locked ? 'Unlock' : 'Lock', '', () => updateSel(x => x.locked = !f.locked)],
    null,
    ['fa-regular fa-trash-can', 'Delete', 'Del', removeSel],
  ];
  ctx.innerHTML = '';
  items.forEach(it => {
    if (!it) { ctx.appendChild(document.createElement('hr')); return; }
    const b = document.createElement('button'); b.type = 'button';
    b.innerHTML = '<i class="' + it[0] + ' w-4 text-center text-gray-400"></i>' + it[1] + (it[2] ? '<span>' + it[2] + '</span>' : '');
    b.addEventListener('click', () => { ctx.style.display = 'none'; it[3](); });
    ctx.appendChild(b);
  });
  ctx.style.display = 'block';
  ctx.style.left = Math.min(e.clientX, innerWidth - 200) + 'px'; ctx.style.top = Math.min(e.clientY, innerHeight - 280) + 'px';
});
document.addEventListener('pointerdown', e => { if (!ctx.contains(e.target)) ctx.style.display = 'none'; });

// ── Keyboard ────────────────────────────────────────────
document.addEventListener('keydown', e => {
  const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) || document.querySelector('dialog[open]');
  const mod = e.ctrlKey || e.metaKey, k = e.key.toLowerCase();
  if (mod && k === 's') { e.preventDefault(); save(); return; }
  if (typing) return;
  if (mod && k === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
  if (mod && k === 'y') { e.preventDefault(); redo(); return; }
  if (mod && k === 'd') { e.preventDefault(); duplicate(); return; }
  if (mod && k === 'a') { e.preventDefault(); sel = fields.map(f => f._id); render(); return; }
  if (mod && k === 'c' && sel.length) { clipboard = JSON.stringify(selFields()); rarlToast('Copied ' + sel.length + ' element(s)', 'info'); return; }
  if (mod && k === 'v' && clipboard) {
    const copies = JSON.parse(clipboard).map(f => { const c = withId(Object.assign(f, {_id: undefined, locked: false})); setMm(c, xmm(c) + 3, ymm(c) + 3); return c; });
    fields.push(...copies); sel = copies.map(c => c._id); render(); commit(); return;
  }
  if (mod && (k === '=' || k === '+')) { e.preventDefault(); setZoom(scale * 1.2); return; }
  if (mod && k === '-') { e.preventDefault(); setZoom(scale / 1.2); return; }
  if (mod && k === '0') { e.preventDefault(); fit(); return; }
  if (k === 'delete' || k === 'backspace') { e.preventDefault(); removeSel(); return; }
  if (k === 'escape') { select(null); return; }
  if (k === 'p') { $('btn-clean').click(); return; }
  if (k === '?') { $('help-dlg').showModal(); return; }
  const arrows = {arrowleft: [-1, 0], arrowright: [1, 0], arrowup: [0, -1], arrowdown: [0, 1]};
  if (arrows[k] && sel.length) {
    e.preventDefault();
    const step = e.shiftKey ? 5 : 0.5;
    selFields().filter(f => !f.locked).forEach(f => setMm(f, xmm(f) + arrows[k][0] * step, ymm(f) + arrows[k][1] * step));
    render(); clearTimeout(window._nudgeT); window._nudgeT = setTimeout(commit, 400);
  }
});

// ── Save / test PDF ─────────────────────────────────────
const cleanConfig = () => snap();
async function save() {
  const btn = $('btn-save'), label = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<span class="rarl-spinner"></span> Saving…';
  const fd = new FormData();
  fd.append('action', 'save_config'); fd.append('acsrf', ACSRF); fd.append('template_id', TEMPLATE_ID);
  fd.append('config_json', cleanConfig()); fd.append('name', $('tpl-name').value.trim());
  try {
    const data = await (await fetch('templates.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
    if (!data.ok) throw new Error(data.error);
    savedSnap = snap(); $('tpl-name').defaultValue = $('tpl-name').value.trim(); syncDirty();
    rarlToast('Layout saved', 'success');
  } catch (err) { rarlToast(err.message || 'Save failed — check your connection.', 'error'); }
  btn.disabled = false; btn.innerHTML = label;
}
$('btn-save').addEventListener('click', save);
$('tpl-name').addEventListener('input', syncDirty);
$('tpl-name').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); } });
$('btn-test-pdf').addEventListener('click', () => {
  const f = $('pdf-form');
  f.config_json.value = cleanConfig(); f.sample_json.value = JSON.stringify(sample);
  f.submit();
});

// ── Boot ────────────────────────────────────────────────
savedSnap = snap(); undoStack = [savedSnap];
const bg = $('bg-img');
const boot = () => { fit(); samplePalette(); syncDirty(); if (!fields.length) rarlToast('Start by adding elements from the left panel', 'info'); };
if (bg.complete) boot(); else { bg.addEventListener('load', boot, {once: true}); bg.addEventListener('error', () => { fit(); paintSwatches(); }, {once: true}); }
})();
</script>
<?php endif; ?>
<?php }, 'templates', 'Templates');
