<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
    <h1 style="color: <?= BRAND_INK ?>; margin: 10px 0 0 0; font-size: 24px;">RARL Community</h1>
  </div>

  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p>Hi <?= htmlspecialchars($memberName) ?>,</p>
    <p>We received a request to reset your RARL Community account password. Enter the code below to choose a new password. This code expires in 10 minutes.</p>

    <div style="text-align: center; margin: 30px 0;">
      <span style="display:inline-block; background:#f4f4f4; border-radius:10px; padding:16px 28px; font-size:32px; font-weight:bold; letter-spacing:8px; color:<?= BRAND_INK ?>;"><?= htmlspecialchars($code) ?></span>
    </div>

    <p style="font-size: 13px; color: #666;">If you didn't request this, you can safely ignore this email — your password will remain unchanged.</p>
  </div>

  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> RARL Community. All rights reserved.
  </div>
</div>
