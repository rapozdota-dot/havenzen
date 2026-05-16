ALTER TABLE vehicle_schedules ADD COLUMN IF NOT EXISTS driver_id integer REFERENCES users(user_id) ON DELETE SET NULL;
ALTER TABLE vehicle_trips ADD COLUMN IF NOT EXISTS driver_id integer REFERENCES users(user_id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_vehicle_schedules_driver_id ON vehicle_schedules(driver_id);
CREATE INDEX IF NOT EXISTS idx_vehicle_trips_driver_date ON vehicle_trips(driver_id, scheduled_departure_at);
