<?php
/**
 * RARL Admin — Certificate Management
 * Upload CSV → Generate PDFs → Email → Store in member profiles
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo    = db();
$events = $pdo->query("SELECT * FROM events WHERE is_active = 1 ORDER BY event_date DESC")->fetchAll();

// ── Handle actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';

    // ── Create event ────────────────────────────────────────
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
        header('Location: certificates.php'); exit;
    }

    // ── Issue certificates from CSV ──────────────────────────
    if ($action === 'issue_csv') {
        $eventId    = (int)($_POST['event_id'] ?? 0);
        $sendEmail  = !empty($_POST['send_email']);
        $templateId = (int)($_POST['template_id'] ?? 0) ?: null;

        if (!$eventId || empty($_FILES['csv_file']['tmp_name'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Event and CSV file are required.'];
            header('Location: certificates.php'); exit;
        }

        $ev = $pdo->prepare("SELECT * FROM events WHERE id = ?");
        $ev->execute([$eventId]);
        $event = $ev->fetch();
        if (!$event) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Event not found.']; header('Location: certificates.php'); exit; }

        // Parse CSV
        $csv    = array_map('str_getcsv', file($_FILES['csv_file']['tmp_name']));
        $header = array_map('strtolower', array_map('trim', array_shift($csv)));
        $nameCol  = array_search('name', $header) ?: array_search('full_name', $header);
        $emailCol = array_search('email', $header);

        if ($nameCol === false || $emailCol === false) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'CSV must have "name" and "email" columns.'];
            header('Location: certificates.php'); exit;
        }

        $issued = 0; $skipped = 0;
        foreach ($csv as $row) {
            $name  = clean($row[$nameCol]  ?? '');
            $email = cleanEmail($row[$emailCol] ?? '');
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }

            // Check if already issued
            $dup = $pdo->prepare("SELECT id FROM certificates WHERE event_id = ? AND recipient_email = ?");
            $dup->execute([$eventId, $email]);
            if ($dup->fetch()) { $skipped++; continue; }

            // Find member account
            $mem = $pdo->prepare("SELECT id FROM members WHERE email = ?");
            $mem->execute([$email]);
            $memberId = ($mem->fetch() ?: ['id' => null])['id'];

            $uuid    = generateUuid();
            $certNo  = nextCertNumber();
            $pdfPath = null;

            // Generate PDF (using basic built-in if FPDF not available)
            $certDir = UPLOADS_PATH . '/certificates/';
            if (!is_dir($certDir)) mkdir($certDir, 0755, true);

            $pdfFile = 'cert_' . str_replace('-', '', $uuid) . '.pdf';
            $pdfFull = $certDir . $pdfFile;

            if (file_exists(dirname(__DIR__) . '/libs/fpdf/fpdf.php')) {
                require_once dirname(__DIR__) . '/libs/fpdf/fpdf.php';
                generateCertPDF($pdfFull, $name, $event['title'], $certNo, $event['event_date'] ?? date('Y-m-d'), $uuid);
                $pdfPath = $pdfFile;
            }

            // Insert certificate record
            $pdo->prepare("INSERT INTO certificates (uuid, certificate_no, member_id, recipient_name, recipient_email, event_id, template_id, pdf_path)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$uuid, $certNo, $memberId, $name, $email, $eventId, $templateId, $pdfPath]);

            // Send email if requested
            if ($sendEmail) {
                $verifyUrl  = CERT_VERIFY_URL . '?id=' . $uuid;
                $eventTitle = $event['title'];
                $eventDate  = $event['event_date'] ? date('d F Y', strtotime($event['event_date'])) : date('d F Y');
                $certNumber = $certNo;
                $memberName = $name;
                ob_start(); require dirname(__DIR__) . '/emails/certificate.php'; $body = ob_get_clean();
                sendEmail($email, $name, 'Your RARL Certificate — ' . $event['title'], $body);
                $pdo->prepare("UPDATE certificates SET emailed_at = NOW() WHERE uuid = ?")->execute([$uuid]);
            }

            $issued++;
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>"Issued {$issued} certificates. Skipped {$skipped} (duplicates or invalid rows)."];
        header('Location: certificates.php'); exit;
    }

    // ── Delete certificate ───────────────────────────────────
    if ($action === 'delete_cert') {
        $cid = (int)($_POST['cert_id'] ?? 0);
        $row = $pdo->prepare("SELECT pdf_path FROM certificates WHERE id = ?");
        $row->execute([$cid]);
        $c = $row->fetch();
        if ($c && $c['pdf_path']) @unlink(UPLOADS_PATH . '/certificates/' . $c['pdf_path']);
        $pdo->prepare("DELETE FROM certificates WHERE id = ?")->execute([$cid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Certificate deleted.'];
        header('Location: certificates.php'); exit;
    }
}

// ── List all certificates ──────────────────────────────────
$filterEvent = (int)($_GET['event'] ?? 0);
$search      = trim($_GET['q'] ?? '');
$where = []; $params = [];
if ($filterEvent) { $where[] = 'c.event_id = ?'; $params[] = $filterEvent; }
if ($search) {
    $where[] = '(c.recipient_name LIKE ? OR c.recipient_email LIKE ? OR c.certificate_no LIKE ?)';
    $params  = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
$ws   = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT c.*, e.title as event_title, e.event_date FROM certificates c LEFT JOIN events e ON c.event_id = e.id {$ws} ORDER BY c.issued_at DESC LIMIT 200");
$stmt->execute($params);
$certificates = $stmt->fetchAll();
$templates    = $pdo->query("SELECT * FROM certificate_templates ORDER BY id")->fetchAll();

// generateCertPDF() now lives in functions.php (shared with the
// auto-issue-on-attendance flow in admin/event-registrations.php).

adminWrap(function() use ($events, $certificates, $templates, $filterEvent, $search, $pdo) {
    adminFlash(); ?>

<div class="flex items-center justify-between mb-6">
  <div><h1 class="text-2xl font-black text-gray-900">Certificates</h1><p class="text-gray-500 text-sm mt-0.5">Issue, manage, and distribute event certificates</p></div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- ── Issue Certificates panel ── -->
  <div class="xl:col-span-1 space-y-5">

    <!-- Issue from CSV -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-4">📤 Issue from CSV</h2>
      <form method="POST" enctype="multipart/form-data" class="space-y-3">
        <?= acsrfField() ?><input type="hidden" name="action" value="issue_csv">

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Event <span class="text-rarl-red">*</span></label>
          <select name="event_id" required class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <option value="">— Select event —</option>
            <?php foreach ($events as $e): ?>
            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['title']) ?> <?= $e['event_date'] ? '(' . date('d M Y', strtotime($e['event_date'])) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($templates): ?>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Template <span class="text-gray-400 font-normal">(optional)</span></label>
          <select name="template_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <option value="">Default template</option>
            <?php foreach ($templates as $t): ?>
            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Participant CSV <span class="text-rarl-red">*</span></label>
          <div class="border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-5 text-center transition-colors cursor-pointer relative">
            <div class="text-2xl mb-1">📎</div>
            <p class="text-xs text-gray-500 mb-0.5">Upload CSV with <strong>name</strong> and <strong>email</strong> columns</p>
            <p class="text-[10px] text-gray-400">Max 500 rows</p>
            <input type="file" name="csv_file" accept=".csv" required class="absolute inset-0 opacity-0 cursor-pointer w-full h-full"/>
          </div>
        </div>

        <label class="flex items-center gap-2.5 p-3 bg-blue-50 border border-blue-200 rounded-xl cursor-pointer text-sm">
          <input type="checkbox" name="send_email" value="1" checked class="w-4 h-4 accent-rarl-red"/>
          <div>
            <span class="font-semibold text-gray-800">Email certificates</span>
            <p class="text-xs text-gray-500">Send PDF to each participant</p>
          </div>
        </label>

        <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl transition-all shadow hover:-translate-y-0.5">
          🏆 Generate & Issue
        </button>
      </form>

      <div class="mt-4 pt-4 border-t border-gray-100">
        <p class="text-xs font-semibold text-gray-500 mb-2">CSV Template:</p>
        <code class="block text-[10px] bg-gray-50 border border-gray-200 rounded-lg p-2 text-gray-600">name,email<br>Dr. Jane Smith,jane@uni.edu<br>John Doe,john@lab.org</code>
        <a href="data:text/csv;charset=utf-8,name%2Cemail%0ADr.%20Jane%20Smith%2Cjane%40university.edu" download="rarl_cert_template.csv"
          class="mt-2 block text-xs text-center text-rarl-red hover:underline">⬇ Download CSV Template</a>
      </div>
    </div>

    <!-- Create event -->
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
        <textarea name="ev_desc" rows="2" placeholder="Description (optional)" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-gray-50 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-none"></textarea>
        <button type="submit" class="w-full py-2.5 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-xl transition-colors">+ Create Event</button>
      </form>
    </div>
  </div>

  <!-- ── Certificates Table ── -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between">
        <h2 class="font-heading font-bold text-sm text-gray-800">All Certificates <span class="text-gray-400 font-normal text-xs">(<?= count($certificates) ?>)</span></h2>
        <form method="GET" class="flex gap-2 flex-wrap">
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name / email…"
            class="px-3 py-2 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
          <select name="event" class="px-3 py-2 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
            <option value="">All events</option>
            <?php foreach ($events as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $filterEvent === (int)$e['id'] ? 'selected':'' ?>><?= htmlspecialchars(mb_strimwidth($e['title'],0,35,'…')) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="px-4 py-2 bg-rarl-red text-white text-xs font-semibold rounded-lg hover:bg-rarl-dark">Filter</button>
        </form>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Recipient</th>
              <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Certificate</th>
              <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Event</th>
              <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Date</th>
              <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if (empty($certificates)): ?>
            <tr><td colspan="5" class="py-12 text-center text-gray-400 text-sm">No certificates yet. Upload a CSV to get started.</td></tr>
            <?php endif; ?>
            <?php foreach ($certificates as $c): ?>
            <tr class="hover:bg-gray-50 group">
              <td class="px-4 py-3">
                <p class="font-medium text-gray-900 text-xs"><?= htmlspecialchars($c['recipient_name']) ?></p>
                <p class="text-[10px] text-gray-400"><?= htmlspecialchars($c['recipient_email']) ?></p>
              </td>
              <td class="px-4 py-3">
                <span class="font-mono text-[10px] text-gray-600 bg-gray-100 px-2 py-0.5 rounded"><?= htmlspecialchars($c['certificate_no']) ?></span>
                <?php if ($c['emailed_at']): ?><span class="block text-[9px] text-green-500 mt-0.5">✉ Emailed</span><?php endif; ?>
              </td>
              <td class="px-4 py-3 text-[10px] text-gray-600"><?= htmlspecialchars(mb_strimwidth($c['event_title']??'',0,30,'…')) ?></td>
              <td class="px-4 py-3 text-[10px] text-gray-400"><?= date('d M Y', strtotime($c['issued_at'])) ?></td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                  <a href="../verify.php?id=<?= urlencode($c['uuid']) ?>" target="_blank"
                    class="px-2 py-1 text-[10px] font-semibold bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg transition-colors">Verify</a>
                  <?php if ($c['pdf_path']): ?>
                  <a href="../uploads/certificates/<?= urlencode($c['pdf_path']) ?>" target="_blank"
                    class="px-2 py-1 text-[10px] font-semibold bg-green-50 text-green-600 hover:bg-green-100 rounded-lg transition-colors">PDF</a>
                  <?php endif; ?>
                  <form method="POST" class="inline" onsubmit="return confirm('Delete this certificate?')">
                    <?= acsrfField() ?><input type="hidden" name="action" value="delete_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="px-2 py-1 text-[10px] font-semibold bg-red-50 text-red-600 hover:bg-red-100 rounded-lg transition-colors">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php }, 'certificates', 'Certificates');
