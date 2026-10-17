<?php
/**
 * Robotics & Automation Research Lab (RARL) Platform — Database + Shared Helpers
 */
require_once __DIR__ . '/config.php';

// Session lifetime: PHP's default (24 min idle, gone on browser close) signed
// members out mid-task and broke forms with "Invalid request". Members stay
// signed in for 30 days; admin sessions are capped at 12 hours.
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    $rarlLife = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? 43200 : 2592000;
    ini_set('session.gc_maxlifetime', (string)$rarlLife);
    session_set_cookie_params(['lifetime' => $rarlLife, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')]);
    unset($rarlLife);
}

// Security response headers (OWASP A05). Sent once for every normal page load,
// before any output. Clickjacking is blocked with frame-ancestors + the legacy
// X-Frame-Options; nosniff stops MIME confusion; the CSP is a defence-in-depth
// layer behind the HTML sanitizer. The CSP allows the exact CDNs the pages load
// (Tailwind Play, Font Awesome, Google Fonts, jsDelivr/unpkg, YouTube embeds,
// and Google Translate for the language menu — loaded only once a visitor
// picks a language other than English)
// and keeps 'unsafe-inline'/'unsafe-eval' because the app uses inline handlers
// and the Tailwind Play CDN — tightening those needs a build step, tracked
// separately. HSTS is sent only over HTTPS so local http dev still works.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $rarlHttps = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'; "
        . "img-src 'self' data: https:; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://translate.google.com https://translate.googleapis.com https://translate-pa.googleapis.com https://www.gstatic.com; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com https://translate.google.com https://translate.googleapis.com https://translate-pa.googleapis.com https://www.gstatic.com; "
        . "connect-src 'self' https://translate.google.com https://translate.googleapis.com https://translate-pa.googleapis.com https://www.gstatic.com; "
        . "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://zoom.us https://translate.google.com"
        . ($rarlHttps ? '; upgrade-insecure-requests' : ''));
    if ($rarlHttps) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    unset($rarlHttps);
}

// ── PDO Singleton ──────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB Error: ' . $e->getMessage());
            die('Database connection error. Please try again later.');
        }
    }
    return $pdo;
}

// ── Settings helper ────────────────────────────────────────
function setting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $s = db()->prepare('SELECT value FROM settings WHERE `key` = ?');
        $s->execute([$key]);
        $row = $s->fetch();
        $cache[$key] = $row ? $row['value'] : $default;
    }
    return $cache[$key] ?? $default;
}

// ── Input sanitizers ───────────────────────────────────────
function clean(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}
// Escapes text for HTML output exactly once. Values saved through clean()
// are already entity-encoded in the database, so plain htmlspecialchars()
// would show "R&amp;D" (and every admin edit round-trip would encode again).
// Decoding first makes the output correct for both encoded and raw values.
function ex($v, ...$flagsIgnored): string {
    return htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}
function cleanEmail(string $v): string {
    return filter_var(trim($v), FILTER_SANITIZE_EMAIL);
}
function cleanUrl(string $v): string {
    $v = trim($v);
    if ($v && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    return filter_var($v, FILTER_SANITIZE_URL);
}

// Accepts a bare ORCID iD or a pasted orcid.org link; returns the bare iD.
function cleanOrcid(string $v): string {
    return preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i', $v, $m) ? strtoupper($m[1]) : clean($v);
}

// ── CSRF ───────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfCheck(): bool {
    if (viewAsActive()) { flash('info', 'Read-only preview: you are viewing the portal as this member, so nothing was changed.'); return false; }
    $tok = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $tok);
}
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

// ── UUID generator ─────────────────────────────────────────
function generateUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Password reset token ───────────────────────────────────
function generateResetToken(): string {
    return bin2hex(random_bytes(32));
}

// ── Certificate number ─────────────────────────────────────
// Deliberately bypasses setting()'s per-request cache and does the increment
// in SQL (not read-then-write in PHP): setting()'s cache is populated once
// and never invalidated on write, so a second call in the same request (e.g.
// bulk-activating several members, each issuing a certificate) would read
// the same pre-increment cached value and mint a duplicate number, which
// then fails on the certificate_no UNIQUE constraint.
function nextCertNumber(): string {
    $pdo = db();
    $pdo->exec("UPDATE settings SET value = value + 1 WHERE `key` = 'cert_id_counter'");
    $counter = (int) $pdo->query("SELECT value FROM settings WHERE `key` = 'cert_id_counter'")->fetchColumn();
    return CERT_PREFIX . '-' . date('Y') . '-' . str_pad($counter, 4, '0', STR_PAD_LEFT);
}

// ── Certificate PDF generation ──────────────────────────────
// Shared by admin/certificates.php (manual CSV issuance) and the
// auto-issue-on-attendance flow in admin/event-registrations.php.
// Requires FPDF — gracefully no-ops if not available (a certificate row
// without a pdf_path just shows "not generated" instead of erroring).
function generateCertPDF(string $path, string $name, string $event, string $certNo, string $date, string $uuid): void {
    if (!class_exists('FPDF') && file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) {
        require_once __DIR__ . '/libs/fpdf/fpdf.php';
    }
    if (!class_exists('FPDF')) return;
    if (!class_exists('QRcode') && file_exists(__DIR__ . '/libs/phpqrcode/qrlib.php')) {
        require_once __DIR__ . '/libs/phpqrcode/qrlib.php';
    }

    [$inkR, $inkG, $inkB]    = brandRgb(BRAND_INK);
    [$redR, $redG, $redB]    = brandRgb(BRAND_RED);
    [$blueR, $blueG, $blueB] = brandRgb(BRAND_BLUE);

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false); // fixed single-page layout; FPDF's default ~20mm bottom trigger would otherwise spill footer content onto extra blank pages
    $pdf->AddPage();
    $pdf->SetFillColor($inkR, $inkG, $inkB);
    $pdf->Rect(0, 0, 297, 210, 'F');
    $pdf->SetDrawColor($redR, $redG, $redB);
    $pdf->SetLineWidth(2);
    $pdf->Rect(8, 8, 281, 194);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor($redR, $redG, $redB);
    $pdf->SetY(30); $pdf->Cell(0, 0, 'RARL COMMUNITY', 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(170, 170, 170);
    $pdf->SetY(40); $pdf->Cell(0, 0, 'Robotics & Automation Research Lab', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 22);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetY(55); $pdf->Cell(0, 0, 'Certificate of Participation', 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(170, 170, 170);
    $pdf->SetY(75); $pdf->Cell(0, 0, 'This is to certify that', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 26);
    $pdf->SetTextColor($blueR, $blueG, $blueB);
    $pdf->SetY(85); $pdf->Cell(0, 0, fpdfEnc($name), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(170, 170, 170);
    $pdf->SetY(110); $pdf->Cell(0, 0, 'has successfully participated in', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetY(120); $pdf->Cell(0, 0, fpdfEnc($event), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetY(145); $pdf->Cell(0, 0, 'Date: ' . ($date ? date('d F Y', strtotime($date)) : date('d F Y')) . '   |   Certificate ID: ' . $certNo, 0, 1, 'C');
    $pdf->SetY(155);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(0, 0, 'Verify at: ' . CERT_VERIFY_URL . '?id=' . $uuid, 0, 1, 'C');
    $qrPath = null;
    if (class_exists('QRcode')) {
        $qrPath = sys_get_temp_dir() . '/rarl_qr_' . str_replace('-', '', $uuid) . '.png';
        QRcode::png(CERT_VERIFY_URL . '?id=' . $uuid, $qrPath, QR_ECLEVEL_L, 4, 2);
    }
    if ($qrPath && file_exists($qrPath)) {
        $pdf->Image($qrPath, 133.5, 158, 30, 30, 'PNG');
        @unlink($qrPath);
    }
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(100, 194, 197, 194);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetY(197); $pdf->Cell(0, 0, 'Authorised by ' . SITE_NAME . ' - ' . str_replace(['https://', 'http://'], '', MAIN_SITE_URL), 0, 1, 'C');
    $pdf->Output('F', $path);
}

// Issues a certificate for one event registration (used by the "mark
// attended" flow) — reuses the exact same PDF/uuid/cert-number logic as the
// manual CSV path in admin/certificates.php so both produce identical output.
function issueCertificateForAttendance(PDO $pdo, array $event, array $member, ?string $batch = null): bool {
    $email = $member['email'];
    $name  = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];

    $dup = $pdo->prepare('SELECT id FROM certificates WHERE event_id = ? AND recipient_email = ?');
    $dup->execute([$event['id'], $email]);
    if ($dup->fetch()) return true; // already issued, not an error

    $uuid   = generateUuid();
    $certNo = nextCertNumber();
    $certDir = UPLOADS_PATH . '/certificates/';
    if (!is_dir($certDir)) mkdir($certDir, 0755, true);
    $pdfFile = 'cert_' . str_replace('-', '', $uuid) . '.pdf';
    $pdfPath = null;
    $eventDate = !empty($event['event_date']) ? date('d F Y', strtotime($event['event_date'])) : date('d F Y');

    // Use the designed default certificate template when one exists, so
    // attendance certificates match the ones issued from admin/certificates.php.
    $template = getDefaultTemplate('certificate');
    if ($template && renderTemplatePdf($template, ['name' => $name, 'event' => $event['title'], 'cert_no' => $certNo, 'date' => $eventDate,
            'verify_url' => CERT_VERIFY_URL . '?id=' . $uuid], $certDir . $pdfFile)) {
        $pdfPath = $pdfFile;
    } elseif (file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) {
        generateCertPDF($certDir . $pdfFile, $name, $event['title'], $certNo, $event['event_date'] ?? date('Y-m-d'), $uuid);
        $pdfPath = $pdfFile;
    }

    $pdo->prepare("INSERT INTO certificates (uuid, certificate_no, member_id, recipient_name, recipient_email, event_id, template_id, pdf_path)
        VALUES (?,?,?,?,?,?,?,?)")->execute([$uuid, $certNo, $member['id'], $name, $email, $event['id'], $template['id'] ?? null, $pdfPath]);
    $certId = (int)$pdo->lastInsertId();

    $verifyUrl  = CERT_VERIFY_URL . '?id=' . $uuid;
    $eventTitle = $event['title'];
    $certNumber = $certNo;
    $memberName = properName($name);
    $eventType  = $event['type'] ?? 'other';
    $eventDate  = !empty($event['event_date']) ? date('d F Y', strtotime($event['event_date'])) : null;
    $attach     = certEmailAttachment($pdfPath ?? '', $certNo, $memberName);
    $pdfAttached = (bool)$attach;
    $subject    = certEmailSubject($eventType, html_entity_decode((string)$event['title'], ENT_QUOTES, 'UTF-8'));
    ob_start(); require __DIR__ . '/emails/certificate.php'; $body = ob_get_clean();
    if ($batch) {
        queueEmail($batch, 'Certificates — ' . $event['title'], $email, $memberName, $subject, $body, $attach, 'cert:' . $certId);
    } elseif (sendEmail($email, $memberName, $subject, $body, $attach)) {
        $pdo->prepare("UPDATE certificates SET emailed_at = NOW() WHERE id = ?")->execute([$certId]);
    }
    return true;
}

/**
 * Default certificate-email wording per event type. Plain text with placeholders:
 * {first_name} {name} {event} {date} {cert_no}. Admins can edit it before sending.
 */
function certEmailCopy(?string $type): array {
    $c = [
        'webinar'     => ['Webinar certificate', 'Thank you for joining us!',
            'Thank you for attending {event} on {date}. We hope the talk gave you fresh ideas to take into your own research and work. Your Certificate of Participation is ready.',
            'Recordings of our webinars are posted on the RARL portal, so you can revisit the session anytime.'],
        'seminar'     => ['Seminar certificate', 'Thank you for being part of the conversation',
            'Thank you for attending {event} on {date}. Your questions and engagement make these sessions worthwhile. Your Certificate of Participation is ready.',
            'Keep an eye on the RARL portal for upcoming talks.'],
        'workshop'    => ['Workshop certificate', 'Well done on completing the workshop!',
            "Thank you for taking part in {event} on {date}. Hands-on learning takes real effort, and we're glad you put it in. Your certificate is ready.",
            'Workshop materials and related resources are available in the RARL Learning Hub.'],
        'conference'  => ['Conference certificate', 'Thank you for joining the conference',
            'Thank you for participating in {event} on {date}. It was great to have you with the RARL community. Your Certificate of Participation is ready.',
            'Published papers and session recordings will be shared on the RARL portal.'],
        'hackathon'   => ['Hackathon certificate', 'Thank you for building with us!',
            'Thank you for taking on {event} on {date}. Your creativity, teamwork and long hours made the event what it was. Your certificate is ready.',
            'We would love to see where your project goes next.'],
        'competition' => ['Competition certificate', 'Thank you for competing!',
            'Thank you for competing in {event} on {date}. Taking part takes courage and skill, and you showed both. Your certificate is ready.',
            'Watch the RARL portal for the next challenge.'],
        'volunteer'   => ['Certificate of appreciation', 'Thank you for your time and help',
            'Thank you for volunteering with {event} on {date}. Events like this only happen because of people like you. Please accept this certificate with our sincere appreciation.',
            'We hope to work with you again soon.'],
    ][$type ?? ''] ?? ['Your certificate', 'Thank you for taking part!',
            'Thank you for participating in {event} on {date}. Your certificate is ready.',
            'Stay connected with RARL for upcoming events and opportunities.'];
    return ['eyebrow' => $c[0], 'headline' => $c[1], 'message' => $c[2], 'closing' => $c[3], 'subject' => certEmailSubject($type, '{event}')];
}

/** Subject line for a certificate email, worded for the event type. */
function certEmailSubject(?string $type, string $title): string {
    return match ($type) {
        'volunteer'   => "Thank you for volunteering — your certificate for {$title}",
        'hackathon', 'competition' => "Thank you for taking part — your certificate for {$title}",
        'workshop'    => "Well done! Your workshop certificate for {$title}",
        default       => "Thank you for joining {$title} — your certificate",
    };
}

/** Placeholder values for one recipient. */
function certEmailVars(string $name, string $event, string $date, string $certNo): array {
    $plain = fn($v) => html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    $name = properName($plain($name));
    $first = explode(' ', preg_replace('/^(dr|prof|mr|mrs|ms|engr)\.?\s+/i', '', $name))[0] ?: $name;
    return ['{first_name}' => $first, '{name}' => $name, '{event}' => $plain($event), '{date}' => $plain($date) ?: date('d F Y'), '{cert_no}' => $plain($certNo)];
}

/** Escapes $text, fills placeholders ({event} in bold when $html) and keeps line breaks. */
function certEmailFill(string $text, array $vars, bool $html = true): string {
    $out = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    foreach ($vars as $k => $v) $out = str_replace($k, $k === '{event}' && $html ? '<strong>' . htmlspecialchars($v) . '</strong>' : htmlspecialchars($v), $out);
    return $html ? nl2br($out) : $out;
}

/** The certificate PDF as an email attachment ([] if the file is missing). */
function certEmailAttachment(string $pdfPath, string $certNo, string $name): array {
    $file = $pdfPath ? UPLOADS_PATH . '/certificates/' . basename($pdfPath) : '';
    if (!$file || !is_file($file)) return [];
    $fname = trim(preg_replace('/[^\w .,()&\'-]+/u', '', 'RARL Certificate - ' . html_entity_decode($name, ENT_QUOTES, 'UTF-8'))) . '.pdf';
    return [['path' => $file, 'filename' => $fname]];
}

// ── Send email ─────────────────────────────────────────────
// Uses SMTP (via a cPanel mailbox) when SMTP_HOST is configured — real SMTP
// auth means SPF/DKIM line up with the sending mailbox, so mail lands in
// inboxes instead of spam. Falls back to PHP's mail() if SMTP isn't set up.
// $attachments: array of ['path' => '/abs/file.pdf', 'filename' => 'Certificate.pdf']
// — e.g. for emailing certificate PDFs alongside the download-link flow.
function sendEmail(string $to, string $toName, string $subject, string $htmlBody, array $attachments = []): bool {
    require_once __DIR__ . '/libs/SmtpMailer.php';

    if (SMTP_HOST !== '') {
        try {
            $mailer = new SmtpMailer(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_SECURE);
            try {
                $mailer->send(MAIL_FROM_EMAIL, MAIL_FROM_NAME, $to, $toName, $subject, $htmlBody, $attachments);
            } finally {
                $GLOBALS['rarl_mail_log'] = $mailer->log;
            }
            emailLogSend();
            return true;
        } catch (Throwable $e) {
            error_log('SMTP send failed: ' . $e->getMessage());
            $GLOBALS['rarl_mail_error'] = $e->getMessage();
            return false;
        }
    }

    [$headers, $body] = SmtpMailer::buildMime(MAIL_FROM_EMAIL, MAIL_FROM_NAME, $to, $toName, $subject, $htmlBody, $attachments);
    // mail() sets Subject/To from its own params — drop those from the shared
    // header set to avoid duplicates, keep the rest (Content-Type, From, etc.)
    $headers = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'Subject:') && !str_starts_with($h, 'To:')));
    $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    // -f sets the envelope sender (Return-Path) to our own address. Without it the
    // host uses its server account, so SPF/DMARC don't line up with the From
    // domain and Gmail/Outlook quietly spam or drop the mail, while mail() still
    // reports success.
    $params = filter_var(MAIL_FROM_EMAIL, FILTER_VALIDATE_EMAIL) ? '-f' . MAIL_FROM_EMAIL : '';
    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), $params);
    if ($ok) emailLogSend();
    if (!$ok) $GLOBALS['rarl_mail_error'] = 'PHP mail() was refused by the server' . (($le = error_get_last()) ? ': ' . $le['message'] : '');
    return $ok;
}

// ── Hourly sending limit ───────────────────────────────────
// Every email that leaves the site is logged, so bulk sending can stay under the
// host's hourly cap. A few sends per hour are always left free for password
// resets and sign-up codes, which are sent immediately, not queued.
const MAIL_HOURLY_RESERVE = 5;

function emailSendLogReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `email_send_log` (
          `id` INT(11) NOT NULL AUTO_INCREMENT, `sent_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`), KEY `sent_at` (`sent_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
    } catch (Throwable $e) {
        error_log('email_send_log unavailable: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function emailLogSend(): void {
    if (!emailSendLogReady()) return;
    try {
        $pdo = db();
        $pdo->exec("INSERT INTO email_send_log (sent_at) VALUES (NOW())");
        if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM email_send_log WHERE sent_at < NOW() - INTERVAL 2 DAY");
    } catch (Throwable $e) { error_log('email_send_log: ' . $e->getMessage()); }
}

/** ['limit' => int, 'used' => int, 'budget' => int (queue sends allowed now), 'resume_at' => ?int (unix time)] */
function emailHourStatus(): array {
    $limit = MAIL_HOURLY_LIMIT;
    if ($limit <= 0 || !emailSendLogReady()) return ['limit' => 0, 'used' => 0, 'budget' => PHP_INT_MAX, 'resume_at' => null];
    $pdo = db();
    $used = (int)$pdo->query("SELECT COUNT(*) FROM email_send_log WHERE sent_at > NOW() - INTERVAL 1 HOUR")->fetchColumn();
    $cap = max(1, $limit - MAIL_HOURLY_RESERVE);
    $budget = max(0, $cap - $used);
    $resume = null;
    if ($budget === 0) {
        // The queue may send again once enough of the last hour's sends age out.
        $offset = max(0, $used - $cap);
        $at = $pdo->query("SELECT UNIX_TIMESTAMP(sent_at) FROM email_send_log WHERE sent_at > NOW() - INTERVAL 1 HOUR ORDER BY sent_at ASC LIMIT 1 OFFSET {$offset}")->fetchColumn();
        $resume = $at ? (int)$at + 3600 + 5 : time() + 300;
    }
    return ['limit' => $limit, 'used' => $used, 'budget' => $budget, 'resume_at' => $resume];
}

/** Rough "about 3 hours" for $n queued emails at the current limit, or '' when there is no limit. */
function emailQueueEta(int $n): string {
    if (MAIL_HOURLY_LIMIT <= 0 || $n <= 0) return '';
    $perHour = max(1, MAIL_HOURLY_LIMIT - MAIL_HOURLY_RESERVE);
    if ($n <= $perHour) return '';
    $hours = (int)ceil($n / $perHour);
    return " Your host allows {$perHour} per hour, so this takes about {$hours} hour" . ($hours > 1 ? 's' : '') . '.';
}

// ── Background email queue ─────────────────────────────────
// Bulk sends go through queueEmail() and are delivered in small batches by
// processEmailQueue() — driven by the admin UI's progress pill (any open
// admin tab ticks admin/email-queue.php) and optionally by a cPanel cron
// running cron-email-queue.php. Claiming rows with a random token means
// several tabs/cron runs can work the queue at once without double-sending.
// $onSent is a small hook run after delivery: "cert:<id>" stamps
// certificates.emailed_at, "welcome:<id>" sets members.discord_invited,
// "nl:<id>" bumps newsletters.recipient_count.
function emailQueueReady(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS `email_queue` (
          `id` INT(11) NOT NULL AUTO_INCREMENT, `batch` VARCHAR(32) NOT NULL, `batch_label` VARCHAR(255) NOT NULL DEFAULT '',
          `to_email` VARCHAR(255) NOT NULL, `to_name` VARCHAR(255) NOT NULL DEFAULT '', `subject` VARCHAR(500) NOT NULL,
          `body` MEDIUMTEXT NOT NULL, `attachments` TEXT DEFAULT NULL, `headers` TEXT DEFAULT NULL, `on_sent` VARCHAR(100) DEFAULT NULL,
          `status` ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued', `attempts` TINYINT(4) NOT NULL DEFAULT 0,
          `last_error` VARCHAR(500) DEFAULT NULL, `claim` VARCHAR(32) DEFAULT NULL, `locked_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `sent_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`), KEY `status` (`status`), KEY `batch` (`batch`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ready = true;
    } catch (Throwable $e) {
        error_log('email_queue unavailable: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function newEmailBatch(): string {
    return bin2hex(random_bytes(8));
}

// Returns true when queued; if the queue table can't be used it sends right
// away instead, so callers never lose an email.
function queueEmail(string $batch, string $label, string $to, string $toName, string $subject, string $htmlBody,
                    array $attachments = [], ?string $onSent = null, ?string $rawHeaders = null): bool {
    if (!emailQueueReady()) {
        $ok = $rawHeaders !== null ? @mail($to, $subject, $htmlBody, $rawHeaders) : sendEmail($to, $toName, $subject, $htmlBody, $attachments);
        if ($ok && $onSent) emailQueueRunHook($onSent);
        return $ok;
    }
    db()->prepare("INSERT INTO email_queue (batch, batch_label, to_email, to_name, subject, body, attachments, headers, on_sent) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$batch, mb_substr($label, 0, 255), $to, mb_substr($toName, 0, 255), mb_substr($subject, 0, 500), $htmlBody,
                   $attachments ? json_encode($attachments) : null, $rawHeaders, $onSent]);
    return true;
}

