<?php
/**
 * RARL Admin — Events Management
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_event') {
        $title      = clean($_POST['ev_title'] ?? '');
        $type       = clean($_POST['ev_type']  ?? 'other');
        $visibility = ($_POST['ev_visibility'] ?? '') === 'members_only' ? 'members_only' : 'public';
        $date       = clean($_POST['ev_date']  ?? '');
        $time       = clean($_POST['ev_time']  ?? '');
        $location   = clean($_POST['ev_location'] ?? '');
        $onlineUrl  = cleanUrl($_POST['ev_online_url'] ?? '');
        $speaker    = clean($_POST['ev_speaker'] ?? '');
        $capacity   = (int)($_POST['ev_capacity'] ?? 0) ?: null;
        $desc       = clean($_POST['ev_desc']  ?? '');

        $coverImage = null;
        if (!empty($_FILES['ev_cover']['name'])) {
            $coverImage = validateUpload($_FILES['ev_cover'], ['jpg','jpeg','png','webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/events');
        }

        if ($title) {
            $pdo->prepare("INSERT INTO events (title, type, visibility, event_date, event_time, location, online_url, speaker_name, capacity, cover_image, description)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$title, $type, $visibility, $date ?: null, $time ?: null, $location, $onlineUrl, $speaker, $capacity, $coverImage, $desc]);

            // Auto-announce in the community feed so it surfaces where members already are.
            // announcements.content is rendered as plain escaped text elsewhere, so keep this plain too.
            $dateLine = $date ? date('D, d M Y', strtotime($date)) . ($time ? ' at ' . date('g:i A', strtotime($time)) : '') : 'Date TBA';
            $lockNote = $visibility === 'members_only' ? ' (members-only)' : '';
            $announceBody = $dateLine . $lockNote . ". See details and register: " . SITE_URL . '/events.php';
            $pdo->prepare("INSERT INTO announcements (title, content, type, is_pinned) VALUES (?,?,?,1)")
                ->execute(['New event: ' . $title, $announceBody, 'event']);

            $_SESSION['flash'] = ['type'=>'success','msg'=>'Event created and announced in the community feed.'];
        }
        header('Location: events.php'); exit;
    }
    if ($action === 'update_recording') {
        $eventId = (int)($_POST['ev_id'] ?? 0);
        $recordingUrl = cleanUrl($_POST['recording_url'] ?? '');
        $pdo->prepare("UPDATE events SET recording_url=? WHERE id=?")->execute([$recordingUrl ?: null, $eventId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Recording link saved.'];
        header('Location: events.php'); exit;
    }
    if ($action === 'toggle_active') {
        $pdo->prepare("UPDATE events SET is_active = 1 - is_active WHERE id=?")->execute([(int)($_POST['ev_id']??0)]);
        header('Location: events.php'); exit;
    }
    if ($action === 'delete_event') {
        $pdo->prepare("DELETE FROM event_registrations WHERE event_id=?")->execute([(int)($_POST['ev_id']??0)]);
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([(int)($_POST['ev_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Event deleted.'];
        header('Location: events.php'); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if ($ids) {
            $inClause = implode(',', $ids);
            if ($bulkOp === 'activate') {
                $pdo->exec("UPDATE events SET is_active = 1 WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' event(s) activated.'];
            } elseif ($bulkOp === 'deactivate') {
                $pdo->exec("UPDATE events SET is_active = 0 WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' event(s) deactivated.'];
            } elseif ($bulkOp === 'delete') {
                $pdo->exec("DELETE FROM event_registrations WHERE event_id IN ({$inClause})");
                $pdo->exec("DELETE FROM events WHERE id IN ({$inClause})");
                $_SESSION['flash'] = ['type'=>'success','msg'=>count($ids) . ' event(s) deleted.'];
            }
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one event first.'];
        }
        header('Location: events.php'); exit;
    }
}

$events = $pdo->query("SELECT e.*,
    (SELECT COUNT(*) FROM certificates c WHERE c.event_id = e.id) as cert_count,
    (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status != 'cancelled') as reg_count
    FROM events e ORDER BY e.event_date DESC, e.created_at DESC")->fetchAll();

adminWrap(function() use ($events) {
    adminFlash(); ?>
<div class="flex items-center justify-between mb-6">
  <div><h1 class="text-2xl font-black text-gray-900">Events</h1><p class="text-gray-500 text-sm">Manage workshops, webinars, and conferences</p></div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Create Event -->
  <div class="xl:col-span-1">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-4"><i class="fa-solid fa-calendar-days"></i> Create New Event</h2>
      <form method="POST" enctype="multipart/form-data" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="create_event">
        <input type="text" name="ev_title" required placeholder="Event title" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>

        <div class="grid grid-cols-2 gap-2">
          <select name="ev_type" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <?php foreach (['workshop','webinar','hackathon','competition','volunteer','conference','seminar','other'] as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="ev_visibility" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <option value="public">Public</option>
            <option value="members_only">Members Only</option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <input type="date" name="ev_date" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
          <input type="time" name="ev_time" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>

        <input type="text" name="ev_location" placeholder="Location (or leave blank if online-only)" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <input type="url" name="ev_online_url" placeholder="Online join link (Zoom/Meet URL)" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>

        <div class="grid grid-cols-2 gap-2">
          <input type="text" name="ev_speaker" placeholder="Speaker name" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
          <input type="number" name="ev_capacity" min="1" placeholder="Capacity (optional)" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>

        <textarea name="ev_desc" rows="3" placeholder="Description (optional)" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"></textarea>

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Cover Image <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="file" name="ev_cover" accept=".jpg,.jpeg,.png,.webp" class="w-full text-xs"/>
        </div>

        <button type="submit" class="w-full py-2.5 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-xl transition-colors">+ Create Event</button>
      </form>
    </div>
  </div>

  <!-- Events List -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Events</h2>
        <label class="flex items-center gap-1.5 text-xs text-gray-500"><?= bulkSelectAllCheckbox() ?> Select all</label>
      </div>
      <?= bulkFormOpen() ?>
      <?= bulkBar([
          ['label'=>'Activate','op'=>'activate','class'=>'bg-green-600 hover:bg-green-500'],
          ['label'=>'Deactivate','op'=>'deactivate','class'=>'bg-amber-600 hover:bg-amber-500'],
          ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected events? This removes their registrations too.'],
      ]) ?>
      <div class="divide-y divide-gray-100">
        <?php if (empty($events)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No events created yet.</p>
        <?php endif; ?>
        <?php foreach ($events as $e): ?>
        <div class="p-5 hover:bg-gray-50 group">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center pr-1"><?= bulkRowCheckbox((int)$e['id']) ?></div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2 mb-1 flex-wrap">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= ucfirst($e['type']) ?></span>
                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded <?= $e['visibility']==='members_only' ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600' ?>"><?= $e['visibility']==='members_only' ? '<i class="fa-solid fa-lock"></i> Members' : '<i class="fa-solid fa-globe"></i> Public' ?></span>
                <span class="text-[10px] text-gray-400 font-semibold"><?= $e['event_date'] ? date('d M Y', strtotime($e['event_date'])) : 'No date' ?></span>
                <?php if (!$e['is_active']): ?><span class="text-[10px] font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded">Inactive</span><?php endif; ?>
              </div>
              <h3 class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($e['title']) ?></h3>
              <p class="text-[10px] text-gray-500 mt-1">
                <i class="fa-solid fa-users"></i> <?= (int)$e['reg_count'] ?><?= $e['capacity'] ? '/' . (int)$e['capacity'] : '' ?> registered
                &nbsp;·&nbsp; <i class="fa-solid fa-trophy"></i> <?= (int)$e['cert_count'] ?> certificates
              </p>
            </div>
            <div class="flex flex-col gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
              <a href="event-registrations.php?event=<?= $e['id'] ?>" class="px-3 py-1 text-[10px] font-semibold bg-blue-50 text-blue-600 rounded-lg text-center">Attendees</a>
              <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>">
                <button class="px-3 py-1 text-[10px] font-semibold <?= $e['is_active'] ? 'bg-amber-50 text-amber-600' : 'bg-green-50 text-green-600' ?> rounded-lg w-full text-left"><?= $e['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <form method="POST" onsubmit="return confirm('Delete event? This removes its registrations too and orphans any certificates.')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>">
                <button class="px-3 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded-lg w-full text-left">Delete</button>
              </form>
            </div>
          </div>
          <?php if ($e['event_date'] && strtotime($e['event_date']) < time()): ?>
          <form method="POST" class="flex gap-2 mt-3">
            <?= acsrfField() ?><input type="hidden" name="action" value="update_recording"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>">
            <input type="url" name="recording_url" value="<?= htmlspecialchars($e['recording_url'] ?? '') ?>" placeholder="Recording URL (YouTube/Drive link) — visible to members only"
              class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25"/>
            <button type="submit" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-900 text-white text-[10px] font-semibold rounded-lg">Save</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?= bulkBarScript() ?>
<?php }, 'events', 'Events');
