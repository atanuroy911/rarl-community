-- ============================================================
-- RARL — Announcement controls: an optional call-to-action link and an
-- expiry date, so admins can publish time-boxed notices ("Abstracts due
-- Friday → Submit") that disappear from member pages on their own.
-- Draft/publish (is_published) and chapter targeting (section_id) already
-- exist from earlier migrations; this only adds the new columns.
-- Idempotent, safe to re-run.
-- ============================================================

ALTER TABLE `announcements`
  ADD COLUMN IF NOT EXISTS `link_url`   VARCHAR(500) DEFAULT NULL AFTER `content`,
  ADD COLUMN IF NOT EXISTS `link_label` VARCHAR(80)  DEFAULT NULL AFTER `link_url`,
  ADD COLUMN IF NOT EXISTS `expires_at` DATE         DEFAULT NULL AFTER `is_published`;
