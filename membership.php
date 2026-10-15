<?php
/**
 * RARL — My Membership
 * The member's official record, IEEE-portal style: membership status and
 * number, the digital ID card, every certificate, and what
 * membership includes. Everything here is issued by RARL; nothing is public
 * beyond the verification links the member chooses to share.
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!membershipEnabled()) renderMembershipPausedPageAndExit('My Membership');
if (empty($_SESSION['member_id'])) { flash('error', 'Please sign in to see your membership.'); redirect('login.php?next=membership.php'); }

$pdo      = db();
$memberId = (int)$_SESSION['member_id'];
$st = $pdo->prepare('SELECT m.*, p.name AS plan_name FROM members m LEFT JOIN membership_plans p ON p.id = m.plan_id WHERE m.id = ?');
try { $st->execute([$memberId]); } catch (Throwable $e) { $st = $pdo->prepare('SELECT * FROM members WHERE id = ?'); $st->execute([$memberId]); }
$m = $st->fetch();
if (!$m) { session_destroy(); redirect('login.php'); }

$isLab       = $m['type'] === 'lab';
$displayName = $isLab ? $m['lab_name'] : $m['full_name'];
$isActive    = $m['status'] === 'active';

$chapter = null;
if ($m['section_id']) {
    $s = $pdo->prepare('SELECT * FROM regional_sections WHERE id = ?'); $s->execute([$m['section_id']]);
    $chapter = $s->fetch() ?: null;
}

$certs = $pdo->prepare("SELECT c.*, e.title AS event_title, e.type AS event_type, e.event_date
    FROM certificates c LEFT JOIN events e ON c.event_id = e.id
    WHERE c.member_id = ? OR c.recipient_email = ? ORDER BY c.issued_at DESC");
$certs->execute([$memberId, $m['email']]);
$certificates = $certs->fetchAll();

$cardHtml = null;
$cardTemplate = getDefaultTemplate('id_card');
if ($cardTemplate && !empty($m['id_card_path'])) {
    $cardHtml = renderTemplateHtml($cardTemplate, [
        'name' => $displayName, 'member_code' => '#' . $m['member_code'], 'section' => $chapter['name'] ?? '—',
        'since_date' => date('Y/m/d', strtotime($m['created_at'])),
        'signer1' => setting('idcard_signer1_name', 'RARL President'),
        'signer2' => ($chapter['chair_name'] ?? '') ?: setting('idcard_signer2_name', 'Chapter Chair'),
        'avatar_url' => UPLOADS_URL . '/avatars/' . $m['avatar_path'],
    ]);
}
$cardExpires = $m['id_card_expires_at'] ?? null;
$cardExpired = $cardExpires && strtotime($cardExpires) < time();
$cardSoon    = $cardExpires && !$cardExpired && strtotime($cardExpires) < strtotime('+60 days');

$h = fn($v) => ex($v);
$statusChip = [
    'active'   => ['Active', 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300', 'fa-circle-check'],
    'pending'  => ['Pending review', 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300', 'fa-hourglass-half'],
    'inactive' => ['Inactive', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300', 'fa-circle-pause'],
][$m['status']] ?? ['—', '', 'fa-circle'];

echo htmlHead('My Membership');
?>
<?= publicNav('membership') ?>
<div class="bg-[#f4f2ee] dark:bg-gray-950 min-h-screen">
  <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 sm:py-6 flex items-center gap-3 sm:gap-4">
      <?= memberAvatarHtml($m['avatar_path'], (string)$displayName, 'w-14 h-14 sm:w-16 sm:h-16 text-2xl') ?>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-semibold uppercase tracking-wider text-rarl-red">My membership</p>
        <h1 class="font-heading font-bold text-xl sm:text-2xl text-gray-900 dark:text-white leading-tight line-clamp-2"><?= $h($displayName) ?></h1>
        <div class="flex flex-wrap items-center gap-2 mt-1 text-sm">
          <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $statusChip[1] ?>"><i class="fa-solid <?= $statusChip[2] ?>"></i> <?= $statusChip[0] ?></span>
          <?php if ($m['member_code']): ?><span class="font-mono whitespace-nowrap text-gray-600 dark:text-gray-300 notranslate" translate="no">#<?= $h($m['member_code']) ?></span><?php endif; ?>
        </div>
      </div>
      <a href="profile.php" class="flex-shrink-0 w-11 h-11 sm:w-auto sm:h-auto sm:px-4 sm:py-2 inline-flex items-center justify-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold text-gray-800 dark:text-gray-200 hover:border-rarl-red hover:text-rarl-red" aria-label="Edit profile"><i class="fa-solid fa-pen"></i><span class="hidden sm:inline">Edit profile</span></a>
    </div>
    <nav class="max-w-6xl mx-auto px-4 sm:px-6 flex gap-6 text-sm font-semibold text-gray-500 overflow-x-auto" aria-label="Sections">
      <a href="#overview" class="py-3 border-b-2 border-rarl-red text-gray-900 dark:text-white whitespace-nowrap">Overview</a>
      <a href="#id-card" class="py-3 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-white whitespace-nowrap">ID card</a>
      <a href="#certificates" class="py-3 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-white whitespace-nowrap">Certificates <span class="text-gray-400"><?= count($certificates) ?></span></a>
      <a href="#benefits" class="py-3 border-b-2 border-transparent hover:text-gray-900 dark:hover:text-white whitespace-nowrap">Benefits</a>
    </nav>
  </div>

  <main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 grid lg:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
    <div class="space-y-6 min-w-0">
      <?= renderFlash() ?>

      <section id="overview" class="rarl-panel scroll-mt-24">
        <h2 class="px-5 pt-5 font-heading font-bold text-lg text-gray-900 dark:text-white">Membership record</h2>
        <dl class="grid sm:grid-cols-2 gap-px bg-gray-100 dark:bg-gray-800 m-5 rounded-xl overflow-hidden border border-gray-100 dark:border-gray-800">
          <?php foreach ([
            ['Member number', $m['member_code'] ? '#' . $m['member_code'] : 'Issued on approval'],
            ['Status', $statusChip[0]],
            ['Membership type', $isLab ? 'Research lab' : 'Individual'],
            ['Plan', $m['plan_name'] ?? 'Standard'],
            ['Member since', date('d M Y', strtotime($m['created_at']))],
            ['ID card valid until', $cardExpires ? date('d M Y', strtotime($cardExpires)) : '—'],
            ['Primary email', $m['email']],
          ] as [$k, $v]): ?>
          <div class="bg-white dark:bg-gray-900 px-4 py-3 sm:last:odd:col-span-2"><dt class="text-xs text-gray-500"><?= $k ?></dt><dd class="text-sm font-semibold text-gray-900 dark:text-white mt-0.5 break-words"><?= $h($v) ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <?php if ($m['status'] === 'pending'): ?>
        <p class="mx-5 mb-5 -mt-1 text-sm text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 rounded-xl p-3"><i class="fa-solid fa-hourglass-half"></i> Your application is with the RARL team. Your member number, ID card and membership certificate are issued automatically when you're approved.</p>
        <?php endif; ?>
      </section>

      <section id="id-card" class="rarl-panel p-5 scroll-mt-24">
        <div class="flex items-center justify-between gap-3 mb-4">
          <h2 class="font-heading font-bold text-lg text-gray-900 dark:text-white">Digital ID card</h2>
          <?php if ($cardExpires): ?><span class="text-xs font-semibold <?= $cardExpired ? 'text-red-600' : ($cardSoon ? 'text-amber-700' : 'text-gray-500') ?>"><?= $cardExpired ? 'Expired ' : 'Valid until ' ?><?= date('d M Y', strtotime($cardExpires)) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($m['id_card_path'])): ?>
        <div class="grid sm:grid-cols-[minmax(0,1fr)_210px] gap-5 items-center">
          <div class="rounded-xl overflow-hidden [&>div]:!shadow-lg [&>div]:!rounded-xl">
            <?= $cardHtml ?: '<div class="aspect-[86/54] rounded-xl bg-gradient-to-br from-gray-800 to-gray-950 text-white p-5 flex flex-col justify-between"><span class="font-heading font-black">RARL</span><span><span class="block font-bold text-lg">' . $h($displayName) . '</span><span class="font-mono text-sm text-white/70">#' . $h($m['member_code']) . '</span></span></div>' ?>
          </div>
          <div class="space-y-2">
            <a href="uploads/id-cards/<?= urlencode($m['id_card_path']) ?>" target="_blank" class="flex items-center justify-center gap-2 w-full py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold rounded-full"><i class="fa-solid fa-download"></i> Download PDF</a>
            <a href="id-card-verify.php?code=<?= urlencode((string)$m['member_code']) ?>" target="_blank" class="flex items-center justify-center gap-2 w-full py-2.5 border border-gray-300 dark:border-gray-700 text-gray-800 dark:text-gray-200 text-sm font-semibold rounded-full hover:border-rarl-red hover:text-rarl-red"><i class="fa-solid fa-shield-halved"></i> Verification page</a>
            <p class="text-xs text-gray-500 leading-relaxed">The QR code on your card opens this verification page, so anyone can confirm your membership is genuine.</p>
            <?php if ($cardExpired || $cardSoon): ?><p class="text-xs text-amber-800 dark:text-amber-300">Need a renewed card? <a class="font-semibold underline" href="mailto:<?= $h(MAIL_REPLY_TO) ?>?subject=ID%20card%20renewal%20%23<?= urlencode((string)$m['member_code']) ?>">Ask the RARL team</a>.</p><?php endif; ?>
          </div>
        </div>
        <?php elseif ($isActive): ?>
        <div class="flex flex-col sm:flex-row items-center gap-5 p-5 rounded-xl border border-dashed border-rarl-red/40 bg-rarl-red/[.03]">
          <span class="w-14 h-14 rounded-2xl bg-rarl-red/10 text-rarl-red flex items-center justify-center text-2xl flex-shrink-0"><i class="fa-solid fa-camera"></i></span>
          <div class="flex-1 text-center sm:text-left"><p class="font-semibold text-gray-900 dark:text-white">Add a photo to get your ID card</p><p class="text-sm text-gray-500 mt-0.5">Your card is generated the moment you upload a clear headshot.</p></div>
          <a href="profile.php#photo" class="px-5 py-2.5 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold rounded-full whitespace-nowrap">Upload photo</a>
        </div>
        <?php else: ?>
        <p class="text-sm text-gray-500 p-5 rounded-xl bg-gray-50 dark:bg-gray-800/50 text-center">Your ID card is issued once your membership is approved.</p>
        <?php endif; ?>
      </section>

      <section id="certificates" class="rarl-panel overflow-hidden scroll-mt-24">
        <div class="px-5 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-800">
          <h2 class="font-heading font-bold text-lg text-gray-900 dark:text-white">Certificates</h2>
          <a href="verify.php" class="text-xs font-semibold text-gray-500 hover:text-rarl-red">How verification works</a>
        </div>
        <?php if (!$certificates): ?>
        <div class="p-10 text-center"><i class="fa-solid fa-award text-3xl text-gray-300"></i><p class="font-semibold text-gray-700 dark:text-gray-200 mt-3">No certificates yet</p><p class="text-sm text-gray-500 mt-1">Attend a RARL event to earn one. It's emailed to you and kept here.</p><a href="events.php" class="inline-flex mt-4 px-4 py-2 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold hover:border-rarl-red hover:text-rarl-red">Browse events</a></div>
        <?php endif; ?>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
          <?php foreach ($certificates as $cert): $isMembership = ($cert['cert_type'] ?? '') === 'membership';
            $verifyUrl = CERT_VERIFY_URL . '?id=' . $cert['uuid'];
            $hasPdf = $cert['pdf_path'] && file_exists(UPLOADS_PATH . '/certificates/' . $cert['pdf_path']); ?>
          <li class="px-5 py-4 flex flex-wrap sm:flex-nowrap items-center gap-4">
            <span class="w-11 h-11 rounded-xl flex items-center justify-center text-lg flex-shrink-0 <?= $isMembership ? 'bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-300' : 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300' ?>"><i class="fa-solid <?= $isMembership ? 'fa-award' : 'fa-trophy' ?>"></i></span>
            <div class="flex-1 min-w-0">
              <p class="font-semibold text-gray-900 dark:text-white"><?= $isMembership ? 'Certificate of Membership' : $h($cert['event_title'] ?? 'Event certificate') ?></p>
              <p class="text-xs text-gray-500"><span class="font-mono"><?= $h($cert['certificate_no']) ?></span> · Issued <?= date('d M Y', strtotime($cert['event_date'] ?: $cert['issued_at'])) ?><?= !$isMembership && $cert['event_type'] ? ' · ' . ucfirst($cert['event_type']) : '' ?></p>
            </div>
            <div class="flex items-center gap-2 w-full sm:w-auto">
              <a href="https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&name=<?= urlencode($isMembership ? 'RARL Membership' : (string)($cert['event_title'] ?? 'RARL Certificate')) ?>&organizationName=<?= urlencode(SITE_NAME) ?>&issueYear=<?= date('Y', strtotime($cert['issued_at'])) ?>&issueMonth=<?= date('n', strtotime($cert['issued_at'])) ?>&certId=<?= urlencode((string)$cert['certificate_no']) ?>&certUrl=<?= urlencode($verifyUrl) ?>" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-full border border-gray-300 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:border-[#0a66c2] hover:text-[#0a66c2]" title="Add to your LinkedIn profile"><i class="fa-brands fa-linkedin"></i> Add to profile</a>
              <button type="button" data-share="<?= $h($verifyUrl) ?>" class="w-9 h-9 rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800" title="Copy verification link" aria-label="Copy verification link"><i class="fa-solid fa-link"></i></button>
              <?php if ($hasPdf): ?><a href="download-cert.php?id=<?= urlencode($cert['uuid']) ?>" class="px-3 py-1.5 rounded-full bg-rarl-red hover:bg-rarl-dark text-white text-xs font-semibold"><i class="fa-solid fa-download"></i> PDF</a><?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>

    <aside class="space-y-6 min-w-0 lg:sticky lg:top-[88px]">
      <section id="benefits" class="rarl-panel p-5 scroll-mt-24">
        <h2 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-3">Your membership includes</h2>
        <ul class="space-y-3 text-sm">
          <?php foreach ([
            ['fa-id-card', 'Verifiable member ID card', 'QR-verified, valid for three years'],
            ['fa-award', 'Certificates for every event', 'Shareable, with a public verification link'],
            ['fa-calendar-days', 'Member registration for events', 'Webinars, workshops, hackathons and conferences'],
            ['fa-graduation-cap', 'Members-only learning', 'Curated courses, talks and reading lists'],
            ['fa-earth-americas', 'A global community', 'Chapters and leaders across every region'],
            ['fa-envelope-open-text', 'The RARL newsletter', 'Research news and opportunities, monthly'],
          ] as [$ic, $t, $sub]): ?>
          <li class="flex gap-3"><i class="fa-solid <?= $ic ?> text-rarl-red mt-1 w-4 text-center"></i><span><span class="block font-semibold text-gray-900 dark:text-white"><?= $t ?></span><span class="block text-xs text-gray-500"><?= $sub ?></span></span></li>
          <?php endforeach; ?>
        </ul>
        <a href="assets/JOINRARL.pdf" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 mt-5 py-2 rounded-full border border-gray-300 dark:border-gray-700 text-sm font-semibold text-gray-800 dark:text-gray-200 hover:border-rarl-red hover:text-rarl-red"><i class="fa-solid fa-file-pdf"></i> Welcome letter</a>
      </section>

      <section class="rarl-panel p-5 text-sm">
        <h2 class="text-sm font-bold text-gray-900 dark:text-white mb-2">Need help?</h2>
        <p class="text-gray-500">Wrong details on a certificate or card, or a membership question?</p>
        <a href="mailto:<?= $h(MAIL_REPLY_TO) ?>" class="inline-block mt-2 font-semibold text-rarl-red hover:underline"><?= $h(MAIL_REPLY_TO) ?></a>
      </section>
    </aside>
  </main>
</div>
<script>
  document.querySelectorAll('[data-share]').forEach(function (b) { b.addEventListener('click', async function () {
    var url = b.dataset.share;
    try { if (navigator.share) await navigator.share({title: 'My RARL certificate', url: url}); else { await navigator.clipboard.writeText(url); b.innerHTML = '<i class="fa-solid fa-check text-green-600"></i>'; setTimeout(function () { b.innerHTML = '<i class="fa-solid fa-link"></i>'; }, 1500); } } catch (e) {}
  }); });
</script>
<?= publicFooter() ?>
</body></html>
