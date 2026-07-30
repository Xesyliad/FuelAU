<?php

declare(strict_types=1);

const FUELAU_FUEL_SOURCES = ['all', 'qld', 'sa', 'nsw', 'tas', 'vic', 'wa', 'nt'];
const FUELAU_FUEL_STATES = ['QLD', 'SA', 'NSW', 'TAS', 'VIC', 'WA', 'NT'];

function fuelauClampInt(int $value, int $minimum, int $maximum): int
{
    return max($minimum, min($maximum, $value));
}

function fuelauFuelSourceForState(string $state): string
{
    return match ($state) {
        'QLD' => 'qld',
        'SA' => 'sa',
        'NSW' => 'nsw',
        'TAS' => 'tas',
        'VIC' => 'vic',
        'WA' => 'wa',
        'NT' => 'nt',
        default => throw new InvalidArgumentException("Unsupported fuel state: {$state}"),
    };
}

function fuelauFuelStateForSource(string $source): string
{
    return match ($source) {
        'qld' => 'QLD',
        'sa' => 'SA',
        'nsw' => 'NSW',
        'tas' => 'TAS',
        'vic' => 'VIC',
        'wa' => 'WA',
        'nt' => 'NT',
        default => throw new InvalidArgumentException("Unsupported state-specific fuel source: {$source}"),
    };
}

function fuelauNormalizeFuelSourceAndState(string $requestedSource, string $requestedState): array
{
    $source = strtolower(trim($requestedSource));
    $state = strtoupper(trim($requestedState));

    if ($source !== '' && !in_array($source, FUELAU_FUEL_SOURCES, true)) {
        throw new InvalidArgumentException("Unsupported fuel source: {$source}");
    }
    if ($state !== '' && !in_array($state, FUELAU_FUEL_STATES, true)) {
        throw new InvalidArgumentException("Unsupported fuel state: {$state}");
    }

    if ($source === '') {
        $source = $state === '' ? 'all' : fuelauFuelSourceForState($state);
    } elseif ($source !== 'all') {
        $sourceState = fuelauFuelStateForSource($source);
        if ($state === '') {
            $state = $sourceState;
        } elseif ($state !== $sourceState) {
            throw new InvalidArgumentException(
                sprintf('Fuel source %s does not provide state %s.', $source, $state)
            );
        }
    }

    return ['source' => $source, 'state' => $state];
}

function fuelauFuelSourcesForFilters(array $filters): array
{
    $source = strtolower(trim((string) ($filters['source'] ?? 'all')));
    $state = strtoupper(trim((string) ($filters['state'] ?? '')));

    if ($source === 'all') {
        if ($state === '') {
            return ['qld', 'sa', 'nsw', 'vic', 'wa', 'nt'];
        }

        $stateSource = fuelauFuelSourceForState($state);
        return [$stateSource === 'tas' ? 'nsw' : $stateSource];
    }

    return [$source === 'tas' ? 'nsw' : $source];
}

function fuelauFuelRequestFilters(?array $query = null): array
{
    $query ??= $_GET;
    $search = trim((string) ($query['q'] ?? ''));
    $fuel = trim((string) ($query['fuel'] ?? ''));
    $brand = trim((string) ($query['brand'] ?? ''));
    $limit = fuelauClampInt((int) ($query['limit'] ?? 100), 1, 500);
    $latitude = $query['lat'] ?? null;
    $longitude = $query['lon'] ?? null;
    $radiusKm = isset($query['radius_km']) ? max(0.1, (float) $query['radius_km']) : null;
    $sourceAndState = fuelauNormalizeFuelSourceAndState(
        (string) ($query['source'] ?? ''),
        (string) ($query['state'] ?? '')
    );

    return [
        'source' => $sourceAndState['source'],
        'search' => $search,
        'state' => $sourceAndState['state'],
        'fuel' => $fuel,
        'brand' => $brand,
        'limit' => $limit,
        'lat' => is_numeric((string) $latitude) ? (float) $latitude : null,
        'lon' => is_numeric((string) $longitude) ? (float) $longitude : null,
        'radius_km' => $radiusKm,
    ];
}

function fuelauDistanceExpression(string $latField, string $lonField): string
{
    return "(6371 * ACOS(LEAST(1, GREATEST(-1, "
        . "COS(RADIANS(:lat)) * COS(RADIANS({$latField})) * COS(RADIANS({$lonField}) - RADIANS(:lon)) + "
        . "SIN(RADIANS(:lat)) * SIN(RADIANS({$latField}))"
        . "))))";
}

function fuelauHistoricalLocationBounds(array $filters): ?array
{
    if (
        !is_numeric((string) ($filters['lat'] ?? null))
        || !is_numeric((string) ($filters['lon'] ?? null))
        || !is_numeric((string) ($filters['radius_km'] ?? null))
    ) {
        return null;
    }

    $latitude = (float) $filters['lat'];
    $longitude = (float) $filters['lon'];
    $radiusKm = max(0.1, (float) $filters['radius_km']);
    $latitudeDelta = $radiusKm / 110.574;
    $longitudeScale = max(0.1, abs(cos(deg2rad($latitude))));
    $longitudeDelta = $radiusKm / (111.320 * $longitudeScale);

    return [
        'min_lat' => max(-90.0, $latitude - $latitudeDelta),
        'max_lat' => min(90.0, $latitude + $latitudeDelta),
        'min_lon' => max(-180.0, $longitude - $longitudeDelta),
        'max_lon' => min(180.0, $longitude + $longitudeDelta),
    ];
}

function fuelauApplyHistoricalLocationFilters(
    array &$where,
    array $filters,
    string $latField,
    string $lonField
): void {
    if ($filters['lat'] === null || $filters['lon'] === null) {
        return;
    }

    $where[] = "{$latField} IS NOT NULL AND {$lonField} IS NOT NULL";
    $bounds = fuelauHistoricalLocationBounds($filters);
    if ($bounds === null) {
        return;
    }

    $where[] = "{$latField} BETWEEN :history_min_lat AND :history_max_lat";
    $where[] = "{$lonField} BETWEEN :history_min_lon AND :history_max_lon";
    $where[] = fuelauDistanceExpression($latField, $lonField) . ' <= :radius_km';
}

function fuelauFuelFiltersHaveBounds(array $filters): bool
{
    foreach (['min_lat', 'max_lat', 'min_lon', 'max_lon'] as $key) {
        if (!isset($filters[$key]) || !is_numeric((string) $filters[$key])) {
            return false;
        }
    }

    return true;
}

function fuelauNumericFuelFilterCondition(
    array $filters,
    string $fuelIdField,
    string $fuelNameField
): string {
    return ctype_digit((string) ($filters['fuel'] ?? ''))
        ? "{$fuelIdField} = :fuel"
        : "{$fuelNameField} = :fuel";
}

function fuelauApplyFuelLocationFilters(
    array &$where,
    string &$distanceSelect,
    array $filters,
    string $latField,
    string $lonField
): void {
    if ($filters['lat'] !== null && $filters['lon'] !== null) {
        $distanceSelect = fuelauDistanceExpression($latField, $lonField) . ' AS distance_km';
        $where[] = "{$latField} IS NOT NULL AND {$lonField} IS NOT NULL";
        if ($filters['radius_km'] !== null) {
            $where[] = fuelauDistanceExpression($latField, $lonField) . ' <= :radius_km';
        }
        return;
    }

    if (fuelauFuelFiltersHaveBounds($filters)) {
        $where[] = "{$latField} BETWEEN :min_lat AND :max_lat";
        $where[] = "{$lonField} BETWEEN :min_lon AND :max_lon";
    }
}

