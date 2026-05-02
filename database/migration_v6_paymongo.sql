-- FABulous Migration v6
-- Run via phpMyAdmin or: mysql -u root fab_ulous < migration_v6_paymongo.sql
--
-- Adds local payment tracking for PayMongo Checkout Sessions.

CREATE TABLE IF NOT EXISTS commission_payments (
    paymentID             INT AUTO_INCREMENT PRIMARY KEY,
    commissionID          INT NOT NULL,
    userID                INT NOT NULL,
    payer_name            VARCHAR(255) DEFAULT NULL,
    payer_email           VARCHAR(150) NOT NULL,
    amount                DECIMAL(10,2) NOT NULL,
    currency              CHAR(3) DEFAULT 'PHP',
    status                ENUM('pending','paid','failed','expired','cancelled') DEFAULT 'pending',
    paymongo_checkout_id  VARCHAR(100) DEFAULT NULL,
    paymongo_payment_id   VARCHAR(100) DEFAULT NULL,
    paymongo_reference    VARCHAR(100) DEFAULT NULL,
    checkout_url          TEXT DEFAULT NULL,
    webhook_event_id      VARCHAR(100) DEFAULT NULL,
    paid_at               TIMESTAMP NULL DEFAULT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_commission_payments_commission (commissionID),
    KEY idx_commission_payments_user (userID),
    KEY idx_commission_payments_checkout (paymongo_checkout_id),
    FOREIGN KEY (commissionID) REFERENCES commissions(commissionID) ON DELETE CASCADE,
    FOREIGN KEY (userID) REFERENCES accounts(id) ON DELETE CASCADE
);
