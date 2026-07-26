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

// ── Send email ─────────────────────────────────────────────
function sendEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . ">\r\n";
    $headers .= 'Reply-To: ' . MAIL_REPLY_TO . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    return @mail($to, $subject, $htmlBody, $headers);
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
    $icon = $f['type'] === 'success' ? '✅' : ($f['type'] === 'error' ? '⚠️' : 'ℹ️');
    return '<div class="flex items-center gap-3 p-4 rounded-xl border mb-5 text-sm ' . $classes . '">'
         . '<span>' . $icon . '</span><span>' . htmlspecialchars($f['msg']) . '</span></div>';
}

// ── Redirect helper ────────────────────────────────────────
function redirect(string $url): never {
    header('Location: ' . $url); exit;
}

// ── Shared HTML head ───────────────────────────────────────
function htmlHead(string $title, bool $isAdmin = false): string {
    $fullTitle  = htmlspecialchars($title) . ' — ' . SITE_NAME;
    $colorsJson = brandTailwindConfigJson();
    $fontUrl    = BRAND_FONT_GOOGLE_URL;
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
          sans:    ['Poppins','system-ui','sans-serif'],
          heading: ['Poppins','system-ui','sans-serif'],
        }
      }}
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="{$fontUrl}" rel="stylesheet"/>
  <style>
    body { font-family:'Poppins',system-ui,sans-serif; }
    h1,h2,h3,h4 { font-family:'Poppins',system-ui,sans-serif; }
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
    $stmt = $pdo->prepare("SELECT id FROM regional_sections WHERE scope='continent' AND is_published=1 AND continent = ? AND chair_title = 'Section Chair' ORDER BY display_order LIMIT 1");
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

