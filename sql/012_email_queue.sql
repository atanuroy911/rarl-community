-- ============================================================
-- RARL — Background email queue. Bulk sends (certificates, newsletters,
-- compose, approvals) are queued here and sent in small batches by the
-- admin UI (and optionally cron-email-queue.php), so a 500-recipient send
-- never times out halfway. Idempotent, safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `batch`       VARCHAR(32)  NOT NULL,
  `batch_label` VARCHAR(255) NOT NULL DEFAULT '',
  `to_email`    VARCHAR(255) NOT NULL,
  `to_name`     VARCHAR(255) NOT NULL DEFAULT '',
  `subject`     VARCHAR(500) NOT NULL,
  `body`        MEDIUMTEXT   NOT NULL,
  `attachments` TEXT         DEFAULT NULL,
  `headers`     TEXT         DEFAULT NULL,
  `on_sent`     VARCHAR(100) DEFAULT NULL,
  `status`      ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
  `attempts`    TINYINT(4)   NOT NULL DEFAULT 0,
  `last_error`  VARCHAR(500) DEFAULT NULL,
  `claim`       VARCHAR(32)  DEFAULT NULL,
  `locked_at`   DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
