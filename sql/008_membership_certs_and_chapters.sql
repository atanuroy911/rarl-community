-- ============================================================
-- RARL — Every member gets both an ID card and a "Certificate of
-- Membership" automatically. Reuses the existing `certificates` table
-- (event_id becomes nullable, cert_type distinguishes event vs membership)
-- so verify.php / admin/certificates.php work for both without duplicating
-- infrastructure. Also adds chapter-scoped announcements for chairs.
-- Idempotent, safe to re-run.
-- ============================================================

ALTER TABLE `certificates`
  MODIFY COLUMN `event_id` INT(11) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cert_type` ENUM('event','membership') NOT NULL DEFAULT 'event' AFTER `event_id`;

ALTER TABLE `certificate_templates`
  MODIFY COLUMN `type` ENUM('certificate','id_card','membership') NOT NULL DEFAULT 'certificate';

ALTER TABLE `announcements`
  ADD COLUMN IF NOT EXISTS `section_id` INT(11) DEFAULT NULL AFTER `type`;
