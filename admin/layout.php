<?php
/**
 * RARL Admin — Shared Layout Helper
 * Provides htmlAdminHead() and adminSidebar()
 */
function htmlAdminHead(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>' . htmlspecialchars($title) . ' — RARL Admin</title>
<meta name="robots" content="noindex,nofollow"/>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:' . brandTailwindConfigJson() . ',fontFamily:{heading:["' . BRAND_FONT_SANS . '","system-ui","sans-serif"]}}}}</script>
<link href="' . BRAND_FONT_GOOGLE_URL . '" rel="stylesheet"/>
<style>body{font-family:"' . BRAND_FONT_SANS . '",sans-serif;}h1,h2,h3,h4{font-family:"' . BRAND_FONT_SANS . '",sans-serif;}</style>
</head><body class="bg-gray-100 text-gray-900 min-h-screen">';
}

function adminSidebar(string $active = ''): void {
    $nav = [
        'index'       => ['index.php',       '📊', 'Dashboard'],
        'members'     => ['members.php',      '👥', 'Members'],
        'events'      => ['events.php',       '📅', 'Events'],
        'certificates'=> ['certificates.php', '🏆', 'Certificates'],
        'newsletter'  => ['newsletter.php',   '📧', 'Newsletter'],
        'resources'   => ['resources.php',    '📚', 'Resources'],
        'community'   => ['community.php',    '💬', 'Community'],
        'partnerships'=> ['partnerships.php',  '🤝', 'Partnerships'],
        'plans'       => ['plans.php',        '🎓', 'Plans'],
        'sections'    => ['sections.php',     '🌍', 'Sections'],
        'people'      => ['people.php',       '🧑‍🤝‍🧑', 'People'],
        'settings'    => ['settings.php',     '⚙️', 'Settings'],
    ];
    echo '<div id="admin-backdrop" onclick="toggleAdminSidebar()" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden"></div>
    <aside id="admin-sidebar" class="w-64 sm:w-56 flex-shrink-0 bg-rarl-navy text-white flex flex-col min-h-screen fixed top-0 left-0 z-40 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-200">
    <div class="p-4 border-b border-white/10 flex items-center justify-between">
      <div class="flex items-center gap-2 min-w-0">
        <img src="' . BRAND_MARK_PATH . '" alt="RARL" class="w-8 h-8 rounded-lg object-contain flex-shrink-0"/>
        <div class="min-w-0"><div class="font-heading font-bold text-xs leading-tight truncate">RARL Admin</div><div class="text-white/35 text-[9px] truncate">Community Platform</div></div>
      </div>
      <button type="button" onclick="toggleAdminSidebar()" class="md:hidden w-8 h-8 flex items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white flex-shrink-0" aria-label="Close menu">✕</button>
    </div>
    <nav class="flex-1 p-3 flex flex-col gap-0.5">';
    foreach ($nav as $key => [$href, $icon, $label]) {
        $cls = $key === $active
            ? 'bg-white/15 text-white font-semibold'
            : 'text-white/55 hover:bg-white/10 hover:text-white';
        echo '<a href="' . $href . '" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs transition-colors ' . $cls . '">' . $icon . ' ' . $label . '</a>';
    }
    echo '</nav>
    <div class="p-3 border-t border-white/10 space-y-0.5">
      <a href="../index.php" target="_blank" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs text-white/40 hover:bg-white/10 hover:text-white transition-colors">🌐 Public Site</a>
      <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs text-white/40 hover:bg-white/10 hover:text-white transition-colors">🚪 Logout</a>
    </div>
    </aside>
    <script>
      function toggleAdminSidebar() {
        document.getElementById("admin-sidebar").classList.toggle("-translate-x-full");
        document.getElementById("admin-backdrop").classList.toggle("hidden");
      }
    </script>';
}

function adminWrap(callable $content, string $page = '', string $title = ''): void {
    htmlAdminHead($title);
    echo '<div class="flex min-h-screen">';
    adminSidebar($page);
    echo '<main class="flex-1 md:ml-56 min-w-0">';
    echo '<div class="md:hidden sticky top-0 z-20 bg-white border-b border-gray-200 px-4 h-14 flex items-center gap-3">
      <button type="button" onclick="toggleAdminSidebar()" class="w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100" aria-label="Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span class="font-heading font-bold text-sm text-gray-800">RARL Admin</span>
    </div>';
    echo '<div class="p-4 sm:p-7 overflow-auto">';
    $content();
    echo '</div></main></div></body></html>';
}

function statCard(string $label, int|string $val, string $icon, string $color, string $href = ''): void {
    $colors = [
        'blue'   => ['bg-blue-50 border-blue-200',   'text-blue-600',   'bg-blue-100'],
        'green'  => ['bg-green-50 border-green-200',  'text-green-600',  'bg-green-100'],
        'amber'  => ['bg-amber-50 border-amber-200',  'text-amber-600',  'bg-amber-100'],
        'red'    => ['bg-red-50 border-red-200',      'text-red-600',    'bg-red-100'],
        'purple' => ['bg-purple-50 border-purple-200','text-purple-600', 'bg-purple-100'],
        'gray'   => ['bg-gray-50 border-gray-200',    'text-gray-600',   'bg-gray-100'],
    ];
    [$bg, $tc, $iconBg] = $colors[$color] ?? $colors['gray'];
    $tag = $href ? "a href=\"{$href}\"" : 'div';
    $cls = $href ? 'hover:-translate-y-0.5 hover:shadow-md cursor-pointer' : '';
    echo "<{$tag} class=\"block bg-white border rounded-2xl p-5 shadow-sm transition-all {$bg} {$cls}\">
    <div class=\"flex items-center justify-between mb-2\">
      <div class=\"w-10 h-10 {$iconBg} rounded-xl flex items-center justify-center text-lg\">{$icon}</div>
    </div>
    <div class=\"font-heading font-black text-2xl text-gray-900\">{$val}</div>
    <div class=\"text-xs {$tc} font-semibold mt-1\">{$label}</div>
</" . ($href ? 'a' : 'div') . ">";
}

function adminFlash(): void {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return;
    $cls = $f['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-700';
    $ic  = $f['type'] === 'success' ? '✅' : '⚠️';
    echo "<div class=\"flex items-center gap-3 p-4 rounded-xl border mb-5 text-sm {$cls}\">{$ic} " . htmlspecialchars($f['msg']) . "</div>";
}
