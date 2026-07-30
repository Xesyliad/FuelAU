<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$configPath = tempnam(sys_get_temp_dir(), 'fuelau-api-contract-');
if ($configPath === false) {
    throw new RuntimeException('Unable to create temporary API test configuration.');
}

$config = <<<'ENV'
MYSQL_HOST=127.0.0.1
MYSQL_PORT=1
MYSQL_DATABASE=fuelau_contract_test
MYSQL_USERNAME=fuelau_contract_test
MYSQL_PASSWORD=not-a-secret
MYSQL_CHARSET=utf8mb4
ENV;
file_put_contents($configPath, $config . PHP_EOL);

$failures = [];

function fuelauRunApiRequest(
    string $projectRoot,
    string $configPath,
    string $method,
    string $requestUri,
    string $remoteAddress = '127.0.0.1'
): array {
    $script = <<<'PHP'
register_shutdown_function(static function (): void {
    fwrite(STDERR, 'STATUS:' . (http_response_code() ?: 200) . PHP_EOL);
});
$_SERVER['REQUEST_METHOD'] = $argv[1];
$_SERVER['REQUEST_URI'] = $argv[2];
$_SERVER['REMOTE_ADDR'] = $argv[4];
parse_str((string) parse_url($argv[2], PHP_URL_QUERY), $_GET);
require $argv[3] . '/public/index.php';
PHP;

    $environment = array_merge(getenv(), [
        'FUELAU_MYSQL_ENV_PATH' => $configPath,
        'FUELAU_APP_ENV_PATH' => $configPath . '.missing',
    ]);
    $process = proc_open(
        [PHP_BINARY, '-r', $script, $method, $requestUri, $projectRoot, $remoteAddress],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $projectRoot,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch API contract request.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    preg_match('/STATUS:(\d+)/', (string) $stderr, $statusMatch);
    $payload = json_decode((string) $stdout, true);

    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 0,
        'payload' => is_array($payload) ? $payload : [],
        'stderr' => (string) $stderr,
    ];
}

function fuelauApiContractTest(string $name, callable $test): void
{
    global $failures;

    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures[$name] = $exception->getMessage();
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

function fuelauApiAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

fuelauApiContractTest('health is unavailable when the database is unavailable', static function () use ($projectRoot, $configPath): void {
    $response = fuelauRunApiRequest($projectRoot, $configPath, 'GET', '/api/health');
    fuelauApiAssertSame(503, $response['status'], 'Health status code');
    fuelauApiAssertSame('unavailable', $response['payload']['status'] ?? null, 'Health payload status');
    fuelauApiAssertSame('unavailable', $response['payload']['database'] ?? null, 'Database health');
});

fuelauApiContractTest('GET endpoints reject POST', static function () use ($projectRoot, $configPath): void {
    $response = fuelauRunApiRequest($projectRoot, $configPath, 'POST', '/api/health');
    fuelauApiAssertSame(405, $response['status'], 'Method status code');
    fuelauApiAssertSame('method_not_allowed', $response['payload']['error'] ?? null, 'Method error');
});

fuelauApiContractTest('malformed route coordinates return 400', static function () use ($projectRoot, $configPath): void {
    $response = fuelauRunApiRequest(
        $projectRoot,
        $configPath,
        'GET',
        '/api/route?coordinates=181,-27;153,-27'
    );
    fuelauApiAssertSame(400, $response['status'], 'Route validation status code');
    fuelauApiAssertSame('invalid_query', $response['payload']['error'] ?? null, 'Route validation error');
});

fuelauApiContractTest('out-of-range reverse coordinates return 400', static function () use ($projectRoot, $configPath): void {
    $response = fuelauRunApiRequest(
        $projectRoot,
        $configPath,
        'GET',
        '/api/geo/reverse?lat=-91&lon=153'
    );
    fuelauApiAssertSame(400, $response['status'], 'Reverse validation status code');
    fuelauApiAssertSame('invalid_query', $response['payload']['error'] ?? null, 'Reverse validation error');
});

fuelauApiContractTest('route throttling returns 429', static function () use ($projectRoot, $configPath): void {
    $remoteAddress = '192.0.2.123';
    $rateLimitDirectory = $projectRoot . '/var/docker/app-state/rate-limits';
    if (!is_dir($rateLimitDirectory)) {
        mkdir($rateLimitDirectory, 0775, true);
    }
    $rateLimitPath = $rateLimitDirectory . '/' . hash('sha256', 'route:' . $remoteAddress) . '.json';
    file_put_contents($rateLimitPath, json_encode(array_fill(0, 240, time())));

    try {
        $response = fuelauRunApiRequest(
            $projectRoot,
            $configPath,
            'GET',
            '/api/route?coordinates=153,-27;153.1,-27.1',
            $remoteAddress
        );
    } finally {
        @unlink($rateLimitPath);
    }

    fuelauApiAssertSame(429, $response['status'], 'Rate-limit status code');
    fuelauApiAssertSame('rate_limited', $response['payload']['error'] ?? null, 'Rate-limit error');
});

fuelauApiContractTest('route optimizer requires POST', static function () use ($projectRoot, $configPath): void {
    $response = fuelauRunApiRequest($projectRoot, $configPath, 'GET', '/api/route/optimize');
    fuelauApiAssertSame(405, $response['status'], 'Optimizer method status code');
    fuelauApiAssertSame('method_not_allowed', $response['payload']['error'] ?? null, 'Optimizer method error');
});

fuelauApiContractTest('route optimizer is disabled by default', static function () use ($projectRoot, $configPath): void {
    $response = fuelauRunApiRequest($projectRoot, $configPath, 'POST', '/api/route/optimize');
    fuelauApiAssertSame(503, $response['status'], 'Optimizer disabled status code');
    fuelauApiAssertSame('optimizer_disabled', $response['payload']['error'] ?? null, 'Optimizer disabled error');
});

@unlink($configPath);

fwrite(
    STDOUT,
    sprintf(
        "\nAPI contract summary: %d passed, %d failed\n",
        7 - count($failures),
        count($failures)
    )
);

exit($failures === [] ? 0 : 1);
