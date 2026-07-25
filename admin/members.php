<?php
/**
 * RARL Admin — Member Management
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$pdo = db();

// ── Actions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $mid    = (int)($_POST['mid'] ?? 0);

    if ($action === 'activate' && $mid) {
        $pdo->prepare("UPDATE members SET status='active' WHERE id=?")->execute([$mid]);
        // Send Discord invite email if not already done
        $m = $pdo->prepare("SELECT * FROM members WHERE id=?");
        $m->execute([$mid]);
        $member = $m->fetch();
        if ($member && !$member['discord_invited']) {
            $discordUrl  = setting('discord_invite_url');
            $memberName  = $member['type'] === 'lab' ? $member['lab_name'] : $member['full_name'];
            $isApproval  = false;
            ob_start(); require dirname(__DIR__) . '/emails/welcome.php'; $body = ob_get_clean();
            sendEmail($member['email'], $memberName, 'Your RARL Membership is Approved! 🎉', $body);
            $pdo->prepare("UPDATE members SET discord_invited=1 WHERE id=?")->execute([$mid]);
        }
        $cardMsg = issueIdCard($mid) ? ' ID card generated.' : ' (Upload a photo to auto-generate their ID card.)';
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Member activated and notified.' . $cardMsg];
    } elseif ($action === 'deactivate' && $mid) {
        $pdo->prepare("UPDATE members SET status='inactive' WHERE id=?")->execute([$mid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Member deactivated.'];
    } elseif ($action === 'delete' && $mid) {
        $pdo->prepare("DELETE FROM members WHERE id=?")->execute([$mid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Member deleted.'];
    } elseif ($action === 'set_section' && $mid) {
        $sectionId = (int)($_POST['section_id'] ?? 0) ?: null;
        $pdo->prepare("UPDATE members SET section_id=? WHERE id=?")->execute([$sectionId, $mid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Section updated.'];
    } elseif ($action === 'regenerate_card' && $mid) {
        $ok = issueIdCard($mid);
        $_SESSION['flash'] = $ok
            ? ['type'=>'success','msg'=>'ID card regenerated.']
            : ['type'=>'error','msg'=>'Could not generate ID card — member needs an avatar photo uploaded first.'];
    } elseif ($action === 'export_csv') {
        // Export
        $members = $pdo->query("SELECT id,type,full_name,lab_name,email,institution,pi_name,country,research_interests,research_areas,position,google_scholar_url,orcid_id,linkedin_url,newsletter_opt_in,discord_invited,status,created_at FROM members ORDER BY created_at DESC")->fetchAll();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rarl_members_' . date('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output','w');
        fputcsv($out,['ID','Type','Name','Email','Institution','Country','Status','Newsletter','Discord','Joined']);
        foreach ($members as $m) {
            $name = $m['type']==='lab' ? $m['lab_name'] : $m['full_name'];
            fputcsv($out,[$m['id'],$m['type'],$name,$m['email'],$m['institution'],$m['country'],$m['status'],$m['newsletter_opt_in']?'Yes':'No',$m['discord_invited']?'Yes':'No',$m['created_at']]);
        }
        fclose($out); exit;
    }
    header('Location: members.php' . ($_GET ? '?' . http_build_query($_GET) : '')); exit;
}

// ── Filters ────────────────────────────────────────────────
$fs = trim($_GET['status'] ?? '');
$ft = trim($_GET['type']   ?? '');
$fq = trim($_GET['q']      ?? '');
$where = []; $params = [];
if ($fs) { $where[] = 'status = ?'; $params[] = $fs; }
if ($ft) { $where[] = 'type = ?';   $params[] = $ft; }
if ($fq) {
    $like = "%{$fq}%";
    $where[] = '(full_name LIKE ? OR lab_name LIKE ? OR email LIKE ? OR institution LIKE ?)';
    $params  = array_merge($params,[$like,$like,$like,$like]);
}
$ws   = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM members {$ws} ORDER BY created_at DESC LIMIT 300");
$stmt->execute($params);
$members = $stmt->fetchAll();
$sections = $pdo->query("SELECT id, name FROM regional_sections WHERE is_published=1 ORDER BY continent, scope DESC, display_order")->fetchAll();

adminWrap(function() use ($members, $fs, $ft, $fq, $sections) {
    adminFlash(); ?>
<div class="flex items-center justify-between mb-6">
  <div><h1 class="text-2xl font-black text-gray-900">Members</h1><p class="text-gray-500 text-sm">Manage all platform members</p></div>
  <form method="POST">
    <?= acsrfField() ?><input type="hidden" name="action" value="export_csv">
    <button type="submit" class="px-4 py-2 bg-gray-800 hover:bg-gray-900 text-white text-sm font-semibold rounded-xl transition-colors">📤 Export CSV</button>
  </form>
</div>

<!-- Filters -->
<div class="bg-white border border-gray-200 rounded-2xl shadow-sm mb-5">
  <form method="GET" class="p-4 flex flex-wrap gap-3 items-center">
    <input type="text" name="q" value="<?= htmlspecialchars($fq) ?>" placeholder="Search name, email, institution…"
      class="px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red flex-1 min-w-48"/>
    <select name="status" class="px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
      <option value="">All status</option>
      <?php foreach (['pending','active','inactive'] as $s): ?>
      <option value="<?= $s ?>" <?= $fs===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red">
      <option value="">All types</option>
      <option value="individual" <?= $ft==='individual'?'selected':'' ?>>Individual</option>
      <option value="lab" <?= $ft==='lab'?'selected':'' ?>>Research Lab</option>
    </select>
    <button type="submit" class="px-5 py-2 bg-rarl-red text-white text-sm font-semibold rounded-xl hover:bg-rarl-dark">Filter</button>
    <a href="members.php" class="px-5 py-2 bg-gray-100 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-200">Clear</a>
  </form>
</div>

<!-- Table -->
<div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
  <div class="px-5 py-4 border-b border-gray-100">
    <span class="text-sm text-gray-500">Showing <strong><?= count($members) ?></strong> members</span>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Member</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Type</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Inst. / Country</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">NL</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Discord</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Status</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Section</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">ID Card</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Joined</th>
          <th class="text-left px-4 py-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($members)): ?><tr><td colspan="10" class="py-12 text-center text-gray-400 text-sm">No members found.</td></tr><?php endif; ?>
        <?php foreach ($members as $m):
          $name = $m['type']==='lab' ? $m['lab_name'] : $m['full_name'];
          $sc   = ['active'=>'bg-green-100 text-green-700','pending'=>'bg-amber-100 text-amber-700','inactive'=>'bg-gray-100 text-gray-500'][$m['status']] ?? '';
        ?>
        <tr class="hover:bg-gray-50 group">
          <td class="px-4 py-3">
            <p class="font-medium text-xs text-gray-900"><?= htmlspecialchars($name) ?></p>
            <p class="text-[10px] text-gray-400"><?= htmlspecialchars($m['email']) ?></p>
          </td>
          <td class="px-4 py-3">
            <span class="text-[10px] font-bold <?= $m['type']==='lab'?'bg-blue-100 text-blue-700':'bg-purple-100 text-purple-700' ?> px-2 py-0.5 rounded-full">
              <?= $m['type']==='lab'?'🏫 Lab':'🧑‍🔬 Individual' ?>
            </span>
          </td>
          <td class="px-4 py-3 text-[10px] text-gray-500">
            <?= htmlspecialchars(mb_strimwidth($m['institution']??'',0,25,'…')) ?><br/>
            <?= htmlspecialchars($m['country']??'') ?>
          </td>
          <td class="px-4 py-3 text-center text-sm"><?= $m['newsletter_opt_in']?'✅':'—' ?></td>
          <td class="px-4 py-3 text-center text-sm"><?= $m['discord_invited']?'✅':'—' ?></td>
          <td class="px-4 py-3">
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $sc ?>"><?= ucfirst($m['status']) ?></span>
          </td>
          <td class="px-4 py-3">
            <form method="POST" class="inline" onchange="this.submit()">
              <?= acsrfField() ?><input type="hidden" name="action" value="set_section"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
              <select name="section_id" class="text-[10px] border border-gray-200 rounded-lg px-1.5 py-1 bg-white">
                <option value="">— None —</option>
                <?php foreach ($sections as $s): ?>
                <option value="<?= $s['id'] ?>" <?= (int)($m['section_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="px-4 py-3 text-[10px]">
            <?php if (!empty($m['id_card_path'])): ?>
              <a href="../uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="text-blue-600 hover:underline">📇 <?= htmlspecialchars($m['member_code'] ?? '') ?></a>
              <div class="text-gray-400">exp <?= $m['id_card_expires_at'] ? date('d M Y', strtotime($m['id_card_expires_at'])) : '—' ?></div>
            <?php else: ?>
              <span class="text-gray-300">— none —</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-[10px] text-gray-400"><?= date('d M Y', strtotime($m['created_at'])) ?></td>
          <td class="px-4 py-3">
            <div class="flex flex-wrap gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
              <?php if ($m['status'] !== 'active'): ?>
              <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
                <button type="submit" class="px-2 py-1 text-[10px] font-semibold bg-green-100 text-green-700 hover:bg-green-200 rounded-lg">Activate</button>
              </form>
              <?php else: ?>
              <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
                <button type="submit" class="px-2 py-1 text-[10px] font-semibold bg-amber-100 text-amber-700 hover:bg-amber-200 rounded-lg">Deactivate</button>
              </form>
              <?php endif; ?>
              <form method="POST" class="inline"><?= acsrfField() ?><input type="hidden" name="action" value="regenerate_card"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
                <button type="submit" class="px-2 py-1 text-[10px] font-semibold bg-blue-100 text-blue-700 hover:bg-blue-200 rounded-lg">🪪 Card</button>
              </form>
              <form method="POST" class="inline" onsubmit="return confirm('Delete this member?')"><?= acsrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="mid" value="<?= $m['id'] ?>">
                <button type="submit" class="px-2 py-1 text-[10px] font-semibold bg-red-100 text-red-600 hover:bg-red-200 rounded-lg">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php }, 'members', 'Members');
