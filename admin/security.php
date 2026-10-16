<?php
/**
 * RARL Admin — Security: two-step verification (TOTP) for the admin sign-in.
 * Turning it on: scan the QR in an authenticator app, confirm one code (so a
 * mis-scan can't lock you out), save the backup codes shown once.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$newBackupCodes = function (): array {
    $codes = []; $hashes = [];
    for ($i = 0; $i < 10; $i++) {
        $c = strtoupper(bin2hex(random_bytes(4)));
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
        $hashes[] = password_hash(str_replace('-', '', $codes[$i]), PASSWORD_DEFAULT);
    }
    settingSet('admin_totp_backup', json_encode($hashes));
    return $codes;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'start' && !adminTwoFactorEnabled()) {
        $_SESSION['totp_setup'] = base32Encode(random_bytes(20));
    } elseif ($action === 'confirm' && !empty($_SESSION['totp_setup'])) {
        if (totpVerify($_SESSION['totp_setup'], $_POST['code'] ?? '') !== null) {
            settingSet('admin_totp_secret', $_SESSION['totp_setup']);
            settingSet('admin_totp_enabled', '1');
            unset($_SESSION['totp_setup']);
            $_SESSION['show_backup'] = $newBackupCodes();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Two-step verification is on. Save your backup codes now.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That code didn\'t match. Make sure the phone\'s clock is set automatically and try the current code.'];
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['totp_setup']);
    } elseif ($action === 'disable' && adminTwoFactorEnabled()) {
        if (totpVerify(setting('admin_totp_secret'), $_POST['code'] ?? '') !== null || adminUseBackupCode($_POST['code'] ?? '')) {
            settingSet('admin_totp_enabled', '0'); settingSet('admin_totp_secret', ''); settingSet('admin_totp_backup', '[]');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Two-step verification turned off.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Enter a current code (or a backup code) to turn it off.'];
        }
    } elseif ($action === 'regen' && adminTwoFactorEnabled()) {
        $_SESSION['show_backup'] = $newBackupCodes();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'New backup codes created; the old ones no longer work.'];
    }
    header('Location: security.php'); exit;
}

$setup = $_SESSION['totp_setup'] ?? null;
$backup = $_SESSION['show_backup'] ?? null; unset($_SESSION['show_backup']);
$left = count(json_decode(setting('admin_totp_backup', '[]'), true) ?: []);

adminWrap(function () use ($setup, $backup, $left) {
    adminFlash();
    $on = adminTwoFactorEnabled(); ?>
<div class="rarl-page-head"><div><h1>Security</h1><p>Protect the admin account with two-step verification: after the password, sign-in also needs a code from your phone.</p></div></div>

<div class="max-w-2xl space-y-6">
  <?php if ($backup): ?>
  <div class="rarl-card p-6 border-amber-300">
    <h2 class="rarl-card-title"><i class="fa-solid fa-key text-amber-600"></i> Your backup codes</h2>
    <p class="text-sm text-[color:var(--muted-fg)] mt-1">Each code signs you in once if you lose your phone. Store them somewhere safe (a password manager is ideal). They won't be shown again.</p>
    <ol class="grid grid-cols-2 gap-2 mt-4 font-mono text-base" id="codes"><?php foreach ($backup as $c): ?><li class="px-3 py-2 rounded-lg bg-[color:var(--muted)] text-center"><?= ex($c) ?></li><?php endforeach; ?></ol>
    <div class="flex gap-2 mt-4">
      <button type="button" class="rarl-btn" onclick="navigator.clipboard.writeText(<?= ex(json_encode(implode("\n", $backup))) ?>).then(function(){rarlToast('Copied','success')})"><i class="fa-solid fa-copy"></i> Copy</button>
      <button type="button" class="rarl-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
  </div>
  <?php endif; ?>

  <div class="rarl-card p-6">
    <div class="flex items-start gap-4">
      <span class="w-12 h-12 rounded-2xl flex items-center justify-center text-xl flex-shrink-0 <?= $on ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' ?>"><i class="fa-solid <?= $on ? 'fa-shield-halved' : 'fa-triangle-exclamation' ?>"></i></span>
      <div class="flex-1">
        <h2 class="rarl-card-title">Two-step verification is <?= $on ? 'on' : 'off' ?></h2>
        <p class="text-sm text-[color:var(--muted-fg)] mt-1"><?= $on ? 'Signing in needs your password and a 6-digit code from your authenticator app. ' . $left . ' backup code' . ($left === 1 ? '' : 's') . ' left.' : 'Anyone who learns the admin password can sign in. Turn this on: it takes a minute with Google Authenticator, Microsoft Authenticator, 1Password or Authy.' ?></p>
      </div>
    </div>

    <?php if (!$on && !$setup): ?>
    <form method="POST" class="mt-5"><?= acsrfField() ?><input type="hidden" name="action" value="start"><button class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-shield-halved"></i> Turn on two-step verification</button></form>

    <?php elseif (!$on && $setup): $uri = 'otpauth://totp/' . rawurlencode('RARL Admin:' . ADMIN_USERNAME) . '?secret=' . $setup . '&issuer=' . rawurlencode('RARL Admin') . '&digits=6&period=30'; ?>
    <ol class="mt-6 space-y-6">
      <li class="flex gap-4"><span class="w-7 h-7 rounded-full bg-gray-900 text-white text-sm font-bold flex items-center justify-center flex-shrink-0">1</span>
        <div class="flex-1"><p class="font-semibold">Scan this with your authenticator app</p>
          <div class="mt-3 flex flex-wrap items-center gap-5">
            <img src="<?= qrDataUri($uri, 5, 2) ?>" alt="Two-step verification QR code" class="w-44 h-44 rounded-xl border border-[color:var(--border)] [image-rendering:pixelated]"/>
            <div class="text-sm text-[color:var(--muted-fg)] max-w-[16rem]">Can't scan? Choose "enter a setup key" and type:<p class="mt-2 font-mono text-[color:var(--fg)] break-all select-all bg-[color:var(--muted)] rounded-lg p-2"><?= trim(chunk_split($setup, 4, ' ')) ?></p></div>
          </div></div></li>
      <li class="flex gap-4"><span class="w-7 h-7 rounded-full bg-gray-900 text-white text-sm font-bold flex items-center justify-center flex-shrink-0">2</span>
        <form method="POST" class="flex-1"><?= acsrfField() ?><input type="hidden" name="action" value="confirm">
          <label for="c1" class="font-semibold block">Enter the 6-digit code it shows</label>
          <div class="flex gap-2 mt-3"><input id="c1" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="rarl-input !w-40 text-center font-mono text-lg tracking-[.3em]" placeholder="000000"/><button class="rarl-btn rarl-btn-primary">Turn on</button></div>
        </form></li>
    </ol>
    <form method="POST" class="mt-6"><?= acsrfField() ?><input type="hidden" name="action" value="cancel"><button class="text-sm text-[color:var(--muted-fg)] underline">Cancel setup</button></form>

    <?php else: ?>
    <div class="mt-5 flex flex-wrap gap-3 items-end">
      <form method="POST" data-confirm="Create new backup codes? The current ones stop working."><?= acsrfField() ?><input type="hidden" name="action" value="regen"><button class="rarl-btn"><i class="fa-solid fa-rotate"></i> New backup codes</button></form>
      <form method="POST" class="flex gap-2 items-end ms-auto"><?= acsrfField() ?><input type="hidden" name="action" value="disable">
        <div><label for="c2" class="rarl-label">Code to turn off</label><input id="c2" name="code" required inputmode="numeric" maxlength="11" class="rarl-input !w-36 font-mono" placeholder="000000"/></div>
        <button class="rarl-btn text-red-600" data-confirm="Turn off two-step verification?">Turn off</button></form>
    </div>
    <?php endif; ?>
  </div>

  <div class="rarl-card p-6 text-sm">
    <h2 class="rarl-card-title mb-2">Also protecting the admin</h2>
    <ul class="space-y-1.5 text-[color:var(--muted-fg)]">
      <li><i class="fa-solid fa-check text-green-600"></i> Sign-in is locked for 15 minutes after 5 failed attempts from the same address.</li>
      <li><i class="fa-solid fa-check text-green-600"></i> Sessions end after <?= (int)(ADMIN_TIMEOUT / 3600) ?> hours of inactivity.</li>
      <li><i class="fa-solid fa-check text-green-600"></i> Lost your phone and your backup codes? Clear <code>admin_totp_enabled</code> in the database's <code>settings</code> table to sign in with the password only.</li>
    </ul>
  </div>
</div>
<?php }, 'security', 'Security');
