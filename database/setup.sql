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
    role        ENUM('user','admin') DEFAULT 'user',
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
    commissionID INT AUTO_INCREMENT PRIMARY KEY,
    userID       INT NOT NULL,
    stl_file_url VARCHAR(500),
    description  TEXT,
    status       ENUM('Pending','In Progress','Completed','Cancelled') DEFAULT 'Pending',
    amount       DECIMAL(10,2) DEFAULT 0.00,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (userID) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_log (
    logID          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id       INT NOT NULL,
    admin_username VARCHAR(50) NOT NULL,
    action         VARCHAR(255) NOT NULL,
    target_type    VARCHAR(50),
    target_id      INT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- To promote an existing account to admin, run:
-- UPDATE accounts SET role = 'admin' WHERE username = 'your_username';
--
-- To add missing columns to an existing accounts table (MySQL 8+):
-- ALTER TABLE accounts ADD COLUMN IF NOT EXISTS role ENUM('user','admin') DEFAULT 'user';
-- ALTER TABLE accounts ADD COLUMN IF NOT EXISTS banned TINYINT(1) DEFAULT 0;
-- ALTER TABLE accounts ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
