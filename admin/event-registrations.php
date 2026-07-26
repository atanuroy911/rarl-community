<?php
/**
 * RARL Admin — Event Attendees
 * Per-event RSVP list. Marking someone "Attended" auto-issues and emails
 * their certificate via issueCertificateForAttendance() in functions.php —
 * the same PDF/QR pipeline as the manual CSV path in admin/certificates.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$eventId = (int)($_GET['event'] ?? $_POST['event_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_attended') {
        $regId = (int)($_POST['reg_id'] ?? 0);
        $reg = $pdo->prepare("SELECT event_registrations.*, events.title, events.event_date
                               FROM event_registrations JOIN events ON events.id = event_registrations.event_id
                               WHERE event_registrations.id = ?");
        $reg->execute([$regId]);
        $r = $reg->fetch();
        if ($r) {
            $pdo->prepare("UPDATE event_registrations SET status='attended' WHERE id=?")->execute([$regId]);
            $memberStmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
            $memberStmt->execute([$r['member_id']]);
            $member = $memberStmt->fetch();
            if ($member) {
                issueCertificateForAttendance($pdo, ['id' => $r['event_id'], 'title' => $r['title'], 'event_date' => $r['event_date']], $member);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Marked attended — certificate issued and emailed.'];
        }
        header('Location: event-registrations.php?event=' . $eventId); exit;
    }
    if ($action === 'mark_no_show') {
        $pdo->prepare("UPDATE event_registrations SET status='no_show' WHERE id=?")->execute([(int)($_POST['reg_id'] ?? 0)]);
        header('Location: event-registrations.php?event=' . $eventId); exit;
    }
}

$eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();
if (!$event) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Event not found.']; header('Location: events.php'); exit; }

$regs = $pdo->prepare("SELECT event_registrations.*, members.full_name, members.lab_name, members.type, members.email
    FROM event_registrations JOIN members ON members.id = event_registrations.member_id
    WHERE event_registrations.event_id = ?
    ORDER BY FIELD(event_registrations.status,'registered','attended','no_show','cancelled'), event_registrations.registered_at ASC");
$regs->execute([$eventId]);
$registrations = $regs->fetchAll();

adminWrap(function() use ($event, $registrations, $eventId) {
    adminFlash(); ?>
<a href="events.php" class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-rarl-red transition-colors mb-4">← Back to Events</a>
<div class="flex items-center justify-between mb-6">
  <div>
    <h1 class="text-2xl font-black text-gray-900"><?= htmlspecialchars($event['title']) ?></h1>
    <p class="text-gray-500 text-sm mt-0.5"><?= count($registrations) ?> registration(s) <?= $event['capacity'] ? '· capacity ' . (int)$event['capacity'] : '' ?></p>
  </div>
</div>

<div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Member</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Registered</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Status</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($registrations)): ?>
        <tr><td colspan="4" class="py-12 text-center text-gray-400 text-sm">No one has registered yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($registrations as $r):
          $name = $r['type'] === 'lab' ? $r['lab_name'] : $r['full_name'];
          $sc = ['registered'=>'bg-blue-100 text-blue-700','attended'=>'bg-green-100 text-green-700','no_show'=>'bg-gray-100 text-gray-500','cancelled'=>'bg-red-50 text-red-400'][$r['status']] ?? '';
        ?>
        <tr class="hover:bg-gray-50 group">
          <td class="px-4 py-3">
            <p class="font-medium text-xs text-gray-900"><?= htmlspecialchars($name) ?></p>
            <p class="text-[10px] text-gray-400"><?= htmlspecialchars($r['email']) ?></p>
          </td>
          <td class="px-4 py-3 text-[10px] text-gray-400"><?= date('d M Y, H:i', strtotime($r['registered_at'])) ?></td>
          <td class="px-4 py-3"><span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $sc ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          <td class="px-4 py-3">
            <?php if ($r['status'] === 'registered'): ?>
            <div class="flex gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
              <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="mark_attended"><input type="hidden" name="reg_id" value="<?= $r['id'] ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                <button type="submit" class="px-2.5 py-1 text-[10px] font-semibold bg-green-100 text-green-700 hover:bg-green-200 rounded-lg">✓ Attended (issues cert)</button>
              </form>
              <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="mark_no_show"><input type="hidden" name="reg_id" value="<?= $r['id'] ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                <button type="submit" class="px-2.5 py-1 text-[10px] font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 rounded-lg">No-show</button>
              </form>
            </div>
            <?php elseif ($r['status'] === 'attended'): ?>
            <span class="text-[10px] text-green-600">🏆 Certificate issued</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }, 'events', 'Event Attendees');
