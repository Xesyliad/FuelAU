<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/docker.php';
require dirname(__DIR__, 2) . '/src/fuel.php';
require dirname(__DIR__, 2) . '/src/migrations.php';
require dirname(__DIR__, 2) . '/src/routing.php';
require dirname(__DIR__, 2) . '/src/request.php';
require dirname(__DIR__, 2) . '/src/route_optimizer.php';
require dirname(__DIR__, 2) . '/src/route_planning.php';

$tests = [];
$failures = [];

function fuelauTest(string $name, callable $test): void
{
    global $tests;
    $tests[$name] = $test;
}

function fuelauAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected === $actual) {
        return;
    }

    $detail = $message !== '' ? $message . ': ' : '';
    throw new RuntimeException(
        $detail . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function fuelauAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fuelauAssertThrows(string $exceptionClass, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException(
            $message . ': expected ' . $exceptionClass . ', got ' . $exception::class
        );
    }

    throw new RuntimeException($message . ': no exception was thrown');
}

function fuelauWithQuery(array $query, callable $callback): mixed
{
    $original = $_GET;
    $_GET = $query;

    try {
        return $callback();
    } finally {
        $_GET = $original;
    }
}

fuelauTest('container management uses an expiring session and CSRF token', static function (): void {
    $token = bin2hex(random_bytes(24));
    $config = ['CONTAINER_MANAGEMENT_TOKEN' => $token];
    $csrfToken = fuelauContainerManagementLogin($config, $token);

    fuelauAssertTrue(is_string($csrfToken) && strlen($csrfToken) === 64, 'Login must issue a CSRF token');
    fuelauAssertTrue(fuelauContainerManagementAuthorized($config), 'Valid session must authorize management');

    $_SERVER['HTTP_X_FUELAU_CSRF_TOKEN'] = $csrfToken;
    try {
        fuelauAssertTrue(fuelauContainerManagementCsrfValid(), 'Valid CSRF token must be accepted');
    } finally {
        unset($_SERVER['HTTP_X_FUELAU_CSRF_TOKEN']);
        fuelauContainerManagementLogout();
    }
});

fuelauTest('fuel filters infer VIC source', static function (): void {
    $filters = fuelauWithQuery(
        ['state' => 'VIC'],
        static fn (): array => fuelauFuelRequestFilters()
    );

    fuelauAssertSame('vic', $filters['source']);
});

fuelauTest('fuel filters reject unsupported source', static function (): void {
    fuelauAssertThrows(
        InvalidArgumentException::class,
        static fn (): array => fuelauWithQuery(
            ['source' => 'invalid'],
            static fn (): array => fuelauFuelRequestFilters()
        ),
        'Unsupported fuel sources must be rejected'
    );
});

fuelauTest('fuel filters reject unsupported state', static function (): void {
    fuelauAssertThrows(
        InvalidArgumentException::class,
        static fn (): array => fuelauWithQuery(
            ['state' => 'XX'],
            static fn (): array => fuelauFuelRequestFilters()
        ),
        'Unsupported states must be rejected'
    );
});

fuelauTest('all-source NSW filter selects only NSW storage', static function (): void {
    fuelauAssertTrue(
        function_exists('fuelauFuelSourcesForFilters'),
        'fuelauFuelSourcesForFilters() must centralize source/state selection'
    );

    $sources = fuelauFuelSourcesForFilters([
        'source' => 'all',
        'state' => 'NSW',
    ]);
    fuelauAssertSame(['nsw'], $sources);
});

fuelauTest('geocoding results obey requested limit', static function (): void {
    fuelauAssertTrue(
        function_exists('fuelauLimitNominatimResults'),
        'fuelauLimitNominatimResults() must enforce the public result limit'
    );

    $results = fuelauLimitNominatimResults(
        [['id' => 1], ['id' => 2], ['id' => 3]],
        1
    );
    fuelauAssertSame([['id' => 1]], $results);
});

