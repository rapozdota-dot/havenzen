<?php
// Converts current local MySQL/MariaDB data into PostgreSQL INSERT statements
// for Supabase. Run from the repo root with:
// C:\xampp\php\php.exe tools\export_supabase_data.php

require_once dirname(__DIR__) . '/config.php';

$outputPath = dirname(__DIR__) . '/database/supabase_data.sql';

$tables = [
    ['users', 'user_id'],
    ['routes', 'route_id'],
    ['admins', 'admin_id'],
    ['customers', 'customer_id'],
    ['drivers', 'driver_id'],
    ['vehicles', 'vehicle_id'],
    ['driver_availability', 'availability_id'],
    ['vehicle_schedules', 'schedule_id'],
    ['vehicle_trips', 'trip_id'],
    ['bookings', 'booking_id'],
    ['driver_earnings', 'earning_id'],
    ['locations', 'location_id'],
    ['notifications', 'id'],
    ['password_reset_tokens', 'id'],
    ['system_logs', 'log_id'],
];

$skipColumns = [
    'drivers' => ['current_location'],
];

function pgIdent($name)
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function pgValue($value)
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . str_replace("'", "''", (string) $value) . "'";
}

$handle = fopen($outputPath, 'wb');
if (!$handle) {
    fwrite(STDERR, "Unable to write $outputPath\n");
    exit(1);
}

fwrite($handle, "-- Havenzen Supabase data import generated from local MySQL.\n");
fwrite($handle, "-- Run database/supabase_schema.sql first.\n\n");
fwrite($handle, "begin;\n\n");
fwrite($handle, "truncate table system_logs, password_reset_tokens, notifications, locations, driver_earnings, bookings, vehicle_trips, vehicle_schedules, driver_availability, vehicles, drivers, customers, admins, routes, users restart identity cascade;\n\n");

foreach ($tables as [$table, $primaryKey]) {
    $result = $conn->query('SELECT * FROM `' . $table . '`');
    if (!$result) {
        fwrite(STDERR, "Failed reading $table: " . $conn->error . "\n");
        fclose($handle);
        exit(1);
    }

    $fields = array_map(function ($field) {
        return $field->name;
    }, $result->fetch_fields());

    $fields = array_values(array_filter($fields, function ($field) use ($skipColumns, $table) {
        return !in_array($field, $skipColumns[$table] ?? [], true);
    }));

    $rowCount = 0;
    while ($row = $result->fetch_assoc()) {
        $columns = array_map('pgIdent', $fields);
        $values = array_map(function ($field) use ($row) {
            return pgValue($row[$field]);
        }, $fields);

        fwrite(
            $handle,
            'insert into ' . pgIdent($table) .
            ' (' . implode(', ', $columns) . ') values (' .
            implode(', ', $values) . ");\n"
        );
        $rowCount++;
    }

    fwrite($handle, "select setval(pg_get_serial_sequence('$table', '$primaryKey'), greatest(coalesce((select max($primaryKey) from " . pgIdent($table) . "), 1), 1), true);\n\n");
    echo "$table: $rowCount rows\n";
    $result->free();
}

fwrite($handle, "commit;\n");
fclose($handle);

echo "Wrote $outputPath\n";
