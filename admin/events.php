<?php
/**
 * RARL Admin — Events Management
 * Create / edit / duplicate events from one slide-out form; upcoming, past and
 * hidden events in tabs; registrations and certificates at a glance.
 * Every event has a time zone (start/end are wall-clock times there) and a
 * visibility: public, members only, or link only (unlisted, opened with a
 * secret link). The share panel gives the link, a QR code and social buttons.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
const EVENT_TYPES = ['workshop','webinar','hackathon','competition','volunteer','conference','seminar','other'];

/** Reads and normalises the form. Returns [fields, error message or null]. */
function eventFieldsFromPost(): array {
    $date = function (string $k): ?string {
        $v = trim((string)($_POST[$k] ?? ''));
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
    };
    $time = function (string $k): ?string {
        $v = trim((string)($_POST[$k] ?? ''));
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v) ? substr($v, 0, 5) . ':00' : null;
    };
    $allDay = !empty($_POST['ev_all_day']);
    $f = [
        'title'        => clean($_POST['ev_title'] ?? ''),
        'type'         => in_array($_POST['ev_type'] ?? '', EVENT_TYPES, true) ? $_POST['ev_type'] : 'other',
        'visibility'   => array_key_exists($_POST['ev_visibility'] ?? '', EVENT_VISIBILITIES) ? $_POST['ev_visibility'] : 'public',
        'event_date'   => $date('ev_date'),
        'event_time'   => $allDay ? null : $time('ev_time'),
        'end_date'     => $date('ev_end_date'),
        'end_time'     => $allDay ? null : $time('ev_end_time'),
        'timezone'     => eventValidTimezone($_POST['ev_timezone'] ?? '') ?? date_default_timezone_get(),
        'location'     => clean($_POST['ev_location'] ?? ''),
        'online_url'   => cleanUrl($_POST['ev_online_url'] ?? ''),
        'speaker_name' => clean($_POST['ev_speaker'] ?? ''),
        'capacity'     => max(0, (int)($_POST['ev_capacity'] ?? 0)) ?: null,
        'recording_url'=> cleanUrl($_POST['ev_recording'] ?? '') ?: null,
        'description'  => clean($_POST['ev_desc'] ?? ''),
    ];
    if (!$f['title']) return [$f, 'The event needs a title.'];
    if (trim((string)($_POST['ev_date'] ?? '')) !== '' && !$f['event_date']) return [$f, 'The start date isn\'t a valid date.'];
    if (trim((string)($_POST['ev_end_date'] ?? '')) !== '' && !$f['end_date']) return [$f, 'The end date isn\'t a valid date.'];

    if (!$f['event_date']) { $f['end_date'] = null; $f['end_time'] = null; }      // no date yet: an end makes no sense
    if (!$f['event_time']) $f['end_time'] = null;                                  // all-day: whole days only
    if ($f['end_date'] && $f['end_date'] < $f['event_date']) return [$f, 'The event can\'t end before it starts — check the end date.'];
    if ($f['end_date'] === $f['event_date']) $f['end_date'] = null;
    if ($f['end_date'] && $f['event_time'] && !$f['end_time']) $f['end_time'] = $f['event_time']; // multi-day, end at the same clock time
    if ($f['end_date'] && $f['end_time'] && $f['event_time']) {
        $t = eventTimes($f);
        if ($t['start'] && $t['end'] && $t['end'] <= $t['start']) return [$f, 'The event can\'t end before it starts — check the end time.'];
    }
    return [$f + eventUtcColumns($f), null];
}

/** The form as typed, in the shape the drawer script opens (used after a failed save). */
function eventDraftFromPost(int $id): array {
    $v = fn($k) => trim((string)($_POST[$k] ?? ''));
    return [
        'id' => $id ?: null, 'title' => $v('ev_title'), 'type' => $v('ev_type'), 'visibility' => $v('ev_visibility'),
        'date' => $v('ev_date'), 'time' => $v('ev_time'), 'end_date' => $v('ev_end_date'), 'end_time' => $v('ev_end_time'),
        'all_day' => !empty($_POST['ev_all_day']), 'timezone' => $v('ev_timezone'), 'location' => $v('ev_location'),
        'online_url' => $v('ev_online_url'), 'speaker' => $v('ev_speaker'), 'capacity' => $v('ev_capacity'), 'desc' => $v('ev_desc'),
        'recording' => $v('ev_recording'), 'auto_cover' => !empty($_POST['auto_cover']), 'announce' => !empty($_POST['announce']), 'cover' => '',
    ];
}

