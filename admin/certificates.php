<?php
/**
 * RARL Admin — Certificate Management
 * Issue (CSV or single or all-members) → Generate PDFs → Email → Store in member profiles
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo    = db();
$events = $pdo->query("SELECT * FROM events WHERE is_active = 1 ORDER BY event_date DESC")->fetchAll();
const CERT_PER_PAGE = 50;

// ── JSON endpoint: member count preview for "All Members" tab ──
if (($_GET['action'] ?? '') === 'member_count_preview') {
    $eventId    = (int)($_GET['event_id'] ?? 0);
    $memberType = in_array($_GET['member_type'] ?? '', ['individual','lab'], true) ? $_GET['member_type'] : '';
    $whereType  = $memberType ? "AND m.type = '" . $memberType . "'" : '';
    $already    = 0;
    if ($eventId) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM certificates c
            JOIN members m ON m.email = c.recipient_email
            WHERE c.event_id = ? AND m.status = 'active' AND m.email_verified_at IS NOT NULL AND m.email NOT LIKE '%@rarl.internal' {$whereType}");
        $s->execute([$eventId]);
        $already = (int)$s->fetchColumn();
    }
    $t = $pdo->prepare("SELECT COUNT(*) FROM members m WHERE m.status = 'active' AND m.email_verified_at IS NOT NULL AND m.email NOT LIKE '%@rarl.internal' {$whereType}");
    $t->execute();
    $total = (int)$t->fetchColumn();
    header('Content-Type: application/json');
    echo json_encode(['total' => $total, 'already' => $already, 'new' => max(0, $total - $already)]);
    exit;
}

// ── JSON endpoint: eligible members for the "Choose people" picker ──
if (($_GET['action'] ?? '') === 'member_list') {
    $eventId = (int)($_GET['event_id'] ?? 0);
    $s = $pdo->prepare("SELECT m.id, m.email, m.type,
            CASE WHEN m.type='individual' THEN COALESCE(NULLIF(TRIM(m.full_name),''), m.email)
                 ELSE COALESCE(NULLIF(TRIM(m.lab_name),''), NULLIF(TRIM(m.pi_name),''), m.email) END AS name,
            EXISTS(SELECT 1 FROM certificates c WHERE c.event_id = ? AND c.recipient_email = m.email) AS has_cert
        FROM members m
        WHERE m.status = 'active' AND m.email_verified_at IS NOT NULL AND m.email NOT LIKE '%@rarl.internal'
        ORDER BY name ASC");
    $s->execute([$eventId]);
    header('Content-Type: application/json');
    echo json_encode(array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name'], 'email' => $m['email'], 'type' => $m['type'], 'has' => (bool)$m['has_cert']], $s->fetchAll()));
    exit;
}

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
// $custom: wording edited in the send dialog (subject, headline, message, closing).
function certSendEmail(PDO $pdo, array $c, ?string $batch = null, array $custom = []): void {
    $verifyUrl  = CERT_VERIFY_URL . '?id=' . $c['uuid'];
    $eventTitle = $c['event_title'] ?? 'RARL';
    $eventDate  = !empty($c['event_date']) ? date('d F Y', strtotime($c['event_date'])) : date('d F Y');
    $certNumber = $c['certificate_no'];
    $memberName = properName($c['recipient_name']);
    $eventType  = $c['event_type'] ?? null;
    if (!$eventType && !empty($c['event_id'])) {
        $et = $pdo->prepare('SELECT type FROM events WHERE id = ?'); $et->execute([$c['event_id']]);
        $eventType = $et->fetchColumn() ?: 'other';
    }
    if (empty($c['pdf_path']) && !empty($c['id'])) {
        $pp = $pdo->prepare('SELECT pdf_path FROM certificates WHERE id = ?'); $pp->execute([$c['id']]);
        $c['pdf_path'] = $pp->fetchColumn();
    }
    $attach = certEmailAttachment($c['pdf_path'] ?? '', $certNumber, $memberName);
    $pdfAttached = (bool)$attach;
    $subject = trim($custom['subject'] ?? '') !== ''
        ? html_entity_decode(certEmailFill($custom['subject'], certEmailVars($memberName, $eventTitle, $eventDate, $certNumber), false), ENT_QUOTES, 'UTF-8')
        : certEmailSubject($eventType, html_entity_decode((string)$eventTitle, ENT_QUOTES, 'UTF-8'));
    ob_start(); require dirname(__DIR__) . '/emails/certificate.php'; $body = ob_get_clean();
    if (!empty($c['_preview'])) { echo $body; return; }
    if ($batch) {
        queueEmail($batch, 'Certificates — ' . $eventTitle, $c['recipient_email'], $memberName, $subject, $body, $attach, 'cert:' . (int)$c['id']);
        return;
    }
    if (sendEmail($c['recipient_email'], $memberName, $subject, $body, $attach)) {
        $pdo->prepare("UPDATE certificates SET emailed_at = NOW() WHERE id = ?")->execute([$c['id']]);
    }
}

/** Emails a "join RARL" invite to a certificate recipient who is not a member. Returns false if they already are. */
function certInvite(PDO $pdo, array $c, ?string $batch = null): bool {
    $chk = $pdo->prepare('SELECT 1 FROM members WHERE email = ?'); $chk->execute([$c['recipient_email']]);
    if ($chk->fetchColumn()) return false;
    $memberName = properName($c['recipient_name']);
    $eventTitle = $c['event_title'] ?? '';
    $inviteUrl  = inviteUrl($c['recipient_email']);
    ob_start(); require dirname(__DIR__) . '/emails/member-invite.php'; $body = ob_get_clean();
    $subject = "You're invited to join RARL";
    return $batch ? queueEmail($batch, 'Member invites', $c['recipient_email'], $memberName, $subject, $body)
                  : sendEmail($c['recipient_email'], $memberName, $subject, $body);
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

    $name    = properName($name);
    $uuid    = generateUuid();
    $certNo  = nextCertNumber();
    $pdfPath = certWritePdf(certTemplateFor($pdo, $templateId), $uuid, $name, $event, $certNo);

    $pdo->prepare("INSERT INTO certificates (uuid, certificate_no, member_id, recipient_name, recipient_email, event_id, template_id, pdf_path)
        VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$uuid, $certNo, $memberId, $name, $email, $event['id'], $templateId, $pdfPath]);

    if ($send) {
        certSendEmail($pdo, ['id' => $pdo->lastInsertId(), 'uuid' => $uuid, 'certificate_no' => $certNo, 'recipient_name' => $name,
            'recipient_email' => $email, 'event_title' => $event['title'], 'event_date' => $event['event_date'],
            'event_id' => $event['id'], 'event_type' => $event['type'] ?? null, 'pdf_path' => $pdfPath], $batch);
    }
    return 'issued';
}

function certFetch(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT c.*, e.title as event_title, e.event_date, e.type AS event_type FROM certificates c LEFT JOIN events e ON c.event_id=e.id WHERE c.id IN ({$in})");
    $s->execute($ids);
    return $s->fetchAll();
}

// Rebuilds an event certificate PDF from its (or the current default) template —
// e.g. after fixing a typo in the design. Membership certificates are skipped.
// $newTemplate: switch the certificate to this design first (replaces the old one).
function certRegenerate(PDO $pdo, array $c, ?int $newTemplate = null): bool {
    if (($c['cert_type'] ?? 'event') === 'membership') {
        if (!$newTemplate) return regenerateMembershipCertificate($c);
        $t = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ? AND type = 'membership'");
        $t->execute([$newTemplate]);
        return ($tpl = $t->fetch()) ? regenerateMembershipCertificate($c, $tpl) : false;
    }
    if (empty($c['event_id'])) return false;
    if ($newTemplate) $c['template_id'] = $newTemplate;
    $c['recipient_name'] = properName($c['recipient_name']);
    $pdf = certWritePdf(certTemplateFor($pdo, $c['template_id'] ? (int)$c['template_id'] : null), $c['uuid'], $c['recipient_name'],
        ['title' => $c['event_title'], 'event_date' => $c['event_date']], $c['certificate_no']);
    if (!$pdf) return false;
    $pdo->prepare("UPDATE certificates SET pdf_path = ?, template_id = ?, recipient_name = ? WHERE id = ?")->execute([$pdf, $c['template_id'] ?: null, $c['recipient_name'], $c['id']]);
    return true;
}

