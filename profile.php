<?php
/**
 * RARL — Member Profile: View + Edit
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in to access your profile.'); redirect('login.php'); }

$pdo      = db();
$memberId = (int)$_SESSION['member_id'];
$stmt     = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$stmt->execute([$memberId]);
$m = $stmt->fetch();
if (!$m) { session_destroy(); redirect('login.php'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $country      = clean($_POST['country'] ?? '');
            $newsletter   = isset($_POST['newsletter_opt_in']) ? 1 : 0;
            $commNotify   = isset($_POST['community_notify']) ? 1 : 0;
            $dirVisible   = isset($_POST['directory_visible']) ? 1 : 0;
            $openMentor   = isset($_POST['open_to_mentor']) ? 1 : 0;
            $seekMentor   = isset($_POST['seeking_mentor']) ? 1 : 0;

            $avatarFile = null;
            if (!empty($_FILES['avatar']['tmp_name'])) {
                $avatarFile = validateUpload($_FILES['avatar'], ['jpg','jpeg','png','webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/avatars');
                if (!$avatarFile) $errors[] = 'Photo must be a JPG/PNG/WEBP under 3MB.';
            }

            $yearsExp = clean($_POST['years_experience'] ?? '');
            $referral = clean($_POST['referral_source']  ?? '');
            if ($yearsExp !== '' && !array_key_exists($yearsExp, YEARS_OPTIONS)) $errors[] = 'Invalid years of experience selection.';
            if ($referral !== '' && !array_key_exists($referral, REFERRAL_OPTIONS)) $errors[] = 'Invalid referral source selection.';

            if ($m['type'] === 'lab') {
                $labName   = clean($_POST['lab_name']   ?? '');
                $piName    = clean($_POST['pi_name']    ?? '');
                $labSite   = cleanUrl($_POST['lab_website'] ?? '');
                $areas     = clean($_POST['research_areas'] ?? '');
                if (strlen($labName) < 2) $errors[] = 'Lab name is required.';
                if (strlen($piName) < 2)  $errors[] = 'PI name is required.';
                if (empty($country))      $errors[] = 'Country is required.';

                if (empty($errors)) {
                    $sql = 'UPDATE members SET lab_name=?, pi_name=?, lab_website=?, research_areas=?, country=?, years_experience=?, referral_source=?, newsletter_opt_in=?, community_notify=?, directory_visible=?, open_to_mentor=?, seeking_mentor=?';
                    $params = [$labName, $piName, $labSite, $areas, $country, $yearsExp ?: null, $referral ?: null, $newsletter, $commNotify, $dirVisible, $openMentor, $seekMentor];
                    if ($avatarFile) { $sql .= ', avatar_path=?'; $params[] = $avatarFile; }
                    $sql .= ' WHERE id=?'; $params[] = $memberId;
                    $pdo->prepare($sql)->execute($params);
                    flash('success', 'Profile updated.');
                    redirect('profile.php');
                }
            } else {
                $fullName    = clean($_POST['full_name']    ?? '');
                $institution = clean($_POST['institution']  ?? '');
                $department  = clean($_POST['department']   ?? '');
                $position    = clean($_POST['position']     ?? '');
                $interests   = clean($_POST['research_interests'] ?? '');
                $scholar     = cleanUrl($_POST['google_scholar_url'] ?? '');
                $orcid       = clean($_POST['orcid_id']      ?? '');
                $linkedin    = cleanUrl($_POST['linkedin_url'] ?? '');
                if (strlen($fullName) < 2)   $errors[] = 'Full name is required.';
                if (empty($institution))     $errors[] = 'Institution is required.';
                if (empty($country))         $errors[] = 'Country is required.';

                if (empty($errors)) {
                    $sql = 'UPDATE members SET full_name=?, institution=?, department=?, position=?, research_interests=?, google_scholar_url=?, orcid_id=?, linkedin_url=?, country=?, years_experience=?, referral_source=?, newsletter_opt_in=?, community_notify=?, directory_visible=?, open_to_mentor=?, seeking_mentor=?';
                    $params = [$fullName, $institution, $department, $position, $interests, $scholar, $orcid, $linkedin, $country, $yearsExp ?: null, $referral ?: null, $newsletter, $commNotify, $dirVisible, $openMentor, $seekMentor];
                    if ($avatarFile) { $sql .= ', avatar_path=?'; $params[] = $avatarFile; }
                    $sql .= ' WHERE id=?'; $params[] = $memberId;
                    $pdo->prepare($sql)->execute($params);
                    flash('success', 'Profile updated.');
                    redirect('profile.php');
                }
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']      ?? '';
            $confirm = $_POST['new_password_confirm'] ?? '';

            if (!password_verify($current, $m['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'New passwords do not match.';
            } else {
                $pdo->prepare('UPDATE members SET password_hash=? WHERE id=?')
                    ->execute([password_hash($new, PASSWORD_BCRYPT), $memberId]);
                flash('success', 'Password changed.');
                redirect('profile.php');
            }
        }
    }
    // Re-fetch in case of validation errors so the form re-renders with saved values
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
}

$displayName = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];

$plan = null;
if ($m['plan_id']) {
    $p = $pdo->prepare('SELECT name FROM membership_plans WHERE id = ?');
    $p->execute([$m['plan_id']]);
    $plan = $p->fetch();
}
$section = null;
if ($m['section_id']) {
    $s = $pdo->prepare('SELECT name, chair_name FROM regional_sections WHERE id = ?');
    $s->execute([$m['section_id']]);
    $section = $s->fetch();
}
$cardExpired = $m['id_card_expires_at'] && strtotime($m['id_card_expires_at']) < time();

echo htmlHead('My Profile');
?>
<?= publicNav('dashboard') ?>

<div class="bg-gray-50 dark:bg-gray-950 min-h-screen">
  <div class="bg-rarl-navy border-b border-rarl-mid">
    <div class="max-w-3xl mx-auto px-6 py-8 flex items-center gap-4">
      <div class="w-14 h-14 bg-rarl-red rounded-2xl flex items-center justify-center text-white font-heading font-black text-xl shadow-lg">
        <?= strtoupper(substr($displayName, 0, 1)) ?>
      </div>
      <div>
        <p class="text-white/50 text-xs uppercase tracking-wider mb-0.5"><?= $m['type'] === 'lab' ? '🏫 Research Lab' : '🧑‍🔬 Individual Researcher' ?></p>
        <h1 class="font-heading font-black text-xl text-white"><?= htmlspecialchars($displayName) ?></h1>
      </div>
    </div>
  </div>

  <div class="max-w-3xl mx-auto px-6 py-8 space-y-6">
    <?= renderFlash() ?>

    <?php if ($errors): ?>
    <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm space-y-1">
      <?php foreach ($errors as $e): ?><div class="flex items-start gap-2"><span>⚠️</span><span><?= htmlspecialchars($e) ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a href="dashboard.php" class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-rarl-red transition-colors">← Back to Dashboard</a>

    <!-- Membership / jurisdiction / ID card status -->
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm space-y-3">
      <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-1">Membership Status</h2>
      <div class="flex flex-wrap gap-2 text-xs">
        <span class="px-3 py-1.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 font-semibold">Plan: <?= htmlspecialchars($plan['name'] ?? 'Free') ?></span>
        <?php if ($section): ?>
        <span class="px-3 py-1.5 rounded-full bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300 font-semibold">Your nearest chair: <?= htmlspecialchars($section['chair_name'] ?? '—') ?> — <?= htmlspecialchars($section['name']) ?></span>
        <?php endif; ?>
      </div>

      <?php if (!empty($m['id_card_path'])): ?>
        <?php if ($cardExpired): ?>
        <div class="flex items-center justify-between gap-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm">
          <span class="text-amber-700 dark:text-amber-300">⚠️ Your ID card expired on <?= date('d M Y', strtotime($m['id_card_expires_at'])) ?>.</span>
          <a href="reverify.php" class="px-3 py-1.5 bg-rarl-red text-white text-xs font-semibold rounded-lg hover:bg-rarl-dark whitespace-nowrap">Reverify →</a>
        </div>
        <?php else: ?>
        <div class="flex items-center justify-between gap-3 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl text-sm">
          <span class="text-green-700 dark:text-green-300">🪪 ID card valid until <?= date('d M Y', strtotime($m['id_card_expires_at'])) ?></span>
          <a href="uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="px-3 py-1.5 bg-gray-800 text-white text-xs font-semibold rounded-lg hover:bg-gray-900 whitespace-nowrap">Download</a>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-xs text-gray-400">No ID card yet — an admin will generate one once your account is approved (a profile photo is required).</p>
      <?php endif; ?>
    </div>

    <!-- Profile form -->
    <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 shadow-sm space-y-4">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_profile"/>
      <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-1">Profile Details</h2>

      <div class="flex items-center gap-4">
        <div class="w-14 h-14 rounded-2xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center overflow-hidden flex-shrink-0">
          <?php if (!empty($m['avatar_path'])): ?>
          <img src="uploads/avatars/<?= urlencode($m['avatar_path']) ?>" alt="" class="w-full h-full object-cover"/>
          <?php else: ?>
          <span class="font-heading font-black text-lg text-gray-400"><?= strtoupper(substr($displayName, 0, 1)) ?></span>
          <?php endif; ?>
        </div>
        <div class="flex-1">
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Profile Photo <span class="text-gray-400 font-normal">(used on your ID card)</span></label>
          <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" class="w-full text-xs"/>
        </div>
      </div>

      <?php if ($m['type'] === 'lab'): ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Lab Name <span class="text-rarl-red">*</span></label>
          <input type="text" name="lab_name" value="<?= htmlspecialchars($m['lab_name'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">PI Name <span class="text-rarl-red">*</span></label>
          <input type="text" name="pi_name" value="<?= htmlspecialchars($m['pi_name'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Country <span class="text-rarl-red">*</span></label>
          <?= countryFieldHtml($m['country'] ?? '', 'profile-lab') ?>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Lab Website</label>
          <input type="url" name="lab_website" value="<?= htmlspecialchars($m['lab_website'] ?? '') ?>"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Years of Research Experience</label>
          <select name="years_experience" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <?= optionsSelectHtml(YEARS_OPTIONS, $m['years_experience'] ?? '') ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">How did you hear about us?</label>
          <select name="referral_source" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <?= optionsSelectHtml(REFERRAL_OPTIONS, $m['referral_source'] ?? '') ?>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Research Areas</label>
        <textarea name="research_areas" rows="2"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all resize-none"><?= htmlspecialchars($m['research_areas'] ?? '') ?></textarea>
      </div>
      <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Full Name <span class="text-rarl-red">*</span></label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($m['full_name'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Institution <span class="text-rarl-red">*</span></label>
          <input type="text" name="institution" value="<?= htmlspecialchars($m['institution'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Department</label>
          <input type="text" name="department" value="<?= htmlspecialchars($m['department'] ?? '') ?>"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Country <span class="text-rarl-red">*</span></label>
          <?= countryFieldHtml($m['country'] ?? '', 'profile-ind') ?>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Position</label>
          <select name="position" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <?= positionSelectOptions($m['position'] ?? '') ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Years of Research Experience</label>
          <select name="years_experience" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
            <?= optionsSelectHtml(YEARS_OPTIONS, $m['years_experience'] ?? '') ?>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">How did you hear about us?</label>
        <select name="referral_source" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all">
          <?= optionsSelectHtml(REFERRAL_OPTIONS, $m['referral_source'] ?? '') ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Research Interests</label>
        <textarea name="research_interests" rows="2"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all resize-none"><?= htmlspecialchars($m['research_interests'] ?? '') ?></textarea>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Google Scholar URL</label>
          <input type="url" name="google_scholar_url" value="<?= htmlspecialchars($m['google_scholar_url'] ?? '') ?>"
            class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">ORCID ID</label>
          <input type="text" name="orcid_id" value="<?= htmlspecialchars($m['orcid_id'] ?? '') ?>"
            class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">LinkedIn URL</label>
          <input type="url" name="linkedin_url" value="<?= htmlspecialchars($m['linkedin_url'] ?? '') ?>"
            class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>
      <?php endif; ?>

      <label class="flex items-start gap-3 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors">
        <input type="checkbox" name="newsletter_opt_in" value="1" <?= $m['newsletter_opt_in'] ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
        <div>
          <strong class="text-sm text-gray-800 dark:text-white">Subscribe to RARL Newsletter</strong>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Receive research updates, events, and opportunities.</p>
        </div>
      </label>

      <label class="flex items-start gap-3 p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors">
        <input type="checkbox" name="community_notify" value="1" <?= $m['community_notify'] ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
        <div>
          <strong class="text-sm text-gray-800 dark:text-white">Community Comment Notifications</strong>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Email me when someone comments on my community post.</p>
        </div>
      </label>

      <div class="pt-2 border-t border-gray-100 dark:border-gray-800">
        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Member Directory</p>
        <div class="space-y-3">
          <label class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors">
            <input type="checkbox" name="directory_visible" value="1" <?= ($m['directory_visible'] ?? 1) ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
            <div>
              <strong class="text-sm text-gray-800 dark:text-white">Show me in the Member Directory</strong>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Other members can find you by country, institution, or research interests.</p>
            </div>
          </label>
          <label class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors">
            <input type="checkbox" name="open_to_mentor" value="1" <?= !empty($m['open_to_mentor']) ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
            <div>
              <strong class="text-sm text-gray-800 dark:text-white">🧭 I'm open to mentoring others</strong>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Shown with a badge in the directory so students/early-career researchers can find you.</p>
            </div>
          </label>
          <label class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors">
            <input type="checkbox" name="seeking_mentor" value="1" <?= !empty($m['seeking_mentor']) ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 accent-rarl-red flex-shrink-0"/>
            <div>
              <strong class="text-sm text-gray-800 dark:text-white">🎯 I'm looking for a mentor</strong>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Shown with a badge in the directory so potential mentors can reach out.</p>
            </div>
          </label>
        </div>
      </div>

      <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg hover:-translate-y-0.5">
        Save Changes
      </button>
    </form>

    <!-- Change password -->
    <form method="POST" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 shadow-sm space-y-4">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="change_password"/>
      <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-1">Change Password</h2>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Current Password</label>
        <input type="password" name="current_password" required
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">New Password</label>
          <input type="password" name="new_password" required minlength="8"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Confirm New Password</label>
          <input type="password" name="new_password_confirm" required minlength="8"
            class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>
      </div>
      <button type="submit" class="w-full py-3 bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white font-bold rounded-xl transition-all text-sm">
        Update Password
      </button>
    </form>

  </div>
</div>
<?= publicFooter() ?>
</body></html>
