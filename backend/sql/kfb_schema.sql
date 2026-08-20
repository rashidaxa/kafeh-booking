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

  -- Hourly rates (per service region)
  `hourly_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hourly_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hourly_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Per-mile rates (per service region)
  `per_mile_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `per_mile_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `per_mile_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Surcharge (per service region, PERCENT of the base fare, e.g. 20.00 = 20%)
  `surcharge_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `surcharge_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `surcharge_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Gratuity (per service region, PERCENT of the base fare, e.g. 20.00 = 20%)
  `gratuity_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gratuity_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gratuity_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Waiting time (per minute, per service region)
  `waiting_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `waiting_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `waiting_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Child-seat surcharge (flat fee per child seat, per service region)
  `child_seat_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `child_seat_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `child_seat_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Minimum fare (v4) — bill at least this amount regardless of distance
  `min_fare`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Meet & Greet fee (v4) — DEPRECATED as of v5: the fee is now a single
  -- global setting (see `kfb_settings`), not per-vehicle. Columns kept
  -- for backward compatibility but no longer read or written.
  `meet_greet_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 65.00,
  `meet_greet_elsewhere` DECIMAL(10,2) NOT NULL DEFAULT 95.00,

  -- Image (filename only — actual file lives in /uploads/vehicles/)
  `image`           VARCHAR(255) NULL,

  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL,

  INDEX `idx_status`     (`status`),
  INDEX `idx_sort`       (`sort_order`),
  INDEX `idx_code`       (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
