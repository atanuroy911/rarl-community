<?php
/**
 * RARL Admin — Research: papers members submit for publishing on the site.
 * Approve → shown on Research + Home feed; share on LinkedIn in one click.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
$ready = publicationsReady();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $tab = $_POST['tab'] ?? 'pending';
    if ($id && $action === 'approve') {
        $pdo->prepare("UPDATE publications SET status = 'approved', approved_at = COALESCE(approved_at, NOW()), admin_note = NULL WHERE id = ?")->execute([$id]);
        // Let the member know.
        $r = $pdo->prepare('SELECT p.title, m.email, m.full_name, m.lab_name, m.type FROM publications p JOIN members m ON m.id = p.member_id WHERE p.id = ?'); $r->execute([$id]);
        if ($row = $r->fetch()) {
            $name = $row['type'] === 'lab' ? $row['lab_name'] : $row['full_name'];
            $link = SITE_URL . '/publications.php?id=' . $id;
            $body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#333;line-height:1.6"><p>Hi ' . htmlspecialchars(html_entity_decode((string)$name, ENT_QUOTES)) . ',</p>'
                  . '<p>Your paper <strong>' . htmlspecialchars(html_entity_decode($row['title'], ENT_QUOTES)) . '</strong> is now featured on the RARL website. Thank you for publishing with the RARL affiliation.</p>'
                  . '<p><a href="' . htmlspecialchars($link) . '" style="background:' . BRAND_RED . ';color:#fff;padding:10px 20px;border-radius:999px;text-decoration:none;font-weight:bold;display:inline-block">See it on RARL</a></p>'
                  . '<p style="font-size:13px;color:#666">Share it with your network: ' . htmlspecialchars($link) . '</p></div>';
            queueEmail(newEmailBatch(), 'Paper featured', $row['email'], (string)$name, 'Your paper is featured on RARL', $body);
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Approved. It\'s live on the Research page and in members\' feeds. The member was emailed.'];
        $tab = 'approved';
    } elseif ($id && $action === 'reject') {
        $pdo->prepare("UPDATE publications SET status = 'rejected', admin_note = ? WHERE id = ?")->execute([mb_substr(clean($_POST['note'] ?? ''), 0, 500) ?: null, $id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Declined. The member sees your note on their Research page.'];
    } elseif ($id && $action === 'feature') {
        $pdo->prepare('UPDATE publications SET is_featured = 1 - is_featured WHERE id = ?')->execute([$id]);
    } elseif ($id && $action === 'shared') {
        $pdo->prepare('UPDATE publications SET shared_at = NOW() WHERE id = ?')->execute([$id]);
        header('Content-Type: application/json'); echo '{"ok":true}'; exit;
    } elseif ($id && $action === 'delete') {
        $pdo->prepare('DELETE FROM publications WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Removed.'];
    }
    header('Location: publications.php?tab=' . urlencode($tab)); exit;
}

$tab = in_array($_GET['tab'] ?? '', ['approved', 'rejected'], true) ? $_GET['tab'] : 'pending';
$counts = $ready ? $pdo->query('SELECT status, COUNT(*) FROM publications GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$rows = [];
if ($ready) {
    $s = $pdo->prepare('SELECT p.*, m.full_name, m.lab_name, m.type, m.email, m.avatar_path FROM publications p JOIN members m ON m.id = p.member_id WHERE p.status = ? ORDER BY ' . ($tab === 'approved' ? 'p.approved_at DESC' : 'p.created_at ASC'));
    $s->execute([$tab]); $rows = $s->fetchAll();
}

adminWrap(function () use ($rows, $tab, $counts, $ready) {
    adminFlash(); ?>
<div class="rarl-page-head">
  <div><h1>Research</h1><p>Papers members published with the RARL affiliation. Approve to feature them on the site, then share on LinkedIn.</p></div>
  <a href="../publications.php" target="_blank" class="rarl-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> View page</a>
</div>
<?php if (!$ready): ?><div class="rarl-card p-5 text-sm">Run the pending database update first (Database page).</div><?php return; endif; ?>
<div class="rarl-tabs mb-5 w-fit">
  <?php foreach (['pending' => 'Waiting', 'approved' => 'Published', 'rejected' => 'Declined'] as $k => $l): ?>
  <a href="publications.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $l ?> <span class="count <?= $k === 'pending' && ($counts['pending'] ?? 0) ? 'hot' : '' ?>"><?= (int)($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?><div class="rarl-card rarl-empty"><?= ['pending' => 'Nothing waiting. New submissions from members show up here.', 'approved' => 'No published papers yet.', 'rejected' => 'Nothing declined.'][$tab] ?></div><?php endif; ?>
<div class="space-y-3">
<?php foreach ($rows as $p):
  $name = $p['type'] === 'lab' ? $p['lab_name'] : $p['full_name'];
  $link = SITE_URL . '/publications.php?id=' . (int)$p['id'];
  $caption = 'New research from our community: "' . html_entity_decode($p['title'], ENT_QUOTES) . '" by ' . html_entity_decode((string)$name, ENT_QUOTES) . ' (RARL member)'
           . ($p['venue'] ? ', published in ' . html_entity_decode($p['venue'], ENT_QUOTES) : '') . ($p['year'] ? ' (' . $p['year'] . ')' : '') . ".\n\nRead it: " . $p['url'] . "\n\n#RARL #Robotics #AI #Research"; ?>
  <article class="rarl-card p-5">
    <div class="flex flex-wrap items-start gap-4">
      <div class="flex-1 min-w-[16rem]">
        <p class="text-xs text-[color:var(--muted-fg)]"><?= ex($p['venue'] ?: 'Venue not given') ?><?= $p['year'] ? ' · ' . (int)$p['year'] : '' ?> · sent <?= date('d M Y', strtotime($p['created_at'])) ?><?php if ($p['is_featured']): ?> · <span class="text-amber-700 font-semibold"><i class="fa-solid fa-star"></i> Featured</span><?php endif; ?><?php if ($p['shared_at']): ?> · <span class="text-[#0a66c2] font-semibold"><i class="fa-brands fa-linkedin"></i> Shared</span><?php endif; ?></p>
        <h2 class="font-semibold text-base mt-1"><a href="<?= ex($p['url']) ?>" target="_blank" rel="noopener" class="hover:underline"><?= ex($p['title']) ?> <i class="fa-solid fa-arrow-up-right-from-square text-xs text-[color:var(--muted-fg)]"></i></a></h2>
        <?php if ($p['authors']): ?><p class="text-sm text-[color:var(--muted-fg)]"><?= ex($p['authors']) ?></p><?php endif; ?>
        <div class="text-sm mt-2 flex items-center gap-2"><?= memberAvatarHtml($p['avatar_path'], (string)$name, 'w-6 h-6 text-[10px]') ?> <a href="members.php#member-<?= (int)$p['member_id'] ?>" class="font-medium hover:underline"><?= ex($name) ?></a><?php if ($p['doi']): ?> <span class="font-mono text-xs text-[color:var(--muted-fg)]">· DOI <?= ex($p['doi']) ?></span><?php endif; ?></div>
        <?php if ($tab === 'rejected' && $p['admin_note']): ?><p class="text-sm mt-2 text-[color:var(--muted-fg)]"><i class="fa-regular fa-comment"></i> <?= ex($p['admin_note']) ?></p><?php endif; ?>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <?php if ($tab !== 'approved'): ?>
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="tab" value="<?= $tab ?>"><button class="rarl-btn rarl-btn-sm rarl-btn-primary"><i class="fa-solid fa-check"></i> Approve</button></form>
        <?php endif; ?>
        <?php if ($tab === 'pending'): ?>
        <button type="button" class="rarl-btn rarl-btn-sm" data-amodal="reject-modal" data-reject="<?= (int)$p['id'] ?>">Decline</button>
        <?php endif; ?>
        <?php if ($tab === 'approved'): ?>
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode($link) ?>" target="_blank" rel="noopener" class="rarl-btn rarl-btn-sm" style="color:#0a66c2" data-shared="<?= (int)$p['id'] ?>" data-caption="<?= ex($caption) ?>"><i class="fa-brands fa-linkedin"></i> Share on LinkedIn</a>
        <button type="button" class="rarl-btn rarl-btn-sm" data-copy-caption="<?= ex($caption) ?>"><i class="fa-regular fa-copy"></i> Copy caption</button>
        <form method="POST"><?= acsrfField() ?><input type="hidden" name="action" value="feature"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="tab" value="approved"><button class="rarl-icon-btn" title="<?= $p['is_featured'] ? 'Unfeature' : 'Feature at the top' ?>" data-no-loading><i class="fa-solid fa-star <?= $p['is_featured'] ? 'text-amber-500' : '' ?>"></i></button></form>
        <?php endif; ?>
        <form method="POST" data-confirm="Remove this paper from the site?"><?= acsrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="tab" value="<?= $tab ?>"><button class="rarl-icon-btn danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php adminModalOpen('reject-modal', 'Decline this paper', 'Optional: tell the member why (for example, the RARL affiliation is missing).', ['form' => true, 'width' => '480px']); ?>
  <input type="hidden" name="action" value="reject"><input type="hidden" name="id" value=""><input type="hidden" name="tab" value="pending">
  <div><label class="rarl-label" for="r-note">Note to the member</label><textarea id="r-note" name="note" rows="3" maxlength="500" class="rarl-input" placeholder="The paper doesn't list the RARL affiliation."></textarea></div>
<?php adminModalClose('Decline', '', true); ?>
<script>
  document.querySelectorAll('[data-reject]').forEach(function (b) { b.addEventListener('click', function () { document.querySelector('#reject-modal [name=id]').value = b.dataset.reject; }); });
  document.querySelectorAll('[data-copy-caption]').forEach(function (b) { b.addEventListener('click', function () { navigator.clipboard.writeText(b.dataset.copyCaption).then(function () { rarlToast('Caption copied: paste it into your LinkedIn post', 'success'); }); }); });
  // LinkedIn opens in a new tab; copy the caption for pasting and remember it was shared.
  document.querySelectorAll('[data-shared]').forEach(function (a) { a.addEventListener('click', function () {
    navigator.clipboard && navigator.clipboard.writeText(a.dataset.caption).then(function () { rarlToast('Caption copied: paste it into the LinkedIn post', 'info'); });
    var fd = new FormData(); fd.append('action', 'shared'); fd.append('id', a.dataset.shared); fd.append('acsrf', <?= json_encode($GLOBALS['acsrf']) ?>);
    fetch('publications.php', {method: 'POST', body: fd});
  }); });
</script>
<?php }, 'publications', 'Research');