function eventRow(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM events WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

// ── QR code for an event's share link (PNG; ?dl=1 downloads a print-size copy) ──
if (isset($_GET['qr'])) {
    $e = eventRow($pdo, (int)$_GET['qr']);
    if (!$e) { http_response_code(404); exit('Event not found'); }
    if ($e['visibility'] === 'unlisted' && empty($e['share_token'])) {
        $e['share_token'] = eventNewShareToken();
        $pdo->prepare('UPDATE events SET share_token = ? WHERE id = ?')->execute([$e['share_token'], $e['id']]);
    }
    $dl = !empty($_GET['dl']);
    header('Content-Type: image/png');
    header('Cache-Control: private, no-store');
    if ($dl) header('Content-Disposition: attachment; filename="rarl-event-' . (int)$e['id'] . '-qr.png"');
    echo qrPng(eventUrl($e), $dl ? 16 : 8, 2);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['ev_id'] ?? 0);
    $tabQ = !empty($_POST['return_tab']) ? 'tab=' . urlencode($_POST['return_tab']) : '';
    $back = 'events.php' . ($tabQ ? '?' . $tabQ : '');
    $backShare = fn(int $eid) => 'events.php?' . ($tabQ ? $tabQ . '&' : '') . 'share=' . $eid;

    if ($action === 'save_event') {
        [$f, $err] = eventFieldsFromPost();
        if ($err) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>$err . ' Your changes are still in the form.'];
            $_SESSION['ev_draft'] = eventDraftFromPost($id);
            header('Location: ' . $back); exit;
        }
        $old = $id ? eventRow($pdo, $id) : null;
        if ($id && !$old) { $_SESSION['flash'] = ['type'=>'error','msg'=>'That event no longer exists.']; header('Location: ' . $back); exit; }
        $cover = !empty($_FILES['ev_cover']['name']) ? validateUpload($_FILES['ev_cover'], ['jpg','jpeg','png','webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/events') : null;
        if (!empty($_FILES['ev_cover']['name']) && !$cover) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'The cover image couldn\'t be uploaded. Use a JPG, PNG or WebP under 3 MB. Your other changes are still in the form.'];
            $_SESSION['ev_draft'] = eventDraftFromPost($id);
            header('Location: ' . $back); exit;
        }
        settingSet('events_last_timezone', $f['timezone']);

        $notes = [];
        if (eventStartShifted($f)) $notes[] = 'The start time you picked doesn\'t exist on that day in ' . $f['timezone'] . ' (the clocks go forward), so it now starts at ' . eventTimes($f)['start']->format('g:i A') . '.';
        if ($f['visibility'] === 'unlisted' && !empty($_POST['announce'])) $notes[] = 'Link-only events aren\'t announced in the feed — share the link instead.';

        if ($id) {
            if ($f['visibility'] === 'unlisted' && empty($old['share_token'])) $f['share_token'] = eventNewShareToken();
            // Moved to a new start time that is still ahead: remind registrants again.
            if ($f['starts_at_utc'] !== $old['starts_at_utc'] && $f['starts_at_utc'] && $f['starts_at_utc'] > gmdate('Y-m-d H:i:s')) $f['reminder_sent'] = 0;
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $vals = array_values($f);
            if ($cover || !empty($_POST['remove_cover'])) {
                $set .= ', cover_image = ?'; $vals[] = $cover;
                if ($old['cover_image']) @unlink(UPLOADS_PATH . '/events/' . basename($old['cover_image']));
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE events SET {$set} WHERE id = ?")->execute($vals);
            if (!$cover && !empty($_POST['auto_cover'])) {
                $made = generateEventCover(['id' => $id] + $f, $_POST['cover_style'] ?? 'blue');
                if ($made) {
                    $cur = $pdo->prepare('SELECT cover_image FROM events WHERE id = ?'); $cur->execute([$id]); $c = $cur->fetchColumn();
                    if ($c && $c !== $made) @unlink(UPLOADS_PATH . '/events/' . basename($c));
                    $pdo->prepare('UPDATE events SET cover_image = ? WHERE id = ?')->execute([$made, $id]);
                }
            }
            if ($old['visibility'] !== $f['visibility']) {
                $notes[] = [
                    'public'       => 'It\'s now public: listed on the Events page and in search.',
                    'members_only' => 'It\'s now members only: visitors with the link are asked to sign in.',
                    'unlisted'     => 'It\'s now link only: removed from lists and search; only people with the link can open it. Links shared before without the key stop working.',
                ][$f['visibility']];
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>trim('Event updated. ' . implode(' ', $notes))];
            header('Location: ' . ($old['visibility'] !== $f['visibility'] ? $backShare($id) : $back)); exit;
        }

        $f['cover_image'] = $cover;
        $f['share_token'] = eventNewShareToken();
        $cols = implode(', ', array_keys($f));
        $pdo->prepare("INSERT INTO events ({$cols}) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")->execute(array_values($f));
        $newId = (int)$pdo->lastInsertId();
        if (!$cover && !empty($_POST['auto_cover']) && ($made = generateEventCover(['id' => $newId] + $f, $_POST['cover_style'] ?? 'blue'))) {
            $pdo->prepare('UPDATE events SET cover_image = ? WHERE id = ?')->execute([$made, $newId]);
        }
        $msg = 'Event created.';
        if (!empty($_POST['announce']) && $f['visibility'] !== 'unlisted') {
            // announcements.content is rendered as plain escaped text elsewhere, so keep this plain too.
            $dateLine = html_entity_decode(eventWhenText($f, false), ENT_QUOTES);
            $lockNote = $f['visibility'] === 'members_only' ? ' (members-only)' : '';
            $pdo->prepare("INSERT INTO announcements (title, content, type, is_pinned) VALUES (?,?,?,1)")
                ->execute(['New event: ' . $f['title'], $dateLine . $lockNote . '. See details and register: ' . eventUrl(['id' => $newId] + $f), 'event']);
            $msg = 'Event created and announced in the community feed.';
        }
        if (eventPhase($f) === 'past') $notes[] = 'Its date has already passed, so it\'s listed under Past.';
        $_SESSION['flash'] = ['type'=>'success','msg'=>trim($msg . ' ' . implode(' ', $notes))];
        header('Location: ' . $backShare($newId)); exit;
    }
    if ($action === 'reset_link' && $id) {
        $pdo->prepare('UPDATE events SET share_token = ? WHERE id = ?')->execute([eventNewShareToken(), $id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'New link created. The old link no longer opens this event (people already registered keep access).'];
        header('Location: ' . $backShare($id)); exit;
    }
    if ($action === 'duplicate_event' && $id) {
        if ($e = eventRow($pdo, $id)) {
            $pdo->prepare("INSERT INTO events (title, type, visibility, share_token, event_date, event_time, end_date, end_time, timezone, location, online_url, speaker_name, capacity, cover_image, description, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)")
                ->execute([$e['title'] . ' (copy)', $e['type'], $e['visibility'], eventNewShareToken(), null, $e['event_time'], null, $e['end_time'], $e['timezone'], $e['location'], $e['online_url'], $e['speaker_name'], $e['capacity'], null, $e['description']]);
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
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Event deleted. Its link no longer works.'];
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
// Old rows saved before time zones existed: fill in the derived columns once.
foreach ($all as &$row) {
    if ($row['event_date'] && (!$row['starts_at_utc'] || !$row['share_token'])) {
        $u = eventUtcColumns($row); $row = $u + $row; $row['share_token'] = $row['share_token'] ?: eventNewShareToken();
        $pdo->prepare('UPDATE events SET starts_at_utc = ?, ends_at_utc = ?, share_token = ? WHERE id = ?')->execute([$u['starts_at_utc'], $u['ends_at_utc'], $row['share_token'], $row['id']]);
    } elseif (!$row['share_token']) {
        $row['share_token'] = eventNewShareToken();
        $pdo->prepare('UPDATE events SET share_token = ? WHERE id = ?')->execute([$row['share_token'], $row['id']]);
    }
    $row['phase'] = eventPhase($row);
}
unset($row);
$bucket = fn($e) => !$e['is_active'] ? 'hidden' : ($e['phase'] === 'past' ? 'past' : 'upcoming');
$counts = ['upcoming' => 0, 'past' => 0, 'hidden' => 0, 'all' => count($all)];
foreach ($all as $e) $counts[$bucket($e)]++;
$events = array_values(array_filter($all, fn($e) => ($tab === 'all' || $bucket($e) === $tab) && (!$q || stripos($e['title'] . ' ' . $e['speaker_name'] . ' ' . $e['location'], $q) !== false)));
if ($tab === 'upcoming') usort($events, fn($a, $b) => strcmp($a['starts_at_utc'] ?? '9999', $b['starts_at_utc'] ?? '9999'));
$editId = (int)($_GET['edit'] ?? 0);
$shareId = (int)($_GET['share'] ?? 0);
$draft = $_SESSION['ev_draft'] ?? null; unset($_SESSION['ev_draft']);
// Editing: keep the event's current cover in the draft so the preview shows it.
if ($draft && $draft['id']) { $cur = array_column($all, null, 'id')[$draft['id']] ?? null; if ($cur && $cur['cover_image']) $draft['cover'] = '../uploads/events/' . rawurlencode($cur['cover_image']); }
$defaultTz = eventValidTimezone(setting('events_last_timezone', '')) ?? date_default_timezone_get();

adminWrap(function() use ($events, $all, $counts, $tab, $q, $editId, $shareId, $defaultTz, $draft) {
    adminFlash();
    $byId = array_column($all, null, 'id');
    $visBadge = [
        'public'       => ['text-blue-700', 'fa-globe', 'Public'],
        'members_only' => ['text-amber-700', 'fa-lock', 'Members'],
        'unlisted'     => ['text-violet-700', 'fa-link', 'Link only'],
    ];
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
    <input type="hidden" name="tab" value="<?= ex($tab) ?>">
    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
    <input type="search" name="q" value="<?= ex($q) ?>" placeholder="Search events…" class="rarl-input !pl-8 w-full sm:!w-64"/>
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
    $isPast = $e['phase'] === 'past';
    $times = eventTimes($e);
    $todayThere = $times['start'] ? (new DateTimeImmutable('now', $times['tz']))->format('Y-m-d') : null;
    $isToday = $e['event_date'] && $e['event_date'] === $todayThere;
    $pct = $e['capacity'] ? min(100, round($e['reg_count'] / $e['capacity'] * 100)) : null;
    $days = $times['start'] && $e['phase'] === 'upcoming' ? (int)floor((strtotime($e['event_date']) - strtotime($todayThere)) / 86400) : null;
    [$vc, $vi, $vl] = $visBadge[$e['visibility']] ?? $visBadge['public'];
  ?>
  <article class="rarl-card overflow-hidden flex flex-col group">
    <div class="relative h-36 bg-gradient-to-br from-gray-800 to-gray-950">
      <?php if (!empty($e['cover_image'])): ?><img src="../uploads/events/<?= ex(rawurlencode($e['cover_image'])) ?>" alt="" class="absolute inset-0 w-full h-full object-cover"/><div class="absolute inset-0 bg-gradient-to-t from-black/70 to-black/0"></div>
      <?php else: ?><div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_30%_20%,#CC0703,transparent_55%)]"></div><?php endif; ?>
      <label class="absolute top-3 left-3 bg-white/90 rounded-md p-1 leading-none" onclick="event.stopPropagation()"><?= bulkRowCheckbox((int)$e['id']) ?></label>
      <div class="absolute top-3 right-3 flex gap-1.5">
        <?php if (!$e['is_active']): ?><span class="rarl-badge bg-white/90 text-gray-700"><i class="fa-solid fa-eye-slash"></i> Hidden</span><?php endif; ?>
        <span class="rarl-badge bg-white/90 <?= $vc ?>"><i class="fa-solid <?= $vi ?>"></i> <?= $vl ?></span>
      </div>
      <div class="absolute bottom-3 left-3 right-3 flex items-end gap-3 text-white">
        <?php if ($e['event_date']): ?>
        <div class="bg-white text-gray-900 rounded-xl px-2.5 py-1.5 text-center leading-none shadow flex-shrink-0">
          <div class="text-[10px] font-bold uppercase text-rarl-red"><?= date('M', strtotime($e['event_date'])) ?></div>
          <div class="font-heading font-black text-xl"><?= date('d', strtotime($e['event_date'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="min-w-0">
          <p class="text-[10px] font-bold uppercase tracking-wider text-white/70"><?= ucfirst($e['type']) ?><?= $times['start'] ? ' · ' . ex(eventTimeText($e)) : '' ?></p>
          <h3 class="font-heading font-bold text-base leading-snug line-clamp-2"><?= ex($e['title']) ?></h3>
        </div>
      </div>
    </div>
    <div class="p-4 flex-1 flex flex-col gap-3">
      <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
        <?php if ($e['phase'] === 'live'): ?><span class="text-rarl-red font-bold"><i class="fa-solid fa-circle text-[7px] align-middle animate-pulse [animation-iteration-count:3]"></i> Happening now</span>
        <?php elseif ($isToday && !$isPast): ?><span class="text-rarl-red font-bold"><i class="fa-solid fa-circle text-[7px] align-middle"></i> Today</span>
        <?php elseif ($days !== null && $days > 0): ?><span class="font-semibold text-gray-700">in <?= $days ?> day<?= $days === 1 ? '' : 's' ?></span>
        <?php elseif (!$e['event_date']): ?><span class="text-amber-600 font-semibold">No date set</span><?php endif; ?>
        <?php if ($times['start']): ?><span title="<?= ex($times['tz']->getName()) ?>"><i class="fa-solid fa-earth-asia"></i> <?= ex(eventTzCity($times['tz'])) ?></span><?php endif; ?>
        <?php if ($e['speaker_name']): ?><span><i class="fa-solid fa-microphone"></i> <?= ex($e['speaker_name']) ?></span><?php endif; ?>
        <?php if ($e['location']): ?><span class="truncate max-w-[180px]"><i class="fa-solid fa-location-dot"></i> <?= ex($e['location']) ?></span><?php endif; ?>
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
      <div class="flex flex-wrap items-center gap-1.5 mt-auto pt-1">
        <a href="event-registrations.php?event=<?= $e['id'] ?>" class="rarl-btn rarl-btn-sm rarl-btn-dark"><i class="fa-solid fa-users"></i> Attendees</a>
        <?php if (empty($e['online_url']) && (!$isPast || $e['event_date'] >= date('Y-m-d', strtotime('-2 days')))): ?><a href="checkin.php" class="rarl-btn rarl-btn-sm" title="Scan check-in passes"><i class="fa-solid fa-qrcode"></i> Check-in</a><?php endif; ?>
        <button type="button" class="rarl-btn rarl-btn-sm" data-edit-event="<?= (int)$e['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</button>
        <button type="button" class="rarl-btn rarl-btn-sm" data-share-event="<?= (int)$e['id'] ?>"><i class="fa-solid fa-share-nodes"></i> Share</button>
        <div class="ml-auto flex">
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="duplicate_event"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>"><button class="rarl-icon-btn" title="Duplicate" data-no-loading><i class="fa-solid fa-copy"></i></button></form>
          <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>"><input type="hidden" name="return_tab" value="<?= ex($tab) ?>"><button class="rarl-icon-btn" title="<?= $e['is_active'] ? 'Hide from everyone (its link stops working)' : 'Publish' ?>" data-no-loading><i class="fa-solid <?= $e['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
          <form method="POST" data-confirm="Delete “<?= ex($e['title']) ?>”? This removes its <?= (int)$e['reg_count'] ?> registration(s) too, and its share link stops working. Issued certificates stay valid."><?= acsrfField() ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>"><input type="hidden" name="return_tab" value="<?= ex($tab) ?>"><button class="rarl-icon-btn danger" title="Delete"><i class="fa-solid fa-trash-can"></i></button></form>
        </div>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="rarl-drawer-backdrop" id="ev-bg"></div>
<aside class="rarl-drawer" id="ev-drawer" style="--modal-w:680px" aria-labelledby="ev-form-title">
  <form method="POST" enctype="multipart/form-data" class="flex flex-col h-full" id="ev-form" novalidate>
    <?= acsrfField() ?><input type="hidden" name="action" value="save_event"><input type="hidden" name="ev_id" value=""><input type="hidden" name="return_tab" value="<?= ex($tab) ?>">
    <div class="px-5 py-4 border-b border-[color:var(--border)] flex items-center gap-3 bg-white">
      <button type="button" class="ev-textbtn" data-ev-close>Cancel</button>
      <h2 class="flex-1 text-center font-heading font-bold text-[17px]" id="ev-form-title">New event</h2>
      <button type="submit" class="ev-textbtn ev-textbtn-strong" id="ev-submit-top">Add</button>
    </div>
    <div class="flex-1 overflow-y-auto ev-grouped px-4 sm:px-6 py-5 space-y-6">

      <section>
        <div class="ev-card">
          <label id="ev-cover-drop" class="relative block aspect-[16/7] bg-[color:var(--muted)] overflow-hidden cursor-pointer group">
            <img id="ev-cover-preview" class="hidden absolute inset-0 w-full h-full object-cover" alt=""/>
            <span id="ev-cover-empty" class="absolute inset-0 flex flex-col items-center justify-center gap-1 text-center text-[color:var(--muted-fg)]"><i class="fa-regular fa-image text-2xl"></i><span class="text-sm font-medium">Add cover image</span><span class="text-xs">16:9 · JPG, PNG or WebP · up to 3 MB</span></span>
            <span class="absolute right-3 bottom-3 rounded-full bg-black/55 text-white text-xs font-semibold px-3 py-1.5 opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true">Change</span>
            <input type="file" name="ev_cover" accept=".jpg,.jpeg,.png,.webp" class="sr-only" data-no-chip/>
          </label>
          <label class="ev-row" id="ev-auto">
            <span class="flex-1 min-w-0"><span class="block" id="ev-auto-label">Make a cover for me</span><span class="ev-sub">RARL webinar style with the title and date. Ignored if you upload an image.</span></span>
            <input type="checkbox" name="auto_cover" value="1" class="ev-switch" role="switch"/>
          </label>
          <div class="ev-row" id="ev-cover-style">
            <span class="flex-1">Cover style</span>
            <span class="ev-seg ev-seg-sm" role="radiogroup" aria-label="Cover style">
              <label><input type="radio" name="cover_style" value="blue" checked/><span><i class="inline-block w-3 h-3 rounded-full bg-gradient-to-r from-sky-200 to-blue-600 align-[-1px]"></i> Blue</span></label>
              <label><input type="radio" name="cover_style" value="red"/><span><i class="inline-block w-3 h-3 rounded-full bg-gradient-to-r from-red-500 to-red-900 align-[-1px]"></i> Red</span></label>
            </span>
          </div>
          <label class="ev-row" id="ev-remove-cover" style="display:none"><span class="flex-1 text-red-600">Remove current cover</span><input type="checkbox" name="remove_cover" value="1" class="ev-switch" role="switch"/></label>
        </div>
      </section>

      <section>
        <div class="ev-card">
          <div class="ev-field"><input name="ev_title" id="ev_title" required class="ev-input text-[17px] font-semibold" placeholder="Title" aria-label="Title" maxlength="200"/></div>
          <label class="ev-row" for="ev_type"><span class="flex-1">Type</span>
            <select name="ev_type" id="ev_type" class="ev-select"><?php foreach (EVENT_TYPES as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?></select></label>
        </div>
      </section>

      <section>
        <h3 class="ev-group-h">Who can see it</h3>
        <div class="ev-seg w-full" role="radiogroup" aria-label="Who can see it">
          <label><input type="radio" name="ev_visibility" value="public"/><span><i class="fa-solid fa-globe"></i> Public</span></label>
          <label><input type="radio" name="ev_visibility" value="members_only"/><span><i class="fa-solid fa-lock"></i> Members</span></label>
          <label><input type="radio" name="ev_visibility" value="unlisted"/><span><i class="fa-solid fa-link"></i> Link only</span></label>
        </div>
        <p class="ev-foot-note" id="ev-vis-help" aria-live="polite"></p>
      </section>

      <section>
        <h3 class="ev-group-h">Date &amp; time</h3>
        <div class="ev-card">
          <label class="ev-row"><span class="flex-1">All-day</span><input type="checkbox" name="ev_all_day" value="1" class="ev-switch" role="switch"/></label>
          <div class="ev-row"><span class="w-16 flex-shrink-0">Starts</span>
            <span class="flex flex-1 justify-end gap-2 min-w-0"><input type="date" name="ev_date" class="ev-pill" aria-label="Start date"/><input type="time" name="ev_time" class="ev-pill" aria-label="Start time" data-timed/></span></div>
          <div class="ev-row"><span class="w-16 flex-shrink-0">Ends</span>
            <span class="flex flex-1 justify-end gap-2 min-w-0"><input type="date" name="ev_end_date" class="ev-pill" aria-label="End date"/><input type="time" name="ev_end_time" class="ev-pill" aria-label="End time" data-timed/></span></div>
          <div class="ev-row flex-wrap !gap-y-2" id="ev-durations" data-timed>
            <span class="flex-1 min-w-[4rem]">Length</span>
            <span class="flex flex-wrap justify-end gap-1.5">
              <?php foreach ([30 => '30 min', 60 => '1 hr', 90 => '1½ hr', 120 => '2 hr', 180 => '3 hr', 240 => '4 hr'] as $min => $l): ?>
              <button type="button" class="ev-dur" data-min="<?= $min ?>"><?= $l ?></button>
              <?php endforeach; ?>
            </span>
          </div>
          <div class="ev-row flex-col !items-stretch !gap-2">
            <div class="flex items-center gap-3"><label for="ev_timezone" class="flex-1">Time zone</label><button type="button" class="ev-textbtn !text-sm hidden" id="ev-tz-mine"></button></div>
            <div class="grid grid-cols-1 sm:grid-cols-[11rem_1fr] gap-2">
              <span class="relative"><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[color:var(--muted-fg)]"></i><input type="search" id="ev-tz-filter" class="ev-pill w-full !pl-8" placeholder="City or country" aria-label="Search time zones" autocomplete="off"/></span>
              <select name="ev_timezone" id="ev_timezone" class="ev-pill w-full"><?= timezoneOptionsHtml($defaultTz) ?></select>
            </div>
          </div>
        </div>
        <div id="ev-when" class="ev-preview hidden" aria-live="polite"></div>
        <p class="ev-foot-note">Leave the date empty to show "Date to be announced". With no end time, events run about <?= EVENT_DEFAULT_MINUTES ?> minutes.</p>
      </section>

      <section>
        <h3 class="ev-group-h">Place</h3>
        <div class="ev-card">
          <div class="ev-field"><i class="fa-solid fa-location-dot ev-field-ic"></i><input name="ev_location" id="ev_location" class="ev-input" placeholder="Venue — leave empty if online only" aria-label="Venue"/></div>
          <div class="ev-field"><i class="fa-solid fa-video ev-field-ic"></i><input type="url" name="ev_online_url" id="ev_online_url" class="ev-input" placeholder="Online join link (https://…)" aria-label="Online join link" inputmode="url"/></div>
        </div>
        <p class="ev-foot-note">Only registered members see the join link.</p>
      </section>

      <section>
        <h3 class="ev-group-h">Details</h3>
        <div class="ev-card">
          <div class="ev-field"><i class="fa-solid fa-microphone ev-field-ic"></i><input name="ev_speaker" id="ev_speaker" class="ev-input" placeholder="Speaker" aria-label="Speaker"/></div>
          <label class="ev-row" for="ev_capacity"><span class="flex-1">Capacity</span><input type="number" min="1" name="ev_capacity" id="ev_capacity" class="ev-pill w-28 text-right" placeholder="Unlimited" inputmode="numeric"/></label>
          <div class="ev-field !items-start"><textarea name="ev_desc" id="ev_desc" rows="5" class="ev-input resize-y" placeholder="Description — what people will learn, agenda, what to bring" aria-label="Description"></textarea></div>
        </div>
      </section>

      <section>
        <h3 class="ev-group-h">After the event</h3>
        <div class="ev-card">
          <div class="ev-field"><i class="fa-solid fa-film ev-field-ic"></i><input type="url" name="ev_recording" id="ev_recording" class="ev-input" placeholder="Recording link (YouTube, Drive…)" aria-label="Recording link" inputmode="url"/></div>
        </div>
        <p class="ev-foot-note">Shown to members once the event has finished.</p>
      </section>

      <section id="ev-announce">
        <div class="ev-card">
          <label class="ev-row"><span class="flex-1 min-w-0"><span class="block">Announce in the community feed</span><span class="ev-sub" id="ev-announce-help">Posts a pinned announcement so members see it right away.</span></span>
            <input type="checkbox" name="announce" value="1" checked class="ev-switch" role="switch"/></label>
        </div>
      </section>
    </div>
    <div class="px-5 py-3.5 border-t border-[color:var(--border)] flex items-center justify-end gap-2 bg-white">
      <p class="text-sm text-red-600 me-auto hidden" id="ev-form-error" role="alert"></p>
      <button type="button" class="rarl-btn" data-ev-close>Cancel</button>
      <button type="submit" class="rarl-btn rarl-btn-primary" id="ev-submit"><i class="fa-solid fa-check"></i> Create event</button>
    </div>
  </form>
</aside>

<dialog class="rarl-amodal" id="share-modal" aria-labelledby="share-t" style="--modal-w:520px">
  <div class="rarl-amodal-head !items-center">
    <div class="w-12 h-12 rounded-xl bg-[color:var(--muted)] overflow-hidden flex-shrink-0 flex items-center justify-center text-[color:var(--muted-fg)]"><img id="share-thumb" alt="" class="hidden w-full h-full object-cover"/><i class="fa-regular fa-calendar" id="share-thumb-ic"></i></div>
    <div class="flex-1 min-w-0"><h2 id="share-t" class="truncate">Share event</h2><p class="truncate" id="share-sub"></p></div>
    <button type="button" class="rarl-icon-btn" data-amodal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="rarl-amodal-body ev-grouped space-y-5">
    <div id="share-notes" class="space-y-2"></div>

    <div class="ev-apps" id="share-social" role="list">
      <a role="listitem" data-net="whatsapp" target="_blank" rel="noopener"><span style="background:#25d366"><i class="fa-brands fa-whatsapp"></i></span>WhatsApp</a>
      <a role="listitem" data-net="linkedin" target="_blank" rel="noopener"><span style="background:#0a66c2"><i class="fa-brands fa-linkedin-in"></i></span>LinkedIn</a>
      <a role="listitem" data-net="x" target="_blank" rel="noopener"><span style="background:#000"><i class="fa-brands fa-x-twitter"></i></span>X</a>
      <a role="listitem" data-net="facebook" target="_blank" rel="noopener"><span style="background:#1877f2"><i class="fa-brands fa-facebook-f"></i></span>Facebook</a>
      <a role="listitem" data-net="telegram" target="_blank" rel="noopener"><span style="background:#229ed9"><i class="fa-brands fa-telegram"></i></span>Telegram</a>
      <a role="listitem" data-net="email"><span style="background:#64748b"><i class="fa-solid fa-envelope"></i></span>Email</a>
      <button role="listitem" type="button" class="hidden" id="share-native"><span style="background:#e4e4e7;color:#1a1f29"><i class="fa-solid fa-ellipsis"></i></span>More</button>
    </div>

    <div class="ev-card">
      <div class="ev-row">
        <i class="fa-solid fa-link text-[color:var(--muted-fg)]"></i>
        <input id="share-url" class="flex-1 min-w-0 bg-transparent font-mono text-xs text-[color:var(--fg)] outline-none truncate" readonly aria-label="Event link"/>
        <button type="button" class="ev-textbtn ev-textbtn-strong" id="share-copy">Copy</button>
      </div>
      <button type="button" class="ev-row w-full text-left" id="share-copy-text"><i class="fa-regular fa-message text-[color:var(--muted-fg)]"></i><span class="flex-1">Copy invitation message</span><i class="fa-solid fa-chevron-right text-xs text-[color:var(--muted-fg)]"></i></button>
      <a id="share-open" target="_blank" rel="noopener" class="ev-row"><i class="fa-solid fa-arrow-up-right-from-square text-[color:var(--muted-fg)]"></i><span class="flex-1">Open event page</span><i class="fa-solid fa-chevron-right text-xs text-[color:var(--muted-fg)]"></i></a>
    </div>

    <div class="ev-card ev-card-solo p-4 flex items-center gap-4">
      <div class="w-32 h-32 flex-shrink-0 rounded-xl bg-white ring-1 ring-[color:var(--border)] p-1.5"><img id="share-qr" alt="QR code for the event link" class="w-full h-full [image-rendering:pixelated]"/></div>
      <div class="min-w-0">
        <p class="font-semibold">QR code</p>
        <p class="text-sm text-[color:var(--muted-fg)] mt-0.5">For posters, slides and the venue door. Scanning opens the event.</p>
        <a id="share-qr-dl" class="rarl-btn rarl-btn-sm mt-3" download><i class="fa-solid fa-download"></i> Download PNG</a>
      </div>
    </div>

    <form method="POST" id="share-reset" class="hidden" data-confirm="Make a new link for this event? The current link and QR code stop working straight away. People already registered keep access.">
      <?= acsrfField() ?><input type="hidden" name="action" value="reset_link"><input type="hidden" name="ev_id" value=""><input type="hidden" name="return_tab" value="<?= ex($tab) ?>">
      <div class="ev-card"><button class="ev-row w-full text-left text-red-600"><i class="fa-solid fa-rotate"></i><span class="flex-1">Reset link</span></button></div>
      <p class="ev-foot-note">Use this if the link reached people it shouldn't have.</p>
    </form>
  </div>
</dialog>

<style>
  /* iOS-style grouped form: inset cards of 44px+ rows, quiet section headers, explanatory footnotes. */
  .ev-grouped{background:#f2f2f7}
  .ev-group-h{font-size:.8125rem;font-weight:600;color:var(--muted-fg);text-transform:uppercase;letter-spacing:.03em;margin:0 0 .45rem .9rem}
  .ev-foot-note{font-size:.8125rem;line-height:1.4;color:var(--muted-fg);margin:.45rem .9rem 0}
  .ev-card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 0 0 .5px rgba(0,0,0,.06)}
  .ev-row,.ev-field{display:flex;align-items:center;gap:.75rem;min-height:46px;padding:.55rem 1rem;font-size:.9375rem;color:var(--fg);position:relative}
  .ev-card>*+*::before{content:"";position:absolute;top:0;left:1rem;right:0;border-top:.5px solid rgba(60,60,67,.18)}
  .ev-card>*+*{position:relative}
  .ev-card-solo>*+*::before{display:none}
  .ev-row.text-red-600,.ev-row .text-red-600{color:#dc2626}
  a.ev-row:hover,button.ev-row:hover{background:#f7f7f9}
  .ev-sub{display:block;font-size:.8125rem;color:var(--muted-fg);line-height:1.35;margin-top:.1rem}
  .ev-field-ic{width:1rem;text-align:center;color:var(--muted-fg);flex-shrink:0}
  .ev-input{flex:1;min-width:0;border:0;outline:0;background:transparent;font-size:.9375rem;padding:.2rem 0;color:var(--fg)}
  .ev-input::placeholder{color:#a1a1aa}
  .ev-select{border:0;background:transparent;color:var(--muted-fg);font-size:.9375rem;text-align:right;outline:0;cursor:pointer}
  .ev-pill{min-height:34px;border:0;border-radius:8px;background:#eeeef0;padding:.3rem .65rem;font-size:.9375rem;color:var(--fg);outline:0;min-width:0}
  .ev-pill:focus,.ev-input:focus-visible{box-shadow:0 0 0 3px var(--ring)}
  .ev-pill:disabled{opacity:.4}
  .ev-switch{appearance:none;-webkit-appearance:none;flex-shrink:0;width:51px;height:31px;border-radius:999px;background:#e9e9ea;position:relative;cursor:pointer;transition:background .2s}
  .ev-switch::after{content:"";position:absolute;top:2px;left:2px;width:27px;height:27px;border-radius:50%;background:#fff;box-shadow:0 3px 8px rgba(0,0,0,.15),0 1px 1px rgba(0,0,0,.16);transition:transform .2s cubic-bezier(.3,.7,.4,1)}
  .ev-switch:checked{background:#34c759}
  .ev-switch:checked::after{transform:translateX(20px)}
  .ev-switch:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
  .ev-switch:disabled{opacity:.45;cursor:not-allowed}
  .ev-seg{display:inline-flex;padding:2px;border-radius:9px;background:#e3e3e8}
  .ev-seg label{flex:1;position:relative;cursor:pointer}
  .ev-seg input{position:absolute;opacity:0;pointer-events:none}
  .ev-seg span{display:flex;align-items:center;justify-content:center;gap:.4rem;min-height:36px;padding:0 .9rem;border-radius:7px;font-size:.875rem;font-weight:500;color:var(--fg);white-space:nowrap;transition:background .15s,box-shadow .15s}
  .ev-seg span i{font-size:.8em;opacity:.7}
  .ev-seg input:checked+span{background:#fff;font-weight:600;box-shadow:0 3px 8px rgba(0,0,0,.12),0 3px 1px rgba(0,0,0,.04)}
  .ev-seg input:focus-visible+span{outline:2px solid var(--primary);outline-offset:1px}
  .ev-seg-sm span{min-height:30px;padding:0 .7rem;font-size:.8125rem}
  .ev-dur{min-height:32px;padding:0 .75rem;border-radius:999px;background:#eeeef0;font-size:.8125rem;font-weight:500;color:var(--fg)}
  .ev-dur:hover{background:#e4e4e7}
  .ev-dur.on{background:var(--primary);color:#fff}
  .ev-textbtn{font-size:1rem;color:var(--primary);min-height:44px;padding:0 .25rem}
  .ev-textbtn:hover{opacity:.75}
  .ev-textbtn-strong{font-weight:600}
  .ev-preview{margin-top:.6rem;padding:.75rem 1rem;border-radius:12px;background:#fff;font-size:.875rem;line-height:1.55;color:var(--muted-fg);box-shadow:0 0 0 .5px rgba(0,0,0,.06)}
  .ev-preview strong{color:var(--fg);font-weight:600}
  .ev-apps{display:flex;gap:.25rem;overflow-x:auto;padding:.25rem 0 .1rem;scrollbar-width:none}
  .ev-apps>*{flex:0 0 4.5rem;display:flex;flex-direction:column;align-items:center;gap:.4rem;font-size:.75rem;color:var(--fg);text-align:center}
  .ev-apps>* span{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.5rem;transition:transform .12s}
  .ev-apps>*:hover span{transform:scale(1.05)}
  .ev-apps>*:active span{transform:scale(.95)}
  .ev-apps>*:focus-visible{outline:none}.ev-apps>*:focus-visible span{outline:3px solid var(--ring)}
  #ev-drawer .ev-hidden-row{display:none}
  @media (prefers-reduced-motion:reduce){.ev-switch,.ev-switch::after,.ev-seg span,.ev-apps>* span{transition:none}}
</style>
<?= bulkBarScript() ?>
<script>
(function() {
  <?php $dec = fn($v) => html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
  const EVENTS = <?= json_encode(array_map(fn($e) => [
      'id' => (int)$e['id'], 'title' => $dec($e['title']), 'type' => $e['type'], 'visibility' => $e['visibility'],
      'date' => $e['event_date'], 'time' => $e['event_time'] ? substr($e['event_time'], 0, 5) : '',
      'end_date' => $e['end_date'] ?? null, 'end_time' => !empty($e['end_time']) ? substr($e['end_time'], 0, 5) : '',
      'timezone' => eventTz($e)->getName(),
      'location' => $dec($e['location']), 'online_url' => $e['online_url'], 'speaker' => $dec($e['speaker_name']), 'capacity' => $e['capacity'], 'desc' => $dec($e['description']),
      'recording' => $e['recording_url'], 'cover' => $e['cover_image'] ? '../uploads/events/' . rawurlencode($e['cover_image']) : '',
      'url' => eventUrl($e), 'when' => $dec(eventWhenText($e)), 'active' => (bool)$e['is_active'], 'phase' => $e['phase'],
      'regs' => (int)$e['reg_count'],
  ], $byId), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  // What was typed before a save failed on the server, so nothing is lost.
  const DRAFT = <?= json_encode($draft, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const DEFAULT_TZ = <?= json_encode($defaultTz) ?>;
  const DEFAULT_MIN = <?= EVENT_DEFAULT_MINUTES ?>;
  const drawer = document.getElementById('ev-drawer'), bg = document.getElementById('ev-bg'), form = document.getElementById('ev-form');
  const F = form.elements;
  const cover = F.ev_cover, prev = document.getElementById('ev-cover-preview'), empty = document.getElementById('ev-cover-empty');
  const tzSel = F.ev_timezone, tzFilter = document.getElementById('ev-tz-filter'), whenBox = document.getElementById('ev-when'), errBox = document.getElementById('ev-form-error');
  const browserTz = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; } })();
  let editing = null;

  // ── Time zone list: add country names so "India" or "Germany" finds the zone ──
  try {
    const names = new Intl.DisplayNames(['en'], {type: 'region'});
    for (const o of tzSel.options) if (o.dataset.cc) { try { o.textContent += ' — ' + names.of(o.dataset.cc); } catch (e) {} }
  } catch (e) {}
  function setTz(tz) {
    if (!tz) return;
    if (![...tzSel.options].some(o => o.value === tz)) { const o = new Option(tz, tz); tzSel.add(o, 0); }
    tzSel.value = tz;
  }
  tzFilter.addEventListener('input', () => {
    const q = tzFilter.value.trim().toLowerCase().replace(/\s+/g, ' ');
    let first = null;
    for (const o of tzSel.options) {
      const hit = !q || (o.textContent + ' ' + o.value.replace(/_/g, ' ')).toLowerCase().includes(q);
      o.hidden = !hit; if (hit && !first) first = o;
    }
    if (q && first && tzSel.selectedOptions[0]?.hidden) { tzSel.value = first.value; update(); }
  });
  tzFilter.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); tzSel.focus(); } });
  const mineBtn = document.getElementById('ev-tz-mine');
  if (browserTz) { mineBtn.textContent = 'Use my time zone (' + browserTz.replace(/_/g, ' ') + ')'; mineBtn.addEventListener('click', () => { tzFilter.value = ''; tzFilter.dispatchEvent(new Event('input')); setTz(browserTz); update(); }); }

  // ── Wall-clock time in a zone → UTC (handles daylight saving) ──
  function offsetMin(zone, ms) {
    const p = new Intl.DateTimeFormat('en-US', {timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'}).formatToParts(new Date(ms));
    const g = t => +p.find(x => x.type === t).value;
    return (Date.UTC(g('year'), g('month') - 1, g('day'), g('hour') % 24, g('minute'), g('second')) - ms) / 60000;
  }
  function toUtc(date, time, zone) {
    const [y, m, d] = date.split('-').map(Number), [hh, mm] = (time || '00:00').split(':').map(Number);
    const guess = Date.UTC(y, m - 1, d, hh, mm);
    let utc = guess - offsetMin(zone, guess) * 60000;
    const o2 = offsetMin(zone, utc); utc = guess - o2 * 60000;
    // Inside the hour skipped when clocks go forward: move forward, like the server does.
    if (offsetMin(zone, utc) !== o2 || (utc + offsetMin(zone, utc) * 60000) !== guess) utc = guess - offsetMin(zone, guess - 86400000) * 60000;
    return utc;
  }
  const fmt = (ms, zone, o) => new Intl.DateTimeFormat('en-GB', Object.assign({timeZone: zone}, o)).format(new Date(ms));
  const fmtTime = (ms, zone) => new Intl.DateTimeFormat('en-US', {timeZone: zone, hour: 'numeric', minute: '2-digit'}).format(new Date(ms));
  const tzName = (ms, zone) => { try { return new Intl.DateTimeFormat('en-US', {timeZone: zone, timeZoneName: 'short'}).formatToParts(new Date(ms)).find(p => p.type === 'timeZoneName').value; } catch (e) { return zone; } };
  const addDays = (date, n) => { const d = new Date(date + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const pad = n => String(n).padStart(2, '0');
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

  // Works out start/end and every problem with them. Same rules as the server.
  function compute() {
    const zone = tzSel.value, allDay = F.ev_all_day.checked;
    const r = {zone, allDay, errors: [], warnings: [], start: null, end: null};
    const d = F.ev_date.value, t = allDay ? '' : F.ev_time.value, ed = F.ev_end_date.value, et = allDay ? '' : F.ev_end_time.value;
    if (!d) { if (ed || et) r.warnings.push('Set a start date first — the end is ignored without one.'); return r; }
    if (ed && ed < d) { r.errors.push('The end date is before the start date.'); return r; }
    r.start = toUtc(d, t, zone);
    if (t) {
      const back = fmt(r.start, zone, {hour: '2-digit', minute: '2-digit', hourCycle: 'h23'});
      if (back !== t) r.warnings.push(t + ' doesn’t exist on that day in this zone (clocks go forward). It will start at ' + back + '.');
    }
    if (allDay) r.end = toUtc(ed || d, '23:59', zone);
    else if (t && et) {
      r.end = toUtc(ed || d, et, zone);
      if (r.end <= r.start) {
        if (!ed || ed === d) { r.end = toUtc(addDays(d, 1), et, zone); r.warnings.push('The end time is earlier than the start, so it ends the next day. Change the end date if that’s wrong.'); }
        else r.errors.push('The event ends before it starts.');
      }
    } else if (t) r.end = r.start + DEFAULT_MIN * 60000;
    else if (et) r.warnings.push('Add a start time — an end time on its own is ignored.');
    if (r.end && r.end - r.start > 14 * 86400000) r.warnings.push('This event lasts more than two weeks. Double-check the end date.');
    if (!editing && r.end && r.end < Date.now()) r.warnings.push('This is in the past. That’s fine for recording an event you already ran (it goes under Past).');
    return r;
  }
  function update() {
    const r = compute();
    const allDay = F.ev_all_day.checked;
    form.querySelectorAll('[data-timed]').forEach(el => { el.disabled = allDay; el.classList.toggle('opacity-40', allDay); });
    document.querySelectorAll('.ev-dur').forEach(b => b.classList.toggle('on', !!(r.start && r.end && F.ev_end_time.value && Math.round((r.end - r.start) / 60000) === +b.dataset.min)));
    const lines = [];
    if (r.start) {
      const z = r.zone, dateOpt = {weekday: 'short', day: 'numeric', month: 'short', year: 'numeric'};
      if (r.allDay) {
        lines.push('<strong class="text-gray-900">' + esc(fmt(r.start, z, dateOpt)) + (F.ev_end_date.value && F.ev_end_date.value !== F.ev_date.value ? ' – ' + esc(fmt(r.end, z, dateOpt)) : '') + '</strong> · all day in ' + esc(z.replace(/_/g, ' ')));
      } else {
        const sameDay = fmt(r.start, z, dateOpt) === fmt(r.end, z, dateOpt);
        lines.push('<strong class="text-gray-900">' + esc(fmt(r.start, z, dateOpt)) + ', ' + esc(fmtTime(r.start, z)) + ' – ' + (sameDay ? '' : esc(fmt(r.end, z, dateOpt)) + ', ') + esc(fmtTime(r.end, z)) + ' ' + esc(tzName(r.start, z)) + '</strong>'
          + (F.ev_end_time.value ? '' : ' <span class="text-gray-400">(end assumed)</span>'));
        const mins = Math.round((r.end - r.start) / 60000);
        lines.push('Length: ' + (mins >= 60 ? Math.floor(mins / 60) + 'h' + (mins % 60 ? ' ' + (mins % 60) + 'm' : '') : mins + 'm'));
        const others = [['UTC', 'UTC']];
        if (browserTz && browserTz !== z) others.unshift([browserTz, 'your time']);
        for (const [oz, label] of others) {
          const sd = fmt(r.start, oz, dateOpt) !== fmt(r.start, z, dateOpt);
          lines.push('= ' + esc(fmtTime(r.start, oz)) + ' – ' + esc(fmtTime(r.end, oz)) + ' ' + esc(tzName(r.start, oz)) + (sd ? ' on ' + esc(fmt(r.start, oz, {weekday: 'short', day: 'numeric', month: 'short'})) : '') + ' <span class="text-gray-400">(' + esc(label) + ')</span>');
        }
      }
    }
    r.warnings.forEach(w => lines.push('<span class="text-amber-700"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(w) + '</span>'));
    r.errors.forEach(w => lines.push('<span class="text-red-600 font-semibold"><i class="fa-solid fa-circle-exclamation"></i> ' + esc(w) + '</span>'));
    whenBox.innerHTML = lines.join('<br>');
    whenBox.classList.toggle('hidden', !lines.length);
    return r;
  }
  ['ev_date', 'ev_time', 'ev_end_date', 'ev_end_time', 'ev_timezone', 'ev_all_day'].forEach(n => F[n].addEventListener('input', update));
  F.ev_all_day.addEventListener('change', update);
  // Moving the start keeps the length; a start date with no end date picks the same day.
  let lastStart = null;
  function rememberStart() { lastStart = F.ev_date.value && F.ev_time.value ? toUtc(F.ev_date.value, F.ev_time.value, 'UTC') : null; }
  ['ev_date', 'ev_time'].forEach(n => F[n].addEventListener('focus', rememberStart));
  ['ev_date', 'ev_time'].forEach(n => F[n].addEventListener('change', () => {
    if (F.ev_end_date.value && F.ev_end_date.value < F.ev_date.value) F.ev_end_date.value = '';
    if (lastStart !== null && F.ev_date.value && F.ev_time.value && F.ev_end_time.value) {
      const oldEnd = toUtc(F.ev_end_date.value || new Date(lastStart).toISOString().slice(0, 10), F.ev_end_time.value, 'UTC');
      const len = oldEnd - lastStart;
      if (len > 0) { const ne = new Date(toUtc(F.ev_date.value, F.ev_time.value, 'UTC') + len); const nd = ne.toISOString().slice(0, 10);
        F.ev_end_time.value = pad(ne.getUTCHours()) + ':' + pad(ne.getUTCMinutes()); F.ev_end_date.value = nd !== F.ev_date.value ? nd : ''; }
    }
    rememberStart(); update();
  }));
  document.querySelectorAll('.ev-dur').forEach(b => b.addEventListener('click', () => {
    if (!F.ev_date.value || !F.ev_time.value) { errBox.textContent = 'Pick a start date and time first.'; errBox.classList.remove('hidden'); (F.ev_date.value ? F.ev_time : F.ev_date).focus(); return; }
    const e = new Date(toUtc(F.ev_date.value, F.ev_time.value, 'UTC') + b.dataset.min * 60000), nd = e.toISOString().slice(0, 10);
    F.ev_end_time.value = pad(e.getUTCHours()) + ':' + pad(e.getUTCMinutes()); F.ev_end_date.value = nd !== F.ev_date.value ? nd : '';
    errBox.classList.add('hidden'); update();
  }));

  // ── Visibility: link-only events are never announced ──
  const VIS_HELP = {
    public: 'Listed on the Events page and in search. Anyone can open it — no sign-in needed.',
    members_only: 'Listed for signed-in members. Anyone else with the link is asked to sign in or join.',
    unlisted: 'Not listed or searchable anywhere. Only people you give the link to can open it. Registering always needs a membership.',
  };
  function syncVisibility() {
    const v = form.querySelector('[name=ev_visibility]:checked')?.value || 'public';
    document.getElementById('ev-vis-help').textContent = VIS_HELP[v];
    const ann = F.announce, help = document.getElementById('ev-announce-help');
    ann.disabled = v === 'unlisted';
    if (v === 'unlisted') ann.checked = false;
    document.getElementById('ev-announce').classList.toggle('opacity-60', v === 'unlisted');
    help.textContent = v === 'unlisted' ? 'Not available for link-only events — they stay out of the feed. Share the link instead.' : (v === 'members_only' ? 'Posts a pinned announcement; only members can open the event.' : 'Posts a pinned announcement so members see it right away.');
  }
  form.querySelectorAll('[name=ev_visibility]').forEach(r => r.addEventListener('change', syncVisibility));
  // Cover style only matters when a cover is being generated.
  function syncCover() { document.getElementById('ev-cover-style').style.display = F.auto_cover.checked ? '' : 'none'; }
  F.auto_cover.addEventListener('change', syncCover);

  form.addEventListener('submit', e => {
    const r = update();
    let msg = '';
    if (!F.ev_title.value.trim()) msg = 'Add a title.';
    else if (r.errors.length) msg = r.errors[0];
    if (msg) { e.preventDefault(); e.stopImmediatePropagation(); errBox.textContent = msg; errBox.classList.remove('hidden'); (F.ev_title.value.trim() ? whenBox : F.ev_title).scrollIntoView({block: 'center', behavior: 'smooth'}); if (!F.ev_title.value.trim()) F.ev_title.focus(); }
  }, true);

  function show(src) { prev.src = src || ''; prev.classList.toggle('hidden', !src); empty.classList.toggle('hidden', !!src); }
  function open(e, focus) {
    editing = e && e.id ? e : null; form.reset(); cover.value = ''; errBox.classList.add('hidden');
    tzFilter.value = ''; tzFilter.dispatchEvent(new Event('input'));
    const set = (n, v) => { F[n].value = v ?? ''; };
    set('ev_id', e ? e.id : ''); set('ev_title', e?.title); set('ev_type', e?.type || 'workshop');
    (form.querySelector('[name=ev_visibility][value="' + (e?.visibility || 'public') + '"]') || form.querySelector('[name=ev_visibility]')).checked = true;
    setTz(e ? e.timezone : DEFAULT_TZ);
    set('ev_date', e?.date); set('ev_time', e?.time); set('ev_end_date', e?.end_date); set('ev_end_time', e?.end_time);
    F.ev_all_day.checked = e && 'all_day' in e ? !!e.all_day : !!(e && e.date && !e.time);
    set('ev_location', e?.location); set('ev_online_url', e?.online_url);
    set('ev_speaker', e?.speaker); set('ev_capacity', e?.capacity); set('ev_desc', e?.desc); set('ev_recording', e?.recording);
    show(e?.cover);
    document.getElementById('ev-remove-cover').style.display = e?.cover ? 'flex' : 'none';
    F.auto_cover.checked = e && 'auto_cover' in e ? !!e.auto_cover : !e?.cover;
    document.getElementById('ev-auto-label').textContent = e?.cover ? 'Replace with a new generated cover' : 'Make a cover for me';
    document.getElementById('ev-announce').style.display = editing ? 'none' : '';
    if (e && 'announce' in e) F.announce.checked = !!e.announce;
    document.getElementById('ev-form-title').textContent = editing ? 'Edit Event' : 'New Event';
    document.getElementById('ev-submit-top').textContent = editing ? 'Save' : 'Add';
    document.getElementById('ev-submit').innerHTML = editing ? '<i class="fa-solid fa-check"></i> Save changes' : '<i class="fa-solid fa-check"></i> Create event';
    syncVisibility(); syncCover(); rememberStart(); update();
    drawer.classList.add('open'); bg.classList.add('open');
    setTimeout(() => (focus ? document.getElementById(focus) : F.ev_title).focus(), 250);
  }
  function close() { drawer.classList.remove('open'); bg.classList.remove('open'); }
  cover.addEventListener('change', () => { if (cover.files[0]) show(URL.createObjectURL(cover.files[0])); });
  document.querySelectorAll('[data-new-event]').forEach(b => b.addEventListener('click', () => open(null)));
  document.querySelectorAll('[data-edit-event]').forEach(b => b.addEventListener('click', () => open(EVENTS[b.dataset.editEvent], b.dataset.focus)));
  document.querySelectorAll('[data-ev-close]').forEach(b => b.addEventListener('click', close));
  bg.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && drawer.classList.contains('open')) close(); });

  // ── Share panel ──
  const sm = document.getElementById('share-modal');
  let shareEv = null;
  const copy = (text, ok) => (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => rarlToast(ok, 'success')).catch(() => { const u = document.getElementById('share-url'); u.select(); rarlToast('Press Ctrl+C to copy', 'info'); });
  const message = ev => ev.title + '\n' + ev.when + '\n' + ev.url;
  function openShare(ev) {
    shareEv = ev;
    document.getElementById('share-t').textContent = ev.title;
    const th = document.getElementById('share-thumb'); th.classList.toggle('hidden', !ev.cover); th.src = ev.cover || '';
    document.getElementById('share-thumb-ic').classList.toggle('hidden', !!ev.cover);
    document.getElementById('share-sub').textContent = ev.when;
    document.getElementById('share-url').value = ev.url;
    document.getElementById('share-qr').src = 'events.php?qr=' + ev.id + '&v=' + encodeURIComponent(ev.url.slice(-8));
    document.getElementById('share-qr-dl').href = 'events.php?qr=' + ev.id + '&dl=1';
    document.getElementById('share-open').href = ev.url;
    const u = encodeURIComponent(ev.url), t = encodeURIComponent(ev.title + ' — ' + ev.when);
    const links = {
      whatsapp: 'https://wa.me/?text=' + encodeURIComponent(message(ev)),
      linkedin: 'https://www.linkedin.com/sharing/share-offsite/?url=' + u,
      x: 'https://twitter.com/intent/tweet?text=' + t + '&url=' + u,
      facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + u,
      telegram: 'https://t.me/share/url?url=' + u + '&text=' + t,
      email: 'mailto:?subject=' + encodeURIComponent(ev.title) + '&body=' + encodeURIComponent(message(ev)),
    };
    sm.querySelectorAll('[data-net]').forEach(a => a.href = links[a.dataset.net]);
    const notes = [];
    const note = (tone, icon, html) => notes.push('<div class="flex gap-2 text-xs rounded-lg px-3 py-2 ' + tone + '"><i class="fa-solid ' + icon + ' mt-0.5"></i><span>' + html + '</span></div>');
    if (!ev.active) note('bg-red-50 text-red-700', 'fa-eye-slash', '<strong>Hidden.</strong> The link shows “not available” until you publish the event.');
    if (ev.visibility === 'unlisted') note('bg-violet-50 text-violet-800', 'fa-link', '<strong>Link only.</strong> Anyone with this link or QR code can open it. Share it only with the people you mean to.');
    if (ev.visibility === 'members_only') note('bg-amber-50 text-amber-800', 'fa-lock', '<strong>Members only.</strong> Anyone else who opens the link is asked to sign in.');
    if (ev.phase === 'past') note('bg-gray-100 text-gray-700', 'fa-clock-rotate-left', 'This event has already finished. The link still works and shows it as a past event.');
    if (ev.phase === 'tba') note('bg-gray-100 text-gray-700', 'fa-calendar-xmark', 'No date yet — it shows as “Date to be announced”.');
    document.getElementById('share-notes').innerHTML = notes.join('');
    const reset = document.getElementById('share-reset');
    reset.classList.toggle('hidden', ev.visibility !== 'unlisted');
    reset.elements.ev_id.value = ev.id;
    sm.showModal();
  }
  const copyBtn = document.getElementById('share-copy');
  copyBtn.addEventListener('click', () => { if (!shareEv) return; copy(shareEv.url, 'Link copied'); copyBtn.textContent = 'Copied'; copyBtn.classList.add('!text-green-600'); setTimeout(() => { copyBtn.textContent = 'Copy'; copyBtn.classList.remove('!text-green-600'); }, 1800); });
  document.getElementById('share-copy-text').addEventListener('click', () => shareEv && copy(message(shareEv), 'Message copied — paste it anywhere'));
  document.getElementById('share-url').addEventListener('focus', e => e.target.select());
  const nativeBtn = document.getElementById('share-native');
  if (navigator.share) { nativeBtn.classList.remove('hidden'); nativeBtn.addEventListener('click', () => shareEv && navigator.share({title: shareEv.title, text: shareEv.title + ' — ' + shareEv.when, url: shareEv.url}).catch(() => {})); }
  document.querySelectorAll('[data-share-event]').forEach(b => b.addEventListener('click', () => openShare(EVENTS[b.dataset.shareEvent])));

  const editId = <?= (int)$editId ?>; if (editId && EVENTS[editId] && !DRAFT) open(EVENTS[editId]);
  const shareId = <?= (int)$shareId ?>; if (shareId && EVENTS[shareId]) document.addEventListener('DOMContentLoaded', () => openShare(EVENTS[shareId]));
  if (DRAFT) open(DRAFT);
  if (shareId || editId || DRAFT) { const qs = new URLSearchParams(location.search); ['share', 'edit'].forEach(k => qs.delete(k)); history.replaceState(null, '', location.pathname + (qs.toString() ? '?' + qs : '')); }
  if (location.hash === '#new') open(null);
})();
</script>
<?php }, 'events', 'Events');
