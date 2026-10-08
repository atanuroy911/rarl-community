<?php
/**
 * RARL Admin — Dashboard
 * Opens on what needs doing (approvals, unsent certificates, failed emails,
 * events awaiting attendance), then growth and recent activity.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo = db();
$q = function (string $sql, $default = 0) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return $default; } };

$s = $pdo->query("SELECT COUNT(*) total, SUM(status='active') active, SUM(status='pending') pending, SUM(type='lab') labs,
    SUM(newsletter_opt_in=1 AND status='active') newsletter, SUM(created_at >= NOW() - INTERVAL 30 DAY) new30,
    SUM(created_at >= NOW() - INTERVAL 60 DAY AND created_at < NOW() - INTERVAL 30 DAY) prev30 FROM members")->fetch();
$certCount   = (int)$q("SELECT COUNT(*) FROM certificates");
$unsentCerts = (int)$q("SELECT COUNT(*) FROM certificates WHERE emailed_at IS NULL AND pdf_path IS NOT NULL");
$failedMail  = (int)$q("SELECT COUNT(*) FROM email_queue WHERE status='failed'");
$noChapter   = (int)$q("SELECT COUNT(*) FROM members WHERE status='active' AND section_id IS NULL");
$noPhoto     = (int)$q("SELECT COUNT(*) FROM members WHERE status='active' AND (avatar_path IS NULL OR avatar_path='')");
$expiring    = (int)$q("SELECT COUNT(*) FROM members WHERE status='active' AND id_card_expires_at IS NOT NULL AND id_card_expires_at < DATE_ADD(CURDATE(), INTERVAL 60 DAY)");
$chairless   = (int)$q("SELECT COUNT(*) FROM regional_sections WHERE is_published=1 AND (chair_name IS NULL OR chair_name='')");

$pending = $pdo->query("SELECT id, full_name, lab_name, type, email, institution, country, avatar_path, created_at, email_verified_at FROM members WHERE status='pending' ORDER BY created_at ASC LIMIT 5")->fetchAll();
$needAttendance = [];
try { $needAttendance = $pdo->query("SELECT e.id, e.title, e.event_date, COUNT(r.id) open_regs FROM events e JOIN event_registrations r ON r.event_id = e.id AND r.status = 'registered'
    WHERE e.event_date < CURDATE() AND e.event_date >= CURDATE() - INTERVAL 60 DAY GROUP BY e.id ORDER BY e.event_date DESC LIMIT 3")->fetchAll(); } catch (Throwable $e) {}
$nextEvents = [];
try { $nextEvents = $pdo->query("SELECT e.id, e.title, e.event_date, e.event_time, e.capacity, (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id=e.id AND r.status!='cancelled') regs
    FROM events e WHERE e.is_active=1 AND e.event_date >= CURDATE() ORDER BY e.event_date LIMIT 3")->fetchAll(); } catch (Throwable $e) {}

// Signups per week, last 12 weeks (oldest first, zero-filled).
$weeks = [];
for ($i = 11; $i >= 0; $i--) { $start = strtotime("monday this week -{$i} week"); $weeks[date('Y-m-d', $start)] = 0; }
try {
    foreach ($pdo->query("SELECT DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) wk, COUNT(*) n FROM members WHERE created_at >= CURDATE() - INTERVAL 12 WEEK GROUP BY wk") as $r) {
        if (isset($weeks[$r['wk']])) $weeks[$r['wk']] = (int)$r['n'];
    }
} catch (Throwable $e) {}
$recent = $pdo->query("SELECT id, full_name, lab_name, type, status, avatar_path, created_at FROM members ORDER BY created_at DESC LIMIT 6")->fetchAll();

adminWrap(function() use ($s, $certCount, $unsentCerts, $failedMail, $noChapter, $noPhoto, $expiring, $chairless, $pending, $needAttendance, $nextEvents, $weeks, $recent) {
    adminFlash();
    $name = fn($m) => ($m['type'] === 'lab' ? $m['lab_name'] : $m['full_name']) ?: ($m['email'] ?? '—');
    $todos = array_filter([
        $s['pending'] ? ['members.php?tab=pending&sort=oldest&review=1', 'fa-user-clock', 'amber', (int)$s['pending'] . ' application' . ($s['pending'] == 1 ? '' : 's') . ' waiting for approval', 'Review them one by one with keyboard shortcuts', 'Review'] : null,
        $failedMail ? ['email-queue.php?status=failed', 'fa-triangle-exclamation', 'red', $failedMail . ' email' . ($failedMail == 1 ? '' : 's') . ' failed to send', 'Check the error and retry', 'Fix'] : null,
        $unsentCerts ? ['certificates.php?status=unsent', 'fa-paper-plane', 'blue', $unsentCerts . ' certificate' . ($unsentCerts == 1 ? ' has' : 's have') . ' not been emailed', 'Select them and send in one go', 'Send'] : null,
        ...array_map(fn($e) => ['event-registrations.php?event=' . $e['id'], 'fa-clipboard-check', 'indigo', 'Mark attendance for “' . $e['title'] . '”', $e['open_regs'] . ' registrations still open since ' . date('d M', strtotime($e['event_date'])), 'Open'], $needAttendance),
        $expiring ? ['members.php?tab=attention', 'fa-id-card', 'amber', $expiring . ' ID card' . ($expiring == 1 ? '' : 's') . ' expiring within 60 days', 'Regenerate to extend them', 'View'] : null,
        $noPhoto ? ['members.php?tab=attention', 'fa-camera', 'gray', $noPhoto . ' active member' . ($noPhoto == 1 ? ' has' : 's have') . ' no photo, so no ID card yet', 'Their card is created automatically once they add one', 'View'] : null,
        $noChapter ? ['members.php?tab=active&chapter=none', 'fa-earth-americas', 'gray', $noChapter . ' active member' . ($noChapter == 1 ? '' : 's') . ' without a chapter', 'Select all → Auto-assign chapter by country', 'Assign'] : null,
        $chairless ? ['sections.php', 'fa-crown', 'gray', $chairless . ' chapter' . ($chairless == 1 ? '' : 's') . ' without a chair', 'Leadership keeps chapters active', 'View'] : null,
    ]);
    $tones = ['amber' => 'bg-amber-100 text-amber-700', 'red' => 'bg-red-100 text-red-700', 'blue' => 'bg-blue-100 text-blue-700', 'indigo' => 'bg-indigo-100 text-indigo-700', 'gray' => 'bg-gray-100 text-gray-600'];
    $growth = (int)$s['prev30'] ? round(((int)$s['new30'] - (int)$s['prev30']) / (int)$s['prev30'] * 100) : null;
    $max = max(1, max($weeks));
    $hour = (int)date('G');
?>
<div class="rarl-page-head">
  <div>
    <h1><?= $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening') ?> 👋</h1>
    <p><?= $todos ? count($todos) . ' thing' . (count($todos) === 1 ? '' : 's') . ' need your attention today.' : 'You are all caught up — nothing needs attention right now.' ?></p>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="events.php#new" class="rarl-btn"><i class="fa-solid fa-calendar-plus"></i> New event</a>
    <a href="certificates.php" class="rarl-btn"><i class="fa-solid fa-award"></i> Issue certificates</a>
    <a href="compose-email.php" class="rarl-btn"><i class="fa-solid fa-envelope-open-text"></i> Email members</a>
  </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <a href="members.php?tab=active" class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-users text-gray-400"></i> Active members</span><span class="rarl-stat-value"><?= number_format((int)$s['active']) ?></span><span class="text-[11px] text-gray-500"><?= (int)$s['labs'] ?> research labs</span></a>
  <a href="members.php?sort=newest" class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-user-plus text-gray-400"></i> New in 30 days</span><span class="rarl-stat-value"><?= number_format((int)$s['new30']) ?></span>
    <span class="text-[11px] <?= $growth === null ? 'text-gray-500' : ($growth >= 0 ? 'text-green-600' : 'text-red-600') ?>"><?= $growth === null ? 'no prior data' : ($growth >= 0 ? '▲ ' : '▼ ') . abs($growth) . '% vs previous 30 days' ?></span></a>
  <a href="members.php?tab=pending" class="rarl-stat <?= $s['pending'] ? 'is-active' : '' ?>"><span class="rarl-stat-label"><i class="fa-solid fa-hourglass-half text-amber-500"></i> Pending approval</span><span class="rarl-stat-value"><?= number_format((int)$s['pending']) ?></span><span class="text-[11px] text-gray-500">oldest first in review</span></a>
  <a href="certificates.php" class="rarl-stat"><span class="rarl-stat-label"><i class="fa-solid fa-trophy text-gray-400"></i> Certificates</span><span class="rarl-stat-value"><?= number_format($certCount) ?></span><span class="text-[11px] text-gray-500"><?= number_format((int)$s['newsletter']) ?> newsletter subscribers</span></a>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_380px] gap-6">
  <div class="space-y-6 min-w-0">
    <section class="rarl-card">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between"><h2 class="rarl-card-title">To do</h2><span class="text-xs text-gray-400"><?= count($todos) ?> open</span></div>
      <?php if (!$todos): ?>
      <div class="rarl-empty"><i class="fa-solid fa-circle-check text-3xl text-green-500 mb-2 block"></i>Inbox zero. New approvals and follow-ups will show up here.</div>
      <?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php foreach ($todos as [$href, $icon, $tone, $title, $sub, $cta]): ?>
        <a href="<?= htmlspecialchars($href) ?>" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 group">
          <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 <?= $tones[$tone] ?>"><i class="fa-solid <?= $icon ?>"></i></span>
          <span class="flex-1 min-w-0"><span class="block text-sm font-semibold text-gray-900 truncate"><?= htmlspecialchars($title) ?></span><span class="block text-xs text-gray-500 truncate"><?= htmlspecialchars($sub) ?></span></span>
          <span class="rarl-btn rarl-btn-sm group-hover:border-gray-400"><?= $cta ?> <i class="fa-solid fa-arrow-right text-[10px]"></i></span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="rarl-card p-5">
      <div class="flex items-center justify-between mb-4">
        <div><h2 class="rarl-card-title">New members per week</h2><p class="text-xs text-gray-500">Last 12 weeks · <?= array_sum($weeks) ?> total</p></div>
      </div>
      <div class="relative">
        <svg viewBox="0 0 600 180" class="w-full h-44" role="img" aria-label="Weekly new member registrations, last 12 weeks">
          <?php foreach ([0, 0.5, 1] as $g): $y = 150 - $g * 130; ?>
          <line x1="28" x2="600" y1="<?= $y ?>" y2="<?= $y ?>" stroke="#eef0f3" stroke-width="1"/>
          <text x="22" y="<?= $y + 3 ?>" text-anchor="end" font-size="10" fill="#94a3b8"><?= round($max * $g) ?></text>
          <?php endforeach; ?>
          <?php $i = 0; $bw = 572 / 12; foreach ($weeks as $wk => $n): $h = $n / $max * 130; $x = 28 + $i * $bw + $bw * 0.28; $w = $bw * 0.44; ?>
          <g class="bar" data-tip="Week of <?= date('d M', strtotime($wk)) ?>: <?= $n ?> new member<?= $n === 1 ? '' : 's' ?>">
            <rect x="<?= 28 + $i * $bw ?>" y="10" width="<?= $bw ?>" height="150" fill="transparent"/>
            <?php if ($n): ?><path d="M<?= $x ?>,150 V<?= 150 - $h + 4 ?> q0,-4 4,-4 h<?= $w - 8 ?> q4,0 4,4 V150 Z" fill="<?= BRAND_RED ?>"/><?php endif; ?>
            <?php if ($i % 2 === 1 || $i === 11): ?><text x="<?= $x + $w / 2 ?>" y="170" text-anchor="middle" font-size="10" fill="#94a3b8"><?= date('d M', strtotime($wk)) ?></text><?php endif; ?>
          </g>
          <?php $i++; endforeach; ?>
          <line x1="28" x2="600" y1="150" y2="150" stroke="#cbd5e1" stroke-width="1"/>
        </svg>
        <div id="chart-tip" class="hidden absolute pointer-events-none px-2.5 py-1.5 rounded-lg bg-gray-900 text-white text-xs whitespace-nowrap -translate-x-1/2 -translate-y-full"></div>
      </div>
    </section>
  </div>

  <aside class="space-y-6">
    <section class="rarl-card">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between"><h2 class="rarl-card-title">Waiting for approval</h2><a href="members.php?tab=pending" class="text-xs font-semibold text-rarl-red hover:underline">All →</a></div>
      <?php if (!$pending): ?><p class="rarl-empty !py-8">No pending applications.</p><?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php foreach ($pending as $m): ?>
        <a href="members.php?tab=pending&sort=oldest&review=1" class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50">
          <?= memberAvatarHtml($m['avatar_path'] ?? null, $name($m), 'w-9 h-9 text-sm') ?>
          <span class="flex-1 min-w-0"><span class="block text-sm font-semibold text-gray-900 truncate"><?= htmlspecialchars($name($m)) ?></span><span class="block text-[11px] text-gray-500 truncate"><?= htmlspecialchars(trim(($m['institution'] ?? '') . ($m['country'] ? ' · ' . $m['country'] : ''), ' ·')) ?></span></span>
          <span class="text-[11px] text-gray-400 whitespace-nowrap"><?= (int)floor((time() - strtotime($m['created_at'])) / 86400) ?>d ago</span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="rarl-card">
      <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between"><h2 class="rarl-card-title">Coming up</h2><a href="events.php" class="text-xs font-semibold text-rarl-red hover:underline">Events →</a></div>
      <?php if (!$nextEvents): ?><div class="rarl-empty !py-8">Nothing scheduled. <a href="events.php#new" class="text-rarl-red font-semibold">Create an event</a></div><?php endif; ?>
      <div class="divide-y divide-gray-100">
        <?php foreach ($nextEvents as $e): ?>
        <a href="event-registrations.php?event=<?= $e['id'] ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50">
          <span class="w-11 text-center rounded-lg border border-gray-200 py-1 flex-shrink-0"><span class="block text-[9px] font-bold uppercase text-rarl-red"><?= date('M', strtotime($e['event_date'])) ?></span><span class="block font-heading font-black text-base leading-none"><?= date('d', strtotime($e['event_date'])) ?></span></span>
          <span class="flex-1 min-w-0"><span class="block text-sm font-semibold text-gray-900 truncate"><?= htmlspecialchars($e['title']) ?></span><span class="block text-[11px] text-gray-500"><?= (int)$e['regs'] ?><?= $e['capacity'] ? ' / ' . (int)$e['capacity'] : '' ?> registered</span></span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="rarl-card">
      <div class="px-5 py-4 border-b border-gray-100"><h2 class="rarl-card-title">Latest sign-ups</h2></div>
      <div class="divide-y divide-gray-100">
        <?php foreach ($recent as $m): $b = ['active' => 'rarl-badge-green', 'pending' => 'rarl-badge-amber', 'inactive' => 'rarl-badge-gray'][$m['status']] ?? 'rarl-badge-gray'; ?>
        <a href="members.php?q=<?= urlencode($name($m)) ?>" class="flex items-center gap-3 px-5 py-2.5 hover:bg-gray-50">
          <?= memberAvatarHtml($m['avatar_path'] ?? null, $name($m), 'w-8 h-8 text-xs') ?>
          <span class="flex-1 min-w-0 text-sm text-gray-800 truncate"><?= htmlspecialchars($name($m)) ?></span>
          <span class="rarl-badge <?= $b ?>"><?= ucfirst($m['status']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
  </aside>
</div>
<script>
  (function() {
    const tip = document.getElementById('chart-tip'), wrap = tip.parentElement;
    document.querySelectorAll('svg .bar').forEach(g => {
      g.addEventListener('mousemove', e => { const r = wrap.getBoundingClientRect(); tip.textContent = g.dataset.tip; tip.style.left = (e.clientX - r.left) + 'px'; tip.style.top = (e.clientY - r.top - 8) + 'px'; tip.classList.remove('hidden'); });
      g.addEventListener('mouseleave', () => tip.classList.add('hidden'));
    });
  })();
</script>
<?php }, 'index', 'Dashboard');
