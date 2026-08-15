-- ============================================================
-- Kafeh Booking — v8 migration
--   Switch PayPal integration: classic NVP (DoDirectPayment, direct
--   card entry) → Orders v2 REST API (redirect checkout).
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v8.sql
--
-- Idempotent: the column add is guarded. paypal_auth_transaction_id /
-- paypal_capture_transaction_id are reused as-is (same role: the
-- authorization id and capture id used for capture/void), only their
-- meaning shifts from NVP ids to REST ids — no column changes needed
-- for those two.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v8_add_column $$
CREATE PROCEDURE _kfb_v8_add_column(
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

-- Order id created before redirecting to PayPal — the return-URL handler
-- checks this against PayPal's ?token= to make sure it's authorizing the
-- order it actually created for this booking.
CALL _kfb_v8_add_column('kfb_bookings', 'paypal_order_id',
  "VARCHAR(64) NULL COMMENT 'Orders v2 order id created before redirecting to PayPal — used to verify the return-URL callback'");

DROP PROCEDURE _kfb_v8_add_column;
