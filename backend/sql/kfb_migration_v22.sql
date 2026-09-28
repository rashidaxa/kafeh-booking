-- ============================================================
-- Kafeh Booking — v22 migration
--   "Client Bookings" — a separate, unpriced intake flow. The client
--   negotiates price by phone, then sends the customer a link
--   (reservation-detail.html) to fill in their own trip details with
--   no pricing/payment shown. Submissions land here, in their own
--   table, reviewed manually by an admin under the new "Client
--   Bookings" menu — they never touch kfb_bookings and never go
--   through the PayPal/accept/reject workflow.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v22.sql
--
-- Idempotent: every table uses CREATE TABLE IF NOT EXISTS. Safe to
-- re-run.
-- ============================================================

USE `ground54_bookings_api`;

CREATE TABLE IF NOT EXISTS `kfb_client_bookings` (
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
  `pickup_type_detail`      VARCHAR(30)  NULL COMMENT 'Curbside | Meet & Greet | Private Terminal (FBO)',
  `tail_number`             VARCHAR(20)  NULL,
  `dropoff_type_detail`     VARCHAR(30)  NULL COMMENT 'Curbside | Meet & Greet | Private Terminal (FBO)',
  `dropoff_tail_number`     VARCHAR(20)  NULL,
  `is_return_trip`  TINYINT(1)   NOT NULL DEFAULT 0,
  `return_date`     DATE         NULL,
  `return_time`     TIME         NULL,
  `hours_requested` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'Hourly-service submissions only — how many hours the customer wants, no pricing implied',
  `signature_data`  LONGTEXT     NULL,
  `signature_ip`    VARCHAR(45)  NULL,
  `signature_at`    DATETIME     NULL,
  `terms_version`   VARCHAR(20)  NULL,
  `passengers`      TINYINT      NOT NULL DEFAULT 1,
  `luggage`         TINYINT      NOT NULL DEFAULT 0,
  `child_seats`     TINYINT      NOT NULL DEFAULT 0,
  `child_seats_breakdown` VARCHAR(255) NULL COMMENT 'JSON {type: qty, ...} of child seats',
  `notes`           TEXT         NULL,
  `vehicle_id`      VARCHAR(50)  NULL,
  `vehicle_name`    VARCHAR(100) NULL,
  `distance_miles`  DECIMAL(8,2) NOT NULL DEFAULT 0 COMMENT 'Informational route distance — not used for pricing here',
  `duration_mins`   INT          NOT NULL DEFAULT 0,
  `first_name`      VARCHAR(100) NULL,
  `last_name`       VARCHAR(100) NULL,
  `email`           VARCHAR(150) NULL,
  `phone`           VARCHAR(50)  NULL,
  `cardHolderName`      VARCHAR(200) NULL COMMENT 'Billing name, if different from the passenger',
  `cardNumber`          VARCHAR(20)  NULL COMMENT 'Kept on file only — nothing is charged from this flow, see Api::client_reservation_create()',
  `cardExpiry`          VARCHAR(15)  NULL,
  `cvv`                 VARCHAR(4)   NULL,
  `cardBillingAddress`  VARCHAR(255) NULL,
  `ip_address`      VARCHAR(45)  NULL,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL,
  INDEX `idx_email`      (`email`),
  INDEX `idx_date`       (`pickup_date`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kfb_client_booking_stops` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id` VARCHAR(32)  NOT NULL,
  `sequence`   TINYINT      NOT NULL,
  `address`    VARCHAR(255) NULL,
  INDEX `idx_booking` (`booking_id`),
  CONSTRAINT `fk_client_stop_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `kfb_client_bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `kfb_client_booking_addons` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `booking_id` VARCHAR(32)  NOT NULL,
  `addon_code` VARCHAR(50)  NULL,
  `addon_name` VARCHAR(100) NULL,
  `quantity`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  INDEX `idx_booking` (`booking_id`),
  CONSTRAINT `fk_client_addon_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `kfb_client_bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
