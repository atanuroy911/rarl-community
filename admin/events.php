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
        $title = clean($_POST['ev_title'] ?? '');
        $type  = clean($_POST['ev_type']  ?? 'other');
        $date  = clean($_POST['ev_date']  ?? '');
        $desc  = clean($_POST['ev_desc']  ?? '');
        if ($title) {
            $pdo->prepare("INSERT INTO events (title, type, event_date, description) VALUES (?,?,?,?)")
                ->execute([$title, $type, $date ?: null, $desc]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Event created.'];
        }
        header('Location: events.php'); exit;
    }
    if ($action === 'toggle_active') {
        $pdo->prepare("UPDATE events SET is_active = 1 - is_active WHERE id=?")->execute([(int)($_POST['ev_id']??0)]);
        header('Location: events.php'); exit;
    }
    if ($action === 'delete_event') {
        $pdo->prepare("DELETE FROM events WHERE id=?")->execute([(int)($_POST['ev_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Event deleted.'];
        header('Location: events.php'); exit;
    }
}

$events = $pdo->query("SELECT e.*, (SELECT COUNT(*) FROM certificates c WHERE c.event_id = e.id) as cert_count FROM events e ORDER BY e.event_date DESC, e.created_at DESC")->fetchAll();

adminWrap(function() use ($events) {
    adminFlash(); ?>
<div class="flex items-center justify-between mb-6">
  <div><h1 class="text-2xl font-black text-gray-900">Events</h1><p class="text-gray-500 text-sm">Manage workshops, webinars, and conferences</p></div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Create Event -->
  <div class="xl:col-span-1">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-4">📅 Create New Event</h2>
      <form method="POST" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="create_event">
        <input type="text" name="ev_title" required placeholder="Event title" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <div class="grid grid-cols-2 gap-2">
          <select name="ev_type" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <?php foreach (['workshop','webinar','hackathon','competition','volunteer','conference','seminar','other'] as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="ev_date" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <textarea name="ev_desc" rows="3" placeholder="Description (optional)" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"></textarea>
        <button type="submit" class="w-full py-2.5 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-xl transition-colors">+ Create Event</button>
      </form>
    </div>
  </div>

  <!-- Events List -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Events</h2>
      </div>
      <div class="divide-y divide-gray-100">
        <?php if (empty($events)): ?>
        <p class="p-8 text-center text-gray-400 text-sm">No events created yet.</p>
        <?php endif; ?>
        <?php foreach ($events as $e): ?>
        <div class="p-5 flex items-center justify-between hover:bg-gray-50 group">
          <div>
            <div class="flex items-center gap-2 mb-1">
              <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500 bg-gray-100 px-2 py-0.5 rounded"><?= ucfirst($e['type']) ?></span>
              <span class="text-[10px] text-gray-400 font-semibold"><?= $e['event_date'] ? date('d M Y', strtotime($e['event_date'])) : 'No date' ?></span>
              <?php if (!$e['is_active']): ?><span class="text-[10px] font-bold text-red-500 bg-red-50 px-2 py-0.5 rounded">Inactive</span><?php endif; ?>
            </div>
            <h3 class="font-semibold text-sm text-gray-900 mb-1"><?= htmlspecialchars($e['title']) ?></h3>
            <p class="text-xs text-gray-500 max-w-md truncate"><?= htmlspecialchars($e['description']) ?></p>
            <p class="text-[10px] text-rarl-red font-semibold mt-1">🏆 <?= $e['cert_count'] ?> certificates issued</p>
          </div>
          <div class="flex flex-col gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
            <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>">
              <button class="px-3 py-1 text-[10px] font-semibold <?= $e['is_active'] ? 'bg-amber-50 text-amber-600' : 'bg-green-50 text-green-600' ?> rounded-lg w-full text-left"><?= $e['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete event? This will orphan any certificates attached to it.')"><?= acsrfField() ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="ev_id" value="<?= $e['id'] ?>">
              <button class="px-3 py-1 text-[10px] font-semibold bg-red-50 text-red-600 rounded-lg w-full text-left">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?php }, 'events', 'Events');