fuelauTest('geocoding retries transient and malformed upstream responses', static function (): void {
    fuelauAssertTrue(
        fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('Invalid JSON response from http://nominatim:8080/search')
        ),
        'Malformed upstream responses should be retried'
    );
    fuelauAssertTrue(
        fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('HTTP 500 from http://nominatim:8080/search: query cancelled')
        ),
        'Nominatim 5xx responses should be retried'
    );
    fuelauAssertTrue(
        !fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('HTTP 400 from http://nominatim:8080/search: invalid query')
        ),
        'Permanent Nominatim client errors should not be retried'
    );
});

fuelauTest('coordinates reject out-of-range longitude', static function (): void {
    fuelauAssertThrows(
        InvalidArgumentException::class,
        static fn (): array => fuelauParseCoordinates('181,-27;153,-27'),
        'Longitude outside -180..180 must be rejected'
    );
});

fuelauTest('coordinates reject excessive waypoint count', static function (): void {
    $coordinates = implode(';', array_fill(0, 101, '153,-27'));
    fuelauAssertThrows(
        InvalidArgumentException::class,
        static fn (): array => fuelauParseCoordinates($coordinates),
        'Excessive route waypoint counts must be rejected'
    );
});

fuelauTest('rate limiter exposes a typed retry interval', static function (): void {
    $bucket = 'regression:' . getmypid() . ':' . bin2hex(random_bytes(8));
    fuelauRateLimit($bucket, 1, 60);

    try {
        fuelauRateLimit($bucket, 1, 60);
    } catch (FuelauRateLimitException $exception) {
        fuelauAssertTrue(
            $exception->retryAfterSeconds() >= 1 && $exception->retryAfterSeconds() <= 60,
            'Retry-After must fit within the configured window'
        );
        return;
    }

    throw new RuntimeException('The second request must be rate limited');
});

fuelauTest('route planner uses bounded request budgets', static function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');
    fuelauAssertTrue(is_string($source), 'Unable to read public/resources/app.js');

    preg_match('/const routePlannerRouteBudgetLimit = (\d+);/', $source, $routeMatch);
    preg_match('/const routePlannerFuelBudgetLimit = (\d+);/', $source, $fuelMatch);

    fuelauAssertTrue(isset($routeMatch[1], $fuelMatch[1]), 'Route planner budgets must be declared');
    fuelauAssertTrue((int) $routeMatch[1] >= 60, 'Route lookup budget must accommodate transcontinental return trips');
    fuelauAssertTrue((int) $routeMatch[1] <= 80, 'Route lookup budget must be at most 80');
    fuelauAssertTrue((int) $fuelMatch[1] <= 50, 'Fuel lookup budget must be at most 50');
});

fuelauTest('route optimizer request resolves practical stop defaults', static function (): void {
    $request = FuelauRouteOptimizationRequest::fromBody([
        'version' => 1,
        'origin' => ['lat' => -27.4698, 'lon' => 153.0251, 'label' => 'Brisbane'],
        'destinations' => [[
            'lat' => -16.9186,
            'lon' => 145.7781,
            'label' => 'Cairns',
        ]],
        'return_mode' => 'one_way',
        'fuel' => [
            'type' => 'Diesel',
            'tank_capacity_l' => 60,
            'starting_fuel_l' => 60,
            'economy_l_per_100km' => 10,
            'reserve_l' => 6,
        ],
        'preferences' => [],
    ]);

    fuelauAssertSame('practical_least_cost', $request->preferences->mode);
    fuelauAssertSame(150.0, $request->preferences->minimumStopSpacingKm);
    fuelauAssertSame(90.0, $request->preferences->minimumStopSpacingMinutes);
    fuelauAssertSame(1000, $request->preferences->minimumNetSavingCents);
    fuelauAssertSame(3000, $request->preferences->driverTimeValueCentsPerHour);
    fuelauAssertSame(60.0, $request->fuel->startingFuelL);
    fuelauAssertSame(6.0, $request->fuel->reserveL);
});

