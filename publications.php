<?php
/**
 * RARL — Research: papers members have published with the RARL affiliation.
 * Members submit (pending) → admins approve → listed here, in the feed, and
 * shared on LinkedIn. publications.php?id=N is a shareable page per paper.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$pdo = db();
$memberId = (int)($_SESSION['member_id'] ?? 0);
$me = null;
if ($memberId) { $s = $pdo->prepare('SELECT id, status, full_name, lab_name, type FROM members WHERE id = ?'); $s->execute([$memberId]); $me = $s->fetch() ?: null; }
$ready = (bool)$pdo->query("SHOW TABLES LIKE 'publications'")->fetchColumn();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit' && $me && csrfCheck()) {
    $title = mb_substr(clean($_POST['title'] ?? ''), 0, 500);
    $url   = cleanUrl($_POST['url'] ?? '');
    $year  = (int)($_POST['year'] ?? 0);
    if ($me['status'] !== 'active') { flash('error', 'Paper submissions open once your membership is approved.'); }
    elseif ($title === '' || !filter_var($url, FILTER_VALIDATE_URL)) { flash('error', 'Add the paper title and a working link to it.'); }
    else {
        $pdo->prepare('INSERT INTO publications (member_id, title, authors, venue, year, url, doi) VALUES (?,?,?,?,?,?,?)')
            ->execute([$memberId, $title, mb_substr(clean($_POST['authors'] ?? ''), 0, 1000), mb_substr(clean($_POST['venue'] ?? ''), 0, 300),
                       $year >= 1990 && $year <= (int)date('Y') + 1 ? $year : null, $url, mb_substr(clean($_POST['doi'] ?? ''), 0, 200) ?: null]);
        flash('success', 'Thanks! Your paper was sent to the RARL team. Once approved it appears here and is shared on RARL\'s LinkedIn.');
    }
    redirect('publications.php#mine');
}

$papers = $ready ? $pdo->query("SELECT p.*, m.full_name, m.lab_name, m.type, m.avatar_path FROM publications p JOIN members m ON m.id = p.member_id
    WHERE p.status = 'approved' ORDER BY p.is_featured DESC, p.approved_at DESC LIMIT 200")->fetchAll() : [];
$mine = [];
if ($ready && $me) { $s = $pdo->prepare('SELECT * FROM publications WHERE member_id = ? ORDER BY created_at DESC'); $s->execute([$memberId]); $mine = $s->fetchAll(); }
$focus = null;
foreach ($papers as $p) if ((int)$p['id'] === (int)($_GET['id'] ?? 0)) $focus = $p;
$who = fn($p) => $p['type'] === 'lab' ? $p['lab_name'] : $p['full_name'];

echo htmlHead($focus ? html_entity_decode($focus['title'], ENT_QUOTES) : 'Research by RARL members', false, [
    'description' => $focus ? 'Published by ' . html_entity_decode((string)$who($focus), ENT_QUOTES) . ' (RARL member)' . ($focus['venue'] ? ' in ' . html_entity_decode($focus['venue'], ENT_QUOTES) : '') . ($focus['year'] ? ', ' . $focus['year'] : '') . '. Affiliation: ' . RARL_AFFILIATION . '.'
                           : 'Papers published by members of the Robotics & Automation Research Lab (RARL), UK, in robotics, AI, automation and intelligent systems.',
    'type' => $focus ? 'article' : 'website',
]);
$statusBadge = ['pending' => ['In review', 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'], 'approved' => ['Published on RARL', 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'], 'rejected' => ['Not approved', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300']];
?>
<?= publicNav('publications') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
<section class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 py-7 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">Research</p>
      <h1 class="font-heading font-bold text-2xl md:text-3xl text-gray-900 dark:text-white">Published with RARL</h1>
      <p class="text-sm text-gray-500 mt-1 max-w-2xl">Papers by RARL members carrying the RARL affiliation.</p>
    </div>
    <?php if ($me): ?><button type="button" data-modal="submit-paper" class="h-11 px-5 rounded-full bg-rarl-red hover:bg-rarl-dark text-white font-semibold inline-flex items-center gap-2"><i class="fa-solid fa-plus"></i> Submit your paper</button>
    <?php else: ?><a href="login.php?next=publications.php" class="h-11 px-5 rounded-full border border-gray-300 dark:border-gray-700 font-semibold inline-flex items-center gap-2 text-gray-800 dark:text-gray-100">Members: sign in to submit a paper</a><?php endif; ?>
  </div>
</section>

<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
  <div class="space-y-4 min-w-0">
    <?= renderFlash() ?>
    <?php if (!$papers): ?>
    <div class="rarl-panel p-12 text-center"><i class="fa-regular fa-file-lines text-4xl text-gray-300"></i><p class="font-semibold text-gray-800 dark:text-gray-100 mt-3">No papers listed yet</p><p class="text-sm text-gray-500 mt-1">Published a paper with the RARL affiliation? Submit it and we'll feature it here.</p></div>
    <?php endif; ?>
    <?php foreach ($papers as $p): $isFocus = $focus && (int)$focus['id'] === (int)$p['id']; ?>
    <article id="pub-<?= (int)$p['id'] ?>" class="rarl-panel p-5 scroll-mt-24 <?= $isFocus ? 'ring-2 ring-rarl-red' : '' ?>">
      <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
        <?php if ($p['is_featured']): ?><span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300"><i class="fa-solid fa-star"></i> Featured</span><?php endif; ?>
        <?php if ($p['venue'] || $p['year']): ?><span class="text-gray-500"><?= ex($p['venue']) ?><?= $p['venue'] && $p['year'] ? ' · ' : '' ?><?= $p['year'] ? (int)$p['year'] : '' ?></span><?php endif; ?>
      </div>
      <h2 class="font-heading font-bold text-lg text-gray-900 dark:text-white leading-snug mt-1"><a href="<?= ex($p['url']) ?>" target="_blank" rel="noopener" class="hover:text-rarl-red"><?= ex($p['title']) ?> <i class="fa-solid fa-arrow-up-right-from-square text-xs text-gray-400"></i></a></h2>
      <?php if ($p['authors']): ?><p class="text-sm text-gray-600 dark:text-gray-400 mt-1"><?= ex($p['authors']) ?></p><?php endif; ?>
      <div class="flex flex-wrap items-center gap-3 mt-3 text-sm">
        <span class="inline-flex items-center gap-2 text-gray-700 dark:text-gray-300"><?= memberAvatarHtml($p['avatar_path'], (string)$who($p), 'w-7 h-7 text-xs') ?> <?= ex($who($p)) ?> <span class="text-xs text-gray-500">· RARL member</span></span>
        <?php if ($p['doi']): ?><a href="https://doi.org/<?= ex(preg_replace('~^https?://(dx\.)?doi\.org/~i', '', html_entity_decode($p['doi'], ENT_QUOTES))) ?>" target="_blank" rel="noopener" class="text-xs font-mono text-gray-500 hover:text-rarl-red">DOI <?= ex($p['doi']) ?></a><?php endif; ?>
        <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(SITE_URL . '/publications.php?id=' . (int)$p['id']) ?>" target="_blank" rel="noopener" class="ms-auto text-xs font-semibold text-gray-500 hover:text-[#0a66c2]"><i class="fa-brands fa-linkedin"></i> Share</a>
      </div>
    </article>
    <?php endforeach; ?>

    <?php if ($mine): ?>
    <section id="mine" class="rarl-panel overflow-hidden scroll-mt-24">
      <h2 class="px-5 pt-5 pb-2 font-heading font-bold text-lg text-gray-900 dark:text-white">Your submissions</h2>
      <ul class="divide-y divide-gray-100 dark:divide-gray-800">
        <?php foreach ($mine as $p): [$label, $tone] = $statusBadge[$p['status']]; ?>
        <li class="px-5 py-3.5">
          <div class="flex flex-wrap items-center gap-2"><span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $tone ?>"><?= $label ?></span><span class="text-xs text-gray-500">Sent <?= date('d M Y', strtotime($p['created_at'])) ?></span></div>
          <p class="font-semibold text-gray-900 dark:text-white mt-1"><?= ex($p['title']) ?></p>
          <?php if ($p['status'] === 'rejected' && $p['admin_note']): ?><p class="text-sm text-gray-600 dark:text-gray-400 mt-1"><i class="fa-regular fa-comment"></i> <?= ex($p['admin_note']) ?></p><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </div>

  <aside class="space-y-4 lg:sticky lg:top-[88px] min-w-0">
    <section class="rarl-panel p-5">
      <h2 class="font-heading font-bold text-gray-900 dark:text-white">Use the RARL affiliation</h2>
      <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Every RARL member is encouraged to add RARL as an affiliation on their papers.</p>
      <?= affiliationBoxHtml() ?>
      <p class="text-xs text-gray-500 mt-3">Then submit the published paper here: we feature it on this page, in members' feeds and on RARL's LinkedIn.</p>
    </section>
  </aside>
</main>
</div>

<?php if ($me): ?>
<dialog class="rarl-modal" id="submit-paper" aria-labelledby="submit-paper-t" data-hash="off">
  <div class="rarl-modal-panel" style="--modal-w:36rem">
    <button type="button" class="rarl-modal-close" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <form method="POST" class="p-6 space-y-4">
      <?= csrfField() ?><input type="hidden" name="action" value="submit">
      <div><h2 id="submit-paper-t" class="font-heading font-bold text-xl text-gray-900 dark:text-white">Submit your paper</h2>
        <p class="text-sm text-gray-500 mt-1">For published papers that list <strong><?= ex(RARL_AFFILIATION) ?></strong> as an affiliation.</p></div>
      <?php if ($me['status'] !== 'active'): ?><p class="text-sm p-3 rounded-xl bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">You can submit once your membership is approved.</p><?php endif; ?>
      <div><label for="pt" class="block text-sm font-semibold mb-1.5 text-gray-800 dark:text-gray-200">Paper title <span class="text-rarl-red">*</span></label><input id="pt" name="title" required maxlength="500" class="pub-input"/></div>
      <div><label for="pu" class="block text-sm font-semibold mb-1.5 text-gray-800 dark:text-gray-200">Link to the paper <span class="text-rarl-red">*</span></label><input id="pu" name="url" type="url" required placeholder="https://…" class="pub-input"/></div>
      <div><label for="pa" class="block text-sm font-semibold mb-1.5 text-gray-800 dark:text-gray-200">Authors</label><input id="pa" name="authors" maxlength="1000" placeholder="A. Author, B. Author, …" class="pub-input"/></div>
      <div class="grid grid-cols-[1fr_110px] gap-3">
        <div><label for="pv" class="block text-sm font-semibold mb-1.5 text-gray-800 dark:text-gray-200">Journal or conference</label><input id="pv" name="venue" maxlength="300" class="pub-input"/></div>
        <div><label for="py" class="block text-sm font-semibold mb-1.5 text-gray-800 dark:text-gray-200">Year</label><input id="py" name="year" type="number" min="1990" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>" class="pub-input"/></div>
      </div>
      <div><label for="pd" class="block text-sm font-semibold mb-1.5 text-gray-800 dark:text-gray-200">DOI <span class="font-normal text-gray-500">(optional)</span></label><input id="pd" name="doi" maxlength="200" placeholder="10.xxxx/xxxxx" class="pub-input font-mono"/></div>
      <button class="w-full h-12 rounded-full bg-rarl-red hover:bg-rarl-dark text-white font-semibold" <?= $me['status'] !== 'active' ? 'disabled' : '' ?>>Send for review</button>
    </form>
  </div>
</dialog>
<?php endif; ?>
<style>.pub-input{width:100%;height:2.9rem;padding:0 .9rem;border-radius:.8rem;border:1px solid #c4c4ca;background:#fff;font-size:16px;color:#111827}.dark .pub-input{background:#0f172a;border-color:#374151;color:#f3f4f6}.pub-input:focus{outline:none;border-color:#CC0703;box-shadow:0 0 0 4px rgba(204,7,3,.14)}</style>
<?php if ($focus): ?><script>document.addEventListener('DOMContentLoaded', function () { document.getElementById('pub-<?= (int)$focus['id'] ?>').scrollIntoView({block: 'center'}); });</script><?php endif; ?>
<?= publicFooter() ?>
</body></html>
