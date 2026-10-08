<?php
/**
 * RARL Admin — Member Management
 * Tabs by status (pending first-class), a slide-out detail panel for every
 * member, and a keyboard-driven review mode for working through applications.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo = db();
const MEMBERS_PER_PAGE = 50;
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$displayName = fn(array $m) => ($m['type'] === 'lab' ? $m['lab_name'] : $m['full_name']) ?: $m['email'];

$sections = $pdo->query("SELECT id, name, scope, continent, country FROM regional_sections WHERE is_published=1 ORDER BY continent, scope DESC, display_order")->fetchAll();
$sectionName = array_column($sections, 'name', 'id');

// Chapter that best fits a member's country: an exact country chapter, else none.
function suggestChapter(array $sections, ?string $country): ?array {
    if (!$country) return null;
    foreach ($sections as $s) if ($s['scope'] === 'country' && strcasecmp((string)$s['country'], $country) === 0) return $s;
    return null;
}

// ── Actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reply = function (string $type, string $msg, array $extra = []) use ($isAjax) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok' => $type === 'success', 'msg' => $msg] + $extra); exit; }
        $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
        header('Location: members.php' . (!empty($_POST['return_qs']) ? '?' . preg_replace('/[^\w=&%.+-]/', '', $_POST['return_qs']) : '')); exit;
    };
    if (!adminCsrfOk()) $reply('error', 'Your session expired — reload the page.');
    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['mid'] ?? 0);

    if ($action === 'activate' && $mid) {
        $pdo->prepare("UPDATE members SET status='active' WHERE id=?")->execute([$mid]);
        completeApproval($mid);
        $card = $pdo->prepare("SELECT id_card_path FROM members WHERE id=?"); $card->execute([$mid]);
        $reply('success', 'Approved and welcomed.' . ($card->fetchColumn() ? ' ID card and membership certificate issued.' : ' Membership certificate issued — the ID card needs a profile photo first.'));
    }
    if ($action === 'reject' && $mid) {
        $pdo->prepare("UPDATE members SET status='inactive' WHERE id=? AND status='pending'")->execute([$mid]);
        $reply('success', 'Application declined (marked inactive). You can still reactivate it later.');
    }
    if ($action === 'deactivate' && $mid) {
        $pdo->prepare("UPDATE members SET status='inactive' WHERE id=?")->execute([$mid]);
        $reply('success', 'Member deactivated.');
    }
    if ($action === 'delete' && $mid) {
        deleteMemberCascade($mid);
        $reply('success', 'Member and all related data deleted.');
    }
    if ($action === 'set_section' && $mid) {
        $sectionId = (int)($_POST['section_id'] ?? 0) ?: null;
        $pdo->prepare("UPDATE members SET section_id=? WHERE id=?")->execute([$sectionId, $mid]);
        $reply('success', $sectionId ? 'Moved to ' . ($sectionName[$sectionId] ?? 'chapter') . '.' : 'Removed from chapter.');
    }
    if ($action === 'regenerate_card' && $mid) {
        issueIdCard($mid)
            ? $reply('success', 'ID card regenerated.')
            : $reply('error', 'Could not generate the ID card — the member needs a profile photo first.');
    }
    if ($action === 'save_note' && $mid) {
        $pdo->prepare("UPDATE members SET notes=? WHERE id=?")->execute([trim((string)($_POST['notes'] ?? '')), $mid]);
        $reply('success', 'Note saved.');
    }
    if ($action === 'export_csv') {
        $selected = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $sql = "SELECT * FROM members";
        if ($selected) $sql .= " WHERE id IN (" . implode(',', $selected) . ")";
        $sql .= " ORDER BY created_at DESC";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rarl_members_' . date('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output','w');
        fputcsv($out, ['ID','Member code','Type','Name','Email','Institution','Position','Country','Chapter','Status','Email verified','Newsletter','Welcome email','ID card expires','Joined']);
        foreach ($pdo->query($sql) as $m) {
            fputcsv($out, [$m['id'], $m['member_code'] ?? '', $m['type'], $displayName($m), $m['email'], $m['institution'], $m['position'], $m['country'],
                $sectionName[$m['section_id'] ?? 0] ?? '', $m['status'], $m['email_verified_at'] ? 'Yes' : 'No', $m['newsletter_opt_in'] ? 'Yes' : 'No',
                $m['discord_invited'] ? 'Yes' : 'No', $m['id_card_expires_at'] ?? '', $m['created_at']]);
        }
        fclose($out); exit;
    }
    if ($action === 'bulk') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $bulkOp = $_POST['bulk_op'] ?? '';
        if (!$ids || !$bulkOp) $reply('error', 'Select at least one member first.');
        $inClause = implode(',', $ids);
        @set_time_limit(0);
        if ($bulkOp === 'activate') {
            $rows = $pdo->query("SELECT * FROM members WHERE id IN ({$inClause}) AND status != 'active'")->fetchAll();
            $pdo->exec("UPDATE members SET status='active' WHERE id IN ({$inClause})");
            $batch = newEmailBatch();
            foreach ($rows as $member) {
                if (!$member['discord_invited']) {
                    $memberName = $displayName($member);
                    $isApproval = false;
                    ob_start(); require dirname(__DIR__) . '/emails/welcome.php'; $body = ob_get_clean();
                    queueEmail($batch, 'Membership approvals', $member['email'], $memberName, 'Your RARL Membership is Approved!', $body, [], 'welcome:' . (int)$member['id']);
                }
                issueIdCard((int)$member['id']);
                issueMembershipCertificate((int)$member['id']);
            }
            $reply('success', count($rows) . ' member(s) approved. Welcome emails are sending in the background.');
        }
        if ($bulkOp === 'deactivate') {
            $pdo->exec("UPDATE members SET status='inactive' WHERE id IN ({$inClause})");
            $reply('success', count($ids) . ' member(s) deactivated.');
        }
        if ($bulkOp === 'delete') {
            foreach ($ids as $delId) deleteMemberCascade((int)$delId);
            $reply('success', count($ids) . ' member(s) and all related data deleted.');
        }
        if ($bulkOp === 'regenerate_card') {
            $done = 0;
            foreach ($ids as $bid) if (issueIdCard($bid)) $done++;
            $reply('success', "ID cards regenerated for {$done} of " . count($ids) . ' (the rest need a profile photo).');
        }
        if ($bulkOp === 'set_section') {
            $sectionId = (int)($_POST['bulk_section_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE members SET section_id=? WHERE id IN ({$inClause})")->execute([$sectionId]);
            $reply('success', count($ids) . ' member(s) moved to ' . ($sectionId ? ($sectionName[$sectionId] ?? 'chapter') : 'no chapter') . '.');
        }
        if ($bulkOp === 'auto_chapter') {
            $n = 0;
            foreach ($pdo->query("SELECT id, country FROM members WHERE id IN ({$inClause})") as $r) {
                if ($s = suggestChapter($sections, $r['country'])) { $pdo->prepare("UPDATE members SET section_id=? WHERE id=?")->execute([$s['id'], $r['id']]); $n++; }
            }
            $reply('success', "Assigned {$n} member(s) to the chapter for their country.");
        }
        $reply('error', 'Unknown bulk action.');
    }
    $reply('error', 'Unknown action.');
}

// ── Detail panel (loaded into the slide-out drawer) ────────
if (isset($_GET['panel'])) {
    $s = $pdo->prepare("SELECT * FROM members WHERE id = ?"); $s->execute([(int)$_GET['panel']]);
    $m = $s->fetch();
    if (!$m) { http_response_code(404); exit('<p class="p-6 text-sm text-gray-500">Member not found.</p>'); }
    $certs = $pdo->prepare("SELECT COUNT(*) FROM certificates WHERE member_id = ?"); $certs->execute([$m['id']]); $certCount = (int)$certs->fetchColumn();
    $suggest = empty($m['section_id']) ? suggestChapter($sections, $m['country']) : null;
    $name = $displayName($m);
    $row = function (string $label, $value, bool $html = false) {
        if ($value === null || $value === '') return;
        echo '<div class="grid grid-cols-[120px_1fr] gap-3 py-2 border-b border-gray-100 last:border-0"><dt class="text-xs text-gray-500">' . $label . '</dt><dd class="text-sm text-gray-900 break-words">' . ($html ? $value : nl2br(htmlspecialchars((string)$value))) . '</dd></div>';
    };
    $link = fn(?string $url, string $label) => $url ? '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" class="text-blue-600 hover:underline">' . $label . ' <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i></a>' : null;
    $badge = ['active' => 'rarl-badge-green', 'pending' => 'rarl-badge-amber', 'inactive' => 'rarl-badge-gray'][$m['status']] ?? 'rarl-badge-gray';
    ?>
<div data-member="<?= (int)$m['id'] ?>" data-status="<?= htmlspecialchars($m['status']) ?>" class="flex flex-col h-full">
  <div class="p-6 border-b border-gray-100">
    <div class="flex items-start gap-4">
      <?= memberAvatarHtml($m['avatar_path'] ?? null, $name, 'w-16 h-16 text-xl') ?>
      <div class="min-w-0 flex-1">
        <h2 class="font-heading font-black text-xl text-gray-900 leading-tight"><?= htmlspecialchars($name) ?></h2>
        <p class="text-sm text-gray-500 truncate"><?= htmlspecialchars($m['email']) ?> <button type="button" class="rarl-icon-btn !w-6 !h-6 align-middle" data-copy="<?= htmlspecialchars($m['email']) ?>" title="Copy email"><i class="fa-regular fa-copy text-xs"></i></button></p>
        <div class="flex flex-wrap gap-1.5 mt-2">
          <span class="rarl-badge <?= $badge ?>"><?= ucfirst($m['status']) ?></span>
          <span class="rarl-badge <?= $m['type'] === 'lab' ? 'rarl-badge-blue' : 'rarl-badge-purple' ?>"><?= $m['type'] === 'lab' ? 'Research lab' : 'Individual' ?></span>
          <?php if (!empty($m['is_chair'])): ?><span class="rarl-badge rarl-badge-indigo"><i class="fa-solid fa-crown"></i> Chapter chair</span><?php endif; ?>
          <?= $m['email_verified_at'] ? '<span class="rarl-badge rarl-badge-green"><i class="fa-solid fa-envelope-circle-check"></i> Email verified</span>' : '<span class="rarl-badge rarl-badge-red"><i class="fa-solid fa-envelope"></i> Email not verified</span>' ?>
        </div>
      </div>
      <button type="button" class="rarl-icon-btn" data-drawer-close title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>

  <div class="flex-1 overflow-y-auto p-6 space-y-6">
    <?php if ($m['status'] === 'pending'): ?>
    <div class="rounded-xl bg-amber-50 border border-amber-200 p-4 text-sm text-amber-900">
      <p class="font-semibold"><i class="fa-solid fa-user-clock"></i> Waiting for approval · applied <?= date('d M Y', strtotime($m['created_at'])) ?></p>
      <p class="text-xs mt-1 text-amber-800">Approving activates the account, sends the welcome email and issues the membership certificate<?= empty($m['avatar_path']) ? ' (the ID card follows once they add a photo)' : ' and ID card' ?>.</p>
    </div>
    <?php endif; ?>

    <section>
      <h3 class="rarl-label uppercase tracking-wider !text-[10px]">Profile</h3>
      <dl>
        <?php
          $row($m['type'] === 'lab' ? 'Principal investigator' : 'Position', $m['type'] === 'lab' ? $m['pi_name'] : $m['position']);
          $row('Institution', trim(($m['institution'] ?? '') . (!empty($m['department']) ? ' — ' . $m['department'] : '')));
          $row('Location', trim(implode(', ', array_filter([$m['city_state'] ?? '', $m['country'] ?? '']))));
          $row('Experience', $m['years_experience'] ?? null);
          $row('Research', $m['type'] === 'lab' ? $m['research_areas'] : $m['research_interests']);
          $row('Primary lab', $m['primary_lab_name'] ?? null);
          $row('Heard about us', !empty($m['referral_source']) ? ucwords(str_replace('_', ' ', $m['referral_source'])) : null);
          $links = array_filter([$link($m['lab_website'] ?? null, 'Website'), $link($m['google_scholar_url'] ?? null, 'Google Scholar'), $link($m['linkedin_url'] ?? null, 'LinkedIn'),
              !empty($m['orcid_id']) ? $link('https://orcid.org/' . $m['orcid_id'], 'ORCID') : null,
              !empty($m['cv_path']) ? $link('../uploads/cv/' . rawurlencode($m['cv_path']), 'CV (file)') : null, $link($m['cv_url'] ?? null, 'CV (link)')]);
          $row('Links', $links ? implode(' · ', $links) : null, true);
        ?>
      </dl>
    </section>

    <section>
      <h3 class="rarl-label uppercase tracking-wider !text-[10px]">Chapter</h3>
      <form class="flex gap-2" data-ajax data-reload-panel>
        <input type="hidden" name="action" value="set_section"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
        <select name="section_id" class="rarl-input" onchange="this.form.requestSubmit()">
          <option value="">— No chapter —</option>
          <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>" <?= (int)($m['section_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
        </select>
      </form>
      <?php if ($suggest): ?>
      <form class="mt-2" data-ajax data-reload-panel><input type="hidden" name="action" value="set_section"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="section_id" value="<?= (int)$suggest['id'] ?>">
        <button class="text-xs font-semibold text-rarl-red hover:underline"><i class="fa-solid fa-wand-magic-sparkles"></i> Suggested from country: <?= htmlspecialchars($suggest['name']) ?> — assign</button></form>
      <?php endif; ?>
    </section>

    <section>
      <h3 class="rarl-label uppercase tracking-wider !text-[10px]">Documents</h3>
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-gray-200 p-3">
          <p class="text-xs text-gray-500"><i class="fa-solid fa-id-card"></i> ID card</p>
          <?php if (!empty($m['id_card_path'])): ?>
          <p class="text-sm font-semibold mt-1"><a href="../uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="hover:text-rarl-red"><?= htmlspecialchars($m['member_code'] ?? 'View card') ?></a></p>
          <p class="text-[11px] text-gray-400"><?= $m['id_card_expires_at'] ? 'Expires ' . date('d M Y', strtotime($m['id_card_expires_at'])) : '' ?></p>
          <?php else: ?><p class="text-sm text-gray-400 mt-1"><?= empty($m['avatar_path']) ? 'Needs a profile photo' : 'Not issued yet' ?></p><?php endif; ?>
          <form data-ajax data-reload-panel class="mt-2"><input type="hidden" name="action" value="regenerate_card"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
            <button class="rarl-btn rarl-btn-sm" <?= empty($m['avatar_path']) ? 'disabled title="Needs a profile photo"' : '' ?>><i class="fa-solid fa-rotate"></i> <?= empty($m['id_card_path']) ? 'Generate' : 'Regenerate' ?></button></form>
        </div>
        <div class="rounded-xl border border-gray-200 p-3">
          <p class="text-xs text-gray-500"><i class="fa-solid fa-trophy"></i> Certificates</p>
          <p class="text-sm font-semibold mt-1"><?= $certCount ?> issued</p>
          <a href="certificates.php?q=<?= urlencode($m['email']) ?>" class="rarl-btn rarl-btn-sm mt-2">View</a>
        </div>
      </div>
    </section>

    <section>
      <h3 class="rarl-label uppercase tracking-wider !text-[10px]">Activity</h3>
      <dl>
        <?php
          $row('Joined', date('d M Y, H:i', strtotime($m['created_at'])));
          $row('Last login', !empty($m['last_login_at']) ? date('d M Y, H:i', strtotime($m['last_login_at'])) : 'Never');
          $row('Welcome email', $m['discord_invited'] ? 'Sent' : 'Not sent');
          $row('Newsletter', $m['newsletter_opt_in'] ? 'Subscribed' : 'Opted out');
        ?>
      </dl>
    </section>

    <section>
      <h3 class="rarl-label uppercase tracking-wider !text-[10px]">Private admin note</h3>
      <form data-ajax>
        <input type="hidden" name="action" value="save_note"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
        <textarea name="notes" rows="3" class="rarl-input" placeholder="Only admins see this…" onblur="this.form.requestSubmit()"><?= htmlspecialchars($m['notes'] ?? '') ?></textarea>
      </form>
    </section>
  </div>

  <div class="p-4 border-t border-gray-100 flex flex-wrap items-center gap-2 bg-gray-50/70">
    <?php if ($m['status'] === 'pending'): ?>
      <form data-ajax data-next <?= $m['email_verified_at'] ? '' : 'data-confirm="This applicant has not verified their email address yet. Approve anyway?" data-confirm-ok="Approve anyway"' ?>><input type="hidden" name="action" value="activate"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="rarl-btn rarl-btn-primary" data-key="a"><i class="fa-solid fa-check"></i> Approve <span class="rarl-kbd !border-white/40 text-white/80">A</span></button></form>
      <form data-ajax data-next data-confirm="Decline this application? The account is marked inactive (not deleted)." data-confirm-ok="Decline"><input type="hidden" name="action" value="reject"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="rarl-btn" data-key="r"><i class="fa-solid fa-xmark"></i> Decline <span class="rarl-kbd">R</span></button></form>
      <button type="button" class="rarl-btn" data-skip data-key="arrowright">Skip <span class="rarl-kbd">→</span></button>
    <?php elseif ($m['status'] === 'active'): ?>
      <form data-ajax data-reload-panel data-confirm="Deactivate this member? They lose access until reactivated." data-confirm-ok="Deactivate"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="rarl-btn"><i class="fa-solid fa-user-slash"></i> Deactivate</button></form>
    <?php else: ?>
      <form data-ajax data-reload-panel><input type="hidden" name="action" value="activate"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-user-check"></i> Reactivate</button></form>
    <?php endif; ?>
    <a href="member-edit.php?id=<?= (int)$m['id'] ?>" class="rarl-btn"><i class="fa-solid fa-pen"></i> Edit everything</a>
    <form data-ajax data-next class="ml-auto" data-confirm="Permanently delete <?= htmlspecialchars($name) ?> and all their posts, certificates, registrations and files?" data-confirm-ok="Delete forever"><input type="hidden" name="action" value="delete"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="rarl-icon-btn danger" title="Delete member"><i class="fa-regular fa-trash-can"></i></button></form>
  </div>
</div>
<?php
    exit;
}

// ── Filters ────────────────────────────────────────────────
$tab  = in_array($_GET['tab'] ?? '', ['pending','active','inactive','attention','chairs'], true) ? $_GET['tab'] : 'all';
$ft   = in_array($_GET['type'] ?? '', ['individual','lab'], true) ? $_GET['type'] : '';
$fsec = $_GET['chapter'] ?? '';
$fq   = trim($_GET['q'] ?? '');
$sort = in_array($_GET['sort'] ?? '', ['oldest','name','login'], true) ? $_GET['sort'] : 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));

$tabWhere = [
    'all'       => '1',
    'pending'   => "status = 'pending'",
    'active'    => "status = 'active'",
    'inactive'  => "status = 'inactive'",
    'attention' => "status = 'active' AND (avatar_path IS NULL OR avatar_path = '' OR id_card_path IS NULL OR id_card_path = '' OR section_id IS NULL OR (id_card_expires_at IS NOT NULL AND id_card_expires_at < DATE_ADD(CURDATE(), INTERVAL 60 DAY)))",
    'chairs'    => 'is_chair = 1',
];
$counts = [];
foreach ($tabWhere as $k => $w) { try { $counts[$k] = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE {$w}")->fetchColumn(); } catch (Throwable $e) { $counts[$k] = 0; } }

$where = [$tabWhere[$tab]]; $params = [];
if ($ft) { $where[] = 'type = ?'; $params[] = $ft; }
if ($fsec === 'none') $where[] = 'section_id IS NULL';
elseif ((int)$fsec) { $where[] = 'section_id = ?'; $params[] = (int)$fsec; }
if ($fq) {
    $like = "%{$fq}%";
    $where[] = '(full_name LIKE ? OR lab_name LIKE ? OR email LIKE ? OR institution LIKE ? OR country LIKE ? OR member_code LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$ws = 'WHERE ' . implode(' AND ', $where);
$order = ['newest' => 'created_at DESC', 'oldest' => 'created_at ASC', 'name' => 'COALESCE(full_name, lab_name) ASC', 'login' => 'last_login_at IS NULL, last_login_at DESC'][$sort];
$cnt = $pdo->prepare("SELECT COUNT(*) FROM members {$ws}"); $cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / MEMBERS_PER_PAGE));
$page = min($page, $pages);
$stmt = $pdo->prepare("SELECT * FROM members {$ws} ORDER BY {$order} LIMIT " . MEMBERS_PER_PAGE . " OFFSET " . (($page - 1) * MEMBERS_PER_PAGE));
$stmt->execute($params);
$members = $stmt->fetchAll();
$qs = http_build_query(array_filter(['tab' => $tab !== 'all' ? $tab : null, 'type' => $ft, 'chapter' => $fsec, 'q' => $fq, 'sort' => $sort !== 'newest' ? $sort : null, 'page' => $page > 1 ? $page : null]));

adminWrap(function() use ($members, $sections, $sectionName, $counts, $tab, $ft, $fsec, $fq, $sort, $page, $pages, $total, $qs, $displayName) {
    adminFlash();
    $url = fn(array $over) => 'members.php?' . http_build_query(array_filter(array_merge(['tab' => $tab !== 'all' ? $tab : null, 'type' => $ft, 'chapter' => $fsec, 'q' => $fq, 'sort' => $sort !== 'newest' ? $sort : null], $over)));
?>
<div class="rarl-page-head">
  <div>
    <h1>Members</h1>
    <p><?= number_format($counts['active']) ?> active members across <?= count($sections) ?> chapters<?= $counts['pending'] ? ' · <strong class="text-amber-700">' . $counts['pending'] . ' waiting for approval</strong>' : '' ?></p>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php if ($counts['pending']): ?>
    <button type="button" id="btn-review" class="rarl-btn rarl-btn-primary"><i class="fa-solid fa-list-check"></i> Review <?= $counts['pending'] ?> pending</button>
    <?php endif; ?>
    <a href="import-members.php" class="rarl-btn"><i class="fa-solid fa-file-import"></i> Import</a>
    <form method="POST" data-no-loading><?= acsrfField() ?><input type="hidden" name="action" value="export_csv"><button class="rarl-btn"><i class="fa-solid fa-download"></i> Export all</button></form>
  </div>
</div>

<div class="flex flex-wrap items-center gap-3 mb-4">
  <nav class="rarl-tabs">
    <?php foreach (['all' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive', 'chairs' => 'Chairs'] as $k => $l): ?>
    <a href="<?= htmlspecialchars($url(['tab' => $k !== 'all' ? $k : null, 'page' => null])) ?>" class="<?= $tab === $k ? 'on' : '' ?>" <?= $k === 'attention' ? 'title="Active members missing a photo, ID card or chapter, or with a card expiring within 60 days"' : '' ?>><?= $l ?> <span class="count <?= $k === 'pending' && $counts[$k] ? 'hot' : '' ?>"><?= number_format($counts[$k]) ?></span></a>
    <?php endforeach; ?>
  </nav>
</div>

<div class="rarl-card">
  <form method="GET" class="p-3 flex flex-wrap gap-2 items-center border-b border-gray-100">
    <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"><?php endif; ?>
    <div class="relative flex-1 min-w-[220px]">
      <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
      <input type="search" name="q" value="<?= htmlspecialchars($fq) ?>" placeholder="Search name, email, institution, country, member ID…   /" class="rarl-input !pl-8"/>
    </div>
    <select name="type" onchange="this.form.submit()" class="rarl-input !w-auto">
      <option value="">All types</option><option value="individual" <?= $ft === 'individual' ? 'selected' : '' ?>>Individuals</option><option value="lab" <?= $ft === 'lab' ? 'selected' : '' ?>>Research labs</option>
    </select>
    <select name="chapter" onchange="this.form.submit()" class="rarl-input !w-auto max-w-[220px]">
      <option value="">All chapters</option><option value="none" <?= $fsec === 'none' ? 'selected' : '' ?>>No chapter yet</option>
      <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>" <?= (string)$fsec === (string)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="sort" onchange="this.form.submit()" class="rarl-input !w-auto">
      <?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name A–Z', 'login' => 'Recently active'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <?php if ($fq || $ft || $fsec): ?><a href="<?= htmlspecialchars($url(['q' => null, 'type' => null, 'chapter' => null])) ?>" class="text-xs text-gray-500 hover:text-rarl-red px-2">Clear filters</a><?php endif; ?>
  </form>

  <?= bulkFormOpen('', ['return_qs' => $qs]) ?>
  <div class="px-3 pt-3 empty:hidden"><?= bulkBar([
      ['label' => '<i class="fa-solid fa-check"></i> Approve', 'op' => 'activate', 'class' => 'bg-green-600 hover:bg-green-500', 'confirm' => 'Approve the selected members and send their welcome emails?'],
      ['label' => '<i class="fa-solid fa-wand-magic-sparkles"></i> Auto-assign chapter', 'op' => 'auto_chapter', 'class' => 'bg-indigo-600 hover:bg-indigo-500'],
      ['label' => '<i class="fa-solid fa-id-card"></i> Regenerate cards', 'op' => 'regenerate_card', 'class' => 'bg-blue-600 hover:bg-blue-500'],
      ['label' => '<i class="fa-solid fa-download"></i> Export', 'name' => 'action', 'value' => 'export_csv', 'class' => 'bg-gray-700 hover:bg-gray-600'],
      ['label' => 'Deactivate', 'op' => 'deactivate', 'class' => 'bg-amber-600 hover:bg-amber-500', 'confirm' => 'Deactivate the selected members?'],
      ['label' => '<i class="fa-solid fa-trash"></i>', 'op' => 'delete', 'class' => 'bg-red-600 hover:bg-red-500', 'confirm' => 'Permanently delete the selected members and all their data? This cannot be undone.'],
  ]) ?></div>
  <div id="bulk-chapter-wrap" class="hidden px-3 pb-3 -mt-2"><div class="flex items-center gap-2 text-xs">
    <span class="text-gray-500">Move selected to</span>
    <select id="bulk-section-select" class="rarl-input !w-auto !h-8"><option value="">No chapter</option><?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select>
    <button type="submit" form="bulk-form" id="bulk-move" class="rarl-btn rarl-btn-sm rarl-btn-dark">Move</button>
  </div></div>

  <div class="overflow-x-auto">
    <table class="rarl-table" data-no-sort>
      <thead><tr>
        <th class="w-8"><?= bulkSelectAllCheckbox() ?></th>
        <th>Member</th><th>Affiliation</th><th>Chapter</th><th>Status</th><th>ID card</th><th>Joined</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php if (!$members): ?><tr><td colspan="8" class="rarl-empty"><i class="fa-solid fa-users text-2xl mb-2 block"></i><?= $fq || $ft || $fsec ? 'No members match these filters.' : ($tab === 'pending' ? 'No applications waiting — all caught up.' : 'No members here yet.') ?></td></tr><?php endif; ?>
      <?php foreach ($members as $m):
        $name = $displayName($m);
        $badge = ['active' => ['rarl-badge-green', 'Active'], 'pending' => ['rarl-badge-amber', 'Pending'], 'inactive' => ['rarl-badge-gray', 'Inactive']][$m['status']] ?? ['rarl-badge-gray', $m['status']];
        $expSoon = !empty($m['id_card_expires_at']) && strtotime($m['id_card_expires_at']) < strtotime('+60 days');
      ?>
        <tr class="cursor-pointer" data-open="<?= (int)$m['id'] ?>" data-status="<?= htmlspecialchars($m['status']) ?>">
          <td onclick="event.stopPropagation()"><?= bulkRowCheckbox((int)$m['id']) ?></td>
          <td>
            <div class="flex items-center gap-3 min-w-0">
              <?= memberAvatarHtml($m['avatar_path'] ?? null, $name, 'w-9 h-9 text-sm') ?>
              <div class="min-w-0">
                <p class="font-semibold text-gray-900 truncate max-w-[220px]"><?= htmlspecialchars($name) ?><?= !empty($m['is_chair']) ? ' <i class="fa-solid fa-crown text-indigo-500 text-[10px]" title="Chapter chair"></i>' : '' ?></p>
                <p class="text-[11px] text-gray-400 truncate max-w-[220px]"><?= htmlspecialchars($m['email']) ?></p>
              </div>
            </div>
          </td>
          <td>
            <p class="text-gray-700 truncate max-w-[200px]"><?= htmlspecialchars($m['institution'] ?: ($m['type'] === 'lab' ? 'Research lab' : 'Independent')) ?></p>
            <p class="text-[11px] text-gray-400"><?= $m['type'] === 'lab' ? '<i class="fa-solid fa-building-columns"></i> Lab' : '<i class="fa-solid fa-user"></i> Individual' ?><?= $m['country'] ? ' · ' . htmlspecialchars($m['country']) : '' ?></p>
          </td>
          <td><?= !empty($m['section_id']) && isset($sectionName[$m['section_id']]) ? '<span class="text-gray-700 text-xs">' . htmlspecialchars($sectionName[$m['section_id']]) . '</span>' : '<span class="text-[11px] text-gray-400 italic">Unassigned</span>' ?></td>
          <td>
            <span class="rarl-badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
            <?php if (!$m['email_verified_at'] && $m['status'] === 'pending'): ?><p class="text-[10px] text-red-600 mt-1">Email not verified</p><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($m['id_card_path'])): ?>
              <span class="font-mono text-[11px] text-gray-700"><?= htmlspecialchars($m['member_code'] ?? '') ?></span>
              <p class="text-[10px] <?= $expSoon ? 'text-amber-600 font-semibold' : 'text-gray-400' ?>"><?= $m['id_card_expires_at'] ? ($expSoon ? 'Expires ' : 'Until ') . date('M Y', strtotime($m['id_card_expires_at'])) : '' ?></p>
            <?php elseif ($m['status'] === 'active'): ?>
              <span class="text-[11px] text-amber-600"><?= empty($m['avatar_path']) ? 'Needs photo' : 'Not issued' ?></span>
            <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
          </td>
          <td class="text-[11px] text-gray-500 whitespace-nowrap"><?= date('d M Y', strtotime($m['created_at'])) ?></td>
          <td class="text-right whitespace-nowrap" onclick="event.stopPropagation()">
            <?php if ($m['status'] === 'pending'): ?>
            <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="mid" value="<?= $m['id'] ?>"><input type="hidden" name="return_qs" value="<?= htmlspecialchars($qs) ?>">
              <button class="rarl-btn rarl-btn-sm rarl-btn-primary" title="Approve"><i class="fa-solid fa-check"></i> Approve</button></form>
            <?php endif; ?>
            <button type="button" class="rarl-icon-btn" data-open-btn="<?= (int)$m['id'] ?>" title="Details"><i class="fa-solid fa-chevron-right"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
    <span><?= $total ? 'Showing ' . number_format(($page - 1) * MEMBERS_PER_PAGE + 1) . '–' . number_format(min($total, $page * MEMBERS_PER_PAGE)) . ' of ' . number_format($total) : '0 members' ?> · click a row for details</span>
    <?php if ($pages > 1): ?>
    <div class="flex items-center gap-1">
      <a href="<?= htmlspecialchars($url(['page' => $page - 1])) ?>" class="rarl-icon-btn border border-gray-200 <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <span class="px-2">Page <?= $page ?> of <?= $pages ?></span>
      <a href="<?= htmlspecialchars($url(['page' => $page + 1])) ?>" class="rarl-icon-btn border border-gray-200 <?= $page >= $pages ? 'pointer-events-none opacity-40' : '' ?>"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="rarl-drawer-backdrop" id="drawer-bg"></div>
<aside class="rarl-drawer" id="drawer" aria-hidden="true"><div id="drawer-body" class="h-full"></div></aside>

<?= bulkBarScript() ?>
<script>
(function() {
  const ACSRF = <?= json_encode($GLOBALS['acsrf'] ?? '') ?>;
  const drawer = document.getElementById('drawer'), bg = document.getElementById('drawer-bg'), body = document.getElementById('drawer-body');
  let current = null, reviewing = false, changed = false, queue = [];

  // Bulk "move to chapter": reveal the picker when rows are selected.
  const bulkBarEl = document.getElementById('bulk-bar'), chapterWrap = document.getElementById('bulk-chapter-wrap');
  new MutationObserver(() => chapterWrap.classList.toggle('hidden', bulkBarEl.classList.contains('hidden'))).observe(bulkBarEl, {attributes: true});
  document.getElementById('bulk-move').addEventListener('click', () => {
    document.getElementById('bulk-op').value = 'set_section';
    const f = document.getElementById('bulk-form'); f.querySelector('[name=bulk_section_id]')?.remove();
    const i = document.createElement('input'); i.type = 'hidden'; i.name = 'bulk_section_id'; i.value = document.getElementById('bulk-section-select').value; f.appendChild(i);
  });

  async function open(id) {
    current = +id;
    document.querySelectorAll('tr[data-open]').forEach(r => r.classList.toggle('bg-red-50/40', +r.dataset.open === current));
    body.innerHTML = '<div class="p-6 space-y-3"><div class="rarl-skeleton h-16 w-16 rounded-full"></div><div class="rarl-skeleton h-5 w-2/3"></div><div class="rarl-skeleton h-4 w-1/2"></div><div class="rarl-skeleton h-40 w-full mt-6"></div></div>';
    drawer.classList.add('open'); bg.classList.add('open'); drawer.setAttribute('aria-hidden', 'false');
    try {
      const res = await fetch('members.php?panel=' + current, {headers: {'X-Requested-With': 'fetch'}});
      body.innerHTML = await res.text();
      wire();
    } catch (e) { body.innerHTML = '<p class="p-6 text-sm text-red-600">Could not load this member.</p>'; }
  }
  function close() {
    drawer.classList.remove('open'); bg.classList.remove('open'); drawer.setAttribute('aria-hidden', 'true'); reviewing = false; current = null;
    if (changed) location.reload();
  }
  function next() {
    if (!reviewing) { changed = true; close(); return; }
    const i = queue.indexOf(current);
    queue.splice(i, 1);
    if (!queue.length) { rarlToast('All pending applications on this page reviewed 🎉', 'success'); close(); return; }
    open(queue[Math.min(i, queue.length - 1)]);
  }
  function wire() {
    body.querySelectorAll('[data-drawer-close]').forEach(b => b.onclick = close);
    body.querySelectorAll('[data-copy]').forEach(b => b.onclick = () => navigator.clipboard.writeText(b.dataset.copy).then(() => rarlToast('Copied', 'success')));
    body.querySelectorAll('[data-skip]').forEach(b => b.onclick = () => {
      if (!reviewing) { close(); return; }
      const i = queue.indexOf(current); open(queue[(i + 1) % queue.length]);
    });
    body.querySelectorAll('form[data-ajax]').forEach(f => f.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(f); fd.append('acsrf', ACSRF);
      const btn = f.querySelector('button'); if (btn) btn.disabled = true;
      try {
        const d = await (await fetch('members.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})).json();
        rarlToast(d.msg, d.ok ? 'success' : 'error');
        if (d.ok) {
          changed = true;
          const row = document.querySelector('tr[data-open="' + current + '"]');
          if (f.hasAttribute('data-next')) { if (row) row.style.opacity = .35; next(); }
          else if (f.hasAttribute('data-reload-panel')) open(current);
        }
      } catch (err) { rarlToast('Something went wrong — please retry.', 'error'); }
      if (btn) btn.disabled = false;
    }));
  }

  document.querySelectorAll('tr[data-open]').forEach(r => r.addEventListener('click', e => { if (!e.target.closest('a,button,input,select,form')) open(r.dataset.open); }));
  document.querySelectorAll('[data-open-btn]').forEach(b => b.addEventListener('click', () => open(b.dataset.openBtn)));
  bg.addEventListener('click', close);
  document.getElementById('btn-review')?.addEventListener('click', () => {
    queue = [...document.querySelectorAll('tr[data-open][data-status=pending]')].map(r => +r.dataset.open);
    if (!queue.length) { location.href = 'members.php?tab=pending&sort=oldest&review=1'; return; }
    reviewing = true; open(queue[0]);
  });
  if (new URLSearchParams(location.search).has('review')) document.getElementById('btn-review')?.click();

  document.addEventListener('keydown', e => {
    if (!drawer.classList.contains('open') || document.querySelector('dialog[open]')) return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) return;
    if (e.key === 'Escape') { close(); return; }
    const k = e.key.toLowerCase();
    const btn = body.querySelector('[data-key="' + k + '"]');
    if (btn) { e.preventDefault(); btn.click(); return; }
    if (!reviewing && (k === 'arrowdown' || k === 'arrowup' || k === 'j' || k === 'k')) {
      const ids = [...document.querySelectorAll('tr[data-open]')].map(r => +r.dataset.open);
      const i = ids.indexOf(current) + (k === 'arrowdown' || k === 'j' ? 1 : -1);
      if (ids[i]) { e.preventDefault(); open(ids[i]); }
    }
  });
})();
</script>
<?php }, 'members', 'Members');
