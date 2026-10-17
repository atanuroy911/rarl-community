<?php
/**
 * RARL — shared helpers for:
 *   • admin two-factor sign-in (TOTP, RFC 6238 — Google Authenticator, 1Password, Authy…)
 *   • event check-in passes (signed QR tokens) and attendance by scan
 *   • event reminder emails and the background job runner
 * Loaded by functions.php.
 */

// A per-install secret for signing tokens. Created on first use and kept in
// settings, so check-in passes stay valid across deploys.
// ── Member invites ─────────────────────────────────────────
// Signed link that opens registration for one email, even while registrations are closed.
const INVITE_DAYS = 30;
function inviteToken(string $email, ?int $expires = null): string {
    $email = strtolower(trim($email)); $expires ??= time() + INVITE_DAYS * 86400;
    $e = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    return $e . '.' . $expires . '.' . substr(hash_hmac('sha256', "invite:{$email}:{$expires}", appSecret()), 0, 24);
}
/** Email the token was issued for, or null if invalid/expired. */
function inviteParse(string $token): ?string {
    if (!preg_match('/^([A-Za-z0-9_-]+)\.(\d{10})\.([a-f0-9]{24})$/', $token, $m) || (int)$m[2] < time()) return null;
    $email = base64_decode(strtr($m[1], '-_', '+/'));
    return $email && hash_equals(inviteToken($email, (int)$m[2]), $token) ? $email : null;
}
function inviteUrl(string $email): string {
    return SITE_URL . '/register.php?invite=' . inviteToken($email);
}
/** True when this visitor arrived with a valid invite (remembered for the session). Sets $_SESSION['invite_email']. */
function inviteActive(): bool {
    if (!empty($_GET['invite']) && ($email = inviteParse((string)$_GET['invite']))) {
        $_SESSION['invite_email'] = $email; $_SESSION['invite_until'] = time() + 86400;
    }
    return !empty($_SESSION['invite_email']) && ($_SESSION['invite_until'] ?? 0) > time();
}
/** Registration is open for this visitor: globally, or via an invite. */
function canRegister(): bool {
    return registrationsOpen() || inviteActive();
}

function appSecret(): string {
    static $s = null;
    if ($s !== null) return $s;
    $s = setting('app_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        db()->prepare("INSERT INTO settings (`key`,`value`) VALUES ('app_secret',?) ON DUPLICATE KEY UPDATE `value` = `value`")->execute([$s]);
        $s = setting('app_secret', $s);
    }
    return $s;
}

function settingSet(string $key, string $value): void {
    db()->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$key, $value]);
    $GLOBALS['rarl_settings_cache'][$key] = $value;
}

/** PNG QR code as a data: URI (for <img>), using the bundled phpqrcode. */
function qrDataUri(string $text, int $scale = 6, int $margin = 2): string {
    if (!class_exists('QRcode')) require_once __DIR__ . '/libs/phpqrcode/qrlib.php';
    $tmp = tempnam(sys_get_temp_dir(), 'rqr');
    QRcode::png($text, $tmp, QR_ECLEVEL_M, $scale, $margin);
    $png = (string)file_get_contents($tmp);
    @unlink($tmp);
    return 'data:image/png;base64,' . base64_encode($png);
}

