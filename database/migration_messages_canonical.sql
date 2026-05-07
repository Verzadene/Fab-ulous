-- migration_messages_canonical.sql
-- Renames legacy `sender_id` / `receiver_id` in fab_ulous_messages.messages to
-- the canonical `senderID` / `receiverID`. Idempotent -- checks information_schema
-- before each ALTER and skips if the canonical column is already in place.
--
-- Safe to run on:
--   * fresh installs (no-op)
--   * databases that predate the column rename (renames in place, preserves rows)
--   * databases already migrated (no-op)
--
-- Apply with:
--   mysql -u root < database/migration_messages_canonical.sql

USE `fab_ulous_messages`;

-- ----------------------------------------------------------------------------
-- 1. sender_id -> senderID
-- ----------------------------------------------------------------------------
SET @needs_rename := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'fab_ulous_messages'
    AND TABLE_NAME   = 'messages'
    AND COLUMN_NAME  = 'sender_id'
);
SET @sql := IF(
  @needs_rename > 0,
  'ALTER TABLE `messages` CHANGE COLUMN `sender_id` `senderID` INT(11) NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. receiver_id -> receiverID
-- ----------------------------------------------------------------------------
SET @needs_rename := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = 'fab_ulous_messages'
    AND TABLE_NAME   = 'messages'
    AND COLUMN_NAME  = 'receiver_id'
);
SET @sql := IF(
  @needs_rename > 0,
  'ALTER TABLE `messages` CHANGE COLUMN `receiver_id` `receiverID` INT(11) NOT NULL',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3. Ensure indexes exist on the canonical column names. CHANGE COLUMN
-- preserves existing indexes and updates their column reference, but if the
-- table never had indexes on these columns to begin with, add them now to
-- match setup_micro_dbs.sql.
-- ----------------------------------------------------------------------------
SET @has_sender_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = 'fab_ulous_messages'
    AND TABLE_NAME   = 'messages'
    AND COLUMN_NAME  = 'senderID'
);
SET @sql := IF(@has_sender_idx = 0,
  'ALTER TABLE `messages` ADD INDEX `senderID` (`senderID`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_receiver_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = 'fab_ulous_messages'
    AND TABLE_NAME   = 'messages'
    AND COLUMN_NAME  = 'receiverID'
);
SET @sql := IF(@has_receiver_idx = 0,
  'ALTER TABLE `messages` ADD INDEX `receiverID` (`receiverID`)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration_messages_canonical.sql complete' AS status;
