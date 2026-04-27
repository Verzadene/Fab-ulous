-- FABulous v2 Migration
-- Run this against an existing fab_ulous database (after setup.sql)
-- Safe to re-run: all statements use IF NOT EXISTS / IF EXISTS guards.

USE fab_ulous;

-- ── 1. Extend role ENUM to include super_admin ────────────────────
ALTER TABLE accounts
    MODIFY COLUMN role ENUM('user','admin','super_admin') DEFAULT 'user';

-- ── 2. Friendships ────────────────────────────────────────────────
-- status: pending (request sent) | accepted | rejected (record deleted on rejection)
CREATE TABLE IF NOT EXISTS friendships (
    friendshipID INT AUTO_INCREMENT PRIMARY KEY,
    requesterID  INT NOT NULL,
    receiverID   INT NOT NULL,
    status       ENUM('pending','accepted') DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pair (requesterID, receiverID),
    FOREIGN KEY (requesterID) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (receiverID)  REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── 3. Notifications ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    notifID    INT AUTO_INCREMENT PRIMARY KEY,
    userID     INT NOT NULL,              -- recipient
    actor_id   INT NOT NULL,              -- who triggered the event
    type       ENUM('like','comment','friend_request','friend_accepted') NOT NULL,
    post_id    INT DEFAULT NULL,          -- relevant post (nullable for friend events)
    ref_id     INT DEFAULT NULL,          -- friendship ID for friend events
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userID)   REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── 4. Commission fields ──────────────────────────────────────────
-- commissions table already exists; add admin-facing fields if missing
ALTER TABLE commissions
    ADD COLUMN IF NOT EXISTS commission_name VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS admin_note TEXT DEFAULT NULL;

-- ── To promote an account to super_admin: ────────────────────────
-- UPDATE accounts SET role = 'super_admin' WHERE username = 'your_username';

-- ── Indexes for common lookups ────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_friendships_receiver  ON friendships (receiverID,  status);
CREATE INDEX IF NOT EXISTS idx_friendships_requester ON friendships (requesterID, status);
CREATE INDEX IF NOT EXISTS idx_notifications_user    ON notifications (userID, is_read);
