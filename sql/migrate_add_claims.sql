-- Adds claims ("This is mine" on Found reports) to an existing database.
-- Run once, with the database selected (phpMyAdmin > SQL, or
-- mysql -u root campus_lost_found < sql/migrate_add_claims.sql).
-- Run it BEFORE uploading the new items.php, which counts claims for every report.

-- Claims on Found reports ("This is mine"). Many per report; one per contact per report.
CREATE TABLE IF NOT EXISTS claims (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id INT UNSIGNED NOT NULL,
  contact VARCHAR(100) NOT NULL,         -- "Phone: 0917..." or "Facebook: Juan Dela Cruz"
  proof VARCHAR(500) NOT NULL,           -- a detail only the owner would know; only the poster sees it
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_claims_item_contact (item_id, contact),
  CONSTRAINT fk_claims_item FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
