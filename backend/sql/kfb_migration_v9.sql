-- ============================================================
-- Kafeh Booking — v9 migration
--   Indexes to support DB-level pagination on the admin reservations
--   list (LIMIT/OFFSET + ORDER BY created_at DESC, optionally filtered
--   by status) once the table grows into the tens/hundreds of
--   thousands of rows — without these, every page load does a full
--   table scan + filesort.
-- Run on existing databases:
--   mysql -u root -p kafeh < kfb_migration_v9.sql
--
-- Idempotent: each index add is guarded.
-- ============================================================

USE `kafeh`;

DELIMITER $$
DROP PROCEDURE IF EXISTS _kfb_v9_add_index $$
CREATE PROCEDURE _kfb_v9_add_index(
  IN p_table    VARCHAR(64),
  IN p_index    VARCHAR(64),
  IN p_columns  VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = p_table
      AND index_name    = p_index
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  END IF;
END $$
DELIMITER ;

-- Unfiltered admin list ("All") — ORDER BY created_at DESC straight off the index.
CALL _kfb_v9_add_index('kfb_bookings', 'idx_created_at', '`created_at`');

-- Status-filtered admin list — WHERE status = ? ORDER BY created_at DESC
-- served entirely from this composite index (no filesort).
CALL _kfb_v9_add_index('kfb_bookings', 'idx_status_created', '`status`, `created_at`');

DROP PROCEDURE _kfb_v9_add_index;
