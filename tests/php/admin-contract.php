<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$configPath = tempnam(sys_get_temp_dir(), 'fuelau-admin-contract-');
$logPath = tempnam(sys_get_temp_dir(), 'fuelau-admin-server-');
if ($configPath === false || $logPath === false) {
    throw new RuntimeException('Unable to create temporary admin test files.');
}

file_put_contents($configPath, implode(PHP_EOL, [
    'MYSQL_HOST=127.0.0.1',
    'MYSQL_PORT=1',
    'MYSQL_DATABASE=fuelau_admin_contract',
    'MYSQL_USERNAME=fuelau_admin_contract',
    'MYSQL_PASSWORD=not-a-secret',
    'MYSQL_CHARSET=utf8mb4',
    '',
]));

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($listener === false) {
    throw new RuntimeException("Unable to reserve admin test port: {$errorMessage}");
}
$address = (string) stream_socket_get_name($listener, false);
fclose($listener);

$environment = array_merge(getenv(), [
    'FUELAU_MYSQL_ENV_PATH' => $configPath,
    'FUELAU_APP_ENV_PATH' => $configPath . '.missing',
    'CONTAINER_MANAGEMENT_ENABLED' => 'true',
    'CONTAINER_MANAGEMENT_TOKEN' => 'fuelau-admin-contract-token',
]);
$process = proc_open(
    [
        PHP_BINARY,
        '-S',
        $address,
        '-t',
        $projectRoot . '/public',
        $projectRoot . '/public/index.php',
    ],
    [
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ],
    $pipes,
    $projectRoot,
    $environment
);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start the admin contract server.');
}

function fuelauAdminRequest(
    string $address,
    string $method,
    string $path,
    ?array $payload = null,
    array $headers = []
): array {
    $headerLines = array_merge(['Accept: application/json'], $headers);
    $body = '';
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $headerLines[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $responseBody = @file_get_contents("http://{$address}{$path}", false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', (string) ($responseHeaders[0] ?? ''), $statusMatch);

    return [
        'status' => isset($statusMatch[1]) ? (int) $statusMatch[1] : 0,
        'headers' => $responseHeaders,
        'payload' => is_string($responseBody)
            ? (json_decode($responseBody, true) ?: [])
            : [],
    ];
}

function fuelauAdminAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function fuelauAdminHeader(array $headers, string $name): string
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $header) {
        if (str_starts_with(strtolower((string) $header), $prefix)) {
            return trim(substr((string) $header, strlen($prefix)));
        }
    }

    return '';
}

$failures = [];
$tests = [
    'admin endpoint rejects invalid credentials' => static function () use ($address): void {
        $response = fuelauAdminRequest(
            $address,
            'POST',
            '/api/docker/session',
            ['token' => 'incorrect']
        );
        fuelauAdminAssertSame(401, $response['status'], 'Invalid login status');
    },
    'admin login issues a protected short session' => static function () use ($address): void {
        $response = fuelauAdminRequest(
            $address,
            'POST',
            '/api/docker/session',
            ['token' => 'fuelau-admin-contract-token']
        );
        fuelauAdminAssertSame(200, $response['status'], 'Valid login status');
        $cookie = fuelauAdminHeader($response['headers'], 'Set-Cookie');
        if (
            !str_contains($cookie, 'fuelau_admin=')
            || !str_contains(strtolower($cookie), 'httponly')
            || !str_contains(strtolower($cookie), 'samesite=strict')
        ) {
            throw new RuntimeException('Admin session cookie must be HttpOnly and SameSite=Strict');
        }
        if (strlen((string) ($response['payload']['csrf_token'] ?? '')) !== 64) {
            throw new RuntimeException('Admin login must issue a 64-character CSRF token');
        }
    },
    'admin mutations require CSRF' => static function () use ($address): void {
        $login = fuelauAdminRequest(
            $address,
            'POST',
            '/api/docker/session',
            ['token' => 'fuelau-admin-contract-token']
        );
        $setCookie = fuelauAdminHeader($login['headers'], 'Set-Cookie');
        $cookie = explode(';', $setCookie, 2)[0];
        $response = fuelauAdminRequest(
            $address,
            'POST',
            '/api/docker/prune',
            ['action' => 'dangling_images'],
            ['Cookie: ' . $cookie]
        );
        fuelauAdminAssertSame(403, $response['status'], 'Missing CSRF status');
        fuelauAdminAssertSame('csrf_failed', $response['payload']['error'] ?? null, 'Missing CSRF error');
    },
    'responses include browser security headers' => static function () use ($address): void {
        $response = fuelauAdminRequest($address, 'GET', '/api/health');
        fuelauAdminAssertSame('DENY', fuelauAdminHeader($response['headers'], 'X-Frame-Options'), 'Frame policy');
        fuelauAdminAssertSame('nosniff', fuelauAdminHeader($response['headers'], 'X-Content-Type-Options'), 'MIME policy');
        if (!str_contains(fuelauAdminHeader($response['headers'], 'Content-Security-Policy'), "frame-ancestors 'none'")) {
            throw new RuntimeException('Content Security Policy must prevent framing');
        }
    },
];

try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $probe = fuelauAdminRequest($address, 'GET', '/api/health');
        if ($probe['status'] !== 0) {
            $ready = true;
            break;
        }
        usleep(100_000);
    }
    if (!$ready) {
        throw new RuntimeException('Admin contract server did not become ready.');
    }

    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $exception) {
            $failures[$name] = $exception->getMessage();
            fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
        }
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($configPath);
    @unlink($logPath);
}

fwrite(
    STDOUT,
    sprintf(
        "\nAdmin contract summary: %d passed, %d failed\n",
        count($tests) - count($failures),
        count($failures)
    )
);

exit($failures === [] ? 0 : 1);
