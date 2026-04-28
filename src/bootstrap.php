<?php

declare(strict_types=1);

const FUELAU_MYSQL_ENV_PATH = '/etc/fuelapi/mysql.env';

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

function fuelauPdo(): PDO
{
    $config = fuelauConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        fuelauRequiredConfig($config, 'MYSQL_HOST'),
        fuelauRequiredConfig($config, 'MYSQL_PORT'),
        fuelauRequiredConfig($config, 'MYSQL_DATABASE'),
        trim((string) ($config['MYSQL_CHARSET'] ?? 'utf8mb4'))
    );

    return new PDO(
        $dsn,
        fuelauRequiredConfig($config, 'MYSQL_USERNAME'),
        fuelauRequiredConfig($config, 'MYSQL_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function fuelauJsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}
