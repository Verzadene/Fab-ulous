-- FABulous Migration v4
-- Adds profile_pic to accounts; creates pending_registrations table

ALTER TABLE accounts
  ADD COLUMN IF NOT EXISTS profile_pic VARCHAR(100) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS pending_registrations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    first_name       VARCHAR(100) NOT NULL,
    last_name        VARCHAR(100) NOT NULL,
    username         VARCHAR(50)  NOT NULL,
    email            VARCHAR(150) NOT NULL,
    password_hash    VARCHAR(255) NOT NULL,
    google_id        VARCHAR(100) DEFAULT NULL,
    verify_code      VARCHAR(6)   NOT NULL,
    code_expires_at  DATETIME     NOT NULL,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pending_email (email)
);
