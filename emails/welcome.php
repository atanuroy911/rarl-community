<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.6;">
  <div style="text-align: center; padding: 20px 0;">
    <img src="<?= MAIN_SITE_URL . BRAND_MARK_PATH ?>" width="40" height="40" style="border-radius:8px;" alt="RARL"/>
    <h1 style="color: <?= BRAND_INK ?>; margin: 10px 0 0 0; font-size: 24px;">Robotics & Automation Research Lab (RARL)</h1>
  </div>
  
  <div style="background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #eee;">
    <p>Hi <?= htmlspecialchars($memberName) ?>,</p>
    
    <?php if ($isApproval): ?>
    <p>Thank you for registering with the <strong>Robotics & Automation Research Lab (RARL)</strong> community platform.</p>
    <p>Your application has been received and is currently under review by our team. We review all applications to ensure the quality and security of our research ecosystem.</p>
    <p>You will receive another email within 1–2 business days once your account is approved. Upon approval, you will gain access to:</p>
    <ul>
      <li>The RARL community feed</li>
      <li>Curated research resources and tutorials</li>
      <li>Verifiable digital certificates for event participation</li>
    </ul>

    <?php else: ?>
    <p>Welcome to the <strong>Robotics & Automation Research Lab (RARL)</strong> community platform!</p>
    <p>We are thrilled to have you join our global network of researchers, labs, and students.</p>

    <div style="text-align: center; margin: 30px 0;">
      <a href="<?= SITE_URL ?>/community.php" style="background: <?= BRAND_INK ?>; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Visit the Community Feed</a>
    </div>

    <p><strong>What's next?</strong></p>
    <ol>
      <li><strong>Join the Conversation:</strong> Click the button above to post updates, share papers, and find collaborators.</li>
      <li><strong>Access Resources:</strong> Log in to the platform to explore our curated learning hub.</li>
      <li><strong>Earn Certificates:</strong> Attend our upcoming workshops and webinars to automatically receive verifiable PDF certificates.</li>
    </ol>
    <?php endif; ?>
    
    <p style="margin-top: 30px;">Best regards,<br>The RARL Team</p>
  </div>
  
  <div style="text-align: center; font-size: 12px; color: #999; margin-top: 20px;">
    &copy; <?= date('Y') ?> Robotics & Automation Research Lab (RARL). All rights reserved.<br>
    <a href="<?= SITE_URL ?>/login.php" style="color: #999;">Sign In to Dashboard</a>
  </div>
</div>
