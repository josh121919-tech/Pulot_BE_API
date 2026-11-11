-- PickApp: Campus Lost and Found Reporting App
-- Creates the database and the single `items` table used by api/items.php.
-- Import through phpMyAdmin, or run: mysql -u root < sql/lost_found.sql

CREATE DATABASE IF NOT EXISTS campus_lost_found
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE campus_lost_found;

CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  type ENUM('lost', 'found') NOT NULL,
  category VARCHAR(50) NOT NULL,
  latitude DECIMAL(10, 8) NOT NULL,
  longitude DECIMAL(11, 8) NOT NULL,
  image_path VARCHAR(255) NULL,          -- relative path, e.g. uploads/3f9c...e1.jpg
  contact_info VARCHAR(100) NOT NULL,
  status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  owner_token_hash CHAR(64) NULL,        -- SHA-256 of the secret only the reporter's phone keeps
  found_contact VARCHAR(100) NULL,       -- set when someone says they found a Lost item
  found_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_items_type_created (type, created_at),
  KEY idx_items_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
