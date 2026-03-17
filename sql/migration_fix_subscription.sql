-- Migration: Fix subscription_plan column (ENUM -> VARCHAR)
-- Run this if you see 'Data truncated for column subscription_plan'
ALTER TABLE `organisations` MODIFY COLUMN `subscription_plan` VARCHAR(30) NOT NULL DEFAULT 'free';
ALTER TABLE `organisations` MODIFY COLUMN `subscription_tier` VARCHAR(30) NOT NULL DEFAULT 'free';

-- Add missing columns if not present (safe - errors ignored)
ALTER TABLE `organisations` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `subscription_su_limit` INT NOT NULL DEFAULT 2;
ALTER TABLE `organisations` ADD COLUMN `subscription_notes` VARCHAR(500) DEFAULT NULL;

SELECT 'Subscription column fix complete' AS result;
