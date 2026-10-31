-- Pulot (campus lost and found): table only, for a hosted server.
-- On shared hosting (cPanel) you create the database in the control panel first,
-- then select it in phpMyAdmin and import this file. It has no CREATE DATABASE or USE,
-- because shared hosts do not allow them. Local XAMPP setups use lost_found.sql instead.

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
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_items_type_created (type, created_at),
  KEY idx_items_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
