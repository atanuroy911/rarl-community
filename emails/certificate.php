<?php
// Expects: $memberName, $eventTitle, $certNumber, $verifyUrl.
// Optional: $eventType (events.type), $eventDate (display string), $pdfAttached (bool),
//           $custom (['headline','message','closing'] edited in the send dialog; placeholders allowed).
$t = fn($v) => htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
$pdfAttached = $pdfAttached ?? false;
$vars = certEmailVars($memberName, $eventTitle, $eventDate ?? '', $certNumber);
$copy = array_merge(certEmailCopy($eventType ?? 'other'), array_filter($custom ?? [], fn($v) => trim((string)$v) !== ''));
$eyebrow  = $t($copy['eyebrow']);
$headline = certEmailFill($copy['headline'], $vars, false);
$message  = certEmailFill($copy['message'], $vars);
$closing  = certEmailFill($copy['closing'], $vars);

// LinkedIn "Add to profile" (promotes RARL as the issuing organisation).
$linkedin = 'https://www.linkedin.com/profile/add?' . http_build_query([
    'startTask' => 'CERTIFICATION_NAME',
    'name' => html_entity_decode((string)$eventTitle, ENT_QUOTES, 'UTF-8'),
    'organizationName' => 'Robotics & Automation Research Lab (RARL)',
    'issueYear' => !empty($eventDate) ? date('Y', strtotime($eventDate)) : date('Y'),
    'issueMonth' => !empty($eventDate) ? date('n', strtotime($eventDate)) : date('n'),
    'certUrl' => $verifyUrl,
    'certId' => $certNumber,
]);
$btn = 'display:inline-block; padding:12px 22px; border-radius:8px; font-weight:bold; text-decoration:none; font-size:14px;';
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
  </div>
  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p style="margin:0 0 4px; font-size:12px; font-weight:bold; letter-spacing:.08em; text-transform:uppercase; color: <?= BRAND_RED ?>;"><?= $eyebrow ?></p>
    <h2 style="margin:0 0 18px; font-size:22px; color:#111;"><?= $headline ?></h2>
    <p>Dear <?= $t($vars['{first_name}']) ?>,</p>
    <p><?= $message ?></p>
    <?php if ($pdfAttached): ?><p>📎 Your certificate is <strong>attached to this email</strong> as a PDF.</p><?php endif; ?>

    <table cellpadding="0" cellspacing="0" style="width:100%; margin:22px 0; background:#f8f9fa; border-radius:8px;">
      <tr><td style="padding:12px 16px; font-size:14px; border-bottom:1px solid #eee;"><strong>Event:</strong> <?= $t($eventTitle) ?></td></tr>
      <tr><td style="padding:12px 16px; font-size:14px;"><strong>Certificate no.:</strong> <span style="font-family: monospace;"><?= $t($certNumber) ?></span></td></tr>
    </table>

    <p style="text-align:center; margin:26px 0 10px;">
      <a href="<?= $t($linkedin) ?>" style="<?= $btn ?> background:#0a66c2; color:#fff;">Add to LinkedIn profile</a>
      &nbsp;
      <a href="<?= $t($verifyUrl) ?>" style="<?= $btn ?> background:#fff; color:#111; border:1px solid #ccc;">View &amp; verify</a>
    </p>
    <p style="font-size:13px; color:#666; text-align:center; margin:0 0 22px;">Anyone can confirm your certificate is genuine at the verification link.</p>

    <p><?= $closing ?></p>
    <p style="margin-bottom:0;">With thanks,<br><strong>The RARL Team</strong><br><span style="color:#666; font-size:13px;">Robotics &amp; Automation Research Lab (RARL), UK</span></p>
  </div>
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    <a href="<?= $t(SITE_URL) ?>" style="color:#999;">Visit the RARL portal</a> · &copy; <?= date('Y') ?> Robotics &amp; Automation Research Lab (RARL)
  </div>
</div>
