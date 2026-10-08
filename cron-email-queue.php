<?php
/**
 * RARL — Email queue worker for cron (optional; the admin UI also drains the
 * queue while any admin page is open). cPanel cron example, every minute:
 *   php /home/USER/public_html/membership/cron-email-queue.php
 * CLI only — refuses to run over HTTP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/functions.php';
$deadline = time() + 50;
$total = ['sent' => 0, 'failed' => 0];
while (time() < $deadline) {
    $r = processEmailQueue(20, max(1, $deadline - time()));
    $total['sent'] += $r['sent']; $total['failed'] += $r['failed'];
    if ($r['sent'] + $r['failed'] === 0) break;
}
echo date('c') . " sent={$total['sent']} failed={$total['failed']}\n";
