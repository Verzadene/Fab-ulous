-- SQL Scripts for FAB-ulous Micro-Database Architecture
--
-- NOTE: This script splits the monolithic 'fab_ulous' database into 12 separate databases,
-- one for each table. This aligns with the Strangler Fig microservices migration plan.
--
-- IMPORTANT: MySQL does not support cross-database foreign key constraints.
-- All FOREIGN KEY declarations have been removed. Data integrity, referential constraints,
-- and cascading deletes/updates must now be handled explicitly at the PHP application layer,
-- primarily within the Repository classes (e.g., AdminRepository, PostRepository).

-- 1. Accounts Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_accounts` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_accounts`;
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `bio` varchar(255) DEFAULT NULL,
  `role` enum('user','admin','super_admin') NOT NULL DEFAULT 'user',
  `banned` tinyint(1) NOT NULL DEFAULT 0,
  `google_id` varchar(255) DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `mfa_code` varchar(6) DEFAULT NULL,
  `mfa_code_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `google_id` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Posts Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_posts` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_posts`;
CREATE TABLE IF NOT EXISTS `posts` (
  `postID` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `caption` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `visibility` enum('public','friends') NOT NULL DEFAULT 'friends', -- 'friends' = visible only to accepted friends (+ self); 'public' = visible platform-wide via the Public feed tab
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`postID`),
  KEY `userID` (`userID`) -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Likes Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_likes` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_likes`;
CREATE TABLE IF NOT EXISTS `likes` (
  `likeID` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `postID` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`likeID`),
  UNIQUE KEY `user_post_unique` (`userID`,`postID`), -- Prevents a user from liking a post more than once
  KEY `userID` (`userID`), -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
  KEY `postID` (`postID`)  -- Originally a FOREIGN KEY to fab_ulous_posts.posts(postID). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Comments Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_comments` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_comments`;
CREATE TABLE IF NOT EXISTS `comments` (
  `commentID` int(11) NOT NULL AUTO_INCREMENT,
  `postID` int(11) NOT NULL,
  `userID` int(11) NOT NULL,
  `comment_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`commentID`),
  KEY `postID` (`postID`), -- Originally a FOREIGN KEY to fab_ulous_posts.posts(postID). Integrity is now handled by the application.
  KEY `userID` (`userID`)  -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Commissions Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_commissions` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_commissions`;
CREATE TABLE IF NOT EXISTS `commissions` (
  `commissionID` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `commission_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Accepted','Ongoing','Delayed','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `stl_file_url` varchar(255) DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `assigned_admin_id` int(11) DEFAULT NULL, -- Assigned Position for Commission: the admin/super_admin handling this request. NULL = unassigned.
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`commissionID`),
  KEY `userID` (`userID`), -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
  KEY `assigned_admin_id` (`assigned_admin_id`) -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Commission Payments Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_commission_payments` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_commission_payments`;
CREATE TABLE IF NOT EXISTS `commission_payments` (
  `paymentID` int(11) NOT NULL AUTO_INCREMENT,
  `commissionID` int(11) NOT NULL,
  `paymongo_payment_id` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`paymentID`),
  UNIQUE KEY `paymongo_payment_id` (`paymongo_payment_id`),
  KEY `commissionID` (`commissionID`) -- Originally a FOREIGN KEY to fab_ulous_commissions.commissions(commissionID). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Friendships Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_friendships` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_friendships`;
CREATE TABLE IF NOT EXISTS `friendships` (
  `friendshipID` int(11) NOT NULL AUTO_INCREMENT,
  `user1_id` int(11) NOT NULL, -- Renamed from requesterID
  `user2_id` int(11) NOT NULL, -- Renamed from receiverID
  `status` enum('pending','accepted') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`friendshipID`),
  UNIQUE KEY `unique_friendship` (`user1_id`,`user2_id`), -- Renamed from unique_pair
  KEY `user1_id` (`user1_id`), -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
  KEY `user2_id` (`user2_id`)  -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Notifications Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_notifications` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `notifID` int(11) NOT NULL AUTO_INCREMENT,
  `userID` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `type` enum('like','comment','friend_request','friend_accept','commission_submitted','commission_approved','commission_updated','commission_paid','message') NOT NULL,
  `post_id` int(11) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notifID`),
  KEY `userID` (`userID`), -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
  KEY `actor_id` (`actor_id`) -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Messages Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_messages` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_messages`;
CREATE TABLE IF NOT EXISTS `messages` (
  `messageID` int(11) NOT NULL AUTO_INCREMENT,
  `senderID` int(11) NOT NULL, -- Renamed from sender_id
  `receiverID` int(11) NOT NULL, -- Renamed from receiver_id
  `message_text` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`messageID`),
  KEY `senderID` (`senderID`),   -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
  KEY `receiverID` (`receiverID`) -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Pending Registrations Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_pending_registrations` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_pending_registrations`;
CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `verification_code` varchar(6) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Password Resets Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_password_resets` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `reset_code` varchar(6) NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Audit Log Database
CREATE DATABASE IF NOT EXISTS `fab_ulous_audit_log` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fab_ulous_audit_log`;
CREATE TABLE IF NOT EXISTS `audit_log` (
  `logID` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `admin_username` varchar(50) NOT NULL,
  `action` varchar(512) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `visibility_role` enum('admin','super_admin') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`logID`),
  KEY `admin_id` (`admin_id`) -- Originally a FOREIGN KEY to fab_ulous_accounts.accounts(id). Integrity is now handled by the application.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- Idempotent post-creation migrations
--
-- CREATE TABLE IF NOT EXISTS above only builds these tables on a brand-new
-- database; it will NOT add new columns to a `commissions` or `posts` table
-- that already exists from an older version of this schema. The two blocks
-- below are the idempotent ALTER TABLE migrations (merged in from
-- migration_posts_visibility.sql and migration_commissions_assigned_admin.sql)
-- that upgrade a pre-existing install to the current schema. They are safe
-- to re-run — each checks information_schema first and skips the ALTER if
-- the column/index is already present, so running this whole script against
-- a fresh install is also a no-op here (the columns already exist from the
-- CREATE TABLE statements above).
-- ════════════════════════════════════════════════════════════════════════

-- ── Migration: posts.visibility (Public / Friends Only feed toggle) ──────
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

-- ── Migration: commissions.assigned_admin_id (Assigned Position for Commission) ──
USE `fab_ulous_commissions`;

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'fab_ulous_commissions'
      AND TABLE_NAME = 'commissions'
      AND COLUMN_NAME = 'assigned_admin_id'
);

SET @ddl = IF(
    @column_exists = 0,
    "ALTER TABLE `commissions` ADD COLUMN `assigned_admin_id` INT(11) DEFAULT NULL AFTER `admin_note`",
    "SELECT 'assigned_admin_id column already present, skipping' AS status"
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the supporting index only if it doesn't already exist (fresh installs
-- from setup_micro_dbs.sql create it as part of the CREATE TABLE).
SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'fab_ulous_commissions'
      AND TABLE_NAME = 'commissions'
      AND INDEX_NAME = 'assigned_admin_id'
);

SET @idx_ddl = IF(
    @index_exists = 0,
    "ALTER TABLE `commissions` ADD KEY `assigned_admin_id` (`assigned_admin_id`)",
    "SELECT 'assigned_admin_id index already present, skipping' AS status"
);

PREPARE idx_stmt FROM @idx_ddl;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

-- Backfill: existing rows default to NULL (unassigned) automatically via the
-- column DEFAULT, so no UPDATE is needed.
