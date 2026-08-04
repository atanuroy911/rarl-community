<?php
/**
 * RARL — Registration: Choose Type
 */
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_name(MEMBER_SESSION_NAME); session_start(); }
if (!empty($_SESSION['member_id'])) redirect('dashboard.php');
echo htmlHead('Join the Community');
?>
<?= publicNav() ?>

<section class="min-h-[calc(100vh-68px)] flex items-center justify-center py-16 px-4" style="background:linear-gradient(135deg,<?= BRAND_INK ?> 0%,<?= BRAND_INK_SOFT ?> 100%);">
  <div class="w-full max-w-2xl">
    <div class="text-center mb-10">
      <div class="inline-flex items-center gap-2 bg-red-900/30 border border-red-700/40 text-red-300 text-xs font-bold uppercase tracking-widest px-4 py-1.5 rounded-full mb-5">
        <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span> Free Membership
      </div>
      <h1 class="font-heading font-black text-3xl md:text-4xl text-white mb-3">Who are you joining as?</h1>
      <p class="text-white/55 text-sm">Choose the profile type that best describes you. You can always update your details later.</p>
      <p class="text-white/40 text-xs mt-4 max-w-lg mx-auto">Membership is free for one year. In return, members are expected to use the RARL affiliation as the second affiliation on at least one paper during that year.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

      <!-- Individual -->
      <a href="register-individual.php"
        class="group block bg-white/5 hover:bg-white/10 border-2 border-white/10 hover:border-rarl-red/60 rounded-2xl p-8 transition-all duration-250 hover:-translate-y-1 hover:shadow-2xl cursor-pointer">
        <div class="w-14 h-14 bg-red-900/30 border-2 border-red-700/40 rounded-2xl flex items-center justify-center text-3xl mb-5 group-hover:scale-110 transition-transform"><i class="fa-solid fa-user-graduate"></i></div>
        <h2 class="font-heading font-bold text-xl text-white mb-2">Individual Researcher</h2>
        <p class="text-white/55 text-sm leading-relaxed mb-5">For PhD students, researchers, professors, postdocs, and any individual working in or interested in robotics and automation.</p>
        <ul class="space-y-1.5 mb-6">
          <?php foreach (['Name, institution, country','Research interests & ORCID','Google Scholar & LinkedIn','Personal newsletter subscription'] as $f): ?>
          <li class="flex items-center gap-2 text-xs text-white/60"><span class="text-green-400"><i class="fa-solid fa-check"></i></span><?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold text-rarl-red uppercase tracking-wider">Register as Individual →</span>
        </div>
      </a>

      <!-- Lab -->
      <a href="register-lab.php"
        class="group block bg-white/5 hover:bg-white/10 border-2 border-white/10 hover:border-blue-500/60 rounded-2xl p-8 transition-all duration-250 hover:-translate-y-1 hover:shadow-2xl cursor-pointer">
        <div class="w-14 h-14 bg-blue-900/30 border-2 border-blue-700/40 rounded-2xl flex items-center justify-center text-3xl mb-5 group-hover:scale-110 transition-transform"><i class="fa-solid fa-school"></i></div>
        <h2 class="font-heading font-bold text-xl text-white mb-2">Research Lab</h2>
        <p class="text-white/55 text-sm leading-relaxed mb-5">For entire research groups, departments, or university labs wanting a community presence, newsletter access, and team certificates.</p>
        <ul class="space-y-1.5 mb-6">
          <?php foreach (['Lab name, university & PI','Research areas & website','Lab newsletter subscription','Team-wide certificate management'] as $f): ?>
          <li class="flex items-center gap-2 text-xs text-white/60"><span class="text-blue-400"><i class="fa-solid fa-check"></i></span><?= $f ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold text-blue-400 uppercase tracking-wider">Register as Lab →</span>
        </div>
      </a>

    </div>

    <p class="text-center text-white/35 text-xs mt-8">
      Already a member? <a href="login.php" class="text-white/60 hover:text-white transition-colors underline">Sign in here</a>
    </p>
  </div>
</section>

<?= publicFooter() ?>
</body></html>
