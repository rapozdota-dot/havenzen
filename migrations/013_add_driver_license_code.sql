ALTER TABLE drivers
  ADD COLUMN IF NOT EXISTS license_code varchar(50) DEFAULT NULL;
