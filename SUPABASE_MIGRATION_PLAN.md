# Supabase Migration Plan

Supabase can be the production database, but it is not a drop-in replacement for the current database. Supabase provides PostgreSQL, while Havenzen currently uses PHP `mysqli` and MySQL/MariaDB SQL.

## Current State

- App database driver: `mysqli`
- Current local database: MariaDB/MySQL `havenzen_db`
- Current deployment config: Render web service with external MySQL/MariaDB
- Current data export: `havenzen_render_export.sql` for MySQL/MariaDB import

## Why This Needs A Port

The current code uses MySQL-specific behavior:

- `mysqli` connection and prepared statement APIs
- `AUTO_INCREMENT`
- `ENUM`
- `ON UPDATE current_timestamp()`
- `ON DUPLICATE KEY UPDATE`
- MySQL date functions such as `CURDATE()`, `DATE_SUB()`, and `TIMESTAMPDIFF()`
- MySQL dump format

Supabase uses PostgreSQL connection strings, PostgreSQL SQL syntax, and PostgreSQL drivers.

## Recommended Supabase Path

1. Keep the current MySQL deployment path working for immediate Render demos.
2. Create a PostgreSQL schema equivalent for Supabase.
3. Use the PostgreSQL compatibility layer in `lib/postgres_mysqli_compat.php` for the first Render demo.
4. Convert SQL queries to PostgreSQL-compatible syntax.
5. Export current MySQL data as CSV or transform it into PostgreSQL inserts.
6. Import converted data into Supabase.
7. Test login, bookings, receipts, admin search, vehicle management, and GPS tracking.
8. Replace the compatibility layer with direct PDO queries module by module before production launch.

Initial migration files:

- `database/supabase_schema.sql`
- `tools/export_supabase_data.php`

Run the schema in Supabase SQL Editor first. Then generate the data import file locally:

```powershell
C:\xampp\php\php.exe tools\export_supabase_data.php
```

That creates `database/supabase_data.sql`, which contains converted inserts from the current local MySQL data. Review it before importing because it contains real users, bookings, and operational data.

If Supabase SQL Editor refuses large copy-pastes, build the compact one-file import:

```powershell
powershell -ExecutionPolicy Bypass -File tools\build_supabase_compact_import.ps1
```

Then paste/run `database/supabase_full_import_compact.sql` in Supabase SQL Editor. It is generated with the same schema and data but uses batched insert lines to stay within editor line limits.

## Render To Supabase Connection

Use Supabase's Session Pooler connection string for Render because it supports IPv4 and works well for a persistent backend service.

In the Supabase dashboard, open the project, choose Connect, and copy the Session Pooler connection string. Use the pooler URL, not the browser API URL.

Render env variables after the port:

```text
DATABASE_URL=postgres://postgres.project-ref:password@aws-0-region.pooler.supabase.com:5432/postgres
GOOGLE_MAPS_API_KEY=your-google-maps-key
GPS_TRACKING_API_KEY=make-a-long-random-secret
THERMAL_PRINTER_NAME=Xprinter XP-58IIH
```

## Migration Work Breakdown

### Phase 1: Database Adapter

- Add a PostgreSQL PDO connection path.
- Replace direct `mysqli` usage with a project-level query helper.
- Keep local MySQL working until the port is complete.

### Phase 2: Schema Conversion

- Convert tables from MySQL to PostgreSQL.
- Replace `AUTO_INCREMENT` with identity columns.
- Replace `ENUM` with `CHECK` constraints or PostgreSQL enum types.
- Replace `POINT` with simple latitude/longitude fields unless PostGIS is needed.
- Recreate indexes and foreign keys.

### Phase 3: Query Conversion

- Replace MySQL-only functions with PostgreSQL equivalents.
- Replace `?` prepared statement binding style where needed.
- Replace `ON DUPLICATE KEY UPDATE` with `ON CONFLICT DO UPDATE`.
- Replace `insert_id` reads with `RETURNING id`.

### Phase 4: Data Transfer

- Export current data from MySQL.
- Convert or import table-by-table into Supabase.
- Verify row counts and critical relationships.
- Keep a backup of the original MySQL export before any import.

### Phase 5: Deployment

- Set Render `DATABASE_URL` to the Supabase Session Pooler URL.
- Deploy to Render.
- Run smoke tests.
- Start phone GPS tracking from the driver page and confirm admin/passenger maps update.

## Important Notes

- Do not commit Supabase passwords or API keys.
- Do not use the browser Supabase anon key for server-side database writes.
- Keep the current MySQL export out of Git because it contains real users and bookings.
- Direct thermal printing will still only work from a local Windows machine or future print bridge.
- The generated `database/supabase_data.sql` file is ignored by Git because it contains live data.
- The current PHP app can now start against Supabase through the compatibility layer, but production should still get a direct PDO/PostgreSQL port.
