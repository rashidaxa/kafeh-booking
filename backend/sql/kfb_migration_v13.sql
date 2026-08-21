-- ============================================================
-- Kafeh Booking — v13 migration
--   Saved cards (display-only) — kfb_customer_cards
--
--   IMPORTANT: this table stores ONLY brand + last 4 digits + expiry +
--   an optional nickname. It never stores the full card number (PAN)
--   or CVV — those remain PCI-DSS "Cardholder Data" and are out of
--   scope for this application to persist. The full number is
--   received transiently when a card is added (same as the existing
--   per-booking card fields already do) purely to derive the brand
--   and last 4 digits, then discarded — never written to this table
--   or logged.
--
--   Because no PAN/CVV is stored, a "saved card" here is a display
--   label + default preference only. It cannot be charged directly;
--   the customer still enters the full number at checkout, same as
--   today. See Customer_model::add_card().
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v13.sql
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- ============================================================

USE `kafeh`;

CREATE TABLE IF NOT EXISTS `kfb_customer_cards` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `customer_id`   INT UNSIGNED NOT NULL,
  `nickname`      VARCHAR(60)  NULL,
  `card_brand`    VARCHAR(20)  NOT NULL COMMENT 'Visa / Mastercard / Amex / Discover / Card — detected from the number at add-time, never the number itself',
  `card_last4`    CHAR(4)      NOT NULL,
  `expiry_month`  TINYINT UNSIGNED NOT NULL,
  `expiry_year`   SMALLINT UNSIGNED NOT NULL,
  `is_default`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL,
  KEY `idx_card_customer` (`customer_id`),
  CONSTRAINT `fk_card_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `kfb_customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
