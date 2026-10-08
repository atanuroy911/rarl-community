<?php
/**
 * RARL — Verify Email: Consume OTP Code
 * On success: active accounts are signed straight in; pending applicants see
 * an "application received" screen explaining what happens next (instead of
 * being sent to a sign-in page that would only refuse them).
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!empty($_SESSION['member_id'])) redirect('dashboard.php');

$email = cleanEmail($_GET['email'] ?? $_POST['email'] ?? '');
$error = '';
$resendMsg = '';
$verified = null; // set to the member row once verified and still pending

// Generic message used whenever we don't want to reveal whether the account
// exists or is already verified — avoids email enumeration.
$genericRedirect = function (string $msg) {
    flash('info', $msg);
    redirect('login.php');
};

if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $stmt = db()->prepare('SELECT * FROM members WHERE email = ?');
    $stmt->execute([$email]);
    $member = $stmt->fetch();
} else {
    $member = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        $error = 'Your session expired — please try again.';
    } elseif (isset($_POST['resend'])) {
        if (!$member || $member['email_verified_at']) {
            $genericRedirect('If that account needs verifying, a new code has been sent to its email address.');
        } else {
            $wait = otpCooldownSecondsLeft($email, 'verify_email');
            if ($wait > 0) {
                $error = "Please wait {$wait}s before requesting another code.";
            } else {
                $code = generateOtp($email, 'verify_email');
                $memberName = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
                ob_start(); require __DIR__ . '/emails/otp-verify.php'; $body = ob_get_clean();
                sendEmail($email, $memberName, 'Your RARL verification code', $body);
                $resendMsg = 'A new code is on its way. It can take a minute — check spam/promotions too.';
            }
        }
    } else {
        $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
        if (!$member || $member['email_verified_at']) {
            $genericRedirect('If that account needed verifying, this step is complete — you can sign in.');
        } elseif (strlen($code) !== 6 || !verifyOtp($email, 'verify_email', $code)) {
            $error = 'That code is not right or has expired. Check the latest email, or request a new code.';
        } else {
            db()->prepare('UPDATE members SET email_verified_at = NOW() WHERE email = ?')->execute([$email]);
            if ($member['status'] === 'active') {
                memberSignIn($member);
                flash('success', 'Email verified — welcome to RARL!');
                redirect('dashboard.php');
            }
            $verified = $member;
        }
    }
}

$cooldown = ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? otpCooldownSecondsLeft($email, 'verify_email') : 0;
$needsReview = REQUIRE_APPROVAL;

echo htmlHead($verified ? 'Application received' : 'Verify your email');
?>
<?= publicNav() ?>

<div class="min-h-[calc(100vh-64px)] bg-gray-50 dark:bg-gray-950 flex items-start sm:items-center justify-center py-14 px-4">
  <div class="w-full <?= $verified ? 'max-w-lg' : 'max-w-md' ?>">
    <?= memberFlowSteps($verified ? 3 : 2, $needsReview) ?>

    <?php if ($verified): $name = $verified['type'] === 'lab' ? $verified['lab_name'] : $verified['full_name']; ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-8 shadow-sm text-center">
      <div class="w-16 h-16 mx-auto rounded-2xl bg-green-100 text-green-600 flex items-center justify-center text-2xl mb-4"><i class="fa-solid fa-check"></i></div>
      <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white">Thanks, <?= htmlspecialchars(explode(' ', preg_replace('/^(dr|prof)\.?\s+/i', '', (string)$name))[0]) ?> — you're verified</h1>
      <p class="text-gray-500 text-sm mt-2">Your application is now with the RARL team.</p>
      <div class="text-left mt-7 space-y-4">
        <div class="flex gap-3"><span class="w-8 h-8 rounded-full bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xs font-bold flex-shrink-0">1</span><div><p class="text-sm font-semibold text-gray-900 dark:text-white">We review your profile</p><p class="text-xs text-gray-500">Usually within a few working days.</p></div></div>
        <div class="flex gap-3"><span class="w-8 h-8 rounded-full bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xs font-bold flex-shrink-0">2</span><div><p class="text-sm font-semibold text-gray-900 dark:text-white">You get an approval email</p><p class="text-xs text-gray-500">Sent to <strong><?= htmlspecialchars($verified['email']) ?></strong>, with your membership certificate.</p></div></div>
        <div class="flex gap-3"><span class="w-8 h-8 rounded-full bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xs font-bold flex-shrink-0">3</span><div><p class="text-sm font-semibold text-gray-900 dark:text-white">Sign in and add a photo</p><p class="text-xs text-gray-500">Your verifiable member ID card is created from it instantly.</p></div></div>
      </div>
      <div class="mt-8 flex flex-col sm:flex-row gap-2 justify-center">
        <a href="events.php" class="px-5 py-2.5 bg-gray-900 dark:bg-white dark:text-gray-900 text-white text-sm font-semibold rounded-xl">Browse public events</a>
        <a href="<?= MAIN_SITE_URL ?>" class="px-5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 text-sm font-semibold rounded-xl">Visit the RARL website</a>
      </div>
    </div>
    <?php else: ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-8 shadow-sm">
      <div class="text-center">
        <div class="w-14 h-14 mx-auto rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-xl mb-4"><i class="fa-solid fa-envelope-open-text"></i></div>
        <h1 class="font-heading font-black text-2xl text-gray-900 dark:text-white">Check your inbox</h1>
        <p class="text-gray-500 text-sm mt-1.5"><?= $email ? 'We sent a 6-digit code to <strong class="text-gray-800 dark:text-gray-200">' . htmlspecialchars($email) . '</strong>.' : 'Enter your email and the 6-digit code we sent you.' ?></p>
      </div>

      <div class="mt-6"><?= renderFlash() ?></div>
      <?php if ($error): ?>
      <div class="mb-4 flex items-start gap-3 p-3.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-red-700 dark:text-red-300 text-sm" role="alert"><i class="fa-solid fa-triangle-exclamation mt-0.5"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($resendMsg): ?>
      <div class="mb-4 flex items-start gap-3 p-3.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl text-blue-700 dark:text-blue-300 text-sm"><i class="fa-solid fa-paper-plane mt-0.5"></i> <?= htmlspecialchars($resendMsg) ?></div>
      <?php endif; ?>

      <form method="POST" id="otp-form" class="space-y-5">
        <?= csrfField() ?>
        <?php if ($email): ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>"/>
        <?php else: ?><div><label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email address</label><input type="email" name="email" required class="w-full h-12 px-4 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-rarl-red/15 focus:border-rarl-red"/></div><?php endif; ?>
        <input type="hidden" name="code" id="code"/>
        <div class="flex justify-center gap-2" id="otp-boxes">
          <?php for ($i = 0; $i < 6; $i++): ?>
          <input type="text" inputmode="numeric" maxlength="1" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="Digit <?= $i + 1 ?>"
            class="w-11 h-14 sm:w-12 sm:h-14 text-center text-2xl font-black bg-white dark:bg-gray-900 border-2 border-gray-200 dark:border-gray-700 rounded-xl focus:outline-none focus:border-rarl-red focus:ring-4 focus:ring-rarl-red/15 transition"/>
          <?php endfor; ?>
        </div>
        <button type="submit" id="verify-btn" class="w-full h-12 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl text-sm shadow-lg shadow-rarl-red/20 disabled:opacity-50" disabled>Verify email</button>
      </form>

      <form method="POST" class="mt-5 text-center" id="resend-form">
        <?= csrfField() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>"/><input type="hidden" name="resend" value="1"/>
        <p class="text-xs text-gray-500">Didn't get it? <button type="submit" id="resend-btn" class="text-rarl-red font-semibold hover:underline disabled:text-gray-400 disabled:no-underline" <?= $cooldown > 0 ? 'disabled' : '' ?>>Resend code<span id="resend-wait"><?= $cooldown > 0 ? ' in ' . (int)$cooldown . 's' : '' ?></span></button></p>
        <p class="text-[11px] text-gray-400 mt-1">Check spam or promotions. Codes expire after a while — always use the newest one.</p>
      </form>
    </div>
    <p class="text-center text-xs text-gray-400 mt-5">Wrong email? <a href="register.php" class="text-rarl-red hover:underline font-semibold">Start again</a> · <a href="login.php" class="hover:underline">Sign in</a></p>
    <?php endif; ?>
  </div>
</div>
<script>
  // Registration finished — drop any saved form drafts.
  try { Object.keys(localStorage).filter(k => k.startsWith('rarl-draft-')).forEach(k => localStorage.removeItem(k)); } catch (e) {}
  (function() {
    const boxes = [...document.querySelectorAll('#otp-boxes input')]; if (!boxes.length) return;
    const code = document.getElementById('code'), btn = document.getElementById('verify-btn'), form = document.getElementById('otp-form');
    const sync = () => { code.value = boxes.map(b => b.value).join(''); btn.disabled = code.value.length !== 6; if (code.value.length === 6 && (!form.email || form.email.value)) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying…'; form.submit(); } };
    const fill = (start, digits) => { digits.split('').forEach((d, i) => { if (boxes[start + i]) boxes[start + i].value = d; }); boxes[Math.min(start + digits.length, 5)].focus(); sync(); };
    boxes.forEach((b, i) => {
      b.addEventListener('input', () => { const d = b.value.replace(/\D/g, ''); if (d.length > 1) { fill(i, d.slice(0, 6 - i)); return; } b.value = d; if (d && boxes[i + 1]) boxes[i + 1].focus(); sync(); });
      b.addEventListener('keydown', e => { if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].value = ''; boxes[i - 1].focus(); sync(); } if (e.key === 'ArrowLeft' && boxes[i - 1]) boxes[i - 1].focus(); if (e.key === 'ArrowRight' && boxes[i + 1]) boxes[i + 1].focus(); });
      b.addEventListener('paste', e => { const d = (e.clipboardData.getData('text') || '').replace(/\D/g, ''); if (d) { e.preventDefault(); fill(0, d.slice(0, 6)); } });
      b.addEventListener('focus', () => b.select());
    });
    (form.email && !form.email.value ? form.email : boxes[0]).focus();
    let wait = <?= (int)$cooldown ?>; const rb = document.getElementById('resend-btn'), rw = document.getElementById('resend-wait');
    if (wait > 0) { const iv = setInterval(() => { wait--; rw.textContent = wait > 0 ? ' in ' + wait + 's' : ''; if (wait <= 0) { rb.disabled = false; clearInterval(iv); } }, 1000); }
  })();
</script>
<?= publicFooter() ?>
</body></html>
