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

final readonly class FuelauGeoAutocompleteRequest
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
        $search = FuelauGeoSearchRequest::fromQuery($query);
        if (strlen($search->query) < 3) {
            throw new FuelauValidationException('Autocomplete query must contain at least three characters.');
        }

        return new self($search->query, min(10, $search->limit));
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
        public string $overview,
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

        $overview = strtolower(trim(FuelauRequestValue::string($query['overview'] ?? 'simplified', 'simplified')));
        if (!in_array($overview, ['simplified', 'full'], true)) {
            throw new FuelauValidationException('overview must be simplified or full.');
        }

        return new self(
            coordinates: fuelauParseCoordinates($coordinates),
            steps: FuelauRequestValue::string($query['steps'] ?? '1', '1') !== '0',
            overview: $overview,
        );
    }
}

final readonly class FuelauRouteOptimizationLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $label,
        public bool $physicalStop,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(
        array $body,
        string $field,
        bool $physicalStop = true,
        bool $allowPhysicalStopOverride = true,
    ): self {
        $latitude = FuelauRequestValue::floatOrNull($body['lat'] ?? null);
        $longitude = FuelauRequestValue::floatOrNull($body['lon'] ?? null);
        if ($latitude === null || $longitude === null) {
            throw new FuelauValidationException("{$field} requires numeric lat and lon values.");
        }
        fuelauValidateCoordinates($latitude, $longitude);

        $label = trim(FuelauRequestValue::string($body['label'] ?? ''));
        if (strlen($label) > 200) {
            throw new FuelauValidationException("{$field} label must not exceed 200 characters.");
        }

        $resolvedPhysicalStop = $physicalStop;
        if ($allowPhysicalStopOverride && array_key_exists('physical_stop', $body)) {
            if (!is_bool($body['physical_stop'])) {
                throw new FuelauValidationException("{$field} physical_stop must be boolean.");
            }
            $resolvedPhysicalStop = $body['physical_stop'];
        }

        return new self($latitude, $longitude, $label, $resolvedPhysicalStop);
    }

    /**
     * @return array{lat: float, lon: float, label: string, physical_stop: bool}
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->latitude,
            'lon' => $this->longitude,
            'label' => $this->label,
            'physical_stop' => $this->physicalStop,
        ];
    }
}

final readonly class FuelauRouteOptimizationFuel
{
    public function __construct(
        public string $type,
        public float $tankCapacityL,
        public float $startingFuelL,
        public float $economyLPer100km,
        public float $reserveL,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $rawType = trim(FuelauRequestValue::string($body['type'] ?? ''));
        $type = fuelauRouteFuelProfileId($rawType);
        if ($type === null) {
            throw new FuelauValidationException('fuel.type must be a supported grouped route fuel.');
        }

        $tankCapacityL = FuelauRequestValue::floatOrNull($body['tank_capacity_l'] ?? null);
        if ($tankCapacityL === null || $tankCapacityL < 5 || $tankCapacityL > 1500) {
            throw new FuelauValidationException('fuel.tank_capacity_l must be between 5 and 1500.');
        }

        $startingFuelL = FuelauRequestValue::floatOrNull($body['starting_fuel_l'] ?? null);
        if ($startingFuelL === null || $startingFuelL < 0 || $startingFuelL > $tankCapacityL) {
            throw new FuelauValidationException(
                'fuel.starting_fuel_l must be between 0 and tank capacity.',
            );
        }

        $economyLPer100km = FuelauRequestValue::floatOrNull($body['economy_l_per_100km'] ?? null);
        if ($economyLPer100km === null || $economyLPer100km < 0.1 || $economyLPer100km > 200) {
            throw new FuelauValidationException('fuel.economy_l_per_100km must be between 0.1 and 200.');
        }

        $reserveL = FuelauRequestValue::floatOrNull($body['reserve_l'] ?? null);
        if ($reserveL === null || $reserveL < 0 || $reserveL >= $tankCapacityL) {
            throw new FuelauValidationException('fuel.reserve_l must be non-negative and less than tank capacity.');
        }

        return new self($type, $tankCapacityL, $startingFuelL, $economyLPer100km, $reserveL);
    }
}

final readonly class FuelauRouteOptimizationPreferences
{
    public function __construct(
        public string $mode,
        public ?int $maximumFuelOnlyStops,
        public ?float $minimumDiscretionaryPurchaseL,
        public float $minimumStopSpacingKm,
        public float $minimumStopSpacingMinutes,
        public int $minimumNetSavingCents,
        public float $maximumDiscretionaryDetourKm,
        public float $maximumDiscretionaryDetourMinutes,
        public int $driverTimeValueCentsPerHour,
        public float $fuelOnlyStopMinutes,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body, float $tankCapacityL): self
    {
        $mode = trim(FuelauRequestValue::string($body['mode'] ?? 'practical_least_cost'));
        if (!in_array($mode, ['practical_least_cost', 'fewer_stops'], true)) {
            throw new FuelauValidationException(
                'preferences.mode must be practical_least_cost or fewer_stops.',
            );
        }

        $maximumFuelOnlyStops = self::nullableInt(
            $body,
            'maximum_fuel_only_stops',
            0,
            20,
        );
        $minimumDiscretionaryPurchaseL = self::nullableFloat(
            $body,
            'minimum_discretionary_purchase_l',
            0,
            $tankCapacityL,
        );

        return new self(
            mode: $mode,
            maximumFuelOnlyStops: $maximumFuelOnlyStops,
            minimumDiscretionaryPurchaseL: $minimumDiscretionaryPurchaseL,
            minimumStopSpacingKm: self::float(
                $body,
                'minimum_stop_spacing_km',
                150,
                0,
                2000,
            ),
            minimumStopSpacingMinutes: self::float(
                $body,
                'minimum_stop_spacing_minutes',
                90,
                0,
                1440,
            ),
            minimumNetSavingCents: self::int(
                $body,
                'minimum_net_saving_cents',
                1000,
                0,
                100000,
            ),
            maximumDiscretionaryDetourKm: self::float(
                $body,
                'maximum_discretionary_detour_km',
                20,
                0,
                500,
            ),
            maximumDiscretionaryDetourMinutes: self::float(
                $body,
                'maximum_discretionary_detour_minutes',
                20,
                0,
                600,
            ),
            driverTimeValueCentsPerHour: self::int(
                $body,
                'driver_time_value_cents_per_hour',
                3000,
                0,
                100000,
            ),
            fuelOnlyStopMinutes: self::float(
                $body,
                'fuel_only_stop_minutes',
                10,
                0,
                240,
            ),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function float(
        array $body,
        string $field,
        float $default,
        float $minimum,
        float $maximum,
    ): float {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return $default;
        }
        $value = FuelauRequestValue::floatOrNull($body[$field]);
        if ($value === null || $value < $minimum || $value > $maximum) {
            throw new FuelauValidationException(
                "preferences.{$field} must be between {$minimum} and {$maximum}.",
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function int(
        array $body,
        string $field,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return $default;
        }
        $raw = $body[$field];
        if (
            (!is_int($raw) && !(is_string($raw) && ctype_digit($raw)))
            || (int) $raw < $minimum
            || (int) $raw > $maximum
        ) {
            throw new FuelauValidationException(
                "preferences.{$field} must be an integer between {$minimum} and {$maximum}.",
            );
        }

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function nullableInt(
        array $body,
        string $field,
        int $minimum,
        int $maximum,
    ): ?int {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        return self::int($body, $field, $minimum, $minimum, $maximum);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function nullableFloat(
        array $body,
        string $field,
        float $minimum,
        float $maximum,
    ): ?float {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        return self::float($body, $field, $minimum, $minimum, $maximum);
    }
}

final readonly class FuelauRouteOptimizationRequest
{
    public const MAX_ITINERARY_LEGS = 20;

    /**
     * @param list<FuelauRouteOptimizationLocation> $destinations
     */
    public function __construct(
        public int $version,
        public FuelauRouteOptimizationLocation $origin,
        public array $destinations,
        public string $returnMode,
        public FuelauRouteOptimizationFuel $fuel,
        public FuelauRouteOptimizationPreferences $preferences,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        if (($body['version'] ?? null) !== 1) {
            throw new FuelauValidationException('version must be the integer 1.');
        }

        $originBody = $body['origin'] ?? null;
        if (!is_array($originBody)) {
            throw new FuelauValidationException('origin must be an object.');
        }
        $origin = FuelauRouteOptimizationLocation::fromBody(
            FuelauRequestValue::stringKeyMap($originBody),
            'origin',
            true,
            false,
        );

        $destinationBodies = $body['destinations'] ?? null;
        if (!is_array($destinationBodies) || count($destinationBodies) < 1) {
            throw new FuelauValidationException('destinations must contain at least 1 location.');
        }

        $returnMode = trim(FuelauRequestValue::string($body['return_mode'] ?? ''));
        if (!in_array($returnMode, ['one_way', 'direct', 'reverse'], true)) {
            throw new FuelauValidationException(
                'return_mode must be one_way, direct, or reverse.',
            );
        }
        $expandedLegCount = match ($returnMode) {
            'one_way' => count($destinationBodies),
            'direct' => count($destinationBodies) + 1,
            'reverse' => count($destinationBodies) * 2,
        };
        if ($expandedLegCount > self::MAX_ITINERARY_LEGS) {
            throw new FuelauValidationException(sprintf(
                'Expanded itinerary must contain at most %d route legs.',
                self::MAX_ITINERARY_LEGS,
            ));
        }
        $destinations = [];
        foreach (array_values($destinationBodies) as $index => $destinationBody) {
            if (!is_array($destinationBody)) {
                throw new FuelauValidationException("destinations[{$index}] must be an object.");
            }
            $destinations[] = FuelauRouteOptimizationLocation::fromBody(
                FuelauRequestValue::stringKeyMap($destinationBody),
                "destinations[{$index}]",
                true,
            );
        }

        $fuelBody = $body['fuel'] ?? null;
        if (!is_array($fuelBody)) {
            throw new FuelauValidationException('fuel must be an object.');
        }
        $fuel = FuelauRouteOptimizationFuel::fromBody(
            FuelauRequestValue::stringKeyMap($fuelBody),
        );

        $preferencesBody = $body['preferences'] ?? [];
        if (!is_array($preferencesBody)) {
            throw new FuelauValidationException('preferences must be an object.');
        }

        return new self(
            version: 1,
            origin: $origin,
            destinations: $destinations,
            returnMode: $returnMode,
            fuel: $fuel,
            preferences: FuelauRouteOptimizationPreferences::fromBody(
                FuelauRequestValue::stringKeyMap($preferencesBody),
                $fuel->tankCapacityL,
            ),
        );
    }

    /**
     * @return list<FuelauRouteOptimizationLocation>
     */
    public function itineraryLocations(): array
    {
        $locations = [$this->origin, ...$this->destinations];
        if ($this->returnMode === 'one_way') {
            return $locations;
        }
        if ($this->returnMode === 'reverse') {
            $locations = [
                ...$locations,
                ...array_reverse(array_slice($this->destinations, 0, -1)),
            ];
        }
        $locations[] = $this->origin;

        return $locations;
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

        $fuel = fuelauRouteFuelProfileId(
            trim(FuelauRequestValue::string($body['fuel'] ?? '')),
        );
        if ($fuel === null) {
            throw new FuelauValidationException('fuel must be a supported grouped route fuel.');
        }

        return new self(
            points: $normalizedPoints,
            fuel: $fuel,
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
