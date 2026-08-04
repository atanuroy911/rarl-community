-- ============================================================
-- RARL — Uploadable, visually-editable templates for ID cards and
-- certificates (background image + positioned text fields), used by the
-- new HTML/FPDF template renderer instead of the old hardcoded layouts.
-- Idempotent, safe to re-run.
-- ============================================================

ALTER TABLE `certificate_templates`
  ADD COLUMN IF NOT EXISTS `type` ENUM('certificate','id_card') NOT NULL DEFAULT 'certificate' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `page_width_mm`  DECIMAL(6,2) NOT NULL DEFAULT 297 AFTER `config`,
  ADD COLUMN IF NOT EXISTS `page_height_mm` DECIMAL(6,2) NOT NULL DEFAULT 210 AFTER `page_width_mm`;
