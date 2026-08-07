<?php

declare(strict_types=1);

define('FUELAU_MYSQL_ENV_PATH', getenv('FUELAU_MYSQL_ENV_PATH') ?: '/etc/fuelapi/mysql.env');
define('FUELAU_APP_ENV_PATH', getenv('FUELAU_APP_ENV_PATH') ?: '/etc/fuelapi/app.env');

final class FuelauValidationException extends InvalidArgumentException {}

final class FuelauRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}

final class FuelauUpstreamException extends RuntimeException {}

function fuelauParseEnvFile(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("Config file is not readable: {$path}");
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Unable to read config file: {$path}");
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $values[trim($key)] = trim($value);
    }

    return $values;
}

function fuelauConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = fuelauParseEnvFile(FUELAU_MYSQL_ENV_PATH);
        if (is_readable(FUELAU_APP_ENV_PATH)) {
            $config = array_merge($config, fuelauParseEnvFile(FUELAU_APP_ENV_PATH));
        }
        foreach ([
            'CONTAINER_MANAGEMENT_ENABLED',
            'CONTAINER_MANAGEMENT_TOKEN',
        ] as $environmentKey) {
            $environmentValue = getenv($environmentKey);
            if ($environmentValue !== false && trim((string) $environmentValue) !== '') {
                $config[$environmentKey] = $environmentValue;
            }
        }
    }

    return $config;
}

function fuelauRequiredConfig(array $config, string $key): string
{
    if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
        throw new RuntimeException("Missing required config key: {$key}");
    }

    return trim((string) $config[$key]);
}

function fuelauConfigBool(array $config, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $config)) {
        return $default;
    }

    $value = strtolower(trim((string) $config[$key]));
    if ($value === '') {
        return $default;
    }

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function fuelauStartContainerManagementSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('fuelau_admin');
    if (!session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'cookie_secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'cookie_path' => '/',
        'gc_maxlifetime' => 1800,
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ])) {
        throw new RuntimeException('Unable to start the container management session.');
    }
}

function fuelauContainerManagementLogin(array $config, string $providedToken): ?string
{
    $expectedToken = trim((string) ($config['CONTAINER_MANAGEMENT_TOKEN'] ?? ''));
    $providedToken = trim($providedToken);
    if (
        $expectedToken === ''
        || $providedToken === ''
        || !hash_equals($expectedToken, $providedToken)
    ) {
        return null;
    }

    fuelauStartContainerManagementSession();
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to rotate the container management session.');
    }

    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['fuelau_container_management'] = [
        'authenticated_at' => time(),
        'last_activity_at' => time(),
        'token_fingerprint' => hash('sha256', $expectedToken),
        'csrf_token' => $csrfToken,
    ];

    return $csrfToken;
}

function fuelauContainerManagementAuthorized(array $config): bool
{
    fuelauStartContainerManagementSession();
    $session = $_SESSION['fuelau_container_management'] ?? null;
    if (!is_array($session)) {
        return false;
    }

    $expectedToken = trim((string) ($config['CONTAINER_MANAGEMENT_TOKEN'] ?? ''));
    $lastActivityAt = (int) ($session['last_activity_at'] ?? 0);
    $fingerprint = (string) ($session['token_fingerprint'] ?? '');
    if (
        $expectedToken === ''
        || $lastActivityAt <= 0
        || time() - $lastActivityAt > 1800
        || $fingerprint === ''
        || !hash_equals(hash('sha256', $expectedToken), $fingerprint)
    ) {
        unset($_SESSION['fuelau_container_management']);
        return false;
    }

    $_SESSION['fuelau_container_management']['last_activity_at'] = time();
    return true;
}

function fuelauContainerManagementCsrfToken(): string
{
    fuelauStartContainerManagementSession();
    return (string) ($_SESSION['fuelau_container_management']['csrf_token'] ?? '');
}

function fuelauContainerManagementCsrfValid(): bool
{
    $expected = fuelauContainerManagementCsrfToken();
    $provided = trim((string) ($_SERVER['HTTP_X_FUELAU_CSRF_TOKEN'] ?? ''));
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function fuelauContainerManagementLogout(): void
{
    fuelauStartContainerManagementSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

function fuelauConfiguredPdo(string $usernameKey, string $passwordKey): PDO
{
    $config = fuelauConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        fuelauRequiredConfig($config, 'MYSQL_HOST'),
        fuelauRequiredConfig($config, 'MYSQL_PORT'),
        fuelauRequiredConfig($config, 'MYSQL_DATABASE'),
        trim((string) ($config['MYSQL_CHARSET'] ?? 'utf8mb4')),
    );

    return new PDO(
        $dsn,
        fuelauRequiredConfig($config, $usernameKey),
        fuelauRequiredConfig($config, $passwordKey),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ],
    );
}

function fuelauPdo(): PDO
{
    $config = fuelauConfig();
    $useAppCredentials = trim((string) ($config['MYSQL_APP_USERNAME'] ?? '')) !== ''
        && trim((string) ($config['MYSQL_APP_PASSWORD'] ?? '')) !== '';
    $usernameKey = $useAppCredentials ? 'MYSQL_APP_USERNAME' : 'MYSQL_USERNAME';
    $passwordKey = $useAppCredentials ? 'MYSQL_APP_PASSWORD' : 'MYSQL_PASSWORD';
    return fuelauConfiguredPdo($usernameKey, $passwordKey);
}

function fuelauMigrationPdo(): PDO
{
    $config = fuelauConfig();
    $useMigrationCredentials = trim((string) ($config['MYSQL_MIGRATION_USERNAME'] ?? '')) !== ''
        && trim((string) ($config['MYSQL_MIGRATION_PASSWORD'] ?? '')) !== '';
    $usernameKey = $useMigrationCredentials
        ? 'MYSQL_MIGRATION_USERNAME'
        : 'MYSQL_USERNAME';
    $passwordKey = $useMigrationCredentials
        ? 'MYSQL_MIGRATION_PASSWORD'
        : 'MYSQL_PASSWORD';
    return fuelauConfiguredPdo($usernameKey, $passwordKey);
}

function fuelauRateLimit(string $bucket, int $limit, int $windowSeconds): void
{
    if ($limit <= 0 || $windowSeconds <= 0) {
        return;
    }

    $directory = fuelauProjectRoot() . '/var/docker/app-state/rate-limits';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create rate limit directory: {$directory}");
    }

    $file = $directory . '/' . hash('sha256', $bucket) . '.json';
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open rate limit file: {$file}");
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("Unable to lock rate limit file: {$file}");
        }

        rewind($handle);
        $payload = stream_get_contents($handle);
        $timestamps = [];
        if ($payload !== false && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $timestamps = array_values(array_filter(array_map('intval', $decoded), static fn(int $value): bool => $value > 0));
            }
        }

        $now = time();
        $threshold = $now - $windowSeconds;
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn(int $timestamp): bool => $timestamp >= $threshold,
        ));

        if (count($timestamps) >= $limit) {
            $retryAfterSeconds = max(
                1,
                ((int) min($timestamps) + $windowSeconds) - $now,
            );
            throw new FuelauRateLimitException(
                'Rate limit exceeded.',
                $retryAfterSeconds,
            );
        }

        $timestamps[] = $now;
        $encoded = json_encode($timestamps, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode rate limit state.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded);
        fflush($handle);
    } finally {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

function fuelauJsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
