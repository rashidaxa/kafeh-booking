-- ============================================================
-- Kafeh Booking — v3 migration (promo codes + child seat surcharge)
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v3.sql
--
-- Idempotent: every column add and table create is guarded so
-- re-runs are safe.
-- ============================================================

USE `kafeh`;

-- ----------------- 1) Helper procedure (created once, kept around) -----------------
-- We keep this around for the entire migration because the
-- second pass below also uses it for the bookings table.
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v3_add_column $$
CREATE PROCEDURE _kfb_v3_add_column(
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

-- ----------------- 2) Vehicles: child-seat surcharge per region -----------------
-- Mirrors the existing per-region rate columns (Chicago / America / Worldwide).
-- A non-zero value means the booking price gets `child_seat_<region>`
-- added for every child seat the customer adds.
CALL _kfb_v3_add_column('kfb_vehicles', 'child_seat_chicago',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat fee per child seat, Chicago'");
CALL _kfb_v3_add_column('kfb_vehicles', 'child_seat_america',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat fee per child seat, Inside America'");
CALL _kfb_v3_add_column('kfb_vehicles', 'child_seat_worldwide',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat fee per child seat, Worldwide'");

-- ----------------- 3) Bookings: promo code + discount snapshot -----------------
CALL _kfb_v3_add_column('kfb_bookings', 'promo_code',
  "VARCHAR(40) NULL COMMENT 'Promo code applied at booking time'");
CALL _kfb_v3_add_column('kfb_bookings', 'discount_amount',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Absolute discount applied to amount'");
CALL _kfb_v3_add_column('kfb_bookings', 'child_seats_breakdown',
  "VARCHAR(255) NULL COMMENT 'JSON {type: qty, ...} of child seats added'");

-- ----------------- 4) Idempotent index on kfb_bookings.promo_code -----------------
-- MySQL has no `CREATE INDEX IF NOT EXISTS` (until 8.0.29+), so we
-- guard with information_schema + PREPARE for broad compatibility.
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = 'kfb_bookings'
              AND index_name   = 'idx_promo_code');
SET @sql := IF(@ix = 0,
               'CREATE INDEX idx_promo_code ON kfb_bookings (promo_code)',
               'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------- 5) Cleanup helper procedure -----------------
DROP PROCEDURE _kfb_v3_add_column;

-- ----------------- 6) Promo codes table -----------------
CREATE TABLE IF NOT EXISTS `kfb_promo_codes` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`           VARCHAR(40)  NOT NULL,
  `description`    VARCHAR(255) NULL,
  `discount_type`  ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Subtotal must be >= this to apply',
  `max_uses`       INT          NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `used_count`     INT          NOT NULL DEFAULT 0,
  `starts_at`      DATE         NULL,
  `expires_at`     DATE         NULL,
  `status`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0=disabled, 1=enabled',
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NULL,
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_status`  (`status`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
