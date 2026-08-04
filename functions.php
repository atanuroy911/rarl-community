<?php
/**
 * RARL Community Platform — Database + Shared Helpers
 */
require_once __DIR__ . '/config.php';

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
function cleanEmail(string $v): string {
    return filter_var(trim($v), FILTER_SANITIZE_EMAIL);
}
function cleanUrl(string $v): string {
    $v = trim($v);
    if ($v && !preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    return filter_var($v, FILTER_SANITIZE_URL);
}

// ── CSRF ───────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfCheck(): bool {
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
function nextCertNumber(): string {
    $pdo     = db();
    $counter = (int) setting('cert_id_counter', '0');
    $counter++;
    $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'cert_id_counter'")->execute([$counter]);
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
    $pdf->SetY(40); $pdf->Cell(0, 0, 'ROBOTICS AND AUTOMATION RESEARCH LABORATORY', 0, 1, 'C');
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
function issueCertificateForAttendance(PDO $pdo, array $event, array $member): bool {
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

    if (file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) {
        generateCertPDF($certDir . $pdfFile, $name, $event['title'], $certNo, $event['event_date'] ?? date('Y-m-d'), $uuid);
        $pdfPath = $pdfFile;
    }

    $pdo->prepare("INSERT INTO certificates (uuid, certificate_no, member_id, recipient_name, recipient_email, event_id, pdf_path)
        VALUES (?,?,?,?,?,?,?)")->execute([$uuid, $certNo, $member['id'], $name, $email, $event['id'], $pdfPath]);

    $verifyUrl  = CERT_VERIFY_URL . '?id=' . $uuid;
    $eventTitle = $event['title'];
    $eventDate  = $event['event_date'] ? date('d F Y', strtotime($event['event_date'])) : date('d F Y');
    $certNumber = $certNo;
    $memberName = $name;
    ob_start(); require __DIR__ . '/emails/certificate.php'; $body = ob_get_clean();
    sendEmail($email, $name, 'Your RARL Certificate — ' . $event['title'], $body);
    $pdo->prepare("UPDATE certificates SET emailed_at = NOW() WHERE uuid = ?")->execute([$uuid]);

    return true;
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
            $mailer->send(MAIL_FROM_EMAIL, MAIL_FROM_NAME, $to, $toName, $subject, $htmlBody, $attachments);
            return true;
        } catch (Throwable $e) {
            error_log('SMTP send failed: ' . $e->getMessage());
            return false;
        }
    }

    [$headers, $body] = SmtpMailer::buildMime(MAIL_FROM_EMAIL, MAIL_FROM_NAME, $to, $toName, $subject, $htmlBody, $attachments);
    // mail() sets Subject/To from its own params — drop those from the shared
    // header set to avoid duplicates, keep the rest (Content-Type, From, etc.)
    $headers = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'Subject:') && !str_starts_with($h, 'To:')));
    $headers[] = 'Reply-To: ' . MAIL_REPLY_TO;
    return @mail($to, $subject, $body, implode("\r\n", $headers));
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
    return '<div class="flex items-center gap-3 p-4 rounded-xl border mb-5 text-sm ' . $classes . '">'
         . '<span>' . $icon . '</span><span>' . htmlspecialchars($f['msg']) . '</span></div>';
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
function htmlHead(string $title, bool $isAdmin = false): string {
    $fullTitle  = htmlspecialchars($title) . ' — ' . SITE_NAME;
    $colorsJson = brandTailwindConfigJson();
    $fontUrl    = BRAND_FONT_GOOGLE_URL;
    $faUrl      = FONTAWESOME_CDN_URL;
    $sizeCss    = rarlFontSizeCss();
    return <<<HTML
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>{$fullTitle}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: {
        colors: {$colorsJson},
        fontFamily: {
          sans:    ['Inter','system-ui','sans-serif'],
          heading: ['Inter','system-ui','sans-serif'],
        }
      }}
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="{$fontUrl}" rel="stylesheet"/>
  <link href="{$faUrl}" rel="stylesheet"/>
  <style>
    body { font-family:'Inter',system-ui,sans-serif; }
    h1,h2,h3,h4 { font-family:'Inter',system-ui,sans-serif; }
    {$sizeCss}
    .reveal { opacity:0; transform:translateY(20px); transition:opacity .5s ease,transform .5s ease; }
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
  </style>
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
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"
            onchange="var o=this.options[this.selectedIndex],f=document.getElementById(\'' . $flagId . '\');if(o.dataset.code){f.src=\'https://flags.restcountries.com/v5/w320/\'+o.dataset.code+\'.png\';f.style.display=\'\';}else{f.style.display=\'none\';}">
            ' . countrySelectOptions($selected) . '
          </select>
          <img id="' . $flagId . '" src="' . htmlspecialchars((string)$flagUrl) . '" alt="" style="' . $display . '" class="w-8 h-6 object-cover rounded flex-shrink-0 border border-gray-200 dark:border-gray-600"/>
        </div>';
}

