-- FABulous Migration v5
-- Run via phpMyAdmin or: mysql -u root fab_ulous < migration_v5.sql
--
-- Changes:
--   1. Expands commissions.status ENUM (Accepted, Ongoing, Delayed)
--   2. Adds visibility_role to audit_log (super_admin-only entries stay hidden from regular admins)
--   3. Creates password_resets table for the forgot-password flow
--   4. Creates messages table (skip if it already exists)

-- 1. Expand commission status options.
--    Migrate 'In Progress' → 'Ongoing' BEFORE modifying the ENUM.
--    Without this UPDATE first, MySQL will blank out any existing 'In Progress'
--    rows when it removes that value from the ENUM.
UPDATE commissions SET status = 'Ongoing' WHERE status = 'In Progress';

ALTER TABLE commissions
  MODIFY COLUMN status
    ENUM('Pending','Accepted','Ongoing','Delayed','Completed','Cancelled')
    DEFAULT 'Pending';

-- 2. Audit log visibility
ALTER TABLE audit_log
  ADD COLUMN IF NOT EXISTS visibility_role
    ENUM('admin','super_admin') DEFAULT 'admin';

-- 3. Password reset tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(150) NOT NULL,
    reset_code  VARCHAR(10)  NOT NULL,
    expires_at  TIMESTAMP    NOT NULL,
    used        TINYINT(1)   DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_resets_email (email)
);

-- 4. Messages table
CREATE TABLE IF NOT EXISTS messages (
    messageID    INT AUTO_INCREMENT PRIMARY KEY,
    senderID     INT NOT NULL,
    receiverID   INT NOT NULL,
    message_text TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_messages_thread (senderID, receiverID),
    FOREIGN KEY (senderID)   REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (receiverID) REFERENCES accounts(id) ON DELETE CASCADE
);
