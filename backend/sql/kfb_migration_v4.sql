-- ============================================================
-- Kafeh Booking — v4 migration
--   Add-ons, minimum fare, meet-and-greet pricing, return trip,
--   tail number, e-signature, customer accounts
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v4.sql
--
-- Idempotent: every column add / table create is guarded.
-- ============================================================

USE `kafeh`;

-- ----------------- Helper procedure -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v4_add_column $$
CREATE PROCEDURE _kfb_v4_add_column(
  IN p_table   VARCHAR(64),
  IN p_column  VARCHAR(64),
  IN p_def     TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = p_table
      AND column_name  = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$
DELIMITER ;

-- ----------------- 1) Vehicles: minimum fare + meet & greet pricing -----------------
CALL _kfb_v4_add_column('kfb_vehicles', 'min_fare',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minimum billable fare regardless of trip distance'");
CALL _kfb_v4_add_column('kfb_vehicles', 'meet_greet_chicago',
  "DECIMAL(10,2) NOT NULL DEFAULT 65.00 COMMENT 'Meet & Greet fee for Chicago airports'");
CALL _kfb_v4_add_column('kfb_vehicles', 'meet_greet_elsewhere',
  "DECIMAL(10,2) NOT NULL DEFAULT 95.00 COMMENT 'Meet & Greet fee for airports outside Chicago'");

-- ----------------- 2) Bookings: return trip, tail number, signature, IP, add-ons -----------------
CALL _kfb_v4_add_column('kfb_bookings', 'is_return_trip',
  "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if this booking is part of a return trip'");
CALL _kfb_v4_add_column('kfb_bookings', 'return_booking_id',
  "VARCHAR(32) NULL COMMENT 'Booking ID of the return leg, if is_return_trip'");
CALL _kfb_v4_add_column('kfb_bookings', 'return_date',
  "DATE NULL COMMENT 'Date of the return trip'");
CALL _kfb_v4_add_column('kfb_bookings', 'return_time',
  "TIME NULL COMMENT 'Pickup time of the return trip'");
CALL _kfb_v4_add_column('kfb_bookings', 'pickup_type_detail',
  "VARCHAR(30) NULL COMMENT 'Curbside | Meet & Greet | Private Terminal (FBO)'");
CALL _kfb_v4_add_column('kfb_bookings', 'tail_number',
  "VARCHAR(20) NULL COMMENT 'Aircraft tail number when pickup is at FBO/Private Terminal'");
CALL _kfb_v4_add_column('kfb_bookings', 'addons_json',
  "TEXT NULL COMMENT 'JSON array of selected add-on IDs and quantities'");
