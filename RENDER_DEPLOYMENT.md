# Render Demo Deployment

This setup deploys Havenzen as a free Render web service while keeping MySQL/MariaDB for the database. That is the fastest demo path because the PHP app currently uses `mysqli` and MySQL-specific SQL.

## Recommended Demo Stack

- Render Free Web Service for the PHP app
- External MySQL or MariaDB database
- Existing `Dockerfile`
- Existing `database/schema.sql` for a clean database
- A local database export for copying your current data

Render Free is good for a client demo, not production. The web service can sleep after idle time, local file uploads are not persistent, and direct USB thermal printing cannot run from Render.

## Supabase Note

Supabase uses PostgreSQL, so it is not compatible with the current `mysqli` app until the database layer and SQL queries are ported. Supabase migration prep lives in:

- `database/supabase_schema.sql`
- `tools/export_supabase_data.php`
- `SUPABASE_MIGRATION_PLAN.md`

Do not point Render's production database env vars to Supabase until the PHP database port is complete. Use this MySQL/MariaDB path for the immediate demo, or continue with the Supabase migration plan first.

## 1. Create The MySQL Database

Use any MySQL/MariaDB host that allows public connections from Render. Practical options:

- Railway MySQL, using your free credit
- A paid MySQL/MariaDB host
- A VPS with MySQL opened safely to Render

Create an empty database, then keep these values:

```text
DB_HOST=
DB_PORT=3306
DB_USER=
DB_PASSWORD=
DB_NAME=
```

## 2. Export Your Current Local Database

Run this from PowerShell:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root havenzen_db --routines --triggers --single-transaction --default-character-set=utf8mb4 > C:\xampp\htdocs\havenzen\havenzen_render_export.sql
```

If your local MySQL root user has a password, use:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root -p havenzen_db --routines --triggers --single-transaction --default-character-set=utf8mb4 > C:\xampp\htdocs\havenzen\havenzen_render_export.sql
```

The export file is intentionally ignored by Git because it contains current users, bookings, and operational data.

## 3. Import Into The Hosted Database

If your provider gives direct MySQL credentials:

```powershell
C:\xampp\mysql\bin\mysql.exe -h your-db-host -P 3306 -u your-db-user -p your-db-name < C:\xampp\htdocs\havenzen\havenzen_render_export.sql
```

If the provider has a dashboard import tool, upload `havenzen_render_export.sql` there instead.

## 4. Deploy The Web App On Render

1. Push this repo to GitHub.
2. In Render, create a new Blueprint or Web Service from the GitHub repo.
3. Use the Docker runtime. The repo already has a `Dockerfile`.
4. Choose the Free instance type.
5. Set the health check path to:

```text
/health.php
```

If using the included `render.yaml`, Render will prompt for the secret database and Maps values.

## 5. Render Environment Variables

Set these in Render:

```text
DB_HOST=your-db-host
DB_PORT=3306
DB_USER=your-db-user
DB_PASSWORD=your-db-password
DB_NAME=your-db-name
GOOGLE_MAPS_API_KEY=your-google-maps-key
GPS_TRACKING_API_KEY=make-a-long-random-secret
THERMAL_PRINTER_NAME=Xprinter XP-58IIH
```

You can also use a MySQL connection URL instead of the individual DB fields:

```text
MYSQL_URL=mysql://user:password@host:3306/database
```

## 6. Phone GPS Tracking

For the demo, use the driver's phone browser:

1. Open the Render URL on the phone.
2. Log in as the driver.
3. Open the driver map/tracking page.
4. Allow location permission.
5. Keep the page open while moving.

The driver page now sends GPS updates about every 10 seconds. Passenger/admin tracking pages also refresh at 10-second intervals.

## 7. Known Free-Tier Limits

- Render Free web services sleep after idle time, so the first load can be slow.
- Render Free web services do not provide persistent local disks.
- Thermal USB printing still belongs on the office laptop/local XAMPP setup.
- External database traffic and Google Maps usage can hit provider limits.

For a real production launch, move the web service and database to paid tiers and add backups.
