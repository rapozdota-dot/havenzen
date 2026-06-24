-- Migration: create admins, customers, and drivers profile tables
-- Run this in your database (phpMyAdmin or CLI) to normalize user profile information
-- IMPORTANT: Back up your database before running this migration.

CREATE TABLE admins (
  admin_id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NULL,
  phone_number VARCHAR(20) NULL,
  profile_picture VARCHAR(255) NULL,
  address TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL,
  PRIMARY KEY (admin_id),
  UNIQUE KEY uq_admins_user (user_id),
  CONSTRAINT fk_admins_user FOREIGN KEY (user_id)
    REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customers (
  customer_id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NULL,
  phone_number VARCHAR(20) NULL,
  profile_picture VARCHAR(255) NULL,
  address TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL,
  PRIMARY KEY (customer_id),
  UNIQUE KEY uq_customers_user (user_id),
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id)
    REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE drivers (
  driver_id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NULL,
  phone_number VARCHAR(20) NULL,
  profile_picture VARCHAR(255) NULL,
  license_number VARCHAR(50) NULL,
  license_code VARCHAR(50) NULL,
  license_expiry DATE NULL,
  license_class VARCHAR(20) NULL,
  years_experience INT(11) NULL,
  emergency_contact VARCHAR(100) NULL,
  emergency_phone VARCHAR(20) NULL,
  address TEXT NULL,
  is_online TINYINT(1) NOT NULL DEFAULT 0,
  current_location POINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL,
  PRIMARY KEY (driver_id),
  UNIQUE KEY uq_drivers_user (user_id),
  SPATIAL INDEX idx_drivers_current_location (current_location),
  CONSTRAINT fk_drivers_user FOREIGN KEY (user_id)
    REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing data from users into the new profile tables.
-- If some columns do not exist in your users table, adjust the SELECT lists accordingly.

INSERT INTO admins (user_id, full_name, email, phone_number, profile_picture, address, created_at, last_login)
SELECT user_id, full_name, email, phone_number, profile_picture, address, created_at, last_login
FROM users
WHERE role = 'admin';

INSERT INTO customers (user_id, full_name, email, phone_number, profile_picture, address, created_at, last_login)
SELECT user_id, full_name, email, phone_number, profile_picture, address, created_at, last_login
FROM users
WHERE role = 'passenger';

INSERT INTO drivers (user_id, full_name, email, phone_number, profile_picture,
                     license_number, license_code, license_expiry, license_class, years_experience,
                     emergency_contact, emergency_phone, address, is_online,
                     current_location, created_at, last_login)
SELECT user_id, full_name, email, phone_number, profile_picture,
       license_number, NULL, license_expiry, license_class, years_experience,
       emergency_contact, emergency_phone, address, is_online,
       current_location, created_at, last_login
FROM users
WHERE role = 'driver';
