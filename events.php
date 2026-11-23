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

// ── Guest registration (public and link-only events, no sign-in needed) ──
$guestFind = function (string $token) use ($pdo): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $s = $pdo->prepare('SELECT * FROM event_guest_registrations WHERE token = ?'); $s->execute([$token]);
    return $s->fetch() ?: null;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['guest_register', 'guest_cancel', 'guest_rejoin'], true)) {
    $action = $_POST['action'];
    if (!csrfCheck()) { flash('error', 'Your session timed out. Please try again.'); redirect('events.php'); }

    if ($action === 'guest_register') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $event = $eventId ? $fetchEvent($eventId) : null;
        $k = isset($_POST['k']) ? substr((string)$_POST['k'], 0, 64) : null;
        $editing = !empty($_POST['g_token']) ? $guestFind((string)$_POST['g_token']) : null;
        if ($editing && (int)$editing['event_id'] !== $eventId) $editing = null;
        $v = fn($name, $max = 255) => mb_substr(trim((string)($_POST[$name] ?? '')), 0, $max);
        $vals = [
            'full_name' => $v('g_name'), 'email' => mb_strtolower($v('g_email')), 'country' => $v('g_country', 100), 'institution' => $v('g_institution'),
            'position' => $v('g_position', 20), 'position_other' => $v('g_position_other', 120), 'says_member' => $v('g_member', 1),
            'member_number' => $v('g_member_number', 40), 'research_interest' => $v('g_interest', 1000),
        ];
        $back = function (array $errors, string $msg) use ($event, $eventId, $k, $vals, $editing) {
            $_SESSION['guest_form'] = ['event_id' => $eventId, 'values' => $vals, 'errors' => $errors, 'msg' => $msg, 'token' => $editing['token'] ?? null];
            redirect($editing ? guestManageUrl($editing, false) : ($event ? eventUrl($event, false) : 'events.php'));
        };
        // Access: only public and link-only events take guests; link-only needs the link's key (or an existing registration).
        $open = $event && in_array($event['visibility'], ['public', 'unlisted'], true) && ($editing || eventCanView($event, $isActiveMember, $myMemberId, $k));
        if (!$open) { flash('error', 'Registration isn\'t open for this event. The link may have been reset, or the event is for members only.'); redirect('events.php'); }
        if (trim((string)($_POST['website'] ?? '')) !== '') redirect(eventUrl($event, false)); // spam trap
        if (eventPhase($event) === 'past') { flash('error', 'This event has already finished, so registration is closed.'); redirect(eventUrl($event, false)); }

        $errors = [];
        if (mb_strlen($vals['full_name']) < 2) $errors['g_name'] = 'Enter your full name.';
        if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) $errors['g_email'] = 'Enter a valid email address — your confirmation goes there.';
        if (!isset(COUNTRIES[$vals['country']])) $errors['g_country'] = 'Choose your country.';
        if ($vals['institution'] === '') $errors['g_institution'] = 'Enter your institution or organisation.';
        if (!isset(GUEST_POSITIONS[$vals['position']])) $errors['g_position'] = 'Choose your position.';
        if (!in_array($vals['says_member'], ['0', '1'], true)) $errors['g_member'] = 'Tell us whether you\'re a RARL member.';
        if ($vals['says_member'] === '1' && normalizeMemberCode($vals['member_number']) === '') $errors['g_member_number'] = 'Enter your RARL member number (it\'s on your ID card).';
        if (mb_strlen($vals['research_interest']) < 2) $errors['g_interest'] = 'Tell us your research interest, in a few words.';
        if ($errors) $back($errors, 'Please check the highlighted fields.');

        // At most 12 new registrations an hour from one connection.
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . appSecret());
        if (!$editing) {
            $rl = $pdo->prepare('SELECT COUNT(*) FROM event_guest_registrations WHERE ip_hash = ? AND registered_at > NOW() - INTERVAL 1 HOUR');
            $rl->execute([$ipHash]);
            if ((int)$rl->fetchColumn() >= 12) $back([], 'Too many registrations from this connection. Please try again in an hour.');
        }

        // Link to a member account when this email belongs to one; check the number they typed against it.
        $acct = memberByEmail($pdo, $vals['email']);
        if ($acct) {
            $m = $pdo->prepare("SELECT 1 FROM event_registrations WHERE event_id = ? AND member_id = ? AND status <> 'cancelled'");
            $m->execute([$eventId, $acct['id']]);
            if ($m->fetchColumn()) { flash('success', 'You\'re already registered for this event with your RARL account. Sign in to see your registration and check-in pass.'); redirect('login.php?next=' . rawurlencode(eventUrl($event, false))); }
        }
        $verified = $acct && $vals['says_member'] === '1' && !empty($acct['member_code']) && normalizeMemberCode($vals['member_number']) === normalizeMemberCode((string)$acct['member_code']);
        $row = [
            'full_name' => clean($vals['full_name']), 'email' => $vals['email'], 'country' => $vals['country'], 'institution' => clean($vals['institution']),
            'position' => $vals['position'], 'position_other' => $vals['position'] === 'other' && $vals['position_other'] !== '' ? clean($vals['position_other']) : null,
            'says_member' => (int)$vals['says_member'], 'member_number' => $vals['says_member'] === '1' ? normalizeMemberCode($vals['member_number']) : null,
            'member_id' => $acct['id'] ?? null, 'member_verified' => $verified ? 1 : 0, 'research_interest' => clean($vals['research_interest']),
        ];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('SELECT id FROM events WHERE id = ? FOR UPDATE')->execute([$eventId]);
            $ex = $pdo->prepare('SELECT * FROM event_guest_registrations WHERE event_id = ? AND email = ?'); $ex->execute([$eventId, $row['email']]);
            $existing = $ex->fetch() ?: null;
            if ($editing && $existing && (int)$existing['id'] !== (int)$editing['id']) { $pdo->rollBack(); $back(['g_email' => 'That email is already registered for this event.'], 'Please check the highlighted fields.'); }
            $target = $editing ?: $existing;
            $active = $target && in_array($target['status'], ['registered', 'attended'], true);
            if (!$active && $event['capacity'] !== null && eventTakenCount($pdo, $eventId) >= (int)$event['capacity']) { $pdo->rollBack(); flash('error', 'Sorry, this event is now fully booked.'); redirect(eventUrl($event, false)); }
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($row)));
            if ($target) {
                $pdo->prepare("UPDATE event_guest_registrations SET {$set}" . ($active ? '' : ", status = 'registered'") . ' WHERE id = ?')->execute([...array_values($row), $target['id']]);
                $gid = (int)$target['id'];
            } else {
                $row += ['event_id' => $eventId, 'token' => guestToken(), 'ip_hash' => $ipHash, 'source' => 'form'];
                $pdo->prepare('INSERT INTO event_guest_registrations (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
                $gid = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('guest register: ' . $e->getMessage());
            $back([], 'Something went wrong saving your registration. Please try again.');
        }
        $g = $pdo->prepare('SELECT * FROM event_guest_registrations WHERE id = ?'); $g->execute([$gid]); $g = $g->fetch();
        sendGuestConfirmation($event, $g, $active);
        $_SESSION['guest_regs'][$eventId] = $g['token'];
        $_SESSION['guest_profile'] = $vals;
        flash('success', $active ? 'Your details are updated. We\'ve emailed you the latest confirmation.' : 'You\'re registered! We\'ve emailed your confirmation to ' . $g['email'] . '.');
        redirect(guestManageUrl($g, false));
    }

    $g = $guestFind((string)($_POST['g_token'] ?? ''));
    if (!$g) { flash('error', 'That registration link isn\'t valid.'); redirect('events.php'); }
    $event = $fetchEvent((int)$g['event_id']);
    if (!$event || eventPhase($event) === 'past') { flash('error', 'This event has finished, so the registration can\'t be changed.'); redirect(guestManageUrl($g, false)); }
    if ($action === 'guest_cancel' && $g['status'] === 'registered') {
        $pdo->prepare("UPDATE event_guest_registrations SET status = 'cancelled' WHERE id = ?")->execute([$g['id']]);
        flash('success', 'Your registration is cancelled. Changed your mind? You can register again below while places last.');
    }
    if ($action === 'guest_rejoin' && $g['status'] === 'cancelled') {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM events WHERE id = ? FOR UPDATE')->execute([$event['id']]);
        if ($event['capacity'] !== null && eventTakenCount($pdo, (int)$event['id']) >= (int)$event['capacity']) { $pdo->rollBack(); flash('error', 'Sorry, this event is now fully booked.'); redirect(guestManageUrl($g, false)); }
        $pdo->prepare("UPDATE event_guest_registrations SET status = 'registered' WHERE id = ?")->execute([$g['id']]);
        $pdo->commit();
        $g['status'] = 'registered';
        sendGuestConfirmation($event, $g);
        flash('success', 'You\'re registered again. We\'ve emailed your confirmation.');
    }
    redirect(guestManageUrl($g, false));
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
        // A guest registration with this member's email becomes the member registration (no double place).
        $pdo->prepare("UPDATE event_guest_registrations SET status = 'cancelled' WHERE event_id = ? AND status = 'registered'
                       AND (member_id = ? OR email IN (SELECT email FROM members WHERE id = ? UNION SELECT email FROM member_emails WHERE member_id = ?))")
            ->execute([$eventId, $myMemberId, $myMemberId, $myMemberId]);
        $isFull = $event['capacity'] !== null && eventTakenCount($pdo, $eventId) >= (int)$event['capacity'];
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
// A guest's private registration page (?guest=TOKEN).
$guestView = null; $guestViewEvent = null;
if (!empty($_GET['guest'])) {
    $guestView = $guestFind((string)$_GET['guest']);
    $guestViewEvent = $guestView ? $fetchEvent((int)$guestView['event_id']) : null;
    if (!$guestView || !$guestViewEvent) { $guestView = null; flash('error', 'That registration link isn\'t valid. Check the link in your confirmation email.'); }
    else {
        $_SESSION['guest_regs'][(int)$guestView['event_id']] = $guestView['token'];
        if ($guestViewEvent['visibility'] === 'unlisted' && $guestViewEvent['share_token']) $_SESSION['event_keys'][(int)$guestViewEvent['id']] = $guestViewEvent['share_token'];
    }
}
// Guest registrations made in this browser: event id => row (still valid tokens only).
$guestRegs = [];
if (!empty($_SESSION['guest_regs']) && is_array($_SESSION['guest_regs'])) {
    $toks = array_values(array_filter(array_map('strval', $_SESSION['guest_regs']), fn($t) => preg_match('/^[a-f0-9]{32}$/', $t)));
    if ($toks) {
        $gs = $pdo->prepare('SELECT * FROM event_guest_registrations WHERE token IN (' . implode(',', array_fill(0, count($toks), '?')) . ')');
        $gs->execute($toks);
        foreach ($gs as $g) if ($g['status'] !== 'cancelled') $guestRegs[(int)$g['event_id']] = $g;
    }
}
// What the registration form starts with: the last attempt (with its errors), the details used before, or the member's profile.
$guestForm = $_SESSION['guest_form'] ?? null; unset($_SESSION['guest_form']);
$guestPrefill = $guestForm['values'] ?? $_SESSION['guest_profile'] ?? [];
if (!$guestPrefill && $isMember) {
    $pf = $pdo->prepare('SELECT full_name, lab_name, type, email, country, institution, member_code FROM members WHERE id = ?'); $pf->execute([$myMemberId]);
    if ($pf = $pf->fetch()) $guestPrefill = ['full_name' => html_entity_decode((string)($pf['type'] === 'lab' ? $pf['lab_name'] : $pf['full_name']), ENT_QUOTES), 'email' => $pf['email'], 'country' => $pf['country'],
        'institution' => html_entity_decode((string)$pf['institution'], ENT_QUOTES), 'says_member' => '1', 'member_number' => (string)$pf['member_code']];
}
if ($guestView && !$guestForm) {
    $gv = fn($c) => html_entity_decode((string)$guestView[$c], ENT_QUOTES);
    $guestPrefill = ['full_name' => $gv('full_name'), 'email' => $guestView['email'], 'country' => $guestView['country'], 'institution' => $gv('institution'), 'position' => $guestView['position'],
        'position_other' => $gv('position_other'), 'says_member' => (string)$guestView['says_member'], 'member_number' => (string)$guestView['member_number'], 'research_interest' => $gv('research_interest')];
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
    $rc  = $pdo->prepare('SELECT e.id, ' . eventTakenSql('e') . " FROM events e WHERE e.id IN ($in)");
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
echo htmlHead($guestView ? 'Your registration' : ($shared ? html_entity_decode($shared['title'], ENT_QUOTES) : ($sharedState === 'locked' ? 'Members-only event' : 'Events & Webinars')), false, [
    'description' => $shared
        ? trim(($shared['event_date'] ? html_entity_decode(eventWhenText($shared, true), ENT_QUOTES) . ' · ' : '') . ($shared['online_url'] && !$shared['location'] ? 'Online · ' : '') . mb_strimwidth(html_entity_decode(strip_tags((string)$shared['description']), ENT_QUOTES), 0, 180, '…'))
        : ($sharedState === 'locked' ? 'A RARL event for members. Sign in or join free to see the details and register.'
        : 'Free webinars, workshops and conferences on robotics, AI and automation from the Robotics & Automation Research Lab (RARL). Register in one click.'),
    'image' => $sharedCover && str_starts_with($sharedCover, 'http') ? $sharedCover : ($sharedCover ? rtrim(SITE_URL, '/') . '/' . ltrim(str_replace(UPLOADS_URL, 'uploads', $sharedCover), '/') : null),
    'type' => $shared ? 'event' : 'website',
    // Only public events belong in search engines.
    'noindex' => ($shared && $shared['visibility'] !== 'public') || $sharedState !== null || !empty($_GET['guest']),
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
      $mine = in_array($myRegistrations[$id] ?? null, ['registered', 'attended'], true) || isset($guestRegs[$id]);
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
        <?php elseif (!$isActiveMember && isset($guestRegs[$id])): $gr = $guestRegs[$id]; ?>
          <a href="<?= ex(guestManageUrl($gr, false)) ?>" class="ev-btn ev-btn-primary"><i class="fa-solid fa-ticket"></i> <?= $e['online_url'] ? 'My registration' : 'My check-in pass' ?></a>
          <?php if ($e['online_url'] && $gr['status'] === 'registered'): ?><a href="<?= ex($e['online_url']) ?>" target="_blank" rel="noopener" class="ev-btn"><i class="fa-solid fa-video"></i> Join</a><?php endif; ?>
          <span class="text-sm text-green-700 dark:text-green-400 font-semibold"><i class="fa-solid fa-circle-check"></i> You're registered</span>
        <?php elseif (!$isActiveMember && $e['visibility'] !== 'members_only'): ?>
          <?php if ($isFull): ?><span class="ev-btn opacity-60 cursor-not-allowed">Fully booked</span>
          <?php else: ?><button type="button" class="ev-btn ev-btn-primary" data-guest-open="<?= $id ?>" data-title="<?= ex($e['title']) ?>" data-when="<?= ex(eventWhenText($e)) ?>" data-k="<?= $e['visibility'] === 'unlisted' ? ex($e['share_token']) : '' ?>"><i class="fa-solid fa-check"></i> Register — free</button><?php endif; ?>
          <?php if (!$isMember): ?><a href="login.php?next=<?= rawurlencode($linkRel) ?>" class="text-sm font-semibold text-gray-600 dark:text-gray-300 hover:text-rarl-red px-1">Member? Sign in</a><?php endif; ?>
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

<?php // ── Guest registration form: one dialog, filled in for whichever event opened it ──
$gp = $guestPrefill; $gpv = fn($k) => ex($gp[$k] ?? '');
$gErr = $guestForm['errors'] ?? [];
$gErrHtml = fn($f) => isset($gErr[$f]) ? '<p class="g-err" id="' . $f . '-err">' . ex($gErr[$f]) . '</p>' : '';
$gInv = fn($f) => isset($gErr[$f]) ? ' aria-invalid="true" aria-describedby="' . $f . '-err"' : '';
?>
<dialog class="rarl-modal" id="guest-form" aria-labelledby="guest-form-t" data-hash="off">
  <div class="rarl-modal-panel" style="--modal-w:34rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <form method="POST" action="events.php" novalidate id="gform" class="g-grouped p-5 sm:p-7 pt-8 sm:pt-8">
      <?= csrfField() ?><input type="hidden" name="action" value="guest_register"><input type="hidden" name="event_id" value="<?= (int)($guestForm['event_id'] ?? 0) ?>"><input type="hidden" name="k" value=""><input type="hidden" name="g_token" value="<?= ex($guestForm['token'] ?? '') ?>">
      <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true"><!-- spam trap -->
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red" id="guest-form-kicker">Register</p>
      <h2 id="guest-form-t" class="font-heading font-bold text-xl text-gray-900 dark:text-white leading-snug pe-10">Event</h2>
      <p id="guest-form-when" class="text-sm text-gray-500 mt-0.5"></p>
      <?php if (!empty($guestForm['msg'])): ?><div role="alert" class="mt-4 flex gap-2 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 text-sm"><i class="fa-solid fa-circle-exclamation mt-1"></i><span><?= ex($guestForm['msg']) ?></span></div><?php endif; ?>

      <h3 class="g-head">About you</h3>
      <div class="g-card">
        <label class="g-row"><span class="g-lab">Full name</span><input name="g_name" class="g-input" value="<?= $gpv('full_name') ?>" required autocomplete="name" maxlength="255" placeholder="As it should appear on your certificate"<?= $gInv('g_name') ?>/></label>
        <label class="g-row"><span class="g-lab">Email</span><input type="email" name="g_email" class="g-input" value="<?= $gpv('email') ?>" required autocomplete="email" inputmode="email" maxlength="255" placeholder="name@university.edu"<?= $gInv('g_email') ?>/></label>
        <label class="g-row"><span class="g-lab">Country</span><select name="g_country" class="g-input g-select" required autocomplete="country-name"<?= $gInv('g_country') ?>><?= countrySelectOptions((string)($gp['country'] ?? '')) ?></select></label>
        <label class="g-row"><span class="g-lab">Institution</span><input name="g_institution" class="g-input" value="<?= $gpv('institution') ?>" required autocomplete="organization" maxlength="255" placeholder="University, lab or company"<?= $gInv('g_institution') ?>/></label>
      </div>
      <?= $gErrHtml('g_name') . $gErrHtml('g_email') . $gErrHtml('g_country') . $gErrHtml('g_institution') ?>
      <p class="g-note">Your confirmation and certificate go to this email.</p>

      <h3 class="g-head" id="g-pos-h">Position</h3>
      <div class="g-chips" role="radiogroup" aria-labelledby="g-pos-h"<?= $gInv('g_position') ?>>
        <?php foreach (GUEST_POSITIONS as $pk => $pl): ?>
        <label><input type="radio" name="g_position" value="<?= $pk ?>" <?= ($gp['position'] ?? '') === $pk ? 'checked' : '' ?> required/><span><?= $pl ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="g-card mt-2" id="g-other" hidden><label class="g-row"><span class="sr-only">Your position</span><input name="g_position_other" class="g-input" value="<?= $gpv('position_other') ?>" maxlength="120" placeholder="Your position (optional)"/></label></div>
      <?= $gErrHtml('g_position') ?>

      <h3 class="g-head" id="g-mem-h">Are you a RARL member?</h3>
      <div class="g-seg" role="radiogroup" aria-labelledby="g-mem-h"<?= $gInv('g_member') ?>>
        <label><input type="radio" name="g_member" value="1" <?= ($gp['says_member'] ?? '') === '1' ? 'checked' : '' ?> required/><span>Yes</span></label>
        <label><input type="radio" name="g_member" value="0" <?= ($gp['says_member'] ?? '') === '0' ? 'checked' : '' ?> required/><span>No</span></label>
      </div>
      <?= $gErrHtml('g_member') ?>
      <div id="g-num" hidden>
        <div class="g-card mt-2"><label class="g-row"><span class="g-lab">Member number</span><input name="g_member_number" class="g-input font-mono uppercase" value="<?= $gpv('member_number') ?>" maxlength="40" autocapitalize="characters" spellcheck="false" placeholder="RARL-2025-0042"<?= $gInv('g_member_number') ?>/></label></div>
        <?= $gErrHtml('g_member_number') ?>
        <p class="g-note">It's on your member ID card and in your welcome email.</p>
      </div>
      <p class="g-note" id="g-join-hint" hidden>Membership is free — you can <a href="register.php" class="text-rarl-red font-semibold">join after registering</a> to keep all your certificates in one place.</p>

      <h3 class="g-head"><label for="g_interest">Research interest</label></h3>
      <div class="g-card"><div class="g-row !items-start"><textarea id="g_interest" name="g_interest" rows="3" class="g-input resize-y" required maxlength="1000" placeholder="e.g. mobile robots, SLAM, human–robot interaction"<?= $gInv('g_interest') ?>><?= $gpv('research_interest') ?></textarea></div></div>
      <?= $gErrHtml('g_interest') ?>

      <button type="submit" class="ev-btn ev-btn-primary w-full justify-center mt-6 !min-h-[3rem] text-base" id="g-submit" data-busy="Registering…"><i class="fa-solid fa-check"></i> <span>Register — free</span></button>
      <p class="text-xs text-gray-500 text-center mt-3">We use these details only to run this event: your confirmation, reminders, check-in and certificate.</p>
      <?php if (!$isMember): ?><p class="text-sm text-center mt-3"><a href="login.php?next=<?= rawurlencode(ltrim($_SERVER['REQUEST_URI'] ?? 'events.php', '/')) ?>" class="font-semibold text-gray-700 dark:text-gray-300 hover:text-rarl-red" id="g-signin">RARL member? Sign in to register in one tap</a></p><?php endif; ?>
    </form>
  </div>
</dialog>

<?php // ── A guest's own registration page (?guest=TOKEN) ──
if ($guestView):
  $ge = $guestViewEvent; $gt = eventTimes($ge); $gphase = eventPhase($ge); $gst = $guestView['status'];
  $gLive = $ge['is_active'] && $gphase !== 'past';
  $gStatus = ['registered' => ['bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300', 'fa-circle-check', "You're registered"],
              'attended' => ['bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300', 'fa-award', 'Attended'],
              'no_show' => ['bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300', 'fa-user-clock', 'Marked as not attended'],
              'cancelled' => ['bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300', 'fa-ban', 'Cancelled']][$gst];
  $gx = fn($c) => ex($guestView[$c] ?? '');
  $gTok = '<input type="hidden" name="g_token" value="' . ex($guestView['token']) . '">';
?>
<dialog class="rarl-modal" id="guest-ticket" aria-labelledby="guest-ticket-t" data-hash="off">
  <div class="rarl-modal-panel" style="--modal-w:28rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="g-grouped p-5 sm:p-7 pt-8">
      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= $gStatus[0] ?>"><i class="fa-solid <?= $gStatus[1] ?>"></i> <?= $gStatus[2] ?></span>
      <h2 id="guest-ticket-t" class="font-heading font-bold text-xl text-gray-900 dark:text-white leading-snug mt-3 pe-8"><?= ex($ge['title']) ?></h2>
      <p class="text-sm text-gray-600 dark:text-gray-300 mt-1"><?= ex(eventWhenText($ge, true)) ?></p>
      <?php if ($gt['start'] && !$gt['all_day']): ?><p class="ev-local text-xs text-gray-500" hidden data-start="<?= $isoUtc($gt['start']) ?>" data-end="<?= $gt['explicit_end'] ? $isoUtc($gt['end']) : '' ?>" data-tz="<?= ex($gt['tz']->getName()) ?>"></p><?php endif; ?>
      <p class="text-sm text-gray-500 mt-0.5"><i class="fa-solid <?= $ge['location'] ? 'fa-location-dot' : 'fa-wifi' ?>"></i> <?= $ge['location'] ? ex($ge['location']) : ($ge['online_url'] ? 'Online' : 'Place to be announced') ?></p>
      <?php if (!$ge['is_active']): ?><p class="mt-4 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300 text-sm">The organisers have taken this event down. Check your email for any update from them.</p><?php endif; ?>

      <?php if ($gst === 'registered' && $gLive): ?>
        <?php if ($ge['online_url']): ?>
        <a href="<?= ex($ge['online_url']) ?>" target="_blank" rel="noopener" class="ev-btn ev-btn-primary w-full justify-center mt-5 !min-h-[3rem] text-base"><i class="fa-solid fa-video"></i> Join the event</a>
        <p class="g-note text-center !mx-0">The link works from the start time. It's also in your confirmation email.</p>
        <?php else: ?>
        <div class="mt-5 rounded-2xl bg-white ring-1 ring-gray-200 dark:ring-gray-700 p-4 text-center">
          <img src="<?= qrDataUri(guestCheckinUrl((int)$guestView['id']), 8, 1) ?>" alt="Check-in QR code for <?= $gx('full_name') ?>" class="w-52 h-52 mx-auto [image-rendering:pixelated]"/>
          <p class="font-semibold text-gray-900 mt-2"><?= $gx('full_name') ?></p>
          <p class="text-xs text-gray-500">Show this at the door. Turn your screen brightness up.</p>
        </div>
        <?php endif; ?>
      <?php elseif ($gst === 'attended'): ?>
        <p class="mt-5 p-3 rounded-xl bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 text-sm"><i class="fa-solid fa-award"></i> Thanks for coming. Your certificate has been emailed to <?= $gx('email') ?>.</p>
      <?php endif; ?>

      <h3 class="g-head">Your details</h3>
      <dl class="g-card text-sm">
        <?php foreach ([
            ['Name', $gx('full_name')], ['Email', $gx('email')], ['Country', $gx('country')], ['Institution', $gx('institution')],
            ['Position', ex(($guestView['position'] === 'other' && $guestView['position_other']) ? html_entity_decode((string)$guestView['position_other'], ENT_QUOTES) : (GUEST_POSITIONS[$guestView['position']] ?? '—'))],
            ['RARL member', $guestView['says_member'] ? 'Yes' . ($guestView['member_number'] ? ' · <span class="font-mono">' . $gx('member_number') . '</span>' : '') : 'No'],
            ['Research interest', $gx('research_interest')],
        ] as [$dt, $dd]): if ($dd === '') continue; ?>
        <div class="g-row !items-start"><dt class="g-lab"><?= $dt ?></dt><dd class="flex-1 min-w-0 text-gray-900 dark:text-white break-words"><?= $dd ?></dd></div>
        <?php endforeach; ?>
      </dl>

      <div class="g-card mt-5">
        <?php if ($gLive && $gst === 'registered'): ?>
        <button type="button" class="g-row w-full text-start" data-guest-open="<?= (int)$ge['id'] ?>" data-token="<?= ex($guestView['token']) ?>" data-title="<?= ex($ge['title']) ?>" data-when="<?= ex(eventWhenText($ge)) ?>"><i class="fa-solid fa-pen w-5 text-center text-gray-400"></i><span class="flex-1">Edit my details</span><i class="fa-solid fa-chevron-right text-xs text-gray-400"></i></button>
        <?php endif; ?>
        <?php if ($gLive && $gt['start']): ?>
        <a class="g-row" href="<?= ex(eventIcsUrl($ge)) ?>"><i class="fa-regular fa-calendar-plus w-5 text-center text-gray-400"></i><span class="flex-1">Add to calendar</span><i class="fa-solid fa-chevron-right text-xs text-gray-400"></i></a>
        <?php endif; ?>
        <?php if ($ge['is_active']): ?>
        <a class="g-row" href="<?= ex(eventUrl($ge, false)) ?>"><i class="fa-regular fa-calendar w-5 text-center text-gray-400"></i><span class="flex-1">Event details</span><i class="fa-solid fa-chevron-right text-xs text-gray-400"></i></a>
        <?php endif; ?>
      </div>

      <?php if ($gLive && $gst === 'registered'): ?>
      <form method="POST" action="events.php" class="g-card mt-5" data-confirm="Cancel your registration for this event?"><?= csrfField() ?><input type="hidden" name="action" value="guest_cancel"><?= $gTok ?>
        <button class="g-row w-full justify-center text-red-600 dark:text-red-400 font-medium">Cancel registration</button></form>
      <?php elseif ($gLive && $gst === 'cancelled'): ?>
      <form method="POST" action="events.php" class="mt-5"><?= csrfField() ?><input type="hidden" name="action" value="guest_rejoin"><?= $gTok ?>
        <button class="ev-btn ev-btn-primary w-full justify-center !min-h-[3rem]"><i class="fa-solid fa-rotate-right"></i> Register again</button></form>
      <?php endif; ?>
      <p class="text-xs text-gray-500 text-center mt-4">This page is private to you — anyone with its link can see and change this registration.</p>
    </div>
  </div>
</dialog>
<?php endif; ?>

<style>
  .g-grouped .g-head{font-size:.8125rem;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:#6b7280;margin:1.35rem 0 .45rem .9rem}
  .g-card{background:#f2f2f7;border-radius:12px;overflow:hidden}
  .dark .g-card{background:#1c1c1e}
  .g-row{display:flex;align-items:center;gap:.75rem;min-height:48px;padding:.5rem 1rem;position:relative;color:#111827}
  .dark .g-row{color:#f3f4f6}
  .g-card>.g-row+.g-row::before,.g-card>*+*>.g-row::before{content:"";position:absolute;top:0;left:1rem;right:0;border-top:.5px solid rgba(60,60,67,.2)}
  .dark .g-card>.g-row+.g-row::before{border-color:rgba(84,84,88,.6)}
  a.g-row:hover,button.g-row:hover{background:rgba(0,0,0,.035)}
  .g-lab{width:7.5rem;flex-shrink:0;font-size:.9375rem;color:#374151}
  .dark .g-lab{color:#d1d5db}
  @media (max-width:420px){.g-lab{width:6.25rem;font-size:.875rem}}
  .g-input{flex:1;min-width:0;border:0;outline:0;background:transparent;font-size:1rem;color:inherit;padding:.25rem 0}
  .g-input::placeholder{color:#9ca3af}
  .g-select{padding-right:1.75rem!important;background-position:right .1rem center!important;cursor:pointer}
  .g-input[aria-invalid=true]{color:#dc2626}
  .g-row:has(.g-input:focus-visible){box-shadow:inset 0 0 0 2px rgba(204,7,3,.45);border-radius:12px}
  .g-input:focus-visible{outline:none}
  .g-note{font-size:.8125rem;color:#6b7280;margin:.4rem .9rem 0;line-height:1.4}
  .g-err{font-size:.8125rem;color:#dc2626;margin:.4rem .9rem 0}
  .g-chips{display:flex;flex-wrap:wrap;gap:.5rem}
  .g-chips label{cursor:pointer}
  .g-chips input,.g-seg input{position:absolute;opacity:0;pointer-events:none}
  .g-chips span{display:inline-flex;align-items:center;min-height:44px;padding:0 1.05rem;border-radius:999px;background:#f2f2f7;font-size:.9375rem;font-weight:500;color:#111827;transition:background-color .15s,color .15s}
  .dark .g-chips span{background:#1c1c1e;color:#f3f4f6}
  .g-chips input:checked+span{background:#CC0703;color:#fff}
  .g-chips input:focus-visible+span,.g-seg input:focus-visible+span{outline:2px solid #CC0703;outline-offset:2px}
  .g-seg{display:flex;padding:2px;border-radius:10px;background:#e3e3e8}
  .dark .g-seg{background:#2c2c2e}
  .g-seg label{flex:1;cursor:pointer;position:relative}
  .g-seg span{display:flex;align-items:center;justify-content:center;min-height:40px;border-radius:8px;font-size:.9375rem;font-weight:500;color:#111827}
  .dark .g-seg span{color:#f3f4f6}
  .g-seg input:checked+span{background:#fff;font-weight:600;box-shadow:0 3px 8px rgba(0,0,0,.12),0 3px 1px rgba(0,0,0,.04)}
  .dark .g-seg input:checked+span{background:#636366}
  [aria-invalid=true].g-chips span,[aria-invalid=true].g-seg{box-shadow:0 0 0 1.5px #dc2626}
</style>
<script>
(function () {
  var dlg = document.getElementById('guest-form'), f = document.getElementById('gform');
  if (!dlg || !f) return;
  var other = document.getElementById('g-other'), num = document.getElementById('g-num'), joinHint = document.getElementById('g-join-hint');
  function sync() {
    var pos = f.querySelector('[name=g_position]:checked'), mem = f.querySelector('[name=g_member]:checked');
    other.hidden = !(pos && pos.value === 'other');
    num.hidden = !(mem && mem.value === '1');
    joinHint.hidden = !(mem && mem.value === '0');
    f.g_member_number.required = !num.hidden;
  }
  f.addEventListener('change', sync); sync();
  function openGuest(b) {
    f.event_id.value = b.dataset.guestOpen; f.k.value = b.dataset.k || ''; f.g_token.value = b.dataset.token || '';
    document.getElementById('guest-form-t').textContent = b.dataset.title || 'Register';
    document.getElementById('guest-form-when').textContent = b.dataset.when || '';
    var editing = !!b.dataset.token;
    document.getElementById('guest-form-kicker').textContent = editing ? 'Edit registration' : 'Register';
    document.querySelector('#g-submit span').textContent = editing ? 'Save changes' : 'Register — free';
    var si = document.getElementById('g-signin'); if (si) si.hidden = editing;
    document.querySelectorAll('dialog.rarl-modal[open]').forEach(function (d) { if (d !== dlg) d.close(); });
    rarlModal.open('guest-form', b);
    setTimeout(function () {
      var first = f.querySelector('[aria-invalid=true]') || [].find.call(f.querySelectorAll('.g-input[required]'), function (i) { return !i.value; });
      if (first && matchMedia('(pointer: fine)').matches) first.focus();
    }, 60);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-guest-open]');
    if (b) { e.preventDefault(); openGuest(b); }
  });
  // Check before sending, with the same messages as the server.
  f.addEventListener('submit', function (e) {
    var msgs = {g_name: 'Enter your full name.', g_email: 'Enter a valid email address.', g_country: 'Choose your country.', g_institution: 'Enter your institution or organisation.', g_member_number: 'Enter your RARL member number.', g_interest: 'Tell us your research interest.'};
    var bad = null;
    f.querySelectorAll('.g-err[data-client]').forEach(function (p) { p.remove(); });
    Object.keys(msgs).forEach(function (n) {
      var el = f.elements[n]; if (!el || (n === 'g_member_number' && num.hidden)) return;
      var ok = el.value.trim() !== '' && (n !== 'g_email' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim()));
      el.setAttribute('aria-invalid', ok ? 'false' : 'true');
      if (!ok) { var p = document.createElement('p'); p.className = 'g-err'; p.dataset.client = 1; p.textContent = msgs[n]; (el.closest('.g-card') || el).insertAdjacentElement('afterend', p); bad = bad || el; }
    });
    [['g_position', '.g-chips', 'Choose your position.'], ['g_member', '.g-seg', 'Tell us whether you’re a RARL member.']].forEach(function (r) {
      var box = f.querySelector(r[1]), ok = !!f.querySelector('[name=' + r[0] + ']:checked');
      box.setAttribute('aria-invalid', ok ? 'false' : 'true');
      if (!ok) { var p = document.createElement('p'); p.className = 'g-err'; p.dataset.client = 1; p.textContent = r[2]; box.insertAdjacentElement('afterend', p); bad = bad || box.querySelector('input'); }
    });
    if (bad) { e.preventDefault(); e.stopImmediatePropagation(); bad.focus(); bad.scrollIntoView({block: 'center', behavior: 'smooth'}); }
  }, true);
  f.addEventListener('input', function (e) { if (e.target.getAttribute('aria-invalid') === 'true') e.target.setAttribute('aria-invalid', 'false'); });
  <?php if ($guestForm): ?>
  // Back from the server with something to fix: reopen the form for the same event.
  document.addEventListener('DOMContentLoaded', function () {
    var b = document.querySelector('[data-guest-open="<?= (int)$guestForm['event_id'] ?>"]<?= !empty($guestForm['token']) ? '[data-token]' : '' ?>') || document.querySelector('[data-guest-open="<?= (int)$guestForm['event_id'] ?>"]');
    if (b) openGuest(b);
  });
  <?php endif; ?>
})();
</script>

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
  <?php if ($guestView): ?>document.addEventListener('DOMContentLoaded', function () { rarlModal.open('guest-ticket'); });<?php endif; ?>
  // A link to an event on the other tab (e.g. a past event from the feed) switches tabs.
  (function () {
    var m = location.hash.match(/^#(event|pass)-(\d+)$/), other = <?= json_encode($otherTabIds) ?>;
    if (m && !document.getElementById('event-' + m[2]) && other.indexOf(+m[2]) > -1) location.replace('events.php?tab=<?= $tab === 'upcoming' ? 'past' : 'upcoming' ?>#' + m[1] + '-' + m[2]);
  })();
</script>
<?= publicFooter() ?>
</body></html>
