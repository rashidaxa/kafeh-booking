-- ============================================================
-- Kafeh Booking — v27 migration
--   Per-booking surcharge breakdown. kfb_bookings only kept the surcharge
--   TOTAL (surcharges_total_amount), so the legacy portal entry and the
--   customer's price lines could not show which surcharges applied
--   (Meet & Greet, Airport fee, Parking, Tolls, ...). surcharges_json keeps
--   the itemised list as the quote computed it:
--     [{"code":"AIRPORT_FEE","name":"Airport fee","amount":"25.00"}, ...]
--   Bookings made before this migration have NULL and fall back to the
--   total as a single "surcharge" line (see Legacy_reservations::leg_pricing()).
--
--   The application only writes this column when it exists, so the code can
--   be deployed before or after this migration.
--
-- Run on existing databases:
--   mysql -u root -p <database> < kfb_migration_v27.sql
--
-- Idempotent: guarded column add. Safe to re-run.
-- ============================================================

USE `ground54_bookings_api`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v27_add_column $$
CREATE PROCEDURE _kfb_v27_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$
DELIMITER ;

CALL _kfb_v27_add_column('kfb_bookings', 'surcharges_json',
  "TEXT NULL COMMENT 'Itemised surcharges [{code,name,amount}] as quoted — NULL on bookings made before v27' AFTER `surcharges_total_amount`");

DROP PROCEDURE _kfb_v27_add_column;
