-- ============================================================
-- Kafeh Booking — v20 migration
--   Fields needed only for syncing an accepted reservation into the
--   legacy management portal (localhost/bookingOld, "works" in
--   production) — see Legacy_reservations.php. None of these are read
--   anywhere else in this app; they exist purely to fill in that
--   system's own reservations-table columns accurately, since it
--   expects a base rate and (for hourly trips) an hours figure that
--   this app never previously stored on its own.
--
--   kfb_bookings.transportation_amount — one-way base rate (before
--   travel fee/surcharges/gratuity/add-ons/discount), the same figure
--   already shown to the customer as "Base Rate". Maps to the legacy
--   `reservations.p_total` (and, x1, `p_trip_rate` for point-to-point
--   trips — see Legacy_reservations.php).
--
--   kfb_bookings.hours_requested — hourly-service bookings only (NULL
--   for point-to-point); the hours the customer actually requested.
--   Maps to legacy `reservations.p_trip_min`/`p_trip_flat`.
--
--   kfb_vehicles.legacy_vehicle_type_id — which of the legacy
--   `reservations.vehicles` catalog rows (Sedan/SUV/Stretch Limo/...)
--   this vehicle corresponds to, admin-selected on the Vehicles page.
--   Replaces the old hardcoded 4-entry guess in
--   Legacy_reservations::$vehicle_map.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v20.sql
--
-- Idempotent: all three column adds are guarded by an
-- information_schema check. Safe to re-run.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v20_add_column $$
CREATE PROCEDURE _kfb_v20_add_column(
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

CALL _kfb_v20_add_column('kfb_bookings', 'transportation_amount',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'One-way base rate, for legacy portal sync only (p_total/p_trip_rate) — see Legacy_reservations.php' AFTER `amount`");
CALL _kfb_v20_add_column('kfb_bookings', 'hours_requested',
  "DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Hourly-service bookings only, for legacy portal sync only (p_trip_min/p_trip_flat) — see Legacy_reservations.php' AFTER `duration_mins`");
CALL _kfb_v20_add_column('kfb_vehicles', 'legacy_vehicle_type_id',
  "INT UNSIGNED NULL DEFAULT NULL COMMENT 'Matching row in the legacy portal''s reservations.vehicles catalog (Sedan/SUV/Stretch Limo/...) — see Legacy_reservations.php' AFTER `garage_dropoff_fee_per_mile`");

DROP PROCEDURE _kfb_v20_add_column;
