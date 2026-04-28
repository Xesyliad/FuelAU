<?php

declare(strict_types=1);

const FUELAU_DOCKER_SOCKET = '/var/run/docker.sock';

function fuelauDockerProject(): string
{
    $project = trim((string) getenv('FUELAU_DOCKER_PROJECT'));
    return $project !== '' ? $project : 'fuelau';
}

function fuelauDockerChunkDecode(string $body): string
{
    $decoded = '';
    $offset = 0;
    $length = strlen($body);

    while ($offset < $length) {
        $lineEnd = strpos($body, "\r\n", $offset);
        if ($lineEnd === false) {
            return $body;
        }

        $chunkHeader = substr($body, $offset, $lineEnd - $offset);
        $chunkSize = hexdec(trim(explode(';', $chunkHeader, 2)[0]));
        if ($chunkSize === 0) {
            return $decoded;
        }

        $offset = $lineEnd + 2;
        $decoded .= substr($body, $offset, $chunkSize);
        $offset += $chunkSize + 2;
    }

    return $decoded;
}

function fuelauDockerRequest(string $method, string $path, ?array $payload = null): array
{
    if (!is_readable(FUELAU_DOCKER_SOCKET)) {
        throw new RuntimeException('Docker socket is not available to the app container.');
    }

    $socket = @stream_socket_client('unix://' . FUELAU_DOCKER_SOCKET, $errno, $errstr, 5);
    if ($socket === false) {
        throw new RuntimeException("Unable to connect to Docker socket: {$errstr}");
    }

    $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Unable to encode Docker API payload.');
    }

    $request = "{$method} {$path} HTTP/1.1\r\n"
        . "Host: docker\r\n"
        . "Connection: close\r\n";

    if ($body !== '') {
        $request .= "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n";
    }

    fwrite($socket, $request . "\r\n" . $body);
    $response = stream_get_contents($socket);
    fclose($socket);

    if ($response === false || !str_contains($response, "\r\n\r\n")) {
        throw new RuntimeException('Invalid response from Docker API.');
    }

    [$headerText, $responseBody] = explode("\r\n\r\n", $response, 2);
    $headerLines = explode("\r\n", $headerText);
    $statusLine = array_shift($headerLines) ?: '';
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('Invalid Docker API status line.');
    }

    $headers = [];
    foreach ($headerLines as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    if (strtolower($headers['transfer-encoding'] ?? '') === 'chunked') {
        $responseBody = fuelauDockerChunkDecode($responseBody);
    }

    $statusCode = (int) $matches[1];
    if ($statusCode >= 400) {
        $message = $responseBody;
        $decoded = json_decode($responseBody, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            $message = (string) $decoded['message'];
        }
        throw new RuntimeException("Docker API HTTP {$statusCode}: {$message}");
    }

    if ($responseBody === '') {
        return [];
    }

    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : ['raw' => $responseBody];
}

function fuelauDockerFilters(array $filters): string
{
    return rawurlencode(json_encode($filters, JSON_UNESCAPED_SLASHES) ?: '{}');
}

function fuelauDockerContainers(): array
{
    $project = fuelauDockerProject();
    $filters = fuelauDockerFilters([
        'label' => ["com.docker.compose.project={$project}"],
    ]);

    $containers = fuelauDockerRequest('GET', "/containers/json?all=1&filters={$filters}");
    $result = [];

    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $labels = is_array($container['Labels'] ?? null) ? $container['Labels'] : [];
        $result[] = [
            'id' => substr((string) ($container['Id'] ?? ''), 0, 12),
            'full_id' => (string) ($container['Id'] ?? ''),
            'name' => ltrim((string) (($container['Names'][0] ?? '') ?: ''), '/'),
            'service' => (string) ($labels['com.docker.compose.service'] ?? ''),
            'image' => (string) ($container['Image'] ?? ''),
            'state' => (string) ($container['State'] ?? ''),
            'status' => (string) ($container['Status'] ?? ''),
            'created' => (int) ($container['Created'] ?? 0),
            'ports' => $container['Ports'] ?? [],
        ];
    }

    usort(
        $result,
        static fn (array $a, array $b): int => strcmp($a['service'] . $a['name'], $b['service'] . $b['name'])
    );

    return $result;
}

