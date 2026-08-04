<?php
/**
 * RARL — Member ID Card Reverification (renews an expired 3-year ID card)
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in to reverify your ID card.'); redirect('login.php'); }

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
        $institution = clean($_POST['institution'] ?? '');
        $country     = clean($_POST['country'] ?? '');
        $interests   = clean($_POST['research_interests'] ?? '');

        if ($m['type'] === 'lab') {
            if (strlen(clean($_POST['lab_name'] ?? '')) < 2) $errors[] = 'Lab name is required.';
        } else {
            if (empty($institution)) $errors[] = 'Institution is required.';
        }
        if (empty($country)) $errors[] = 'Country is required.';

        $avatarFile = null;
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $avatarFile = validateUpload($_FILES['avatar'], ['jpg','jpeg','png','webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/avatars');
            if (!$avatarFile) $errors[] = 'Photo must be a JPG/PNG/WEBP under 3MB.';
        }

        if (empty($errors)) {
            $position = clean($_POST['position'] ?? $m['position'] ?? '');
            $sectionId = nearestSection($country);
            $params = [$institution ?: $m['institution'], $country, $interests, $position, $sectionId, $memberId];
            $sql = 'UPDATE members SET institution=?, country=?, research_interests=?, position=?, section_id=?';
            if ($avatarFile) { $sql = 'UPDATE members SET institution=?, country=?, research_interests=?, position=?, section_id=?, avatar_path=?'; array_splice($params, 5, 0, [$avatarFile]); }
            $sql .= ' WHERE id=?';
            $pdo->prepare($sql)->execute($params);

            issueIdCard($memberId);

            flash('success', 'Your details have been reverified and your ID card has been renewed for 3 years.');
            redirect('profile.php');
        }
    }
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
}

echo htmlHead('Reverify ID Card');
?>
<?= publicNav() ?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-950 py-12 px-4">
  <div class="max-w-xl mx-auto">
    <div class="mb-8 text-center">
      <div class="text-4xl mb-3"><i class="fa-solid fa-id-card"></i></div>
      <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white mb-1">Reverify Your ID Card</h1>
      <p class="text-gray-500 text-sm">Your RARL member ID card has expired. Confirm your details below to renew it for another 3 years.</p>
    </div>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm space-y-1">
      <?php foreach ($errors as $e): ?><div class="flex items-start gap-2"><span><i class="fa-solid fa-triangle-exclamation"></i></span><span><?= htmlspecialchars($e) ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-7 shadow-sm space-y-4">
      <?= csrfField() ?>

      <?php if ($m['type'] === 'lab'): ?>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Lab Name <span class="text-rarl-red">*</span></label>
        <input type="text" name="lab_name" value="<?= htmlspecialchars($m['lab_name'] ?? '') ?>" required
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm"/>
      </div>
      <?php else: ?>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Institution <span class="text-rarl-red">*</span></label>
        <input type="text" name="institution" value="<?= htmlspecialchars($m['institution'] ?? '') ?>" required
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Position</label>
        <input type="text" name="position" value="<?= htmlspecialchars($m['position'] ?? '') ?>"
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm"/>
      </div>
      <?php endif; ?>

      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Country <span class="text-rarl-red">*</span></label>
        <input type="text" name="country" value="<?= htmlspecialchars($m['country'] ?? '') ?>" required
          class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Research Interests</label>
        <textarea name="research_interests" rows="2" class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm resize-none"><?= htmlspecialchars($m['research_interests'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Update Photo <span class="text-gray-400 font-normal">(optional — required if you've never uploaded one)</span></label>
        <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" class="w-full text-sm"/>
      </div>

      <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg hover:-translate-y-0.5">
        Reverify &amp; Renew ID Card
      </button>
    </form>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
