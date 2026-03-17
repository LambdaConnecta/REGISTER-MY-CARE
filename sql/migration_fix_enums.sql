-- Fix: Convert ENUM columns to VARCHAR in users table
-- Run if you see 'Data truncated for column job_category'
ALTER TABLE `users` MODIFY COLUMN `job_category` VARCHAR(80)  DEFAULT NULL;
ALTER TABLE `users` MODIFY COLUMN `role`         VARCHAR(20)  NOT NULL DEFAULT 'Staff';
SELECT 'Users ENUM fix complete' AS result;
