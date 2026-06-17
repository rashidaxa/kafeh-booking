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
  `pickup`          VARCHAR(255) NULL,
  `dropoff`         VARCHAR(255) NULL,
  `passengers`      TINYINT      NOT NULL DEFAULT 1,
  `luggage`         TINYINT      NOT NULL DEFAULT 0,
  `child_seats`     TINYINT      NOT NULL DEFAULT 0,
  `notes`           TEXT         NULL,
  `vehicle_id`      VARCHAR(50)  NULL,
  `vehicle_name`    VARCHAR(100) NULL,
  `distance_miles`  DECIMAL(8,2) NOT NULL DEFAULT 0,
  `duration_mins`   INT          NOT NULL DEFAULT 0,
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0,
  `currency`        CHAR(3)      NOT NULL DEFAULT 'USD',
  `first_name`      VARCHAR(100) NULL,
  `last_name`       VARCHAR(100) NULL,
  `email`           VARCHAR(150) NULL,
  `phone`           VARCHAR(50)  NULL,
  `status`          ENUM('pending','awaiting_payment','paid','payment_failed','cancelled','refunded')
                    NOT NULL DEFAULT 'pending',
  `paypal_order_id` VARCHAR(64)  NULL,
  `ip_address`      VARCHAR(45)  NULL,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL,
  INDEX `idx_email`   (`email`),
  INDEX `idx_status`  (`status`),
  INDEX `idx_paypal`  (`paypal_order_id`),
  INDEX `idx_date`    (`pickup_date`)
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
  `paypal_order_id`VARCHAR(64)  NULL,
  `event`          ENUM('create','capture','refund') NOT NULL,
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

-- ----------------- Vehicles (admin-managed fleet) -----------------
-- One row per vehicle class shown in the booking wizard step 2.
-- All money / numeric fields use DECIMAL(10,2). Region columns use the
-- same three buckets as the booking widget (Chicago / America / Worldwide).
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

  -- Per-kilometer rates (per service region)
  `per_km_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `per_km_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `per_km_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Surcharges (per service region, currency units)
  `surcharge_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `surcharge_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `surcharge_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Gratuity (per service region, currency units)
  `gratuity_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gratuity_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gratuity_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Waiting time (per minute, per service region)
  `waiting_chicago`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `waiting_america`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `waiting_worldwide` DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  -- Image (filename only — actual file lives in /uploads/vehicles/)
  `image`           VARCHAR(255) NULL,

  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL,

  INDEX `idx_status`     (`status`),
  INDEX `idx_sort`       (`sort_order`),
  INDEX `idx_code`       (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
