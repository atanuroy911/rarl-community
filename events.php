<?php
/**
 * RARL — Public Events & Webinars (with RSVP)
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
            $registeredCount = (int) $countStmt->fetchColumn();
            $isFull = $event['capacity'] !== null && $registeredCount >= (int)$event['capacity'];

            if (!$isFull) {
                try {
                    $pdo->prepare("INSERT INTO event_registrations (event_id, member_id, status) VALUES (?,?,'registered')
                                   ON DUPLICATE KEY UPDATE status = IF(status='cancelled','registered',status)")
                        ->execute([$eventId, $myMemberId]);
                    flash('success', 'You\'re registered for "' . $event['title'] . '".');
                } catch (PDOException $e) { /* already registered */ }
            } else {
                flash('error', 'Sorry, this event is fully booked.');
            }
        }
        redirect('events.php#event-' . $eventId);
    }

    if ($action === 'cancel_rsvp' && $eventId) {
        $pdo->prepare("UPDATE event_registrations SET status='cancelled' WHERE event_id=? AND member_id=?")->execute([$eventId, $myMemberId]);
        flash('success', 'Registration cancelled.');
        redirect('events.php#event-' . $eventId);
    }
}

// ── Event lists ──
$tab = ($_GET['tab'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
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

// Registration counts + my status for each visible event
$myRegistrations = [];
if ($isActiveMember && $events) {
    $ids = array_column($events, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $rs  = $pdo->prepare("SELECT event_id, status FROM event_registrations WHERE member_id = ? AND event_id IN ($in)");
    $rs->execute(array_merge([$myMemberId], $ids));
    foreach ($rs->fetchAll() as $r) $myRegistrations[$r['event_id']] = $r['status'];
}
$regCountStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status != 'cancelled'");

$typeIcons  = ['workshop'=>'<i class="fa-solid fa-screwdriver-wrench"></i>','webinar'=>'<i class="fa-solid fa-video"></i>','hackathon'=>'<i class="fa-solid fa-laptop-code"></i>','competition'=>'<i class="fa-solid fa-trophy"></i>','volunteer'=>'<i class="fa-solid fa-handshake"></i>','conference'=>'<i class="fa-solid fa-microphone"></i>','seminar'=>'<i class="fa-solid fa-book-open"></i>','other'=>'<i class="fa-solid fa-thumbtack"></i>'];

echo htmlHead('Events & Webinars');
?>
<?= publicNav('events') ?>

<!-- Hero -->
<section class="relative overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
  <div class="relative z-10 max-w-5xl mx-auto px-6 py-16 text-center">
    <div class="text-5xl mb-4"><i class="fa-solid fa-video"></i></div>
    <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-3">Events &amp; Webinars</h1>
    <p class="text-white/60 text-base max-w-lg mx-auto">Workshops, webinars, and conferences from the RARL community — some open to everyone, some reserved for members.</p>
  </div>
</section>

<div class="max-w-5xl mx-auto px-6 py-14">
  <?= renderFlash() ?>

  <!-- Tabs -->
  <div class="flex gap-2 mb-8 border-b border-gray-200 dark:border-gray-800">
    <a href="events.php?tab=upcoming" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors <?= $tab==='upcoming' ? 'border-rarl-red text-rarl-red' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">Upcoming</a>
    <a href="events.php?tab=past" class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors <?= $tab==='past' ? 'border-rarl-red text-rarl-red' : 'border-transparent text-gray-400 hover:text-gray-600' ?>">Past &amp; Recordings</a>
  </div>

  <?php if (empty($events)): ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-14 text-center text-gray-400">
    <p class="text-4xl mb-3"><i class="fa-solid fa-inbox text-4xl text-gray-300"></i></p>
    <p class="font-semibold mb-1"><?= $tab==='upcoming' ? 'No upcoming events yet' : 'No past events to show' ?></p>
    <p class="text-sm">Check back soon, or follow the community feed for announcements.</p>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <?php foreach ($events as $e):
      $regCountStmt->execute([$e['id']]);
      $registeredCount = (int) $regCountStmt->fetchColumn();
      $isFull = $e['capacity'] !== null && $registeredCount >= (int)$e['capacity'];
      $myStatusForEvent = $myRegistrations[$e['id']] ?? null;
      $isRegistered = in_array($myStatusForEvent, ['registered','attended'], true);
      $membersOnly = $e['visibility'] === 'members_only';
      $locked = $membersOnly && !$isActiveMember;
    ?>
    <div id="event-<?= $e['id'] ?>" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all">
      <?php if (!empty($e['cover_image'])): ?>
      <img src="<?= UPLOADS_URL ?>/events/<?= htmlspecialchars($e['cover_image']) ?>" alt="" class="w-full h-36 object-cover"/>
      <?php else: ?>
      <div class="w-full h-24 flex items-center justify-center text-4xl" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);"><?= $typeIcons[$e['type']] ?? '<i class="fa-solid fa-thumbtack"></i>' ?></div>
      <?php endif; ?>

      <div class="p-6">
        <div class="flex items-center gap-2 mb-3 flex-wrap">
          <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500"><?= $typeIcons[$e['type']] ?? '<i class="fa-solid fa-thumbtack"></i>' ?> <?= ucfirst($e['type']) ?></span>
          <?php if ($membersOnly): ?>
          <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400"><i class="fa-solid fa-lock"></i> Members Only</span>
          <?php endif; ?>
          <?php if ($tab === 'upcoming' && !empty($e['online_url'])): ?>
          <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400"><i class="fa-solid fa-laptop-code"></i> Online</span>
          <?php endif; ?>
        </div>

        <h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-1.5 leading-snug"><?= htmlspecialchars($e['title']) ?></h3>

        <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1 mb-3">
          <?php if ($e['event_date']): ?>
          <div><i class="fa-solid fa-calendar-days"></i> <?= date('D, d M Y', strtotime($e['event_date'])) ?><?= $e['event_time'] ? ' · ' . date('g:i A', strtotime($e['event_time'])) : '' ?></div>
          <?php endif; ?>
          <?php if ($e['location']): ?><div><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($e['location']) ?></div><?php endif; ?>
          <?php if ($e['speaker_name']): ?><div><i class="fa-solid fa-microphone"></i> <?= htmlspecialchars($e['speaker_name']) ?></div><?php endif; ?>
        </div>

        <?php if ($e['description']): ?>
        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-4 line-clamp-3"><?= nl2br(htmlspecialchars($e['description'])) ?></p>
        <?php endif; ?>

        <?php if ($e['capacity'] !== null && $tab === 'upcoming'): ?>
        <div class="mb-4">
          <div class="flex justify-between text-[10px] text-gray-400 mb-1">
            <span><?= $registeredCount ?> / <?= (int)$e['capacity'] ?> registered</span>
            <?php if ($isFull): ?><span class="text-red-500 font-bold">FULL</span><?php endif; ?>
          </div>
          <div class="w-full h-1.5 bg-gray-100 dark:bg-gray-800 rounded-full overflow-hidden">
            <div class="h-full bg-rarl-red rounded-full" style="width:<?= min(100, $e['capacity'] > 0 ? ($registeredCount / $e['capacity']) * 100 : 0) ?>%"></div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'past'): ?>
          <?php if (!empty($e['recording_url']) && $isActiveMember): ?>
          <a href="<?= htmlspecialchars($e['recording_url']) ?>" target="_blank" rel="noopener"
            class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-800 hover:bg-gray-900 text-white text-xs font-semibold rounded-xl transition-colors"><i class="fa-solid fa-play"></i> Watch Recording</a>
          <?php elseif (!empty($e['recording_url'])): ?>
          <a href="register.php" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs font-semibold rounded-xl"><i class="fa-solid fa-lock"></i> Join Free to Watch Recording</a>
          <?php else: ?>
          <span class="text-xs text-gray-300">No recording available</span>
          <?php endif; ?>
        <?php elseif ($locked): ?>
          <a href="register.php" class="inline-flex items-center gap-2 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 text-xs font-bold rounded-xl"><i class="fa-solid fa-lock"></i> Members Only — Join Free</a>
        <?php elseif (!$isMember): ?>
          <a href="login.php" class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-white text-xs font-semibold rounded-xl">Sign In to Register</a>
        <?php elseif (!$isActiveMember): ?>
          <span class="text-xs text-amber-600"><i class="fa-solid fa-hourglass-half"></i> Registration opens once your account is approved</span>
        <?php elseif ($isRegistered): ?>
          <div class="flex items-center gap-3">
            <span class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 text-xs font-bold rounded-xl"><i class="fa-solid fa-circle-check"></i> You're registered</span>
            <?php if (!empty($e['online_url'])): ?>
            <a href="<?= htmlspecialchars($e['online_url']) ?>" target="_blank" rel="noopener" class="text-xs text-rarl-red font-semibold hover:underline">Join link →</a>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Cancel your registration?')">
              <?= csrfField() ?><input type="hidden" name="action" value="cancel_rsvp"><input type="hidden" name="event_id" value="<?= $e['id'] ?>">
              <button type="submit" class="text-xs text-gray-400 hover:text-red-500">Cancel</button>
            </form>
          </div>
        <?php elseif ($isFull): ?>
          <span class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-400 text-xs font-bold rounded-xl">Fully Booked</span>
        <?php else: ?>
          <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="event_id" value="<?= $e['id'] ?>">
            <button type="submit" class="px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-xs font-bold rounded-xl transition-all hover:-translate-y-0.5 shadow">Register →</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?= publicFooter() ?>
</body></html>
