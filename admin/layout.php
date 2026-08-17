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
<script>tailwind.config={darkMode:"class",theme:{extend:{colors:' . brandTailwindConfigJson() . ',fontFamily:{heading:["' . BRAND_FONT_HEADING . '","' . BRAND_FONT_SANS . '","system-ui","sans-serif"]},boxShadow:{card:"0 1px 2px rgba(16,16,16,.04), 0 1px 12px rgba(16,16,16,.05)"}}}}</script>
<link href="' . BRAND_FONT_GOOGLE_URL . '" rel="stylesheet"/>
<link href="' . FONTAWESOME_CDN_URL . '" rel="stylesheet"/>
<style>
  body{font-family:"' . BRAND_FONT_SANS . '",sans-serif;-webkit-font-smoothing:antialiased;}
  h1,h2,h3,h4{font-family:"' . BRAND_FONT_HEADING . '","' . BRAND_FONT_SANS . '",sans-serif;letter-spacing:-0.01em;}
  ' . rarlFontSizeCss() . '
  a,button,[role="button"]{transition:color .15s ease,background-color .15s ease,border-color .15s ease,opacity .15s ease,box-shadow .15s ease;}
  a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:2px solid ' . BRAND_RED . ';outline-offset:2px;border-radius:4px;}
  ::-webkit-scrollbar{width:10px;height:10px;}
  ::-webkit-scrollbar-track{background:transparent;}
  ::-webkit-scrollbar-thumb{background:rgba(120,120,120,.35);border-radius:999px;}
  ::-webkit-scrollbar-thumb:hover{background:rgba(120,120,120,.55);}
</style>
</head><body class="bg-gray-100 text-gray-900 min-h-screen">';
}

