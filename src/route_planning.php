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
     * @return list<array{lat: float, lon: float}>
     */
    public function geometryPoints(): array
    {
        return $this->geometry;
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

final readonly class FuelauPreparedItineraryLeg
{
    /**
     * @param list<array<string, mixed>> $candidateRows
     */
    public function __construct(
        public int $index,
        public FuelauRouteCorridor $corridor,
        public FuelauRouteOptimizationLocation $target,
        public array $candidateRows,
    ) {}
}

final readonly class FuelauCompleteItineraryInput
{
    /**
     * @param list<array{
     *     index: int,
     *     start_m: int,
     *     end_m: int,
     *     start_s: int,
     *     end_s: int,
     *     target: FuelauRouteOptimizationLocation
     * }> $legSummaries
     */
    public function __construct(
        public FuelauRouteCorridor $corridor,
        public FuelauFixedCorridorInput $input,
        public array $legSummaries,
    ) {}

    /**
     * @return list<array{lat: float, lon: float}>
     */
    public function exactRouteCoordinates(FuelauOptimizerPlan $plan): array
    {
        $coordinates = [];
        $firstGeometryPoint = $this->corridor->geometryPoints()[0];
        $coordinates[] = $firstGeometryPoint;
        $purchaseIndex = 0;
        foreach ($this->legSummaries as $leg) {
            while (
                isset($plan->purchases[$purchaseIndex])
                && $plan->purchases[$purchaseIndex]->progressM < $leg['end_m']
            ) {
                $purchase = $plan->purchases[$purchaseIndex];
                $candidate = $this->input->candidatesByNodeId[$purchase->nodeId] ?? null;
                $latitude = $candidate?->sourceRow['latitude'] ?? null;
                $longitude = $candidate?->sourceRow['longitude'] ?? null;
                if (!is_numeric((string) $latitude) || !is_numeric((string) $longitude)) {
                    throw new LogicException("Missing station coordinates for {$purchase->nodeId}.");
                }
                $coordinates[] = [
                    'lat' => (float) $latitude,
                    'lon' => (float) $longitude,
                ];
                $purchaseIndex++;
            }
            $coordinates[] = [
                'lat' => $leg['target']->latitude,
                'lon' => $leg['target']->longitude,
            ];
        }

        return $coordinates;
    }
}

final class FuelauCompleteItineraryAssembler
{
    private const COMBINED_STOP_DISTANCE_M = 5_000;
    private const COMBINED_STOP_DURATION_S = 300;

    /**
     * @param list<FuelauPreparedItineraryLeg> $legs
     */
    public function build(
        FuelauRouteOptimizationRequest $request,
        array $legs,
        FuelauOptimizerPolicy $policy,
    ): FuelauCompleteItineraryInput {
        $locations = $request->itineraryLocations();
        if (count($legs) !== count($locations) - 1 || $legs === []) {
            throw new InvalidArgumentException(
                'Prepared itinerary legs must match the expanded request itinerary.',
            );
        }

        $nodes = [new FuelauOptimizerNode(
            'origin',
            0,
            progressS: 0,
            physicalStop: true,
        )];
        $candidatesByNodeId = [];
        $combinedGeometry = [];
        $legSummaries = [];
        $distanceOffsetM = 0;
        $durationOffsetS = 0;
        $eligibleCandidateCount = 0;
        $selectedCandidateCount = 0;
        foreach (array_values($legs) as $legIndex => $leg) {
            if (!$leg instanceof FuelauPreparedItineraryLeg || $leg->index !== $legIndex) {
                throw new InvalidArgumentException('Prepared itinerary leg order is invalid.');
            }
            $expectedTarget = $locations[$legIndex + 1];
            if (
                $leg->target->latitude !== $expectedTarget->latitude
                || $leg->target->longitude !== $expectedTarget->longitude
                || $leg->target->label !== $expectedTarget->label
                || $leg->target->physicalStop !== $expectedTarget->physicalStop
            ) {
                throw new InvalidArgumentException(
                    "Prepared itinerary leg {$legIndex} has an unexpected target.",
                );
            }
            $eligibleRows = fuelauEligibleOptimizerCandidateRows(
                $leg->candidateRows,
                $policy,
            );
            $legInput = (new FuelauFixedCorridorCandidateAdapter())->build(
                $leg->corridor,
                $eligibleRows,
            );
            $eligibleCandidateCount += $legInput->eligibleCandidateCount;
            $selectedCandidateCount += $legInput->selectedCandidateCount;
            foreach ($legInput->candidatesByNodeId as $candidate) {
                $nodeId = "station:{$candidate->stableId}:visit:{$legIndex}";
                $combinedStopIndex = $this->combinedStopIndex(
                    $locations,
                    $legIndex,
                    $candidate,
                    $leg->corridor,
                );
                $globalCandidate = new FuelauProjectedStationCandidate(
                    stableId: $candidate->stableId,
                    nodeId: $nodeId,
                    label: $candidate->label,
                    progressM: $distanceOffsetM + $candidate->progressM,
                    progressS: $durationOffsetS + $candidate->progressS,
                    offRouteM: $candidate->offRouteM,
                    accessDistanceM: $candidate->accessDistanceM,
                    accessDurationS: $candidate->accessDurationS,
                    accessEstimated: $candidate->accessEstimated,
                    priceCentsPerL: $candidate->priceCentsPerL,
                    sourceRow: [
                        ...$candidate->sourceRow,
                        'itinerary_leg_index' => $legIndex,
                        'combined_itinerary_stop_index' => $combinedStopIndex,
                    ],
                );
                $nodes[] = FuelauOptimizerNode::station(
                    id: $nodeId,
                    progressM: $globalCandidate->progressM,
                    priceCentsPerL: $globalCandidate->priceCentsPerL,
                    label: $globalCandidate->label,
                    progressS: $globalCandidate->progressS,
                    accessDistanceM: $globalCandidate->accessDistanceM,
                    accessDurationS: $globalCandidate->accessDurationS,
                    combinedStop: $combinedStopIndex !== null,
                    combinedStopReason: $combinedStopIndex === 0
                        ? 'origin_departure_top_up'
                        : ($combinedStopIndex !== null
                            ? 'planned_stop_combination'
                            : null),
                );
                $candidatesByNodeId[$nodeId] = $globalCandidate;
            }

            $legStartM = $distanceOffsetM;
            $legStartS = $durationOffsetS;
            $distanceOffsetM += $leg->corridor->distanceM;
            $durationOffsetS += $leg->corridor->durationS;
            $nodes[] = new FuelauOptimizerNode(
                $legIndex === count($legs) - 1
                    ? 'destination'
                    : "itinerary-stop:" . ($legIndex + 1),
                $distanceOffsetM,
                label: $leg->target->label,
                progressS: $durationOffsetS,
                physicalStop: $leg->target->physicalStop,
            );
            $legSummaries[] = [
                'index' => $legIndex,
                'start_m' => $legStartM,
                'end_m' => $distanceOffsetM,
                'start_s' => $legStartS,
                'end_s' => $durationOffsetS,
                'target' => $leg->target,
            ];
            foreach ($leg->corridor->geometryPoints() as $pointIndex => $point) {
                if ($combinedGeometry !== [] && $pointIndex === 0) {
                    continue;
                }
                $combinedGeometry[] = $point;
            }
        }

        usort(
            $nodes,
            static fn(FuelauOptimizerNode $left, FuelauOptimizerNode $right): int =>
                ($left->progressM <=> $right->progressM)
                ?: strcmp($left->id, $right->id),
        );

        return new FuelauCompleteItineraryInput(
            corridor: new FuelauRouteCorridor(
                $distanceOffsetM,
                $durationOffsetS,
                $combinedGeometry,
            ),
            input: new FuelauFixedCorridorInput(
                nodes: $nodes,
                candidatesByNodeId: $candidatesByNodeId,
                eligibleCandidateCount: $eligibleCandidateCount,
                selectedCandidateCount: $selectedCandidateCount,
            ),
            legSummaries: $legSummaries,
        );
    }

    /**
     * @param list<FuelauRouteOptimizationLocation> $locations
     */
    private function combinedStopIndex(
        array $locations,
        int $legIndex,
        FuelauProjectedStationCandidate $candidate,
        FuelauRouteCorridor $corridor,
    ): ?int {
        $matches = [];
        $start = $locations[$legIndex];
        if (
            $start->physicalStop
            && $candidate->progressM + $candidate->accessDistanceM
                <= self::COMBINED_STOP_DISTANCE_M
            && $candidate->progressS + $candidate->accessDurationS
                <= self::COMBINED_STOP_DURATION_S
        ) {
            $matches[$legIndex] =
                $candidate->progressM + $candidate->accessDistanceM;
        }

        $target = $locations[$legIndex + 1];
        $remainingDistanceM = $corridor->distanceM - $candidate->progressM;
        $remainingDurationS = $corridor->durationS - $candidate->progressS;
        if (
            $target->physicalStop
            && $remainingDistanceM + $candidate->accessDistanceM
                <= self::COMBINED_STOP_DISTANCE_M
            && $remainingDurationS + $candidate->accessDurationS
                <= self::COMBINED_STOP_DURATION_S
        ) {
            $matches[$legIndex + 1] =
                $remainingDistanceM + $candidate->accessDistanceM;
        }
        if ($matches === []) {
            return null;
        }

        asort($matches, SORT_NUMERIC);

        return (int) array_key_first($matches);
    }
}

final class FuelauFixedCorridorCandidateAdapter
{
    private const OFFICIAL_SOURCES = ['qld', 'sa', 'nsw', 'wa', 'tas', 'vic', 'nt'];
    private const COMBINED_STOP_DISTANCE_M = 5_000;
    private const COMBINED_STOP_DURATION_S = 300;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function build(
        FuelauRouteCorridor $corridor,
        array $rows,
        int $maximumOffRouteM = 75_000,
        int $maximumCandidates = 320,
        int $coverageBinM = 50_000,
        bool $combineNearOrigin = false,
        bool $combineNearDestination = false,
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
            static fn(FuelauProjectedStationCandidate $candidate): bool =>
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
            static fn(
                FuelauProjectedStationCandidate $left,
                FuelauProjectedStationCandidate $right,
            ): int => ($left->progressM <=> $right->progressM)
                ?: strcmp($left->stableId, $right->stableId),
        );

        $nodes = [new FuelauOptimizerNode(
            'origin',
            0,
            progressS: 0,
            physicalStop: true,
        )];
        $candidatesByNodeId = [];
        foreach ($selected as $candidate) {
            $nearOrigin = (
                $combineNearOrigin
                && $candidate->progressM + $candidate->accessDistanceM
                    <= self::COMBINED_STOP_DISTANCE_M
                && $candidate->progressS + $candidate->accessDurationS
                    <= self::COMBINED_STOP_DURATION_S
            );
            $nearDestination = (
                $combineNearDestination
                && ($corridor->distanceM - $candidate->progressM)
                    + $candidate->accessDistanceM
                    <= self::COMBINED_STOP_DISTANCE_M
                && ($corridor->durationS - $candidate->progressS)
                    + $candidate->accessDurationS
                    <= self::COMBINED_STOP_DURATION_S
            );
            $combinedStop = $nearOrigin || $nearDestination;
            $combinedStopReason = $nearOrigin
                ? 'origin_departure_top_up'
                : ($nearDestination ? 'planned_stop_combination' : null);
            if ($combinedStop) {
                $candidate = new FuelauProjectedStationCandidate(
                    stableId: $candidate->stableId,
                    nodeId: $candidate->nodeId,
                    label: $candidate->label,
                    progressM: $candidate->progressM,
                    progressS: $candidate->progressS,
                    offRouteM: $candidate->offRouteM,
                    accessDistanceM: $candidate->accessDistanceM,
                    accessDurationS: $candidate->accessDurationS,
                    accessEstimated: $candidate->accessEstimated,
                    priceCentsPerL: $candidate->priceCentsPerL,
                    sourceRow: [
                        ...$candidate->sourceRow,
                        'combined_endpoint_stop' => true,
                        'combined_stop_reason' => $combinedStopReason,
                    ],
                );
            }
            $nodes[] = FuelauOptimizerNode::station(
                id: $candidate->nodeId,
                progressM: $candidate->progressM,
                priceCentsPerL: $candidate->priceCentsPerL,
                label: $candidate->label,
                progressS: $candidate->progressS,
                accessDistanceM: $candidate->accessDistanceM,
                accessDurationS: $candidate->accessDurationS,
                combinedStop: $combinedStop,
                combinedStopReason: $combinedStopReason,
            );
            $candidatesByNodeId[$candidate->nodeId] = $candidate;
        }
        $nodes[] = new FuelauOptimizerNode(
            'destination',
            $corridor->distanceM,
            progressS: $corridor->durationS,
            physicalStop: true,
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
            static fn(string $part): bool => $part !== '',
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
    $utcTimezone = new DateTimeZone('UTC');
    $waTimezone = new DateTimeZone('Australia/Perth');
    foreach ($rows as &$row) {
        $rawUpdatedAt = trim((string) ($row['updated_at'] ?? ''));
        try {
            if (
                strtolower(trim((string) ($row['source'] ?? ''))) === 'wa'
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rawUpdatedAt) === 1
            ) {
                // FuelWatch publishes a WA calendar date rather than an
                // instant. Parsing that date as UTC makes today's prices look
                // several hours into the future until 08:00 AWST.
                $updatedAt = DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $rawUpdatedAt,
                    $waTimezone,
                ) ?: null;
            } else {
                $updatedAt = $rawUpdatedAt !== ''
                    ? new DateTimeImmutable($rawUpdatedAt, $utcTimezone)
                    : null;
            }
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
        int $chunkSize = 13,
        ?FuelauOptimizerVehicle $vehicle = null,
    ): array {
        if ($maximumCandidates < 1 || $maximumCandidates > 80 || $chunkSize < 1 || $chunkSize > 13) {
            throw new InvalidArgumentException('Invalid road-access measurement limits.');
        }

        $adapter = new FuelauFixedCorridorCandidateAdapter();
        $input = $adapter->build(
            $corridor,
            $candidateRows,
            maximumCandidates: $maximumCandidates,
            coverageBinM: max(
                50_000,
                (int) ceil($corridor->distanceM / $maximumCandidates),
            ),
        );
        $candidates = array_values($input->candidatesByNodeId);
        if ($vehicle !== null && $candidateRows !== []) {
            $fullInput = $adapter->build(
                $corridor,
                $candidateRows,
                maximumCandidates: 320,
                coverageBinM: max(
                    50_000,
                    (int) ceil($corridor->distanceM / 320),
                ),
            );
            $backbone = $this->rangeSafetyBackbone(
                $corridor,
                array_values($fullInput->candidatesByNodeId),
                $vehicle,
            );
            if (count($backbone) > $maximumCandidates) {
                throw new FuelauRoutePlanningUnsupportedException(
                    'Road-candidate budget is too small to preserve the physical-range station chain.',
                );
            }
            $selected = [];
            foreach ([...$backbone, ...$candidates] as $candidate) {
                $selected[$candidate->stableId] ??= $candidate;
                if (count($selected) >= $maximumCandidates) {
                    break;
                }
            }
            $candidates = array_values($selected);
            usort(
                $candidates,
                static fn(
                    FuelauProjectedStationCandidate $left,
                    FuelauProjectedStationCandidate $right,
                ): int => ($left->progressM <=> $right->progressM)
                    ?: strcmp($left->stableId, $right->stableId),
            );
        }
        $measuredRows = [];
        foreach (array_chunk($candidates, $chunkSize) as $chunk) {
            $coordinates = [];
            foreach ($chunk as $candidate) {
                $coordinates[] = $corridor->coordinateAtProgressM(max(
                    0,
                    $candidate->progressM - 5_000,
                ));
                $coordinates[] = [
                    'lat' => (float) $candidate->sourceRow['latitude'],
                    'lon' => (float) $candidate->sourceRow['longitude'],
                ];
                $coordinates[] = $corridor->coordinateAtProgressM(min(
                    $corridor->distanceM,
                    $candidate->progressM + 5_000,
                ));
            }
            $table = $tableLoader($coordinates);
            $coordinateCount = count($coordinates);
            foreach ($chunk as $index => $candidate) {
                $beforeIndex = $index * 3;
                $stationIndex = $beforeIndex + 1;
                $afterIndex = $beforeIndex + 2;
                $beforeToStationDistanceM = $this->matrixValue(
                    $table,
                    'distances',
                    $beforeIndex,
                    $stationIndex,
                    $coordinateCount,
                );
                $stationToAfterDistanceM = $this->matrixValue(
                    $table,
                    'distances',
                    $stationIndex,
                    $afterIndex,
                    $coordinateCount,
                );
                $bypassDistanceM = $this->matrixValue(
                    $table,
                    'distances',
                    $beforeIndex,
                    $afterIndex,
                    $coordinateCount,
                );
                $beforeToStationDurationS = $this->matrixValue(
                    $table,
                    'durations',
                    $beforeIndex,
                    $stationIndex,
                    $coordinateCount,
                );
                $stationToAfterDurationS = $this->matrixValue(
                    $table,
                    'durations',
                    $stationIndex,
                    $afterIndex,
                    $coordinateCount,
                );
                $bypassDurationS = $this->matrixValue(
                    $table,
                    'durations',
                    $beforeIndex,
                    $afterIndex,
                    $coordinateCount,
                );
                if (
                    $beforeToStationDistanceM === null
                    || $stationToAfterDistanceM === null
                    || $bypassDistanceM === null
                    || $beforeToStationDurationS === null
                    || $stationToAfterDurationS === null
                    || $bypassDurationS === null
                ) {
                    continue;
                }
                $insertionDistanceM = max(
                    0,
                    $beforeToStationDistanceM + $stationToAfterDistanceM - $bypassDistanceM,
                );
                $insertionDurationS = max(
                    0,
                    $beforeToStationDurationS + $stationToAfterDurationS - $bypassDurationS,
                );

                $measuredRows[] = [
                    ...$candidate->sourceRow,
                    'access_distance_m' => (int) ceil($insertionDistanceM / 2),
                    'access_duration_s' => (int) ceil($insertionDurationS / 2),
                    'road_access_status' => 'measured',
                ];
            }
        }

        return $measuredRows;
    }

    /**
     * Preserve a price-independent path that minimizes required capacity and
     * then the total auxiliary fuel across its hops. This prevents coverage
     * and price ranking from manufacturing a stationless range gap.
     *
     * @param list<FuelauProjectedStationCandidate> $candidates
     * @return list<FuelauProjectedStationCandidate>
     */
    private function rangeSafetyBackbone(
        FuelauRouteCorridor $corridor,
        array $candidates,
        FuelauOptimizerVehicle $vehicle,
    ): array {
        usort(
            $candidates,
            static fn(
                FuelauProjectedStationCandidate $left,
                FuelauProjectedStationCandidate $right,
            ): int => ($left->progressM <=> $right->progressM)
                ?: strcmp($left->stableId, $right->stableId),
        );
        $physicalCapacityBuckets = (int) floor($vehicle->tankCapacityL / 0.5);
        $startingBuckets = (int) floor($vehicle->startingFuelL / 0.5);
        $reserveBuckets = (int) ceil($vehicle->reserveL / 0.5);
        $destinationIndex = count($candidates);
        $edgeFuelUsedBuckets = static function (
            ?FuelauProjectedStationCandidate $fromCandidate,
            ?FuelauProjectedStationCandidate $toCandidate,
        ) use ($corridor, $vehicle): int {
            $fromProgressM = $fromCandidate?->progressM ?? 0;
            $toProgressM = $toCandidate?->progressM ?? $corridor->distanceM;
            $fromAccessDistanceM = $fromCandidate?->accessDistanceM ?? 0;
            $toAccessDistanceM = $toCandidate?->accessDistanceM ?? 0;

            return (int) ceil(
                (
                    (
                        ($toProgressM - $fromProgressM)
                        + $fromAccessDistanceM
                        + $toAccessDistanceM
                    ) / 100_000
                ) * $vehicle->economyLPer100km / 0.5,
            );
        };

        // First find the smallest capacity that can traverse the candidate
        // graph. Price never participates in this pass.
        $minimumCapacity = array_fill(0, $destinationIndex + 1, null);
        for ($toIndex = 0; $toIndex <= $destinationIndex; $toIndex++) {
            $toCandidate = $candidates[$toIndex] ?? null;
            for ($fromIndex = -1; $fromIndex < $toIndex; $fromIndex++) {
                $fromCandidate = $fromIndex >= 0 ? $candidates[$fromIndex] : null;
                $fromCapacity = $fromIndex >= 0
                    ? $minimumCapacity[$fromIndex]
                    : $physicalCapacityBuckets;
                if ($fromCapacity === null) {
                    continue;
                }
                $fuelUsedBuckets = $edgeFuelUsedBuckets($fromCandidate, $toCandidate);
                if ($fromIndex < 0) {
                    if ($startingBuckets - $fuelUsedBuckets < $reserveBuckets) {
                        continue;
                    }
                    $candidateCapacity = $physicalCapacityBuckets;
                } else {
                    $candidateCapacity = max(
                        $fromCapacity,
                        $fuelUsedBuckets + $reserveBuckets,
                    );
                }
                if (
                    $minimumCapacity[$toIndex] === null
                    || $candidateCapacity < $minimumCapacity[$toIndex]
                ) {
                    $minimumCapacity[$toIndex] = $candidateCapacity;
                }
            }
        }

        $effectiveCapacityBuckets = $minimumCapacity[$destinationIndex];
        if ($effectiveCapacityBuckets === null) {
            return [];
        }

        // Under that minimum capacity, prefer the path that loads the least
        // auxiliary fuel, then the one requiring the fewest safety stations.
        $best = array_fill(0, $destinationIndex + 1, null);
        for ($toIndex = 0; $toIndex <= $destinationIndex; $toIndex++) {
            $toCandidate = $candidates[$toIndex] ?? null;
            for ($fromIndex = -1; $fromIndex < $toIndex; $fromIndex++) {
                $fromCandidate = $fromIndex >= 0 ? $candidates[$fromIndex] : null;
                $fromState = $fromIndex >= 0 ? $best[$fromIndex] : [
                    'additional_buckets' => 0,
                    'stop_count' => 0,
                    'previous_index' => -1,
                ];
                if ($fromState === null) {
                    continue;
                }
                $fuelUsedBuckets = $edgeFuelUsedBuckets($fromCandidate, $toCandidate);
                if ($fromIndex < 0) {
                    if ($startingBuckets - $fuelUsedBuckets < $reserveBuckets) {
                        continue;
                    }
                    $requiredCapacityBuckets = $physicalCapacityBuckets;
                    $additionalBuckets = 0;
                } else {
                    $requiredCapacityBuckets = $fuelUsedBuckets + $reserveBuckets;
                    if ($requiredCapacityBuckets > $effectiveCapacityBuckets) {
                        continue;
                    }
                    $additionalBuckets = $fromState['additional_buckets'] + max(
                        0,
                        $requiredCapacityBuckets - $physicalCapacityBuckets,
                    );
                }
                $candidateState = [
                    'additional_buckets' => $additionalBuckets,
                    'stop_count' => $fromState['stop_count']
                        + ($toIndex < $destinationIndex ? 1 : 0),
                    'previous_index' => $fromIndex,
                ];
                $existing = $best[$toIndex];
                if (
                    $existing !== null
                    && (
                        [
                            $existing['additional_buckets'],
                            $existing['stop_count'],
                        ] <=> [
                            $candidateState['additional_buckets'],
                            $candidateState['stop_count'],
                        ]
                    ) <= 0
                ) {
                    continue;
                }
                $best[$toIndex] = $candidateState;
            }
        }

        if ($best[$destinationIndex] === null) {
            return [];
        }
        $path = [];
        $cursor = $best[$destinationIndex]['previous_index'];
        while ($cursor >= 0) {
            $path[] = $candidates[$cursor];
            $cursor = $best[$cursor]['previous_index'];
        }

        return array_reverse($path);
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
            static fn(int $total, FuelauOptimizerPurchase $purchase): int =>
                $total + $purchase->detourDistanceM,
            0,
        );
        $modeledDetourDurationS = array_reduce(
            $result->plan->purchases,
            static fn(int $total, FuelauOptimizerPurchase $purchase): int =>
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

    public function isAcceptableConservativeVariance(
        FuelauExactRouteValidation $validation,
    ): bool {
        return $validation->distanceDeltaM <= 0
            && $validation->distanceDeltaM >= -5_000
            && abs($validation->durationDeltaS) <= 180
            && $validation->fuelBucketDelta <= 0
            && $validation->fuelBucketDelta >= -1;
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
        public bool $acceptedConservativeVariance = false,
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
            + (
                $this->result->plan->fuelOnlyStopCount
                * $this->result->policy->stopCostCents()
            )
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
        $response['diagnostics']['accepted_conservative_variance'] =
            $this->acceptedConservativeVariance;
        if ($this->acceptedConservativeVariance) {
            $response['warnings'][] =
                'The validated route is slightly shorter than the conservative station-access model.';
            $response['warnings'] = array_values(array_unique($response['warnings']));
        }

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
                if ($validator->isAcceptableConservativeVariance($validation)) {
                    return new FuelauValidatedSingleCorridorPlan(
                        result: $result,
                        validation: $validation,
                        exactRoute: $exactRoute,
                        validationPassCount: $pass,
                        acceptedConservativeVariance: true,
                    );
                }
                break;
            }
            $rows = $this->reconcileSelectedAccess($rows, $result, $validation);
        }

        throw new FuelauRoutePlanValidationException(sprintf(
            'Exact selected-stop routing did not stabilize within the validation budget '
                . '(distance delta %d m, duration delta %d s, fuel bucket delta %d).',
            $validation->distanceDeltaM,
            $validation->durationDeltaS,
            $validation->fuelBucketDelta,
        ));
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
    /**
     * @param list<array{lat: float, lon: float}>|null $itineraryCoordinates
     * @param list<array<string, mixed>> $itineraryLegs
     */
    public function __construct(
        public FuelauRouteOptimizationRequest $request,
        public FuelauRouteCorridor $corridor,
        public FuelauFixedCorridorInput $input,
        public FuelauOptimizerPlan $plan,
        public FuelauOptimizerPolicy $policy,
        public ?array $itineraryCoordinates = null,
        public array $itineraryLegs = [],
        public ?float $effectiveFuelCapacityL = null,
    ) {}

    /**
     * @return list<array{lat: float, lon: float}>
     */
    public function exactRouteCoordinates(): array
    {
        if ($this->itineraryCoordinates !== null) {
            return $this->itineraryCoordinates;
        }
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
        $additionalFuelRequirements = $this->additionalFuelRequirements();
        $additionalFuelByNodeId = [];
        foreach ($additionalFuelRequirements as $requirement) {
            $additionalFuelByNodeId[$requirement['station_node_id']] = $requirement;
            $warnings[] = $requirement['message'];
            $warnings[] = $requirement['purchase_instruction'];
        }

        foreach ($this->plan->purchases as $index => $purchase) {
            $candidate = $this->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null) {
                throw new LogicException("Missing candidate metadata for {$purchase->nodeId}.");
            }
            $row = $candidate->sourceRow;
            $updatedAt = trim((string) ($row['updated_at'] ?? ''));
            if ($updatedAt !== '') {
                $priceTimestamps[] = $updatedAt;
            }
            if (in_array('sparse_corridor', $purchase->reasonCodes, true)) {
                $sequence = $index + 1;
                $warnings[] = "Stop {$sequence} exceeds normal discretionary detour limits for route safety.";
            }
            if (in_array('minimum_purchase_safety_override', $purchase->reasonCodes, true)) {
                $sequence = $index + 1;
                $warnings[] =
                    "Stop {$sequence} buys less than the preferred minimum because it is required for route safety.";
            }
            $additionalFuel = $additionalFuelByNodeId[$purchase->nodeId] ?? null;
            $itineraryLegIndex = $this->purchaseLegIndex($candidate);

            $stops[] = [
                'sequence' => $index + 1,
                'node_id' => $purchase->nodeId,
                'itinerary_leg_index' => $itineraryLegIndex,
                'itinerary_leg_number' => $itineraryLegIndex + 1,
                'classification' => $purchase->classification,
                'reason_codes' => $additionalFuel === null
                    ? $purchase->reasonCodes
                    : array_values(array_unique([
                        ...$purchase->reasonCodes,
                        'additional_fuel_required',
                    ])),
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
                'marginal_net_saving_cents' => $purchase->marginalNetSavingCents,
                'additional_fuel_l' => $additionalFuel['additional_fuel_l'] ?? 0.0,
                'additional_fuel_cost_cents' =>
                    $additionalFuel['additional_fuel_cost_cents'] ?? 0,
                'additional_fuel_next_stop' => $additionalFuel['next_stop_name'] ?? null,
                'additional_fuel_leg_index' => $additionalFuel['leg_index'] ?? null,
            ];

            $detourDistanceM += $purchase->detourDistanceM;
            $detourDurationS += $purchase->detourDurationS;
        }

        sort($priceTimestamps, SORT_STRING);
        $fuelUsedL = $this->request->fuel->startingFuelL
            + $this->plan->fuelPurchasedL
            - $this->plan->endingFuelL;
        $corridorTimeCostCents = (int) ceil(
            ($this->corridor->durationS * $this->policy->driverTimeValueCentsPerHour) / 3_600,
        );
        $additionalFuelL = array_sum(array_column(
            $additionalFuelRequirements,
            'additional_fuel_l',
        ));
        $additionalFuelCostCents = array_sum(array_column(
            $additionalFuelRequirements,
            'additional_fuel_cost_cents',
        ));
        $legFuelTotals = $this->legFuelTotals();
        foreach ($additionalFuelRequirements as &$requirement) {
            $requirement['leg_fuel_purchased_l'] =
                $legFuelTotals[$requirement['leg_index']]['fuel_purchased_l'] ?? 0.0;
            $requirement['leg_fuel_purchase_cost_cents'] =
                $legFuelTotals[$requirement['leg_index']]['fuel_purchase_cost_cents'] ?? 0;
        }
        unset($requirement);

        $response = [
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
                'effective_fuel_capacity_l' =>
                    $this->effectiveFuelCapacityL ?? $this->request->fuel->tankCapacityL,
                'additional_required_fuel_l' => round($additionalFuelL, 1),
                'additional_fuel_cost_cents' => $additionalFuelCostCents,
                'required_stop_count' => $this->plan->requiredStopCount,
                'discretionary_stop_count' => $this->plan->discretionaryStopCount,
                'combined_stop_count' => $this->plan->combinedStopCount,
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
            'additional_fuel_requirements' => $additionalFuelRequirements,
            'alternatives' => [],
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => [
                'candidate_count' => $this->input->eligibleCandidateCount,
                'network_shortlist_count' => $this->input->selectedCandidateCount,
            ],
        ];
        if ($this->itineraryLegs !== []) {
            $itineraryLegs = array_map(
                static function (array $leg) use (
                    $additionalFuelRequirements,
                    $legFuelTotals,
                ): array {
                    $legIndex = (int) ($leg['index'] ?? 0);
                    $requirements = array_values(array_filter(
                        $additionalFuelRequirements,
                        static fn(array $requirement): bool =>
                            $requirement['leg_index'] === $legIndex,
                    ));
                    $totals = $legFuelTotals[$legIndex] ?? [
                        'fuel_purchased_l' => 0.0,
                        'fuel_purchase_cost_cents' => 0,
                    ];

                    return [
                        ...$leg,
                        ...$totals,
                        'requires_additional_fuel' => $requirements !== [],
                        'additional_fuel_requirements' => $requirements,
                        'additional_required_fuel_l' => round((float) array_sum(
                            array_column($requirements, 'additional_fuel_l'),
                        ), 1),
                        'additional_fuel_cost_cents' => (int) array_sum(
                            array_column($requirements, 'additional_fuel_cost_cents'),
                        ),
                    ];
                },
                $this->itineraryLegs,
            );
            $response['itinerary'] = [
                'return_mode' => $this->request->returnMode,
                'leg_count' => count($itineraryLegs),
                'legs' => $itineraryLegs,
            ];
        }

        return $response;
    }

    /**
     * @return list<array<string, int|float|string>>
     */
    private function additionalFuelRequirements(): array
    {
        $physicalCapacityL = $this->request->fuel->tankCapacityL;
        if (($this->effectiveFuelCapacityL ?? $physicalCapacityL) <= $physicalCapacityL) {
            return [];
        }

        $requirements = [];
        foreach ($this->plan->purchases as $index => $purchase) {
            $overflowOnArrivalL = max(0.0, $purchase->arrivalFuelL - $physicalCapacityL);
            $overflowOnDepartureL = max(0.0, $purchase->departureFuelL - $physicalCapacityL);
            $additionalFuelL = round(
                max(0.0, $overflowOnDepartureL - $overflowOnArrivalL),
                1,
            );
            if ($additionalFuelL <= 0) {
                continue;
            }

            $candidate = $this->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null) {
                throw new LogicException("Missing candidate metadata for {$purchase->nodeId}.");
            }
            $legIndex = $this->purchaseLegIndex($candidate);
            $nextPurchase = $this->plan->purchases[$index + 1] ?? null;
            $nextStopName = trim((string) ($nextPurchase?->label ?? ''));
            if ($nextStopName === '') {
                $nextStopName = $this->nextItineraryStopName($legIndex);
            }
            $stationName = trim((string) ($candidate->sourceRow['station_name'] ?? ''));
            if ($stationName === '') {
                $stationName = trim($purchase->label) !== ''
                    ? trim($purchase->label)
                    : 'the preceding station';
            }
            $litresText = number_format($additionalFuelL, 1, '.', '');
            $legNumber = $legIndex + 1;

            $requirements[] = [
                'leg_index' => $legIndex,
                'leg_number' => $legNumber,
                'station_node_id' => $purchase->nodeId,
                'station_name' => $stationName,
                'next_stop_name' => $nextStopName,
                'additional_fuel_l' => $additionalFuelL,
                'additional_fuel_cost_cents' => (int) ceil(
                    $additionalFuelL * $purchase->priceCentsPerL,
                ),
                'price_cents_per_l' => $purchase->priceCentsPerL,
                'message' => "Leg {$legNumber} requires additional {$litresText} litres of fuel to reach next stop",
                'purchase_instruction' => "Purchase additional {$litresText} litres of fuel at {$stationName} in order to reach next stop at {$nextStopName}.",
            ];
        }

        return $requirements;
    }

    private function nextItineraryStopName(int $purchaseLegIndex): string
    {
        foreach ($this->itineraryLegs as $leg) {
            $legIndex = (int) ($leg['index'] ?? 0);
            if ($legIndex < $purchaseLegIndex) {
                continue;
            }
            $label = trim((string) ($leg['target']['label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        $destination = $this->request->destinations[count($this->request->destinations) - 1];
        $label = trim($destination->label);

        return $label !== '' ? $label : 'the destination';
    }

    /**
     * @return array<int, array{fuel_purchased_l: float, fuel_purchase_cost_cents: int}>
     */
    private function legFuelTotals(): array
    {
        $totals = [];
        foreach ($this->plan->purchases as $purchase) {
            $candidate = $this->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            $legIndex = $candidate === null ? 0 : $this->purchaseLegIndex($candidate);
            $totals[$legIndex] ??= [
                'fuel_purchased_l' => 0.0,
                'fuel_purchase_cost_cents' => 0,
            ];
            $totals[$legIndex]['fuel_purchased_l'] += $purchase->purchaseL;
            $totals[$legIndex]['fuel_purchase_cost_cents'] += $purchase->purchaseCostCents;
        }

        return $totals;
    }

    private function purchaseLegIndex(FuelauProjectedStationCandidate $candidate): int
    {
        $sourceLegIndex = is_int($candidate->sourceRow['itinerary_leg_index'] ?? null)
            ? $candidate->sourceRow['itinerary_leg_index']
            : 0;
        $combinedStopIndex = $candidate->sourceRow['combined_itinerary_stop_index'] ?? null;
        if (
            is_int($combinedStopIndex)
            && $combinedStopIndex > $sourceLegIndex
            && $combinedStopIndex < max(1, count($this->itineraryLegs))
        ) {
            // A purchase combined with the end of one itinerary leg is the
            // departure purchase for the following leg.
            return $combinedStopIndex;
        }

        return $sourceLegIndex;
    }
}

function fuelauOptimizerPolicyForRequest(
    FuelauRouteOptimizationRequest $request,
): FuelauOptimizerPolicy {
    $preferences = $request->preferences;

    return new FuelauOptimizerPolicy(
        mode: $preferences->mode,
        maximumFuelOnlyStops: $preferences->maximumFuelOnlyStops,
        minimumDiscretionaryPurchaseL: $preferences->minimumDiscretionaryPurchaseL,
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
}

/**
 * @param list<array<string, mixed>> $candidateRows
 * @return list<array<string, mixed>>
 */
function fuelauEligibleOptimizerCandidateRows(
    array $candidateRows,
    FuelauOptimizerPolicy $policy,
): array {
    return array_values(array_filter(
        $candidateRows,
        static function (array $row) use ($policy): bool {
            if (($row['price_status'] ?? null) !== 'fresh') {
                return false;
            }
            $accessDistanceM = $row['access_distance_m'] ?? null;
            $accessDurationS = $row['access_duration_s'] ?? null;

            return !is_numeric((string) $accessDistanceM)
                || !is_numeric((string) $accessDurationS)
                || (
                    ((float) $accessDistanceM * 2) <= $policy->maximumSafetyDetourM
                    && ((float) $accessDurationS * 2) <= $policy->maximumSafetyDetourS
                );
        },
    ));
}

final readonly class FuelauCapacityAdjustedOptimizerPlan
{
    public function __construct(
        public FuelauOptimizerPlan $plan,
        public float $effectiveFuelCapacityL,
    ) {}
}

/**
 * Falls back to the smallest auxiliary-fuel capacity that can bridge a gap.
 * Ordinary plans always retain the configured physical tank capacity.
 */
final class FuelauAdditionalFuelOptimizer
{
    private const BUCKET_L = 0.5;
    private const CAPACITY_FAILURE_MESSAGE =
        'No station sequence can reach the destination while maintaining reserve.';
    private const STOP_LIMIT_FAILURE_MESSAGE =
        'The configured stop limit is below the minimum feasible stop count.';

    /**
     * @param list<FuelauOptimizerNode> $nodes
     */
    public function optimizePractical(
        array $nodes,
        FuelauOptimizerVehicle $vehicle,
        FuelauOptimizerPolicy $policy,
    ): FuelauCapacityAdjustedOptimizerPlan {
        $optimizer = new FuelauFuelStateOptimizer();
        try {
            return new FuelauCapacityAdjustedOptimizerPlan(
                $optimizer->optimizePractical($nodes, $vehicle, $policy),
                $vehicle->tankCapacityL,
            );
        } catch (FuelauRouteInfeasibleException $exception) {
            if ($exception->getMessage() !== self::CAPACITY_FAILURE_MESSAGE) {
                throw $exception;
            }
            $capacityFailure = $exception;
        }

        $nodes = array_values($nodes);
        $fallback = $this->minimumCapacityStationPath(
            $nodes,
            $vehicle,
        );
        if ($fallback === null) {
            throw $capacityFailure;
        }

        try {
            $plan = $optimizer->optimizePractical(
                $fallback['nodes'],
                new FuelauOptimizerVehicle(
                    tankCapacityL: $fallback['capacity_buckets'] * self::BUCKET_L,
                    startingFuelL: $vehicle->startingFuelL,
                    reserveL: $vehicle->reserveL,
                    economyLPer100km: $vehicle->economyLPer100km,
                ),
                $policy,
            );

            return new FuelauCapacityAdjustedOptimizerPlan(
                $plan,
                $fallback['capacity_buckets'] * self::BUCKET_L,
            );
        } catch (FuelauRouteInfeasibleException $exception) {
            if ($exception->getMessage() === self::STOP_LIMIT_FAILURE_MESSAGE) {
                throw $exception;
            }
            throw $capacityFailure;
        }
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     * @return array{nodes: list<FuelauOptimizerNode>, capacity_buckets: int}|null
     */
    private function minimumCapacityStationPath(
        array $nodes,
        FuelauOptimizerVehicle $vehicle,
    ): ?array {
        if (count($nodes) < 2) {
            return null;
        }

        $lastIndex = count($nodes) - 1;
        $physicalCapacityBuckets = (int) floor(
            $vehicle->tankCapacityL / self::BUCKET_L,
        );
        $startingBuckets = (int) floor($vehicle->startingFuelL / self::BUCKET_L);
        $reserveBuckets = (int) ceil($vehicle->reserveL / self::BUCKET_L);

        // Auxiliary capacity is a physical reachability fallback. Consider
        // every eligible station regardless of price and do not manufacture a
        // larger fuel requirement merely to satisfy a preferred stop limit.
        /** @var array<int, array<int, array<string, int>>> $states */
        $states = array_fill(0, count($nodes), []);
        $states[0][0] = [
            'capacity_buckets' => $physicalCapacityBuckets,
            'additional_buckets' => 0,
            'stop_count' => 0,
            'previous_index' => -1,
            'previous_fuel_only_stops' => 0,
        ];

        for ($fromIndex = 0; $fromIndex < $lastIndex; $fromIndex++) {
            if ($fromIndex > 0 && $nodes[$fromIndex]->priceTenthsCentsPerL === null) {
                continue;
            }
            foreach ($states[$fromIndex] as $fuelOnlyStops => $state) {
                for ($toIndex = $fromIndex + 1; $toIndex <= $lastIndex; $toIndex++) {
                    if (
                        $toIndex < $lastIndex
                        && $nodes[$toIndex]->priceTenthsCentsPerL === null
                    ) {
                        continue;
                    }

                    $fuelUsedBuckets = $this->fuelUsedBuckets(
                        ($nodes[$toIndex]->progressM - $nodes[$fromIndex]->progressM)
                            + $nodes[$fromIndex]->accessDistanceM
                            + $nodes[$toIndex]->accessDistanceM,
                        $vehicle->economyLPer100km,
                    );
                    if ($fromIndex === 0) {
                        if ($startingBuckets - $fuelUsedBuckets < $reserveBuckets) {
                            continue;
                        }
                        $capacityBuckets = $physicalCapacityBuckets;
                        $additionalBuckets = 0;
                    } else {
                        $requiredCapacityBuckets = $fuelUsedBuckets + $reserveBuckets;
                        $capacityBuckets = max(
                            $state['capacity_buckets'],
                            $requiredCapacityBuckets,
                        );
                        $additionalBuckets = $state['additional_buckets'] + max(
                            0,
                            $requiredCapacityBuckets - $physicalCapacityBuckets,
                        );
                    }

                    $nextFuelOnlyStops = $fuelOnlyStops;
                    $nextStopCount = $state['stop_count'];
                    if ($toIndex < $lastIndex) {
                        $nextStopCount++;
                        if (!$nodes[$toIndex]->combinedStop) {
                            $nextFuelOnlyStops++;
                        }
                    }
                    $existing = $states[$toIndex][$nextFuelOnlyStops] ?? null;
                    if (
                        $existing !== null
                        && (
                            [
                                $existing['capacity_buckets'],
                                $existing['additional_buckets'],
                                $existing['stop_count'],
                            ] <=> [
                                $capacityBuckets,
                                $additionalBuckets,
                                $nextStopCount,
                            ]
                        ) <= 0
                    ) {
                        continue;
                    }
                    $states[$toIndex][$nextFuelOnlyStops] = [
                        'capacity_buckets' => $capacityBuckets,
                        'additional_buckets' => $additionalBuckets,
                        'stop_count' => $nextStopCount,
                        'previous_index' => $fromIndex,
                        'previous_fuel_only_stops' => $fuelOnlyStops,
                    ];
                }
            }
        }

        if ($states[$lastIndex] === []) {
            return null;
        }

        $destinationFuelOnlyStops = array_key_first($states[$lastIndex]);
        foreach ($states[$lastIndex] as $fuelOnlyStops => $state) {
            $best = $states[$lastIndex][$destinationFuelOnlyStops];
            if (
                (
                    [
                        $state['capacity_buckets'],
                        $state['additional_buckets'],
                        $fuelOnlyStops,
                        $state['stop_count'],
                    ]
                    <=> [
                        $best['capacity_buckets'],
                        $best['additional_buckets'],
                        $destinationFuelOnlyStops,
                        $best['stop_count'],
                    ]
                ) < 0
            ) {
                $destinationFuelOnlyStops = $fuelOnlyStops;
            }
        }

        $pathIndexes = [];
        $nodeIndex = $lastIndex;
        $fuelOnlyStops = $destinationFuelOnlyStops;
        while ($nodeIndex >= 0) {
            $pathIndexes[] = $nodeIndex;
            if ($nodeIndex === 0) {
                break;
            }
            $state = $states[$nodeIndex][$fuelOnlyStops];
            $nodeIndex = $state['previous_index'];
            $fuelOnlyStops = $state['previous_fuel_only_stops'];
        }
        $pathIndexes = array_reverse($pathIndexes);
        $pathNodes = array_values(array_map(
            static fn(int $index): FuelauOptimizerNode => $nodes[$index],
            $pathIndexes,
        ));
        $constrainedPathNodes = [];
        foreach ($pathNodes as $pathIndex => $node) {
            $nextNode = $pathNodes[$pathIndex + 1] ?? null;
            $maximumDepartureFuelL = null;
            if ($nextNode !== null && $pathIndex > 0) {
                $fuelUsedBuckets = $this->fuelUsedBuckets(
                    ($nextNode->progressM - $node->progressM)
                        + $node->accessDistanceM
                        + $nextNode->accessDistanceM,
                    $vehicle->economyLPer100km,
                );
                $maximumDepartureFuelL = max(
                    $physicalCapacityBuckets,
                    $fuelUsedBuckets + $reserveBuckets,
                ) * self::BUCKET_L;
            }
            $constrainedPathNodes[] = new FuelauOptimizerNode(
                id: $node->id,
                progressM: $node->progressM,
                priceTenthsCentsPerL: $node->priceTenthsCentsPerL,
                label: $node->label,
                progressS: $node->progressS,
                accessDistanceM: $node->accessDistanceM,
                accessDurationS: $node->accessDurationS,
                physicalStop: $node->physicalStop,
                combinedStop: $node->combinedStop,
                combinedStopReason: $node->combinedStopReason,
                maximumDepartureFuelL: $maximumDepartureFuelL,
            );
        }

        return [
            'nodes' => $constrainedPathNodes,
            'capacity_buckets' =>
                $states[$lastIndex][$destinationFuelOnlyStops]['capacity_buckets'],
        ];
    }

    private function fuelUsedBuckets(int $distanceM, float $economyLPer100km): int
    {
        return (int) ceil(
            (($distanceM / 100_000) * $economyLPer100km) / self::BUCKET_L,
        );
    }
}

final class FuelauCompleteItineraryPlanner
{
    /**
     * @param list<FuelauPreparedItineraryLeg> $legs
     */
    public function plan(
        FuelauRouteOptimizationRequest $request,
        array $legs,
    ): FuelauSingleCorridorOptimizationResult {
        $policy = fuelauOptimizerPolicyForRequest($request);
        $itinerary = (new FuelauCompleteItineraryAssembler())->build(
            $request,
            $legs,
            $policy,
        );
        $adjustedPlan = (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            $itinerary->input->nodes,
            new FuelauOptimizerVehicle(
                tankCapacityL: $request->fuel->tankCapacityL,
                startingFuelL: $request->fuel->startingFuelL,
                reserveL: $request->fuel->reserveL,
                economyLPer100km: $request->fuel->economyLPer100km,
            ),
            $policy,
        );
        $plan = $adjustedPlan->plan;
        foreach ($plan->purchases as $purchase) {
            $candidate = $itinerary->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null || $candidate->accessEstimated) {
                throw new FuelauRoutePlanValidationException(
                    'Selected station access must be validated with road-network distance and duration.',
                );
            }
        }
        $legSummaries = array_map(
            static fn(array $leg): array => [
                'index' => $leg['index'],
                'distance_m' => $leg['end_m'] - $leg['start_m'],
                'duration_s' => $leg['end_s'] - $leg['start_s'],
                'target' => $leg['target']->toArray(),
            ],
            $itinerary->legSummaries,
        );

        return new FuelauSingleCorridorOptimizationResult(
            request: $request,
            corridor: $itinerary->corridor,
            input: $itinerary->input,
            plan: $plan,
            policy: $policy,
            itineraryCoordinates: $itinerary->exactRouteCoordinates($plan),
            itineraryLegs: $legSummaries,
            effectiveFuelCapacityL: $adjustedPlan->effectiveFuelCapacityL,
        );
    }
}

final class FuelauCompleteItineraryValidationCoordinator
{
    /**
     * @param list<FuelauPreparedItineraryLeg> $legs
     * @param callable(list<array{lat: float, lon: float}>): array<string, mixed> $exactRouteLoader
     */
    public function planAndValidate(
        FuelauRouteOptimizationRequest $request,
        array $legs,
        callable $exactRouteLoader,
        int $maximumValidationPasses = 2,
    ): FuelauValidatedSingleCorridorPlan {
        if ($maximumValidationPasses < 1 || $maximumValidationPasses > 2) {
            throw new InvalidArgumentException('Exact route validation passes must be between 1 and 2.');
        }

        $planner = new FuelauCompleteItineraryPlanner();
        $validator = new FuelauExactRouteValidator();
        $preparedLegs = array_values($legs);
        for ($pass = 1; $pass <= $maximumValidationPasses; $pass++) {
            $result = $planner->plan($request, $preparedLegs);
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
                if ($validator->isAcceptableConservativeVariance($validation)) {
                    return new FuelauValidatedSingleCorridorPlan(
                        result: $result,
                        validation: $validation,
                        exactRoute: $exactRoute,
                        validationPassCount: $pass,
                        acceptedConservativeVariance: true,
                    );
                }
                break;
            }
            $preparedLegs = $this->reconcileSelectedAccess(
                $preparedLegs,
                $result,
                $validation,
            );
        }

        throw new FuelauRoutePlanValidationException(sprintf(
            'Exact selected-stop itinerary did not stabilize within the validation budget '
                . '(distance delta %d m, duration delta %d s, fuel bucket delta %d).',
            $validation->distanceDeltaM,
            $validation->durationDeltaS,
            $validation->fuelBucketDelta,
        ));
    }

    /**
     * @param list<FuelauPreparedItineraryLeg> $legs
     * @return list<FuelauPreparedItineraryLeg>
     */
    private function reconcileSelectedAccess(
        array $legs,
        FuelauSingleCorridorOptimizationResult $result,
        FuelauExactRouteValidation $validation,
    ): array {
        $selectedVisits = [];
        $currentAccessDistanceM = 0;
        $currentAccessDurationS = 0;
        foreach ($result->plan->purchases as $purchase) {
            $candidate = $result->input->candidatesByNodeId[$purchase->nodeId] ?? null;
            if ($candidate === null) {
                continue;
            }
            $legIndex = $candidate->sourceRow['itinerary_leg_index'] ?? null;
            if (!is_int($legIndex)) {
                continue;
            }
            $selectedVisits["{$legIndex}:{$candidate->stableId}"] = true;
            $currentAccessDistanceM += $candidate->accessDistanceM;
            $currentAccessDurationS += $candidate->accessDurationS;
        }
        if ($selectedVisits === []) {
            return $legs;
        }

        $targetAccessDistanceM = max(
            0,
            (int) ceil(($validation->exactDistanceM - $result->corridor->distanceM) / 2),
        );
        $targetAccessDurationS = max(
            0,
            (int) ceil(($validation->exactDurationS - $result->corridor->durationS) / 2),
        );
        $selectedCount = count($selectedVisits);
        $reconciled = [];
        foreach ($legs as $leg) {
            $rows = $leg->candidateRows;
            foreach ($rows as &$row) {
                $visitKey = "{$leg->index}:{$this->stableRowId($row)}";
                if (!isset($selectedVisits[$visitKey])) {
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
            $reconciled[] = new FuelauPreparedItineraryLeg(
                index: $leg->index,
                corridor: $leg->corridor,
                target: $leg->target,
                candidateRows: array_values($rows),
            );
        }

        return $reconciled;
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

        $policy = fuelauOptimizerPolicyForRequest($request);
        $freshRows = fuelauEligibleOptimizerCandidateRows(
            $candidateRows,
            $policy,
        );
        $input = (new FuelauFixedCorridorCandidateAdapter())->build(
            $corridor,
            $freshRows,
            combineNearOrigin: true,
            combineNearDestination: $request->destinations[0]->physicalStop,
        );
        $adjustedPlan = (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            $input->nodes,
            new FuelauOptimizerVehicle(
                tankCapacityL: $request->fuel->tankCapacityL,
                startingFuelL: $request->fuel->startingFuelL,
                reserveL: $request->fuel->reserveL,
                economyLPer100km: $request->fuel->economyLPer100km,
            ),
            $policy,
        );
        $plan = $adjustedPlan->plan;

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
            effectiveFuelCapacityL: $adjustedPlan->effectiveFuelCapacityL,
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
        $this->tableLoader = $tableLoader ?? static fn(array $coordinates): array =>
            fuelauOsrmTable($coordinates);
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
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
        try {
            $noStopResult = (new FuelauSingleCorridorPlanner())->plan(
                $request,
                $corridor,
                [],
            );
            $noStopValidation = (new FuelauExactRouteValidator())->validate(
                $noStopResult,
                $baselineRoute,
            );
            if (!$noStopValidation->requiresReoptimization) {
                $response = (new FuelauValidatedSingleCorridorPlan(
                    result: $noStopResult,
                    validation: $noStopValidation,
                    exactRoute: $baselineRoute,
                    validationPassCount: 1,
                ))->toResponseArray();
                $response['diagnostics']['raw_candidate_count'] = 0;
                $response['diagnostics']['fresh_candidate_count'] = 0;
                $response['diagnostics']['osrm_route_request_count'] = $routeRequestCount;
                $response['diagnostics']['osrm_table_request_count'] = 0;

                return $response;
            }
        } catch (FuelauRouteInfeasibleException) {
            // Candidate work is only needed when starting fuel cannot finish
            // the baseline route with the requested terminal reserve.
        }
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
            static fn(array $row): bool => ($row['price_status'] ?? null) === 'fresh',
        ));
        $roadCandidateLimit = min(
            64,
            max(24, (int) ceil($corridor->distanceM / 50_000)),
        );
        $measuredRows = (new FuelauCandidateRoadAccessMeasurer())->measure(
            $corridor,
            $freshRows,
            function (array $coordinates) use (&$tableRequestCount): array {
                $tableRequestCount++;

                return ($this->tableLoader)($coordinates);
            },
            maximumCandidates: $roadCandidateLimit,
            vehicle: new FuelauOptimizerVehicle(
                tankCapacityL: $request->fuel->tankCapacityL,
                startingFuelL: $request->fuel->startingFuelL,
                reserveL: $request->fuel->reserveL,
                economyLPer100km: $request->fuel->economyLPer100km,
            ),
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

final class FuelauItineraryRoadCandidateBudget
{
    /**
     * @param list<FuelauPreparedItineraryLeg> $legs
     * @return list<int>
     */
    public function allocate(
        array $legs,
        int $globalLimit = 64,
        int $minimumPerLeg = 3,
    ): array {
        $legCount = count($legs);
        $minimumTotal = $legCount * $minimumPerLeg;
        if (
            $legCount === 0
            || $globalLimit < 1
            || $globalLimit > 64
            || $minimumPerLeg < 1
            || $minimumTotal > $globalLimit
        ) {
            throw new InvalidArgumentException('Itinerary exceeds the road-candidate budget.');
        }

        $desired = array_map(
            static fn(FuelauPreparedItineraryLeg $leg): int => min(
                80,
                max(
                    $minimumPerLeg,
                    (int) ceil($leg->corridor->distanceM / 50_000),
                ),
            ),
            $legs,
        );
        if (array_sum($desired) <= $globalLimit) {
            return array_values($desired);
        }

        $limits = array_fill(0, $legCount, $minimumPerLeg);
        $remaining = $globalLimit - $minimumTotal;
        $weights = array_map(
            static fn(int $value): int => $value - $minimumPerLeg,
            $desired,
        );
        $weightTotal = array_sum($weights);
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $share = $weightTotal > 0 ? ($remaining * $weight) / $weightTotal : 0.0;
            $wholeShare = min($weight, (int) floor($share));
            $limits[$index] += $wholeShare;
            $remainders[$index] = $share - $wholeShare;
        }

        $unassigned = $globalLimit - array_sum($limits);
        while ($unassigned > 0) {
            $eligible = array_filter(
                array_keys($limits),
                static fn(int $index): bool => $limits[$index] < $desired[$index],
            );
            if ($eligible === []) {
                break;
            }
            usort(
                $eligible,
                static fn(int $left, int $right): int =>
                    ($remainders[$right] <=> $remainders[$left])
                    ?: ($weights[$right] <=> $weights[$left])
                    ?: ($left <=> $right),
            );
            foreach ($eligible as $index) {
                $limits[$index]++;
                $unassigned--;
                if ($unassigned === 0) {
                    break;
                }
            }
        }

        return array_values($limits);
    }
}

final class FuelauLiveCompleteItineraryPlanner
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
        $this->tableLoader = $tableLoader ?? static fn(array $coordinates): array =>
            fuelauOsrmTable($coordinates);
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(FuelauRouteOptimizationRequest $request): array
    {
        $locations = $request->itineraryLocations();
        $legCount = count($locations) - 1;
        if (
            $legCount < 1
            || $legCount > FuelauRouteOptimizationRequest::MAX_ITINERARY_LEGS
        ) {
            throw new FuelauRoutePlanningUnsupportedException(
                sprintf(
                    'Expanded itineraries must contain between 1 and %d route legs.',
                    FuelauRouteOptimizationRequest::MAX_ITINERARY_LEGS,
                ),
            );
        }

        $routeRequestCount = 0;
        $tableRequestCount = 0;
        $loadRoute = function (array $coordinates) use (&$routeRequestCount): array {
            $routeRequestCount++;

            return ($this->routeLoader)($coordinates);
        };

        $preparedLegs = [];
        foreach (array_slice($locations, 1) as $index => $target) {
            $start = $locations[$index];
            $route = $loadRoute([
                ['lat' => $start->latitude, 'lon' => $start->longitude],
                ['lat' => $target->latitude, 'lon' => $target->longitude],
            ]);
            $preparedLegs[] = new FuelauPreparedItineraryLeg(
                index: $index,
                corridor: FuelauRouteCorridor::fromOsrmRoute($route),
                target: $target,
                candidateRows: [],
            );
        }
        $baselineRoute = $this->combinedBaselineRoute($preparedLegs);

        try {
            $noStopResult = (new FuelauCompleteItineraryPlanner())->plan(
                $request,
                $preparedLegs,
            );
            $noStopValidation = (new FuelauExactRouteValidator())->validate(
                $noStopResult,
                $baselineRoute,
            );
            if (!$noStopValidation->requiresReoptimization) {
                $response = (new FuelauValidatedSingleCorridorPlan(
                    result: $noStopResult,
                    validation: $noStopValidation,
                    exactRoute: $baselineRoute,
                    validationPassCount: 1,
                ))->toResponseArray();
                $response['diagnostics']['raw_candidate_count'] = 0;
                $response['diagnostics']['fresh_candidate_count'] = 0;
                $response['diagnostics']['osrm_route_request_count'] = $routeRequestCount;
                $response['diagnostics']['osrm_table_request_count'] = 0;
                $response['diagnostics']['itinerary_leg_count'] = $legCount;

                return $response;
            }
        } catch (FuelauRouteInfeasibleException) {
            // Candidate work is only needed when starting fuel cannot finish
            // the complete itinerary with the requested terminal reserve.
        }

        $rawCandidateCount = 0;
        $freshCandidateCount = 0;
        $roadCandidateLimits = (new FuelauItineraryRoadCandidateBudget())->allocate(
            $preparedLegs,
        );
        $measuredLegs = [];
        $asOf = ($this->clock)();
        foreach ($preparedLegs as $leg) {
            $candidateRows = ($this->candidateLoader)(
                $leg->corridor->candidateLookupPoints(),
                $request->fuel->type,
            );
            if (!is_array($candidateRows)) {
                throw new RuntimeException('Route candidate loader returned an invalid result.');
            }
            $candidateRows = array_values($candidateRows);
            $rawCandidateCount += count($candidateRows);
            $classifiedRows = fuelauClassifyRouteCandidatePriceRows(
                $candidateRows,
                $asOf,
            );
            $freshRows = array_values(array_filter(
                $classifiedRows,
                static fn(array $row): bool => ($row['price_status'] ?? null) === 'fresh',
            ));
            $freshCandidateCount += count($freshRows);
            $roadCandidateLimit = $roadCandidateLimits[$leg->index];
            $measuredRows = (new FuelauCandidateRoadAccessMeasurer())->measure(
                $leg->corridor,
                $freshRows,
                function (array $coordinates) use (&$tableRequestCount): array {
                    $tableRequestCount++;

                    return ($this->tableLoader)($coordinates);
                },
                maximumCandidates: $roadCandidateLimit,
                vehicle: new FuelauOptimizerVehicle(
                    tankCapacityL: $request->fuel->tankCapacityL,
                    startingFuelL: $leg->index === 0
                        ? $request->fuel->startingFuelL
                        : $request->fuel->tankCapacityL,
                    reserveL: $request->fuel->reserveL,
                    economyLPer100km: $request->fuel->economyLPer100km,
                ),
            );
            $measuredLegs[] = new FuelauPreparedItineraryLeg(
                index: $leg->index,
                corridor: $leg->corridor,
                target: $leg->target,
                candidateRows: $measuredRows,
            );
        }

        $validated = (new FuelauCompleteItineraryValidationCoordinator())->planAndValidate(
            $request,
            $measuredLegs,
            $loadRoute,
        );
        $response = $validated->toResponseArray();
        $response['diagnostics']['raw_candidate_count'] = $rawCandidateCount;
        $response['diagnostics']['fresh_candidate_count'] = $freshCandidateCount;
        $response['diagnostics']['osrm_route_request_count'] = $routeRequestCount;
        $response['diagnostics']['osrm_table_request_count'] = $tableRequestCount;
        $response['diagnostics']['itinerary_leg_count'] = $legCount;

        return $response;
    }

    /**
     * @param list<FuelauPreparedItineraryLeg> $legs
     * @return array<string, mixed>
     */
    private function combinedBaselineRoute(array $legs): array
    {
        $distanceM = 0;
        $durationS = 0;
        $coordinates = [];
        foreach ($legs as $leg) {
            $distanceM += $leg->corridor->distanceM;
            $durationS += $leg->corridor->durationS;
            foreach ($leg->corridor->geometryPoints() as $index => $point) {
                if ($coordinates !== [] && $index === 0) {
                    continue;
                }
                $coordinates[] = [$point['lon'], $point['lat']];
            }
        }

        return [
            'distance' => $distanceM,
            'duration' => $durationS,
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coordinates,
            ],
        ];
    }
}

final class FuelauAlternativeCorridorSelector
{
    /**
     * @template T of array{rank: int, response: array<string, mixed>}
     * @param list<T> $candidates
     * @return T
     */
    public function select(array $candidates): array
    {
        if ($candidates === []) {
            throw new InvalidArgumentException('At least one feasible corridor is required.');
        }
        usort($candidates, [$this, 'compare']);

        return $candidates[0];
    }

    /**
     * @param array{rank: int, response: array<string, mixed>} $left
     * @param array{rank: int, response: array<string, mixed>} $right
     */
    private function compare(array $left, array $right): int
    {
        $leftSummary = $left['response']['summary'] ?? [];
        $rightSummary = $right['response']['summary'] ?? [];
        $leftCost = (int) (
            $leftSummary['generalized_cost_cents'] ?? PHP_INT_MAX
        );
        $rightCost = (int) (
            $rightSummary['generalized_cost_cents'] ?? PHP_INT_MAX
        );
        $leftFuelOnlyStops = (int) ($leftSummary['required_stop_count'] ?? 0)
            + (int) ($leftSummary['discretionary_stop_count'] ?? 0);
        $rightFuelOnlyStops = (int) ($rightSummary['required_stop_count'] ?? 0)
            + (int) ($rightSummary['discretionary_stop_count'] ?? 0);
        if (abs($leftCost - $rightCost) <= 500) {
            return ($leftFuelOnlyStops <=> $rightFuelOnlyStops)
                ?: ((int) ($leftSummary['route_duration_s'] ?? PHP_INT_MAX)
                    <=> (int) ($rightSummary['route_duration_s'] ?? PHP_INT_MAX))
                ?: ((int) ($leftSummary['fuel_purchase_cost_cents'] ?? PHP_INT_MAX)
                    <=> (int) ($rightSummary['fuel_purchase_cost_cents'] ?? PHP_INT_MAX))
                ?: ($left['rank'] <=> $right['rank']);
        }

        return ($leftCost <=> $rightCost)
            ?: ($left['rank'] <=> $right['rank']);
    }
}

final class FuelauLiveAlternativeCorridorPlanner
{
    private Closure $routeLoader;
    private Closure $alternativeRouteLoader;
    private ?Closure $displayRouteLoader;
    private Closure $candidateLoader;
    private Closure $tableLoader;
    private Closure $clock;

    public function __construct(
        ?Closure $routeLoader = null,
        ?Closure $candidateLoader = null,
        ?Closure $tableLoader = null,
        ?Closure $clock = null,
        ?Closure $alternativeRouteLoader = null,
        ?Closure $displayRouteLoader = null,
    ) {
        $this->routeLoader = $routeLoader ?? static function (array $coordinates): array {
            $payload = fuelauRoutePlan($coordinates, false);
            $route = $payload['routes'][0] ?? null;
            if (($payload['code'] ?? null) !== 'Ok' || !is_array($route)) {
                throw new FuelauUpstreamException('OSRM did not return a usable route.');
            }

            return $route;
        };
        if ($alternativeRouteLoader !== null) {
            $this->alternativeRouteLoader = $alternativeRouteLoader;
        } elseif ($routeLoader !== null) {
            $this->alternativeRouteLoader = static fn(array $coordinates): array =>
                [$routeLoader($coordinates)];
        } else {
            $this->alternativeRouteLoader = static function (array $coordinates): array {
                $payload = fuelauAlternativeRoutePlan($coordinates, 3, false);
                if (($payload['code'] ?? null) !== 'Ok') {
                    throw new FuelauUpstreamException(
                        'OSRM did not return usable alternative routes.',
                    );
                }

                return is_array($payload['routes'] ?? null)
                    ? array_slice($payload['routes'], 0, 3)
                    : [];
            };
        }
        if ($displayRouteLoader !== null) {
            $this->displayRouteLoader = $displayRouteLoader;
        } elseif ($routeLoader === null) {
            $this->displayRouteLoader = static function (array $coordinates): array {
                $payload = fuelauRoutePlan($coordinates, false, 'full');
                $route = $payload['routes'][0] ?? null;
                if (($payload['code'] ?? null) !== 'Ok' || !is_array($route)) {
                    throw new FuelauUpstreamException(
                        'OSRM did not return usable full display geometry.',
                    );
                }

                return $route;
            };
        } else {
            // Dependency-injected planners retain their supplied geometry unless
            // a distinct display loader is explicitly provided.
            $this->displayRouteLoader = null;
        }
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
        $this->tableLoader = $tableLoader ?? static fn(array $coordinates): array =>
            fuelauOsrmTable($coordinates);
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(FuelauRouteOptimizationRequest $request): array
    {
        $locations = $request->itineraryLocations();
        $legCount = count($locations) - 1;
        $routeSets = [];
        foreach (array_slice($locations, 1) as $index => $target) {
            $start = $locations[$index];
            $routes = ($this->alternativeRouteLoader)([
                ['lat' => $start->latitude, 'lon' => $start->longitude],
                ['lat' => $target->latitude, 'lon' => $target->longitude],
            ]);
            if (!is_array($routes) || $routes === []) {
                throw new FuelauUpstreamException('OSRM returned no corridor routes.');
            }
            $routeSets[] = array_values(array_filter(
                array_slice($routes, 0, 3),
                static fn(mixed $route): bool => is_array($route),
            ));
            if ($routeSets[$index] === []) {
                throw new FuelauUpstreamException('OSRM returned no usable corridor routes.');
            }
        }

        $corridors = $this->distinctCorridors($routeSets);
        $asOf = ($this->clock)();
        $successful = [];
        $failures = [];
        $exactRouteRequestCount = 0;
        $evaluatedRawCandidateCount = 0;
        $evaluatedTableRequestCount = 0;
        foreach ($corridors as $corridor) {
            $baselineCallIndex = 0;
            $rank = $corridor['rank'];
            $routes = $corridor['routes'];
            $displayCoordinates = array_map(
                static fn(FuelauRouteOptimizationLocation $location): array => [
                    'lat' => $location->latitude,
                    'lon' => $location->longitude,
                ],
                $locations,
            );
            if ($rank > 0) {
                $displayCoordinates = $this->shapeExactCoordinates(
                    $displayCoordinates,
                    $locations,
                    $routes,
                );
            }
            $countedCandidateLoader = function (
                array $points,
                string $fuel,
            ) use (&$evaluatedRawCandidateCount): array {
                $rows = ($this->candidateLoader)($points, $fuel);
                $evaluatedRawCandidateCount += count($rows);

                return $rows;
            };
            $countedTableLoader = function (
                array $coordinates,
            ) use (&$evaluatedTableRequestCount): array {
                $evaluatedTableRequestCount++;

                return ($this->tableLoader)($coordinates);
            };
            $seededRouteLoader = function (array $coordinates) use (
                &$baselineCallIndex,
                &$exactRouteRequestCount,
                $legCount,
                $rank,
                $routes,
                $locations,
                &$displayCoordinates,
            ): array {
                if ($baselineCallIndex < $legCount) {
                    return $routes[$baselineCallIndex++];
                }
                $exactRouteRequestCount++;
                if ($rank > 0) {
                    $coordinates = $this->shapeExactCoordinates(
                        $coordinates,
                        $locations,
                        $routes,
                    );
                }
                $displayCoordinates = $coordinates;

                return ($this->routeLoader)($coordinates);
            };
            $fixedClock = static fn(): DateTimeImmutable => $asOf;
            try {
                $planner = $request->returnMode === 'one_way'
                    && count($request->destinations) === 1
                    ? new FuelauLiveSingleCorridorPlanner(
                        $seededRouteLoader,
                        $countedCandidateLoader,
                        $countedTableLoader,
                        $fixedClock,
                    )
                    : new FuelauLiveCompleteItineraryPlanner(
                        $seededRouteLoader,
                        $countedCandidateLoader,
                        $countedTableLoader,
                        $fixedClock,
                    );
                $response = $planner->plan($request);
                $successful[] = [
                    'rank' => $rank,
                    'response' => $response,
                    'display_coordinates' => $displayCoordinates,
                ];
            } catch (
                FuelauRouteInfeasibleException
                | FuelauRoutePlanValidationException
                | FuelauUpstreamException $exception
            ) {
                $failures[] = [
                    'rank' => $rank,
                    'exception' => $exception,
                ];
            }
        }
        if ($successful === []) {
            throw (
                $failures[0]['exception']
                ?? new FuelauRouteInfeasibleException('No alternative corridor is feasible.')
            );
        }

        $selected = (new FuelauAlternativeCorridorSelector())->select($successful);
        $response = $selected['response'];
        $selectedRank = $selected['rank'];
        $displayGeometryRequestCount = 0;
        $displayGeometryStatus = 'simplified';
        if ($this->displayRouteLoader !== null) {
            $displayGeometryRequestCount++;
            try {
                $displayRoute = ($this->displayRouteLoader)(
                    $selected['display_coordinates'],
                );
                $displayGeometry = $displayRoute['geometry'] ?? null;
                $displayGeometryCoordinates = is_array($displayGeometry)
                    ? ($displayGeometry['coordinates'] ?? null)
                    : null;
                if (
                    !is_array($displayGeometry)
                    || !is_array($displayGeometryCoordinates)
                    || count($displayGeometryCoordinates) < 2
                ) {
                    throw new FuelauUpstreamException(
                        'OSRM full display geometry is missing route coordinates.',
                    );
                }
                foreach ($response['route_pieces'] as &$routePiece) {
                    if (($routePiece['kind'] ?? null) !== 'selected_route') {
                        continue;
                    }
                    $routePiece['geometry'] = $displayGeometry;
                    $displayGeometryStatus = 'full';
                    break;
                }
                unset($routePiece);
            } catch (Throwable $exception) {
                error_log(
                    'FuelAU full display geometry lookup failed; using simplified geometry: '
                        . $exception->getMessage(),
                );
                $displayGeometryStatus = 'simplified_fallback';
            }
        }
        $response['corridor']['id'] = 'corridor-' . ($selectedRank + 1);
        $response['corridor']['kind'] = $selectedRank === 0 ? 'fastest' : 'alternative';
        $response['corridor']['selection_reason'] = $this->selectionReason(
            $corridors,
            $successful,
            $selected,
        );
        $response['alternatives'] = [];
        foreach ($successful as $candidate) {
            if ($candidate['rank'] === $selectedRank) {
                continue;
            }
            $response['alternatives'][] = $this->corridorSummary(
                $candidate['rank'],
                $candidate['response'],
                $response,
            );
        }
        foreach ($failures as $failure) {
            $response['alternatives'][] = [
                'id' => 'corridor-' . ($failure['rank'] + 1),
                'kind' => $failure['rank'] === 0 ? 'fastest' : 'alternative',
                'status' => $failure['exception'] instanceof FuelauRouteInfeasibleException
                    ? 'infeasible'
                    : 'validation_failed',
                'selected' => false,
            ];
        }
        usort(
            $response['alternatives'],
            static fn(array $left, array $right): int =>
                strcmp((string) $left['id'], (string) $right['id']),
        );
        $response['diagnostics']['corridor_count'] = count($corridors);
        $response['diagnostics']['feasible_corridor_count'] = count($successful);
        $response['diagnostics']['osrm_route_request_count'] =
            $legCount + $exactRouteRequestCount + $displayGeometryRequestCount;
        $response['diagnostics']['display_geometry'] = $displayGeometryStatus;
        $response['diagnostics']['osrm_table_request_count'] =
            $evaluatedTableRequestCount;
        $response['diagnostics']['evaluated_raw_candidate_count'] =
            $evaluatedRawCandidateCount;

        return $response;
    }

    /**
     * @param list<list<array<string, mixed>>> $routeSets
     * @return list<array{rank: int, routes: list<array<string, mixed>>}>
     */
    private function distinctCorridors(array $routeSets): array
    {
        $maximumRankCount = max(array_map('count', $routeSets));
        $seen = [];
        $corridors = [];
        for ($rank = 0; $rank < min(3, $maximumRankCount); $rank++) {
            $routes = [];
            foreach ($routeSets as $routeSet) {
                $routes[] = $routeSet[$rank] ?? $routeSet[0];
            }
            $fingerprint = hash('sha256', json_encode(array_map(
                static fn(array $route): array => [
                    (int) round((float) ($route['distance'] ?? 0)),
                    (int) round((float) ($route['duration'] ?? 0)),
                    $route['geometry']['coordinates'] ?? [],
                ],
                $routes,
            ), JSON_UNESCAPED_SLASHES) ?: '');
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $corridors[] = ['rank' => $rank, 'routes' => $routes];
        }

        return $corridors;
    }

    /**
     * @param list<array{lat: float, lon: float}> $coordinates
     * @param list<FuelauRouteOptimizationLocation> $locations
     * @param list<array<string, mixed>> $routes
     * @return list<array{lat: float, lon: float}>
     */
    private function shapeExactCoordinates(
        array $coordinates,
        array $locations,
        array $routes,
    ): array {
        $corridors = array_map(
            static fn(array $route): FuelauRouteCorridor =>
                FuelauRouteCorridor::fromOsrmRoute($route),
            $routes,
        );
        $totalDistanceM = array_sum(array_map(
            static fn(FuelauRouteCorridor $corridor): int => $corridor->distanceM,
            $corridors,
        ));
        $anchorSpacingM = max(200_000, (int) ceil($totalDistanceM / 40));
        $result = [$coordinates[0]];
        $coordinateIndex = 1;
        foreach ($corridors as $legIndex => $corridor) {
            $target = $locations[$legIndex + 1];
            $visits = [];
            while (isset($coordinates[$coordinateIndex])) {
                $coordinate = $coordinates[$coordinateIndex++];
                if ($this->sameCoordinate($coordinate, $target)) {
                    break;
                }
                $projection = $corridor->project(
                    $coordinate['lat'],
                    $coordinate['lon'],
                );
                $visits[] = [
                    'progress_m' => $projection->progressM,
                    'coordinate' => $coordinate,
                    'kind' => 0,
                ];
            }
            for (
                $progressM = $anchorSpacingM;
                $progressM < $corridor->distanceM;
                $progressM += $anchorSpacingM
            ) {
                $visits[] = [
                    'progress_m' => $progressM,
                    'coordinate' => $corridor->coordinateAtProgressM($progressM),
                    'kind' => 1,
                ];
            }
            usort(
                $visits,
                static fn(array $left, array $right): int =>
                    ($left['progress_m'] <=> $right['progress_m'])
                    ?: ($left['kind'] <=> $right['kind']),
            );
            foreach ($visits as $visit) {
                $result[] = $visit['coordinate'];
            }
            $result[] = [
                'lat' => $target->latitude,
                'lon' => $target->longitude,
            ];
        }

        return array_values(array_filter(
            $result,
            static function (array $coordinate, int $index) use ($result): bool {
                if ($index === 0) {
                    return true;
                }
                $previous = $result[$index - 1];

                return $coordinate['lat'] !== $previous['lat']
                    || $coordinate['lon'] !== $previous['lon'];
            },
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param array{lat: float, lon: float} $coordinate
     */
    private function sameCoordinate(
        array $coordinate,
        FuelauRouteOptimizationLocation $location,
    ): bool {
        return abs($coordinate['lat'] - $location->latitude) < 0.0000001
            && abs($coordinate['lon'] - $location->longitude) < 0.0000001;
    }

    /**
     * @param array<string, mixed> $candidateResponse
     * @param array<string, mixed> $selectedResponse
     * @return array<string, mixed>
     */
    private function corridorSummary(
        int $rank,
        array $candidateResponse,
        array $selectedResponse,
    ): array {
        $summary = $candidateResponse['summary'];
        $selectedSummary = $selectedResponse['summary'];

        return [
            'id' => 'corridor-' . ($rank + 1),
            'kind' => $rank === 0 ? 'fastest' : 'alternative',
            'status' => 'feasible',
            'selected' => false,
            'distance_m' => (int) $summary['route_distance_m'],
            'duration_s' => (int) $summary['route_duration_s'],
            'fuel_purchase_cost_cents' => (int) $summary['fuel_purchase_cost_cents'],
            'generalized_cost_cents' => (int) $summary['generalized_cost_cents'],
            'fuel_stop_count' => (int) $summary['required_stop_count']
                + (int) $summary['discretionary_stop_count']
                + (int) $summary['combined_stop_count'],
            'generalized_cost_delta_cents' =>
                (int) $summary['generalized_cost_cents']
                - (int) $selectedSummary['generalized_cost_cents'],
        ];
    }

    /**
     * @param list<array{rank: int, routes: list<array<string, mixed>>}> $corridors
     * @param list<array{rank: int, response: array<string, mixed>}> $successful
     * @param array{rank: int, response: array<string, mixed>} $selected
     */
    private function selectionReason(
        array $corridors,
        array $successful,
        array $selected,
    ): string {
        if (count($corridors) === 1) {
            return 'only_distinct_corridor';
        }
        $selectedCost = (int) (
            $selected['response']['summary']['generalized_cost_cents'] ?? PHP_INT_MAX
        );
        $lowestCost = min(array_map(
            static fn(array $candidate): int => (int) (
                $candidate['response']['summary']['generalized_cost_cents']
                ?? PHP_INT_MAX
            ),
            $successful,
        ));
        if ($selectedCost > $lowestCost) {
            return 'similar_cost_fewer_fuel_stops';
        }

        return $selected['rank'] === 0
            ? 'fastest_corridor_lowest_generalized_cost'
            : 'lower_complete_generalized_cost';
    }
}

final class FuelauLiveRoutePlanner
{
    private FuelauLiveAlternativeCorridorPlanner $planner;

    public function __construct(
        ?Closure $routeLoader = null,
        ?Closure $candidateLoader = null,
        ?Closure $tableLoader = null,
        ?Closure $clock = null,
        ?Closure $alternativeRouteLoader = null,
        ?Closure $displayRouteLoader = null,
    ) {
        $this->planner = new FuelauLiveAlternativeCorridorPlanner(
            $routeLoader,
            $candidateLoader,
            $tableLoader,
            $clock,
            $alternativeRouteLoader,
            $displayRouteLoader,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(FuelauRouteOptimizationRequest $request): array
    {
        return $this->planner->plan($request);
    }
}
