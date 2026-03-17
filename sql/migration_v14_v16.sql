-- Register My Care Migration v14
ALTER TABLE `organisations`
  ADD COLUMN `logo_path` VARCHAR(255) DEFAULT NULL AFTER `name`;

CREATE TABLE IF NOT EXISTS `staff_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `from_id` INT UNSIGNED NOT NULL,
  `to_id` INT UNSIGNED DEFAULT NULL,
  `is_broadcast` TINYINT(1) NOT NULL DEFAULT 0,
  `subject` VARCHAR(255) NOT NULL DEFAULT '',
  `body` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_by_sender` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`),
  KEY `k_to` (`to_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `handovers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `from_staff_id` INT UNSIGNED NOT NULL,
  `to_staff_id` INT UNSIGNED DEFAULT NULL,
  `service_user_id` INT UNSIGNED DEFAULT NULL,
  `handover_date` DATE NOT NULL,
  `shift_end` VARCHAR(20) DEFAULT NULL,
  `no_further_visits` TINYINT(1) NOT NULL DEFAULT 0,
  `content` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `holiday_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `staff_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `days` INT NOT NULL DEFAULT 1,
  `reason` TEXT DEFAULT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_original` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `reported_by` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED DEFAULT NULL,
  `incident_date` DATETIME NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'General',
  `description` TEXT NOT NULL,
  `action_taken` TEXT DEFAULT NULL,
  `severity` ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Low',
  `status` ENUM('Open','Under Review','Closed') NOT NULL DEFAULT 'Open',
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_original` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `policies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) DEFAULT 'General',
  `file_name` VARCHAR(255) NOT NULL,
  `file_original` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `file_type` VARCHAR(100) NOT NULL DEFAULT '',
  `version` VARCHAR(20) DEFAULT '1.0',
  `review_date` DATE DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payment_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `cardholder_name` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `payment_ref` VARCHAR(100) NOT NULL,
  `status` ENUM('Pending','Confirmed','Failed') NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration v14 complete' AS result;
