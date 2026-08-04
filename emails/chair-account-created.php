<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
    <h1 style="color: <?= BRAND_INK ?>; margin: 10px 0 0 0; font-size: 24px;">RARL Community</h1>
  </div>

  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p>Hi <?= htmlspecialchars($memberName) ?>,</p>
    <p>As a Chapter Chair, you've been given a RARL Community member account so you can sign in, manage your profile, and engage with your chapter.</p>

    <p style="margin: 20px 0 6px;">Your login email: <strong><?= htmlspecialchars($chairEmail) ?></strong></p>
    <div style="text-align: center; margin: 12px 0 30px;">
      <span style="display:inline-block; background:#f4f4f4; border-radius:10px; padding:16px 28px; font-size:22px; font-weight:bold; letter-spacing:2px; color:<?= BRAND_INK ?>; font-family: ui-monospace, monospace;"><?= htmlspecialchars($tempPassword) ?></span>
    </div>

    <div style="text-align: center; margin: 20px 0;">
      <a href="<?= SITE_URL ?>/login.php" style="background: <?= BRAND_INK ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Sign In Now</a>
    </div>

    <p style="font-size: 13px; color: #666;">You'll be asked to set your own password the first time you sign in.</p>
  </div>

  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> RARL Community. All rights reserved.
  </div>
</div>
