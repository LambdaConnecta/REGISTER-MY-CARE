-- Migration v27: Staff profile fields + compliance documents
ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `address` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `date_of_birth` DATE DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `ni_number` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `dbs_on_update` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `emergency_contact` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `notes` TEXT DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `staff_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `staff_id`        INT UNSIGNED NOT NULL,
  `doc_category`    VARCHAR(80)  NOT NULL DEFAULT 'Other',
  `title`           VARCHAR(255) NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT 0,
  `file_type`       VARCHAR(120) DEFAULT '',
  `issue_date`      DATE DEFAULT NULL,
  `expiry_date`     DATE DEFAULT NULL,
  `notes`           VARCHAR(500) DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_staff` (`organisation_id`,`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration v27 complete' AS result;