// ── TOTP ───────────────────────────────────────────────────
function base32Encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32Decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
function totpCode(string $secretB32, int $step): string {
    $h = hash_hmac('sha1', pack('N*', 0, $step), base32Decode($secretB32), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Checks a 6-digit code (±30 s clock drift). Returns the matched time step, or null. Never accepts the same step twice. */
function totpVerify(string $secretB32, string $code, string $replayKey = 'admin_totp_last_step'): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $now = (int)floor(time() / 30); $last = (int)setting($replayKey, '0');
    foreach ([0, -1, 1] as $d) {
        $step = $now + $d;
        if ($step > $last && hash_equals(totpCode($secretB32, $step), $code)) { settingSet($replayKey, (string)$step); return $step; }
    }
    return null;
}
function adminTwoFactorEnabled(): bool {
    return setting('admin_totp_enabled', '0') === '1' && setting('admin_totp_secret', '') !== '';
}
/** One-time backup codes, stored hashed; a used code is removed. */
function adminUseBackupCode(string $code): bool {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    $hashes = json_decode(setting('admin_totp_backup', '[]'), true) ?: [];
    foreach ($hashes as $i => $h) {
        if (password_verify($code, $h)) { unset($hashes[$i]); settingSet('admin_totp_backup', json_encode(array_values($hashes))); return true; }
    }
    return false;
}

// ── Event check-in ─────────────────────────────────────────
// A pass is "event.member.signature" — unguessable, and only valid for that
// member at that event. It encodes the admin check-in URL, so scanning it with
// any phone camera opens the check-in screen for a signed-in admin.
function checkinToken(int $eventId, int $memberId): string {
    return $eventId . '.' . $memberId . '.' . substr(hash_hmac('sha256', "checkin:{$eventId}:{$memberId}", appSecret()), 0, 20);
}
function checkinParse(string $token): ?array {
    if (!preg_match('/(\d+)\.(\d+)\.([a-f0-9]{20})/', $token, $m)) return null;
    return hash_equals(checkinToken((int)$m[1], (int)$m[2]), $m[0]) ? ['event_id' => (int)$m[1], 'member_id' => (int)$m[2]] : null;
}
function checkinUrl(int $eventId, int $memberId): string {
    return SITE_URL . '/admin/checkin.php?t=' . checkinToken($eventId, $memberId);
}
/**
 * Marks attendance from a pass and issues the certificate.
 * Returns ['ok', 'state' => checked_in|already|not_registered|cancelled|invalid, 'name', 'event'].
 */
function checkinApply(string $token): array {
    $p = checkinParse($token);
    if (!$p) return ['ok' => false, 'state' => 'invalid', 'name' => null, 'event' => null];
    $pdo = db();
    $e = $pdo->prepare('SELECT * FROM events WHERE id = ?'); $e->execute([$p['event_id']]); $event = $e->fetch();
    $m = $pdo->prepare('SELECT * FROM members WHERE id = ?'); $m->execute([$p['member_id']]); $member = $m->fetch();
    if (!$event || !$member) return ['ok' => false, 'state' => 'invalid', 'name' => null, 'event' => null];
    $name = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
    $r = $pdo->prepare('SELECT id, status FROM event_registrations WHERE event_id = ? AND member_id = ?'); $r->execute([$event['id'], $member['id']]); $reg = $r->fetch();
    $base = ['name' => $name, 'event' => $event['title'], 'member_code' => $member['member_code'], 'avatar' => $member['avatar_path'], 'event_id' => (int)$event['id']];
    if (!$reg) return ['ok' => false, 'state' => 'not_registered'] + $base;
    if ($reg['status'] === 'attended') return ['ok' => true, 'state' => 'already'] + $base;
    if ($reg['status'] === 'cancelled') return ['ok' => false, 'state' => 'cancelled'] + $base;
    $pdo->prepare("UPDATE event_registrations SET status = 'attended' WHERE id = ?")->execute([$reg['id']]);
    try { issueCertificateForAttendance($pdo, $event, $member, newEmailBatch()); } catch (Throwable $ex) { error_log('checkin cert: ' . $ex->getMessage()); }
    return ['ok' => true, 'state' => 'checked_in'] + $base;
}

// ── Event reminders ────────────────────────────────────────
// The day before an event, every registered member gets a reminder with the
// time, place/join link and an add-to-calendar link. Each event is reminded
// once (events.reminder_sent).
function sendEventReminders(): int {
    $pdo = db(); $sent = 0;
    try {
        $events = $pdo->query("SELECT * FROM events WHERE is_active = 1 AND reminder_sent = 0 AND event_date IS NOT NULL
                               AND event_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY")->fetchAll();
    } catch (Throwable $e) { return 0; }
    foreach ($events as $event) {
        // Claim the event first so two concurrent runs can't both send.
        $claim = $pdo->prepare('UPDATE events SET reminder_sent = 1 WHERE id = ? AND reminder_sent = 0');
        $claim->execute([$event['id']]);
        if (!$claim->rowCount()) continue;
        $regs = $pdo->prepare("SELECT m.id, m.email, m.full_name, m.lab_name, m.type FROM event_registrations r JOIN members m ON m.id = r.member_id
                               WHERE r.event_id = ? AND r.status = 'registered' AND m.status = 'active'");
        $regs->execute([$event['id']]);
        $batch = newEmailBatch();
        foreach ($regs as $member) {
            $memberName = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
            $eventUrl = SITE_URL . '/events.php#event-' . (int)$event['id'];
            $icsUrl   = SITE_URL . '/events.php?ics=' . (int)$event['id'];
            ob_start(); require __DIR__ . '/emails/event-reminder.php'; $body = ob_get_clean();
            $when = $event['event_date'] === date('Y-m-d') ? 'today' : 'tomorrow';
            queueEmail($batch, 'Reminder: ' . html_entity_decode($event['title'], ENT_QUOTES), $member['email'], (string)$memberName,
                'Reminder: ' . html_entity_decode($event['title'], ENT_QUOTES) . ' is ' . $when, $body);
            $sent++;
        }
    }
    return $sent;
}

/**
 * Background jobs, at most every 10 minutes, run after the response has been
 * sent (so no visitor waits): event reminders, then a small slice of the
 * email queue. Works without cron; cron-email-queue.php runs them too.
 */
function scheduleBackgroundJobs(): void {
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') return;
    $registered = true;
    register_shutdown_function(function () {
        try {
            if (time() - (int)setting('jobs_last_run', '0') < 600) return;
            settingSet('jobs_last_run', (string)time());
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            else return; // can't detach from the visitor's request: leave it to admin pages / cron
            ignore_user_abort(true);
            sendEventReminders();
            if (function_exists('processEmailQueue') && emailQueueReady()) processEmailQueue(10, 20);
            if (function_exists('membershipCertAutoRegen') && membershipCertAutoRegen()) regenerateOutdatedMembershipCerts(15, 20);
        } catch (Throwable $e) { error_log('background jobs: ' . $e->getMessage()); }
    });
}

// ── Affiliation ────────────────────────────────────────────
// Every member may (and is encouraged to) use this affiliation on papers.
const RARL_AFFILIATION = 'Robotics & Automation Research Lab (RARL), UK';
const RARL_AFFILIATION_ROLE = 'RARL Member';

// ── YouTube ────────────────────────────────────────────────
/** Video id from any YouTube link (watch, youtu.be, live, shorts, embed) or a bare id. */
function youtubeId(string $url): ?string {
    $url = trim($url);
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) return $url;
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|live/|shorts/|embed/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    return null;
}
/** Title + channel from YouTube's public oEmbed endpoint (no API key). */
function youtubeInfo(string $id): ?array {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = $code === 200 ? json_decode((string)$body, true) : null;
    return $j ? ['title' => (string)($j['title'] ?? ''), 'author' => (string)($j['author_name'] ?? '')] : null;
}
function youtubeThumb(string $id, string $q = 'hqdefault'): string { return 'https://i.ytimg.com/vi/' . $id . '/' . $q . '.jpg'; }
function youtubeEmbed(string $id): string { return 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0&modestbranding=1'; }

function videosReady(): bool {
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)db()->query("SHOW TABLES LIKE 'videos'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/** The affiliation, ready to copy — used on Research, Membership and Home. */
function affiliationBoxHtml(): string {
    $rows = [['Affiliation', RARL_AFFILIATION], ['Position', RARL_AFFILIATION_ROLE]];
    $out = '<div class="mt-3 space-y-2">';
    foreach ($rows as [$label, $value]) {
        $v = htmlspecialchars($value);
        $out .= '<div class="flex items-center gap-2 p-2.5 ps-3 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700">'
              . '<div class="min-w-0 flex-1"><p class="text-[11px] text-gray-500">' . $label . '</p><p class="text-sm font-semibold text-gray-900 dark:text-white notranslate" translate="no">' . $v . '</p></div>'
              . '<button type="button" class="w-9 h-9 rounded-full flex-shrink-0 text-gray-500 hover:bg-white dark:hover:bg-gray-700 hover:text-rarl-red" aria-label="Copy ' . strtolower($label) . '" title="Copy"'
              . ' onclick="var b=this;navigator.clipboard.writeText(' . htmlspecialchars(json_encode($value)) . ').then(function(){b.innerHTML=\'<i class=&quot;fa-solid fa-check text-green-600&quot;></i>\';setTimeout(function(){b.innerHTML=\'<i class=&quot;fa-regular fa-copy&quot;></i>\'},1500)})"><i class="fa-regular fa-copy"></i></button></div>';
    }
    return $out . '</div>';
}

function publicationsReady(): bool {
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)db()->query("SHOW TABLES LIKE 'publications'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

// ── Event cover generator ──────────────────────────────────
// Makes a 1600×900 cover in the RARL webinar style: logo, "International",
// a big "WEBINAR 2026"-style headline, the event title and its date.
// Styles: 'blue' (tech, light) or 'red' (RARL red). Returns the saved file
// name in uploads/events, or null if GD/FreeType isn't available.
function generateEventCover(array $ev, string $style = 'blue'): ?string {
    if (!function_exists('imagettftext')) return null;
    $style = $style === 'red' ? 'red' : 'blue';
    $im = @imagecreatefromjpeg(__DIR__ . "/assets/covers/cover-$style.jpg");
    if (!$im) return null;
    imagealphablending($im, true);
    $F = fn($w) => __DIR__ . "/assets/fonts/montserrat-$w.ttf";
    $dark = $style === 'blue';
    $ink   = $dark ? imagecolorallocate($im, 14, 20, 34) : imagecolorallocate($im, 255, 255, 255);
    $soft  = $dark ? imagecolorallocate($im, 36, 50, 77) : imagecolorallocate($im, 255, 226, 222);
    $accent = $dark ? imagecolorallocate($im, 225, 18, 11) : imagecolorallocate($im, 255, 255, 255);
    $W = imagesx($im);
    $text = fn($s) => html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    $width = function (string $s, string $font, float $size) { $b = imagettfbbox($size, 0, $font, $s); return $b[2] - $b[0]; };
    $center = function ($s, $font, $size, $y, $color) use ($im, $W, $width) { imagettftext($im, $size, 0, (int)(($W - $width($s, $font, $size)) / 2), (int)$y, $color, $font, $s); };
    // Shrink to fit a width.
    $fit = function (string $s, string $font, float $size, float $max) use ($width) { while ($size > 10 && $width($s, $font, $size) > $max) $size -= 2; return $size; };

    // Logo
    $logo = @imagecreatefrompng(__DIR__ . ($dark ? '/assets/brand/rarl-logo.png' : '/assets/brand/rarl-logo-light.png'));
    if ($logo) { $lw = 600; $lh = (int)($lw * imagesy($logo) / imagesx($logo)); imagecopyresampled($im, $logo, (int)(($W - $lw) / 2), 70, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo)); }

    $year = $ev['event_date'] ? date('Y', strtotime($ev['event_date'])) : date('Y');
    $center('International', $F(800), 46, 330, $ink);
    $head = strtoupper((string)($ev['type'] ?: 'event')) . ' ' . $year;
    $center($head, $F(900), $fit($head, $F(900), 128, 1380), 480, $accent);

    // Title: up to two lines.
    $words = preg_split('/\s+/', trim($text($ev['title']))); $lines = ['']; $size = 38; $max = 1250;
    foreach ($words as $w) { $try = trim(end($lines) . ' ' . $w); if ($width($try, $F(600), $size) > $max && end($lines) !== '') { if (count($lines) === 2) { $lines[1] .= '…'; break; } $lines[] = $w; } else $lines[count($lines) - 1] = $try; }
    foreach ($lines as $i => $l) $center($l, $F(600), $size, 570 + $i * 56, $soft);

    // Date / place
    $when = $ev['event_date'] ? date('l, d F Y', strtotime($ev['event_date'])) . ($ev['event_time'] ? '  ·  ' . date('g:i A', strtotime($ev['event_time'])) : '') : '';
    $where = $ev['location'] ? $text($ev['location']) : (!empty($ev['online_url']) ? 'Online' : '');
    $line = trim($when . ($when && $where ? '  ·  ' : '') . $where);
    if ($line !== '') $center($line, $F(800), 30, 570 + count($lines) * 56 + 40, $ink);

    $dir = UPLOADS_PATH . '/events'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = 'cover-' . (int)($ev['id'] ?? 0) . '-' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($im, "$dir/$file", 88); imagedestroy($im);
    return $file;
}
