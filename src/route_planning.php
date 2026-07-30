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
