<?php
/**
 * RARL Admin — Newsletter Management
 * Write, segment, schedule, send, view stats
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo = db();

// ── Actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action  = $_POST['action'] ?? '';
    $subject = clean($_POST['subject'] ?? '');
    $body    = $_POST['body_html'] ?? '';
    $preview = clean($_POST['preview_text'] ?? '');
    $segment = in_array($_POST['segment']??'', ['all','individual','lab']) ? $_POST['segment'] : 'all';

    if ($action === 'save_draft') {
        $pdo->prepare("INSERT INTO newsletters (subject, body_html, preview_text, segment, status, created_by) VALUES (?,?,?,?,'draft',?)")
            ->execute([$subject, $body, $preview, $segment, $_SESSION['admin_user'] ?? 'admin']);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Draft saved.'];
        header('Location: newsletter.php'); exit;
    }

    if ($action === 'send') {
        $nlId = (int)($_POST['nl_id'] ?? 0);
        if ($nlId) {
            $nl = $pdo->prepare("SELECT * FROM newsletters WHERE id = ?");
            $nl->execute([$nlId]);
            $newsletter = $nl->fetch();
        } else {
            $pdo->prepare("INSERT INTO newsletters (subject, body_html, preview_text, segment, status, created_by) VALUES (?,?,?,?,'draft',?)")
                ->execute([$subject, $body, $preview, $segment, $_SESSION['admin_user'] ?? 'admin']);
            $nlId = (int)$pdo->lastInsertId();
            $newsletter = ['id'=>$nlId,'subject'=>$subject,'body_html'=>$body,'segment'=>$segment];
        }

        if (!$newsletter) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Newsletter not found.']; header('Location: newsletter.php'); exit; }

        // Get recipients
        $segWhere = match($newsletter['segment']) {
            'individual' => "AND type = 'individual'",
            'lab'        => "AND type = 'lab'",
            default      => '',
        };
        $recipients = $pdo->query("SELECT full_name, lab_name, type, email, unsubscribe_token FROM members WHERE status = 'active' AND newsletter_opt_in = 1 {$segWhere}")->fetchAll();

        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $recipientName  = $r['type'] === 'lab' ? $r['lab_name'] : $r['full_name'];
            $unsubscribeUrl = SITE_URL . '/unsubscribe.php?token=' . urlencode($r['unsubscribe_token']);
            $subject_send   = $newsletter['subject'];
            $bodyHtml       = $newsletter['body_html'];
            ob_start(); require dirname(__DIR__) . '/emails/newsletter.php'; $emailBody = ob_get_clean();
            $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_EMAIL . ">\r\n";
            $headers .= "Reply-To: " . MAIL_REPLY_TO . "\r\n";
            $headers .= "List-Unsubscribe: <" . SITE_URL . "/unsubscribe.php?token=" . urlencode($r['unsubscribe_token']) . ">";
            if (@mail($r['email'], $newsletter['subject'], $emailBody, $headers)) $sent++; else $failed++;
            usleep(50000);
        }

        $pdo->prepare("UPDATE newsletters SET status='sent', sent_at=NOW(), recipient_count=? WHERE id=?")->execute([$sent, $newsletter['id']]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Sent to {$sent} subscribers. {$failed} failed."];
        header('Location: newsletter.php'); exit;
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM newsletters WHERE id = ? AND status='draft'")->execute([(int)($_POST['nl_id']??0)]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Draft deleted.'];
        header('Location: newsletter.php'); exit;
    }
}

$newsletters = $pdo->query("SELECT * FROM newsletters ORDER BY created_at DESC LIMIT 50")->fetchAll();
$segCounts   = $pdo->query("SELECT type, COUNT(*) c FROM members WHERE status='active' AND newsletter_opt_in=1 GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalNL     = array_sum($segCounts);
$editing     = null;
if (!empty($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM newsletters WHERE id = ? AND status = 'draft'");
    $es->execute([(int)$_GET['edit']]);
    $editing = $es->fetch();
}

adminWrap(function() use ($newsletters, $segCounts, $totalNL, $editing) {
    adminFlash(); ?>
<div class="flex items-center justify-between mb-6">
  <div><h1 class="text-2xl font-black text-gray-900">Newsletter</h1><p class="text-gray-500 text-sm">Compose and send newsletters to community members</p></div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

  <!-- Compose -->
  <div class="xl:col-span-2">
    <div class="bg-white border border-gray-200 rounded-2xl p-7 shadow-sm">
      <h2 class="font-heading font-bold text-base text-gray-800 mb-5">
        <?= $editing ? '<i class="fa-solid fa-pen-to-square"></i> Edit Draft' : '<i class="fa-solid fa-pen-to-square"></i> Compose Newsletter' ?>
      </h2>
      <form method="POST" class="space-y-4">
        <?= acsrfField() ?>
        <?php if ($editing): ?><input type="hidden" name="nl_id" value="<?= $editing['id'] ?>"><?php endif; ?>

        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1.5">Subject Line <span class="text-rarl-red">*</span></label>
          <input type="text" name="subject" required value="<?= htmlspecialchars($editing['subject'] ?? '') ?>"
            placeholder="e.g. RARL Community — June Update & Events"
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1.5">Preview Text <span class="text-gray-400 font-normal">(shown in email client)</span></label>
          <input type="text" name="preview_text" value="<?= htmlspecialchars($editing['preview_text'] ?? '') ?>"
            placeholder="Short preview that shows under subject line…"
            class="w-full px-4 py-2.5 bg-gray-50 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all"/>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1.5">Recipient Segment</label>
          <div class="grid grid-cols-3 gap-3">
            <?php foreach (['all'=>'Everyone','individual'=>'Individuals Only','lab'=>'Labs Only'] as $val => $lbl): ?>
            <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-xl cursor-pointer hover:border-rarl-red/40 transition-colors has-[:checked]:border-rarl-red has-[:checked]:bg-red-50">
              <input type="radio" name="segment" value="<?= $val ?>" <?= ($editing['segment']??'all')===$val?'checked':'' ?> class="accent-rarl-red"/>
              <span class="text-xs font-semibold text-gray-700"><?= $lbl ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-700 mb-1.5">
            Email Body <span class="text-rarl-red">*</span>
            <span class="text-gray-400 font-normal ml-1">HTML supported</span>
          </label>
          <textarea name="body_html" required rows="16"
            placeholder="Write your newsletter here. HTML supported: &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;a href&gt;, &lt;strong&gt;, etc."
            class="w-full px-4 py-3 bg-gray-50 border border-gray-300 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red transition-all resize-y min-h-[300px]"><?= htmlspecialchars($editing['body_html'] ?? '') ?></textarea>
          <p class="text-xs text-gray-400 mt-1">Unsubscribe links are added automatically. The recipient's name is available as a greeting.</p>
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
          <button type="submit" name="action" value="save_draft"
            class="px-6 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm rounded-xl transition-colors">
            <i class="fa-solid fa-floppy-disk"></i> Save Draft
          </button>
          <button type="submit" name="action" value="send"
            onclick="return confirm('Send now to all matching active subscribers? This cannot be undone.')"
            class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold text-sm rounded-xl transition-colors shadow-lg hover:-translate-y-0.5">
            <i class="fa-solid fa-paper-plane"></i> Send Now
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="space-y-5">
    <!-- Subscriber stats -->
    <div class="bg-rarl-navy text-white rounded-2xl p-6">
      <h3 class="font-heading font-bold text-sm mb-4"><i class="fa-solid fa-envelope"></i> Subscriber Stats</h3>
      <div class="space-y-2.5">
        <div class="flex justify-between items-center py-2 border-b border-white/10 text-sm">
          <span class="text-white/60">Total subscribers</span>
          <span class="font-bold text-white"><?= $totalNL ?></span>
        </div>
        <div class="flex justify-between items-center py-2 border-b border-white/10 text-sm">
          <span class="text-white/60">Individuals</span>
          <span class="font-bold text-white"><?= $segCounts['individual'] ?? 0 ?></span>
        </div>
        <div class="flex justify-between items-center py-2 text-sm">
          <span class="text-white/60">Research Labs</span>
          <span class="font-bold text-white"><?= $segCounts['lab'] ?? 0 ?></span>
        </div>
      </div>
    </div>

    <!-- Past newsletters -->
    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100">
        <h3 class="font-heading font-bold text-sm text-gray-800">Past Newsletters</h3>
      </div>
      <div class="divide-y divide-gray-100 max-h-[420px] overflow-y-auto">
        <?php if (empty($newsletters)): ?>
        <p class="px-5 py-6 text-center text-gray-400 text-sm">No newsletters yet.</p>
        <?php endif; ?>
        <?php foreach ($newsletters as $nl):
          $isDraft = $nl['status'] === 'draft';
          $sc = $isDraft ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700';
        ?>
        <div class="p-4">
          <div class="flex items-start justify-between gap-2 mb-1">
            <p class="font-semibold text-xs text-gray-800 leading-snug flex-1"><?= htmlspecialchars(mb_strimwidth($nl['subject'],0,45,'…')) ?></p>
            <span class="text-[9px] font-bold px-2 py-0.5 rounded-full <?= $sc ?> flex-shrink-0"><?= ucfirst($nl['status']) ?></span>
          </div>
          <p class="text-[10px] text-gray-400 mb-1.5">
            <?= $nl['status']==='sent' ? '<i class="fa-solid fa-paper-plane"></i> Sent to ' . $nl['recipient_count'] . ' · ' . date('d M Y', strtotime($nl['sent_at'])) : '<i class="fa-solid fa-pen-to-square"></i> ' . date('d M Y', strtotime($nl['created_at'])) ?>
          </p>
          <?php if ($isDraft): ?>
          <div class="flex gap-2">
            <a href="newsletter.php?edit=<?= $nl['id'] ?>" class="text-[10px] font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 px-2 py-0.5 rounded transition-colors">Edit</a>
            <form method="POST" class="inline" onsubmit="return confirm('Delete draft?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="nl_id" value="<?= $nl['id'] ?>"><button type="submit" class="text-[10px] font-semibold text-red-600 bg-red-50 hover:bg-red-100 px-2 py-0.5 rounded transition-colors">Delete</button></form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
<?php }, 'newsletter', 'Newsletter');
