-- ============================================================
-- Kafeh Booking — v5 migration
--   Global settings table (Meet & Greet fee is no longer per-vehicle),
--   drop-off pickup-type-detail + tail number
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v5.sql
--
-- Idempotent: every column add / table create is guarded.
-- ============================================================

USE `kafeh`;

-- ----------------- Helper procedure -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v5_add_column $$
CREATE PROCEDURE _kfb_v5_add_column(
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

-- ----------------- 1) Bookings: drop-off pickup type + tail number -----------------
CALL _kfb_v5_add_column('kfb_bookings', 'dropoff_type_detail',
  "VARCHAR(30) NULL COMMENT 'Curbside | Meet & Greet | Private Terminal (FBO)'");
CALL _kfb_v5_add_column('kfb_bookings', 'dropoff_tail_number',
  "VARCHAR(20) NULL COMMENT 'Aircraft tail number when dropoff is at FBO/Private Terminal'");

-- ----------------- 2) Global settings (key/value) -----------------
CREATE TABLE IF NOT EXISTS `kfb_settings` (
  `setting_key`   VARCHAR(64)  NOT NULL PRIMARY KEY,
  `setting_value` VARCHAR(255) NULL,
  `updated_at`    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v5_add_column;

-- ----------------- Seed Meet & Greet defaults (only if the table is empty) -----------------
-- NOTE: Meet & Greet pricing moves from per-vehicle (kfb_vehicles.meet_greet_chicago /
-- meet_greet_elsewhere, added in v4) to this single global setting. The old per-vehicle
-- columns are left in place (unused) rather than dropped, so no vehicle data is lost.
INSERT INTO `kfb_settings` (`setting_key`, `setting_value`, `updated_at`)
SELECT * FROM (
  SELECT 'meet_greet_chicago'   AS setting_key, '65.00' AS setting_value, NOW() AS updated_at
  UNION ALL SELECT 'meet_greet_elsewhere', '95.00', NOW()
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `kfb_settings` LIMIT 1);
