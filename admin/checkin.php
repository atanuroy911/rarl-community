<?php
/**
 * RARL Admin — Event check-in.
 * Scan members' check-in passes with this phone's camera (or open a pass link
 * scanned by any camera app): attendance is marked and the certificate is
 * issued on the spot. Works on iPhone and Android over HTTPS.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

// AJAX scan from the camera screen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    header('Content-Type: application/json');
    if (!adminCsrfOk()) { echo json_encode(['ok' => false, 'state' => 'invalid', 'message' => 'Session expired — reload the page.']); exit; }
    $r = checkinApply((string)($_POST['t'] ?? ''));
    $r['avatar_url'] = !empty($r['avatar']) ? UPLOADS_URL . '/avatars/' . $r['avatar'] : null;
    $r['count'] = !empty($r['event_id']) ? (int)$pdo->query("SELECT COUNT(*) FROM event_registrations WHERE event_id = " . (int)$r['event_id'] . " AND status = 'attended'")->fetchColumn() : null;
    echo json_encode($r, JSON_HEX_TAG); exit;
}
// Confirm from a pass link opened by a phone's camera app.
$linkResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm' && adminCsrfOk()) {
    $linkResult = checkinApply((string)($_POST['t'] ?? ''));
}
$token = (string)($_GET['t'] ?? '');
$preview = null;
if ($token && !$linkResult) {
    $p = checkinParse($token);
    if ($p) {
        $q = $pdo->prepare("SELECT e.title, e.event_date, m.full_name, m.lab_name, m.type, m.member_code, m.avatar_path, r.status
            FROM events e JOIN members m ON m.id = ? LEFT JOIN event_registrations r ON r.event_id = e.id AND r.member_id = m.id WHERE e.id = ?");
        $q->execute([$p['member_id'], $p['event_id']]);
        $preview = $q->fetch() ?: null;
    }
}

$events = $pdo->query("SELECT e.id, e.title, e.event_date, e.event_time,
        (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status IN ('registered','attended')) AS regs,
        (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'attended') AS done
    FROM events e WHERE e.is_active = 1 AND e.event_date BETWEEN CURDATE() - INTERVAL 2 DAY AND CURDATE() + INTERVAL 7 DAY ORDER BY e.event_date, e.event_time")->fetchAll();

adminWrap(function () use ($events, $token, $preview, $linkResult) {
    adminFlash();
    $states = [
        'checked_in'     => ['green', 'fa-circle-check', 'Checked in', 'Attendance recorded and certificate issued.'],
        'already'        => ['amber', 'fa-clock-rotate-left', 'Already checked in', 'This pass was scanned before.'],
        'not_registered' => ['red', 'fa-user-xmark', 'Not registered', 'This member hasn\'t registered for this event.'],
        'cancelled'      => ['red', 'fa-ban', 'Registration cancelled', 'This member cancelled their place.'],
        'invalid'        => ['red', 'fa-triangle-exclamation', 'Not a valid pass', 'This QR code isn\'t a RARL check-in pass.'],
    ];
    ?>
<div class="rarl-page-head">
  <div><h1>Check-in</h1><p>Scan members' check-in passes at the door. Each scan marks attendance and issues the certificate right away.</p></div>
  <a href="events.php" class="rarl-btn"><i class="fa-solid fa-calendar-days"></i> Events</a>
</div>

<?php if ($linkResult || $preview || $token): ?>
<?php $r = $linkResult; $st = $r ? $states[$r['state']] : null; ?>
<div class="rarl-card p-6 max-w-md mb-6 text-center">
  <?php if ($r): ?>
    <div class="w-16 h-16 mx-auto rounded-full flex items-center justify-center text-3xl bg-<?= $st[0] ?>-100 text-<?= $st[0] ?>-700"><i class="fa-solid <?= $st[1] ?>"></i></div>
    <h2 class="font-heading font-bold text-xl mt-3"><?= $st[2] ?></h2>
    <?php if ($r['name']): ?><p class="mt-1 font-semibold"><?= ex($r['name']) ?></p><p class="text-sm text-[color:var(--muted-fg)]"><?= ex($r['event']) ?></p><?php endif; ?>
    <p class="text-sm text-[color:var(--muted-fg)] mt-2"><?= $st[3] ?></p>
  <?php elseif ($preview): $pn = $preview['type'] === 'lab' ? $preview['lab_name'] : $preview['full_name']; ?>
    <?= memberAvatarHtml($preview['avatar_path'], (string)$pn, 'w-20 h-20 text-2xl mx-auto') ?>
    <h2 class="font-heading font-bold text-xl mt-3"><?= ex($pn) ?></h2>
    <?php if ($preview['member_code']): ?><p class="font-mono text-sm text-[color:var(--muted-fg)]">#<?= ex($preview['member_code']) ?></p><?php endif; ?>
    <p class="mt-2 text-sm"><?= ex($preview['title']) ?> · <?= $preview['event_date'] ? date('d M', strtotime($preview['event_date'])) : '' ?></p>
    <p class="mt-1"><span class="rarl-badge <?= ['attended' => 'rarl-badge-amber', 'registered' => 'rarl-badge-blue'][$preview['status']] ?? 'rarl-badge-red' ?>"><?= ['attended' => 'Already checked in', 'registered' => 'Registered', 'cancelled' => 'Cancelled'][$preview['status']] ?? 'Not registered' ?></span></p>
    <?php if ($preview['status'] === 'registered'): ?>
    <form method="POST" class="mt-5"><?= acsrfField() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="t" value="<?= ex($token) ?>"><button class="rarl-btn rarl-btn-primary w-full !h-12 text-base"><i class="fa-solid fa-check"></i> Check in</button></form>
    <?php endif; ?>
  <?php else: ?>
    <div class="w-16 h-16 mx-auto rounded-full flex items-center justify-center text-3xl bg-red-100 text-red-700"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <h2 class="font-heading font-bold text-xl mt-3">Not a valid pass</h2>
  <?php endif; ?>
  <a href="checkin.php" class="inline-block mt-4 text-sm underline text-[color:var(--muted-fg)]">Open the scanner</a>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
  <section class="rarl-card overflow-hidden">
    <div class="relative bg-black aspect-[4/3] sm:aspect-video">
      <video id="cam" class="absolute inset-0 w-full h-full object-cover" playsinline muted></video>
      <div class="absolute inset-0 flex items-center justify-center pointer-events-none"><div class="w-56 h-56 sm:w-64 sm:h-64 rounded-3xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,.35)]"></div></div>
      <div id="cam-idle" class="absolute inset-0 flex flex-col items-center justify-center text-center text-white p-6 bg-gray-900">
        <i class="fa-solid fa-qrcode text-5xl text-white/70"></i>
        <p class="mt-3 font-semibold">Scan check-in passes with this device's camera</p>
        <button type="button" id="cam-start" class="rarl-btn rarl-btn-primary mt-4 !h-11"><i class="fa-solid fa-camera"></i> Start scanning</button>
        <p id="cam-error" class="mt-3 text-sm text-red-300" hidden></p>
      </div>
      <div id="result" class="absolute inset-x-3 bottom-3 rounded-2xl p-4 text-white flex items-center gap-3 shadow-2xl" hidden aria-live="assertive">
        <img id="r-avatar" alt="" class="w-14 h-14 rounded-full object-cover bg-white/20 flex-shrink-0" hidden/>
        <div class="min-w-0 flex-1"><p id="r-title" class="font-heading font-bold text-lg leading-tight"></p><p id="r-name" class="font-semibold truncate"></p><p id="r-sub" class="text-sm opacity-90 truncate"></p></div>
        <i id="r-icon" class="fa-solid text-3xl"></i>
      </div>
    </div>
    <div class="p-4 flex flex-wrap items-center gap-3 text-sm">
      <span class="text-[color:var(--muted-fg)]">Checked in this session: <strong id="session-count" class="text-[color:var(--fg)]">0</strong></span>
      <button type="button" id="cam-stop" class="rarl-btn rarl-btn-sm ms-auto" hidden><i class="fa-solid fa-stop"></i> Stop</button>
      <button type="button" id="cam-flip" class="rarl-btn rarl-btn-sm" hidden><i class="fa-solid fa-camera-rotate"></i> Switch camera</button>
    </div>
  </section>

  <aside class="space-y-4">
    <div class="rarl-card overflow-hidden">
      <div class="px-5 py-4 border-b border-[color:var(--border)]"><h2 class="rarl-card-title">Events this week</h2></div>
      <?php if (!$events): ?><p class="rarl-empty !py-8">No events between 2 days ago and next week.</p><?php endif; ?>
      <ul class="divide-y divide-[color:var(--border)]">
        <?php foreach ($events as $ev): ?>
        <li class="px-5 py-3 flex items-center gap-3">
          <div class="flex-1 min-w-0"><p class="font-semibold text-sm truncate"><?= ex($ev['title']) ?></p><p class="text-xs text-[color:var(--muted-fg)]"><?= date('D d M', strtotime($ev['event_date'])) ?><?= $ev['event_time'] ? ' · ' . date('g:i A', strtotime($ev['event_time'])) : '' ?></p></div>
          <span class="text-sm font-semibold whitespace-nowrap" data-event-count="<?= (int)$ev['id'] ?>"><?= (int)$ev['done'] ?></span><span class="text-xs text-[color:var(--muted-fg)] whitespace-nowrap">/ <?= (int)$ev['regs'] ?></span>
          <a href="event-registrations.php?event=<?= (int)$ev['id'] ?>" class="rarl-icon-btn" title="Attendee list (check in by name)"><i class="fa-solid fa-list-check"></i></a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="rarl-card p-5 text-sm text-[color:var(--muted-fg)] space-y-2">
      <p class="font-semibold text-[color:var(--fg)]">How it works</p>
      <p><i class="fa-solid fa-1 w-4"></i> Members open the event in the app and tap <strong>Check-in pass</strong>.</p>
      <p><i class="fa-solid fa-2 w-4"></i> Scan it here, or with the phone's own camera app (it opens a one-tap check-in).</p>
      <p><i class="fa-solid fa-3 w-4"></i> Forgot their phone? Use the attendee list <i class="fa-solid fa-list-check"></i> to check them in by name.</p>
    </div>
  </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js" defer></script>
<script>
(function () {
  var ACSRF = <?= json_encode($GLOBALS['acsrf']) ?>;
  var video = document.getElementById('cam'), idle = document.getElementById('cam-idle'), err = document.getElementById('cam-error');
  var result = document.getElementById('result'), stopBtn = document.getElementById('cam-stop'), flipBtn = document.getElementById('cam-flip');
  var stream = null, facing = 'environment', busy = false, lastToken = '', lastAt = 0, sessionCount = 0, detector = null, canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', {willReadFrequently: true});
  var STATES = <?= json_encode(array_map(fn($s) => ['tone' => $s[0], 'icon' => $s[1], 'title' => $s[2], 'sub' => $s[3]], $states)) ?>;
  var TONES = {green: '#15803d', amber: '#b45309', red: '#b91c1c'};

  async function start() {
    err.hidden = true;
    try {
      stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: facing, width: {ideal: 1280}}, audio: false});
      video.srcObject = stream; await video.play();
      idle.hidden = true; stopBtn.hidden = false; flipBtn.hidden = false;
      if ('BarcodeDetector' in window) { try { detector = new BarcodeDetector({formats: ['qr_code']}); } catch (e) { detector = null; } }
      requestAnimationFrame(tick);
    } catch (e) {
      err.hidden = false;
      err.textContent = location.protocol !== 'https:' && location.hostname !== 'localhost' ? 'The camera only works over https://.' : 'Camera unavailable: allow camera access for this site in your browser settings.';
    }
  }
  function stop() { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; idle.hidden = false; stopBtn.hidden = true; flipBtn.hidden = true; }
  async function tick() {
    if (!stream) return;
    if (!busy && video.readyState >= 2) {
      var text = null;
      try {
        if (detector) { var codes = await detector.detect(video); if (codes.length) text = codes[0].rawValue; }
        else if (window.jsQR) {
          canvas.width = video.videoWidth; canvas.height = video.videoHeight; ctx.drawImage(video, 0, 0);
          var img = ctx.getImageData(0, 0, canvas.width, canvas.height), code = jsQR(img.data, img.width, img.height, {inversionAttempts: 'dontInvert'});
          if (code) text = code.data;
        }
      } catch (e) {}
      if (text) handle(text);
    }
    setTimeout(function () { requestAnimationFrame(tick); }, 120);
  }
  function handle(text) {
    var m = text.match(/(\d+\.\d+\.[a-f0-9]{20})/), token = m ? m[1] : text;
    if (token === lastToken && Date.now() - lastAt < 4000) return; // same pass still in view
    lastToken = token; lastAt = Date.now(); busy = true;
    var fd = new FormData(); fd.append('action', 'scan'); fd.append('t', token); fd.append('acsrf', ACSRF);
    fetch('checkin.php', {method: 'POST', body: fd}).then(function (r) { return r.json(); }).then(show).catch(function () { show({state: 'invalid', message: 'Network error — try again.'}); });
  }
  function show(r) {
    var s = STATES[r.state] || STATES.invalid;
    result.style.background = TONES[s.tone]; result.hidden = false;
    document.getElementById('r-title').textContent = s.title;
    document.getElementById('r-name').textContent = r.name || '';
    document.getElementById('r-sub').textContent = r.message || (r.event ? r.event : s.sub);
    document.getElementById('r-icon').className = 'fa-solid text-3xl ' + s.icon;
    var av = document.getElementById('r-avatar'); av.hidden = !r.avatar_url; if (r.avatar_url) av.src = r.avatar_url;
    if (navigator.vibrate) navigator.vibrate(r.state === 'checked_in' ? 80 : [60, 60, 60]);
    if (r.state === 'checked_in') { sessionCount++; document.getElementById('session-count').textContent = sessionCount; }
    var c = r.event_id && document.querySelector('[data-event-count="' + r.event_id + '"]'); if (c && r.count !== null) c.textContent = r.count;
    setTimeout(function () { result.hidden = true; busy = false; }, r.state === 'checked_in' ? 1800 : 2800);
  }
  document.getElementById('cam-start').addEventListener('click', start);
  stopBtn.addEventListener('click', stop);
  flipBtn.addEventListener('click', function () { facing = facing === 'environment' ? 'user' : 'environment'; stop(); start(); });
  document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); });
})();
</script>
<?php }, 'checkin', 'Check-in');
