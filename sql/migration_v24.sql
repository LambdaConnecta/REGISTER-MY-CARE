-- Migration v24: Subscription tiers + care plans table

-- Add subscription tier columns (safe individual ALTERs)
ALTER TABLE `organisations` ADD COLUMN `subscription_tier` VARCHAR(30) NOT NULL DEFAULT 'free';
ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit` INT NOT NULL DEFAULT 2;

-- Care plans table
CREATE TABLE IF NOT EXISTS `care_plans` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `title`           VARCHAR(255) NOT NULL,
  `plan_type`       VARCHAR(100) NOT NULL DEFAULT 'General',
  `content`         LONGTEXT,
  `status`          VARCHAR(30) NOT NULL DEFAULT 'Active',
  `review_date`     DATE DEFAULT NULL,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Care plan documents
CREATE TABLE IF NOT EXISTS `care_plan_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `care_plan_id`    INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `doc_type`        VARCHAR(100) NOT NULL DEFAULT 'Care Plan',
  `title`           VARCHAR(255) NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT 0,
  `file_type`       VARCHAR(100) DEFAULT '',
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_cp` (`organisation_id`,`care_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure su_medical_history exists
CREATE TABLE IF NOT EXISTS `su_medical_history` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `entry_type`      VARCHAR(100) NOT NULL DEFAULT 'General',
  `title`           VARCHAR(255) NOT NULL,
  `content`         LONGTEXT,
  `recorded_by`     INT UNSIGNED DEFAULT NULL,
  `entry_date`      DATE DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure su_documents exists
CREATE TABLE IF NOT EXISTS `su_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `doc_type`        VARCHAR(100) NOT NULL DEFAULT 'Other',
  `title`           VARCHAR(255) NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT 0,
  `file_type`       VARCHAR(100) DEFAULT '',
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration v24 complete' AS result;
