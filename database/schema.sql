-- ============================================================
-- RARL — database schema (single source of truth)
--
-- The site syncs the database to this file on every deploy and from
-- Admin → Database: missing tables, columns and indexes are added, and
-- enum lists / text lengths are widened. Nothing is ever dropped.
--
-- To change the schema: edit the CREATE TABLE below (add a column line,
-- a KEY line, or a new table) and deploy. No migration files.
-- Data that must exist (default settings, starter rows) lives in
-- database/seed.sql; one-off data fixes go in database/changes.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL COMMENT 'bcrypt hash',
  `email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `content` text NOT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `link_label` varchar(80) DEFAULT NULL,
  `type` enum('general','event','opportunity','alert') NOT NULL DEFAULT 'general',
  `section_id` int(11) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_live` (`is_published`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'Used in verification URL',
  `certificate_no` varchar(50) NOT NULL COMMENT 'e.g. RARL-2025-0042',
  `member_id` int(11) DEFAULT NULL COMMENT 'NULL if member not registered',
  `recipient_name` varchar(255) NOT NULL COMMENT 'Name printed on cert',
  `recipient_email` varchar(255) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `cert_type` enum('event','membership') NOT NULL DEFAULT 'event',
  `template_id` int(11) DEFAULT NULL,
  `design_sig` varchar(40) DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailed_at` timestamp NULL DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `certificate_no` (`certificate_no`),
  KEY `idx_member` (`member_id`),
  KEY `idx_recipient_email` (`recipient_email`),
  KEY `idx_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificate_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('certificate','id_card','membership') NOT NULL DEFAULT 'certificate',
  `background_path` varchar(500) DEFAULT NULL COMMENT 'Path to background image',
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Layout: font sizes, positions, colors' CHECK (json_valid(`config`)),
  `page_width_mm` decimal(6,2) NOT NULL DEFAULT 297.00,
  `page_height_mm` decimal(6,2) NOT NULL DEFAULT 210.00,
  `preview_path` varchar(500) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `community_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `post_id` (`post_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `community_likes` (
  `post_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`post_id`,`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `community_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `body_format` enum('markdown','html') NOT NULL DEFAULT 'markdown',
  `image_path` varchar(500) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `idx_feed` (`is_hidden`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch` varchar(32) NOT NULL,
  `batch_label` varchar(255) NOT NULL DEFAULT '',
  `to_email` varchar(255) NOT NULL,
  `to_name` varchar(255) NOT NULL DEFAULT '',
  `subject` varchar(500) NOT NULL,
  `body` mediumtext NOT NULL,
  `attachments` text DEFAULT NULL,
  `headers` text DEFAULT NULL,
  `on_sent` varchar(100) DEFAULT NULL,
  `status` enum('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  `claim` varchar(32) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_send_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('workshop','webinar','hackathon','competition','volunteer','conference','seminar','other') NOT NULL DEFAULT 'other',
  `visibility` enum('public','members_only') NOT NULL DEFAULT 'public',
  `event_date` date DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL COMMENT 'Online or physical location',
  `online_url` varchar(500) DEFAULT NULL,
  `speaker_name` varchar(255) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `recording_url` varchar(500) DEFAULT NULL,
  `cover_image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `reminder_sent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active_date` (`is_active`,`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `status` enum('registered','attended','no_show','cancelled') NOT NULL DEFAULT 'registered',
  `registered_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_member` (`event_id`,`member_id`),
  KEY `event_id` (`event_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `kind` varchar(30) NOT NULL COMMENT 'share_linkedin',
  `ref_type` varchar(20) NOT NULL DEFAULT '',
  `ref_id` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `one_per_item` (`member_id`,`kind`,`ref_type`,`ref_id`),
  KEY `idx_member_kind` (`member_id`,`kind`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL COMMENT 'Public-facing ID',
  `member_code` varchar(20) DEFAULT NULL,
  `type` enum('individual','lab') NOT NULL DEFAULT 'individual',
  `is_chair` tinyint(1) NOT NULL DEFAULT 0,
  `plan_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `country` varchar(100) DEFAULT NULL,
  `city_state` varchar(150) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `research_interests` text DEFAULT NULL,
  `primary_lab_name` varchar(150) DEFAULT NULL,
  `years_experience` enum('<1','1-3','3-5','5-10','10+') DEFAULT NULL,
  `referral_source` enum('conference','referral','social_media','newsletter','other') DEFAULT NULL,
  `newsletter_opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `community_notify` tinyint(1) NOT NULL DEFAULT 1,
  `unsubscribe_token` varchar(64) NOT NULL,
  `status` enum('pending','active','inactive') NOT NULL DEFAULT 'pending',
  `avatar_path` varchar(500) DEFAULT NULL,
  `cv_path` varchar(255) DEFAULT NULL,
  `cv_url` varchar(500) DEFAULT NULL,
  `id_card_path` varchar(255) DEFAULT NULL,
  `id_card_issued_at` date DEFAULT NULL,
  `id_card_expires_at` date DEFAULT NULL,
  `discord_invited` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Doubles as "welcome email sent" flag',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL COMMENT 'Password reset token',
  `reset_expires` datetime DEFAULT NULL COMMENT 'Reset token expiry',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `full_name` varchar(255) DEFAULT NULL,
  `institution` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL COMMENT 'PhD Student, Researcher, Professor, etc.',
  `google_scholar_url` varchar(500) DEFAULT NULL,
  `orcid_id` varchar(50) DEFAULT NULL,
  `linkedin_url` varchar(500) DEFAULT NULL,
  `lab_name` varchar(255) DEFAULT NULL,
  `pi_name` varchar(255) DEFAULT NULL,
  `lab_website` varchar(500) DEFAULT NULL,
  `research_areas` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `directory_visible` tinyint(1) NOT NULL DEFAULT 1,
  `open_to_mentor` tinyint(1) NOT NULL DEFAULT 0,
  `seeking_mentor` tinyint(1) NOT NULL DEFAULT 0,
  `standing` enum('good','warned_1','warned_2','lapsed') NOT NULL DEFAULT 'good' COMMENT 'Membership standing (activity rules)',
  `standing_changed_at` datetime DEFAULT NULL,
  `standing_exempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Never warned or paused',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `unsubscribe_token` (`unsubscribe_token`),
  UNIQUE KEY `member_code` (`member_code`),
  KEY `reset_token` (`reset_token`),
  KEY `idx_status` (`status`),
  KEY `idx_section` (`section_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_standing` (`standing`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `features` text DEFAULT NULL COMMENT 'one bullet per line',
  `audience` enum('individual','lab','both') NOT NULL DEFAULT 'both',
  `regular_price` decimal(10,2) DEFAULT NULL COMMENT 'future price once free period ends',
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `billing_period` varchar(20) NOT NULL DEFAULT 'year',
  `is_free_now` tinyint(1) NOT NULL DEFAULT 1,
  `free_note` varchar(160) NOT NULL DEFAULT 'Free for a limited time',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_emails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `label` varchar(100) DEFAULT NULL COMMENT 'e.g. "Work", "ORCID-linked", "University"',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `newsletters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject` varchar(500) NOT NULL,
  `body_html` longtext NOT NULL,
  `preview_text` varchar(255) DEFAULT NULL COMMENT 'Shown in email client preview',
  `segment` enum('all','individual','lab') NOT NULL DEFAULT 'all',
  `status` enum('draft','scheduled','sent') NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `recipient_count` int(11) NOT NULL DEFAULT 0,
  `open_count` int(11) NOT NULL DEFAULT 0,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `purpose` enum('verify_email','password_reset') NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_purpose` (`email`,`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `partnership_tiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` enum('membership','partnership') NOT NULL DEFAULT 'partnership',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL COMMENT 'NULL = no fee shown',
  `fee_currency` varchar(10) DEFAULT 'EUR',
  `fee_period` varchar(50) DEFAULT 'year',
  `is_addon` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'e.g. High Visibility upsell, shown separately',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `people` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `designation` varchar(150) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `linkedin_url` varchar(500) DEFAULT NULL,
  `google_scholar_url` varchar(500) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `authors` varchar(1000) DEFAULT NULL,
  `venue` varchar(300) DEFAULT NULL COMMENT 'Journal or conference',
  `year` smallint(6) DEFAULT NULL,
  `url` varchar(1000) NOT NULL,
  `doi` varchar(200) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` varchar(500) DEFAULT NULL COMMENT 'Shown to the member when not approved',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `approved_at` datetime DEFAULT NULL,
  `shared_at` datetime DEFAULT NULL COMMENT 'When an admin shared it on LinkedIn',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`,`approved_at`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `regional_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum('continent','country') NOT NULL DEFAULT 'continent',
  `continent` varchar(50) NOT NULL,
  `country` varchar(100) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `chair_name` varchar(150) DEFAULT NULL,
  `chair_email` varchar(255) DEFAULT NULL,
  `chair_title` varchar(100) NOT NULL DEFAULT 'Chapter Chair',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('youtube_playlist','youtube_video','article','book','tool','course','other') NOT NULL DEFAULT 'other',
  `url` varchar(1000) NOT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `access_level` enum('public','member') NOT NULL DEFAULT 'public',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `icon_emoji` varchar(10) DEFAULT '?',
  `description` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `access_level` enum('public','member') NOT NULL DEFAULT 'public',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `videos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `youtube_id` varchar(20) NOT NULL,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL,
  `access_level` enum('public','member') NOT NULL DEFAULT 'public',
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_live` (`is_published`,`created_at`),
  KEY `idx_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