function fuelauConfiguredServices(): array
{
    return [
        'app' => [
            'service' => 'app',
            'title' => 'FuelAU App',
            'role' => 'PHP web app, API, cron, and management UI.',
            'profile' => 'default',
            'start_command' => 'docker compose up -d app',
            'data_paths' => ['var/docker/app-logs'],
        ],
        'db' => [
            'service' => 'db',
            'title' => 'MariaDB',
            'role' => 'FuelAU application database.',
            'profile' => 'default',
            'start_command' => 'docker compose up -d db',
            'data_paths' => ['var/docker/db-data'],
        ],
        'nominatim' => [
            'service' => 'nominatim',
            'title' => 'Nominatim',
            'role' => 'Australia geocoding service.',
            'profile' => 'routing',
            'start_command' => 'docker compose --profile routing up -d nominatim',
            'data_paths' => ['var/docker/nominatim-db', 'var/docker/nominatim-flatnode'],
            'source' => 'https://download.geofabrik.de/australia-oceania/australia-latest.osm.pbf',
            'updates' => 'https://download.geofabrik.de/australia-oceania/australia-updates',
        ],
        'osrm-download' => [
            'service' => 'osrm-download',
            'title' => 'OSRM Download',
            'role' => 'Downloads the Australia OSM PBF before OSRM preprocessing.',
            'profile' => 'routing-setup',
            'start_command' => 'docker compose --profile routing-setup run --rm osrm-download',
            'data_paths' => ['var/docker/osrm-data'],
            'source' => 'https://download.geofabrik.de/australia-oceania/australia-latest.osm.pbf',
        ],
        'osrm-extract' => [
            'service' => 'osrm-extract',
            'title' => 'OSRM Extract',
            'role' => 'Builds OSRM extract data from the Australia PBF.',
            'profile' => 'routing-setup',
            'start_command' => 'docker compose --profile routing-setup run --rm osrm-extract',
            'data_paths' => ['var/docker/osrm-data'],
        ],
        'osrm-partition' => [
            'service' => 'osrm-partition',
            'title' => 'OSRM Partition',
            'role' => 'Prepares MLD partitions for OSRM routing.',
            'profile' => 'routing-setup',
            'start_command' => 'docker compose --profile routing-setup run --rm osrm-partition',
            'data_paths' => ['var/docker/osrm-data'],
        ],
        'osrm-customize' => [
            'service' => 'osrm-customize',
            'title' => 'OSRM Customize',
            'role' => 'Customizes MLD cells for OSRM routing.',
            'profile' => 'routing-setup',
            'start_command' => 'docker compose --profile routing-setup run --rm osrm-customize',
            'data_paths' => ['var/docker/osrm-data'],
        ],
        'osrm-routed' => [
            'service' => 'osrm-routed',
            'title' => 'OSRM Routed',
            'role' => 'Australia routing API service.',
            'profile' => 'routing',
            'start_command' => 'docker compose --profile routing up -d osrm-routed',
            'data_paths' => ['var/docker/osrm-data'],
        ],
    ];
}

function fuelauDockerServices(): array
{
    $configured = fuelauConfiguredServices();
    $containersByService = [];
    foreach (fuelauDockerContainers() as $container) {
        $service = (string) ($container['service'] ?? '');
        if ($service !== '') {
            $containersByService[$service] = $container;
        }
    }

    $services = [];
    foreach ($configured as $service => $metadata) {
        $container = $containersByService[$service] ?? null;
        $services[] = array_merge(
            $metadata,
            [
                'configured' => true,
                'has_container' => $container !== null,
                'container' => $container,
            ]
        );
        unset($containersByService[$service]);
    }

    foreach ($containersByService as $service => $container) {
        $services[] = [
            'service' => $service,
            'title' => $service,
            'role' => 'Compose service detected from Docker labels.',
            'profile' => 'unknown',
            'start_command' => '',
            'data_paths' => [],
            'configured' => false,
            'has_container' => true,
            'container' => $container,
        ];
    }

    return $services;
}

function fuelauDockerContainerId(string $id): string
{
    $id = trim($id);
    foreach (fuelauDockerContainers() as $container) {
        $fullId = (string) $container['full_id'];
        if ($id !== '' && str_starts_with($fullId, $id)) {
            return $fullId;
        }
    }

    throw new RuntimeException('Container is not part of this Compose project.');
}

function fuelauDockerLogText(string $raw): string
{
    $text = '';
    $offset = 0;
    $length = strlen($raw);

    while ($offset + 8 <= $length) {
        $size = unpack('N', substr($raw, $offset + 4, 4))[1] ?? 0;
        $offset += 8;
        if ($size <= 0 || $offset + $size > $length) {
            return $raw;
        }
        $text .= substr($raw, $offset, $size);
        $offset += $size;
    }

    return $text !== '' ? $text : $raw;
}

function fuelauDockerDiskSummary(): array
{
    $disk = fuelauDockerRequest('GET', '/system/df');
    $images = is_array($disk['Images'] ?? null) ? $disk['Images'] : [];
    $containers = is_array($disk['Containers'] ?? null) ? $disk['Containers'] : [];
    $volumes = is_array($disk['Volumes'] ?? null) ? $disk['Volumes'] : [];
    $buildCache = is_array($disk['BuildCache'] ?? null) ? $disk['BuildCache'] : [];

    $buildCacheSize = 0;
    foreach ($buildCache as $entry) {
        if (is_array($entry)) {
            $buildCacheSize += (int) ($entry['Size'] ?? 0);
        }
    }

    return [
        'layers_size' => (int) ($disk['LayersSize'] ?? 0),
        'image_count' => count($images),
        'container_count' => count($containers),
        'volume_count' => count($volumes),
        'build_cache_count' => count($buildCache),
        'build_cache_size' => $buildCacheSize,
    ];
}

function fuelauDockerApiResponse(array $payload, int $statusCode = 200): never
{
    fuelauJsonResponse($payload, $statusCode);
}