CALL _kfb_v4_add_column('kfb_bookings', 'addons_total',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of selected add-on prices'");
CALL _kfb_v4_add_column('kfb_bookings', 'min_fare_applied',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Minimum fare that was applied (0 if subtotal was higher)'");
CALL _kfb_v4_add_column('kfb_bookings', 'signature_data',
  "LONGTEXT NULL COMMENT 'Base64 PNG of customer signature for bookings > $500'");
CALL _kfb_v4_add_column('kfb_bookings', 'signature_ip',
  "VARCHAR(45) NULL COMMENT 'IP address at time of signature'");
CALL _kfb_v4_add_column('kfb_bookings', 'signature_at',
  "DATETIME NULL COMMENT 'Timestamp when the signature was captured'");
CALL _kfb_v4_add_column('kfb_bookings', 'terms_version',
  "VARCHAR(20) NULL COMMENT 'Version of T&C the customer agreed to'");
CALL _kfb_v4_add_column('kfb_bookings', 'customer_id',
  "INT UNSIGNED NULL COMMENT 'FK to kfb_customers.id (set if the customer created an account)'");

-- ----------------- 3) Add-ons catalog (configurable from admin) -----------------
CREATE TABLE IF NOT EXISTS `kfb_addons` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`           VARCHAR(40)  NOT NULL,
  `name`           VARCHAR(120) NOT NULL,
  `description`    VARCHAR(500) NULL,
  `category`       VARCHAR(40)  NULL COMMENT 'Red Carpet | Floral | Beverage | Decor | Other',
  `pricing_type`   ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  `price_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `image`          VARCHAR(255) NULL,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `status`         TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=disabled, 1=enabled',
  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NULL,
  UNIQUE KEY `uniq_addon_code` (`code`),
  KEY `idx_addon_status` (`status`),
  KEY `idx_addon_sort`   (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- 4) Booking ↔ Add-on mapping (line items) -----------------
CREATE TABLE IF NOT EXISTS `kfb_booking_addons` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id`  VARCHAR(32)  NOT NULL,
  `addon_id`    INT UNSIGNED NOT NULL,
  `addon_code`  VARCHAR(40)  NOT NULL COMMENT 'Denormalized for receipt rendering',
  `addon_name`  VARCHAR(120) NOT NULL,
  `quantity`    INT          NOT NULL DEFAULT 1,
  `unit_price`  DECIMAL(10,2) NOT NULL,
  `line_total`  DECIMAL(10,2) NOT NULL,
  `region`      VARCHAR(20)  NOT NULL,
  KEY `idx_ba_booking` (`booking_id`),
  KEY `idx_ba_addon`   (`addon_id`),
  CONSTRAINT `fk_ba_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `kfb_bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- 5) Customer accounts (optional, for repeat customers) -----------------
CREATE TABLE IF NOT EXISTS `kfb_customers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email`          VARCHAR(150) NOT NULL,
  `password_hash`  VARCHAR(255) NULL COMMENT 'NULL = guest checkout, set when customer creates an account',
  `first_name`     VARCHAR(100) NOT NULL,
  `last_name`      VARCHAR(100) NOT NULL,
  `phone`          VARCHAR(50)  NULL,
  `default_pickup` VARCHAR(255) NULL,
  `default_dropoff` VARCHAR(255) NULL,
  `total_bookings` INT NOT NULL DEFAULT 0,
  `last_booking_at` DATETIME NULL,
  `status`         ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NULL,
  UNIQUE KEY `uniq_customer_email` (`email`),
  KEY `idx_customer_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- 6) Idempotent index for return-trip pairing -----------------
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = 'kfb_bookings'
              AND index_name   = 'idx_return_booking');
SET @sql := IF(@ix = 0,
               'CREATE INDEX idx_return_booking ON kfb_bookings (return_booking_id)',
               'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v4_add_column;

-- ----------------- Seed default add-ons (only if the table is empty) -----------------
INSERT INTO `kfb_addons` (`code`, `name`, `description`, `category`, `pricing_type`, `price_chicago`, `price_america`, `price_worldwide`, `sort_order`, `status`, `created_at`)
SELECT * FROM (
  SELECT 'REDCARPET'   AS code, 'Red Carpet Service'          AS name, 'Premium red-carpet meet & greet at the vehicle'           AS description, 'Red Carpet' AS category, 'flat' AS pricing_type, 75.00 AS price_chicago, 95.00 AS price_america, 125.00 AS price_worldwide, 1 AS sort_order, 1 AS status, NOW() AS created_at
  UNION ALL SELECT 'FLORAL',      'Floral & Balloon Décor',         'Custom floral arrangement + balloons installed in the vehicle',            'Floral & Balloon', 'flat',  120.00, 150.00, 200.00, 2, 1, NOW()
  UNION ALL SELECT 'CHAMPAGNE',   'Champagne Package',              'Premium champagne with glassware and chilled service',                    'Beverage',         'flat',  85.00,  110.00, 150.00, 3, 1, NOW()
  UNION ALL SELECT 'WEDDINGDECOR','Wedding Decorations',            'Custom floral arches, candles, and aisle décor coordination',             'Wedding',          'flat',  450.00, 550.00, 750.00, 4, 1, NOW()
  UNION ALL SELECT 'CHILDSEAT2',  'Premium Child Seat Upgrade',      'Higher-end Britax / Recaro child seat (replaces standard seat)',          'Other',            'flat',  25.00,  30.00,  40.00,  5, 1, NOW()
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `kfb_addons` LIMIT 1);
