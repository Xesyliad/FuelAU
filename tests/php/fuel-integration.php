<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/fuel.php';

$failures = [];
$states = FUELAU_FUEL_STATES;

try {
    $unclassified = fuelauUnclassifiedRouteFuelOptions(fuelauFuelOptionRows(fuelauPdo()));
    if ($unclassified !== []) {
        throw new RuntimeException(
            'Unclassified upstream products: ' . json_encode($unclassified, JSON_THROW_ON_ERROR),
        );
    }
    fwrite(STDOUT, "PASS every live Australian fuel product is explicitly classified\n");
} catch (Throwable $exception) {
    $failures['live Australian fuel product classification'] = $exception->getMessage();
    fwrite(STDERR, "FAIL live Australian fuel product classification: {$exception->getMessage()}\n");
}

foreach (['unleaded_91_plus' => 'petrol', 'diesel' => 'diesel', 'lpg' => 'lpg'] as $profile => $fuelClass) {
    try {
        $registry = fuelauAustralianFuelProductRegistry();
        $rows = fuelauRouteCandidateRows(
            fuelauPdo(),
            [
                ['lat' => -27.4698, 'lon' => 153.0251],
                ['lat' => -33.8688, 'lon' => 151.2093],
                ['lat' => -37.8136, 'lon' => 144.9631],
            ],
            $profile,
            75,
            5_000,
        );
        foreach ($rows as $row) {
            $source = strtolower((string) ($row['source'] ?? ''));
            $code = (string) ($row['fuel_code'] ?? '');
            if (($registry[$source][$code]['class'] ?? null) !== $fuelClass) {
                throw new RuntimeException("Profile {$profile} returned incompatible {$source}:{$code}.");
            }
        }
        fwrite(STDOUT, "PASS grouped route candidates remain inside {$fuelClass} class\n");
    } catch (Throwable $exception) {
        $failures["{$profile} grouped route candidates"] = $exception->getMessage();
        fwrite(STDERR, "FAIL {$profile} grouped route candidates: {$exception->getMessage()}\n");
    }
}

foreach ($states as $state) {
    foreach (['', 'all'] as $source) {
        $label = sprintf('current source=%s state=%s', $source === '' ? '(inferred)' : $source, $state);

        try {
            $filters = fuelauFuelRequestFilters([
                'source' => $source,
                'state' => $state,
                'limit' => 5,
            ]);
            $rows = fuelauNormalizedFuelRows(fuelauPdo(), $filters);
            foreach ($rows as $row) {
                if (($row['state'] ?? null) !== $state) {
                    throw new RuntimeException(
                        sprintf('Expected state %s, received %s.', $state, (string) ($row['state'] ?? ''))
                    );
                }
            }
            fwrite(STDOUT, "PASS {$label}\n");
        } catch (Throwable $exception) {
            $failures[$label] = $exception->getMessage();
            fwrite(STDERR, "FAIL {$label}: {$exception->getMessage()}\n");
        }
    }
}

try {
    $allFilters = fuelauHistoricalFilters([
        'source' => 'all',
        'state' => 'NSW',
        'period' => 'weekly',
    ]);
    $rows = fuelauHistoricalRows(fuelauPdo(), $allFilters);
    foreach ($rows as $row) {
        if (($row['state'] ?? null) !== 'NSW') {
            throw new RuntimeException(
                sprintf('Expected NSW history, received %s.', (string) ($row['state'] ?? ''))
            );
        }
    }
    fwrite(STDOUT, "PASS historical source=all state=NSW isolation\n");
} catch (Throwable $exception) {
    $failures['historical source=all state=NSW isolation'] = $exception->getMessage();
    fwrite(STDERR, "FAIL historical source=all state=NSW isolation: {$exception->getMessage()}\n");
}

try {
    $radiusKm = 25.0;
    $rows = fuelauRouteCandidateRows(
        fuelauPdo(),
        [
            ['lat' => -27.4698, 'lon' => 153.0251],
            ['lat' => -27.9506, 'lon' => 153.4000],
        ],
        '',
        $radiusKm,
        250
    );
    foreach ($rows as $row) {
        if ((float) ($row['distance_km'] ?? INF) > $radiusKm) {
            throw new RuntimeException('Route candidate lies outside the requested corridor radius.');
        }
    }
    fwrite(STDOUT, "PASS batched route candidate corridor\n");
} catch (Throwable $exception) {
    $failures['batched route candidate corridor'] = $exception->getMessage();
    fwrite(STDERR, "FAIL batched route candidate corridor: {$exception->getMessage()}\n");
}

fwrite(
    STDOUT,
    sprintf("\nFuel integration summary: %d failed\n", count($failures))
);

exit($failures === [] ? 0 : 1);
