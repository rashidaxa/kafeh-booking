-- ============================================================
-- Kafeh Booking — v17 migration
--   1) Late-Night / Early-Morning Pickup Fee — a new auto-triggering
--      row in kfb_surcharges (11:00 PM - 5:00 AM pickup), reusing the
--      existing surcharges catalog rather than a bespoke setting, so
--      it's editable on the same Surcharges admin page as everything
--      else and shows up automatically in the itemized "Surcharges"
--      line on the Trip Summary.
--   2) Child seat fee — a single global $/seat rate (kfb_settings),
--      same amount regardless of seat type, multiplied by the number
--      of seats requested. Reinstates child-seat pricing (dropped in
--      v16 pending this decision) as a dedicated Pricing_engine field
--      rather than folding it into kfb_surcharges, since it needs
--      quantity multiplication, which surcharges (flat-once or
--      percent-of-base) don't support.
--   3) kfb_bookings.child_seats_fee_amount — persists the actual child
--      seat fee charged, same audit pattern as v16's travel_fee_amount/
--      surcharges_total_amount/gratuity_amount (previously only the
--      final `amount` was stored).
--
--   See Surcharge_model::KNOWN_TRIGGERS and
--   Pricing_engine::_apply_surcharges()/_is_late_night_pickup() for the
--   code that makes AUTO_TRIGGER = 'late_night_pickup' actually work —
--   this migration only seeds the data.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v17.sql
--
-- Idempotent: settings seed uses INSERT IGNORE (kfb_settings.setting_key
-- is a PRIMARY KEY); surcharge seed uses INSERT IGNORE
-- (kfb_surcharges.code is UNIQUE); the column add is guarded by an
-- information_schema check. Safe to re-run.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v17_add_column $$
CREATE PROCEDURE _kfb_v17_add_column(
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

CALL _kfb_v17_add_column('kfb_bookings', 'child_seats_fee_amount',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Child seat fee actually charged (pricing_child_seat_fee x child_seats)' AFTER `gratuity_amount`");

DROP PROCEDURE _kfb_v17_add_column;

-- ----------------- 1) Late-Night / Early-Morning Pickup Fee -----------------
-- Ships ENABLED at $25 (unlike the other placeholder surcharges seeded in
-- v16, which ship disabled at $0) — the customer's spec asks for this fee
-- to be live and applied automatically from the start, not configured
-- in later by an admin.
INSERT IGNORE INTO `kfb_surcharges`
  (`code`, `name`, `description`, `pricing_type`, `amount`, `auto_trigger`, `sort_order`, `status`, `created_at`)
VALUES
  ('LATE_NIGHT_FEE', 'Late-Night / Early-Morning Pickup Fee',
   'Applied automatically when the pickup time is between 11:00 PM and 5:00 AM.',
   'flat', 25.00, 'late_night_pickup', 55, 1, NOW());

-- ----------------- 2) Child seat fee (global setting) -----------------
INSERT IGNORE INTO `kfb_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('pricing_child_seat_fee', '15.00', NOW());