fuelauTest('route optimizer rejects impossible starting fuel and stop counts', static function (): void {
    $base = [
        'version' => 1,
        'origin' => ['lat' => -27.4, 'lon' => 153.0],
        'destinations' => [['lat' => -28.0, 'lon' => 153.0]],
        'return_mode' => 'direct',
        'fuel' => [
            'type' => 'Diesel',
            'tank_capacity_l' => 60,
            'starting_fuel_l' => 61,
            'economy_l_per_100km' => 12,
            'reserve_l' => 6,
        ],
    ];

    fuelauAssertThrows(
        FuelauValidationException::class,
        static fn (): FuelauRouteOptimizationRequest => FuelauRouteOptimizationRequest::fromBody($base),
        'Starting fuel above capacity must be rejected',
    );

    $base['fuel']['starting_fuel_l'] = 60;
    $base['preferences'] = ['maximum_fuel_only_stops' => 21];
    fuelauAssertThrows(
        FuelauValidationException::class,
        static fn (): FuelauRouteOptimizationRequest => FuelauRouteOptimizationRequest::fromBody($base),
        'Excessive fuel-only stop limits must be rejected',
    );
});

fuelauTest('fuel-state optimizer avoids terminal overfill', static function (): void {
    $plan = (new FuelauFuelStateOptimizer())->optimize(
        [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station('station-b', 500_000, 150, 'Station B'),
            new FuelauOptimizerNode('destination', 700_000),
        ],
        new FuelauOptimizerVehicle(
            tankCapacityL: 60,
            startingFuelL: 60,
            reserveL: 6,
            economyLPer100km: 10,
        ),
    );

    fuelauAssertSame(1, $plan->fuelStopCount);
    fuelauAssertSame(16.0, $plan->fuelPurchasedL);
    fuelauAssertSame(2400, $plan->fuelPurchaseCostCents);
    fuelauAssertSame(6.0, $plan->endingFuelL);
    fuelauAssertSame('station-b', $plan->purchases[0]->nodeId);
    fuelauAssertSame(16.0, $plan->purchases[0]->purchaseL);
});

fuelauTest('fuel-state optimizer buys only enough expensive fuel to reach cheaper fuel', static function (): void {
    $plan = (new FuelauFuelStateOptimizer())->optimize(
        [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station('station-a', 50_000, 200, 'Station A'),
            FuelauOptimizerNode::station('station-b', 400_000, 150, 'Station B'),
            new FuelauOptimizerNode('destination', 600_000),
        ],
        new FuelauOptimizerVehicle(
            tankCapacityL: 60,
            startingFuelL: 12,
            reserveL: 6,
            economyLPer100km: 10,
        ),
    );

    fuelauAssertSame(2, $plan->fuelStopCount);
    fuelauAssertSame(54.0, $plan->fuelPurchasedL);
    fuelauAssertSame(9800, $plan->fuelPurchaseCostCents);
    fuelauAssertSame(6.0, $plan->endingFuelL);
    fuelauAssertSame('station-a', $plan->purchases[0]->nodeId);
    fuelauAssertSame(34.0, $plan->purchases[0]->purchaseL);
    fuelauAssertSame('station-b', $plan->purchases[1]->nodeId);
    fuelauAssertSame(20.0, $plan->purchases[1]->purchaseL);
});

fuelauTest('fuel-state optimizer rejects an unbridgeable range gap', static function (): void {
    fuelauAssertThrows(
        FuelauRouteInfeasibleException::class,
        static fn (): FuelauOptimizerPlan => (new FuelauFuelStateOptimizer())->optimize(
            [
                new FuelauOptimizerNode('origin', 0),
                new FuelauOptimizerNode('destination', 700_000),
            ],
            new FuelauOptimizerVehicle(
                tankCapacityL: 60,
                startingFuelL: 60,
                reserveL: 6,
                economyLPer100km: 10,
            ),
        ),
        'An unbridgeable full-tank range gap must be infeasible',
    );
});

fuelauTest('practical optimizer keeps a short refill only when it is required', static function (): void {
    $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
        [
            new FuelauOptimizerNode('origin', 0, progressS: 0),
            FuelauOptimizerNode::station('safety', 50_000, 150, 'Safety', 1_800),
            new FuelauOptimizerNode('destination', 100_000, progressS: 3_600),
        ],
        new FuelauOptimizerVehicle(60, 12, 6, 10),
    );

    fuelauAssertSame(1, $plan->fuelStopCount);
    fuelauAssertSame(4.0, $plan->purchases[0]->purchaseL);
    fuelauAssertSame('required', $plan->purchases[0]->classification);
    fuelauAssertSame(
        ['minimum_purchase_safety_override'],
        $plan->purchases[0]->reasonCodes,
    );
});

