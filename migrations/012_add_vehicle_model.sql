ALTER TABLE vehicles
  ADD COLUMN IF NOT EXISTS vehicle_model varchar(100) DEFAULT NULL;
