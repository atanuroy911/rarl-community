<?php
/**
 * RARL Admin — Template preview with sample data.
 *   GET  ?id=N            → preview page (exact PDF output embedded + HTML render)
 *   GET  ?id=N&pdf=1      → the sample PDF itself
 *   POST id, config_json, sample_json (from the designer) → PDF of the *unsaved*
 *        layout, so admins can check real output before saving anything.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
$pdo = db();

$id = (int)($_REQUEST['id'] ?? 0);
$t = $pdo->prepare("SELECT * FROM certificate_templates WHERE id = ?"); $t->execute([$id]);
$template = $t->fetch();
if (!$template) { $_SESSION['flash'] = ['type'=>'error','msg'=>'Template not found.']; header('Location: templates.php'); exit; }

$sampleData = templateSampleData($template['type']);

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost || !empty($_GET['pdf'])) {
    if ($isPost) {
        if (!adminCsrfOk()) { http_response_code(403); exit('Invalid session — reload the designer.'); }
        $config = $_POST['config_json'] ?? '[]';
        if (is_array(json_decode($config, true))) $template['config'] = $config;
        $overrides = json_decode($_POST['sample_json'] ?? '{}', true);
        if (is_array($overrides)) {
            foreach ($overrides as $k => $v) if (is_string($v) && isset($sampleData[$k])) $sampleData[$k] = mb_substr($v, 0, 200);
        }
    }
    $pdf = renderTemplatePdf($template, $sampleData, null);
    if (!is_string($pdf)) { http_response_code(500); exit('PDF library unavailable.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="preview-' . $id . '.pdf"');
    header('Cache-Control: no-store');
    echo $pdf; exit;
}

$back = $template['type'] === 'id_card' ? 'templates.php' : 'certificates.php';
adminWrap(function() use ($template, $sampleData, $back, $id) { ?>
<div class="max-w-5xl">
  <div class="flex flex-wrap items-center gap-3 mb-5">
    <a href="<?= $back ?>" class="w-9 h-9 flex items-center justify-center rounded-xl bg-white border border-gray-200 text-gray-500 hover:text-rarl-red" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="flex-1 min-w-0">
      <h1 class="text-xl font-black text-gray-900 truncate"><?= htmlspecialchars($template['name']) ?></h1>
      <p class="text-xs text-gray-500">Sample preview — this is the exact PDF that will be generated.</p>
    </div>
    <a href="templates.php?edit=<?= $id ?>" class="px-4 py-2 bg-white border border-gray-200 hover:border-rarl-red text-sm font-semibold rounded-xl"><i class="fa-solid fa-pen-ruler"></i> Open in designer</a>
    <a href="preview-template.php?id=<?= $id ?>&pdf=1" download class="px-4 py-2 bg-rarl-red hover:bg-rarl-dark text-white text-sm font-semibold rounded-xl"><i class="fa-solid fa-download"></i> Download sample PDF</a>
  </div>
  <div class="bg-white border border-gray-200 rounded-2xl p-3 shadow-sm">
    <iframe src="preview-template.php?id=<?= $id ?>&pdf=1#toolbar=0&view=Fit" title="PDF preview" class="w-full rounded-xl bg-gray-100" style="aspect-ratio:<?= (float)$template['page_width_mm'] ?>/<?= (float)$template['page_height_mm'] ?>;max-height:80vh"></iframe>
  </div>
  <details class="mt-4 bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
    <summary class="text-sm font-semibold text-gray-700 cursor-pointer">Browser (HTML) render</summary>
    <div class="mt-3"><?= renderTemplateHtml($template, $sampleData) ?></div>
  </details>
</div>
<?php }, 'templates', 'Preview: ' . $template['name']);
