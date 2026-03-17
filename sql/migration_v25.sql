-- Migration v25: Subscription tiers for super admin management
ALTER TABLE `organisations` ADD COLUMN `subscription_tier` VARCHAR(30) NOT NULL DEFAULT 'free';
ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit` INT NOT NULL DEFAULT 2;
ALTER TABLE `organisations` ADD COLUMN `subscription_notes` VARCHAR(500) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `payment_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `cardholder_name` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  `payment_ref` VARCHAR(100) NOT NULL,
  `status` ENUM('Pending','Confirmed','Failed') NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `super_admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SELECT 'Migration v25 complete' AS result;
