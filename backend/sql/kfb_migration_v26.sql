-- ============================================================
-- Kafeh Booking — v26 migration
--   PayPal on/off. Admin > Settings > Payments has a global switch
--   ("paypal_enabled", default ON = today's behaviour). When it is OFF a
--   new reservation skips PayPal completely (no order, authorization or
--   capture) and is created directly as "awaiting approval"; the admin
--   accepts/rejects it without any PayPal call.
--
--   kfb_bookings.payment_mode records how each reservation was booked, so
--   switching the setting later never breaks reservations already in the
--   system:  'paypal' (default, every existing row) or 'none'.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v26.sql
--
-- Idempotent: guarded column add + INSERT IGNORE. Safe to re-run.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v26_add_column $$
CREATE PROCEDURE _kfb_v26_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
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

CALL _kfb_v26_add_column('kfb_bookings', 'payment_mode',
  "ENUM('paypal','none') NOT NULL DEFAULT 'paypal' COMMENT 'paypal = booked through PayPal authorize/capture; none = booked while PayPal was switched off (admin accepts with no payment step)' AFTER `status`");

DROP PROCEDURE _kfb_v26_add_column;

INSERT IGNORE INTO `kfb_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('paypal_enabled', '1', NOW());
