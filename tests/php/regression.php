<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/docker.php';
require dirname(__DIR__, 2) . '/src/fuel.php';
require dirname(__DIR__, 2) . '/src/migrations.php';
require dirname(__DIR__, 2) . '/src/routing.php';

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

fuelauTest('incremental migration directory exists', static function (): void {
    fuelauAssertTrue(
        is_dir(dirname(__DIR__, 2) . '/migrations'),
        'Database upgrades must use an incremental migrations directory'
    );
});

fuelauTest('migration sequence includes explicit v7 upgrade', static function (): void {
    $migrations = fuelauLoadMigrations(dirname(__DIR__, 2) . '/migrations');
    fuelauAssertSame([7, 8], array_keys($migrations));
    fuelauAssertSame('baseline_schema', $migrations[7]['name']);
    fuelauAssertSame('station_coordinate_indexes', $migrations[8]['name']);

    $setup = file_get_contents(dirname(__DIR__, 2) . '/setup.php');
    fuelauAssertTrue(is_string($setup), 'Unable to read setup.php');
    fuelauAssertTrue(
        !str_contains($setup, 'INSERT IGNORE INTO `schema_migrations`'),
        'Setup must not mark versions with INSERT IGNORE'
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
