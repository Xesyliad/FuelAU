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
    $styleId = trim((string) ($config['MAP_TILE_STYLE'] ?? 'topo-3d'));
    if ($baseUrl === '') {
        $baseUrl = '/tiles';
    }
    if ($styleId === '') {
        $styleId = 'topo-3d';
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
        error_log('FuelAU nominatim status probe failed: ' . $exception->getMessage());
        $services['nominatim'] = [
            'status' => 'unavailable',
            'message' => fuelauNominatimUnavailableMessage(),
        ];
    }

    try {
        fuelauHttpJsonRequest(
            fuelauServiceBaseUrl('osrm') . '/route/v1/driving/151.2093,-33.8688;151.2069,-33.8731?overview=false',
            [],
            15
        );
        $services['osrm'] = ['status' => 'ok'];
    } catch (Throwable $exception) {
        error_log('FuelAU osrm status probe failed: ' . $exception->getMessage());
        $services['osrm'] = ['status' => 'unavailable', 'message' => 'Service unavailable'];
    }

    return $services;
}

function fuelauDockerService(string $service): ?array
{
    try {
        foreach (fuelauDockerServices() as $dockerService) {
            if ((string) ($dockerService['service'] ?? '') === $service) {
                return $dockerService;
            }
        }
    } catch (Throwable) {
        return null;
    }

    return null;
}

function fuelauNominatimUnavailableMessage(): string
{
    $service = fuelauDockerService('nominatim');
    if (!is_array($service)) {
        return 'Geocoding service unavailable. Unable to inspect the Nominatim container.';
    }

    if (($service['has_container'] ?? false) !== true) {
        return 'Geocoding service unavailable. Start it with `docker compose --profile routing up -d nominatim`.';
    }

    $displayState = strtolower((string) ($service['display_state'] ?? ''));
    $displayStatus = strtolower((string) ($service['display_status'] ?? ''));

    if (str_contains($displayStatus, 'health: starting')) {
        return 'Geocoding service is still starting or importing. Try again after Nominatim finishes its import.';
    }

    if (str_contains($displayStatus, 'unhealthy')) {
        return 'Geocoding service is unhealthy. Check `docker compose logs -f nominatim`.';
    }

    if ($displayState !== '' && $displayState !== 'running') {
        return 'Geocoding service is not running. Start it with `docker compose --profile routing up -d nominatim`.';
    }

    if ($displayState === 'running' || str_contains($displayStatus, 'healthy')) {
        return 'Geocoding service is running, but geocoding still failed. Check `docker compose logs -f nominatim`.';
    }

    return 'Geocoding service unavailable. Check `docker compose logs -f nominatim`.';
}

function fuelauNominatimSearch(string $query, int $limit = 10): array
{
    $query = fuelauValidateNominatimQuery($query);
    $limit = max(1, min(50, $limit));
    $payload = fuelauNominatimSearchPayload($query, min(50, max(10, $limit * 2)));

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

    return fuelauLimitNominatimResults($results, $limit);
}

function fuelauCachedNominatimSearch(
    string $query,
    int $limit,
    string $cacheDirectory,
    int $ttlSeconds = 3600
): array {
    $query = fuelauValidateNominatimQuery($query);
    $limit = max(1, min(50, $limit));
    $cacheKey = hash('sha256', strtolower($query) . '|' . $limit);

    return fuelauRememberArray(
        rtrim($cacheDirectory, '/') . "/geo-search-{$cacheKey}.json",
        $ttlSeconds,
        static fn (): array => fuelauNominatimSearch($query, $limit)
    );
}

function fuelauValidateNominatimQuery(string $query): string
{
    $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);
    if ($query === '') {
        throw new FuelauValidationException('Search query must not be empty.');
    }
    if (strlen($query) > 200) {
        throw new FuelauValidationException('Search query must not exceed 200 characters.');
    }

    return $query;
}

function fuelauLimitNominatimResults(array $results, int $limit): array
{
    return array_slice($results, 0, max(1, min(50, $limit)));
}

