-- ============================================================
-- Kafeh Booking — v16 migration
--   Replace the 3-region (Chicago/America/Worldwide) vehicle rate
--   matrix with a distance-from-garage pricing model:
--     - kfb_vehicles: one local $/mile rate + one local $/hour rate
--       per vehicle (region-scoped rate columns dropped — regional/
--       long-distance/worldwide prices are now derived from the local
--       rate via global multipliers, computed by the new Pricing_engine
--       library, not stored per vehicle).
--     - kfb_settings: new pricing_* global knobs (service radius,
--       travel fee, multipliers, hourly minimums, gratuity, garage
--       coordinates, worldwide quote threshold).
--     - kfb_surcharges: new admin-manageable surcharge catalog,
--       replacing the old per-vehicle surcharge/gratuity/waiting/
--       child-seat columns and the old global meet_greet_* settings.
--     - kfb_bookings: new columns to persist the actual price
--       breakdown (zone, travel fee, surcharges total, gratuity) —
--       previously only the final `amount` was ever stored.
--
--   Why this matters: the customer rejected the Chicago/America/
--   Worldwide region model entirely in favor of a 75-mile-radius
--   local/regional/long-distance/worldwide zone model measured from
--   the company garage. See Pricing_engine.php for the formulas this
--   data feeds.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v16.sql
--
-- Idempotent: every column add/drop/rename is guarded by
-- information_schema checks; table creates use IF NOT EXISTS; settings
-- seed uses INSERT IGNORE (kfb_settings.setting_key is a PRIMARY KEY);
-- surcharge seed uses INSERT IGNORE (kfb_surcharges.code is UNIQUE).
-- Safe to re-run.
-- ============================================================

USE `kafeh`;

-- ----------------- 0) Back up the old per-vehicle rate matrix -----------------
-- 6 real vehicles have manually-tuned rates across 20 columns that are about
-- to be dropped. This preserves every original value permanently, queryable
-- by an admin, even though the migration's own backfill (below) only
-- automatically carries forward the Chicago-region numbers.
CREATE TABLE IF NOT EXISTS `kfb_vehicles_rates_backup_v16` LIKE `kfb_vehicles`;

INSERT INTO `kfb_vehicles_rates_backup_v16`
SELECT * FROM `kfb_vehicles`
WHERE NOT EXISTS (SELECT 1 FROM `kfb_vehicles_rates_backup_v16` LIMIT 1);

-- ----------------- Helper procedures -----------------
DELIMITER $$