function fuelauBindFuelFilters(PDOStatement $statement, array $filters, bool $bindState = false): void
{
    if ($filters['search'] !== '') {
        $statement->bindValue(':search', '%' . $filters['search'] . '%');
    }
    if ($filters['brand'] !== '') {
        $statement->bindValue(':brand', '%' . $filters['brand'] . '%');
    }
    if ($bindState && $filters['state'] !== '') {
        $statement->bindValue(':state', $filters['state']);
    }
    if ($filters['fuel'] !== '') {
        $statement->bindValue(':fuel', $filters['fuel']);
    }
    if ($filters['lat'] !== null && $filters['lon'] !== null) {
        $statement->bindValue(':lat', $filters['lat']);
        $statement->bindValue(':lon', $filters['lon']);
        if ($filters['radius_km'] !== null) {
            $statement->bindValue(':radius_km', $filters['radius_km']);
        }
    } elseif (fuelauFuelFiltersHaveBounds($filters)) {
        $statement->bindValue(':min_lat', $filters['min_lat']);
        $statement->bindValue(':max_lat', $filters['max_lat']);
        $statement->bindValue(':min_lon', $filters['min_lon']);
        $statement->bindValue(':max_lon', $filters['max_lon']);
    }
    $statement->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
}

function fuelauBindHistoricalFilters(PDOStatement $statement, array $filters): void
{
    if ($filters['fuel'] !== '') {
        $statement->bindValue(':fuel', $filters['fuel']);
    }
    if ($filters['lat'] !== null && $filters['lon'] !== null) {
        $statement->bindValue(':lat', $filters['lat']);
        $statement->bindValue(':lon', $filters['lon']);
        if ($filters['radius_km'] !== null) {
            $statement->bindValue(':radius_km', $filters['radius_km']);
            $bounds = fuelauHistoricalLocationBounds($filters);
            if ($bounds !== null) {
                $statement->bindValue(':history_min_lat', $bounds['min_lat']);
                $statement->bindValue(':history_max_lat', $bounds['max_lat']);
                $statement->bindValue(':history_min_lon', $bounds['min_lon']);
                $statement->bindValue(':history_max_lon', $bounds['max_lon']);
            }
        }
    }
}

