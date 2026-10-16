<?php
/**
 * RARL — Events & Webinars
 * Card grid; every card opens a detail modal (description, schedule, place,
 * speaker, capacity, RSVP, add-to-calendar, share). #event-ID links open the
 * modal directly, so Home, the feed and emails can deep-link to an event.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$isMember = !empty($_SESSION['member_id']);
$myMemberId = (int)($_SESSION['member_id'] ?? 0);
$myStatus = null;
if ($isMember) {
    $s = $pdo->prepare('SELECT status FROM members WHERE id = ?');
    $s->execute([$myMemberId]);
    $myStatus = $s->fetchColumn();
}
$isActiveMember = $myStatus === 'active';

// ── Add to calendar (.ics) ──
if (!empty($_GET['ics'])) {
    $ev = $pdo->prepare("SELECT * FROM events WHERE id = ? AND is_active = 1" . ($isActiveMember ? '' : " AND visibility = 'public'"));
    $ev->execute([(int)$_GET['ics']]);
    $e = $ev->fetch();
    if (!$e || !$e['event_date']) { http_response_code(404); exit('Event not found'); }
    $esc = function ($v) {
        $v = html_entity_decode(strip_tags((string)$v), ENT_QUOTES, 'UTF-8');
        $v = str_replace(['\\', ';', ','], ['\\\\', '\;', '\,'], $v);
        return str_replace(["\r\n", "\n"], '\n', $v);
    };
    $at    = $e['event_date'] . ' ' . ($e['event_time'] ?: '00:00:00');
    $start = $e['event_time'] ? date('Ymd\THis', strtotime($at)) : date('Ymd', strtotime($e['event_date']));
    $end   = $e['event_time'] ? date('Ymd\THis', strtotime($at . ' +90 minutes')) : date('Ymd', strtotime($e['event_date'] . ' +1 day'));
    $dt    = $e['event_time'] ? '' : ';VALUE=DATE';
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RARL//Events//EN', 'BEGIN:VEVENT',
        'UID:rarl-event-' . (int)$e['id'] . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'rarl-lab.com'),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        "DTSTART{$dt}:{$start}", "DTEND{$dt}:{$end}",
        'SUMMARY:' . $esc($e['title']),
    ];
    if ($e['description']) $lines[] = 'DESCRIPTION:' . $esc($e['description']);
    if ($e['location'] ?: $e['online_url']) $lines[] = 'LOCATION:' . $esc($e['location'] ?: $e['online_url']);
    $lines[] = 'URL:' . SITE_URL . '/events.php#event-' . (int)$e['id'];
    array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="rarl-event-' . (int)$e['id'] . '.ics"');
    echo implode("\r\n", $lines) . "\r\n";
    exit;
}

// ── RSVP handling ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck() && $isActiveMember) {
    $action  = $_POST['action'] ?? '';
    $eventId = (int)($_POST['event_id'] ?? 0);

    if ($action === 'rsvp' && $eventId) {
        $ev = $pdo->prepare('SELECT * FROM events WHERE id = ? AND is_active = 1');
        $ev->execute([$eventId]);
        $event = $ev->fetch();
        if ($event) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status != 'cancelled'");
            $countStmt->execute([$eventId]);
            $isFull = $event['capacity'] !== null && (int)$countStmt->fetchColumn() >= (int)$event['capacity'];
            if (!$isFull) {
                try {
                    $pdo->prepare("INSERT INTO event_registrations (event_id, member_id, status) VALUES (?,?,'registered')
                                   ON DUPLICATE KEY UPDATE status = IF(status='cancelled','registered',status)")
                        ->execute([$eventId, $myMemberId]);
                    flash('success', 'You\'re registered for "' . html_entity_decode($event['title'], ENT_QUOTES) . '". Add it to your calendar so you don\'t miss it.');
                } catch (PDOException $e) { /* already registered */ }
            } else {
                flash('error', 'Sorry, this event is fully booked.');
            }
        }
        redirect(($_POST['return'] ?? '') === 'dashboard' ? 'dashboard.php' : 'events.php#event-' . $eventId);
    }

    if ($action === 'cancel_rsvp' && $eventId) {
        $pdo->prepare("UPDATE event_registrations SET status='cancelled' WHERE event_id=? AND member_id=?")->execute([$eventId, $myMemberId]);
        flash('success', 'Registration cancelled.');
        redirect('events.php#event-' . $eventId);
    }
}

