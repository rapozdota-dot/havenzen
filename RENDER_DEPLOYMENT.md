# Render Demo Deployment

This setup deploys Havenzen as a free Render web service connected to Supabase PostgreSQL. The app includes a compatibility layer so the existing `mysqli`-style code can run against Supabase for the demo.

## Recommended Demo Stack

- Render Free Web Service for the PHP app
- Supabase PostgreSQL through the Session Pooler connection string
- Existing `Dockerfile`
- Imported Supabase schema/data

Render Free is good for a client demo, not production. The web service can sleep after idle time, local file uploads are not persistent, and direct USB thermal printing cannot run from Render.

## Supabase Note

Supabase uses PostgreSQL. The app now includes a PostgreSQL-backed compatibility layer for the existing `mysqli`-style code so Render can connect with `DATABASE_URL`.

Use the Supabase Session Pooler URL:

```text
postgresql://postgres.project-ref:your-password@aws-region.pooler.supabase.com:5432/postgres
```

Keep the password only in Render environment variables. Do not commit it.

## 1. Confirm Supabase Data

Run these in Supabase SQL Editor:

```sql
select count(*) from users;
select count(*) from bookings;
select count(*) from vehicles;
select count(*) from locations;
select count(*) from system_logs;
```

Expected imported counts:

```text
users: 17
bookings: 20
vehicles: 3
locations: 6856
system_logs: 746
```

## 2. Deploy The Web App On Render

1. Push this repo to GitHub.
2. In Render, create a new Blueprint or Web Service from the GitHub repo.
3. Use the Docker runtime. The repo already has a `Dockerfile`.
4. Choose the Free instance type.
5. Set the health check path to:

```text
/health.php
```

If using the included `render.yaml`, Render will prompt for the secret database and Maps values.

## 3. Render Environment Variables

Set these in Render:

```text
DATABASE_URL=postgresql://postgres.project-ref:your-password@aws-region.pooler.supabase.com:5432/postgres
GOOGLE_MAPS_API_KEY=your-google-maps-key
GPS_TRACKING_API_KEY=make-a-long-random-secret
THERMAL_PRINTER_NAME=Xprinter XP-58IIH
```

## 4. Phone GPS Tracking

For the demo, use the driver's phone browser:

1. Open the Render URL on the phone.
2. Log in as the driver.
3. Open the driver map/tracking page.
4. Allow location permission.
5. Keep the page open while moving.

The driver page now sends GPS updates about every 10 seconds. Passenger/admin tracking pages also refresh at 10-second intervals.

## 5. Known Free-Tier Limits

- Render Free web services sleep after idle time, so the first load can be slow.
- Render Free web services do not provide persistent local disks.
- Thermal USB printing still belongs on the office laptop/local XAMPP setup.
- External database traffic and Google Maps usage can hit provider limits.
- The PostgreSQL compatibility layer is for getting the demo online. A full query-by-query PDO port is still better for production.

For a real production launch, move the web service and database to paid tiers and add backups.
