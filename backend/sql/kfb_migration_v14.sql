-- ============================================================
-- Kafeh Booking — v14 migration (corrective)
--   kfb_customer_cards had drifted from its intended design: a `cvv`
--   column was added and being written to, and `card_last4` had been
--   widened to VARCHAR(50) and was storing the FULL card number, not
--   just the last 4 digits. Both are PCI-DSS "Cardholder Data" —
--   storing CVV after authorization is banned outright with no
--   exceptions, and storing the full PAN puts this application in the
--   strictest compliance tier (SAQ D) for no functional benefit, since
--   nothing here ever charges from this table (see kfb_migration_v13.sql).
--
--   This migration:
--     1. Truncates card_last4 back down to the real last 4 digits for
--        any row where it had drifted to a longer value.
--     2. Drops the cvv column entirely (which deletes whatever was
--        stored in it).
--     3. Shrinks card_last4 back to CHAR(4).
--
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v14.sql
--
-- Idempotent: guarded by information_schema checks, safe to re-run.
-- ============================================================

USE `kafeh`;

UPDATE `kfb_customer_cards` SET `card_last4` = RIGHT(`card_last4`, 4) WHERE CHAR_LENGTH(`card_last4`) > 4;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v14_fix_customer_cards $$
CREATE PROCEDURE _kfb_v14_fix_customer_cards()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_customer_cards' AND column_name = 'cvv'
  ) THEN
    ALTER TABLE `kfb_customer_cards` DROP COLUMN `cvv`;
  END IF;

  IF (
    SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'kfb_customer_cards' AND column_name = 'card_last4'
  ) <> 4 THEN
    ALTER TABLE `kfb_customer_cards` MODIFY COLUMN `card_last4` CHAR(4) NOT NULL;
  END IF;
END $$
DELIMITER ;

CALL _kfb_v14_fix_customer_cards();

DROP PROCEDURE _kfb_v14_fix_customer_cards;