function emailQueueRunHook(string $hook): void {
    [$kind, $id] = array_pad(explode(':', $hook, 2), 2, '0');
    $id = (int)$id;
    $sql = match ($kind) {
        'cert'    => "UPDATE certificates SET emailed_at = NOW() WHERE id = ?",
        'welcome' => "UPDATE members SET discord_invited = 1 WHERE id = ?",
        'nl'      => "UPDATE newsletters SET recipient_count = recipient_count + 1 WHERE id = ?",
        default   => null,
    };
    if ($sql && $id) { try { db()->prepare($sql)->execute([$id]); } catch (Throwable $e) { error_log('queue hook failed: ' . $e->getMessage()); } }
}

// Sends up to $limit queued emails (stopping early after $maxSeconds).
// Returns ['sent' => n, 'failed' => n].
function processEmailQueue(int $limit = 8, int $maxSeconds = 15): array {
    $done = ['sent' => 0, 'failed' => 0];
    if (!emailQueueReady()) return $done;
    $hour = emailHourStatus();
    if ($hour['budget'] <= 0) return $done + ['paused_until' => $hour['resume_at']];
    $limit = min($limit, $hour['budget']);
    $pdo = db();
    $claim = bin2hex(random_bytes(8));
    // Rows stuck in "sending" (a tab closed mid-batch) are retried after 5 minutes.
    $pdo->prepare("UPDATE email_queue SET status = 'sending', claim = ?, locked_at = NOW()
        WHERE status = 'queued' OR (status = 'sending' AND locked_at < NOW() - INTERVAL 5 MINUTE)
        ORDER BY id LIMIT " . max(1, $limit))->execute([$claim]);
    $rows = $pdo->prepare("SELECT * FROM email_queue WHERE claim = ? AND status = 'sending' ORDER BY id");
    $rows->execute([$claim]);
    $start = time();
    foreach ($rows->fetchAll() as $r) {
        if (time() - $start > $maxSeconds) {
            $pdo->prepare("UPDATE email_queue SET status = 'queued', claim = NULL WHERE id = ?")->execute([$r['id']]);
            continue;
        }
        $GLOBALS['rarl_mail_error'] = null;
        $attachments = json_decode($r['attachments'] ?? '', true) ?: [];
        $attachments = array_values(array_filter($attachments, fn($a) => !empty($a['path']) && file_exists($a['path'])));
        $ok = $r['headers'] !== null
            ? @mail($r['to_email'], $r['subject'], $r['body'], $r['headers'])
            : sendEmail($r['to_email'], $r['to_name'], $r['subject'], $r['body'], $attachments);
        if ($ok) {
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?")->execute([$r['id']]);
            if ($r['on_sent']) emailQueueRunHook($r['on_sent']);
            $done['sent']++;
        } else {
            $err = mb_substr((string)($GLOBALS['rarl_mail_error'] ?? 'Mail server rejected the message'), 0, 500);
            $pdo->prepare("UPDATE email_queue SET status = IF(attempts + 1 >= 3, 'failed', 'queued'), attempts = attempts + 1, last_error = ?, claim = NULL WHERE id = ?")->execute([$err, $r['id']]);
            $done['failed']++;
        }
        usleep(50000);
    }
    return $done;
}

// Progress of all batches that still have work (or finished in the last hour).
function emailQueueSummary(): array {
    if (!emailQueueReady()) return ['pending' => 0, 'batches' => [], 'hour' => emailHourStatus()];
    $pdo = db();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status IN ('queued','sending')")->fetchColumn();
    $batches = $pdo->query("SELECT batch, MAX(batch_label) label, COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed,
        SUM(status IN ('queued','sending')) pending, SUM(status='cancelled') cancelled, MIN(created_at) created_at, MAX(sent_at) last_sent
        FROM email_queue GROUP BY batch HAVING pending > 0 OR MAX(created_at) > NOW() - INTERVAL 1 HOUR ORDER BY MIN(id) DESC LIMIT 10")->fetchAll();
    return ['pending' => $pending, 'batches' => $batches, 'hour' => emailHourStatus()];
}

// ── Flash messages ─────────────────────────────────────────
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function renderFlash(): string {
    $f = getFlash();
    if (!$f) return '';
    $classes = $f['type'] === 'success'
        ? 'bg-green-50 border-green-200 text-green-800'
        : ($f['type'] === 'error'
            ? 'bg-red-50 border-red-200 text-red-700'
            : 'bg-blue-50 border-blue-200 text-blue-700');
    $icon = $f['type'] === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : ($f['type'] === 'error' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '<i class="fa-solid fa-circle-info"></i>');
    return '<div role="' . ($f['type'] === 'error' ? 'alert' : 'status') . '" class="flex items-center gap-3 p-4 rounded-xl border mb-5 text-sm ' . $classes . '">'
         . '<span>' . $icon . '</span><span class="flex-1">' . htmlspecialchars($f['msg']) . '</span>'
         . '<button type="button" onclick="this.parentNode.remove()" class="opacity-60 hover:opacity-100 text-lg leading-none px-1" aria-label="Dismiss">&times;</button></div>';
}

// ── Redirect helper ────────────────────────────────────────
function redirect(string $url): never {
    header('Location: ' . $url); exit;
}

// ── Global font-size bump ───────────────────────────────────
// The whole site was built almost entirely on Tailwind's text-xs/text-sm
// utilities, which read as cramped (12px/14px). Rather than touch the class
// on every element across 100+ files, override the utility classes
// themselves, one tier up, with !important — same markup, larger type
// everywhere at once. Shared by both htmlHead() (public) and
// htmlAdminHead() (admin/layout.php).
// Accessibility floor shared by the public site and admin (Apple HIG / WCAG AA):
// muted text on light surfaces lifted to ≥4.5:1, reduced motion honoured,
// 44px touch targets on coarse pointers, stronger edges under Increase Contrast.
function rarlA11yCss(): string {
    $light = ':is(.bg-white,.bg-gray-50,.bg-slate-50,.rarl-card,.rarl-stat,dialog)';
    return <<<CSS
    html:not(.dark) {$light} .text-gray-400, html:not(.dark) {$light}.text-gray-400 { color:#6b7280; }   /* 2.54:1 → 4.83:1 */
    html:not(.dark) {$light} .text-slate-400 { color:#64748b; }                                           /* 2.56:1 → 4.76:1 */
    html:not(.dark) {$light} .text-amber-600 { color:#b45309; }                                           /* 3.19:1 → 5.02:1 */
    html:not(.dark) {$light} .text-green-600 { color:#15803d; }                                           /* 3.30:1 → 5.02:1 */
    html:not(.dark) {$light} .text-green-500 { color:#16a34a; }
    .rarl-empty { color:#64748b; }
    /* Field borders at 3:1 against white so the box is visible (ulab-mms --input) */
    html:not(.dark) :is(input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]):not([type=color]), select, textarea):is(.border-gray-300,.border-gray-200) { border-color:#9b9ba1; }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { animation-duration:.01ms !important; animation-iteration-count:1 !important; transition-duration:.01ms !important; scroll-behavior:auto !important; }
      .reveal { opacity:1 !important; transform:none !important; }
      [class*="hover:-translate-y"]:hover, a.rarl-stat:hover { transform:none !important; }
    }
    @media (pointer: coarse) {
      .rarl-icon-btn, button.w-8.h-8, a.w-8.h-8, button.w-7.h-7, a.w-7.h-7 { min-width:44px; min-height:44px; }
      .rarl-btn-sm { height:2.5rem; }
    }
    @media (prefers-contrast: more) {
      .rarl-card, .rarl-stat, .rarl-input, .rarl-btn, .border-gray-100, .border-gray-200 { border-color:#475569 !important; }
      .text-gray-500, .text-gray-400, .text-slate-500 { color:#1f2937 !important; }
    }
    @media (forced-colors: active) { a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline:2px solid CanvasText; } }
    CSS;
}

// Site-wide form friction fixes (public + admin heads):
// - type=url accepts "scholar.google.com/…" — https:// is added before the
//   browser's validity check instead of blocking the submit (cleanUrl does the same server-side)
// - pasted ORCID URLs are reduced to the bare iD
// - browser autofill tokens are filled in by field name where a field has none
function rarlFormFrictionJs(): string {
    return <<<'HTML'
<script>
(function() {
  const AC = {full_name:'name', name:'name', email:'email', phone:'tel', institution:'organization', organization:'organization',
    position:'organization-title', country:'country-name', city_state:'address-level2', password:'new-password',
    password_confirm:'new-password', new_password:'new-password', new_password_confirm:'new-password', current_password:'current-password',
    linkedin_url:'url', website_url:'url', google_scholar_url:'url'};
  const fixUrl = el => { const v = el.value.trim(); if (v && !/^[a-z][a-z0-9+.-]*:/i.test(v)) el.value = 'https://' + v.replace(/^\/+/, ''); };
  const fixOrcid = el => { const m = el.value.match(/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i); if (m) el.value = m[1].toUpperCase(); };
  function prep(root) {
    root.querySelectorAll('input[name],select[name],textarea[name]').forEach(el => {
      if (!el.hasAttribute('autocomplete') && AC[el.name] && el.type !== 'hidden') el.setAttribute('autocomplete', AC[el.name]);
    });
    // Icon-only buttons/links get an accessible name from their tooltip.
    root.querySelectorAll('button[title]:not([aria-label]),a[title]:not([aria-label])').forEach(el => {
      if (!el.textContent.trim()) el.setAttribute('aria-label', el.getAttribute('title'));
    });
  }
  document.addEventListener('DOMContentLoaded', () => prep(document));
  document.addEventListener('change', e => {
    const el = e.target;
    if (el.type === 'url') fixUrl(el);
    if (el.name === 'orcid_id') fixOrcid(el);
  }, true);
  // Runs before the browser validates, so a scheme-less URL never blocks submit.
  document.addEventListener('click', e => {
    const b = e.target.closest && e.target.closest('button[type=submit],button:not([type]),input[type=submit]');
    if (b && b.form) b.form.querySelectorAll('input[type=url]').forEach(fixUrl);
  }, true);
  document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.form) e.target.form.querySelectorAll('input[type=url]').forEach(fixUrl);
  }, true);
})();
</script>
HTML;
}

function rarlFontSizeCss(): string {
    return <<<CSS
    html { font-size: 17px; }
    .text-\[9px\]  { font-size: 11px !important; line-height: 14px !important; }
    .text-\[10px\] { font-size: 12px !important; line-height: 16px !important; }
    .text-\[11px\] { font-size: 13px !important; line-height: 18px !important; }
    .text-xs   { font-size: 0.9rem   !important; line-height: 1.4rem  !important; }
    .text-sm   { font-size: 1rem     !important; line-height: 1.6rem  !important; }
    .text-base { font-size: 1.125rem !important; line-height: 1.75rem !important; }
    .text-lg   { font-size: 1.25rem  !important; line-height: 1.85rem !important; }
    .text-xl   { font-size: 1.4rem   !important; line-height: 1.9rem  !important; }
    .text-2xl  { font-size: 1.65rem  !important; line-height: 2.1rem  !important; }
    .text-3xl  { font-size: 2rem     !important; line-height: 2.3rem  !important; }
    .text-4xl  { font-size: 2.5rem   !important; line-height: 2.7rem  !important; }
    CSS;
}

// ── Shared HTML head ───────────────────────────────────────
// ── Search & share metadata ────────────────────────────────
// $meta: description, image (absolute URL), type (og:type), noindex (bool).
// Private member pages are never indexed.
const RARL_PRIVATE_PAGES = ['dashboard.php', 'membership.php', 'profile.php', 'chapter.php', 'search.php', 'impersonate.php', 'reset-password.php', 'verify-email.php', 'verify-linked-email.php', 'reverify.php', 'unsubscribe.php'];
function seoHeadHtml(string $title, array $meta): string {
    $base = rtrim(SITE_URL, '/');
    $page = basename(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) ?: 'index.php';
    // Canonical: the page plus only the query keys that change its content.
    $keep = array_intersect_key($_GET, array_flip(['v', 'id', 'tab', 'event', 'cat']));
    $canonical = $base . '/' . $page . ($keep ? '?' . http_build_query($keep) : '');
    $desc = trim($meta['description'] ?? '') ?: 'The Robotics & Automation Research Lab (RARL), UK: a free international membership for robotics and AI researchers, with webinars, learning resources, verified certificates and member ID cards.';
    $desc = mb_strimwidth(preg_replace('/\s+/', ' ', $desc), 0, 300, '…');
    $img = $meta['image'] ?? ($base . '/assets/og-image.jpg');
    $noindex = !empty($meta['noindex']) || in_array($page, RARL_PRIVATE_PAGES, true);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'ResearchOrganization', '@id' => MAIN_SITE_URL . '#org', 'name' => 'Robotics & Automation Research Lab (RARL)', 'alternateName' => 'RARL',
             'url' => MAIN_SITE_URL, 'logo' => $base . '/assets/icons/icon-512.png', 'email' => MAIL_REPLY_TO,
             'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Queens Building', 'addressLocality' => 'Leicester', 'postalCode' => 'LE1 9BH', 'addressCountry' => 'GB']],
            ['@type' => 'WebSite', 'name' => 'RARL Community', 'url' => $base . '/', 'publisher' => ['@id' => MAIN_SITE_URL . '#org'],
             'potentialAction' => ['@type' => 'SearchAction', 'target' => $base . '/search.php?q={q}', 'query-input' => 'required name=q']],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    return '<meta name="description" content="' . $e($desc) . '"/>'
        . ($noindex ? '<meta name="robots" content="noindex, follow"/>' : '<meta name="robots" content="index, follow, max-image-preview:large"/>')
        . '<link rel="canonical" href="' . $e($canonical) . '"/>'
        . '<meta property="og:site_name" content="RARL Community"/><meta property="og:type" content="' . $e($meta['type'] ?? 'website') . '"/>'
        . '<meta property="og:title" content="' . $e($title) . '"/><meta property="og:description" content="' . $e($desc) . '"/>'
        . '<meta property="og:url" content="' . $e($canonical) . '"/><meta property="og:image" content="' . $e($img) . '"/>'
        . '<meta property="og:image:alt" content="' . $e($title) . '"/><meta property="og:locale" content="en_GB"/>'
        . '<meta name="twitter:card" content="summary_large_image"/><meta name="twitter:title" content="' . $e($title) . '"/>'
        . '<meta name="twitter:description" content="' . $e($desc) . '"/><meta name="twitter:image" content="' . $e($img) . '"/>'
        . '<script type="application/ld+json">' . $ld . '</script>';
}

function htmlHead(string $title, bool $isAdmin = false, array $meta = []): string {
    scheduleBackgroundJobs();
    $fullTitle  = htmlspecialchars($title) . ' — ' . SITE_NAME;
    $plain      = html_entity_decode($title, ENT_QUOTES);
    $seo        = seoHeadHtml($plain . (str_contains($plain, 'RARL') ? '' : ' — RARL'), $meta);
    $colorsJson = brandTailwindConfigJson();
    $fontUrl    = BRAND_FONT_GOOGLE_URL;
    $faUrl      = FONTAWESOME_CDN_URL;
    $sizeCss    = rarlFontSizeCss() . rarlA11yCss();
    $formJs     = rarlFormFrictionJs();
    $langJs     = languageHeadJs();
    $brandRedHex = BRAND_RED;
    return <<<HTML
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>{$fullTitle}</title>
  {$seo}
  <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#030712" media="(prefers-color-scheme: dark)"/>
  <link rel="icon" href="favicon.ico?v=2" sizes="48x48"/>
  <link rel="icon" href="assets/rarl-mark.svg?v=2" type="image/svg+xml"/>
  <link rel="manifest" href="manifest.webmanifest"/>
  <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=2"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-title" content="RARL"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="default"/>
  <meta name="application-name" content="RARL Community"/>
  <meta name="format-detection" content="telephone=no"/>
  <script>
    // PWA: offline support + install. Install UI = any [data-install] element
    // (hidden until installing is possible on this device).
    if ('serviceWorker' in navigator) window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').then(function (reg) {
        if (/[?&]logged_out=1/.test(location.search) && reg.active) reg.active.postMessage('clear-pages');
      }).catch(function () {});
    });
    (function () {
      var deferred = null, ua = navigator.userAgent;
      var standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
      var ios = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      var iosSafari = ios && /safari/i.test(ua) && !/crios|fxios|edgios|opios/i.test(ua);
      if (standalone) document.documentElement.classList.add('is-standalone');
      function show(on) { document.querySelectorAll('[data-install]').forEach(function (el) { el.hidden = !on; }); }
      window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(true); });
      window.addEventListener('appinstalled', function () { deferred = null; show(false); });
      document.addEventListener('DOMContentLoaded', function () { show(!standalone && (!!deferred || iosSafari)); });
      document.addEventListener('click', function (e) {
        var b = e.target.closest && e.target.closest('[data-install-go]'); if (!b) return;
        e.preventDefault();
        if (deferred) { deferred.prompt(); deferred.userChoice.finally(function () { deferred = null; show(false); }); }
        else if (iosSafari && window.rarlModal) rarlModal.open('ios-install', b);
      });
    })();
  </script>
  <script>
    (function(){ try { var t = localStorage.getItem('rarl-theme'); if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {} })();
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-theme-toggle]'); if (!b) return;
      var dark = document.documentElement.classList.toggle('dark');
      try { localStorage.setItem('rarl-theme', dark ? 'dark' : 'light'); } catch (err) {}
    });
  </script>
  {$langJs}
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: {
        colors: {$colorsJson},
        fontFamily: {
          sans:    ['Inter','system-ui','sans-serif'],
          heading: ['Sora','Inter','system-ui','sans-serif'],
        },
        boxShadow: {
          card:    '0 1px 2px rgba(16,16,16,.04), 0 1px 12px rgba(16,16,16,.05)',
          'card-hover': '0 8px 24px rgba(16,16,16,.10)',
        }
      }}
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="{$fontUrl}" rel="stylesheet"/>
  <link href="{$faUrl}" rel="stylesheet"/>
  {$formJs}
  <style>
    body { font-family:'Inter',system-ui,sans-serif; -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility; }
    h1,h2,h3,h4 { font-family:'Sora','Inter',system-ui,sans-serif; letter-spacing:-0.01em; }
    {$sizeCss}
    a, button, [role="button"] { transition:color .15s ease, background-color .15s ease, border-color .15s ease, opacity .15s ease, transform .15s ease, box-shadow .15s ease; }
    a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible, [tabindex]:focus-visible {
      outline:2px solid {$brandRedHex}; outline-offset:2px; border-radius:4px;
    }
    ::selection { background:{$brandRedHex}; color:#fff; }
    ::-webkit-scrollbar { width:10px; height:10px; }
    ::-webkit-scrollbar-track { background:transparent; }
    ::-webkit-scrollbar-thumb { background:rgba(120,120,120,.35); border-radius:999px; }
    ::-webkit-scrollbar-thumb:hover { background:rgba(120,120,120,.55); }
    .reveal { opacity:0; transform:translateY(20px); transition:opacity .3s cubic-bezier(0,0,.2,1),transform .3s cubic-bezier(0,0,.2,1); }
    .reveal.visible { opacity:1; transform:none; }
    select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%235a6478' d='M6 8L0 0h12z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 1rem center; padding-right:2.5rem!important; -webkit-appearance:none; }
    /* Markdown-rendered content (community posts/comments) */
    .md-content p { margin: 0 0 0.5em; }
    .md-content p:last-child { margin-bottom: 0; }
    .md-content ul, .md-content ol { margin: 0.25em 0 0.5em; padding-left: 1.25em; }
    .md-content ul { list-style: disc; }
    .md-content ol { list-style: decimal; }
    .md-content code { padding: 0.1em 0.35em; border-radius: 0.25em; font-size: 0.85em; font-family: ui-monospace, monospace; background: rgba(127,127,127,.15); }
    .md-content a { text-decoration: underline; color: #CC0703; }
    .md-content strong { font-weight: 700; }
    .md-content blockquote { border-left: 3px solid #CC0703; padding-left: 0.75em; color: inherit; opacity: .8; margin: 0.5em 0; }
    html.dark { color-scheme: dark; }
    /* WCAG AA contrast on every surface the portal uses (incl. the warm #f4f2ee page) */
    html:not(.dark) .text-gray-500 { color:#565d6b; }                 /* 4.3:1 → 5.6:1 on #f4f2ee */
    html:not(.dark) .text-gray-400 { color:#5f6672; }
    .dark .text-gray-500 { color:#9ca3af; }                             /* 3.9:1 → 7.0:1 on gray-900 */
    .dark .text-gray-400 { color:#a8afba; }
    .dark .text-rarl-red, .dark .hover\:text-rarl-red:hover { color:#ff6b66; }   /* brand red 3.2:1 → 6.1:1 */
    .dark .border-rarl-red { border-color:#ff6b66; }
    .dark ::selection { background:#ff6b66; color:#111; }
    /* Comfortable reading measure and rhythm for long text (Apple HIG: legibility) */
    .md-content, [data-clamp] { max-width: 72ch; }
    [dir=rtl] .fa-arrow-right, [dir=rtl] .fa-chevron-right { transform: scaleX(-1); }
    .dark .md-content a { color: #f87171; }
    /* Portal surface — shared card look for member pages */
    .rarl-panel { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; }
    .dark .rarl-panel { background:#111827; border-color:#1f2937; }
    .rarl-feed-card { transition: box-shadow .15s ease, border-color .15s ease; }
    .rarl-feed-card:hover { box-shadow: 0 8px 24px -16px rgba(15,23,42,.25); }
    /* ── Modal (native <dialog>): centred card on desktop, bottom sheet on phones ── */
    dialog.rarl-modal { padding:0; border:0; background:transparent; max-width:none; max-height:none; width:100%; height:100%; margin:0; overflow:visible; }
    dialog.rarl-modal::backdrop { background:rgba(10,12,20,.55); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); }
    dialog.rarl-modal[open] { display:flex; align-items:flex-end; justify-content:center; }
    .rarl-modal-panel { position:relative; width:100%; max-height:92dvh; overflow-y:auto; overscroll-behavior:contain; background:#fff; border-radius:1.5rem 1.5rem 0 0; box-shadow:0 -12px 48px rgba(0,0,0,.25); padding-bottom:env(safe-area-inset-bottom); }
    .dark .rarl-modal-panel { background:#111827; }
    .rarl-modal-panel::before { content:""; position:sticky; top:0; display:block; width:40px; height:5px; margin:8px auto -13px; border-radius:99px; background:rgba(127,127,127,.35); z-index:2; }
    @media (min-width:640px) {
      dialog.rarl-modal[open] { align-items:center; padding:2rem; }
      .rarl-modal-panel { max-width:var(--modal-w, 40rem); max-height:88vh; border-radius:1.5rem; box-shadow:0 24px 80px rgba(0,0,0,.35); }
      .rarl-modal-panel::before { display:none; }
    }
    dialog.rarl-modal[open] .rarl-modal-panel { animation:rarlSheetUp .28s cubic-bezier(.16,1,.3,1); }
    @media (min-width:640px) { dialog.rarl-modal[open] .rarl-modal-panel { animation:rarlPopIn .22s cubic-bezier(.16,1,.3,1); } }
    @keyframes rarlSheetUp { from { transform:translateY(40px); opacity:.6 } to { transform:none; opacity:1 } }
    @keyframes rarlPopIn { from { transform:translateY(8px) scale(.98); opacity:0 } to { transform:none; opacity:1 } }
    .rarl-modal-close { position:absolute; top:.75rem; inset-inline-end:.75rem; z-index:3; width:2.5rem; height:2.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.9); color:#111827; box-shadow:0 1px 4px rgba(0,0,0,.15); }
    .dark .rarl-modal-close { background:rgba(31,41,55,.9); color:#f3f4f6; }
    .rarl-modal-close:hover { background:#fff; }
    body.rarl-modal-open { overflow:hidden; }
  </style>
  <script>
    // Modals: <dialog class="rarl-modal" id="x"> + any [data-modal="x"] trigger.
    // Backdrop click and Escape close; the URL hash (#x) opens it on load so
    // modals are linkable; focus returns to the trigger on close.
    window.rarlModal = {
      open: function (id, opener) {
        var d = document.getElementById(id); if (!d || d.open) return;
        d._opener = opener || document.activeElement; d.showModal(); document.body.classList.add('rarl-modal-open');
        if (d.dataset.hash !== 'off' && location.hash !== '#' + id) history.replaceState(null, '', '#' + id);
      },
      close: function (d) { if (typeof d === 'string') d = document.getElementById(d); if (d && d.open) d.close(); }
    };
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-modal]');
      if (t) { e.preventDefault(); rarlModal.open(t.dataset.modal, t); return; }
      if (e.target.closest && e.target.closest('[data-modal-close]')) { rarlModal.close(e.target.closest('dialog')); return; }
      if (e.target.tagName === 'DIALOG' && e.target.classList.contains('rarl-modal')) rarlModal.close(e.target);
    });
    document.addEventListener('close', function (e) {
      var d = e.target; if (!d.classList || !d.classList.contains('rarl-modal')) return;
      if (!document.querySelector('dialog.rarl-modal[open]')) document.body.classList.remove('rarl-modal-open');
      if (location.hash === '#' + d.id) history.replaceState(null, '', location.pathname + location.search);
      if (d._opener && d._opener.focus) d._opener.focus();
    }, true);
    function rarlOpenFromHash() { var id = decodeURIComponent(location.hash.slice(1)); var d = id && document.getElementById(id); if (d && d.classList.contains('rarl-modal')) rarlModal.open(id); }
    document.addEventListener('DOMContentLoaded', rarlOpenFromHash);
    window.addEventListener('hashchange', rarlOpenFromHash);
  </script>
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100 min-h-screen">
HTML;
}

// ── OTP (email verification / password reset) ─────────────
function generateOtp(string $email, string $purpose): string {
    $pdo = db();
    $pdo->prepare("UPDATE otp_codes SET consumed_at = NOW() WHERE email = ? AND purpose = ? AND consumed_at IS NULL")
        ->execute([$email, $purpose]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO otp_codes (email, code_hash, purpose, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
        ->execute([$email, password_hash($code, PASSWORD_BCRYPT), $purpose]);
    return $code;
}
function verifyOtp(string $email, string $purpose, string $code): bool {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT * FROM otp_codes WHERE email = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if ((int)$row['attempts'] >= 5) return false;
    $pdo->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
    if (!password_verify($code, $row['code_hash'])) return false;
    $pdo->prepare("UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return true;
}
// Minutes since the most recent OTP of this purpose was requested — used to rate-limit resends.
function otpCooldownSecondsLeft(string $email, string $purpose, int $cooldown = 60): int {
    $stmt = db()->prepare("SELECT created_at FROM otp_codes WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();
    if (!$row) return 0;
    $elapsed = time() - strtotime($row['created_at']);
    return max(0, $cooldown - $elapsed);
}

// ── Designation options (registration + profile forms) ─────
const POSITION_OPTIONS = [
    'Undergraduate Student', "Master's Student", 'PhD Student',
    'Postdoctoral Researcher', 'Lecturer', 'Research Scientist',
    'Assistant Professor', 'Associate Professor', 'Professor',
    'Industry Researcher', 'Independent Researcher', 'Other',
];

function positionSelectOptions(string $selected): string {
    $html = '<option value="">— Select —</option>';
    foreach (POSITION_OPTIONS as $p) {
        $sel = $selected === $p ? ' selected' : '';
        $html .= '<option' . $sel . '>' . htmlspecialchars($p) . '</option>';
    }
    return $html;
}

// ── Shared registration/profile option sets ─────────────────
// Defined once here (not duplicated per-file) so individual/lab registration
// and profile editing always stay in sync.
const YEARS_OPTIONS = [
    '<1' => 'Less than 1 year', '1-3' => '1-3 years', '3-5' => '3-5 years',
    '5-10' => '5-10 years', '10+' => 'More than 10 years',
];
const REFERRAL_OPTIONS = [
    'conference' => 'Academic Conference', 'referral' => 'Colleague / Peer Referral',
    'social_media' => 'Social Media', 'newsletter' => 'University Newsletter', 'other' => 'Other',
];

function optionsSelectHtml(array $options, string $selected): string {
    $html = '<option value="">— Select —</option>';
    foreach ($options as $val => $label) {
        $sel = $selected === $val ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html;
}

// ── Country list (static, bundled — no external API) ────────
// name => ISO 3166-1 alpha-2 code (code powers the flag image, see
// countryFlagUrl() below; no API/key needed for flags either).
const COUNTRIES = [
    'Afghanistan'=>'af','Albania'=>'al','Algeria'=>'dz','Andorra'=>'ad','Angola'=>'ao',
    'Argentina'=>'ar','Armenia'=>'am','Australia'=>'au','Austria'=>'at','Azerbaijan'=>'az',
    'Bahamas'=>'bs','Bahrain'=>'bh','Bangladesh'=>'bd','Belarus'=>'by','Belgium'=>'be',
    'Belize'=>'bz','Benin'=>'bj','Bhutan'=>'bt','Bolivia'=>'bo','Bosnia and Herzegovina'=>'ba',
    'Botswana'=>'bw','Brazil'=>'br','Brunei'=>'bn','Bulgaria'=>'bg','Burkina Faso'=>'bf',
    'Burundi'=>'bi','Cambodia'=>'kh','Cameroon'=>'cm','Canada'=>'ca','Chad'=>'td',
    'Chile'=>'cl','China'=>'cn','Colombia'=>'co','Costa Rica'=>'cr','Croatia'=>'hr',
    'Cuba'=>'cu','Cyprus'=>'cy','Czechia'=>'cz','Denmark'=>'dk','Dominican Republic'=>'do',
    'Ecuador'=>'ec','Egypt'=>'eg','El Salvador'=>'sv','Estonia'=>'ee','Ethiopia'=>'et',
    'Fiji'=>'fj','Finland'=>'fi','France'=>'fr','Gabon'=>'ga','Georgia'=>'ge',
    'Germany'=>'de','Ghana'=>'gh','Greece'=>'gr','Guatemala'=>'gt','Honduras'=>'hn',
    'Hong Kong'=>'hk','Hungary'=>'hu','Iceland'=>'is','India'=>'in','Indonesia'=>'id',
    'Iran'=>'ir','Iraq'=>'iq','Ireland'=>'ie','Israel'=>'il','Italy'=>'it',
    'Jamaica'=>'jm','Japan'=>'jp','Jordan'=>'jo','Kazakhstan'=>'kz','Kenya'=>'ke',
    'Kuwait'=>'kw','Kyrgyzstan'=>'kg','Laos'=>'la','Latvia'=>'lv','Lebanon'=>'lb',
    'Libya'=>'ly','Liechtenstein'=>'li','Lithuania'=>'lt','Luxembourg'=>'lu','Madagascar'=>'mg',
    'Malawi'=>'mw','Malaysia'=>'my','Maldives'=>'mv','Mali'=>'ml','Malta'=>'mt',
    'Mauritius'=>'mu','Mexico'=>'mx','Moldova'=>'md','Monaco'=>'mc','Mongolia'=>'mn',
    'Montenegro'=>'me','Morocco'=>'ma','Mozambique'=>'mz','Myanmar'=>'mm','Namibia'=>'na',
    'Nepal'=>'np','Netherlands'=>'nl','New Zealand'=>'nz','Nicaragua'=>'ni','Niger'=>'ne',
    'Nigeria'=>'ng','North Korea'=>'kp','North Macedonia'=>'mk','Norway'=>'no','Oman'=>'om',
    'Pakistan'=>'pk','Panama'=>'pa','Papua New Guinea'=>'pg','Paraguay'=>'py','Peru'=>'pe',
    'Philippines'=>'ph','Poland'=>'pl','Portugal'=>'pt','Qatar'=>'qa','Romania'=>'ro',
    'Russia'=>'ru','Rwanda'=>'rw','Saudi Arabia'=>'sa','Senegal'=>'sn','Serbia'=>'rs',
    'Singapore'=>'sg','Slovakia'=>'sk','Slovenia'=>'si','Somalia'=>'so','South Africa'=>'za',
    'South Korea'=>'kr','South Sudan'=>'ss','Spain'=>'es','Sri Lanka'=>'lk','Sudan'=>'sd',
    'Sweden'=>'se','Switzerland'=>'ch','Syria'=>'sy','Taiwan'=>'tw','Tajikistan'=>'tj',
    'Tanzania'=>'tz','Thailand'=>'th','Togo'=>'tg','Trinidad and Tobago'=>'tt','Tunisia'=>'tn',
    'Turkey'=>'tr','Turkmenistan'=>'tm','Uganda'=>'ug','Ukraine'=>'ua','United Arab Emirates'=>'ae',
    'United Kingdom'=>'gb','United States'=>'us','Uruguay'=>'uy','Uzbekistan'=>'uz','Venezuela'=>'ve',
    'Vietnam'=>'vn','Yemen'=>'ye','Zambia'=>'zm','Zimbabwe'=>'zw',
];

function countrySelectOptions(string $selected): string {
    $html = '<option value="">— Select —</option>';
    foreach (COUNTRIES as $name => $code) {
        $sel = $selected === $name ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($name) . '" data-code="' . $code . '"' . $sel . '>' . htmlspecialchars($name) . '</option>';
    }
    return $html;
}

// Flag image URL — flags.restcountries.com needs no API key.
function countryFlagUrl(string $countryName): ?string {
    $code = COUNTRIES[$countryName] ?? null;
    return $code ? "https://flags.restcountries.com/v5/w320/{$code}.png" : null;
}

// Renders a country <select> (name="country") plus a live flag preview next
// to it — used on both registration forms and the profile edit form so the
// behavior/markup isn't duplicated four times. $uid must be unique per
// <select> on the page (e.g. "reg-ind", "profile-lab").
function countryFieldHtml(string $selected, string $uid, bool $required = true): string {
    $flagUrl = $selected !== '' ? countryFlagUrl($selected) : null;
    $reqAttr = $required ? ' required' : '';
    $selectId = 'country-select-' . $uid;
    $flagId   = 'country-flag-' . $uid;
    $display  = $flagUrl ? '' : 'display:none;';
    return '
        <div class="flex items-center gap-2">
          <select name="country" id="' . $selectId . '"' . $reqAttr . '
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition"
            onchange="var o=this.options[this.selectedIndex],f=document.getElementById(\'' . $flagId . '\');if(o.dataset.code){f.src=\'https://flags.restcountries.com/v5/w320/\'+o.dataset.code+\'.png\';f.style.display=\'\';}else{f.style.display=\'none\';}">
            ' . countrySelectOptions($selected) . '
          </select>
          <img id="' . $flagId . '" src="' . htmlspecialchars((string)$flagUrl) . '" alt="" style="' . $display . '" class="w-8 h-6 object-cover rounded flex-shrink-0 border border-gray-200 dark:border-gray-600"/>
        </div>';
}

// ── Install / migrations — see migrations.php ──────────────
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/extras.php';
require_once __DIR__ . '/premade-templates.php';

function dbTablesExist(): bool {
    try {
        $pdo = db();
        return (bool) $pdo->query("SHOW TABLES LIKE 'members'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

// ── Admin login throttle (brute-force protection) ──────────
// Tracked per client IP in the settings table (no extra table needed) —
// locks out after 5 failed attempts within 15 minutes.
function loginThrottleKey(string $ip): string {
    return 'login_throttle_' . hash('sha256', $ip);
}

function loginThrottleCheck(string $ip): int {
    $raw = setting(loginThrottleKey($ip), '');
    if ($raw === '') return 0;
    $data = json_decode($raw, true);
    if (!$data || empty($data['locked_until'])) return 0;
    $left = $data['locked_until'] - time();
    return max(0, $left);
}

function loginThrottleRecordFailure(string $ip): void {
    $key  = loginThrottleKey($ip);
    $raw  = setting($key, '');
    $data = $raw !== '' ? json_decode($raw, true) : null;
    $count = ($data['count'] ?? 0) + 1;
    $lockedUntil = ($data['locked_until'] ?? 0);
    if ($count >= 5) {
        $lockedUntil = time() + 900; // 15 minutes
        $count = 0;
    }
    $value = json_encode(['count' => $count, 'locked_until' => $lockedUntil]);
    db()->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
        ->execute([$key, $value, $value]);
}

function loginThrottleReset(string $ip): void {
    db()->prepare("DELETE FROM settings WHERE `key` = ?")->execute([loginThrottleKey($ip)]);
}

// ── Regional section matching ("nearest chair") ────────────
function countryToContinent(string $country): ?string {
    static $map = [
        'pakistan'=>'Asia','bangladesh'=>'Asia','india'=>'Asia','korea'=>'Asia','south korea'=>'Asia',
        'malaysia'=>'Asia','china'=>'Asia','japan'=>'Asia','indonesia'=>'Asia','vietnam'=>'Asia',
        'thailand'=>'Asia','philippines'=>'Asia','singapore'=>'Asia','sri lanka'=>'Asia','nepal'=>'Asia',
        'saudi arabia'=>'Asia','uae'=>'Asia','united arab emirates'=>'Asia','iran'=>'Asia','iraq'=>'Asia',
        'turkey'=>'Europe','israel'=>'Asia','qatar'=>'Asia',
        'nigeria'=>'Africa','egypt'=>'Africa','south africa'=>'Africa','kenya'=>'Africa','ghana'=>'Africa',
        'morocco'=>'Africa','ethiopia'=>'Africa','tanzania'=>'Africa','uganda'=>'Africa','algeria'=>'Africa',
        'united kingdom'=>'Europe','uk'=>'Europe','france'=>'Europe','germany'=>'Europe','italy'=>'Europe',
        'spain'=>'Europe','portugal'=>'Europe','netherlands'=>'Europe','belgium'=>'Europe','sweden'=>'Europe',
        'norway'=>'Europe','poland'=>'Europe','greece'=>'Europe','switzerland'=>'Europe','austria'=>'Europe',
        'ireland'=>'Europe','finland'=>'Europe','denmark'=>'Europe',
        'united states'=>'Americas','usa'=>'Americas','us'=>'Americas','canada'=>'Americas','mexico'=>'Americas',
        'brazil'=>'Americas','argentina'=>'Americas','chile'=>'Americas','colombia'=>'Americas',
        'australia'=>'Oceania','new zealand'=>'Oceania',
    ];
    return $map[strtolower(trim($country))] ?? null;
}
// Resolves the member's nearest chair: exact country chapter first, else the continent section.
function nearestSection(string $country): ?int {
    $country = trim($country);
    if ($country === '') return null;
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id FROM regional_sections WHERE scope='country' AND is_published=1 AND country = ? LIMIT 1");
    $stmt->execute([$country]);
    if ($row = $stmt->fetch()) return (int)$row['id'];

    $continent = countryToContinent($country);
    if (!$continent) return null;
    $stmt = $pdo->prepare("SELECT id FROM regional_sections WHERE scope='continent' AND is_published=1 AND continent = ? ORDER BY display_order LIMIT 1");
    $stmt->execute([$continent]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

// ── Membership plan auto-assignment ─────────────────────────
function planIdFor(string $memberType): ?int {
    $slug = $memberType === 'lab' ? 'organization' : 'free';
    $stmt = db()->prepare("SELECT id FROM membership_plans WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

// ── Member code / ID card numbering ─────────────────────────
// Same fix as nextCertNumber(): SQL-side atomic increment instead of
// read-through-cache-then-write, so repeated calls within one request (bulk
// activate issuing cards for several members) don't hand out the same number twice.
// Format matches the official RARL roster: <year><4-digit counter>, e.g. 20260885.
// Stored without the leading "#" — display sites (directory.php, roster-pdf.php,
// id-card-verify.php, etc.) already prepend it when rendering member_code.
function nextMemberCode(): string {
    $pdo = db();
    $pdo->exec("UPDATE settings SET value = value + 1 WHERE `key` = 'id_card_counter'");
    $counter = (int) $pdo->query("SELECT value FROM settings WHERE `key` = 'id_card_counter'")->fetchColumn();
    return date('Y') . str_pad((string)$counter, 4, '0', STR_PAD_LEFT);
}

// ── Simple bare-URL auto-linker (applied to already-escaped text) ──
function autoLinkUrls(string $escapedHtml): string {
    return preg_replace(
        '#(https?://[^\s<]+)#i',
        '<a href="$1" target="_blank" rel="noopener nofollow" class="underline text-rarl-red break-all">$1</a>',
        $escapedHtml
    );
}

// ── Markdown renderer (Parsedown, bundled in libs/parsedown — same
// no-Composer/single-file convention as libs/fpdf and libs/phpqrcode) ──────
// Safe mode strips raw HTML/script input from the source text entirely, so
// this is safe to run directly on untrusted member-submitted post/comment
// bodies without a separate escape pass.
function markdownToHtml(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    static $parsedown = null;
    if ($parsedown === null) {
        require_once __DIR__ . '/libs/parsedown/Parsedown.php';
        $parsedown = new Parsedown();
        $parsedown->setSafeMode(true);   // strips raw HTML tags from input
        $parsedown->setBreaksEnabled(true); // single newline -> <br>, chat-style
    }

    $html = $parsedown->text($raw);
    // Harden generated links the same way the rest of the app links out to
    // member-submitted URLs (autoLinkUrls, certificate links, etc).
    return preg_replace('/<a href=/', '<a target="_blank" rel="noopener nofollow" href=', $html);
}

// ── Lottie JSON fetch (server-side, cached) ─────────────────
// lottie.loadAnimation({path:...}) fetches client-side, which breaks across
// subdomains without CORS headers on the source (WordPress uploads rarely
// send any). Fetching it here once and embedding the JSON directly into the
// page sidesteps CORS entirely. Cached in settings for 24h.
function getLottieJson(string $url): ?string {
    $cacheKey = 'lottie_cache_' . md5($url);
    $cached = setting($cacheKey, '');
    $cachedAt = (int) setting($cacheKey . '_at', '0');
    if ($cached !== '' && (time() - $cachedAt) < 86400) return $cached;

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && json_decode($body) !== null;
    curl_close($ch);

    if (!$ok) return $cached !== '' ? $cached : null;

    $pdo = db();
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$cacheKey, $body, $body]);
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$cacheKey . '_at', time(), time()]);
    return $body;
}

// ── Rich-text sanitizer (community posts) ───────────────────
// Quill.js (loaded client-side in community.php) produces arbitrary HTML,
// which is never trusted as-is. This strips everything to an allow-list of
// tags/attributes before it's stored — the ONLY place community_posts.body
// is allowed to contain raw HTML instead of markdown (body_format='html').
const RICH_TEXT_ALLOWED_TAGS = [
    'p','br','strong','b','em','i','u','s','a','ul','ol','li',
    'h1','h2','h3','blockquote','code','pre','span',
];

function sanitizeRichHtml(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $html = mb_substr($html, 0, 10000); // hard cap before parsing

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Wrap in a template so DOMDocument doesn't invent <html><body>, and force UTF-8.
    $dom->loadHTML('<?xml encoding="utf-8"?><div id="rarl-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $root = $dom->getElementById('rarl-root');
    if (!$root) return '';

    sanitizeRichHtmlNode($root);
    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $dom->saveHTML($child);
    }
    return trim($out);
}

function sanitizeRichHtmlNode(DOMNode $node): void {
    $toRemove = [];
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) continue;
        if ($child instanceof DOMComment) { $toRemove[] = $child; continue; }
        if (!($child instanceof DOMElement)) { $toRemove[] = $child; continue; }

        $tag = strtolower($child->tagName);
        if (!in_array($tag, RICH_TEXT_ALLOWED_TAGS, true)) {
            // Unwrap disallowed tags (script/style/img/etc.) but keep their text content —
            // don't silently drop what the member typed, just strip the markup around it.
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $toRemove[] = $child;
            continue;
        }

        foreach (iterator_to_array($child->attributes ?? []) as $attr) {
            if ($tag === 'a' && $attr->name === 'href') {
                $href = trim($attr->value);
                if (!preg_match('#^(https?://|mailto:)#i', $href)) {
                    $child->removeAttribute('href');
                }
                continue;
            }
            $child->removeAttribute($attr->name);
        }
        if ($tag === 'a' && $child->hasAttribute('href')) {
            $child->setAttribute('target', '_blank');
            $child->setAttribute('rel', 'noopener nofollow');
        }

        sanitizeRichHtmlNode($child);
    }
    foreach ($toRemove as $c) $node->removeChild($c);
}

// Avatar circle for a member — falls back to an initial-letter badge when no
// avatar_path is set, so post/comment authors always show something.
// $needsAttention wraps it in a red ring + small dot badge (used for "your own"
// avatar in the nav/dashboard when onboarding isn't finished — see
// memberNeedsAttention()) — replaces the earlier cron/email reminder approach
// with a persistent, low-noise visual cue instead of an inbox nag.
function memberAvatarHtml(?string $avatarPath, string $displayName, string $sizeClasses = 'w-9 h-9 text-sm', bool $needsAttention = false): string {
    $initial = htmlspecialchars(strtoupper(mb_substr($displayName, 0, 1)));
    $inner = !empty($avatarPath)
        ? '<img src="' . UPLOADS_URL . '/avatars/' . htmlspecialchars($avatarPath) . '" alt="" class="' . $sizeClasses . ' rounded-full object-cover flex-shrink-0' . ($needsAttention ? ' ring-2 ring-rarl-red ring-offset-2' : '') . '"/>'
        : '<div class="' . $sizeClasses . ' rounded-full bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-300 font-bold flex items-center justify-center flex-shrink-0' . ($needsAttention ? ' ring-2 ring-rarl-red ring-offset-2' : '') . '">' . $initial . '</div>';
    if (!$needsAttention) return $inner;
    return '<span class="relative inline-flex flex-shrink-0">' . $inner
         . '<span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-rarl-red rounded-full border-2 border-white dark:border-gray-900"></span></span>';
}

// True if this member has an unfinished onboarding step worth surfacing as a
// visual nudge (ring + dot on their avatar) rather than an email. Keep this in
// sync with dashboard.php's checklist — same underlying signals, condensed to
// one boolean for chrome that appears on every page (nav, dashboard header).
function memberNeedsAttention(array $member): bool {
    if ($member['status'] !== 'active') return false;
    if (empty($member['avatar_path'])) return true;
    if (empty($member['id_card_path'])) return true;
    $hasProfileInfo = !empty($member['institution']) || !empty($member['lab_website']);
    return !$hasProfileInfo;
}

// The "Robotics & Automation Research Lab (RARL) Team" system account admin-authored feed posts are
// attributed to (see sql/009_admin_only_posts.sql) — reuses community_posts'
// existing member_id join instead of adding a parallel admin-post table.
// SQL condition for real people: excludes internal accounts such as the
// "RARL Team" account team posts are published as (…@rarl.internal).
const REAL_MEMBER_SQL = "email NOT LIKE '%@rarl.internal'";

function systemPosterMemberId(): ?int {
    static $id = null;
    if ($id === null) {
        $id = (int) (db()->query("SELECT id FROM members WHERE email = 'community-team@rarl.internal' LIMIT 1")->fetchColumn() ?: 0);
    }
    return $id ?: null;
}

// ── Community post card renderer ────────────────────────────
// Shared by the initial community.php page load and the lazy-load AJAX
// endpoint (?ajax=posts) — one post's full HTML (content, image, like/comment
// counts, comments, reply form, and edit/delete controls if $myMemberId owns it).
// Members follow RARL rather than post, so replies under team posts are off
// unless an admin turns them on (Admin → Community → Member replies). Off
// also hides existing replies, since they show other members' names.
function communityCommentsEnabled(): bool {
    return setting('community_comments_enabled', '0') === '1';
}

function renderCommunityPost(PDO $pdo, array $post, int $myMemberId): string {
    $likeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM community_likes WHERE post_id=?');
    $likeCountStmt->execute([$post['id']]);
    $likeCount = (int) $likeCountStmt->fetchColumn();

    $likedStmt = $pdo->prepare('SELECT 1 FROM community_likes WHERE post_id=? AND member_id=?');
    $likedStmt->execute([$post['id'], $myMemberId]);
    $likedByMe = (bool) $likedStmt->fetch();

    $commentsStmt = $pdo->prepare('SELECT community_comments.*, members.full_name, members.lab_name, members.type, members.avatar_path
                                     FROM community_comments JOIN members ON members.id = community_comments.member_id
                                     WHERE post_id=? AND is_hidden=0 ORDER BY created_at ASC');
    $commentsStmt->execute([$post['id']]);
    $comments = $commentsStmt->fetchAll();

    $authorName = $post['type'] === 'lab' ? $post['lab_name'] : $post['full_name'];
    $bodyHtml = ($post['body_format'] ?? 'markdown') === 'html' ? $post['body'] : markdownToHtml($post['body']);
    $isOwner = $myMemberId > 0 && (int)$post['member_id'] === $myMemberId;
    $isOfficial = (int)$post['member_id'] === systemPosterMemberId();
    $pid = (int)$post['id'];

    ob_start();
    ?>
    <div id="post-<?= $pid ?>" class="bg-white dark:bg-gray-900 border-y sm:border sm:border-gray-200 sm:dark:border-gray-700 border-gray-200 dark:border-gray-700 sm:rounded-2xl p-4 sm:p-6 shadow-sm">
      <div class="flex items-start justify-between gap-2 mb-3">
        <div class="flex items-center gap-2 sm:gap-2.5 min-w-0">
          <?php if ($isOfficial): ?>
          <img src="<?= BRAND_MARK_PATH ?>" alt="" class="w-10 h-10 sm:w-9 sm:h-9 rounded-full object-contain bg-rarl-navy p-1.5 flex-shrink-0"/>
          <?php else: ?>
          <?= memberAvatarHtml($post['avatar_path'] ?? null, $authorName, 'w-10 h-10 sm:w-9 sm:h-9 text-sm') ?>
          <?php endif; ?>
          <div class="min-w-0">
            <div class="flex items-center gap-1.5 flex-wrap">
              <?php if ($post['is_pinned']): ?><span class="text-xs font-bold text-amber-500"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
              <span class="font-semibold text-sm text-gray-900 dark:text-white truncate"><?= htmlspecialchars($authorName) ?></span>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] text-gray-400">
              <span class="font-bold uppercase tracking-wider <?= $isOfficial ? 'text-rarl-red' : 'text-gray-400' ?>"><?= $isOfficial ? 'RARL Team' : htmlspecialchars(ucfirst($post['type'])) ?></span>
              <span>·</span>
              <span title="<?= date('d M Y, H:i', strtotime($post['created_at'])) ?>"><?= timeAgo($post['created_at']) ?></span>
            </div>
          </div>
        </div>
        <?php if ($isOwner): ?>
        <div class="flex items-center gap-3 flex-shrink-0 pl-2">
          <button type="button" onclick="rarlToggleEditPanel(<?= $pid ?>)" class="text-gray-300 hover:text-gray-600 dark:hover:text-gray-300 text-sm" title="Edit"><i class="fa-solid fa-pen"></i></button>
          <form method="POST" onsubmit="return confirm('Delete this post?')" class="inline">
            <?= csrfField() ?><input type="hidden" name="action" value="delete_own_post"><input type="hidden" name="post_id" value="<?= $pid ?>">
            <button type="submit" class="text-gray-300 hover:text-red-500 text-sm" title="Delete"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($bodyHtml !== ''): ?>
      <div class="post-text-wrap-<?= $pid ?> relative mb-4">
        <div class="rich-content post-view-<?= $pid ?> post-clamp-<?= $pid ?> text-gray-600 dark:text-gray-300 text-sm leading-relaxed line-clamp-4"><?= $bodyHtml ?></div>
        <button type="button" onclick="rarlExpandPost(<?= $pid ?>)" class="post-more-<?= $pid ?> hidden text-xs font-semibold text-gray-400 hover:text-rarl-red mt-1">…more</button>
      </div>
      <?php endif; ?>
      <?php if (!empty($post['image_path'])): ?>
      <img src="<?= UPLOADS_URL ?>/community/<?= htmlspecialchars($post['image_path']) ?>" alt="" loading="lazy"
        class="post-view-<?= $pid ?> w-[calc(100%+2rem)] sm:w-full -mx-4 sm:mx-0 max-h-[70vh] sm:max-h-none object-cover sm:rounded-xl border-y sm:border border-gray-100 dark:border-gray-800 mb-4"/>
      <?php endif; ?>

      <?php if ($isOwner): ?>
      <div id="edit-panel-<?= $pid ?>" class="hidden mb-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
        <form method="POST" enctype="multipart/form-data" id="edit-post-form-<?= $pid ?>" onsubmit="return rarlSubmitEdit(<?= $pid ?>)">
          <?= csrfField() ?><input type="hidden" name="action" value="edit_post"><input type="hidden" name="post_id" value="<?= $pid ?>">
          <input type="hidden" name="body_html" id="edit-body-html-<?= $pid ?>">
          <div id="edit-quill-toolbar-<?= $pid ?>">
            <span class="ql-formats"><button class="ql-bold"></button><button class="ql-italic"></button><button class="ql-underline"></button></span>
            <span class="ql-formats"><button class="ql-list" value="ordered"></button><button class="ql-list" value="bullet"></button></span>
            <span class="ql-formats"><button class="ql-link"></button><button class="ql-blockquote"></button></span>
          </div>
          <div id="edit-quill-editor-<?= $pid ?>" data-content="<?= htmlspecialchars($bodyHtml) ?>" style="min-height:70px;"></div>
          <?php if (!empty($post['image_path'])): ?>
          <label class="flex items-center gap-2 text-xs text-gray-500 mt-2">
            <input type="checkbox" name="remove_image" value="1" class="accent-rarl-red"/> Remove current photo
          </label>
          <?php endif; ?>
          <div class="flex justify-end gap-2 mt-3">
            <button type="button" onclick="document.getElementById('edit-panel-<?= $pid ?>').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-gray-500 hover:text-gray-700">Cancel</button>
            <button type="submit" class="px-4 py-2 bg-rarl-red hover:bg-rarl-dark text-white text-xs font-semibold rounded-lg">Save</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="flex items-center gap-5 sm:gap-4 mb-4 py-1 border-y sm:border-y-0 border-gray-100 dark:border-gray-800">
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="action" value="toggle_like"><input type="hidden" name="post_id" value="<?= $pid ?>">
          <button type="submit" class="inline-flex items-center gap-1.5 text-xs font-semibold py-2 <?= $likedByMe ? 'text-rarl-red' : 'text-gray-400 hover:text-rarl-red' ?> transition-colors">
            <?= $likedByMe ? '<i class="fa-solid fa-heart"></i>' : '<i class="fa-regular fa-heart"></i>' ?> <?= $likeCount ?>
          </button>
        </form>
        <?php if (communityCommentsEnabled()): ?><span class="text-xs text-gray-400 py-2"><i class="fa-solid fa-comment"></i> <?= count($comments) ?></span><?php endif; ?>
      </div>

      <?php if (communityCommentsEnabled()): ?>
      <?php if (!empty($comments)): ?>
      <div class="space-y-3 pt-1 mb-4">
        <?php foreach ($comments as $c):
          $cAuthor = $c['type'] === 'lab' ? $c['lab_name'] : $c['full_name'];
          $cHtml   = markdownToHtml($c['body']);
          $cid     = (int)$c['id'];
          $cIsOwner = $myMemberId > 0 && (int)$c['member_id'] === $myMemberId;
        ?>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-xl px-3 sm:px-4 py-2.5 sm:py-3">
          <div class="flex items-start gap-2 mb-1">
            <?= memberAvatarHtml($c['avatar_path'] ?? null, $cAuthor, 'w-7 h-7 sm:w-6 sm:h-6 text-[10px]') ?>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between">
                <span class="font-semibold text-xs text-gray-800 dark:text-white"><?= htmlspecialchars($cAuthor) ?></span>
                <div class="flex items-center gap-2 flex-shrink-0">
                  <span class="text-[10px] text-gray-400"><?= date('d M Y, H:i', strtotime($c['created_at'])) ?></span>
                  <?php if ($cIsOwner): ?>
                  <button type="button" onclick="document.getElementById('comment-edit-<?= $cid ?>').classList.toggle('hidden')" class="text-gray-300 hover:text-gray-600 dark:hover:text-gray-300 text-[10px]" title="Edit"><i class="fa-solid fa-pen"></i></button>
                  <form method="POST" onsubmit="return confirm('Delete this comment?')" class="inline">
                    <?= csrfField() ?><input type="hidden" name="action" value="delete_own_comment"><input type="hidden" name="comment_id" value="<?= $cid ?>"><input type="hidden" name="post_id" value="<?= $pid ?>">
                    <button type="submit" class="text-gray-300 hover:text-red-500 text-[10px]" title="Delete"><i class="fa-solid fa-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>
              <div class="md-content text-gray-500 dark:text-gray-400 text-xs leading-relaxed"><?= $cHtml ?></div>
              <?php if ($cIsOwner): ?>
              <form method="POST" id="comment-edit-<?= $cid ?>" class="hidden mt-2">
                <?= csrfField() ?><input type="hidden" name="action" value="edit_comment"><input type="hidden" name="comment_id" value="<?= $cid ?>"><input type="hidden" name="post_id" value="<?= $pid ?>">
                <div class="flex gap-2">
                  <input type="text" name="body" required maxlength="2000" value="<?= htmlspecialchars($c['body']) ?>"
                    class="flex-1 px-2.5 py-1.5 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
                  <button type="submit" class="px-3 py-1.5 bg-rarl-red hover:bg-rarl-dark text-white text-[10px] font-semibold rounded-lg">Save</button>
                </div>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <form method="POST" class="flex gap-2" title="Markdown supported — **bold**, *italic*, [link](url)">
        <?= csrfField() ?><input type="hidden" name="action" value="create_comment"><input type="hidden" name="post_id" value="<?= $pid ?>">
        <input type="text" name="body" required maxlength="2000" placeholder="Write a reply…"
          class="md-editor flex-1 min-w-0 px-3 py-2.5 sm:py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg text-sm sm:text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        <button type="submit" class="px-4 py-2.5 sm:py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-white font-semibold text-xs rounded-lg transition-colors flex-shrink-0">Reply</button>
      </form>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Compact relative timestamp ("2w", "5h", "just now") for mobile post headers —
// full date/time is still available via the element's title attribute.
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'now';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'd';
    if ($diff < 2419200) return floor($diff / 604800) . 'w';
    if ($diff < 29030400) return floor($diff / 2419200) . 'mo';
    return floor($diff / 29030400) . 'y';
}

// Plain-text excerpt of a post/comment body regardless of format (markdown
// source has no tags to strip; html source does) — for previews/emails.
function communityBodyExcerpt(string $body, int $len = 120): string {
    return mb_strimwidth(trim(strip_tags($body)), 0, $len, '…');
}

// ── File upload validation ──────────────────────────────────
// Returns the stored filename on success, or null on failure (bad type/size/upload error).
function validateUpload(array $file, array $allowedExt, int $maxBytes, string $destDir): ?string {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > $maxBytes) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'pdf'=>'application/pdf','doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif',
    ];
    if (isset($allowedMimes[$ext]) && $mime !== $allowedMimes[$ext]) return null;

    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $filename = generateUuid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], rtrim($destDir, '/') . '/' . $filename)) return null;
    return $filename;
}

// ── Reseed default membership plans (also used by admin "Reseed Defaults") ──
// Regional/chapter leadership is NOT reseeded here — it's live data owned by
// admin/sections.php. Reseeding it from a hardcoded list would silently overwrite
// whatever an admin has since edited there. Chapters only get a one-time bootstrap
// row (see seedRegionalSectionsIfEmpty()) so the table always starts editable, never fixed.
function seedDefaults(): void {
    $pdo = db();
    $pdo->exec("INSERT INTO membership_plans (slug,name,tagline,description,audience,regular_price,display_order) VALUES
        ('free','Free','Open to every researcher','Full community access at no cost — individuals and labs alike.','both',NULL,1),
        ('student','Student','For students and trainees','For PhD/MSc/BSc students and early-career researchers.','individual',0.00,2),
        ('organization','Organization','For labs & institutions','For research labs and institutions joining as an organization.','lab',2500.00,3)
        ON DUPLICATE KEY UPDATE name=VALUES(name), tagline=VALUES(tagline), description=VALUES(description), audience=VALUES(audience), display_order=VALUES(display_order)");
}

// ── One-time bootstrap for regional_sections: only fires if the table is empty,
// so it never clobbers admin edits made via admin/sections.php. ──
function seedRegionalSectionsIfEmpty(): void {
    $pdo = db();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM regional_sections")->fetchColumn();
    if ($count > 0) return;
    $pdo->exec("INSERT INTO regional_sections (scope,continent,country,name,chair_name,chair_email,chair_title,display_order) VALUES
        ('country','Asia','Korea','Korea Chapter','Seyed Ali Eftekhari','sae@rarl-lab.com','Chapter Chair',1),
        ('country','Asia','Bangladesh','Bangladesh Chapter','Atanu Shuvam Roy','asr@rarl-lab.com','Chapter Chair',2),
        ('country','Asia','Pakistan','Pakistan Chapter','Dr. Zeashan Hameed Khan','zhk@rarl.com','Chapter Chair',3),
        ('country','Asia','Malaysia','Malaysia Chapter','Dr. Mohammad Ali Toufigh',NULL,'Chapter Chair',4),
        ('country','Asia','China','China Chapter','Dr. Arash Sioofy Khoojine',NULL,'Chapter Chair',5),
        ('continent','Africa',NULL,'Africa Chapter','Dr. Mukhtar Iderawumi Abdulraheem',NULL,'Chapter Chair',6)");
}

// ── Member ID card PDF generation (reuses libs/fpdf + libs/phpqrcode, same as certificates) ──
function generateIdCardPDF(array $member, string $sectionName, string $chairName): ?string {
    if (!class_exists('FPDF')) {
        if (file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) require_once __DIR__ . '/libs/fpdf/fpdf.php';
    }
    if (!class_exists('FPDF')) return null;
    if (!class_exists('QRcode') && file_exists(__DIR__ . '/libs/phpqrcode/qrlib.php')) {
        require_once __DIR__ . '/libs/phpqrcode/qrlib.php';
    }

    [$inkR,$inkG,$inkB] = brandRgb(BRAND_INK);
    [$redR,$redG,$redB] = brandRgb(BRAND_RED);

    $name = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
    $verifyUrl = SITE_URL . '/id-card-verify.php?code=' . urlencode($member['member_code']);

    $pdf = new FPDF('L', 'mm', [86, 54]); // credit-card size
    $pdf->SetAutoPageBreak(false); // small custom page size; default ~20mm break trigger would spill content onto extra blank pages
    $pdf->AddPage();
    $pdf->SetFillColor($inkR, $inkG, $inkB);
    $pdf->Rect(0, 0, 86, 54, 'F');

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor($redR, $redG, $redB);
    $pdf->SetXY(4, 3); $pdf->Cell(0, 5, 'RARL', 0, 1);
    $pdf->SetFont('Helvetica', '', 5);
    $pdf->SetTextColor(200, 200, 200);
    $pdf->SetXY(4, 8); $pdf->Cell(0, 3, 'Robotic and Automation Research Lab', 0, 1);

    $pdf->SetFont('Helvetica', 'B', 7);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(4, 14); $pdf->Cell(0, 4, fpdfEnc(mb_strtoupper($name)), 0, 1);

    if (!empty($member['avatar_path']) && file_exists(UPLOADS_PATH . '/avatars/' . $member['avatar_path'])) {
        try {
            $pdf->Image(UPLOADS_PATH . '/avatars/' . $member['avatar_path'], 4, 20, 16, 20);
        } catch (Throwable $e) {
            error_log('ID card avatar image failed to embed for member ' . $member['id'] . ': ' . $e->getMessage());
        }
    }

    $pdf->SetFont('Helvetica', '', 5.5);
    $pdf->SetTextColor(220, 220, 220);
    $pdf->SetXY(22, 22);
    $pdf->MultiCell(30, 3.2, fpdfEnc(
        "MEMBER ID: #{$member['member_code']}\n" .
        'SINCE: ' . date('Y/m/d', strtotime($member['created_at'])) . "\n" .
        'SECTION: ' . ($sectionName ?: '—')
    ), 0, 'L');

    $qrPath = null;
    if (class_exists('QRcode')) {
        $qrPath = sys_get_temp_dir() . '/rarl_idqr_' . $member['id'] . '.png';
        QRcode::png($verifyUrl, $qrPath, QR_ECLEVEL_L, 4, 1);
    }
    if ($qrPath && file_exists($qrPath)) {
        $pdf->Image($qrPath, 56, 18, 20, 20, 'PNG');
        @unlink($qrPath);
    }
    $pdf->SetFont('Helvetica', '', 4.5);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetXY(4, 40);
    $pdf->Cell(0, 3, fpdfEnc(setting('idcard_signer1_name', 'RARL President')), 0, 1);
    $pdf->SetXY(4, 44);
    $pdf->Cell(0, 3, fpdfEnc($chairName ?: setting('idcard_signer2_name', 'Section Chair')), 0, 1);

    $pdf->SetFont('Helvetica', '', 4);
    $pdf->SetTextColor(180, 180, 180);
    $pdf->SetXY(4, 49);
    $pdf->Cell(0, 3, 'Valid for 3 years from issue · Robotics & Automation Research Lab (RARL)', 0, 1);

    $dir = UPLOADS_PATH . '/id-cards/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'idcard_' . $member['uuid'] . '.pdf';
    $pdf->Output('F', $dir . $filename);
    return $filename;
}

// ── Uploadable template renderer (ID cards & certificates) ──────────────
// A template is a row in `certificate_templates`: a background image plus a
// JSON `config` array of positioned fields, e.g.
//   [{"key":"name","x":50,"y":40,"font_size":22,"color":"#1a1a1a","align":"center","bold":true},
//    {"key":"qr","x":85,"y":85,"w":15,"h":15}]
// x/y are percentages of the page; font_size is in pt; image w/h are in mm.
// The same config drives the designer (admin/templates.php), the HTML preview
// (renderTemplateHtml) and the FPDF output (renderTemplatePdf).
// Recognized field keys: name, event, date, cert_no, member_code, section,
// signer1, signer2, since_date, custom_text, qr / avatar / signature (images).
// Optional text props: font (helvetica|times|courier), italic, uppercase,
// max_width (mm — text shrinks to fit, so long names never overflow).
// custom_text may contain {tokens} (e.g. "Awarded to {name}") merged from $data.
const TEMPLATE_FONTS = ['helvetica' => 'Helvetica', 'times' => 'Times', 'courier' => 'Courier',
                        'montserrat' => 'Montserrat', 'playfair' => 'Playfair', 'script' => 'GreatVibes'];
// Embedded fonts (libs/fpdf/font, generated from assets/fonts with FPDF's makefont).
// Each has a regular and a bold file; there are no italics, so italic is ignored.
const TEMPLATE_EMBEDDED_FONTS = [
    'Montserrat' => ['' => 'montserrat-400.php', 'B' => 'montserrat-800.php'],
    'Playfair'   => ['' => 'playfair-display-700.php', 'B' => 'playfair-display-700.php'],
    'GreatVibes' => ['' => 'great-vibes-400.php', 'B' => 'great-vibes-400.php'],
];
/** Registers an embedded font with FPDF on first use; returns the style FPDF can actually draw. */
function templatePdfFont($pdf, string $family, string $style): string {
    if (!isset(TEMPLATE_EMBEDDED_FONTS[$family])) return $style;
    $style = str_contains($style, 'B') ? 'B' : '';
    $pdf->AddFont($family, $style, TEMPLATE_EMBEDDED_FONTS[$family][$style]); // no-op if already registered on this PDF
    return $style;
}
/** @font-face rules so on-screen previews use the same fonts as the PDF. */
function templateFontFaceCss(string $base = ''): string {
    $base = $base ?: rtrim(SITE_URL, '/') . '/assets/fonts/';
    return "@font-face{font-family:'RarlMontserrat';src:url('{$base}montserrat-400.ttf')}"
         . "@font-face{font-family:'RarlMontserrat';font-weight:bold;src:url('{$base}montserrat-800.ttf')}"
         . "@font-face{font-family:'RarlPlayfair';src:url('{$base}playfair-display-700.ttf')}"
         . "@font-face{font-family:'RarlScript';src:url('{$base}great-vibes-400.ttf')}";
}
const TEMPLATE_IMAGE_KEYS = ['qr', 'avatar', 'signature'];

function templateFieldValue(string $key, array $data): string {
    return (string)($data[$key] ?? '');
}

// FPDF's core fonts (Helvetica etc.) only understand Windows-1252/Latin-1, not
// UTF-8 — feeding UTF-8 straight into Cell()/MultiCell()/Text() renders as
// mojibake (e.g. the em-dash "—" comes out as "â€"") because each UTF-8 byte
// gets shown as its own cp1252 character. Every string reaching FPDF (from
// DB content, not the hardcoded ASCII labels) must go through this first.
// //TRANSLIT approximates unmappable characters (curly quotes, etc.) instead
// of dropping them; //IGNORE silently drops anything that still can't map
// (e.g. CJK/emoji) rather than throwing.
function fpdfEnc(string $s): string {
    $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $out !== false ? $out : $s;
}

// Resolves the final text of a text field: data value or merged custom text,
// with the optional uppercase transform applied.
function templateFieldText(array $f, array $data): string {
    $key = $f['key'] ?? '';
    $text = $key === 'custom_text'
        ? preg_replace_callback('/\{([a-z0-9_]+)\}/', fn($m) => array_key_exists($m[1], $data) && is_scalar($data[$m[1]]) ? (string)$data[$m[1]] : $m[0], (string)($f['text'] ?? ''))
        : templateFieldValue($key, $data);
    return !empty($f['uppercase']) ? mb_strtoupper($text) : $text;
}

function templateFontFamily(array $f): string {
    return TEMPLATE_FONTS[$f['font'] ?? 'helvetica'] ?? 'Helvetica';
}

/**
 * Capitalises each part of a person's name: "atanu ROY" -> "Atanu Roy", "dr. jean-luc o'neil" -> "Dr. Jean-Luc O'Neil".
 * Words already in mixed case (McDonald, DeVries) and short all-caps initials (J.R.) are kept as typed.
 */
function properName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    return preg_replace_callback("/[\p{L}\p{M}]+/u", function ($m) {
        $w = $m[0];
        $lower = mb_strtolower($w); $upper = mb_strtoupper($w);
        if ($w !== $lower && $w !== $upper && mb_substr($w, 0, 1) === mb_strtoupper(mb_substr($w, 0, 1))) return $w; // McDonald: trust it
        if ($w === $upper && mb_strlen($w) === 1) return $w;          // initial
        return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }, $name);
}

function renderTemplateHtml(array $template, array $data): string {
    if (isset($data['name'])) $data['name'] = properName((string)$data['name']);
    $bgUrl = SITE_URL . '/uploads/templates/' . rawurlencode($template['background_path']);
    $w = (float)$template['page_width_mm']; $h = (float)$template['page_height_mm'];
    $config = json_decode($template['config'] ?? '[]', true) ?: [];
    $cssFonts = ['Helvetica' => 'Helvetica,Arial,sans-serif', 'Times' => '"Times New Roman",Times,serif', 'Courier' => '"Courier New",Courier,monospace',
                 'Montserrat' => 'RarlMontserrat,Montserrat,sans-serif', 'Playfair' => 'RarlPlayfair,Georgia,serif', 'GreatVibes' => 'RarlScript,cursive'];
    // 1mm expressed in container-width units, so sizes match the PDF at any width.
    $mm = 100 / $w;

    $html = '<style>' . templateFontFaceCss() . '</style><div style="position:relative;width:100%;max-width:900px;aspect-ratio:' . $w . '/' . $h . ';container-type:inline-size;background:#fff;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.15);">';
    $html .= '<img src="' . htmlspecialchars($bgUrl) . '" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill;" />';

    foreach ($config as $f) {
        $key = $f['key'] ?? '';
        $x = (float)($f['x'] ?? 0); $y = (float)($f['y'] ?? 0);
        if (in_array($key, TEMPLATE_IMAGE_KEYS, true)) {
            $imgW = (float)($f['w'] ?? 15); $imgH = (float)($f['h'] ?? 15);
            $placeholder = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="#eee"/></svg>');
            $src = match (true) {
                $key === 'qr' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="#eee"/><text x="30" y="34" font-size="8" text-anchor="middle" fill="#999">QR</text></svg>'),
                $key === 'signature' => !empty($f['image']) ? SITE_URL . '/uploads/templates/' . rawurlencode($f['image']) : $placeholder,
                default => !empty($data['avatar_url']) ? $data['avatar_url'] : $placeholder,
            };
            $html .= sprintf(
                '<img src="%s" alt="" style="position:absolute;left:%s%%;top:%s%%;width:%s%%;height:%s%%;object-fit:%s;" />',
                htmlspecialchars($src), $x, $y, ($imgW / $w * 100), ($imgH / $h * 100), $key === 'avatar' ? 'cover' : 'fill'
            );
            continue;
        }
        $fontSize = (float)($f['font_size'] ?? 12);
        $sizeMm   = $fontSize * 25.4 / 72;
        $color    = $f['color'] ?? '#111111';
        $align    = $f['align'] ?? 'left';
        $style    = (!empty($f['bold']) ? 'font-weight:bold;' : '') . (!empty($f['italic']) ? 'font-style:italic;' : '');
        $transform = $align === 'center' ? 'translateX(-50%)' : ($align === 'right' ? 'translateX(-100%)' : 'none');
        $maxW = (float)($f['max_width'] ?? 0);
        $wrap = !empty($f['wrap']) && $maxW > 0;
        $html .= sprintf(
            '<div style="position:absolute;left:calc(%s%% + %scqw);top:%s%%;transform:%s;font-family:%s;font-size:%scqw;line-height:%s;color:%s;%s%s">%s</div>',
            $x, $mm, $y, $transform, $cssFonts[templateFontFamily($f)], $sizeMm * $mm, $wrap ? '1.3' : '1.134', htmlspecialchars($color), $style,
            $wrap ? 'white-space:normal;width:' . ($maxW * $mm) . 'cqw;text-align:' . $align . ';'
                  : 'white-space:nowrap;' . ($maxW > 0 ? 'max-width:' . ($maxW * $mm) . 'cqw;overflow:hidden;text-overflow:clip;' : ''),
            htmlspecialchars(templateFieldText($f, $data))
        );
    }
    $html .= '</div>';
    return $html;
}

// Crops an image to the target aspect ratio (centre "cover" crop) so member
// photos are never stretched on the card. Falls back to the original path if
// GD is unavailable or the image can't be read.
function templateCoverCrop(string $path, float $targetW, float $targetH, array &$tmpFiles): string {
    if (!function_exists('imagecreatefromstring') || $targetW <= 0 || $targetH <= 0) return $path;
    $raw = @file_get_contents($path);
    $src = $raw !== false ? @imagecreatefromstring($raw) : false;
    if (!$src) return $path;
    $sw = imagesx($src); $sh = imagesy($src);
    $targetRatio = $targetW / $targetH;
    if (abs($sw / $sh - $targetRatio) < 0.01) { imagedestroy($src); return $path; }
    if ($sw / $sh > $targetRatio) { $cw = (int)round($sh * $targetRatio); $ch = $sh; $cx = (int)(($sw - $cw) / 2); $cy = 0; }
    else { $cw = $sw; $ch = (int)round($sw / $targetRatio); $cx = 0; $cy = (int)(($sh - $ch) / 2); }
    $dst = imagecreatetruecolor($cw, $ch);
    imagecopy($dst, $src, 0, 0, $cx, $cy, $cw, $ch);
    $out = sys_get_temp_dir() . '/rarl_crop_' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($dst, $out, 92);
    imagedestroy($src); imagedestroy($dst);
    $tmpFiles[] = $out;
    return $out;
}

// Builds the PDF for a template. With $outPath = null the PDF is returned as a
// string instead of written to disk (used by the designer's live "Test PDF").
function renderTemplatePdf(array $template, array $data, ?string $outPath): bool|string {
    if (isset($data['name'])) $data['name'] = properName((string)$data['name']);
    if (!class_exists('FPDF') && file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) {
        require_once __DIR__ . '/libs/fpdf/fpdf.php';
    }
    if (!class_exists('FPDF')) return false;
    if (!class_exists('QRcode') && file_exists(__DIR__ . '/libs/phpqrcode/qrlib.php')) {
        require_once __DIR__ . '/libs/phpqrcode/qrlib.php';
    }

    $w = (float)$template['page_width_mm']; $h = (float)$template['page_height_mm'];
    $bgPath = UPLOADS_PATH . '/templates/' . $template['background_path'];
    $config = json_decode($template['config'] ?? '[]', true) ?: [];

    $pdf = new FPDF($w >= $h ? 'L' : 'P', 'mm', [$w, $h]);
    $pdf->SetAutoPageBreak(false); // fixed single-page template layout
    $pdf->AddPage();
    if (file_exists($bgPath)) {
        try { $pdf->Image($bgPath, 0, 0, $w, $h); } catch (Throwable $e) { error_log('Template background failed: ' . $e->getMessage()); }
    }

    $tmpFiles = [];
    foreach ($config as $f) {
        $key = $f['key'] ?? '';
        $x = (float)($f['x'] ?? 0) / 100 * $w; $y = (float)($f['y'] ?? 0) / 100 * $h;
        if ($key === 'qr') {
            $imgW = (float)($f['w'] ?? 15); $imgH = (float)($f['h'] ?? 15);
            if (class_exists('QRcode') && !empty($data['verify_url'])) {
                $qrPath = sys_get_temp_dir() . '/rarl_tplqr_' . substr(md5($data['verify_url'] . microtime()), 0, 12) . '.png';
                QRcode::png($data['verify_url'], $qrPath, QR_ECLEVEL_L, 4, 1);
                $tmpFiles[] = $qrPath;
                if (file_exists($qrPath)) $pdf->Image($qrPath, $x, $y, $imgW, $imgH, 'PNG');
            }
            continue;
        }
        if ($key === 'avatar') {
            $imgW = (float)($f['w'] ?? 15); $imgH = (float)($f['h'] ?? 15);
            if (!empty($data['avatar_path']) && file_exists($data['avatar_path'])) {
                try { $pdf->Image(templateCoverCrop($data['avatar_path'], $imgW, $imgH, $tmpFiles), $x, $y, $imgW, $imgH); } catch (Throwable $e) {}
            }
            continue;
        }
        if ($key === 'signature') {
            $imgW = (float)($f['w'] ?? 20); $imgH = (float)($f['h'] ?? 12);
            $sigPath = !empty($f['image']) ? UPLOADS_PATH . '/templates/' . basename($f['image']) : null;
            if ($sigPath && file_exists($sigPath)) {
                try { $pdf->Image($sigPath, $x, $y, $imgW, $imgH); } catch (Throwable $e) {}
            }
            continue;
        }
        $fontSize = (float)($f['font_size'] ?? 12);
        $color = $f['color'] ?? '#111111';
        [$r, $g, $b] = sscanf(ltrim($color, '#'), '%02x%02x%02x') ?: [17, 17, 17];
        $align = $f['align'] ?? 'left';
        $style = (!empty($f['bold']) ? 'B' : '') . (!empty($f['italic']) ? 'I' : '');
        $family = templateFontFamily($f);
        $style = templatePdfFont($pdf, $family, $style);
        $pdf->SetFont($family, $style, $fontSize);
        $pdf->SetTextColor((int)$r, (int)$g, (int)$b);
        $text = fpdfEnc(templateFieldText($f, $data));
        $maxW = (float)($f['max_width'] ?? 0);
        // Wrapped paragraph (e.g. "for receiving the … award in …"): a text box max_width wide.
        if (!empty($f['wrap']) && $maxW > 0) {
            $bx = $align === 'center' ? $x - $maxW / 2 : ($align === 'right' ? $x - $maxW : $x);
            $pdf->SetXY($bx, $y);
            $pdf->MultiCell($maxW, $fontSize * 0.3528 * 1.3, $text, 0, ['left' => 'L', 'center' => 'C', 'right' => 'R'][$align] ?? 'L');
            continue;
        }
        // Shrink-to-fit: long names step the font down until they fit max_width.
        if ($maxW > 0) {
            while ($fontSize > 4 && $pdf->GetStringWidth($text) > $maxW) {
                $fontSize -= 0.5;
                $pdf->SetFont($family, $style, $fontSize);
            }
        }
        $textW = $pdf->GetStringWidth($text);
        $px = $align === 'center' ? $x - $textW / 2 : ($align === 'right' ? $x - $textW : $x);
        // Keep the text vertically anchored at the designed size even if it shrank.
        $origSize = (float)($f['font_size'] ?? 12);
        $py = $y + ($origSize - $fontSize) * 0.2;
        $pdf->SetXY($px, $py);
        $pdf->Cell($textW + 1, $fontSize * 0.4, $text, 0, 0);
    }

    if ($outPath === null) {
        $out = $pdf->Output('S');
        foreach ($tmpFiles as $t) @unlink($t);
        return $out;
    }
    $dir = dirname($outPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $pdf->Output('F', $outPath);
    foreach ($tmpFiles as $t) @unlink($t);
    return true;
}

// Placeholder values used by the template designer and sample previews, so a
// layout can be checked with realistic text before anything is issued for real.
function templateSampleData(string $type): array {
    $data = match ($type) {
        'id_card' => [
            'name' => 'Dr. Jane Sample', 'member_code' => 'RARL-000000', 'since_date' => date('Y/m/d'),
            'section' => 'Sample Chapter', 'signer1' => 'RARL President', 'signer2' => 'Chapter Chair',
            'verify_url' => SITE_URL . '/id-card-verify.php?code=SAMPLE',
        ],
        'membership' => [
            'name' => 'Dr. Jane Sample', 'member_code' => 'RARL-000000', 'since_date' => date('d F Y'),
            'section' => 'Sample Chapter', 'cert_no' => 'RARL-2026-SAMPLE', 'signer1' => 'RARL President',
            'verify_url' => CERT_VERIFY_URL . '?id=sample',
        ],
        default => [
            'name' => 'Dr. Jane Sample', 'event' => 'Sample Workshop 2026', 'date' => date('d F Y'),
            'cert_no' => 'RARL-2026-SAMPLE', 'verify_url' => CERT_VERIFY_URL . '?id=sample',
        ],
    };
    $photo = __DIR__ . '/assets/rarl-mark.svg';
    $data['avatar_url']  = BRAND_MARK_PATH;
    $data['avatar_path'] = file_exists($photo) ? $photo : null;
    return $data;
}

function getDefaultTemplate(string $type): ?array {
    $pdo = db();
    $s = $pdo->prepare("SELECT * FROM certificate_templates WHERE type = ? AND is_default = 1 LIMIT 1");
    $s->execute([$type]);
    return $s->fetch() ?: null;
}

// ── Minimal XLSX reader (no Composer/PhpSpreadsheet dependency, matching this
// repo's bundled-single-file-lib convention for FPDF/phpqrcode/Parsedown) ──
// Reads sheet1 of a .xlsx into an array of string rows, resolving shared strings.
// Good enough for simple header+data import sheets; not a general spreadsheet engine.
function parseXlsxToRows(string $path): array {
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = [];
    if (($sharedXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = new SimpleXMLElement($sharedXml);
        foreach ($sx->si as $si) {
            $shared[] = isset($si->t) ? (string)$si->t : implode('', array_map(fn($r) => (string)$r->t, $si->r ?? []));
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) return [];

    $sheet = new SimpleXMLElement($sheetXml);
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $colIdx = 0;
        foreach ($row->c as $c) {
            // Cell ref like "C7" tells us the actual column, in case of blank gaps
            $ref = (string)$c['r'];
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                $colIdx = 0;
                foreach (str_split($m[1]) as $ch) $colIdx = $colIdx * 26 + (ord($ch) - 64);
                $colIdx -= 1;
            }
            $type = (string)$c['t'];
            $val = isset($c->v) ? (string)$c->v : '';
            if ($type === 's') $val = $shared[(int)$val] ?? '';
            elseif ($type === 'inlineStr') $val = isset($c->is->t) ? (string)$c->is->t : '';
            $cells[$colIdx] = $val;
            $colIdx++;
        }
        $maxIdx = $cells ? max(array_keys($cells)) : -1;
        $ordered = [];
        for ($i = 0; $i <= $maxIdx; $i++) $ordered[] = $cells[$i] ?? '';
        $rows[] = $ordered;
    }
    return $rows;
}

// ── Chair account provisioning ───────────────────────────────
// Every regional chair needs member-portal login access. Called whenever a
// chair_email is saved on a regional_sections row (admin/sections.php); a no-op
// if that email already belongs to a member (primary or linked). Returns true
// if a new account was created.
function createChairAccountIfMissing(string $chairEmail, string $chairName): bool {
    $chairEmail = trim($chairEmail);
    if (!$chairEmail || !filter_var($chairEmail, FILTER_VALIDATE_EMAIL)) return false;
    $pdo = db();

    $exists = $pdo->prepare('SELECT m.id FROM members m LEFT JOIN member_emails me ON me.member_id = m.id WHERE m.email = ? OR me.email = ? LIMIT 1');
    $exists->execute([$chairEmail, $chairEmail]);
    if ($exists->fetch()) return false;

    $freePlanId = (int)$pdo->query("SELECT id FROM membership_plans WHERE slug = 'free' LIMIT 1")->fetchColumn() ?: null;
    $temp = bin2hex(random_bytes(5));
    $uuid = generateUuid();

    $pdo->prepare(
        'INSERT INTO members (uuid, type, email, password_hash, must_change_password, is_chair, status, full_name, plan_id, email_verified_at, unsubscribe_token)
         VALUES (?, "individual", ?, ?, 1, 1, "active", ?, ?, NOW(), ?)'
    )->execute([$uuid, $chairEmail, password_hash($temp, PASSWORD_BCRYPT), $chairName ?: $chairEmail, $freePlanId, bin2hex(random_bytes(32))]);
    $memberId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO member_emails (member_id, email, is_primary, verified_at) VALUES (?,?,1,NOW())')->execute([$memberId, $chairEmail]);

    $memberName = $chairName ?: $chairEmail; $tempPassword = $temp;
    ob_start(); require __DIR__ . '/emails/chair-account-created.php'; $body = ob_get_clean();
    sendEmail($chairEmail, $memberName, 'Your Robotics & Automation Research Lab (RARL) chair account', $body);
    return true;
}

// Generates (or regenerates) a member's ID card, assigning member_code if needed. Returns true on success.
function issueIdCard(int $memberId): bool {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
    if (!$m || empty($m['avatar_path'])) return false;

    if (empty($m['member_code'])) {
        $m['member_code'] = nextMemberCode();
    }

    $sectionName = ''; $chairName = '';
    if ($m['section_id']) {
        $s = $pdo->prepare('SELECT name, chair_name FROM regional_sections WHERE id = ?');
        $s->execute([$m['section_id']]);
        if ($row = $s->fetch()) { $sectionName = $row['name']; $chairName = $row['chair_name']; }
    }

    try {
        $template = getDefaultTemplate('id_card');
        if ($template) {
            $name = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
            $dir = UPLOADS_PATH . '/id-cards/';
            $pdfFile = 'idcard_' . $m['uuid'] . '.pdf';
            renderTemplatePdf($template, [
                'name' => $name, 'member_code' => '#' . $m['member_code'], 'section' => $sectionName ?: '—',
                'since_date' => date('Y/m/d', strtotime($m['created_at'])),
                'signer1' => setting('idcard_signer1_name', 'RARL President'),
                'signer2' => $chairName ?: setting('idcard_signer2_name', 'Chapter Chair'),
                'verify_url' => SITE_URL . '/id-card-verify.php?code=' . urlencode($m['member_code']),
                'avatar_path' => !empty($m['avatar_path']) ? UPLOADS_PATH . '/avatars/' . $m['avatar_path'] : null,
            ], $dir . $pdfFile);
        } else {
            $pdfFile = generateIdCardPDF($m, $sectionName, $chairName);
        }
    } catch (Throwable $e) {
        error_log('ID card generation failed for member ' . $memberId . ': ' . $e->getMessage());
        return false;
    }
    if (!$pdfFile) return false;

    $pdo->prepare('UPDATE members SET member_code=?, id_card_path=?, id_card_issued_at=CURDATE(), id_card_expires_at=DATE_ADD(CURDATE(), INTERVAL 3 YEAR) WHERE id=?')
        ->execute([$m['member_code'], $pdfFile, $memberId]);
    return true;
}

// ── Full database purge (admin "Danger Zone") ───────────────────────────────
// Wipes every operational table back to empty — members, community, events,
// certificates, resources, settings, plans, templates, sections, people,
// partnerships — plus their uploaded files. `admin_users` is untouched so the
// logged-in admin's own login always survives (auth.php actually authenticates
// against the ADMIN_USERNAME/ADMIN_PASSWORD_HASH env constants, not this table,
// but it's left alone regardless in case it's used for anything else).
// Caller (admin/settings.php) is responsible for the confirmation phrase +
// password re-check before ever calling this.
function purgeDatabase(): void {
    $pdo = db();
    $tables = [
        'community_likes', 'community_comments', 'community_posts',
        'event_registrations', 'events',
        'certificates', 'certificate_templates',
        'member_emails', 'otp_codes', 'members',
        'newsletters', 'resources', 'resource_categories',
        'announcements', 'partnership_tiers', 'membership_plans',
        'regional_sections', 'people', 'settings',
    ];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        try { $pdo->exec("TRUNCATE TABLE `{$t}`"); } catch (Throwable $e) { /* table may not exist on older installs */ }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // Clear uploaded files, keeping the directories and their .gitkeep/.htaccess placeholders
    $dirs = ['avatars', 'certificates', 'cv', 'id-cards', 'popups', 'roster', 'templates', 'people'];
    foreach ($dirs as $d) {
        $path = UPLOADS_PATH . '/' . $d;
        if (!is_dir($path)) continue;
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..' || $f === '.gitkeep' || $f === '.htaccess') continue;
            $full = $path . '/' . $f;
            if (is_file($full)) @unlink($full);
        }
    }
}

// ── Member deletion (cascades to every table/file that references the member) ──
// No FK constraints exist in the schema (see sql/*.sql), so deleting a member
// via a plain `DELETE FROM members` leaves orphaned rows in community_posts,
// community_comments, community_likes, event_registrations, member_emails,
// certificates, otp_codes — plus orphaned files in uploads/. This walks all of
// them before removing the member row itself.
function deleteMemberCascade(int $memberId): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
    if (!$m) return false;

    // Certificate PDFs + QR codes belonging to this member
    $certs = $pdo->prepare('SELECT pdf_path FROM certificates WHERE member_id = ?');
    $certs->execute([$memberId]);
    foreach ($certs->fetchAll() as $c) {
        if (!empty($c['pdf_path'])) @unlink(UPLOADS_PATH . '/certificates/' . $c['pdf_path']);
    }
    $pdo->prepare('DELETE FROM certificates WHERE member_id = ?')->execute([$memberId]);

    // Community: comments/likes on the member's own posts, then the posts themselves,
    // then the member's comments/likes on other members' posts.
    $postIds = $pdo->prepare('SELECT id FROM community_posts WHERE member_id = ?');
    $postIds->execute([$memberId]);
    $ownPostIds = $postIds->fetchAll(PDO::FETCH_COLUMN);
    if ($ownPostIds) {
        $in = implode(',', array_fill(0, count($ownPostIds), '?'));
        $pdo->prepare("DELETE FROM community_comments WHERE post_id IN ($in)")->execute($ownPostIds);
        $pdo->prepare("DELETE FROM community_likes WHERE post_id IN ($in)")->execute($ownPostIds);
    }
    $pdo->prepare('DELETE FROM community_posts WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('DELETE FROM community_comments WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('DELETE FROM community_likes WHERE member_id = ?')->execute([$memberId]);

    // Events, extra emails, password/verification codes
    $pdo->prepare('DELETE FROM event_registrations WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('DELETE FROM member_emails WHERE member_id = ?')->execute([$memberId]);
    $pdo->prepare('DELETE FROM otp_codes WHERE email = ?')->execute([$m['email']]);

    // Uploaded files owned by the member
    if (!empty($m['avatar_path']))  @unlink(UPLOADS_PATH . '/avatars/' . $m['avatar_path']);
    if (!empty($m['id_card_path'])) @unlink(UPLOADS_PATH . '/id-cards/' . $m['id_card_path']);
    if (!empty($m['cv_path']))      @unlink(UPLOADS_PATH . '/cv/' . $m['cv_path']);

    return $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$memberId]);
}

// ── Membership certificates ─────────────────────────────────
// Issued manually by default (Admin → Membership certificates); automatic issue
// on approval is a setting. Each certificate records a fingerprint of the
// design it was made with (design_sig), so when the default membership design
// changes the outdated ones can be regenerated — keeping their number and
// verification link.

function membershipCertAutoIssue(): bool { return setting('membership_cert_auto', '0') === '1'; }
function membershipCertAutoRegen(): bool { return setting('membership_cert_autoregen', '1') === '1'; }

/** Fingerprint of a design: template, layout, background and page size ('plain-v1' = built-in fallback). */
function membershipDesignSig(?array $tpl): string {
    if (!$tpl) return 'plain-v1';
    return substr(sha1($tpl['id'] . '|' . ($tpl['config'] ?? '') . '|' . ($tpl['background_path'] ?? '') . '|' . $tpl['page_width_mm'] . 'x' . $tpl['page_height_mm']), 0, 40);
}

/** Template data for one member's certificate. */
function membershipCertData(array $m, string $certNo, string $uuid): array {
    $pdo = db();
    $sectionName = '';
    if (!empty($m['section_id'])) {
        $s = $pdo->prepare('SELECT name FROM regional_sections WHERE id = ?');
        $s->execute([$m['section_id']]);
        $sectionName = $s->fetchColumn() ?: '';
    }
    return [
        'name' => $m['type'] === 'lab' ? ($m['lab_name'] ?: $m['full_name']) : $m['full_name'],
        'member_code' => $m['member_code'] ?: '', 'section' => $sectionName ?: '—',
        'since_date' => date('d F Y', strtotime($m['created_at'])), 'cert_no' => $certNo,
        'signer1' => setting('idcard_signer1_name', 'RARL President'),
        'verify_url' => CERT_VERIFY_URL . '?id=' . $uuid,
        '_section' => $sectionName,
    ];
}

/** Writes a new PDF for a member's certificate; returns the file name or null. */
function renderMembershipCertPdf(array $m, string $uuid, string $certNo, ?array $tpl): ?string {
    $certDir = UPLOADS_PATH . '/certificates/';
    if (!is_dir($certDir)) mkdir($certDir, 0755, true);
    // A fresh file name on every render, so browsers never show a cached old design.
    $pdfFile = 'membercert_' . str_replace('-', '', $uuid) . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.pdf';
    $data = membershipCertData($m, $certNo, $uuid);
    try {
        if ($tpl) renderTemplatePdf($tpl, $data, $certDir . $pdfFile);
        else generatePlainMembershipCertPDF($certDir . $pdfFile, $data['name'], $certNo, $m['created_at'], $data['_section'], $uuid);
    } catch (Throwable $e) {
        error_log('Membership certificate render failed for member ' . $m['id'] . ': ' . $e->getMessage());
        return null;
    }
    return is_file($certDir . $pdfFile) ? $pdfFile : null;
}

// Issues (once) a "Certificate of Membership" for a member with the default
// membership design. No-op if the member already has one. True if one now exists.
function issueMembershipCertificate(int $memberId): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
    if (!$m) return false;

    $dup = $pdo->prepare("SELECT id FROM certificates WHERE member_id = ? AND cert_type = 'membership'");
    $dup->execute([$memberId]);
    if ($dup->fetch()) return true; // already issued, not an error

    $uuid = generateUuid();
    $certNo = nextCertNumber();
    $tpl = getDefaultTemplate('membership');
    $pdfFile = renderMembershipCertPdf($m, $uuid, $certNo, $tpl);
    if (!$pdfFile) return false;
    $name = $m['type'] === 'lab' ? ($m['lab_name'] ?: $m['full_name']) : $m['full_name'];
    $pdo->prepare(
        'INSERT INTO certificates (uuid, certificate_no, member_id, cert_type, recipient_name, recipient_email, event_id, template_id, design_sig, pdf_path)
         VALUES (?,?,?,"membership",?,?,NULL,?,?,?)'
    )->execute([$uuid, $certNo, $memberId, properName((string)$name), $m['email'], $tpl['id'] ?? null, membershipDesignSig($tpl), $pdfFile]);
    return true;
}

/** Re-renders one membership certificate with $tpl (default design if null). Same number and link. */
function regenerateMembershipCertificate(array $c, ?array $tpl = null): bool {
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $s->execute([(int)$c['member_id']]);
    $m = $s->fetch();
    if (!$m) return false;
    $tpl ??= getDefaultTemplate('membership');
    $pdfFile = renderMembershipCertPdf($m, $c['uuid'], $c['certificate_no'], $tpl);
    if (!$pdfFile) return false;
    $name = $m['type'] === 'lab' ? ($m['lab_name'] ?: $m['full_name']) : $m['full_name'];
    $pdo->prepare('UPDATE certificates SET pdf_path = ?, template_id = ?, design_sig = ?, recipient_name = ?, recipient_email = ? WHERE id = ?')
        ->execute([$pdfFile, $tpl['id'] ?? null, membershipDesignSig($tpl), properName((string)$name), $m['email'], $c['id']]);
    if (!empty($c['pdf_path']) && $c['pdf_path'] !== $pdfFile) @unlink(UPLOADS_PATH . '/certificates/' . basename($c['pdf_path']));
    return true;
}

/** Counts for the admin page: active members, with/without a certificate, outdated. */
function membershipCertStats(): array {
    $pdo = db();
    $sig = membershipDesignSig(getDefaultTemplate('membership'));
    $active = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active' AND " . REAL_MEMBER_SQL)->fetchColumn();
    $issued = (int)$pdo->query("SELECT COUNT(*) FROM certificates WHERE cert_type = 'membership'")->fetchColumn();
    $missing = (int)$pdo->query("SELECT COUNT(*) FROM members m WHERE m.status = 'active' AND " . str_replace('email', 'm.email', REAL_MEMBER_SQL) . "
        AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.member_id = m.id AND c.cert_type = 'membership')")->fetchColumn();
    $o = $pdo->prepare("SELECT COUNT(*) FROM certificates WHERE cert_type = 'membership' AND member_id IS NOT NULL AND (design_sig IS NULL OR design_sig <> ?)");
    $o->execute([$sig]);
    return ['active' => $active, 'issued' => $issued, 'missing' => $missing, 'outdated' => (int)$o->fetchColumn(), 'sig' => $sig];
}

/** Regenerates up to $limit outdated membership certificates. Returns how many were done. */
function regenerateOutdatedMembershipCerts(int $limit = 10, int $maxSeconds = 20): int {
    $pdo = db();
    $tpl = getDefaultTemplate('membership');
    $sig = membershipDesignSig($tpl);
    $s = $pdo->prepare("SELECT * FROM certificates WHERE cert_type = 'membership' AND member_id IS NOT NULL AND (design_sig IS NULL OR design_sig <> ?) ORDER BY id LIMIT " . max(1, $limit));
    $s->execute([$sig]);
    $done = 0; $start = time();
    foreach ($s->fetchAll() as $c) {
        if (time() - $start > $maxSeconds) break;
        if (regenerateMembershipCertificate($c, $tpl)) $done++;
        else $pdo->prepare('UPDATE certificates SET design_sig = ? WHERE id = ?')->execute([$sig, $c['id']]); // member gone: don't retry forever
    }
    return $done;
}

/** Issues certificates to up to $limit active members who have none. Returns how many. */
function issueMissingMembershipCerts(int $limit = 10, int $maxSeconds = 20): int {
    $ids = db()->query("SELECT m.id FROM members m WHERE m.status = 'active' AND " . str_replace('email', 'm.email', REAL_MEMBER_SQL) . "
        AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.member_id = m.id AND c.cert_type = 'membership') ORDER BY m.id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    $done = 0; $start = time();
    foreach ($ids as $id) {
        if (time() - $start > $maxSeconds) break;
        if (issueMembershipCertificate((int)$id)) $done++;
    }
    return $done;
}

// Plain fallback "Certificate of Membership" — used whenever no admin
// template is set as default for type='membership'. Same FPDF conventions
// as generateCertPDF() (AutoPageBreak off, single fixed-position page).
function generatePlainMembershipCertPDF(string $path, string $name, string $certNo, string $memberSince, string $sectionName, string $uuid): void {
    if (!class_exists('FPDF') && file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) require_once __DIR__ . '/libs/fpdf/fpdf.php';
    if (!class_exists('FPDF')) return;
    if (!class_exists('QRcode') && file_exists(__DIR__ . '/libs/phpqrcode/qrlib.php')) require_once __DIR__ . '/libs/phpqrcode/qrlib.php';

    [$inkR, $inkG, $inkB] = brandRgb(BRAND_INK);
    [$redR, $redG, $redB] = brandRgb(BRAND_RED);

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, 297, 210, 'F');
    $pdf->SetDrawColor($redR, $redG, $redB);
    $pdf->SetLineWidth(1.5);
    $pdf->Rect(10, 10, 277, 190);
    $pdf->SetDrawColor($inkR, $inkG, $inkB);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(13, 13, 271, 184);

    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor($redR, $redG, $redB);
    $pdf->SetY(32); $pdf->Cell(0, 0, 'RARL COMMUNITY', 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetY(42); $pdf->Cell(0, 0, 'Robotics & Automation Research Lab', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 24);
    $pdf->SetTextColor($inkR, $inkG, $inkB);
    $pdf->SetY(60); $pdf->Cell(0, 0, 'Certificate of Membership', 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetY(85); $pdf->Cell(0, 0, 'This is to certify that', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 26);
    [$blueR, $blueG, $blueB] = brandRgb(BRAND_BLUE);
    $pdf->SetTextColor($blueR, $blueG, $blueB);
    $pdf->SetY(95); $pdf->Cell(0, 0, fpdfEnc($name), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(120, 120, 120);
    $sectionLine = $sectionName ? " and is a recognized member of the {$sectionName} chapter" : '';
    $pdf->SetY(115); $pdf->Cell(0, 0, fpdfEnc("is a certified member of the Robotics & Automation Research Lab (RARL){$sectionLine}."), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetY(140); $pdf->Cell(0, 0, 'Member since ' . date('d F Y', strtotime($memberSince)) . '   |   Certificate ID: ' . $certNo, 0, 1, 'C');
    $pdf->SetY(150); $pdf->Cell(0, 0, 'Verify at: ' . CERT_VERIFY_URL . '?id=' . $uuid, 0, 1, 'C');

    $qrPath = null;
    if (class_exists('QRcode')) {
        $qrPath = sys_get_temp_dir() . '/rarl_memcertqr_' . str_replace('-', '', $uuid) . '.png';
        QRcode::png(CERT_VERIFY_URL . '?id=' . $uuid, $qrPath, QR_ECLEVEL_L, 4, 2);
    }
    if ($qrPath && file_exists($qrPath)) { $pdf->Image($qrPath, 133.5, 158, 30, 30, 'PNG'); @unlink($qrPath); }

    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(100, 194, 197, 194);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetY(197); $pdf->Cell(0, 0, setting('idcard_signer1_name', 'RARL President') . ' — ' . SITE_NAME, 0, 1, 'C');
    $pdf->Output('F', $path);
}

// Site-wide urgency banner for the free-membership window (Settings → Free
// Membership Growth → deadline). Empty string if no deadline is set or it's
// already passed — callers can safely echo the result unconditionally.
function freeMembershipBannerHtml(): string {
    $deadline = setting('free_membership_deadline', '');
    if ($deadline === '') return '';
    $daysLeft = (int) ceil((strtotime($deadline . ' 23:59:59') - time()) / 86400);
    if ($daysLeft < 0) return '';
    $daysLeft = max(0, $daysLeft);
    $urgent = $daysLeft <= 14;
    $bg = $urgent ? 'background:linear-gradient(90deg,#CC0703,#A80502);' : 'background:linear-gradient(90deg,#101010,#1a1a1a);';
    $msg = $daysLeft === 0
        ? 'Last day — free membership ends tonight.'
        : ($daysLeft === 1
            ? 'Free membership ends tomorrow — join now.'
            : "Free membership ends in {$daysLeft} days — join now, it's free.");
    return '<div style="' . $bg . '" class="text-white text-center text-xs font-semibold py-2 px-4">'
         . '<i class="fa-solid fa-clock"></i> ' . htmlspecialchars($msg)
         . ' <a href="' . (isset($_SESSION['member_id']) ? '#' : 'register.php') . '" class="underline ml-1' . (isset($_SESSION['member_id']) ? ' hidden' : '') . '">Register free →</a>'
         . '</div>';
}

// Master kill switch for the entire membership system (Settings → General) —
// distinct from registrationsOpen(), which only pauses *new* signups. When
// this is off, login/register/dashboard/community/profile/roster all show a
// "Membership Paused" notice; the public marketing pages (home, events,
// resources, people, partners) and certificate/ID verification stay up since
// those are informational/backward-looking, not part of the membership
// program itself.
function membershipEnabled(): bool {
    return setting('membership_enabled', '1') === '1';
}

// Full standalone page + exit — call at the top of any membership-gated page
// right after functions.php is required (before session-dependent redirects).
function renderMembershipPausedPageAndExit(string $title = 'Membership'): never {
    echo htmlHead($title);
    echo publicNav();
    echo membershipPausedHtml();
    echo publicFooter();
    echo '</body></html>';
    exit;
}

function membershipPausedHtml(): string {
    $msg = setting('membership_paused_message', 'The membership program is temporarily unavailable. Please check back soon.');
    return '<section class="min-h-[calc(100vh-68px)] flex items-center justify-center py-16 px-4" style="background:linear-gradient(135deg,' . BRAND_INK . ' 0%,' . BRAND_INK_SOFT . ' 100%);">
      <div class="w-full max-w-md text-center">
        <div class="text-5xl mb-5"><i class="fa-solid fa-power-off text-white/40"></i></div>
        <h1 class="font-heading font-black text-2xl md:text-3xl text-white mb-3">Membership Paused</h1>
        <p class="text-white/55 text-sm mb-8">' . htmlspecialchars($msg) . '</p>
        <a href="index.php" class="inline-flex items-center gap-2 px-7 py-3 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition text-sm">← Back to Home</a>
      </div>
    </section>';
}

// Site-wide registration on/off switch (Settings → General). Checked by
// register.php and both register-individual.php/register-lab.php (deep
// links to the sub-forms are gated too, not just the chooser page).
function registrationsOpen(): bool {
    return setting('registrations_open', '1') === '1';
}

// Shared "registrations are closed" block for the two sub-forms.
function closedRegistrationHtml(): string {
    $msg = setting('registrations_closed_message', 'We are not accepting new member registrations right now. Please check back soon.');
    return '<section class="min-h-[calc(100vh-68px)] flex items-center justify-center py-16 px-4" style="background:linear-gradient(135deg,' . BRAND_INK . ' 0%,' . BRAND_INK_SOFT . ' 100%);">
      <div class="w-full max-w-md text-center">
        <div class="text-5xl mb-5"><i class="fa-solid fa-lock text-white/40"></i></div>
        <h1 class="font-heading font-black text-2xl md:text-3xl text-white mb-3">Registrations Are Closed</h1>
        <p class="text-white/55 text-sm mb-8">' . htmlspecialchars($msg) . '</p>
        <a href="index.php" class="inline-flex items-center gap-2 px-7 py-3 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition text-sm">← Back to Home</a>
      </div>
    </section>';
}

// True if $email's domain is on the admin-configured auto-approve allowlist
// (Settings → Free Membership Growth). Lets a trusted partner institution's
// members skip the manual-review queue entirely during the free-year push.
function emailDomainAutoApproved(string $email): bool {
    $domainsRaw = setting('auto_approve_domains', '');
    if (trim($domainsRaw) === '') return false;
    $emailDomain = strtolower(trim(substr(strrchr($email, '@'), 1)));
    if (!$emailDomain) return false;
    foreach (explode(',', $domainsRaw) as $d) {
        if (strtolower(trim($d)) === $emailDomain) return true;
    }
    return false;
}

// Runs the same "just got approved" side effects admin/members.php triggers on
// manual activation — welcome email, ID card, membership certificate — used
// both by that admin action and by auto-approval at registration.
function completeApproval(int $memberId): void {
    $pdo = db();
    $m = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $m->execute([$memberId]);
    $member = $m->fetch();
    if (!$member) return;
    if (!$member['discord_invited']) {
        $memberName = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
        $isApproval = false;
        ob_start(); require __DIR__ . '/emails/welcome.php'; $body = ob_get_clean();
        sendEmail($member['email'], $memberName, 'Your RARL Membership is Approved!', $body);
        $pdo->prepare('UPDATE members SET discord_invited = 1 WHERE id = ?')->execute([$memberId]);
    }
    issueIdCard($memberId);
    if (membershipCertAutoIssue()) issueMembershipCertificate($memberId);
}

// ── Language menu (Google Translate) ───────────────────────
// Same approach as ULAB MMS: the choice is Google's own `googtrans` cookie,
// and Google's widget script loads only once someone has picked a language
// other than English, so English pages make no request to Google. Languages
// follow where RARL's members are; each is named in its own script.
const RARL_LANGUAGES = [
    'en' => 'English',          'ar' => 'العربية',   'bn' => 'বাংলা',          'zh-CN' => '中文',
    'fr' => 'Français',         'de' => 'Deutsch',  'hi' => 'हिन्दी',          'id' => 'Bahasa Indonesia',
    'ja' => '日本語',            'ko' => '한국어',     'ms' => 'Bahasa Melayu',  'ne' => 'नेपाली',
    'fa' => 'فارسی',            'pt' => 'Português', 'es' => 'Español',       'tr' => 'Türkçe',
    'ur' => 'اردو',             'vi' => 'Tiếng Việt',
];
const RARL_RTL_LANGUAGES = ['ar', 'fa', 'ur'];

// Runs in <head>: sets lang/dir before first paint, then (only when needed)
// loads Google's widget, hides its banner/tooltips and keeps our fonts.
function languageHeadJs(): string {
    $codes = json_encode(array_keys(RARL_LANGUAGES));
    $rtl   = json_encode(RARL_RTL_LANGUAGES);
    return <<<HTML
<script>
(function () {
  var CODES = {$codes}, RTL = {$rtl};
  function current() { var m = document.cookie.match(/(?:^|;\s*)googtrans=([^;]*)/); var l = m ? decodeURIComponent(m[1]).split('/').pop() : 'en'; return CODES.indexOf(l) > -1 ? l : 'en'; }
  var lang = current(), root = document.documentElement;
  window.rarlLang = lang;
  window.rarlSetLang = function (l) {
    var host = location.hostname, parts = host.split('.'), domains = ['', host];
    if (parts.length > 1) domains.push('.' + parts.slice(-2).join('.'));
    domains.forEach(function (d) {
      var dom = d ? '; domain=' + d : '';
      document.cookie = l === 'en' ? 'googtrans=; path=/' + dom + '; expires=Thu, 01 Jan 1970 00:00:00 GMT'
                                   : 'googtrans=/en/' + l + '; path=/' + dom + '; max-age=31536000; SameSite=Lax';
    });
    location.reload();
  };
  if (lang === 'en') return;
  root.setAttribute('lang', lang);
  if (RTL.indexOf(lang) > -1) root.setAttribute('dir', 'rtl');
  var css = document.createElement('style');
  css.textContent = '.goog-te-banner-frame,iframe.skiptranslate,body>.skiptranslate,#goog-gt-tt,.goog-te-balloon-frame,.goog-tooltip,.VIpgJd-ZVi9od-ORHb-OEVmcd,.VIpgJd-ZVi9od-aZ2wEe-wOHMyf,.VIpgJd-yAWNEb-L7lbkb,.VIpgJd-yAWNEb-hvhgNd{display:none!important}'
    + 'body{top:0!important;position:static!important}'
    + 'html.translated-ltr .rarl-keep,html.translated-rtl .rarl-keep{margin-inline:.25em}'
    + '.goog-text-highlight,.VIpgJd-yAWNEb-VIpgJd-fmcmS-sn54Q,font{background:none!important;background-color:transparent!important;box-shadow:none!important}';
  document.head.appendChild(css);
  window.googleTranslateElementInit = function () {
    if (window.google && google.translate) new google.translate.TranslateElement({pageLanguage: 'en', includedLanguages: CODES.join(','), autoDisplay: false}, 'google_translate_element');
  };
  document.addEventListener('DOMContentLoaded', function () {
    // Keep the RARL name intact: Google otherwise "expands" the acronym.
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT), hits = [];
    for (var n = walker.nextNode(); n; n = walker.nextNode()) if (/\bRARL\b/.test(n.nodeValue) && !n.parentNode.closest('script,style,textarea,.notranslate')) hits.push(n);
    hits.forEach(function (t) {
      var frag = document.createDocumentFragment();
      t.nodeValue.split(/(\bRARL\b)/).forEach(function (part) {
        if (part === 'RARL') { var sp = document.createElement('span'); sp.className = 'notranslate rarl-keep'; sp.setAttribute('translate', 'no'); sp.textContent = part; frag.appendChild(sp); }
        else if (part) frag.appendChild(document.createTextNode(part));
      });
      t.parentNode.replaceChild(frag, t);
    });
    var host = document.createElement('div'); host.id = 'google_translate_element'; host.hidden = true; document.body.appendChild(host);
    var s = document.createElement('script'); s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'; s.async = true;
    document.body.appendChild(s);
  });
})();
</script>
HTML;
}

// The globe button + menu in the site header.
function languageMenuHtml(): string {
    $items = '';
    foreach (RARL_LANGUAGES as $code => $name) {
        $items .= '<li><button type="button" role="menuitemradio" data-lang="' . $code . '" lang="' . $code . '"'
                . ' class="w-full flex items-center justify-between gap-3 px-4 py-2 text-sm text-start text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 focus-visible:bg-gray-50 dark:focus-visible:bg-gray-800">'
                . '<span>' . $name . '</span><i class="fa-solid fa-check text-rarl-red text-xs" hidden></i></button></li>';
    }
    return <<<HTML
<div class="relative notranslate" translate="no">
  <button type="button" id="lang-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="lang-menu" title="Language" aria-label="Choose language"
    class="h-10 px-2.5 flex items-center gap-1.5 rounded-full text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white">
    <i class="fa-solid fa-language text-[17px]"></i><span id="lang-code" class="hidden lg:inline text-xs font-semibold uppercase">EN</span>
  </button>
  <div id="lang-menu" role="menu" aria-label="Language" hidden class="absolute end-0 top-[calc(100%+6px)] w-60 max-h-[70vh] overflow-y-auto bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl z-50 py-1.5">
    <p class="px-4 pt-1.5 pb-2 text-[11px] text-gray-500 leading-snug">Machine translation by Google. The English text is the official version.</p>
    <ul>{$items}</ul>
  </div>
</div>
<script>
(function () {
  var b = document.getElementById('lang-btn'), m = document.getElementById('lang-menu'), cur = window.rarlLang || 'en';
  document.getElementById('lang-code').textContent = cur.split('-')[0];
  m.querySelectorAll('[data-lang]').forEach(function (el) {
    var on = el.dataset.lang === cur; el.setAttribute('aria-checked', on ? 'true' : 'false'); el.querySelector('i').hidden = !on;
    el.addEventListener('click', function () { if (el.dataset.lang !== cur) window.rarlSetLang(el.dataset.lang); else set(false); });
  });
  function items() { return Array.prototype.slice.call(m.querySelectorAll('[data-lang]')); }
  function set(open) { m.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open) (m.querySelector('[aria-checked=true]') || items()[0]).focus(); }
  b.addEventListener('click', function (e) { e.stopPropagation(); set(m.hidden); });
  document.addEventListener('click', function (e) { if (!m.hidden && !m.contains(e.target)) set(false); });
  m.addEventListener('keydown', function (e) {
    var list = items(), i = list.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length].focus(); }
    else if (e.key === 'Home') { e.preventDefault(); list[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); list[list.length - 1].focus(); }
    else if (e.key === 'Escape' || e.key === 'Tab') { set(false); b.focus(); }
  });
})();
</script>
HTML;
}

// ── Shared nav ─────────────────────────────────────────────
// Signed-in members get a portal bar (icon tabs + "Me" menu, like LinkedIn,
// with an IEEE-style "Membership" area); visitors get the public nav. One
// function, so every page picks the right chrome with no per-page changes.
// The portal promotes RARL itself: tabs lead to what RARL offers, never to
// other members.
function memberNavItems(bool $isMember): array {
    // 4th item: shown in the phone tab bar (max 5); the rest appear from large screens up.
    return $isMember ? [
        'dashboard'    => ['dashboard.php',    'fa-house',          'Home',       true],
        'community'    => ['community.php',    'fa-newspaper',      'Updates',    true],
        'events'       => ['events.php',       'fa-calendar-days',  'Events',     true],
        'videos'       => ['videos.php',       'fa-circle-play',    'Videos',     false],
        'resources'    => ['resources.php',    'fa-graduation-cap', 'Learning',   true],
        'publications' => ['publications.php', 'fa-file-lines',     'Research',   false],
        'membership'   => ['membership.php',   'fa-id-card',        'Membership', true],
    ] : [
        'home'         => ['index.php',        'fa-house',          'Home',       true],
        'events'       => ['events.php',       'fa-calendar-days',  'Events',     true],
        'videos'       => ['videos.php',       'fa-circle-play',    'Videos',     true],
        'resources'    => ['resources.php',    'fa-graduation-cap', 'Learning',   true],
        'publications' => ['publications.php', 'fa-file-lines',     'Research',   true],
        'community'    => ['community.php',    'fa-newspaper',      'Updates',    false],
        'directory'    => ['directory.php',    'fa-earth-americas', 'Roster',     false],
    ];
}

function publicNav(string $active = ''): string {
    $isLoggedIn = isset($_SESSION['member_id']);
    $me = null;
    if ($isLoggedIn) {
        $ms = db()->prepare('SELECT full_name, lab_name, type, avatar_path, is_chair, status, id_card_path, institution, lab_website, member_code FROM members WHERE id = ?');
        $ms->execute([(int)$_SESSION['member_id']]);
        $me = $ms->fetch() ?: null;
        if (!$me) $isLoggedIn = false;
    }
    // Pages that sit under another tab light that tab up.
    $active = ['profile' => 'membership'][$active] ?? $active;
    $items = memberNavItems($isLoggedIn);
    $homeHref = $items[array_key_first($items)][0];

    $nav = ''; $tabBar = ''; $mobileNav = '';
    foreach ($items as $key => [$href, $icon, $label, $inTabBar]) {
        $on  = $key === $active;
        $cur = $on ? ' aria-current="page"' : '';
        $nav .= '<a href="' . $href . '"' . $cur . ' class="relative ' . ($inTabBar ? 'flex' : 'hidden lg:flex') . ' flex-col items-center justify-center gap-1 px-2.5 xl:px-4 h-16 ' . ($on ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white') . '">'
              . '<i class="fa-solid ' . $icon . ' text-[17px] leading-none"></i><span class="text-[11px] font-medium leading-none">' . $label . '</span>'
              . ($on ? '<span class="absolute bottom-0 inset-x-2 h-0.5 rounded-full bg-rarl-red"></span>' : '') . '</a>';
        if ($inTabBar) $tabBar .= '<a href="' . $href . '"' . $cur . ' class="flex-1 flex flex-col items-center justify-center gap-1 py-2 ' . ($on ? 'text-rarl-red' : 'text-gray-500 dark:text-gray-400') . '"><i class="fa-solid ' . $icon . ' text-lg leading-none"></i><span class="text-[10px] font-medium leading-none">' . $label . '</span></a>';
        $mobileNav .= '<a href="' . $href . '" class="flex items-center gap-3 px-4 py-3 text-sm border-b border-gray-100 dark:border-gray-800 ' . ($on ? 'text-rarl-red font-semibold' : 'text-gray-700 dark:text-gray-300') . '"><i class="fa-solid ' . $icon . ' w-5 text-center"></i>' . $label . '</a>';
    }

    $mainUrl  = MAIN_SITE_URL;
    $mark     = BRAND_MARK_PATH;
    $searchQ  = htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES);
    $langMenu = languageMenuHtml();
    $themeBtn = '<button type="button" data-theme-toggle class="hidden sm:flex w-10 h-10 items-center justify-center rounded-full text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Switch light/dark theme" title="Switch light/dark theme"><i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:inline"></i></button>';
    $menuItem = 'flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 [&>i]:w-4 [&>i]:text-center [&>i]:text-gray-400';

    if ($isLoggedIn) {
        $myName   = htmlspecialchars($me['type'] === 'lab' ? (string)$me['lab_name'] : (string)$me['full_name']);
        $avatarS  = memberAvatarHtml($me['avatar_path'], $myName ?: '?', 'w-7 h-7 text-xs', memberNeedsAttention($me));
        $avatarL  = memberAvatarHtml($me['avatar_path'], $myName ?: '?', 'w-12 h-12 text-lg');
        $headline = htmlspecialchars($me['type'] === 'lab' ? 'Research lab member' : ((string)$me['institution'] ?: 'RARL member'));
        $code     = $me['member_code'] ? '<span class="font-mono">#' . htmlspecialchars($me['member_code']) . '</span> · ' : '';
        $status   = ['active' => '<span class="text-green-700 dark:text-green-400">Active</span>', 'pending' => '<span class="text-amber-700 dark:text-amber-400">Pending review</span>', 'inactive' => '<span class="text-gray-500">Inactive</span>'][$me['status']] ?? '';
        $chair    = !empty($me['is_chair']) ? '<a href="chapter.php" role="menuitem" class="' . $menuItem . '"><i class="fa-solid fa-compass"></i> Chapter portal</a>' : '';
        $right = <<<HTML
<div class="relative">
  <button type="button" id="me-menu-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="me-menu" class="flex flex-col items-center justify-center gap-1 px-2 lg:px-3 h-16 text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
    {$avatarS}<span class="hidden md:flex text-[11px] font-medium leading-none items-center gap-1">Me <i class="fa-solid fa-caret-down text-[10px]"></i></span>
  </button>
  <div id="me-menu" role="menu" hidden class="absolute right-0 top-[calc(100%-4px)] w-72 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl overflow-hidden z-50">
    <div class="p-4 flex items-center gap-3">{$avatarL}<div class="min-w-0"><p class="font-semibold text-gray-900 dark:text-white truncate">{$myName}</p><p class="text-xs text-gray-500 truncate">{$headline}</p><p class="text-xs text-gray-500 mt-0.5">{$code}{$status}</p></div></div>
    <div class="px-4 pb-3"><a href="profile.php" class="block text-center text-sm font-semibold text-rarl-red border border-rarl-red/60 rounded-full py-1.5 hover:bg-rarl-red/5">View &amp; edit profile</a></div>
    <div class="border-t border-gray-100 dark:border-gray-800 py-1.5">
      <p class="px-4 py-1 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Membership</p>
      <a href="membership.php" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-id-card"></i> Membership &amp; ID card</a>
      <a href="membership.php#certificates" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-award"></i> Certificates</a>
      {$chair}
    </div>
    <div class="border-t border-gray-100 dark:border-gray-800 py-1.5">
      <p class="px-4 py-1 text-[11px] font-semibold uppercase tracking-wider text-gray-500">About RARL</p>
      <a href="videos.php" role="menuitem" class="{$menuItem} lg:hidden"><i class="fa-solid fa-circle-play"></i> Videos</a>
      <a href="publications.php" role="menuitem" class="{$menuItem} lg:hidden"><i class="fa-solid fa-file-lines"></i> Research &amp; your papers</a>
      <a href="people.php" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-people-group"></i> Leadership &amp; chapters</a>
      <a href="partners.php" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-handshake"></i> Partner with us</a>
      <a href="{$mainUrl}" target="_blank" rel="noopener" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-arrow-up-right-from-square"></i> rarl-lab.com</a>
    </div>
    <div class="border-t border-gray-100 dark:border-gray-800 py-1.5">
      <a href="profile.php#account" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-gear"></i> Settings &amp; privacy</a>
      <button type="button" role="menuitem" data-install data-install-go hidden class="{$menuItem} w-full text-start"><i class="fa-solid fa-mobile-screen-button"></i> Install the RARL app</button>
      <button type="button" role="menuitem" data-theme-toggle class="{$menuItem} w-full text-start"><i class="fa-solid fa-circle-half-stroke"></i> <span class="dark:hidden">Dark mode</span><span class="hidden dark:inline">Light mode</span></button>
      <a href="logout.php" role="menuitem" class="{$menuItem}"><i class="fa-solid fa-right-from-bracket"></i> Sign out</a>
    </div>
  </div>
</div>
HTML;
        $mobileExtra = '<a href="profile.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-user w-5 text-center"></i>Profile &amp; settings</a>'
                     . '<button type="button" data-install data-install-go hidden class="w-full flex items-center gap-3 px-4 py-3 text-sm text-start text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-mobile-screen-button w-5 text-center"></i>Install the RARL app</button>'
                     . '<a href="people.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-people-group w-5 text-center"></i>Leadership</a>'
                     . '<a href="logout.php" class="flex items-center gap-3 px-4 py-3 text-sm text-rarl-red"><i class="fa-solid fa-right-from-bracket w-5 text-center"></i>Sign out</a>';
    } else {
        $right = '<div class="flex items-center gap-2 pl-1">
            <a href="login.php" class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full">Sign in</a>
            <a href="register.php" class="inline-flex whitespace-nowrap px-4 py-2 text-sm font-semibold text-white bg-rarl-red hover:bg-rarl-dark rounded-full"><span class="sm:hidden">Join</span><span class="hidden sm:inline">Join RARL</span></a>
          </div>';
        $mobileExtra = '<a href="people.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-people-group w-5 text-center"></i>Leadership</a>'
                     . '<a href="partners.php" class="flex items-center gap-3 px-4 py-3 text-sm text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-handshake w-5 text-center"></i>Partner with us</a>'
                     . '<button type="button" data-theme-toggle class="w-full flex items-center gap-3 px-4 py-3 text-sm text-start text-gray-700 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-circle-half-stroke w-5 text-center"></i><span class="dark:hidden">Dark mode</span><span class="hidden dark:inline">Light mode</span></button>'
                     . '<a href="login.php" class="flex items-center gap-3 px-4 py-3 text-sm font-semibold text-rarl-red"><i class="fa-solid fa-right-to-bracket w-5 text-center"></i>Sign in</a>';
    }

    // The "free membership ends soon — join now" urgency banner is a sales
    // message; members already joined, so they never see it.
    $banner = $isLoggedIn ? '' : freeMembershipBannerHtml();
    $iosInstall = iosInstallModalHtml();
    $burgerCls = $isLoggedIn ? 'hidden' : 'md:hidden';
    $viewAs = viewAsBannerHtml();
    $tabBarHtml = $isLoggedIn
        ? '<nav class="md:hidden fixed bottom-0 inset-x-0 z-40 flex bg-white/95 dark:bg-gray-900/95 backdrop-blur border-t border-gray-200 dark:border-gray-800 pb-[env(safe-area-inset-bottom)]" aria-label="Primary">' . $tabBar . '</nav>'
          . '<style>@media(max-width:767px){body{padding-bottom:calc(64px + env(safe-area-inset-bottom))}}</style>'
        : '';

    return <<<HTML
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:px-4 focus:py-2 focus:bg-white focus:text-gray-900 focus:rounded-lg focus:shadow">Skip to content</a>
{$viewAs}{$banner}
<header class="sticky top-0 z-40 bg-white/90 dark:bg-gray-950/90 backdrop-blur-xl border-b border-gray-200/80 dark:border-gray-800">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center gap-3">
    <a href="{$homeHref}" class="flex items-center gap-2.5 flex-shrink-0" aria-label="RARL home">
      <img src="{$mark}" alt="" class="w-9 h-9 rounded-xl object-contain"/>
      <span class="hidden xl:block font-heading font-bold text-sm text-gray-900 dark:text-white leading-tight notranslate" translate="no">RARL<span class="block text-[11px] font-medium text-gray-500">Community</span></span>
    </a>
    <form action="search.php" method="GET" role="search" class="hidden sm:block flex-1 max-w-xs">
      <label class="relative block"><span class="sr-only">Search RARL</span>
        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
        <input type="search" name="q" value="{$searchQ}" placeholder="Search events, learning, updates…" class="w-full h-10 pl-10 pr-3 bg-gray-100 dark:bg-gray-800/80 border border-transparent focus:border-rarl-red/40 focus:bg-white dark:focus:bg-gray-900 rounded-full text-sm outline-none"/>
      </label>
    </form>
    <nav class="hidden md:flex items-stretch ml-auto" aria-label="Primary">{$nav}</nav>
    <div class="flex items-center ml-auto md:ml-2 md:pl-2 md:border-l border-gray-200 dark:border-gray-800">
      <a href="search.php" class="sm:hidden w-10 h-10 flex items-center justify-center rounded-full text-gray-500" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></a>
      {$langMenu}
      {$themeBtn}
      {$right}
      <button type="button" id="mobile-nav-btn" aria-expanded="false" aria-controls="mobile-nav-panel" class="{$burgerCls} w-10 h-10 flex items-center justify-center rounded-full text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
    </div>
  </div>
  <div id="mobile-nav-panel" hidden class="md:hidden bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 shadow-lg max-h-[70vh] overflow-y-auto">
    {$mobileNav}{$mobileExtra}
  </div>
</header>
{$tabBarHtml}
{$iosInstall}
<script>
(function(){
  function wire(btnId, panelId, isMenu){
    var b = document.getElementById(btnId), p = document.getElementById(panelId); if (!b || !p) return;
    function set(open){ p.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    b.addEventListener('click', function(e){ e.stopPropagation(); set(p.hidden); if (isMenu && !p.hidden) { var f = p.querySelector('a,button'); if (f) f.focus(); } });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !p.hidden) { set(false); b.focus(); } });
    if (isMenu) document.addEventListener('click', function(e){ if (!p.hidden && !p.contains(e.target)) set(false); });
  }
  wire('me-menu-btn', 'me-menu', true); wire('mobile-nav-btn', 'mobile-nav-panel', false);
})();
</script>
<span id="main" tabindex="-1"></span>
HTML;
}

// iOS has no install prompt: this guide shows the two taps instead.
function iosInstallModalHtml(): string {
    return <<<HTML
<dialog class="rarl-modal" id="ios-install" data-hash="off" aria-labelledby="ios-install-t">
  <div class="rarl-modal-panel" style="--modal-w:26rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="p-6 pt-8 text-center">
      <img src="assets/icons/apple-touch-icon.png" alt="" class="w-16 h-16 rounded-2xl mx-auto shadow"/>
      <h2 id="ios-install-t" class="font-heading font-bold text-xl text-gray-900 dark:text-white mt-4">Add RARL to your Home Screen</h2>
      <p class="text-sm text-gray-500 mt-1">Opens full screen, like an app, and keeps your ID card and events handy, even offline.</p>
      <ol class="text-start mt-5 space-y-3 text-[15px] text-gray-800 dark:text-gray-200">
        <li class="flex gap-3 items-center"><span class="w-7 h-7 rounded-full bg-rarl-red text-white text-sm font-bold flex items-center justify-center flex-shrink-0">1</span><span>Tap <strong>Share</strong> <i class="fa-solid fa-arrow-up-from-bracket text-[#007aff]"></i> in Safari's toolbar</span></li>
        <li class="flex gap-3 items-center"><span class="w-7 h-7 rounded-full bg-rarl-red text-white text-sm font-bold flex items-center justify-center flex-shrink-0">2</span><span>Choose <strong>Add to Home Screen</strong> <i class="fa-regular fa-square-plus"></i></span></li>
        <li class="flex gap-3 items-center"><span class="w-7 h-7 rounded-full bg-rarl-red text-white text-sm font-bold flex items-center justify-center flex-shrink-0">3</span><span>Tap <strong>Add</strong></span></li>
      </ol>
      <button type="button" data-modal-close class="mt-6 w-full h-11 rounded-full bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-semibold">Got it</button>
    </div>
  </div>
</dialog>
HTML;
}

// "View as member" — an admin sees the portal exactly as one member does
// (started from admin/member-edit.php via impersonate.php). It is read-only:
// while it is on, csrfCheck() refuses every member write, and it expires on
// its own after 30 minutes.
function viewAsActive(): bool {
    $v = $_SESSION['view_as'] ?? null;
    if (!$v) return false;
    if (($v['exp'] ?? 0) < time()) {
        unset($_SESSION['view_as'], $_SESSION['member_id'], $_SESSION['member_type'], $_SESSION['member_name']);
        return false;
    }
    return true;
}
function viewAsBannerHtml(): string {
    if (!viewAsActive()) return '';
    $name = htmlspecialchars($_SESSION['member_name'] ?? 'member');
    $mins = max(1, (int)ceil(($_SESSION['view_as']['exp'] - time()) / 60));
    return '<div class="bg-amber-400 text-amber-950 text-sm font-medium px-4 py-2 flex flex-wrap items-center justify-center gap-x-4 gap-y-1">'
         . '<span><i class="fa-solid fa-eye"></i> Viewing as <strong>' . $name . '</strong> · read-only · ends in ' . $mins . ' min</span>'
         . '<a href="impersonate.php?end=1" class="underline font-semibold">Exit to admin</a></div>';
}

// Chapter leaders — chapters are shown as a list of the people who lead
// them; members are not assigned to or asked to pick a chapter.
function chapterLeaders(): array {
    try {
        return db()->query("SELECT * FROM regional_sections WHERE is_published = 1 AND chair_name IS NOT NULL AND chair_name <> '' ORDER BY continent, display_order, name")->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ── "From RARL" feed ────────────────────────────────────────
// Everything RARL publishes, merged newest-first: announcements, team posts,
// events, learning resources and sent newsletters. Powers the member home
// and search. Each source is queried defensively so a table from a
// not-yet-run migration never takes the page down.
function announcementsHaveControls(): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)db()->query("SHOW COLUMNS FROM announcements LIKE 'expires_at'")->fetch(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}
function liveAnnouncementsSql(): string {
    return 'is_published = 1' . (announcementsHaveControls() ? ' AND (expires_at IS NULL OR expires_at >= CURDATE())' : '');
}
function rarlFeedItems(?int $sectionId, bool $isMember, int $limit = 20, string $q = ''): array {
    $pdo = db(); $items = [];
    $like = '%' . $q . '%';
    $try = function (callable $fn) { try { $fn(); } catch (Throwable $e) { /* source unavailable — skip */ } };

    $try(function () use ($pdo, &$items, $sectionId, $q, $like) {
        $sql = 'SELECT * FROM announcements WHERE ' . liveAnnouncementsSql() . ' AND (section_id IS NULL OR section_id <=> ?)' . ($q !== '' ? ' AND (title LIKE ? OR content LIKE ?)' : '') . ' ORDER BY created_at DESC LIMIT 30';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$sectionId, $like, $like] : [$sectionId]);
        foreach ($st as $a) $items[] = ['kind' => 'announcement', 'at' => $a['created_at'], 'pinned' => (bool)$a['is_pinned'], 'row' => $a];
    });
    if ($isMember) $try(function () use ($pdo, &$items, $q, $like) {
        $sql = 'SELECT p.*, (SELECT COUNT(*) FROM community_likes l WHERE l.post_id = p.id) AS like_count, (SELECT COUNT(*) FROM community_comments c WHERE c.post_id = p.id) AS comment_count FROM community_posts p WHERE p.is_hidden = 0' . ($q !== '' ? ' AND p.body LIKE ?' : '') . ' ORDER BY p.created_at DESC LIMIT 30';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$like] : []);
        foreach ($st as $p) $items[] = ['kind' => 'post', 'at' => $p['created_at'], 'pinned' => (bool)$p['is_pinned'], 'row' => $p];
    });
    $try(function () use ($pdo, &$items, $isMember, $q, $like) {
        $sql = "SELECT * FROM events WHERE is_active = 1" . ($isMember ? '' : " AND visibility = 'public'") . ($q !== '' ? ' AND (title LIKE ? OR description LIKE ? OR speaker_name LIKE ?)' : ' AND (event_date IS NULL OR event_date >= CURDATE() - INTERVAL 30 DAY)') . ' ORDER BY created_at DESC LIMIT 30';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$like, $like, $like] : []);
        foreach ($st as $e) $items[] = ['kind' => 'event', 'at' => $e['created_at'], 'pinned' => false, 'row' => $e];
    });
    $try(function () use ($pdo, &$items, $isMember, $q, $like) {
        $sql = "SELECT r.*, c.name AS category_name, c.slug AS category_slug FROM resources r JOIN resource_categories c ON c.id = r.category_id WHERE c.is_published = 1" . ($isMember ? '' : " AND r.access_level = 'public' AND c.access_level = 'public'") . ($q !== '' ? ' AND (r.title LIKE ? OR r.description LIKE ? OR c.name LIKE ?)' : '') . ' ORDER BY r.created_at DESC LIMIT 30';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$like, $like, $like] : []);
        foreach ($st as $r) $items[] = ['kind' => 'resource', 'at' => $r['created_at'], 'pinned' => false, 'row' => $r];
    });
    if ($isMember) $try(function () use ($pdo, &$items, $q, $like) {
        $sql = "SELECT id, subject, preview_text, sent_at FROM newsletters WHERE status = 'sent' AND sent_at IS NOT NULL" . ($q !== '' ? ' AND (subject LIKE ? OR preview_text LIKE ?)' : '') . ' ORDER BY sent_at DESC LIMIT 20';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$like, $like] : []);
        foreach ($st as $n) $items[] = ['kind' => 'newsletter', 'at' => $n['sent_at'], 'pinned' => false, 'row' => $n];
    });

    if (videosReady()) $try(function () use ($pdo, &$items, $isMember, $q, $like) {
        $sql = 'SELECT * FROM videos WHERE is_published = 1' . ($isMember ? '' : " AND access_level = 'public'") . ($q !== '' ? ' AND (title LIKE ? OR description LIKE ?)' : '') . ' ORDER BY created_at DESC LIMIT 20';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$like, $like] : []);
        foreach ($st as $v) $items[] = ['kind' => 'video', 'at' => $v['created_at'], 'pinned' => false, 'row' => $v];
    });
    if (publicationsReady()) $try(function () use ($pdo, &$items, $q, $like) {
        $sql = "SELECT p.*, m.full_name, m.lab_name, m.type AS mtype FROM publications p JOIN members m ON m.id = p.member_id WHERE p.status = 'approved'" . ($q !== '' ? ' AND (p.title LIKE ? OR p.authors LIKE ? OR p.venue LIKE ?)' : '') . ' ORDER BY p.approved_at DESC LIMIT 20';
        $st = $pdo->prepare($sql); $st->execute($q !== '' ? [$like, $like, $like] : []);
        foreach ($st as $p) $items[] = ['kind' => 'publication', 'at' => $p['approved_at'] ?: $p['created_at'], 'pinned' => false, 'row' => $p];
    });

    usort($items, fn($a, $b) => [$b['pinned'], strtotime((string)$b['at'])] <=> [$a['pinned'], strtotime((string)$a['at'])]);
    return array_slice($items, 0, $limit);
}

