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
$displayNameFor = fn(array $mm) => $mm['type'] === 'lab' ? $mm['lab_name'] : $mm['full_name'];

// ── Admin-assist actions (resend/verify/reset/impersonate/regenerate) ──
// These let an admin unblock a stuck member without needing DB access.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && !empty($_POST['assist_action'])) {
    $assist = $_POST['assist_action'];

    if ($assist === 'resend_verification' && empty($m['email_verified_at'])) {
        $code = generateOtp($m['email'], 'verify');
        $memberName = $displayNameFor($m);
        ob_start(); require dirname(__DIR__) . '/emails/otp-verify.php'; $body = ob_get_clean();
        sendEmail($m['email'], $memberName, 'Verify your RARL Community email', $body);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Verification code resent to ' . $m['email'] . '.'];
    } elseif ($assist === 'mark_verified') {
        $pdo->prepare('UPDATE members SET email_verified_at = NOW() WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Email manually marked as verified.'];
    } elseif ($assist === 'approve_now') {
        $pdo->prepare("UPDATE members SET status = 'active' WHERE id = ?")->execute([$id]);
        $cardMsg = issueIdCard($id) ? ' ID card generated.' : '';
        issueMembershipCertificate($id);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Member approved and activated.' . $cardMsg . ' Membership certificate issued.'];
    } elseif ($assist === 'send_temp_password') {
        $temp = bin2hex(random_bytes(5));
        $pdo->prepare('UPDATE members SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_BCRYPT), $id]);
        $memberName = $displayNameFor($m); $tempPassword = $temp;
        ob_start(); require dirname(__DIR__) . '/emails/admin-temp-password.php'; $body = ob_get_clean();
        sendEmail($m['email'], $memberName, 'Your RARL Community password has been reset', $body);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Temporary password generated and emailed to ' . $m['email'] . '.'];
    } elseif ($assist === 'resend_welcome') {
        $memberName = $displayNameFor($m); $isApproval = false;
        ob_start(); require dirname(__DIR__) . '/emails/welcome.php'; $body = ob_get_clean();
        sendEmail($m['email'], $memberName, 'Welcome to RARL Community', $body);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Welcome email resent.'];
    } elseif ($assist === 'regenerate_id_card') {
        $ok = issueIdCard($id);
        $_SESSION['flash'] = $ok
            ? ['type'=>'success','msg'=>'ID card regenerated.']
            : ['type'=>'error','msg'=>'Could not generate ID card — member needs an avatar photo uploaded first.'];
    } elseif ($assist === 'add_email') {
        $newEmail = cleanEmail($_POST['new_email'] ?? '');
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Enter a valid email to link.'];
        } else {
            $dupe = $pdo->prepare('SELECT id FROM member_emails WHERE email = ?'); $dupe->execute([$newEmail]);
            if ($dupe->fetch()) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'That email is already linked to an account.'];
            } else {
                $pdo->prepare('INSERT INTO member_emails (member_id, email, label, verified_at) VALUES (?,?,?,NOW())')
                    ->execute([$id, $newEmail, clean($_POST['email_label'] ?? '') ?: null]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Linked ' . $newEmail . ' — they can now log in with it too.'];
            }
        }
    } elseif ($assist === 'remove_email') {
        $emailId = (int)($_POST['email_id'] ?? 0);
        $pdo->prepare('DELETE FROM member_emails WHERE id = ? AND member_id = ? AND is_primary = 0')->execute([$emailId, $id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Email unlinked.'];
    } elseif ($assist === 'upload_id_card') {
        // Manual override — hand-designed or one-off ID card, bypassing the
        // template renderer entirely. Accepts a PDF or an image.
        $filename = validateUpload($_FILES['custom_file'] ?? [], ['pdf','jpg','jpeg','png','webp'], 10*1024*1024, UPLOADS_PATH.'/id-cards');
        if (!$filename) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Upload failed — must be a PDF or image (JPG/PNG/WEBP), max 10MB.'];
        } else {
            $memberCode = $m['member_code'];
            if (!$memberCode) {
                $memberCode = nextMemberCode();
            }
            $pdo->prepare('UPDATE members SET member_code=?, id_card_path=?, id_card_issued_at=CURDATE(), id_card_expires_at=DATE_ADD(CURDATE(), INTERVAL 3 YEAR) WHERE id=?')
                ->execute([$memberCode, $filename, $id]);
            $sent = false;
            if (!empty($_POST['send_email'])) {
                $memberName = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
                $docLabel = 'ID card';
                ob_start(); require dirname(__DIR__) . '/emails/custom-document-ready.php'; $body = ob_get_clean();
                $sent = sendEmail($m['email'], $memberName, 'Your RARL ID Card', $body, [
                    ['path' => UPLOADS_PATH . '/id-cards/' . $filename, 'filename' => 'RARL-ID-Card.' . pathinfo($filename, PATHINFO_EXTENSION)],
                ]);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Custom ID card uploaded.' . ($sent ? ' Emailed to member.' : '')];
        }
    } elseif ($assist === 'upload_membership_cert') {
        $filename = validateUpload($_FILES['custom_file'] ?? [], ['pdf','jpg','jpeg','png','webp'], 10*1024*1024, UPLOADS_PATH.'/certificates');
        if (!$filename) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Upload failed — must be a PDF or image (JPG/PNG/WEBP), max 10MB.'];
        } else {
            $memberName = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
            $existing = $pdo->prepare("SELECT id, pdf_path, uuid FROM certificates WHERE member_id = ? AND cert_type = 'membership'");
            $existing->execute([$id]);
            $existing = $existing->fetch();
            if ($existing) {
                if ($existing['pdf_path']) @unlink(UPLOADS_PATH . '/certificates/' . $existing['pdf_path']);
                $pdo->prepare('UPDATE certificates SET pdf_path = ? WHERE id = ?')->execute([$filename, $existing['id']]);
            } else {
                $uuid = generateUuid(); $certNo = nextCertNumber();
                $pdo->prepare('INSERT INTO certificates (uuid, certificate_no, member_id, cert_type, recipient_name, recipient_email, event_id, pdf_path) VALUES (?,?,?,"membership",?,?,NULL,?)')
                    ->execute([$uuid, $certNo, $id, $memberName, $m['email'], $filename]);
            }
            $sent = false;
            if (!empty($_POST['send_email'])) {
                $docLabel = 'Certificate of Membership';
                ob_start(); require dirname(__DIR__) . '/emails/custom-document-ready.php'; $body = ob_get_clean();
                $sent = sendEmail($m['email'], $memberName, 'Your RARL Certificate of Membership', $body, [
                    ['path' => UPLOADS_PATH . '/certificates/' . $filename, 'filename' => 'RARL-Membership-Certificate.' . pathinfo($filename, PATHINFO_EXTENSION)],
                ]);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Custom certificate uploaded.' . ($sent ? ' Emailed to member.' : '')];
        }
    }
    header('Location: member-edit.php?id=' . $id); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $email      = cleanEmail($_POST['email'] ?? '');
    $status     = in_array($_POST['status'] ?? '', ['pending','active','inactive'], true) ? $_POST['status'] : $m['status'];
    $planId     = (int)($_POST['plan_id'] ?? 0) ?: null;
    $sectionId  = (int)($_POST['section_id'] ?? 0) ?: null;
    $country    = clean($_POST['country'] ?? '');
    $cityState  = clean($_POST['city_state'] ?? '');
    $newsletter = isset($_POST['newsletter_opt_in']) ? 1 : 0;
    $commNotify = isset($_POST['community_notify']) ? 1 : 0;
    $dirVisible = isset($_POST['directory_visible']) ? 1 : 0;
    $yearsExp   = clean($_POST['years_experience'] ?? '');
    $referral   = clean($_POST['referral_source']  ?? '');
    $notes      = clean($_POST['notes'] ?? '');
    $memberCode = strtoupper(trim(clean($_POST['member_code'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($yearsExp !== '' && !array_key_exists($yearsExp, YEARS_OPTIONS)) $errors[] = 'Invalid years of experience value.';
    if ($referral !== '' && !array_key_exists($referral, REFERRAL_OPTIONS)) $errors[] = 'Invalid referral source value.';
    if ($memberCode !== '') {
        $dupeCode = $pdo->prepare('SELECT id FROM members WHERE member_code = ? AND id != ?');
        $dupeCode->execute([$memberCode, $id]);
        if ($dupeCode->fetch()) $errors[] = 'That Member ID is already assigned to another member.';
    }

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
            $cvUrl = cleanUrl($_POST['cv_url'] ?? '');
            $sql = 'UPDATE members SET email=?, status=?, plan_id=?, section_id=?, country=?, city_state=?, newsletter_opt_in=?, community_notify=?, directory_visible=?, years_experience=?, referral_source=?, notes=?, member_code=?, cv_url=?';
            $params = [$email, $status, $planId, $sectionId, $country, $cityState, $newsletter, $commNotify, $dirVisible, $yearsExp ?: null, $referral ?: null, $notes, $memberCode ?: null, $cvUrl ?: null];

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
$linkedEmails = $pdo->prepare('SELECT * FROM member_emails WHERE member_id = ? ORDER BY is_primary DESC, id');
$linkedEmails->execute([$id]);
$linkedEmails = $linkedEmails->fetchAll();

adminWrap(function() use ($m, $errors, $plans, $sections, $displayName, $linkedEmails) { ?>
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
    <p class="text-gray-500 text-sm"><?= $m['type'] === 'lab' ? '<i class="fa-solid fa-building-columns"></i> Research Lab' : '<i class="fa-solid fa-user-graduate"></i> Individual Researcher' ?> · Joined <?= date('d M Y', strtotime($m['created_at'])) ?></p>
  </div>
</div>

<?php if ($errors): ?>
<div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm space-y-1">
  <?php foreach ($errors as $e): ?><div class="flex items-start gap-2"><span><i class="fa-solid fa-triangle-exclamation"></i></span><span><?= htmlspecialchars($e) ?></span></div><?php endforeach; ?>
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

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">Member ID <span class="text-gray-400 font-normal">(shown on ID card/certificates — leave blank to auto-generate on approval)</span></label>
        <input type="text" name="member_code" value="<?= htmlspecialchars($m['member_code'] ?? '') ?>" placeholder="e.g. FRE0042"
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
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
        <label class="flex items-center gap-2 text-xs font-semibold text-gray-700">
          <input type="checkbox" name="directory_visible" value="1" <?= ($m['directory_visible']??1)?'checked':'' ?> class="accent-rarl-red w-4 h-4"/> Visible on public roster
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
        <label class="block text-xs font-semibold text-gray-600 mb-1.5">CV / Resume Link <span class="text-gray-400 font-normal">(e.g. Google Drive — used if no file is uploaded above)</span></label>
        <input type="url" name="cv_url" value="<?= htmlspecialchars($m['cv_url'] ?? '') ?>" placeholder="https://drive.google.com/..."
          class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
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
        <a href="../uploads/cv/<?= urlencode($m['cv_path']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg font-semibold hover:bg-blue-100"><i class="fa-solid fa-file-lines"></i> View CV</a>
        <?php elseif (!empty($m['cv_url'])): ?>
        <a href="<?= htmlspecialchars($m['cv_url']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg font-semibold hover:bg-blue-100"><i class="fa-solid fa-arrow-up-right-from-square"></i> CV Link (external)</a>
        <?php else: ?>
        <span class="text-gray-300">Not uploaded</span>
        <?php endif; ?>
      </div>
      <div class="text-xs">
        <p class="text-gray-500 mb-1">ID Card</p>
        <?php if (!empty($m['id_card_path'])): ?>
        <a href="../uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-lg font-semibold hover:bg-purple-100"><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($m['member_code'] ?? '') ?></a>
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
      <div class="flex justify-between"><span class="text-gray-500">Must change password</span><span class="text-gray-700"><?= !empty($m['must_change_password']) ? '<i class="fa-solid fa-triangle-exclamation"></i> Yes' : 'No' ?></span></div>
    </div>

    <!-- Admin Assist -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-2">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-1"><i class="fa-solid fa-life-ring"></i> Admin Assist</h2>
      <p class="text-[11px] text-gray-400 mb-2">Common help-desk actions for this member.</p>
      <?php
        $assistBtn = function(string $action, string $label, string $confirmMsg = '', string $classes = 'bg-gray-50 hover:bg-gray-100 text-gray-700') {
            $onsubmit = $confirmMsg ? " onsubmit=\"return confirm('" . htmlspecialchars($confirmMsg, ENT_QUOTES) . "')\"" : '';
            echo '<form method="POST"' . $onsubmit . '>' . acsrfField()
               . '<input type="hidden" name="assist_action" value="' . $action . '">'
               . '<button type="submit" class="w-full text-left px-3 py-2 text-xs font-semibold ' . $classes . ' rounded-lg transition-colors">' . $label . '</button></form>';
        };
      ?>
      <?php if (empty($m['email_verified_at'])): ?>
        <?php $assistBtn('resend_verification', '<i class="fa-solid fa-envelope"></i> Resend verification email'); ?>
        <?php $assistBtn('mark_verified', '<i class="fa-solid fa-circle-check"></i> Manually mark email verified'); ?>
      <?php endif; ?>
      <?php if ($m['status'] === 'pending'): ?>
        <?php $assistBtn('approve_now', '<i class="fa-solid fa-thumbs-up"></i> Approve & activate now', '', 'bg-green-50 hover:bg-green-100 text-green-700'); ?>
      <?php endif; ?>
      <?php $assistBtn('send_temp_password', '<i class="fa-solid fa-key"></i> Reset password & email temp password', 'Generate a new temporary password and email it to this member?'); ?>
      <?php $assistBtn('resend_welcome', '<i class="fa-solid fa-envelope"></i> Resend welcome email'); ?>
      <?php $assistBtn('regenerate_id_card', '<i class="fa-solid fa-id-card"></i> Regenerate ID card'); ?>
    </div>

    <!-- Custom ID card / certificate upload — bypasses the template renderer
         entirely for one-off hand-designed documents (PDF or image). -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-4">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-1"><i class="fa-solid fa-upload"></i> Custom ID Card / Certificate</h2>
      <p class="text-[11px] text-gray-400 -mt-2">Upload a hand-made ID card or certificate for this member (PDF or image) — overrides the auto-generated one.</p>

      <form method="POST" enctype="multipart/form-data" class="space-y-2">
        <?= acsrfField() ?><input type="hidden" name="assist_action" value="upload_id_card">
        <label class="block text-[10px] font-semibold text-gray-500">ID Card</label>
        <input type="file" name="custom_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required class="w-full text-xs"/>
        <label class="flex items-center gap-1.5 text-[10px] text-gray-500"><input type="checkbox" name="send_email" value="1" checked class="accent-rarl-red"/> Email it to the member</label>
        <button type="submit" class="w-full py-2 text-xs font-semibold bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg transition-colors">Upload ID Card</button>
      </form>

      <form method="POST" enctype="multipart/form-data" class="space-y-2 pt-3 border-t border-gray-100">
        <?= acsrfField() ?><input type="hidden" name="assist_action" value="upload_membership_cert">
        <label class="block text-[10px] font-semibold text-gray-500">Certificate of Membership</label>
        <input type="file" name="custom_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required class="w-full text-xs"/>
        <label class="flex items-center gap-1.5 text-[10px] text-gray-500"><input type="checkbox" name="send_email" value="1" checked class="accent-rarl-red"/> Email it to the member</label>
        <button type="submit" class="w-full py-2 text-xs font-semibold bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg transition-colors">Upload Certificate</button>
      </form>
    </div>

    <!-- Linked emails -->
    <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-2">
      <h2 class="font-heading font-bold text-sm text-gray-800 mb-1">Linked Emails</h2>
      <?php foreach ($linkedEmails as $le): ?>
      <div class="flex items-center justify-between gap-2 text-xs p-2 bg-gray-50 rounded-lg">
        <span class="truncate"><?= htmlspecialchars($le['email']) ?> <?= $le['is_primary'] ? '· primary' : ($le['verified_at'] ? '· verified' : '· pending') ?></span>
        <?php if (!$le['is_primary']): ?>
        <form method="POST" onsubmit="return confirm('Unlink this email?')"><?= acsrfField() ?><input type="hidden" name="assist_action" value="remove_email"><input type="hidden" name="email_id" value="<?= $le['id'] ?>">
          <button type="submit" class="text-red-500 font-semibold flex-shrink-0"><i class="fa-solid fa-xmark"></i></button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <form method="POST" class="flex gap-1.5 pt-2">
        <?= acsrfField() ?><input type="hidden" name="assist_action" value="add_email">
        <input type="email" name="new_email" placeholder="Add email" required class="flex-1 min-w-0 px-2 py-1.5 border border-gray-300 rounded-lg text-xs"/>
        <button type="submit" class="px-2.5 py-1.5 bg-gray-800 text-white text-xs font-semibold rounded-lg flex-shrink-0">+</button>
      </form>
    </div>
  </div>
</div>
<?php }, 'members', 'Edit Member');
