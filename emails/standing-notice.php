<?php
// Expects: $memberName, $level ('warn_1' | 'warn_2' | 'lapse'), $s (scored member: events, shares, papers, signed_in, points), $r (standingRules()), $deadline (?string).
$t = fn($v) => htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
$first = explode(' ', preg_replace('/^(dr|prof|mr|mrs|ms|engr)\.?\s+/i', '', trim(html_entity_decode((string)$memberName, ENT_QUOTES, 'UTF-8'))))[0] ?: $memberName;
$months = max(1, (int)round($r['window'] / 30));
$base = rtrim(SITE_URL, '/');
$btn = 'display:inline-block; padding:12px 22px; border-radius:8px; font-weight:bold; text-decoration:none; font-size:14px; background:' . BRAND_RED . '; color:#fff;';
$row = fn($label, $value, $pts) => '<tr><td style="padding:8px 14px; font-size:14px; border-bottom:1px solid #eee;">' . $label . '</td><td style="padding:8px 14px; font-size:14px; border-bottom:1px solid #eee; text-align:right;"><strong>' . $value . '</strong> <span style="color:#888;">(' . $pts . ' pt' . ($pts == 1 ? '' : 's') . ' each)</span></td></tr>';
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= emailLogoUrl() ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
  </div>
  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <?php if ($level === 'lapse'): ?>
    <p style="margin:0 0 4px; font-size:12px; font-weight:bold; letter-spacing:.08em; text-transform:uppercase; color:#b45309;">Membership paused</p>
    <h2 style="margin:0 0 18px; font-size:22px; color:#111;">We've paused your RARL membership</h2>
    <p>Dear <?= $t($first) ?>,</p>
    <p>We didn't see any activity from you in the last <?= $months ?> months, so your RARL membership is now paused. While it is paused, your member ID card and membership certificate no longer verify, and members-only events and resources are closed.</p>
    <p>We'd love to have you back. Reply to this email and we'll reactivate your membership.</p>
    <?php else: ?>
    <p style="margin:0 0 4px; font-size:12px; font-weight:bold; letter-spacing:.08em; text-transform:uppercase; color:<?= $level === 'warn_2' ? '#b91c1c' : BRAND_RED ?>;"><?= $level === 'warn_2' ? 'Final reminder' : 'Membership reminder' ?></p>
    <h2 style="margin:0 0 18px; font-size:22px; color:#111;"><?= $level === 'warn_2' ? 'Your membership may be paused after ' . $t($deadline) : 'Stay an active RARL member' ?></h2>
    <p>Dear <?= $t($first) ?>,</p>
    <p>RARL membership is for people who take part. To keep it active, members need at least <strong><?= (int)$r['target'] ?> points</strong> over <?= $months ?> months. You have <strong><?= (int)$s['points'] ?></strong> right now.</p>
    <?php if ($level === 'warn_2'): ?><p>This is your second and final reminder. If you haven't reached the target by <strong><?= $t($deadline) ?></strong>, your membership, ID card and membership certificate may be paused.</p>
    <?php else: ?><p>There's plenty of time: just reach the target by <strong><?= $t($deadline) ?></strong>.</p><?php endif; ?>
    <table cellpadding="0" cellspacing="0" style="width:100%; margin:20px 0; background:#f8f9fa; border-radius:8px;">
      <?= $row('Events you attended', (int)$s['events'], $r['event']) ?>
      <?= $row('RARL events you shared on LinkedIn', (int)$s['shares'], $r['share']) ?>
      <?= $row('Papers with the RARL affiliation', (int)$s['papers'], $r['paper']) ?>
      <tr><td style="padding:8px 14px; font-size:14px;">Signed in to the portal</td><td style="padding:8px 14px; font-size:14px; text-align:right;"><strong><?= $s['signed_in'] ? 'Yes' : 'No' ?></strong> <span style="color:#888;">(<?= (int)$r['login'] ?> pt)</span></td></tr>
    </table>
    <p style="margin-bottom:6px;"><strong>Easy ways to earn points:</strong></p>
    <ul style="padding-left:20px; margin:0 0 20px;">
      <li>Join an upcoming webinar or event</li>
      <li>Share an RARL event on LinkedIn using the LinkedIn button on the event</li>
      <li>Submit a paper you published with the RARL affiliation</li>
    </ul>
    <p style="text-align:center; margin:24px 0;"><a href="<?= $t($base . '/events.php') ?>" style="<?= $btn ?>">See upcoming events</a></p>
    <p style="font-size:13px; color:#666;">You can check your points any time on your <a href="<?= $t($base . '/membership.php#standing') ?>">Membership page</a>.</p>
    <?php endif; ?>
    <p style="margin-bottom:0;">Warm regards,<br><strong>The RARL Team</strong></p>
  </div>
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">&copy; <?= date('Y') ?> Robotics &amp; Automation Research Lab (RARL)</div>
</div>
