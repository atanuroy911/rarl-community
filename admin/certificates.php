<?php
/**
 * RARL Admin — Certificate Management
 * Issue (CSV or single) → Generate PDFs → Email → Store in member profiles
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo    = db();
$events = $pdo->query("SELECT * FROM events WHERE is_active = 1 ORDER BY event_date DESC")->fetchAll();
const CERT_PER_PAGE = 50;

// Accepted header spellings, so spreadsheets exported from Google Forms /
// Excel / registration tools work without renaming columns first.
const CERT_NAME_HEADERS  = ['name', 'full_name', 'full name', 'fullname', 'participant', 'participant name', 'recipient', 'recipient name'];
const CERT_EMAIL_HEADERS = ['email', 'e-mail', 'email address', 'e-mail address', 'mail'];

function certFindColumn(array $header, array $candidates): ?int {
    foreach ($candidates as $c) {
        $i = array_search($c, $header, true);
        if ($i !== false) return (int)$i;
    }
    return null;
}

function certTemplateFor(PDO $pdo, ?int $templateId): ?array {
    if ($templateId) {
        $t = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ? AND type = 'certificate'");
        $t->execute([$templateId]);
        if ($row = $t->fetch()) return $row;
    }
    return getDefaultTemplate('certificate');
}

// Writes the PDF for an event certificate; returns the stored filename or null.
function certWritePdf(?array $template, string $uuid, string $name, array $event, string $certNo): ?string {
    $certDir = UPLOADS_PATH . '/certificates/';
    if (!is_dir($certDir)) mkdir($certDir, 0755, true);
    $pdfFile = 'cert_' . str_replace('-', '', $uuid) . '.pdf';
    $eventDate = !empty($event['event_date']) ? date('d F Y', strtotime($event['event_date'])) : date('d F Y');
    if ($template) {
        $ok = renderTemplatePdf($template, [
            'name' => $name, 'event' => $event['title'] ?? '', 'cert_no' => $certNo, 'date' => $eventDate,
            'verify_url' => CERT_VERIFY_URL . '?id=' . $uuid,
        ], $certDir . $pdfFile);
        return $ok ? $pdfFile : null;
    }
    if (file_exists(dirname(__DIR__) . '/libs/fpdf/fpdf.php')) {
        require_once dirname(__DIR__) . '/libs/fpdf/fpdf.php';
        generateCertPDF($certDir . $pdfFile, $name, $event['title'] ?? '', $certNo, $event['event_date'] ?? date('Y-m-d'), $uuid);
        return $pdfFile;
    }
    return null;
}

// With $batch set, the email is queued (sent in the background) instead of sent inline.
function certSendEmail(PDO $pdo, array $c, ?string $batch = null): void {
    $verifyUrl  = CERT_VERIFY_URL . '?id=' . $c['uuid'];
    $eventTitle = $c['event_title'] ?? 'RARL';
    $eventDate  = !empty($c['event_date']) ? date('d F Y', strtotime($c['event_date'])) : date('d F Y');
    $certNumber = $c['certificate_no'];
    $memberName = $c['recipient_name'];
    ob_start(); require dirname(__DIR__) . '/emails/certificate.php'; $body = ob_get_clean();
    if ($batch) {
        queueEmail($batch, 'Certificates — ' . $eventTitle, $c['recipient_email'], $c['recipient_name'], 'Your RARL Certificate — ' . $eventTitle, $body, [], 'cert:' . (int)$c['id']);
        return;
    }
    if (sendEmail($c['recipient_email'], $c['recipient_name'], 'Your RARL Certificate — ' . $eventTitle, $body)) {
        $pdo->prepare("UPDATE certificates SET emailed_at = NOW() WHERE id = ?")->execute([$c['id']]);
    }
}

// Issues one event certificate. Returns 'issued', 'duplicate' or 'invalid'.
function certIssue(PDO $pdo, array $event, string $name, string $email, ?int $templateId, bool $send, ?string $batch = null): string {
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'invalid';
    $dup = $pdo->prepare("SELECT id FROM certificates WHERE event_id = ? AND recipient_email = ?");
    $dup->execute([$event['id'], $email]);
    if ($dup->fetch()) return 'duplicate';

    $mem = $pdo->prepare("SELECT id FROM members WHERE email = ?");
    $mem->execute([$email]);
    $memberId = ($mem->fetch() ?: ['id' => null])['id'];

    $uuid    = generateUuid();
    $certNo  = nextCertNumber();
    $pdfPath = certWritePdf(certTemplateFor($pdo, $templateId), $uuid, $name, $event, $certNo);

    $pdo->prepare("INSERT INTO certificates (uuid, certificate_no, member_id, recipient_name, recipient_email, event_id, template_id, pdf_path)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$uuid, $certNo, $memberId, $name, $email, $event['id'], $templateId, $pdfPath]);

    if ($send) {
        certSendEmail($pdo, ['id' => $pdo->lastInsertId(), 'uuid' => $uuid, 'certificate_no' => $certNo, 'recipient_name' => $name,
            'recipient_email' => $email, 'event_title' => $event['title'], 'event_date' => $event['event_date']], $batch);
    }
    return 'issued';
}

function certFetch(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT c.*, e.title as event_title, e.event_date FROM certificates c LEFT JOIN events e ON c.event_id=e.id WHERE c.id IN ({$in})");
    $s->execute($ids);
    return $s->fetchAll();
}

// Rebuilds an event certificate PDF from its (or the current default) template —
// e.g. after fixing a typo in the design. Membership certificates are skipped.
function certRegenerate(PDO $pdo, array $c): bool {
    if (($c['cert_type'] ?? 'event') === 'membership' || empty($c['event_id'])) return false;
    $pdf = certWritePdf(certTemplateFor($pdo, $c['template_id'] ? (int)$c['template_id'] : null), $c['uuid'], $c['recipient_name'],
        ['title' => $c['event_title'], 'event_date' => $c['event_date']], $c['certificate_no']);
    if (!$pdf) return false;
    $pdo->prepare("UPDATE certificates SET pdf_path = ? WHERE id = ?")->execute([$pdf, $c['id']]);
    return true;
}

// ── Filters (shared by the list and CSV export) ─────────────
$filterEvent  = (int)($_GET['event'] ?? 0);
$filterStatus = in_array($_GET['status'] ?? '', ['emailed','unsent','membership','nopdf'], true) ? $_GET['status'] : '';
$search       = trim($_GET['q'] ?? '');
$where = []; $params = [];
if ($filterEvent) { $where[] = 'c.event_id = ?'; $params[] = $filterEvent; }
if ($filterStatus === 'emailed')    $where[] = 'c.emailed_at IS NOT NULL';
if ($filterStatus === 'unsent')     $where[] = 'c.emailed_at IS NULL';
if ($filterStatus === 'membership') $where[] = "c.cert_type = 'membership'";
if ($filterStatus === 'nopdf')      $where[] = 'c.pdf_path IS NULL';
if ($search) {
    $where[] = '(c.recipient_name LIKE ? OR c.recipient_email LIKE ? OR c.certificate_no LIKE ?)';
    $params  = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
$ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT c.*, e.title as event_title FROM certificates c LEFT JOIN events e ON c.event_id = e.id {$ws} ORDER BY c.issued_at DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rarl-certificates-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['certificate_no', 'name', 'email', 'type', 'event', 'issued_at', 'emailed_at', 'verify_url']);
    while ($r = $stmt->fetch()) {
        fputcsv($out, [$r['certificate_no'], $r['recipient_name'], $r['recipient_email'], $r['cert_type'] ?? 'event', $r['event_title'], $r['issued_at'], $r['emailed_at'], CERT_VERIFY_URL . '?id=' . $r['uuid']]);
    }
    exit;
}

// ── Handle actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $back = 'certificates.php' . (!empty($_POST['return_qs']) ? '?' . preg_replace('/[^\w=&%.+-]/', '', $_POST['return_qs']) : '');

    if ($action === 'create_event') {
        $title = clean($_POST['ev_title'] ?? '');
        $type  = clean($_POST['ev_type']  ?? 'other');
        $date  = clean($_POST['ev_date']  ?? '');
        $desc  = clean($_POST['ev_desc']  ?? '');
        if ($title) {
            $pdo->prepare("INSERT INTO events (title, type, event_date, description) VALUES (?,?,?,?)")
                ->execute([$title, $type, $date ?: null, $desc]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Event “' . $title . '” created.'];
            $_SESSION['cert_last_event'] = (int)$pdo->lastInsertId();
        }
        header('Location: certificates.php'); exit;
    }

    if ($action === 'issue_csv' || $action === 'issue_single') {
        $eventId    = (int)($_POST['event_id'] ?? 0);
        $sendEmail  = !empty($_POST['send_email']);
        $templateId = (int)($_POST['template_id'] ?? 0) ?: null;
        $ev = $pdo->prepare("SELECT * FROM events WHERE id = ?");
        $ev->execute([$eventId]);
        $event = $ev->fetch();
        if (!$event) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Please choose an event.']; header('Location: certificates.php'); exit; }
        $_SESSION['cert_last_event'] = $eventId;

        if ($action === 'issue_single') {
            $r = certIssue($pdo, $event, clean($_POST['name'] ?? ''), cleanEmail($_POST['email'] ?? ''), $templateId, $sendEmail);
            $_SESSION['flash'] = match ($r) {
                'issued'    => ['type'=>'success','msg'=>'Certificate issued' . ($sendEmail ? ' and emailed.' : '.')],
                'duplicate' => ['type'=>'error','msg'=>'This email already has a certificate for that event.'],
                default     => ['type'=>'error','msg'=>'Enter a name and a valid email address.'],
            };
            header('Location: certificates.php'); exit;
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Choose a CSV file to upload.'];
            header('Location: certificates.php'); exit;
        }
        $lines  = file($_FILES['csv_file']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($lines) $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]); // Excel UTF-8 BOM
        $delim  = $lines && substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $csv    = array_map(fn($l) => str_getcsv($l, $delim), $lines);
        $header = array_map(fn($h) => strtolower(trim((string)$h)), array_shift($csv) ?? []);
        $nameCol  = certFindColumn($header, CERT_NAME_HEADERS);
        $emailCol = certFindColumn($header, CERT_EMAIL_HEADERS);
        if ($nameCol === null || $emailCol === null) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'CSV needs a "name" and an "email" column (found: ' . implode(', ', array_filter($header)) . ').'];
            header('Location: certificates.php'); exit;
        }
        @set_time_limit(0);
        $counts = ['issued' => 0, 'duplicate' => 0, 'invalid' => 0];
        $batch = $sendEmail ? newEmailBatch() : null;
        foreach (array_slice($csv, 0, 500) as $row) {
            $counts[certIssue($pdo, $event, clean($row[$nameCol] ?? ''), cleanEmail($row[$emailCol] ?? ''), $templateId, $sendEmail, $batch)]++;
        }
        $extra = count($csv) > 500 ? ' Only the first 500 rows were processed.' : '';
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Issued {$counts['issued']} certificate(s)" . ($sendEmail ? ' — emails are sending in the background' : '') . ". Skipped {$counts['duplicate']} already issued and {$counts['invalid']} invalid row(s).{$extra}"];
        header('Location: certificates.php?event=' . $eventId); exit;
    }

    if ($action === 'email_cert') {
        foreach (certFetch($pdo, [$_POST['cert_id'] ?? 0]) as $c) {
            if (!$c['pdf_path']) { $_SESSION['flash'] = ['type'=>'error','msg'=>'This certificate has no PDF yet — regenerate it first.']; break; }
            certSendEmail($pdo, $c);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Certificate emailed to ' . $c['recipient_email'] . '.'];
        }
        header('Location: ' . $back); exit;
    }

    if ($action === 'regen_cert') {
        $c = certFetch($pdo, [$_POST['cert_id'] ?? 0])[0] ?? null;
        $_SESSION['flash'] = $c && certRegenerate($pdo, $c)
            ? ['type'=>'success','msg'=>'PDF regenerated with the current template.']
            : ['type'=>'error','msg'=>'Could not regenerate this certificate (membership certificates are regenerated from the member page).'];
        header('Location: ' . $back); exit;
    }

    if ($action === 'delete_cert') {
        foreach (certFetch($pdo, [$_POST['cert_id'] ?? 0]) as $c) {
            if ($c['pdf_path']) @unlink(UPLOADS_PATH . '/certificates/' . basename($c['pdf_path']));
            $pdo->prepare("DELETE FROM certificates WHERE id = ?")->execute([$c['id']]);
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Certificate deleted.'];
        header('Location: ' . $back); exit;
    }

    if ($action === 'bulk') {
        $rows = certFetch($pdo, $_POST['ids'] ?? []);
        $bulkOp = $_POST['bulk_op'] ?? '';
        @set_time_limit(0);
        if (!$rows) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one certificate first.'];
        } elseif ($bulkOp === 'delete') {
            foreach ($rows as $r) {
                if ($r['pdf_path']) @unlink(UPLOADS_PATH . '/certificates/' . basename($r['pdf_path']));
                $pdo->prepare("DELETE FROM certificates WHERE id = ?")->execute([$r['id']]);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>count($rows) . ' certificate(s) deleted.'];
        } elseif ($bulkOp === 'send' || $bulkOp === 'resend') {
            $sent = 0; $batch = newEmailBatch();
            foreach ($rows as $c) {
                if (!$c['pdf_path'] || ($bulkOp === 'send' && $c['emailed_at'])) continue;
                certSendEmail($pdo, $c, $batch); $sent++;
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Queued {$sent} certificate email(s) — sending in the background." . ($bulkOp === 'send' && $sent < count($rows) ? ' Already-sent ones were skipped.' : '')];
        } elseif ($bulkOp === 'regen') {
            $done = 0;
            foreach ($rows as $c) if (certRegenerate($pdo, $c)) $done++;
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Regenerated {$done} PDF(s)" . ($done < count($rows) ? ' (membership certificates skipped).' : '.')];
        }
        header('Location: ' . $back); exit;
    }
}

// ── List ───────────────────────────────────────────────────
$page  = max(1, (int)($_GET['page'] ?? 1));
$cnt   = $pdo->prepare("SELECT COUNT(*) FROM certificates c {$ws}"); $cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / CERT_PER_PAGE));
$page  = min($page, $pages);
$stmt  = $pdo->prepare("SELECT c.*, e.title as event_title, e.event_date FROM certificates c LEFT JOIN events e ON c.event_id = e.id {$ws} ORDER BY c.issued_at DESC LIMIT " . CERT_PER_PAGE . " OFFSET " . (($page - 1) * CERT_PER_PAGE));
$stmt->execute($params);
$certificates = $stmt->fetchAll();
$templates    = $pdo->query("SELECT * FROM certificate_templates WHERE type = 'certificate' ORDER BY is_default DESC, id DESC")->fetchAll();
$stats = $pdo->query("SELECT COUNT(*) total, SUM(emailed_at IS NOT NULL) emailed, SUM(emailed_at IS NULL) unsent,
    SUM(issued_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) month FROM certificates")->fetch();
$lastEvent = (int)($_SESSION['cert_last_event'] ?? 0);
$qs = http_build_query(array_filter(['q' => $search, 'event' => $filterEvent ?: null, 'status' => $filterStatus, 'page' => $page > 1 ? $page : null]));

adminWrap(function() use ($events, $certificates, $templates, $filterEvent, $filterStatus, $search, $stats, $total, $page, $pages, $lastEvent, $qs) {
    adminFlash();
    $input = 'w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red';
    $pageUrl = fn(array $over) => 'certificates.php?' . http_build_query(array_filter(array_merge(['q' => $search, 'event' => $filterEvent ?: null, 'status' => $filterStatus], $over)));
    $defaultTpl = null; foreach ($templates as $t) if ($t['is_default']) $defaultTpl = $t;
?>

<div class="flex flex-wrap items-end justify-between gap-3 mb-6">
  <div><h1 class="text-2xl font-black text-gray-900">Certificates</h1><p class="text-gray-500 text-sm mt-0.5">Issue, manage, and distribute event certificates</p></div>
  <div class="flex gap-2">
    <a href="templates.php" class="px-4 py-2 bg-white border border-gray-200 hover:border-gray-400 text-sm font-semibold rounded-xl"><i class="fa-solid fa-pen-ruler"></i> Design templates</a>
    <a href="<?= htmlspecialchars($pageUrl(['export' => 'csv'])) ?>" class="px-4 py-2 bg-white border border-gray-200 hover:border-gray-400 text-sm font-semibold rounded-xl"><i class="fa-solid fa-file-csv"></i> Export</a>
  </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php foreach ([
      ['Total issued', (int)$stats['total'], 'fa-trophy', 'text-gray-900', ''],
      ['Emailed', (int)$stats['emailed'], 'fa-envelope-circle-check', 'text-green-600', 'emailed'],
      ['Not sent yet', (int)$stats['unsent'], 'fa-clock', 'text-amber-600', 'unsent'],
      ['This month', (int)$stats['month'], 'fa-calendar-check', 'text-blue-600', ''],
  ] as [$label, $val, $icon, $tone, $st]): ?>
  <a href="<?= $st ? htmlspecialchars($pageUrl(['status' => $st, 'page' => null])) : 'certificates.php' ?>" class="bg-white border <?= $st && $filterStatus === $st ? 'border-rarl-red ring-2 ring-rarl-red/15' : 'border-gray-200' ?> rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between"><span class="text-xs font-semibold text-gray-500"><?= $label ?></span><i class="fa-solid <?= $icon ?> <?= $tone ?> opacity-70"></i></div>
    <div class="font-heading font-black text-2xl mt-1 <?= $tone ?>"><?= number_format($val) ?></div>
  </a>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[320px_minmax(0,1fr)] 2xl:grid-cols-[360px_minmax(0,1fr)] gap-6">
  <!-- ── Issue panel ── -->
  <div>
    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm xl:sticky xl:top-4">
      <div class="flex border-b border-gray-100 p-1.5 gap-1" role="tablist">
        <button type="button" class="issue-tab flex-1 py-2 text-xs font-semibold rounded-lg bg-gray-900 text-white" data-tab="csv"><i class="fa-solid fa-file-csv"></i> From CSV</button>
        <button type="button" class="issue-tab flex-1 py-2 text-xs font-semibold rounded-lg text-gray-600 hover:bg-gray-100" data-tab="single"><i class="fa-solid fa-user-plus"></i> Single person</button>
      </div>
      <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4" id="issue-form">
        <?= acsrfField() ?><input type="hidden" name="action" value="issue_csv" id="issue-action">

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="text-xs font-semibold text-gray-600">Event <span class="text-rarl-red">*</span></label>
            <button type="button" onclick="document.getElementById('new-event').showModal()" class="text-[11px] font-semibold text-rarl-red hover:underline">+ New event</button>
          </div>
          <select name="event_id" required class="<?= $input ?>">
            <option value="">— Select event —</option>
            <?php foreach ($events as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $lastEvent === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['title']) ?> <?= $e['event_date'] ? '(' . date('d M Y', strtotime($e['event_date'])) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="text-xs font-semibold text-gray-600">Design</label>
            <a href="#" id="tpl-preview-link" target="_blank" class="text-[11px] font-semibold text-gray-500 hover:text-rarl-red <?= $defaultTpl ? '' : 'hidden' ?>"><i class="fa-solid fa-eye"></i> Full preview</a>
          </div>
          <?php if ($templates): ?>
          <select name="template_id" id="tpl-select" class="<?= $input ?>">
            <option value="" data-preview="<?= $defaultTpl ? (int)$defaultTpl['id'] : '' ?>">Default template<?= $defaultTpl ? ' — ' . htmlspecialchars($defaultTpl['name']) : '' ?></option>
            <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>" data-preview="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
          </select>
          <div class="mt-2 rounded-xl overflow-hidden border border-gray-100 bg-gray-50" id="tpl-thumbs">
            <?php foreach ($templates as $t): ?>
            <div data-thumb="<?= $t['id'] ?>" class="<?= $defaultTpl && $defaultTpl['id'] === $t['id'] ? '' : 'hidden' ?> pointer-events-none"><?= renderTemplateHtml($t, templateSampleData('certificate')) ?></div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800">No custom design yet — a plain built-in certificate will be used. <a href="templates.php#new" class="font-semibold underline">Design one</a></div>
          <?php endif; ?>
        </div>

        <div data-pane="csv">
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Participant list <span class="text-rarl-red">*</span></label>
          <label id="csv-drop" class="block border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-5 text-center transition-colors cursor-pointer">
            <i class="fa-solid fa-file-csv text-2xl text-gray-400"></i>
            <p class="text-xs text-gray-600 mt-1.5" id="csv-label">Drop a CSV here or <span class="text-rarl-red font-semibold">browse</span></p>
            <p class="text-[10px] text-gray-400 mt-0.5">Needs <strong>name</strong> and <strong>email</strong> columns · max 500 rows</p>
            <input type="file" name="csv_file" id="csv-input" accept=".csv,text/csv" class="sr-only" data-no-chip/>
          </label>
          <div id="csv-preview" class="hidden mt-3"></div>
          <a href="data:text/csv;charset=utf-8,name%2Cemail%0ADr.%20Jane%20Smith%2Cjane%40university.edu%0AJohn%20Doe%2Cjohn%40lab.org" download="rarl_cert_template.csv" class="mt-2 inline-block text-[11px] text-gray-500 hover:text-rarl-red"><i class="fa-solid fa-arrow-down"></i> Download sample CSV</a>
        </div>

        <div data-pane="single" class="hidden space-y-3">
          <div><label class="block text-xs font-semibold text-gray-600 mb-1.5">Full name <span class="text-rarl-red">*</span></label><input name="name" class="<?= $input ?>" placeholder="As it should appear on the certificate"/></div>
          <div><label class="block text-xs font-semibold text-gray-600 mb-1.5">Email <span class="text-rarl-red">*</span></label><input type="email" name="email" class="<?= $input ?>" placeholder="name@example.com"/></div>
        </div>

        <label class="flex items-start gap-2.5 p-3 bg-blue-50 border border-blue-200 rounded-xl cursor-pointer text-sm">
          <input type="checkbox" name="send_email" value="1" checked class="w-4 h-4 mt-0.5 accent-rarl-red"/>
          <span><span class="font-semibold text-gray-800">Email certificates now</span><span class="block text-xs text-gray-500">Untick to review first, then send from the list.</span></span>
        </label>

        <button type="submit" id="issue-btn" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl transition-all shadow hover:-translate-y-0.5">
          <i class="fa-solid fa-trophy"></i> <span>Generate & issue</span>
        </button>
      </form>
    </div>
  </div>

  <!-- ── Certificates table ── -->
  <div class="min-w-0">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <form method="GET" class="px-4 py-3 border-b border-gray-100 flex flex-wrap gap-2 items-center">
        <div class="relative flex-1 min-w-[180px]">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
          <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, email, certificate no…  ( / )" class="w-full pl-8 pr-3 py-2 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <select name="event" onchange="this.form.submit()" class="px-3 py-2 text-xs border border-gray-300 rounded-lg max-w-[200px]">
          <option value="">All events</option>
          <?php foreach ($events as $e): ?><option value="<?= $e['id'] ?>" <?= $filterEvent === (int)$e['id'] ? 'selected':'' ?>><?= htmlspecialchars(mb_strimwidth($e['title'],0,35,'…')) ?></option><?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 text-xs border border-gray-300 rounded-lg">
          <?php foreach (['' => 'Any status', 'emailed' => 'Emailed', 'unsent' => 'Not sent', 'nopdf' => 'Missing PDF', 'membership' => 'Membership'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="px-3 py-2 bg-gray-900 text-white text-xs font-semibold rounded-lg">Search</button>
        <?php if ($search || $filterEvent || $filterStatus): ?><a href="certificates.php" class="px-2 py-2 text-xs text-gray-500 hover:text-rarl-red">Clear</a><?php endif; ?>
      </form>

      <?= bulkFormOpen('', ['return_qs' => $qs]) ?>
      <div class="px-4 pt-3"><?= bulkBar([
          ['label'=>'<i class="fa-solid fa-paper-plane"></i> Send unsent','op'=>'send','class'=>'bg-amber-600 hover:bg-amber-500'],
          ['label'=>'<i class="fa-solid fa-envelope"></i> Resend','op'=>'resend','class'=>'bg-gray-700 hover:bg-gray-600','confirm'=>'Email the selected certificates again, including ones already sent?'],
          ['label'=>'<i class="fa-solid fa-rotate"></i> Regenerate PDFs','op'=>'regen','class'=>'bg-blue-600 hover:bg-blue-500','confirm'=>'Rebuild the selected PDFs with their current template design?'],
          ['label'=>'<i class="fa-solid fa-trash"></i> Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete all selected certificates? This cannot be undone.'],
      ]) ?></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-y border-gray-200">
            <tr>
              <th class="px-4 py-3 w-8"><?= bulkSelectAllCheckbox() ?></th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Recipient</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Certificate · Issued</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Event</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Status</th>
              <th class="text-right px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if (empty($certificates)): ?>
            <tr><td colspan="6" class="py-14 text-center">
              <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center text-xl mb-2"><i class="fa-solid fa-trophy"></i></div>
              <p class="text-sm text-gray-500"><?= $search || $filterEvent || $filterStatus ? 'No certificates match these filters.' : 'No certificates yet — issue your first batch on the left.' ?></p>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($certificates as $c): $verify = CERT_VERIFY_URL . '?id=' . $c['uuid']; $isMembership = ($c['cert_type'] ?? 'event') === 'membership'; ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3"><?= bulkRowCheckbox((int)$c['id']) ?></td>
              <td class="px-3 py-3" data-sort="<?= htmlspecialchars($c['recipient_name']) ?>">
                <div class="flex items-center gap-2.5 min-w-0">
                  <span class="w-8 h-8 rounded-full bg-rarl-red/10 text-rarl-red font-bold text-xs flex items-center justify-center flex-shrink-0"><?= htmlspecialchars(mb_strtoupper(mb_substr(preg_replace('/^(dr|prof|mr|mrs|ms)\.?\s+/i', '', $c['recipient_name']), 0, 1))) ?></span>
                  <div class="min-w-0"><p class="font-medium text-gray-900 text-xs truncate max-w-[150px]"><?= htmlspecialchars($c['recipient_name']) ?></p><p class="text-[10px] text-gray-400 truncate max-w-[150px]"><?= htmlspecialchars($c['recipient_email']) ?></p></div>
                </div>
              </td>
              <td class="px-3 py-3" data-sort="<?= htmlspecialchars($c['issued_at']) ?>">
                <button type="button" class="copy-btn whitespace-nowrap font-mono text-[10px] text-gray-700 bg-gray-100 hover:bg-gray-200 px-2 py-0.5 rounded" data-copy="<?= htmlspecialchars($c['certificate_no']) ?>" title="Copy certificate number"><?= htmlspecialchars($c['certificate_no']) ?></button>
                <span class="block text-[10px] text-gray-400 mt-0.5 whitespace-nowrap" title="Issued <?= htmlspecialchars($c['issued_at']) ?>">Issued <?= date('d M Y', strtotime($c['issued_at'])) ?></span>
              </td>
              <td class="px-3 py-3 text-[11px] text-gray-600"><?= $isMembership ? '<span class="font-semibold text-purple-600">Membership</span>' : '<span class="line-clamp-2 max-w-[150px] block" title="' . htmlspecialchars($c['event_title'] ?? '') . '">' . htmlspecialchars(mb_strimwidth($c['event_title'] ?? '', 0, 40, '…')) . '</span>' ?></td>
              <td class="px-3 py-3">
                <?php if (!$c['pdf_path']): ?><span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] font-semibold text-red-700 bg-red-50 px-2 py-0.5 rounded-full"><i class="fa-solid fa-file-circle-exclamation"></i> No PDF</span>
                <?php elseif ($c['emailed_at']): ?><span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded-full" title="<?= htmlspecialchars($c['emailed_at']) ?>"><i class="fa-solid fa-check"></i> Sent <?= date('d M', strtotime($c['emailed_at'])) ?></span>
                <?php else: ?><span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full"><i class="fa-regular fa-clock"></i> Not sent</span><?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-0.5">
                  <?php if ($c['pdf_path']): ?>
                  <a href="../uploads/certificates/<?= urlencode($c['pdf_path']) ?>" target="_blank" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="Open PDF"><i class="fa-regular fa-file-pdf"></i></a>
                  <?php endif; ?>
                  <button type="button" class="copy-btn w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900" data-copy="<?= htmlspecialchars($verify) ?>" title="Copy verification link"><i class="fa-solid fa-link"></i></button>
                  <?php if ($c['pdf_path']): ?>
                  <form method="POST" class="inline" <?= $c['emailed_at'] ? 'data-confirm="Email this certificate to ' . htmlspecialchars($c['recipient_email']) . ' again?" data-confirm-ok="Resend"' : '' ?>><?= acsrfField() ?><input type="hidden" name="action" value="email_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
                    <button type="submit" class="w-8 h-8 inline-flex items-center justify-center rounded-lg <?= $c['emailed_at'] ? 'text-gray-500 hover:bg-gray-100' : 'text-amber-600 bg-amber-50 hover:bg-amber-100' ?>" title="<?= $c['emailed_at'] ? 'Resend email' : 'Send email' ?>"><i class="fa-solid fa-paper-plane"></i></button>
                  </form>
                  <?php endif; ?>
                  <?php if (!$isMembership): ?>
                  <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="regen_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
                    <button type="submit" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="Regenerate PDF with current design"><i class="fa-solid fa-rotate"></i></button>
                  </form>
                  <?php endif; ?>
                  <form method="POST" class="inline" data-confirm="Delete the certificate for <?= htmlspecialchars($c['recipient_name']) ?>? Its verification link will stop working."><?= acsrfField() ?><input type="hidden" name="action" value="delete_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
                    <button type="submit" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
        <span><?= $total ? 'Showing ' . number_format(($page - 1) * CERT_PER_PAGE + 1) . '–' . number_format(min($total, $page * CERT_PER_PAGE)) . ' of ' . number_format($total) : '0 results' ?></span>
        <?php if ($pages > 1): ?>
        <div class="flex items-center gap-1">
          <a href="<?= htmlspecialchars($pageUrl(['page' => $page - 1])) ?>" class="px-2.5 py-1.5 rounded-lg border border-gray-200 <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50' ?>"><i class="fa-solid fa-chevron-left"></i></a>
          <span class="px-2">Page <?= $page ?> of <?= $pages ?></span>
          <a href="<?= htmlspecialchars($pageUrl(['page' => $page + 1])) ?>" class="px-2.5 py-1.5 rounded-lg border border-gray-200 <?= $page >= $pages ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50' ?>"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<dialog id="new-event" class="rarl-dialog w-full max-w-md">
  <form method="POST" class="p-6 space-y-3">
    <?= acsrfField() ?><input type="hidden" name="action" value="create_event">
    <div class="flex items-center justify-between mb-2"><h2 class="font-heading font-bold text-lg">New event</h2><button type="button" onclick="this.closest('dialog').close()" class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100"><i class="fa-solid fa-xmark"></i></button></div>
    <input type="text" name="ev_title" required placeholder="Event title" class="<?= $input ?>"/>
    <div class="grid grid-cols-2 gap-2">
      <select name="ev_type" class="<?= $input ?>">
        <?php foreach (['workshop','webinar','hackathon','competition','volunteer','conference','seminar','other'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="ev_date" value="<?= date('Y-m-d') ?>" class="<?= $input ?>"/>
    </div>
    <textarea name="ev_desc" rows="2" placeholder="Description (optional)" class="<?= $input ?> resize-none"></textarea>
    <div class="flex justify-end gap-2 pt-2">
      <button type="button" onclick="this.closest('dialog').close()" class="px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">Cancel</button>
      <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl">Create event</button>
    </div>
  </form>
</dialog>

<?= bulkBarScript() ?>
<script>
(function() {
  // Copy buttons
  document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); rarlToast('Copied to clipboard', 'success'); } catch (e) { prompt('Copy:', b.dataset.copy); }
  }));

  // Tabs: CSV vs single recipient
  const form = document.getElementById('issue-form'), actionInput = document.getElementById('issue-action');
  const btnLabel = document.querySelector('#issue-btn span');
  let csvRows = 0;
  function setTab(tab) {
    document.querySelectorAll('.issue-tab').forEach(t => t.className = 'issue-tab flex-1 py-2 text-xs font-semibold rounded-lg ' + (t.dataset.tab === tab ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'));
    form.querySelectorAll('[data-pane]').forEach(p => p.classList.toggle('hidden', p.dataset.pane !== tab));
    actionInput.value = tab === 'csv' ? 'issue_csv' : 'issue_single';
    form.querySelector('[name=name]').required = form.querySelector('[name=email]').required = tab === 'single';
    document.getElementById('csv-input').required = tab === 'csv';
    btnLabel.textContent = tab === 'single' ? 'Issue certificate' : (csvRows ? 'Issue ' + csvRows + ' certificate' + (csvRows === 1 ? '' : 's') : 'Generate & issue');
    try { localStorage.setItem('rarl-cert-tab', tab); } catch (e) {}
  }
  document.querySelectorAll('.issue-tab').forEach(t => t.addEventListener('click', () => setTab(t.dataset.tab)));
  let saved = 'csv'; try { saved = localStorage.getItem('rarl-cert-tab') || 'csv'; } catch (e) {}
  setTab(saved);

  // Template thumbnail
  const sel = document.getElementById('tpl-select'), link = document.getElementById('tpl-preview-link');
  function syncThumb() {
    if (!sel) return;
    const id = sel.selectedOptions[0].dataset.preview;
    document.querySelectorAll('[data-thumb]').forEach(t => t.classList.toggle('hidden', t.dataset.thumb !== id));
    document.getElementById('tpl-thumbs').classList.toggle('hidden', !id);
    link.classList.toggle('hidden', !id); if (id) link.href = 'preview-template.php?id=' + id;
  }
  sel?.addEventListener('change', syncThumb); syncThumb();

  // CSV preview: parse in the browser so problems show up before anything is issued.
  const NAME_H = <?= json_encode(CERT_NAME_HEADERS) ?>, EMAIL_H = <?= json_encode(CERT_EMAIL_HEADERS) ?>;
  function parseCsv(text) {
    text = text.replace(/^﻿/, '');
    const first = text.split(/\r?\n/)[0] || '';
    const d = (first.match(/;/g) || []).length > (first.match(/,/g) || []).length ? ';' : ',';
    const rows = []; let row = [], cell = '', q = false;
    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      if (q) { if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (ch === '"') q = false; else cell += ch; }
      else if (ch === '"') q = true;
      else if (ch === d) { row.push(cell); cell = ''; }
      else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cell); if (row.some(c => c.trim())) rows.push(row); row = []; cell = ''; }
      else cell += ch;
    }
    row.push(cell); if (row.some(c => c.trim())) rows.push(row);
    return rows;
  }
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  const csvInput = document.getElementById('csv-input'), drop = document.getElementById('csv-drop'), prev = document.getElementById('csv-preview');
  function preview(file) {
    if (!file) return;
    document.getElementById('csv-label').innerHTML = '<i class="fa-solid fa-paperclip"></i> <strong>' + esc(file.name) + '</strong> · <span class="text-rarl-red">change</span>';
    drop.classList.add('border-green-400', 'bg-green-50/40');
    file.text().then(text => {
      const rows = parseCsv(text);
      const header = (rows.shift() || []).map(h => h.trim().toLowerCase());
      const ni = NAME_H.map(h => header.indexOf(h)).find(i => i >= 0) ?? -1;
      const ei = EMAIL_H.map(h => header.indexOf(h)).find(i => i >= 0) ?? -1;
      if (ni < 0 || ei < 0) {
        csvRows = 0; setTab('csv');
        prev.innerHTML = '<div class="p-3 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700"><i class="fa-solid fa-triangle-exclamation"></i> Missing ' + (ni < 0 ? '<strong>name</strong>' : '') + (ni < 0 && ei < 0 ? ' and ' : '') + (ei < 0 ? '<strong>email</strong>' : '') + ' column. Found: ' + esc(header.filter(Boolean).join(', ') || 'nothing') + '</div>';
        prev.classList.remove('hidden'); return;
      }
      const seen = new Set(); let bad = 0, dup = 0;
      const data = rows.slice(0, 500).map(r => {
        const name = (r[ni] || '').trim(), email = (r[ei] || '').trim().toLowerCase();
        const ok = name && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        const isDup = ok && seen.has(email); if (ok) seen.add(email);
        if (!ok) bad++; else if (isDup) dup++;
        return {name, email, ok: ok && !isDup, why: !ok ? (name ? 'invalid email' : 'missing name') : isDup ? 'duplicate' : ''};
      });
      csvRows = data.filter(r => r.ok).length;
      setTab('csv');
      let html = '<div class="flex flex-wrap gap-1.5 mb-2 text-[11px]"><span class="px-2 py-0.5 rounded-full bg-green-50 text-green-700 font-semibold">' + csvRows + ' ready</span>' +
        (bad ? '<span class="px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-semibold">' + bad + ' invalid</span>' : '') +
        (dup ? '<span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-semibold">' + dup + ' duplicate</span>' : '') +
        (rows.length > 500 ? '<span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 font-semibold">first 500 of ' + rows.length + '</span>' : '') + '</div>';
      html += '<div class="max-h-56 overflow-y-auto border border-gray-100 rounded-xl"><table class="w-full text-[11px]" data-no-sort><tbody class="divide-y divide-gray-100">' +
        data.map(r => '<tr class="' + (r.ok ? '' : 'bg-red-50/60') + '"><td class="px-2.5 py-1.5 text-gray-800">' + (esc(r.name) || '<em class="text-gray-400">—</em>') + '</td><td class="px-2.5 py-1.5 text-gray-500 truncate max-w-[140px]">' + esc(r.email) + '</td><td class="px-2 py-1.5 text-right">' + (r.ok ? '<i class="fa-solid fa-check text-green-500"></i>' : '<span class="text-red-600">' + r.why + '</span>') + '</td></tr>').join('') +
        '</tbody></table></div><p class="text-[10px] text-gray-400 mt-1.5">People who already have a certificate for this event are skipped automatically.</p>';
      prev.innerHTML = html; prev.classList.remove('hidden');
    });
  }
  csvInput.addEventListener('change', () => preview(csvInput.files[0]));
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('border-rarl-red'); }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('border-rarl-red'); }));
  drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) { csvInput.files = e.dataTransfer.files; preview(csvInput.files[0]); } });
  form.addEventListener('submit', e => {
    if (actionInput.value === 'issue_csv' && csvInput.files[0] && csvRows === 0) { e.preventDefault(); e.stopImmediatePropagation(); rarlToast('The CSV has no valid rows to issue.', 'error'); }
  }, true);
})();
</script>
<?php }, 'certificates', 'Certificates');
