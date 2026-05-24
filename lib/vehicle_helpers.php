<?php

function hz_vehicle_model_text(array $vehicle): string
{
    $model = trim((string) ($vehicle['vehicle_model'] ?? ''));
    if ($model !== '') {
        return $model;
    }

    $type = trim((string) ($vehicle['vehicle_type'] ?? ''));
    return $type !== '' ? ucfirst($type) : '';
}

function hz_vehicle_display_label(array $vehicle, bool $includeSeats = false, bool $includeDriver = false): string
{
    $plate = trim((string) ($vehicle['license_plate'] ?? ''));
    $model = hz_vehicle_model_text($vehicle);
    $name = trim((string) ($vehicle['vehicle_name'] ?? ''));

    if ($name === '') {
        $name = ($model !== '' || $plate !== '') ? 'Vehicle' : 'Not assigned';
    }

    $parts = [$name];
    if ($model !== '') {
        $parts[] = $model;
    }
    if ($plate !== '') {
        $parts[] = $plate;
    }
    if ($includeSeats && array_key_exists('seat_capacity', $vehicle)) {
        $parts[] = intval($vehicle['seat_capacity']) . ' seats';
    }
    if ($includeDriver && !empty($vehicle['driver_name'])) {
        $parts[] = (string) $vehicle['driver_name'];
    }

    return implode(' - ', $parts);
}

function hz_vehicle_detail_line(array $vehicle): string
{
    $model = hz_vehicle_model_text($vehicle);
    $plate = trim((string) ($vehicle['license_plate'] ?? ''));

    if ($model !== '' && $plate !== '') {
        return $model . ' - ' . $plate;
    }

    return $model !== '' ? $model : ($plate !== '' ? $plate : 'Vehicle details unavailable');
}
