-- ============================================================
-- RARL Community Platform — Public events/RSVP, premium learning
-- content, and member directory. Run after 003 (same idempotent,
-- safe-to-re-run style as every other migration here).
-- ============================================================

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `visibility`    ENUM('public','members_only') NOT NULL DEFAULT 'public' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `event_time`    TIME         DEFAULT NULL AFTER `event_date`,
  ADD COLUMN IF NOT EXISTS `online_url`    VARCHAR(500) DEFAULT NULL AFTER `location`,
  ADD COLUMN IF NOT EXISTS `speaker_name`  VARCHAR(255) DEFAULT NULL AFTER `online_url`,
  ADD COLUMN IF NOT EXISTS `capacity`      INT(11)      DEFAULT NULL AFTER `speaker_name`,
  ADD COLUMN IF NOT EXISTS `recording_url` VARCHAR(500) DEFAULT NULL AFTER `capacity`,
  ADD COLUMN IF NOT EXISTS `cover_image`   VARCHAR(500) DEFAULT NULL AFTER `recording_url`,
  ADD COLUMN IF NOT EXISTS `reminder_sent` TINYINT(1)   NOT NULL DEFAULT 0 AFTER `is_active`;

CREATE TABLE IF NOT EXISTS `event_registrations` (
  `id`            INT(11)   NOT NULL AUTO_INCREMENT,
  `event_id`      INT(11)   NOT NULL,
  `member_id`     INT(11)   NOT NULL,
  `status`        ENUM('registered','attended','no_show','cancelled') NOT NULL DEFAULT 'registered',
  `registered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_member` (`event_id`,`member_id`),
  KEY `event_id` (`event_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `resource_categories`
  ADD COLUMN IF NOT EXISTS `access_level` ENUM('public','member') NOT NULL DEFAULT 'public' AFTER `is_published`;

ALTER TABLE `resources`
  ADD COLUMN IF NOT EXISTS `access_level` ENUM('public','member') NOT NULL DEFAULT 'public' AFTER `is_featured`;

ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `directory_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `open_to_mentor`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `directory_visible`,
  ADD COLUMN IF NOT EXISTS `seeking_mentor`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `open_to_mentor`;
