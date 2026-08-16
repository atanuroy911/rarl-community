<?php
/**
 * RARL Admin — Bulk Member Import (CSV / Excel)
 * Three-step flow:
 *   1. Upload  → file is parsed, raw header + rows stashed in session.
 *   2. Map     → every column in the file gets shown next to a dropdown of
 *      system fields, pre-guessed by header-name matching (including a
 *      Membership Code field) — admin confirms or changes each one before
 *      anything is read as data.
 *   3. Review  → rows matching an existing member by email and/or name are
 *      flagged as conflicts with an explicit Merge/Skip choice; everything
 *      else creates outright. Nothing touches the database until "Confirm & Apply."
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

// Internal field => [label, auto-detect header keywords (lowercased, substring match)]
$FIELDS = [
    'full_name'          => ['Full Name (required)', ['full name', 'name']],
    'email'              => ['Email Address (required)', ['email']],
    'member_code'        => ['Membership Code / ID', ['member', 'membership code', 'member id', 'member code']],
    'country'            => ['Country', ['country']],
    'city_state'         => ['City / State', ['city', 'state', 'province']],
    'position'           => ['Designation / Position', ['designation', 'position']],
    'institution'        => ['Institution', ['university', 'institution']],
    'primary_lab_name'   => ['Primary Lab Name', ['lab name', 'primary lab']],
    'cv_url'             => ['CV / Resume Link', ['cv', 'resume']],
    'google_scholar_url' => ['Google Scholar URL', ['scholar']],
    'orcid_id'           => ['ORCID', ['orcid']],
    'research_interests' => ['Research Interests', ['research interest']],
    'years_experience'   => ['Years of Experience', ['years of', 'experience']],
    'referral_source'    => ['Referral Source', ['hear about', 'referral']],
    ''                   => ['— Ignore this column —', []],
];
$PROFILE_FIELDS = ['country','city_state','position','institution','primary_lab_name','cv_url','google_scholar_url','orcid_id','research_interests','years_experience','referral_source'];

$errorMsg = null;
$report = null;

// ── Step 1: upload → stash raw header/rows, go to mapping ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'upload' && !empty($_FILES['import_file']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
    $rows = $ext === 'xlsx' ? parseXlsxToRows($_FILES['import_file']['tmp_name']) : array_map('str_getcsv', file($_FILES['import_file']['tmp_name']));
    if (empty($rows)) {
        $errorMsg = 'Could not read that file — upload a .csv or .xlsx with a header row.';
    } else {
        $header = array_shift($rows);
        $_SESSION['import_raw'] = ['header' => $header, 'rows' => array_slice($rows, 0, 2000)];
        header('Location: import-members.php?step=map'); exit;
    }
}

// ── Step 2: mapping submitted → resolve rows, split create/conflict, go to review ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'apply_mapping') {
    $raw = $_SESSION['import_raw'] ?? null;
    if (!$raw) { header('Location: import-members.php'); exit; }
    $map = $_POST['map'] ?? []; // column index => field key

    $emailCol = array_search('email', $map, true);
    $nameCol  = array_search('full_name', $map, true);
    if ($emailCol === false || $nameCol === false) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Map at least one column each to Full Name and Email Address.'];
        header('Location: import-members.php?step=map'); exit;
    }

    $toCreate = []; $conflicts = []; $invalidCount = 0;
    foreach ($raw['rows'] as $row) {
        $get = function(string $field) use ($map, $row) {
            $idx = array_search($field, $map, true);
            return $idx !== false ? clean($row[$idx] ?? '') : '';
        };
        $email = cleanEmail($get('email'));
        $name  = $get('full_name');
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalidCount++; continue; }

        $yearsExp = $get('years_experience'); if (!array_key_exists($yearsExp, YEARS_OPTIONS)) $yearsExp = '';
        $referral = $get('referral_source');  if (!array_key_exists($referral, REFERRAL_OPTIONS)) $referral = '';
        $data = [
            'email' => $email, 'full_name' => $name, 'member_code' => ltrim(strtoupper(trim($get('member_code'))), '#'),
            'country' => $get('country'), 'city_state' => $get('city_state'), 'position' => $get('position'),
            'institution' => $get('institution'), 'primary_lab_name' => $get('primary_lab_name'),
            'cv_url' => cleanUrl($get('cv_url')),
            'google_scholar_url' => cleanUrl($get('google_scholar_url')),
            'orcid_id' => $get('orcid_id'), 'research_interests' => $get('research_interests'),
            'years_experience' => $yearsExp, 'referral_source' => $referral,
        ];

        $byEmail = $pdo->prepare('SELECT m.id FROM members m LEFT JOIN member_emails me ON me.member_id=m.id WHERE m.email=? OR me.email=? LIMIT 1');
        $byEmail->execute([$email, $email]);
        $existingId = $byEmail->fetchColumn();
        $matchType = $existingId ? 'email' : null;

        if (!$existingId) {
            $byName = $pdo->prepare("SELECT id FROM members WHERE type='individual' AND LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1");
            $byName->execute([$name]);
            $existingId = $byName->fetchColumn();
            if ($existingId) $matchType = 'name';
        }
        if (!$existingId && $data['member_code'] !== '') {
            $byCode = $pdo->prepare('SELECT id FROM members WHERE member_code = ? LIMIT 1');
            $byCode->execute([$data['member_code']]);
            $existingId = $byCode->fetchColumn();
            if ($existingId) $matchType = 'member_code';
        }

        if ($existingId) {
            $ex = $pdo->prepare('SELECT id, full_name, email, institution, country, member_code FROM members WHERE id = ?');
            $ex->execute([$existingId]);
            $conflicts[] = ['incoming' => $data, 'existing' => $ex->fetch(), 'match_type' => $matchType];
        } else {
            $toCreate[] = $data;
        }
    }

    $_SESSION['import_pending'] = ['toCreate' => $toCreate, 'conflicts' => $conflicts, 'invalid' => $invalidCount];
    unset($_SESSION['import_raw']);
    header('Location: import-members.php?step=confirm'); exit;
}

// ── Step 3: apply — create clean rows, apply each conflict's decision ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'apply_import') {
    $pending = $_SESSION['import_pending'] ?? null;
    if (!$pending) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Import session expired — please upload the file again.'];
        header('Location: import-members.php'); exit;
    }

    $freePlanId = (int)$pdo->query("SELECT id FROM membership_plans WHERE slug = 'free' LIMIT 1")->fetchColumn() ?: null;
    $created = 0; $merged = 0; $skipped = (int)$pending['invalid'];

    foreach ($pending['toCreate'] as $data) {
        // Respect an imported Membership Code if given and free; otherwise auto-generate as usual.
        $memberCode = $data['member_code'];
        if ($memberCode !== '') {
            $taken = $pdo->prepare('SELECT id FROM members WHERE member_code = ?'); $taken->execute([$memberCode]);
            if ($taken->fetch()) $memberCode = ''; // already used — fall back to auto-generation rather than erroring the whole row out
        }
        if ($memberCode === '') $memberCode = nextMemberCode();

        $temp = bin2hex(random_bytes(5));
        $uuid = generateUuid();
        $pdo->prepare(
            'INSERT INTO members (uuid, type, email, password_hash, must_change_password, status, full_name, member_code, country, city_state,
              position, institution, primary_lab_name, cv_url, google_scholar_url, orcid_id, research_interests, years_experience,
              referral_source, plan_id, email_verified_at, unsubscribe_token)
             VALUES (?,"individual",?,?,1,"active",?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)'
        )->execute([
            $uuid, $data['email'], password_hash($temp, PASSWORD_BCRYPT), $data['full_name'], $memberCode, $data['country'], $data['city_state'],
            $data['position'], $data['institution'], $data['primary_lab_name'], $data['cv_url'] ?: null, $data['google_scholar_url'],
            $data['orcid_id'], $data['research_interests'], $data['years_experience'] ?: null, $data['referral_source'] ?: null,
            $freePlanId, bin2hex(random_bytes(32)),
        ]);
        $memberId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO member_emails (member_id, email, is_primary, verified_at) VALUES (?,?,1,NOW())')->execute([$memberId, $data['email']]);

        $memberName = $data['full_name']; $tempPassword = $temp;
        ob_start(); require dirname(__DIR__) . '/emails/admin-temp-password.php'; $body = ob_get_clean();
        sendEmail($data['email'], $memberName, 'Your Robotics & Automation Research Lab (RARL) account is ready', $body);
        $created++;
    }

    foreach ($pending['conflicts'] as $i => $c) {
        $decision = $_POST['decision'][$i] ?? 'skip';
        if ($decision !== 'merge') { $skipped++; continue; }

        $data = $c['incoming'];
        $existingId = (int)$c['existing']['id'];

        $sets = []; $params = [];
        foreach ($PROFILE_FIELDS as $field) {
            if ($data[$field] !== '') { $sets[] = "{$field} = ?"; $params[] = $data[$field]; }
        }
        // Only set member_code if the existing record doesn't already have one
        // (never silently overwrite an already-issued ID) and it's not taken elsewhere.
        if ($data['member_code'] !== '' && empty($c['existing']['member_code'])) {
            $taken = $pdo->prepare('SELECT id FROM members WHERE member_code = ? AND id != ?'); $taken->execute([$data['member_code'], $existingId]);
            if (!$taken->fetch()) { $sets[] = 'member_code = ?'; $params[] = $data['member_code']; }
        }
        if ($sets) {
            $params[] = $existingId;
            $pdo->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }

        if ($c['match_type'] === 'name' && strcasecmp($data['email'], $c['existing']['email']) !== 0) {
            $dupe = $pdo->prepare('SELECT id FROM member_emails WHERE email = ?');
            $dupe->execute([$data['email']]);
            if (!$dupe->fetch()) {
                $pdo->prepare('INSERT INTO member_emails (member_id, email, label, verified_at) VALUES (?,?,"Imported",NOW())')
                    ->execute([$existingId, $data['email']]);
            }
        }
        $merged++;
    }

    unset($_SESSION['import_pending']);
    $report = ['created' => $created, 'merged' => $merged, 'skipped' => $skipped];
}

$mapping = ($_GET['step'] ?? '') === 'map' ? ($_SESSION['import_raw'] ?? null) : null;
if (($_GET['step'] ?? '') === 'map' && !$mapping) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Upload a file first.'];
    header('Location: import-members.php'); exit;
}
$pending = ($_GET['step'] ?? '') === 'confirm' ? ($_SESSION['import_pending'] ?? null) : null;
if (($_GET['step'] ?? '') === 'confirm' && !$pending) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Nothing to review — upload a file first.'];
    header('Location: import-members.php'); exit;
}

// Best-guess field for a given file column header, for pre-selecting the mapping dropdown.
$guessField = function(string $header) use ($FIELDS): string {
    $h = strtolower(trim($header));
    foreach ($FIELDS as $field => [$label, $keywords]) {
        foreach ($keywords as $kw) {
            if ($field !== '' && str_contains($h, $kw)) return $field;
        }
    }
    return '';
};

adminWrap(function() use ($report, $errorMsg, $mapping, $pending, $FIELDS, $guessField) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Import Members</h1>
<p class="text-gray-500 text-sm mb-7">Bulk-create or merge accounts from a CSV or Excel file — you'll map its columns to system fields and review any matches before anything is saved.</p>

<?php if ($errorMsg): ?>
<div class="max-w-2xl mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php if ($report): ?>
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mb-6">
  <h2 class="font-heading font-bold text-sm text-gray-800 mb-3">Import Results</h2>
  <p class="text-sm text-gray-700">
    <i class="fa-solid fa-circle-check text-green-600"></i> Created <strong><?= $report['created'] ?></strong> ·
    <i class="fa-solid fa-code-merge text-blue-600"></i> Merged <strong><?= $report['merged'] ?></strong> ·
    <i class="fa-solid fa-forward text-gray-400"></i> Skipped <strong><?= $report['skipped'] ?></strong>
  </p>
</div>
<?php endif; ?>

<?php if ($mapping): ?>
<!-- ── Step 2: map columns ── -->
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
  <h2 class="font-heading font-bold text-base text-gray-800 mb-1">Map Your Columns</h2>
  <p class="text-xs text-gray-400 mb-5">We guessed a mapping for each column below — check it, and change anything that's wrong. Columns set to "Ignore" are not imported.</p>
  <form method="POST" class="space-y-3">
    <?= acsrfField() ?><input type="hidden" name="action" value="apply_mapping">
    <?php foreach ($mapping['header'] as $i => $colName): $guess = $guessField($colName); ?>
    <div class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-xl">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($colName ?: '(blank header)') ?></p>
        <p class="text-[10px] text-gray-400">Column <?= $i + 1 ?></p>
      </div>
      <select name="map[<?= $i ?>]" class="px-3 py-2 border border-gray-300 rounded-lg text-xs bg-white flex-shrink-0 w-56">
        <?php foreach ($FIELDS as $field => [$label, $keywords]): ?>
        <option value="<?= $field ?>" <?= $guess === $field ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5 mt-2"><i class="fa-solid fa-arrow-right"></i> Continue to Review</button>
  </form>
  <a href="import-members.php" class="block text-center text-xs text-gray-400 hover:text-gray-600 mt-3">Cancel and start over</a>
</div>

<?php elseif ($pending): ?>
<!-- ── Step 3: review conflicts ── -->
<div class="max-w-3xl space-y-6">
  <div class="bg-blue-50 border border-blue-200 rounded-2xl p-5 text-sm text-blue-800">
    <strong><?= count($pending['toCreate']) ?></strong> new account(s) will be created as-is.
    <?php if ($pending['invalid']): ?><br><strong><?= $pending['invalid'] ?></strong> row(s) skipped (invalid name/email).<?php endif; ?>
    <?php if ($pending['conflicts']): ?><br><strong><?= count($pending['conflicts']) ?></strong> row(s) matched an existing member — choose below.<?php endif; ?>
  </div>

  <?php if ($pending['conflicts']): ?>
  <form method="POST" class="space-y-4">
    <?= acsrfField() ?><input type="hidden" name="action" value="apply_import">
    <?php foreach ($pending['conflicts'] as $i => $c):
      $ex = $c['existing']; $inc = $c['incoming'];
      $matchLabel = ['email' => 'Email match', 'name' => 'Name match (different email)', 'member_code' => 'Membership Code match'][$c['match_type']] ?? 'Match';
    ?>
    <div class="bg-white border border-amber-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-3 bg-amber-50 border-b border-amber-200">
        <span class="text-xs font-bold text-amber-700 uppercase tracking-wider"><i class="fa-solid fa-triangle-exclamation"></i> <?= $matchLabel ?></span>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">
        <div class="p-4">
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">Existing Member</p>
          <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($ex['full_name'] ?: '(unnamed)') ?></p>
          <p class="text-xs text-gray-500"><?= htmlspecialchars($ex['email']) ?><?= $ex['member_code'] ? ' · #' . htmlspecialchars($ex['member_code']) : '' ?></p>
          <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($ex['institution'] ?: '—') ?> · <?= htmlspecialchars($ex['country'] ?: '—') ?></p>
        </div>
        <div class="p-4">
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">Incoming Row</p>
          <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($inc['full_name']) ?></p>
          <p class="text-xs text-gray-500"><?= htmlspecialchars($inc['email']) ?><?= $inc['member_code'] ? ' · #' . htmlspecialchars($inc['member_code']) : '' ?></p>
          <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($inc['institution'] ?: '—') ?> · <?= htmlspecialchars($inc['country'] ?: '—') ?></p>
        </div>
      </div>
      <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center gap-4">
        <label class="flex items-center gap-1.5 text-xs font-semibold text-blue-700 cursor-pointer">
          <input type="radio" name="decision[<?= $i ?>]" value="merge" checked class="accent-blue-600"/> Merge into existing
        </label>
        <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-500 cursor-pointer">
          <input type="radio" name="decision[<?= $i ?>]" value="skip" class="accent-gray-500"/> Skip this row
        </label>
      </div>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5"><i class="fa-solid fa-check"></i> Confirm & Apply</button>
  </form>
  <?php else: ?>
  <form method="POST">
    <?= acsrfField() ?><input type="hidden" name="action" value="apply_import">
    <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5">No conflicts — Create <?= count($pending['toCreate']) ?> Account(s)</button>
  </form>
  <?php endif; ?>
  <a href="import-members.php" class="block text-center text-xs text-gray-400 hover:text-gray-600">Cancel and start over</a>
</div>

<?php else: ?>
<!-- ── Step 1: upload ── -->
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
  <form method="POST" enctype="multipart/form-data" class="space-y-4">
    <?= acsrfField() ?><input type="hidden" name="action" value="upload">
    <div class="border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-6 text-center relative">
      <div class="text-2xl mb-1"><i class="fa-solid fa-download"></i></div>
      <p class="text-sm text-gray-600 mb-1">Upload <strong>.csv</strong> or <strong>.xlsx</strong></p>
      <p class="text-[11px] text-gray-400">Any column headers — you'll map them to system fields on the next screen</p>
      <input type="file" name="import_file" accept=".csv,.xlsx" required class="absolute inset-0 opacity-0 cursor-pointer w-full h-full"/>
    </div>
    <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5"><i class="fa-solid fa-upload"></i> Upload & Map Columns</button>
  </form>
</div>
<?php endif; ?>
<?php }, 'import', 'Import Members');
