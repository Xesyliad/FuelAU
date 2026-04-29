<?php

declare(strict_types=1);

function fuelauClampInt(int $value, int $minimum, int $maximum): int
{
    return max($minimum, min($maximum, $value));
}

function fuelauFuelRequestFilters(): array
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $state = strtoupper(trim((string) ($_GET['state'] ?? '')));
    $requestedSource = trim((string) ($_GET['source'] ?? ''));
    $fuel = trim((string) ($_GET['fuel'] ?? ''));
    $brand = trim((string) ($_GET['brand'] ?? ''));
    $limit = fuelauClampInt((int) ($_GET['limit'] ?? 100), 1, 500);
    $latitude = $_GET['lat'] ?? null;
    $longitude = $_GET['lon'] ?? null;
    $radiusKm = isset($_GET['radius_km']) ? max(0.1, (float) $_GET['radius_km']) : null;
    $source = strtolower($requestedSource);
    if ($source === '') {
        $source = match ($state) {
            'QLD' => 'qld',
            'NSW' => 'nsw',
            'TAS' => 'tas',
            default => 'all',
        };
    }

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
    if ($filters['lat'] !== null && $filters['lon'] !== null) {
        $distanceSelect = fuelauDistanceExpression('s.latitude', 's.longitude') . ' AS distance_km';
        $where[] = 's.latitude IS NOT NULL AND s.longitude IS NOT NULL';
        if ($filters['radius_km'] !== null) {
            $where[] = fuelauDistanceExpression('s.latitude', 's.longitude') . ' <= :radius_km';
        }
    }

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
            c.updated_at_utc AS updated_at,
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
    if ($source === 'all' || $source === 'vic') {
        $vicFilters = $filters;
        if ($source === 'vic') {
            $vicFilters['state'] = 'VIC';
        }
        $rows = array_merge($rows, fuelauVicFuelRows($pdo, $vicFilters));
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
        'vic' => [
            'stations' => 'SELECT COUNT(*) FROM vic_stations',
            'current_prices' => 'SELECT COUNT(*) FROM vic_site_prices_current',
            'latest_update' => 'SELECT DATE_FORMAT(MAX(updated_at_utc), "%Y-%m-%d %H:%i:%s") FROM vic_site_prices_current',
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
        ['value' => 'nsw', 'label' => 'NSW'],
    ];
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
    if (($summary['tas']['stations'] ?? 0) > 0 || ($summary['tas']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'TAS', 'label' => 'TAS'];
    }
    if (($summary['vic']['stations'] ?? 0) > 0 || ($summary['vic']['current_prices'] ?? 0) > 0) {
        $states[] = ['value' => 'VIC', 'label' => 'VIC'];
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

function fuelauHistoricalFilters(): array
{
    $state = strtoupper(trim((string) ($_GET['state'] ?? '')));
    $requestedSource = strtolower(trim((string) ($_GET['source'] ?? '')));
    $fuel = trim((string) ($_GET['fuel'] ?? ''));
    $period = strtolower(trim((string) ($_GET['period'] ?? 'weekly')));
    if (!in_array($period, ['weekly', 'monthly'], true)) {
        $period = 'weekly';
    }
    $source = $requestedSource !== ''
        ? $requestedSource
        : match ($state) {
            'QLD' => 'qld',
            'NSW' => 'nsw',
            'TAS' => 'tas',
            'VIC' => 'vic',
            default => 'all',
        };

    return [
        'source' => $source,
        'state' => $state,
        'fuel' => $fuel,
        'period' => $period,
    ];
}

function fuelauQldHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['fuel'] !== '') {
        $where[] = '(CAST(h.fuel_id AS CHAR) = :fuel OR f.name = :fuel)';
    }

    $selectPeriod = $filters['period'] === 'monthly'
        ? "DATE_FORMAT(h.transaction_date_utc, '%Y-%m-01')"
        : "DATE(h.transaction_date_utc)";
    $lookback = $filters['period'] === 'monthly' ? '12 MONTH' : '42 DAY';

    $sql = "
        SELECT
            'qld' AS source,
            'QLD' AS state,
            {$selectPeriod} AS bucket_date,
            AVG(h.price / 10) AS average_price,
            MIN(h.price / 10) AS minimum_price,
            MAX(h.price / 10) AS maximum_price,
            COUNT(*) AS sample_count
        FROM fpq_site_prices_history h
        INNER JOIN fpq_fuel_types f ON f.fuel_id = h.fuel_id
        WHERE h.transaction_date_utc >= (UTC_TIMESTAMP() - INTERVAL {$lookback})
          AND h.price BETWEEN 500 AND 4000
          AND " . implode(' AND ', $where) . "
        GROUP BY bucket_date
        ORDER BY bucket_date ASC
    ";

    $statement = $pdo->prepare($sql);
    if ($filters['fuel'] !== '') {
        $statement->bindValue(':fuel', $filters['fuel']);
    }
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauNswHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['state'] !== '') {
        $where[] = 'h.state = :state';
    }
    if ($filters['fuel'] !== '') {
        $where[] = '(h.fuel_code = :fuel OR f.name = :fuel)';
    }

    $selectPeriod = $filters['period'] === 'monthly'
        ? "DATE_FORMAT(h.last_updated_at, '%Y-%m-01')"
        : "DATE(h.last_updated_at)";
    $lookback = $filters['period'] === 'monthly' ? '12 MONTH' : '42 DAY';

    $sql = "
        SELECT
            'nsw' AS source,
            h.state,
            {$selectPeriod} AS bucket_date,
            AVG(h.price) AS average_price,
            MIN(h.price) AS minimum_price,
            MAX(h.price) AS maximum_price,
            COUNT(*) AS sample_count
        FROM nsw_site_prices_history h
        INNER JOIN nsw_fuel_types f
            ON f.state = h.state
           AND f.fuel_code = h.fuel_code
        WHERE h.last_updated_at >= (UTC_TIMESTAMP() - INTERVAL {$lookback})
          AND h.price BETWEEN 50 AND 400
          AND " . implode(' AND ', $where) . "
        GROUP BY h.state, bucket_date
        ORDER BY bucket_date ASC
    ";

    $statement = $pdo->prepare($sql);
    if ($filters['state'] !== '') {
        $statement->bindValue(':state', $filters['state']);
    }
    if ($filters['fuel'] !== '') {
        $statement->bindValue(':fuel', $filters['fuel']);
    }
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauVicHistoryRows(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    if ($filters['fuel'] !== '') {
        $where[] = '(h.fuel_code = :fuel OR f.name = :fuel)';
    }

    $selectPeriod = $filters['period'] === 'monthly'
        ? "DATE_FORMAT(h.updated_at_utc, '%Y-%m-01')"
        : "DATE(h.updated_at_utc)";
    $lookback = $filters['period'] === 'monthly' ? '12 MONTH' : '42 DAY';

    $sql = "
        SELECT
            'vic' AS source,
            'VIC' AS state,
            {$selectPeriod} AS bucket_date,
            AVG(h.price) AS average_price,
            MIN(h.price) AS minimum_price,
            MAX(h.price) AS maximum_price,
            COUNT(*) AS sample_count
        FROM vic_site_prices_history h
        INNER JOIN vic_fuel_types f ON f.fuel_code = h.fuel_code
        WHERE h.updated_at_utc >= (UTC_TIMESTAMP() - INTERVAL {$lookback})
          AND h.price BETWEEN 50 AND 400
          AND " . implode(' AND ', $where) . "
        GROUP BY bucket_date
        ORDER BY bucket_date ASC
    ";

    $statement = $pdo->prepare($sql);
    if ($filters['fuel'] !== '') {
        $statement->bindValue(':fuel', $filters['fuel']);
    }
    $statement->execute();
    return $statement->fetchAll();
}

function fuelauHistoricalSeries(PDO $pdo, array $filters): array
{
    $source = $filters['source'];
    $rows = [];
    if ($source === 'all' || $source === 'qld') {
        $rows = array_merge($rows, fuelauQldHistoryRows($pdo, $filters));
    }
    if ($source === 'all' || $source === 'nsw' || $source === 'tas') {
        $nswFilters = $filters;
        if ($source === 'tas') {
            $nswFilters['state'] = 'TAS';
        } elseif ($source === 'nsw' && $filters['state'] === '') {
            $nswFilters['state'] = 'NSW';
        }
        $rows = array_merge($rows, fuelauNswHistoryRows($pdo, $nswFilters));
    }
    if ($source === 'all' || $source === 'vic') {
        $vicFilters = $filters;
        if ($source === 'vic') {
            $vicFilters['state'] = 'VIC';
        }
        $rows = array_merge($rows, fuelauVicHistoryRows($pdo, $vicFilters));
    }

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