// ── Event lists ──
$shared = null;
if (!empty($_GET['event'])) {
    $se = $pdo->prepare("SELECT * FROM events WHERE id = ? AND is_active = 1" . ($isActiveMember ? '' : " AND visibility = 'public'"));
    $se->execute([(int)$_GET['event']]);
    $shared = $se->fetch() ?: null;
}
$tab = ($_GET['tab'] ?? ($shared && $shared['event_date'] && $shared['event_date'] < date('Y-m-d') ? 'past' : 'upcoming')) === 'past' ? 'past' : 'upcoming';
$visibilityFilter = $isActiveMember ? "" : "AND visibility = 'public'";
if ($tab === 'upcoming') {
    $events = $pdo->query("SELECT * FROM events WHERE is_active = 1 {$visibilityFilter}
        AND (event_date IS NULL OR event_date >= CURDATE())
        ORDER BY event_date IS NULL, event_date ASC, event_time ASC")->fetchAll();
} else {
    $events = $pdo->query("SELECT * FROM events WHERE is_active = 1 {$visibilityFilter}
        AND event_date IS NOT NULL AND event_date < CURDATE()
        ORDER BY event_date DESC, event_time DESC LIMIT 30")->fetchAll();
}
// Every visible event id, so a link to a past event from the upcoming tab (or vice versa) can switch tabs.
$otherTabIds = array_map('intval', $pdo->query("SELECT id FROM events WHERE is_active = 1 {$visibilityFilter} AND " . ($tab === 'upcoming' ? 'event_date IS NOT NULL AND event_date < CURDATE()' : '(event_date IS NULL OR event_date >= CURDATE())'))->fetchAll(PDO::FETCH_COLUMN));

$myRegistrations = []; $regCounts = [];
if ($events) {
    $ids = array_column($events, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $rc  = $pdo->prepare("SELECT event_id, COUNT(*) FROM event_registrations WHERE status != 'cancelled' AND event_id IN ($in) GROUP BY event_id");
    $rc->execute($ids);
    $regCounts = $rc->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($isActiveMember) {
        $rs = $pdo->prepare("SELECT event_id, status FROM event_registrations WHERE member_id = ? AND event_id IN ($in)");
        $rs->execute(array_merge([$myMemberId], $ids));
        $myRegistrations = $rs->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

$typeIcons = ['workshop' => 'fa-screwdriver-wrench', 'webinar' => 'fa-video', 'hackathon' => 'fa-laptop-code', 'competition' => 'fa-trophy', 'volunteer' => 'fa-handshake', 'conference' => 'fa-microphone', 'seminar' => 'fa-book-open', 'other' => 'fa-calendar-days'];
$typesPresent = array_values(array_unique(array_column($events, 'type')));
$coverUrl = fn($e) => empty($e['cover_image']) ? null : (str_starts_with((string)$e['cover_image'], 'http') ? $e['cover_image'] : UPLOADS_URL . '/events/' . $e['cover_image']);

// Recordings linked to these events (Admin → Videos).
$eventVideos = [];
if ($events && videosReady()) {
    $ids = implode(',', array_map('intval', array_column($events, 'id')));
    foreach ($pdo->query("SELECT id, event_id, youtube_id, access_level FROM videos WHERE is_published = 1 AND event_id IN ($ids) ORDER BY created_at") as $v) $eventVideos[(int)$v['event_id']] ??= $v;
}
$sharedCover = $shared ? $coverUrl($shared) : null;
echo htmlHead($shared ? html_entity_decode($shared['title'], ENT_QUOTES) : 'Events & Webinars', false, [
    'description' => $shared
        ? trim(($shared['event_date'] ? date('l, d F Y', strtotime($shared['event_date'])) . ($shared['event_time'] ? ', ' . date('g:i A', strtotime($shared['event_time'])) : '') . ' · ' : '') . ($shared['online_url'] && !$shared['location'] ? 'Online · ' : '') . mb_strimwidth(html_entity_decode(strip_tags((string)$shared['description']), ENT_QUOTES), 0, 180, '…'))
        : 'Free webinars, workshops and conferences on robotics, AI and automation from the Robotics & Automation Research Lab (RARL). Register in one click.',
    'image' => $sharedCover && str_starts_with($sharedCover, 'http') ? $sharedCover : ($sharedCover ? rtrim(SITE_URL, '/') . '/' . ltrim(str_replace(UPLOADS_URL, 'uploads', $sharedCover), '/') : null),
    'type' => $shared ? 'event' : 'website',
]);
?>
<?= publicNav('events') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
<section class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 pt-7">
    <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">RARL events</p>
    <h1 class="font-heading font-bold text-2xl md:text-3xl text-gray-900 dark:text-white">Events &amp; Webinars</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">Workshops, webinars, hackathons and conferences run by RARL. Tap an event for details; your certificate arrives after you attend.</p>
    <nav class="flex gap-6 mt-5 text-sm font-semibold" aria-label="Event lists">
      <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past &amp; recordings'] as $k => $label): ?>
      <a href="events.php?tab=<?= $k ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?> class="pb-3 border-b-2 <?= $tab === $k ? 'border-rarl-red text-gray-900 dark:text-white' : 'border-transparent text-gray-500 hover:text-gray-900 dark:hover:text-white' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</section>

<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
  <?= renderFlash() ?>

  <?php if (count($typesPresent) > 1): ?>
  <div class="flex gap-2 overflow-x-auto pb-1 mb-5 -mx-1 px-1" role="group" aria-label="Filter by type">
    <button type="button" data-type="" aria-pressed="true" class="ev-chip">All <span class="opacity-60"><?= count($events) ?></span></button>
    <?php foreach ($typesPresent as $t): ?>
    <button type="button" data-type="<?= ex($t) ?>" aria-pressed="false" class="ev-chip"><i class="fa-solid <?= $typeIcons[$t] ?? 'fa-calendar-days' ?> text-xs"></i> <?= ucfirst($t) ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!$events): ?>
  <div class="rarl-panel p-12 text-center">
    <i class="fa-regular fa-calendar text-4xl text-gray-300"></i>
    <p class="font-semibold text-gray-800 dark:text-gray-100 mt-3"><?= $tab === 'upcoming' ? 'No upcoming events yet' : 'No past events to show' ?></p>
    <p class="text-sm text-gray-500 mt-1"><?= $tab === 'upcoming' ? 'New events are announced on your Home feed.' : 'Recordings of past events will appear here.' ?></p>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5" id="event-grid">
    <?php foreach ($events as $e):
      $id = (int)$e['id']; $count = (int)($regCounts[$id] ?? 0);
      $isFull = $e['capacity'] !== null && $count >= (int)$e['capacity'];
      $mine = in_array($myRegistrations[$id] ?? null, ['registered', 'attended'], true);
      $cover = $coverUrl($e); $icon = $typeIcons[$e['type']] ?? 'fa-calendar-days'; ?>
    <button type="button" data-modal="event-<?= $id ?>" data-type="<?= ex($e['type']) ?>" aria-haspopup="dialog"
      class="group text-start rarl-panel overflow-hidden flex flex-col hover:shadow-xl hover:-translate-y-0.5 focus-visible:-translate-y-0.5 transition">
      <span class="relative block aspect-[16/9] w-full overflow-hidden bg-gradient-to-br from-rarl-red/[.07] to-rarl-red/[.18] dark:from-rarl-red/10 dark:to-rarl-red/25">
        <?php if ($cover): ?><img src="<?= ex($cover) ?>" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500"/>
        <?php else: ?><i class="fa-solid <?= $icon ?> absolute inset-0 m-auto w-fit h-fit text-5xl text-rarl-red/60"></i><?php endif; ?>
        <?php if ($e['event_date']): ?>
        <span class="absolute top-3 start-3 bg-white/95 dark:bg-gray-900/95 rounded-xl px-2.5 py-1.5 text-center shadow-sm leading-none">
          <span class="block text-[10px] font-bold uppercase text-rarl-red"><?= date('M', strtotime($e['event_date'])) ?></span>
          <span class="block font-heading font-bold text-xl text-gray-900 dark:text-white mt-0.5"><?= date('d', strtotime($e['event_date'])) ?></span>
        </span>
        <?php endif; ?>
        <?php if ($mine): ?><span class="absolute top-3 end-3 bg-green-600 text-white text-xs font-semibold rounded-full px-2.5 py-1 shadow"><i class="fa-solid fa-check"></i> Going</span>
        <?php elseif ($e['visibility'] === 'members_only'): ?><span class="absolute top-3 end-3 bg-gray-900/80 text-white text-xs font-semibold rounded-full px-2.5 py-1"><i class="fa-solid fa-lock"></i> Members</span><?php endif; ?>
      </span>
      <span class="p-4 sm:p-5 flex-1 flex flex-col">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><i class="fa-solid <?= $icon ?>"></i> <?= ucfirst($e['type']) ?><?= $e['online_url'] ? ' · Online' : '' ?></span>
        <span class="block font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug mt-1 group-hover:text-rarl-red"><?= ex($e['title']) ?></span>
        <span class="block text-sm text-gray-600 dark:text-gray-400 mt-1.5">
          <?= $e['event_date'] ? date('D, d M Y', strtotime($e['event_date'])) . ($e['event_time'] ? ' · ' . date('g:i A', strtotime($e['event_time'])) : '') : 'Date to be announced' ?>
        </span>
        <?php if ($e['location']): ?><span class="block text-sm text-gray-500 truncate"><i class="fa-solid fa-location-dot"></i> <?= ex($e['location']) ?></span><?php endif; ?>
        <span class="mt-auto pt-4 flex items-center justify-between gap-3 text-sm">
          <?php if ($tab === 'upcoming' && $e['capacity'] !== null): ?>
          <span class="text-gray-500"><?= $isFull ? '<span class="font-semibold text-red-600">Fully booked</span>' : ((int)$e['capacity'] - $count) . ' places left' ?></span>
          <?php elseif ($tab === 'past' && $e['recording_url']): ?><span class="text-gray-500"><i class="fa-solid fa-circle-play"></i> Recording</span>
          <?php else: ?><span></span><?php endif; ?>
          <span class="font-semibold text-rarl-red">Details <i class="fa-solid fa-arrow-right text-xs"></i></span>
        </span>
      </span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>
</div>

<?php foreach ($events as $e):
  $id = (int)$e['id']; $count = (int)($regCounts[$id] ?? 0);
  $isFull = $e['capacity'] !== null && $count >= (int)$e['capacity'];
  $myState = $myRegistrations[$id] ?? null; $mine = in_array($myState, ['registered', 'attended'], true);
  $locked = $e['visibility'] === 'members_only' && !$isActiveMember;
  $cover = $coverUrl($e); $icon = $typeIcons[$e['type']] ?? 'fa-calendar-days';
  $when = $e['event_date'] ? date('l, d F Y', strtotime($e['event_date'])) : 'Date to be announced';
  $gcal = $e['event_date'] ? 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode(html_entity_decode($e['title'], ENT_QUOTES))
        . '&dates=' . ($e['event_time'] ? date('Ymd\THis', strtotime($e['event_date'] . ' ' . $e['event_time'])) . '/' . date('Ymd\THis', strtotime($e['event_date'] . ' ' . $e['event_time'] . ' +90 minutes'))
                                         : date('Ymd', strtotime($e['event_date'])) . '/' . date('Ymd', strtotime($e['event_date'] . ' +1 day')))
        . '&details=' . rawurlencode(SITE_URL . '/events.php#event-' . $id) . '&location=' . rawurlencode(html_entity_decode((string)($e['location'] ?: $e['online_url']), ENT_QUOTES)) : null; ?>
<dialog class="rarl-modal" id="event-<?= $id ?>" aria-labelledby="event-<?= $id ?>-title">
  <div class="rarl-modal-panel" style="--modal-w:42rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="relative aspect-[21/9] bg-gradient-to-br from-rarl-red/[.08] to-rarl-red/[.22] dark:from-rarl-red/15 dark:to-rarl-red/30 sm:rounded-t-[1.5rem] overflow-hidden">
      <?php if ($cover): ?><img src="<?= ex($cover) ?>" alt="" class="absolute inset-0 w-full h-full object-cover"/>
      <?php else: ?><i class="fa-solid <?= $icon ?> absolute inset-0 m-auto w-fit h-fit text-6xl text-rarl-red/50"></i><?php endif; ?>
    </div>
    <div class="p-5 sm:p-7">
      <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
        <span class="px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300"><i class="fa-solid <?= $icon ?>"></i> <?= ucfirst($e['type']) ?></span>
        <?php if ($e['visibility'] === 'members_only'): ?><span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="fa-solid fa-lock"></i> Members only</span><?php endif; ?>
        <?php if ($e['online_url']): ?><span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><i class="fa-solid fa-wifi"></i> Online</span><?php endif; ?>
        <?php if ($mine): ?><span class="px-2.5 py-1 rounded-full bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300"><i class="fa-solid fa-circle-check"></i> <?= $myState === 'attended' ? 'Attended' : "You're going" ?></span><?php endif; ?>
      </div>
      <h2 id="event-<?= $id ?>-title" class="font-heading font-bold text-2xl text-gray-900 dark:text-white leading-tight mt-3"><?= ex($e['title']) ?></h2>

      <dl class="grid sm:grid-cols-2 gap-3 mt-5">
        <div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-regular fa-calendar text-rarl-red mt-1 w-4 text-center"></i><div><dt class="text-xs text-gray-500">When</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= $when ?><?php if ($e['event_time']): ?><span class="block font-normal text-gray-600 dark:text-gray-400"><?= date('g:i A', strtotime($e['event_time'])) ?> (organiser's local time)</span><?php endif; ?></dd></div></div>
        <div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-solid <?= $e['location'] ? 'fa-location-dot' : 'fa-wifi' ?> text-rarl-red mt-1 w-4 text-center"></i><div><dt class="text-xs text-gray-500">Where</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= $e['location'] ? ex($e['location']) : ($e['online_url'] ? 'Online — link shared with registered members' : 'To be announced') ?></dd></div></div>
        <?php if ($e['speaker_name']): ?><div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-solid fa-microphone text-rarl-red mt-1 w-4 text-center"></i><div><dt class="text-xs text-gray-500">Speaker</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= ex($e['speaker_name']) ?></dd></div></div><?php endif; ?>
        <?php if ($e['capacity'] !== null && $tab === 'upcoming'): ?>
        <div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-solid fa-users text-rarl-red mt-1 w-4 text-center"></i><div class="flex-1"><dt class="text-xs text-gray-500">Places</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= $isFull ? 'Fully booked' : ((int)$e['capacity'] - $count) . ' of ' . (int)$e['capacity'] . ' left' ?>
          <span class="block h-1.5 mt-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden" aria-hidden="true"><span class="block h-full bg-rarl-red rounded-full" style="width:<?= $e['capacity'] > 0 ? min(100, round($count / $e['capacity'] * 100)) : 0 ?>%"></span></span></dd></div></div>
        <?php endif; ?>
      </dl>

      <?php if ($e['description']): ?>
      <div class="mt-5"><h3 class="text-sm font-bold text-gray-900 dark:text-white">About this event</h3>
        <p class="mt-1.5 text-[15px] leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-line"><?= ex($e['description']) ?></p></div>
      <?php endif; ?>

      <div class="sticky bottom-0 -mx-5 sm:-mx-7 -mb-5 sm:-mb-7 mt-6 px-5 sm:px-7 py-4 bg-white/95 dark:bg-gray-900/95 backdrop-blur border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-2">
        <?php if ($tab === 'past'): ?>
          <?php if (!empty($eventVideos[$id]) && ($eventVideos[$id]['access_level'] === 'public' || $isActiveMember)): ?><a href="videos.php?v=<?= (int)$eventVideos[$id]['id'] ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-play"></i> Watch recording</a>
          <?php elseif ($e['recording_url'] && $isActiveMember): ?><a href="<?= ex($e['recording_url']) ?>" target="_blank" rel="noopener" class="ev-btn ev-btn-primary"><i class="fa-solid fa-play"></i> Watch recording</a>
          <?php elseif ($e['recording_url']): ?><a href="<?= $isMember ? 'membership.php' : 'register.php' ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-lock"></i> <?= $isMember ? 'Recording for active members' : 'Join free to watch' ?></a>
          <?php else: ?><span class="text-sm text-gray-500">No recording for this event.</span><?php endif; ?>
        <?php elseif ($locked && !$isMember): ?><a href="register.php" class="ev-btn ev-btn-primary"><i class="fa-solid fa-lock"></i> Join free to register</a><a href="login.php?next=<?= rawurlencode('events.php#event-' . $id) ?>" class="ev-btn">Sign in</a>
        <?php elseif (!$isMember): ?><a href="login.php?next=<?= rawurlencode('events.php#event-' . $id) ?>" class="ev-btn ev-btn-primary">Sign in to register</a><a href="register.php" class="ev-btn">Join free</a>
        <?php elseif (!$isActiveMember): ?><span class="text-sm text-amber-800 dark:text-amber-300"><i class="fa-solid fa-hourglass-half"></i> Registration opens once your membership is approved.</span>
        <?php elseif ($mine): ?>
          <?php if ($myState === 'registered' && !$e['online_url']): ?><button type="button" data-modal="pass-<?= $id ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-qrcode"></i> Check-in pass</button><?php endif; ?>
          <?php if ($e['online_url']): ?><a href="<?= ex($e['online_url']) ?>" target="_blank" rel="noopener" class="ev-btn ev-btn-primary"><i class="fa-solid fa-video"></i> Join link</a><?php endif; ?>
          <form method="POST" data-confirm="Cancel your registration for this event?"><?= csrfField() ?><input type="hidden" name="action" value="cancel_rsvp"><input type="hidden" name="event_id" value="<?= $id ?>"><button class="ev-btn" onclick="return confirm('Cancel your registration for this event?')">Cancel registration</button></form>
        <?php elseif ($isFull): ?><span class="ev-btn opacity-60 cursor-not-allowed">Fully booked</span>
        <?php else: ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="event_id" value="<?= $id ?>"><button class="ev-btn ev-btn-primary"><i class="fa-solid fa-check"></i> Register — free</button></form>
        <?php endif; ?>
        <span class="flex gap-2 ms-auto">
          <?php if ($tab === 'upcoming' && $e['event_date']): ?>
          <a href="events.php?ics=<?= $id ?>" class="ev-icon" title="Add to calendar (Apple, Outlook)" aria-label="Download calendar file"><i class="fa-regular fa-calendar-plus"></i></a>
          <a href="<?= ex($gcal) ?>" target="_blank" rel="noopener" class="ev-icon" title="Add to Google Calendar" aria-label="Add to Google Calendar"><i class="fa-brands fa-google"></i></a>
          <?php endif; ?>
          <button type="button" class="ev-icon" data-share-url="<?= ex(SITE_URL . '/events.php?event=' . $id) ?>" data-share-title="<?= ex($e['title']) ?>" title="Share" aria-label="Share event"><i class="fa-solid fa-share-nodes"></i></button>
        </span>
      </div>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<?php // Check-in passes: one per event this member is registered for.
if ($isActiveMember):
  $meRow = $pdo->prepare('SELECT full_name, lab_name, type, member_code, avatar_path FROM members WHERE id = ?'); $meRow->execute([$myMemberId]); $meRow = $meRow->fetch();
  $meName = $meRow ? ($meRow['type'] === 'lab' ? $meRow['lab_name'] : $meRow['full_name']) : '';
  foreach ($events as $e): if (($myRegistrations[(int)$e['id']] ?? null) !== 'registered' || $e['online_url']) continue; $id = (int)$e['id']; ?>
<dialog class="rarl-modal" id="pass-<?= $id ?>" aria-labelledby="pass-<?= $id ?>-t">
  <div class="rarl-modal-panel" style="--modal-w:24rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="p-6 pt-8 text-center">
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">Check-in pass</p>
      <h2 id="pass-<?= $id ?>-t" class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug mt-1"><?= ex($e['title']) ?></h2>
      <p class="text-sm text-gray-500"><?= $e['event_date'] ? date('D, d M Y', strtotime($e['event_date'])) . ($e['event_time'] ? ' · ' . date('g:i A', strtotime($e['event_time'])) : '') : '' ?></p>
      <div class="mt-5 mx-auto w-64 max-w-full aspect-square rounded-2xl bg-white p-3 shadow-inner ring-1 ring-gray-200">
        <img src="<?= qrDataUri(checkinUrl($id, $myMemberId), 8, 1) ?>" alt="Check-in QR code for <?= ex($meName) ?>" class="w-full h-full [image-rendering:pixelated]"/>
      </div>
      <div class="mt-4 flex items-center justify-center gap-3">
        <?= memberAvatarHtml($meRow['avatar_path'] ?? null, (string)$meName, 'w-10 h-10 text-sm') ?>
        <div class="text-start"><p class="font-semibold text-gray-900 dark:text-white leading-tight"><?= ex($meName) ?></p><?php if (!empty($meRow['member_code'])): ?><p class="font-mono text-xs text-gray-500 notranslate" translate="no">#<?= ex($meRow['member_code']) ?></p><?php endif; ?></div>
      </div>
      <p class="text-xs text-gray-500 mt-4 leading-relaxed">Show this to the RARL team at the venue. Turn your screen brightness up. Your certificate is issued as soon as you're scanned.</p>
    </div>
  </div>
</dialog>
<?php endforeach; endif; ?>

<style>
  .ev-chip{display:inline-flex;align-items:center;gap:.4rem;flex-shrink:0;padding:.45rem .9rem;border-radius:999px;border:1px solid #d1d5db;background:#fff;color:#374151;font-size:.875rem;font-weight:600}
  .dark .ev-chip{background:transparent;border-color:#374151;color:#d1d5db}
  .ev-chip[aria-pressed=true]{background:#CC0703;border-color:#CC0703;color:#fff}
  .ev-btn{display:inline-flex;align-items:center;gap:.5rem;min-height:2.75rem;padding:0 1.15rem;border-radius:999px;font-size:.9rem;font-weight:600;border:1px solid #d1d5db;color:#111827;background:#fff}
  .dark .ev-btn{background:transparent;border-color:#4b5563;color:#f3f4f6}
  .ev-btn:hover{border-color:#CC0703;color:#CC0703}
  .ev-btn-primary,.dark .ev-btn-primary{background:#CC0703;border-color:#CC0703;color:#fff}
  .ev-btn-primary:hover{background:#A80502;color:#fff}
  .ev-icon{width:2.75rem;height:2.75rem;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #e5e7eb;color:#4b5563}
  .dark .ev-icon{border-color:#374151;color:#d1d5db}
  .ev-icon:hover{border-color:#CC0703;color:#CC0703}
</style>
<script>
  // Type filter
  document.querySelectorAll('[data-type][aria-pressed]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var t = chip.dataset.type;
      document.querySelectorAll('[data-type][aria-pressed]').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      document.querySelectorAll('#event-grid > [data-type]').forEach(function (card) { card.hidden = !!t && card.dataset.type !== t; });
    });
  });
  // Share: native sheet on phones, copy link elsewhere
  document.querySelectorAll('[data-share-url]').forEach(function (b) {
    b.addEventListener('click', async function () {
      var url = b.dataset.shareUrl;
      try { if (navigator.share) await navigator.share({title: b.dataset.shareTitle, url: url}); else { await navigator.clipboard.writeText(url); b.innerHTML = '<i class="fa-solid fa-check text-green-600"></i>'; setTimeout(function () { b.innerHTML = '<i class="fa-solid fa-share-nodes"></i>'; }, 1600); } } catch (e) {}
    });
  });
  <?php if ($shared): ?>document.addEventListener('DOMContentLoaded', function () { if (document.getElementById('event-<?= (int)$shared['id'] ?>')) rarlModal.open('event-<?= (int)$shared['id'] ?>'); });<?php endif; ?>
  // A link to an event on the other tab (e.g. a past event from the feed) switches tabs.
  (function () {
    var m = location.hash.match(/^#event-(\d+)$/), other = <?= json_encode($otherTabIds) ?>;
    if (m && !document.getElementById('event-' + m[1]) && other.indexOf(+m[1]) > -1) location.replace('events.php?tab=<?= $tab === 'upcoming' ? 'past' : 'upcoming' ?>#event-' + m[1]);
  })();
</script>
<?= publicFooter() ?>
</body></html>
