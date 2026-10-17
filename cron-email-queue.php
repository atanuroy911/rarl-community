<?php
/**
 * RARL — Email queue worker for cron. Sends event reminders the day before each
 * event and delivers queued emails, staying under the host's hourly limit
 * (MAIL_HOURLY_LIMIT): when this hour's budget is used up it stops and the next
 * run continues. The admin UI also sends while an admin page is open; this
 * keeps big batches going when nobody is signed in.
 * cPanel → Cron Jobs, once per five minutes (Admin → Outbox shows the exact path):
 *   /usr/local/bin/php /home/USER/SITE_DIR/cron-email-queue.php >/dev/null 2>&1
 * Keep ">/dev/null 2>&1": otherwise cPanel emails the output of every run, and
 * those emails count against the same hourly limit.
 * CLI only — refuses to run over HTTP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/functions.php';
$reminders = sendEventReminders();   // the day before each event
$regen = membershipCertAutoRegen() ? regenerateOutdatedMembershipCerts(50, 40) : 0; // design changed
$deadline = time() + 50;
$total = ['sent' => 0, 'failed' => 0];
while (time() < $deadline) {
    $r = processEmailQueue(20, max(1, $deadline - time()));
    $total['sent'] += $r['sent']; $total['failed'] += $r['failed'];
    if ($r['sent'] + $r['failed'] === 0) break;
}
echo date('c') . " reminders={$reminders} certs_updated={$regen} sent={$total['sent']} failed={$total['failed']}\n";
