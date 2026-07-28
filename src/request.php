<?php

declare(strict_types=1);

final class FuelauRequestValue
{
    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return $default;
    }

    public static function int(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    public static function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    public static function stringKeyMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[is_string($key) ? $key : (string) $key] = $value;
        }

        return $result;
    }
}

/**
 * Immutable representation of the PHP HTTP globals used by the application.
 */
final readonly class FuelauHttpRequest
{
    /**
     * @param array<string, mixed> $query
     */
    public function __construct(
        public string $path,
        public string $method,
        public array $query,
        public string $rawBody = '',
        public string $remoteAddress = 'unknown',
    ) {}

    public static function fromGlobals(): self
    {
        $requestUri = FuelauRequestValue::string($_SERVER['REQUEST_URI'] ?? '/', '/');
        $path = parse_url($requestUri, PHP_URL_PATH);

        return new self(
            path: is_string($path) && $path !== '' ? $path : '/',
            method: strtoupper(FuelauRequestValue::string($_SERVER['REQUEST_METHOD'] ?? 'GET', 'GET')),
            query: FuelauRequestValue::stringKeyMap($_GET),
            rawBody: file_get_contents('php://input') ?: '',
            remoteAddress: FuelauRequestValue::string($_SERVER['REMOTE_ADDR'] ?? 'unknown', 'unknown'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonObject(): array
    {
        $rawBody = $this->rawBody === '' ? '{}' : $this->rawBody;
        try {
            $object = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FuelauValidationException('Request body must contain valid JSON.', previous: $exception);
        }

        if (!is_object($object) || !is_array($decoded)) {
            throw new FuelauValidationException('Request body must be a JSON object.');
        }

        return FuelauRequestValue::stringKeyMap($decoded);
    }
}

final readonly class FuelauGeoSearchRequest
{
    public function __construct(
        public string $query,
        public int $limit,
    ) {}

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            query: fuelauValidateNominatimQuery(trim(FuelauRequestValue::string($query['q'] ?? ''))),
            limit: max(1, min(50, FuelauRequestValue::int($query['limit'] ?? 10, 10))),
        );
    }
}

final readonly class FuelauCoordinateRequest
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $latitude = FuelauRequestValue::floatOrNull($query['lat'] ?? null);
        $longitude = FuelauRequestValue::floatOrNull($query['lon'] ?? null);
        if ($latitude === null || $longitude === null) {
            throw new FuelauValidationException('lat and lon are required numeric query parameters.');
        }

        fuelauValidateCoordinates($latitude, $longitude);

        return new self($latitude, $longitude);
    }
}

final readonly class FuelauRouteRequest
{
    /**
     * @param list<array{lat: float, lon: float}> $coordinates
     */
    public function __construct(
        public array $coordinates,
        public bool $steps,
    ) {}

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $coordinates = trim(FuelauRequestValue::string($query['coordinates'] ?? ''));
        if ($coordinates === '') {
            throw new FuelauValidationException('Missing required query parameter: coordinates');
        }

        return new self(
            coordinates: fuelauParseCoordinates($coordinates),
            steps: FuelauRequestValue::string($query['steps'] ?? '1', '1') !== '0',
        );
    }
}

final readonly class FuelauRouteCandidateRequest
{
    /**
     * @param list<array{lat: float, lon: float}> $points
     */
    public function __construct(
        public array $points,
        public string $fuel,
        public float $radiusKm,
        public int $limit,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $points = $body['points'] ?? null;
        if (!is_array($points)) {
            throw new FuelauValidationException('Route candidate points must be an array.');
        }

        try {
            $normalizedPoints = fuelauNormalizeRouteCandidatePoints($points);
        } catch (InvalidArgumentException $exception) {
            throw new FuelauValidationException($exception->getMessage(), previous: $exception);
        }

        return new self(
            points: $normalizedPoints,
            fuel: trim(FuelauRequestValue::string($body['fuel'] ?? '')),
            radiusKm: max(
                0.1,
                min(100.0, FuelauRequestValue::floatOrNull($body['radius_km'] ?? null) ?? 25.0),
            ),
            limit: fuelauClampInt(FuelauRequestValue::int($body['limit'] ?? 2000, 2000), 1, 5000),
        );
    }
}

/**
 * Typed HTTP-boundary representation of current and historical fuel filters.
 *
 * The array conversion methods are temporary adapters for the existing query
 * services, whose internal SQL composition still uses documented array shapes.
 */
final readonly class FuelauFuelFilterRequest
{
    public function __construct(
        public string $source,
        public string $state,
        public string $fuel,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $radiusKm,
        public string $search = '',
        public string $brand = '',
        public int $limit = 100,
        public string $period = 'weekly',
    ) {}

    /**
     * @param array<string, mixed> $query
     */
    public static function current(array $query): self
    {
        $filters = fuelauFuelRequestFilters($query);

        return new self(
            source: (string) $filters['source'],
            state: (string) $filters['state'],
            fuel: (string) $filters['fuel'],
            latitude: is_float($filters['lat']) ? $filters['lat'] : null,
            longitude: is_float($filters['lon']) ? $filters['lon'] : null,
            radiusKm: is_float($filters['radius_km']) ? $filters['radius_km'] : null,
            search: (string) $filters['search'],
            brand: (string) $filters['brand'],
            limit: (int) $filters['limit'],
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function history(array $query): self
    {
        $filters = fuelauHistoricalFilters($query);

        return new self(
            source: (string) $filters['source'],
            state: (string) $filters['state'],
            fuel: (string) $filters['fuel'],
            latitude: is_float($filters['lat']) ? $filters['lat'] : null,
            longitude: is_float($filters['lon']) ? $filters['lon'] : null,
            radiusKm: is_float($filters['radius_km']) ? $filters['radius_km'] : null,
            period: (string) $filters['period'],
        );
    }

    /**
     * @return array{
     *     source: string,
     *     search: string,
     *     state: string,
     *     fuel: string,
     *     brand: string,
     *     limit: int,
     *     lat: float|null,
     *     lon: float|null,
     *     radius_km: float|null
     * }
     */
    public function toCurrentFilters(): array
    {
        return [
            'source' => $this->source,
            'search' => $this->search,
            'state' => $this->state,
            'fuel' => $this->fuel,
            'brand' => $this->brand,
            'limit' => $this->limit,
            'lat' => $this->latitude,
            'lon' => $this->longitude,
            'radius_km' => $this->radiusKm,
        ];
    }

    /**
     * @return array{
     *     source: string,
     *     state: string,
     *     fuel: string,
     *     period: string,
     *     lat: float|null,
     *     lon: float|null,
     *     radius_km: float|null
     * }
     */
    public function toHistoricalFilters(): array
    {
        return [
            'source' => $this->source,
            'state' => $this->state,
            'fuel' => $this->fuel,
            'period' => $this->period,
            'lat' => $this->latitude,
            'lon' => $this->longitude,
            'radius_km' => $this->radiusKm,
        ];
    }
}

final readonly class FuelauContainerLoginRequest
{
    public function __construct(public string $token) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        return new self(trim(FuelauRequestValue::string($body['token'] ?? '')));
    }
}

final readonly class FuelauDockerPruneRequest
{
    public function __construct(public string $action) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        return new self(trim(FuelauRequestValue::string($body['action'] ?? '')));
    }
}