function adminSidebar(string $active = ''): void {
    $nav = [
        'index'       => ['index.php',       '<i class="fa-solid fa-chart-simple"></i>', 'Dashboard'],
        'members'     => ['members.php',      '<i class="fa-solid fa-users"></i>', 'Members'],
        'events'      => ['events.php',       '<i class="fa-solid fa-calendar-days"></i>', 'Events'],
        'certificates'=> ['certificates.php', '<i class="fa-solid fa-trophy"></i>', 'Certificates'],
        'templates'   => ['templates.php',    '<i class="fa-solid fa-image"></i>', 'Templates'],
        'newsletter'  => ['newsletter.php',   '<i class="fa-solid fa-envelope"></i>', 'Newsletter'],
        'compose'     => ['compose-email.php','<i class="fa-solid fa-envelope-open-text"></i>', 'Compose Email'],
        'import'      => ['import-members.php','<i class="fa-solid fa-download"></i>', 'Import Members'],
        'resources'   => ['resources.php',    '<i class="fa-solid fa-book"></i>', 'Resources'],
        'community'   => ['community.php',    '<i class="fa-solid fa-comment"></i>', 'Community'],
        'partnerships'=> ['partnerships.php',  '<i class="fa-solid fa-handshake"></i>', 'Partnerships'],
        'plans'       => ['plans.php',        '<i class="fa-solid fa-graduation-cap"></i>', 'Plans'],
        'sections'    => ['sections.php',     '<i class="fa-solid fa-earth-americas"></i>', 'Sections'],
        'people'      => ['people.php',       '<i class="fa-solid fa-people-group"></i>', 'People'],
        'migrate'     => ['migrate.php',      '<i class="fa-solid fa-database"></i>', 'Migrations'],
        'settings'    => ['settings.php',     '<i class="fa-solid fa-gear"></i>', 'Settings'],
    ];
    echo '<div id="admin-backdrop" onclick="toggleAdminSidebar()" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden"></div>
    <aside id="admin-sidebar" class="w-64 sm:w-56 flex-shrink-0 bg-rarl-navy text-white flex flex-col h-screen fixed top-0 left-0 z-40 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-200">
    <div class="p-4 border-b border-white/10 flex items-center justify-between">
      <div class="flex items-center gap-2 min-w-0">
        <img src="' . BRAND_MARK_PATH . '" alt="RARL" class="w-8 h-8 rounded-lg object-contain flex-shrink-0"/>
        <div class="min-w-0"><div class="font-heading font-bold text-xs leading-tight truncate">RARL Admin</div><div class="text-white/35 text-[9px] truncate">Community Platform</div></div>
      </div>
      <button type="button" onclick="toggleAdminSidebar()" class="md:hidden w-8 h-8 flex items-center justify-center rounded-lg text-white/60 hover:bg-white/10 hover:text-white flex-shrink-0" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
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
      <a href="../index.php" target="_blank" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs text-white/40 hover:bg-white/10 hover:text-white transition-colors"><i class="fa-solid fa-earth-americas"></i> Public Site</a>
      <a href="logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-xs text-white/40 hover:bg-white/10 hover:text-white transition-colors"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
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

// ── Reusable bulk-action toolbar (checkbox column + floating action bar) ──
// Usage per list page:
//   1. echo bulkFormOpen(); right before the table (a detached <form id="bulk-form">
//      the checkboxes/buttons point at via form="bulk-form", so it never nests
//      inside the existing per-row single-action <form> elements in each <td>).
//   2. echo bulkBar([...actions]) where each action is either
//      ['label'=>'Delete','op'=>'delete','class'=>'bg-red-600 hover:bg-red-500','confirm'=>'Sure?']
//      or ['label'=>'Export','name'=>'action','value'=>'export_csv','class'=>'...'] for a plain field override.
//   3. Give the <table>'s header row a `<th block start><?= bulkSelectAllCheckbox() th block end and each row
//      `td block <?= bulkRowCheckbox($id) td block end.
//   4. echo bulkBarScript(); once, anywhere after the table.
// The page's own POST handler reads $_POST['ids'] (array) and $_POST['bulk_op'] when action=='bulk'.
// $group distinguishes multiple independent bulk-selection sets on the same
// page (e.g. admin/community.php has separate Posts / Comments / Announcements
// bulk bars) — pass e.g. 'posts' so ids don't collide; omit it for the common
// single-bulk-group-per-page case.
function bulkFormOpen(string $group = '', array $extraFields = []): string {
    $extra = '';
    foreach ($extraFields as $k => $v) $extra .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
    return '<form id="bulk-form' . $group . '" method="POST">' . acsrfField() . '<input type="hidden" name="action" value="bulk"><input type="hidden" name="bulk_op" id="bulk-op' . $group . '">' . $extra . '</form>';
}
function bulkSelectAllCheckbox(string $group = ''): string {
    return '<input type="checkbox" id="select-all' . $group . '" class="accent-rarl-red w-4 h-4"/>';
}
function bulkRowCheckbox(int $id, string $group = ''): string {
    return '<input type="checkbox" class="row-check' . $group . ' accent-rarl-red w-4 h-4" name="ids[]" value="' . $id . '" form="bulk-form' . $group . '"/>';
}
function bulkBar(array $actions, string $group = ''): string {
    $btns = '';
    foreach ($actions as $a) {
        $confirmAttr = !empty($a['confirm'])
            ? " onclick=\"document.getElementById('bulk-op{$group}').value='" . htmlspecialchars($a['op'] ?? '', ENT_QUOTES) . "'; return confirm('" . htmlspecialchars($a['confirm'], ENT_QUOTES) . "');\""
            : (isset($a['op']) ? " onclick=\"document.getElementById('bulk-op{$group}').value='" . htmlspecialchars($a['op'], ENT_QUOTES) . "'\"" : '');
        $nameVal = isset($a['name']) ? ' name="' . htmlspecialchars($a['name']) . '" value="' . htmlspecialchars($a['value'] ?? '') . '"' : '';
        $cls = $a['class'] ?? 'bg-gray-700 hover:bg-gray-600';
        $btns .= '<button type="submit" form="bulk-form' . $group . '"' . $nameVal . $confirmAttr . ' class="px-3 py-1.5 ' . $cls . ' text-xs font-semibold rounded-lg">' . $a['label'] . '</button>';
    }
    return '<div id="bulk-bar' . $group . '" class="hidden sticky top-2 z-20 mb-4 bg-gray-900 text-white rounded-2xl shadow-lg px-5 py-3 flex flex-wrap items-center gap-3">
      <span class="text-sm font-semibold"><span id="bulk-count' . $group . '">0</span> selected</span>
      <div class="flex flex-wrap gap-2 ml-auto">' . $btns . '</div>
    </div>';
}
// Pass every $group used on the page (e.g. bulkBarScript(['', 'comments'])); each
// gets its own independent select-all/checked-count wiring.
function bulkBarScript(array $groups = ['']): string {
    $groupsJson = json_encode($groups);
    return <<<HTML
<script>
  (function() {
    {$groupsJson}.forEach(function(group) {
      const selectAll = document.getElementById('select-all' + group);
      const rowClass = 'row-check' + group;
      const rowChecks = () => Array.from(document.querySelectorAll('.' + rowClass));
      const bulkBarEl = document.getElementById('bulk-bar' + group);
      const bulkCountEl = document.getElementById('bulk-count' + group);
      function syncBulkBar() {
        const checked = rowChecks().filter(c => c.checked);
        if (bulkCountEl) bulkCountEl.textContent = checked.length;
        if (bulkBarEl) bulkBarEl.classList.toggle('hidden', checked.length === 0);
      }
      selectAll?.addEventListener('change', () => { rowChecks().forEach(c => c.checked = selectAll.checked); syncBulkBar(); });
      document.addEventListener('change', (e) => { if (e.target.classList.contains(rowClass)) syncBulkBar(); });
    });
  })();
</script>
HTML;
}

function adminFlash(): void {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return;
    $cls = $f['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-700';
    $ic  = $f['type'] === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-triangle-exclamation"></i>';
    echo "<div class=\"flex items-center gap-3 p-4 rounded-xl border mb-5 text-sm {$cls}\">{$ic} " . htmlspecialchars($f['msg']) . "</div>";
}