// ── Reseed default plans / regional sections (also used by admin "Reseed Defaults") ──
function seedDefaults(): void {
    $pdo = db();
    $pdo->exec("INSERT INTO membership_plans (slug,name,tagline,description,audience,regular_price,display_order) VALUES
        ('free','Free','Open to every researcher','Full community access at no cost — individuals and labs alike.','both',NULL,1),
        ('student','Student','For students and trainees','For PhD/MSc/BSc students and early-career researchers.','individual',0.00,2),
        ('organization','Organization','For labs & institutions','For research labs and institutions joining as an organization.','lab',2500.00,3)
        ON DUPLICATE KEY UPDATE name=VALUES(name), tagline=VALUES(tagline), description=VALUES(description), audience=VALUES(audience), display_order=VALUES(display_order)");

    $pdo->exec("INSERT INTO regional_sections (scope,continent,country,name,chair_name,chair_email,chair_title,display_order) VALUES
        ('continent','Asia',NULL,'Asia Section','Seyed Ali Eftekhari','sae@rarl-lab.com','Section Chair',1),
        ('country','Asia','Korea','Korea Chapter','Seyed Ali Eftekhari','sae@rarl-lab.com','Section Chair',2),
        ('country','Asia','Bangladesh','Bangladesh Chapter','Atanu Shuvam Roy','asr@rarl-lab.com','Section Chair',3),
        ('country','Asia','Pakistan','Pakistan Chapter','Dr. Zeashan Hamid Khan','zhk@rarl.com','Chapter Chair',4),
        ('continent','Asia',NULL,'Asia Vice Chair','Dr. Zeashan Hamid Khan','zhk@rarl.com','Vice Chair',5),
        ('country','Asia','Malaysia','Malaysia Chapter','Dr. Mohammad Ali Toufigh',NULL,'Section Chair',6),
        ('country','Asia','China','China Chapter','Dr. Arash Sioofy Khoojine',NULL,'Section Chair',7),
        ('continent','Africa',NULL,'Africa Section','Dr. Mukhtar Iderawumi Abdulraheem',NULL,'Section Chair',8)
        ON DUPLICATE KEY UPDATE chair_name=VALUES(chair_name), chair_title=VALUES(chair_title), display_order=VALUES(display_order)");
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
    $pdf->SetXY(4, 14); $pdf->Cell(0, 4, mb_strtoupper($name), 0, 1);

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
    $pdf->MultiCell(30, 3.2,
        "MEMBER ID: #{$member['member_code']}\n" .
        'SINCE: ' . date('Y/m/d', strtotime($member['created_at'])) . "\n" .
        'SECTION: ' . ($sectionName ?: '—'), 0, 'L');

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
    $pdf->Cell(0, 3, setting('idcard_signer1_name', 'RARL President'), 0, 1);
    $pdf->SetXY(4, 44);
    $pdf->Cell(0, 3, $chairName ?: setting('idcard_signer2_name', 'Section Chair'), 0, 1);

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
        $pdfFile = generateIdCardPDF($m, $sectionName, $chairName);
    } catch (Throwable $e) {
        error_log('ID card generation failed for member ' . $memberId . ': ' . $e->getMessage());
        return false;
    }
    if (!$pdfFile) return false;

    $pdo->prepare('UPDATE members SET member_code=?, id_card_path=?, id_card_issued_at=CURDATE(), id_card_expires_at=DATE_ADD(CURDATE(), INTERVAL 3 YEAR) WHERE id=?')
        ->execute([$m['member_code'], $pdfFile, $memberId]);
    return true;
}

// ── Shared nav ─────────────────────────────────────────────
function publicNav(string $active = ''): string {
    $links = [
        'home'      => ['/', 'Home'],
        'community' => ['community.php', 'Community'],
        'resources' => ['resources.php', 'Learning Hub'],
        'people'    => ['people.php', 'People'],
        'partners'  => ['partners.php', 'Partner With Us'],
    ];
    $nav = ''; $mobileNav = '';
    foreach ($links as $key => [$href, $label]) {
        $cls = $key === $active
            ? 'text-rarl-red font-semibold'
            : 'text-gray-600 dark:text-gray-300 hover:text-rarl-red transition-colors';
        $nav .= '<a href="' . $href . '" class="text-sm ' . $cls . '">' . $label . '</a>';
        $mobileNav .= '<a href="' . $href . '" class="block px-4 py-3 text-sm border-b border-gray-100 dark:border-gray-800 ' . $cls . '">' . $label . '</a>';
    }
    $nav .= '<a href="' . MAIN_SITE_URL . '" target="_blank" rel="noopener" class="text-sm text-gray-400 hover:text-rarl-red transition-colors inline-flex items-center gap-1">rarl-lab.com <span class="text-[10px]">↗</span></a>';
    $mobileNav .= '<a href="' . MAIN_SITE_URL . '" target="_blank" rel="noopener" class="block px-4 py-3 text-sm text-gray-400 hover:text-rarl-red transition-colors">rarl-lab.com ↗</a>';
    $memberLinks = isset($_SESSION['member_id'])
        ? '<a href="dashboard.php" class="text-sm font-semibold text-white bg-rarl-red hover:bg-rarl-dark px-4 py-2 rounded-xl transition-colors">My Dashboard</a>'
        : '<a href="login.php" class="text-sm text-gray-600 hover:text-rarl-red transition-colors hidden sm:inline">Sign In</a>
           <a href="register.php" class="text-sm font-semibold text-white bg-rarl-red hover:bg-rarl-dark px-3 sm:px-4 py-2 rounded-xl transition-colors">Join</a>';
    $mobileMemberLinks = isset($_SESSION['member_id'])
        ? '<a href="dashboard.php" class="block px-4 py-3 text-sm font-semibold text-rarl-red">My Dashboard</a>'
        : '<a href="login.php" class="block px-4 py-3 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-800">Sign In</a>';
    $mark = BRAND_MARK_PATH;
    return <<<HTML
<header class="sticky top-0 z-50 bg-white/95 dark:bg-gray-900/95 backdrop-blur-md border-b border-gray-200 dark:border-gray-800 shadow-sm">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3 sm:gap-6">
    <a href="index.php" class="flex items-center gap-2.5 flex-shrink-0 min-w-0">
      <img src="{$mark}" alt="RARL" class="w-8 h-8 rounded-lg object-contain shadow flex-shrink-0"/>
      <div class="leading-tight hidden sm:block min-w-0">
        <div class="font-heading font-black text-sm text-gray-900 dark:text-white truncate">RARL Community</div>
        <div class="text-[10px] text-gray-400 truncate">Robotics &amp; Automation Research Lab</div>
      </div>
    </a>
    <nav class="hidden md:flex items-center gap-5">{$nav}</nav>
    <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
      {$memberLinks}
      <button type="button" onclick="document.getElementById('mobile-nav-panel').classList.toggle('hidden')" class="md:hidden w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors flex-shrink-0" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
  </div>
  <div id="mobile-nav-panel" class="hidden md:hidden bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 shadow-lg">
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
  <div class="max-w-6xl mx-auto px-6 flex flex-col gap-6 text-xs">
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
      <span>&copy; {$year} {$siteName} &middot; {$tagline}</span>
      <div class="flex gap-5">
        <a href="{$mainUrl}" class="hover:text-white transition-colors">www.rarl-lab.com</a>
        <a href="login.php" class="hover:text-white transition-colors">Member Login</a>
        <a href="verify.php" class="hover:text-white transition-colors">Verify Certificate</a>
      </div>
    </div>
    <div class="flex flex-col sm:flex-row justify-between items-center gap-2 pt-4 border-t border-white/10 text-white/35">
      <span>🌐 Website: <a href="{$mainUrl}" class="hover:text-white transition-colors">www.rarl-lab.com</a></span>
      <span>📍 Robotics &amp; Automation Research Lab (RARL), Queens Building, Leicester, LE1 9BH, United Kingdom</span>
    </div>
  </div>
</footer>
HTML;
}
