<?php
/**
 * RARL — premade certificate and ID card designs.
 * Backgrounds are rendered from the SVG sources in assets/templates/src
 * (editable in Figma / Illustrator / Inkscape) to assets/templates/*.jpg.
 * Text fields are fully editable afterwards in Admin → Templates.
 *
 * Field coordinates: x/y in % of the page (top-left of the text, or its
 * centre/right edge per align); image w/h and max_width in mm; sizes in pt.
 */

const PRESIDENT_NAME = 'Ata Jahangir Moshayedi';

function premadeTemplates(): array {
    // $o (per-field options) is on the left of + so it overrides the defaults.
    $t  = fn($text, $x, $y, $size, $o = []) => $o + ['key' => 'custom_text', 'text' => $text, 'x' => $x, 'y' => $y, 'font_size' => $size, 'align' => 'center', 'font' => 'montserrat', 'color' => '#111111'];
    $k  = fn($key, $x, $y, $size, $o = []) => $o + ['key' => $key, 'x' => $x, 'y' => $y, 'font_size' => $size, 'align' => 'center', 'font' => 'montserrat', 'color' => '#111111'];
    $qr = fn($x, $y, $s) => ['key' => 'qr', 'x' => $x, 'y' => $y, 'w' => $s, 'h' => $s];
    // Two signature blocks: script name, printed name, role.
    $sig = fn($x, $y, $name, $role, $color = '#111111') => [
        $t($name, $x, $y, 22, ['font' => 'script', 'color' => $color]),
        $t($name, $x, $y + 6.6, 10, ['bold' => true, 'color' => $color]),
        $t($role, $x, $y + 10.4, 8.5, ['color' => '#555555']),
    ];
    $para = 'for active participation in {event}, organised by the Robotics & Automation Research Lab (RARL), UK.';

    return [
        'red-wave' => ['name' => 'Red Wave — Achievement', 'type' => 'certificate', 'bg' => 'cert-red-wave.jpg', 'w' => 297, 'h' => 210, 'default' => true,
            'config' => array_merge([
                $t('CERTIFICATE', 50, 24, 56, ['bold' => true]),
                $t('OF ACHIEVEMENT', 50, 35, 21, ['bold' => true]),
                $t('THIS CERTIFICATE IS AWARDED TO', 50, 42.5, 10.5, ['color' => '#555555']),
                $k('name', 50, 47.5, 34, ['bold' => true, 'max_width' => 210]),
                $t($para, 50, 59, 12.5, ['wrap' => true, 'max_width' => 190, 'color' => '#333333']),
                $k('date', 50, 71, 11.5, ['color' => '#333333']),
            ], $sig(33, 76, 'Amin Kolahdooz', 'Europe Chapter Chair'), $sig(67, 76, PRESIDENT_NAME, 'RARL President'), [
                $qr(4.2, 73, 20),
                $t('Certificate {cert_no}', 4.2, 93.3, 7, ['align' => 'left', 'color' => '#666666']),
            ])],
        'webinar-aischolar' => ['name' => 'Webinar — RARL × AiScholar', 'type' => 'certificate', 'bg' => 'cert-webinar-aischolar.jpg', 'w' => 297, 'h' => 167.06,
            'config' => array_merge([
                $t('CERTIFICATE OF PARTICIPATION', 50, 21, 26, ['font' => 'playfair', 'color' => '#0d1b4b']),
                $t('International Webinar 2026', 50, 27.5, 15, ['font' => 'playfair', 'color' => '#c8102e']),
                $t('PROUDLY PRESENTED TO', 50, 38.5, 9, ['bold' => true, 'color' => '#3b4a63']),
                $k('name', 50, 42.5, 38, ['font' => 'script', 'color' => '#0d1b4b', 'max_width' => 180]),
                $t($para, 50, 54, 11.5, ['wrap' => true, 'max_width' => 170, 'color' => '#24324f']),
                $k('date', 50, 61, 10.5, ['color' => '#24324f']),
            ], $sig(31, 69, 'Amin Kolahdooz', 'Europe Chapter Chair', '#0d1b4b'), $sig(69, 69, PRESIDENT_NAME, 'RARL President', '#0d1b4b'), [
                $qr(47.5, 69, 15),
                $t('No. {cert_no}', 50, 79, 6.5, ['color' => '#3b4a63']),
            ])],
        'navy-classic' => ['name' => 'Navy & Gold — Classic', 'type' => 'certificate', 'bg' => 'cert-navy-classic.jpg', 'w' => 297, 'h' => 210,
            'config' => array_merge([
                $t('Certificate', 50, 23, 50, ['font' => 'playfair', 'color' => '#14213f']),
                $t('OF PARTICIPATION', 50, 35, 15, ['bold' => true, 'color' => '#a77c25']),
                $t('PROUDLY PRESENTED TO', 50, 41.5, 9.5, ['color' => '#555555']),
                $k('name', 50, 45, 44, ['font' => 'script', 'color' => '#14213f', 'max_width' => 200]),
                $t($para, 50, 58.5, 12, ['wrap' => true, 'max_width' => 185, 'color' => '#333333']),
                $k('date', 50, 69.5, 11, ['color' => '#333333']),
            ], $sig(27, 73, 'Amin Kolahdooz', 'Europe Chapter Chair', '#14213f'), $sig(73, 73, PRESIDENT_NAME, 'RARL President', '#14213f'), [
                $qr(9.5, 74.5, 16),
                $t('No. {cert_no}', 9.5, 83.6, 6.5, ['align' => 'left', 'color' => '#666666']),
            ])],
        'tech-blue' => ['name' => 'Tech Blue — Webinar', 'type' => 'certificate', 'bg' => 'cert-tech-blue.jpg', 'w' => 297, 'h' => 210,
            'config' => array_merge([
                $t('INTERNATIONAL WEBINAR', 50, 20.5, 15, ['bold' => true, 'color' => '#0d1b33']),
                $t('CERTIFICATE', 50, 25.5, 50, ['bold' => true, 'color' => '#e1120b']),
                $t('OF PARTICIPATION', 50, 36, 16, ['bold' => true, 'color' => '#0d1b33']),
                $t('THIS IS TO CERTIFY THAT', 50, 42.5, 9.5, ['color' => '#3b4a63']),
                $k('name', 50, 46.5, 32, ['bold' => true, 'color' => '#0d1b33', 'max_width' => 200]),
                $t('attended the webinar {event}, organised by the Robotics & Automation Research Lab (RARL), UK.', 50, 56, 11.5, ['wrap' => true, 'max_width' => 185, 'color' => '#24324d']),
                $k('date', 50, 65.2, 10.5, ['color' => '#24324d']),
            ], $sig(32, 68.5, 'Amin Kolahdooz', 'Europe Chapter Chair', '#0d1b33'), $sig(68, 68.5, PRESIDENT_NAME, 'RARL President', '#0d1b33'), [
                $qr(4.5, 55, 19),
                $t('{cert_no}', 4.5, 64.6, 6.5, ['align' => 'left', 'color' => '#3b4a63']),
            ])],
        'modern-minimal' => ['name' => 'Modern Minimal — Appreciation', 'type' => 'certificate', 'bg' => 'cert-modern-minimal.jpg', 'w' => 297, 'h' => 210,
            'config' => array_merge([
                $t('CERTIFICATE', 30, 25, 50, ['bold' => true, 'align' => 'left']),
                $t('OF APPRECIATION', 30, 35.5, 18, ['bold' => true, 'align' => 'left', 'color' => '#CC0703']),
                $t('Presented to', 30, 44, 11, ['align' => 'left', 'color' => '#555555']),
                $k('name', 30, 48, 34, ['bold' => true, 'align' => 'left', 'max_width' => 190]),
                $t('in recognition of an outstanding contribution to {event}, organised by the Robotics & Automation Research Lab (RARL), UK.', 30, 59, 12, ['wrap' => true, 'max_width' => 175, 'align' => 'left', 'color' => '#333333']),
                $k('date', 30, 71, 11, ['align' => 'left', 'color' => '#333333']),
            ], $sig(44, 76, 'Amin Kolahdooz', 'Europe Chapter Chair'), $sig(74, 76, PRESIDENT_NAME, 'RARL President'), [
                $qr(88.5, 74, 20),
                $t('{cert_no}', 95.2, 84, 6.5, ['align' => 'right', 'color' => '#666666']),
            ])],
        'membership-gold' => ['name' => 'Gold Frame — Membership', 'type' => 'membership', 'bg' => 'cert-membership-gold.jpg', 'w' => 297, 'h' => 210, 'default' => true,
            'config' => [
                $t('Certificate of Membership', 50, 24, 40, ['font' => 'playfair', 'color' => '#111111']),
                $t('THIS CERTIFIES THAT', 50, 36, 10, ['color' => '#a77c25', 'bold' => true]),
                $k('name', 50, 39.5, 46, ['font' => 'script', 'max_width' => 200]),
                $t('is a recognised member of the Robotics & Automation Research Lab (RARL), UK, and is entitled to use the affiliation "Robotics & Automation Research Lab (RARL), UK" in scholarly work.', 50, 54, 12, ['wrap' => true, 'max_width' => 190, 'color' => '#333333']),
                $t('Member since {since_date}  ·  Membership No. {member_code}', 50, 67, 10.5, ['color' => '#333333']),
                $t('{signer1}', 30, 72, 24, ['font' => 'script']),
                $t('{signer1}', 30, 79, 10, ['bold' => true]),
                $t('President, RARL', 30, 82.8, 8.5, ['color' => '#555555']),
                $qr(46.6, 72.5, 18),
                $t('Certificate {cert_no}', 50, 82.6, 6.5, ['color' => '#666666']),
            ]],
        'id-red' => ['name' => 'Red Gradient — ID card', 'type' => 'id_card', 'bg' => 'id-red-gradient.jpg', 'w' => 85.6, 'h' => 54, 'default' => true,
            'config' => [
                ['key' => 'avatar', 'x' => 5, 'y' => 28, 'w' => 21, 'h' => 25.5],
                $t('MEMBER', 34, 29, 5.5, ['align' => 'left', 'color' => '#ffd7d4', 'bold' => true]),
                $k('name', 34, 34, 10.5, ['align' => 'left', 'bold' => true, 'color' => '#ffffff', 'max_width' => 32]),
                $k('member_code', 34, 46, 8, ['align' => 'left', 'color' => '#ffffff', 'font' => 'courier', 'bold' => true]),
                $t('Since {since_date}', 34, 55, 6, ['align' => 'left', 'color' => '#ffe3e1']),
                $k('section', 34, 61.5, 6, ['align' => 'left', 'color' => '#ffe3e1', 'max_width' => 32]),
                $qr(74, 28, 17.5),
                $t('SCAN TO VERIFY', 84.2, 63.5, 4.5, ['color' => '#ffffff', 'bold' => true]),
                $t('rarl-lab.com', 50, 94.5, 5, ['color' => '#ffffff']),
            ]],
        'id-white' => ['name' => 'Clean White — ID card', 'type' => 'id_card', 'bg' => 'id-clean-white.jpg', 'w' => 85.6, 'h' => 54,
            'config' => [
                ['key' => 'avatar', 'x' => 5, 'y' => 30, 'w' => 20, 'h' => 24.5],
                $t('MEMBER', 33, 31, 5.5, ['align' => 'left', 'color' => '#CC0703', 'bold' => true]),
                $k('name', 33, 36, 10.5, ['align' => 'left', 'bold' => true, 'max_width' => 33]),
                $k('member_code', 33, 48, 8, ['align' => 'left', 'color' => '#CC0703', 'font' => 'courier', 'bold' => true]),
                $t('Since {since_date}', 33, 57, 6, ['align' => 'left', 'color' => '#444444']),
                $k('section', 33, 63.5, 6, ['align' => 'left', 'color' => '#444444', 'max_width' => 33]),
                $qr(75, 31, 16.5),
                $t('SCAN TO VERIFY', 84.6, 64.5, 4.5, ['color' => '#444444', 'bold' => true]),
            ]],
    ];
}

