-- ============================================================
-- RARL — Membership certificates: manual by default, kept in
-- step with the default design.
-- design_sig: fingerprint of the design a certificate was made
-- with, so outdated ones can be found and regenerated.
-- Idempotent, safe to re-run.
-- ============================================================

ALTER TABLE `certificates`
  ADD COLUMN IF NOT EXISTS `design_sig` VARCHAR(40) DEFAULT NULL AFTER `template_id`;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('membership_cert_auto', '0'),
  ('membership_cert_autoregen', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;
