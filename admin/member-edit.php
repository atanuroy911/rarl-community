<?php
/**
 * RARL Admin — Member Detail / Edit
 * Full read+write view of a single member for admins — every registration
 * field, uploaded CV, avatar, ID card, and admin-only notes. Members can only
 * edit a subset of this themselves via profile.php; this page has no such
 * restriction (e.g. admins can change email, status, plan, section here).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Member not found.']; header('Location: members.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $email      = cleanEmail($_POST['email'] ?? '');
    $status     = in_array($_POST['status'] ?? '', ['pending','active','inactive'], true) ? $_POST['status'] : $m['status'];
    $planId     = (int)($_POST['plan_id'] ?? 0) ?: null;
    $sectionId  = (int)($_POST['section_id'] ?? 0) ?: null;
    $country    = clean($_POST['country'] ?? '');
    $cityState  = clean($_POST['city_state'] ?? '');
    $newsletter = isset($_POST['newsletter_opt_in']) ? 1 : 0;
    $commNotify = isset($_POST['community_notify']) ? 1 : 0;
    $yearsExp   = clean($_POST['years_experience'] ?? '');
    $referral   = clean($_POST['referral_source']  ?? '');
    $notes      = clean($_POST['notes'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($yearsExp !== '' && !array_key_exists($yearsExp, YEARS_OPTIONS)) $errors[] = 'Invalid years of experience value.';
    if ($referral !== '' && !array_key_exists($referral, REFERRAL_OPTIONS)) $errors[] = 'Invalid referral source value.';

    $avatarFile = null;
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $avatarFile = validateUpload($_FILES['avatar'], ['jpg','jpeg','png','webp'], 3 * 1024 * 1024, UPLOADS_PATH . '/avatars');
        if (!$avatarFile) $errors[] = 'Avatar must be a JPG/PNG/WEBP under 3MB.';
    }
    $cvFile = null;
    if (!empty($_FILES['cv_file']['tmp_name'])) {
        $cvFile = validateUpload($_FILES['cv_file'], ['pdf','doc','docx'], 10 * 1024 * 1024, UPLOADS_PATH . '/cv');
        if (!$cvFile) $errors[] = 'CV must be a PDF/DOC/DOCX under 10MB.';
    }

    if (empty($errors)) {
        $dupe = $pdo->prepare('SELECT id FROM members WHERE email = ? AND id != ?');
        $dupe->execute([$email, $id]);
        if ($dupe->fetch()) {
            $errors[] = 'Another member already uses that email.';
        } else {
            $sql = 'UPDATE members SET email=?, status=?, plan_id=?, section_id=?, country=?, city_state=?, newsletter_opt_in=?, community_notify=?, years_experience=?, referral_source=?, notes=?';
            $params = [$email, $status, $planId, $sectionId, $country, $cityState, $newsletter, $commNotify, $yearsExp ?: null, $referral ?: null, $notes];

            if ($m['type'] === 'lab') {
                $sql .= ', lab_name=?, pi_name=?, lab_website=?, research_areas=?';
                $params = array_merge($params, [
                    clean($_POST['lab_name'] ?? ''), clean($_POST['pi_name'] ?? ''),
                    cleanUrl($_POST['lab_website'] ?? ''), clean($_POST['research_areas'] ?? ''),
                ]);
            } else {
                $sql .= ', full_name=?, institution=?, department=?, position=?, research_interests=?, primary_lab_name=?, google_scholar_url=?, orcid_id=?, linkedin_url=?';
                $params = array_merge($params, [
                    clean($_POST['full_name'] ?? ''), clean($_POST['institution'] ?? ''), clean($_POST['department'] ?? ''),
                    clean($_POST['position'] ?? ''), clean($_POST['research_interests'] ?? ''), clean($_POST['primary_lab_name'] ?? ''),
                    cleanUrl($_POST['google_scholar_url'] ?? ''), clean($_POST['orcid_id'] ?? ''), cleanUrl($_POST['linkedin_url'] ?? ''),
                ]);
            }

            if ($avatarFile) { $sql .= ', avatar_path=?'; $params[] = $avatarFile; }
            if ($cvFile)     { $sql .= ', cv_path=?';     $params[] = $cvFile; }
            $sql .= ' WHERE id=?'; $params[] = $id;

            $pdo->prepare($sql)->execute($params);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Member updated.'];
            header('Location: member-edit.php?id=' . $id); exit;
        }
    }
    // Re-fetch so the form re-renders with what's actually saved (or the attempted values on error)
    $stmt->execute([$id]);
    $m = $stmt->fetch();
}

$plans    = $pdo->query("SELECT id, name FROM membership_plans WHERE is_published=1 ORDER BY display_order")->fetchAll();
$sections = $pdo->query("SELECT id, name FROM regional_sections WHERE is_published=1 ORDER BY continent, scope DESC, display_order")->fetchAll();
$displayName = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];

adminWrap(function() use ($m, $errors, $plans, $sections, $displayName) { ?>
<a href="members.php" class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-rarl-red transition-colors mb-4">← Back to Members</a>
<div class="flex items-center gap-3 mb-6">
  <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0">
    <?php if (!empty($m['avatar_path'])): ?>
    <img src="../uploads/avatars/<?= urlencode($m['avatar_path']) ?>" alt="" class="w-full h-full object-cover"/>
    <?php else: ?>
    <span class="font-heading font-black text-lg text-gray-400"><?= strtoupper(substr($displayName ?: '?', 0, 1)) ?></span>
    <?php endif; ?>
  </div>
  <div>
    <h1 class="text-2xl font-black text-gray-900"><?= htmlspecialchars($displayName ?: '(unnamed)') ?></h1>
    <p class="text-gray-500 text-sm"><?= $m['type'] === 'lab' ? '🏫 Research Lab' : '🧑‍🔬 Individual Researcher' ?> · Joined <?= date('d M Y', strtotime($m['created_at'])) ?></p>
  </div>
</div>

<?php if ($errors): ?>
<div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm space-y-1">
  <?php foreach ($errors as $e): ?><div class="flex items-start gap-2"><span>⚠️</span><span><?= htmlspecialchars($e) ?></span></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2">
    <form method="POST" enctype="multipart/form-data" class="bg-white border border-gray-200 rounded-2xl p-7 shadow-sm space-y-5">
      <?= acsrfField() ?>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Email</label>
          <input type="email" name="email" value="<?= htmlspecialchars($m['email']) ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Status</label>
          <select name="status" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm">
            <?php foreach (['pending','active','inactive'] as $s): ?>
            <option value="<?= $s ?>" <?= $m['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if ($m['type'] === 'lab'): ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Lab Name</label>
          <input type="text" name="lab_name" value="<?= htmlspecialchars($m['lab_name'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">PI Name</label>
          <input type="text" name="pi_name" value="<?= htmlspecialchars($m['pi_name'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Lab Website</label>
        <input type="url" name="lab_website" value="<?= htmlspecialchars($m['lab_website'] ?? '') ?>"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Research Areas</label>
        <textarea name="research_areas" rows="2" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm resize-none"><?= htmlspecialchars($m['research_areas'] ?? '') ?></textarea>
      </div>
      <?php else: ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Full Name</label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($m['full_name'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Institution</label>
          <input type="text" name="institution" value="<?= htmlspecialchars($m['institution'] ?? '') ?>" required
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Department</label>
          <input type="text" name="department" value="<?= htmlspecialchars($m['department'] ?? '') ?>"
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Position</label>
          <select name="position" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm">
            <?= positionSelectOptions($m['position'] ?? '') ?>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Primary Lab Name</label>
        <input type="text" name="primary_lab_name" value="<?= htmlspecialchars($m['primary_lab_name'] ?? '') ?>"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Research Interests</label>
        <textarea name="research_interests" rows="2" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm resize-none"><?= htmlspecialchars($m['research_interests'] ?? '') ?></textarea>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Google Scholar URL</label>
          <input type="url" name="google_scholar_url" value="<?= htmlspecialchars($m['google_scholar_url'] ?? '') ?>"
            class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-xs"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">ORCID ID</label>
          <input type="text" name="orcid_id" value="<?= htmlspecialchars($m['orcid_id'] ?? '') ?>"
            class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-xs"/>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">LinkedIn URL</label>
          <input type="url" name="linkedin_url" value="<?= htmlspecialchars($m['linkedin_url'] ?? '') ?>"
            class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-lg text-xs"/>
        </div>
      </div>
      <?php endif; ?>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Country</label>
          <?= countryFieldHtml($m['country'] ?? '', 'admin-edit', false) ?>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">City / State</label>
          <input type="text" name="city_state" value="<?= htmlspecialchars($m['city_state'] ?? '') ?>"
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Years of Research Experience</label>
          <select name="years_experience" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm">
            <?= optionsSelectHtml(YEARS_OPTIONS, $m['years_experience'] ?? '') ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">How did they hear about us?</label>
          <select name="referral_source" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm">
            <?= optionsSelectHtml(REFERRAL_OPTIONS, $m['referral_source'] ?? '') ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Membership Plan</label>
          <select name="plan_id" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm">
            <option value="">— None —</option>
            <?php foreach ($plans as $p): ?>
            <option value="<?= $p['id'] ?>" <?= (int)($m['plan_id']??0)===(int)$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Regional Section</label>
          <select name="section_id" class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm">
            <option value="">— None —</option>
            <?php foreach ($sections as $s): ?>
            <option value="<?= $s['id'] ?>" <?= (int)($m['section_id']??0)===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="flex flex-wrap gap-6 pt-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-gray-700">
          <input type="checkbox" name="newsletter_opt_in" value="1" <?= $m['newsletter_opt_in']?'checked':'' ?> class="accent-rarl-red w-4 h-4"/> Newsletter
        </label>
        <label class="flex items-center gap-2 text-xs font-semibold text-gray-700">
          <input type="checkbox" name="community_notify" value="1" <?= $m['community_notify']?'checked':'' ?> class="accent-rarl-red w-4 h-4"/> Community comment emails
        </label>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Replace Avatar</label>
          <input type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp" class="w-full text-xs"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1.5">Replace CV</label>
          <input type="file" name="cv_file" accept=".pdf,.doc,.docx" class="w-full text-xs"/>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Admin Notes <span class="text-gray-400 font-normal">(internal only, never shown to the member)</span></label>
        <textarea name="notes" rows="3" class="w-full px-4 py-2.5 bg-amber-50 border border-amber-200 rounded-xl text-sm resize-none"><?= htmlspecialchars($m['notes'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="w-full py-3 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl transition-all text-sm shadow-lg">Save Changes</button>
    </form>
  </div>

  <!-- Sidebar: files + read-only info -->
  <div class="space-y-5">
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-3">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-1">Files</h2>
      <div class="text-xs">
        <p class="text-gray-500 mb-1">CV / Resume</p>
        <?php if (!empty($m['cv_path'])): ?>
        <a href="../uploads/cv/<?= urlencode($m['cv_path']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg font-semibold hover:bg-blue-100">📄 View CV</a>
        <?php else: ?>
        <span class="text-gray-300">Not uploaded</span>
        <?php endif; ?>
      </div>
      <div class="text-xs">
        <p class="text-gray-500 mb-1">ID Card</p>
        <?php if (!empty($m['id_card_path'])): ?>
        <a href="../uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-lg font-semibold hover:bg-purple-100">🪪 <?= htmlspecialchars($m['member_code'] ?? '') ?></a>
        <p class="text-gray-400 mt-1">Expires <?= $m['id_card_expires_at'] ? date('d M Y', strtotime($m['id_card_expires_at'])) : '—' ?></p>
        <?php else: ?>
        <span class="text-gray-300">Not generated</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-2 text-xs">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-1">Account Info</h2>
      <div class="flex justify-between"><span class="text-gray-500">UUID</span><span class="text-gray-700 font-mono text-[10px]"><?= htmlspecialchars($m['uuid']) ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Email verified</span><span class="text-gray-700"><?= $m['email_verified_at'] ? date('d M Y', strtotime($m['email_verified_at'])) : '—' ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Last login</span><span class="text-gray-700"><?= $m['last_login_at'] ? date('d M Y H:i', strtotime($m['last_login_at'])) : '—' ?></span></div>
      <div class="flex justify-between"><span class="text-gray-500">Welcome email sent</span><span class="text-gray-700"><?= $m['discord_invited'] ? 'Yes' : 'No' ?></span></div>
    </div>
  </div>
</div>
<?php }, 'members', 'Edit Member');
