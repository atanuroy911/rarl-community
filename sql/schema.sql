-- ============================================================
-- RARL Community Platform — Database Schema
-- 10 tables covering all 5 modules
-- Run in cPanel → phpMyAdmin on your target database
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- ── 1. members ──────────────────────────────────────────────
-- Stores both individual researchers and research labs
CREATE TABLE IF NOT EXISTS `members` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `uuid`                CHAR(36)     NOT NULL                    COMMENT 'Public-facing ID',
  `type`                ENUM('individual','lab') NOT NULL DEFAULT 'individual',
  -- Shared fields
  `email`               VARCHAR(255) NOT NULL,
  `password_hash`       VARCHAR(255) NOT NULL,
  `country`             VARCHAR(100) DEFAULT NULL,
  `research_interests`  TEXT         DEFAULT NULL,
  `newsletter_opt_in`   TINYINT(1)   NOT NULL DEFAULT 1,
  `unsubscribe_token`   VARCHAR(64)  NOT NULL,
  `status`              ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `avatar_path`         VARCHAR(500) DEFAULT NULL,
  `discord_invited`     TINYINT(1)   NOT NULL DEFAULT 0,
  `email_verified_at`   TIMESTAMP    NULL DEFAULT NULL,
  `reset_token`         VARCHAR(64)  DEFAULT NULL                COMMENT 'Password reset token',
  `reset_expires`       DATETIME     DEFAULT NULL                COMMENT 'Reset token expiry',
  `last_login_at`       TIMESTAMP    NULL DEFAULT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Individual fields
  `full_name`           VARCHAR(255) DEFAULT NULL,
  `institution`         VARCHAR(255) DEFAULT NULL,
  `department`          VARCHAR(255) DEFAULT NULL,
  `position`            VARCHAR(100) DEFAULT NULL  COMMENT 'PhD Student, Researcher, Professor, etc.',
  `google_scholar_url`  VARCHAR(500) DEFAULT NULL,
  `orcid_id`            VARCHAR(50)  DEFAULT NULL,
  `linkedin_url`        VARCHAR(500) DEFAULT NULL,
  -- Lab fields
  `lab_name`            VARCHAR(255) DEFAULT NULL,
  `pi_name`             VARCHAR(255) DEFAULT NULL,
  `lab_website`         VARCHAR(500) DEFAULT NULL,
  `research_areas`      TEXT         DEFAULT NULL,
  -- Admin notes
  `notes`               TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `unsubscribe_token` (`unsubscribe_token`),
  KEY `reset_token` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. events ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `events` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(500) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `type`        ENUM('workshop','webinar','hackathon','competition','volunteer','conference','seminar','other') NOT NULL DEFAULT 'other',
  `event_date`  DATE         DEFAULT NULL,
  `location`    VARCHAR(255) DEFAULT NULL  COMMENT 'Online or physical location',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. certificate_templates ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `certificate_templates` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(255) NOT NULL,
  `background_path` VARCHAR(500) DEFAULT NULL  COMMENT 'Path to background image',
  `config`          JSON         DEFAULT NULL  COMMENT 'Layout: font sizes, positions, colors',
  `preview_path`    VARCHAR(500) DEFAULT NULL,
  `is_default`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. certificates ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `certificates` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36)     NOT NULL                    COMMENT 'Used in verification URL',
  `certificate_no`   VARCHAR(50)  NOT NULL                    COMMENT 'e.g. RARL-2025-0042',
  `member_id`        INT(11)      DEFAULT NULL                COMMENT 'NULL if member not registered',
  `recipient_name`   VARCHAR(255) NOT NULL                    COMMENT 'Name printed on cert',
  `recipient_email`  VARCHAR(255) NOT NULL,
  `event_id`         INT(11)      NOT NULL,
  `template_id`      INT(11)      DEFAULT NULL,
  `pdf_path`         VARCHAR(500) DEFAULT NULL,
  `issued_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailed_at`       TIMESTAMP    NULL DEFAULT NULL,
  `is_public`        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `certificate_no` (`certificate_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. newsletters ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `newsletters` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `subject`         VARCHAR(500) NOT NULL,
  `body_html`       LONGTEXT     NOT NULL,
  `preview_text`    VARCHAR(255) DEFAULT NULL  COMMENT 'Shown in email client preview',
  `segment`         ENUM('all','individual','lab') NOT NULL DEFAULT 'all',
  `status`          ENUM('draft','scheduled','sent') NOT NULL DEFAULT 'draft',
  `scheduled_at`    DATETIME     DEFAULT NULL,
  `sent_at`         DATETIME     DEFAULT NULL,
  `recipient_count` INT(11)      NOT NULL DEFAULT 0,
  `open_count`      INT(11)      NOT NULL DEFAULT 0,
  `created_by`      VARCHAR(100) DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. resource_categories ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `resource_categories` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(255) NOT NULL,
  `slug`          VARCHAR(255) NOT NULL,
  `icon_emoji`    VARCHAR(10)  DEFAULT '📚',
  `description`   TEXT         DEFAULT NULL,
  `display_order` INT(11)      NOT NULL DEFAULT 0,
  `is_published`  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. resources ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `resources` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `category_id`   INT(11)      NOT NULL,
  `title`         VARCHAR(500) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `type`          ENUM('youtube_playlist','youtube_video','article','book','tool','course','other') NOT NULL DEFAULT 'other',
  `url`           VARCHAR(1000) NOT NULL,
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `display_order` INT(11)      NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. announcements ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `announcements` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(500) NOT NULL,
  `content`      TEXT         NOT NULL,
  `type`         ENUM('general','event','opportunity','alert') NOT NULL DEFAULT 'general',
  `is_pinned`    TINYINT(1)   NOT NULL DEFAULT 0,
  `is_published` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. settings (key-value store) ───────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT         DEFAULT NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT INTO `settings` (`key`, `value`) VALUES
('discord_invite_url',  'https://discord.gg/YOUR_INVITE_CODE'),
('discord_server_name', 'RARL Community'),
('community_guidelines','Welcome to the RARL Community. Please be respectful and collaborative.'),
('site_name',           'RARL Community'),
('site_tagline',        'Robotics and Automation Research Laboratory'),
('site_url',            'https://rarl-lab.com/membership'),
('require_approval',    '1'),
('cert_id_prefix',      'RARL'),
('cert_id_counter',     '0')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- ── 10. admin_users ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL  COMMENT 'bcrypt hash',
  `email`         VARCHAR(255) DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. partnership_tiers ────────────────────────────────────
-- Editable from Admin → Partnerships. Public display on partners.php.
CREATE TABLE IF NOT EXISTS `partnership_tiers` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `category`      ENUM('membership','partnership') NOT NULL DEFAULT 'partnership',
  `name`          VARCHAR(255) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `fee_amount`    DECIMAL(10,2) DEFAULT NULL                COMMENT 'NULL = no fee shown',
  `fee_currency`  VARCHAR(10)  DEFAULT 'EUR',
  `fee_period`    VARCHAR(50)  DEFAULT 'year',
  `is_addon`      TINYINT(1)   NOT NULL DEFAULT 0            COMMENT 'e.g. High Visibility upsell, shown separately',
  `display_order` INT(11)      NOT NULL DEFAULT 0,
  `is_published`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `partnership_tiers` (`category`, `name`, `description`, `fee_amount`, `fee_currency`, `fee_period`, `is_addon`, `display_order`) VALUES
('membership',  'Membership',                 'Universities and research institutions, private or public, that support our association, train students, and conduct international research.', 5000.00, 'EUR', 'year', 0, 1),
('partnership', 'Private Partnership',        'Companies in the wine, robotics, or automation industry that support our association.', 5000.00, 'EUR', 'year', 0, 2),
('partnership', 'Institutional Partnership',  'Any institution, or association, research or interprofessional institute that supports our association.', 5000.00, 'EUR', 'year', 0, 3),
('partnership', 'High Visibility',            'Contribute to production fees for RARL publications and gain additional visibility across our channels.', 10000.00, 'EUR', 'year', 1, 4)
ON DUPLICATE KEY UPDATE `name` = `name`;

INSERT INTO `settings` (`key`, `value`) VALUES
('partner_page_intro',      'RARL welcomes members and partners who support open, collaborative robotics and automation research.'),
('partner_benefits_member', 'Right to vote at the General Assembly during annual meetings\nInput on RARL strategy and media development\nPromotion of open access dissemination'),
('partner_benefits_partner','Involvement with the RARL Technical Reviews editorial board\nNetworking opportunities with researchers\nParticipation in the yearly RARL Science meeting'),
('partner_ethics_note',     'All private partners of RARL are asked to sign a charter of ethics.'),
('partner_contact_name',    'RARL Partnerships Team'),
('partner_contact_email',   'info@rarl-lab.com')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- Default seed data for resource categories
INSERT INTO `resource_categories` (`name`, `slug`, `icon_emoji`, `description`, `display_order`) VALUES
('Scientific Writing',  'scientific-writing',  '✍️', 'Learn how to write impactful research papers and academic documents.', 1),
('Research Methodology','research-methodology','🔬', 'Fundamentals of designing and conducting research.', 2),
('Machine Learning',    'machine-learning',    '🤖', 'Tutorials and courses on ML algorithms and applications.', 3),
('Artificial Intelligence','artificial-intelligence','🧠','Foundations and advanced topics in AI.', 4),
('Programming',         'programming',         '💻', 'Python, MATLAB, and other tools for researchers.', 5),
('Statistics',          'statistics',          '📊', 'Statistical methods for research analysis.', 6),
('Publishing & Journals','publishing',         '📄', 'How to navigate academic publishing and peer review.', 7),
('LaTeX',               'latex',               '📝', 'Document preparation for academic writing.', 8)
ON DUPLICATE KEY UPDATE `slug` = `slug`;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
