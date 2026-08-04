<?php
/**
 * RARL — Research Lab Registration
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('Join');
if (!empty($_SESSION['member_id'])) redirect('dashboard.php');
if (!registrationsOpen()) redirect('register.php');

$errors = []; $vals = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { $errors[] = 'Invalid request.'; }
    if (!empty($_POST['website_trap'])) { redirect('register-lab.php'); }

    $vals = [
        'lab_name'       => clean($_POST['lab_name']       ?? ''),
        'pi_name'        => clean($_POST['pi_name']         ?? ''),
        'email'          => cleanEmail($_POST['email']      ?? ''),
        'password'       => $_POST['password']              ?? '',
        'institution'    => clean($_POST['institution']     ?? ''),
        'country'        => clean($_POST['country']         ?? ''),
        'city_state'       => clean($_POST['city_state']       ?? ''),
        'years_experience' => clean($_POST['years_experience'] ?? ''),
        'referral_source'  => clean($_POST['referral_source']  ?? ''),
        'google_scholar_url' => cleanUrl($_POST['google_scholar_url'] ?? ''),
        'orcid_id'          => clean($_POST['orcid_id']          ?? ''),
        'research_areas' => clean($_POST['research_areas']  ?? ''),
        'lab_website'    => cleanUrl($_POST['lab_website']  ?? ''),
        'newsletter_opt_in' => isset($_POST['newsletter_opt_in']) ? 1 : 0,
    ];

    if (strlen($vals['lab_name']) < 2)   $errors[] = 'Lab name is required.';
    if (strlen($vals['pi_name']) < 2)    $errors[] = 'Principal Investigator name is required.';
    if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid contact email is required.';
    if (strlen($vals['password']) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if (empty($vals['institution']))     $errors[] = 'Institution/University is required.';
    if (empty($vals['country']))         $errors[] = 'Country is required.';
    if (empty($vals['city_state']))      $errors[] = 'City & State/Province is required.';
    if (!array_key_exists($vals['years_experience'], YEARS_OPTIONS)) $errors[] = 'Please select the lab\'s years of research experience.';
    if (!array_key_exists($vals['referral_source'], REFERRAL_OPTIONS)) $errors[] = 'Please tell us how you heard about us.';
    if (empty($vals['research_areas']))  $errors[] = 'Please describe your research areas.';
    if ($vals['google_scholar_url'] !== '' && !filter_var($vals['google_scholar_url'], FILTER_VALIDATE_URL)) $errors[] = 'Google Scholar URL must be a valid URL.';

    $cvFilename = null;
    if (empty($_FILES['cv_file']['name'])) {
        $errors[] = 'Please upload a valid CV — PDF/DOC/DOCX, max 10MB.';
    } else {
        $cvFilename = validateUpload($_FILES['cv_file'], ['pdf','doc','docx'], 10 * 1024 * 1024, UPLOADS_PATH . '/cv');
        if (!$cvFilename) $errors[] = 'Please upload a valid CV — PDF/DOC/DOCX, max 10MB.';
    }

    if (empty($errors)) {
        $pdo = db();
        $dup = $pdo->prepare('SELECT id FROM members WHERE email = ?');
        $dup->execute([$vals['email']]);
        if ($dup->fetch()) {
            $errors[] = 'This email is already registered. <a href="login.php" class="underline">Sign in?</a>';
        } else {
            $uuid      = generateUuid();
            $token     = bin2hex(random_bytes(32));
            $hash      = password_hash($vals['password'], PASSWORD_BCRYPT);
            $autoApproved = emailDomainAutoApproved($vals['email']);
            $status    = (REQUIRE_APPROVAL && !$autoApproved) ? 'pending' : 'active';
            $planId    = planIdFor('lab');
            $sectionId = nearestSection($vals['country']);

            $pdo->prepare("
                INSERT INTO members
                  (uuid, type, email, password_hash, lab_name, pi_name, institution, country,
                   city_state, years_experience, referral_source, google_scholar_url, orcid_id, cv_path,
                   research_areas, lab_website, newsletter_opt_in, unsubscribe_token, status, plan_id, section_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $uuid, 'lab', $vals['email'], $hash, $vals['lab_name'], $vals['pi_name'],
                $vals['institution'], $vals['country'], $vals['city_state'], $vals['years_experience'],
                $vals['referral_source'], $vals['google_scholar_url'], $vals['orcid_id'], $cvFilename,
                $vals['research_areas'], $vals['lab_website'], $vals['newsletter_opt_in'], $token, $status,
                $planId, $sectionId,
            ]);

            if ($autoApproved) completeApproval((int)$pdo->lastInsertId());

            // Send email-verification OTP (replaces the old welcome email as first touchpoint)
            $memberName = $vals['lab_name'] . ' (' . $vals['pi_name'] . ')';
            $code = generateOtp($vals['email'], 'verify_email');
            ob_start(); require __DIR__ . '/emails/otp-verify.php'; $body = ob_get_clean();
            sendEmail($vals['email'], $vals['lab_name'], 'Verify your email — ' . SITE_NAME, $body);

            sendEmail(ADMIN_EMAIL, 'RARL Admin',
                '[RARL] New lab registration: ' . $vals['lab_name'],
                '<p>New research lab registration:<br><strong>' . $vals['lab_name'] . '</strong><br>PI: ' . $vals['pi_name'] . '<br>Email: ' . $vals['email'] . '<br>' . $vals['institution'] . ', ' . $vals['country'] . '</p>'
            );

            redirect('verify-email.php?email=' . urlencode($vals['email']));
        }
    }
}

echo htmlHead('Research Lab Registration');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 py-12 px-4">
  <div class="max-w-xl mx-auto">

    <div class="flex items-center gap-2 text-xs text-gray-400 mb-8">
      <a href="register.php" class="hover:text-rarl-red transition-colors">← Back</a>
      <span>/</span><span>Research Lab Registration</span>
    </div>

    <div class="mb-8 flex items-center gap-3">
      <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center text-xl"><i class="fa-solid fa-building-columns"></i></div>
      <div>
        <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white">Research Lab Registration</h1>
        <p class="text-gray-500 text-xs">Register your entire research group or lab</p>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm space-y-1">
      <?php foreach ($errors as $e): ?><div class="flex items-start gap-2"><span><i class="fa-solid fa-triangle-exclamation"></i></span><span><?= $e ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 shadow-sm space-y-4">
      <?= csrfField() ?>
      <input type="text" name="website_trap" class="hidden" tabindex="-1" autocomplete="off">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Lab Name <span class="text-rarl-red">*</span></label>
          <input type="text" name="lab_name" value="<?= htmlspecialchars($vals['lab_name'] ?? '') ?>" required
            placeholder="e.g. RARL Lab" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Principal Investigator <span class="text-rarl-red">*</span></label>
          <input type="text" name="pi_name" value="<?= htmlspecialchars($vals['pi_name'] ?? '') ?>" required
            placeholder="Dr. John Smith" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contact Email <span class="text-rarl-red">*</span></label>
          <input type="email" name="email" value="<?= htmlspecialchars($vals['email'] ?? '') ?>" required
            placeholder="lab@university.edu" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Password <span class="text-rarl-red">*</span></label>
          <input type="password" name="password" required minlength="8"
            placeholder="Min. 8 characters" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">University / Institution <span class="text-rarl-red">*</span></label>
          <input type="text" name="institution" value="<?= htmlspecialchars($vals['institution'] ?? '') ?>" required
            placeholder="e.g. MIT" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Country <span class="text-rarl-red">*</span></label>
          <?= countryFieldHtml($vals['country'] ?? '', 'reg-lab') ?>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">City &amp; State/Province <span class="text-rarl-red">*</span></label>
          <input type="text" name="city_state" value="<?= htmlspecialchars($vals['city_state'] ?? '') ?>" required
            placeholder="e.g. Cambridge, MA" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Years of Research Experience <span class="text-rarl-red">*</span></label>
          <select name="years_experience" required class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <?= optionsSelectHtml(YEARS_OPTIONS, $vals['years_experience'] ?? '') ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">How did you hear about us? <span class="text-rarl-red">*</span></label>
          <select name="referral_source" required class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <?= optionsSelectHtml(REFERRAL_OPTIONS, $vals['referral_source'] ?? '') ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">CV / Resume (PI) <span class="text-rarl-red">*</span></label>
          <input type="file" name="cv_file" required accept=".pdf,.doc,.docx"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Google Scholar URL <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="url" name="google_scholar_url" value="<?= htmlspecialchars($vals['google_scholar_url'] ?? '') ?>"
            placeholder="scholar.google.com/…" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">ORCID ID <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="text" name="orcid_id" value="<?= htmlspecialchars($vals['orcid_id'] ?? '') ?>"
            placeholder="0000-0000-0000-0000" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Research Areas <span class="text-rarl-red">*</span></label>
        <textarea name="research_areas" required rows="2" placeholder="e.g. Service Robotics, Autonomous Systems, Industrial Automation, Computer Vision"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all resize-none"><?= htmlspecialchars($vals['research_areas'] ?? '') ?></textarea>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Lab Website <span class="text-gray-400 font-normal">(optional)</span></label>
        <input type="url" name="lab_website" value="<?= htmlspecialchars($vals['lab_website'] ?? '') ?>"
          placeholder="https://your-lab.university.edu"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
      </div>

      <label class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl cursor-pointer">
        <input type="checkbox" name="newsletter_opt_in" value="1" checked class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
        <div>
          <strong class="text-sm text-gray-800 dark:text-white">Subscribe to RARL Newsletter</strong>
          <p class="text-xs text-gray-500 mt-0.5">Receive lab-relevant updates, events, and opportunities.</p>
        </div>
      </label>

      <button type="submit" class="w-full py-3.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition-all text-sm">
        Register Our Lab →
      </button>
      <p class="text-center text-xs text-gray-400">Already registered? <a href="login.php" class="text-rarl-red hover:underline">Sign in</a></p>
    </form>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
