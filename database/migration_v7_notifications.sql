-- FABulous Migration v7
-- Run via phpMyAdmin or: mysql -u root fab_ulous < migration_v7_notifications.sql
--
-- Adds notification event types for commissions, payments, and messages.

ALTER TABLE notifications
  MODIFY COLUMN type
    ENUM(
      'like',
      'comment',
      'friend_request',
      'friend_accepted',
      'commission_submitted',
      'commission_approved',
      'commission_updated',
      'commission_paid',
      'message'
    ) NOT NULL;
