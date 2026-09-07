-- ============================================================
-- Kafeh Booking — MySQL schema (CodeIgniter 3)
-- Run once: mysql -u root -p kafeh < kfb_schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `kafeh`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `kafeh`;

-- ----------------- Bookings -----------------
CREATE TABLE IF NOT EXISTS `kfb_bookings` (
  `booking_id`      VARCHAR(32)  NOT NULL PRIMARY KEY,
  `service_type`    VARCHAR(50)  NULL,
  `pickup_date`     DATE         NULL,
  `pickup_time`     TIME         NULL,
  `pickup_loc_type` VARCHAR(20)  NULL COMMENT 'Search All | Address | Airport | Landmark',
  `dropoff_loc_type`VARCHAR(20)  NULL,
  `pickup`          VARCHAR(255) NULL,
  `dropoff`         VARCHAR(255) NULL,
  `airline`         VARCHAR(100) NULL COMMENT 'When pickup loc type = Airport',
  `flight_number`   VARCHAR(20)  NULL,
  `arrival_time`    TIME         NULL,
  `pickup_point`    VARCHAR(50)  NULL COMMENT 'Baggage claim / Curbside / Gate / Arrivals hall',
  `dropoff_airline`         VARCHAR(100) NULL COMMENT 'When dropoff loc type = Airport',
  `dropoff_flight_number`   VARCHAR(20)  NULL,
  `dropoff_arrival_time`    TIME         NULL,
  `dropoff_pickup_point`    VARCHAR(50)  NULL COMMENT 'Curb / Terminal / Departure hall when dropoff loc type = Airport',
  `pickup_type_detail`     VARCHAR(30)  NULL COMMENT 'Curbside | Meet & Greet | Private Terminal (FBO)',
  `tail_number`             VARCHAR(20)  NULL,
  `dropoff_type_detail`     VARCHAR(30)  NULL COMMENT 'Curbside | Meet & Greet | Private Terminal (FBO)',
  `dropoff_tail_number`     VARCHAR(20)  NULL,
  `addons_json`             TEXT         NULL,
  `addons_total`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_fare_applied`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pricing_zone`            VARCHAR(20)  NULL COMMENT 'local | regional | long_distance | worldwide — NULL on bookings priced before v16',
  `travel_fee_amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `surcharges_total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gratuity_pct`            DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `gratuity_amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_return_trip`          TINYINT(1) NOT NULL DEFAULT 0,
  `return_booking_id`       VARCHAR(32)  NULL,
  `return_date`             DATE         NULL,
  `return_time`             TIME         NULL,
  `signature_data`          LONGTEXT     NULL,
  `signature_ip`            VARCHAR(45)  NULL,
  `signature_at`            DATETIME     NULL,
  `terms_version`           VARCHAR(20)  NULL,
  `customer_id`             INT UNSIGNED NULL,
  `stripe_customer_id`       VARCHAR(64)  NULL COMMENT 'Legacy — Stripe is no longer used, kept for historical rows',
  `stripe_payment_method_id` VARCHAR(64)  NULL COMMENT 'Legacy — Stripe is no longer used, kept for historical rows',
  `stripe_payment_intent_id` VARCHAR(64)  NULL COMMENT 'Legacy — Stripe is no longer used, kept for historical rows',
  `paypal_auth_transaction_id`    VARCHAR(32) NULL COMMENT 'Orders v2 authorization id (from POST /orders/{id}/authorize) — used for capture/void',
  `paypal_capture_transaction_id` VARCHAR(32) NULL COMMENT 'Orders v2 capture id',
  `card_brand`               VARCHAR(20)  NULL,
  `card_last4`               VARCHAR(4)   NULL,
  `approved_at`              DATETIME     NULL COMMENT 'When an admin accepted or rejected this reservation',
  `approved_by`              VARCHAR(60)  NULL COMMENT 'Admin username who accepted/rejected',
  `edited_by_customer_at`    DATETIME     NULL COMMENT 'Set each time the customer edits this reservation pre-acceptance',
  `edit_count`               TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'How many times the customer has edited this reservation',
  `passengers`      TINYINT      NOT NULL DEFAULT 1,
  `luggage`         TINYINT      NOT NULL DEFAULT 0,
  `child_seats`     TINYINT      NOT NULL DEFAULT 0,
  `child_seats_breakdown` VARCHAR(255) NULL COMMENT 'JSON {type: qty, ...} of child seats',
  `notes`           TEXT         NULL,
  `vehicle_id`      VARCHAR(50)  NULL,
  `vehicle_name`    VARCHAR(100) NULL,
  `distance_miles`  DECIMAL(8,2) NOT NULL DEFAULT 0,
  `duration_mins`   INT          NOT NULL DEFAULT 0,
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `promo_code`      VARCHAR(40)  NULL,
  `currency`        CHAR(3)      NOT NULL DEFAULT 'USD',
  `first_name`      VARCHAR(100) NULL,
  `last_name`       VARCHAR(100) NULL,
  `email`           VARCHAR(150) NULL,
  `phone`           VARCHAR(50)  NULL,
  `cardHolderName`      VARCHAR(200) NULL COMMENT 'Billing name, if different from the passenger',
  `cardNumber`   VARCHAR(20) NULL COMMENT 'Card number',
  `cardExpiry` VARCHAR(15) NULL COMMENT 'Card expiry date',
  `cvv`     VARCHAR(4)   NULL COMMENT 'Card verification value',
  `CardBillingAddress`   VARCHAR(255) NULL COMMENT 'Billing address for the card',
  `status`          ENUM('pending','awaiting_payment','awaiting_approval','paid','payment_failed','cancelled','refunded')
                    NOT NULL DEFAULT 'pending',
  `paypal_order_id` VARCHAR(64)  NULL COMMENT 'Orders v2 order id created before redirecting to PayPal — used to verify the return-URL callback',
  `ip_address`      VARCHAR(45)  NULL,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL,
  INDEX `idx_email`   (`email`),
  INDEX `idx_status`  (`status`),
  INDEX `idx_paypal`  (`paypal_order_id`),
  INDEX `idx_date`    (`pickup_date`),
  INDEX `idx_promo_code` (`promo_code`),
  INDEX `idx_return_booking` (`return_booking_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Stops -----------------
CREATE TABLE IF NOT EXISTS `kfb_stops` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id` VARCHAR(32)  NOT NULL,
  `sequence`   TINYINT      NOT NULL,
  `address`    VARCHAR(255) NULL,
  INDEX `idx_booking` (`booking_id`),
  CONSTRAINT `fk_stop_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `kfb_bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Payments -----------------
CREATE TABLE IF NOT EXISTS `kfb_payments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id`     VARCHAR(32)  NOT NULL,
  `paypal_order_id`VARCHAR(64)  NULL COMMENT 'Legacy — from the earlier REST-API PayPal integration, superseded by paypal_transaction_id',
  `provider`       VARCHAR(20)  NOT NULL DEFAULT 'paypal' COMMENT 'paypal | stripe (legacy rows)',
  `stripe_payment_intent_id` VARCHAR(64) NULL COMMENT 'Legacy — Stripe is no longer used, kept for historical rows',
  `paypal_transaction_id` VARCHAR(32) NULL,
  `event`          ENUM('create','capture','refund','authorize','cancel','additional_charge') NOT NULL,
  `status`         VARCHAR(40)  NULL,
  `amount`         DECIMAL(10,2) NULL,
  `currency`       CHAR(3)       NULL,
  `raw_response`   TEXT          NULL,
  `created_at`     DATETIME     NOT NULL,
  INDEX `idx_booking`  (`booking_id`),
  INDEX `idx_paypal`   (`paypal_order_id`),
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `kfb_bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Admins (backend portal login) -----------------
CREATE TABLE IF NOT EXISTS `kfb_admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(60)  NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name`  VARCHAR(120) NULL,
  `email`         VARCHAR(150) NULL,
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Promo codes (v3) -----------------
CREATE TABLE IF NOT EXISTS `kfb_promo_codes` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`           VARCHAR(40)  NOT NULL,
  `description`    VARCHAR(255) NULL,
  `discount_type`  ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Subtotal must be >= this to apply',
  `max_uses`       INT          NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `used_count`     INT          NOT NULL DEFAULT 0,
  `starts_at`      DATE         NULL,
  `expires_at`     DATE         NULL,
  `status`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0=disabled, 1=enabled',
  `created_at`     DATETIME     NOT NULL,
  `updated_at`     DATETIME     NULL,
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_status`  (`status`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Vehicles -----------------
CREATE TABLE IF NOT EXISTS `kfb_vehicles` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  -- General
  `status`           TINYINT(1)   NOT NULL DEFAULT 1,         -- 0=disabled, 1=enabled
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `code`             VARCHAR(50)  NULL,                       -- embed uses this as id (e.g. 'sedan')
  `name`             VARCHAR(100) NOT NULL,
  `description`      VARCHAR(500) NULL,
  `emoji`            VARCHAR(16)  NULL,                       -- small icon/emoji shown in card

  -- Passenger limits
  `min_passengers`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `max_passengers`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `luggage_capacity` TINYINT UNSIGNED NULL,

  -- Local rates (v16) — the single root $/mile and $/hour rate. Regional,
  -- long-distance and worldwide prices are DERIVED from these via global
  -- multipliers/travel fee in Pricing_engine, not stored per vehicle.
  `local_per_mile_rate`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `local_hourly_rate`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `local_hourly_min_hours` DECIMAL(5,2) NULL COMMENT 'Per-vehicle override of kfb_settings.pricing_local_hourly_minimum_hours — NULL means use the global default',

  -- Local point-to-point minimum charge (v4, renamed from min_fare in v16)
  -- — MAX(route_miles * local_per_mile_rate, local_min_fare)
  `local_min_fare`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Image (filename only — actual file lives in /uploads/vehicles/)
  `image`           VARCHAR(255) NULL,

  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL,

  INDEX `idx_status`     (`status`),
  INDEX `idx_sort`       (`sort_order`),
  INDEX `idx_code`       (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Surcharges (v16) -----------------
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

-- ----------------- Add-ons (v4) -----------------
CREATE TABLE IF NOT EXISTS `kfb_addons` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`           VARCHAR(40)  NOT NULL,
  `name`           VARCHAR(120) NOT NULL,
  `description`    VARCHAR(500) NULL,
  `category`       VARCHAR(40)  NULL COMMENT 'Red Carpet | Floral | Beverage | Decor | Other',
  `pricing_type`   ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  `price_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `image`          VARCHAR(255) NULL,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `status`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NULL,
  UNIQUE KEY `uniq_addon_code` (`code`),
  KEY `idx_addon_status` (`status`),
  KEY `idx_addon_sort`   (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Booking add-on line items (v4) -----------------
CREATE TABLE IF NOT EXISTS `kfb_booking_addons` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id`  VARCHAR(32)  NOT NULL,
  `addon_id`    INT UNSIGNED NOT NULL,
  `addon_code`  VARCHAR(40)  NOT NULL,
  `addon_name`  VARCHAR(120) NOT NULL,
  `quantity`    INT          NOT NULL DEFAULT 1,
  `unit_price`  DECIMAL(10,2) NOT NULL,
  `line_total`  DECIMAL(10,2) NOT NULL,
  `region`      VARCHAR(20)  NOT NULL,
  KEY `idx_ba_booking` (`booking_id`),
  KEY `idx_ba_addon`   (`addon_id`),
  CONSTRAINT `fk_ba_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `kfb_bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Customer accounts (v4, optional) -----------------
CREATE TABLE IF NOT EXISTS `kfb_customers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email`          VARCHAR(150) NOT NULL,
  `password_hash`  VARCHAR(255) NULL,
  `email_verified_at` DATETIME NULL COMMENT 'NULL until the OTP sent at registration is verified — login is refused until then (v15)',
  `first_name`     VARCHAR(100) NOT NULL,
  `last_name`      VARCHAR(100) NOT NULL,
  `phone`          VARCHAR(50)  NULL,
  `default_pickup` VARCHAR(255) NULL,
  `default_dropoff` VARCHAR(255) NULL,
  `total_bookings` INT NOT NULL DEFAULT 0,
  `last_booking_at` DATETIME NULL,
  `status`         ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME NOT NULL,
  `updated_at`     DATETIME NULL,
  UNIQUE KEY `uniq_customer_email` (`email`),
  KEY `idx_customer_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Email verification OTPs (v15) -----------------
CREATE TABLE IF NOT EXISTS `kfb_customer_email_otps` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NOT NULL,
  `otp_hash`    CHAR(64)     NOT NULL COMMENT 'SHA-256 of the 6-digit code',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failed verify attempts — locked out at 5, must resend',
  `created_at`  DATETIME     NOT NULL,
  `expires_at`  DATETIME     NOT NULL COMMENT '10 minutes from creation',
  `used_at`     DATETIME     NULL COMMENT 'Single-use',
  KEY `idx_otp_customer` (`customer_id`),
  CONSTRAINT `fk_otp_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `kfb_customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Customer bearer tokens (v12, login) -----------------
CREATE TABLE IF NOT EXISTS `kfb_customer_tokens` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id`  INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL COMMENT 'SHA-256 of the raw token — the raw token is only ever returned to the client once, at login',
  `created_at`   DATETIME     NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `last_used_at` DATETIME     NULL,
  `revoked_at`   DATETIME     NULL COMMENT 'Set on logout or on password reset',
  `user_agent`   VARCHAR(255) NULL,
  `ip_address`   VARCHAR(45)  NULL,
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_token_customer` (`customer_id`),
  KEY `idx_token_expires` (`expires_at`),
  CONSTRAINT `fk_token_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `kfb_customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Password reset tokens (v12) -----------------
CREATE TABLE IF NOT EXISTS `kfb_password_resets` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64)     NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  `expires_at`  DATETIME     NOT NULL COMMENT '1 hour from creation',
  `used_at`     DATETIME     NULL COMMENT 'Single-use — set once the token is consumed',
  UNIQUE KEY `uniq_reset_token_hash` (`token_hash`),
  KEY `idx_reset_customer` (`customer_id`),
  CONSTRAINT `fk_reset_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `kfb_customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Saved cards, display-only (v13) -----------------
-- Brand + last 4 + expiry + nickname ONLY — never the full number or
-- CVV. See kfb_migration_v13.sql for why.
CREATE TABLE IF NOT EXISTS `kfb_customer_cards` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id`   INT UNSIGNED NOT NULL,
  `nickname`      VARCHAR(60)  NULL,
  `card_brand`    VARCHAR(20)  NOT NULL,
  `card_last4`    CHAR(20)      NOT NULL,
  `cvv`   CHAR(4)      NOT NULL,
  `expiry_month`  TINYINT UNSIGNED NOT NULL,
  `expiry_year`   SMALLINT UNSIGNED NOT NULL,
  `is_default`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL,
  KEY `idx_card_customer` (`customer_id`),
  CONSTRAINT `fk_card_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `kfb_customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------- Global settings (v5, key/value) -----------------
CREATE TABLE IF NOT EXISTS `kfb_settings` (
  `setting_key`   VARCHAR(64)  NOT NULL PRIMARY KEY,
  `setting_value` VARCHAR(255) NULL,
  `updated_at`    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `kfb_settings` (`setting_key`, `setting_value`, `updated_at`)
SELECT * FROM (
  SELECT 'meet_greet_chicago'   AS setting_key, '65.00' AS setting_value, NOW() AS updated_at
  UNION ALL SELECT 'meet_greet_elsewhere', '95.00', NOW()
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `kfb_settings` LIMIT 1);

-- ----------------- Global pricing settings (v16) -----------------
INSERT IGNORE INTO `kfb_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('pricing_local_service_radius_miles',    '75.00',              NOW()),
  ('pricing_regional_travel_fee_per_mile',  '1.50',               NOW()),
  ('pricing_long_distance_multiplier',      '3.00',               NOW()),
  ('pricing_worldwide_multiplier',          '3.00',               NOW()),
  ('pricing_local_hourly_minimum_hours',    '4.00',               NOW()),
  ('pricing_regional_hourly_minimum_hours', '5.00',               NOW()),
  ('pricing_worldwide_hourly_minimum_hours','5.00',               NOW()),
  ('pricing_default_gratuity_pct',          '20.00',              NOW()),
  ('pricing_garage_lat',                    '41.98429380078823',  NOW()),
  ('pricing_garage_lng',                    '-87.9099405503354',  NOW()),
  ('pricing_worldwide_quote_threshold_miles','5000.00',           NOW());
