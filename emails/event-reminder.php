<?php
// Expects: $event (events row), $memberName, $eventUrl, $icsUrl
$t = fn($v) => htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
$isToday = $event['event_date'] === date('Y-m-d');
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= emailLogoUrl() ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
  </div>
  <div style="background: #ffffff; padding: 28px; border-radius: 12px; border: 1px solid #eee;">
    <p style="margin:0 0 4px; font-size:12px; font-weight:bold; letter-spacing:.08em; text-transform:uppercase; color: <?= BRAND_RED ?>;"><?= $isToday ? 'Happening today' : 'Tomorrow' ?></p>
    <h2 style="margin:0 0 16px; font-size:22px; color:#111;"><?= $t($event['title']) ?></h2>
    <p>Hi <?= $t($memberName) ?>, a quick reminder that you're registered.</p>
    <table cellpadding="0" cellspacing="0" style="width:100%; margin:18px 0; background:#f8f9fa; border-radius:8px;">
      <tr><td style="padding:12px 16px; font-size:14px;"><strong>When:</strong> <?= date('l, d F Y', strtotime($event['event_date'])) ?><?= $event['event_time'] ? ' at ' . date('g:i A', strtotime($event['event_time'])) : '' ?></td></tr>
      <tr><td style="padding:0 16px 12px; font-size:14px;"><strong>Where:</strong> <?= $event['location'] ? $t($event['location']) : 'Online' ?></td></tr>
      <?php if (!empty($event['speaker_name'])): ?><tr><td style="padding:0 16px 12px; font-size:14px;"><strong>Speaker:</strong> <?= $t($event['speaker_name']) ?></td></tr><?php endif; ?>
    </table>
    <div style="margin: 24px 0; text-align:center;">
      <?php if (!empty($event['online_url'])): ?>
      <a href="<?= htmlspecialchars($event['online_url']) ?>" style="background: <?= BRAND_RED ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 999px; font-weight: bold; display: inline-block;">Join link</a>
      <?php else: ?>
      <a href="<?= htmlspecialchars($eventUrl) ?>" style="background: <?= BRAND_RED ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 999px; font-weight: bold; display: inline-block;">Event details &amp; check-in pass</a>
      <?php endif; ?>
    </div>
    <p style="font-size:13px; color:#666;"><?php if (empty($event['online_url'])): ?>At the venue, open the event in the RARL app and show your check-in pass; your certificate is issued as soon as you're scanned. <?php endif; ?>
      <a href="<?= htmlspecialchars($icsUrl) ?>" style="color: <?= BRAND_RED ?>;">Add to calendar</a> · <a href="<?= htmlspecialchars($eventUrl) ?>" style="color: <?= BRAND_RED ?>;">Can't make it? Cancel your place</a></p>
  </div>
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">&copy; <?= date('Y') ?> Robotics &amp; Automation Research Lab (RARL)</div>
</div>
