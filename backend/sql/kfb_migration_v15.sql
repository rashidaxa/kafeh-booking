-- ============================================================
-- Kafeh Booking — v15 migration
--   Email verification (OTP) before a customer account is loginable.
--
--   - kfb_customers.email_verified_at: NULL until the customer proves
--     they own the email by entering the OTP sent to it. Login is
--     refused until this is set (see Customer_model::authenticate()).
--   - kfb_customer_email_otps: 6-digit codes, stored hashed (SHA-256,
--     same pattern as bearer tokens/password resets), short-lived
--     (10 min), with an attempt counter to blunt brute-forcing a
--     6-digit space.
--
--   Why this matters: without it, anyone could register an account
--   using someone else's email address (no proof of ownership), which
--   both impersonates that person and — since kfb_bookings.customer_id
--   links by email — could expose another customer's real reservations
--   to whoever squatted their email first.
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v15.sql
--
-- Idempotent: guarded by information_schema checks / CREATE TABLE IF
-- NOT EXISTS, safe to re-run.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v15_add_email_verified_col $$
CREATE PROCEDURE _kfb_v15_add_email_verified_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_customers' AND column_name = 'email_verified_at'
  ) THEN
    ALTER TABLE `kfb_customers`
      ADD COLUMN `email_verified_at` DATETIME NULL COMMENT 'NULL until the OTP sent at registration is verified — login is refused until then' AFTER `password_hash`;
  END IF;
END $$
DELIMITER ;

CALL _kfb_v15_add_email_verified_col();

DROP PROCEDURE _kfb_v15_add_email_verified_col;

CREATE TABLE IF NOT EXISTS `kfb_customer_email_otps` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NOT NULL,
  `otp_hash`    CHAR(64)     NOT NULL COMMENT 'SHA-256 of the 6-digit code',
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failed verify attempts — locked out at 5, must resend',
  `created_at`  DATETIME     NOT NULL,
  `expires_at`  DATETIME     NOT NULL COMMENT '10 minutes from creation',
  `used_at`     DATETIME     NULL COMMENT 'Single-use — set once successfully verified',
  KEY `idx_otp_customer` (`customer_id`),
  CONSTRAINT `fk_otp_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `kfb_customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
