-- Migration v32: Invoices tables + missing columns
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `invoice_number`  VARCHAR(50)  NOT NULL,
  `client_name`     VARCHAR(255) NOT NULL DEFAULT '',
  `client_address`  TEXT         DEFAULT NULL,
  `client_email`    VARCHAR(255) DEFAULT NULL,
  `service_period`  VARCHAR(100) DEFAULT NULL,
  `issue_date`      DATE         NOT NULL,
  `due_date`        DATE         DEFAULT NULL,
  `status`          VARCHAR(20)  NOT NULL DEFAULT 'Draft',
  `subtotal`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `vat_rate`        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `vat_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes`           TEXT          DEFAULT NULL,
  `created_by`      INT UNSIGNED  DEFAULT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `invoice_lines` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT UNSIGNED  NOT NULL,
  `description` VARCHAR(500)  NOT NULL,
  `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1,
  `unit_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `k_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration v32 complete' AS result;
