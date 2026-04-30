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
            'limit' => 50,
            'q' => $query,
        ]),
        [],
        20
    );

    $normalizedQuery = fuelauNormalizeLookupText($query);
    $results = [];
    foreach ($payload as $item) {
        if (!is_array($item)) {
            continue;
        }
        $displayName = (string) ($item['display_name'] ?? '');
        $address = is_array($item['address'] ?? null) ? $item['address'] : [];
        $label = fuelauNominatimLookupLabel($item, $address);
        $tier = fuelauNominatimLookupTier($item);
        $importance = isset($item['importance']) ? (float) $item['importance'] : 0.0;
        $similarity = fuelauNominatimTextSimilarity($normalizedQuery, $label . ' ' . $displayName);
        $normalizedLabel = fuelauNormalizeLookupText($label);
        $results[] = [
            'provider' => 'nominatim',
            'place_id' => (string) ($item['place_id'] ?? ''),
            'osm_type' => (string) ($item['osm_type'] ?? ''),
            'osm_id' => (string) ($item['osm_id'] ?? ''),
            'display_name' => $displayName,
            'label' => $label,
            'lat' => isset($item['lat']) ? (float) $item['lat'] : null,
            'lon' => isset($item['lon']) ? (float) $item['lon'] : null,
            'class' => (string) ($item['class'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'tier' => $tier,
            'is_fallback' => $tier >= 3,
            'importance' => $importance,
            'score' => fuelauNominatimLookupScore(
                $tier,
                $importance,
                $similarity,
                $normalizedQuery,
                $normalizedLabel,
                fuelauNormalizeLookupText($displayName),
                fuelauNominatimLookupQueryMatch($normalizedQuery, $address)
            ),
            'address' => $address,
        ];
    }

    usort(
        $results,
        static function (array $left, array $right): int {
            $tierCompare = (int) ($left['tier'] ?? 3) <=> (int) ($right['tier'] ?? 3);
            if ($tierCompare !== 0) {
                return $tierCompare;
            }

            $scoreCompare = (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            $importanceCompare = (float) ($right['importance'] ?? 0) <=> (float) ($left['importance'] ?? 0);
            if ($importanceCompare !== 0) {
                return $importanceCompare;
            }

            return strcmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
        }
    );

    return $results;
}

function fuelauNormalizeLookupText(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function fuelauNominatimLookupLabel(array $item, array $address): string
{
    $houseNumber = trim((string) ($address['house_number'] ?? ''));
    $road = trim((string) ($address['road'] ?? $address['pedestrian'] ?? $address['footway'] ?? ''));
    $suburb = trim((string) ($address['suburb'] ?? $address['city_district'] ?? $address['neighbourhood'] ?? $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['locality'] ?? ''));
    $state = trim((string) ($address['state'] ?? ''));
    $postcode = trim((string) ($address['postcode'] ?? ''));
    $displayName = trim((string) ($item['display_name'] ?? ''));

    $street = trim(implode(' ', array_filter([$houseNumber, $road])));
    $locality = trim(implode(', ', array_filter([$suburb, $state, $postcode])));

    if ($street !== '' && $locality !== '') {
        return "{$street}, {$locality}";
    }
    if ($street !== '') {
        return $street;
    }
    if ($locality !== '') {
        return $locality;
    }
    return $displayName;
}

function fuelauNominatimLookupTier(array $item): int
{
    $class = strtolower(trim((string) ($item['class'] ?? '')));
    $type = strtolower(trim((string) ($item['type'] ?? '')));
    $placeTypes = [
        'house',
        'building',
        'residential',
        'road',
        'street',
        'pedestrian',
        'footway',
        'suburb',
        'neighbourhood',
        'city',
        'town',
        'village',
        'hamlet',
        'locality',
        'postcode',
        'isolated_dwelling',
    ];

    if ($class === 'place' || in_array($type, $placeTypes, true)) {
        return 1;
    }

    if (in_array($type, ['farm', 'isolated_dwelling'], true)) {
        return 4;
    }

    if (in_array($class, ['highway', 'railway', 'shop', 'amenity', 'tourism', 'office'], true)) {
        return 2;
    }

    if ($class === 'boundary' || $type === 'administrative' || $class === 'administrative') {
        return 3;
    }

    return 2;
}

function fuelauNominatimTextSimilarity(string $query, string $candidate): float
{
    $query = fuelauNormalizeLookupText($query);
    $candidate = fuelauNormalizeLookupText($candidate);
    if ($query === '' || $candidate === '') {
        return 0.0;
    }

    similar_text($query, $candidate, $percent);
    $score = $percent / 100;
    if (str_contains($candidate, $query)) {
        $score += 0.2;
    }
    return min(1.0, $score);
}

function fuelauNominatimLookupQueryMatch(string $query, array $address): bool
{
    if ($query === '' || $address === []) {
        return false;
    }

    $fields = [
        'house_number',
        'road',
        'pedestrian',
        'footway',
        'suburb',
        'city_district',
        'neighbourhood',
        'city',
        'town',
        'village',
        'locality',
    ];

    foreach ($fields as $field) {
        $value = fuelauNormalizeLookupText((string) ($address[$field] ?? ''));
        if ($value !== '' && ($value === $query || str_contains($value, $query))) {
            return true;
        }
    }

    return false;
}

function fuelauNominatimLookupScore(int $tier, float $importance, float $similarity, string $query = '', string $label = '', string $displayName = '', bool $queryMatch = false): float
{
    $tierScore = match ($tier) {
        1 => 3.0,
        2 => 2.0,
        default => 0.5,
    };

    $score = $tierScore + ($importance * 0.75) + ($similarity * 0.5);
    if ($tier >= 3) {
        $score -= 1.0;
    }
    if ($query !== '' && (
        $label === $query ||
        $displayName === $query ||
        str_contains($label, $query) ||
        str_contains($displayName, $query)
    )) {
        $score += 2.5;
    }
    if ($queryMatch) {
        $score += 1.5;
    }

    return $score;
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
