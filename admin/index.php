<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo = db();
$s   = $pdo->query("SELECT
  COUNT(*) total,
  SUM(status='active') active,
  SUM(status='pending') pending,
  SUM(type='individual') individuals,
  SUM(type='lab') labs,
  SUM(newsletter_opt_in=1) newsletter
 FROM members")->fetch();
$certCount = $pdo->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
$nlCount   = $pdo->query("SELECT COUNT(*) FROM newsletters WHERE status='sent'")->fetchColumn();
$eventCount= $pdo->query("SELECT COUNT(*) FROM events WHERE is_active=1")->fetchColumn();
$recentMembers = $pdo->query("SELECT id, full_name, lab_name, type, email, status, created_at FROM members ORDER BY created_at DESC LIMIT 8")->fetchAll();
$recentCerts   = $pdo->query("SELECT c.certificate_no, c.recipient_name, c.issued_at, e.title as event_title FROM certificates c LEFT JOIN events e ON c.event_id = e.id ORDER BY c.issued_at DESC LIMIT 5")->fetchAll();

adminWrap(function() use ($s, $certCount, $nlCount, $eventCount, $recentMembers, $recentCerts) {
    adminFlash(); ?>
<div class="flex items-center justify-between mb-7">
  <div><h1 class="text-2xl font-black text-gray-900">Dashboard</h1><p class="text-gray-500 text-sm mt-0.5">RARL Community Platform overview</p></div>
  <a href="members.php?status=pending" class="inline-flex items-center gap-2 px-4 py-2 bg-rarl-red text-white text-sm font-semibold rounded-xl hover:bg-rarl-dark transition-colors shadow">
    <i class="fa-solid fa-hourglass-half"></i> <?= (int)$s['pending'] ?> Pending Approvals
  </a>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-7">
  <?php
  statCard('Total Members', (int)$s['total'], '<i class="fa-solid fa-users"></i>', 'blue', 'members.php');
  statCard('Active Members', (int)$s['active'], '<i class="fa-solid fa-circle-check"></i>', 'green', 'members.php?status=active');
  statCard('Newsletter Subscribers', (int)$s['newsletter'], '<i class="fa-solid fa-envelope"></i>', 'amber', 'newsletter.php');
  statCard('Certificates Issued', (int)$certCount, '<i class="fa-solid fa-trophy"></i>', 'purple', 'certificates.php');
  ?>
</div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <?php
  statCard('Individuals', (int)$s['individuals'], '<i class="fa-solid fa-user-graduate"></i>', 'blue', 'members.php?type=individual');
  statCard('Research Labs', (int)$s['labs'], '<i class="fa-solid fa-building-columns"></i>', 'blue', 'members.php?type=lab');
  statCard('Active Events', (int)$eventCount, '<i class="fa-solid fa-calendar-days"></i>', 'green', 'events.php');
  statCard('Newsletters Sent', (int)$nlCount, '<i class="fa-solid fa-paper-plane"></i>', 'gray', 'newsletter.php');
  ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <!-- Recent Members -->
  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h2 class="font-heading font-bold text-sm text-gray-800">Recent Registrations</h2>
      <a href="members.php" class="text-xs text-rarl-red hover:underline">View all →</a>
    </div>
    <div class="divide-y divide-gray-100">
      <?php foreach ($recentMembers as $m):
        $name = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
        $sc   = ['active'=>'bg-green-100 text-green-700','pending'=>'bg-amber-100 text-amber-700','inactive'=>'bg-gray-100 text-gray-500'][$m['status']] ?? 'bg-gray-100 text-gray-500';
      ?>
      <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center text-xs font-bold text-gray-600"><?= strtoupper(substr($name,0,1)) ?></div>
          <div><p class="text-sm font-medium text-gray-800"><?= htmlspecialchars($name) ?></p><p class="text-[10px] text-gray-400"><?= $m['type'] === 'lab' ? '<i class="fa-solid fa-building-columns"></i> Lab' : '<i class="fa-solid fa-user-graduate"></i> Researcher' ?> · <?= date('d M', strtotime($m['created_at'])) ?></p></div>
        </div>
        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $sc ?>"><?= ucfirst($m['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Recent Certificates -->
  <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <h2 class="font-heading font-bold text-sm text-gray-800">Recent Certificates</h2>
      <a href="certificates.php" class="text-xs text-rarl-red hover:underline">View all →</a>
    </div>
    <div class="divide-y divide-gray-100">
      <?php if (empty($recentCerts)): ?>
      <p class="px-5 py-8 text-center text-gray-400 text-sm">No certificates issued yet.</p>
      <?php endif; ?>
      <?php foreach ($recentCerts as $c): ?>
      <div class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50">
        <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center text-sm"><i class="fa-solid fa-trophy"></i></div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($c['recipient_name']) ?></p>
          <p class="text-[10px] text-gray-400"><?= htmlspecialchars($c['certificate_no']) ?> · <?= htmlspecialchars(mb_strimwidth($c['event_title'] ?? '', 0, 30, '…')) ?></p>
        </div>
        <span class="text-[10px] text-gray-400 flex-shrink-0"><?= date('d M', strtotime($c['issued_at'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="p-4 text-center">
      <a href="certificates.php" class="text-sm font-semibold text-white bg-rarl-red hover:bg-rarl-dark px-5 py-2 rounded-xl transition-colors inline-block">Issue Certificates</a>
    </div>
  </div>
</div>

<?php }, 'index', 'Dashboard');