fuelauTest('practical optimizer rejects a chain of short price-chasing stops', static function (): void {
    $nodes = [new FuelauOptimizerNode('origin', 0, progressS: 0)];
    for ($progressKm = 50; $progressKm <= 550; $progressKm += 50) {
        $nodes[] = FuelauOptimizerNode::station(
            "station-{$progressKm}",
            $progressKm * 1000,
            210 - ($progressKm / 50),
            "Station {$progressKm}",
            (int) (($progressKm / 50) * 1_800),
        );
    }
    $nodes[] = new FuelauOptimizerNode('destination', 600_000, progressS: 21_600);

    $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
        $nodes,
        new FuelauOptimizerVehicle(60, 12, 6, 10),
        new FuelauOptimizerPolicy(maximumFuelOnlyStops: 10),
    );

    fuelauAssertSame(2, $plan->fuelStopCount);
    fuelauAssertSame(0, $plan->discretionaryStopCount);
    for ($index = 1; $index < count($plan->purchases); $index++) {
        $previous = $plan->purchases[$index - 1];
        $current = $plan->purchases[$index];
        $tooClose = ($current->progressM - $previous->progressM) < 150_000
            && ($current->progressS - $previous->progressS) < 5_400;
        fuelauAssertTrue(
            !$tooClose || $current->classification === 'required',
            'Discretionary fuel-only stops must respect spacing',
        );
    }
});

fuelauTest('practical optimizer reports trip-wide savings for a strategic stop', static function (): void {
    $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
        [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station('required', 50_000, 200, progressS: 1_800),
            FuelauOptimizerNode::station('strategic', 300_000, 100, progressS: 10_800),
            FuelauOptimizerNode::station('fallback', 550_000, 300, progressS: 19_800),
            new FuelauOptimizerNode('destination', 600_000, progressS: 21_600),
        ],
        new FuelauOptimizerVehicle(60, 12, 6, 10),
        new FuelauOptimizerPolicy(maximumFuelOnlyStops: 3),
    );

    fuelauAssertSame(2, $plan->fuelStopCount);
    fuelauAssertSame('required', $plan->purchases[0]->classification);
    fuelauAssertSame('strategic', $plan->purchases[1]->classification);
    fuelauAssertSame(3_100, $plan->purchases[1]->marginalNetSavingCents);
});

fuelauTest('practical optimizer rejects a stop limit below route feasibility', static function (): void {
    fuelauAssertThrows(
        FuelauRouteInfeasibleException::class,
        static fn (): FuelauOptimizerPlan => (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('first', 50_000, 200),
                FuelauOptimizerNode::station('last', 550_000, 150),
                new FuelauOptimizerNode('destination', 600_000),
            ],
            new FuelauOptimizerVehicle(60, 12, 6, 10),
            new FuelauOptimizerPolicy(maximumFuelOnlyStops: 1),
        ),
        'A user stop limit must not weaken reserve feasibility',
    );
});

fuelauTest('practical optimizer prefers fewer stops within the similar-cost threshold', static function (): void {
    $nodes = [
        new FuelauOptimizerNode('origin', 0),
        FuelauOptimizerNode::station('first', 50_000, 200, progressS: 1_800),
        FuelauOptimizerNode::station('middle', 300_000, 199, progressS: 10_800),
        FuelauOptimizerNode::station('last', 550_000, 198, progressS: 19_800),
        new FuelauOptimizerNode('destination', 600_000, progressS: 21_600),
    ];
    $vehicle = new FuelauOptimizerVehicle(60, 12, 6, 10);
    $commonPolicy = [
        'maximumFuelOnlyStops' => 3,
        'minimumDiscretionaryPurchaseL' => 0,
        'minimumStopSpacingM' => 0,
        'minimumStopSpacingS' => 0,
        'minimumNetSavingCents' => 0,
        'driverTimeValueCentsPerHour' => 0,
        'fuelOnlyStopSeconds' => 0,
    ];
    $optimizer = new FuelauFuelStateOptimizer();
    $strictCostPlan = $optimizer->optimizePractical(
        $nodes,
        $vehicle,
        new FuelauOptimizerPolicy(...$commonPolicy, similarCostCents: 0),
    );
    $similarCostPlan = $optimizer->optimizePractical(
        $nodes,
        $vehicle,
        new FuelauOptimizerPolicy(
            ...$commonPolicy,
            similarCostCents: 500,
        ),
    );

    fuelauAssertSame(3, $strictCostPlan->fuelStopCount);
    fuelauAssertSame(2, $similarCostPlan->fuelStopCount);
    fuelauAssertTrue(
        $similarCostPlan->generalizedCostCents - $strictCostPlan->generalizedCostCents <= 500,
        'The fewer-stop plan must remain within the configured similar-cost threshold',
    );
});

