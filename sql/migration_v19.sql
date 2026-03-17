-- Register My Care Migration v19
-- Fix: funding_type column was ENUM, caused "Data truncated" on save
-- Change to VARCHAR(100) so any value can be stored

ALTER TABLE `service_users`
  MODIFY COLUMN `funding_type` VARCHAR(100) NOT NULL DEFAULT '';

ALTER TABLE `service_users`
  ADD COLUMN `council_id`   INT UNSIGNED DEFAULT NULL,
  ADD COLUMN `nhs_trust_id` INT UNSIGNED DEFAULT NULL;

SELECT 'Migration v19 complete' AS result;
