<?php
/** robots.txt (served as /robots.txt): public pages open, private and admin areas closed. */
require_once __DIR__ . '/env.php'; loadEnv(__DIR__ . '/.env'); require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$path = rtrim(parse_url(SITE_URL, PHP_URL_PATH) ?: '', '/');
echo "User-agent: *\n";
foreach (['/admin/', '/uploads/cv/', '/uploads/id-cards/', '/dashboard.php', '/membership.php', '/profile.php', '/search.php', '/impersonate.php', '/deploy-extract.php'] as $d) echo "Disallow: {$path}{$d}\n";
echo "Allow: /\n\nSitemap: " . rtrim(SITE_URL, '/') . "/sitemap.xml\n";
