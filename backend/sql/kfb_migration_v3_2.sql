-- ============================================================
-- Kafeh Booking — v3.2 migration (dropoff airport flight info)
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v3_2.sql
--
-- Idempotent: every column add is guarded so re-runs are safe.
-- Mirrors the pickup-side airline / flight_number / arrival_time
-- columns for the dropoff side.
-- ============================================================

USE `kafeh`;

-- ----------------- Helper procedure -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v32_add_column $$
CREATE PROCEDURE _kfb_v32_add_column(
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

-- ----------------- Bookings: dropoff flight details -----------------
CALL _kfb_v32_add_column('kfb_bookings', 'dropoff_airline',
  "VARCHAR(100) NULL COMMENT 'Airline when dropoff loc type = Airport'");
CALL _kfb_v32_add_column('kfb_bookings', 'dropoff_flight_number',
  "VARCHAR(20) NULL COMMENT 'Flight number when dropoff loc type = Airport'");
CALL _kfb_v32_add_column('kfb_bookings', 'dropoff_arrival_time',
  "TIME NULL COMMENT 'Arrival time when dropoff loc type = Airport'");
CALL _kfb_v32_add_column('kfb_bookings', 'dropoff_pickup_point',
  "VARCHAR(50) NULL COMMENT 'Departure curb / Terminal / etc. when dropoff loc type = Airport'");

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v32_add_column;
