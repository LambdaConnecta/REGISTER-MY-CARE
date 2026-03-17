-- ============================================================
-- Register My Care — Full Database Schema
-- Created and designed by Dr. Andrew Ebhoma
-- Run this file ONCE on a fresh database to create all tables.
-- Then run migration files in version order for existing databases.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = '';

-- ── Organisations ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `organisations` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                   VARCHAR(255) NOT NULL,
  `logo_path`              VARCHAR(255) DEFAULT NULL,
  `email`                  VARCHAR(255) DEFAULT NULL,
  `phone`                  VARCHAR(50)  DEFAULT NULL,
  `address`                TEXT DEFAULT NULL,
  `subscription_plan`      VARCHAR(50)  NOT NULL DEFAULT 'free',
  `subscription_expires_at` DATETIME    DEFAULT NULL,
  `su_limit`               INT UNSIGNED NOT NULL DEFAULT 2,
  `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Users (staff) ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id`   INT UNSIGNED NOT NULL,
  `first_name`        VARCHAR(100) NOT NULL,
  `last_name`         VARCHAR(100) NOT NULL,
  `email`             VARCHAR(255) NOT NULL,
  `password`          VARCHAR(255) NOT NULL,
  `role`              VARCHAR(20)  NOT NULL DEFAULT 'Staff',
  `job_category`      VARCHAR(80)  DEFAULT NULL,
  `phone`             VARCHAR(30)  DEFAULT NULL,
  `address`           TEXT DEFAULT NULL,
  `date_of_birth`     DATE DEFAULT NULL,
  `ni_number`         VARCHAR(20)  DEFAULT NULL,
  `dbs_on_update`     TINYINT(1) NOT NULL DEFAULT 0,
  `dbs_check_date`    DATE DEFAULT NULL,
  `emergency_contact` VARCHAR(255) DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Service Users ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `service_users` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id`   INT UNSIGNED NOT NULL,
  `first_name`        VARCHAR(100) NOT NULL,
  `last_name`         VARCHAR(100) NOT NULL,
  `date_of_birth`     DATE DEFAULT NULL,
  `nhs_number`        VARCHAR(30)  DEFAULT NULL,
  `address`           TEXT DEFAULT NULL,
  `phone`             VARCHAR(30)  DEFAULT NULL,
  `email`             VARCHAR(255) DEFAULT NULL,
  `gender`            VARCHAR(20)  DEFAULT NULL,
  `funding_type`      VARCHAR(80)  DEFAULT NULL,
  `council`           VARCHAR(150) DEFAULT NULL,
  `nhs_trust`         VARCHAR(150) DEFAULT NULL,
  `emergency_contact` VARCHAR(255) DEFAULT NULL,
  `gp_details`        TEXT DEFAULT NULL,
  `care_plan`         TEXT DEFAULT NULL,
  `allergies`         TEXT DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Visits ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `visits` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id`   INT UNSIGNED NOT NULL,
  `service_user_id`   INT UNSIGNED NOT NULL,
  `carer_id`          INT UNSIGNED DEFAULT NULL,
  `visit_date`        DATE NOT NULL,
  `start_time`        TIME NOT NULL,
  `end_time`          TIME NOT NULL,
  `actual_start_time` TIME DEFAULT NULL,
  `actual_end_time`   TIME DEFAULT NULL,
  `status`            VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
  `visit_type`        VARCHAR(80) DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org_date` (`organisation_id`,`visit_date`),
  KEY `k_carer`    (`carer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Care Logs ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `care_logs` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `visit_id`        INT UNSIGNED DEFAULT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `staff_id`        INT UNSIGNED NOT NULL,
  `log_date`        DATE NOT NULL,
  `care_type`       VARCHAR(100) DEFAULT NULL,
  `mood`            VARCHAR(50)  DEFAULT NULL,
  `duration_mins`   INT UNSIGNED DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `file_name`       VARCHAR(255) DEFAULT NULL,
  `file_original`   VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org`  (`organisation_id`),
  KEY `k_visit`(`visit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Medications ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `medications` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `name`            VARCHAR(255) NOT NULL,
  `dose`            VARCHAR(100) DEFAULT NULL,
  `frequency`       VARCHAR(100) DEFAULT NULL,
  `route`           VARCHAR(80)  DEFAULT NULL,
  `prescriber`      VARCHAR(255) DEFAULT NULL,
  `start_date`      DATE DEFAULT NULL,
  `end_date`        DATE DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_su` (`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── MAR Records ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mar_records` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `medication_id`   INT UNSIGNED NOT NULL,
  `staff_id`        INT UNSIGNED NOT NULL,
  `administered_at` DATETIME NOT NULL,
  `status`          VARCHAR(30) NOT NULL DEFAULT 'Given',
  `notes`           TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_med` (`medication_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Staff Messages ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `staff_messages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id`  INT UNSIGNED NOT NULL,
  `from_id`          INT UNSIGNED NOT NULL,
  `to_id`            INT UNSIGNED DEFAULT NULL,
  `is_broadcast`     TINYINT(1) NOT NULL DEFAULT 0,
  `subject`          VARCHAR(255) NOT NULL DEFAULT '',
  `body`             TEXT NOT NULL,
  `is_read`          TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_by_sender` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`),
  KEY `k_to`  (`to_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Handovers ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `handovers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id`  INT UNSIGNED NOT NULL,
  `from_staff_id`    INT UNSIGNED NOT NULL,
  `to_staff_id`      INT UNSIGNED DEFAULT NULL,
  `service_user_id`  INT UNSIGNED DEFAULT NULL,
  `handover_date`    DATE NOT NULL,
  `shift_end`        VARCHAR(20) DEFAULT NULL,
  `no_further_visits` TINYINT(1) NOT NULL DEFAULT 0,
  `content`          TEXT NOT NULL,
  `is_read`          TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Holiday Requests ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `holiday_requests` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `staff_id`        INT UNSIGNED NOT NULL,
  `start_date`      DATE NOT NULL,
  `end_date`        DATE NOT NULL,
  `days`            INT NOT NULL DEFAULT 1,
  `reason`          TEXT DEFAULT NULL,
  `file_name`       VARCHAR(255) DEFAULT NULL,
  `file_original`   VARCHAR(255) DEFAULT NULL,
  `status`          VARCHAR(20) NOT NULL DEFAULT 'Pending',
  `admin_notes`     TEXT DEFAULT NULL,
  `reviewed_by`     INT UNSIGNED DEFAULT NULL,
  `reviewed_at`     DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Incidents ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `incidents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `reported_by`     INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED DEFAULT NULL,
  `incident_date`   DATETIME NOT NULL,
  `category`        VARCHAR(100) NOT NULL DEFAULT 'General',
  `description`     TEXT NOT NULL,
  `action_taken`    TEXT DEFAULT NULL,
  `severity`        VARCHAR(20) NOT NULL DEFAULT 'Low',
  `status`          VARCHAR(30) NOT NULL DEFAULT 'Open',
  `file_name`       VARCHAR(255) DEFAULT NULL,
  `file_original`   VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Policies ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `policies` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `title`           VARCHAR(255) NOT NULL,
  `category`        VARCHAR(100) DEFAULT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT 0,
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Staff Documents ───────────────────────────────────────────────────────────
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

-- ── SU Documents ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `su_documents` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `doc_category`    VARCHAR(80)  NOT NULL DEFAULT 'Other',
  `title`           VARCHAR(255) NOT NULL,
  `file_name`       VARCHAR(255) NOT NULL,
  `file_original`   VARCHAR(255) NOT NULL,
  `file_size`       INT UNSIGNED DEFAULT 0,
  `file_type`       VARCHAR(120) DEFAULT '',
  `notes`           VARCHAR(500) DEFAULT NULL,
  `uploaded_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_su` (`organisation_id`,`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Medical History ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `medical_history` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `entry_date`      DATE NOT NULL,
  `category`        VARCHAR(100) DEFAULT 'General',
  `description`     TEXT NOT NULL,
  `added_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_su` (`service_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Audit Log ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED DEFAULT NULL,
  `user_id`         INT UNSIGNED DEFAULT NULL,
  `action`          VARCHAR(100) NOT NULL,
  `details`         TEXT DEFAULT NULL,
  `ip_address`      VARCHAR(45) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`),
  KEY `k_user`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Invoices ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `service_user_id` INT UNSIGNED NOT NULL,
  `invoice_number`  VARCHAR(50)  NOT NULL,
  `invoice_date`    DATE NOT NULL,
  `due_date`        DATE DEFAULT NULL,
  `period_from`     DATE DEFAULT NULL,
  `period_to`       DATE DEFAULT NULL,
  `total_hours`     DECIMAL(8,2) DEFAULT 0,
  `hourly_rate`     DECIMAL(8,2) DEFAULT 0,
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0,
  `status`          VARCHAR(30) NOT NULL DEFAULT 'Draft',
  `notes`           TEXT DEFAULT NULL,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_org` (`organisation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Subscriptions (super-admin tracking) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `subscription_payments` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `organisation_id` INT UNSIGNED NOT NULL,
  `plan`            VARCHAR(50)  NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `currency`        VARCHAR(10) NOT NULL DEFAULT 'GBP',
  `payment_ref`     VARCHAR(255) DEFAULT NULL,
  `paid_at`         DATETIME DEFAULT NULL,
  `expires_at`      DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Super Admin Users ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `super_admins` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(255) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `name`       VARCHAR(255) NOT NULL DEFAULT 'Super Admin',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Default Super Admin (password: changeme123 — CHANGE IMMEDIATELY) ──────────
INSERT IGNORE INTO `super_admins` (`email`,`password`,`name`) VALUES
('admin@registermycare.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin');