function fuelauNominatimSearchPayload(string $query, int $upstreamLimit = 20): array
{
    $lastException = null;
    foreach (fuelauNominatimSearchCandidates($query) as $candidate) {
        try {
            return fuelauHttpJsonRequest(
                fuelauHttpBuildUrl(fuelauServiceBaseUrl('nominatim') . '/search', [
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'countrycodes' => 'au',
                    'limit' => max(1, min(50, $upstreamLimit)),
                    'q' => $candidate,
                ]),
                [],
                20
            );
        } catch (Throwable $exception) {
            $lastException = $exception;
            if (!fuelauNominatimShouldRetrySearch($exception)) {
                throw $exception;
            }
        }
    }

    if ($lastException instanceof Throwable) {
        throw $lastException;
    }

    throw new FuelauUpstreamException('Nominatim search failed.');
}

function fuelauNominatimSearchCandidates(string $query): array
{
    $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);
    if ($query === '') {
        return [];
    }

    $parts = preg_split('/[\s,]+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || $parts === []) {
        return [$query];
    }

    $candidates = [];
    for ($count = count($parts); $count >= 1; $count--) {
        $candidate = trim(implode(' ', array_slice($parts, 0, $count)));
        if ($candidate !== '' && !in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }

    return $candidates;
}

function fuelauNominatimShouldRetrySearch(Throwable $exception): bool
{
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'query took too long to process')
        || str_contains($message, 'invalid json response')
        || preg_match('/http 5\d\d/', $message) === 1;
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
    fuelauValidateCoordinates($latitude, $longitude);

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

function fuelauValidateCoordinates(float $latitude, float $longitude): void
{
    if (!is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
        throw new FuelauValidationException('Latitude must be between -90 and 90.');
    }
    if (!is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
        throw new FuelauValidationException('Longitude must be between -180 and 180.');
    }
}

/**
 * @return list<array{lon: float, lat: float}>
 */
function fuelauParseCoordinates(string $coordinates): array
{
    if (strlen($coordinates) > 8192) {
        throw new FuelauValidationException('Route coordinates must not exceed 8192 characters.');
    }

    $parts = array_map('trim', explode(';', $coordinates));
    if (in_array('', $parts, true)) {
        throw new FuelauValidationException('Coordinate pairs must not be empty.');
    }
    if (count($parts) < 2) {
        throw new FuelauValidationException('At least two coordinates are required.');
    }
    if (count($parts) > 100) {
        throw new FuelauValidationException('At most 100 coordinates are allowed.');
    }

    $normalized = [];
    foreach ($parts as $part) {
        $pair = array_map('trim', explode(',', $part));
        if (count($pair) !== 2) {
            throw new FuelauValidationException("Invalid coordinate pair: {$part}");
        }
        [$longitude, $latitude] = $pair;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new FuelauValidationException("Invalid coordinate pair: {$part}");
        }
        $normalizedLongitude = (float) $longitude;
        $normalizedLatitude = (float) $latitude;
        fuelauValidateCoordinates($normalizedLatitude, $normalizedLongitude);
        $normalized[] = ['lon' => $normalizedLongitude, 'lat' => $normalizedLatitude];
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
            'overview' => 'simplified',
            'steps' => $steps ? 'true' : 'false',
        ]),
        [],
        30
    );

    $routes = is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
    foreach ($routes as &$route) {
        if (!is_array($route) || !is_array($route['legs'] ?? null)) {
            continue;
        }
        foreach ($route['legs'] as &$leg) {
            if (!is_array($leg) || !is_array($leg['steps'] ?? null)) {
                continue;
            }
            foreach ($leg['steps'] as &$step) {
                if (!is_array($step)) {
                    continue;
                }
                unset($step['geometry'], $step['intersections']);
            }
            unset($step);
        }
        unset($leg);
    }
    unset($route);

    return [
        'provider' => 'osrm',
        'code' => (string) ($payload['code'] ?? ''),
        'routes' => $routes,
        'waypoints' => $payload['waypoints'] ?? [],
    ];
}
