<?php
/**
 * RARL Admin — Compose custom email
 * Targeted, ad-hoc admin messages (specific addresses, a chapter, a plan tier,
 * an event's attendees, pending applicants or everyone active) — distinct from
 * admin/newsletter.php which is for opted-in bulk broadcasts. Bulk sends go
 * through the background email queue.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$sections = $pdo->query("SELECT id, name FROM regional_sections ORDER BY continent, display_order")->fetchAll();
$plans    = $pdo->query("SELECT id, name FROM membership_plans ORDER BY display_order")->fetchAll();
$events   = $pdo->query("SELECT id, title, event_date FROM events ORDER BY event_date IS NULL, event_date DESC LIMIT 50")->fetchAll();
const COMPOSE_MODES = ['raw', 'section', 'plan', 'event', 'pending', 'all_active'];

function composeRecipients(PDO $pdo, array $in): array {
    $nameOf = fn($r) => $r['type'] === 'lab' ? $r['lab_name'] : $r['full_name'];
    $rows = [];
    switch ($in['mode']) {
        case 'raw':
            $out = [];
            foreach (preg_split('/[,\n;\s]+/', $in['raw_emails'] ?? '') as $raw) {
                $e = cleanEmail(trim($raw));
                if ($e && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[$e] = ['email' => $e, 'name' => $e];
            }
            return array_values($out);
        case 'section':
            $s = $pdo->prepare("SELECT email, full_name, lab_name, type FROM members WHERE section_id = ? AND status = 'active'"); $s->execute([(int)$in['section_id']]); $rows = $s->fetchAll(); break;
        case 'plan':
            $s = $pdo->prepare("SELECT email, full_name, lab_name, type FROM members WHERE plan_id = ? AND status = 'active'"); $s->execute([(int)$in['plan_id']]); $rows = $s->fetchAll(); break;
        case 'event':
            $statuses = $in['event_status'] === 'attended' ? "('attended')" : ($in['event_status'] === 'registered' ? "('registered')" : "('registered','attended')");
            $s = $pdo->prepare("SELECT m.email, m.full_name, m.lab_name, m.type FROM event_registrations r JOIN members m ON m.id = r.member_id WHERE r.event_id = ? AND r.status IN {$statuses}");
            $s->execute([(int)$in['event_id']]); $rows = $s->fetchAll(); break;
        case 'pending':
            $rows = $pdo->query("SELECT email, full_name, lab_name, type FROM members WHERE status = 'pending'")->fetchAll(); break;
        case 'all_active':
            $rows = $pdo->query("SELECT email, full_name, lab_name, type FROM members WHERE status = 'active'")->fetchAll(); break;
    }
    return array_map(fn($r) => ['email' => $r['email'], 'name' => $nameOf($r) ?: $r['email']], $rows);
}

$in = [
    'mode' => in_array($_REQUEST['mode'] ?? '', COMPOSE_MODES, true) ? $_REQUEST['mode'] : 'raw',
    'raw_emails' => (string)($_REQUEST['raw_emails'] ?? $_GET['to'] ?? ''),
    'section_id' => (string)($_REQUEST['section_id'] ?? ''), 'plan_id' => (string)($_REQUEST['plan_id'] ?? ''),
    'event_id' => (string)($_REQUEST['event_id'] ?? $_GET['event'] ?? ''), 'event_status' => in_array($_REQUEST['event_status'] ?? '', ['registered','attended'], true) ? $_REQUEST['event_status'] : 'all',
    'subject' => (string)($_POST['subject'] ?? ''), 'body' => (string)($_POST['body'] ?? ''),
];
if (isset($_GET['event']) && !isset($_GET['mode'])) $in['mode'] = 'event';

// Live recipient count for the form (fetch).
if (($_GET['count'] ?? '') === '1') {
    header('Content-Type: application/json');
    $r = composeRecipients($pdo, $in);
    echo json_encode(['count' => count($r), 'sample' => array_slice(array_column($r, 'name'), 0, 3)]);
    exit;
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $subject   = clean($in['subject']);
    $bodyMd    = $in['body'];
    $submit    = $_POST['submit_action'] ?? 'preview';
    $recipients = composeRecipients($pdo, $in);
    $bodyHtml = markdownToHtml($bodyMd);

    if (!$subject || !trim($bodyMd)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Add a subject and a message first.'];
    } elseif ($submit === 'test') {
        $memberName = 'there';
        ob_start(); require dirname(__DIR__) . '/emails/admin-message.php'; $emailBody = ob_get_clean();
        $ok = sendEmail(ADMIN_EMAIL, 'RARL Admin', '[TEST] ' . $subject, $emailBody);
        $_SESSION['flash'] = $ok ? ['type' => 'success', 'msg' => 'Test email sent to ' . ADMIN_EMAIL . '.'] : ['type' => 'error', 'msg' => 'The test email could not be sent — check the mail settings.'];
        $result = ['count' => count($recipients)];
    } elseif (!$recipients) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Nobody matches those recipients.'];
    } elseif ($submit === 'send') {
        $sent = 0; $batch = newEmailBatch();
        foreach ($recipients as $r) {
            $memberName = $r['name'] ?: $r['email'];
            ob_start(); require dirname(__DIR__) . '/emails/admin-message.php'; $emailBody = ob_get_clean();
            if (queueEmail($batch, 'Email — ' . $subject, $r['email'], $memberName, $subject, $emailBody)) $sent++;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Queued {$sent} email(s) — sending in the background. Progress shows bottom-right."];
        header('Location: compose-email.php'); exit;
    } else {
        $memberName = $recipients[0]['name'] ?? 'there';
        ob_start(); require dirname(__DIR__) . '/emails/admin-message.php'; $previewHtml = ob_get_clean();
        $result = ['preview' => $previewHtml, 'count' => count($recipients), 'names' => array_slice(array_column($recipients, 'name'), 0, 5)];
    }
}

adminWrap(function() use ($sections, $plans, $events, $result, $in) {
    adminFlash();
    $modes = [
        'raw' => ['fa-at', 'Specific emails'], 'section' => ['fa-sitemap', 'A chapter'], 'plan' => ['fa-graduation-cap', 'A plan tier'],
        'event' => ['fa-calendar-days', 'Event attendees'], 'pending' => ['fa-user-clock', 'Pending applicants'], 'all_active' => ['fa-users', 'All active members'],
    ];
?>
<div class="rarl-page-head">
  <div>
    <h1>Compose email</h1>
    <p>A one-off message to specific people, a chapter, an event's attendees and more. For opted-in broadcasts use <a href="newsletter.php" class="underline">Newsletter</a>.</p>
  </div>
</div>

<form method="POST" id="compose" class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-6 items-start">
  <?= acsrfField() ?>
  <div class="rarl-card p-6 space-y-5">
    <div>
      <label class="rarl-label">To</label>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        <?php foreach ($modes as $k => [$ic, $l]): ?>
        <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer text-xs font-semibold has-[:checked]:border-rarl-red has-[:checked]:bg-rarl-red/5">
          <input type="radio" name="mode" value="<?= $k ?>" <?= $in['mode'] === $k ? 'checked' : '' ?> class="accent-rarl-red"/><i class="fa-solid <?= $ic ?> text-gray-400"></i> <?= $l ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 space-y-2">
        <textarea name="raw_emails" data-for="raw" rows="2" class="rarl-input" placeholder="jane@uni.edu, john@lab.org — commas, spaces or new lines"><?= htmlspecialchars($in['raw_emails']) ?></textarea>
        <select name="section_id" data-for="section" class="rarl-input"><option value="">— Select chapter —</option><?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>" <?= $in['section_id'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select>
        <select name="plan_id" data-for="plan" class="rarl-input"><option value="">— Select plan —</option><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>" <?= $in['plan_id'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?></select>
        <div data-for="event" class="grid grid-cols-[1fr_auto] gap-2">
          <select name="event_id" class="rarl-input"><option value="">— Select event —</option><?php foreach ($events as $e): ?><option value="<?= $e['id'] ?>" <?= $in['event_id'] == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['title']) ?><?= $e['event_date'] ? ' · ' . date('d M Y', strtotime($e['event_date'])) : '' ?></option><?php endforeach; ?></select>
          <select name="event_status" class="rarl-input !w-auto"><option value="all">Everyone registered</option><option value="registered" <?= $in['event_status'] === 'registered' ? 'selected' : '' ?>>Not yet checked in</option><option value="attended" <?= $in['event_status'] === 'attended' ? 'selected' : '' ?>>Attended</option></select>
        </div>
      </div>
      <p class="text-xs text-gray-500 mt-2" id="rcpt"><i class="fa-solid fa-users"></i> <span>—</span></p>
    </div>
    <div><label class="rarl-label">Subject</label><input name="subject" required class="rarl-input" value="<?= htmlspecialchars($in['subject']) ?>"/></div>
    <div>
      <div class="flex items-center justify-between"><label class="rarl-label">Message <span class="font-normal text-gray-400">— Markdown: **bold**, _italic_, [link](https://…), - lists</span></label></div>
      <textarea name="body" required rows="14" class="rarl-input font-mono text-[13px] leading-relaxed"><?= htmlspecialchars($in['body']) ?></textarea>
      <p class="text-[11px] text-gray-400 mt-1">Each email is greeted by the recipient's name automatically.</p>
    </div>
    <div class="flex flex-wrap gap-2 pt-1">
      <button name="submit_action" value="preview" class="rarl-btn rarl-btn-dark"><i class="fa-solid fa-eye"></i> Preview</button>
      <button name="submit_action" value="test" class="rarl-btn" data-no-loading><i class="fa-solid fa-flask"></i> Send test to <?= htmlspecialchars(ADMIN_EMAIL) ?></button>
    </div>
  </div>

  <div class="rarl-card p-6 xl:sticky xl:top-4">
    <?php if (!empty($result['preview'])): ?>
    <div class="flex items-center justify-between mb-3">
      <h2 class="rarl-card-title"><i class="fa-solid fa-eye"></i> Preview</h2>
      <span class="text-xs text-gray-500">as <?= htmlspecialchars($result['names'][0] ?? '') ?> sees it</span>
    </div>
    <div class="border border-gray-200 rounded-xl bg-gray-50 max-h-[60vh] overflow-y-auto p-4"><?= $result['preview'] ?></div>
    <div class="mt-4 p-3 rounded-xl bg-gray-50 text-xs text-gray-600"><strong><?= (int)$result['count'] ?> recipient<?= $result['count'] == 1 ? '' : 's' ?></strong>: <?= htmlspecialchars(implode(', ', $result['names'])) ?><?= $result['count'] > 5 ? ' and ' . ($result['count'] - 5) . ' more' : '' ?></div>
    <button name="submit_action" value="send" data-confirm="Send this email to <?= (int)$result['count'] ?> recipient(s) now?" data-confirm-ok="Send now" class="rarl-btn rarl-btn-primary w-full mt-4 !h-11"><i class="fa-solid fa-paper-plane"></i> Send to <?= (int)$result['count'] ?> recipient<?= $result['count'] == 1 ? '' : 's' ?></button>
    <p class="text-[11px] text-gray-400 mt-2 text-center">Edit on the left and preview again any time — nothing is lost.</p>
    <?php else: ?>
    <div class="rarl-empty"><i class="fa-regular fa-envelope-open text-3xl mb-2 block"></i>Write your message, then <strong>Preview</strong> to see exactly what recipients get before sending.</div>
    <?php endif; ?>
  </div>
</form>
<script>
(function() {
  const f = document.getElementById('compose'), rc = document.querySelector('#rcpt span');
  let t;
  function sync() {
    const mode = f.querySelector('[name=mode]:checked').value;
    f.querySelectorAll('[data-for]').forEach(el => { el.style.display = el.dataset.for === mode ? '' : 'none'; });
    clearTimeout(t); t = setTimeout(async () => {
      const p = new URLSearchParams({count: 1, mode, raw_emails: f.raw_emails.value, section_id: f.section_id.value, plan_id: f.plan_id.value, event_id: f.event_id.value, event_status: f.event_status.value});
      try { const d = await (await fetch('compose-email.php?' + p)).json();
        rc.textContent = d.count ? d.count + ' recipient' + (d.count === 1 ? '' : 's') + ' · ' + d.sample.join(', ') + (d.count > 3 ? '…' : '') : 'No recipients yet'; } catch (e) {}
    }, 300);
  }
  f.addEventListener('change', sync); f.raw_emails.addEventListener('input', sync); sync();
})();
</script>
<?php }, 'compose', 'Compose Email');
