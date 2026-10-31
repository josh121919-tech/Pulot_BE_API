-- Adds the Resolved status to an `items` table created before it existed.
-- Run once, with the database selected (phpMyAdmin > Import, or
-- mysql -u root campus_lost_found < sql/migrate_add_status.sql).
-- Reports created before this change have no owner token, so nobody can resolve them from the app.

ALTER TABLE items
  ADD COLUMN status ENUM('open', 'resolved') NOT NULL DEFAULT 'open' AFTER contact_info,
  ADD COLUMN resolved_at TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN owner_token_hash CHAR(64) NULL AFTER resolved_at,
  ADD KEY idx_items_status (status);
