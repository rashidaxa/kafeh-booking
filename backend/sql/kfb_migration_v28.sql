-- ============================================================
-- Kafeh Booking — v28 migration
--   Manual price / payment-status edits from the admin portal.
--   Staff change prices and the paid/unpaid state of a reservation in the
--   legacy dispatch portal, and those edits never reached the customer's
--   profile or receipt. The admin can now edit them here:
--     price_override_json       {"base":..,"gratuity":..,"discount":..,"prices":{"meet-greet":..,...}}
--                               per-leg price lines; when set, they replace the
--                               quoted breakdown everywhere (receipt, email, sync).
--     payment_status_override   'paid' | 'unpaid' — NULL = automatic (derived from status).
--     pricing_edited_at         when the admin last saved a manual edit.
--
--   The application checks these columns exist before writing them, so the
--   code can be deployed before or after this migration.
--
-- Run on existing databases:
--   mysql -u root -p <database> < kfb_migration_v28.sql
--
-- Idempotent: guarded column adds. Safe to re-run.
-- ============================================================

USE `ground54_bookings_api`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v28_add_column $$
CREATE PROCEDURE _kfb_v28_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_def TEXT)
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

CALL _kfb_v28_add_column('kfb_bookings', 'price_override_json',
  "TEXT NULL COMMENT 'Admin manual price lines per leg — replaces the quoted breakdown when set (v28)'");
CALL _kfb_v28_add_column('kfb_bookings', 'payment_status_override',
  "VARCHAR(10) NULL COMMENT 'paid | unpaid set manually by admin; NULL = derived from status (v28)'");
CALL _kfb_v28_add_column('kfb_bookings', 'pricing_edited_at',
  "DATETIME NULL COMMENT 'When the admin last saved a manual price/payment edit (v28)'");

DROP PROCEDURE _kfb_v28_add_column;
