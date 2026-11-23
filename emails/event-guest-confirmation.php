<?php
// Expects: $event (events row), $guest (event_guest_registrations row), $manageUrl, $icsUrl, $updated (bool)
$t = fn($v) => htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
$tz = eventTz($event);
$online = !empty($event['online_url']);
$btn = 'background:' . BRAND_RED . '; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:999px; font-weight:bold; display:inline-block;';
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= emailLogoUrl() ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
  </div>
  <div style="background: #ffffff; padding: 28px; border-radius: 12px; border: 1px solid #eee;">
    <p style="margin:0 0 4px; font-size:12px; font-weight:bold; letter-spacing:.08em; text-transform:uppercase; color: <?= BRAND_RED ?>;"><?= !empty($updated) ? 'Registration updated' : "You're registered" ?></p>
    <h2 style="margin:0 0 16px; font-size:22px; color:#111;"><?= $t($event['title']) ?></h2>
    <p>Hi <?= $t($guest['full_name']) ?>, <?= !empty($updated) ? 'your details for this event have been updated.' : 'thanks for registering. Your place is confirmed.' ?></p>
    <table cellpadding="0" cellspacing="0" style="width:100%; margin:18px 0; background:#f8f9fa; border-radius:8px;">
      <tr><td style="padding:12px 16px; font-size:14px;"><strong>When:</strong> <?= $t(eventWhenText($event, true)) ?><?php if (!empty($event['event_time'])): ?><br><span style="font-size:12px; color:#666;">Time zone: <?= $t(str_replace('_', ' ', $tz->getName())) ?>. Your registration page shows it in your own time.</span><?php endif; ?></td></tr>
      <tr><td style="padding:0 16px 12px; font-size:14px;"><strong>Where:</strong> <?= $event['location'] ? $t($event['location']) : ($online ? 'Online' : 'To be announced') ?></td></tr>
      <?php if (!empty($event['speaker_name'])): ?><tr><td style="padding:0 16px 12px; font-size:14px;"><strong>Speaker:</strong> <?= $t($event['speaker_name']) ?></td></tr><?php endif; ?>
    </table>
    <div style="margin: 24px 0; text-align:center;">
      <?php if ($online): ?>
      <a href="<?= htmlspecialchars($event['online_url']) ?>" style="<?= $btn ?>">Join link</a>
      <p style="font-size:13px; color:#666; margin-top:10px;">Keep this email — the join link is also on <a href="<?= htmlspecialchars($manageUrl) ?>" style="color: <?= BRAND_RED ?>;">your registration page</a>.</p>
      <?php else: ?>
      <a href="<?= htmlspecialchars($manageUrl) ?>" style="<?= $btn ?>">Your registration &amp; check-in pass</a>
      <p style="font-size:13px; color:#666; margin-top:10px;">At the venue, open this page and show the QR code. Your certificate is issued as soon as you're checked in.</p>
      <?php endif; ?>
    </div>
    <p style="font-size:13px; color:#666;">
      <?php if (!empty($event['event_date'])): ?><a href="<?= htmlspecialchars($icsUrl) ?>" style="color: <?= BRAND_RED ?>;">Add to calendar</a> · <?php endif; ?>
      <a href="<?= htmlspecialchars($manageUrl) ?>" style="color: <?= BRAND_RED ?>;">Can't make it? Cancel your place</a>
    </p>
    <?php if (empty($guest['member_id'])): ?>
    <p style="font-size:13px; color:#666; border-top:1px solid #eee; padding-top:14px; margin-top:18px;">Not a RARL member yet? Membership is free: certificates in one place, member events and a verified ID card. <a href="<?= htmlspecialchars(rtrim(SITE_URL, '/') . '/register.php') ?>" style="color: <?= BRAND_RED ?>;">Join RARL</a></p>
    <?php endif; ?>
  </div>
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">You're receiving this because this address was used to register for a RARL event.<br>&copy; <?= date('Y') ?> Robotics &amp; Automation Research Lab (RARL)</div>
</div>
