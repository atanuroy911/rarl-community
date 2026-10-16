<?php
/**
 * RARL Admin — Videos: paste a YouTube link (webinar recordings, talks).
 * Members watch inside the site; new videos appear in everyone's Home feed.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();
$ready = videosReady();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && adminCsrfOk()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'save') {
        $yt = youtubeId((string)($_POST['url'] ?? ''));
        if (!$yt) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'That doesn\'t look like a YouTube link. Copy it from the video\'s Share button.'];
            header('Location: videos.php'); exit;
        }
        $title = clean($_POST['title'] ?? '');
        if ($title === '') $title = clean(youtubeInfo($yt)['title'] ?? '') ?: 'RARL video';
        $vals = [$yt, mb_substr($title, 0, 300), clean($_POST['description'] ?? '') ?: null, (int)($_POST['event_id'] ?? 0) ?: null,
                 !empty($_POST['members_only']) ? 'member' : 'public', !empty($_POST['hidden']) ? 0 : 1];
        if ($id) {
            $pdo->prepare('UPDATE videos SET youtube_id=?, title=?, description=?, event_id=?, access_level=?, is_published=? WHERE id=?')->execute([...$vals, $id]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Video updated.'];
        } else {
            $pdo->prepare('INSERT INTO videos (youtube_id, title, description, event_id, access_level, is_published) VALUES (?,?,?,?,?,?)')->execute($vals);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Video added. Members see it on Videos and in their Home feed.'];
        }
    } elseif ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Video removed.'];
    } elseif ($action === 'toggle' && $id) {
        $pdo->prepare('UPDATE videos SET is_published = 1 - is_published WHERE id = ?')->execute([$id]);
    }
    header('Location: videos.php'); exit;
}

$videos = $ready ? $pdo->query('SELECT v.*, e.title AS event_title FROM videos v LEFT JOIN events e ON e.id = v.event_id ORDER BY v.created_at DESC')->fetchAll() : [];
$events = $pdo->query("SELECT id, title, event_date FROM events ORDER BY event_date IS NULL, event_date DESC LIMIT 60")->fetchAll();

adminWrap(function () use ($videos, $events, $ready) {
    adminFlash(); ?>
<div class="rarl-page-head">
  <div><h1>Videos</h1><p>Webinar recordings and talks from YouTube. Members watch them on the site.</p></div>
  <div class="flex gap-2">
    <a href="../videos.php" target="_blank" class="rarl-btn"><i class="fa-solid fa-arrow-up-right-from-square"></i> View page</a>
    <button type="button" class="rarl-btn rarl-btn-primary" data-amodal="video-modal" data-video-new <?= $ready ? '' : 'disabled' ?>><i class="fa-solid fa-plus"></i> Add video</button>
  </div>
</div>

<?php if (!$ready): ?><div class="rarl-card p-5 text-sm">Run the pending database update first (Database page).</div><?php endif; ?>

<?php if ($ready && !$videos): ?>
<div class="rarl-card rarl-empty"><i class="fa-brands fa-youtube text-4xl text-red-500 mb-3 block"></i><p class="font-semibold text-[color:var(--fg)]">No videos yet</p><p class="mt-1">Paste a YouTube link to share a webinar recording.</p>
  <button type="button" class="rarl-btn rarl-btn-primary mt-4" data-amodal="video-modal" data-video-new><i class="fa-solid fa-plus"></i> Add the first video</button></div>
<?php elseif ($videos): ?>
<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php foreach ($videos as $v): ?>
  <article class="rarl-card overflow-hidden flex flex-col <?= $v['is_published'] ? '' : 'opacity-60' ?>">
    <button type="button" class="relative aspect-video bg-black" data-amodal="video-modal" data-video='<?= ex(json_encode(['id' => (int)$v['id'], 'url' => 'https://youtu.be/' . $v['youtube_id'], 'title' => html_entity_decode($v['title'], ENT_QUOTES), 'description' => html_entity_decode((string)$v['description'], ENT_QUOTES), 'event_id' => (int)$v['event_id'], 'members_only' => $v['access_level'] === 'member', 'hidden' => !$v['is_published']])) ?>'>
      <img src="<?= ex(youtubeThumb($v['youtube_id'])) ?>" alt="" class="w-full h-full object-cover"/>
      <span class="absolute top-2 start-2 flex gap-1"><?php if (!$v['is_published']): ?><span class="rarl-badge bg-white/90 text-gray-700">Hidden</span><?php endif; ?><?php if ($v['access_level'] === 'member'): ?><span class="rarl-badge bg-white/90 text-amber-700"><i class="fa-solid fa-lock"></i> Members</span><?php endif; ?></span>
    </button>
    <div class="p-4 flex-1 flex flex-col">
      <p class="font-semibold text-sm leading-snug line-clamp-2"><?= ex($v['title']) ?></p>
      <p class="text-xs text-[color:var(--muted-fg)] mt-1"><?= $v['event_title'] ? ex($v['event_title']) . ' · ' : '' ?><?= date('d M Y', strtotime($v['created_at'])) ?></p>
      <div class="flex items-center gap-1 mt-auto pt-3">
        <button type="button" class="rarl-btn rarl-btn-sm" data-amodal="video-modal" data-video='<?= ex(json_encode(['id' => (int)$v['id'], 'url' => 'https://youtu.be/' . $v['youtube_id'], 'title' => html_entity_decode($v['title'], ENT_QUOTES), 'description' => html_entity_decode((string)$v['description'], ENT_QUOTES), 'event_id' => (int)$v['event_id'], 'members_only' => $v['access_level'] === 'member', 'hidden' => !$v['is_published']])) ?>'><i class="fa-solid fa-pen"></i> Edit</button>
        <a href="../videos.php?v=<?= (int)$v['id'] ?>" target="_blank" class="rarl-icon-btn" title="Open on site"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
        <form method="POST" class="ms-auto"><?= acsrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="rarl-icon-btn" title="<?= $v['is_published'] ? 'Hide' : 'Show' ?>" data-no-loading><i class="fa-solid <?= $v['is_published'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
        <form method="POST" data-confirm="Remove this video from the site? It stays on YouTube."><?= acsrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="rarl-icon-btn danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php adminModalOpen('video-modal', '<span data-t-new>Add a video</span><span data-t-edit hidden>Edit video</span>', 'Paste the link from YouTube\'s Share button. Live-stream links work too.', ['form' => true, 'width' => '560px']); ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
  <div><label class="rarl-label" for="v-url">YouTube link <span class="text-rarl-red">*</span></label><input id="v-url" name="url" required class="rarl-input" placeholder="https://youtu.be/…"/></div>
  <img id="v-thumb" alt="" class="hidden w-full aspect-video object-cover rounded-[var(--radius)] bg-black"/>
  <div><label class="rarl-label" for="v-title">Title <span class="font-normal text-[color:var(--muted-fg)]">— leave empty to use the YouTube title</span></label><input id="v-title" name="title" maxlength="300" class="rarl-input"/></div>
  <div><label class="rarl-label" for="v-desc">Short description</label><textarea id="v-desc" name="description" rows="3" class="rarl-input"></textarea></div>
  <div><label class="rarl-label" for="v-event">Recording of event <span class="font-normal text-[color:var(--muted-fg)]">(optional)</span></label>
    <select id="v-event" name="event_id" class="rarl-input"><option value="">None</option><?php foreach ($events as $e): ?><option value="<?= (int)$e['id'] ?>"><?= ex($e['title']) ?><?= $e['event_date'] ? ' — ' . date('d M Y', strtotime($e['event_date'])) : '' ?></option><?php endforeach; ?></select></div>
  <div class="flex flex-wrap gap-5 text-sm">
    <label class="flex items-center gap-2"><input type="checkbox" name="members_only" value="1" class="accent-rarl-red w-4 h-4"> Members only</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="hidden" value="1" class="accent-rarl-red w-4 h-4"> Hidden for now</label>
  </div>
<?php adminModalClose('Save video', '', true); ?>
<script>
(function () {
  var m = document.getElementById('video-modal'), f = m.querySelector('form'), thumb = document.getElementById('v-thumb');
  function ytId(u) { var x = (u || '').match(/(?:v=|youtu\.be\/|live\/|shorts\/|embed\/)([A-Za-z0-9_-]{11})/); return x ? x[1] : (/^[A-Za-z0-9_-]{11}$/.test(u) ? u : null); }
  function preview() { var id = ytId(f.url.value.trim()); thumb.classList.toggle('hidden', !id); if (id) thumb.src = 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg'; }
  f.url.addEventListener('input', preview);
  function mode(edit) { m.querySelector('[data-t-new]').hidden = edit; m.querySelector('[data-t-edit]').hidden = !edit; }
  document.querySelectorAll('[data-video-new]').forEach(function (b) { b.addEventListener('click', function () { f.reset(); f.id.value = ''; mode(false); preview(); }); });
  document.querySelectorAll('[data-video]').forEach(function (b) { b.addEventListener('click', function () {
    var d = JSON.parse(b.dataset.video); f.reset(); mode(true);
    f.id.value = d.id; f.url.value = d.url; f.title.value = d.title; f.description.value = d.description; f.event_id.value = d.event_id || ''; f.members_only.checked = d.members_only; f.hidden.checked = d.hidden; preview();
  }); });
})();
</script>
<?php }, 'videos', 'Videos');
