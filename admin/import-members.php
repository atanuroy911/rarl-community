<?php
/**
 * RARL Admin — Bulk Member Import (CSV / Excel)
 * Matches the Google Form export header exactly:
 * Timestamp, Full Name, Email Address, Country, City and State/Province,
 * Current Designation, University / Institution Name, Primary Lab Name,
 * Link to Google Scholar Profile, ORCID, Primary Research Interests,
 * Years of Research Experience, Upload CV / Resume, How did you hear about the RARL community?
 *
 * Two-step flow: upload → preview (rows matching an existing member by email
 * and/or name are flagged as conflicts, everything else creates outright) →
 * confirm, where each conflict gets an explicit Merge/Skip choice before
 * anything touches the database. Non-conflicting rows still create
 * immediately on confirm, same as before.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

// Header (lowercased, trimmed) => members column
$HEADER_MAP = [
    'full name'                                      => 'full_name',
    'email address'                                  => 'email',
    'country'                                        => 'country',
    'city and state/province'                        => 'city_state',
    'current designation'                             => 'position',
    'university / institution name'                   => 'institution',
    'primary lab name'                                => 'primary_lab_name',
    'link to google scholar profile'                  => 'google_scholar_url',
    'orcid'                                           => 'orcid_id',
    'primary research interests'                      => 'research_interests',
    'years of research experience'                    => 'years_experience',
    'how did you hear about the rarl community?'      => 'referral_source',
];
$PROFILE_FIELDS = ['country','city_state','position','institution','primary_lab_name','google_scholar_url','orcid_id','research_interests','years_experience','referral_source'];

$report = null;
$errorMsg = null;

// ── Step 1: upload → parse → split into create-outright vs needs-review ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'preview_import' && !empty($_FILES['import_file']['tmp_name'])) {
    $tmpPath = $_FILES['import_file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
    $rows = $ext === 'xlsx' ? parseXlsxToRows($tmpPath) : array_map('str_getcsv', file($tmpPath));

    if (empty($rows)) {
        $errorMsg = 'Could not read that file — upload a .csv or .xlsx export from the Google Form.';
    } else {
        $header = array_map(fn($h) => strtolower(trim($h)), array_shift($rows));
        $colFor = [];
        foreach ($HEADER_MAP as $srcHeader => $dbCol) {
            $idx = array_search($srcHeader, $header, true);
            if ($idx !== false) $colFor[$dbCol] = $idx;
        }
        if (!isset($colFor['email']) || !isset($colFor['full_name'])) {
            $errorMsg = 'File must include "Full Name" and "Email Address" columns matching the Google Form export.';
        } else {
            $toCreate = []; $conflicts = []; $invalid = [];
            foreach ($rows as $row) {
                $email = cleanEmail($row[$colFor['email']] ?? '');
                $name  = clean($row[$colFor['full_name']] ?? '');
                if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $invalid[] = ($email ?: '(blank)') . ' — invalid name/email'; continue; }

                $get = fn(string $col) => isset($colFor[$col]) ? clean($row[$colFor[$col]] ?? '') : '';
                $yearsExp = $get('years_experience'); if (!array_key_exists($yearsExp, YEARS_OPTIONS)) $yearsExp = '';
                $referral = $get('referral_source');  if (!array_key_exists($referral, REFERRAL_OPTIONS)) $referral = '';
                $data = [
                    'email' => $email, 'full_name' => $name,
                    'country' => $get('country'), 'city_state' => $get('city_state'), 'position' => $get('position'),
                    'institution' => $get('institution'), 'primary_lab_name' => $get('primary_lab_name'),
                    'google_scholar_url' => cleanUrl($row[$colFor['google_scholar_url'] ?? -1] ?? ''),
                    'orcid_id' => $get('orcid_id'), 'research_interests' => $get('research_interests'),
                    'years_experience' => $yearsExp, 'referral_source' => $referral,
                ];

                // Email match takes priority; otherwise fall back to an exact
                // case-insensitive name match (different email — could be a
                // typo'd re-signup or genuinely the same person's new address).
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

                if ($existingId) {
                    $ex = $pdo->prepare('SELECT id, full_name, email, institution, country FROM members WHERE id = ?');
                    $ex->execute([$existingId]);
                    $conflicts[] = ['incoming' => $data, 'existing' => $ex->fetch(), 'match_type' => $matchType];
                } else {
                    $toCreate[] = $data;
                }
            }

            $_SESSION['import_pending'] = ['toCreate' => $toCreate, 'conflicts' => $conflicts, 'invalid' => $invalid];
            header('Location: import-members.php?step=confirm'); exit;
        }
    }
}

// ── Step 2: apply — create the clean rows, apply each conflict's decision ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'apply_import') {
    $pending = $_SESSION['import_pending'] ?? null;
    if (!$pending) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Import session expired — please upload the file again.'];
        header('Location: import-members.php'); exit;
    }

    $freePlanId = (int)$pdo->query("SELECT id FROM membership_plans WHERE slug = 'free' LIMIT 1")->fetchColumn() ?: null;
    $created = 0; $merged = 0; $skipped = count($pending['invalid']);

    foreach ($pending['toCreate'] as $data) {
        $temp = bin2hex(random_bytes(5));
        $uuid = generateUuid();
        $pdo->prepare(
            'INSERT INTO members (uuid, type, email, password_hash, must_change_password, status, full_name, country, city_state,
              position, institution, primary_lab_name, google_scholar_url, orcid_id, research_interests, years_experience,
              referral_source, plan_id, email_verified_at, unsubscribe_token)
             VALUES (?,"individual",?,?,1,"active",?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)'
        )->execute([
            $uuid, $data['email'], password_hash($temp, PASSWORD_BCRYPT), $data['full_name'], $data['country'], $data['city_state'],
            $data['position'], $data['institution'], $data['primary_lab_name'], $data['google_scholar_url'],
            $data['orcid_id'], $data['research_interests'], $data['years_experience'] ?: null, $data['referral_source'] ?: null,
            $freePlanId, bin2hex(random_bytes(32)),
        ]);
        $memberId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO member_emails (member_id, email, is_primary, verified_at) VALUES (?,?,1,NOW())')->execute([$memberId, $data['email']]);

        $memberName = $data['full_name']; $tempPassword = $temp;
        ob_start(); require dirname(__DIR__) . '/emails/admin-temp-password.php'; $body = ob_get_clean();
        sendEmail($data['email'], $memberName, 'Your RARL Community account is ready', $body);
        $created++;
    }

    foreach ($pending['conflicts'] as $i => $c) {
        $decision = $_POST['decision'][$i] ?? 'skip';
        if ($decision !== 'merge') { $skipped++; continue; }

        $data = $c['incoming'];
        $existingId = (int)$c['existing']['id'];

        // Only overwrite fields the CSV actually provided a value for — a
        // sparse row shouldn't blank out a fuller existing profile.
        $sets = []; $params = [];
        foreach ($PROFILE_FIELDS as $field) {
            if ($data[$field] !== '') { $sets[] = "{$field} = ?"; $params[] = $data[$field]; }
        }
        if ($sets) {
            $params[] = $existingId;
            $pdo->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }

        // Name-only match with a different email: link it as an additional
        // login email rather than overwriting the existing primary address.
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

$pending = ($_GET['step'] ?? '') === 'confirm' ? ($_SESSION['import_pending'] ?? null) : null;
if (($_GET['step'] ?? '') === 'confirm' && !$pending) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Nothing to review — upload a file first.'];
    header('Location: import-members.php'); exit;
}

adminWrap(function() use ($report, $errorMsg, $pending) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Import Members</h1>
<p class="text-gray-500 text-sm mb-7">Bulk-create accounts from a CSV or Excel export (e.g. your Google Form responses sheet). Rows that match an existing member by email or name are flagged for you to review before anything changes.</p>

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

<?php if ($pending): ?>
<!-- ── Step 2: review conflicts ── -->
<div class="max-w-3xl space-y-6">
  <div class="bg-blue-50 border border-blue-200 rounded-2xl p-5 text-sm text-blue-800">
    <strong><?= count($pending['toCreate']) ?></strong> new account(s) will be created as-is.
    <?php if ($pending['invalid']): ?><br><strong><?= count($pending['invalid']) ?></strong> row(s) skipped (invalid name/email).<?php endif; ?>
    <?php if ($pending['conflicts']): ?><br><strong><?= count($pending['conflicts']) ?></strong> row(s) matched an existing member — choose below.<?php endif; ?>
  </div>

  <?php if ($pending['conflicts']): ?>
  <form method="POST" class="space-y-4">
    <?= acsrfField() ?><input type="hidden" name="action" value="apply_import">
    <?php foreach ($pending['conflicts'] as $i => $c):
      $ex = $c['existing']; $inc = $c['incoming'];
      $exName = $ex['full_name'];
    ?>
    <div class="bg-white border border-amber-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-3 bg-amber-50 border-b border-amber-200 flex items-center justify-between gap-3">
        <span class="text-xs font-bold text-amber-700 uppercase tracking-wider"><i class="fa-solid fa-triangle-exclamation"></i> <?= $c['match_type'] === 'email' ? 'Email match' : 'Name match (different email)' ?></span>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">
        <div class="p-4">
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">Existing Member</p>
          <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($exName ?: '(unnamed)') ?></p>
          <p class="text-xs text-gray-500"><?= htmlspecialchars($ex['email']) ?></p>
          <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($ex['institution'] ?: '—') ?> · <?= htmlspecialchars($ex['country'] ?: '—') ?></p>
        </div>
        <div class="p-4">
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1.5">Incoming Row</p>
          <p class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($inc['full_name']) ?></p>
          <p class="text-xs text-gray-500"><?= htmlspecialchars($inc['email']) ?></p>
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
    <?= acsrfField() ?><input type="hidden" name="action" value="preview_import">
    <div class="border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-6 text-center relative">
      <div class="text-2xl mb-1"><i class="fa-solid fa-download"></i></div>
      <p class="text-sm text-gray-600 mb-1">Upload <strong>.csv</strong> or <strong>.xlsx</strong></p>
      <p class="text-[11px] text-gray-400">Must include the standard RARL registration-form columns</p>
      <input type="file" name="import_file" accept=".csv,.xlsx" required class="absolute inset-0 opacity-0 cursor-pointer w-full h-full"/>
    </div>
    <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5"><i class="fa-solid fa-upload"></i> Upload & Review</button>
  </form>

  <div class="mt-5 pt-5 border-t border-gray-100">
    <p class="text-xs font-semibold text-gray-500 mb-2">Expected columns (exact Google Form header):</p>
    <code class="block text-[10px] bg-gray-50 border border-gray-200 rounded-lg p-3 text-gray-600 leading-relaxed">Timestamp, Full Name, Email Address, Country, City and State/Province, Current Designation, University / Institution Name, Primary Lab Name, Link to Google Scholar Profile, ORCID, Primary Research Interests, Years of Research Experience, Upload CV / Resume, How did you hear about the RARL community?</code>
  </div>
</div>
<?php endif; ?>
<?php }, 'import', 'Import Members');
