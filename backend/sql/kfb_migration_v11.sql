-- ============================================================
-- Kafeh Booking — v11 migration
--   Distance-based pricing switches from kilometers to miles (the
--   widget now reads distance straight from the Maps API in miles,
--   instead of computing km and converting). `kfb_vehicles.per_km_*`
--   is renamed to `per_mile_*`, and existing rates are converted from
--   $/km to the equivalent $/mile (× 1.609344) so a vehicle's real
--   per-trip pricing is unchanged — only the unit of measurement is.
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v11.sql
--
-- Idempotent: skips entirely if per_mile_chicago already exists
-- (i.e. this migration already ran) or if per_km_chicago doesn't
-- exist (fresh install already created with per_mile_* columns).
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v11_migrate_per_km $$
CREATE PROCEDURE _kfb_v11_migrate_per_km()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_vehicles' AND column_name = 'per_km_chicago'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_vehicles' AND column_name = 'per_mile_chicago'
  ) THEN
    -- Convert stored $/km rates to the equivalent $/mile rate first,
    -- while the columns still have their old names.
    UPDATE `kfb_vehicles` SET
      `per_km_chicago`   = ROUND(`per_km_chicago`   * 1.609344, 2),
      `per_km_america`   = ROUND(`per_km_america`   * 1.609344, 2),
      `per_km_worldwide` = ROUND(`per_km_worldwide` * 1.609344, 2);

    ALTER TABLE `kfb_vehicles`
      CHANGE COLUMN `per_km_chicago`   `per_mile_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      CHANGE COLUMN `per_km_america`   `per_mile_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      CHANGE COLUMN `per_km_worldwide` `per_mile_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00;
  END IF;
END $$
DELIMITER ;

CALL _kfb_v11_migrate_per_km();

DROP PROCEDURE _kfb_v11_migrate_per_km;
