<?php

declare(strict_types=1);

final readonly class FuelauCorridorProjection
{
    public function __construct(
        public int $progressM,
        public int $progressS,
        public int $offRouteM,
    ) {}
}

/**
 * Fastest-route corridor with progress scaled to OSRM's authoritative distance
 * and duration totals.
 */
final class FuelauRouteCorridor
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    /** @var list<array{lat: float, lon: float}> */
    private array $geometry;

    /** @var list<float> */
    private array $cumulativeGeometryM = [0.0];

    private float $geometryDistanceM = 0.0;

    /**
     * @param list<array{lat: float, lon: float}> $geometry
     */
    public function __construct(
        public readonly int $distanceM,
        public readonly int $durationS,
        array $geometry,
    ) {
        if ($distanceM <= 0 || $durationS <= 0) {
            throw new InvalidArgumentException('A route corridor requires positive distance and duration.');
        }
        if (count($geometry) < 2) {
            throw new InvalidArgumentException('A route corridor requires at least two geometry points.');
        }

        $normalized = [];
        foreach ($geometry as $index => $point) {
            $latitude = $point['lat'] ?? null;
            $longitude = $point['lon'] ?? null;
            if (
                (!is_float($latitude) && !is_int($latitude))
                || (!is_float($longitude) && !is_int($longitude))
            ) {
                throw new InvalidArgumentException("Corridor geometry point {$index} is invalid.");
            }
            $latitude = (float) $latitude;
            $longitude = (float) $longitude;
            if (
                !is_finite($latitude)
                || !is_finite($longitude)
                || $latitude < -90
                || $latitude > 90
                || $longitude < -180
                || $longitude > 180
            ) {
                throw new InvalidArgumentException("Corridor geometry point {$index} is out of range.");
            }
            $normalized[] = ['lat' => $latitude, 'lon' => $longitude];
        }

        $distinctGeometry = [];
        foreach ($normalized as $point) {
            $previous = $distinctGeometry[count($distinctGeometry) - 1] ?? null;
            if ($previous !== null && self::haversineM($previous, $point) <= 0.0) {
                continue;
            }
            $distinctGeometry[] = $point;
        }
        if (count($distinctGeometry) < 2) {
            throw new InvalidArgumentException('A route corridor requires two distinct geometry points.');
        }

        $normalized = $distinctGeometry;
        for ($index = 1; $index < count($normalized); $index++) {
            $segmentM = self::haversineM($normalized[$index - 1], $normalized[$index]);
            $this->geometryDistanceM += $segmentM;
            $this->cumulativeGeometryM[] = $this->geometryDistanceM;
        }
        $this->geometry = $normalized;
    }

    /**
     * @param array<string, mixed> $route
     */
    public static function fromOsrmRoute(array $route): self
    {
        $distance = $route['distance'] ?? null;
        $duration = $route['duration'] ?? null;
        $routeGeometry = $route['geometry'] ?? null;
        $coordinates = is_array($routeGeometry)
            ? ($routeGeometry['coordinates'] ?? null)
            : null;
        if (!is_numeric((string) $distance) || !is_numeric((string) $duration) || !is_array($coordinates)) {
            throw new InvalidArgumentException('OSRM route is missing distance, duration, or geometry.');
        }

        $geometry = [];
        foreach (array_values($coordinates) as $index => $coordinate) {
            if (
                !is_array($coordinate)
                || !is_numeric((string) ($coordinate[0] ?? null))
                || !is_numeric((string) ($coordinate[1] ?? null))
            ) {
                throw new InvalidArgumentException("OSRM geometry coordinate {$index} is invalid.");
            }
            $geometry[] = [
                'lat' => (float) $coordinate[1],
                'lon' => (float) $coordinate[0],
            ];
        }

        return new self(
            distanceM: (int) round((float) $distance),
            durationS: (int) round((float) $duration),
            geometry: $geometry,
        );
    }

    public function project(float $latitude, float $longitude): FuelauCorridorProjection
    {
        if (
            !is_finite($latitude)
            || !is_finite($longitude)
            || $latitude < -90
            || $latitude > 90
            || $longitude < -180
            || $longitude > 180
        ) {
            throw new InvalidArgumentException('Projection coordinates are out of range.');
        }

        $point = ['lat' => $latitude, 'lon' => $longitude];
        $bestDistanceM = INF;
        $bestGeometryProgressM = 0.0;
        for ($index = 1; $index < count($this->geometry); $index++) {
            [$segmentFraction, $distanceM] = self::projectToSegment(
                $point,
                $this->geometry[$index - 1],
                $this->geometry[$index],
            );
            if ($distanceM >= $bestDistanceM) {
                continue;
            }

            $segmentLengthM = $this->cumulativeGeometryM[$index]
                - $this->cumulativeGeometryM[$index - 1];
            $bestDistanceM = $distanceM;
            $bestGeometryProgressM = $this->cumulativeGeometryM[$index - 1]
                + ($segmentLengthM * $segmentFraction);
        }

        $fraction = $bestGeometryProgressM / $this->geometryDistanceM;

        return new FuelauCorridorProjection(
            progressM: max(0, min($this->distanceM, (int) round($this->distanceM * $fraction))),
            progressS: max(0, min($this->durationS, (int) round($this->durationS * $fraction))),
            offRouteM: max(0, (int) round($bestDistanceM)),
        );
    }

    /**
     * @return list<array{lat: float, lon: float}>
     */
    public function candidateLookupPoints(
        int $spacingM = 50_000,
        int $maximumPoints = 100,
    ): array {
        if ($spacingM <= 0 || $maximumPoints < 2 || $maximumPoints > 100) {
            throw new InvalidArgumentException('Invalid corridor sampling limits.');
        }

        $pointCount = min(
            $maximumPoints,
            max(2, (int) ceil($this->distanceM / $spacingM) + 1),
        );
        $points = [];
        for ($index = 0; $index < $pointCount; $index++) {
            $points[] = $this->coordinateAtFraction($index / ($pointCount - 1));
        }

        return $points;
    }

    /**
     * @return array{lat: float, lon: float}
     */
    public function coordinateAtProgressM(int $progressM): array
    {
        if ($progressM < 0 || $progressM > $this->distanceM) {
            throw new InvalidArgumentException('Corridor progress is out of range.');
        }

        return $this->coordinateAtFraction($progressM / $this->distanceM);
    }

    /**
     * @return array{lat: float, lon: float}
     */
    private function coordinateAtFraction(float $fraction): array
    {
        $targetM = $this->geometryDistanceM * max(0.0, min(1.0, $fraction));
        for ($index = 1; $index < count($this->cumulativeGeometryM); $index++) {
            if ($targetM > $this->cumulativeGeometryM[$index]) {
                continue;
            }
            $segmentStartM = $this->cumulativeGeometryM[$index - 1];
            $segmentLengthM = $this->cumulativeGeometryM[$index] - $segmentStartM;
            $segmentFraction = ($targetM - $segmentStartM) / $segmentLengthM;
            $from = $this->geometry[$index - 1];
            $to = $this->geometry[$index];

            return [
                'lat' => $from['lat'] + (($to['lat'] - $from['lat']) * $segmentFraction),
                'lon' => $from['lon'] + (($to['lon'] - $from['lon']) * $segmentFraction),
            ];
        }

        return $this->geometry[count($this->geometry) - 1];
    }

    /**
     * @param array{lat: float, lon: float} $point
     * @param array{lat: float, lon: float} $from
     * @param array{lat: float, lon: float} $to
     * @return array{float, float}
     */
    private static function projectToSegment(array $point, array $from, array $to): array
    {
        $referenceLatitude = deg2rad(($point['lat'] + $from['lat'] + $to['lat']) / 3);
        $latitudeScale = self::EARTH_RADIUS_M * (M_PI / 180);
        $longitudeScale = $latitudeScale * max(0.01, abs(cos($referenceLatitude)));
        $segmentX = ($to['lon'] - $from['lon']) * $longitudeScale;
        $segmentY = ($to['lat'] - $from['lat']) * $latitudeScale;
        $pointX = ($point['lon'] - $from['lon']) * $longitudeScale;
        $pointY = ($point['lat'] - $from['lat']) * $latitudeScale;
        $lengthSquared = ($segmentX ** 2) + ($segmentY ** 2);
        $fraction = max(
            0.0,
            min(1.0, (($pointX * $segmentX) + ($pointY * $segmentY)) / $lengthSquared),
        );
        $offsetX = $pointX - ($segmentX * $fraction);
        $offsetY = $pointY - ($segmentY * $fraction);

        return [$fraction, sqrt(($offsetX ** 2) + ($offsetY ** 2))];
    }

    /**
     * @param array{lat: float, lon: float} $left
     * @param array{lat: float, lon: float} $right
     */
    private static function haversineM(array $left, array $right): float
    {
        $leftLatitude = deg2rad($left['lat']);
        $rightLatitude = deg2rad($right['lat']);
        $latitudeDelta = $rightLatitude - $leftLatitude;
        $longitudeDelta = deg2rad($right['lon'] - $left['lon']);
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($leftLatitude) * cos($rightLatitude) * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_M
            * 2
            * atan2(sqrt($haversine), sqrt(max(0.0, 1 - $haversine)));
    }
}

