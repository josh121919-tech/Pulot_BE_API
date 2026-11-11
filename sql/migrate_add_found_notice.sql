-- Adds the "I found this" notice to an existing `items` table.
-- Run once, with the database selected (phpMyAdmin > SQL, or
-- mysql -u root campus_lost_found < sql/migrate_add_found_notice.sql).

ALTER TABLE items
  ADD COLUMN found_contact VARCHAR(100) NULL AFTER owner_token_hash,
  ADD COLUMN found_at TIMESTAMP NULL DEFAULT NULL AFTER found_contact;
