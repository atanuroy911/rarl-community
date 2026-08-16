<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
    <h1 style="color: <?= BRAND_INK ?>; margin: 10px 0 0 0; font-size: 24px;">Robotics & Automation Research Lab (RARL)</h1>
  </div>

  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p>Hi <?= htmlspecialchars($memberName) ?>,</p>
    <p>An administrator has reset your Robotics & Automation Research Lab (RARL) password. Use the temporary password below to sign in — you'll be asked to set a new one immediately.</p>

    <div style="text-align: center; margin: 30px 0;">
      <span style="display:inline-block; background:#f4f4f4; border-radius:10px; padding:16px 28px; font-size:22px; font-weight:bold; letter-spacing:2px; color:<?= BRAND_INK ?>; font-family: ui-monospace, monospace;"><?= htmlspecialchars($tempPassword) ?></span>
    </div>

    <div style="text-align: center; margin: 20px 0;">
      <a href="<?= SITE_URL ?>/login.php" style="background: <?= BRAND_INK ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Sign In Now</a>
    </div>

    <p style="font-size: 13px; color: #666;">If you didn't request this, contact us immediately.</p>
  </div>

  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> Robotics & Automation Research Lab (RARL). All rights reserved.
  </div>
</div>