fuelauTest('route corridor projects station progress using OSRM totals', static function (): void {
    $corridor = FuelauRouteCorridor::fromOsrmRoute([
        'distance' => 100_000,
        'duration' => 3_600,
        'geometry' => [
            'coordinates' => [
                [150.0, -30.0],
                [151.0, -30.0],
            ],
        ],
    ]);
    $projection = $corridor->project(-30.1, 150.5);
    $lookupPoints = $corridor->candidateLookupPoints(25_000);

    fuelauAssertSame(50_000, $projection->progressM);
    fuelauAssertSame(1_800, $projection->progressS);
    fuelauAssertTrue(
        $projection->offRouteM >= 11_000 && $projection->offRouteM <= 11_200,
        'Projection must retain the station offset from the route',
    );
    fuelauAssertSame(5, count($lookupPoints));
    fuelauAssertSame(['lat' => -30.0, 'lon' => 150.0], $lookupPoints[0]);
    fuelauAssertSame(['lat' => -30.0, 'lon' => 151.0], $lookupPoints[4]);
});

fuelauTest('corridor candidates preserve coverage before filling dense bins', static function (): void {
    $corridor = new FuelauRouteCorridor(
        distanceM: 300_000,
        durationS: 10_800,
        geometry: [
            ['lat' => -30.0, 'lon' => 150.0],
            ['lat' => -30.0, 'lon' => 153.0],
        ],
    );
    $rows = [
        ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'near-1', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.1, 'price' => 190],
        ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'near-2', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.2, 'price' => 180],
        ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'near-3', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.3, 'price' => 170],
        ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'middle', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 151.2, 'price' => 200],
        ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'remote', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 152.6, 'price' => 210],
    ];

    $input = (new FuelauFixedCorridorCandidateAdapter())->build(
        $corridor,
        $rows,
        maximumCandidates: 3,
    );
    $stationNodes = array_slice($input->nodes, 1, -1);
    $coverageBins = array_map(
        static fn (FuelauOptimizerNode $node): int => intdiv($node->progressM, 50_000),
        $stationNodes,
    );

    fuelauAssertSame(5, $input->eligibleCandidateCount);
    fuelauAssertSame(3, $input->selectedCandidateCount);
    fuelauAssertSame([0, 2, 5], $coverageBins);
});

fuelauTest('corridor candidates use stable station identity and eligibility filters', static function (): void {
    $corridor = new FuelauRouteCorridor(
        distanceM: 100_000,
        durationS: 3_600,
        geometry: [
            ['lat' => -30.0, 'lon' => 150.0],
            ['lat' => -30.0, 'lon' => 151.0],
        ],
    );
    $input = (new FuelauFixedCorridorCandidateAdapter())->build(
        $corridor,
        [
            ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'same', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.5, 'price' => 200],
            ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'same', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.5, 'price' => 190],
            ['source' => 'unofficial', 'state' => 'NSW', 'station_id' => 'bad-source', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.6, 'price' => 100],
            ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'too-far', 'fuel_code' => 'E10', 'latitude' => -31.0, 'longitude' => 150.6, 'price' => 100],
        ],
    );
    $candidate = array_values($input->candidatesByNodeId)[0];

    fuelauAssertSame(1, $input->eligibleCandidateCount);
    fuelauAssertSame('nsw:NSW:same:E10', $candidate->stableId);
    fuelauAssertSame(190.0, $candidate->priceCentsPerL);
});

