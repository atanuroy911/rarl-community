<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
    <h1 style="color: <?= BRAND_INK ?>; margin: 10px 0 0 0; font-size: 24px;">RARL Community</h1>
  </div>

  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p>Hi <?= htmlspecialchars($memberName) ?>,</p>
    <p>Your <?= htmlspecialchars($docLabel) ?> is attached to this email.</p>
    <div style="text-align: center; margin: 20px 0;">
      <a href="<?= SITE_URL ?>/dashboard.php" style="background: <?= BRAND_INK ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">View My Dashboard</a>
    </div>
  </div>

  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> RARL Community. All rights reserved.
  </div>
</div>
