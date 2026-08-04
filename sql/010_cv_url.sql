-- ============================================================
-- RARL — Optional external CV/Resume link (e.g. a Google Drive share link
-- from the Google Form import), separate from cv_path which is a locally
-- uploaded file. Either, both, or neither may be set for a member.
-- Idempotent, safe to re-run.
-- ============================================================

ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `cv_url` VARCHAR(500) DEFAULT NULL AFTER `cv_path`;