fuelauTest('projected corridor candidates feed the practical optimizer', static function (): void {
    $corridor = new FuelauRouteCorridor(
        distanceM: 600_000,
        durationS: 21_600,
        geometry: [
            ['lat' => -30.0, 'lon' => 150.0],
            ['lat' => -30.0, 'lon' => 156.0],
        ],
    );
    $input = (new FuelauFixedCorridorCandidateAdapter())->build(
        $corridor,
        [
            ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'required', 'station_name' => 'Required', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 150.5, 'price' => 200],
            ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'strategic', 'station_name' => 'Strategic', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 153.0, 'price' => 100],
            ['source' => 'nsw', 'state' => 'NSW', 'station_id' => 'fallback', 'station_name' => 'Fallback', 'fuel_code' => 'E10', 'latitude' => -30.0, 'longitude' => 155.5, 'price' => 300],
        ],
    );
    $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
        $input->nodes,
        new FuelauOptimizerVehicle(60, 12, 6, 10),
        new FuelauOptimizerPolicy(maximumFuelOnlyStops: 3),
    );

    fuelauAssertSame(2, $plan->fuelStopCount);
    fuelauAssertSame('Required', $plan->purchases[0]->label);
    fuelauAssertSame('Strategic', $plan->purchases[1]->label);
    fuelauAssertSame('strategic', $plan->purchases[1]->classification);
});

fuelauTest('fuel dashboard prevents repeated hidden viewport refreshes', static function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');
    fuelauAssertTrue(is_string($source), 'Unable to read public/resources/app.js');
    fuelauAssertTrue(
        str_contains($source, 'function fuelPricesTabIsActive()'),
        'Viewport refreshes must check whether the fuel tab is active'
    );
    fuelauAssertTrue(
        str_contains($source, 'requestKey === fuelMapViewportLastRequestKey'),
        'Identical viewport requests must be deduplicated'
    );
    fuelauAssertTrue(
        str_contains($source, 'if (!preserveViewport)'),
        'Viewport-only map renders must not resize the map'
    );
    fuelauAssertTrue(
        str_contains($source, 'destroyFuelMap();'),
        'The fuel map must release tile resources while its tab is hidden'
    );
});

fuelauTest('historical radius queries use coordinate bounds', static function (): void {
    $bounds = fuelauHistoricalLocationBounds([
        'lat' => -27.4698,
        'lon' => 153.0251,
        'radius_km' => 80.0,
    ]);
    fuelauAssertTrue(is_array($bounds), 'Radius filters must produce coordinate bounds');
    fuelauAssertTrue(
        $bounds['min_lat'] < -27.4698 && $bounds['max_lat'] > -27.4698,
        'Latitude bounds must enclose the requested centre'
    );

    $source = file_get_contents(dirname(__DIR__, 2) . '/src/fuel.php');
    fuelauAssertTrue(is_string($source), 'Unable to read src/fuel.php');
    fuelauAssertSame(
        6,
        substr_count(
            $source,
            "fuelauApplyHistoricalLocationFilters(\$where, \$filters, 's.latitude', 's.longitude');"
        ),
        'Every historical provider must apply the indexed bounding-box prefilter'
    );
});

fuelauTest('numeric fuel filters use the composite history index path', static function (): void {
    fuelauAssertSame(
        'h.fuel_id = :fuel',
        fuelauNumericFuelFilterCondition(['fuel' => '3'], 'h.fuel_id', 'f.name')
    );
    fuelauAssertSame(
        'f.name = :fuel',
        fuelauNumericFuelFilterCondition(['fuel' => 'Diesel'], 'h.fuel_id', 'f.name')
    );
});

fuelauTest('dashboard excludes stale and out-of-range prices', static function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');
    fuelauAssertTrue(is_string($source), 'Unable to read public/resources/app.js');
    fuelauAssertTrue(
        str_contains($source, 'return routeFuelPriceIsReasonable(value);'),
        'Dashboard price rendering must use the supported price range'
    );
    fuelauAssertTrue(
        str_contains($source, 'routeFuelPriceIsFresh(row?.updated_at)'),
        'Dashboard records must pass the freshness check'
    );
});

