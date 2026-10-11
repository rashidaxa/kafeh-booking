-- ============================================================
-- Kafeh Booking — v23 migration
--   Credit Card Holder details on kfb_bookings (Step 3 of the widget).
--   The cardholder ("client"/booker) can differ from the passenger, so
--   they get their own columns instead of reusing first_name/last_name/
--   phone/email (which stay the passenger's). Legacy portal sync uses
--   these for Client Name ("First Last" or "First Last | Company"),
--   client phone and email — see Legacy_reservations.php. Customer-facing
--   emails go to cc_email.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v23.sql
--
-- Idempotent: every column add is guarded by an information_schema
-- check. Safe to re-run. Existing rows keep NULLs (they fall back to
-- the passenger's details everywhere these are read).
-- ============================================================

USE `ground54_bookings_api`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v23_add_column $$
CREATE PROCEDURE _kfb_v23_add_column(
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

CALL _kfb_v23_add_column('kfb_bookings', 'cc_first_name', "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Credit card holder first name (legacy Client Name)' AFTER `cardHolderName`");
CALL _kfb_v23_add_column('kfb_bookings', 'cc_last_name',  "VARCHAR(100) NULL DEFAULT NULL AFTER `cc_first_name`");
CALL _kfb_v23_add_column('kfb_bookings', 'cc_company',    "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Optional — legacy Client Name becomes First Last | Company' AFTER `cc_last_name`");
CALL _kfb_v23_add_column('kfb_bookings', 'cc_phone',      "VARCHAR(50) NULL DEFAULT NULL AFTER `cc_company`");
CALL _kfb_v23_add_column('kfb_bookings', 'cc_email',      "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Customer-facing emails go here' AFTER `cc_phone`");

DROP PROCEDURE _kfb_v23_add_column;
