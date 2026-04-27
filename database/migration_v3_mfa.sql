-- FABulous v3 Migration
-- Run this against the existing fab_ulous database.
-- This codebase now expects MFA columns on the accounts table.
-- It does NOT create the messages table; verify that table manually if you already use it.

USE fab_ulous;

ALTER TABLE accounts
    ADD COLUMN IF NOT EXISTS mfa_code VARCHAR(6) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS mfa_code_expires_at DATETIME DEFAULT NULL;

-- Optional quick check for the existing messages table:
-- SHOW TABLES LIKE 'messages';
-- DESCRIBE messages;
--
-- Expected messaging columns for the new PHP/AJAX code:
-- senderID
-- receiverID
-- message_text OR content
-- created_at OR timestamp
