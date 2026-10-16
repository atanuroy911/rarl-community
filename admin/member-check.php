<?php
/**
 * RARL Admin — Check a list
 * Paste any list (emails, names, CSV, copied spreadsheet rows) and see who is
 * already registered. Matches by email first, then by name (titles ignored).
 * POST json=1 returns matches for the certificate paste preview.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

function mcNameKey(string $s): string {
    $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
    $s = preg_replace('/\b(dr|prof|mr|mrs|ms|miss|engr|eng)\b\.?/u', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Parses pasted text into [['line','name','email']]. */
function mcParse(string $text): array {
    $out = []; $seen = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $email = preg_match('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $line, $m) ? strtolower($m[0]) : '';
        $rest = $email ? str_ireplace($m[0], ' ', $line) : $line;
        // First non-empty cell that looks like a name.
        $name = '';
        foreach (preg_split('/[,;\t|<>]+/', $rest) as $cell) {
            $cell = trim($cell, " \"'()");
            if ($cell !== '' && preg_match('/\p{L}/u', $cell) && !preg_match('/^(name|full name|email|e-mail)$/i', $cell)) { $name = $cell; break; }
        }
        if (!$email && !$name) continue;
        $key = $email ?: 'n:' . mcNameKey($name);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['line' => $line, 'name' => $name, 'email' => $email];
        if (count($out) >= 5000) break;
    }
    return $out;
}

function mcMatch(PDO $pdo, array $rows): array {
    $byEmail = []; $byName = [];
    foreach ($pdo->query('SELECT id, email, full_name, status FROM members WHERE ' . REAL_MEMBER_SQL) as $m) {
        $byEmail[strtolower($m['email'])] = $m;
        $k = mcNameKey($m['full_name'] ?? '');
        if ($k !== '') $byName[$k][] = $m;
    }
    foreach ($rows as &$r) {
        $r['member'] = null; $r['how'] = '';
        if ($r['email'] && isset($byEmail[$r['email']])) { $r['member'] = $byEmail[$r['email']]; $r['how'] = 'email'; }
        elseif ($r['name'] && count($byName[mcNameKey($r['name'])] ?? []) === 1) { $r['member'] = $byName[mcNameKey($r['name'])][0]; $r['how'] = 'name'; }
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!adminCsrfOk()) { http_response_code(400); exit('Session expired. Reload the page.'); }
    $rows = mcMatch($pdo, mcParse((string)($_POST['list'] ?? '')));
    if (!empty($_POST['json'])) {
        header('Content-Type: application/json');
        echo json_encode(array_map(fn($r) => ['email' => $r['email'], 'name' => $r['name'], 'status' => $r['member']['status'] ?? null, 'how' => $r['how']], $rows));
        exit;
    }
    if (($_POST['export'] ?? '') !== '') {
        $want = $_POST['export'];
        $fname = 'list-check-' . $want . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $f = fopen('php://output', 'w');
        fputcsv($f, ['name', 'email', 'result', 'member_status']);
        foreach ($rows as $r) {
            $is = (bool)$r['member'];
            if ($want === 'members' && !$is || $want === 'not' && $is) continue;
            fputcsv($f, [$r['name'], $r['email'], $is ? 'Member (matched by ' . $r['how'] . ')' : 'Not registered', $r['member']['status'] ?? '']);
        }
        exit;
    }
    $_SESSION['mc_result'] = ['list' => (string)$_POST['list'], 'rows' => $rows];
    header('Location: member-check.php#result'); exit;
}

$result = $_SESSION['mc_result'] ?? null;
unset($_SESSION['mc_result']);

