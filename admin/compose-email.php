<?php
/**
 * RARL Admin — Compose custom email
 * Targeted, ad-hoc admin messages (a single member, a chapter's members, or a raw
 * list of addresses) — distinct from admin/newsletter.php which is for opted-in
 * bulk broadcasts. This bypasses newsletter_opt_in since it's an admin-initiated
 * support/announcement message, not marketing.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$sections = $pdo->query("SELECT id, name FROM regional_sections ORDER BY continent, display_order")->fetchAll();
$plans    = $pdo->query("SELECT id, name FROM membership_plans ORDER BY display_order")->fetchAll();

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $subject   = clean($_POST['subject'] ?? '');
    $bodyMd    = $_POST['body'] ?? '';
    $mode      = $_POST['mode'] ?? 'raw';
    $isPreview = ($_POST['submit_action'] ?? '') === 'preview';

    $recipients = []; // [ ['email'=>..,'name'=>..], ... ]
    if ($mode === 'raw') {
        foreach (preg_split('/[,\n;]+/', $_POST['raw_emails'] ?? '') as $raw) {
            $e = cleanEmail(trim($raw));
            if ($e && filter_var($e, FILTER_VALIDATE_EMAIL)) $recipients[] = ['email' => $e, 'name' => $e];
        }
    } elseif ($mode === 'section') {
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $rows = $pdo->prepare("SELECT email, full_name, lab_name, type FROM members WHERE section_id = ? AND status = 'active'");
        $rows->execute([$sectionId]);
        foreach ($rows->fetchAll() as $r) $recipients[] = ['email' => $r['email'], 'name' => $r['type'] === 'lab' ? $r['lab_name'] : $r['full_name']];
    } elseif ($mode === 'plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $rows = $pdo->prepare("SELECT email, full_name, lab_name, type FROM members WHERE plan_id = ? AND status = 'active'");
        $rows->execute([$planId]);
        foreach ($rows->fetchAll() as $r) $recipients[] = ['email' => $r['email'], 'name' => $r['type'] === 'lab' ? $r['lab_name'] : $r['full_name']];
    } elseif ($mode === 'all_active') {
        $rows = $pdo->query("SELECT email, full_name, lab_name, type FROM members WHERE status = 'active'");
        foreach ($rows->fetchAll() as $r) $recipients[] = ['email' => $r['email'], 'name' => $r['type'] === 'lab' ? $r['lab_name'] : $r['full_name']];
    }

    $bodyHtml = markdownToHtml($bodyMd);

    if ($isPreview) {
        $memberName = $recipients[0]['name'] ?? 'there';
        ob_start(); require dirname(__DIR__) . '/emails/admin-message.php'; $previewHtml = ob_get_clean();
        $result = ['preview' => $previewHtml, 'count' => count($recipients), 'subject' => $subject, 'bodyMd' => $bodyMd, 'mode' => $mode,
                   'section_id' => $_POST['section_id'] ?? '', 'plan_id' => $_POST['plan_id'] ?? '', 'raw_emails' => $_POST['raw_emails'] ?? ''];
    } elseif (!$subject || !$bodyMd || empty($recipients)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Subject, body, and at least one recipient are required.'];
        header('Location: compose-email.php'); exit;
    } else {
        $sent = 0;
        foreach ($recipients as $r) {
            $memberName = $r['name'] ?: $r['email'];
            ob_start(); require dirname(__DIR__) . '/emails/admin-message.php'; $emailBody = ob_get_clean();
            if (sendEmail($r['email'], $memberName, $subject, $emailBody)) $sent++;
            usleep(50000);
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Sent to {$sent} of " . count($recipients) . " recipients."];
        header('Location: compose-email.php'); exit;
    }
}

adminWrap(function() use ($sections, $plans, $result) {
    adminFlash(); ?>
<h1 class="text-2xl font-black text-gray-900 mb-1">Compose Email</h1>
<p class="text-gray-500 text-sm mb-7">Send a targeted, one-off message — to a single address, a chapter's members, or a plan tier. For opted-in bulk broadcasts use <a href="newsletter.php" class="underline">Newsletter</a> instead.</p>

<?php if ($result): ?>
<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-6 shadow-sm mb-6">
  <h2 class="font-heading font-bold text-sm text-gray-800 mb-3">👁️ Preview <span class="text-gray-400 font-normal">(will send to <?= $result['count'] ?> recipients)</span></h2>
  <div class="border border-gray-200 rounded-xl p-4 bg-gray-50 max-h-96 overflow-y-auto">
    <?= $result['preview'] ?>
  </div>
  <form method="POST" class="mt-4">
    <?= acsrfField() ?>
    <input type="hidden" name="subject" value="<?= htmlspecialchars($result['subject']) ?>">
    <input type="hidden" name="body" value="<?= htmlspecialchars($result['bodyMd']) ?>">
    <input type="hidden" name="mode" value="<?= htmlspecialchars($result['mode']) ?>">
    <input type="hidden" name="section_id" value="<?= htmlspecialchars($result['section_id']) ?>">
    <input type="hidden" name="plan_id" value="<?= htmlspecialchars($result['plan_id']) ?>">
    <input type="hidden" name="raw_emails" value="<?= htmlspecialchars($result['raw_emails']) ?>">
    <button type="submit" name="submit_action" value="send"
      onclick="return confirm('Send this email to <?= $result['count'] ?> recipients now?')"
      class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl shadow hover:-translate-y-0.5">📨 Send to <?= $result['count'] ?> Recipients</button>
    <a href="compose-email.php" class="ml-2 text-xs text-gray-400 hover:text-gray-600">Start over</a>
  </form>
</div>
<?php endif; ?>

<div class="max-w-2xl bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
  <form method="POST" class="space-y-4">
    <?= acsrfField() ?>
    <div>
      <label class="block text-xs font-semibold text-gray-700 mb-1.5">Recipients</label>
      <div class="grid grid-cols-2 gap-2 mb-2">
        <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-red-50">
          <input type="radio" name="mode" value="raw" checked class="accent-rarl-red"/> <span class="text-xs font-semibold">Specific emails</span>
        </label>
        <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-red-50">
          <input type="radio" name="mode" value="section" class="accent-rarl-red"/> <span class="text-xs font-semibold">A chapter's members</span>
        </label>
        <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-red-50">
          <input type="radio" name="mode" value="plan" class="accent-rarl-red"/> <span class="text-xs font-semibold">A plan tier</span>
        </label>
        <label class="flex items-center gap-2 p-2.5 border border-gray-200 rounded-xl cursor-pointer has-[:checked]:border-rarl-red has-[:checked]:bg-red-50">
          <input type="radio" name="mode" value="all_active" class="accent-rarl-red"/> <span class="text-xs font-semibold">All active members</span>
        </label>
      </div>
      <textarea name="raw_emails" rows="2" placeholder="jane@uni.edu, john@lab.org"
        class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm"></textarea>
      <div class="grid grid-cols-2 gap-2 mt-2">
        <select name="section_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm">
          <option value="">— Select chapter —</option>
          <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="plan_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm">
          <option value="">— Select plan —</option>
          <?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-700 mb-1.5">Subject <span class="text-rarl-red">*</span></label>
      <input type="text" name="subject" required class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm"/>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-700 mb-1.5">Message <span class="text-rarl-red">*</span> <span class="text-gray-400 font-normal">(Markdown supported)</span></label>
      <textarea name="body" required rows="10" class="w-full px-4 py-3 bg-gray-50 border border-gray-300 rounded-xl text-sm font-mono resize-y"></textarea>
    </div>
    <div class="flex gap-3">
      <button type="submit" name="submit_action" value="preview" class="px-6 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm rounded-xl">👁️ Preview</button>
    </div>
  </form>
</div>
<?php }, 'compose', 'Compose Email');
