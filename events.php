<?php
/**
 * RARL — Events & Webinars
 * Card grid; every card opens a detail modal (description, schedule, place,
 * speaker, capacity, RSVP, add-to-calendar, share). ?event=ID links (plus
 * &k=KEY for link-only events) open the modal directly, so Home, the feed,
 * emails and shared links can deep-link to an event; #event-ID works for
 * events already on the page.
 * Times are shown in the event's own time zone, with the viewer's local time
 * added by the browser when it differs.
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
$key = isset($_GET['k']) && is_string($_GET['k']) ? substr($_GET['k'], 0, 64) : null;
$fetchEvent = function (int $id) use ($pdo): ?array {
    $s = $pdo->prepare('SELECT * FROM events WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
};
$isoUtc = fn(?DateTimeImmutable $d) => $d ? $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : '';

// ── Add to calendar (.ics) — times in UTC so every calendar app places them right ──
if (!empty($_GET['ics'])) {
    $e = $fetchEvent((int)$_GET['ics']);
    if (!$e || !$e['event_date'] || !eventCanView($e, $isActiveMember, $myMemberId, $key)) { http_response_code(404); exit('Event not found'); }
    $esc = function ($v) {
        $v = html_entity_decode(strip_tags((string)$v), ENT_QUOTES, 'UTF-8');
        $v = str_replace(['\\', ';', ','], ['\\\\', '\;', '\,'], $v);
        return str_replace(["\r\n", "\n"], '\n', $v);
    };
    $t = eventTimes($e);
    if ($t['all_day']) {
        $dates = ['DTSTART;VALUE=DATE:' . $t['start']->format('Ymd'), 'DTEND;VALUE=DATE:' . $t['end']->modify('+1 day')->format('Ymd')];
    } else {
        $u = new DateTimeZone('UTC');
        $dates = ['DTSTART:' . $t['start']->setTimezone($u)->format('Ymd\THis\Z'), 'DTEND:' . $t['end']->setTimezone($u)->format('Ymd\THis\Z')];
    }
    $lines = array_merge([
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RARL//Events//EN', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
        'UID:rarl-event-' . (int)$e['id'] . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'rarl-lab.com'),
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    ], $dates, ['SUMMARY:' . $esc($e['title'])]);
    if ($e['description']) $lines[] = 'DESCRIPTION:' . $esc($e['description']);
    if ($e['location'] ?: $e['online_url']) $lines[] = 'LOCATION:' . $esc($e['location'] ?: 'Online');
    $lines[] = 'URL:' . eventUrl($e);
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
    $event   = $eventId ? $fetchEvent($eventId) : null;
    $back    = fn() => ($_POST['return'] ?? '') === 'dashboard' ? 'dashboard.php' : ($event ? eventUrl($event, false) : 'events.php');

    if ($action === 'rsvp' && $event) {
        if (!eventCanView($event, true, $myMemberId, isset($_POST['k']) ? (string)$_POST['k'] : null)) {
            flash('error', 'This event isn\'t available. The link may have been reset or the event removed.');
            redirect('events.php');
        }
        if (eventPhase($event) === 'past') {
            flash('error', 'This event has already finished, so registration is closed.');
            redirect($back());
        }
        // Lock the event row so two people can't take the last place at once.
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM events WHERE id = ? FOR UPDATE')->execute([$eventId]);
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
        $pdo->commit();
        redirect($back());
    }

    if ($action === 'cancel_rsvp' && $event) {
        $pdo->prepare("UPDATE event_registrations SET status='cancelled' WHERE event_id=? AND member_id=? AND status='registered'")->execute([$eventId, $myMemberId]);
        flash('success', 'Registration cancelled.');
        // A link-only event stays reachable for this session (the key was remembered when it was opened).
        redirect(eventCanView($event, true, $myMemberId) ? eventUrl($event, false) : 'events.php');
    }
}

// ── The event a link points at ──
$shared = null; $sharedState = null; // null | 'locked' (members only, not signed in as an active member) | 'gone'
if (!empty($_GET['event'])) {
    $row = $fetchEvent((int)$_GET['event']);
    if ($row && eventCanView($row, $isActiveMember, $myMemberId, $key)) $shared = $row;
    elseif ($row && $row['is_active'] && $row['visibility'] === 'members_only') $sharedState = 'locked';
    else $sharedState = 'gone';
}
if ($sharedState === 'gone') flash('error', 'That event link isn\'t valid any more. The event may have been removed or hidden, or the organisers reset its link. Ask them for a new one, or browse the events below.');

// ── Event lists ──
$tab = ($_GET['tab'] ?? ($shared && eventIsPast($shared) ? 'past' : 'upcoming')) === 'past' ? 'past' : 'upcoming';
$listable = eventListableSql($isActiveMember, $myMemberId);
if ($tab === 'upcoming') {
    $events = $pdo->query("SELECT * FROM events WHERE is_active = 1 AND {$listable} AND " . eventNotOverSql() . "
        ORDER BY event_date IS NULL, COALESCE(starts_at_utc, TIMESTAMP(event_date, COALESCE(event_time, '00:00:00'))) ASC")->fetchAll();
} else {
    $events = $pdo->query("SELECT * FROM events WHERE is_active = 1 AND {$listable} AND " . eventOverSql() . "
        ORDER BY COALESCE(starts_at_utc, TIMESTAMP(event_date, COALESCE(event_time, '00:00:00'))) DESC LIMIT 30")->fetchAll();
}
// Every visible event id, so a link to a past event from the upcoming tab (or vice versa) can switch tabs.
$otherTabIds = array_map('intval', $pdo->query("SELECT id FROM events WHERE is_active = 1 AND {$listable} AND " . ($tab === 'upcoming' ? eventOverSql() : eventNotOverSql()))->fetchAll(PDO::FETCH_COLUMN));

// Every event that gets a detail modal: the list, plus the linked event if it isn't in it (e.g. link-only).
$modalEvents = $events;
if ($shared && !in_array((int)$shared['id'], array_map('intval', array_column($events, 'id')), true)) $modalEvents[] = $shared;

$myRegistrations = []; $regCounts = [];
if ($modalEvents) {
    $ids = array_column($modalEvents, 'id');
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
if ($modalEvents && videosReady()) {
    $ids = implode(',', array_map('intval', array_column($modalEvents, 'id')));
    foreach ($pdo->query("SELECT id, event_id, youtube_id, access_level FROM videos WHERE is_published = 1 AND event_id IN ($ids) ORDER BY created_at") as $v) $eventVideos[(int)$v['event_id']] ??= $v;
}
$sharedCover = $shared ? $coverUrl($shared) : null;
echo htmlHead($shared ? html_entity_decode($shared['title'], ENT_QUOTES) : ($sharedState === 'locked' ? 'Members-only event' : 'Events & Webinars'), false, [
    'description' => $shared
        ? trim(($shared['event_date'] ? html_entity_decode(eventWhenText($shared, true), ENT_QUOTES) . ' · ' : '') . ($shared['online_url'] && !$shared['location'] ? 'Online · ' : '') . mb_strimwidth(html_entity_decode(strip_tags((string)$shared['description']), ENT_QUOTES), 0, 180, '…'))
        : ($sharedState === 'locked' ? 'A RARL event for members. Sign in or join free to see the details and register.'
        : 'Free webinars, workshops and conferences on robotics, AI and automation from the Robotics & Automation Research Lab (RARL). Register in one click.'),
    'image' => $sharedCover && str_starts_with($sharedCover, 'http') ? $sharedCover : ($sharedCover ? rtrim(SITE_URL, '/') . '/' . ltrim(str_replace(UPLOADS_URL, 'uploads', $sharedCover), '/') : null),
    'type' => $shared ? 'event' : 'website',
    // Only public events belong in search engines.
    'noindex' => ($shared && $shared['visibility'] !== 'public') || $sharedState !== null,
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
      $phase = eventPhase($e); $times = eventTimes($e);
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
        <?php if ($phase === 'live'): ?><span class="absolute top-3 end-3 bg-rarl-red text-white text-xs font-semibold rounded-full px-2.5 py-1 shadow"><i class="fa-solid fa-circle text-[7px] align-middle"></i> Live now</span>
        <?php elseif ($mine): ?><span class="absolute top-3 end-3 bg-green-600 text-white text-xs font-semibold rounded-full px-2.5 py-1 shadow"><i class="fa-solid fa-check"></i> Going</span>
        <?php elseif ($e['visibility'] === 'members_only'): ?><span class="absolute top-3 end-3 bg-gray-900/80 text-white text-xs font-semibold rounded-full px-2.5 py-1"><i class="fa-solid fa-lock"></i> Members</span>
        <?php elseif ($e['visibility'] === 'unlisted'): ?><span class="absolute top-3 end-3 bg-gray-900/80 text-white text-xs font-semibold rounded-full px-2.5 py-1"><i class="fa-solid fa-link"></i> Private link</span><?php endif; ?>
      </span>
      <span class="p-4 sm:p-5 flex-1 flex flex-col">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide"><i class="fa-solid <?= $icon ?>"></i> <?= ucfirst($e['type']) ?><?= $e['online_url'] ? ' · Online' : '' ?></span>
        <span class="block font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug mt-1 group-hover:text-rarl-red"><?= ex($e['title']) ?></span>
        <span class="block text-sm text-gray-600 dark:text-gray-400 mt-1.5"><?= ex(eventWhenText($e)) ?></span>
        <?php if ($times['start'] && !$times['all_day']): ?><span class="ev-local block text-xs text-gray-500" hidden data-start="<?= $isoUtc($times['start']) ?>" data-end="<?= $times['explicit_end'] ? $isoUtc($times['end']) : '' ?>" data-tz="<?= ex($times['tz']->getName()) ?>"></span><?php endif; ?>
        <?php if ($e['location']): ?><span class="block text-sm text-gray-500 truncate"><i class="fa-solid fa-location-dot"></i> <?= ex($e['location']) ?></span><?php endif; ?>
        <span class="mt-auto pt-4 flex items-center justify-between gap-3 text-sm">
          <?php if ($phase !== 'past' && $e['capacity'] !== null): ?>
          <span class="text-gray-500"><?= $isFull ? '<span class="font-semibold text-red-600">Fully booked</span>' : ((int)$e['capacity'] - $count) . ' places left' ?></span>
          <?php elseif ($phase === 'past' && $e['recording_url']): ?><span class="text-gray-500"><i class="fa-solid fa-circle-play"></i> Recording</span>
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

<?php if ($sharedState === 'locked'): $lockId = (int)$_GET['event']; ?>
<dialog class="rarl-modal" id="event-locked" aria-labelledby="event-locked-t">
  <div class="rarl-modal-panel" style="--modal-w:26rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="p-6 pt-8 text-center">
      <div class="w-14 h-14 mx-auto rounded-2xl bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 flex items-center justify-center text-2xl"><i class="fa-solid fa-lock"></i></div>
      <h2 id="event-locked-t" class="font-heading font-bold text-xl text-gray-900 dark:text-white mt-4">This event is for RARL members</h2>
      <?php if ($isMember): ?>
      <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">You'll be able to open it once your membership is active.</p>
      <div class="mt-5 flex justify-center"><a href="membership.php" class="ev-btn ev-btn-primary">Check my membership</a></div>
      <?php else: ?>
      <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">Sign in to see the details and register. Membership is free.</p>
      <div class="mt-5 flex flex-wrap justify-center gap-2">
        <a href="login.php?next=<?= rawurlencode('events.php?event=' . $lockId) ?>" class="ev-btn ev-btn-primary">Sign in</a>
        <a href="register.php" class="ev-btn">Join free</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</dialog>
<?php endif; ?>

<?php foreach ($modalEvents as $e):
  $id = (int)$e['id']; $count = (int)($regCounts[$id] ?? 0);
  $isFull = $e['capacity'] !== null && $count >= (int)$e['capacity'];
  $myState = $myRegistrations[$id] ?? null; $mine = in_array($myState, ['registered', 'attended'], true);
  $locked = $e['visibility'] === 'members_only' && !$isActiveMember;
  $phase = eventPhase($e); $isPastEv = $phase === 'past';
  $times = eventTimes($e);
  $cover = $coverUrl($e); $icon = $typeIcons[$e['type']] ?? 'fa-calendar-days';
  $link = eventUrl($e); $linkRel = eventUrl($e, false);
  $kField = $e['visibility'] === 'unlisted' ? '<input type="hidden" name="k" value="' . ex($e['share_token']) . '">' : '';
  $gcal = null;
  if ($times['start']) {
      $u = new DateTimeZone('UTC');
      $dates = $times['all_day'] ? $times['start']->format('Ymd') . '/' . $times['end']->modify('+1 day')->format('Ymd')
                                 : $times['start']->setTimezone($u)->format('Ymd\THis\Z') . '/' . $times['end']->setTimezone($u)->format('Ymd\THis\Z');
      $gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode(html_entity_decode($e['title'], ENT_QUOTES))
            . '&dates=' . $dates . '&ctz=' . rawurlencode($times['tz']->getName())
            . '&details=' . rawurlencode($link) . '&location=' . rawurlencode(html_entity_decode((string)($e['location'] ?: ($e['online_url'] ? 'Online' : '')), ENT_QUOTES));
      $outlook = 'https://outlook.live.com/calendar/0/deeplink/compose?path=%2Fcalendar%2Faction%2Fcompose&rru=addevent'
            . '&subject=' . rawurlencode(html_entity_decode($e['title'], ENT_QUOTES))
            . ($times['all_day'] ? '&allday=true&startdt=' . $times['start']->format('Y-m-d') . '&enddt=' . $times['end']->modify('+1 day')->format('Y-m-d')
                                 : '&allday=false&startdt=' . rawurlencode($isoUtc($times['start'])) . '&enddt=' . rawurlencode($isoUtc($times['end'])))
            . '&body=' . rawurlencode($link) . '&location=' . rawurlencode(html_entity_decode((string)($e['location'] ?: ($e['online_url'] ? 'Online' : '')), ENT_QUOTES));
  } ?>
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
        <?php if ($phase === 'live'): ?><span class="px-2.5 py-1 rounded-full bg-rarl-red text-white"><i class="fa-solid fa-circle text-[7px] align-middle"></i> Happening now</span><?php endif; ?>
        <?php if ($e['visibility'] === 'members_only'): ?><span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="fa-solid fa-lock"></i> Members only</span><?php endif; ?>
        <?php if ($e['visibility'] === 'unlisted'): ?><span class="px-2.5 py-1 rounded-full bg-violet-50 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300" title="Not listed publicly — only people with the link can see it"><i class="fa-solid fa-link"></i> Private link</span><?php endif; ?>
        <?php if ($e['online_url']): ?><span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><i class="fa-solid fa-wifi"></i> Online</span><?php endif; ?>
        <?php if ($mine): ?><span class="px-2.5 py-1 rounded-full bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300"><i class="fa-solid fa-circle-check"></i> <?= $myState === 'attended' ? 'Attended' : "You're going" ?></span><?php endif; ?>
      </div>
      <h2 id="event-<?= $id ?>-title" class="font-heading font-bold text-2xl text-gray-900 dark:text-white leading-tight mt-3"><?= ex($e['title']) ?></h2>

      <dl class="grid sm:grid-cols-2 gap-3 mt-5">
        <div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-regular fa-calendar text-rarl-red mt-1 w-4 text-center"></i><div><dt class="text-xs text-gray-500">When</dt>
          <dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= ex(eventWhenText($e, true)) ?>
            <?php if ($times['start'] && !$times['all_day']): ?>
            <span class="block font-normal text-xs text-gray-500 mt-0.5">Time zone: <?= ex(str_replace('_', ' ', $times['tz']->getName())) ?></span>
            <span class="ev-local block font-normal text-gray-600 dark:text-gray-400" hidden data-start="<?= $isoUtc($times['start']) ?>" data-end="<?= $times['explicit_end'] ? $isoUtc($times['end']) : '' ?>" data-tz="<?= ex($times['tz']->getName()) ?>"></span>
            <?php endif; ?>
          </dd></div></div>
        <div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-solid <?= $e['location'] ? 'fa-location-dot' : 'fa-wifi' ?> text-rarl-red mt-1 w-4 text-center"></i><div><dt class="text-xs text-gray-500">Where</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= $e['location'] ? ex($e['location']) : ($e['online_url'] ? 'Online — link shared with registered members' : 'To be announced') ?></dd></div></div>
        <?php if ($e['speaker_name']): ?><div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-solid fa-microphone text-rarl-red mt-1 w-4 text-center"></i><div><dt class="text-xs text-gray-500">Speaker</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= ex($e['speaker_name']) ?></dd></div></div><?php endif; ?>
        <?php if ($e['capacity'] !== null && !$isPastEv): ?>
        <div class="flex gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/60"><i class="fa-solid fa-users text-rarl-red mt-1 w-4 text-center"></i><div class="flex-1"><dt class="text-xs text-gray-500">Places</dt><dd class="text-sm font-semibold text-gray-900 dark:text-white"><?= $isFull ? 'Fully booked' : ((int)$e['capacity'] - $count) . ' of ' . (int)$e['capacity'] . ' left' ?>
          <span class="block h-1.5 mt-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden" aria-hidden="true"><span class="block h-full bg-rarl-red rounded-full" style="width:<?= $e['capacity'] > 0 ? min(100, round($count / $e['capacity'] * 100)) : 0 ?>%"></span></span></dd></div></div>
        <?php endif; ?>
      </dl>

      <?php if ($e['description']): ?>
      <div class="mt-5"><h3 class="text-sm font-bold text-gray-900 dark:text-white">About this event</h3>
        <p class="mt-1.5 text-[15px] leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-line"><?= ex($e['description']) ?></p></div>
      <?php endif; ?>

      <div class="ev-actions sticky bottom-0 -mx-5 sm:-mx-7 -mb-5 sm:-mb-7 mt-6 px-5 sm:px-7 py-4 bg-white/90 dark:bg-gray-900/90 backdrop-blur-xl border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-2">
        <?php if ($isPastEv): ?>
          <?php if (!empty($eventVideos[$id]) && ($eventVideos[$id]['access_level'] === 'public' || $isActiveMember)): ?><a href="videos.php?v=<?= (int)$eventVideos[$id]['id'] ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-play"></i> Watch recording</a>
          <?php elseif ($e['recording_url'] && $isActiveMember): ?><a href="<?= ex($e['recording_url']) ?>" target="_blank" rel="noopener" class="ev-btn ev-btn-primary"><i class="fa-solid fa-play"></i> Watch recording</a>
          <?php elseif ($e['recording_url']): ?><a href="<?= $isMember ? 'membership.php' : 'register.php' ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-lock"></i> <?= $isMember ? 'Recording for active members' : 'Join free to watch' ?></a>
          <?php else: ?><span class="text-sm text-gray-500">This event has finished<?= $e['recording_url'] ? '' : ' — no recording' ?>.</span><?php endif; ?>
        <?php elseif ($locked && !$isMember): ?><a href="register.php" class="ev-btn ev-btn-primary"><i class="fa-solid fa-lock"></i> Join free to register</a><a href="login.php?next=<?= rawurlencode($linkRel) ?>" class="ev-btn">Sign in</a>
        <?php elseif (!$isMember): ?><a href="login.php?next=<?= rawurlencode($linkRel) ?>" class="ev-btn ev-btn-primary">Sign in to register</a><a href="register.php" class="ev-btn">Join free</a>
        <?php elseif (!$isActiveMember): ?><span class="text-sm text-amber-800 dark:text-amber-300"><i class="fa-solid fa-hourglass-half"></i> Registration opens once your membership is approved.</span>
        <?php elseif ($mine): ?>
          <?php if ($myState === 'registered' && !$e['online_url']): ?><button type="button" data-modal="pass-<?= $id ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-qrcode"></i> Check-in pass</button><?php endif; ?>
          <?php if ($e['online_url']): ?><a href="<?= ex($e['online_url']) ?>" target="_blank" rel="noopener" class="ev-btn ev-btn-primary"><i class="fa-solid fa-video"></i> Join link</a><?php endif; ?>
          <?php if ($myState === 'registered'): ?><form method="POST" data-confirm="Cancel your registration for this event?"><?= csrfField() ?><input type="hidden" name="action" value="cancel_rsvp"><input type="hidden" name="event_id" value="<?= $id ?>"><button class="ev-btn">Cancel registration</button></form><?php endif; ?>
        <?php elseif ($isFull): ?><span class="ev-btn opacity-60 cursor-not-allowed">Fully booked</span>
        <?php else: ?>
          <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="event_id" value="<?= $id ?>"><?= $kField ?><button class="ev-btn ev-btn-primary"><i class="fa-solid fa-check"></i> Register — free</button></form>
        <?php endif; ?>
        <span class="flex gap-2 ms-auto">
          <?php if (!$isPastEv && $gcal): ?>
          <details class="ev-menu">
            <summary class="ev-btn ev-btn-quiet" aria-label="Add to calendar"><i class="fa-regular fa-calendar-plus"></i><span class="hidden sm:inline">Calendar</span></summary>
            <div class="ev-menu-pop" role="menu">
              <a role="menuitem" href="<?= ex(eventIcsUrl($e)) ?>"><i class="fa-brands fa-apple"></i> Apple Calendar</a>
              <a role="menuitem" href="<?= ex($gcal) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-google"></i> Google Calendar</a>
              <a role="menuitem" href="<?= ex($outlook) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-microsoft"></i> Outlook.com</a>
              <a role="menuitem" href="<?= ex(eventIcsUrl($e)) ?>"><i class="fa-solid fa-download"></i> Other (.ics file)</a>
            </div>
          </details>
          <?php endif; ?>
          <?php if ($e['visibility'] !== 'unlisted'): ?>
          <a href="share.php?type=event&amp;id=<?= $id ?>" target="_blank" rel="noopener" class="ev-icon" title="Share on LinkedIn<?= $isActiveMember ? ' (counts towards your membership)' : '' ?>" aria-label="Share on LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
          <?php endif; ?>
          <button type="button" class="ev-btn ev-btn-quiet" data-share-url="<?= ex($link) ?>" data-share-title="<?= ex($e['title']) ?>" title="<?= $e['visibility'] === 'unlisted' ? 'Private link: only share it with people who should see this event' : 'Share' ?>" aria-label="Share event"><i class="fa-solid fa-arrow-up-from-bracket"></i><span class="hidden sm:inline" data-share-label>Share</span></button>
        </span>
      </div>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<?php // schema.org Event data for public events only.
$ld = [];
foreach ($modalEvents as $e) {
    if ($e['visibility'] !== 'public' || !$e['event_date']) continue;
    $t = eventTimes($e); $dec = fn($v) => html_entity_decode(strip_tags((string)$v), ENT_QUOTES, 'UTF-8');
    $c = $coverUrl($e); $img = $c ? (str_starts_with($c, 'http') ? $c : rtrim(SITE_URL, '/') . '/' . ltrim(str_replace(UPLOADS_URL, 'uploads', $c), '/')) : null;
    $place = $e['location'] ? ['@type' => 'Place', 'name' => $dec($e['location']), 'address' => $dec($e['location'])] : null;
    $online = $e['online_url'] ? ['@type' => 'VirtualLocation', 'url' => eventUrl($e)] : null;
    $ld[] = array_filter([
        '@type' => 'Event', 'name' => $dec($e['title']), 'url' => eventUrl($e),
        'startDate' => $t['all_day'] ? $t['start']->format('Y-m-d') : $t['start']->format(DATE_ATOM),
        'endDate' => $t['all_day'] ? $t['end']->format('Y-m-d') : $t['end']->format(DATE_ATOM),
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/' . ($place && $online ? 'Mixed' : ($place ? 'Offline' : 'Online')) . 'EventAttendanceMode',
        'location' => $place && $online ? [$place, $online] : ($place ?: ($online ?: ['@type' => 'VirtualLocation', 'url' => eventUrl($e)])),
        'description' => $e['description'] ? mb_strimwidth($dec($e['description']), 0, 300, '…') : null,
        'image' => $img, 'isAccessibleForFree' => true,
        'performer' => $e['speaker_name'] ? ['@type' => 'Person', 'name' => $dec($e['speaker_name'])] : null,
        'organizer' => ['@type' => 'Organization', 'name' => 'Robotics & Automation Research Lab (RARL)', 'url' => MAIN_SITE_URL],
    ], fn($v) => $v !== null);
}
if ($ld) echo '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $ld], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
?>

<?php // Check-in passes: one per event this member is registered for.
if ($isActiveMember):
  $meRow = $pdo->prepare('SELECT full_name, lab_name, type, member_code, avatar_path FROM members WHERE id = ?'); $meRow->execute([$myMemberId]); $meRow = $meRow->fetch();
  $meName = $meRow ? ($meRow['type'] === 'lab' ? $meRow['lab_name'] : $meRow['full_name']) : '';
  foreach ($modalEvents as $e): if (($myRegistrations[(int)$e['id']] ?? null) !== 'registered' || $e['online_url']) continue; $id = (int)$e['id']; ?>
<dialog class="rarl-modal" id="pass-<?= $id ?>" aria-labelledby="pass-<?= $id ?>-t">
  <div class="rarl-modal-panel" style="--modal-w:24rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="p-6 pt-8 text-center">
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">Check-in pass</p>
      <h2 id="pass-<?= $id ?>-t" class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug mt-1"><?= ex($e['title']) ?></h2>
      <p class="text-sm text-gray-500"><?= $e['event_date'] ? ex(eventWhenText($e)) : '' ?></p>
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
  .ev-btn-quiet{padding:0 .95rem;border-color:#e5e7eb;color:#374151}
  .dark .ev-btn-quiet{border-color:#374151;color:#e5e7eb}
  @media (max-width:639px){.ev-btn-quiet{width:2.75rem;padding:0;justify-content:center}}
  .ev-menu{position:relative}
  .ev-menu>summary{list-style:none;cursor:pointer}
  .ev-menu>summary::-webkit-details-marker{display:none}
  .ev-menu-pop{position:absolute;right:0;bottom:calc(100% + .5rem);min-width:14rem;padding:.35rem;border-radius:14px;background:rgba(255,255,255,.96);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);box-shadow:0 12px 40px rgba(0,0,0,.18),0 0 0 .5px rgba(0,0,0,.08);z-index:5;animation:evPop .16s cubic-bezier(.2,.9,.3,1.2)}
  .dark .ev-menu-pop{background:rgba(31,41,55,.96);box-shadow:0 12px 40px rgba(0,0,0,.5),0 0 0 .5px rgba(255,255,255,.08)}
  .ev-menu-pop a{display:flex;align-items:center;gap:.7rem;min-height:44px;padding:0 .8rem;border-radius:9px;font-size:.9375rem;color:#111827}
  .dark .ev-menu-pop a{color:#f3f4f6}
  .ev-menu-pop a i{width:1.1rem;text-align:center;color:#6b7280}
  .ev-menu-pop a:hover,.ev-menu-pop a:focus-visible{background:rgba(0,0,0,.05);outline:none}
  .dark .ev-menu-pop a:hover{background:rgba(255,255,255,.08)}
  @keyframes evPop{from{opacity:0;transform:translateY(4px) scale(.97)}to{opacity:1;transform:none}}
  @media (max-width:639px){.ev-actions>form:has(.ev-btn-primary),.ev-actions>.ev-btn-primary:first-child{flex:1 1 auto}.ev-actions>form .ev-btn-primary,.ev-actions>.ev-btn-primary:first-child{width:100%;justify-content:center}}
  @media (prefers-reduced-motion:reduce){.ev-menu-pop{animation:none}}
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
  // Each viewer also sees the time in their own zone when it differs from the event's.
  (function () {
    var mine; try { mine = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { return; }
    if (!mine) return;
    function off(zone, d) {
      try {
        var p = new Intl.DateTimeFormat('en-US', {timeZone: zone, hourCycle: 'h23', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric'}).formatToParts(d), g = {};
        p.forEach(function (x) { g[x.type] = +x.value; });
        return Date.UTC(g.year, g.month - 1, g.day, g.hour % 24, g.minute) - Math.floor(d.getTime() / 60000) * 60000;
      } catch (e) { return null; }
    }
    var dayF = new Intl.DateTimeFormat(undefined, {weekday: 'short', day: 'numeric', month: 'short'});
    var timeF = new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'});
    var tzF = new Intl.DateTimeFormat(undefined, {timeZoneName: 'short'});
    document.querySelectorAll('.ev-local[data-start]').forEach(function (el) {
      var s = new Date(el.dataset.start), e = el.dataset.end ? new Date(el.dataset.end) : null;
      if (isNaN(s) || el.dataset.tz === mine || off(el.dataset.tz, s) === off(mine, s)) return;
      var zone = (tzF.formatToParts(s).find(function (x) { return x.type === 'timeZoneName'; }) || {}).value || '';
      var txt = dayF.format(s) + ', ' + timeF.format(s);
      if (e && !isNaN(e)) txt += ' – ' + (dayF.format(e) !== dayF.format(s) ? dayF.format(e) + ', ' : '') + timeF.format(e);
      el.innerHTML = '<i class="fa-solid fa-earth-americas opacity-60"></i> ';
      el.appendChild(document.createTextNode('Your time: ' + txt + (zone ? ' ' + zone : '')));
      el.hidden = false;
    });
  })();
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.ev-menu[open]').forEach(function (d) { if (!d.contains(e.target) || e.target.closest('.ev-menu-pop a')) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var d = document.querySelector('details.ev-menu[open]'); if (d) { e.stopPropagation(); e.preventDefault(); d.removeAttribute('open'); d.querySelector('summary').focus(); }
  }, true);
  // Share: native sheet on phones, copy link elsewhere
  document.querySelectorAll('[data-share-url]').forEach(function (b) {
    b.addEventListener('click', async function () {
      var url = b.dataset.shareUrl;
      var old = b.innerHTML;
      try { if (navigator.share) await navigator.share({title: b.dataset.shareTitle, url: url}); else { await navigator.clipboard.writeText(url); b.innerHTML = '<i class="fa-solid fa-check text-green-600"></i><span class="hidden sm:inline">Link copied</span>'; setTimeout(function () { b.innerHTML = old; }, 1800); } } catch (e) {}
    });
  });
  <?php if ($shared): ?>document.addEventListener('DOMContentLoaded', function () { if (document.getElementById('event-<?= (int)$shared['id'] ?>')) rarlModal.open('event-<?= (int)$shared['id'] ?>'); });<?php endif; ?>
  <?php if ($sharedState === 'locked'): ?>document.addEventListener('DOMContentLoaded', function () { rarlModal.open('event-locked'); });<?php endif; ?>
  // A link to an event on the other tab (e.g. a past event from the feed) switches tabs.
  (function () {
    var m = location.hash.match(/^#(event|pass)-(\d+)$/), other = <?= json_encode($otherTabIds) ?>;
    if (m && !document.getElementById('event-' + m[2]) && other.indexOf(+m[2]) > -1) location.replace('events.php?tab=<?= $tab === 'upcoming' ? 'past' : 'upcoming' ?>#' + m[1] + '-' + m[2]);
  })();
</script>
<?= publicFooter() ?>
</body></html>
