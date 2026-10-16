<?php
// Expects: $memberName, $inviteUrl. Optional: $eventTitle.
$t = fn($v) => htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
$first = explode(' ', preg_replace('/^(dr|prof|mr|mrs|ms|engr)\.?\s+/i', '', trim(html_entity_decode((string)$memberName, ENT_QUOTES, 'UTF-8'))))[0] ?: $memberName;
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
  </div>
  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p style="margin:0 0 4px; font-size:12px; font-weight:bold; letter-spacing:.08em; text-transform:uppercase; color: <?= BRAND_RED ?>;">Personal invitation</p>
    <h2 style="margin:0 0 18px; font-size:22px; color:#111;">Join the RARL community</h2>
    <p>Dear <?= $t($first) ?>,</p>
    <p><?= !empty($eventTitle) ? 'Thank you again for taking part in <strong>' . $t($eventTitle) . '</strong>. ' : '' ?>We'd love to welcome you as a member of the Robotics &amp; Automation Research Lab (RARL), UK.</p>
    <p>Membership is free and gives you:</p>
    <ul style="padding-left:20px; margin:0 0 18px;">
      <li>Early access to RARL webinars, workshops and events</li>
      <li>Your certificates and member ID card in one place</li>
      <li>Use of the RARL affiliation on your research papers</li>
      <li>Recordings, learning resources and research news</li>
    </ul>
    <p style="text-align:center; margin:28px 0;">
      <a href="<?= $t($inviteUrl) ?>" style="display:inline-block; padding:13px 26px; border-radius:8px; font-weight:bold; text-decoration:none; font-size:15px; background: <?= BRAND_RED ?>; color:#fff;">Accept invitation</a>
    </p>
    <p style="font-size:13px; color:#666;">This link is personal to you and works for <?= INVITE_DAYS ?> days, even when public registration is closed.</p>
    <p style="margin-bottom:0;">Warm regards,<br><strong>The RARL Team</strong></p>
  </div>
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">&copy; <?= date('Y') ?> Robotics &amp; Automation Research Lab (RARL)</div>
</div>
