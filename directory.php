<?php
/**
 * RARL — Member Directory (members-only perk)
 * Searchable/filterable directory of active members who opted in
 * (directory_visible=1). Locked behind active membership, same "join free
 * to unlock" pattern as premium Learning Hub content.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$isMember = !empty($_SESSION['member_id']);
$isActiveMember = false;
if ($isMember) {
    $s = $pdo->prepare('SELECT status FROM members WHERE id = ?');
    $s->execute([(int)$_SESSION['member_id']]);
    $isActiveMember = $s->fetchColumn() === 'active';
}

$members = [];
$countries = [];
if ($isActiveMember) {
    $q          = trim($_GET['q'] ?? '');
    $country    = trim($_GET['country'] ?? '');
    $type       = trim($_GET['type'] ?? '');
    $mentorOnly = !empty($_GET['mentors']);

    $where = ['directory_visible = 1', "status = 'active'"];
    $params = [];
    if ($q !== '') {
        $where[] = '(full_name LIKE ? OR lab_name LIKE ? OR institution LIKE ? OR research_interests LIKE ? OR research_areas LIKE ?)';
        $like = "%{$q}%";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    if ($country !== '') { $where[] = 'country = ?'; $params[] = $country; }
    if ($type === 'individual' || $type === 'lab') { $where[] = 'type = ?'; $params[] = $type; }
    if ($mentorOnly) { $where[] = 'open_to_mentor = 1'; }

    $ws = 'WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT * FROM members {$ws} ORDER BY (open_to_mentor = 1) DESC, created_at DESC LIMIT 100");
    $stmt->execute($params);
    $members = $stmt->fetchAll();

    $countries = $pdo->query("SELECT DISTINCT country FROM members WHERE directory_visible = 1 AND status = 'active' AND country IS NOT NULL AND country != '' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
}

echo htmlHead('Member Directory');
?>
<?= publicNav() ?>

<!-- Hero -->
<section class="relative overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
  <div class="relative z-10 max-w-5xl mx-auto px-6 py-16 text-center">
    <div class="text-5xl mb-4">🧑‍🤝‍🧑</div>
    <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-3">Member Directory</h1>
    <p class="text-white/60 text-base max-w-lg mx-auto">Find and connect with researchers and labs across the RARL community — by country, institution, or research interest.</p>
  </div>
</section>

<div class="max-w-6xl mx-auto px-6 py-14">

  <?php if (!$isActiveMember): ?>
  <!-- Locked state -->
  <div class="bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800 rounded-2xl p-14 text-center max-w-lg mx-auto shadow-sm">
    <div class="text-5xl mb-4">🔒</div>
    <h2 class="font-heading font-black text-xl text-gray-900 dark:text-white mb-2">Members-Only Directory</h2>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-7">Join RARL free to search the member directory, find collaborators by research interest or country, and connect with mentors.</p>
    <?php if ($isMember): ?>
    <p class="text-amber-600 text-sm font-semibold">⏳ Your account is pending approval — you'll get access as soon as you're approved.</p>
    <?php else: ?>
    <a href="register.php" class="inline-flex items-center gap-2 px-7 py-3.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl shadow-lg hover:-translate-y-0.5 transition-all text-sm">
      Join Free — Get Started →
    </a>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <!-- Filters -->
  <form method="GET" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-8 flex flex-wrap gap-3 items-center">
    <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Search name, institution, research interest…"
      class="flex-1 min-w-56 px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-rarl-red/25 focus:border-rarl-red"/>
    <select name="country" class="px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm">
      <option value="">All Countries</option>
      <?php foreach ($countries as $c): ?>
      <option value="<?= htmlspecialchars($c) ?>" <?= ($_GET['country'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm">
      <option value="">All Types</option>
      <option value="individual" <?= ($_GET['type'] ?? '') === 'individual' ? 'selected' : '' ?>>Individuals</option>
      <option value="lab" <?= ($_GET['type'] ?? '') === 'lab' ? 'selected' : '' ?>>Research Labs</option>
    </select>
    <label class="flex items-center gap-2 px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-xl text-sm cursor-pointer">
      <input type="checkbox" name="mentors" value="1" <?= !empty($_GET['mentors']) ? 'checked' : '' ?> class="accent-rarl-red w-4 h-4"/> 🧭 Mentors only
    </label>
    <button type="submit" class="px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold rounded-xl transition-colors">Search</button>
    <?php if (!empty($_GET)): ?><a href="directory.php" class="px-5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-sm font-semibold rounded-xl">Clear</a><?php endif; ?>
  </form>

  <p class="text-sm text-gray-400 mb-5"><?= count($members) ?> member(s) found</p>

  <?php if (empty($members)): ?>
  <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-14 text-center text-gray-400">
    <p class="text-4xl mb-3">🔍</p>
    <p class="font-semibold mb-1">No members match your search</p>
    <p class="text-sm">Try a different keyword, country, or clear the filters.</p>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php foreach ($members as $m):
      $name = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
      $sub  = $m['type'] === 'lab' ? $m['institution'] : ($m['position'] ?: $m['institution']);
      $interests = $m['type'] === 'lab' ? $m['research_areas'] : $m['research_interests'];
    ?>
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6 shadow-sm hover:-translate-y-0.5 hover:shadow-lg transition-all">
      <div class="flex items-start gap-3 mb-3">
        <?= memberAvatarHtml($m['avatar_path'], $name ?: '?', 'w-12 h-12 text-base') ?>
        <div class="min-w-0 flex-1">
          <h3 class="font-heading font-bold text-sm text-gray-900 dark:text-white leading-snug truncate"><?= htmlspecialchars($name ?: 'Member') ?></h3>
          <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($sub ?: '') ?></p>
        </div>
      </div>
      <div class="flex items-center gap-1.5 flex-wrap mb-3">
        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full <?= $m['type']==='lab'?'bg-blue-50 text-blue-600':'bg-purple-50 text-purple-600' ?>"><?= $m['type']==='lab'?'🏫 Lab':'🧑‍🔬 Individual' ?></span>
        <?php if ($m['country']): ?><span class="text-[10px] font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 px-2 py-0.5 rounded-full"><?= htmlspecialchars($m['country']) ?></span><?php endif; ?>
        <?php if (!empty($m['open_to_mentor'])): ?><span class="text-[10px] font-bold text-green-700 bg-green-50 px-2 py-0.5 rounded-full">🧭 Mentor</span><?php endif; ?>
        <?php if (!empty($m['seeking_mentor'])): ?><span class="text-[10px] font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full">🎯 Seeking Mentor</span><?php endif; ?>
      </div>
      <?php if ($interests): ?>
      <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-2 mb-3"><?= htmlspecialchars($interests) ?></p>
      <?php endif; ?>
      <div class="flex items-center gap-2">
        <?php if (!empty($m['linkedin_url'])): ?>
        <a href="<?= htmlspecialchars($m['linkedin_url']) ?>" target="_blank" rel="noopener" title="LinkedIn" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-xs transition-colors">in</a>
        <?php endif; ?>
        <?php if (!empty($m['google_scholar_url'])): ?>
        <a href="<?= htmlspecialchars($m['google_scholar_url']) ?>" target="_blank" rel="noopener" title="Google Scholar" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-xs transition-colors">🎓</a>
        <?php endif; ?>
        <?php if (!empty($m['lab_website'])): ?>
        <a href="<?= htmlspecialchars($m['lab_website']) ?>" target="_blank" rel="noopener" title="Website" class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-800 hover:bg-rarl-red hover:text-white text-gray-500 dark:text-gray-300 flex items-center justify-center text-xs transition-colors">🌐</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?= publicFooter() ?>
</body></html>
