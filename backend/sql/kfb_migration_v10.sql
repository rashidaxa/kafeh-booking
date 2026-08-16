-- ============================================================
-- Kafeh Booking — v10 migration
--   Optional billing-details fields, collected on Step 3 when billing
--   differs from the passenger making the reservation.
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v10.sql
--
-- Idempotent: every column add is guarded.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v10_add_column $$
CREATE PROCEDURE _kfb_v10_add_column(
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

CALL _kfb_v10_add_column('kfb_bookings', 'billing_name',
  "VARCHAR(200) NULL COMMENT 'Billing name, if different from the passenger'");
CALL _kfb_v10_add_column('kfb_bookings', 'billing_contact',
  "VARCHAR(150) NULL COMMENT 'Billing phone or email'");
CALL _kfb_v10_add_column('kfb_bookings', 'secondary_contact',
  "VARCHAR(150) NULL COMMENT 'Additional phone or email contact'");
CALL _kfb_v10_add_column('kfb_bookings', 'street_number',
  "VARCHAR(50) NULL");
CALL _kfb_v10_add_column('kfb_bookings', 'billing_address',
  "VARCHAR(255) NULL");

DROP PROCEDURE _kfb_v10_add_column;