// One card per feed item. Posts reuse the community renderer elsewhere;
// here every kind gets the same compact, LinkedIn-style card.
function renderFeedItem(array $it): string {
    $r = $it['row']; $h = fn($s) => ex($s);
    $when = '<span title="' . $h(date('d M Y, g:i A', strtotime((string)$it['at']))) . '">' . timeAgo((string)$it['at']) . '</span>';
    $pin  = $it['pinned'] ? '<span class="text-amber-700 dark:text-amber-400"><i class="fa-solid fa-thumbtack"></i> Pinned</span> · ' : '';
    $head = function (string $icon, string $tint, string $label) use ($when, $pin) {
        return '<div class="flex items-center gap-3 mb-3"><span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ' . $tint . '"><i class="fa-solid ' . $icon . '"></i></span>'
             . '<div class="min-w-0 leading-tight"><p class="text-sm font-semibold text-gray-900 dark:text-white">RARL · ' . $label . '</p><p class="text-xs text-gray-500">' . $pin . $when . '</p></div></div>';
    };
    $card = 'rarl-feed-card bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-5';
    switch ($it['kind']) {
        case 'announcement':
            $tone = ['event' => 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300', 'opportunity' => 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300', 'alert' => 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-300'][$r['type']] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
            $label = ['event' => 'Event announcement', 'opportunity' => 'Opportunity', 'alert' => 'Important notice'][$r['type']] ?? 'Announcement';
            $cta = !empty($r['link_url']) ? '<a href="' . $h($r['link_url']) . '" target="_blank" rel="noopener" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-full bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold">' . $h($r['link_label'] ?: 'Learn more') . ' <i class="fa-solid fa-arrow-right text-xs"></i></a>' : '';
            $exp = !empty($r['expires_at']) ? '<p class="text-xs text-gray-500 mt-3"><i class="fa-regular fa-clock"></i> Until ' . $h(date('d M Y', strtotime($r['expires_at']))) . '</p>' : '';
            $body = $h($r['content']); $long = mb_strlen((string)$r['content']) > 320;
            return '<article class="' . $card . '">' . $head('fa-bullhorn', $tone, $label)
                 . '<h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug">' . $h($r['title']) . '</h3>'
                 . '<div class="mt-1.5 text-[15px] text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line' . ($long ? ' line-clamp-5' : '') . '" data-clamp>' . $body . '</div>'
                 . ($long ? '<button type="button" class="text-sm font-semibold text-gray-500 hover:text-rarl-red mt-1" onclick="this.previousElementSibling.classList.remove(\'line-clamp-5\');this.remove()">…see more</button>' : '')
                 . $cta . $exp . '</article>';
        case 'post':
            $img = !empty($r['image_path']) ? '<img src="' . UPLOADS_URL . '/community/' . $h($r['image_path']) . '" alt="" loading="lazy" class="mt-3 -mx-5 w-[calc(100%+2.5rem)] max-h-[480px] object-cover"/>' : '';
            $body = ($r['body_format'] ?? 'markdown') === 'html' ? sanitizeRichHtml((string)$r['body']) : markdownToHtml((string)$r['body']);
            return '<article class="' . $card . '">' . $head('fa-flask', 'bg-rarl-red/10 text-rarl-red', 'Team update')
                 . '<div class="md-content text-[15px] text-gray-800 dark:text-gray-200 leading-relaxed line-clamp-[8]">' . $body . '</div>' . $img
                 . '<div class="flex items-center gap-4 mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 text-xs text-gray-500"><span><i class="fa-solid fa-thumbs-up"></i> ' . (int)($r['like_count'] ?? 0) . '</span>' . (communityCommentsEnabled() ? '<span>' . (int)($r['comment_count'] ?? 0) . ' replies</span>' : '')
                 . '<a href="community.php#post-' . (int)$r['id'] . '" class="ml-auto font-semibold text-gray-600 dark:text-gray-300 hover:text-rarl-red">Open in Updates →</a></div></article>';
        case 'event':
            $date = $r['event_date'] ? date('D, d M Y', strtotime($r['event_date'])) . ($r['event_time'] ? ' · ' . date('g:i A', strtotime($r['event_time'])) : '') : 'Date to be announced';
            $past = $r['event_date'] && $r['event_date'] < date('Y-m-d');
            $cover = !empty($r['cover_image']) ? '<img src="' . $h(str_starts_with((string)$r['cover_image'], 'http') ? $r['cover_image'] : UPLOADS_URL . '/events/' . $r['cover_image']) . '" alt="" loading="lazy" class="mt-3 w-full aspect-[2/1] object-cover rounded-xl"/>' : '';
            return '<article class="' . $card . '">' . $head('fa-calendar-days', 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300', ($past ? 'Past ' : 'New ') . ucfirst((string)$r['type']))
                 . '<h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug">' . $h($r['title']) . '</h3>'
                 . '<p class="text-sm text-gray-600 dark:text-gray-400 mt-1"><i class="fa-regular fa-calendar"></i> ' . $h($date) . ($r['location'] ? ' · ' . $h($r['location']) : ($r['online_url'] ? ' · Online' : '')) . '</p>'
                 . ($r['speaker_name'] ? '<p class="text-sm text-gray-600 dark:text-gray-400"><i class="fa-solid fa-microphone"></i> ' . $h($r['speaker_name']) . '</p>' : '')
                 . ($r['description'] ? '<p class="text-[15px] text-gray-700 dark:text-gray-300 mt-2 line-clamp-3">' . $h(strip_tags((string)$r['description'])) . '</p>' : '')
                 . $cover
                 . '<a href="events.php#event-' . (int)$r['id'] . '" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold text-gray-800 dark:text-gray-200 hover:border-rarl-red hover:text-rarl-red">' . ($past && $r['recording_url'] ? 'Watch recording' : ($past ? 'View event' : 'Details &amp; RSVP')) . '</a></article>';
        case 'resource':
            $icon = ['youtube_playlist' => 'fa-brands fa-youtube', 'youtube_video' => 'fa-brands fa-youtube', 'article' => 'fa-solid fa-file-lines', 'book' => 'fa-solid fa-book', 'tool' => 'fa-solid fa-screwdriver-wrench', 'course' => 'fa-solid fa-graduation-cap'][$r['type']] ?? 'fa-solid fa-link';
            return '<article class="' . $card . '">' . $head('fa-graduation-cap', 'bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-300', 'New in Learning')
                 . '<a href="' . $h($r['url']) . '" target="_blank" rel="noopener" class="flex gap-4 p-3 -m-1 rounded-xl border border-gray-200 dark:border-gray-800 hover:border-rarl-red/40 group">'
                 . '<span class="w-12 h-12 rounded-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-xl text-gray-600 dark:text-gray-300 flex-shrink-0"><i class="' . $icon . '"></i></span>'
                 . '<span class="min-w-0"><span class="block font-semibold text-gray-900 dark:text-white group-hover:text-rarl-red">' . $h($r['title']) . '</span>'
                 . '<span class="block text-xs text-gray-500 mt-0.5">' . $h($r['category_name']) . ($r['access_level'] === 'member' ? ' · <i class="fa-solid fa-lock"></i> Members' : '') . '</span>'
                 . ($r['description'] ? '<span class="block text-sm text-gray-600 dark:text-gray-400 mt-1 line-clamp-2">' . $h($r['description']) . '</span>' : '') . '</span></a></article>';
        case 'video':
            return '<article class="' . $card . '">' . $head('fa-circle-play', 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-300', 'New video')
                 . '<a href="videos.php?v=' . (int)$r['id'] . '" class="group block -mx-5 -mb-5 mt-1"><span class="relative block aspect-video bg-black overflow-hidden"><img src="' . $h(youtubeThumb($r['youtube_id'])) . '" alt="" loading="lazy" class="w-full h-full object-cover group-hover:scale-[1.02] transition-transform"/>'
                 . '<span class="absolute inset-0 m-auto w-16 h-16 rounded-full bg-black/60 group-hover:bg-rarl-red text-white flex items-center justify-center text-2xl"><i class="fa-solid fa-play ps-1"></i></span></span>'
                 . '<span class="block px-5 py-4 font-heading font-bold text-gray-900 dark:text-white group-hover:text-rarl-red">' . $h($r['title']) . '</span></a></article>';
        case 'publication':
            $by = $r['mtype'] === 'lab' ? $r['lab_name'] : $r['full_name'];
            return '<article class="' . $card . '">' . $head('fa-file-lines', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', 'New paper by a member')
                 . '<h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug"><a href="' . $h($r['url']) . '" target="_blank" rel="noopener" class="hover:text-rarl-red">' . $h($r['title']) . '</a></h3>'
                 . '<p class="text-sm text-gray-600 dark:text-gray-400 mt-1">' . $h($by) . ($r['venue'] ? ' · ' . $h($r['venue']) : '') . ($r['year'] ? ' · ' . (int)$r['year'] : '') . '</p>'
                 . '<a href="publications.php?id=' . (int)$r['id'] . '" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold text-gray-800 dark:text-gray-200 hover:border-rarl-red hover:text-rarl-red">See all member research</a></article>';
        case 'newsletter':
            return '<article class="' . $card . '">' . $head('fa-envelope-open-text', 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300', 'Newsletter')
                 . '<h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug">' . $h($r['subject']) . '</h3>'
                 . ($r['preview_text'] ? '<p class="text-[15px] text-gray-700 dark:text-gray-300 mt-1">' . $h($r['preview_text']) . '</p>' : '')
                 . '<p class="text-xs text-gray-500 mt-3"><i class="fa-solid fa-inbox"></i> Sent to your inbox</p></article>';
    }
    return '';
}

// ── Shared footer ──────────────────────────────────────────
// ── Member onboarding flow UI (register → verify → review → welcome) ──────
// One stepper shared by every page of the journey so applicants always see
// where they are and what happens next.
function memberFlowSteps(int $current, bool $needsReview = true): string {
    $steps = [1 => ['fa-user-plus', 'Create account'], 2 => ['fa-envelope-circle-check', 'Verify email']];
    if ($needsReview) $steps[3] = ['fa-user-shield', 'Team review'];
    $steps[$needsReview ? 4 : 3] = ['fa-id-card', 'Welcome'];
    $html = '<ol class="flex items-center justify-center gap-1 sm:gap-2 mb-8 text-[11px] sm:text-xs" aria-label="Progress">';
    $n = count($steps); $i = 0;
    foreach ($steps as $k => [$icon, $label]) {
        $i++;
        $state = $k < $current ? 'done' : ($k === $current ? 'now' : 'todo');
        $dot = ['done' => 'bg-green-500 text-white', 'now' => 'bg-rarl-red text-white ring-4 ring-rarl-red/15', 'todo' => 'bg-gray-200 dark:bg-gray-800 text-gray-500'][$state];
        $txt = ['done' => 'text-gray-500', 'now' => 'text-gray-900 dark:text-white font-semibold', 'todo' => 'text-gray-400'][$state];
        $html .= '<li class="flex items-center gap-1.5 sm:gap-2"' . ($state === 'now' ? ' aria-current="step"' : '') . '>'
              . '<span class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 ' . $dot . '"><i class="fa-solid ' . ($state === 'done' ? 'fa-check' : $icon) . ' text-[11px]"></i></span>'
              . '<span class="hidden sm:inline ' . $txt . '">' . $label . '</span></li>';
        if ($i < $n) $html .= '<li class="w-5 sm:w-10 h-px ' . ($k < $current ? 'bg-green-400' : 'bg-gray-300 dark:bg-gray-700') . '" aria-hidden="true"></li>';
    }
    return $html . '</ol>';
}

// Signs a member in (shared by login, verify-email and reset-password).
function memberSignIn(array $member): void {
    session_regenerate_id(true);
    $_SESSION['member_id']   = $member['id'];
    $_SESSION['member_type'] = $member['type'];
    $_SESSION['member_name'] = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
    db()->prepare('UPDATE members SET last_login_at = NOW() WHERE id = ?')->execute([$member['id']]);
}

// Progressive enhancement for the long registration forms: draft autosave
// (never passwords/files), show-password + strength meter, inline invalid
// styling and jump-to-first-problem, and a busy state on submit.
function registrationFormEnhancerJs(string $formId, string $draftKey, string $busyLabel = 'Creating your account…'): string {
    $formId = json_encode($formId); $busy = json_encode($busyLabel); $draftKey = json_encode($draftKey === '' ? '' : 'rarl-draft-' . $draftKey);
    return <<<HTML
<script>
(function() {
  const form = document.getElementById({$formId}); if (!form) return;
  const KEY = {$draftKey}, BUSY = {$busy};
  const skip = el => !el.name || el.type === 'password' || el.type === 'file' || el.type === 'hidden' || el.name === 'website' || el.name === 'website_trap';
  // Restore a saved draft into empty fields (server-echoed values win).
  if (KEY) try {
    const d = JSON.parse(localStorage.getItem(KEY) || '{}');
    [...form.elements].forEach(el => { if (skip(el) || !(el.name in d)) return;
      if (el.type === 'checkbox') { if (!form.dataset.posted) el.checked = !!d[el.name]; }
      else if (!el.value) { el.value = d[el.name]; el.dispatchEvent(new Event('change')); } });
  } catch (e) {}
  let t; if (KEY) form.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => {
    const d = {}; [...form.elements].forEach(el => { if (!skip(el)) d[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
    try { localStorage.setItem(KEY, JSON.stringify(d)); } catch (e) {}
  }, 400); });

  // Password: show toggle + strength
  form.querySelectorAll('input[type=password]').forEach(pw => {
    const wrap = document.createElement('div'); wrap.className = 'relative'; pw.parentNode.insertBefore(wrap, pw); wrap.appendChild(pw); pw.classList.add('pr-11');
    const b = document.createElement('button'); b.type = 'button'; b.className = 'absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg text-gray-400 hover:text-gray-700'; b.innerHTML = '<i class="fa-solid fa-eye"></i>'; b.setAttribute('aria-label', 'Show password');
    b.onclick = () => { const s = pw.type === 'password'; pw.type = s ? 'text' : 'password'; b.innerHTML = '<i class="fa-solid ' + (s ? 'fa-eye-slash' : 'fa-eye') + '"></i>'; b.setAttribute('aria-label', s ? 'Hide password' : 'Show password'); };
    wrap.appendChild(b);
    if (pw.name !== 'password') return;
    const meter = document.createElement('div'); meter.className = 'mt-2 flex items-center gap-2';
    meter.innerHTML = '<div class="flex-1 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden"><div class="h-full w-0 transition-[width]"></div></div><span class="text-[11px] text-gray-400 w-20 text-right"></span>';
    wrap.after(meter);
    pw.addEventListener('input', () => {
      const v = pw.value; let s = 0;
      if (v.length >= 8) s++; if (v.length >= 12) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^\w\s]/.test(v)) s++;
      const lv = !v ? 0 : v.length < 8 ? 1 : Math.min(4, Math.max(2, s));
      const bar = meter.querySelector('.h-full'); bar.style.width = lv * 25 + '%';
      bar.className = 'h-full transition-[width] ' + ['', 'bg-red-500', 'bg-amber-500', 'bg-lime-500', 'bg-green-600'][lv];
      meter.querySelector('span').textContent = ['', 'Too short', 'Okay', 'Good', 'Strong'][lv];
    });
  });

  // Inline validation styling + jump to the first problem.
  const mark = el => { const bad = !el.validity.valid; el.classList.toggle('!border-red-400', bad); el.classList.toggle('!ring-red-100', bad); el.classList.toggle('ring-4', bad); };
  form.querySelectorAll('input,select,textarea').forEach(el => { el.addEventListener('blur', () => { if (el.value) mark(el); }); el.addEventListener('input', () => { if (el.classList.contains('!border-red-400')) mark(el); }); });
  form.addEventListener('invalid', e => { mark(e.target); }, true);
  form.addEventListener('submit', e => {
    const first = [...form.elements].find(el => el.willValidate && !el.validity.valid);
    if (first) { e.preventDefault(); first.scrollIntoView({behavior: 'smooth', block: 'center'}); first.focus({preventScroll: true}); return; }
    const btn = form.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + BUSY; }
  });
  form.addEventListener('rarl-registered', () => { try { localStorage.removeItem(KEY); } catch (e) {} });
  const err = document.getElementById('form-errors'); if (err) err.scrollIntoView({block: 'center'});
})();
</script>
HTML;
}

function publicFooter(): string {
    $year    = date('Y');
    $siteName = SITE_NAME;
    $tagline  = SITE_TAGLINE;
    $mainUrl  = MAIN_SITE_URL;
    return <<<HTML
<footer class="bg-rarl-navy text-white/45 py-10 mt-auto">
  <div class="w-full px-6 flex flex-col gap-6 text-xs">
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
      <span>&copy; {$year} {$siteName} &middot; {$tagline}</span>
      <div class="flex gap-5">
        <a href="{$mainUrl}" class="hover:text-white transition-colors">www.rarl-lab.com</a>
        <a href="login.php" class="hover:text-white transition-colors">Member Login</a>
        <a href="verify.php" class="hover:text-white transition-colors">Verify Certificate</a>
      </div>
    </div>
    <div class="flex flex-col sm:flex-row justify-between items-center gap-2 pt-4 border-t border-white/10 text-white/35">
      <span><i class="fa-solid fa-globe"></i> Website: <a href="{$mainUrl}" class="hover:text-white transition-colors">www.rarl-lab.com</a></span>
      <span><i class="fa-solid fa-location-dot"></i> Robotics &amp; Automation Research Lab (RARL), Queens Building, Leicester, LE1 9BH, United Kingdom</span>
    </div>
  </div>
</footer>
HTML;
}