// ── Install / migrations ────────────────────────────────────
const RARL_MIGRATIONS = [
    'schema.sql'                        => 'Base schema (members, certificates, settings, …)',
    '002_v2_features.sql'               => 'v2 features (plans, OTP, community, ID cards, sections)',
    '003_community_richtext.sql'        => 'Community rich-text posts + single photo upload',
    '004_events_premium_directory.sql'  => 'Public events/RSVP, premium learning content, member directory',
    '005_chapters_not_sections.sql'     => 'Consolidate "Section" terminology into "Chapter"',
    '006_multi_email_accounts.sql'      => 'Multiple emails per account + temp-password / chair-account support',
    '007_certificate_templates.sql'     => 'Uploadable ID card / certificate templates (HTML-based generation)',
    '008_membership_certs_and_chapters.sql' => 'Membership certificates (nullable event_id, cert_type) + chapter-scoped announcements',
    '009_admin_only_posts.sql'          => 'Seeds the "RARL Community Team" system account admin posts are authored as',
];

function dbTablesExist(): bool {
    try {
        $pdo = db();
        return (bool) $pdo->query("SHOW TABLES LIKE 'members'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function runSqlFile(PDO $pdo, string $path): array {
    $sql = file_get_contents($path);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $ran = 0;
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $ran++;
    }
    return ['ok' => true, 'statements' => $ran];
}

function runAllMigrations(): array {
    $pdo = db();
    $results = [];
    foreach (array_keys(RARL_MIGRATIONS) as $file) {
        $path = __DIR__ . '/sql/' . $file;
        try {
            $results[$file] = runSqlFile($pdo, $path);
        } catch (PDOException $e) {
            $results[$file] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    return $results;
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
function nextMemberCode(string $planSlug): string {
    $pdo     = db();
    $counter = (int) setting('id_card_counter', '0');
    $counter++;
    $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'id_card_counter'")->execute([$counter]);
    $prefix = strtoupper(substr($planSlug ?: 'MEM', 0, 3));
    return $prefix . str_pad((string)$counter, 4, '0', STR_PAD_LEFT);
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

// The "RARL Community Team" system account admin-authored feed posts are
// attributed to (see sql/009_admin_only_posts.sql) — reuses community_posts'
// existing member_id join instead of adding a parallel admin-post table.
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
        <span class="text-xs text-gray-400 py-2"><i class="fa-solid fa-comment"></i> <?= count($comments) ?></span>
      </div>

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
// x/y/w/h are percentages of the template's page_width_mm/page_height_mm, so the
// exact same config drives both the HTML preview (renderTemplateHtml) and the
// FPDF output (renderTemplatePdf) — what the admin previews is what gets emailed.
// Recognized field keys: name, event, date, cert_no, member_code, section,
// signer1, signer2, since_date, qr (image), avatar (image, member photo).
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

function renderTemplateHtml(array $template, array $data): string {
    $bgUrl = SITE_URL . '/uploads/templates/' . rawurlencode($template['background_path']);
    $w = (float)$template['page_width_mm']; $h = (float)$template['page_height_mm'];
    $config = json_decode($template['config'] ?? '[]', true) ?: [];

    $html = '<div style="position:relative;width:100%;max-width:900px;aspect-ratio:' . $w . '/' . $h . ';background:#fff;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.15);">';
    $html .= '<img src="' . htmlspecialchars($bgUrl) . '" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" />';

    foreach ($config as $f) {
        $key = $f['key'] ?? '';
        $x = (float)($f['x'] ?? 0); $y = (float)($f['y'] ?? 0);
        if ($key === 'qr' || $key === 'avatar' || $key === 'signature') {
            $imgW = (float)($f['w'] ?? 15); $imgH = (float)($f['h'] ?? 15);
            $placeholder = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="#eee"/></svg>');
            $src = match (true) {
                $key === 'qr' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="#eee"/><text x="30" y="34" font-size="8" text-anchor="middle" fill="#999">QR</text></svg>'),
                $key === 'signature' => !empty($f['image']) ? SITE_URL . '/uploads/templates/' . rawurlencode($f['image']) : $placeholder,
                default => !empty($data['avatar_url']) ? $data['avatar_url'] : $placeholder,
            };
            $html .= sprintf(
                '<img src="%s" style="position:absolute;left:%s%%;top:%s%%;width:%s%%;height:%s%%;object-fit:contain;" />',
                htmlspecialchars($src), $x, $y, ($imgW / $w * 100), ($imgH / $h * 100)
            );
            continue;
        }
        $fontSize = (float)($f['font_size'] ?? 12);
        $color    = $f['color'] ?? '#111111';
        $align    = $f['align'] ?? 'left';
        $bold     = !empty($f['bold']) ? 'font-weight:bold;' : '';
        $transform = $align === 'center' ? 'translateX(-50%)' : ($align === 'right' ? 'translateX(-100%)' : 'none');
        $text = $key === 'custom_text' ? ($f['text'] ?? '') : templateFieldValue($key, $data);
        $html .= sprintf(
            '<div style="position:absolute;left:%s%%;top:%s%%;transform:%s;font-family:Helvetica,Arial,sans-serif;font-size:%spx;color:%s;%swhite-space:nowrap;">%s</div>',
            $x, $y, $transform, $fontSize * 1.5, htmlspecialchars($color), $bold, htmlspecialchars($text)
        );
    }
    $html .= '</div>';
    return $html;
}

function renderTemplatePdf(array $template, array $data, string $outPath): bool {
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
                $qrPath = sys_get_temp_dir() . '/rarl_tplqr_' . substr(md5($data['verify_url']), 0, 12) . '.png';
                QRcode::png($data['verify_url'], $qrPath, QR_ECLEVEL_L, 4, 1);
                $tmpFiles[] = $qrPath;
                if (file_exists($qrPath)) $pdf->Image($qrPath, $x, $y, $imgW, $imgH, 'PNG');
            }
            continue;
        }
        if ($key === 'avatar') {
            $imgW = (float)($f['w'] ?? 15); $imgH = (float)($f['h'] ?? 15);
            if (!empty($data['avatar_path']) && file_exists($data['avatar_path'])) {
                try { $pdf->Image($data['avatar_path'], $x, $y, $imgW, $imgH); } catch (Throwable $e) {}
            }
            continue;
        }
        if ($key === 'signature') {
            $imgW = (float)($f['w'] ?? 20); $imgH = (float)($f['h'] ?? 12);
            $sigPath = !empty($f['image']) ? UPLOADS_PATH . '/templates/' . $f['image'] : null;
            if ($sigPath && file_exists($sigPath)) {
                try { $pdf->Image($sigPath, $x, $y, $imgW, $imgH); } catch (Throwable $e) {}
            }
            continue;
        }
        $fontSize = (float)($f['font_size'] ?? 12);
        $color = $f['color'] ?? '#111111';
        [$r, $g, $b] = sscanf(ltrim($color, '#'), '%02x%02x%02x') ?: [17, 17, 17];
        $align = $f['align'] ?? 'left';
        $pdf->SetFont('Helvetica', !empty($f['bold']) ? 'B' : '', $fontSize);
        $pdf->SetTextColor($r, $g, $b);
        $text = fpdfEnc($key === 'custom_text' ? ($f['text'] ?? '') : templateFieldValue($key, $data));
        $textW = $pdf->GetStringWidth($text);
        $px = $align === 'center' ? $x - $textW / 2 : ($align === 'right' ? $x - $textW : $x);
        $pdf->SetXY($px, $y);
        $pdf->Cell($textW + 1, $fontSize * 0.4, $text, 0, 0);
    }

    $dir = dirname($outPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $pdf->Output('F', $outPath);
    foreach ($tmpFiles as $t) @unlink($t);
    return true;
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
    sendEmail($chairEmail, $memberName, 'Your RARL Community chair account', $body);
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
        $planSlug = 'free';
        if ($m['plan_id']) {
            $p = $pdo->prepare('SELECT slug FROM membership_plans WHERE id = ?');
            $p->execute([$m['plan_id']]);
            $planSlug = ($p->fetch()['slug'] ?? 'free');
        }
        $m['member_code'] = nextMemberCode($planSlug);
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

// Issues (once) a "Certificate of Membership" for a member — unlike ID cards,
// no photo is required, so this can fire the moment a member is activated.
// Uses the admin's default 'membership' template if one is set; otherwise
// falls back to a simple plain design (generatePlainMembershipCertPDF).
// No-op if the member already has one. Returns true if a cert now exists.
function issueMembershipCertificate(int $memberId): bool {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
    if (!$m) return false;

    $dup = $pdo->prepare("SELECT id FROM certificates WHERE member_id = ? AND cert_type = 'membership'");
    $dup->execute([$memberId]);
    if ($dup->fetch()) return true; // already issued, not an error

    $name = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
    $sectionName = '';
    if ($m['section_id']) {
        $s = $pdo->prepare('SELECT name FROM regional_sections WHERE id = ?');
        $s->execute([$m['section_id']]);
        $sectionName = $s->fetchColumn() ?: '';
    }

    $uuid = generateUuid();
    $certNo = nextCertNumber();
    $certDir = UPLOADS_PATH . '/certificates/';
    if (!is_dir($certDir)) mkdir($certDir, 0755, true);
    $pdfFile = 'membercert_' . str_replace('-', '', $uuid) . '.pdf';
    $pdfFull = $certDir . $pdfFile;
    $verifyUrl = CERT_VERIFY_URL . '?id=' . $uuid;

    try {
        $template = getDefaultTemplate('membership');
        if ($template) {
            renderTemplatePdf($template, [
                'name' => $name, 'member_code' => $m['member_code'] ?: '', 'section' => $sectionName ?: '—',
                'since_date' => date('d F Y', strtotime($m['created_at'])), 'cert_no' => $certNo,
                'signer1' => setting('idcard_signer1_name', 'RARL President'),
                'verify_url' => $verifyUrl,
            ], $pdfFull);
        } else {
            generatePlainMembershipCertPDF($pdfFull, $name, $certNo, $m['created_at'], $sectionName, $uuid);
        }
    } catch (Throwable $e) {
        error_log('Membership certificate generation failed for member ' . $memberId . ': ' . $e->getMessage());
        return false;
    }

    $pdo->prepare(
        'INSERT INTO certificates (uuid, certificate_no, member_id, cert_type, recipient_name, recipient_email, event_id, pdf_path)
         VALUES (?,?,?,"membership",?,?,NULL,?)'
    )->execute([$uuid, $certNo, $memberId, $name, $m['email'], $pdfFile]);
    return true;
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
    $pdf->SetY(42); $pdf->Cell(0, 0, 'ROBOTICS AND AUTOMATION RESEARCH LABORATORY', 0, 1, 'C');
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
    $pdf->SetY(115); $pdf->Cell(0, 0, fpdfEnc("is a certified member of the RARL Community{$sectionLine}."), 0, 1, 'C');
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
        <a href="index.php" class="inline-flex items-center gap-2 px-7 py-3 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition-all text-sm">← Back to Home</a>
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
    issueMembershipCertificate($memberId);
}

// ── Shared nav ─────────────────────────────────────────────
function publicNav(string $active = ''): string {
    // Icon-based primary nav (LinkedIn-style: icon + tiny label, underline on active)
    $icons = [
        'home'      => ['index.php',     '<i class="fa-solid fa-house"></i>', 'Home'],
        'community' => ['community.php', '<i class="fa-solid fa-comments"></i>', 'Community'],
        'events'    => ['events.php',    '<i class="fa-solid fa-calendar-days"></i>', 'Events'],
        'resources' => ['resources.php', '<i class="fa-solid fa-book"></i>', 'Learning'],
        'directory' => ['directory.php', '<i class="fa-solid fa-magnifying-glass"></i>', 'Directory'],
    ];
    // Secondary links tucked into the "Me" account menu rather than the top bar
    $moreLinks = [
        'people'   => ['people.php',   '<i class="fa-solid fa-people-group"></i> Leadership'],
        'partners' => ['partners.php', '<i class="fa-solid fa-handshake"></i> Partner With Us'],
    ];

    $isLoggedIn = isset($_SESSION['member_id']);
    $me = null;
    if ($isLoggedIn) {
        $ms = db()->prepare('SELECT full_name, lab_name, type, avatar_path, is_chair, status, id_card_path, institution, lab_website FROM members WHERE id = ?');
        $ms->execute([(int)$_SESSION['member_id']]);
        $me = $ms->fetch();
    }
    $myName = $me ? ($me['type'] === 'lab' ? $me['lab_name'] : $me['full_name']) : '';
    $myAvatar = $me ? memberAvatarHtml($me['avatar_path'], $myName ?: '?', 'w-8 h-8 text-xs', memberNeedsAttention($me)) : '';

    $nav = ''; $mobileNav = '';
    foreach ($icons as $key => [$href, $icon, $label]) {
        $cls = $key === $active
            ? 'text-rarl-red border-rarl-red'
            : 'text-gray-500 dark:text-gray-400 border-transparent hover:text-rarl-red';
        $nav .= '<a href="' . $href . '" class="flex flex-col items-center justify-center gap-0.5 px-2 sm:px-3 h-16 border-b-2 ' . $cls . ' transition-colors">
                    <span class="text-lg leading-none">' . $icon . '</span>
                    <span class="text-[10px] font-medium leading-none">' . $label . '</span>
                  </a>';
        $mobileNav .= '<a href="' . $href . '" class="block px-4 py-3 text-sm border-b border-gray-100 dark:border-gray-800 ' . ($key === $active ? 'text-rarl-red font-semibold' : 'text-gray-600 dark:text-gray-300') . '">' . $icon . ' ' . $label . '</a>';
    }

    $moreMobile = '';
    foreach ($moreLinks as [$href2, $label2]) {
        $moreMobile .= '<a href="' . $href2 . '" class="block px-4 py-3 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800">' . $label2 . '</a>';
    }
    $mobileNav .= $moreMobile;
    $mobileNav .= '<a href="' . MAIN_SITE_URL . '" target="_blank" rel="noopener" class="block px-4 py-3 text-sm text-gray-400 hover:text-rarl-red transition-colors">rarl-lab.com ↗</a>';

    $moreMenuItems = '';
    foreach ($moreLinks as [$href3, $label3]) {
        $moreMenuItems .= '<a href="' . $href3 . '" class="block px-4 py-2 text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">' . $label3 . '</a>';
    }

    $chairMenuItem = ($isLoggedIn && !empty($me['is_chair']))
        ? '<a href="chapter.php" class="block px-4 py-2 text-xs text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20"><i class="fa-solid fa-compass"></i> Chapter Portal</a>'
        : '';

    if ($isLoggedIn) {
        $accountMenu = <<<HTML
<div class="relative flex-shrink-0" id="me-menu-wrap">
  <button type="button" onclick="document.getElementById('me-menu').classList.toggle('hidden')" class="flex flex-col items-center justify-center gap-0.5 px-2 h-16 text-gray-500 dark:text-gray-400 hover:text-rarl-red transition-colors">
    {$myAvatar}
    <span class="text-[10px] font-medium leading-none flex items-center gap-0.5">Me ▾</span>
  </button>
  <div id="me-menu" class="hidden absolute right-0 top-16 w-56 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-xl overflow-hidden z-50">
    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800">
      <p class="text-sm font-bold text-gray-900 dark:text-white truncate">{$myName}</p>
    </div>
    <a href="profile.php" class="block px-4 py-2 text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"><i class="fa-solid fa-user"></i> View Profile</a>
    <a href="dashboard.php" class="block px-4 py-2 text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"><i class="fa-solid fa-chart-simple"></i> Dashboard</a>
    {$chairMenuItem}
    <a href="profile.php#account" class="block px-4 py-2 text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"><i class="fa-solid fa-gear"></i> Account Settings</a>
    {$moreMenuItems}
    <div class="border-t border-gray-100 dark:border-gray-800">
      <a href="logout.php" class="block px-4 py-2 text-xs text-rarl-red font-semibold hover:bg-gray-50 dark:hover:bg-gray-800"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
    </div>
  </div>
</div>
<script>
  document.addEventListener('click', function(e) {
    const wrap = document.getElementById('me-menu-wrap');
    if (wrap && !wrap.contains(e.target)) document.getElementById('me-menu')?.classList.add('hidden');
  });
</script>
HTML;
        $mobileMemberLinks = '<a href="profile.php" class="block px-4 py-3 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-user"></i> Profile &amp; Account Settings</a>
                               <a href="dashboard.php" class="block px-4 py-3 text-sm font-semibold text-rarl-red border-b border-gray-100 dark:border-gray-800"><i class="fa-solid fa-chart-simple"></i> Dashboard</a>
                               <a href="logout.php" class="block px-4 py-3 text-sm text-rarl-red"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>';
    } else {
        $accountMenu = '<div class="flex items-center gap-2 flex-shrink-0">
            <a href="login.php" class="text-sm text-gray-600 hover:text-rarl-red transition-colors hidden sm:inline">Sign In</a>
            <a href="register.php" class="text-sm font-semibold text-white bg-rarl-red hover:bg-rarl-dark px-3 sm:px-4 py-2 rounded-xl transition-colors">Join</a>
          </div>';
        $mobileMemberLinks = '<a href="login.php" class="block px-4 py-3 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800">Sign In</a>
                               <a href="register.php" class="block px-4 py-3 text-sm font-semibold text-rarl-red">Join Free</a>';
    }

    $mark = BRAND_MARK_PATH;
    $tagline = SITE_TAGLINE;
    $mainUrl = MAIN_SITE_URL;
    $replyTo = MAIL_REPLY_TO;
    $searchQ = htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES);
    $banner = freeMembershipBannerHtml();
    return <<<HTML
{$banner}
<div class="bg-rarl-navy text-white/60 text-xs py-2 hidden sm:block">
  <div class="w-full px-6 flex justify-between items-center">
    <span><i class="fa-solid fa-flask"></i> {$tagline}</span>
    <div class="flex gap-5">
      <a href="{$mainUrl}" class="hover:text-white transition-colors">rarl-lab.com</a>
      <a href="mailto:{$replyTo}" class="hover:text-white transition-colors">{$replyTo}</a>
    </div>
  </div>
</div>
<header class="sticky top-0 z-50 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md border-b border-gray-200 dark:border-gray-800 shadow-sm">
  <div class="w-full px-4 sm:px-6 h-16 flex items-center gap-3 sm:gap-5">
    <a href="index.php" class="flex items-center gap-2 flex-shrink-0">
      <img src="{$mark}" alt="RARL" class="w-8 h-8 rounded-lg object-contain shadow flex-shrink-0"/>
    </a>
    <form action="directory.php" method="GET" class="hidden sm:flex items-center flex-1 max-w-xs">
      <div class="relative w-full">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="text" name="q" value="{$searchQ}" placeholder="Search members, labs, research…"
          class="w-full pl-9 pr-3 py-2 bg-gray-100 dark:bg-gray-800 border border-transparent focus:border-rarl-red/40 focus:bg-white dark:focus:bg-gray-900 rounded-lg text-xs outline-none transition-colors"/>
      </div>
    </form>
    <nav class="hidden md:flex items-center ml-auto">{$nav}</nav>
    <div class="flex items-center gap-1 sm:gap-2 flex-shrink-0 ml-auto md:ml-0">
      {$accountMenu}
      <button type="button" onclick="document.getElementById('mobile-nav-panel').classList.toggle('hidden')" class="md:hidden w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors flex-shrink-0" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
  <div id="mobile-nav-panel" class="hidden md:hidden bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 shadow-lg">
    <form action="directory.php" method="GET" class="p-3 border-b border-gray-100 dark:border-gray-800">
      <input type="text" name="q" value="{$searchQ}" placeholder="Search members, labs, research…"
        class="w-full px-3 py-2 bg-gray-100 dark:bg-gray-800 rounded-lg text-sm outline-none"/>
    </form>
    {$mobileNav}
    {$mobileMemberLinks}
  </div>
</header>
HTML;
}

// ── Shared footer ──────────────────────────────────────────
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
