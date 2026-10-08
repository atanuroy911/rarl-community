<?php
/**
 * RARL Admin — Event Attendees
 * Per-event registrations. Marking someone "Attended" issues their
 * certificate via issueCertificateForAttendance() (designed template + email);
 * bulk marking queues the emails so big events never time out. Check-in mode
 * is a fast, search-first view for marking attendance at the door.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$eventId = (int)($_GET['event'] ?? $_POST['event_id'] ?? 0);
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();
if (!$event) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Event not found.']; header('Location: events.php'); exit; }

function regMarkAttended(PDO $pdo, array $event, int $regId, ?string $batch): bool {
    $reg = $pdo->prepare("SELECT * FROM event_registrations WHERE id = ? AND event_id = ?");
    $reg->execute([$regId, $event['id']]);
    if (!$r = $reg->fetch()) return false;
    $pdo->prepare("UPDATE event_registrations SET status='attended' WHERE id=?")->execute([$regId]);
    $m = $pdo->prepare('SELECT * FROM members WHERE id = ?'); $m->execute([$r['member_id']]);
    if ($member = $m->fetch()) issueCertificateForAttendance($pdo, $event, $member, $batch);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reply = function (string $type, string $msg) use ($isAjax, $eventId) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => $type === 'success', 'msg' => $msg]); exit; }
        $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
        header('Location: event-registrations.php?event=' . $eventId . (!empty($_POST['return_qs']) ? '&' . preg_replace('/[^\w=&%.+-]/', '', $_POST['return_qs']) : '')); exit;
    };
    if (!adminCsrfOk()) $reply('error', 'Your session expired — reload the page.');
    $action = $_POST['action'] ?? '';
    $regId = (int)($_POST['reg_id'] ?? 0);

    if ($action === 'mark_attended') {
        regMarkAttended($pdo, $event, $regId, null) ? $reply('success', 'Checked in — certificate issued and emailed.') : $reply('error', 'Registration not found.');
    }
    if ($action === 'mark_no_show' || $action === 'reset') {
        $pdo->prepare("UPDATE event_registrations SET status=? WHERE id=? AND event_id=?")->execute([$action === 'reset' ? 'registered' : 'no_show', $regId, $eventId]);
        $reply('success', $action === 'reset' ? 'Moved back to registered.' : 'Marked as no-show.');
    }
    if ($action === 'add_attendee') {
        $email = cleanEmail($_POST['email'] ?? '');
        $m = $pdo->prepare("SELECT id FROM members WHERE email = ?"); $m->execute([$email]);
        if (!$mid = $m->fetchColumn()) $reply('error', 'No member account uses ' . $email . '. They need to join first.');
        $pdo->prepare("INSERT INTO event_registrations (event_id, member_id, status) VALUES (?,?,'registered') ON DUPLICATE KEY UPDATE status = IF(status='cancelled','registered',status)")->execute([$eventId, $mid]);
        $reply('success', 'Added to the attendee list.');
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $op = $_POST['bulk_op'] ?? '';
        if (!$ids) $reply('error', 'Select at least one person first.');
        @set_time_limit(0);
        if ($op === 'attended') {
            $batch = newEmailBatch(); $n = 0;
            foreach ($ids as $id) if (regMarkAttended($pdo, $event, $id, $batch)) $n++;
            $reply('success', "{$n} marked attended. Certificates issued — emails are sending in the background.");
        }
        if ($op === 'no_show' || $op === 'reset') {
            $in = implode(',', $ids);
            $pdo->prepare("UPDATE event_registrations SET status=? WHERE event_id=? AND id IN ({$in})")->execute([$op === 'reset' ? 'registered' : 'no_show', $eventId]);
            $reply('success', count($ids) . ' updated.');
        }
    }
    if ($action === 'close_out') {
        $n = $pdo->prepare("UPDATE event_registrations SET status='no_show' WHERE event_id=? AND status='registered'"); $n->execute([$eventId]);
        $reply('success', $n->rowCount() . ' remaining registration(s) marked as no-show.');
    }
    $reply('error', 'Unknown action.');
}

$regs = $pdo->prepare("SELECT r.*, m.full_name, m.lab_name, m.type, m.email, m.avatar_path, m.institution, m.country,
        (SELECT c.pdf_path FROM certificates c WHERE c.event_id = r.event_id AND c.recipient_email = m.email LIMIT 1) AS cert_pdf
    FROM event_registrations r JOIN members m ON m.id = r.member_id
    WHERE r.event_id = ?
    ORDER BY FIELD(r.status,'registered','attended','no_show','cancelled'), COALESCE(m.full_name, m.lab_name)");
$regs->execute([$eventId]);
$all = $regs->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendees-' . $eventId . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['name', 'email', 'institution', 'country', 'status', 'registered_at']);
    foreach ($all as $r) fputcsv($out, [$r['type'] === 'lab' ? $r['lab_name'] : $r['full_name'], $r['email'], $r['institution'], $r['country'], $r['status'], $r['registered_at']]);
    exit;
}

$counts = ['all' => count($all), 'registered' => 0, 'attended' => 0, 'no_show' => 0, 'cancelled' => 0];
foreach ($all as $r) $counts[$r['status']]++;
$tab = in_array($_GET['tab'] ?? '', ['registered','attended','no_show','cancelled'], true) ? $_GET['tab'] : 'all';
$rows = $tab === 'all' ? $all : array_values(array_filter($all, fn($r) => $r['status'] === $tab));
$isPast = $event['event_date'] && $event['event_date'] < date('Y-m-d');
$qs = $tab !== 'all' ? 'tab=' . $tab : '';

adminWrap(function() use ($event, $rows, $all, $counts, $tab, $isPast, $eventId, $qs) {
    adminFlash();
    $emails = implode(', ', array_column(array_filter($all, fn($r) => $r['status'] !== 'cancelled'), 'email'));
?>
<a href="events.php" class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-rarl-red mb-3"><i class="fa-solid fa-arrow-left"></i> Events</a>
<div class="rarl-page-head">
  <div>
    <h1><?= htmlspecialchars($event['title']) ?></h1>
    <p><?= $event['event_date'] ? date('l, d F Y', strtotime($event['event_date'])) . ($event['event_time'] ? ' · ' . date('g:i A', strtotime($event['event_time'])) : '') : 'No date set' ?><?= $event['location'] ? ' · ' . htmlspecialchars($event['location']) : '' ?></p>
  </div>
  <div class="flex flex-wrap gap-2">
    <button type="button" class="rarl-btn rarl-btn-primary" id="btn-checkin"><i class="fa-solid fa-clipboard-check"></i> Check-in mode</button>
    <button type="button" class="rarl-btn" onclick="document.getElementById('add-dlg').showModal()"><i class="fa-solid fa-user-plus"></i> Add attendee</button>
    <button type="button" class="rarl-btn" data-copy="<?= htmlspecialchars($emails) ?>" <?= $emails ? '' : 'disabled' ?>><i class="fa-regular fa-copy"></i> Copy emails</button>
    <a href="event-registrations.php?event=<?= $eventId ?>&export=csv" class="rarl-btn"><i class="fa-solid fa-download"></i> CSV</a>
  </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
  <?php foreach ([
    ['registered', 'Still to check in', 'fa-hourglass-half', 'text-blue-600'],
    ['attended', 'Attended', 'fa-circle-check', 'text-green-600'],
    ['no_show', 'No-show', 'fa-user-xmark', 'text-gray-500'],
    ['cancelled', 'Cancelled', 'fa-ban', 'text-red-500'],
  ] as [$k, $l, $ic, $tone]): ?>
  <a href="?event=<?= $eventId ?>&tab=<?= $k ?>" class="rarl-stat <?= $tab === $k ? 'is-active' : '' ?>">
    <span class="rarl-stat-label"><i class="fa-solid <?= $ic ?> <?= $tone ?>"></i> <?= $l ?></span>
    <span class="rarl-stat-value"><?= $counts[$k] ?><?= $k === 'registered' && $event['capacity'] ? '<span class="text-sm text-gray-400 font-semibold"> / ' . (int)$event['capacity'] . ' seats</span>' : '' ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($isPast && $counts['registered']): ?>
<div class="rarl-card p-4 mb-5 flex flex-wrap items-center gap-3 border-amber-200 bg-amber-50/60">
  <i class="fa-solid fa-flag-checkered text-amber-600"></i>
  <p class="text-sm text-amber-900 flex-1">This event is over and <strong><?= $counts['registered'] ?></strong> people were never checked in. Mark the ones who came, then close out the rest.</p>
  <form method="POST" data-confirm="Mark all <?= $counts['registered'] ?> remaining registrations as no-show?" data-confirm-ok="Close out"><?= acsrfField() ?><input type="hidden" name="action" value="close_out"><input type="hidden" name="event_id" value="<?= $eventId ?>">
    <button class="rarl-btn rarl-btn-sm">Mark the rest as no-show</button></form>
</div>
<?php endif; ?>

<div class="rarl-card" id="list-view">
  <div class="p-3 border-b border-gray-100 flex flex-wrap items-center gap-2">
    <nav class="rarl-tabs">
      <?php foreach (['all' => 'All', 'registered' => 'Registered', 'attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'] as $k => $l): ?>
      <a href="?event=<?= $eventId ?><?= $k !== 'all' ? '&tab=' . $k : '' ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $l ?> <span class="count"><?= $counts[$k] ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="relative ml-auto">
      <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
      <input type="search" id="filter" placeholder="Filter by name or email…   /" class="rarl-input !pl-8 !w-64"/>
    </div>
  </div>
  <?= bulkFormOpen('', ['event_id' => (string)$eventId, 'return_qs' => $qs]) ?>
  <div class="px-3 pt-3"><?= bulkBar([
      ['label' => '<i class="fa-solid fa-check"></i> Mark attended & issue certificates', 'op' => 'attended', 'class' => 'bg-green-600 hover:bg-green-500', 'confirm' => 'Mark the selected people as attended? Each gets a certificate by email.'],
      ['label' => 'No-show', 'op' => 'no_show', 'class' => 'bg-gray-600 hover:bg-gray-500'],
      ['label' => 'Reset to registered', 'op' => 'reset', 'class' => 'bg-gray-700 hover:bg-gray-600'],
  ]) ?></div>
  <div class="overflow-x-auto">
    <table class="rarl-table">
      <thead><tr><th class="w-8"><?= bulkSelectAllCheckbox() ?></th><th>Attendee</th><th>Registered</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="rarl-empty"><?= $tab === 'all' ? 'No one has registered yet. Share the event or add attendees manually.' : 'Nobody in this list.' ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
          $name = ($r['type'] === 'lab' ? $r['lab_name'] : $r['full_name']) ?: $r['email'];
          $badge = ['registered' => ['rarl-badge-blue', 'Registered'], 'attended' => ['rarl-badge-green', 'Attended'], 'no_show' => ['rarl-badge-gray', 'No-show'], 'cancelled' => ['rarl-badge-red', 'Cancelled']][$r['status']];
        ?>
        <tr data-search="<?= htmlspecialchars(mb_strtolower($name . ' ' . $r['email'])) ?>">
          <td><?= bulkRowCheckbox((int)$r['id']) ?></td>
          <td><div class="flex items-center gap-3"><?= memberAvatarHtml($r['avatar_path'] ?? null, $name, 'w-8 h-8 text-xs') ?><div class="min-w-0"><p class="font-semibold text-gray-900 truncate max-w-[240px]"><?= htmlspecialchars($name) ?></p><p class="text-[11px] text-gray-400 truncate max-w-[240px]"><?= htmlspecialchars($r['email']) ?><?= $r['institution'] ? ' · ' . htmlspecialchars($r['institution']) : '' ?></p></div></div></td>
          <td class="text-[11px] text-gray-500 whitespace-nowrap" data-sort="<?= htmlspecialchars($r['registered_at']) ?>"><?= date('d M Y, H:i', strtotime($r['registered_at'])) ?></td>
          <td><span class="rarl-badge <?= $badge[0] ?>"><?= $badge[1] ?></span></td>
          <td class="text-right whitespace-nowrap">
            <?php if ($r['status'] === 'registered' || $r['status'] === 'no_show'): ?>
            <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="mark_attended"><input type="hidden" name="reg_id" value="<?= $r['id'] ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
              <button class="rarl-btn rarl-btn-sm rarl-btn-primary"><i class="fa-solid fa-check"></i> Attended</button></form>
            <?php endif; ?>
            <?php if ($r['status'] === 'registered'): ?>
            <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="mark_no_show"><input type="hidden" name="reg_id" value="<?= $r['id'] ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
              <button class="rarl-btn rarl-btn-sm">No-show</button></form>
            <?php elseif ($r['status'] === 'attended'): ?>
              <?php if ($r['cert_pdf']): ?><a href="../uploads/certificates/<?= urlencode($r['cert_pdf']) ?>" target="_blank" class="rarl-btn rarl-btn-sm"><i class="fa-solid fa-trophy"></i> Certificate</a><?php endif; ?>
            <?php elseif ($r['status'] === 'no_show'): ?>
            <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="reg_id" value="<?= $r['id'] ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
              <button class="rarl-icon-btn" title="Undo — back to registered"><i class="fa-solid fa-rotate-left"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Check-in mode: big tap targets, search-first, no page reloads -->
<div id="checkin" class="hidden fixed inset-0 z-[80] bg-gray-950 text-white flex flex-col">
  <div class="p-4 sm:p-6 flex items-center gap-3 border-b border-white/10">
    <div class="flex-1 min-w-0"><p class="text-xs text-white/50 uppercase tracking-wider font-bold">Check-in</p><h2 class="font-heading font-black text-xl truncate"><?= htmlspecialchars($event['title']) ?></h2></div>
    <div class="text-right"><p class="font-heading font-black text-2xl"><span id="ci-done"><?= $counts['attended'] ?></span><span class="text-white/40">/<?= $counts['all'] - $counts['cancelled'] ?></span></p><p class="text-[11px] text-white/50">checked in</p></div>
    <button type="button" id="ci-close" class="w-11 h-11 rounded-xl bg-white/10 hover:bg-white/20 text-lg" aria-label="Exit check-in"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="p-4 sm:px-6"><input id="ci-search" type="search" placeholder="Type a name or email…" class="w-full h-14 px-5 rounded-2xl bg-white/10 border border-white/10 text-lg placeholder-white/40 focus:outline-none focus:border-white/40" autocomplete="off"/></div>
  <div class="flex-1 overflow-y-auto px-4 sm:px-6 pb-6 grid gap-2 content-start" id="ci-list">
    <?php foreach ($all as $r): if ($r['status'] === 'cancelled') continue; $name = ($r['type'] === 'lab' ? $r['lab_name'] : $r['full_name']) ?: $r['email']; ?>
    <button type="button" class="ci-row flex items-center gap-4 p-4 rounded-2xl text-left transition-colors <?= $r['status'] === 'attended' ? 'bg-green-600/25' : 'bg-white/5 hover:bg-white/10' ?>" data-reg="<?= (int)$r['id'] ?>" data-done="<?= $r['status'] === 'attended' ? 1 : 0 ?>" data-search="<?= htmlspecialchars(mb_strtolower($name . ' ' . $r['email'])) ?>">
      <span class="w-11 h-11 rounded-full flex-shrink-0 flex items-center justify-center text-lg ci-icon <?= $r['status'] === 'attended' ? 'bg-green-500' : 'bg-white/10' ?>"><i class="fa-solid <?= $r['status'] === 'attended' ? 'fa-check' : 'fa-user' ?>"></i></span>
      <span class="min-w-0 flex-1"><span class="block font-semibold text-base truncate"><?= htmlspecialchars($name) ?></span><span class="block text-xs text-white/50 truncate"><?= htmlspecialchars($r['email']) ?></span></span>
      <span class="text-xs font-semibold ci-label <?= $r['status'] === 'attended' ? 'text-green-300' : 'text-white/40' ?>"><?= $r['status'] === 'attended' ? 'Checked in' : 'Tap to check in' ?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>

<dialog id="add-dlg" class="rarl-dialog w-full max-w-md">
  <form method="POST" class="p-6 space-y-4"><?= acsrfField() ?><input type="hidden" name="action" value="add_attendee"><input type="hidden" name="event_id" value="<?= $eventId ?>">
    <div class="flex items-center justify-between"><h2 class="font-heading font-bold text-lg">Add attendee</h2><button type="button" onclick="this.closest('dialog').close()" class="rarl-icon-btn"><i class="fa-solid fa-xmark"></i></button></div>
    <p class="text-sm text-gray-500">For walk-ins or people who registered elsewhere. They need a member account.</p>
    <div><label class="rarl-label">Member email</label><input type="email" name="email" required class="rarl-input" placeholder="name@example.com"/></div>
    <div class="flex justify-end gap-2"><button type="button" class="rarl-btn" onclick="this.closest('dialog').close()">Cancel</button><button class="rarl-btn rarl-btn-primary">Add</button></div>
  </form>
</dialog>

<?= bulkBarScript() ?>
<script>
(function() {
  const ACSRF = <?= json_encode($GLOBALS['acsrf'] ?? '') ?>, EVENT = <?= (int)$eventId ?>;
  document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => navigator.clipboard.writeText(b.dataset.copy).then(() => rarlToast('Emails copied — paste into Compose Email', 'success'))));
  const filter = document.getElementById('filter');
  filter.addEventListener('input', () => { const q = filter.value.trim().toLowerCase(); document.querySelectorAll('#list-view tr[data-search]').forEach(r => r.style.display = !q || r.dataset.search.includes(q) ? '' : 'none'); });

  const ci = document.getElementById('checkin'), search = document.getElementById('ci-search');
  let dirty = false;
  document.getElementById('btn-checkin').addEventListener('click', () => { ci.classList.remove('hidden'); document.body.style.overflow = 'hidden'; search.focus(); });
  document.getElementById('ci-close').addEventListener('click', () => { ci.classList.add('hidden'); document.body.style.overflow = ''; if (dirty) location.reload(); });
  search.addEventListener('input', () => { const q = search.value.trim().toLowerCase(); document.querySelectorAll('.ci-row').forEach(r => r.style.display = !q || r.dataset.search.includes(q) ? '' : 'none'); });
  search.addEventListener('keydown', e => { if (e.key === 'Enter') { const first = [...document.querySelectorAll('.ci-row')].find(r => r.style.display !== 'none' && r.dataset.done === '0'); first?.click(); } });
  document.querySelectorAll('.ci-row').forEach(row => row.addEventListener('click', async () => {
    if (row.dataset.done === '1' || row.dataset.busy) return;
    row.dataset.busy = 1; row.querySelector('.ci-label').textContent = 'Checking in…';
    const fd = new FormData(); fd.append('action', 'mark_attended'); fd.append('reg_id', row.dataset.reg); fd.append('event_id', EVENT); fd.append('acsrf', ACSRF);
    try {
      const d = await (await fetch('event-registrations.php?event=' + EVENT, {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
      if (!d.ok) throw new Error(d.msg);
      dirty = true; row.dataset.done = 1;
      row.className = row.className.replace('bg-white/5 hover:bg-white/10', 'bg-green-600/25');
      row.querySelector('.ci-icon').className = row.querySelector('.ci-icon').className.replace('bg-white/10', 'bg-green-500');
      row.querySelector('.ci-icon i').className = 'fa-solid fa-check';
      row.querySelector('.ci-label').textContent = 'Checked in'; row.querySelector('.ci-label').className = 'text-xs font-semibold ci-label text-green-300';
      const n = document.getElementById('ci-done'); n.textContent = +n.textContent + 1;
      search.value = ''; search.dispatchEvent(new Event('input')); search.focus();
    } catch (err) { row.querySelector('.ci-label').textContent = 'Failed — tap to retry'; rarlToast(err.message || 'Check-in failed', 'error'); }
    delete row.dataset.busy;
  }));
})();
</script>
<?php }, 'events', 'Attendees — ' . $event['title']);