// ── Filters (shared by the list and CSV export) ─────────────
/** WHERE clause + params for the History filters (q, event, status). */
function certFilterSql(array $f): array {
    $where = []; $params = [];
    $ev = (int)($f['event'] ?? 0); $st = $f['status'] ?? ''; $q = trim((string)($f['q'] ?? ''));
    if ($ev) { $where[] = 'c.event_id = ?'; $params[] = $ev; }
    if ($st === 'emailed')    $where[] = 'c.emailed_at IS NOT NULL';
    if ($st === 'unsent')     $where[] = 'c.emailed_at IS NULL';
    if ($st === 'membership') $where[] = "c.cert_type = 'membership'";
    if ($st === 'nopdf')      $where[] = 'c.pdf_path IS NULL';
    if ($q !== '') { $where[] = '(c.recipient_name LIKE ? OR c.recipient_email LIKE ? OR c.certificate_no LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}
// Sort key for names that ignores academic/courtesy titles ("Dr. Ada" sorts under A).
const CERT_NAME_KEY = "CASE WHEN c.recipient_name LIKE 'Dr. %' THEN SUBSTRING(c.recipient_name, 5) WHEN c.recipient_name LIKE 'Dr %' THEN SUBSTRING(c.recipient_name, 4)
    WHEN c.recipient_name LIKE 'Prof. %' THEN SUBSTRING(c.recipient_name, 7) WHEN c.recipient_name LIKE 'Prof %' THEN SUBSTRING(c.recipient_name, 6)
    WHEN c.recipient_name LIKE 'Mr. %' OR c.recipient_name LIKE 'Ms. %' THEN SUBSTRING(c.recipient_name, 5) WHEN c.recipient_name LIKE 'Mrs. %' THEN SUBSTRING(c.recipient_name, 6)
    ELSE c.recipient_name END";
const CERT_SORTS = [
    'newest' => ['Newest first', 'c.issued_at DESC, c.id DESC'],
    'oldest' => ['Oldest first', 'c.issued_at ASC, c.id ASC'],
    'name'   => ['Name A–Z', CERT_NAME_KEY . ' ASC'],
    'name_desc' => ['Name Z–A', CERT_NAME_KEY . ' DESC'],
    'number' => ['Certificate no.', 'c.certificate_no ASC'],
    'event'  => ['Event', 'e.title ASC, ' . CERT_NAME_KEY . ' ASC'],
];
$filterEvent  = (int)($_GET['event'] ?? 0);
$filterStatus = in_array($_GET['status'] ?? '', ['emailed','unsent','membership','nopdf'], true) ? $_GET['status'] : '';
$search       = trim($_GET['q'] ?? '');
$sort         = isset(CERT_SORTS[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'newest';
$groupBy      = in_array($_GET['group'] ?? '', ['event', 'status'], true) ? $_GET['group'] : '';
$perPage      = in_array((int)($_GET['per'] ?? 0), [50, 100, 250, 500], true) ? (int)$_GET['per'] : CERT_PER_PAGE;
[$ws, $params] = certFilterSql(['event' => $filterEvent, 'status' => $filterStatus, 'q' => $search]);

/** Streams the PDFs of $rows as one ZIP ("CERTNO - Name.pdf"). Returns false if none have a PDF. */
function certZipDownload(array $rows, string $label): bool {
    if (!class_exists('ZipArchive')) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'rcz');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return false;
    $added = 0; $used = [];
    foreach ($rows as $r) {
        $file = $r['pdf_path'] ? UPLOADS_PATH . '/certificates/' . basename($r['pdf_path']) : '';
        if (!$file || !is_file($file)) continue;
        $name = trim(preg_replace('/[^\w .,()&\'-]+/u', '', html_entity_decode($r['certificate_no'] . ' - ' . $r['recipient_name'], ENT_QUOTES, 'UTF-8'))) ?: 'certificate-' . $r['id'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'pdf';
        $entry = $name . '.' . $ext;
        for ($n = 2; isset($used[strtolower($entry)]); $n++) $entry = "$name ($n).$ext";
        $used[strtolower($entry)] = true;
        $zip->addFile($file, $entry); $added++;
    }
    $zip->close();
    if (!$added) { @unlink($tmp); return false; }
    $fname = 'RARL certificates - ' . trim(preg_replace('/[^\w .,()&-]+/u', '', html_entity_decode($label, ENT_QUOTES, 'UTF-8'))) . ' - ' . date('Y-m-d') . '.zip';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fname . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp);
    return true;
}

if (($_GET['export'] ?? '') === 'zip') {
    @set_time_limit(0);
    $stmt = $pdo->prepare("SELECT c.*, e.title AS event_title FROM certificates c LEFT JOIN events e ON c.event_id = e.id {$ws} ORDER BY c.recipient_name");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $label = $filterEvent && $rows ? ($rows[0]['event_title'] ?: 'event') : ($filterStatus === 'membership' ? 'membership' : 'all');
    if (certZipDownload($rows, $label)) exit;
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $rows ? 'None of these certificates have a PDF yet. Regenerate them first.' : 'No certificates issued for this yet.'];
    header('Location: certificates.php' . ($filterEvent ? '?view=history&event=' . $filterEvent : '?view=history')); exit;
}

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

    if ($action === 'issue_csv' || $action === 'issue_single' || $action === 'issue_all_members') {
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

        if ($action === 'issue_all_members') {
            $memberType = in_array($_POST['member_type'] ?? '', ['individual','lab'], true) ? $_POST['member_type'] : '';
            $whereType  = $memberType ? "AND type = '" . $memberType . "'" : '';
            $pickIds = ($_POST['pick_mode'] ?? '') === 'pick' ? array_values(array_filter(array_map('intval', (array)($_POST['member_ids'] ?? [])))) : [];
            if (($_POST['pick_mode'] ?? '') === 'pick' && !$pickIds) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Tick at least one member to issue certificates to.'];
                header('Location: certificates.php'); exit;
            }
            $whereIds = $pickIds ? 'AND id IN (' . implode(',', $pickIds) . ')' : '';
            if ($pickIds) $whereType = '';
            $members = $pdo->query("SELECT email,
                CASE WHEN type='individual' THEN COALESCE(NULLIF(TRIM(full_name),''), email)
                     ELSE COALESCE(NULLIF(TRIM(lab_name),''), NULLIF(TRIM(pi_name),''), email) END AS display_name
                FROM members
                WHERE status = 'active' AND email_verified_at IS NOT NULL AND email NOT LIKE '%@rarl.internal' {$whereType} {$whereIds}
                ORDER BY created_at ASC")->fetchAll();
            if (!$members) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'No active, verified members found to issue certificates to.'];
                header('Location: certificates.php'); exit;
            }
            @set_time_limit(0);
            $counts = ['issued' => 0, 'duplicate' => 0, 'invalid' => 0];
            $batch  = $sendEmail ? newEmailBatch() : null;
            foreach ($members as $m) {
                $counts[certIssue($pdo, $event, $m['display_name'], $m['email'], $templateId, $sendEmail, $batch)]++;
            }
            $typeLabel = $pickIds ? '' : ($memberType ? " ({$memberType}s only)" : '');
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Issued {$counts['issued']} certificate(s) to " . ($pickIds ? 'the selected members' : 'all registered members') . "{$typeLabel}" . ($sendEmail ? ' — emails are sending in the background' : '') . ". Skipped {$counts['duplicate']} already issued" . ($counts['invalid'] ? " and {$counts['invalid']} with an invalid email" : '') . "."];
            header('Location: certificates.php?view=history&event=' . $eventId); exit;
        }

        // Resolve raw CSV text: either from uploaded file or from the paste textarea
        $csvPaste = ($_POST['csv_mode'] ?? '') === 'paste' ? trim($_POST['csv_paste'] ?? '') : '';
        if ($csvPaste) {
            $rawText = preg_replace('/^\xEF\xBB\xBF/', '', $csvPaste);
            $lines   = array_filter(explode("\n", str_replace("\r\n", "\n", str_replace("\r", "\n", $rawText))), 'strlen');
            $lines   = array_values($lines);
        } elseif (!empty($_FILES['csv_file']['tmp_name'])) {
            $lines = file($_FILES['csv_file']['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if ($lines) $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]); // Excel UTF-8 BOM
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Upload a CSV file or paste CSV text.'];
            header('Location: certificates.php'); exit;
        }
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
        header('Location: certificates.php?view=history&event=' . $eventId); exit;
    }

    // Wording edited in the send dialog (only when the admin changed it).
    $customEmail = !empty($_POST['email_custom']) ? array_map(fn($k) => mb_substr(trim((string)($_POST['email_' . $k] ?? '')), 0, 2000), ['subject' => 'subject', 'headline' => 'headline', 'message' => 'message', 'closing' => 'closing']) : [];
    if ($action === 'email_preview') {
        $c = certFetch($pdo, [$_POST['cert_id'] ?? 0])[0] ?? null;
        if (!$c) { http_response_code(404); exit; }
        $c['_preview'] = true;
        $custom = $customEmail ?: array_map(fn($k) => (string)($_POST['email_' . $k] ?? ''), ['subject' => 'subject', 'headline' => 'headline', 'message' => 'message', 'closing' => 'closing']);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Email-Subject: ' . rawurlencode(html_entity_decode(certEmailFill($custom['subject'] ?: certEmailSubject($c['event_type'] ?? null, '{event}'), certEmailVars($c['recipient_name'], $c['event_title'] ?? '', !empty($c['event_date']) ? date('d F Y', strtotime($c['event_date'])) : '', $c['certificate_no']), false), ENT_QUOTES, 'UTF-8')));
        certSendEmail($pdo, $c, null, $custom);
        exit;
    }
    if ($action === 'email_cert') {
        foreach (certFetch($pdo, [$_POST['cert_id'] ?? 0]) as $c) {
            if (!$c['pdf_path']) { $_SESSION['flash'] = ['type'=>'error','msg'=>'This certificate has no PDF yet — regenerate it first.']; break; }
            certSendEmail($pdo, $c, null, $customEmail);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Certificate emailed to ' . $c['recipient_email'] . '.'];
        }
        header('Location: ' . $back); exit;
    }

    if ($action === 'invite_cert') {
        $c = certFetch($pdo, [$_POST['cert_id'] ?? 0])[0] ?? null;
        $_SESSION['flash'] = $c && certInvite($pdo, $c)
            ? ['type'=>'success','msg'=>'Invitation sent to ' . $c['recipient_email'] . '.']
            : ['type'=>'error','msg'=>'Already a member, so no invite was sent.'];
        header('Location: ' . $back); exit;
    }

    // Regenerate: keep each certificate's design, or replace it with a chosen one.
    $newTpl = null;
    if (($_POST['regen_mode'] ?? '') === 'new') {
        $chk = $pdo->prepare("SELECT id FROM certificate_templates WHERE id = ? AND type = 'certificate'");
        $chk->execute([(int)($_POST['new_template_id'] ?? 0)]);
        $newTpl = (int)$chk->fetchColumn() ?: null;
        if (!$newTpl) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Choose the new design to use.']; header('Location: ' . $back); exit; }
    }
    if ($action === 'regen_cert') {
        $c = certFetch($pdo, [$_POST['cert_id'] ?? 0])[0] ?? null;
        $_SESSION['flash'] = $c && certRegenerate($pdo, $c, $newTpl)
            ? ['type'=>'success','msg'=>$newTpl ? 'Certificate regenerated with the new design.' : 'PDF regenerated with its current design.']
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
        if (($_POST['all_matching'] ?? '') === '1') {
            parse_str((string)($_POST['return_qs'] ?? ''), $fq);
            [$fws, $fparams] = certFilterSql($fq);
            $s = $pdo->prepare("SELECT c.*, e.title as event_title, e.event_date FROM certificates c LEFT JOIN events e ON c.event_id = e.id {$fws}");
            $s->execute($fparams); $rows = $s->fetchAll();
        } else {
            $rows = certFetch($pdo, $_POST['ids'] ?? []);
        }
        $bulkOp = $_POST['bulk_op'] ?? '';
        @set_time_limit(0);
        if (!$rows) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Select at least one certificate first.'];
        } elseif ($bulkOp === 'invite') {
            $batch = 'invite-' . date('YmdHis'); $sent = 0; $seen = [];
            foreach ($rows as $c) {
                $k = strtolower($c['recipient_email']);
                if (isset($seen[$k])) continue; $seen[$k] = true;
                if (certInvite($pdo, $c, $batch)) $sent++;
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Queued {$sent} invitation(s)" . ($sent < count($seen) ? ' — existing members were skipped.' : '.')];
        } elseif ($bulkOp === 'zip') {
            if (certZipDownload($rows, count($rows) . ' selected')) exit;
            $_SESSION['flash'] = ['type'=>'error','msg'=>'None of the selected certificates have a PDF yet.'];
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
                certSendEmail($pdo, $c, $batch, $customEmail); $sent++;
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Queued {$sent} certificate email(s) — sending in the background." . ($bulkOp === 'send' && $sent < count($rows) ? ' Already-sent ones were skipped.' : '') . emailQueueEta($sent)];
        } elseif ($bulkOp === 'regen') {
            $done = 0;
            foreach ($rows as $c) if (certRegenerate($pdo, $c, $newTpl)) $done++;
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Regenerated {$done} PDF(s)" . ($newTpl ? ' with the new design' : '') . ($done < count($rows) ? ' (membership certificates skipped).' : '.')];
        }
        header('Location: ' . $back); exit;
    }
}

// ── List ───────────────────────────────────────────────────
$page  = max(1, (int)($_GET['page'] ?? 1));
$cnt   = $pdo->prepare("SELECT COUNT(*) FROM certificates c {$ws}"); $cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
// Grouping sorts by the group first so each group's rows sit together.
$order = ($groupBy === 'event' ? 'COALESCE(e.title, \'~\') ASC, ' : ($groupBy === 'status' ? '(c.pdf_path IS NULL) DESC, (c.emailed_at IS NULL) DESC, ' : '')) . CERT_SORTS[$sort][1];
$stmt  = $pdo->prepare("SELECT c.*, e.title as event_title, e.event_date, e.type AS event_type, COALESCE(mi.status, me.status) AS member_status FROM certificates c LEFT JOIN events e ON c.event_id = e.id LEFT JOIN members mi ON mi.id = c.member_id LEFT JOIN members me ON c.member_id IS NULL AND me.email = c.recipient_email {$ws} ORDER BY {$order} LIMIT " . $perPage . " OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$certificates = $stmt->fetchAll();
$templates    = $pdo->query("SELECT * FROM certificate_templates WHERE type = 'certificate' ORDER BY is_default DESC, id DESC")->fetchAll();
$stats = $pdo->query("SELECT COUNT(*) total, SUM(emailed_at IS NOT NULL) emailed, SUM(emailed_at IS NULL) unsent,
    SUM(issued_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) month FROM certificates")->fetch();
$lastEvent = (int)($_SESSION['cert_last_event'] ?? 0);
// Two jobs, two views: Issue (default) and History. Any filter means History.
$view = (($_GET['view'] ?? '') === 'history' || $filterEvent || $filterStatus || $search || isset($_GET['page'])) ? 'history' : 'issue';
$recent = $pdo->query("SELECT e.id, e.title, COUNT(*) n, SUM(c.emailed_at IS NULL) unsent, MAX(c.issued_at) last_at
    FROM certificates c JOIN events e ON e.id = c.event_id GROUP BY e.id, e.title ORDER BY last_at DESC LIMIT 5")->fetchAll();
$qs = http_build_query(array_filter(['view' => 'history', 'q' => $search, 'event' => $filterEvent ?: null, 'status' => $filterStatus, 'sort' => $sort !== 'newest' ? $sort : null, 'group' => $groupBy ?: null, 'per' => $perPage !== CERT_PER_PAGE ? $perPage : null, 'page' => $page > 1 ? $page : null]));

adminWrap(function() use ($events, $certificates, $templates, $filterEvent, $filterStatus, $search, $stats, $total, $page, $pages, $lastEvent, $qs, $view, $recent, $sort, $groupBy, $perPage) {
    adminFlash();
    $input = 'w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red';
    $pageUrl = fn(array $over) => 'certificates.php?' . http_build_query(array_filter(array_merge(['view' => 'history', 'q' => $search, 'event' => $filterEvent ?: null, 'status' => $filterStatus, 'sort' => $sort !== 'newest' ? $sort : null, 'group' => $groupBy ?: null, 'per' => $perPage !== CERT_PER_PAGE ? $perPage : null], $over)));
    $defaultTpl = null; foreach ($templates as $t) if ($t['is_default']) $defaultTpl = $t;
?>

<div class="flex flex-wrap items-end justify-between gap-3 mb-5">
  <div><h1 class="text-2xl font-black text-gray-900">Certificates</h1><p class="text-gray-500 text-sm mt-0.5"><?= $view === 'issue' ? 'Choose an event and who gets a certificate, then issue.' : 'Everyone you have issued a certificate to, and whether it was emailed.' ?></p></div>
  <div class="flex gap-2">
    <a href="templates.php" class="rarl-btn"><i class="fa-solid fa-pen-ruler"></i> Design templates</a>
    <?php if ($view === 'history'): ?><a href="<?= ex($pageUrl(['export' => 'zip'])) ?>" class="rarl-btn"><i class="fa-solid fa-file-zipper"></i> Download ZIP</a><a href="<?= ex($pageUrl(['export' => 'csv'])) ?>" class="rarl-btn"><i class="fa-solid fa-file-csv"></i> Export</a><?php endif; ?>
  </div>
</div>

<nav class="rarl-tabs mb-6 w-fit" aria-label="Certificates">
  <a href="certificates.php" class="<?= $view === 'issue' ? 'on' : '' ?>" <?= $view === 'issue' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-paper-plane"></i> Issue</a>
  <a href="certificates.php?view=history" class="<?= $view === 'history' ? 'on' : '' ?>" <?= $view === 'history' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-clock-rotate-left"></i> History <span class="count <?= (int)$stats['unsent'] ? 'hot' : '' ?>" title="<?= (int)$stats['unsent'] ?> not emailed yet"><?= number_format((int)$stats['total']) ?></span></a>
</nav>

<!-- ══ ISSUE VIEW ══ -->
<section id="view-issue" class="<?= $view === 'issue' ? '' : 'hidden' ?> grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-6 items-start max-w-6xl">
  <div class="bg-white border border-gray-200 rounded-2xl shadow-sm">
      <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4" id="issue-form">
        <?= acsrfField() ?><input type="hidden" name="action" value="issue_csv" id="issue-action"><input type="hidden" name="csv_mode" value="upload" id="csv-mode-input">

        <h2 class="cert-step"><span>1</span> Event &amp; design</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="text-xs font-semibold text-gray-600">Event <span class="text-rarl-red">*</span></label>
            <button type="button" onclick="document.getElementById('new-event').showModal()" class="text-[11px] font-semibold text-rarl-red hover:underline">+ New event</button>
          </div>
          <select name="event_id" required class="<?= $input ?>">
            <option value="">— Select event —</option>
            <?php foreach ($events as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $lastEvent === (int)$e['id'] ? 'selected' : '' ?>><?= ex($e['title']) ?> <?= $e['event_date'] ? '(' . date('d M Y', strtotime($e['event_date'])) . ')' : '' ?></option>
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
            <option value="" data-preview="<?= $defaultTpl ? (int)$defaultTpl['id'] : '' ?>">Default template<?= $defaultTpl ? ' — ' . ex($defaultTpl['name']) : '' ?></option>
            <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>" data-preview="<?= $t['id'] ?>"><?= ex($t['name']) ?></option><?php endforeach; ?>
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
        </div>

        <h2 class="cert-step pt-2"><span>2</span> Who gets one</h2>
        <div class="flex p-1 gap-1 bg-gray-100 rounded-xl" role="tablist" aria-label="Recipients">
          <button type="button" class="issue-tab flex-1 py-2 text-xs font-semibold rounded-lg" data-tab="all_members"><i class="fa-solid fa-users"></i> Registered members</button>
          <button type="button" class="issue-tab flex-1 py-2 text-xs font-semibold rounded-lg" data-tab="csv"><i class="fa-solid fa-file-csv"></i> CSV list</button>
          <button type="button" class="issue-tab flex-1 py-2 text-xs font-semibold rounded-lg" data-tab="single"><i class="fa-solid fa-user-plus"></i> One person</button>
        </div>

        <div data-pane="csv" class="space-y-3">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Participant list <span class="text-rarl-red">*</span></label>
            <!-- CSV sub-mode toggle -->
            <div class="flex border border-gray-200 rounded-lg p-0.5 gap-0.5 mb-2 bg-gray-50" role="tablist">
              <button type="button" id="csv-mode-upload" class="csv-mode-btn flex-1 py-1.5 text-[11px] font-semibold rounded-md bg-white shadow-sm text-gray-800" data-mode="upload"><i class="fa-solid fa-upload"></i> Upload file</button>
              <button type="button" id="csv-mode-paste"  class="csv-mode-btn flex-1 py-1.5 text-[11px] font-semibold rounded-md text-gray-500 hover:text-gray-700" data-mode="paste"><i class="fa-solid fa-paste"></i> Paste text</button>
            </div>

            <!-- Upload sub-pane -->
            <div id="csv-subpane-upload">
              <label id="csv-drop" class="block border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-5 text-center transition-colors cursor-pointer">
                <i class="fa-solid fa-file-csv text-2xl text-gray-400"></i>
                <p class="text-xs text-gray-600 mt-1.5" id="csv-label">Drop a CSV here or <span class="text-rarl-red font-semibold">browse</span></p>
                <p class="text-[10px] text-gray-400 mt-0.5">Needs <strong>name</strong> and <strong>email</strong> columns · max 500 rows</p>
                <input type="file" name="csv_file" id="csv-input" accept=".csv,text/csv" class="sr-only" data-no-chip/>
              </label>
              <div id="csv-preview" class="hidden mt-2"></div>
            </div>

            <!-- Paste sub-pane -->
            <div id="csv-subpane-paste" class="hidden">
              <textarea id="csv-paste-area" name="csv_paste" rows="7"
                placeholder="Paste CSV text here, e.g.:
name,email
Dr. Jane Smith,jane@university.edu
John Doe,john@lab.org"
                class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-xs font-mono bg-white focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-y"></textarea>
              <div id="csv-paste-preview" class="hidden mt-2"></div>
            </div>
          </div>
          <a href="data:text/csv;charset=utf-8,name%2Cemail%0ADr.%20Jane%20Smith%2Cjane%40university.edu%0AJohn%20Doe%2Cjohn%40lab.org" download="rarl_cert_template.csv" class="inline-block text-[11px] text-gray-500 hover:text-rarl-red"><i class="fa-solid fa-arrow-down"></i> Download sample CSV</a>
        </div>

        <div data-pane="single" class="hidden space-y-3">
          <div><label class="block text-xs font-semibold text-gray-600 mb-1.5">Full name <span class="text-rarl-red">*</span></label><input name="name" class="<?= $input ?>" placeholder="As it should appear on the certificate"/></div>
          <div><label class="block text-xs font-semibold text-gray-600 mb-1.5">Email <span class="text-rarl-red">*</span></label><input type="email" name="email" class="<?= $input ?>" placeholder="name@example.com"/></div>
        </div>

        <div data-pane="all_members" class="hidden space-y-3">
          <input type="hidden" name="pick_mode" id="pick-mode" value="all">
          <div class="flex border border-gray-200 rounded-lg p-0.5 gap-0.5 bg-gray-50" role="tablist">
            <button type="button" class="pick-mode-btn flex-1 py-1.5 text-[11px] font-semibold rounded-md bg-white shadow-sm text-gray-800" data-mode="all"><i class="fa-solid fa-users"></i> Everyone</button>
            <button type="button" class="pick-mode-btn flex-1 py-1.5 text-[11px] font-semibold rounded-md text-gray-500 hover:text-gray-700" data-mode="pick"><i class="fa-solid fa-list-check"></i> Choose people</button>
          </div>
          <div id="pick-pane" class="hidden space-y-2">
            <div class="relative">
              <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
              <input type="search" id="pick-search" placeholder="Search name or email" aria-label="Search members" class="<?= $input ?> pl-8"/>
            </div>
            <div class="flex items-center justify-between text-[11px]">
              <span id="pick-count" class="font-semibold text-gray-700">0 selected</span>
              <span class="flex gap-3"><button type="button" id="pick-all" class="font-semibold text-rarl-red hover:underline">Select shown</button><button type="button" id="pick-none" class="font-semibold text-gray-500 hover:underline">Clear</button></span>
            </div>
            <div id="pick-list" class="max-h-64 overflow-y-auto border border-gray-200 rounded-xl divide-y divide-gray-100 bg-white" role="group" aria-label="Members"></div>
          </div>
          <div id="all-pane">
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Member type</label>
            <select name="member_type" id="all-members-type" class="<?= $input ?>">
              <option value="">All members (individual + labs)</option>
              <option value="individual">Individual researchers only</option>
              <option value="lab">Research labs only</option>
            </select>
          </div>
          <div id="all-members-preview" class="p-3 rounded-xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-800 space-y-1">
            <div class="flex items-center gap-1.5 font-semibold"><i class="fa-solid fa-spinner fa-spin"></i> Loading member count…</div>
          </div>
          <p class="text-[10px] text-gray-400">Only <strong>active</strong> members with a <strong>verified email</strong> will receive a certificate. Members who already have a certificate for the selected event are skipped automatically.</p>
        </div>

        <h2 class="cert-step pt-2"><span>3</span> Send</h2>
        <label class="flex items-start gap-2.5 p-3 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer text-sm">
          <input type="checkbox" name="send_email" value="1" checked class="w-4 h-4 mt-0.5 accent-rarl-red"/>
          <span><span class="font-semibold text-gray-800">Email certificates now</span><span class="block text-xs text-gray-500">Untick to review first, then send from the list.</span></span>
        </label>

        <div class="flex flex-col sm:flex-row gap-2">
          <button type="submit" id="issue-btn" class="flex-1 min-h-[48px] py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl transition shadow">
            <i class="fa-solid fa-trophy"></i> <span>Generate & issue</span>
          </button>
          <a id="zip-btn" href="certificates.php?export=zip" aria-disabled="true" title="Download every certificate already issued for the selected event"
            class="min-h-[48px] px-5 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white text-sm font-bold text-gray-800 hover:border-gray-500 aria-disabled:opacity-50 aria-disabled:pointer-events-none">
            <i class="fa-solid fa-file-zipper"></i> <span>Download ZIP</span>
          </a>
        </div>
        <p class="text-xs text-gray-500 -mt-1" id="zip-note">Download ZIP gets the certificates already issued for the selected event, ready to print or share.</p>
      </form>
  </div>

  <aside class="space-y-3 lg:sticky lg:top-4">
    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm">
      <div class="flex items-center justify-between px-4 pt-4 pb-2">
        <h2 class="text-sm font-bold text-gray-900">Recently issued</h2>
        <a href="certificates.php?view=history" class="text-xs font-semibold text-rarl-red hover:underline">All history</a>
      </div>
      <?php if (!$recent): ?>
      <p class="px-4 pb-4 text-xs text-gray-500">Nothing issued yet. Batches you issue show up here.</p>
      <?php else: ?>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($recent as $b): ?>
        <li><a href="certificates.php?view=history&amp;event=<?= (int)$b['id'] ?>" class="flex items-center gap-3 px-4 py-3 min-h-[44px] hover:bg-gray-50 rounded-xl">
          <span class="min-w-0 flex-1">
            <span class="block text-xs font-semibold text-gray-900 truncate"><?= ex($b['title']) ?></span>
            <span class="block text-[11px] text-gray-500"><?= (int)$b['n'] ?> issued · <?= date('d M', strtotime($b['last_at'])) ?></span>
          </span>
          <?php if ((int)$b['unsent']): ?><span class="rarl-badge rarl-badge-amber"><i class="fa-solid fa-clock"></i> <?= (int)$b['unsent'] ?> not sent</span>
          <?php else: ?><span class="rarl-badge rarl-badge-green"><i class="fa-solid fa-check"></i> Sent</span><?php endif; ?>
        </a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <p class="px-1 text-[11px] text-gray-500">People who already have a certificate for the chosen event are always skipped, so re-running a batch is safe.</p>
  </aside>
</section>

<!-- ══ HISTORY VIEW ══ -->
<section id="view-history" class="<?= $view === 'history' ? '' : 'hidden' ?>">
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php foreach ([
      ['Total issued', (int)$stats['total'], 'fa-trophy', 'text-gray-900', ''],
      ['Emailed', (int)$stats['emailed'], 'fa-envelope-circle-check', 'text-green-600', 'emailed'],
      ['Not sent yet', (int)$stats['unsent'], 'fa-clock', 'text-amber-600', 'unsent'],
      ['This month', (int)$stats['month'], 'fa-calendar-check', 'text-gray-900', ''],
  ] as [$label, $val, $icon, $tone, $st]): ?>
  <a href="<?= ex($st ? $pageUrl(['status' => $st, 'page' => null]) : 'certificates.php?view=history') ?>" class="rarl-stat <?= $st && $filterStatus === $st ? 'is-active' : '' ?>">
    <span class="rarl-stat-label"><i class="fa-solid <?= $icon ?> <?= $tone ?>"></i> <?= $label ?></span>
    <span class="rarl-stat-value <?= $tone ?>"><?= number_format($val) ?></span>
  </a>
  <?php endforeach; ?>
</div>
  <div class="min-w-0">
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <form method="GET" class="px-4 py-3 border-b border-gray-100 flex flex-wrap gap-2 items-center"><input type="hidden" name="view" value="history">
        <div class="relative flex-1 min-w-[180px]">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
          <input type="search" name="q" value="<?= ex($search) ?>" placeholder="Search name, email, certificate no…  ( / )" class="w-full pl-8 pr-3 py-2 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <select name="event" onchange="this.form.submit()" class="px-3 py-2 text-xs border border-gray-300 rounded-lg max-w-[200px]">
          <option value="">All events</option>
          <?php foreach ($events as $e): ?><option value="<?= $e['id'] ?>" <?= $filterEvent === (int)$e['id'] ? 'selected':'' ?>><?= ex(mb_strimwidth($e['title'],0,35,'…')) ?></option><?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()" class="px-3 py-2 text-xs border border-gray-300 rounded-lg">
          <?php foreach (['' => 'Any status', 'emailed' => 'Emailed', 'unsent' => 'Not sent', 'nopdf' => 'Missing PDF', 'membership' => 'Membership'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="px-3 py-2 bg-gray-900 text-white text-xs font-semibold rounded-lg">Search</button>
        <?php if ($search || $filterEvent || $filterStatus): ?><a href="certificates.php?view=history" class="px-2 py-2 text-xs text-gray-500 hover:text-rarl-red">Clear</a><?php endif; ?>
        <div class="w-full flex flex-wrap items-center gap-2 pt-1 text-xs text-gray-500">
          <label class="flex items-center gap-1.5">Sort
            <select name="sort" onchange="this.form.submit()" class="px-2.5 py-1.5 text-xs border border-gray-300 rounded-lg"><?php foreach (CERT_SORTS as $k => [$l]): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
          <label class="flex items-center gap-1.5">Group
            <select name="group" onchange="this.form.submit()" class="px-2.5 py-1.5 text-xs border border-gray-300 rounded-lg"><?php foreach (['' => 'None', 'event' => 'By event', 'status' => 'By status'] as $k => $l): ?><option value="<?= $k ?>" <?= $groupBy === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
          <label class="flex items-center gap-1.5">Show
            <select name="per" onchange="this.form.submit()" class="px-2.5 py-1.5 text-xs border border-gray-300 rounded-lg"><?php foreach ([50, 100, 250, 500] as $n): ?><option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> per page</option><?php endforeach; ?></select></label>
          <span class="ms-auto hidden sm:inline">Tick certificates to email, download, regenerate or delete them together.</span>
        </div>
      </form>

      <?= bulkFormOpen('', ['return_qs' => $qs, 'all_matching' => '0']) ?>
      <?php if ($total > count($certificates)): ?>
      <div id="all-matching" hidden class="mx-4 mt-3 px-4 py-2.5 rounded-xl bg-blue-50 text-blue-900 text-xs flex flex-wrap items-center gap-2">
        <span data-am-text>All <?= count($certificates) ?> on this page are selected.</span>
        <button type="button" class="font-semibold underline" data-am-on>Select all <?= number_format($total) ?> matching certificates</button>
        <button type="button" class="font-semibold underline" data-am-off hidden>Clear selection</button>
      </div>
      <?php endif; ?>
      <div class="px-4 pt-3"><?= bulkBar([
          ['label'=>'<i class="fa-solid fa-paper-plane"></i> Send unsent','op'=>'send','class'=>'bg-amber-600 hover:bg-amber-500','attrs'=>'data-email-bulk="send"'],
          ['label'=>'<i class="fa-solid fa-envelope"></i> Resend','op'=>'resend','class'=>'bg-gray-700 hover:bg-gray-600','attrs'=>'data-email-bulk="resend"'],
          ['label'=>'<i class="fa-solid fa-user-plus"></i> Invite non-members','op'=>'invite','class'=>'bg-green-700 hover:bg-green-600','confirm'=>'Email a personal join link to the selected people who are not members yet? Existing members are skipped.'],
          ['label'=>'<i class="fa-solid fa-file-zipper"></i> Download ZIP','op'=>'zip','class'=>'bg-gray-700 hover:bg-gray-600','no_loading'=>true],
          ['label'=>'<i class="fa-solid fa-rotate"></i> Regenerate','op'=>'regen','class'=>'bg-blue-600 hover:bg-blue-500','attrs'=>'data-regen-bulk'],
          ['label'=>'<i class="fa-solid fa-trash"></i> Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Delete the selected certificates? Their PDFs are removed and their verification links stop working. This cannot be undone.'],
      ]) ?></div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-y border-gray-200">
            <tr>
              <th class="px-4 py-3 w-8"><?= bulkSelectAllCheckbox() ?></th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Recipient</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Certificate · Issued</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Event</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Membership</th>
              <th class="text-left px-3 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Status</th>
              <th class="text-right px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if (empty($certificates)): ?>
            <tr><td colspan="7" class="py-14 text-center">
              <div class="w-12 h-12 mx-auto rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center text-xl mb-2"><i class="fa-solid fa-trophy"></i></div>
              <p class="text-sm text-gray-500"><?= $search || $filterEvent || $filterStatus ? 'No certificates match these filters.' : 'No certificates yet. <a href="certificates.php" class="font-semibold text-rarl-red hover:underline">Issue your first batch</a>' ?></p>
            </td></tr>
            <?php endif; ?>
            <?php
              $statusOf = fn($c) => !$c['pdf_path'] ? 'Missing PDF' : ($c['emailed_at'] ? 'Emailed' : 'Not sent yet');
              $groupKey = fn($c) => $groupBy === 'event' ? (($c['cert_type'] ?? '') === 'membership' ? 'membership' : 'e' . (int)$c['event_id']) : ($groupBy === 'status' ? $statusOf($c) : '');
              $groupCounts = $groupBy ? array_count_values(array_map($groupKey, $certificates)) : [];
              $lastGroup = null;
            ?>
            <?php foreach ($certificates as $c): $verify = CERT_VERIFY_URL . '?id=' . $c['uuid']; $isMembership = ($c['cert_type'] ?? 'event') === 'membership';
              $gk = $groupKey($c);
              if ($groupBy && $gk !== $lastGroup): $lastGroup = $gk;
                $gLabel = $groupBy === 'status' ? $gk : ($gk === 'membership' ? 'Membership certificates' : ($c['event_title'] ?: 'No event')); ?>
            <tr class="bg-gray-50/80 border-t-2 border-gray-200" data-group-head="<?= ex($gk) ?>">
              <td class="px-4 py-2.5"><input type="checkbox" class="accent-rarl-red w-4 h-4" data-group-select="<?= ex($gk) ?>" aria-label="Select all in <?= ex($gLabel) ?>"/></td>
              <td colspan="6" class="px-3 py-2.5">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold text-gray-900 text-sm"><?= ex($gLabel) ?></span>
                  <span class="text-xs text-gray-500"><?= (int)$groupCounts[$gk] ?> on this page<?= $groupBy === 'event' && $gk !== 'membership' && $c['event_date'] ? ' · ' . date('d M Y', strtotime($c['event_date'])) : '' ?></span>
                  <?php if ($groupBy === 'event' && $gk !== 'membership' && $c['event_id']): ?>
                  <a href="certificates.php?export=zip&event=<?= (int)$c['event_id'] ?>" class="ms-auto inline-flex items-center gap-1.5 text-xs font-semibold text-gray-700 hover:text-rarl-red"><i class="fa-solid fa-file-zipper"></i> Download ZIP</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endif; ?>
            <tr class="hover:bg-gray-50" data-group="<?= ex($gk) ?>">
              <td class="px-4 py-3" data-etype="<?= ex($c['event_type'] ?? '') ?>" data-has-pdf="<?= $c['pdf_path'] ? 1 : 0 ?>" data-sent="<?= $c['emailed_at'] ? 1 : 0 ?>"><?= bulkRowCheckbox((int)$c['id']) ?></td>
              <td class="px-3 py-3" data-sort="<?= ex($c['recipient_name']) ?>">
                <div class="flex items-center gap-2.5 min-w-0">
                  <span class="w-8 h-8 rounded-full bg-rarl-red/10 text-rarl-red font-bold text-xs flex items-center justify-center flex-shrink-0"><?= ex(mb_strtoupper(mb_substr(preg_replace('/^(dr|prof|mr|mrs|ms)\.?\s+/i', '', $c['recipient_name']), 0, 1))) ?></span>
                  <div class="min-w-0"><p class="font-medium text-gray-900 text-xs truncate max-w-[150px]"><?= ex(properName($c['recipient_name'])) ?></p><p class="text-[10px] text-gray-400 truncate max-w-[150px]"><?= ex($c['recipient_email']) ?></p></div>
                </div>
              </td>
              <td class="px-3 py-3" data-sort="<?= ex($c['issued_at']) ?>">
                <button type="button" class="copy-btn whitespace-nowrap font-mono text-[10px] text-gray-700 bg-gray-100 hover:bg-gray-200 px-2 py-0.5 rounded" data-copy="<?= ex($c['certificate_no']) ?>" title="Copy certificate number"><?= ex($c['certificate_no']) ?></button>
                <span class="block text-[10px] text-gray-400 mt-0.5 whitespace-nowrap" title="Issued <?= ex($c['issued_at']) ?>">Issued <?= date('d M Y', strtotime($c['issued_at'])) ?></span>
              </td>
              <td class="px-3 py-3 text-[11px] text-gray-600"><?= $isMembership ? '<span class="font-semibold text-purple-600">Membership</span>' : '<span class="line-clamp-2 max-w-[150px] block" title="' . ex($c['event_title'] ?? '') . '">' . ex(mb_strimwidth($c['event_title'] ?? '', 0, 40, '…')) . '</span>' ?></td>
              <td class="px-3 py-3" data-sort="<?= ex($c['member_status'] ?? 'zz') ?>">
                <?php $ms = $c['member_status'] ?? null; if ($ms === 'active'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-50 text-green-700 whitespace-nowrap"><i class="fa-solid fa-check"></i> Member</span>
                <?php elseif ($ms): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 whitespace-nowrap"><?= ex(ucfirst($ms)) ?> member</span>
                <?php else: ?>
                <span class="block text-[10px] text-gray-500 whitespace-nowrap">Not a member</span>
                <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="invite_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>"><input type="hidden" name="return_qs" value="<?= ex($qs) ?>">
                  <button type="submit" class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-green-700 hover:underline whitespace-nowrap"><i class="fa-solid fa-user-plus"></i> Invite</button>
                </form>
                <?php endif; ?>
              </td>
              <td class="px-3 py-3">
                <?php if (!$c['pdf_path']): ?><span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] font-semibold text-red-700 bg-red-50 px-2 py-0.5 rounded-full"><i class="fa-solid fa-file-circle-exclamation"></i> No PDF</span>
                <?php elseif ($c['emailed_at']): ?><span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded-full" title="<?= ex($c['emailed_at']) ?>"><i class="fa-solid fa-check"></i> Sent <?= date('d M', strtotime($c['emailed_at'])) ?></span>
                <?php else: ?><span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full"><i class="fa-solid fa-clock"></i> Not sent</span><?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-0.5">
                  <?php if ($c['pdf_path']): ?>
                  <a href="../uploads/certificates/<?= urlencode($c['pdf_path']) ?>" target="_blank" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="Open PDF"><i class="fa-solid fa-file-pdf"></i></a>
                  <?php endif; ?>
                  <button type="button" class="copy-btn w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900" data-copy="<?= ex($verify) ?>" title="Copy verification link"><i class="fa-solid fa-link"></i></button>
                  <?php if ($c['pdf_path']): ?>
                  <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="email_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>"><input type="hidden" name="return_qs" value="<?= ex($qs) ?>">
                    <button type="button" data-email-one="<?= $c['id'] ?>" data-email-type="<?= ex($c['event_type'] ?? '') ?>" data-email-to="<?= ex($c['recipient_email']) ?>" data-email-sent="<?= $c['emailed_at'] ? 1 : 0 ?>" class="w-8 h-8 inline-flex items-center justify-center rounded-lg <?= $c['emailed_at'] ? 'text-gray-500 hover:bg-gray-100' : 'text-amber-600 bg-amber-50 hover:bg-amber-100' ?>" title="<?= $c['emailed_at'] ? 'Resend email' : 'Send email' ?>"><i class="fa-solid fa-paper-plane"></i></button>
                  </form>
                  <?php endif; ?>
                  <?php if (!$isMembership): ?>
                  <button type="button" data-regen="<?= $c['id'] ?>" data-regen-tpl="<?= (int)$c['template_id'] ?>" data-regen-name="<?= ex($c['recipient_name']) ?>" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900" title="Regenerate certificate"><i class="fa-solid fa-rotate"></i></button>
                  <?php endif; ?>
                  <form method="POST" class="inline" data-confirm="Delete the certificate for <?= ex($c['recipient_name']) ?>? Its verification link will stop working."><?= acsrfField() ?><input type="hidden" name="action" value="delete_cert"><input type="hidden" name="cert_id" value="<?= $c['id'] ?>"><input type="hidden" name="return_qs" value="<?= ex($qs) ?>">
                    <button type="submit" class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
        <span><?= $total ? 'Showing ' . number_format(($page - 1) * $perPage + 1) . '–' . number_format(min($total, $page * $perPage)) . ' of ' . number_format($total) : '0 results' ?></span>
        <?php if ($pages > 1): ?>
        <div class="flex items-center gap-1">
          <a href="<?= ex($pageUrl(['page' => $page - 1])) ?>" class="px-2.5 py-1.5 rounded-lg border border-gray-200 <?= $page <= 1 ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50' ?>"><i class="fa-solid fa-chevron-left"></i></a>
          <span class="px-2">Page <?= $page ?> of <?= $pages ?></span>
          <a href="<?= ex($pageUrl(['page' => $page + 1])) ?>" class="px-2.5 py-1.5 rounded-lg border border-gray-200 <?= $page >= $pages ? 'pointer-events-none opacity-40' : 'hover:bg-gray-50' ?>"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<dialog id="new-event" class="rarl-dialog w-full max-w-md">
  <form method="POST" class="p-6 space-y-3">
    <?= acsrfField() ?><input type="hidden" name="action" value="create_event">
    <div class="flex items-center justify-between mb-2"><h2 class="font-heading font-bold text-lg">New event</h2><button type="button" onclick="this.closest('dialog').close()" class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
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

<style>
  .cert-step{display:flex;align-items:center;gap:.6rem;font-size:.95rem;font-weight:700;color:#0f172a}
  .cert-step>span{display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border-radius:999px;background:#0f172a;color:#fff;font-size:.75rem}
  .issue-tab{color:#475569}.issue-tab.on{background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(15,23,42,.12)}
</style>
<?= bulkBarScript() ?>
<script>
(function () {
  const rows = () => [...document.querySelectorAll('.row-check')];
  const fire = el => el.dispatchEvent(new Event('change', {bubbles: true}));
  // Group checkbox selects that group's rows.
  document.querySelectorAll('[data-group-select]').forEach(g => g.addEventListener('change', () => {
    document.querySelectorAll('tr[data-group="' + CSS.escape(g.dataset.groupSelect) + '"] .row-check').forEach(c => { c.checked = g.checked; });
    rows().length && fire(rows()[0]);
  }));
  // Keep the top and group checkboxes in step with the rows, whichever was clicked.
  document.addEventListener('change', e => {
    if (!e.target.classList.contains('row-check') && e.target.id !== 'select-all' && !e.target.dataset.groupSelect) return;
    const top = document.getElementById('select-all'), rs = rows();
    if (top && e.target !== top) { top.checked = rs.length && rs.every(c => c.checked); top.indeterminate = !top.checked && rs.some(c => c.checked); }
    if (top && e.target === top) top.indeterminate = false;
    document.querySelectorAll('[data-group-select]').forEach(g => {
      const rs = [...document.querySelectorAll('tr[data-group="' + CSS.escape(g.dataset.groupSelect) + '"] .row-check')];
      g.checked = rs.length && rs.every(c => c.checked); g.indeterminate = !g.checked && rs.some(c => c.checked);
    });
  });
  // "Select all N matching": bulk actions apply to every certificate matching the filters, across pages.
  const bar = document.getElementById('all-matching'), flag = document.querySelector('#bulk-form [name=all_matching]'), all = document.getElementById('select-all');
  if (!bar || !flag || !all) return;
  const count = document.getElementById('bulk-count');
  function setAll(on) {
    flag.value = on ? '1' : '0';
    bar.querySelector('[data-am-on]').hidden = on; bar.querySelector('[data-am-off]').hidden = !on;
    bar.querySelector('[data-am-text]').textContent = on ? 'All <?= number_format($total) ?> certificates matching your filters are selected.' : 'All <?= count($certificates) ?> on this page are selected.';
    if (count) count.textContent = on ? '<?= number_format($total) ?>' : rows().filter(c => c.checked).length;
  }
  document.addEventListener('change', e => {
    if (!e.target.classList.contains('row-check') && e.target !== all) return;
    const allOnPage = rows().length && rows().every(c => c.checked);
    bar.hidden = !allOnPage; if (!allOnPage) setAll(false); else if (flag.value === '1') setAll(true);
  });
  bar.querySelector('[data-am-on]').addEventListener('click', () => setAll(true));
  bar.querySelector('[data-am-off]').addEventListener('click', () => { rows().forEach(c => { c.checked = false; }); all.checked = false; setAll(false); bar.hidden = true; fire(all); });
})();
</script>
<script>
(function() {
  // Copy buttons
  document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); rarlToast('Copied to clipboard', 'success'); } catch (e) { prompt('Copy:', b.dataset.copy); }
  }));

  // Tabs: CSV vs single recipient vs all members
  const form = document.getElementById('issue-form'), actionInput = document.getElementById('issue-action');
  const btnLabel = document.querySelector('#issue-btn span');
  // Download ZIP follows the selected event.
  (function () {
    const sel = document.querySelector('#issue-form [name=event_id]'), zip = document.getElementById('zip-btn');
    if (!sel || !zip) return;
    const sync = () => { const v = sel.value; zip.href = 'certificates.php?export=zip&event=' + encodeURIComponent(v); zip.setAttribute('aria-disabled', v ? 'false' : 'true'); };
    sel.addEventListener('change', sync); sync();
  })();
  let pickMode = 'all', pickN = 0, pickLoadedFor = null;
  let csvRows = 0, allMembersNew = 0, countXhr = null, csvMode = 'upload';
  try { csvMode = localStorage.getItem('rarl-csv-mode') || 'upload'; } catch(e) {}
  function setTab(tab) {
    document.querySelectorAll('.issue-tab').forEach(t => { t.classList.toggle('on', t.dataset.tab === tab); t.setAttribute('aria-selected', t.dataset.tab === tab); });
    form.querySelectorAll('[data-pane]').forEach(p => p.classList.toggle('hidden', p.dataset.pane !== tab));
    if (tab === 'all_members') {
      actionInput.value = 'issue_all_members';
      refreshMemberCount();
    } else {
      actionInput.value = tab === 'csv' ? 'issue_csv' : 'issue_single';
    }
    form.querySelector('[name=name]').required = form.querySelector('[name=email]').required = tab === 'single';
    // csv-input required only when in CSV tab AND in upload mode
    updateCsvInputRequired();
    updateBtn(tab);
    try { localStorage.setItem('rarl-cert-tab', tab); } catch (e) {}
  }
  function updateBtn(tab) {
    tab = tab || actionInput.value.replace('issue_','');
    const ib = document.getElementById('issue-btn');
    const memN = pickMode === 'pick' ? pickN : allMembersNew;
    if (tab === 'all_members') { ib.dataset.confirm = 'Issue certificates to ' + (memN || (pickMode === 'pick' ? 'the' : 'all')) + ' ' + (pickMode === 'pick' ? 'selected' : 'registered') + ' member(s)' + (form.querySelector('[name=send_email]').checked ? ' and email them now' : '') + '?'; ib.dataset.confirmOk = 'Issue'; }
    else { delete ib.dataset.confirm; delete ib.dataset.confirmOk; }
    if (tab === 'single') btnLabel.textContent = 'Issue certificate';
    else if (tab === 'all_members') btnLabel.textContent = memN > 0 ? 'Issue ' + memN + ' certificate' + (memN === 1 ? '' : 's') : (pickMode === 'pick' ? 'Select members to issue' : 'Issue certificates to all members');
    else btnLabel.textContent = csvRows ? 'Issue ' + csvRows + ' certificate' + (csvRows === 1 ? '' : 's') : 'Generate & issue';
  }
  document.querySelectorAll('.issue-tab').forEach(t => t.addEventListener('click', () => setTab(t.dataset.tab)));
  form.querySelector('[name=send_email]').addEventListener('change', () => updateBtn());
  let saved = 'all_members'; try { saved = localStorage.getItem('rarl-cert-tab') || 'all_members'; } catch (e) {}
  setTab(saved);

  // All-members: live count preview
  function refreshMemberCount() {
    const eventSel = form.querySelector('[name=event_id]');
    const typeSel  = document.getElementById('all-members-type');
    const preview  = document.getElementById('all-members-preview');
    if (!eventSel || !typeSel || !preview) return;
    const eventId   = eventSel.value;
    const memberType = typeSel.value;
    preview.innerHTML = '<div class="flex items-center gap-1.5 font-semibold"><i class="fa-solid fa-spinner fa-spin"></i> Loading member count…</div>';
    preview.className = 'p-3 rounded-xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-800 space-y-1';
    if (countXhr) countXhr.abort();
    const params = new URLSearchParams({action:'member_count_preview', event_id: eventId, member_type: memberType});
    countXhr = new XMLHttpRequest();
    countXhr.open('GET', 'certificates.php?' + params);
    countXhr.onload = function() {
      try {
        const d = JSON.parse(this.responseText);
        allMembersNew = d.new || 0;
        updateBtn('all_members');
        if (!eventId) {
          preview.innerHTML = '<div class="flex items-center gap-1.5"><i class="fa-solid fa-circle-info"></i> Select an event above to see how many members will receive a certificate.</div>';
          preview.className = 'p-3 rounded-xl bg-gray-50 border border-gray-200 text-xs text-gray-600 space-y-1';
        } else if (d.total === 0) {
          preview.innerHTML = '<div class="flex items-center gap-1.5"><i class="fa-solid fa-triangle-exclamation"></i> No active verified members found for the selected type.</div>';
          preview.className = 'p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-700 space-y-1';
        } else {
          const skipped = d.already ? '<span class="text-amber-700"><i class="fa-solid fa-forward"></i> ' + d.already + ' already have a certificate for this event (will be skipped)</span>' : '';
          preview.innerHTML =
            '<div class="flex items-center gap-1.5 font-bold text-indigo-900"><i class="fa-solid fa-users"></i> ' + d.total + ' registered member' + (d.total === 1 ? '' : 's') + ' found</div>' +
            (d.new > 0 ? '<span class="text-green-700 font-semibold"><i class="fa-solid fa-check"></i> ' + d.new + ' will receive a new certificate</span>' : '') +
            (skipped ? skipped : '') +
            (d.new === 0 ? '<div class="text-amber-700"><i class="fa-solid fa-circle-exclamation"></i> All members already have a certificate for this event.</div>' : '');
          preview.className = 'p-3 rounded-xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-800 space-y-1';
        }
      } catch(e) {}
    };
    countXhr.send();
  }
  document.getElementById('all-members-type')?.addEventListener('change', refreshMemberCount);
  form.querySelector('[name=event_id]')?.addEventListener('change', function() {
    if (actionInput.value === 'issue_all_members') refreshMemberCount();
  });

  // Members tab: "Choose people" picker
  const pickList = document.getElementById('pick-list'), pickSearch = document.getElementById('pick-search');
  const escP = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  function setPickMode(mode) {
    pickMode = mode;
    document.getElementById('pick-mode').value = mode;
    document.getElementById('pick-pane').classList.toggle('hidden', mode !== 'pick');
    document.getElementById('all-pane').classList.toggle('hidden', mode === 'pick');
    document.getElementById('all-members-preview').classList.toggle('hidden', mode === 'pick');
    document.querySelectorAll('.pick-mode-btn').forEach(b => b.className = 'pick-mode-btn flex-1 py-1.5 text-[11px] font-semibold rounded-md ' + (b.dataset.mode === mode ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700'));
    if (mode === 'pick') loadPickList();
    updateBtn('all_members');
  }
  function syncPickCount() {
    const boxes = [...pickList.querySelectorAll('input[name="member_ids[]"]:checked')];
    pickN = boxes.filter(b => b.dataset.has !== '1').length;
    const dup = boxes.length - pickN;
    document.getElementById('pick-count').textContent = boxes.length + ' selected' + (dup ? ' · ' + dup + ' already issued (skipped)' : '');
    updateBtn('all_members');
  }
  function loadPickList() {
    const eventId = form.querySelector('[name=event_id]').value;
    if (pickLoadedFor === eventId) return;
    pickLoadedFor = eventId;
    const keep = new Set([...pickList.querySelectorAll('input:checked')].map(b => b.value));
    pickList.innerHTML = '<div class="p-3 text-xs text-gray-500"><i class="fa-solid fa-spinner fa-spin"></i> Loading members…</div>';
    fetch('certificates.php?' + new URLSearchParams({action: 'member_list', event_id: eventId})).then(r => r.json()).then(list => {
      pickList.innerHTML = list.length ? list.map(m =>
        '<label class="flex items-center gap-2.5 px-3 py-2 min-h-[44px] cursor-pointer hover:bg-gray-50" data-q="' + escP((m.name + ' ' + m.email).toLowerCase()) + '">' +
        '<input type="checkbox" name="member_ids[]" value="' + m.id + '" data-has="' + (m.has ? 1 : 0) + '"' + (keep.has(String(m.id)) ? ' checked' : '') + ' class="w-4 h-4 accent-rarl-red flex-shrink-0">' +
        '<span class="min-w-0 flex-1"><span class="block text-xs font-medium text-gray-900 truncate">' + escP(m.name) + '</span><span class="block text-[11px] text-gray-500 truncate">' + escP(m.email) + '</span></span>' +
        (m.has ? '<span class="text-[10px] font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fa-solid fa-check"></i> Has one</span>' : (m.type === 'lab' ? '<span class="text-[10px] font-semibold text-gray-600 bg-gray-100 px-2 py-0.5 rounded-full">Lab</span>' : '')) +
        '</label>').join('') : '<div class="p-3 text-xs text-gray-500">No active, verified members yet.</div>';
      filterPick(); syncPickCount();
    }).catch(() => { pickLoadedFor = null; pickList.innerHTML = '<div class="p-3 text-xs text-red-700">Could not load members. <button type="button" class="underline font-semibold" id="pick-retry">Try again</button></div>'; });
  }
  function filterPick() {
    const q = pickSearch.value.trim().toLowerCase();
    pickList.querySelectorAll('label[data-q]').forEach(l => l.classList.toggle('hidden', !!q && !l.dataset.q.includes(q)));
  }
  document.querySelectorAll('.pick-mode-btn').forEach(b => b.addEventListener('click', () => setPickMode(b.dataset.mode)));
  pickSearch.addEventListener('input', filterPick);
  pickSearch.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
  pickList.addEventListener('change', syncPickCount);
  pickList.addEventListener('click', e => { if (e.target.id === 'pick-retry') loadPickList(); });
  document.getElementById('pick-all').addEventListener('click', () => { pickList.querySelectorAll('label[data-q]:not(.hidden) input').forEach(b => b.checked = true); syncPickCount(); });
  document.getElementById('pick-none').addEventListener('click', () => { pickList.querySelectorAll('input:checked').forEach(b => b.checked = false); syncPickCount(); });
  form.querySelector('[name=event_id]').addEventListener('change', () => { if (pickMode === 'pick') loadPickList(); });
  form.addEventListener('submit', e => {
    if (actionInput.value === 'issue_all_members' && pickMode === 'pick' && !pickList.querySelector('input:checked')) {
      e.preventDefault(); e.stopImmediatePropagation(); rarlToast('Tick at least one member first.', 'error');
    }
  }, true);

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

  // ── CSV sub-mode toggle: Upload vs Paste ─────────────────────────────────
  function updateCsvInputRequired() {
    const isActive = actionInput.value === 'issue_csv';
    document.getElementById('csv-input').required = isActive && csvMode === 'upload';
  }

  function setCsvMode(mode) {
    csvMode = mode;
    document.getElementById('csv-mode-input').value = mode;
    const isUpload = mode === 'upload';
    document.getElementById('csv-subpane-upload').classList.toggle('hidden', !isUpload);
    document.getElementById('csv-subpane-paste').classList.toggle('hidden', isUpload);
    document.querySelectorAll('.csv-mode-btn').forEach(b => {
      const active = b.dataset.mode === mode;
      b.className = 'csv-mode-btn flex-1 py-1.5 text-[11px] font-semibold rounded-md ' +
        (active ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500 hover:text-gray-700');
    });
    updateCsvInputRequired();
    try { localStorage.setItem('rarl-csv-mode', mode); } catch(e) {}
  }
  document.querySelectorAll('.csv-mode-btn').forEach(b => b.addEventListener('click', () => setCsvMode(b.dataset.mode)));
  setCsvMode(csvMode);

  // Paste area: live preview (reuses parseCsv + preview rendering)
  const pasteArea  = document.getElementById('csv-paste-area');
  const pastePrev  = document.getElementById('csv-paste-preview');
  let pasteTimer;
  function previewPaste(text) {
    if (!text.trim()) { pastePrev.classList.add('hidden'); csvRows = 0; updateBtn('csv'); return; }
    const rows = parseCsv(text);
    const header = (rows.shift() || []).map(h => h.trim().toLowerCase());
    const ni = NAME_H.map(h => header.indexOf(h)).find(i => i >= 0) ?? -1;
    const ei = EMAIL_H.map(h => header.indexOf(h)).find(i => i >= 0) ?? -1;
    if (ni < 0 || ei < 0) {
      csvRows = 0; updateBtn('csv');
      pastePrev.innerHTML = '<div class="p-3 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700"><i class="fa-solid fa-triangle-exclamation"></i> Missing ' + (ni < 0 ? '<strong>name</strong>' : '') + (ni < 0 && ei < 0 ? ' and ' : '') + (ei < 0 ? '<strong>email</strong>' : '') + ' column. Found: ' + esc(header.filter(Boolean).join(', ') || 'nothing') + '</div>';
      pastePrev.classList.remove('hidden'); return;
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
    updateBtn('csv');
    let html = '<div class="flex flex-wrap gap-1.5 mb-2 text-[11px]"><span class="px-2 py-0.5 rounded-full bg-green-50 text-green-700 font-semibold">' + csvRows + ' ready</span>' +
      (bad ? '<span class="px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-semibold">' + bad + ' invalid</span>' : '') +
      (dup ? '<span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-semibold">' + dup + ' duplicate</span>' : '') +
      (rows.length > 500 ? '<span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 font-semibold">first 500 of ' + rows.length + '</span>' : '') + '</div>';
    html += '<div class="max-h-48 overflow-y-auto border border-gray-100 rounded-xl"><table class="w-full text-[11px]" data-no-sort><tbody class="divide-y divide-gray-100">' +
      data.map(r => '<tr class="' + (r.ok ? '' : 'bg-red-50/60') + '"><td class="px-2.5 py-1.5 text-gray-800">' + (esc(r.name) || '<em class="text-gray-400">—</em>') + '</td><td class="px-2.5 py-1.5 text-gray-500 truncate max-w-[140px]">' + esc(r.email) + '</td><td class="px-2 py-1.5 text-right">' + (r.ok ? '<i class="fa-solid fa-check text-green-500"></i>' : '<span class="text-red-600">' + r.why + '</span>') + '</td></tr>').join('') +
      '</tbody></table></div><p class="text-[10px] text-gray-400 mt-1.5">People who already have a certificate for this event are skipped automatically.</p>';
    pastePrev.innerHTML = html; pastePrev.classList.remove('hidden');
    markMembers(data.filter(r => r.ok));
  }
  // Tag rows that belong to registered members (via Check a list).
  let memberReq = 0;
  async function markMembers(list) {
    const id = ++memberReq;
    if (!list.length) return;
    const fd = new FormData();
    fd.append('acsrf', document.querySelector('#issue-form [name=acsrf]').value);
    fd.append('json', '1');
    fd.append('list', list.map(r => r.email).join('\n'));
    let res;
    try { res = await (await fetch('member-check.php', {method: 'POST', body: fd})).json(); } catch (e) { return; }
    if (id !== memberReq) return;
    const st = {}; res.forEach(r => st[r.email] = r.status);
    let n = 0;
    pastePrev.querySelectorAll('tbody tr').forEach(tr => {
      const td = tr.children[1], s = st[(td?.textContent || '').trim().toLowerCase()];
      if (!s) return;
      n++;
      td.insertAdjacentHTML('beforeend', ' <span class="ml-1 px-1.5 rounded-full text-[10px] font-semibold ' + (s === 'active' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700') + '">' + (s === 'active' ? 'Member' : s) + '</span>');
    });
    pastePrev.querySelector('div')?.insertAdjacentHTML('beforeend', '<span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold">' + n + ' registered · ' + (list.length - n) + ' not</span>');
  }
  pasteArea?.addEventListener('input', () => { clearTimeout(pasteTimer); pasteTimer = setTimeout(() => previewPaste(pasteArea.value), 350); });

  form.addEventListener('submit', e => {
    if (actionInput.value !== 'issue_csv') return;
    if (csvMode === 'upload' && csvInput.files[0] && csvRows === 0) {
      e.preventDefault(); e.stopImmediatePropagation(); rarlToast('The CSV has no valid rows to issue.', 'error');
    } else if (csvMode === 'paste' && !pasteArea?.value.trim()) {
      e.preventDefault(); e.stopImmediatePropagation(); rarlToast('Paste some CSV text first.', 'error');
    } else if (csvMode === 'paste' && csvRows === 0 && pasteArea?.value.trim()) {
      e.preventDefault(); e.stopImmediatePropagation(); rarlToast('The pasted CSV has no valid rows to issue.', 'error');
    }
  }, true);
})();
</script>

<?php if ($view === 'history'):
  adminModalOpen('regen-modal', 'Regenerate certificate', '<span id="regen-sub"></span>', ['form' => true, 'width' => '640px', 'form_attrs' => 'id="regen-form"']); ?>
  <input type="hidden" name="action" value="regen_cert">
  <input type="hidden" name="cert_id" id="regen-id">
  <input type="hidden" name="return_qs" value="<?= ex($qs) ?>">
  <fieldset class="grid sm:grid-cols-2 gap-2">
    <legend class="sr-only">Design</legend>
    <label class="flex gap-3 p-3 rounded-xl border border-gray-200 cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-red-50/40">
      <input type="radio" name="regen_mode" value="keep" checked class="mt-1 accent-rarl-red">
      <span><span class="block text-sm font-semibold text-gray-900">Existing design</span><span class="block text-xs text-gray-500" id="regen-cur">Rebuild with the design it already uses.</span></span>
    </label>
    <label class="flex gap-3 p-3 rounded-xl border border-gray-200 cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-red-50/40">
      <input type="radio" name="regen_mode" value="new" class="mt-1 accent-rarl-red">
      <span><span class="block text-sm font-semibold text-gray-900">New design</span><span class="block text-xs text-gray-500">Pick a design to replace the old one.</span></span>
    </label>
  </fieldset>
  <div id="regen-new" class="hidden space-y-2">
    <label for="regen-tpl" class="block text-sm font-semibold text-gray-800">New design</label>
    <select name="new_template_id" id="regen-tpl" class="<?= $input ?>">
      <?php foreach ($templates as $t): ?><option value="<?= $t['id'] ?>"><?= ex($t['name']) ?><?= $t['is_default'] ? ' (default)' : '' ?></option><?php endforeach; ?>
    </select>
    <div class="rounded-xl overflow-hidden border border-gray-100 bg-gray-50">
      <?php foreach ($templates as $t): ?><div data-rthumb="<?= $t['id'] ?>" class="hidden pointer-events-none"><?= renderTemplateHtml($t, templateSampleData('certificate')) ?></div><?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-500">The certificate number and verification link stay the same. Re-send the email if recipients need the new PDF.</p>
  </div>
<?php adminModalClose('<i class="fa-solid fa-rotate"></i> Regenerate', '', true); ?>
<script>
(function () {
  const dlg = document.getElementById('regen-modal'), form = document.getElementById('regen-form');
  if (!dlg) return;
  const names = <?= json_encode((object)array_column(array_map(fn($t) => ['id' => (int)$t['id'], 'n' => html_entity_decode($t['name'], ENT_QUOTES, 'UTF-8')], $templates), 'n', 'id')) ?>;
  const defId = <?= (int)($defaultTpl['id'] ?? 0) ?>;
  const sel = document.getElementById('regen-tpl'), box = document.getElementById('regen-new');
  const sync = () => {
    box.classList.toggle('hidden', form.regen_mode.value !== 'new');
    dlg.querySelectorAll('[data-rthumb]').forEach(d => d.classList.toggle('hidden', d.dataset.rthumb !== sel.value));
  };
  form.addEventListener('change', sync);
  let bulk = false;
  const open = (title, cur) => {
    document.getElementById('regen-sub').textContent = title;
    document.getElementById('regen-cur').textContent = cur;
    form.regen_mode.value = 'keep'; sync();
    dlg.showModal();
  };
  document.addEventListener('click', e => {
    const one = e.target.closest('[data-regen]');
    if (one) {
      bulk = false;
      document.getElementById('regen-id').value = one.dataset.regen;
      const tid = +one.dataset.regenTpl || defId;
      if (names[tid]) sel.value = tid;
      open(one.dataset.regenName, 'Currently: ' + (names[tid] || 'default design') + '.');
      return;
    }
    const many = e.target.closest('[data-regen-bulk]');
    if (many) {
      e.preventDefault(); bulk = true;
      open(document.getElementById('bulk-count').textContent + ' selected certificates', 'Each keeps the design it already uses.');
    }
  }, true);
  form.addEventListener('submit', e => {
    if (!bulk) return;
    // Bulk: send the choice through the selection form.
    e.preventDefault();
    const bf = document.getElementById('bulk-form');
    bf.querySelectorAll('.regen-x').forEach(n => n.remove());
    [['regen_mode', form.regen_mode.value], ['new_template_id', sel.value]].forEach(([k, v]) => {
      const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; i.className = 'regen-x'; bf.appendChild(i);
    });
    document.getElementById('bulk-op').value = 'regen';
    dlg.close(); bf.submit();
  });
})();
</script>
<?php endif; ?>

<?php if ($view === 'history'):
  $copies = []; foreach (['webinar','seminar','workshop','conference','hackathon','competition','volunteer','other'] as $ty) $copies[$ty] = certEmailCopy($ty);
  adminModalOpen('email-modal', 'Email certificate', '<span id="em-sub"></span>', ['form' => true, 'width' => '980px', 'form_attrs' => 'id="em-form"']); ?>
  <input type="hidden" name="action" value="email_cert">
  <input type="hidden" name="cert_id" id="em-id">
  <input type="hidden" name="return_qs" value="<?= ex($qs) ?>">
  <input type="hidden" name="email_custom" id="em-custom" value="">
  <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] gap-5">
    <div class="space-y-3">
      <div><label for="em-subject" class="block text-xs font-semibold text-gray-700 mb-1">Subject</label><input id="em-subject" name="email_subject" class="<?= $input ?>"></div>
      <div><label for="em-headline" class="block text-xs font-semibold text-gray-700 mb-1">Headline</label><input id="em-headline" name="email_headline" class="<?= $input ?>"></div>
      <div><label for="em-message" class="block text-xs font-semibold text-gray-700 mb-1">Message</label><textarea id="em-message" name="email_message" rows="6" class="<?= $input ?> resize-y"></textarea></div>
      <div><label for="em-closing" class="block text-xs font-semibold text-gray-700 mb-1">Closing line</label><textarea id="em-closing" name="email_closing" rows="2" class="<?= $input ?> resize-y"></textarea></div>
      <div class="text-xs text-gray-500">
        <p class="mb-1">Insert for each person:</p>
        <div class="flex flex-wrap gap-1.5">
          <?php foreach (['{first_name}', '{name}', '{event}', '{date}', '{cert_no}'] as $ph): ?><button type="button" data-ph="<?= $ph ?>" class="px-2 py-0.5 rounded-md bg-gray-100 hover:bg-gray-200 font-mono text-[11px] text-gray-700"><?= $ph ?></button><?php endforeach; ?>
        </div>
        <p class="mt-2">The PDF is attached and the LinkedIn and verify buttons are added automatically.</p>
        <button type="button" id="em-reset" class="mt-2 text-rarl-red font-semibold hover:underline"><i class="fa-solid fa-rotate-left"></i> Reset to default wording</button>
      </div>
    </div>
    <div class="min-w-0">
      <p class="text-xs font-semibold text-gray-700 mb-1">Preview <span class="font-normal text-gray-500" id="em-pv-who"></span></p>
      <div class="rounded-xl border border-gray-200 bg-gray-50 overflow-hidden">
        <div class="px-3 py-2 border-b border-gray-200 bg-white text-xs text-gray-700 truncate"><span class="text-gray-400">Subject:</span> <span id="em-pv-subject"></span></div>
        <iframe id="em-pv" title="Email preview" class="w-full h-[440px] bg-white" sandbox="allow-same-origin allow-popups"></iframe>
      </div>
    </div>
  </div>
<?php adminModalClose('<i class="fa-solid fa-paper-plane"></i> <span id="em-go">Send</span>', '', true); ?>
<script>
(function () {
  const dlg = document.getElementById('email-modal'), form = document.getElementById('em-form');
  if (!dlg) return;
  const copies = <?= json_encode($copies, JSON_UNESCAPED_UNICODE) ?>;
  const F = ['subject', 'headline', 'message', 'closing'], el = k => document.getElementById('em-' + k);
  let bulk = null, previewId = 0, type = 'other', timer, lastField = el('message');
  const fill = () => { const c = copies[type] || copies.other; F.forEach(k => el(k).value = c[k]); document.getElementById('em-custom').value = ''; };
  const edited = () => F.some(k => el(k).value !== (copies[type] || copies.other)[k]);
  async function preview() {
    if (!previewId) return;
    const fd = new FormData(form); fd.set('action', 'email_preview'); fd.set('cert_id', previewId);
    try {
      const r = await fetch('certificates.php', {method: 'POST', body: fd});
      document.getElementById('em-pv').srcdoc = await r.text();
      document.getElementById('em-pv-subject').textContent = decodeURIComponent(r.headers.get('X-Email-Subject') || '');
    } catch (e) {}
  }
  const queue = () => { clearTimeout(timer); timer = setTimeout(preview, 300); };
  F.forEach(k => el(k).addEventListener('input', () => { document.getElementById('em-custom').value = edited() ? '1' : ''; queue(); }));
  F.forEach(k => el(k).addEventListener('focus', () => lastField = el(k)));
  dlg.querySelectorAll('[data-ph]').forEach(b => b.addEventListener('click', () => {
    const f = lastField, s = f.selectionStart ?? f.value.length;
    f.value = f.value.slice(0, s) + b.dataset.ph + f.value.slice(f.selectionEnd ?? s);
    f.focus(); f.selectionStart = f.selectionEnd = s + b.dataset.ph.length; f.dispatchEvent(new Event('input'));
  }));
  document.getElementById('em-reset').addEventListener('click', () => { fill(); preview(); });
  const open = (sub, who, go) => {
    document.getElementById('em-sub').textContent = sub;
    document.getElementById('em-pv-who').textContent = who;
    document.getElementById('em-go').textContent = go;
    fill(); dlg.showModal(); preview();
  };
  document.addEventListener('click', e => {
    const one = e.target.closest('[data-email-one]');
    if (one) {
      e.preventDefault(); bulk = null;
      previewId = +one.dataset.emailOne; type = one.dataset.emailType || 'other';
      document.getElementById('em-id').value = previewId;
      open('To ' + one.dataset.emailTo, '', one.dataset.emailSent === '1' ? 'Resend' : 'Send');
      return;
    }
    const many = e.target.closest('[data-email-bulk]');
    if (many) {
      e.preventDefault(); e.stopImmediatePropagation();
      bulk = many.dataset.emailBulk;
      const rows = [...document.querySelectorAll('.row-check:checked')].map(c => c.closest('td'));
      const target = rows.filter(td => td.dataset.hasPdf === '1' && (bulk === 'resend' || td.dataset.sent !== '1'));
      const allMatching = document.querySelector('#bulk-form [name=all_matching]')?.value === '1';
      if (!target.length && !allMatching) { rarlToast(bulk === 'send' ? 'All selected certificates were already emailed. Use Resend.' : 'None of the selected certificates have a PDF yet.', 'error'); return; }
      const first = (target[0] || rows[0]);
      previewId = +first.querySelector('.row-check').value; type = first.dataset.etype || 'other';
      const types = new Set(target.map(td => td.dataset.etype || 'other'));
      const n = allMatching ? document.getElementById('bulk-count').textContent : target.length;
      open(n + ' recipient' + (n == 1 ? '' : 's') + (types.size > 1 ? ' · mixed event types: each gets its own wording unless you edit it' : ''), '(first recipient)', 'Send ' + n + ' email' + (n == 1 ? '' : 's'));
    }
  }, true);
  form.addEventListener('submit', e => {
    if (!bulk) return;
    e.preventDefault();
    const bf = document.getElementById('bulk-form');
    bf.querySelectorAll('.em-x').forEach(n => n.remove());
    ['email_custom', ...F.map(k => 'email_' + k)].forEach(k => {
      const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = form.elements[k].value; i.className = 'em-x'; bf.appendChild(i);
    });
    document.getElementById('bulk-op').value = bulk;
    dlg.close(); bf.submit();
  });
})();
</script>
<?php endif; ?>
<?php }, 'certificates', 'Certificates');