fuelauTest('page declares the project favicon', static function (): void {
    $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
    fuelauAssertTrue(is_string($template), 'Unable to read templates/app.php');
    fuelauAssertTrue(str_contains($template, '/favicon.svg'), 'Page must declare the favicon');
    fuelauAssertTrue(
        str_contains($template, 'appJsVersion') && str_contains($template, 'appCssVersion'),
        'Application assets must use content-hash cache busting'
    );
    fuelauAssertTrue(is_file(dirname(__DIR__, 2) . '/public/favicon.svg'), 'Favicon asset must exist');
});

fuelauTest('aggregate cache avoids repeated loaders', static function (): void {
    $directory = sys_get_temp_dir() . '/fuelau-cache-' . bin2hex(random_bytes(8));
    mkdir($directory, 0775, true);
    $calls = 0;
    $loader = static function () use (&$calls): array {
        $calls++;
        return ['value' => $calls];
    };

    try {
        $first = fuelauRememberArray($directory . '/value.json', 60, $loader);
        $second = fuelauRememberArray($directory . '/value.json', 60, $loader);
        fuelauAssertSame(['value' => 1], $first);
        fuelauAssertSame($first, $second);
        fuelauAssertSame(1, $calls);
    } finally {
        foreach (glob($directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});

fuelauTest('public app does not mount Docker socket', static function (): void {
    $compose = file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');
    fuelauAssertTrue(is_string($compose), 'Unable to read docker-compose.yml');

    preg_match('/^  app:\s*$.*?(?=^  [a-z0-9_-]+:\s*$)/ms', $compose, $matches);
    $appSection = $matches[0] ?? '';
    fuelauAssertTrue($appSection !== '', 'Unable to locate the app service');
    fuelauAssertTrue(
        !str_contains($appSection, '/var/run/docker.sock'),
        'The public app service must not mount the Docker socket'
    );
});

fuelauTest('Docker build context excludes database dumps', static function (): void {
    $dockerignore = file_get_contents(dirname(__DIR__, 2) . '/.dockerignore');
    fuelauAssertTrue(is_string($dockerignore), 'Unable to read .dockerignore');
    fuelauAssertTrue(
        str_contains($dockerignore, '*.sql.gz'),
        'Compressed SQL dumps must never enter the application image',
    );
});

fuelauTest('Git excludes database dumps and the local importer plan', static function (): void {
    $gitignore = file_get_contents(dirname(__DIR__, 2) . '/.gitignore');
    fuelauAssertTrue(is_string($gitignore), 'Unable to read .gitignore');
    fuelauAssertTrue(str_contains($gitignore, '*.sql'), 'SQL dumps must be ignored by Git');
    fuelauAssertTrue(str_contains($gitignore, '*.sql.gz'), 'Compressed SQL dumps must be ignored by Git');
    fuelauAssertTrue(
        str_contains($gitignore, 'importer-optimisation.md'),
        'The local importer plan must not be committed',
    );
});

fuelauTest('incremental migration directory exists', static function (): void {
    fuelauAssertTrue(
        is_dir(dirname(__DIR__, 2) . '/migrations'),
        'Database upgrades must use an incremental migrations directory'
    );
});

fuelauTest('migration sequence includes all forward-only upgrades', static function (): void {
    $migrations = fuelauLoadMigrations(dirname(__DIR__, 2) . '/migrations');
    fuelauAssertSame([7, 8, 9, 10], array_keys($migrations));
    fuelauAssertSame('baseline_schema', $migrations[7]['name']);
    fuelauAssertSame('station_coordinate_indexes', $migrations[8]['name']);
    fuelauAssertSame('importer_last_seen', $migrations[9]['name']);
    fuelauAssertSame('normalize_last_seen_utc', $migrations[10]['name']);

    $setup = file_get_contents(dirname(__DIR__, 2) . '/setup.php');
    fuelauAssertTrue(is_string($setup), 'Unable to read setup.php');
    fuelauAssertTrue(
        !str_contains($setup, 'INSERT IGNORE INTO `schema_migrations`'),
        'Setup must not mark versions with INSERT IGNORE'
    );
});

fuelauTest('importer optimization migration adds last-seen tracking', static function (): void {
    $migration = require dirname(__DIR__, 2) . '/migrations/009_importer_last_seen.php';
    fuelauAssertSame(9, $migration['version'] ?? null, 'Expected migration version 9');
    fuelauAssertSame('importer_last_seen', $migration['name'] ?? null, 'Expected importer migration name');
});

fuelauTest('fresh schema uses explicit UTC last-seen writes', static function (): void {
    $setup = file_get_contents(dirname(__DIR__, 2) . '/setup.php');
    fuelauAssertTrue(is_string($setup), 'Unable to read setup.php');
    fuelauAssertSame(
        5,
        substr_count($setup, '`last_seen_at` DATETIME NOT NULL,'),
        'All five importer current tables must require explicit last-seen values',
    );
    fuelauAssertTrue(
        !str_contains($setup, '`last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'),
        'Fresh schemas must not introduce local-time last-seen defaults',
    );
});

fuelauTest('last-seen normalization corrects local-time backfill', static function (): void {
    $migrationPath = dirname(__DIR__, 2) . '/migrations/010_normalize_last_seen_utc.php';
    $migration = require $migrationPath;
    $source = file_get_contents($migrationPath);
    fuelauAssertSame(10, $migration['version'] ?? null, 'Expected normalization migration version 10');
    fuelauAssertSame(
        'normalize_last_seen_utc',
        $migration['name'] ?? null,
        'Expected UTC normalization migration name',
    );
    fuelauAssertTrue(is_string($source), 'Unable to read UTC normalization migration');
    fuelauAssertTrue(
        str_contains($source, 'TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())'),
        'Normalization must derive the active database UTC offset',
    );
    fuelauAssertTrue(
        str_contains($source, 'UTC_TIMESTAMP() + INTERVAL 5 MINUTE'),
        'Normalization must only adjust implausibly future last-seen values',
    );
});

fuelauTest('history buckets use Australia Brisbane calendar boundaries', static function (): void {
    fuelauAssertTrue(
        str_contains(fuelauHistoryBucketCte('weekly'), 'INTERVAL 10 HOUR'),
        'Weekly history buckets must use Brisbane UTC offset',
    );
    fuelauAssertTrue(
        str_contains(fuelauHistoryBucketCte('monthly'), 'INTERVAL 10 HOUR'),
        'Monthly history buckets must use Brisbane UTC offset',
    );
});

fuelauTest('monthly history events use monthly buckets', static function (): void {
    $query = fuelauEffectiveHistoryQuery(
        'monthly',
        'test',
        "'QLD'",
        'test_current',
        'test_history',
        ['station_id', 'fuel_code'],
        'event_at_utc',
        '',
        ['1=1'],
        'h.price',
        'h.price IS NOT NULL',
    );
    $monthlyExpression = "DATE_FORMAT(h.`event_at_utc` + INTERVAL 10 HOUR, '%Y-%m-01')";
    fuelauAssertSame(
        2,
        substr_count($query, $monthlyExpression),
        'Monthly history must use the month expression in both SELECT and GROUP BY',
    );
    fuelauAssertTrue(
        !str_contains($query, 'DATE(h.`event_at_utc` + INTERVAL 10 HOUR) AS bucket_date'),
        'Monthly history must not group events into daily buckets',
    );
});

fuelauTest('fuel summary formats UTC timestamps in Brisbane time', static function (): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');
    fuelauAssertTrue(is_string($source), 'Unable to read public/resources/app.js');
    fuelauAssertTrue(
        str_contains($source, "timeZone: 'Australia/Brisbane'"),
        'Timestamp rendering must explicitly select Australia/Brisbane',
    );
    fuelauAssertTrue(
        str_contains($source, 'item?.latest_update ? formatDateTime(item.latest_update)'),
        'Latest provider reports must use the timestamp formatter',
    );
    fuelauAssertTrue(
        str_contains($source, 'item?.last_checked ? formatDateTime(item.last_checked)'),
        'Last-checked timestamps must use the timestamp formatter',
    );
});

foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures[$name] = $exception->getMessage();
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(
    STDOUT,
    sprintf(
        "\nPHP regression summary: %d passed, %d failed\n",
        count($tests) - count($failures),
        count($failures)
    )
);

exit($failures === [] ? 0 : 1);
