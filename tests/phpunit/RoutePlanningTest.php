<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RoutePlanningTest extends TestCase
{
    public function testCorridorProjectionUsesOsrmDistanceAndDurationTotals(): void
    {
        $corridor = FuelauRouteCorridor::fromOsrmRoute([
            'distance' => 100_000,
            'duration' => 3_600,
            'geometry' => [
                'coordinates' => [
                    [150.0, -30.0],
                    [151.0, -30.0],
                ],
            ],
        ]);

        $projection = $corridor->project(-30.1, 150.5);

        self::assertSame(50_000, $projection->progressM);
        self::assertSame(1_800, $projection->progressS);
        self::assertGreaterThanOrEqual(11_000, $projection->offRouteM);
        self::assertLessThanOrEqual(11_200, $projection->offRouteM);
        self::assertCount(5, $corridor->candidateLookupPoints(25_000));
    }

    public function testCandidateCapPreservesEveryNonEmptyCoverageBin(): void
    {
        $corridor = new FuelauRouteCorridor(
            distanceM: 300_000,
            durationS: 10_800,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 153.0],
            ],
        );
        $input = (new FuelauFixedCorridorCandidateAdapter())->build(
            $corridor,
            [
                $this->stationRow('near-1', 150.1, 190),
                $this->stationRow('near-2', 150.2, 180),
                $this->stationRow('near-3', 150.3, 170),
                $this->stationRow('middle', 151.2, 200),
                $this->stationRow('remote', 152.6, 210),
            ],
            maximumCandidates: 3,
        );
        $coverageBins = array_map(
            static fn (FuelauOptimizerNode $node): int => intdiv($node->progressM, 50_000),
            array_slice($input->nodes, 1, -1),
        );

        self::assertSame(5, $input->eligibleCandidateCount);
        self::assertSame(3, $input->selectedCandidateCount);
        self::assertSame([0, 2, 5], $coverageBins);
    }

    public function testCandidatesAreDeduplicatedByStableIdentityAndFiltered(): void
    {
        $corridor = new FuelauRouteCorridor(
            distanceM: 100_000,
            durationS: 3_600,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 151.0],
            ],
        );
        $input = (new FuelauFixedCorridorCandidateAdapter())->build(
            $corridor,
            [
                $this->stationRow('same', 150.5, 200),
                [
                    ...$this->stationRow('same', 150.5, 190),
                    'access_distance_m' => 1_234,
                    'access_duration_s' => 120,
                ],
                [
                    ...$this->stationRow('bad-source', 150.6, 100),
                    'source' => 'unofficial',
                ],
                [
                    ...$this->stationRow('too-far', 150.6, 100),
                    'latitude' => -31.0,
                ],
            ],
        );
        $candidate = array_values($input->candidatesByNodeId)[0];

        self::assertSame(1, $input->eligibleCandidateCount);
        self::assertSame('nsw:NSW:same:E10', $candidate->stableId);
        self::assertSame(190.0, $candidate->priceCentsPerL);
        self::assertSame(1_234, $candidate->accessDistanceM);
        self::assertSame(120, $candidate->accessDurationS);
    }

    public function testProjectedCandidatesCanBeOptimizedWithoutShapeConversion(): void
    {
        $corridor = new FuelauRouteCorridor(
            distanceM: 600_000,
            durationS: 21_600,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 156.0],
            ],
        );
        $input = (new FuelauFixedCorridorCandidateAdapter())->build(
            $corridor,
            [
                $this->stationRow('required', 150.5, 200, 'Required'),
                $this->stationRow('strategic', 153.0, 100, 'Strategic'),
                $this->stationRow('fallback', 155.5, 300, 'Fallback'),
            ],
        );

        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            $input->nodes,
            new FuelauOptimizerVehicle(60, 12, 6, 10),
            new FuelauOptimizerPolicy(maximumFuelOnlyStops: 3),
        );

        self::assertSame(2, $plan->fuelStopCount);
        self::assertSame('Required', $plan->purchases[0]->label);
        self::assertSame('Strategic', $plan->purchases[1]->label);
        self::assertSame('strategic', $plan->purchases[1]->classification);
    }

    /**
     * @return array<string, mixed>
     */
    private function stationRow(
        string $stationId,
        float $longitude,
        float $price,
        string $stationName = '',
    ): array {
        return [
            'source' => 'nsw',
            'state' => 'NSW',
            'station_id' => $stationId,
            'station_name' => $stationName,
            'fuel_code' => 'E10',
            'latitude' => -30.0,
            'longitude' => $longitude,
            'price' => $price,
        ];
    }
}
