<?php
/**
 * RARL Admin — Live template preview with sample data (used by the "Preview
 * Sample" link on Templates and Certificates so admins can check a layout
 * before generating/sending anything for real).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$t = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ?"); $t->execute([$id]);
$template = $t->fetch();
if (!$template) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Template not found.']; header('Location: templates.php'); exit; }

$sampleData = $template['type'] === 'id_card' ? [
    'name' => 'Dr. Jane Sample', 'member_code' => 'RARL-000000', 'since_date' => date('Y/m/d'),
    'section' => 'Sample Chapter', 'signer1' => 'RARL President', 'signer2' => 'Chapter Chair',
    'verify_url' => SITE_URL . '/id-card-verify.php?code=SAMPLE',
] : [
    'name' => 'Dr. Jane Sample', 'event' => 'Sample Workshop 2026', 'date' => date('d F Y'),
    'cert_no' => 'RARL-2026-SAMPLE', 'verify_url' => CERT_VERIFY_URL . '?id=sample',
];

htmlAdminHead('Preview: ' . $template['name']);
?>
<div class="max-w-4xl mx-auto p-6">
  <a href="<?= $template['type'] === 'id_card' ? 'templates.php' : 'certificates.php' ?>" class="text-xs text-gray-400 hover:text-rarl-red">← Back</a>
  <h1 class="text-xl font-black text-gray-900 mt-2 mb-4"><?= htmlspecialchars($template['name']) ?> <span class="text-gray-400 font-normal text-sm">— sample preview</span></h1>
  <?= renderTemplateHtml($template, $sampleData) ?>
</div>
</body></html>
