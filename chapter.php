<?php
/**
 * RARL — Chapter Chair Portal
 * A scoped view for chapter chairs (members.is_chair=1): their chapter's
 * member list with a one-click approve for local pending signups, and a
 * chapter-only announcement tool. Distributes the approval bottleneck that
 * used to require the single shared admin login for every activation.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in first.'); redirect('login.php'); }

$pdo = db();
$memberId = (int)$_SESSION['member_id'];
$me = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$me->execute([$memberId]);
$me = $me->fetch();

if (!$me || empty($me['is_chair']) || empty($me['section_id'])) {
    flash('error', 'This page is only available to chapter chairs with an assigned chapter.');
    redirect('dashboard.php');
}

$sectionId = (int)$me['section_id'];
$section = $pdo->prepare('SELECT * FROM regional_sections WHERE id = ?');
$section->execute([$sectionId]);
$section = $section->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_member') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        // Scope check: chairs can only approve members inside their own chapter.
        $chk = $pdo->prepare("SELECT id FROM members WHERE id = ? AND section_id = ? AND status = 'pending'");
        $chk->execute([$targetId, $sectionId]);
        if ($chk->fetch()) {
            $pdo->prepare("UPDATE members SET status = 'active' WHERE id = ?")->execute([$targetId]);
            completeApproval($targetId);
            flash('success', 'Member approved.');
        }
        redirect('chapter.php');
    }

    if ($action === 'post_announcement') {
        $title = clean($_POST['title'] ?? '');
        $content = clean($_POST['content'] ?? '');
        if ($title && $content) {
            $pdo->prepare("INSERT INTO announcements (title, content, type, section_id, is_published) VALUES (?,?,'general',?,1)")
                ->execute([$title, $content, $sectionId]);
            flash('success', 'Posted to your chapter.');
        }
        redirect('chapter.php');
    }
}

$members = $pdo->prepare("SELECT * FROM members WHERE section_id = ? ORDER BY (status = 'pending') DESC, created_at DESC");
$members->execute([$sectionId]);
$members = $members->fetchAll();
$pendingCount = count(array_filter($members, fn($m) => $m['status'] === 'pending'));

$chapterAnnouncements = $pdo->prepare("SELECT * FROM announcements WHERE section_id = ? ORDER BY created_at DESC LIMIT 10");
$chapterAnnouncements->execute([$sectionId]);
$chapterAnnouncements = $chapterAnnouncements->fetchAll();

echo htmlHead('Chapter Portal');
?>
<?= publicNav('dashboard') ?>

<div class="bg-gray-50 dark:bg-gray-950 min-h-screen">
  <div class="bg-rarl-navy border-b border-rarl-mid">
    <div class="max-w-5xl mx-auto px-6 py-8">
      <p class="text-white/50 text-xs uppercase tracking-wider mb-1"><i class="fa-solid fa-compass"></i> Chapter Chair Portal</p>
      <h1 class="font-heading font-black text-2xl text-white"><?= htmlspecialchars($section['name'] ?? 'Your Chapter') ?></h1>
      <p class="text-white/50 text-sm mt-1"><?= count($members) ?> members · <?= $pendingCount ?> pending approval</p>
    </div>
  </div>

  <div class="max-w-5xl mx-auto px-6 py-8 space-y-7">
    <?= renderFlash() ?>
    <a href="dashboard.php" class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-rarl-red transition-colors">← Back to Dashboard</a>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-7">
      <div class="lg:col-span-2 space-y-7">

        <!-- Chapter members -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
          <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white"><i class="fa-solid fa-users"></i> Chapter Members</h2>
          </div>
          <?php if (empty($members)): ?>
          <p class="p-8 text-center text-gray-400 text-sm">No members in this chapter yet.</p>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($members as $mem):
              $name = $mem['type'] === 'lab' ? $mem['lab_name'] : $mem['full_name'];
              $sc = ['active'=>'bg-green-100 text-green-700','pending'=>'bg-amber-100 text-amber-700','inactive'=>'bg-gray-100 text-gray-500'][$mem['status']] ?? '';
            ?>
            <div class="p-4 flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2 mb-0.5">
                  <p class="font-semibold text-sm text-gray-900 dark:text-white truncate"><?= htmlspecialchars($name ?: '(unnamed)') ?></p>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $sc ?>"><?= ucfirst($mem['status']) ?></span>
                </div>
                <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($mem['email']) ?> · joined <?= date('d M Y', strtotime($mem['created_at'])) ?></p>
              </div>
              <?php if ($mem['status'] === 'pending'): ?>
              <form method="POST" class="flex-shrink-0"><?= csrfField() ?><input type="hidden" name="action" value="approve_member"><input type="hidden" name="target_id" value="<?= $mem['id'] ?>">
                <button type="submit" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded-lg">Approve</button>
              </form>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Post chapter announcement -->
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm">
          <h2 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-4"><i class="fa-solid fa-bullhorn"></i> Post a Chapter Announcement</h2>
          <form method="POST" class="space-y-3">
            <?= csrfField() ?><input type="hidden" name="action" value="post_announcement">
            <input type="text" name="title" required placeholder="Title"
              class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm"/>
            <textarea name="content" required rows="4" placeholder="Only members of your chapter will see this."
              class="w-full px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm resize-none"></textarea>
            <button type="submit" class="px-6 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white font-semibold text-sm rounded-xl">Post to Chapter</button>
          </form>
        </div>
      </div>

      <div class="space-y-5">
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl overflow-hidden shadow-sm">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
            <h3 class="font-heading font-bold text-sm text-gray-800 dark:text-white">Recent Chapter Posts</h3>
          </div>
          <?php if (empty($chapterAnnouncements)): ?>
          <p class="p-6 text-center text-gray-400 text-xs">Nothing posted yet.</p>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($chapterAnnouncements as $ann): ?>
            <div class="p-4">
              <p class="font-semibold text-xs text-gray-800 dark:text-white"><?= htmlspecialchars($ann['title']) ?></p>
              <p class="text-[11px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($ann['created_at'])) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?= publicFooter() ?>
</body></html>
