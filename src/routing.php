<?php

declare(strict_types=1);

function fuelauServiceBaseUrl(string $service): string
{
    return match ($service) {
        'nominatim' => 'http://nominatim:8080',
        'photon' => 'http://photon:2322',
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
        $status = fuelauHttpJsonRequest(fuelauServiceBaseUrl('photon') . '/status', [], 15);
        if (strtolower((string) ($status['status'] ?? '')) !== 'ok') {
            throw new FuelauUpstreamException('Photon status response was not healthy.');
        }
        $services['photon'] = [
            'status' => 'ok',
            'import_date' => (string) ($status['import_date'] ?? ''),
        ];
    } catch (Throwable $exception) {
        error_log('FuelAU photon status probe failed: ' . $exception->getMessage());
        $services['photon'] = [
            'status' => 'unavailable',
            'message' => fuelauPhotonUnavailableMessage(),
        ];
    }

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

function fuelauPhotonUnavailableMessage(): string
{
    $service = fuelauDockerService('photon');
    if (!is_array($service)) {
        return 'Autocomplete service unavailable. Unable to inspect the Photon container.';
    }

    if (($service['has_container'] ?? false) !== true) {
        return 'Autocomplete service unavailable. Start it with `docker compose --profile routing up -d photon`.';
    }

    $displayState = strtolower((string) ($service['display_state'] ?? ''));
    $displayStatus = strtolower((string) ($service['display_status'] ?? ''));
    if (str_contains($displayStatus, 'health: starting')) {
        return 'Autocomplete service is still opening its index. Try again shortly.';
    }
    if (str_contains($displayStatus, 'unhealthy')) {
        return 'Autocomplete service is unhealthy. Check `docker compose logs -f photon`.';
    }
    if ($displayState !== '' && $displayState !== 'running') {
        return 'Autocomplete service is not running. Start it with `docker compose --profile routing up -d photon`.';
    }

    return 'Autocomplete service unavailable. Check `docker compose logs -f photon`.';
}

/**
 * @param array<string, mixed> $config
 */
function fuelauAutocompleteProvider(array $config): string
{
    $provider = strtolower(trim((string) ($config['GEOCODER_AUTOCOMPLETE_PROVIDER'] ?? 'photon')));
    if (!in_array($provider, ['photon', 'nominatim'], true)) {
        throw new RuntimeException('GEOCODER_AUTOCOMPLETE_PROVIDER must be photon or nominatim.');
    }

    return $provider;
}

/**
 * @return list<array<string, mixed>>
 */
function fuelauPhotonSearch(string $query, int $limit = 10): array
{
    $query = fuelauValidateNominatimQuery($query);
    $limit = max(1, min(50, $limit));
    $payload = fuelauHttpJsonRequest(
        fuelauHttpBuildUrl(fuelauServiceBaseUrl('photon') . '/api', [
            'q' => $query,
            'lang' => 'en',
            'limit' => $limit,
        ]),
        [],
        10,
    );
    $features = $payload['features'] ?? null;
    if (($payload['type'] ?? null) !== 'FeatureCollection' || !is_array($features)) {
        throw new FuelauUpstreamException('Photon returned an invalid GeoJSON response.');
    }

    $results = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $coordinates = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];
        $longitude = isset($coordinates[0]) && is_numeric($coordinates[0]) ? (float) $coordinates[0] : null;
        $latitude = isset($coordinates[1]) && is_numeric($coordinates[1]) ? (float) $coordinates[1] : null;
        $countryCode = strtoupper(trim((string) ($properties['countrycode'] ?? '')));
        if ($longitude === null || $latitude === null || $countryCode !== 'AU') {
            continue;
        }
        try {
            fuelauValidateCoordinates($latitude, $longitude);
        } catch (FuelauValidationException) {
            continue;
        }

        $address = fuelauPhotonAddress($properties);
        $displayName = fuelauPhotonDisplayName($properties, $address);
        $item = [
            'class' => (string) ($properties['osm_key'] ?? ''),
            'type' => (string) ($properties['type'] ?? $properties['osm_value'] ?? ''),
            'display_name' => $displayName,
        ];
        $tier = fuelauNominatimLookupTier($item);
        $results[] = [
            'provider' => 'photon',
            'place_id' => 'photon-' . (string) ($properties['osm_type'] ?? '') . '-' . (string) ($properties['osm_id'] ?? ''),
            'osm_type' => (string) ($properties['osm_type'] ?? ''),
            'osm_id' => (string) ($properties['osm_id'] ?? ''),
            'display_name' => $displayName,
            'label' => fuelauPhotonLabel($properties, $address),
            'lat' => $latitude,
            'lon' => $longitude,
            'class' => (string) ($item['class'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'tier' => $tier,
            'is_fallback' => $tier >= 3,
            'importance' => 0.0,
            'score' => 0.0,
            'address' => $address,
        ];
    }

    return array_slice($results, 0, $limit);
}

/**
 * @param array<string, mixed> $properties
 * @return array<string, string>
 */
function fuelauPhotonAddress(array $properties): array
{
    $mapping = [
        'house_number' => 'housenumber',
        'road' => 'street',
        'suburb' => 'locality',
        'city_district' => 'district',
        'city' => 'city',
        'county' => 'county',
        'state' => 'state',
        'postcode' => 'postcode',
        'country' => 'country',
        'country_code' => 'countrycode',
    ];
    $address = [];
    foreach ($mapping as $target => $source) {
        $value = trim((string) ($properties[$source] ?? ''));
        if ($value !== '') {
            $address[$target] = $target === 'country_code' ? strtolower($value) : $value;
        }
    }

    return $address;
}

/**
 * @param array<string, mixed> $properties
 * @param array<string, string> $address
 */
function fuelauPhotonDisplayName(array $properties, array $address): string
{
    $label = fuelauPhotonLabel($properties, $address);
    $country = trim((string) ($address['country'] ?? ''));
    if ($country === '' || str_contains(fuelauNormalizeLookupText($label), fuelauNormalizeLookupText($country))) {
        return $label;
    }

    return "{$label}, {$country}";
}

/**
 * @param array<string, mixed> $properties
 * @param array<string, string> $address
 */
function fuelauPhotonLabel(array $properties, array $address): string
{
    $houseNumber = trim((string) ($address['house_number'] ?? ''));
    $street = trim((string) ($address['road'] ?? ''));
    $name = trim((string) ($properties['name'] ?? ''));
    if ($houseNumber !== '' && $street !== '') {
        $primary = "{$houseNumber} {$street}";
    } elseif ($name !== '') {
        $primary = $name;
    } else {
        $primary = $street;
    }

    $parts = array_filter([
        $primary,
        (string) ($address['suburb'] ?? ''),
        (string) ($address['city_district'] ?? ''),
        (string) ($address['city'] ?? ''),
        (string) ($address['state'] ?? ''),
        (string) ($address['postcode'] ?? ''),
    ]);
    $unique = [];
    foreach ($parts as $part) {
        $normalized = fuelauNormalizeLookupText($part);
        if ($normalized !== '' && !isset($unique[$normalized])) {
            $unique[$normalized] = trim($part);
        }
    }

    return implode(', ', $unique);
}

/**
 * @return list<array<string, mixed>>
 */
function fuelauCachedPhotonSearch(
    string $query,
    int $limit,
    string $cacheDirectory,
    int $ttlSeconds = 3600,
): array {
    $query = fuelauValidateNominatimQuery($query);
    $limit = max(1, min(50, $limit));
    $cacheKey = hash('sha256', 'photon-v2|' . strtolower($query) . '|' . $limit);

    return fuelauRememberArray(
        rtrim($cacheDirectory, '/') . "/photon-autocomplete-{$cacheKey}.json",
        $ttlSeconds,
        static fn (): array => fuelauPhotonSearch($query, $limit),
    );
}

/**
 * @param array<string, mixed> $config
 * @return array{provider: string, fallback: bool, results: list<array<string, mixed>>}
 */
function fuelauAutocompleteSearch(
    string $query,
    int $limit,
    array $config,
    string $cacheDirectory,
): array {
    $provider = fuelauAutocompleteProvider($config);
    if ($provider === 'nominatim') {
        return [
            'provider' => 'nominatim',
            'fallback' => false,
            'results' => fuelauCachedNominatimSearch($query, $limit, $cacheDirectory),
        ];
    }

    try {
        $results = fuelauCachedPhotonSearch($query, $limit, $cacheDirectory);
        if ($results !== []) {
            return ['provider' => 'photon', 'fallback' => false, 'results' => $results];
        }
        error_log("FuelAU Photon autocomplete returned no results for a bounded query; trying Nominatim fallback.");
    } catch (FuelauUpstreamException $exception) {
        error_log('FuelAU Photon autocomplete failed; trying Nominatim fallback: ' . $exception->getMessage());
    }

    return [
        'provider' => 'nominatim',
        'fallback' => true,
        'results' => fuelauCachedNominatimSearch($query, $limit, $cacheDirectory),
    ];
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

function fuelauRoutePlan(
    array $coordinates,
    bool $steps = true,
    string $overview = 'simplified',
): array
{
    return fuelauRoutePlanRequest($coordinates, $steps, 1, $overview);
}

function fuelauAlternativeRoutePlan(
    array $coordinates,
    int $maximumRoutes = 3,
    bool $steps = false,
): array {
    if ($maximumRoutes < 1 || $maximumRoutes > 3) {
        throw new InvalidArgumentException('Alternative route count must be between 1 and 3.');
    }

    return fuelauRoutePlanRequest($coordinates, $steps, $maximumRoutes, 'simplified');
}

/**
 * @param list<array{lat: float, lon: float}> $coordinates
 * @return array<string, mixed>
 */
function fuelauRoutePlanRequest(
    array $coordinates,
    bool $steps,
    int $maximumRoutes,
    string $overview,
): array
{
    if (!in_array($overview, ['simplified', 'full'], true)) {
        throw new InvalidArgumentException('Route overview must be simplified or full.');
    }

    $encodedCoordinates = implode(
        ';',
        array_map(
            static fn (array $coordinate): string => $coordinate['lon'] . ',' . $coordinate['lat'],
            $coordinates
        )
    );

    $payload = fuelauHttpJsonRequest(
        fuelauHttpBuildUrl(fuelauServiceBaseUrl('osrm') . "/route/v1/driving/{$encodedCoordinates}", [
            'alternatives' => $maximumRoutes > 1 ? (string) $maximumRoutes : 'false',
            'geometries' => 'geojson',
            'overview' => $overview,
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

/**
 * @param list<array{lat: float, lon: float}> $coordinates
 * @return array{
 *     distances: list<list<int|null>>,
 *     durations: list<list<int|null>>
 * }
 */
function fuelauOsrmTable(array $coordinates): array
{
    $coordinates = fuelauNormalizeRouteCandidatePoints($coordinates);
    if (count($coordinates) < 2 || count($coordinates) > 40) {
        throw new InvalidArgumentException('OSRM table requests require between 2 and 40 coordinates.');
    }
    $encodedCoordinates = implode(
        ';',
        array_map(
            static fn (array $coordinate): string => $coordinate['lon'] . ',' . $coordinate['lat'],
            $coordinates,
        ),
    );
    $payload = fuelauHttpJsonRequest(
        fuelauHttpBuildUrl(
            fuelauServiceBaseUrl('osrm') . "/table/v1/driving/{$encodedCoordinates}",
            ['annotations' => 'distance,duration'],
        ),
        [],
        30,
    );

    return fuelauNormalizeOsrmTablePayload($payload, count($coordinates));
}

/**
 * @param array<string, mixed> $payload
 * @return array{
 *     distances: list<list<int|null>>,
 *     durations: list<list<int|null>>
 * }
 */
function fuelauNormalizeOsrmTablePayload(array $payload, int $coordinateCount): array
{
    if ($coordinateCount < 2 || ($payload['code'] ?? null) !== 'Ok') {
        throw new FuelauUpstreamException('OSRM table response was not successful.');
    }

    $normalized = [];
    foreach (['distances', 'durations'] as $matrixName) {
        $matrix = $payload[$matrixName] ?? null;
        if (!is_array($matrix) || count($matrix) !== $coordinateCount) {
            throw new FuelauUpstreamException("OSRM table {$matrixName} matrix has an invalid size.");
        }
        $normalizedRows = [];
        foreach (array_values($matrix) as $row) {
            if (!is_array($row) || count($row) !== $coordinateCount) {
                throw new FuelauUpstreamException("OSRM table {$matrixName} row has an invalid size.");
            }
            $normalizedRow = [];
            foreach (array_values($row) as $value) {
                if ($value === null) {
                    $normalizedRow[] = null;
                    continue;
                }
                if (!is_numeric((string) $value) || (float) $value < -1.0) {
                    throw new FuelauUpstreamException(sprintf(
                        'OSRM table %s contains an invalid %s value: %s.',
                        $matrixName,
                        get_debug_type($value),
                        json_encode($value, JSON_UNESCAPED_SLASHES) ?: '(unencodable)',
                    ));
                }
                // OSRM MLD can emit sub-metre negative floating-point noise
                // (observed as -0.1) for effectively identical locations.
                $normalizedRow[] = (int) ceil(max(0.0, (float) $value));
            }
            $normalizedRows[] = $normalizedRow;
        }
        $normalized[$matrixName] = $normalizedRows;
    }

    return [
        'distances' => $normalized['distances'],
        'durations' => $normalized['durations'],
    ];
}