adminWrap(function () use ($result) {
    $rows = $result['rows'] ?? [];
    $count = fn($fn) => count(array_filter($rows, $fn));
    $nActive  = $count(fn($r) => ($r['member']['status'] ?? '') === 'active');
    $nOther   = $count(fn($r) => $r['member'] && $r['member']['status'] !== 'active');
    $nNot     = $count(fn($r) => !$r['member']);
    $statusPill = ['active' => 'bg-green-50 text-green-700', 'pending' => 'bg-amber-50 text-amber-700', 'inactive' => 'bg-gray-100 text-gray-600'];
?>
<h1 class="font-heading font-bold text-2xl text-gray-900">Check a list</h1>
<p class="text-gray-500 text-sm mb-6">Paste a list of people (emails, names, or rows copied from a spreadsheet) to see who is already a RARL member.</p>

<form method="post" class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm mb-6 max-w-3xl" id="mc-form">
  <?= acsrfField() ?>
  <label for="mc-list" class="block text-sm font-semibold text-gray-800 mb-1.5">People to check</label>
  <textarea id="mc-list" name="list" rows="9" required placeholder="Jane Smith, jane@uni.edu&#10;john.doe@example.com&#10;Dr. Ali Khan" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red resize-y"><?= ex($result['list'] ?? '') ?></textarea>
  <p class="text-xs text-gray-500 mt-1.5">One person per line. Emails give exact matches; a name alone matches only when exactly one member has that name.</p>
  <button type="submit" class="mt-4 px-5 min-h-[44px] bg-rarl-red hover:bg-rarl-dark text-white text-sm font-bold rounded-xl"><i class="fa-solid fa-magnifying-glass"></i> Check list</button>
</form>

<?php if ($result): ?>
<section id="result" class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
  <div class="p-5 border-b border-gray-100 flex flex-wrap items-center gap-2">
    <span class="px-3 py-1 rounded-full bg-green-50 text-green-700 text-sm font-semibold"><?= $nActive ?> active member<?= $nActive === 1 ? '' : 's' ?></span>
    <?php if ($nOther): ?><span class="px-3 py-1 rounded-full bg-amber-50 text-amber-700 text-sm font-semibold"><?= $nOther ?> registered, not active</span><?php endif; ?>
    <span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-sm font-semibold"><?= $nNot ?> not registered</span>
    <span class="text-sm text-gray-500">of <?= count($rows) ?></span>
    <div class="ml-auto flex flex-wrap gap-2">
      <?php if ($nNot): $notEmails = implode(', ', array_filter(array_map(fn($r) => $r['member'] ? '' : $r['email'], $rows))); ?>
      <?php if ($notEmails): ?><button type="button" class="rarl-btn rarl-btn-sm" data-copy="<?= ex($notEmails) ?>"><i class="fa-solid fa-copy"></i> Copy non-member emails</button><?php endif; ?>
      <?php endif; ?>
      <?php foreach (['not' => 'Non-members CSV', 'all' => 'Full CSV'] as $k => $lbl): ?>
      <form method="post" class="contents"><?= acsrfField() ?><input type="hidden" name="list" value="<?= ex($result['list']) ?>"><input type="hidden" name="export" value="<?= $k ?>">
        <button class="rarl-btn rarl-btn-sm" data-no-loading><i class="fa-solid fa-file-csv"></i> <?= $lbl ?></button></form>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500"><tr><th class="px-4 py-2.5">Name</th><th class="px-4 py-2.5">Email</th><th class="px-4 py-2.5">Result</th></tr></thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($rows as $r): $m = $r['member']; ?>
      <tr class="<?= $m ? '' : 'bg-red-50/40' ?>">
        <td class="px-4 py-2.5 text-gray-900"><?= ex($r['name'] ?: '—') ?><?php if ($m && $r['how'] === 'email' && $m['full_name'] && mcNameKey($m['full_name']) !== mcNameKey($r['name'])): ?><span class="block text-xs text-gray-500">Registered as <?= ex($m['full_name']) ?></span><?php endif; ?></td>
        <td class="px-4 py-2.5 text-gray-600"><?= ex($r['email'] ?: ($m['email'] ?? '—')) ?></td>
        <td class="px-4 py-2.5">
          <?php if ($m): ?>
          <a href="members.php?q=<?= urlencode($m['email']) ?>" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $statusPill[$m['status']] ?? '' ?>"><i class="fa-solid fa-check"></i> <?= ucfirst($m['status']) ?> member</a>
          <?php if ($r['how'] === 'name'): ?><span class="text-xs text-gray-500 ml-1">matched by name</span><?php endif; ?>
          <?php else: ?><span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700"><i class="fa-solid fa-xmark"></i> Not registered</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<script>
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(b.dataset.copy); rarlToast('Copied', 'success'); } catch (e) { rarlToast('Could not copy', 'error'); }
}));
</script>
<?php endif; ?>
<?php }, 'member-check', 'Check a list');