/**
 * Copies a premade design into uploads/templates and inserts it. Returns the new id,
 * or 0 if the background file is missing. A design becomes the default for its type
 * only when that type has no default yet (or $makeDefault is true).
 */
function installPremadeTemplate(string $slug, bool $makeDefault = false): int {
    $d = premadeTemplates()[$slug] ?? null;
    if (!$d) return 0;
    $src = __DIR__ . '/assets/templates/' . $d['bg'];
    if (!is_file($src)) return 0;
    $dir = UPLOADS_PATH . '/templates';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = 'premade-' . pathinfo($d['bg'], PATHINFO_FILENAME) . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.jpg';
    if (!copy($src, "$dir/$file")) return 0;
    $pdo = db();
    $hasDefault = (bool)$pdo->query("SELECT 1 FROM certificate_templates WHERE type = " . $pdo->quote($d['type']) . " AND is_default = 1")->fetchColumn();
    $default = $makeDefault || (!$hasDefault && !empty($d['default']));
    if ($default) $pdo->prepare('UPDATE certificate_templates SET is_default = 0 WHERE type = ?')->execute([$d['type']]);
    $pdo->prepare('INSERT INTO certificate_templates (name, type, background_path, config, page_width_mm, page_height_mm, is_default) VALUES (?,?,?,?,?,?,?)')
        ->execute([$d['name'], $d['type'], $file, json_encode($d['config']), $d['w'], $d['h'], $default ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

/** Adds any premade design not installed yet (tracked by name). Run once per deploy. */
function seedPremadeTemplates(): array {
    $added = [];
    try {
        $have = db()->query('SELECT name FROM certificate_templates')->fetchAll(PDO::FETCH_COLUMN);
        $seeded = json_decode(setting('premade_templates_seeded', '[]'), true) ?: [];
        foreach (premadeTemplates() as $slug => $d) {
            if (in_array($slug, $seeded, true) || in_array($d['name'], $have, true)) continue;
            if (installPremadeTemplate($slug)) $added[] = $d['name'];
            $seeded[] = $slug;
        }
        settingSet('premade_templates_seeded', json_encode(array_values(array_unique($seeded))));
    } catch (Throwable $e) { error_log('premade templates: ' . $e->getMessage()); }
    return $added;
}
