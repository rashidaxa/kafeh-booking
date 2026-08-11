-- ============================================================
-- Kafeh Booking — v6 migration
--   Stripe payments: authorize-on-submit / admin accept-or-reject,
--   saved card for future off-session charges
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v6.sql
--
-- Idempotent: every column add is guarded. The ENUM widenings
-- (status / event) are safe to re-run — MODIFY COLUMN is a no-op
-- if the target definition already matches.
-- ============================================================

USE `kafeh`;

-- ----------------- Helper procedure -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v6_add_column $$
CREATE PROCEDURE _kfb_v6_add_column(
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

-- ----------------- 1) Bookings: Stripe fields + approval audit -----------------
CALL _kfb_v6_add_column('kfb_bookings', 'stripe_customer_id',
  "VARCHAR(64) NULL COMMENT 'Stripe Customer id — card saved here for future off-session charges'");
CALL _kfb_v6_add_column('kfb_bookings', 'stripe_payment_method_id',
  "VARCHAR(64) NULL COMMENT 'Stripe PaymentMethod id of the saved card'");
CALL _kfb_v6_add_column('kfb_bookings', 'stripe_payment_intent_id',
  "VARCHAR(64) NULL COMMENT 'PaymentIntent for the original authorization/capture'");
CALL _kfb_v6_add_column('kfb_bookings', 'card_brand',
  "VARCHAR(20) NULL COMMENT 'visa | mastercard | amex | ... (display only)'");
CALL _kfb_v6_add_column('kfb_bookings', 'card_last4',
  "VARCHAR(4) NULL");
CALL _kfb_v6_add_column('kfb_bookings', 'approved_at',
  "DATETIME NULL COMMENT 'When an admin accepted or rejected this reservation'");
CALL _kfb_v6_add_column('kfb_bookings', 'approved_by',
  "VARCHAR(60) NULL COMMENT 'Admin username who accepted/rejected'");

-- Widen status to add 'awaiting_approval' (card authorized, waiting on admin
-- accept/reject). Existing values are unchanged.
ALTER TABLE `kfb_bookings`
  MODIFY COLUMN `status` ENUM('pending','awaiting_payment','awaiting_approval','paid','payment_failed','cancelled','refunded')
  NOT NULL DEFAULT 'pending';

-- ----------------- 2) Payments log: Stripe fields -----------------
CALL _kfb_v6_add_column('kfb_payments', 'provider',
  "VARCHAR(20) NOT NULL DEFAULT 'stripe' COMMENT 'stripe | paypal (legacy rows)'");
CALL _kfb_v6_add_column('kfb_payments', 'stripe_payment_intent_id',
  "VARCHAR(64) NULL");

-- Widen event to add the Stripe lifecycle events. Existing values are unchanged.
ALTER TABLE `kfb_payments`
  MODIFY COLUMN `event` ENUM('create','capture','refund','authorize','cancel','additional_charge') NOT NULL;

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v6_add_column;
