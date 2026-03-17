-- Migration v28: Settings page new columns
ALTER TABLE `organisations` ADD COLUMN `website`        VARCHAR(255) DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `contact_name`   VARCHAR(255) DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `company_number` VARCHAR(50)  DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `vat_number`     VARCHAR(50)  DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `bank_name`      VARCHAR(100) DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `bank_sort_code` VARCHAR(20)  DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `bank_account`   VARCHAR(30)  DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `active_modules` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `organisations` ADD COLUMN `address`        TEXT         DEFAULT NULL;
SELECT 'Migration v28 complete' AS result;
