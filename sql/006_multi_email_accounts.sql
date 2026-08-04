-- ============================================================
-- RARL — Multiple emails per account (login with any linked email,
-- e.g. an ORCID-affiliated address) + temp-password / bulk-import support.
-- Idempotent, safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `member_emails` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `member_id`   INT(11)      NOT NULL,
  `email`       VARCHAR(255) NOT NULL,
  `label`       VARCHAR(100) DEFAULT NULL COMMENT 'e.g. "Work", "ORCID-linked", "University"',
  `is_primary`  TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at` TIMESTAMP    NULL DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every existing member's primary email becomes their first linked+verified row.
INSERT INTO `member_emails` (`member_id`, `email`, `is_primary`, `verified_at`)
SELECT `id`, `email`, 1, IFNULL(`email_verified_at`, `created_at`) FROM `members`
ON DUPLICATE KEY UPDATE `member_id` = `member_id`;

ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`,
  ADD COLUMN IF NOT EXISTS `is_chair` TINYINT(1) NOT NULL DEFAULT 0 AFTER `type`;
