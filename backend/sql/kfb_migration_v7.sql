-- ============================================================
-- Kafeh Booking — v7 migration
--   Revert payment provider: Stripe → PayPal (Payments Pro / classic
--   NVP API — DoDirectPayment / DoCapture / DoVoid / DoReferenceTransaction)
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v7.sql
--
-- Idempotent: every column add is guarded. Existing stripe_* columns
-- and data are left in place (unused/legacy), not dropped — same
-- non-destructive pattern used when Stripe was added in v6.
-- ============================================================

USE `kafeh`;

-- ----------------- Helper procedure -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v7_add_column $$
CREATE PROCEDURE _kfb_v7_add_column(
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

-- ----------------- 1) Bookings: PayPal transaction ids -----------------
CALL _kfb_v7_add_column('kfb_bookings', 'paypal_auth_transaction_id',
  "VARCHAR(32) NULL COMMENT 'DoDirectPayment Authorization id — used for DoCapture/DoVoid'");
CALL _kfb_v7_add_column('kfb_bookings', 'paypal_capture_transaction_id',
  "VARCHAR(32) NULL COMMENT 'DoCapture id — used as the reference for later DoReferenceTransaction charges'");

-- ----------------- 2) Payments log: PayPal transaction id -----------------
CALL _kfb_v7_add_column('kfb_payments', 'paypal_transaction_id',
  "VARCHAR(32) NULL");

-- New rows default to the paypal provider going forward.
ALTER TABLE `kfb_payments` MODIFY COLUMN `provider` VARCHAR(20) NOT NULL DEFAULT 'paypal';

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v7_add_column;
