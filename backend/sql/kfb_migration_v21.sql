-- ============================================================
-- Kafeh Booking — v21 migration
--   kfb_bookings.legacy_sync_log — stores the JSON result (URL, HTTP
--   status, response body/error, timestamp) of the most recent attempt
--   to push this reservation into the legacy management portal (works/
--   bookingOld) on accept, so an admin can see exactly why a sync did
--   or didn't land without digging through server error logs — see
--   Legacy_reservations::sync() and the "View Legacy Sync Log" button
--   on the reservation detail page.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v21.sql
--
-- Idempotent: the column add is guarded by an information_schema
-- check. Safe to re-run.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v21_add_column $$
CREATE PROCEDURE _kfb_v21_add_column(
  IN p_table  VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_def    TEXT
)
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

CALL _kfb_v21_add_column('kfb_bookings', 'legacy_sync_log',
  "TEXT NULL DEFAULT NULL COMMENT 'JSON log of the last legacy-portal sync attempt(s) on accept — see Legacy_reservations.php' AFTER `hours_requested`");

DROP PROCEDURE _kfb_v21_add_column;
