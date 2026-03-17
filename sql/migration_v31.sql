-- Migration v31: Add missing columns for holiday, incidents, handovers
-- holiday_requests
ALTER TABLE `holiday_requests` ADD COLUMN `file_name`     VARCHAR(255) DEFAULT NULL;
ALTER TABLE `holiday_requests` ADD COLUMN `file_original` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `holiday_requests` ADD COLUMN `admin_notes`   TEXT         DEFAULT NULL;
ALTER TABLE `holiday_requests` ADD COLUMN `reviewed_by`   INT UNSIGNED DEFAULT NULL;
ALTER TABLE `holiday_requests` ADD COLUMN `reviewed_at`   DATETIME     DEFAULT NULL;
-- incidents
ALTER TABLE `incidents` ADD COLUMN `file_name`     VARCHAR(255) DEFAULT NULL;
ALTER TABLE `incidents` ADD COLUMN `file_original` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `incidents` MODIFY COLUMN `severity` VARCHAR(20) NOT NULL DEFAULT 'Low';
ALTER TABLE `incidents` MODIFY COLUMN `status`   VARCHAR(30) NOT NULL DEFAULT 'Open';
-- handovers
ALTER TABLE `handovers` ADD COLUMN `shift_end`          VARCHAR(10)    DEFAULT NULL;
ALTER TABLE `handovers` ADD COLUMN `no_further_visits`  TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `handovers` ADD COLUMN `is_read`            TINYINT(1) NOT NULL DEFAULT 0;
-- Fix ENUMs on users table
ALTER TABLE `users` MODIFY COLUMN `job_category` VARCHAR(80) DEFAULT NULL;
ALTER TABLE `users` MODIFY COLUMN `role`         VARCHAR(20) NOT NULL DEFAULT 'Staff';
-- Subscription plan fix
ALTER TABLE `organisations` MODIFY COLUMN `subscription_plan` VARCHAR(30) NOT NULL DEFAULT 'free';
ALTER TABLE `organisations` ADD COLUMN `subscription_tier`      VARCHAR(30) DEFAULT 'free';
ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME  DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit`   INT       NOT NULL DEFAULT 2;
SELECT 'Migration v31 complete' AS result;
