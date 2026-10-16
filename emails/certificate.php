<?php
// Expects: $memberName, $eventTitle, $certNumber, $verifyUrl.
// Optional: $eventType (events.type), $eventDate (display string), $pdfAttached (bool).
$t = fn($v) => htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));
$eventType   = $eventType ?? 'other';
$pdfAttached = $pdfAttached ?? false;
$first       = explode(' ', preg_replace('/^(dr|prof|mr|mrs|ms|engr)\.?\s+/i', '', trim(html_entity_decode((string)$memberName, ENT_QUOTES, 'UTF-8'))))[0] ?: $memberName;
$title       = '<strong>' . $t($eventTitle) . '</strong>';
$when        = !empty($eventDate) ? ' on ' . $t($eventDate) : '';

// Thank-you copy per event type: [eyebrow, headline, message, closing].
$copy = [
    'webinar'     => ['Webinar certificate', 'Thank you for joining us!',
        "Thank you for attending {$title}{$when}. We hope the talk gave you fresh ideas to take into your own research and work. Your Certificate of Participation is ready.",
        'Recordings of our webinars are posted on the RARL portal, so you can revisit the session anytime.'],
    'seminar'     => ['Seminar certificate', 'Thank you for being part of the conversation',
        "Thank you for attending {$title}{$when}. Your questions and engagement make these sessions worthwhile. Your Certificate of Participation is ready.",
        'Keep an eye on the RARL portal for upcoming talks.'],
    'workshop'    => ['Workshop certificate', 'Well done on completing the workshop!',
        "Thank you for taking part in {$title}{$when}. Hands-on learning takes real effort, and we're glad you put it in. Your certificate is ready.",
        'Workshop materials and related resources are available in the RARL Learning Hub.'],
    'conference'  => ['Conference certificate', 'Thank you for joining the conference',
        "Thank you for participating in {$title}{$when}. It was great to have you with the RARL community. Your Certificate of Participation is ready.",
        'Published papers and session recordings will be shared on the RARL portal.'],
    'hackathon'   => ['Hackathon certificate', 'Thank you for building with us!',
        "Thank you for taking on {$title}{$when}. Your creativity, teamwork and long hours made the event what it was. Your certificate is ready.",
        'We would love to see where your project goes next.'],
    'competition' => ['Competition certificate', 'Thank you for competing!',
        "Thank you for competing in {$title}{$when}. Taking part takes courage and skill, and you showed both. Your certificate is ready.",
        'Watch the RARL portal for the next challenge.'],
    'volunteer'   => ['Certificate of appreciation', 'Thank you for your time and help',
        "Thank you for volunteering with {$title}{$when}. Events like this only happen because of people like you. Please accept this certificate with our sincere appreciation.",
        'We hope to work with you again soon.'],
    'other'       => ['Your certificate', 'Thank you for taking part!',
        "Thank you for participating in {$title}{$when}. Your certificate is ready.",
        'Stay connected with RARL for upcoming events and opportunities.'],
][$eventType] ?? null;
$copy = $copy ?: [ 'Your certificate', 'Thank you for taking part!', "Thank you for participating in {$title}{$when}. Your certificate is ready.", 'Stay connected with RARL for upcoming events and opportunities.'];
[$eyebrow, $headline, $message, $closing] = $copy;

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
    <p>Dear <?= $t($first) ?>,</p>
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
