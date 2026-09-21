-- ============================================================
-- Kafeh Booking — v19 migration
--   Per-vehicle garage deadhead fees, replacing the single global
--   "Regional travel fee per mile" setting.
--
--   Previously, a trip's price never accounted for how far the vehicle
--   itself has to drive from the garage to reach the pickup, or back to
--   the garage after the dropoff — a customer 50 miles from the garage
--   requesting a 2-mile ride only ever paid for those 2 miles, even
--   though the vehicle actually travels garage->pickup->dropoff->garage.
--   Two new per-vehicle $/mile rates fix this (different vehicles have
--   different fuel costs, so it can't be one global number):
--
--     - garage_pickup_fee_per_mile  ("Travel Fee Till Pickup")
--     - garage_dropoff_fee_per_mile ("Back To Garage Fee")
--
--   Billed against the real driving distance garage->pickup and
--   dropoff->garage (see the v19 client-side DistanceMatrix work from
--   the previous migration) — but ONLY for trips inside the local
--   service radius. Outside the radius, the existing long-distance/
--   worldwide multipliers apply instead, same as before, with no
--   separate fee — see Pricing_engine::_compute().
--
--   The old pricing_regional_travel_fee_per_mile setting (a flat $/mi
--   fee for the distance beyond the radius, regardless of vehicle) is
--   retired by this change but its row is left alone in kfb_settings —
--   nothing reads it anymore after this migration, no need to delete it.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v19.sql
--
-- Idempotent: both column adds are guarded by an information_schema
-- check. Safe to re-run.
-- ============================================================

USE `ground54_bookings_api`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v19_add_column $$
CREATE PROCEDURE _kfb_v19_add_column(
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

CALL _kfb_v19_add_column('kfb_vehicles', 'garage_pickup_fee_per_mile',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Travel Fee Till Pickup — $/mile, garage to pickup, inside-radius trips only' AFTER `local_min_fare`");
CALL _kfb_v19_add_column('kfb_vehicles', 'garage_dropoff_fee_per_mile',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Back To Garage Fee — $/mile, dropoff to garage, inside-radius trips only' AFTER `garage_pickup_fee_per_mile`");

DROP PROCEDURE _kfb_v19_add_column;
