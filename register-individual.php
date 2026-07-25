<?php
/**
 * RARL — Individual Researcher Registration
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!empty($_SESSION['member_id'])) redirect('dashboard.php');

$errors = []; $vals = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) { $errors[] = 'Invalid request. Please try again.'; }

    // Honeypot
    if (!empty($_POST['website'])) { redirect('register-individual.php'); }

    $vals = [
        'full_name'         => clean($_POST['full_name']         ?? ''),
        'email'             => cleanEmail($_POST['email']        ?? ''),
        'password'          => $_POST['password']                ?? '',
        'institution'       => clean($_POST['institution']       ?? ''),
        'department'        => clean($_POST['department']        ?? ''),
        'position'          => clean($_POST['position']          ?? ''),
        'country'           => clean($_POST['country']           ?? ''),
        'city_state'        => clean($_POST['city_state']        ?? ''),
        'primary_lab_name'  => clean($_POST['primary_lab_name']  ?? ''),
        'years_experience'  => clean($_POST['years_experience']  ?? ''),
        'referral_source'   => clean($_POST['referral_source']   ?? ''),
        'research_interests'=> clean($_POST['research_interests']?? ''),
        'google_scholar_url'=> cleanUrl($_POST['google_scholar_url'] ?? ''),
        'orcid_id'          => clean($_POST['orcid_id']          ?? ''),
        'linkedin_url'      => cleanUrl($_POST['linkedin_url']   ?? ''),
        'newsletter_opt_in' => isset($_POST['newsletter_opt_in']) ? 1 : 0,
    ];

    $yearsOptions    = ['<1'=>'Less than 1 year','1-3'=>'1-3 years','3-5'=>'3-5 years','5-10'=>'5-10 years','10+'=>'More than 10 years'];
    $referralOptions = ['conference'=>'Academic Conference','referral'=>'Colleague / Peer Referral','social_media'=>'Social Media','newsletter'=>'University Newsletter','other'=>'Other'];

    // Validate
    if (strlen($vals['full_name']) < 2) $errors[] = 'Full name is required.';
    if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($vals['password']) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (empty($vals['institution'])) $errors[] = 'Institution is required.';
    if (empty($vals['country'])) $errors[] = 'Country is required.';
    if (empty($vals['city_state'])) $errors[] = 'City & State/Province is required.';
    if (empty($vals['primary_lab_name'])) $errors[] = 'Primary lab name is required.';
    if (!array_key_exists($vals['years_experience'], $yearsOptions)) $errors[] = 'Please select your years of research experience.';
    if (!array_key_exists($vals['referral_source'], $referralOptions)) $errors[] = 'Please tell us how you heard about us.';
    if (empty($vals['research_interests'])) $errors[] = 'Please share your research interests.';
    if (!filter_var($vals['google_scholar_url'], FILTER_VALIDATE_URL)) $errors[] = 'A valid Google Scholar URL is required.';
    if (empty($vals['orcid_id'])) $errors[] = 'ORCID ID is required.';

    $cvFilename = null;
    if (empty($_FILES['cv_file']['name'])) {
        $errors[] = 'Please upload a valid CV — PDF/DOC/DOCX, max 10MB.';
    } else {
        $cvFilename = validateUpload($_FILES['cv_file'], ['pdf','doc','docx'], 10 * 1024 * 1024, UPLOADS_PATH . '/cv');
        if (!$cvFilename) $errors[] = 'Please upload a valid CV — PDF/DOC/DOCX, max 10MB.';
    }

    if (empty($errors)) {
        $pdo = db();
        // Check duplicate email
        $exists = $pdo->prepare('SELECT id FROM members WHERE email = ?');
        $exists->execute([$vals['email']]);
        if ($exists->fetch()) {
            $errors[] = 'This email is already registered. <a href="login.php" class="underline">Sign in?</a>';
        } else {
            $uuid     = generateUuid();
            $token    = bin2hex(random_bytes(32));
            $hash     = password_hash($vals['password'], PASSWORD_BCRYPT);
            $status   = REQUIRE_APPROVAL ? 'pending' : 'active';
            $planId   = planIdFor('individual');
            $sectionId= nearestSection($vals['country']);

            $pdo->prepare("
                INSERT INTO members
                  (uuid, type, email, password_hash, full_name, institution, department, position,
                   country, city_state, primary_lab_name, years_experience, referral_source, cv_path,
                   research_interests, google_scholar_url, orcid_id, linkedin_url,
                   newsletter_opt_in, unsubscribe_token, status, plan_id, section_id)
                VALUES
                  (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $uuid, 'individual', $vals['email'], $hash, $vals['full_name'],
                $vals['institution'], $vals['department'], $vals['position'],
                $vals['country'], $vals['city_state'], $vals['primary_lab_name'],
                $vals['years_experience'], $vals['referral_source'], $cvFilename,
                $vals['research_interests'],
                $vals['google_scholar_url'], $vals['orcid_id'], $vals['linkedin_url'],
                $vals['newsletter_opt_in'], $token, $status, $planId, $sectionId,
            ]);

            // Send email-verification OTP (replaces the old welcome email as first touchpoint)
            $memberName = $vals['full_name'];
            $code = generateOtp($vals['email'], 'verify_email');
            ob_start(); require __DIR__ . '/emails/otp-verify.php'; $body = ob_get_clean();
            sendEmail($vals['email'], $vals['full_name'], 'Verify your email — ' . SITE_NAME, $body);

            // Notify admin
            sendEmail(ADMIN_EMAIL, 'RARL Admin',
                '[RARL] New member: ' . $vals['full_name'],
                '<p>New individual researcher registration:<br><strong>' . $vals['full_name'] . '</strong> (' . $vals['email'] . ')<br>' . $vals['institution'] . ', ' . $vals['country'] . '</p>'
            );

            redirect('verify-email.php?email=' . urlencode($vals['email']));
        }
    }
}

echo htmlHead('Individual Researcher Registration');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 py-12 px-4">
  <div class="max-w-xl mx-auto">

    <!-- Breadcrumb -->
    <div class="flex items-center gap-2 text-xs text-gray-400 mb-8">
      <a href="register.php" class="hover:text-rarl-red transition-colors">← Back</a>
      <span>/</span><span>Individual Registration</span>
    </div>

    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center text-xl">🧑‍🔬</div>
        <div>
          <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white">Individual Registration</h1>
          <p class="text-gray-500 text-xs">Researcher, student, or professor</p>
        </div>
      </div>
    </div>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm space-y-1">
      <?php foreach ($errors as $e): ?><div class="flex items-start gap-2"><span>⚠️</span><span><?= $e ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 shadow-sm space-y-4">
      <?= csrfField() ?>
      <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off"><!-- honeypot -->

      <!-- Name + Email -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Full Name <span class="text-rarl-red">*</span></label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($vals['full_name'] ?? '') ?>" required
            placeholder="Dr. Jane Smith"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email <span class="text-rarl-red">*</span></label>
          <input type="email" name="email" value="<?= htmlspecialchars($vals['email'] ?? '') ?>" required
            placeholder="you@university.edu"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <!-- Password -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Password <span class="text-rarl-red">*</span></label>
        <input type="password" name="password" required minlength="8"
          placeholder="Minimum 8 characters"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
      </div>

      <!-- Institution + Department -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Institution <span class="text-rarl-red">*</span></label>
          <input type="text" name="institution" value="<?= htmlspecialchars($vals['institution'] ?? '') ?>" required
            placeholder="University or Organisation"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Department <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="text" name="department" value="<?= htmlspecialchars($vals['department'] ?? '') ?>"
            placeholder="e.g. Computer Science"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <!-- Position + Country -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Current Designation</label>
          <select name="position" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <option value="">— Select —</option>
            <?php foreach (['PhD Student','MSc Student','BSc Student','Postdoctoral Researcher','Research Scientist','Assistant Professor','Associate Professor','Professor','Industry Researcher','Independent Researcher','Other'] as $p): ?>
            <option <?= ($vals['position'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Country <span class="text-rarl-red">*</span></label>
          <input type="text" name="country" value="<?= htmlspecialchars($vals['country'] ?? '') ?>" required
            placeholder="e.g. United Kingdom"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <!-- City/State + Primary Lab Name -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">City &amp; State/Province <span class="text-rarl-red">*</span></label>
          <input type="text" name="city_state" value="<?= htmlspecialchars($vals['city_state'] ?? '') ?>" required
            placeholder="e.g. Boston, MA"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Primary Lab Name <span class="text-rarl-red">*</span></label>
          <input type="text" name="primary_lab_name" value="<?= htmlspecialchars($vals['primary_lab_name'] ?? '') ?>" required
            placeholder="e.g. Robotics & Automation Lab"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>

      <!-- Years of Experience + Referral Source -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Years of Research Experience <span class="text-rarl-red">*</span></label>
          <select name="years_experience" required class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <option value="">— Select —</option>
            <?php foreach ($yearsOptions as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($vals['years_experience'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">How did you hear about us? <span class="text-rarl-red">*</span></label>
          <select name="referral_source" required class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <option value="">— Select —</option>
            <?php foreach ($referralOptions as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($vals['referral_source'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- CV Upload -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">CV / Resume <span class="text-rarl-red">*</span></label>
        <input type="file" name="cv_file" required accept=".pdf,.doc,.docx"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        <p class="text-[11px] text-gray-400 mt-1">PDF, DOC, or DOCX — max 10MB.</p>
      </div>

      <!-- Research Interests -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Research Interests <span class="text-rarl-red">*</span></label>
        <textarea name="research_interests" required rows="2" placeholder="e.g. Service Robotics, Computer Vision, Path Planning, Autonomous Systems"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all resize-none"><?= htmlspecialchars($vals['research_interests'] ?? '') ?></textarea>
      </div>

      <!-- Academic links -->
      <div class="pt-2 border-t border-gray-100 dark:border-gray-800">
        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Academic Profiles</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Google Scholar URL <span class="text-rarl-red">*</span></label>
            <input type="url" name="google_scholar_url" value="<?= htmlspecialchars($vals['google_scholar_url'] ?? '') ?>" required
              placeholder="scholar.google.com/…"
              class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">ORCID ID <span class="text-rarl-red">*</span></label>
            <input type="text" name="orcid_id" value="<?= htmlspecialchars($vals['orcid_id'] ?? '') ?>" required
              placeholder="0000-0000-0000-0000"
              class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">LinkedIn URL</label>
            <input type="url" name="linkedin_url" value="<?= htmlspecialchars($vals['linkedin_url'] ?? '') ?>"
              placeholder="linkedin.com/in/…"
              class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
          </div>
        </div>
      </div>

      <!-- Newsletter opt-in -->
      <label class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors">
        <input type="checkbox" name="newsletter_opt_in" value="1" checked class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
        <div>
          <strong class="text-sm text-gray-800 dark:text-white">Subscribe to RARL Newsletter</strong>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Receive research updates, events, and opportunities. Unsubscribe anytime.</p>
        </div>
      </label>

      <!-- Submit -->
      <button type="submit"
        class="w-full py-3.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all text-sm">
        Create My Account →
      </button>
      <p class="text-center text-xs text-gray-400">Already a member? <a href="login.php" class="text-rarl-red hover:underline">Sign in</a></p>
    </form>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
