-- ============================================================
-- RARL Community Platform — Rich-text posts + single photo upload
-- Run after schema.sql and 002_v2_features.sql (same idempotent style).
-- ============================================================

ALTER TABLE `community_posts`
  ADD COLUMN IF NOT EXISTS `body_format` ENUM('markdown','html') NOT NULL DEFAULT 'markdown' AFTER `body`,
  ADD COLUMN IF NOT EXISTS `image_path`  VARCHAR(500) DEFAULT NULL AFTER `body_format`;
