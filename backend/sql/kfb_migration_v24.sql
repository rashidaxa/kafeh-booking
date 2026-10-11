-- ============================================================
-- Kafeh Booking — v24 migration
--   Multi-company ("tenants"). Each company that embeds the widget has
--   its own customers, bookings and branding; the company is resolved
--   from the website domain the widget runs on (kfb_company_domains).
--   Unknown domains are refused by the public API — an admin adds the
--   company + its domains first (Admin > Companies).
--
--   - kfb_companies: name, logo, 2 contact numbers, 2 address lines,
--     contact + notification email, widget page URL (target for emailed
--     links), legacy company id (legacy portal `select_company`).
--   - kfb_company_domains: normalized host -> company.
--   - company_id on kfb_customers (email is now unique PER company),
--     kfb_bookings and kfb_client_bookings, plus origin_host (the domain
--     the reservation came from).
--   - Everything that exists today is assigned to company 1,
--     "A1 Limousine" (legacy company 6), which owns grounddispatch.com,
--     localhost and 127.0.0.1.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v24.sql
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, INSERT IGNORE, and guarded
-- column/index changes via information_schema. Safe to re-run.
-- ============================================================

USE `ground54_bookings_api`;

CREATE TABLE IF NOT EXISTS `kfb_companies` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`              VARCHAR(150) NOT NULL,
  `logo`              VARCHAR(255) NULL COMMENT 'File name under uploads/companies/',
  `phone_1`           VARCHAR(50)  NULL,
  `phone_2`           VARCHAR(50)  NULL,
  `address_line_1`    VARCHAR(255) NULL,
  `address_line_2`    VARCHAR(255) NULL,
  `contact_email`     VARCHAR(150) NULL COMMENT 'Shown in email footer; Reply-To of customer emails',
  `notification_email` VARCHAR(150) NULL COMMENT 'Receives this company''s new-reservation emails (falls back to admin_notification_email)',
  `widget_page_url`   VARCHAR(255) NULL COMMENT 'Page the widget is embedded on — target of emailed links',
  `legacy_company_id` INT UNSIGNED NULL COMMENT 'companies.company_id in the legacy portal (reservations.select_company)',
  `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`        DATETIME NOT NULL,
  `updated_at`        DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kfb_company_domains` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT UNSIGNED NOT NULL,
  `domain`     VARCHAR(190) NOT NULL COMMENT 'Normalized: lowercase, no scheme/port/www',
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uniq_company_domain` (`domain`),
  KEY `idx_domain_company` (`company_id`),
  CONSTRAINT `fk_domain_company` FOREIGN KEY (`company_id`)
    REFERENCES `kfb_companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `kfb_companies`
  (`id`, `name`, `notification_email`, `widget_page_url`, `legacy_company_id`, `status`, `created_at`)
VALUES
  (1, 'A1 Limousine', 'info@a1limousines.com', 'https://grounddispatch.com/booking/test3.html', 6, 'active', NOW());

INSERT IGNORE INTO `kfb_company_domains` (`company_id`, `domain`, `created_at`) VALUES
  (1, 'grounddispatch.com', NOW()),
  (1, 'localhost',          NOW()),
  (1, '127.0.0.1',          NOW());

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v24_add_column $$
CREATE PROCEDURE _kfb_v24_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$

DROP PROCEDURE IF EXISTS _kfb_v24_swap_customer_email_index $$
CREATE PROCEDURE _kfb_v24_swap_customer_email_index()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'kfb_customers' AND index_name = 'uniq_customer_email'
  ) THEN
    ALTER TABLE `kfb_customers` DROP INDEX `uniq_customer_email`;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'kfb_customers' AND index_name = 'uniq_customer_company_email'
  ) THEN
    ALTER TABLE `kfb_customers` ADD UNIQUE KEY `uniq_customer_company_email` (`company_id`, `email`);
  END IF;
END $$

DROP PROCEDURE IF EXISTS _kfb_v24_add_index $$
CREATE PROCEDURE _kfb_v24_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols VARCHAR(128))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD KEY `', p_index, '` (', p_cols, ')');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$
DELIMITER ;

CALL _kfb_v24_add_column('kfb_customers', 'company_id', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`");
CALL _kfb_v24_swap_customer_email_index();

CALL _kfb_v24_add_column('kfb_bookings', 'company_id', "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Company the reservation came from (kfb_companies)' AFTER `booking_id`");
CALL _kfb_v24_add_column('kfb_bookings', 'origin_host', "VARCHAR(190) NULL DEFAULT NULL COMMENT 'Website domain the widget was on when booked' AFTER `company_id`");
CALL _kfb_v24_add_index('kfb_bookings', 'idx_booking_company', '`company_id`');

CALL _kfb_v24_add_column('kfb_client_bookings', 'company_id', "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Company the submission came from (kfb_companies)' AFTER `booking_id`");
CALL _kfb_v24_add_column('kfb_client_bookings', 'origin_host', "VARCHAR(190) NULL DEFAULT NULL AFTER `company_id`");
CALL _kfb_v24_add_index('kfb_client_bookings', 'idx_client_booking_company', '`company_id`');

DROP PROCEDURE _kfb_v24_add_column;
DROP PROCEDURE _kfb_v24_swap_customer_email_index;
DROP PROCEDURE _kfb_v24_add_index;
