<?php
/**
 * RARL Admin — Bulk Member Import (CSV / Excel)
 * Matches the Google Form export header exactly:
 * Timestamp, Full Name, Email Address, Country, City and State/Province,
 * Current Designation, University / Institution Name, Primary Lab Name,
 * Link to Google Scholar Profile, ORCID, Primary Research Interests,
 * Years of Research Experience, Upload CV / Resume, How did you hear about the RARL community?
 * Creates a member account for each row whose email doesn't already exist,
 * emails a temporary password, and requires a password change on first login.
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

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && !empty($_FILES['import_file']['tmp_name'])) {
    $tmpPath = $_FILES['import_file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));

    $rows = $ext === 'xlsx' ? parseXlsxToRows($tmpPath) : array_map('str_getcsv', file($tmpPath));
    if (empty($rows)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Could not read that file — upload a .csv or .xlsx export from the Google Form.'];
        header('Location: import-members.php'); exit;
    }

    $header = array_map(fn($h) => strtolower(trim($h)), array_shift($rows));
    $colFor = [];
    foreach ($HEADER_MAP as $srcHeader => $dbCol) {
        $idx = array_search($srcHeader, $header, true);
        if ($idx !== false) $colFor[$dbCol] = $idx;
    }
    if (!isset($colFor['email']) || !isset($colFor['full_name'])) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'File must include "Full Name" and "Email Address" columns matching the Google Form export.'];
        header('Location: import-members.php'); exit;
    }

    $freePlanId = (int)$pdo->query("SELECT id FROM membership_plans WHERE slug = 'free' LIMIT 1")->fetchColumn() ?: null;
    $created = 0; $skipped = 0; $skippedRows = [];

    foreach ($rows as $row) {
        $email = cleanEmail($row[$colFor['email']] ?? '');
        $name  = clean($row[$colFor['full_name']] ?? '');
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; $skippedRows[] = ($email ?: '(blank)') . ' — invalid name/email'; continue; }

        $exists = $pdo->prepare('SELECT m.id FROM members m LEFT JOIN member_emails me ON me.member_id=m.id WHERE m.email=? OR me.email=? LIMIT 1');
        $exists->execute([$email, $email]);
        if ($exists->fetch()) { $skipped++; $skippedRows[] = $email . ' — already has an account'; continue; }

        $get = fn(string $col) => isset($colFor[$col]) ? clean($row[$colFor[$col]] ?? '') : '';
        $yearsExp = $get('years_experience'); if (!array_key_exists($yearsExp, YEARS_OPTIONS)) $yearsExp = '';
        $referral = $get('referral_source');  if (!array_key_exists($referral, REFERRAL_OPTIONS)) $referral = '';

        $temp = bin2hex(random_bytes(5));
        $uuid = generateUuid();
        $pdo->prepare(
            'INSERT INTO members (uuid, type, email, password_hash, must_change_password, status, full_name, country, city_state,
              position, institution, primary_lab_name, google_scholar_url, orcid_id, research_interests, years_experience,
              referral_source, plan_id, email_verified_at, unsubscribe_token)
             VALUES (?,"individual",?,?,1,"active",?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)'
        )->execute([
            $uuid, $email, password_hash($temp, PASSWORD_BCRYPT), $name, $get('country'), $get('city_state'),
            $get('position'), $get('institution'), $get('primary_lab_name'), cleanUrl($row[$colFor['google_scholar_url'] ?? -1] ?? ''),
            $get('orcid_id'), $get('research_interests'), $yearsExp ?: null, $referral ?: null, $freePlanId, bin2hex(random_bytes(32)),
        ]);
        $memberId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO member_emails (member_id, email, is_primary, verified_at) VALUES (?,?,1,NOW())')->execute([$memberId, $email]);

        $memberName = $name; $tempPassword = $temp;
        ob_start(); require dirname(__DIR__) . '/emails/admin-temp-password.php'; $body = ob_get_clean();
        sendEmail($email, $memberName, 'Your RARL Community account is ready', $body);
        $created++;
    }

    $report = ['created' => $created, 'skipped' => $skipped, 'skippedRows' => $skippedRows];
}

adminWrap(function() use ($report) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Import Members</h1>
<p class="text-gray-500 text-sm mb-7">Bulk-create accounts from a CSV or Excel export (e.g. your Google Form responses sheet). Existing emails are skipped. Each new member gets a temporary password by email and must change it on first login.</p>

<?php if ($report): ?>
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mb-6">
  <h2 class="font-heading font-bold text-sm text-gray-800 mb-3">Import Results</h2>
  <p class="text-sm text-gray-700 mb-2">✅ Created <strong><?= $report['created'] ?></strong> accounts · ⚠️ Skipped <strong><?= $report['skipped'] ?></strong> rows</p>
  <?php if ($report['skippedRows']): ?>
  <div class="max-h-48 overflow-y-auto text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg p-3 space-y-1">
    <?php foreach ($report['skippedRows'] as $s): ?><div><?= htmlspecialchars($s) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
  <form method="POST" enctype="multipart/form-data" class="space-y-4">
    <?= acsrfField() ?>
    <div class="border-2 border-dashed border-gray-300 hover:border-rarl-red/50 rounded-xl p-6 text-center relative">
      <div class="text-2xl mb-1">📥</div>
      <p class="text-sm text-gray-600 mb-1">Upload <strong>.csv</strong> or <strong>.xlsx</strong></p>
      <p class="text-[11px] text-gray-400">Must include the standard RARL registration-form columns</p>
      <input type="file" name="import_file" accept=".csv,.xlsx" required class="absolute inset-0 opacity-0 cursor-pointer w-full h-full"/>
    </div>
    <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5">📤 Import & Create Accounts</button>
  </form>

  <div class="mt-5 pt-5 border-t border-gray-100">
    <p class="text-xs font-semibold text-gray-500 mb-2">Expected columns (exact Google Form header):</p>
    <code class="block text-[10px] bg-gray-50 border border-gray-200 rounded-lg p-3 text-gray-600 leading-relaxed">Timestamp, Full Name, Email Address, Country, City and State/Province, Current Designation, University / Institution Name, Primary Lab Name, Link to Google Scholar Profile, ORCID, Primary Research Interests, Years of Research Experience, Upload CV / Resume, How did you hear about the RARL community?</code>
  </div>
</div>
<?php }, 'import', 'Import Members');
