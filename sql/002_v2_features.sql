-- ============================================================
-- RARL Community Platform — v2 migration
-- Free plans, OTP auth, community feed, regional sections,
-- people directory, member ID cards, expanded registration.
-- Safe to re-run (idempotent CREATE/ALTER/UPSERT).
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- ── membership_plans ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `membership_plans` (
  `id`             INT(11)       NOT NULL AUTO_INCREMENT,
  `slug`           VARCHAR(50)   NOT NULL,
  `name`           VARCHAR(100)  NOT NULL,
  `tagline`        VARCHAR(255)  DEFAULT NULL,
  `description`    TEXT          DEFAULT NULL,
  `features`       TEXT          DEFAULT NULL COMMENT 'one bullet per line',
  `audience`       ENUM('individual','lab','both') NOT NULL DEFAULT 'both',
  `regular_price`  DECIMAL(10,2) DEFAULT NULL COMMENT 'future price once free period ends',
  `currency`       VARCHAR(3)    NOT NULL DEFAULT 'EUR',
  `billing_period` VARCHAR(20)   NOT NULL DEFAULT 'year',
  `is_free_now`    TINYINT(1)    NOT NULL DEFAULT 1,
  `free_note`      VARCHAR(160)  NOT NULL DEFAULT 'Free for a limited time',
  `display_order`  INT(11)       NOT NULL DEFAULT 0,
  `is_published`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `membership_plans` (`slug`,`name`,`tagline`,`description`,`audience`,`regular_price`,`display_order`) VALUES
('free',         'Free',         'Open to every researcher',      'Full community access at no cost — individuals and labs alike.', 'both',       NULL,    1),
('student',      'Student',      'For students and trainees',     'For PhD/MSc/BSc students and early-career researchers.',          'individual', 0.00,    2),
('organization', 'Organization', 'For labs & institutions',       'For research labs and institutions joining as an organization.',  'lab',        2500.00, 3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `tagline` = VALUES(`tagline`), `description` = VALUES(`description`), `audience` = VALUES(`audience`), `display_order` = VALUES(`display_order`);

-- ── members: new columns ────────────────────────────────────
ALTER TABLE `members`
  ADD COLUMN IF NOT EXISTS `plan_id`            INT(11)      DEFAULT NULL AFTER `type`,
  ADD COLUMN IF NOT EXISTS `section_id`         INT(11)      DEFAULT NULL AFTER `country`,
  ADD COLUMN IF NOT EXISTS `community_notify`   TINYINT(1)   NOT NULL DEFAULT 1 AFTER `newsletter_opt_in`,
  ADD COLUMN IF NOT EXISTS `city_state`         VARCHAR(150) DEFAULT NULL AFTER `country`,
  ADD COLUMN IF NOT EXISTS `primary_lab_name`   VARCHAR(150) DEFAULT NULL AFTER `research_interests`,
  ADD COLUMN IF NOT EXISTS `years_experience`   ENUM('<1','1-3','3-5','5-10','10+') DEFAULT NULL AFTER `primary_lab_name`,
  ADD COLUMN IF NOT EXISTS `referral_source`    ENUM('conference','referral','social_media','newsletter','other') DEFAULT NULL AFTER `years_experience`,
  ADD COLUMN IF NOT EXISTS `cv_path`            VARCHAR(255) DEFAULT NULL AFTER `avatar_path`,
  ADD COLUMN IF NOT EXISTS `member_code`        VARCHAR(20)  DEFAULT NULL AFTER `uuid`,
  ADD COLUMN IF NOT EXISTS `id_card_path`       VARCHAR(255) DEFAULT NULL AFTER `cv_path`,
  ADD COLUMN IF NOT EXISTS `id_card_issued_at`  DATE         DEFAULT NULL AFTER `id_card_path`,
  ADD COLUMN IF NOT EXISTS `id_card_expires_at` DATE         DEFAULT NULL AFTER `id_card_issued_at`;

ALTER TABLE `members` ADD UNIQUE KEY IF NOT EXISTS `member_code` (`member_code`);

UPDATE `members` SET `plan_id` = (SELECT id FROM `membership_plans` WHERE slug='free') WHERE `plan_id` IS NULL;

-- ── retire old paid "Membership" tier (superseded by membership_plans) ──
UPDATE `partnership_tiers` SET `is_published` = 0 WHERE `category` = 'membership';

-- ── otp_codes ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(255) NOT NULL,
  `code_hash`    VARCHAR(255) NOT NULL,
  `purpose`      ENUM('verify_email','password_reset') NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `attempts`     TINYINT(4)   NOT NULL DEFAULT 0,
  `consumed_at`  DATETIME     DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_purpose` (`email`,`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── community_posts / comments / likes ──────────────────────
CREATE TABLE IF NOT EXISTS `community_posts` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `member_id`   INT(11)   NOT NULL,
  `body`        TEXT      NOT NULL,
  `is_pinned`   TINYINT(1) NOT NULL DEFAULT 0,
  `is_hidden`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `community_comments` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `post_id`     INT(11)   NOT NULL,
  `member_id`   INT(11)   NOT NULL,
  `body`        TEXT      NOT NULL,
  `is_hidden`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `community_likes` (
  `post_id`     INT(11)   NOT NULL,
  `member_id`   INT(11)   NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`post_id`,`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── regional_sections ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `regional_sections` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `scope`         ENUM('continent','country') NOT NULL DEFAULT 'continent',
  `continent`     VARCHAR(50)  NOT NULL,
  `country`       VARCHAR(100) DEFAULT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `chair_name`    VARCHAR(150) DEFAULT NULL,
  `chair_email`   VARCHAR(255) DEFAULT NULL,
  `chair_title`   VARCHAR(100) NOT NULL DEFAULT 'Chapter Chair',
  `display_order` INT(11)      NOT NULL DEFAULT 0,
  `is_published`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chapter leadership rows are no longer seeded here — the table starts empty and
-- editable via admin/sections.php (see functions.php: seedRegionalSectionsIfEmpty()),
-- so a fresh install never carries fixed people's data that a schema reseed could
-- later overwrite.

-- (no formal FK constraint, matching the rest of this schema's app-level-only relationships)

-- ── people (leadership directory) ───────────────────────────
CREATE TABLE IF NOT EXISTS `people` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(150) NOT NULL,
  `designation`       VARCHAR(150) DEFAULT NULL,
  `email`             VARCHAR(255) DEFAULT NULL,
  `linkedin_url`      VARCHAR(500) DEFAULT NULL,
  `google_scholar_url` VARCHAR(500) DEFAULT NULL,
  `photo_path`        VARCHAR(255) DEFAULT NULL,
  `display_order`     INT(11)      NOT NULL DEFAULT 0,
  `is_published`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── extra settings keys (site content / hero stats / popup / ID card signers) ──
INSERT INTO `settings` (`key`,`value`) VALUES
('home_hero_badge',        'Now Open for Membership'),
('home_hero_title',        'Join the Global RARL Research Community'),
('home_hero_subtitle',     'Connect with researchers worldwide. Access curated learning resources, receive newsletters, attend events, and earn verifiable digital certificates for your contributions.'),
('community_feed_intro',   'Share updates, ask questions, and connect with fellow RARL researchers.'),
('stat_members_override',  ''),
('stat_labs_override',     ''),
('stat_certs_override',    ''),
('stat_countries',         '5'),
('popup_enabled',          '0'),
('popup_image_path',       ''),
('popup_link_url',         ''),
('popup_alt_text',         ''),
('idcard_signer1_name',    'Ata Jahangir Moshayedi — RARL President'),
('idcard_signer2_name',    'S. Ali Eftekhari — Asia Section Chair'),
('id_card_counter',        '0')
ON DUPLICATE KEY UPDATE `value` = `value`;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
