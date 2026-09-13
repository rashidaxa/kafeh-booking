-- ============================================================
-- Kafeh Booking — v18 migration
--   Adds an optional active date/time WINDOW to kfb_surcharges
--   (starts_at / ends_at, both DATETIME, both nullable) so a seasonal
--   surcharge — e.g. a Christmas surcharge — can be created ahead of
--   time and left to auto-apply only to trips whose pickup date/time
--   falls inside the window, without an admin having to manually
--   enable/disable it around the holiday.
--
--   NULL (the default, and the only state possible before this
--   migration) means "no restriction on that side" — a row with both
--   starts_at and ends_at NULL behaves exactly as it did before this
--   migration (auto-applies on every trip whenever its `auto_trigger`
--   condition matches, regardless of date).
--
--   See Surcharge_model::validate_form()/_build_row() and
--   Pricing_engine::_apply_surcharges() for the code that evaluates
--   the window against the trip's pickup date/time.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v18.sql
--
-- Idempotent: both column adds are guarded by an information_schema
-- check. Safe to re-run.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v18_add_column $$
CREATE PROCEDURE _kfb_v18_add_column(
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

CALL _kfb_v18_add_column('kfb_surcharges', 'starts_at',
  "DATETIME NULL DEFAULT NULL COMMENT 'Auto-trigger only applies to trips picking up on/after this date/time; NULL = no restriction' AFTER `auto_trigger`");
CALL _kfb_v18_add_column('kfb_surcharges', 'ends_at',
  "DATETIME NULL DEFAULT NULL COMMENT 'Auto-trigger only applies to trips picking up on/before this date/time; NULL = no restriction' AFTER `starts_at`");

DROP PROCEDURE _kfb_v18_add_column;
