<?php

declare(strict_types=1);

function fuelauClampInt(int $value, int $minimum, int $maximum): int
{
    return max($minimum, min($maximum, $value));
}

function fuelauFuelRequestFilters(): array
{
    $source = trim((string) ($_GET['source'] ?? 'all'));
    $search = trim((string) ($_GET['q'] ?? ''));
    $state = strtoupper(trim((string) ($_GET['state'] ?? '')));
    $fuel = trim((string) ($_GET['fuel'] ?? ''));
    $brand = trim((string) ($_GET['brand'] ?? ''));
    $limit = fuelauClampInt((int) ($_GET['limit'] ?? 100), 1, 500);
    $latitude = $_GET['lat'] ?? null;
    $longitude = $_GET['lon'] ?? null;
    $radiusKm = isset($_GET['radius_km']) ? max(0.1, (float) $_GET['radius_km']) : null;

    return [
        'source' => $source,
        'search' => $search,
        'state' => $state,
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

function fuelauBindFuelFilters(PDOStatement $statement, array $filters): void
{
    if ($filters['search'] !== '') {
        $statement->bindValue(':search', '%' . $filters['search'] . '%');
    }
    if ($filters['brand'] !== '') {
        $statement->bindValue(':brand', '%' . $filters['brand'] . '%');
    }
    if ($filters['state'] !== '') {
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
    }
    $statement->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
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
        $where[] = '(CAST(c.fuel_id AS CHAR) = :fuel OR f.name = :fuel)';
    }
    if ($filters['lat'] !== null && $filters['lon'] !== null) {
        $distanceSelect = fuelauDistanceExpression('s.latitude', 's.longitude') . ' AS distance_km';
        $where[] = 's.latitude IS NOT NULL AND s.longitude IS NOT NULL';
        if ($filters['radius_km'] !== null) {
            $where[] = fuelauDistanceExpression('s.latitude', 's.longitude') . ' <= :radius_km';
        }
    }

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
            c.transaction_date_utc AS updated_at,
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
    if ($filters['lat'] !== null && $filters['lon'] !== null) {
        $distanceSelect = fuelauDistanceExpression('s.latitude', 's.longitude') . ' AS distance_km';
        $where[] = 's.latitude IS NOT NULL AND s.longitude IS NOT NULL';
        if ($filters['radius_km'] !== null) {
            $where[] = fuelauDistanceExpression('s.latitude', 's.longitude') . ' <= :radius_km';
        }
    }

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
            c.last_updated_at AS updated_at,
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
    fuelauBindFuelFilters($statement, $filters);
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNormalizedFuelRows(PDO $pdo, array $filters): array
{
    $source = strtolower($filters['source']);
    $rows = [];

    if ($source === 'all' || $source === 'qld') {
        $rows = array_merge($rows, fuelauQldFuelRows($pdo, $filters));
    }
    if ($source === 'all' || $source === 'nsw' || $source === 'tas') {
        $nswFilters = $filters;
        if ($source === 'tas') {
            $nswFilters['state'] = 'TAS';
        }
        $rows = array_merge($rows, fuelauNswFuelRows($pdo, $nswFilters));
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

function fuelauFuelSourceSummary(PDO $pdo): array
{
    $queries = [
        'qld' => [
            'stations' => 'SELECT COUNT(*) FROM fpq_sites',
            'current_prices' => 'SELECT COUNT(*) FROM fpq_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(transaction_date_utc), "%Y-%m-%d %H:%i:%s") FROM fpq_site_prices_current',
        ],
        'nsw' => [
            'stations' => 'SELECT COUNT(*) FROM nsw_stations WHERE state = "NSW"',
            'current_prices' => 'SELECT COUNT(*) FROM nsw_site_prices_current WHERE state = "NSW"',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(last_updated_at), "%Y-%m-%d %H:%i:%s") FROM nsw_site_prices_current WHERE state = "NSW"',
        ],
        'tas' => [
            'stations' => 'SELECT COUNT(*) FROM nsw_stations WHERE state = "TAS"',
            'current_prices' => 'SELECT COUNT(*) FROM nsw_site_prices_current WHERE state = "TAS"',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(last_updated_at), "%Y-%m-%d %H:%i:%s") FROM nsw_site_prices_current WHERE state = "TAS"',
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
