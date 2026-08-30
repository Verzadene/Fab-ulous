-- migration_posts_visibility.sql
--
-- Adds the `visibility` column to `fab_ulous_posts`.`posts` for installs created
-- before the Public / Friends Only feed feature. Idempotent — uses an
-- information_schema check before the ALTER, so it is safe to run against a
-- database that already has the column (fresh installs from
-- setup_micro_dbs.sql already include it and do not need this script).
--
-- Usage:
--   mysql -u root < database/migration_posts_visibility.sql

USE `fab_ulous_posts`;

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'fab_ulous_posts'
      AND TABLE_NAME = 'posts'
      AND COLUMN_NAME = 'visibility'
);

SET @ddl = IF(
    @column_exists = 0,
    "ALTER TABLE `posts` ADD COLUMN `visibility` ENUM('public','friends') NOT NULL DEFAULT 'friends' AFTER `image_url`",
    "SELECT 'visibility column already present, skipping' AS status"
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill: any existing rows created before this migration default to
-- 'friends' automatically via the column DEFAULT, so no UPDATE is needed.
