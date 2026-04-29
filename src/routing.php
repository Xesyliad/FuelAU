<?php

declare(strict_types=1);

function fuelauServiceBaseUrl(string $service): string
{
    return match ($service) {
        'nominatim' => 'http://nominatim:8080',
        'osrm' => 'http://osrm-routed:5000',
        default => throw new RuntimeException("Unknown service: {$service}"),
    };
}

function fuelauMapTileConfig(): array
{
    $config = fuelauConfig();
    $baseUrl = trim((string) ($config['MAP_TILE_SERVER_URL'] ?? '/tiles'));
    $styleId = trim((string) ($config['MAP_TILE_STYLE'] ?? 'basic-preview'));
    if ($baseUrl === '') {
        $baseUrl = '/tiles';
    }
    if ($styleId === '') {
        $styleId = 'basic-preview';
    }

    return [
        'provider' => 'Local Australia Tiles',
        'base_url' => $baseUrl,
        'style_id' => $styleId,
        'style_url' => rtrim($baseUrl, '/') . "/styles/{$styleId}/style.json",
        'tile_url' => rtrim($baseUrl, '/') . "/styles/{$styleId}/{z}/{x}/{y}.png",
        'attribution' => '&copy; OpenStreetMap contributors',
    ];
}

function fuelauServiceStatus(): array
{
    $services = [];

    try {
        fuelauHttpJsonRequest(fuelauHttpBuildUrl(fuelauServiceBaseUrl('nominatim') . '/search', [
            'format' => 'jsonv2',
            'q' => 'Sydney',
            'countrycodes' => 'au',
            'limit' => 1,
        ]), [], 15);
        $services['nominatim'] = ['status' => 'ok'];
    } catch (Throwable $exception) {
        $services['nominatim'] = ['status' => 'unavailable', 'message' => $exception->getMessage()];
    }

    try {
        fuelauHttpJsonRequest(
            fuelauServiceBaseUrl('osrm') . '/route/v1/driving/151.2093,-33.8688;151.2069,-33.8731?overview=false',
            [],
            15
        );
        $services['osrm'] = ['status' => 'ok'];
    } catch (Throwable $exception) {
        $services['osrm'] = ['status' => 'unavailable', 'message' => $exception->getMessage()];
    }

    return $services;
}

function fuelauNominatimSearch(string $query, int $limit = 10): array
{
    $payload = fuelauHttpJsonRequest(
        fuelauHttpBuildUrl(fuelauServiceBaseUrl('nominatim') . '/search', [
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'countrycodes' => 'au',
            'limit' => max(1, min(50, $limit)),
            'q' => $query,
        ]),
        [],
        20
    );

    $results = [];
    foreach ($payload as $item) {
        if (!is_array($item)) {
            continue;
        }
        $results[] = [
            'provider' => 'nominatim',
            'place_id' => (string) ($item['place_id'] ?? ''),
            'osm_type' => (string) ($item['osm_type'] ?? ''),
            'osm_id' => (string) ($item['osm_id'] ?? ''),
            'display_name' => (string) ($item['display_name'] ?? ''),
            'lat' => isset($item['lat']) ? (float) $item['lat'] : null,
            'lon' => isset($item['lon']) ? (float) $item['lon'] : null,
            'class' => (string) ($item['class'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'importance' => isset($item['importance']) ? (float) $item['importance'] : null,
            'address' => is_array($item['address'] ?? null) ? $item['address'] : [],
        ];
    }

    return $results;
}

function fuelauNominatimReverse(float $latitude, float $longitude): array
{
    $payload = fuelauHttpJsonRequest(
        fuelauHttpBuildUrl(fuelauServiceBaseUrl('nominatim') . '/reverse', [
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'lat' => $latitude,
            'lon' => $longitude,
        ]),
        [],
        20
    );

    return [
        'provider' => 'nominatim',
        'place_id' => (string) ($payload['place_id'] ?? ''),
        'display_name' => (string) ($payload['display_name'] ?? ''),
        'lat' => isset($payload['lat']) ? (float) $payload['lat'] : $latitude,
        'lon' => isset($payload['lon']) ? (float) $payload['lon'] : $longitude,
        'address' => is_array($payload['address'] ?? null) ? $payload['address'] : [],
    ];
}

function fuelauParseCoordinates(string $coordinates): array
{
    $parts = array_values(array_filter(array_map('trim', explode(';', $coordinates))));
    if (count($parts) < 2) {
        throw new RuntimeException('At least two coordinates are required.');
    }

    $normalized = [];
    foreach ($parts as $part) {
        [$longitude, $latitude] = array_map('trim', explode(',', $part, 2) + ['', '']);
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new RuntimeException("Invalid coordinate pair: {$part}");
        }
        $normalized[] = ['lon' => (float) $longitude, 'lat' => (float) $latitude];
    }

    return $normalized;
}

function fuelauRoutePlan(array $coordinates, bool $steps = true): array
{
    $encodedCoordinates = implode(
        ';',
        array_map(
            static fn (array $coordinate): string => $coordinate['lon'] . ',' . $coordinate['lat'],
            $coordinates
        )
    );

    $payload = fuelauHttpJsonRequest(
        fuelauHttpBuildUrl(fuelauServiceBaseUrl('osrm') . "/route/v1/driving/{$encodedCoordinates}", [
            'alternatives' => 'false',
            'geometries' => 'geojson',
            'overview' => 'full',
            'steps' => $steps ? 'true' : 'false',
        ]),
        [],
        30
    );

    return [
        'provider' => 'osrm',
        'code' => (string) ($payload['code'] ?? ''),
        'routes' => $payload['routes'] ?? [],
        'waypoints' => $payload['waypoints'] ?? [],
    ];
}