final readonly class FuelauProjectedStationCandidate
{
    /**
     * @param array<string, mixed> $sourceRow
     */
    public function __construct(
        public string $stableId,
        public string $nodeId,
        public string $label,
        public int $progressM,
        public int $progressS,
        public int $offRouteM,
        public int $accessDistanceM,
        public int $accessDurationS,
        public bool $accessEstimated,
        public float $priceCentsPerL,
        public array $sourceRow,
    ) {}
}

final readonly class FuelauFixedCorridorInput
{
    /**
     * @param list<FuelauOptimizerNode> $nodes
     * @param array<string, FuelauProjectedStationCandidate> $candidatesByNodeId
     */
    public function __construct(
        public array $nodes,
        public array $candidatesByNodeId,
        public int $eligibleCandidateCount,
        public int $selectedCandidateCount,
    ) {}
}

final class FuelauFixedCorridorCandidateAdapter
{
    private const OFFICIAL_SOURCES = ['qld', 'sa', 'nsw', 'wa', 'tas', 'vic', 'nt'];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function build(
        FuelauRouteCorridor $corridor,
        array $rows,
        int $maximumOffRouteM = 75_000,
        int $maximumCandidates = 320,
        int $coverageBinM = 50_000,
    ): FuelauFixedCorridorInput {
        if ($maximumOffRouteM < 0 || $maximumCandidates < 1 || $coverageBinM < 1) {
            throw new InvalidArgumentException('Invalid fixed-corridor candidate limits.');
        }

        /** @var array<string, FuelauProjectedStationCandidate> $deduplicated */
        $deduplicated = [];
        foreach ($rows as $row) {
            $candidate = $this->projectRow($corridor, $row, $maximumOffRouteM);
            if ($candidate === null) {
                continue;
            }
            $existing = $deduplicated[$candidate->stableId] ?? null;
            if ($existing === null || $this->compareCandidates($candidate, $existing) < 0) {
                $deduplicated[$candidate->stableId] = $candidate;
            }
        }

        $eligible = array_values($deduplicated);
        /** @var array<int, list<FuelauProjectedStationCandidate>> $bins */
        $bins = [];
        foreach ($eligible as $candidate) {
            $bins[intdiv($candidate->progressM, $coverageBinM)][] = $candidate;
        }
        ksort($bins, SORT_NUMERIC);
        if (count($bins) > $maximumCandidates) {
            throw new InvalidArgumentException(
                'Candidate cap is too low to preserve one station in every non-empty coverage bin.',
            );
        }

        /** @var array<string, FuelauProjectedStationCandidate> $selected */
        $selected = [];
        foreach ($bins as $binCandidates) {
            usort($binCandidates, [$this, 'compareCoverageCandidates']);
            $selected[$binCandidates[0]->stableId] = $binCandidates[0];
        }

        $remaining = array_values(array_filter(
            $eligible,
            static fn (FuelauProjectedStationCandidate $candidate): bool =>
                !isset($selected[$candidate->stableId]),
        ));
        usort($remaining, [$this, 'compareCandidates']);
        foreach ($remaining as $candidate) {
            if (count($selected) >= $maximumCandidates) {
                break;
            }
            $selected[$candidate->stableId] = $candidate;
        }

        $selected = $this->removeProgressCollisions(array_values($selected));
        usort(
            $selected,
            static fn (
                FuelauProjectedStationCandidate $left,
                FuelauProjectedStationCandidate $right,
            ): int => ($left->progressM <=> $right->progressM)
                ?: strcmp($left->stableId, $right->stableId),
        );

        $nodes = [new FuelauOptimizerNode('origin', 0, progressS: 0)];
        $candidatesByNodeId = [];
        foreach ($selected as $candidate) {
            $nodes[] = FuelauOptimizerNode::station(
                id: $candidate->nodeId,
                progressM: $candidate->progressM,
                priceCentsPerL: $candidate->priceCentsPerL,
                label: $candidate->label,
                progressS: $candidate->progressS,
                accessDistanceM: $candidate->accessDistanceM,
                accessDurationS: $candidate->accessDurationS,
            );
            $candidatesByNodeId[$candidate->nodeId] = $candidate;
        }
        $nodes[] = new FuelauOptimizerNode(
            'destination',
            $corridor->distanceM,
            progressS: $corridor->durationS,
        );

        return new FuelauFixedCorridorInput(
            nodes: $nodes,
            candidatesByNodeId: $candidatesByNodeId,
            eligibleCandidateCount: count($eligible),
            selectedCandidateCount: count($selected),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function projectRow(
        FuelauRouteCorridor $corridor,
        array $row,
        int $maximumOffRouteM,
    ): ?FuelauProjectedStationCandidate {
        $source = strtolower(trim((string) ($row['source'] ?? '')));
        $stationId = trim((string) ($row['station_id'] ?? ''));
        $latitude = $row['latitude'] ?? null;
        $longitude = $row['longitude'] ?? null;
        $price = $row['price'] ?? null;
        if (
            !in_array($source, self::OFFICIAL_SOURCES, true)
            || $stationId === ''
            || !is_numeric((string) $latitude)
            || !is_numeric((string) $longitude)
            || !is_numeric((string) $price)
        ) {
            return null;
        }
        $price = (float) $price;
        if (!is_finite($price) || $price < 50 || $price > 500) {
            return null;
        }

        try {
            $projection = $corridor->project((float) $latitude, (float) $longitude);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (
            $projection->progressM <= 0
            || $projection->progressM >= $corridor->distanceM
            || $projection->offRouteM > $maximumOffRouteM
        ) {
            return null;
        }

        $state = strtoupper(trim((string) ($row['state'] ?? '')));
        $fuel = trim((string) ($row['fuel_code'] ?? $row['fuel_name'] ?? 'fuel'));
        $stableId = implode(':', [$source, $state, $stationId, $fuel]);
        $stationName = trim((string) ($row['station_name'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        $measuredAccessDistanceM = $row['access_distance_m'] ?? null;
        $measuredAccessDurationS = $row['access_duration_s'] ?? null;
        $hasMeasuredAccessDistance = is_numeric((string) $measuredAccessDistanceM)
            && (float) $measuredAccessDistanceM >= 0;
        $hasMeasuredAccessDuration = is_numeric((string) $measuredAccessDurationS)
            && (float) $measuredAccessDurationS >= 0;
        $accessDistanceM = $hasMeasuredAccessDistance
            ? (int) round((float) $measuredAccessDistanceM)
            : (int) ceil($projection->offRouteM * 1.15);
        $accessDurationS = $hasMeasuredAccessDuration
            ? (int) round((float) $measuredAccessDurationS)
            : 0;
        $label = implode(' - ', array_filter(
            [$stationName, $address],
            static fn (string $part): bool => $part !== '',
        ));

        return new FuelauProjectedStationCandidate(
            stableId: $stableId,
            nodeId: "station:{$stableId}",
            label: $label,
            progressM: $projection->progressM,
            progressS: $projection->progressS,
            offRouteM: $projection->offRouteM,
            accessDistanceM: $accessDistanceM,
            accessDurationS: $accessDurationS,
            accessEstimated: !$hasMeasuredAccessDistance || !$hasMeasuredAccessDuration,
            priceCentsPerL: $price,
            sourceRow: $row,
        );
    }

    private function compareCandidates(
        FuelauProjectedStationCandidate $left,
        FuelauProjectedStationCandidate $right,
    ): int {
        return ($left->priceCentsPerL <=> $right->priceCentsPerL)
            ?: ($left->offRouteM <=> $right->offRouteM)
            ?: ($left->progressM <=> $right->progressM)
            ?: strcmp($left->stableId, $right->stableId);
    }

    private function compareCoverageCandidates(
        FuelauProjectedStationCandidate $left,
        FuelauProjectedStationCandidate $right,
    ): int {
        return ($left->offRouteM <=> $right->offRouteM)
            ?: ($left->priceCentsPerL <=> $right->priceCentsPerL)
            ?: strcmp($left->stableId, $right->stableId);
    }

    /**
     * @param list<FuelauProjectedStationCandidate> $candidates
     * @return list<FuelauProjectedStationCandidate>
     */
    private function removeProgressCollisions(array $candidates): array
    {
        /** @var array<int, FuelauProjectedStationCandidate> $byProgress */
        $byProgress = [];
        foreach ($candidates as $candidate) {
            $existing = $byProgress[$candidate->progressM] ?? null;
            if ($existing === null || $this->compareCandidates($candidate, $existing) < 0) {
                $byProgress[$candidate->progressM] = $candidate;
            }
        }

        return array_values($byProgress);
    }
}

class FuelauRoutePlanValidationException extends RuntimeException {}

final class FuelauRoutePlanningUnsupportedException extends FuelauRoutePlanValidationException {}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function fuelauClassifyRouteCandidatePriceRows(
    array $rows,
    DateTimeImmutable $asOf,
    int $maximumAgeDays = 14,
): array {
    if ($maximumAgeDays < 1 || $maximumAgeDays > 90) {
        throw new InvalidArgumentException('Candidate price age must be between 1 and 90 days.');
    }

    $minimumTimestamp = $asOf->getTimestamp() - ($maximumAgeDays * 86_400);
    $maximumTimestamp = $asOf->getTimestamp();
    $timezone = new DateTimeZone('UTC');
    foreach ($rows as &$row) {
        $rawUpdatedAt = trim((string) ($row['updated_at'] ?? ''));
        try {
            $updatedAt = $rawUpdatedAt !== ''
                ? new DateTimeImmutable($rawUpdatedAt, $timezone)
                : null;
        } catch (Exception) {
            $updatedAt = null;
        }
        $timestamp = $updatedAt?->getTimestamp();
        $row['price_status'] = $timestamp !== null
            && $timestamp >= $minimumTimestamp
            && $timestamp <= $maximumTimestamp
            ? 'fresh'
            : 'stale';
    }
    unset($row);

    return array_values($rows);
}

final class FuelauCandidateRoadAccessMeasurer
{
    /**
     * @param list<array<string, mixed>> $candidateRows
     * @param callable(list<array{lat: float, lon: float}>): array{
     *     distances: list<list<int|null>>,
     *     durations: list<list<int|null>>
     * } $tableLoader
     * @return list<array<string, mixed>>
     */
    public function measure(
        FuelauRouteCorridor $corridor,
        array $candidateRows,
        callable $tableLoader,
        int $maximumCandidates = 80,
        int $chunkSize = 20,
    ): array {
        if ($maximumCandidates < 1 || $maximumCandidates > 80 || $chunkSize < 1 || $chunkSize > 20) {
            throw new InvalidArgumentException('Invalid road-access measurement limits.');
        }

        $input = (new FuelauFixedCorridorCandidateAdapter())->build(
            $corridor,
            $candidateRows,
            maximumCandidates: $maximumCandidates,
            coverageBinM: max(
                50_000,
                (int) ceil($corridor->distanceM / $maximumCandidates),
            ),
        );
        $candidates = array_values($input->candidatesByNodeId);
        $measuredRows = [];
        foreach (array_chunk($candidates, $chunkSize) as $chunk) {
            $coordinates = [];
            foreach ($chunk as $candidate) {
                $coordinates[] = $corridor->coordinateAtProgressM($candidate->progressM);
                $coordinates[] = [
                    'lat' => (float) $candidate->sourceRow['latitude'],
                    'lon' => (float) $candidate->sourceRow['longitude'],
                ];
            }
            $table = $tableLoader($coordinates);
            $coordinateCount = count($coordinates);
            foreach ($chunk as $index => $candidate) {
                $anchorIndex = $index * 2;
                $stationIndex = $anchorIndex + 1;
                $outboundDistanceM = $this->matrixValue(
                    $table,
                    'distances',
                    $anchorIndex,
                    $stationIndex,
                    $coordinateCount,
                );
                $returnDistanceM = $this->matrixValue(
                    $table,
                    'distances',
                    $stationIndex,
                    $anchorIndex,
                    $coordinateCount,
                );
                $outboundDurationS = $this->matrixValue(
                    $table,
                    'durations',
                    $anchorIndex,
                    $stationIndex,
                    $coordinateCount,
                );
                $returnDurationS = $this->matrixValue(
                    $table,
                    'durations',
                    $stationIndex,
                    $anchorIndex,
                    $coordinateCount,
                );
                if (
                    $outboundDistanceM === null
                    || $returnDistanceM === null
                    || $outboundDurationS === null
                    || $returnDurationS === null
                ) {
                    continue;
                }

                $measuredRows[] = [
                    ...$candidate->sourceRow,
                    'access_distance_m' => (int) ceil(
                        ($outboundDistanceM + $returnDistanceM) / 2,
                    ),
                    'access_duration_s' => (int) ceil(
                        ($outboundDurationS + $returnDurationS) / 2,
                    ),
                    'road_access_status' => 'measured',
                ];
            }
        }

        return $measuredRows;
    }

    /**
     * @param array<string, mixed> $table
     */
    private function matrixValue(
        array $table,
        string $matrixName,
        int $rowIndex,
        int $columnIndex,
        int $coordinateCount,
    ): ?int {
        $matrix = $table[$matrixName] ?? null;
        if (!is_array($matrix) || count($matrix) !== $coordinateCount) {
            throw new FuelauUpstreamException("OSRM {$matrixName} matrix has an invalid size.");
        }
        $row = $matrix[$rowIndex] ?? null;
        if (!is_array($row) || count($row) !== $coordinateCount) {
            throw new FuelauUpstreamException("OSRM {$matrixName} row has an invalid size.");
        }
        $value = $row[$columnIndex] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new FuelauUpstreamException("OSRM {$matrixName} matrix contains an invalid value.");
        }

        return $value;
    }
}

final readonly class FuelauExactRouteValidation
{
    public function __construct(
        public int $modeledDistanceM,
        public int $exactDistanceM,
        public int $distanceDeltaM,
        public int $modeledDurationS,
        public int $exactDurationS,
        public int $durationDeltaS,
        public int $fuelBucketDelta,
        public bool $requiresReoptimization,
    ) {}
}

final class FuelauExactRouteValidator
{
    /**
     * @param array<string, mixed> $exactRoute
     */
    public function validate(
        FuelauSingleCorridorOptimizationResult $result,
        array $exactRoute,
    ): FuelauExactRouteValidation {
        $distance = $exactRoute['distance'] ?? null;
        $duration = $exactRoute['duration'] ?? null;
        if (
            !is_numeric((string) $distance)
            || !is_numeric((string) $duration)
            || (float) $distance <= 0
            || (float) $duration <= 0
        ) {
            throw new FuelauUpstreamException('Exact OSRM route is missing distance or duration.');
        }

        $modeledDetourDistanceM = array_reduce(
            $result->plan->purchases,
            static fn (int $total, FuelauOptimizerPurchase $purchase): int =>
                $total + $purchase->detourDistanceM,
            0,
        );
        $modeledDetourDurationS = array_reduce(
            $result->plan->purchases,
            static fn (int $total, FuelauOptimizerPurchase $purchase): int =>
                $total + $purchase->detourDurationS,
            0,
        );
        $modeledDistanceM = $result->corridor->distanceM + $modeledDetourDistanceM;
        $modeledDurationS = $result->corridor->durationS + $modeledDetourDurationS;
        $exactDistanceM = (int) round((float) $distance);
        $exactDurationS = (int) round((float) $duration);
        $distanceDeltaM = $exactDistanceM - $modeledDistanceM;
        $durationDeltaS = $exactDurationS - $modeledDurationS;
        $economyLPer100km = $result->request->fuel->economyLPer100km;
        $modeledFuelBuckets = (int) ceil(
            (($modeledDistanceM / 100_000) * $economyLPer100km) / 0.5,
        );
        $exactFuelBuckets = (int) ceil(
            (($exactDistanceM / 100_000) * $economyLPer100km) / 0.5,
        );
        $fuelBucketDelta = $exactFuelBuckets - $modeledFuelBuckets;

        return new FuelauExactRouteValidation(
            modeledDistanceM: $modeledDistanceM,
            exactDistanceM: $exactDistanceM,
            distanceDeltaM: $distanceDeltaM,
            modeledDurationS: $modeledDurationS,
            exactDurationS: $exactDurationS,
            durationDeltaS: $durationDeltaS,
            fuelBucketDelta: $fuelBucketDelta,
            requiresReoptimization: abs($distanceDeltaM) > 2_000
                || abs($durationDeltaS) > 180
                || abs($fuelBucketDelta) > 1,
        );
    }
}

final readonly class FuelauValidatedSingleCorridorPlan
{
    /**
     * @param array<string, mixed> $exactRoute
     */
    public function __construct(
        public FuelauSingleCorridorOptimizationResult $result,
        public FuelauExactRouteValidation $validation,
        public array $exactRoute,
        public int $validationPassCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        $response = $this->result->toResponseArray();
        $exactDetourDistanceM = max(
            0,
            $this->validation->exactDistanceM - $this->result->corridor->distanceM,
        );
        $exactDetourDurationS = max(
            0,
            $this->validation->exactDurationS - $this->result->corridor->durationS,
        );
        $exactDrivingTimeCostCents = (int) ceil(
            (
                $this->validation->exactDurationS
                * $this->result->policy->driverTimeValueCentsPerHour
            ) / 3_600,
        );
        $response['summary']['route_distance_m'] = $this->validation->exactDistanceM;
        $response['summary']['route_duration_s'] = $this->validation->exactDurationS;
        $response['summary']['detour_distance_m'] = $exactDetourDistanceM;
        $response['summary']['detour_duration_s'] = $exactDetourDurationS;
        $response['summary']['generalized_cost_cents'] =
            $this->result->plan->fuelPurchaseCostCents
            + ($this->result->plan->fuelStopCount * $this->result->policy->stopCostCents())
            + $exactDrivingTimeCostCents;
        $response['route_pieces'] = [[
            'kind' => 'selected_route',
            'distance_m' => $this->validation->exactDistanceM,
            'duration_s' => $this->validation->exactDurationS,
            'geometry' => is_array($this->exactRoute['geometry'] ?? null)
                ? $this->exactRoute['geometry']
                : null,
        ]];
        $response['diagnostics']['validation_pass_count'] = $this->validationPassCount;
        $response['diagnostics']['exact_distance_delta_m'] = $this->validation->distanceDeltaM;
        $response['diagnostics']['exact_duration_delta_s'] = $this->validation->durationDeltaS;

        return $response;
    }
}

final class FuelauSingleCorridorValidationCoordinator
{
    /**
     * @param list<array<string, mixed>> $candidateRows
     * @param callable(list<array{lat: float, lon: float}>): array<string, mixed> $exactRouteLoader
     */
    public function planAndValidate(
        FuelauRouteOptimizationRequest $request,
        FuelauRouteCorridor $corridor,
        array $candidateRows,
        callable $exactRouteLoader,
        int $maximumValidationPasses = 2,
    ): FuelauValidatedSingleCorridorPlan {
        if ($maximumValidationPasses < 1 || $maximumValidationPasses > 2) {
            throw new InvalidArgumentException('Exact route validation passes must be between 1 and 2.');
        }

        $planner = new FuelauSingleCorridorPlanner();
        $validator = new FuelauExactRouteValidator();
        $rows = array_values($candidateRows);
        for ($pass = 1; $pass <= $maximumValidationPasses; $pass++) {
            $result = $planner->plan($request, $corridor, $rows);
            $exactRoute = $exactRouteLoader($result->exactRouteCoordinates());
            $validation = $validator->validate($result, $exactRoute);
            if (!$validation->requiresReoptimization) {
                return new FuelauValidatedSingleCorridorPlan(
                    result: $result,
                    validation: $validation,
                    exactRoute: $exactRoute,
                    validationPassCount: $pass,
                );
            }
            if ($pass === $maximumValidationPasses) {
                break;
            }
            $rows = $this->reconcileSelectedAccess($rows, $result, $validation);
        }

        throw new FuelauRoutePlanValidationException(
            'Exact selected-stop routing did not stabilize within the validation budget.',
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function reconcileSelectedAccess(
        array $rows,
        FuelauSingleCorridorOptimizationResult $result,
        FuelauExactRouteValidation $validation,
    ): array {
        $selectedIds = [];
        $currentAccessDistanceM = 0;
        $currentAccessDurationS = 0;
        foreach ($result->plan->purchases as $purchase) {
            $candidate = $result->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null) {
                continue;
            }
            $selectedIds[$candidate->stableId] = true;
            $currentAccessDistanceM += $candidate->accessDistanceM;
            $currentAccessDurationS += $candidate->accessDurationS;
        }
        if ($selectedIds === []) {
            return $rows;
        }

        $targetAccessDistanceM = max(
            0,
            (int) ceil(($validation->exactDistanceM - $result->corridor->distanceM) / 2),
        );
        $targetAccessDurationS = max(
            0,
            (int) ceil(($validation->exactDurationS - $result->corridor->durationS) / 2),
        );
        $selectedCount = count($selectedIds);
        foreach ($rows as &$row) {
            $stableId = $this->stableRowId($row);
            if (!isset($selectedIds[$stableId])) {
                continue;
            }
            $row['access_distance_m'] = $currentAccessDistanceM > 0
                ? (int) ceil(
                    ((float) ($row['access_distance_m'] ?? 0) / $currentAccessDistanceM)
                    * $targetAccessDistanceM,
                )
                : (int) ceil($targetAccessDistanceM / $selectedCount);
            $row['access_duration_s'] = $currentAccessDurationS > 0
                ? (int) ceil(
                    ((float) ($row['access_duration_s'] ?? 0) / $currentAccessDurationS)
                    * $targetAccessDurationS,
                )
                : (int) ceil($targetAccessDurationS / $selectedCount);
            $row['road_access_status'] = 'exact_route_reconciled';
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stableRowId(array $row): string
    {
        return implode(':', [
            strtolower(trim((string) ($row['source'] ?? ''))),
            strtoupper(trim((string) ($row['state'] ?? ''))),
            trim((string) ($row['station_id'] ?? '')),
            trim((string) ($row['fuel_code'] ?? $row['fuel_name'] ?? 'fuel')),
        ]);
    }
}

final readonly class FuelauSingleCorridorOptimizationResult
{
    public function __construct(
        public FuelauRouteOptimizationRequest $request,
        public FuelauRouteCorridor $corridor,
        public FuelauFixedCorridorInput $input,
        public FuelauOptimizerPlan $plan,
        public FuelauOptimizerPolicy $policy,
    ) {}

    /**
     * @return list<array{lat: float, lon: float}>
     */
    public function exactRouteCoordinates(): array
    {
        $coordinates = [[
            'lat' => $this->request->origin->latitude,
            'lon' => $this->request->origin->longitude,
        ]];
        foreach ($this->plan->purchases as $purchase) {
            $candidate = $this->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            $latitude = $candidate?->sourceRow['latitude'] ?? null;
            $longitude = $candidate?->sourceRow['longitude'] ?? null;
            if (!is_numeric((string) $latitude) || !is_numeric((string) $longitude)) {
                throw new LogicException("Missing station coordinates for {$purchase->nodeId}.");
            }
            $coordinates[] = ['lat' => (float) $latitude, 'lon' => (float) $longitude];
        }
        $destination = $this->request->destinations[0];
        $coordinates[] = ['lat' => $destination->latitude, 'lon' => $destination->longitude];

        return $coordinates;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseArray(): array
    {
        $detourDistanceM = 0;
        $detourDurationS = 0;
        $priceTimestamps = [];
        $warnings = [];
        $stops = [];
        $previousProgressM = 0;
        $previousProgressS = 0;
        $previousAccessDistanceM = 0;
        $previousAccessDurationS = 0;

        foreach ($this->plan->purchases as $index => $purchase) {
            $candidate = $this->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null) {
                throw new LogicException("Missing candidate metadata for {$purchase->nodeId}.");
            }
            $row = $candidate->sourceRow;
            $accessDistanceM = intdiv($purchase->detourDistanceM, 2);
            $accessDurationS = intdiv($purchase->detourDurationS, 2);
            $distanceSincePhysicalStopM = ($purchase->progressM - $previousProgressM)
                + $previousAccessDistanceM
                + $accessDistanceM;
            $durationSincePhysicalStopS = ($purchase->progressS - $previousProgressS)
                + $previousAccessDurationS
                + $accessDurationS;
            $updatedAt = trim((string) ($row['updated_at'] ?? ''));
            if ($updatedAt !== '') {
                $priceTimestamps[] = $updatedAt;
            }
            if (in_array('sparse_corridor', $purchase->reasonCodes, true)) {
                $sequence = $index + 1;
                $warnings[] = "Stop {$sequence} exceeds normal discretionary detour limits for route safety.";
            }

            $stops[] = [
                'sequence' => $index + 1,
                'classification' => $purchase->classification,
                'reason_codes' => $purchase->reasonCodes,
                'station' => [
                    'source' => (string) ($row['source'] ?? ''),
                    'state' => (string) ($row['state'] ?? ''),
                    'station_id' => (string) ($row['station_id'] ?? ''),
                    'station_name' => (string) ($row['station_name'] ?? ''),
                    'address' => (string) ($row['address'] ?? ''),
                    'latitude' => is_numeric((string) ($row['latitude'] ?? null))
                        ? (float) $row['latitude']
                        : null,
                    'longitude' => is_numeric((string) ($row['longitude'] ?? null))
                        ? (float) $row['longitude']
                        : null,
                ],
                'price_cents_per_l' => $purchase->priceCentsPerL,
                'price_status' => 'fresh',
                'route_progress_km' => round($purchase->progressM / 1_000, 1),
                'arrival_fuel_l' => $purchase->arrivalFuelL,
                'purchase_l' => $purchase->purchaseL,
                'departure_fuel_l' => $purchase->departureFuelL,
                'purchase_cost_cents' => $purchase->purchaseCostCents,
                'detour_distance_m' => $purchase->detourDistanceM,
                'detour_duration_s' => $purchase->detourDurationS,
                'distance_since_physical_stop_km' => round(
                    $distanceSincePhysicalStopM / 1_000,
                    1,
                ),
                'minutes_since_physical_stop' => round($durationSincePhysicalStopS / 60, 1),
                'marginal_net_saving_cents' => $purchase->marginalNetSavingCents,
            ];

            $detourDistanceM += $purchase->detourDistanceM;
            $detourDurationS += $purchase->detourDurationS;
            $previousProgressM = $purchase->progressM;
            $previousProgressS = $purchase->progressS;
            $previousAccessDistanceM = $accessDistanceM;
            $previousAccessDurationS = $accessDurationS;
        }

        sort($priceTimestamps, SORT_STRING);
        $fuelUsedL = $this->request->fuel->startingFuelL
            + $this->plan->fuelPurchasedL
            - $this->plan->endingFuelL;
        $corridorTimeCostCents = (int) ceil(
            ($this->corridor->durationS * $this->policy->driverTimeValueCentsPerHour) / 3_600,
        );

        return [
            'version' => 1,
            'status' => 'ok',
            'objective' => [
                'mode' => $this->request->preferences->mode,
                'starting_fuel_cost_included' => false,
                'terminal_reserve_l' => $this->request->fuel->reserveL,
                'driver_time_value_cents_per_hour' => $this->policy->driverTimeValueCentsPerHour,
                'minimum_net_saving_cents' => $this->policy->minimumNetSavingCents,
            ],
            'summary' => [
                'route_distance_m' => $this->corridor->distanceM + $detourDistanceM,
                'route_duration_s' => $this->corridor->durationS + $detourDurationS,
                'detour_distance_m' => $detourDistanceM,
                'detour_duration_s' => $detourDurationS,
                'fuel_used_l' => round($fuelUsedL, 1),
                'fuel_purchased_l' => $this->plan->fuelPurchasedL,
                'fuel_purchase_cost_cents' => $this->plan->fuelPurchaseCostCents,
                'generalized_cost_cents' => $this->plan->generalizedCostCents
                    + $corridorTimeCostCents,
                'starting_fuel_l' => $this->request->fuel->startingFuelL,
                'ending_fuel_l' => $this->plan->endingFuelL,
                'required_stop_count' => $this->plan->requiredStopCount,
                'discretionary_stop_count' => $this->plan->discretionaryStopCount,
                'combined_stop_count' => 0,
                'price_as_of' => $priceTimestamps[0] ?? null,
            ],
            'corridor' => [
                'id' => 'corridor-1',
                'kind' => 'fastest',
                'distance_m' => $this->corridor->distanceM,
                'duration_s' => $this->corridor->durationS,
            ],
            'route_pieces' => [],
            'stops' => $stops,
            'alternatives' => [],
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => [
                'candidate_count' => $this->input->eligibleCandidateCount,
                'network_shortlist_count' => $this->input->selectedCandidateCount,
            ],
        ];
    }
}

final class FuelauSingleCorridorPlanner
{
    /**
     * @param list<array<string, mixed>> $candidateRows
     */
    public function plan(
        FuelauRouteOptimizationRequest $request,
        FuelauRouteCorridor $corridor,
        array $candidateRows,
    ): FuelauSingleCorridorOptimizationResult {
        if ($request->returnMode !== 'one_way' || count($request->destinations) !== 1) {
            throw new FuelauRoutePlanningUnsupportedException(
                'Single-corridor planning currently requires one destination and one_way return mode.',
            );
        }

        $freshRows = array_values(array_filter(
            $candidateRows,
            static fn (array $row): bool => ($row['price_status'] ?? null) === 'fresh',
        ));
        $input = (new FuelauFixedCorridorCandidateAdapter())->build(
            $corridor,
            $freshRows,
        );
        $preferences = $request->preferences;
        $policy = new FuelauOptimizerPolicy(
            mode: $preferences->mode,
            maximumFuelOnlyStops: $preferences->maximumFuelOnlyStops,
            minimumDiscretionaryPurchaseL: $preferences->minimumDiscretionaryPurchaseL,
            minimumStopSpacingM: (int) round($preferences->minimumStopSpacingKm * 1_000),
            minimumStopSpacingS: (int) round($preferences->minimumStopSpacingMinutes * 60),
            maximumDiscretionaryDetourM: (int) round(
                $preferences->maximumDiscretionaryDetourKm * 1_000,
            ),
            maximumDiscretionaryDetourS: (int) round(
                $preferences->maximumDiscretionaryDetourMinutes * 60,
            ),
            minimumNetSavingCents: $preferences->minimumNetSavingCents,
            driverTimeValueCentsPerHour: $preferences->driverTimeValueCentsPerHour,
            fuelOnlyStopSeconds: (int) round($preferences->fuelOnlyStopMinutes * 60),
        );
        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            $input->nodes,
            new FuelauOptimizerVehicle(
                tankCapacityL: $request->fuel->tankCapacityL,
                startingFuelL: $request->fuel->startingFuelL,
                reserveL: $request->fuel->reserveL,
                economyLPer100km: $request->fuel->economyLPer100km,
            ),
            $policy,
        );

        foreach ($plan->purchases as $purchase) {
            $candidate = $input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null || $candidate->accessEstimated) {
                throw new FuelauRoutePlanValidationException(
                    'Selected station access must be validated with road-network distance and duration.',
                );
            }
        }

        return new FuelauSingleCorridorOptimizationResult(
            request: $request,
            corridor: $corridor,
            input: $input,
            plan: $plan,
            policy: $policy,
        );
    }
}

final class FuelauLiveSingleCorridorPlanner
{
    private Closure $routeLoader;
    private Closure $candidateLoader;
    private Closure $tableLoader;
    private Closure $clock;

    public function __construct(
        ?Closure $routeLoader = null,
        ?Closure $candidateLoader = null,
        ?Closure $tableLoader = null,
        ?Closure $clock = null,
    ) {
        $this->routeLoader = $routeLoader ?? static function (array $coordinates): array {
            $payload = fuelauRoutePlan($coordinates, false);
            $route = $payload['routes'][0] ?? null;
            if (($payload['code'] ?? null) !== 'Ok' || !is_array($route)) {
                throw new FuelauUpstreamException('OSRM did not return a usable route.');
            }

            return $route;
        };
        $this->candidateLoader = $candidateLoader ?? static function (
            array $points,
            string $fuel,
        ): array {
            return fuelauCachedCoverageBalancedRouteCandidateRows(
                fuelauPdo(),
                $points,
                $fuel,
                75,
                5_000,
                fuelauProjectRoot() . '/var/docker/app-state/route-candidate-cache',
            );
        };
        $this->tableLoader = $tableLoader ?? static fn (array $coordinates): array =>
            fuelauOsrmTable($coordinates);
        $this->clock = $clock ?? static fn (): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(FuelauRouteOptimizationRequest $request): array
    {
        if ($request->returnMode !== 'one_way' || count($request->destinations) !== 1) {
            throw new FuelauRoutePlanningUnsupportedException(
                'Live optimization currently supports one one-way destination.',
            );
        }

        $routeRequestCount = 0;
        $tableRequestCount = 0;
        $loadRoute = function (array $coordinates) use (&$routeRequestCount): array {
            $routeRequestCount++;

            return ($this->routeLoader)($coordinates);
        };
        $baselineCoordinates = [
            ['lat' => $request->origin->latitude, 'lon' => $request->origin->longitude],
            [
                'lat' => $request->destinations[0]->latitude,
                'lon' => $request->destinations[0]->longitude,
            ],
        ];
        $baselineRoute = $loadRoute($baselineCoordinates);
        $corridor = FuelauRouteCorridor::fromOsrmRoute($baselineRoute);
        $candidateRows = ($this->candidateLoader)(
            $corridor->candidateLookupPoints(),
            $request->fuel->type,
        );
        if (!is_array($candidateRows)) {
            throw new RuntimeException('Route candidate loader returned an invalid result.');
        }
        $classifiedRows = fuelauClassifyRouteCandidatePriceRows(
            array_values($candidateRows),
            ($this->clock)(),
        );
        $freshRows = array_values(array_filter(
            $classifiedRows,
            static fn (array $row): bool => ($row['price_status'] ?? null) === 'fresh',
        ));
        $measuredRows = (new FuelauCandidateRoadAccessMeasurer())->measure(
            $corridor,
            $freshRows,
            function (array $coordinates) use (&$tableRequestCount): array {
                $tableRequestCount++;

                return ($this->tableLoader)($coordinates);
            },
        );
        $validated = (new FuelauSingleCorridorValidationCoordinator())->planAndValidate(
            $request,
            $corridor,
            $measuredRows,
            $loadRoute,
        );
        $response = $validated->toResponseArray();
        $response['diagnostics']['raw_candidate_count'] = count($candidateRows);
        $response['diagnostics']['fresh_candidate_count'] = count($freshRows);
        $response['diagnostics']['osrm_route_request_count'] = $routeRequestCount;
        $response['diagnostics']['osrm_table_request_count'] = $tableRequestCount;

        return $response;
    }
}
