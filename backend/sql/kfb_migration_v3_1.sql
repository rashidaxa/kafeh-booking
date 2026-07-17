-- ============================================================
-- Kafeh Booking — v3.1 migration (flight details + location type)
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v3_1.sql
--
-- Idempotent: every column add is guarded so re-runs are safe.
-- ============================================================

USE `kafeh`;

-- ----------------- Helper procedure -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v31_add_column $$
CREATE PROCEDURE _kfb_v31_add_column(
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

-- ----------------- Bookings: location type + flight details -----------------
CALL _kfb_v31_add_column('kfb_bookings', 'pickup_loc_type',
  "VARCHAR(20) NULL COMMENT 'Search All | Address | Airport | Landmark'");
CALL _kfb_v31_add_column('kfb_bookings', 'dropoff_loc_type',
  "VARCHAR(20) NULL COMMENT 'Search All | Address | Airport | Landmark'");
CALL _kfb_v31_add_column('kfb_bookings', 'airline',
  "VARCHAR(100) NULL COMMENT 'Airline name when pickup loc type = Airport'");
CALL _kfb_v31_add_column('kfb_bookings', 'flight_number',
  "VARCHAR(20) NULL COMMENT 'Flight number when pickup loc type = Airport'");
CALL _kfb_v31_add_column('kfb_bookings', 'arrival_time',
  "TIME NULL COMMENT 'Flight arrival time when pickup loc type = Airport'");
CALL _kfb_v31_add_column('kfb_bookings', 'pickup_point',
  "VARCHAR(50) NULL COMMENT 'Baggage claim / Curbside / Gate / Arrivals hall'");

-- ----------------- Idempotent index -----------------
SET @ix := (SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = 'kfb_bookings'
              AND index_name   = 'idx_pickup_date');
SET @sql := IF(@ix = 0,
               'CREATE INDEX idx_pickup_date ON kfb_bookings (pickup_date)',
               'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v31_add_column;
