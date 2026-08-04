<?php
/**
 * RARL Admin — ID Card / Certificate Templates
 * Upload a background image, then visually drag-place text/QR/photo fields.
 * The saved config drives both the on-screen preview and the actual PDF output
 * (renderTemplateHtml / renderTemplatePdf in functions.php), so what you see
 * here is what gets generated and emailed.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_template') {
        $name = clean($_POST['name'] ?? '');
        $type = in_array($_POST['type'] ?? '', ['id_card','membership'], true) ? $_POST['type'] : 'certificate';
        $pw   = $type === 'id_card' ? 86.0 : 297.0;
        $ph   = $type === 'id_card' ? 54.0 : 210.0;

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
        header('Content-Type: application/json');
        echo $filename ? json_encode(['ok'=>true,'filename'=>$filename]) : json_encode(['ok'=>false]);
        exit;
    }

    if ($action === 'save_config') {
        $id = (int)($_POST['template_id'] ?? 0);
        $config = $_POST['config_json'] ?? '[]';
        json_decode($config); // validate
        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Invalid field config — not saved.'];
            header('Location: templates.php?edit=' . $id); exit;
        }
        $pdo->prepare("UPDATE certificate_templates SET config = ? WHERE id = ?")->execute([$config, $id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Field layout saved.'];
        header('Location: templates.php?edit=' . $id); exit;
    }

    if ($action === 'set_default') {
        $id = (int)($_POST['template_id'] ?? 0);
        $t  = $pdo->prepare("SELECT type FROM certificate_templates WHERE id = ?"); $t->execute([$id]); $type = $t->fetchColumn();
        if ($type) {
            $pdo->prepare("UPDATE certificate_templates SET is_default = 0 WHERE type = ?")->execute([$type]);
            $pdo->prepare("UPDATE certificate_templates SET is_default = 1 WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Set as default ' . ($TYPE_LABELS[$type] ?? $type) . ' template.'];
        }
        header('Location: templates.php'); exit;
    }

    if ($action === 'delete_template') {
        $id = (int)($_POST['template_id'] ?? 0);
        $row = $pdo->prepare("SELECT background_path FROM certificate_templates WHERE id = ?"); $row->execute([$id]);
        if ($bg = $row->fetchColumn()) @unlink(UPLOADS_PATH . '/templates/' . $bg);
        $pdo->prepare("DELETE FROM certificate_templates WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Template deleted.'];
        header('Location: templates.php'); exit;
    }
}

$templates = $pdo->query("SELECT * FROM certificate_templates ORDER BY type, is_default DESC, id DESC")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $e = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ?"); $e->execute([$editId]); $edit = $e->fetch();
}

adminWrap(function() use ($templates, $edit, $FIELD_KEYS, $TYPE_LABELS) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Templates</h1>
<p class="text-gray-500 text-sm mb-7">Upload a background image for ID cards or certificates, then drag fields onto it. This exact layout is used for the live PDF and preview.</p>

<?php if (!$edit): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-1">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4"><i class="fa-solid fa-plus"></i> New Template</h2>
      <form method="POST" enctype="multipart/form-data" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="create_template">
        <input type="text" name="name" required placeholder="Template name (e.g. 2026 Gold Certificate)"
          class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
        <select name="type" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50">
          <option value="certificate">Event Certificate (A4 landscape)</option>
          <option value="membership">Certificate of Membership (A4 landscape)</option>
          <option value="id_card">ID Card (credit-card size)</option>
        </select>
        <div class="border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-5 text-center relative">
          <div class="text-2xl mb-1"><i class="fa-solid fa-image"></i></div>
          <p class="text-xs text-gray-500">Upload background image</p>
          <input type="file" name="background" accept=".jpg,.jpeg,.png,.webp" required class="absolute inset-0 opacity-0 cursor-pointer w-full h-full"/>
        </div>
        <button type="submit" class="w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl">Create & Place Fields →</button>
      </form>
    </div>
  </div>
  <div class="lg:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm divide-y divide-gray-100">
      <?php if (empty($templates)): ?>
      <p class="p-8 text-center text-gray-400 text-sm">No templates yet. Create one on the left.</p>
      <?php endif; ?>
      <?php foreach ($templates as $t): ?>
      <div class="p-4 flex items-center gap-4 hover:bg-gray-50">
        <img src="../uploads/templates/<?= htmlspecialchars($t['background_path']) ?>" class="w-20 h-14 object-cover rounded-lg border border-gray-200 flex-shrink-0"/>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($t['name']) ?></p>
            <span class="text-[10px] font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars($TYPE_LABELS[$t['type']] ?? $t['type']) ?></span>
            <?php if ($t['is_default']): ?><span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded">Default</span><?php endif; ?>
          </div>
          <p class="text-xs text-gray-400 mt-0.5"><?= count(json_decode($t['config'] ?: '[]', true) ?: []) ?> fields placed</p>
        </div>
        <div class="flex gap-1.5 flex-shrink-0">
          <a href="templates.php?edit=<?= $t['id'] ?>" class="px-3 py-1.5 text-[10px] font-semibold bg-blue-50 text-blue-600 rounded-lg">Edit Fields</a>
          <?php if (!$t['is_default']): ?>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="set_default"><input type="hidden" name="template_id" value="<?= $t['id'] ?>">
            <button type="submit" class="px-3 py-1.5 text-[10px] font-semibold bg-green-50 text-green-600 rounded-lg">Set Default</button>
          </form>
          <?php endif; ?>
          <form method="POST" onsubmit="return confirm('Delete this template?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?= $t['id'] ?>">
            <button type="submit" class="px-3 py-1.5 text-[10px] font-semibold bg-red-50 text-red-600 rounded-lg">Delete</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php else: ?>
<?php
  $bgUrl = '../uploads/templates/' . htmlspecialchars($edit['background_path']);
  $keys  = $FIELD_KEYS[$edit['type']] ?? [];
  $config = json_decode($edit['config'] ?: '[]', true) ?: [];
?>
<a href="templates.php" class="inline-block text-xs text-gray-400 hover:text-gray-600 mb-4">← Back to templates</a>
<div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
  <div class="xl:col-span-3">
    <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
      <div id="canvas-wrap" style="position:relative;width:100%;aspect-ratio:<?= $edit['page_width_mm'] ?>/<?= $edit['page_height_mm'] ?>;background:#eee;border-radius:10px;overflow:hidden;user-select:none;">
        <img id="bg-img" src="<?= $bgUrl ?>" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;pointer-events:none;"/>
      </div>
      <p class="text-[11px] text-gray-400 mt-3">Drag a field onto the canvas, then reposition by dragging it. Click a field to edit its font size / color / alignment.</p>
    </div>
  </div>
  <div class="xl:col-span-1 space-y-4">
    <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
      <h3 class="font-heading font-bold text-sm text-gray-800 mb-3">Add Field</h3>
      <div class="space-y-1.5">
        <?php foreach ($keys as $k => $label): ?>
        <button type="button" onclick="addField('<?= $k ?>')" class="w-full text-left px-3 py-2 text-xs font-medium bg-gray-50 hover:bg-rarl-red/10 hover:text-rarl-red border border-gray-200 rounded-lg transition-colors">+ <?= htmlspecialchars($label) ?></button>
        <?php endforeach; ?>
        <button type="button" onclick="addCustomText()" class="w-full text-left px-3 py-2 text-xs font-medium bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 rounded-lg transition-colors"><i class="fa-solid fa-font"></i> Custom Text…</button>
        <button type="button" onclick="triggerSignatureUpload()" class="w-full text-left px-3 py-2 text-xs font-medium bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 rounded-lg transition-colors"><i class="fa-solid fa-signature"></i> Signature / Stamp Image…</button>
        <input type="file" id="signature-upload" accept=".jpg,.jpeg,.png,.webp" class="hidden"/>
      </div>
    </div>
    <div id="field-editor" class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hidden">
      <h3 class="font-heading font-bold text-sm text-gray-800 mb-3">Field Settings</h3>
      <div class="space-y-2.5">
        <div id="f-text-wrap" class="hidden"><label class="text-[10px] font-semibold text-gray-500">Text</label>
          <textarea id="f-text" rows="2" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-xs"></textarea></div>
        <div><label class="text-[10px] font-semibold text-gray-500">Font size</label>
          <input type="number" id="f-size" min="3" max="60" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-xs"/></div>
        <div><label class="text-[10px] font-semibold text-gray-500">Color</label>
          <input type="color" id="f-color" class="w-full h-8 border border-gray-300 rounded-lg"/></div>
        <div><label class="text-[10px] font-semibold text-gray-500">Align</label>
          <select id="f-align" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-xs">
            <option value="left">Left</option><option value="center">Center</option><option value="right">Right</option>
          </select></div>
        <label class="flex items-center gap-2 text-xs"><input type="checkbox" id="f-bold"/> Bold</label>
        <div><label class="text-[10px] font-semibold text-gray-500">Size (image fields, mm)</label>
          <input type="number" id="f-wh" min="5" max="200" class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-xs"/></div>
        <button type="button" onclick="removeSelected()" class="w-full py-1.5 text-[10px] font-semibold bg-red-50 text-red-600 rounded-lg">Remove Field</button>
      </div>
    </div>
    <form method="POST" id="save-form">
      <?= acsrfField() ?><input type="hidden" name="action" value="save_config">
      <input type="hidden" name="template_id" value="<?= $edit['id'] ?>">
      <input type="hidden" name="config_json" id="config_json">
      <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5"><i class="fa-solid fa-floppy-disk"></i> Save Layout</button>
    </form>
  </div>
</div>

<script>
let fields = <?= json_encode($config) ?>;
const wrap = document.getElementById('canvas-wrap');
let selected = null;
const ACSRF = <?= json_encode($GLOBALS['acsrf'] ?? '') ?>;
const TEMPLATE_ID = <?= (int)$edit['id'] ?>;
const PAGE_WIDTH_MM = <?= (float)$edit['page_width_mm'] ?>;
const IMAGE_KEYS = ['qr', 'avatar', 'signature'];
// Realistic placeholder values so the canvas looks like a filled-in card/certificate
// while editing, not a wall of raw {tokens} — matches the sample data used by
// "Preview Sample" (preview-template.php) so what you design here matches what you see there.
const SAMPLE_DATA = {
  name: 'Dr. Jane Sample', event: 'Sample Workshop 2026', date: '<?= date('d F Y') ?>',
  cert_no: 'RARL-2026-SAMPLE', member_code: 'RARL-000000', since_date: '<?= date('Y/m/d') ?>',
  section: 'Sample Chapter', signer1: 'RARL President', signer2: 'Chapter Chair',
};
// A generic person silhouette so the photo slot reads as a photo, not a blank box.
const AVATAR_PLACEHOLDER = 'data:image/svg+xml;base64,' + btoa('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#d1d5db"/><circle cx="50" cy="38" r="18" fill="#9ca3af"/><ellipse cx="50" cy="88" rx="30" ry="24" fill="#9ca3af"/></svg>');
const QR_PLACEHOLDER = 'data:image/svg+xml;base64,' + btoa('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="#fff" stroke="#ccc"/><text x="30" y="34" font-size="9" text-anchor="middle" fill="#999">QR CODE</text></svg>');

function render() {
  wrap.querySelectorAll('.field-el').forEach(el => el.remove());
  const pxPerMm900 = wrap.offsetWidth / 900; // matches the 900px-wide reference used by the static "Preview Sample" page
  fields.forEach((f, i) => {
    const el = document.createElement('div');
    el.className = 'field-el';
    el.style.position = 'absolute';
    el.style.left = f.x + '%';
    el.style.top = f.y + '%';
    el.style.cursor = 'move';
    el.dataset.idx = i;
    el.addEventListener('mousedown', startDrag);
    el.addEventListener('click', (e) => { e.stopPropagation(); selectField(i); });

    if (IMAGE_KEYS.includes(f.key)) {
      el.style.width = (f.w || 15) + '%';
      const img = document.createElement('img');
      img.src = f.key === 'signature' ? (f.image ? '../uploads/templates/' + f.image : QR_PLACEHOLDER)
              : f.key === 'avatar' ? AVATAR_PLACEHOLDER : QR_PLACEHOLDER;
      img.style.width = '100%';
      img.style.display = 'block';
      img.style.outline = selected === i ? '2px solid #E11D2A' : '1px dashed rgba(225,29,42,.5)';
      el.appendChild(img);
    } else {
      el.style.padding = '2px 4px';
      el.style.fontSize = (f.font_size || 12) * 1.5 * pxPerMm900 + 'px';
      el.style.color = f.color || '#111';
      el.style.fontWeight = f.bold ? 'bold' : 'normal';
      el.style.fontFamily = 'Helvetica, Arial, sans-serif';
      el.style.outline = selected === i ? '2px solid #E11D2A' : '1px dashed rgba(225,29,42,.5)';
      el.style.background = selected === i ? 'rgba(225,29,42,.08)' : 'transparent';
      el.style.whiteSpace = 'nowrap';
      el.style.transform = f.align === 'center' ? 'translateX(-50%)' : (f.align === 'right' ? 'translateX(-100%)' : 'none');
      el.textContent = f.key === 'custom_text' ? (f.text || '(empty text — click to set)') : (SAMPLE_DATA[f.key] ?? ('{' + f.key + '}'));
    }
    wrap.appendChild(el);
  });
}

function addField(key) {
  fields.push({key, x: 40, y: 40, font_size: 12, color: '#111111', align: 'left', bold: false, w: 15, h: 15});
  render();
  selectField(fields.length - 1);
}

function addCustomText() {
  const text = prompt('Text to show on the card (e.g. "Robotics & Automation Research Lab"):');
  if (!text) return;
  fields.push({key: 'custom_text', text, x: 40, y: 40, font_size: 12, color: '#111111', align: 'left', bold: false});
  render();
  selectField(fields.length - 1);
}

function triggerSignatureUpload() {
  document.getElementById('signature-upload').click();
}
document.getElementById('signature-upload').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('action', 'upload_field_image');
  fd.append('acsrf', ACSRF);
  fd.append('field_image', file);
  const res = await fetch('templates.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    fields.push({key: 'signature', image: data.filename, x: 40, y: 40, w: 20, h: 12});
    render();
    selectField(fields.length - 1);
  } else {
    alert('Upload failed — check file type/size (max 3MB, jpg/png/webp).');
  }
  e.target.value = '';
});

function selectField(i) {
  selected = i;
  const f = fields[i];
  document.getElementById('field-editor').classList.remove('hidden');
  const isText = f.key === 'custom_text';
  const isImage = IMAGE_KEYS.includes(f.key);
  document.getElementById('f-text-wrap').classList.toggle('hidden', !isText);
  if (isText) document.getElementById('f-text').value = f.text || '';
  document.getElementById('f-size').value = f.font_size || 12;
  document.getElementById('f-color').value = f.color || '#111111';
  document.getElementById('f-align').value = f.align || 'left';
  document.getElementById('f-bold').checked = !!f.bold;
  document.getElementById('f-wh').value = f.w || 15;
  document.getElementById('f-size').closest('div').style.display = isImage ? 'none' : '';
  document.getElementById('f-color').closest('div').style.display = isImage ? 'none' : '';
  document.getElementById('f-align').closest('div').style.display = isImage ? 'none' : '';
  render();
}
['f-size','f-color','f-align','f-bold','f-wh','f-text'].forEach(id => {
  document.getElementById(id).addEventListener('input', () => {
    if (selected === null) return;
    const f = fields[selected];
    f.font_size = parseFloat(document.getElementById('f-size').value) || 12;
    f.color = document.getElementById('f-color').value;
    f.align = document.getElementById('f-align').value;
    f.bold = document.getElementById('f-bold').checked;
    f.w = f.h = parseFloat(document.getElementById('f-wh').value) || 15;
    if (f.key === 'custom_text') f.text = document.getElementById('f-text').value;
    render();
  });
});
function removeSelected() {
  if (selected === null) return;
  fields.splice(selected, 1);
  selected = null;
  document.getElementById('field-editor').classList.add('hidden');
  render();
}

let dragIdx = null;
function startDrag(e) {
  // currentTarget (the wrapper div the listener was bound to), not target —
  // image fields (avatar/QR/signature) have a nested <img>, so a click on the
  // photo itself makes e.target the <img> (no dataset.idx), breaking drag.
  dragIdx = parseInt(e.currentTarget.dataset.idx);
  e.preventDefault();
}
document.addEventListener('mousemove', (e) => {
  if (dragIdx === null) return;
  const rect = wrap.getBoundingClientRect();
  let x = (e.clientX - rect.left) / rect.width * 100;
  let y = (e.clientY - rect.top) / rect.height * 100;
  x = Math.max(0, Math.min(100, x));
  y = Math.max(0, Math.min(100, y));
  fields[dragIdx].x = Math.round(x * 10) / 10;
  fields[dragIdx].y = Math.round(y * 10) / 10;
  render();
});
document.addEventListener('mouseup', () => { dragIdx = null; });

document.getElementById('save-form').addEventListener('submit', () => {
  document.getElementById('config_json').value = JSON.stringify(fields);
});

render();
</script>
<?php endif; ?>
<?php }, 'templates', 'Templates');
