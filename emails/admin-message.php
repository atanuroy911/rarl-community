<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="background: <?= BRAND_INK ?>; padding: 24px; border-radius: 12px 12px 0 0; text-align: center;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="36" height="36" style="border-radius:8px;" alt="RARL"/>
    <div style="color: #fff; font-weight: bold; font-size: 13px; letter-spacing: 1px; margin-top: 8px; text-transform: uppercase; opacity: .8;">Robotics & Automation Research Lab (RARL) — Admin Message</div>
  </div>

  <div style="background: #ffffff; padding: 30px; border: 1px solid #eee; border-top: none; border-radius: 0 0 12px 12px;">
    <p>Hi <?= htmlspecialchars($memberName ?? 'there') ?>,</p>
    <div class="md-content"><?= $bodyHtml ?></div>
    <p style="margin-top: 30px; font-size: 13px; color: #777;">— The RARL Team</p>
  </div>

  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> Robotics & Automation Research Lab (RARL). This message was sent to you directly by a RARL administrator.
  </div>
</div>