function fuelauQldFuelRows(PDO $pdo, array $filters): array
{
    $distanceSelect = 'NULL AS distance_km';
    $where = ['1=1'];
    if ($filters['search'] !== '') {
        $where[] = '(s.name LIKE :search OR s.address LIKE :search OR b.name LIKE :search)';
    }
    if ($filters['brand'] !== '') {
        $where[] = 'b.name LIKE :brand';
    }
    if ($filters['fuel'] !== '') {
        $where[] = fuelauNumericFuelFilterCondition($filters, 'c.fuel_id', 'f.name');
    }
    fuelauApplyFuelLocationFilters($where, $distanceSelect, $filters, 's.latitude', 's.longitude');

    $sql = "
        SELECT
            'qld' AS source,
            'QLD' AS state,
            CAST(s.site_id AS CHAR) AS station_id,
            s.name AS station_name,
            s.address,
            s.postcode,
            b.name AS brand_name,
            s.latitude,
            s.longitude,
            CAST(c.fuel_id AS CHAR) AS fuel_code,
            f.name AS fuel_name,
            c.price AS price_raw,
            ROUND(c.price / 10, 1) AS price,
            ROUND((
                SELECT h.price
                FROM fpq_site_prices_history h
                WHERE h.site_id = c.site_id
                  AND h.fuel_id = c.fuel_id
                  AND h.transaction_date_utc < c.transaction_date_utc
                ORDER BY h.transaction_date_utc DESC
                LIMIT 1
            ) / 10, 1) AS previous_price,
            c.transaction_date_utc AS updated_at,
            c.last_seen_at,
            {$distanceSelect}
        FROM fpq_site_prices_current c
        INNER JOIN fpq_sites s ON s.site_id = c.site_id
        INNER JOIN fpq_fuel_types f ON f.fuel_id = c.fuel_id
        LEFT JOIN fpq_brands b ON b.brand_id = s.brand_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY " . ($filters['lat'] !== null && $filters['lon'] !== null ? 'distance_km ASC,' : '') . " c.transaction_date_utc DESC
        LIMIT :limit
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindFuelFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauSaFuelRows(PDO $pdo, array $filters): array
{
    $distanceSelect = 'NULL AS distance_km';
    $where = ['1=1'];
    if ($filters['search'] !== '') {
        $where[] = '(s.name LIKE :search OR s.address LIKE :search OR b.name LIKE :search)';
    }
    if ($filters['brand'] !== '') {
        $where[] = 'b.name LIKE :brand';
    }
    if ($filters['fuel'] !== '') {
        $where[] = fuelauNumericFuelFilterCondition($filters, 'c.fuel_id', 'f.name');
    }
    fuelauApplyFuelLocationFilters($where, $distanceSelect, $filters, 's.latitude', 's.longitude');

    $sql = "
        SELECT
            'sa' AS source,
            'SA' AS state,
            CAST(s.station_id AS CHAR) AS station_id,
            s.name AS station_name,
            s.address,
            s.postcode,
            b.name AS brand_name,
            s.latitude,
            s.longitude,
            CAST(c.fuel_id AS CHAR) AS fuel_code,
            f.name AS fuel_name,
            c.price AS price_raw,
            ROUND(c.price / 10, 1) AS price,
            ROUND((
                SELECT h.price
                FROM sa_site_prices_history h
                WHERE h.station_id = c.station_id
                  AND h.fuel_id = c.fuel_id
                  AND h.transaction_date_utc < c.transaction_date_utc
                ORDER BY h.transaction_date_utc DESC
                LIMIT 1
            ) / 10, 1) AS previous_price,
            c.transaction_date_utc AS updated_at,
            c.last_seen_at,
            {$distanceSelect}
        FROM sa_site_prices_current c
        INNER JOIN sa_stations s ON s.station_id = c.station_id
        INNER JOIN sa_fuel_types f ON f.fuel_id = c.fuel_id
        LEFT JOIN sa_brands b ON b.brand_id = s.brand_id
        WHERE c.price IS NOT NULL AND " . implode(' AND ', $where) . "
        ORDER BY " . ($filters['lat'] !== null && $filters['lon'] !== null ? 'distance_km ASC,' : '') . " c.transaction_date_utc DESC
        LIMIT :limit
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindFuelFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNswFuelRows(PDO $pdo, array $filters): array
{
    $distanceSelect = 'NULL AS distance_km';
    $where = ['1=1'];
    if ($filters['search'] !== '') {
        $where[] = '(s.name LIKE :search OR s.address LIKE :search OR s.brand_name LIKE :search)';
    }
    if ($filters['brand'] !== '') {
        $where[] = 's.brand_name LIKE :brand';
    }
    if ($filters['state'] !== '') {
        $where[] = 'c.state = :state';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(c.fuel_code = :fuel OR f.name = :fuel)';
    }
    fuelauApplyFuelLocationFilters($where, $distanceSelect, $filters, 's.latitude', 's.longitude');

    $sql = "
        SELECT
            'nsw' AS source,
            c.state,
            c.station_code AS station_id,
            s.name AS station_name,
            s.address,
            NULL AS postcode,
            s.brand_name,
            s.latitude,
            s.longitude,
            c.fuel_code,
            f.name AS fuel_name,
            c.price AS price_raw,
            c.price AS price,
            (
                SELECT h.price
                FROM nsw_site_prices_history h
                WHERE h.state = c.state
                  AND h.station_code = c.station_code
                  AND h.fuel_code = c.fuel_code
                  AND h.last_updated_at < c.last_updated_at
                ORDER BY h.last_updated_at DESC
                LIMIT 1
            ) AS previous_price,
            c.last_updated_at AS updated_at,
            c.last_seen_at,
            {$distanceSelect}
        FROM nsw_site_prices_current c
        INNER JOIN nsw_stations s
            ON s.state = c.state
           AND s.station_code = c.station_code
        INNER JOIN nsw_fuel_types f
            ON f.state = c.state
           AND f.fuel_code = c.fuel_code
        WHERE " . implode(' AND ', $where) . "
        ORDER BY " . ($filters['lat'] !== null && $filters['lon'] !== null ? 'distance_km ASC,' : '') . " c.last_updated_at DESC
        LIMIT :limit
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindFuelFilters($statement, $filters, true);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauVicFuelRows(PDO $pdo, array $filters): array
{
    $distanceSelect = 'NULL AS distance_km';
    $where = ['1=1'];
    if ($filters['search'] !== '') {
        $where[] = '(s.name LIKE :search OR s.address LIKE :search OR b.name LIKE :search)';
    }
    if ($filters['brand'] !== '') {
        $where[] = 'b.name LIKE :brand';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(c.fuel_code = :fuel OR f.name = :fuel)';
    }
    fuelauApplyFuelLocationFilters($where, $distanceSelect, $filters, 's.latitude', 's.longitude');

    $sql = "
        SELECT
            'vic' AS source,
            'VIC' AS state,
            s.station_id AS station_id,
            s.name AS station_name,
            s.address,
            NULL AS postcode,
            b.name AS brand_name,
            s.latitude,
            s.longitude,
            c.fuel_code,
            f.name AS fuel_name,
            c.price AS price_raw,
            c.price AS price,
            (
                SELECT h.price
                FROM vic_site_prices_history h
                WHERE h.station_id = c.station_id
                  AND h.fuel_code = c.fuel_code
                  AND h.updated_at_utc < c.updated_at_utc
                ORDER BY h.updated_at_utc DESC
                LIMIT 1
            ) AS previous_price,
            c.updated_at_utc AS updated_at,
            c.last_seen_at,
            {$distanceSelect}
        FROM vic_site_prices_current c
        INNER JOIN vic_stations s ON s.station_id = c.station_id
        INNER JOIN vic_fuel_types f ON f.fuel_code = c.fuel_code
        LEFT JOIN vic_brands b ON b.brand_id = s.brand_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY " . ($filters['lat'] !== null && $filters['lon'] !== null ? 'distance_km ASC,' : '') . " c.updated_at_utc DESC
        LIMIT :limit
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindFuelFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauWaFuelRows(PDO $pdo, array $filters): array
{
    $distanceSelect = 'NULL AS distance_km';
    $where = ['1=1'];
    $bindFilters = $filters;
    if ($filters['state'] !== '' && $filters['state'] !== 'WA') {
        $where[] = '1=0';
        $bindFilters['state'] = '';
    } elseif ($filters['state'] === 'WA') {
        $bindFilters['state'] = '';
    }
    if ($filters['search'] !== '') {
        $where[] = '(s.name LIKE :search OR s.address LIKE :search OR s.suburb LIKE :search OR b.name LIKE :search OR s.site_features LIKE :search OR s.restrictions LIKE :search)';
    }
    if ($filters['brand'] !== '') {
        $where[] = 'b.name LIKE :brand';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(CAST(c.fuel_code AS CHAR) = :fuel OR f.name = :fuel)';
    }
    fuelauApplyFuelLocationFilters($where, $distanceSelect, $filters, 's.latitude', 's.longitude');

    $sql = "
        SELECT
            'wa' AS source,
            'WA' AS state,
            s.station_id AS station_id,
            s.name AS station_name,
            s.address,
            NULL AS postcode,
            s.suburb,
            b.name AS brand_name,
            s.latitude,
            s.longitude,
            c.fuel_code,
            f.name AS fuel_name,
            c.price AS price_raw,
            c.price AS price,
            (
                SELECT h.price
                FROM wa_site_prices_history h
                WHERE h.station_id = c.station_id
                  AND h.fuel_code = c.fuel_code
                  AND h.price_date < c.price_date
                ORDER BY h.price_date DESC
                LIMIT 1
            ) AS previous_price,
            c.price_date AS updated_at,
            {$distanceSelect}
        FROM wa_site_prices_current c
        INNER JOIN wa_stations s ON s.station_id = c.station_id
        INNER JOIN wa_fuel_types f ON f.fuel_code = c.fuel_code
        LEFT JOIN wa_brands b ON b.brand_id = s.brand_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY " . ($filters['lat'] !== null && $filters['lon'] !== null ? 'distance_km ASC,' : '') . " c.price_date DESC, c.price ASC
        LIMIT :limit
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindFuelFilters($statement, $bindFilters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNtFuelRows(PDO $pdo, array $filters): array
{
    $distanceSelect = 'NULL AS distance_km';
    $where = ['1=1'];
    $bindFilters = $filters;
    if ($filters['state'] !== '' && $filters['state'] !== 'NT') {
        $where[] = '1=0';
        $bindFilters['state'] = '';
    } elseif ($filters['state'] === 'NT') {
        $bindFilters['state'] = '';
    }
    if ($filters['search'] !== '') {
        $where[] = '(s.name LIKE :search OR s.address LIKE :search OR s.suburb LIKE :search OR b.name LIKE :search)';
    }
    if ($filters['brand'] !== '') {
        $where[] = 'b.name LIKE :brand';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(CAST(c.fuel_code AS CHAR) = :fuel OR f.name = :fuel)';
    }
    fuelauApplyFuelLocationFilters($where, $distanceSelect, $filters, 's.latitude', 's.longitude');

    $sql = "
        SELECT
            'nt' AS source,
            'NT' AS state,
            s.station_id AS station_id,
            s.name AS station_name,
            s.address,
            s.postcode,
            s.suburb,
            b.name AS brand_name,
            s.latitude,
            s.longitude,
            c.fuel_code,
            f.name AS fuel_name,
            c.price AS price_raw,
            c.price AS price,
            c.is_available AS is_available,
            (
                SELECT h.price
                FROM nt_site_prices_history h
                WHERE h.station_id = c.station_id
                  AND h.fuel_code = c.fuel_code
                  AND h.observed_at_utc < c.observed_at_utc
                ORDER BY h.observed_at_utc DESC
                LIMIT 1
            ) AS previous_price,
            c.observed_at_utc AS updated_at,
            c.last_seen_at,
            {$distanceSelect}
        FROM nt_site_prices_current c
        INNER JOIN nt_stations s ON s.station_id = c.station_id
        INNER JOIN nt_fuel_types f ON f.fuel_code = c.fuel_code
        LEFT JOIN nt_brands b ON b.brand_id = s.brand_id
        WHERE c.is_available = 1
          AND c.price IS NOT NULL
          AND " . implode(' AND ', $where) . "
        ORDER BY " . ($filters['lat'] !== null && $filters['lon'] !== null ? 'distance_km ASC,' : '') . " c.observed_at_utc DESC
        LIMIT :limit
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindFuelFilters($statement, $bindFilters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNormalizedFuelRows(PDO $pdo, array $filters): array
{
    $rows = [];

    foreach (fuelauFuelSourcesForFilters($filters) as $source) {
        $sourceRows = match ($source) {
            'qld' => fuelauQldFuelRows($pdo, $filters),
            'sa' => fuelauSaFuelRows($pdo, $filters),
            'nsw' => fuelauNswFuelRows($pdo, $filters),
            'vic' => fuelauVicFuelRows($pdo, $filters),
            'wa' => fuelauWaFuelRows($pdo, $filters),
            'nt' => fuelauNtFuelRows($pdo, $filters),
            default => throw new LogicException("Unsupported normalized fuel provider: {$source}"),
        };
        if ($sourceRows !== []) {
            $rows = array_merge($rows, $sourceRows);
        }
    }

    usort(
        $rows,
        static function (array $left, array $right) use ($filters): int {
            $leftDistance = $left['distance_km'];
            $rightDistance = $right['distance_km'];
            if ($filters['lat'] !== null && $filters['lon'] !== null) {
                if ($leftDistance !== null && $rightDistance !== null) {
                    return (float) $leftDistance <=> (float) $rightDistance;
                }
                if ($leftDistance !== null) {
                    return -1;
                }
                if ($rightDistance !== null) {
                    return 1;
                }
            }
            return strcmp((string) $right['updated_at'], (string) $left['updated_at']);
        }
    );

    return array_slice($rows, 0, $filters['limit']);
}

function fuelauRouteCandidateDistanceKm(array $left, array $right): float
{
    $earthRadiusKm = 6371.0;
    $lat1 = deg2rad((float) $left['lat']);
    $lat2 = deg2rad((float) $right['lat']);
    $latDelta = $lat2 - $lat1;
    $lonDelta = deg2rad((float) $right['lon'] - (float) $left['lon']);
    $haversine = sin($latDelta / 2) ** 2
        + cos($lat1) * cos($lat2) * sin($lonDelta / 2) ** 2;

    return $earthRadiusKm * 2 * atan2(sqrt($haversine), sqrt(max(0.0, 1 - $haversine)));
}

/**
 * @param array<mixed> $points
 * @return list<array{lat: float, lon: float}>
 */
function fuelauNormalizeRouteCandidatePoints(array $points): array
{
    if ($points === [] || count($points) > 100) {
        throw new InvalidArgumentException('Route candidate lookup requires between 1 and 100 points.');
    }

    $normalized = [];
    foreach ($points as $point) {
        if (!is_array($point) || !is_numeric((string) ($point['lat'] ?? null)) || !is_numeric((string) ($point['lon'] ?? null))) {
            throw new InvalidArgumentException('Each route candidate point requires numeric lat and lon values.');
        }

        $lat = (float) $point['lat'];
        $lon = (float) $point['lon'];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new InvalidArgumentException('Route candidate point coordinates are out of range.');
        }
        $normalized[] = ['lat' => $lat, 'lon' => $lon];
    }

    return $normalized;
}

function fuelauRouteCandidateBounds(array $points, float $radiusKm): array
{
    $minLat = 90.0;
    $maxLat = -90.0;
    $minLon = 180.0;
    $maxLon = -180.0;

    foreach ($points as $point) {
        $latDelta = $radiusKm / 111.0;
        $longitudeScale = max(0.1, abs(cos(deg2rad((float) $point['lat']))));
        $lonDelta = $radiusKm / (111.0 * $longitudeScale);
        $minLat = min($minLat, (float) $point['lat'] - $latDelta);
        $maxLat = max($maxLat, (float) $point['lat'] + $latDelta);
        $minLon = min($minLon, (float) $point['lon'] - $lonDelta);
        $maxLon = max($maxLon, (float) $point['lon'] + $lonDelta);
    }

    return [
        'min_lat' => max(-90.0, $minLat),
        'max_lat' => min(90.0, $maxLat),
        'min_lon' => max(-180.0, $minLon),
        'max_lon' => min(180.0, $maxLon),
    ];
}

function fuelauRouteCandidateRows(
    PDO $pdo,
    array $points,
    string $fuel,
    float $radiusKm = 25.0,
    int $limit = 2000
): array {
    $points = fuelauNormalizeRouteCandidatePoints($points);
    $radiusKm = max(0.1, min(100.0, $radiusKm));
    $limit = fuelauClampInt($limit, 1, 5000);
    $bounds = fuelauRouteCandidateBounds($points, $radiusKm);
    $filters = array_merge(
        fuelauFuelRequestFilters([
            'source' => 'all',
            'fuel' => trim($fuel),
            'limit' => 5000,
        ]),
        $bounds
    );
    $rows = fuelauNormalizedFuelRows($pdo, $filters);
    $candidates = [];

    foreach ($rows as $row) {
        if (!is_numeric((string) ($row['latitude'] ?? null)) || !is_numeric((string) ($row['longitude'] ?? null))) {
            continue;
        }

        $stationPoint = [
            'lat' => (float) $row['latitude'],
            'lon' => (float) $row['longitude'],
        ];
        $nearestDistanceKm = INF;
        foreach ($points as $point) {
            $nearestDistanceKm = min(
                $nearestDistanceKm,
                fuelauRouteCandidateDistanceKm($point, $stationPoint)
            );
        }
        if ($nearestDistanceKm > $radiusKm) {
            continue;
        }

        $row['distance_km'] = round($nearestDistanceKm, 3);
        $key = implode('|', [
            (string) ($row['source'] ?? ''),
            (string) ($row['station_id'] ?? ''),
            (string) ($row['fuel_code'] ?? ''),
        ]);
        $existing = $candidates[$key] ?? null;
        if (
            !is_array($existing)
            || (float) $row['distance_km'] < (float) $existing['distance_km']
            || (
                (float) $row['distance_km'] === (float) $existing['distance_km']
                && (float) ($row['price'] ?? INF) < (float) ($existing['price'] ?? INF)
            )
        ) {
            $candidates[$key] = $row;
        }
    }

    $candidates = array_values($candidates);
    usort(
        $candidates,
        static function (array $left, array $right): int {
            $distanceComparison = (float) $left['distance_km'] <=> (float) $right['distance_km'];
            if ($distanceComparison !== 0) {
                return $distanceComparison;
            }

            return (float) ($left['price'] ?? INF) <=> (float) ($right['price'] ?? INF);
        }
    );

    return array_slice($candidates, 0, $limit);
}

function fuelauCachedRouteCandidateRows(
    PDO $pdo,
    array $points,
    string $fuel,
    float $radiusKm,
    int $limit,
    string $cacheDirectory,
    int $ttlSeconds = 30
): array {
    $normalizedPoints = fuelauNormalizeRouteCandidatePoints($points);
    $cacheKey = hash('sha256', json_encode([
        'points' => $normalizedPoints,
        'fuel' => trim($fuel),
        'radius_km' => round($radiusKm, 2),
        'limit' => $limit,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    $cachePath = rtrim($cacheDirectory, '/') . "/{$cacheKey}.json";

    if (is_file($cachePath) && filemtime($cachePath) >= time() - max(1, $ttlSeconds)) {
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rows = fuelauRouteCandidateRows($pdo, $normalizedPoints, $fuel, $radiusKm, $limit);
    if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
        return $rows;
    }

    if (random_int(1, 100) === 1) {
        $expiration = time() - max(600, $ttlSeconds * 10);
        foreach (glob(rtrim($cacheDirectory, '/') . '/*.json') ?: [] as $candidate) {
            if (is_file($candidate) && filemtime($candidate) < $expiration) {
                unlink($candidate);
            }
        }
    }

    $encoded = json_encode($rows, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return $rows;
    }

    $temporaryPath = $cachePath . '.' . getmypid() . '.tmp';
    if (file_put_contents($temporaryPath, $encoded, LOCK_EX) !== false) {
        chmod($temporaryPath, 0664);
        rename($temporaryPath, $cachePath);
    }

    return $rows;
}

/**
 * @param list<list<array<string, mixed>>> $windows
 * @return list<array<string, mixed>>
 */
function fuelauMergeCoverageCandidateWindows(array $windows, int $limit): array
{
    $limit = fuelauClampInt($limit, 1, 5_000);
    $windows = array_map(
        static fn (array $rows): array => array_values($rows),
        array_values($windows),
    );
    $offsets = array_fill(0, count($windows), 0);
    $selected = [];
    $seen = [];

    do {
        $advanced = false;
        foreach ($windows as $windowIndex => $rows) {
            while ($offsets[$windowIndex] < count($rows)) {
                $row = $rows[$offsets[$windowIndex]];
                $offsets[$windowIndex]++;
                $key = implode('|', [
                    strtolower(trim((string) ($row['source'] ?? ''))),
                    strtoupper(trim((string) ($row['state'] ?? ''))),
                    trim((string) ($row['station_id'] ?? '')),
                    trim((string) ($row['fuel_code'] ?? $row['fuel_name'] ?? 'fuel')),
                ]);
                if ($key === '|||fuel' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $selected[] = $row;
                $advanced = true;
                break;
            }
            if (count($selected) >= $limit) {
                break 2;
            }
        }
    } while ($advanced);

    return $selected;
}

/**
 * Split long corridors into bounded local database windows before applying the
 * global result cap. Round-robin merging prevents dense origin regions from
 * displacing sparse downstream coverage.
 *
 * @param list<array{lat: float, lon: float}> $points
 * @return list<array<string, mixed>>
 */
function fuelauCoverageBalancedRouteCandidateRows(
    PDO $pdo,
    array $points,
    string $fuel,
    float $radiusKm = 75.0,
    int $limit = 5_000,
    int $windowPointCount = 10,
): array {
    $points = fuelauNormalizeRouteCandidatePoints($points);
    $limit = fuelauClampInt($limit, 1, 5_000);
    $windowPointCount = fuelauClampInt($windowPointCount, 2, 20);
    $pointWindows = array_chunk($points, $windowPointCount);
    $perWindowLimit = min(
        1_000,
        max(100, (int) ceil(($limit / count($pointWindows)) * 2)),
    );
    $rowWindows = [];
    foreach ($pointWindows as $window) {
        $rowWindows[] = fuelauRouteCandidateRows(
            $pdo,
            $window,
            $fuel,
            $radiusKm,
            $perWindowLimit,
        );
    }

    return fuelauMergeCoverageCandidateWindows($rowWindows, $limit);
}

/**
 * @param list<array{lat: float, lon: float}> $points
 * @return list<array<string, mixed>>
 */
function fuelauCachedCoverageBalancedRouteCandidateRows(
    PDO $pdo,
    array $points,
    string $fuel,
    float $radiusKm,
    int $limit,
    string $cacheDirectory,
    int $ttlSeconds = 30,
): array {
    $points = fuelauNormalizeRouteCandidatePoints($points);
    $cacheKey = hash('sha256', json_encode([
        'version' => 1,
        'points' => $points,
        'fuel' => trim($fuel),
        'radius_km' => round($radiusKm, 2),
        'limit' => $limit,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    $cachePath = rtrim($cacheDirectory, '/') . "/coverage-{$cacheKey}.json";

    return fuelauRememberArray(
        $cachePath,
        $ttlSeconds,
        static fn (): array => fuelauCoverageBalancedRouteCandidateRows(
            $pdo,
            $points,
            $fuel,
            $radiusKm,
            $limit,
        ),
    );
}

function fuelauRememberArray(
    string $cachePath,
    int $ttlSeconds,
    callable $loader
): array {
    $ttlSeconds = max(1, $ttlSeconds);
    $readCache = static function () use ($cachePath, $ttlSeconds): ?array {
        if (!is_file($cachePath) || filemtime($cachePath) < time() - $ttlSeconds) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($cachePath), true);
        return is_array($decoded) ? $decoded : null;
    };

    $cached = $readCache();
    if (is_array($cached)) {
        return $cached;
    }

    $directory = dirname($cachePath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return $loader();
    }
    $lock = fopen($cachePath . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return $loader();
    }

    try {
        $cached = $readCache();
        if (is_array($cached)) {
            return $cached;
        }

        $value = $loader();
        if (!is_array($value)) {
            throw new RuntimeException('Cached loader must return an array.');
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return $value;
        }

        $temporaryPath = $cachePath . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) !== false) {
            chmod($temporaryPath, 0664);
            rename($temporaryPath, $cachePath);
        }
        return $value;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function fuelauCachedFuelSourceSummary(
    PDO $pdo,
    string $cacheDirectory,
    int $ttlSeconds = 300
): array {
    return fuelauRememberArray(
        rtrim($cacheDirectory, '/') . '/fuel-source-summary.json',
        $ttlSeconds,
        static fn (): array => fuelauFuelSourceSummary($pdo)
    );
}

function fuelauCachedFuelOptions(
    PDO $pdo,
    string $cacheDirectory,
    int $ttlSeconds = 300
): array {
    return fuelauRememberArray(
        rtrim($cacheDirectory, '/') . '/fuel-options.json',
        $ttlSeconds,
        static fn (): array => fuelauFuelOptions($pdo)
    );
}

function fuelauCachedHistoricalSeries(
    PDO $pdo,
    array $filters,
    string $cacheDirectory,
    int $ttlSeconds = 300
): array {
    $cacheKey = hash('sha256', json_encode([
        'source' => (string) ($filters['source'] ?? ''),
        'state' => (string) ($filters['state'] ?? ''),
        'fuel' => (string) ($filters['fuel'] ?? ''),
        'period' => (string) ($filters['period'] ?? ''),
        'lat' => isset($filters['lat']) ? round((float) $filters['lat'], 4) : null,
        'lon' => isset($filters['lon']) ? round((float) $filters['lon'], 4) : null,
        'radius_km' => isset($filters['radius_km']) ? round((float) $filters['radius_km'], 1) : null,
    ], JSON_UNESCAPED_SLASHES) ?: '');

    return fuelauRememberArray(
        rtrim($cacheDirectory, '/') . "/fuel-history-{$cacheKey}.json",
        $ttlSeconds,
        static fn (): array => fuelauHistoricalSeries($pdo, $filters)
    );
}

function fuelauFuelSourceSummary(PDO $pdo): array
{
    $queries = [
        'qld' => [
            'stations' => 'SELECT COUNT(*) FROM fpq_sites',
            'current_prices' => 'SELECT COUNT(*) FROM fpq_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(transaction_date_utc), "%Y-%m-%d %H:%i:%s") FROM fpq_site_prices_current',
            'last_checked' => 'SELECT DATE_FORMAT(MAX(last_seen_at), "%Y-%m-%d %H:%i:%s") FROM fpq_site_prices_current',
        ],
        'sa' => [
            'stations' => 'SELECT COUNT(*) FROM sa_stations',
            'current_prices' => 'SELECT COUNT(*) FROM sa_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(transaction_date_utc), "%Y-%m-%d %H:%i:%s") FROM sa_site_prices_current',
            'last_checked' => 'SELECT DATE_FORMAT(MAX(last_seen_at), "%Y-%m-%d %H:%i:%s") FROM sa_site_prices_current',
        ],
        'nsw' => [
            'stations' => 'SELECT COUNT(*) FROM nsw_stations WHERE state = "NSW"',
            'current_prices' => 'SELECT COUNT(*) FROM nsw_site_prices_current WHERE state = "NSW"',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(last_updated_at), "%Y-%m-%d %H:%i:%s") FROM nsw_site_prices_current WHERE state = "NSW"',
            'last_checked' => 'SELECT DATE_FORMAT(MAX(last_seen_at), "%Y-%m-%d %H:%i:%s") FROM nsw_site_prices_current WHERE state = "NSW"',
        ],
        'tas' => [
            'stations' => 'SELECT COUNT(*) FROM nsw_stations WHERE state = "TAS"',
            'current_prices' => 'SELECT COUNT(*) FROM nsw_site_prices_current WHERE state = "TAS"',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(last_updated_at), "%Y-%m-%d %H:%i:%s") FROM nsw_site_prices_current WHERE state = "TAS"',
            'last_checked' => 'SELECT DATE_FORMAT(MAX(last_seen_at), "%Y-%m-%d %H:%i:%s") FROM nsw_site_prices_current WHERE state = "TAS"',
        ],
        'vic' => [
            'stations' => 'SELECT COUNT(*) FROM vic_stations',
            'current_prices' => 'SELECT COUNT(*) FROM vic_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(updated_at_utc), "%Y-%m-%d %H:%i:%s") FROM vic_site_prices_current',
            'last_checked' => 'SELECT DATE_FORMAT(MAX(last_seen_at), "%Y-%m-%d %H:%i:%s") FROM vic_site_prices_current',
        ],
        'wa' => [
            'stations' => 'SELECT COUNT(*) FROM wa_stations',
            'current_prices' => 'SELECT COUNT(*) FROM wa_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(price_date), "%Y-%m-%d") FROM wa_site_prices_current',
        ],
        'nt' => [
            'stations' => 'SELECT COUNT(*) FROM nt_stations',
            'current_prices' => 'SELECT COUNT(*) FROM nt_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(observed_at_utc), "%Y-%m-%d %H:%i:%s") FROM nt_site_prices_current',
            'last_checked' => 'SELECT DATE_FORMAT(MAX(last_seen_at), "%Y-%m-%d %H:%i:%s") FROM nt_site_prices_current',
        ],
    ];

    $summary = [];
    foreach ($queries as $source => $sourceQueries) {
        $summary[$source] = [];
        foreach ($sourceQueries as $key => $sql) {
            $value = $pdo->query($sql)->fetchColumn();
            $summary[$source][$key] = is_numeric((string) $value) ? (int) $value : $value;
        }
    }

    return $summary;
}

function fuelauFuelOptionRows(PDO $pdo): array
{
    $sql = "
        SELECT DISTINCT
            CAST('qld' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            CAST('QLD' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS state,
            CAST(fuel_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_code,
            CAST(name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_name
        FROM fpq_fuel_types
        UNION ALL
        SELECT DISTINCT
            CAST('sa' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            CAST('SA' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS state,
            CAST(fuel_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_code,
            CAST(name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_name
        FROM sa_fuel_types
        UNION ALL
        SELECT DISTINCT
            CAST('nsw' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            CAST(state AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS state,
            CAST(fuel_code AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_code,
            CAST(name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_name
        FROM nsw_fuel_types
        UNION ALL
        SELECT DISTINCT
            CAST('vic' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            CAST('VIC' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS state,
            CAST(fuel_code AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_code,
            CAST(name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_name
        FROM vic_fuel_types
        UNION ALL
        SELECT DISTINCT
            CAST('wa' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            CAST('WA' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS state,
            CAST(fuel_code AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_code,
            CAST(name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_name
        FROM wa_fuel_types
        UNION ALL
        SELECT DISTINCT
            CAST('nt' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source,
            CAST('NT' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS state,
            CAST(fuel_code AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_code,
            CAST(name AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS fuel_name
        FROM nt_fuel_types
        ORDER BY source, state, fuel_name
    ";

    return $pdo->query($sql)->fetchAll();
}

function fuelauFuelOptions(PDO $pdo): array
{
    $rows = fuelauFuelOptionRows($pdo);
    $summary = fuelauFuelSourceSummary($pdo);
    $sources = [
        ['value' => 'all', 'label' => 'All Sources'],
        ['value' => 'qld', 'label' => 'QLD'],
        ['value' => 'sa', 'label' => 'SA'],
        ['value' => 'nsw', 'label' => 'NSW'],
        ['value' => 'wa', 'label' => 'WA'],
    ];
    if (($summary['nt']['stations'] ?? 0) > 0 || ($summary['nt']['current_prices'] ?? 0) > 0) {
        $sources[] = ['value' => 'nt', 'label' => 'NT'];
    }
    if (($summary['tas']['stations'] ?? 0) > 0 || ($summary['tas']['current_prices'] ?? 0) > 0) {
        $sources[] = ['value' => 'tas', 'label' => 'TAS'];
    }
    if (($summary['vic']['stations'] ?? 0) > 0 || ($summary['vic']['current_prices'] ?? 0) > 0) {
        $sources[] = ['value' => 'vic', 'label' => 'VIC'];
    }

    $states = [['value' => '', 'label' => 'All States']];
    if (($summary['qld']['stations'] ?? 0) > 0 || ($summary['qld']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'QLD', 'label' => 'QLD'];
    }
    if (($summary['nsw']['stations'] ?? 0) > 0 || ($summary['nsw']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'NSW', 'label' => 'NSW'];
    }
    $states[] = ['value' => 'SA', 'label' => 'SA'];
    $states[] = ['value' => 'WA', 'label' => 'WA'];
    if (($summary['tas']['stations'] ?? 0) > 0 || ($summary['tas']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'TAS', 'label' => 'TAS'];
    }
    if (($summary['vic']['stations'] ?? 0) > 0 || ($summary['vic']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'VIC', 'label' => 'VIC'];
    }
    if (($summary['nt']['stations'] ?? 0) > 0 || ($summary['nt']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'NT', 'label' => 'NT'];
    }
    $fuels = [];
    $seen = [];

    foreach ($rows as $row) {
        $key = strtoupper((string) $row['state']) . '|' . (string) $row['fuel_code'] . '|' . (string) $row['fuel_name'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $fuels[] = [
            'value' => (string) $row['fuel_code'],
            'label' => (string) $row['fuel_name'],
            'state' => (string) $row['state'],
            'source' => (string) $row['source'],
        ];
    }

    usort(
        $fuels,
        static fn (array $left, array $right): int => strcmp($left['label'], $right['label'])
    );
    array_unshift($fuels, ['value' => '', 'label' => 'All Fuels']);

    return [
        'sources' => $sources,
        'states' => $states,
        'fuels' => $fuels,
    ];
}

function fuelauHistoricalFilters(?array $query = null): array
{
    $query ??= $_GET;
    $fuel = trim((string) ($query['fuel'] ?? ''));
    $period = strtolower(trim((string) ($query['period'] ?? 'weekly')));
    if (!in_array($period, ['weekly', 'monthly'], true)) {
        $period = 'weekly';
    }
    $latitude = $query['lat'] ?? null;
    $longitude = $query['lon'] ?? null;
    $radiusKm = isset($query['radius_km']) ? max(0.1, (float) $query['radius_km']) : null;
    $sourceAndState = fuelauNormalizeFuelSourceAndState(
        (string) ($query['source'] ?? ''),
        (string) ($query['state'] ?? '')
    );

    return [
        'source' => $sourceAndState['source'],
        'state' => $sourceAndState['state'],
        'fuel' => $fuel,
        'period' => $period,
        'lat' => is_numeric((string) $latitude) ? (float) $latitude : null,
        'lon' => is_numeric((string) $longitude) ? (float) $longitude : null,
        'radius_km' => $radiusKm,
    ];
}

function fuelauHistoryBucketCte(string $period): string
{
    if ($period === 'monthly') {
        return <<<'SQL'
WITH RECURSIVE buckets AS (
    SELECT
        DATE_FORMAT(
            DATE(UTC_TIMESTAMP() + INTERVAL 10 HOUR) - INTERVAL 11 MONTH,
            '%Y-%m-01'
        ) AS bucket_date,
        TIMESTAMP(
            DATE_FORMAT(
                DATE(UTC_TIMESTAMP() + INTERVAL 10 HOUR) - INTERVAL 10 MONTH,
                '%Y-%m-01'
            )
        ) - INTERVAL 10 HOUR AS bucket_end_utc
    UNION ALL
    SELECT
        DATE_FORMAT(DATE(bucket_date) + INTERVAL 1 MONTH, '%Y-%m-01'),
        TIMESTAMP(DATE(bucket_date) + INTERVAL 2 MONTH) - INTERVAL 10 HOUR
    FROM buckets
    WHERE DATE(bucket_date) < DATE_FORMAT(DATE(UTC_TIMESTAMP() + INTERVAL 10 HOUR), '%Y-%m-01')
)
SQL;
    }

    return <<<'SQL'
WITH RECURSIVE buckets AS (
    SELECT
        DATE(UTC_TIMESTAMP() + INTERVAL 10 HOUR) - INTERVAL 41 DAY AS bucket_date,
        TIMESTAMP(DATE(UTC_TIMESTAMP() + INTERVAL 10 HOUR) - INTERVAL 40 DAY)
            - INTERVAL 10 HOUR AS bucket_end_utc
    UNION ALL
    SELECT
        bucket_date + INTERVAL 1 DAY,
        bucket_end_utc + INTERVAL 1 DAY
    FROM buckets
    WHERE bucket_date < DATE(UTC_TIMESTAMP() + INTERVAL 10 HOUR)
)
SQL;
}

function fuelauEffectiveHistoryQuery(
    string $period,
    string $source,
    string $stateExpression,
    string $currentTable,
    string $historyTable,
    array $keyColumns,
    string $timeColumn,
    string $eligibleJoins,
    array $where,
    string $priceExpression,
    string $validStateCondition,
    bool $groupByState = false,
): string {
    $keys = implode(', ', array_map(
        static fn (string $column): string => "c.`{$column}`",
        $keyColumns,
    ));
    $historyKeys = implode(', ', array_map(
        static fn (string $column): string => "h.`{$column}`",
        $keyColumns,
    ));
    $keyJoin = static fn (string $left, string $right): string => implode(
        ' AND ',
        array_map(
            static fn (string $column): string => "{$left}.`{$column}` = {$right}.`{$column}`",
            $keyColumns,
        ),
    );
    $groupKeys = implode(', ', array_map(
        static fn (string $column): string => "h.`{$column}`",
        $keyColumns,
    ));
    $partitionKeys = implode(', ', array_map(
        static fn (string $column): string => "k.`{$column}`",
        $keyColumns,
    ));
    $gridKeys = implode(', ', array_map(
        static fn (string $column): string => "k.`{$column}`",
        $keyColumns,
    ));
    $groupState = $groupByState ? ", {$stateExpression}" : '';
    $eventBucketExpression = $period === 'monthly'
        ? "DATE_FORMAT(h.`{$timeColumn}` + INTERVAL 10 HOUR, '%Y-%m-01')"
        : "DATE(h.`{$timeColumn}` + INTERVAL 10 HOUR)";

    return fuelauHistoryBucketCte($period) . ",
eligible_keys AS (
    SELECT {$keys}
    FROM `{$currentTable}` c
    {$eligibleJoins}
    WHERE " . implode(' AND ', $where) . "
),
bounds AS (
    SELECT
        MIN(bucket_date) AS first_date,
        TIMESTAMP(MIN(bucket_date)) - INTERVAL 10 HOUR AS first_start_utc,
        MAX(bucket_end_utc) AS last_end_utc
    FROM buckets
),
daily_events AS (
    SELECT
        {$historyKeys},
        {$eventBucketExpression} AS bucket_date,
        MAX(h.`{$timeColumn}`) AS event_time
    FROM eligible_keys k
    STRAIGHT_JOIN `{$historyTable}` h ON " . $keyJoin('k', 'h') . "
    CROSS JOIN bounds x
    WHERE h.`{$timeColumn}` >= x.first_start_utc
      AND h.`{$timeColumn}` < x.last_end_utc
    GROUP BY {$groupKeys}, {$eventBucketExpression}
),
baseline_events AS (
    SELECT
        {$gridKeys},
        x.first_date AS bucket_date,
        (
            SELECT MAX(prior.`{$timeColumn}`)
            FROM `{$historyTable}` prior
            WHERE " . $keyJoin('prior', 'k') . "
              AND prior.`{$timeColumn}` < x.first_start_utc
        ) AS event_time
    FROM eligible_keys k
    CROSS JOIN bounds x
),
events_per_day AS (
    SELECT event_rows.*, MAX(event_rows.event_time) AS effective_event_time
    FROM (
        SELECT * FROM daily_events
        UNION ALL
        SELECT * FROM baseline_events WHERE event_time IS NOT NULL
    ) event_rows
    GROUP BY " . implode(', ', array_map(
        static fn (string $column): string => "event_rows.`{$column}`",
        $keyColumns,
    )) . ", event_rows.bucket_date
),
effective_grid AS (
    SELECT
        {$gridKeys},
        b.bucket_date,
        MAX(e.effective_event_time) OVER (
            PARTITION BY {$partitionKeys}
            ORDER BY b.bucket_date
            ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
        ) AS effective_event_time
    FROM eligible_keys k
    CROSS JOIN buckets b
    LEFT JOIN events_per_day e
      ON " . $keyJoin('e', 'k') . "
     AND e.bucket_date = b.bucket_date
)
SELECT
    '{$source}' AS source,
    {$stateExpression} AS state,
    g.bucket_date,
    AVG({$priceExpression}) AS average_price,
    MIN({$priceExpression}) AS minimum_price,
    MAX({$priceExpression}) AS maximum_price,
    COUNT(*) AS sample_count
FROM effective_grid g
STRAIGHT_JOIN `{$historyTable}` h
  ON " . $keyJoin('h', 'g') . "
 AND h.`{$timeColumn}` = g.effective_event_time
WHERE g.effective_event_time IS NOT NULL
  AND {$validStateCondition}
GROUP BY g.bucket_date{$groupState}
ORDER BY g.bucket_date ASC";
}

function fuelauQldHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['fuel'] !== '') {
        $where[] = fuelauNumericFuelFilterCondition($filters, 'c.fuel_id', 'f.name');
    }
    fuelauApplyHistoricalLocationFilters($where, $filters, 's.latitude', 's.longitude');

    $sql = fuelauEffectiveHistoryQuery(
        $filters['period'], 'qld', "'QLD'", 'fpq_site_prices_current',
        'fpq_site_prices_history', ['site_id', 'fuel_id'], 'transaction_date_utc',
        'INNER JOIN fpq_fuel_types f ON f.fuel_id = c.fuel_id '
            . 'INNER JOIN fpq_sites s ON s.site_id = c.site_id',
        $where, 'h.price / 10', 'h.price BETWEEN 500 AND 4000',
    );

    $statement = $pdo->prepare($sql);
    fuelauBindHistoricalFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNswHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['state'] !== '') {
        $where[] = 'c.state = :state';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(c.fuel_code = :fuel OR f.name = :fuel)';
    }
    fuelauApplyHistoricalLocationFilters($where, $filters, 's.latitude', 's.longitude');

    $sql = fuelauEffectiveHistoryQuery(
        $filters['period'], 'nsw', 'g.state', 'nsw_site_prices_current',
        'nsw_site_prices_history', ['state', 'station_code', 'fuel_code'], 'last_updated_at',
        'INNER JOIN nsw_fuel_types f ON f.state = c.state AND f.fuel_code = c.fuel_code '
            . 'INNER JOIN nsw_stations s ON s.state = c.state AND s.station_code = c.station_code',
        $where, 'h.price', 'h.price BETWEEN 50 AND 400', true,
    );

    $statement = $pdo->prepare($sql);
    if ($filters['state'] !== '') {
        $statement->bindValue(':state', $filters['state']);
    }
    fuelauBindHistoricalFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauVicHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['fuel'] !== '') {
        $where[] = '(c.fuel_code = :fuel OR f.name = :fuel)';
    }
    fuelauApplyHistoricalLocationFilters($where, $filters, 's.latitude', 's.longitude');

    $sql = fuelauEffectiveHistoryQuery(
        $filters['period'], 'vic', "'VIC'", 'vic_site_prices_current',
        'vic_site_prices_history', ['station_id', 'fuel_code'], 'updated_at_utc',
        'INNER JOIN vic_fuel_types f ON f.fuel_code = c.fuel_code '
            . 'INNER JOIN vic_stations s ON s.station_id = c.station_id',
        $where, 'h.price', 'h.is_available = 1 AND h.price BETWEEN 50 AND 400',
    );

    $statement = $pdo->prepare($sql);
    fuelauBindHistoricalFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauWaHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['state'] !== '' && $filters['state'] !== 'WA') {
        $where[] = '1=0';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(h.fuel_code = :fuel OR f.name = :fuel)';
    }
    fuelauApplyHistoricalLocationFilters($where, $filters, 's.latitude', 's.longitude');

    $selectPeriod = $filters['period'] === 'monthly'
        ? "DATE_FORMAT(h.price_date, '%Y-%m-01')"
        : "DATE(h.price_date)";
    $lookback = $filters['period'] === 'monthly' ? '12 MONTH' : '42 DAY';

    $sql = "
        SELECT
            'wa' AS source,
            'WA' AS state,
            {$selectPeriod} AS bucket_date,
            AVG(h.price) AS average_price,
            MIN(h.price) AS minimum_price,
            MAX(h.price) AS maximum_price,
            COUNT(*) AS sample_count
        FROM wa_site_prices_history h
        INNER JOIN wa_fuel_types f ON f.fuel_code = h.fuel_code
        INNER JOIN wa_stations s ON s.station_id = h.station_id
        WHERE h.price_date >= (CURDATE() - INTERVAL {$lookback})
          AND h.price > 0
          AND " . implode(' AND ', $where) . "
        GROUP BY bucket_date
        ORDER BY bucket_date ASC
    ";

    $statement = $pdo->prepare($sql);
    fuelauBindHistoricalFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNtHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['state'] !== '' && $filters['state'] !== 'NT') {
        $where[] = '1=0';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(c.fuel_code = :fuel OR f.name = :fuel)';
    }
    fuelauApplyHistoricalLocationFilters($where, $filters, 's.latitude', 's.longitude');

    $sql = fuelauEffectiveHistoryQuery(
        $filters['period'], 'nt', "'NT'", 'nt_site_prices_current',
        'nt_site_prices_history', ['station_id', 'fuel_code'], 'observed_at_utc',
        'INNER JOIN nt_fuel_types f ON f.fuel_code = c.fuel_code '
            . 'INNER JOIN nt_stations s ON s.station_id = c.station_id',
        $where, 'h.price', 'h.is_available = 1 AND h.price IS NOT NULL',
    );

    $statement = $pdo->prepare($sql);
    fuelauBindHistoricalFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauSaHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['fuel'] !== '') {
        $where[] = fuelauNumericFuelFilterCondition($filters, 'c.fuel_id', 'f.name');
    }
    fuelauApplyHistoricalLocationFilters($where, $filters, 's.latitude', 's.longitude');

    $sql = fuelauEffectiveHistoryQuery(
        $filters['period'], 'sa', "'SA'", 'sa_site_prices_current',
        'sa_site_prices_history', ['station_id', 'fuel_id'], 'transaction_date_utc',
        'INNER JOIN sa_fuel_types f ON f.fuel_id = c.fuel_id '
            . 'INNER JOIN sa_stations s ON s.station_id = c.station_id',
        $where, 'h.price / 10', 'h.price BETWEEN 500 AND 4000',
    );

    $statement = $pdo->prepare($sql);
    fuelauBindHistoricalFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauHistoricalRows(PDO $pdo, array $filters): array
{
    $rows = [];

    foreach (fuelauFuelSourcesForFilters($filters) as $source) {
        $sourceRows = match ($source) {
            'qld' => fuelauQldHistoryRows($pdo, $filters),
            'sa' => fuelauSaHistoryRows($pdo, $filters),
            'nsw' => fuelauNswHistoryRows($pdo, $filters),
            'vic' => fuelauVicHistoryRows($pdo, $filters),
            'wa' => fuelauWaHistoryRows($pdo, $filters),
            'nt' => fuelauNtHistoryRows($pdo, $filters),
            default => throw new LogicException("Unsupported historical fuel provider: {$source}"),
        };
        if ($sourceRows !== []) {
            $rows = array_merge($rows, $sourceRows);
        }
    }

    return $rows;
}

function fuelauHistoricalSeries(PDO $pdo, array $filters): array
{
    $rows = fuelauHistoricalRows($pdo, $filters);
    $buckets = [];
    foreach ($rows as $row) {
        $bucket = (string) $row['bucket_date'];
        if (!isset($buckets[$bucket])) {
            $buckets[$bucket] = [
                'bucket_date' => $bucket,
                'average_price_total' => 0.0,
                'minimum_price' => null,
                'maximum_price' => null,
                'sample_count' => 0,
            ];
        }
        $sampleCount = (int) $row['sample_count'];
        $average = (float) $row['average_price'];
        $minimum = isset($row['minimum_price']) ? (float) $row['minimum_price'] : null;
        $maximum = isset($row['maximum_price']) ? (float) $row['maximum_price'] : null;

        $buckets[$bucket]['average_price_total'] += $average * $sampleCount;
        $buckets[$bucket]['sample_count'] += $sampleCount;
        $buckets[$bucket]['minimum_price'] = $buckets[$bucket]['minimum_price'] === null
            ? $minimum
            : min((float) $buckets[$bucket]['minimum_price'], (float) $minimum);
        $buckets[$bucket]['maximum_price'] = $buckets[$bucket]['maximum_price'] === null
            ? $maximum
            : max((float) $buckets[$bucket]['maximum_price'], (float) $maximum);
    }

    ksort($buckets);
    $series = [];
    foreach ($buckets as $bucket) {
        if ((int) $bucket['sample_count'] <= 0) {
            continue;
        }
        $series[] = [
            'bucket_date' => $bucket['bucket_date'],
            'average_price' => round($bucket['average_price_total'] / (int) $bucket['sample_count'], 1),
            'minimum_price' => round((float) $bucket['minimum_price'], 1),
            'maximum_price' => round((float) $bucket['maximum_price'], 1),
            'sample_count' => (int) $bucket['sample_count'],
        ];
    }

    return $series;
}
