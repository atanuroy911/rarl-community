<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($subject_send) ?></title>
<style>
  body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f9fafb; color: #111827; }
  .wrapper { width: 100%; max-width: 600px; margin: 0 auto; background: #ffffff; }
  .header { background-color: <?= BRAND_INK ?>; padding: 24px; text-align: center; }
  .logo { display: inline-block; }
  .logo img { border-radius: 8px; margin-bottom: 8px; }
  .title { color: white; font-size: 20px; font-weight: 800; margin: 0; font-family: <?= BRAND_FONT_SANS ?>, sans-serif; }
  .content { padding: 32px 24px; font-size: 15px; line-height: 1.6; color: #374151; }
  .content h1, .content h2, .content h3 { color: #111827; margin-top: 0; font-family: <?= BRAND_FONT_SANS ?>, sans-serif; }
  .content a { color: <?= BRAND_RED ?>; text-decoration: underline; }
  .footer { background-color: #f3f4f6; padding: 24px; text-align: center; font-size: 12px; color: #6b7280; border-top: 1px solid #e5e7eb; }
  .footer a { color: #4b5563; text-decoration: underline; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <div class="logo"><img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="36" height="36" alt="RARL"/></div>
      <h1 class="title">Robotics & Automation Research Lab (RARL)</h1>
    </div>
    
    <div class="content">
      <p>Hi <?= htmlspecialchars($recipientName) ?>,</p>
      
      <?= $bodyHtml ?>
      
    </div>
    
    <div class="footer">
      <p>You received this email because you are subscribed to the Robotics & Automation Research Lab (RARL) newsletter.</p>
      <p>
        <a href="<?= htmlspecialchars(SITE_URL) ?>">Visit Community Portal</a> | 
        <a href="<?= htmlspecialchars($unsubscribeUrl) ?>">Unsubscribe</a>
      </p>
      <p>&copy; <?= date('Y') ?> Robotics & Automation Research Lab.</p>
    </div>
  </div>
</body>
</html>
