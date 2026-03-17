-- Register My Care Migration v17
-- Fixes: ensures visits table has correct columns for rota
-- Adds council_id/nhs_trust_id to service_users (idempotent)

-- Ensure service_users has council/NHS columns
ALTER TABLE `service_users`
  ADD COLUMN `council_id`   INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `nhs_trust_id` INT UNSIGNED DEFAULT NULL;

-- Ensure visits table has all required columns
ALTER TABLE `visits`
  ADD COLUMN `visit_date`        DATE DEFAULT NULL,
  ADD COLUMN `carer_id`          INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `status`            VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
  ADD COLUMN `actual_start_time` TIME DEFAULT NULL,
  ADD COLUMN `actual_end_time`   TIME DEFAULT NULL,
  ADD COLUMN `notes`             TEXT DEFAULT NULL;

-- Backfill visit_date from created_at if empty
UPDATE `visits` SET `visit_date` = DATE(`created_at`) WHERE `visit_date` IS NULL;

-- Ensure care_logs and care_log_documents exist
CREATE TABLE IF NOT EXISTS `care_logs` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `staff_id`        INT UNSIGNED NOT NULL,
  `visit_id`        INT UNSIGNED DEFAULT NULL,
  `log_date`        DATE NOT NULL,
  `care_type`       VARCHAR(150) NOT NULL DEFAULT 'General Care',
  `notes`           TEXT NOT NULL,
  `duration_mins`   INT DEFAULT NULL,
  `mood`            VARCHAR(30) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org`   (`organisation_id`),
  KEY `k_su`    (`service_user_id`),
  KEY `k_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `care_log_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `care_log_id`     INT UNSIGNED NOT NULL,
  `organisation_id` INT UNSIGNED NOT NULL,
  `title`           VARCHAR(255) NOT NULL DEFAULT '',
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED NOT NULL DEFAULT 0,
  `file_type`       VARCHAR(100) NOT NULL DEFAULT '',
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_log` (`care_log_id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `su_medical_history` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `entry_type`      VARCHAR(100) NOT NULL DEFAULT 'General',
  `title`           VARCHAR(255) NOT NULL DEFAULT '',
  `content`         TEXT NOT NULL,
  `recorded_by`     INT UNSIGNED DEFAULT NULL,
  `entry_date`      DATE NOT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_su`  (`service_user_id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `su_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `doc_type`        VARCHAR(100) NOT NULL DEFAULT 'General',
  `title`           VARCHAR(255) NOT NULL DEFAULT '',
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED NOT NULL DEFAULT 0,
  `file_type`       VARCHAR(100) NOT NULL DEFAULT '',
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_su`  (`service_user_id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration v17 complete' AS result;
