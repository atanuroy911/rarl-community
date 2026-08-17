<?php
/**
 * Robotics & Automation Research Lab (RARL) Platform — Brand Constants (single source of truth)
 * Colors/fonts/logo paths match rarl-lab.com. Consumed by functions.php
 * (public site), admin/layout.php + admin/login.php (admin), and emails/*.php.
 */
define('BRAND_RED',       '#CC0703');
define('BRAND_RED_DARK',  '#A80502');
define('BRAND_BLUE',      '#1273EB');
define('BRAND_BLUE_DARK', '#0E5ECB');
define('BRAND_INK',       '#101010');
define('BRAND_INK_SOFT',  '#1a1a1a');
define('BRAND_WHITE',     '#FFFFFF');

// Inter for body copy (on-screen UI legibility at small sizes); Sora for headings
// (geometric, slightly more distinctive — gives the academic/research brand a
// stronger identity in H1-H3 without hurting small-size legibility elsewhere).
define('BRAND_FONT_SANS',       'Inter');
define('BRAND_FONT_HEADING',    'Sora');
define('BRAND_FONT_GOOGLE_URL', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Sora:wght@500;600;700;800&display=swap');

// FontAwesome — swapped in for emoji icons across the site (see rarlIconCss()/rarlFaCdn()).
define('FONTAWESOME_CDN_URL', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css');

define('BRAND_LOGO_PATH', '/assets/logo.png'); // full lockup: icon + "RARL" wordmark + tagline
define('BRAND_MARK_PATH', '/assets/mark.png'); // square icon only — nav, sidebar, login cards, certs

// Tailwind color keys stay the same across the app (rarl-red, rarl-navy, ...) so that
// every existing bg-rarl-red / text-rarl-navy class is re-themed just by changing these
// values here — no need to touch class names across ~15 consumer files.
function brandTailwindColors(): array {
    return [
        'rarl-red'      => BRAND_RED,
        'rarl-dark'     => BRAND_RED_DARK,
        'rarl-blue'     => BRAND_BLUE,
        'rarl-blue-dark'=> BRAND_BLUE_DARK,
        'rarl-navy'     => BRAND_INK,
        'rarl-mid'      => BRAND_INK_SOFT,
    ];
}
function brandTailwindConfigJson(): string {
    return json_encode(brandTailwindColors(), JSON_UNESCAPED_SLASHES);
}

// FPDF (used for certificates) takes 0-255 RGB triples, not hex — bridge from the
// single brand hex source instead of re-hardcoding RGB triples separately.
function brandRgb(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}
