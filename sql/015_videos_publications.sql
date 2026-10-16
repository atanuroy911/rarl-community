-- ============================================================
-- RARL — Videos (YouTube recordings shown in the portal) and
-- Publications (papers members publish with the RARL affiliation;
-- submitted by members, approved by admins, shown on the site).
-- Idempotent, safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `videos` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `youtube_id`   VARCHAR(20)  NOT NULL,
  `title`        VARCHAR(300) NOT NULL,
  `description`  TEXT         DEFAULT NULL,
  `event_id`     INT(11)      DEFAULT NULL,
  `access_level` ENUM('public','member') NOT NULL DEFAULT 'public',
  `is_published` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_live` (`is_published`, `created_at`),
  KEY `idx_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publications` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `member_id`     INT(11)      NOT NULL,
  `title`         VARCHAR(500) NOT NULL,
  `authors`       VARCHAR(1000) DEFAULT NULL,
  `venue`         VARCHAR(300) DEFAULT NULL  COMMENT 'Journal or conference',
  `year`          SMALLINT     DEFAULT NULL,
  `url`           VARCHAR(1000) NOT NULL,
  `doi`           VARCHAR(200) DEFAULT NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note`    VARCHAR(500) DEFAULT NULL  COMMENT 'Shown to the member when not approved',
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `approved_at`   DATETIME     DEFAULT NULL,
  `shared_at`     DATETIME     DEFAULT NULL  COMMENT 'When an admin shared it on LinkedIn',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `approved_at`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
