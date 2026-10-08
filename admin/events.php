<?php
/**
 * RARL Admin — Events Management
 * Create / edit / duplicate events from one slide-out form; upcoming, past and
 * hidden events in tabs; registrations and certificates at a glance.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
const EVENT_TYPES = ['workshop','webinar','hackathon','competition','volunteer','conference','seminar','other'];

function eventFieldsFromPost(): array {
    return [
        'title'        => clean($_POST['ev_title'] ?? ''),
        'type'         => in_array($_POST['ev_type'] ?? '', EVENT_TYPES, true) ? $_POST['ev_type'] : 'other',
        'visibility'   => ($_POST['ev_visibility'] ?? '') === 'members_only' ? 'members_only' : 'public',
        'event_date'   => clean($_POST['ev_date'] ?? '') ?: null,
        'event_time'   => clean($_POST['ev_time'] ?? '') ?: null,
        'location'     => clean($_POST['ev_location'] ?? ''),
        'online_url'   => cleanUrl($_POST['ev_online_url'] ?? ''),
        'speaker_name' => clean($_POST['ev_speaker'] ?? ''),
        'capacity'     => (int)($_POST['ev_capacity'] ?? 0) ?: null,
        'recording_url'=> cleanUrl($_POST['ev_recording'] ?? '') ?: null,
        'description'  => clean($_POST['ev_desc'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['ev_id'] ?? 0);
    $back = 'events.php' . (!empty($_POST['return_tab']) ? '?tab=' . urlencode($_POST['return_tab']) : '');

    if ($action === 'save_event') {
        $f = eventFieldsFromPost();
        if (!$f['title']) { $_SESSION['flash'] = ['type'=>'error','msg'=>'The event needs a title.']; header('Location: ' . $back); exit; }
        $cover = !empty($_FILES['ev_cover']['name']) ? validateUpload($_FILES['ev_cover'], ['jpg','jpeg','png','webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/events') : null;
        if (!empty($_FILES['ev_cover']['name']) && !$cover) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Cover image upload failed (jpg/png/webp, max 3MB).']; header('Location: ' . $back); exit; }

        if ($id) {
            $old = $pdo->prepare("SELECT cover_image FROM events WHERE id = ?"); $old->execute([$id]); $oldCover = $old->fetchColumn();
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $vals = array_values($f);
            if ($cover || !empty($_POST['remove_cover'])) {
                $set .= ', cover_image = ?'; $vals[] = $cover;
                if ($oldCover) @unlink(UPLOADS_PATH . '/events/' . basename($oldCover));
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE events SET {$set} WHERE id = ?")->execute($vals);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Event updated.'];
        } else {
            $f['cover_image'] = $cover;
            $cols = implode(', ', array_keys($f));
            $pdo->prepare("INSERT INTO events ({$cols}) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")->execute(array_values($f));
            $msg = 'Event created.';
            if (!empty($_POST['announce'])) {
                // announcements.content is rendered as plain escaped text elsewhere, so keep this plain too.
                $dateLine = $f['event_date'] ? date('D, d M Y', strtotime($f['event_date'])) . ($f['event_time'] ? ' at ' . date('g:i A', strtotime($f['event_time'])) : '') : 'Date TBA';
                $lockNote = $f['visibility'] === 'members_only' ? ' (members-only)' : '';
                $pdo->prepare("INSERT INTO announcements (title, content, type, is_pinned) VALUES (?,?,?,1)")
                    ->execute(['New event: ' . $f['title'], $dateLine . $lockNote . '. See details and register: ' . SITE_URL . '/events.php', 'event']);
                $msg = 'Event created and announced in the community feed.';
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>$msg];
        }
        header('Location: ' . $back); exit;
    }
    if ($action === 'duplicate_event' && $id) {
        $s = $pdo->prepare("SELECT * FROM events WHERE id = ?"); $s->execute([$id]);
        if ($e = $s->fetch()) {
            $pdo->prepare("INSERT INTO events (title, type, visibility, event_date, event_time, location, online_url, speaker_name, capacity, cover_image, description, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,0)")
                ->execute([$e['title'] . ' (copy)', $e['type'], $e['visibility'], null, $e['event_time'], $e['location'], $e['online_url'], $e['speaker_name'], $e['capacity'], null, $e['description']]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Copy created as a hidden draft — set a date and publish it.'];
            header('Location: events.php?tab=hidden&edit=' . $pdo->lastInsertId()); exit;
        }
    }
    if ($action === 'toggle_active' && $id) {
        $pdo->prepare("UPDATE events SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Visibility updated.'];
    }
    if ($action === 'delete_event' && $id) {
        $pdo->prepare("DELETE FROM event_registrations WHERE event_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Event deleted.'];
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'activate')   $pdo->exec("UPDATE events SET is_active = 1 WHERE id IN ({$inClause})");
            if ($bulkOp === 'deactivate') $pdo->exec("UPDATE events SET is_active = 0 WHERE id IN ({$inClause})");
            if ($bulkOp === 'delete') { $pdo->exec("DELETE FROM event_registrations WHERE event_id IN ({$inClause})"); $pdo->exec("DELETE FROM events WHERE id IN ({$inClause})"); }
            $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' event(s) updated.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one event first.'];
        }
    }
    header('Location: ' . $back); exit;
}

$tab = in_array($_GET['tab'] ?? '', ['past','hidden','all'], true) ? $_GET['tab'] : 'upcoming';
$q = trim($_GET['q'] ?? '');
$all = $pdo->query("SELECT e.*,
    (SELECT COUNT(*) FROM certificates c WHERE c.event_id = e.id) as cert_count,
    (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status != 'cancelled') as reg_count,
    (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'attended') as attended_count
    FROM events e ORDER BY e.event_date IS NULL, e.event_date DESC, e.created_at DESC")->fetchAll();
$today = date('Y-m-d');
$bucket = fn($e) => !$e['is_active'] ? 'hidden' : (($e['event_date'] && $e['event_date'] < $today) ? 'past' : 'upcoming');
$counts = ['upcoming' => 0, 'past' => 0, 'hidden' => 0, 'all' => count($all)];
foreach ($all as $e) $counts[$bucket($e)]++;
$events = array_values(array_filter($all, fn($e) => ($tab === 'all' || $bucket($e) === $tab) && (!$q || stripos($e['title'] . ' ' . $e['speaker_name'] . ' ' . $e['location'], $q) !== false)));
if ($tab === 'upcoming') usort($events, fn($a, $b) => strcmp($a['event_date'] ?? '9999', $b['event_date'] ?? '9999'));
$editId = (int)($_GET['edit'] ?? 0);

adminWrap(function() use ($events, $all, $counts, $tab, $q, $editId, $today) {
    adminFlash();
    $byId = array_column($all, null, 'id');
?>
<div class="rarl-page-head">
  <div>
    <h1>Events</h1>
    <p>Workshops, webinars and conferences. Mark attendance on an event to issue certificates automatically.</p>
  </div>
  <button type="button" class="rarl-btn rarl-btn-primary" data-new-event><i class="fa-solid fa-plus"></i> New event</button>
</div>

<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <nav class="rarl-tabs">
    <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'hidden' => 'Hidden & drafts', 'all' => 'All'] as $k => $l): ?>
    <a href="events.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $l ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form method="GET" class="relative">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search events…" class="rarl-input !pl-8 !w-64"/>
  </form>
</div>

<?= bulkFormOpen('', ['return_tab' => $tab]) ?>
<?= bulkBar([
    ['label'=>'Publish','op'=>'activate','class'=>'bg-green-600 hover:bg-green-500'],
    ['label'=>'Hide','op'=>'deactivate','class'=>'bg-amber-600 hover:bg-amber-500'],
    ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected events? This removes their registrations too.'],
]) ?>

<?php if (!$events): ?>
<div class="rarl-card rarl-empty">
  <div class="w-14 h-14 mx-auto rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-2xl mb-3"><i class="fa-solid fa-calendar-plus"></i></div>
  <p class="font-semibold text-gray-700"><?= $q ? 'No events match your search.' : ['upcoming' => 'Nothing scheduled yet.', 'past' => 'No past events.', 'hidden' => 'No hidden events or drafts.', 'all' => 'No events yet.'][$tab] ?></p>
  <?php if (!$q && $tab !== 'past'): ?><button type="button" class="rarl-btn rarl-btn-primary mt-4" data-new-event><i class="fa-solid fa-plus"></i> Create an event</button><?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-5">
  <?php foreach ($events as $e):
    $isPast = $e['event_date'] && $e['event_date'] < $today;
    $isToday = $e['event_date'] === $today;
    $pct = $e['capacity'] ? min(100, round($e['reg_count'] / $e['capacity'] * 100)) : null;
    $days = $e['event_date'] ? (int)round((strtotime($e['event_date']) - strtotime($today)) / 86400) : null;
  ?>
  <article class="rarl-card overflow-hidden flex flex-col group">
    <div class="relative h-36 bg-gradient-to-br from-gray-800 to-gray-950">
      <?php if (!empty($e['cover_image'])): ?><img src="../uploads/events/<?= htmlspecialchars(rawurlencode($e['cover_image'])) ?>" alt="" class="absolute inset-0 w-full h-full object-cover"/><div class="absolute inset-0 bg-gradient-to-t from-black/70 to-black/0"></div>
      <?php else: ?><div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_30%_20%,#CC0703,transparent_55%)]"></div><?php endif; ?>
      <label class="absolute top-3 left-3 bg-white/90 rounded-md p-1 leading-none" onclick="event.stopPropagation()"><?= bulkRowCheckbox((int)$e['id']) ?></label>
      <div class="absolute top-3 right-3 flex gap-1.5">
        <?php if (!$e['is_active']): ?><span class="rarl-badge bg-white/90 text-gray-700"><i class="fa-solid fa-eye-slash"></i> Hidden</span><?php endif; ?>
        <span class="rarl-badge bg-white/90 <?= $e['visibility'] === 'members_only' ? 'text-amber-700' : 'text-blue-700' ?>"><?= $e['visibility'] === 'members_only' ? '<i class="fa-solid fa-lock"></i> Members' : '<i class="fa-solid fa-globe"></i> Public' ?></span>
      </div>
      <div class="absolute bottom-3 left-3 right-3 flex items-end gap-3 text-white">
        <?php if ($e['event_date']): ?>
        <div class="bg-white text-gray-900 rounded-xl px-2.5 py-1.5 text-center leading-none shadow flex-shrink-0">
          <div class="text-[10px] font-bold uppercase text-rarl-red"><?= date('M', strtotime($e['event_date'])) ?></div>
          <div class="font-heading font-black text-xl"><?= date('d', strtotime($e['event_date'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="min-w-0">
          <p class="text-[10px] font-bold uppercase tracking-wider text-white/70"><?= ucfirst($e['type']) ?><?= $e['event_time'] ? ' · ' . date('g:i A', strtotime($e['event_time'])) : '' ?></p>
          <h3 class="font-heading font-bold text-base leading-snug line-clamp-2"><?= htmlspecialchars($e['title']) ?></h3>
        </div>
      </div>
    </div>
    <div class="p-4 flex-1 flex flex-col gap-3">
      <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
        <?php if ($isToday): ?><span class="text-rarl-red font-bold"><i class="fa-solid fa-circle text-[7px] align-middle animate-pulse"></i> Today</span>
        <?php elseif ($days !== null && $days > 0): ?><span class="font-semibold text-gray-700">in <?= $days ?> day<?= $days === 1 ? '' : 's' ?></span>
        <?php elseif (!$e['event_date']): ?><span class="text-amber-600 font-semibold">No date set</span><?php endif; ?>
        <?php if ($e['speaker_name']): ?><span><i class="fa-solid fa-microphone"></i> <?= htmlspecialchars($e['speaker_name']) ?></span><?php endif; ?>
        <?php if ($e['location']): ?><span class="truncate max-w-[180px]"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($e['location']) ?></span><?php endif; ?>
        <?php if ($e['online_url']): ?><span><i class="fa-solid fa-video"></i> Online</span><?php endif; ?>
      </div>
      <div>
        <div class="flex items-center justify-between text-xs mb-1">
          <span class="text-gray-600"><strong class="text-gray-900"><?= (int)$e['reg_count'] ?></strong><?= $e['capacity'] ? ' / ' . (int)$e['capacity'] : '' ?> registered<?= $isPast ? ' · <strong class="text-gray-900">' . (int)$e['attended_count'] . '</strong> attended' : '' ?></span>
          <span class="text-gray-400"><i class="fa-solid fa-trophy"></i> <?= (int)$e['cert_count'] ?></span>
        </div>
        <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden"><div class="h-full <?= $pct !== null && $pct >= 90 ? 'bg-amber-500' : 'bg-rarl-red' ?>" style="width:<?= $pct ?? ($e['reg_count'] ? 100 : 0) ?>%;opacity:<?= $pct === null ? .35 : 1 ?>"></div></div>
      </div>
      <?php if ($isPast && empty($e['recording_url'])): ?>
      <button type="button" class="text-left text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2 hover:bg-amber-100" data-edit-event="<?= (int)$e['id'] ?>" data-focus="ev_recording"><i class="fa-solid fa-film"></i> Add the recording link for members</button>
      <?php endif; ?>
      <div class="flex items-center gap-1.5 mt-auto pt-1">
        <a href="event-registrations.php?event=<?= $e['id'] ?>" class="rarl-btn rarl-btn-sm rarl-btn-dark"><i class="fa-solid fa-users"></i> Attendees</a>
        <button type="button" class="rarl-btn rarl-btn-sm" data-edit-event="<?= (int)$e['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</button>
        <div class="ml-auto flex">
          <button type="button" class="rarl-icon-btn" data-copy="<?= htmlspecialchars(SITE_URL . '/events.php') ?>" title="Copy public events link"><i class="fa-solid fa-link"></i></button>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="duplicate_event"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>"><button class="rarl-icon-btn" title="Duplicate" data-no-loading><i class="fa-regular fa-copy"></i></button></form>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>"><input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>"><button class="rarl-icon-btn" title="<?= $e['is_active'] ? 'Hide from members' : 'Publish' ?>" data-no-loading><i class="fa-regular <?= $e['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
          <form method="POST" data-confirm="Delete “<?= htmlspecialchars($e['title']) ?>”? This removes its <?= (int)$e['reg_count'] ?> registration(s) too. Issued certificates stay valid."><?= acsrfField() ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>"><input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-regular fa-trash-can"></i></button></form>
        </div>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="rarl-drawer-backdrop" id="ev-bg"></div>
<aside class="rarl-drawer" id="ev-drawer">
  <form method="POST" enctype="multipart/form-data" class="flex flex-col h-full" id="ev-form">
    <?= acsrfField() ?><input type="hidden" name="action" value="save_event"><input type="hidden" name="ev_id" value=""><input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
      <h2 class="font-heading font-black text-lg" id="ev-form-title">New event</h2>
      <button type="button" class="rarl-icon-btn" data-ev-close><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="flex-1 overflow-y-auto p-5 space-y-4">
      <label id="ev-cover-drop" class="relative block h-40 rounded-xl border-2 border-dashed border-gray-300 hover:border-rarl-red/60 bg-gray-50 overflow-hidden cursor-pointer">
        <img id="ev-cover-preview" class="hidden absolute inset-0 w-full h-full object-cover" alt=""/>
        <span id="ev-cover-empty" class="absolute inset-0 flex flex-col items-center justify-center text-center text-gray-500 text-xs"><i class="fa-regular fa-image text-2xl mb-1"></i>Cover image (optional)<span class="text-[10px] text-gray-400">16:9 · jpg/png/webp · max 3 MB</span></span>
        <input type="file" name="ev_cover" accept=".jpg,.jpeg,.png,.webp" class="sr-only" data-no-chip/>
      </label>
      <label class="hidden items-center gap-2 text-xs text-gray-600" id="ev-remove-cover"><input type="checkbox" name="remove_cover" value="1" class="accent-rarl-red"/> Remove current cover</label>
      <div><label class="rarl-label">Title *</label><input name="ev_title" required class="rarl-input" placeholder="e.g. ROS 2 Hands-on Workshop"/></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="rarl-label">Type</label><select name="ev_type" class="rarl-input"><?php foreach (EVENT_TYPES as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
        <div><label class="rarl-label">Who can see it</label><select name="ev_visibility" class="rarl-input"><option value="public">Everyone (public)</option><option value="members_only">Members only</option></select></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="rarl-label">Date</label><input type="date" name="ev_date" class="rarl-input"/></div>
        <div><label class="rarl-label">Start time</label><input type="time" name="ev_time" class="rarl-input"/></div>
      </div>
      <div><label class="rarl-label">Venue</label><input name="ev_location" class="rarl-input" placeholder="Leave blank if online-only"/></div>
      <div><label class="rarl-label">Online join link</label><input type="url" name="ev_online_url" class="rarl-input" placeholder="https://zoom.us/…"/></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="rarl-label">Speaker</label><input name="ev_speaker" class="rarl-input"/></div>
        <div><label class="rarl-label">Capacity</label><input type="number" min="1" name="ev_capacity" class="rarl-input" placeholder="Unlimited"/></div>
      </div>
      <div><label class="rarl-label">Description</label><textarea name="ev_desc" rows="5" class="rarl-input" placeholder="What attendees will learn, agenda, requirements…"></textarea></div>
      <div><label class="rarl-label">Recording link <span class="font-normal text-gray-400">— shown to members after the event</span></label><input type="url" name="ev_recording" id="ev_recording" class="rarl-input" placeholder="YouTube / Drive link"/></div>
      <label class="flex items-start gap-2.5 p-3 bg-blue-50 border border-blue-200 rounded-xl cursor-pointer text-sm" id="ev-announce">
        <input type="checkbox" name="announce" value="1" checked class="w-4 h-4 mt-0.5 accent-rarl-red"/>
        <span><span class="font-semibold text-gray-800">Announce in the community feed</span><span class="block text-xs text-gray-500">Posts a pinned announcement so members see it right away.</span></span>
      </label>
    </div>
    <div class="p-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50/70">
      <button type="button" class="rarl-btn" data-ev-close>Cancel</button>
      <button type="submit" class="rarl-btn rarl-btn-primary" id="ev-submit"><i class="fa-solid fa-check"></i> Create event</button>
    </div>
  </form>
</aside>

<?= bulkBarScript() ?>
<script>
(function() {
  const EVENTS = <?= json_encode(array_map(fn($e) => [
      'id' => (int)$e['id'], 'title' => $e['title'], 'type' => $e['type'], 'visibility' => $e['visibility'], 'date' => $e['event_date'], 'time' => $e['event_time'] ? substr($e['event_time'], 0, 5) : '',
      'location' => $e['location'], 'online_url' => $e['online_url'], 'speaker' => $e['speaker_name'], 'capacity' => $e['capacity'], 'desc' => $e['description'],
      'recording' => $e['recording_url'], 'cover' => $e['cover_image'] ? '../uploads/events/' . rawurlencode($e['cover_image']) : '',
  ], $byId), JSON_HEX_TAG) ?>;
  const drawer = document.getElementById('ev-drawer'), bg = document.getElementById('ev-bg'), form = document.getElementById('ev-form');
  const cover = form.querySelector('[name=ev_cover]'), prev = document.getElementById('ev-cover-preview'), empty = document.getElementById('ev-cover-empty');
  function show(src) { prev.src = src || ''; prev.classList.toggle('hidden', !src); empty.classList.toggle('hidden', !!src); }
  function open(e, focus) {
    form.reset(); cover.value = '';
    const set = (n, v) => { form.elements[n].value = v ?? ''; };
    set('ev_id', e ? e.id : ''); set('ev_title', e?.title); set('ev_type', e?.type || 'workshop'); set('ev_visibility', e?.visibility || 'public');
    set('ev_date', e?.date); set('ev_time', e?.time); set('ev_location', e?.location); set('ev_online_url', e?.online_url);
    set('ev_speaker', e?.speaker); set('ev_capacity', e?.capacity); set('ev_desc', e?.desc); set('ev_recording', e?.recording);
    show(e?.cover);
    document.getElementById('ev-remove-cover').style.display = e?.cover ? 'flex' : 'none';
    document.getElementById('ev-announce').style.display = e ? 'none' : '';
    document.getElementById('ev-form-title').textContent = e ? 'Edit event' : 'New event';
    document.getElementById('ev-submit').innerHTML = e ? '<i class="fa-solid fa-check"></i> Save changes' : '<i class="fa-solid fa-check"></i> Create event';
    drawer.classList.add('open'); bg.classList.add('open');
    setTimeout(() => (focus ? document.getElementById(focus) : form.elements.ev_title).focus(), 250);
  }
  function close() { drawer.classList.remove('open'); bg.classList.remove('open'); }
  cover.addEventListener('change', () => { if (cover.files[0]) show(URL.createObjectURL(cover.files[0])); });
  document.querySelectorAll('[data-new-event]').forEach(b => b.addEventListener('click', () => open(null)));
  document.querySelectorAll('[data-edit-event]').forEach(b => b.addEventListener('click', () => open(EVENTS[b.dataset.editEvent], b.dataset.focus)));
  document.querySelectorAll('[data-ev-close]').forEach(b => b.addEventListener('click', close));
  bg.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && drawer.classList.contains('open')) close(); });
  document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => navigator.clipboard.writeText(b.dataset.copy).then(() => rarlToast('Link copied', 'success'))));
  const editId = <?= (int)$editId ?>; if (editId && EVENTS[editId]) open(EVENTS[editId]);
  if (location.hash === '#new') open(null);
})();
</script>
<?php }, 'events', 'Events');
