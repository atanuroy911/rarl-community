<?php
/**
 * RARL Admin — Membership certificates.
 * Settings (automatic issue on approval: off by default; keep certificates in
 * step with the design: on), the default membership design with a live
 * preview, and batch actions to issue missing certificates or regenerate
 * outdated ones. Regenerating keeps each certificate's number and verify link.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if (!adminCsrfOk()) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']); exit; }
        header('Location: membership-certificates.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    // Batch step for the progress bar: a few certificates per request.
    if ($action === 'batch') {
        session_write_close();
        @set_time_limit(60);
        $op = $_POST['op'] ?? '';
        $done = $op === 'issue' ? issueMissingMembershipCerts(8, 20) : ($op === 'regen' ? regenerateOutdatedMembershipCerts(8, 20) : 0);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'done' => $done, 'stats' => membershipCertStats()]);
        exit;
    }

    if ($action === 'save_settings') {
        settingSet('membership_cert_auto', !empty($_POST['auto']) ? '1' : '0');
        settingSet('membership_cert_autoregen', !empty($_POST['autoregen']) ? '1' : '0');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Membership certificate settings saved.'];
    }

    if ($action === 'set_design') {
        $id = (int)($_POST['template_id'] ?? 0);
        $t = $pdo->prepare("SELECT id FROM certificate_templates WHERE id = ? AND type = 'membership'");
        $t->execute([$id]);
        if ($t->fetchColumn()) {
            $pdo->exec("UPDATE certificate_templates SET is_default = 0 WHERE type = 'membership'");
            $pdo->prepare("UPDATE certificate_templates SET is_default = 1 WHERE id = ?")->execute([$id]);
            $n = membershipCertStats()['outdated'];
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Default membership design updated.' . ($n ? ' ' . $n . ' certificate(s) will be updated to match.' : '')];
        }
    }
    header('Location: membership-certificates.php'); exit;
}

$templates = $pdo->query("SELECT * FROM certificate_templates WHERE type = 'membership' ORDER BY is_default DESC, name")->fetchAll();
$default = null;
foreach ($templates as $t) if ($t['is_default']) $default = $t;
$stats = membershipCertStats();

// Preview with a real member when there is one, so long names and real codes show.
$sample = templateSampleData('membership');
$real = $pdo->query("SELECT * FROM members WHERE status = 'active' AND " . REAL_MEMBER_SQL . " ORDER BY id DESC LIMIT 1")->fetch();
if ($real) $sample = array_merge($sample, array_diff_key(membershipCertData($real, $sample['cert_no'] ?? 'RARL-2026-0000', '00000000-0000-0000-0000-000000000000'), ['_section' => 1]));
$recent = $pdo->query("SELECT c.id, c.certificate_no, c.recipient_name, c.issued_at, c.pdf_path, c.design_sig FROM certificates c WHERE c.cert_type = 'membership' ORDER BY c.id DESC LIMIT 6")->fetchAll();

adminWrap(function () use ($templates, $default, $stats, $sample, $real, $recent) {
    adminFlash();
    $auto = membershipCertAutoIssue();
    $autoregen = membershipCertAutoRegen();
    $input = 'w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red';
?>
<div class="rarl-page-head">
  <div>
    <h1>Membership certificates</h1>
    <p>Choose the design, decide when certificates are issued, and keep every certificate matching the current design.</p>
  </div>
  <a href="certificates.php?view=history&status=membership" class="rarl-btn"><i class="fa-solid fa-list"></i> View all</a>
</div>

<div class="grid sm:grid-cols-4 gap-3 mb-5">
  <?php foreach ([['Active members', $stats['active'], 'text-gray-900'], ['Certificates issued', $stats['issued'], 'text-green-700'], ['Members without one', $stats['missing'], $stats['missing'] ? 'text-amber-700' : 'text-gray-900'], ['Using an old design', $stats['outdated'], $stats['outdated'] ? 'text-blue-700' : 'text-gray-900']] as [$l, $v, $tone]): ?>
  <div class="rarl-card p-4"><p class="text-xs text-gray-500"><?= $l ?></p><p class="text-2xl font-bold <?= $tone ?>" data-stat="<?= ex($l) ?>"><?= number_format($v) ?></p></div>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] gap-5 items-start">
  <section class="rarl-card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <h2 class="font-semibold text-gray-900">Design</h2>
      <?php if ($default): ?><a href="templates.php?edit=<?= (int)$default['id'] ?>" class="text-sm font-semibold text-rarl-red hover:underline"><i class="fa-solid fa-pen-ruler"></i> Edit this design</a><?php endif; ?>
    </div>
    <?php if (!$templates): ?>
      <div class="p-6 text-center text-sm text-gray-600 border-2 border-dashed border-gray-200 rounded-xl">
        <p class="font-semibold text-gray-800">No membership design yet</p>
        <p class="mt-1">Certificates use a simple built-in layout until you add one. Install the <b>Gold Frame — Membership</b> design or upload your own in Designs.</p>
        <a href="templates.php#premade" class="inline-flex mt-3 px-4 py-2 bg-rarl-red text-white text-sm font-semibold rounded-xl">Open Designs</a>
      </div>
    <?php else: ?>
      <form method="POST" class="flex flex-col sm:flex-row gap-2 mb-3" id="design-form">
        <?= acsrfField() ?><input type="hidden" name="action" value="set_design">
        <label class="sr-only" for="design-select">Default design</label>
        <select id="design-select" name="template_id" class="<?= $input ?>">
          <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['is_default'] ? 'selected' : '' ?>><?= ex($t['name']) ?><?= $t['is_default'] ? ' (current)' : '' ?></option><?php endforeach; ?>
        </select>
        <button id="design-save" class="rarl-btn rarl-btn-primary whitespace-nowrap" hidden><i class="fa-solid fa-check"></i> Use this design</button>
      </form>
      <div class="rounded-xl overflow-hidden border border-gray-100 bg-gray-50">
        <?php foreach ($templates as $t): ?>
        <div data-preview="<?= (int)$t['id'] ?>" class="<?= $t['is_default'] ? '' : 'hidden' ?> pointer-events-none"><?= renderTemplateHtml($t, $sample) ?></div>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-gray-500 mt-2">Preview with <?= $real ? 'the latest member, ' . ex(properName((string)$sample['name'])) : 'sample details' ?>. Changing the design updates existing certificates too<?= $autoregen ? '' : ' (when you press Update)' ?>; numbers and verification links stay the same.</p>
    <?php endif; ?>
  </section>

  <div class="space-y-5">
    <section class="rarl-card p-5">
      <h2 class="font-semibold text-gray-900 mb-3">Settings</h2>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="save_settings">
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?> class="mt-1 w-4 h-4 accent-rarl-red">
          <span><span class="block text-sm font-semibold text-gray-900">Issue automatically on approval</span><span class="block text-xs text-gray-500">When off, members only get a certificate when you issue it here.</span></span>
        </label>
        <label class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" name="autoregen" value="1" <?= $autoregen ? 'checked' : '' ?> class="mt-1 w-4 h-4 accent-rarl-red">
          <span><span class="block text-sm font-semibold text-gray-900">Keep certificates up to date with the design</span><span class="block text-xs text-gray-500">When the default design or its layout changes, existing certificates are regenerated in the background.</span></span>
        </label>
        <button class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
      </form>
    </section>

    <section class="rarl-card p-5">
      <h2 class="font-semibold text-gray-900 mb-3">Actions</h2>
      <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="rarl-btn" data-run="issue" <?= $stats['missing'] ? '' : 'disabled' ?>><i class="fa-solid fa-award"></i> Issue to <span data-count="missing"><?= number_format($stats['missing']) ?></span> member(s) without one</button>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="rarl-btn" data-run="regen" <?= $stats['outdated'] ? '' : 'disabled' ?>><i class="fa-solid fa-rotate"></i> Update <span data-count="outdated"><?= number_format($stats['outdated']) ?></span> to the current design</button>
        </div>
        <div id="run-progress" hidden>
          <div class="h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-rarl-red transition-all" style="width:0"></div></div>
          <p class="text-xs text-gray-600 mt-1.5" data-run-label></p>
        </div>
        <p class="text-xs text-gray-500">Issued certificates are not emailed automatically; members find them on their Membership page.</p>
      </div>
    </section>

    <?php if ($recent): ?>
    <section class="rarl-card p-5">
      <h2 class="font-semibold text-gray-900 mb-2">Latest</h2>
      <ul class="divide-y divide-gray-100 text-sm">
        <?php foreach ($recent as $c): $old = $c['design_sig'] !== $stats['sig']; ?>
        <li class="flex items-center gap-3 py-2">
          <span class="flex-1 min-w-0"><span class="block font-medium text-gray-900 truncate"><?= ex(properName($c['recipient_name'])) ?></span><span class="block text-xs text-gray-500"><?= ex($c['certificate_no']) ?> · <?= date('d M Y', strtotime($c['issued_at'])) ?><?= $old ? ' · <span class="text-blue-700">old design</span>' : '' ?></span></span>
          <?php if ($c['pdf_path']): ?><a class="rarl-icon-btn" title="Open PDF" target="_blank" href="../uploads/certificates/<?= rawurlencode(basename($c['pdf_path'])) ?>"><i class="fa-solid fa-file-pdf"></i></a><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  const sel = document.getElementById('design-select'), save = document.getElementById('design-save');
  const current = sel ? sel.value : null;
  sel?.addEventListener('change', () => {
    document.querySelectorAll('[data-preview]').forEach(p => p.classList.toggle('hidden', p.dataset.preview !== sel.value));
    save.hidden = sel.value === current;
  });

  const box = document.getElementById('run-progress'), bar = box.querySelector('div > div'), label = box.querySelector('[data-run-label]');
  const csrf = <?= json_encode($GLOBALS['acsrf'] ?? '') ?>;
  let running = false;
  async function run(op) {
    if (running) return;
    running = true;
    const btns = document.querySelectorAll('[data-run]'); btns.forEach(b => b.disabled = true);
    const start = op === 'issue' ? <?= (int)$stats['missing'] ?> : +document.querySelector('[data-count="outdated"]').textContent.replace(/\D/g, '');
    let done = 0;
    box.hidden = false;
    try {
      for (;;) {
        const fd = new FormData(); fd.append('action', 'batch'); fd.append('op', op); fd.append('acsrf', csrf);
        const d = await (await fetch('membership-certificates.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
        if (!d.ok) throw new Error(d.error || 'Failed');
        done += d.done;
        const left = op === 'issue' ? d.stats.missing : d.stats.outdated;
        const total = Math.max(start, done + left, 1);
        bar.style.width = Math.round(done / total * 100) + '%';
        label.textContent = (op === 'issue' ? 'Issued ' : 'Updated ') + done + ' of ' + total + '…';
        document.querySelector('[data-count="missing"]').textContent = d.stats.missing;
        document.querySelector('[data-count="outdated"]').textContent = d.stats.outdated;
        if (!left || !d.done) break;
      }
      label.textContent = (op === 'issue' ? 'Issued ' : 'Updated ') + done + ' certificate(s).';
      window.rarlToast && rarlToast(label.textContent, 'success');
      setTimeout(() => location.reload(), 1200);
    } catch (e) {
      label.textContent = 'Stopped: ' + e.message;
      btns.forEach(b => b.disabled = false);
    }
    running = false;
  }
  document.querySelectorAll('[data-run]').forEach(b => b.addEventListener('click', () => run(b.dataset.run)));
  // Design just changed and auto-update is on: bring certificates up to date now, while this page is open.
  <?php if ($autoregen && $stats['outdated'] > 0): ?>run('regen');<?php endif; ?>
})();
</script>
<?php }, 'membership-certs', 'Membership certificates');
