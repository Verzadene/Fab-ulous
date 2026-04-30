-- FABulous Database Setup Script
-- Run via phpMyAdmin or: mysql -u root < setup.sql

CREATE DATABASE IF NOT EXISTS fab_ulous;
USE fab_ulous;

CREATE TABLE IF NOT EXISTS accounts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    first_name  VARCHAR(100) NOT NULL,
    last_name   VARCHAR(100) NOT NULL,
    username    VARCHAR(50)  UNIQUE NOT NULL,
    email       VARCHAR(150) UNIQUE NOT NULL,
    password    VARCHAR(255),
    google_id   VARCHAR(100),
    profile_pic VARCHAR(100) DEFAULT NULL,
    role        ENUM('user','admin','super_admin') DEFAULT 'user',
    banned      TINYINT(1)   DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS posts (
    postID      INT AUTO_INCREMENT PRIMARY KEY,
    userID      INT NOT NULL,
    caption     TEXT,
    image_url   VARCHAR(500),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userID) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS likes (
    likeID      INT AUTO_INCREMENT PRIMARY KEY,
    postID      INT NOT NULL,
    userID      INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (postID, userID),
    FOREIGN KEY (postID) REFERENCES posts(postID) ON DELETE CASCADE,
    FOREIGN KEY (userID) REFERENCES accounts(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS comments (
    commentID   INT AUTO_INCREMENT PRIMARY KEY,
    postID      INT NOT NULL,
    userID      INT NOT NULL,
    content     TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (postID) REFERENCES posts(postID) ON DELETE CASCADE,
    FOREIGN KEY (userID) REFERENCES accounts(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS commissions (
    commissionID    INT AUTO_INCREMENT PRIMARY KEY,
    userID          INT NOT NULL,
    commission_name VARCHAR(255) DEFAULT NULL,
    stl_file_url    VARCHAR(500),
    description     TEXT,
    status          ENUM('Pending','Accepted','Ongoing','Delayed','Completed','Cancelled') DEFAULT 'Pending',
    amount          DECIMAL(10,2) DEFAULT 0.00,
    admin_note      TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (userID) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS friendships (
    friendshipID INT AUTO_INCREMENT PRIMARY KEY,
    requesterID  INT NOT NULL,
    receiverID   INT NOT NULL,
    status       ENUM('pending','accepted') DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pair (requesterID, receiverID),
    KEY idx_friendships_receiver (receiverID, status),
    KEY idx_friendships_requester (requesterID, status),
    FOREIGN KEY (requesterID) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (receiverID)  REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    notifID    INT AUTO_INCREMENT PRIMARY KEY,
    userID     INT NOT NULL,
    actor_id   INT NOT NULL,
    type       ENUM('like','comment','friend_request','friend_accepted') NOT NULL,
    post_id    INT DEFAULT NULL,
    ref_id     INT DEFAULT NULL,
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (userID, is_read),
    FOREIGN KEY (userID)   REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_log (
    logID           INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT NOT NULL,
    admin_username  VARCHAR(50) NOT NULL,
    action          VARCHAR(255) NOT NULL,
    target_type     VARCHAR(50),
    target_id       INT,
    visibility_role ENUM('admin','super_admin') DEFAULT 'admin',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(150) NOT NULL,
    reset_code  VARCHAR(10)  NOT NULL,
    expires_at  TIMESTAMP    NOT NULL,
    used        TINYINT(1)   DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_resets_email (email)
);

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

-- To promote an existing account to admin, run:
-- UPDATE accounts SET role = 'admin' WHERE username = 'your_username';
-- To promote an existing account to super_admin, run:
-- UPDATE accounts SET role = 'super_admin' WHERE username = 'your_username';
--
-- To add missing columns to an existing accounts table (MySQL 8+):
-- ALTER TABLE accounts MODIFY COLUMN role ENUM('user','admin','super_admin') DEFAULT 'user';
-- ALTER TABLE accounts ADD COLUMN IF NOT EXISTS banned TINYINT(1) DEFAULT 0;
-- ALTER TABLE accounts ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
