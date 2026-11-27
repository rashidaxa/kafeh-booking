-- ============================================================
-- Kafeh Booking — v29 migration
--   Credit Card Holder ("client") details on kfb_client_bookings — the
--   "Email Booking" flow (email-booking.html). Step 3 of that page now
--   collects the same fields as the main widget: card holder first/last
--   name, company, phone and email, with the passenger as a separate
--   person (or the same, via the "same as card holder" checkbox).
--   Mirrors v23, which added the same columns to kfb_bookings.
--
-- Run on existing databases:
--   mysql -u root -p <database> < kfb_migration_v29.sql
--
-- Idempotent: every column add is guarded by an information_schema
-- check. Safe to re-run. Existing rows keep NULLs (they fall back to
-- the passenger's details wherever these are read).
-- ============================================================

USE `ground54_bookings_api`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v29_add_column $$
CREATE PROCEDURE _kfb_v29_add_column(
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

CALL _kfb_v29_add_column('kfb_client_bookings', 'cc_first_name', "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Credit card holder first name' AFTER `cardHolderName`");
CALL _kfb_v29_add_column('kfb_client_bookings', 'cc_last_name',  "VARCHAR(100) NULL DEFAULT NULL AFTER `cc_first_name`");
CALL _kfb_v29_add_column('kfb_client_bookings', 'cc_company',    "VARCHAR(150) NULL DEFAULT NULL AFTER `cc_last_name`");
CALL _kfb_v29_add_column('kfb_client_bookings', 'cc_phone',      "VARCHAR(50) NULL DEFAULT NULL AFTER `cc_company`");
CALL _kfb_v29_add_column('kfb_client_bookings', 'cc_email',      "VARCHAR(150) NULL DEFAULT NULL AFTER `cc_phone`");

DROP PROCEDURE _kfb_v29_add_column;
