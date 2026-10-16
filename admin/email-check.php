<?php
/**
 * RARL Admin — Email check.
 * Shows how this site sends email and sends a test message, reporting the mail
 * server's actual answer. "Sent" in the queue only means the server accepted
 * the message; this page helps find out why accepted mail never arrives.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk() && ($_POST['action'] ?? '') === 'test') {
    $to = trim($_POST['to'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'msg' => 'Enter a valid email address.', 'log' => []];
    } else {
        @set_time_limit(60);
        $GLOBALS['rarl_mail_error'] = null; $GLOBALS['rarl_mail_log'] = [];
        $body = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;border:1px solid #eee;border-radius:12px">'
              . '<img src="' . htmlspecialchars(emailLogoUrl()) . '" width="40" height="40" alt="RARL"/>'
              . '<h2 style="margin:16px 0 8px">Test email from RARL Community</h2>'
              . '<p>If you can read this, email from <b>' . htmlspecialchars(SITE_URL) . '</b> is being delivered.</p>'
              . '<p style="color:#666;font-size:13px">Sent ' . date('d M Y, H:i') . ' via ' . (SMTP_HOST !== '' ? 'SMTP (' . htmlspecialchars(SMTP_HOST) . ')' : 'PHP mail()') . '.</p></div>';
        // Optionally attach a real certificate PDF, to compare with/without an attachment.
        $attach = [];
        if (!empty($_POST['with_pdf'])) {
            $pdfName = $pdo->query("SELECT pdf_path FROM certificates WHERE pdf_path IS NOT NULL AND pdf_path <> '' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $pdfFile = $pdfName ? UPLOADS_PATH . '/certificates/' . basename($pdfName) : '';
            if ($pdfFile && is_file($pdfFile)) $attach = [['path' => $pdfFile, 'filename' => 'Test certificate.pdf']];
        }
        $t0 = microtime(true);
        $ok = sendEmail($to, '', 'RARL Community — test email' . ($attach ? ' (with PDF)' : ''), $body, $attach);
        $result = ['ok' => $ok, 'to' => $to, 'ms' => (int)((microtime(true) - $t0) * 1000),
                   'pdf' => $attach ? round(filesize($attach[0]['path']) / 1024) . ' KB PDF attached' : (!empty($_POST['with_pdf']) ? 'No certificate PDF found to attach' : ''),
                   'msg' => $ok ? null : ($GLOBALS['rarl_mail_error'] ?? 'The mail server refused the message.'),
                   'log' => $GLOBALS['rarl_mail_log'] ?? []];
    }
}

$recentFailed = [];
$recentSent = 0;
if (emailQueueReady()) {
    $recentFailed = $pdo->query("SELECT to_email, subject, attempts, last_error, created_at FROM email_queue WHERE status = 'failed' ORDER BY id DESC LIMIT 8")->fetchAll();
    $recentSent = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
}

adminWrap(function () use ($result, $recentFailed, $recentSent) {
    $smtp = SMTP_HOST !== '';
    $fromDomain = strtolower(substr((string)strrchr(MAIL_FROM_EMAIL, '@'), 1));
    $siteHost = strtolower((string)parse_url(SITE_URL, PHP_URL_HOST));
    $row = fn($k, $v, $warn = '') => '<div class="flex flex-wrap gap-x-4 py-2.5 border-b border-gray-100 last:border-0"><dt class="w-40 text-gray-500">' . $k . '</dt><dd class="flex-1 font-medium text-gray-900 break-all">' . $v . ($warn ? '<span class="block text-xs font-normal text-amber-700 mt-0.5">' . $warn . '</span>' : '') . '</dd></div>';
?>
<h1 class="font-heading font-bold text-2xl text-gray-900">Email check</h1>
<p class="text-gray-500 text-sm mb-6">See how this site sends email and send yourself a test. &ldquo;Sent&rdquo; in the outbox means the mail server accepted the message, not that it reached the inbox.</p>

<div class="grid lg:grid-cols-2 gap-5 items-start">
  <section class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
    <h2 class="font-semibold text-gray-900 mb-2">Current setup</h2>
    <dl class="text-sm">
      <?= $row('Sending method', $smtp ? 'SMTP: ' . ex(SMTP_HOST) . ':' . (int)SMTP_PORT . ' (' . ex(SMTP_SECURE) . ')' : 'PHP mail() (server default)',
          $smtp ? '' : 'Recommended: send through the real mailbox with SMTP. Messages from PHP mail() are often filtered as spam or silently dropped by Gmail and Outlook.') ?>
      <?= $smtp ? $row('SMTP login', ex(SMTP_USER), SMTP_PASS === '' ? 'SMTP_PASS is empty.' : '') : '' ?>
      <?= $row('From', ex(MAIL_FROM_NAME) . ' &lt;' . ex(MAIL_FROM_EMAIL) . '&gt;', $smtp && strcasecmp(SMTP_USER, MAIL_FROM_EMAIL) !== 0 ? 'The From address differs from the SMTP login. Many servers reject or flag that; use the same mailbox for both.' : '') ?>
      <?= $row('Reply-To', ex(MAIL_REPLY_TO)) ?>
      <?= $row('Site address', ex(SITE_URL), $siteHost && !str_ends_with($siteHost, $fromDomain) ? 'The site and the From address use different domains.' : '') ?>
      <?= $row('Email logo', '<a class="text-rarl-red hover:underline" target="_blank" href="' . ex(emailLogoUrl()) . '">' . ex(emailLogoUrl()) . '</a>') ?>
      <?= $row('Sent in last 24 h', number_format($recentSent)) ?>
    </dl>
  </section>

  <section class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm">
    <h2 class="font-semibold text-gray-900 mb-2">Send a test email</h2>
    <form method="post" id="test-form" class="flex flex-col sm:flex-row gap-2" data-loading>
      <?= acsrfField() ?><input type="hidden" name="action" value="test">
      <label class="sr-only" for="to">Send to</label>
      <input id="to" name="to" type="email" required value="<?= ex($result['to'] ?? '') ?>" placeholder="you@gmail.com" class="flex-1 min-h-[44px] px-3 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
      <button class="min-h-[44px] px-5 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-bold rounded-xl"><i class="fa-solid fa-paper-plane"></i> Send test</button>
    </form>
    <label class="flex items-center gap-2 text-sm text-gray-700 mt-2"><input type="checkbox" name="with_pdf" value="1" form="test-form" class="accent-rarl-red" <?= !empty($_POST['with_pdf']) ? 'checked' : '' ?>> Attach a certificate PDF, like certificate emails do</label>
    <p class="text-xs text-gray-500 mt-1">Send one test with and one without the PDF to a Gmail address, and check Spam too. If only the plain one arrives, the attachment is the problem.</p>

    <?php if ($result): ?>
    <div class="mt-4 rounded-xl p-4 text-sm <?= $result['ok'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' ?>">
      <?php if ($result['ok']): ?>
        <p class="font-semibold"><i class="fa-solid fa-circle-check"></i> The mail server accepted the message (<?= (int)$result['ms'] ?> ms<?= !empty($result['pdf']) ? ', ' . ex($result['pdf']) : '' ?>).</p>
        <p class="mt-1">If it doesn&rsquo;t arrive within a few minutes (check Spam), the server is accepting mail it then can&rsquo;t deliver. <?= $smtp ? 'Check the mailbox&rsquo;s sent and bounced mail in cPanel or Webmail.' : 'Switch to SMTP (see below).' ?></p>
      <?php else: ?>
        <p class="font-semibold"><i class="fa-solid fa-triangle-exclamation"></i> Not sent</p>
        <p class="mt-1 break-words"><?= ex($result['msg']) ?></p>
      <?php endif; ?>
    </div>
    <?php if (!empty($result['log'])): ?>
    <details class="mt-3"><summary class="text-xs font-semibold text-gray-600 cursor-pointer">SMTP conversation</summary>
      <pre class="mt-2 p-3 bg-gray-900 text-gray-100 text-[11px] rounded-xl overflow-x-auto whitespace-pre-wrap"><?= ex(implode("\n", $result['log'])) ?></pre>
    </details>
    <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php if (!$smtp): ?>
<section class="mt-5 bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-900">
  <h2 class="font-semibold mb-1">Set up SMTP (recommended)</h2>
  <p>In cPanel &rarr; Email Accounts, open the mailbox <b><?= ex(MAIL_FROM_EMAIL) ?></b> &rarr; <i>Connect Devices</i> to see its outgoing server. Then add these lines to the site&rsquo;s <code>.env</code> on the server:</p>
  <pre class="mt-2 p-3 bg-white/70 rounded-lg text-xs overflow-x-auto">SMTP_HOST=mail.<?= ex($fromDomain ?: 'your-domain.com') ?>
SMTP_PORT=465
SMTP_SECURE=ssl
SMTP_USER=<?= ex(MAIL_FROM_EMAIL) ?>

SMTP_PASS=the mailbox password</pre>
  <p class="mt-2">Use the outgoing server name cPanel shows if it differs (on Namecheap it is often <code>server###.web-hosting.com</code>). Then send a test from this page.</p>
</section>
<?php endif; ?>

<?php if ($recentFailed): ?>
<section class="mt-5 bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
  <h2 class="font-semibold text-gray-900 px-5 pt-4">Recent failures</h2>
  <div class="overflow-x-auto"><table class="w-full text-sm mt-2">
    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500"><tr><th class="px-5 py-2">To</th><th class="px-3 py-2">Error</th><th class="px-3 py-2">When</th></tr></thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($recentFailed as $f): ?>
      <tr><td class="px-5 py-2 text-gray-800"><?= ex($f['to_email']) ?></td><td class="px-3 py-2 text-red-700 break-words"><?= ex($f['last_error'] ?: 'Unknown') ?></td><td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?= ex(date('d M H:i', strtotime($f['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php }, 'email-check', 'Email check');