DROP PROCEDURE IF EXISTS _kfb_v16_add_column $$
CREATE PROCEDURE _kfb_v16_add_column(
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

DROP PROCEDURE IF EXISTS _kfb_v16_drop_column $$
CREATE PROCEDURE _kfb_v16_drop_column(
  IN p_table  VARCHAR(64),
  IN p_column VARCHAR(64)
)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` DROP COLUMN `', p_column, '`');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$

DROP PROCEDURE IF EXISTS _kfb_v16_rename_column $$
CREATE PROCEDURE _kfb_v16_rename_column(
  IN p_table   VARCHAR(64),
  IN p_old_col VARCHAR(64),
  IN p_new_col VARCHAR(64),
  IN p_new_def TEXT
)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_old_col
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_new_col
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` CHANGE COLUMN `', p_old_col, '` `', p_new_col, '` ', p_new_def);
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$

DELIMITER ;

-- ----------------- 1) kfb_vehicles: add new local rate columns -----------------
CALL _kfb_v16_add_column('kfb_vehicles', 'local_per_mile_rate',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'The one $/mile rate — regional/long-distance/worldwide are derived from this via global multipliers' AFTER `luggage_capacity`");
CALL _kfb_v16_add_column('kfb_vehicles', 'local_hourly_rate',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'The one $/hour rate for local hourly service' AFTER `local_per_mile_rate`");
CALL _kfb_v16_add_column('kfb_vehicles', 'local_hourly_min_hours',
  "DECIMAL(5,2) NULL COMMENT 'Per-vehicle override of the global local hourly minimum (kfb_settings.pricing_local_hourly_minimum_hours) — NULL means use the global default' AFTER `local_hourly_rate`");

-- ----------------- 2) kfb_vehicles: rename min_fare -> local_min_fare -----------------
-- The new model has multiple distinct minimums (hourly-hours minimums, the
-- regional MAX() floor), so a bare `min_fare` is now ambiguous.
CALL _kfb_v16_rename_column('kfb_vehicles', 'min_fare', 'local_min_fare',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Local point-to-point minimum charge — MAX(route_miles * local_per_mile_rate, local_min_fare)'");

-- ----------------- 3) kfb_vehicles: backfill the new columns -----------------
-- Only runs while the old per_mile_chicago/hourly_chicago columns still exist
-- (i.e. this migration hasn't completed on this database yet) — keeps the
-- whole file safe to re-run even after the columns below are dropped.
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v16_backfill_vehicle_rates $$
CREATE PROCEDURE _kfb_v16_backfill_vehicle_rates()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_vehicles' AND column_name = 'per_mile_chicago'
  ) THEN
    -- Exact seed rates from the customer's spec for the 6 stock vehicles,
    -- matched by name regardless of what their old rates happened to be.
    UPDATE `kfb_vehicles` SET local_per_mile_rate=3.00,  local_min_fare=119.00, local_hourly_rate=125.00, local_hourly_min_hours=4 WHERE LOWER(`name`)='sedan';
    UPDATE `kfb_vehicles` SET local_per_mile_rate=3.50,  local_min_fare=139.00, local_hourly_rate=150.00, local_hourly_min_hours=4 WHERE LOWER(`name`)='suv';
    UPDATE `kfb_vehicles` SET local_per_mile_rate=4.00,  local_min_fare=159.00, local_hourly_rate=175.00, local_hourly_min_hours=4 WHERE LOWER(`name`)='van';
    UPDATE `kfb_vehicles` SET local_per_mile_rate=5.00,  local_min_fare=199.00, local_hourly_rate=200.00, local_hourly_min_hours=4 WHERE LOWER(`name`)='stretch limo';
    UPDATE `kfb_vehicles` SET local_per_mile_rate=17.00, local_min_fare=695.00, local_hourly_rate=350.00, local_hourly_min_hours=4 WHERE LOWER(`name`)='party bus';
    UPDATE `kfb_vehicles` SET local_per_mile_rate=14.00, local_min_fare=695.00, local_hourly_rate=275.00, local_hourly_min_hours=4 WHERE LOWER(`name`)='charter bus';

    -- Any other (custom, admin-added) vehicle: carry over its Chicago-region
    -- rate as the new local rate. local_min_fare already got the old min_fare
    -- value via the rename above. Flag for manual admin review post-migration
    -- if this vehicle's America/Worldwide rates meaningfully diverged from its
    -- Chicago rate — the original values remain queryable in
    -- kfb_vehicles_rates_backup_v16.
    UPDATE `kfb_vehicles`
      SET local_per_mile_rate = per_mile_chicago,
          local_hourly_rate   = hourly_chicago
      WHERE LOWER(`name`) NOT IN ('sedan','suv','van','stretch limo','party bus','charter bus');
  END IF;
END $$
DELIMITER ;

CALL _kfb_v16_backfill_vehicle_rates();
DROP PROCEDURE _kfb_v16_backfill_vehicle_rates;

-- ----------------- 4) kfb_vehicles: drop the obsolete region-rate matrix -----------------
CALL _kfb_v16_drop_column('kfb_vehicles', 'hourly_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'hourly_america');
CALL _kfb_v16_drop_column('kfb_vehicles', 'hourly_worldwide');
CALL _kfb_v16_drop_column('kfb_vehicles', 'per_mile_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'per_mile_america');
CALL _kfb_v16_drop_column('kfb_vehicles', 'per_mile_worldwide');
CALL _kfb_v16_drop_column('kfb_vehicles', 'surcharge_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'surcharge_america');
CALL _kfb_v16_drop_column('kfb_vehicles', 'surcharge_worldwide');
CALL _kfb_v16_drop_column('kfb_vehicles', 'gratuity_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'gratuity_america');
CALL _kfb_v16_drop_column('kfb_vehicles', 'gratuity_worldwide');
CALL _kfb_v16_drop_column('kfb_vehicles', 'waiting_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'waiting_america');
CALL _kfb_v16_drop_column('kfb_vehicles', 'waiting_worldwide');
CALL _kfb_v16_drop_column('kfb_vehicles', 'child_seat_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'child_seat_america');
CALL _kfb_v16_drop_column('kfb_vehicles', 'child_seat_worldwide');
CALL _kfb_v16_drop_column('kfb_vehicles', 'meet_greet_chicago');
CALL _kfb_v16_drop_column('kfb_vehicles', 'meet_greet_elsewhere');

-- ----------------- 5) kfb_bookings: persist the actual price breakdown -----------------
-- Previously only the final `amount` was ever stored — these let a saved
-- booking's price be reconstructed instead of just trusting the total.
-- NULL/0 on pre-v16 rows means "priced under the legacy region model", not
-- "zero surcharges were applied".
CALL _kfb_v16_add_column('kfb_bookings', 'pricing_zone',
  "VARCHAR(20) NULL COMMENT 'local | regional | long_distance | worldwide — NULL on bookings priced before v16' AFTER `min_fare_applied`");
CALL _kfb_v16_add_column('kfb_bookings', 'travel_fee_amount',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Regional travel fee actually charged (miles outside the local radius x pricing_regional_travel_fee_per_mile)' AFTER `pricing_zone`");
CALL _kfb_v16_add_column('kfb_bookings', 'surcharges_total_amount',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all applied kfb_surcharges line items' AFTER `travel_fee_amount`");
CALL _kfb_v16_add_column('kfb_bookings', 'gratuity_pct',
  "DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Gratuity percent applied at booking time (kfb_settings.pricing_default_gratuity_pct at that moment)' AFTER `surcharges_total_amount`");
CALL _kfb_v16_add_column('kfb_bookings', 'gratuity_amount',
  "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Gratuity dollar amount actually charged' AFTER `gratuity_pct`");

-- ----------------- Cleanup -----------------
DROP PROCEDURE _kfb_v16_add_column;
DROP PROCEDURE _kfb_v16_drop_column;
DROP PROCEDURE _kfb_v16_rename_column;

-- ----------------- 6) New surcharges catalog -----------------
CREATE TABLE IF NOT EXISTS `kfb_surcharges` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`          VARCHAR(40)  NOT NULL COMMENT 'Stable identifier, e.g. AIRPORT_FEE — referenced by Pricing_engine, not the display name',
  `name`          VARCHAR(120) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `pricing_type`  ENUM('flat','percent') NOT NULL DEFAULT 'flat' COMMENT 'flat = $ amount; percent = % of (transportation + travel fee)',
  `amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `auto_trigger`  VARCHAR(40)  NULL COMMENT 'NULL = manual selection only; else a Pricing_engine-recognized trigger key (airport | airport_meet_greet)',
  `sort_order`    INT NOT NULL DEFAULT 0,
  `status`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL,
  `updated_at`    DATETIME NULL,
  UNIQUE KEY `uniq_surcharge_code` (`code`),
  KEY `idx_surcharge_status` (`status`),
  KEY `idx_surcharge_sort`   (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only the two surcharges with known, pre-existing trigger logic ship
-- funded/enabled (Meet & Greet replaces the old kfb_settings.meet_greet_*
-- mechanism). Everything else ships disabled at $0.00 so nothing starts
-- charging real customers a fee nobody has configured a real number for.
INSERT IGNORE INTO `kfb_surcharges`
  (`code`, `name`, `description`, `pricing_type`, `amount`, `auto_trigger`, `sort_order`, `status`, `created_at`)
VALUES
  ('AIRPORT_FEE',              'Airport fee',              NULL, 'flat', 0.00,  'airport',            10, 0, NOW()),
  ('FUEL_SURCHARGE',           'Fuel surcharge',           NULL, 'flat', 0.00,  NULL,                 20, 0, NOW()),
  ('TOLL',                     'Toll',                     NULL, 'flat', 0.00,  NULL,                 30, 0, NOW()),
  ('PARKING',                  'Parking',                  NULL, 'flat', 0.00,  NULL,                 40, 0, NOW()),
  ('MEET_GREET',               'Meet & greet',             NULL, 'flat', 65.00, 'airport_meet_greet', 50, 1, NOW()),
  ('ADDITIONAL_STOP',          'Additional stop',          NULL, 'flat', 0.00,  NULL,                 60, 0, NOW()),
  ('WAITING_TIME',             'Waiting time',              NULL, 'flat', 0.00,  NULL,                 70, 0, NOW()),
  ('HOLIDAY_SURCHARGE',        'Holiday surcharge',        NULL, 'flat', 0.00,  NULL,                 80, 0, NOW()),
  ('SPECIAL_EVENT_SURCHARGE',  'Special event surcharge',  NULL, 'flat', 0.00,  NULL,                 90, 0, NOW()),
  ('OTHER_FEES',               'Other fees',               NULL, 'flat', 0.00,  NULL,                100, 0, NOW());

-- ----------------- 7) New global pricing settings -----------------
-- kfb_settings already has rows in production, so the "seed only if empty"
-- trick from v5 isn't safe here — INSERT IGNORE relies on setting_key being
-- the PRIMARY KEY instead.
INSERT IGNORE INTO `kfb_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('pricing_local_service_radius_miles',   '75.00',              NOW()),
  ('pricing_regional_travel_fee_per_mile', '1.50',               NOW()),
  ('pricing_long_distance_multiplier',     '3.00',               NOW()),
  ('pricing_worldwide_multiplier',         '3.00',               NOW()),
  ('pricing_local_hourly_minimum_hours',   '4.00',               NOW()),
  ('pricing_regional_hourly_minimum_hours','5.00',               NOW()),
  ('pricing_worldwide_hourly_minimum_hours','5.00',              NOW()),
  ('pricing_default_gratuity_pct',         '20.00',              NOW()),
  ('pricing_garage_lat',                   '41.98429380078823',  NOW()),
  ('pricing_garage_lng',                   '-87.9099405503354',  NOW()),
  ('pricing_worldwide_quote_threshold_miles', '5000.00',         NOW());
