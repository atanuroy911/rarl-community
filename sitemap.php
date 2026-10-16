<?php
/** XML sitemap of every public page, event, video and approved publication (served as /sitemap.xml). */
require_once __DIR__ . '/functions.php';
header('Content-Type: application/xml; charset=utf-8');
$base = rtrim(SITE_URL, '/');
$urls = [];
foreach (['index.php' => '1.0', 'events.php' => '0.9', 'videos.php' => '0.8', 'publications.php' => '0.8', 'resources.php' => '0.8',
          'community.php' => '0.7', 'people.php' => '0.6', 'partners.php' => '0.5', 'directory.php' => '0.5', 'register.php' => '0.7', 'verify.php' => '0.3'] as $p => $pri)
    $urls[] = [$base . '/' . $p, null, $pri];
$q = function (string $sql) { try { return db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; } };
foreach ($q("SELECT id, created_at FROM videos WHERE is_published = 1 AND access_level = 'public'") as $r) $urls[] = [$base . '/videos.php?v=' . $r['id'], $r['created_at'], '0.6'];
foreach ($q("SELECT id, approved_at FROM publications WHERE status = 'approved'") as $r) $urls[] = [$base . '/publications.php?id=' . $r['id'], $r['approved_at'], '0.6'];
foreach ($q("SELECT id, created_at FROM events WHERE is_active = 1 AND visibility = 'public'") as $r) $urls[] = [$base . '/events.php?event=' . $r['id'], $r['created_at'], '0.6'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $mod, $pri]) {
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . date('Y-m-d', strtotime($mod)) . '</lastmod>' : '') . '<priority>' . $pri . "</priority></url>\n";
}
echo '</urlset>';
