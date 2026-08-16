<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
    <h1 style="color: <?= BRAND_INK ?>; margin: 10px 0 0 0; font-size: 24px;">Robotics & Automation Research Lab (RARL)</h1>
  </div>
  
  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee; text-align: center;">
    <div style="font-size: 40px; margin-bottom: 15px;">🏆</div>
    <h2 style="margin-top: 0;">Your Certificate is Ready!</h2>
    
    <p style="text-align: left;">Hi <?= htmlspecialchars($memberName) ?>,</p>
    
    <p style="text-align: left;">Thank you for participating in <strong><?= htmlspecialchars($eventTitle) ?></strong> on <?= $eventDate ?>. We have issued your official Certificate of Participation.</p>
    
    <div style="background: #f8f9fa; border: 1px dashed #ccc; padding: 15px; margin: 25px 0; border-radius: 8px; text-align: left;">
      <p style="margin: 0 0 5px 0;"><strong>Certificate ID:</strong> <span style="font-family: monospace;"><?= htmlspecialchars($certNumber) ?></span></p>
      <p style="margin: 0;"><strong>Event:</strong> <?= htmlspecialchars($eventTitle) ?></p>
    </div>
    
    <div style="margin: 30px 0;">
      <a href="<?= htmlspecialchars(SITE_URL . '/login.php') ?>" style="background: <?= BRAND_RED ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Download PDF from Dashboard</a>
    </div>
    
    <p style="text-align: left; font-size: 13px; color: #666;">You can also share your verifiable certificate link directly:<br>
    <a href="<?= htmlspecialchars($verifyUrl) ?>"><?= htmlspecialchars($verifyUrl) ?></a></p>
    
  </div>
  
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> Robotics & Automation Research Lab (RARL). All rights reserved.
  </div>
</div>
