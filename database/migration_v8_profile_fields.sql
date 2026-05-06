-- FABulous Migration v8
-- Run via phpMyAdmin or: mysql -u root fab_ulous < migration_v8_profile_fields.sql
--
-- Changes:
--   1. Adds 'bio' column to the accounts table.

ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS bio VARCHAR(255) DEFAULT '';