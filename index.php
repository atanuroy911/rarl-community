<?php
/**
 * RARL Community Platform — Public Landing Page
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }

$loggedInMember = null;
if (!empty($_SESSION['member_id'])) {
    $meStmt = db()->prepare('SELECT full_name, lab_name, type, status, avatar_path FROM members WHERE id = ?');
    $meStmt->execute([(int)$_SESSION['member_id']]);
    $loggedInMember = $meStmt->fetch();
}

echo htmlHead('Join the RARL Community');
?>
<?= publicNav('home') ?>

<!-- ── HERO ──────────────────────────────────────────────── -->
<section class="relative overflow-hidden bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800">
  <div class="relative z-10 max-w-6xl mx-auto px-6 py-20 md:py-28 grid md:grid-cols-2 gap-12 items-center">
    <div>
      <?php if ($loggedInMember): ?>
      <?php
        $myName = $loggedInMember['type'] === 'lab' ? $loggedInMember['lab_name'] : $loggedInMember['full_name'];
        $firstName = trim(explode(' ', $myName ?: '')[0] ?? '');
      ?>
      <div class="inline-flex items-center gap-2 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-7">
        <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Welcome back
      </div>
      <h1 class="text-4xl sm:text-5xl lg:text-[3.4rem] font-black text-gray-900 dark:text-white leading-tight mb-5">
        Good to see you,<br/><span class="text-rarl-red"><?= htmlspecialchars($firstName ?: 'there') ?></span>
      </h1>
      <?php if ($loggedInMember['status'] !== 'active'): ?>
      <p class="text-amber-600 dark:text-amber-400 text-base max-w-xl leading-relaxed mb-9">
        ⏳ Your account is still pending approval — you'll get an email as soon as it's reviewed. In the meantime, feel free to browse the community and learning hub.
      </p>
      <?php else: ?>
      <p class="text-gray-500 dark:text-gray-400 text-xl max-w-xl leading-relaxed mb-9">
        Catch up on the community feed, check your certificates, or pick up where you left off.
      </p>
      <?php endif; ?>
      <div class="flex flex-wrap gap-4 mb-14">
        <a href="dashboard.php" class="inline-flex items-center gap-2 px-7 py-3.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all text-sm">
          Go to My Dashboard →
        </a>
        <a href="community.php" class="inline-flex items-center gap-2 px-7 py-3.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-800 dark:text-white font-semibold rounded-xl border border-gray-200 dark:border-gray-700 hover:-translate-y-0.5 transition-all text-sm">
          Community Feed
        </a>
      </div>
      <?php else: ?>
      <div class="inline-flex items-center gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-rarl-red text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-7">
        <span class="w-1.5 h-1.5 rounded-full bg-rarl-red animate-pulse"></span> <?= htmlspecialchars(setting('home_hero_badge', 'Now Open for Membership')) ?>
      </div>
      <h1 class="text-4xl sm:text-5xl lg:text-[3.4rem] font-black text-gray-900 dark:text-white leading-tight mb-5">
        <?php
          $heroTitle = setting('home_hero_title', 'Join the Global RARL Research Community');
          $heroTitleParts = explode(' RARL ', $heroTitle, 2);
        ?>
        <?php if (count($heroTitleParts) === 2): ?>
        <?= htmlspecialchars($heroTitleParts[0]) ?><br/><span class="text-rarl-red">RARL <?= htmlspecialchars($heroTitleParts[1]) ?></span>
        <?php else: ?>
        <?= htmlspecialchars($heroTitle) ?>
        <?php endif; ?>
      </h1>
      <p class="text-gray-500 dark:text-gray-400 text-xl max-w-xl leading-relaxed mb-9">
        <?= htmlspecialchars(setting('home_hero_subtitle', 'Connect with researchers worldwide. Access curated learning resources, receive newsletters, attend events, and earn verifiable digital certificates for your contributions.')) ?>
      </p>
      <div class="flex flex-wrap gap-4 mb-14">
        <a href="register.php" class="inline-flex items-center gap-2 px-7 py-3.5 bg-rarl-red hover:bg-rarl-dark text-white font-bold rounded-xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all text-sm">
          Join Free — Get Started →
        </a>
        <a href="community.php" class="inline-flex items-center gap-2 px-7 py-3.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-800 dark:text-white font-semibold rounded-xl border border-gray-200 dark:border-gray-700 hover:-translate-y-0.5 transition-all text-sm">
          Explore Community
        </a>
      </div>
      <?php endif; ?>
      <!-- Stats -->
      <div class="pt-8 border-t border-gray-100 dark:border-gray-800 flex flex-wrap gap-10">
        <?php
          $membersOverride = setting('stat_members_override', '');
          $labsOverride    = setting('stat_labs_override', '');
          $certsOverride   = setting('stat_certs_override', '');
          $total = $membersOverride !== '' ? $membersOverride : (int)(db()->query('SELECT COUNT(*) FROM members WHERE status="active"')->fetchColumn());
          $labs  = $labsOverride !== ''    ? $labsOverride    : (int)(db()->query('SELECT COUNT(*) FROM members WHERE type="lab" AND status="active"')->fetchColumn());
          $certs = $certsOverride !== ''   ? $certsOverride   : (int)(db()->query('SELECT COUNT(*) FROM certificates')->fetchColumn());
          $countries = setting('stat_countries', '5');
        ?>
        <div><div class="font-heading font-black text-3xl text-gray-900 dark:text-white"><?= $total ?: '0' ?> <span class="text-rarl-red">+</span></div><div class="text-gray-400 text-xs mt-1 uppercase tracking-wider">Members</div></div>
        <div><div class="font-heading font-black text-3xl text-gray-900 dark:text-white"><?= $labs ?: '0' ?> <span class="text-rarl-red">+</span></div><div class="text-gray-400 text-xs mt-1 uppercase tracking-wider">Research Labs</div></div>
        <div><div class="font-heading font-black text-3xl text-gray-900 dark:text-white"><?= $certs ?: '0' ?> <span class="text-rarl-red">+</span></div><div class="text-gray-400 text-xs mt-1 uppercase tracking-wider">Certificates Issued</div></div>
        <div><div class="font-heading font-black text-3xl text-gray-900 dark:text-white"><?= htmlspecialchars($countries) ?: '0' ?> <span class="text-rarl-red">+</span></div><div class="text-gray-400 text-xs mt-1 uppercase tracking-wider">Countries</div></div>
      </div>
    </div>
    <div class="hidden md:flex justify-center">
      <div class="relative w-full max-w-sm aspect-square rounded-3xl bg-gradient-to-br from-blue-50 to-red-50 dark:from-gray-800 dark:to-gray-800 flex items-center justify-center overflow-hidden">
        <div id="rarl-hero-lottie" class="w-full h-full"></div>
      </div>
    </div>
  </div>
</section>

<script src="https://unpkg.com/lottie-web@5.12.2/build/player/lottie.min.js"></script>
<script>
  <?php $lottieJson = getLottieJson('https://rarl-lab.com/wp-content/uploads/2026/03/Anima-Bot.json'); ?>
  <?php if ($lottieJson): ?>
  lottie.loadAnimation({
    container: document.getElementById('rarl-hero-lottie'),
    renderer: 'svg',
    loop: true,
    autoplay: true,
    animationData: <?= $lottieJson ?>
  });
  <?php endif; ?>
</script>

<!-- ── ABOUT RARL ───────────────────────────────────────── -->
<section class="py-20 bg-gray-50 dark:bg-gray-950">
  <div class="max-w-6xl mx-auto px-6">
    <div class="max-w-3xl reveal mb-14">
      <span class="text-sm font-bold uppercase tracking-widest text-rarl-red">About Us</span>
      <h2 class="text-3xl md:text-4xl font-black text-gray-900 dark:text-white mt-2 mb-4">Robotics and Automation Research Lab</h2>
      <p class="text-gray-600 dark:text-gray-300 text-lg leading-relaxed mb-4">
        The Robotics and Automation Research Laboratory (RARL) was established to innovate,
        design, and develop robots, with a particular focus on service robots and automation
        projects. Beyond research, RARL aims to provide consultation and support to industries
        seeking advanced automation solutions.
      </p>
      <p class="text-gray-600 dark:text-gray-300 text-lg leading-relaxed">
        RARL hosts researchers from Italy, Iran, the UK, the USA, and Saudi Arabia.
      </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
      <?php $achievements = [
        ['📄', 'Publication of several research papers', 'Published several impactful research papers across reputed journals and conferences.'],
        ['🎓', 'Successful mentorship of 12 students', 'Successfully mentored 12 students, guiding them through academic and professional growth.'],
        ['©️', 'Securing 12 copyrights', 'Secured 12 copyrights showcasing innovation and original contributions.'],
        ['📚', 'Authorship of 3 books and 4 book chapters', 'Authored 3 books and 4 book chapters, advancing knowledge in the field.'],
      ]; ?>
      <?php foreach ($achievements as $i => [$icon, $title, $desc]): ?>
      <div class="reveal reveal-delay-<?= $i+1 ?> bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 hover:-translate-y-1 hover:shadow-lg transition-all duration-250">
        <div class="w-12 h-12 rounded-xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-2xl mb-4"><?= $icon ?></div>
        <h3 class="font-heading font-bold text-base text-gray-900 dark:text-white mb-2 leading-snug"><?= htmlspecialchars($title) ?></h3>
        <p class="text-gray-500 dark:text-gray-400 text-base leading-relaxed"><?= htmlspecialchars($desc) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── WHAT YOU GET ─────────────────────────────────────── -->
<section class="py-20 bg-white dark:bg-gray-900">
  <div class="max-w-6xl mx-auto px-6">
    <div class="text-center mb-14 reveal">
      <span class="text-xs font-bold uppercase tracking-widest text-rarl-red">Platform Modules</span>
      <h2 class="text-3xl md:text-4xl font-black text-gray-900 dark:text-white mt-2 mb-3">Everything in One Place</h2>
      <p class="text-gray-500 dark:text-gray-400 max-w-lg mx-auto text-base">A focused community management platform built for scientific organisations, labs, and researchers.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">

      <?php $modules = [
        ['👥','Member Profiles','Individual researchers and research labs register with rich academic profiles — ORCID, Google Scholar, research interests, institution.','bg-blue-50 dark:bg-blue-900/20','text-blue-600 dark:text-blue-400','border-blue-200 dark:border-blue-800'],
        ['💬','Community Feed','Approved members can post updates, discuss research, and connect with fellow researchers on the community feed.','bg-purple-50 dark:bg-purple-900/20','text-purple-600 dark:text-purple-400','border-purple-200 dark:border-purple-800'],
        ['📚','Learning Hub','Curated YouTube playlists, articles, and free books — plus premium courses unlocked exclusively for members.','bg-green-50 dark:bg-green-900/20','text-green-600 dark:text-green-400','border-green-200 dark:border-green-800'],
        ['🎥','Events &amp; Webinars','Register for workshops and webinars, some open to everyone, some reserved for members — with recordings archived afterward.','bg-indigo-50 dark:bg-indigo-900/20','text-indigo-600 dark:text-indigo-400','border-indigo-200 dark:border-indigo-800'],
        ['🧑‍🤝‍🧑','Member Directory','Search the community by country, institution, or research interest — and find or become a mentor.','bg-pink-50 dark:bg-pink-900/20','text-pink-600 dark:text-pink-400','border-pink-200 dark:border-pink-800'],
        ['🏆','Certificates','Attend workshops, webinars, or competitions and receive a verifiable PDF certificate with a unique ID and QR code — ready for LinkedIn and your CV.','bg-red-50 dark:bg-red-900/20','text-red-600 dark:text-red-400','border-red-200 dark:border-red-800'],
        ['📧','Newsletter','Regular updates on RARL research, upcoming events, funding opportunities, and featured community members delivered to your inbox.','bg-amber-50 dark:bg-amber-900/20','text-amber-600 dark:text-amber-400','border-amber-200 dark:border-amber-800'],
        ['🔐','Member Dashboard','Log in anytime to view and download your certificates, update your profile, and manage your newsletter preferences.','bg-gray-50 dark:bg-gray-800','text-gray-600 dark:text-gray-300','border-gray-200 dark:border-gray-700'],
      ]; ?>

      <?php foreach ($modules as $i => [$icon, $name, $desc, $bg, $tc, $bc]): ?>
      <div class="reveal reveal-delay-<?= $i+1 ?> group p-6 rounded-2xl border <?= $bg ?> <?= $bc ?> hover:-translate-y-1 hover:shadow-lg transition-all duration-250 cursor-default">
        <div class="w-11 h-11 rounded-xl <?= $bg ?> flex items-center justify-center text-xl mb-4"><?= $icon ?></div>
        <h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-2"><?= $name ?></h3>
        <p class="text-gray-500 dark:text-gray-400 text-base leading-relaxed"><?= $desc ?></p>
      </div>
      <?php endforeach; ?>

    </div>
  </div>
</section>

<!-- ── HOW IT WORKS ───────────────────────────────────────── -->
<section class="py-20 bg-gray-50 dark:bg-gray-950">
  <div class="max-w-4xl mx-auto px-6 text-center">
    <div class="reveal mb-14">
      <span class="text-xs font-bold uppercase tracking-widest text-rarl-red">Simple Process</span>
      <h2 class="text-3xl md:text-4xl font-black text-gray-900 dark:text-white mt-2 mb-3">Join in 3 Steps</h2>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
      <?php $steps = [
        ['1','Register Free','Fill in a short form as an Individual Researcher or a Research Lab. Takes less than 2 minutes.'],
        ['2','Get Approved','Our team reviews and approves your application within 1–2 business days.'],
        ['3','Start Participating','Access the community, browse learning resources, attend events, and earn certificates.'],
      ]; foreach ($steps as $i => [$num, $title, $desc]): ?>
      <div class="reveal reveal-delay-<?= $i+1 ?> text-center">
        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-rarl-navy text-white flex items-center justify-center font-heading font-black text-xl shadow-lg"><?= $num ?></div>
        <h3 class="font-heading font-bold text-lg text-gray-900 dark:text-white mb-2"><?= $title ?></h3>
        <p class="text-gray-500 dark:text-gray-400 text-base leading-relaxed"><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── CERTIFICATE HIGHLIGHT ────────────────────────────── -->
<section class="py-20 bg-white dark:bg-gray-900">
  <div class="max-w-6xl mx-auto px-6">
    <div class="rounded-3xl overflow-hidden" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
      <div class="p-10 md:p-14 flex flex-col md:flex-row items-start md:items-center gap-10">
        <div class="flex-1 reveal">
          <span class="inline-flex items-center gap-2 bg-red-900/30 border border-red-700/40 text-red-300 text-xs font-bold uppercase tracking-widest px-3 py-1.5 rounded-full mb-5">🏆 Certificates</span>
          <h2 class="font-heading font-black text-2xl md:text-3xl text-white mb-4">Verifiable Digital Certificates</h2>
          <p class="text-white/60 text-base leading-relaxed mb-6 max-w-lg">Every workshop, webinar, and competition you attend generates a professional PDF certificate with a unique ID and QR code that anyone can verify instantly online.</p>
          <ul class="space-y-2 mb-8">
            <?php foreach (['Unique certificate ID (e.g. RARL-2025-0042)', 'QR code links to public verification page', 'Download anytime from your member dashboard', 'LinkedIn-ready — add to your profile with a click'] as $pt): ?>
            <li class="flex items-center gap-2.5 text-white/70 text-base"><span class="w-4 h-4 rounded-full bg-green-900/40 text-green-400 flex items-center justify-center text-[10px] font-bold">✓</span><?= $pt ?></li>
            <?php endforeach; ?>
          </ul>
          <a href="register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-rarl-red hover:bg-rarl-dark text-white font-semibold rounded-xl text-sm transition-all hover:-translate-y-0.5">Get Started Free →</a>
        </div>
        <!-- Certificate mockup -->
        <div class="flex-shrink-0 reveal reveal-delay-2 w-full md:w-auto">
          <div class="w-full max-w-xs mx-auto bg-white rounded-2xl shadow-2xl p-7 text-center">
            <div class="text-xs font-bold uppercase tracking-widest text-rarl-red mb-4">Certificate of Participation</div>
            <img src="<?= BRAND_MARK_PATH ?>" alt="RARL" class="w-12 h-12 rounded-xl mx-auto mb-4 object-contain shadow"/>
            <div class="font-heading font-bold text-gray-900 text-lg mb-1">Dr. Jane Smith</div>
            <div class="text-gray-400 text-xs mb-4">has successfully completed</div>
            <div class="font-semibold text-gray-800 text-sm mb-3">AI Research Workshop 2025</div>
            <div class="text-gray-300 text-[10px] font-mono mb-4">RARL-2025-0042</div>
            <div class="w-16 h-16 mx-auto bg-gray-100 rounded-lg flex items-center justify-center text-3xl mb-2">▣</div>
            <div class="text-[9px] text-gray-300">Scan to verify</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── CTA ────────────────────────────────────────────────── -->
<section class="py-16 bg-rarl-red">
  <div class="max-w-3xl mx-auto px-6 text-center">
    <?php if ($loggedInMember): ?>
    <h2 class="font-heading font-black text-2xl md:text-3xl text-white mb-3">Ready to share something with the community?</h2>
    <p class="text-white/75 text-base mb-7">Post an update, ask a question, or check out what other members are working on.</p>
    <a href="community.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all text-sm">
      Go to the Community Feed →
    </a>
    <?php else: ?>
    <h2 class="font-heading font-black text-2xl md:text-3xl text-white mb-3">Ready to Join the RARL Community?</h2>
    <p class="text-white/75 text-base mb-2">Free for your first year. No credit card. Open to researchers worldwide.</p>
    <p class="text-white/60 text-sm mb-7 max-w-lg mx-auto">Members are expected to use the RARL affiliation as the second affiliation on at least one paper during their free year.</p>
    <a href="register.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-rarl-red font-bold rounded-xl shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all text-sm">
      Create Your Free Account →
    </a>
    <?php endif; ?>
  </div>
</section>

<!-- Footer -->
<?= publicFooter() ?>

<script>
  const io = new IntersectionObserver(es => es.forEach(e => { if(e.isIntersecting){e.target.classList.add('visible');io.unobserve(e.target);} }), {threshold:.12});
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));
</script>

<?php
  $popupEnabled = setting('popup_enabled', '0') === '1';
  $popupImage   = setting('popup_image_path', '');
?>
<?php if ($popupEnabled && $popupImage !== ''): ?>
<!-- Homepage Popup -->
<div id="rarl-popup-overlay" class="hidden fixed inset-0 z-[100] bg-black/60 backdrop-blur-sm items-center justify-center p-6">
  <div class="relative max-w-md w-full">
    <button id="rarl-popup-close" type="button" aria-label="Close" class="absolute -top-3 -right-3 w-9 h-9 rounded-full bg-white text-gray-700 font-bold shadow-lg flex items-center justify-center hover:bg-gray-100 z-10">✕</button>
    <a href="<?= htmlspecialchars(setting('popup_link_url', '')) ?>" target="_blank" rel="noopener">
      <img src="<?= UPLOADS_URL ?>/popups/<?= htmlspecialchars($popupImage) ?>" alt="<?= htmlspecialchars(setting('popup_alt_text', '')) ?>" class="w-full rounded-2xl shadow-2xl"/>
    </a>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('rarl-popup-overlay');
    if (!overlay) return;
    if (sessionStorage.getItem('rarl_popup_dismissed')) return;
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
    function dismiss() {
      overlay.classList.add('hidden');
      overlay.classList.remove('flex');
      sessionStorage.setItem('rarl_popup_dismissed', '1');
    }
    document.getElementById('rarl-popup-close').addEventListener('click', dismiss);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) dismiss(); });
  });
</script>
<?php endif; ?>
</body></html>
