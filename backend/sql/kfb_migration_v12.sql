-- ============================================================
-- Kafeh Booking — v12 migration
--   Customer login (bearer tokens), password reset, and reservation
--   editing:
--     - kfb_customer_tokens: opaque bearer-token store for customer
--       login (chosen over cookie sessions because the widget is
--       embedded cross-origin on third-party sites)
--     - kfb_password_resets: single-use password-reset tokens
--     - kfb_bookings.edited_by_customer_at / edit_count: lets the
--       admin portal show when a customer has edited a reservation
--       pre-acceptance
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v12.sql
--
-- Idempotent: new tables use CREATE TABLE IF NOT EXISTS; the new
-- kfb_bookings columns are guarded the same way kfb_migration_v11.sql
-- guards its rename.
-- ============================================================

USE `kafeh`;

-- ----------------- Customer bearer tokens -----------------
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

-- ----------------- Password reset tokens -----------------
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

-- ----------------- kfb_bookings: customer-edit audit -----------------
DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v12_add_edit_audit_cols $$
CREATE PROCEDURE _kfb_v12_add_edit_audit_cols()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_bookings' AND column_name = 'edited_by_customer_at'
  ) THEN
    ALTER TABLE `kfb_bookings`
      ADD COLUMN `edited_by_customer_at` DATETIME NULL COMMENT 'Set each time the customer edits this reservation pre-acceptance' AFTER `approved_by`,
      ADD COLUMN `edit_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'How many times the customer has edited this reservation' AFTER `edited_by_customer_at`;
  END IF;
END $$
DELIMITER ;

CALL _kfb_v12_add_edit_audit_cols();

DROP PROCEDURE _kfb_v12_add_edit_audit_cols;
