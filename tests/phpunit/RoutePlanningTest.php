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
            static fn(FuelauOptimizerNode $node): int => intdiv($node->progressM, 50_000),
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
        self::assertFalse($candidate->accessEstimated);
    }

    public function testPriceFreshnessUsesInjectedReferenceTime(): void
    {
        $rows = fuelauClassifyRouteCandidatePriceRows(
            [
                ['updated_at' => '2026-07-20T00:00:00Z'],
                ['updated_at' => '2026-07-16T00:00:00Z'],
                ['updated_at' => '2026-07-15T23:59:59Z'],
                ['updated_at' => '2026-07-31T00:00:00Z'],
                ['updated_at' => 'invalid'],
            ],
            new DateTimeImmutable('2026-07-30T00:00:00Z'),
        );

        self::assertSame(
            ['fresh', 'fresh', 'stale', 'stale', 'stale'],
            array_column($rows, 'price_status'),
        );
    }

    public function testWaCalendarPriceDateUsesPerthTime(): void
    {
        $rows = fuelauClassifyRouteCandidatePriceRows(
            [
                ['source' => 'wa', 'updated_at' => '2026-08-07'],
                ['source' => 'nsw', 'updated_at' => '2026-08-07'],
            ],
            new DateTimeImmutable('2026-08-06T21:00:00Z'),
        );

        self::assertSame(['fresh', 'stale'], array_column($rows, 'price_status'));
    }

    public function testCompleteItineraryUsesOutboundPurchaseForReturnLeg(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0, 'label' => 'Origin'],
            'destinations' => [
                ['lat' => -30.0, 'lon' => 153.0, 'label' => 'Destination'],
            ],
            'return_mode' => 'direct',
            'fuel' => [
                'type' => 'E10',
                'tank_capacity_l' => 70,
                'starting_fuel_l' => 35,
                'economy_l_per_100km' => 10,
                'reserve_l' => 5,
            ],
            'preferences' => [
                'maximum_fuel_only_stops' => 3,
                'minimum_discretionary_purchase_l' => 5,
                'minimum_stop_spacing_km' => 100,
                'minimum_stop_spacing_minutes' => 60,
            ],
        ]);
        $station = [
            ...$this->stationRow('round-trip-cheap', 152.5, 100),
            'updated_at' => '2026-07-30T00:00:00Z',
            'price_status' => 'fresh',
            'access_distance_m' => 0,
            'access_duration_s' => 0,
        ];
        $locations = $request->itineraryLocations();
        $result = (new FuelauCompleteItineraryPlanner())->plan($request, [
            new FuelauPreparedItineraryLeg(
                0,
                new FuelauRouteCorridor(
                    300_000,
                    10_800,
                    [
                        ['lat' => -30.0, 'lon' => 150.0],
                        ['lat' => -30.0, 'lon' => 153.0],
                    ],
                ),
                $locations[1],
                [$station],
            ),
            new FuelauPreparedItineraryLeg(
                1,
                new FuelauRouteCorridor(
                    300_000,
                    10_800,
                    [
                        ['lat' => -30.0, 'lon' => 153.0],
                        ['lat' => -30.0, 'lon' => 150.0],
                    ],
                ),
                $locations[2],
                [$station],
            ),
        ]);

        self::assertSame(1, $result->plan->fuelStopCount);
        self::assertSame(
            'station:nsw:NSW:round-trip-cheap:E10:visit:0',
            $result->plan->purchases[0]->nodeId,
        );
        self::assertSame(30.0, $result->plan->purchases[0]->purchaseL);
        self::assertSame(3_000, $result->plan->fuelPurchaseCostCents);
        self::assertSame(
            [
                'station:nsw:NSW:round-trip-cheap:E10:visit:0',
                'station:nsw:NSW:round-trip-cheap:E10:visit:1',
            ],
            array_keys($result->input->candidatesByNodeId),
        );
    }

    public function testPlannedStopFuelDoesNotConsumeFuelOnlyStopAllowance(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0],
            'destinations' => [
                ['lat' => -30.0, 'lon' => 153.0, 'label' => 'Meal stop'],
                ['lat' => -30.0, 'lon' => 156.0, 'label' => 'Destination'],
            ],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'E10',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 35,
                'economy_l_per_100km' => 10,
                'reserve_l' => 5,
            ],
            'preferences' => [
                'maximum_fuel_only_stops' => 0,
                'minimum_discretionary_purchase_l' => 40,
                'minimum_stop_spacing_km' => 400,
                'minimum_stop_spacing_minutes' => 240,
            ],
        ]);
        $locations = $request->itineraryLocations();
        $combinedStation = [
            ...$this->validatedStationRow(
                'meal-stop-fuel',
                152.95,
                101,
                '2026-07-30T00:00:00Z',
            ),
        ];
        $result = (new FuelauCompleteItineraryPlanner())->plan($request, [
            new FuelauPreparedItineraryLeg(
                0,
                new FuelauRouteCorridor(
                    300_000,
                    10_800,
                    [
                        ['lat' => -30.0, 'lon' => 150.0],
                        ['lat' => -30.0, 'lon' => 153.0],
                    ],
                ),
                $locations[1],
                [$combinedStation],
            ),
            new FuelauPreparedItineraryLeg(
                1,
                new FuelauRouteCorridor(
                    300_000,
                    10_800,
                    [
                        ['lat' => -30.0, 'lon' => 153.0],
                        ['lat' => -30.0, 'lon' => 156.0],
                    ],
                ),
                $locations[2],
                [],
            ),
        ]);

        self::assertSame(1, $result->plan->fuelStopCount);
        self::assertSame(0, $result->plan->fuelOnlyStopCount);
        self::assertSame(1, $result->plan->combinedStopCount);
        self::assertSame('combined', $result->plan->purchases[0]->classification);
        self::assertSame(3_030, $result->plan->generalizedCostCents);
        self::assertSame(
            ['planned_stop_combination'],
            $result->plan->purchases[0]->reasonCodes,
        );
        $stop = $result->toResponseArray()['stops'][0];
        self::assertSame(1, $stop['itinerary_leg_index']);
        self::assertSame(2, $stop['itinerary_leg_number']);
        self::assertSame(
            'station:nsw:NSW:meal-stop-fuel:E10:visit:0',
            $stop['node_id'],
        );
    }

    public function testRoadAccessMeasurementUsesOneBoundedTableChunk(): void
    {
        $corridor = new FuelauRouteCorridor(
            distanceM: 200_000,
            durationS: 7_200,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 152.0],
            ],
        );
        $calls = 0;
        $rows = (new FuelauCandidateRoadAccessMeasurer())->measure(
            $corridor,
            [
                $this->stationRow('first', 150.5, 180),
                $this->stationRow('second', 151.5, 190),
            ],
            static function (array $coordinates) use (&$calls): array {
                $calls++;
                $count = count($coordinates);
                $distances = array_fill(0, $count, array_fill(0, $count, null));
                $durations = array_fill(0, $count, array_fill(0, $count, null));
                for ($index = 0; $index < $count; $index++) {
                    $distances[$index][$index] = 0;
                    $durations[$index][$index] = 0;
                }
                $distances[0][1] = 1_000;
                $distances[1][2] = 1_200;
                $distances[0][2] = 0;
                $durations[0][1] = 100;
                $durations[1][2] = 120;
                $durations[0][2] = 0;
                $distances[3][4] = 2_000;
                $distances[4][5] = 2_200;
                $distances[3][5] = 0;
                $durations[3][4] = 200;
                $durations[4][5] = 220;
                $durations[3][5] = 0;

                return ['distances' => $distances, 'durations' => $durations];
            },
        );

        self::assertSame(1, $calls);
        self::assertCount(2, $rows);
        self::assertSame(1_100, $rows[0]['access_distance_m']);
        self::assertSame(110, $rows[0]['access_duration_s']);
        self::assertSame(2_100, $rows[1]['access_distance_m']);
        self::assertSame(210, $rows[1]['access_duration_s']);
    }

    public function testRoadAccessShortlistScalesCoverageBinsForLongRoutes(): void
    {
        $corridor = new FuelauRouteCorridor(
            distanceM: 4_500_000,
            durationS: 180_000,
            geometry: [
                ['lat' => -30.0, 'lon' => 110.0],
                ['lat' => -30.0, 'lon' => 150.5],
            ],
        );
        $candidateRows = [];
        for ($index = 1; $index <= 90; $index++) {
            $candidateRows[] = [
                ...$this->stationRow("station-{$index}", 110.0 + (40.5 * ($index / 91)), 180),
                'source' => 'wa',
                'state' => 'WA',
            ];
        }
        $tableCalls = 0;
        $rows = (new FuelauCandidateRoadAccessMeasurer())->measure(
            $corridor,
            $candidateRows,
            static function (array $coordinates) use (&$tableCalls): array {
                $tableCalls++;
                $count = count($coordinates);
                $distances = array_fill(0, $count, array_fill(0, $count, null));
                $durations = array_fill(0, $count, array_fill(0, $count, null));
                for ($index = 0; $index < $count; $index++) {
                    $distances[$index][$index] = 0;
                    $durations[$index][$index] = 0;
                }
                for ($index = 0; $index < $count; $index += 3) {
                    $distances[$index][$index + 1] = 1_000;
                    $distances[$index + 1][$index + 2] = 1_000;
                    $distances[$index][$index + 2] = 0;
                    $durations[$index][$index + 1] = 60;
                    $durations[$index + 1][$index + 2] = 60;
                    $durations[$index][$index + 2] = 0;
                }

                return ['distances' => $distances, 'durations' => $durations];
            },
        );

        self::assertCount(80, $rows);
        self::assertSame(7, $tableCalls);
        self::assertLessThan(112.0, (float) $rows[0]['longitude']);
        self::assertGreaterThan(148.0, (float) $rows[count($rows) - 1]['longitude']);
    }

    public function testRoadAccessShortlistPreservesPhysicalRangeBackbone(): void
    {
        $corridor = new FuelauRouteCorridor(
            distanceM: 1_000_000,
            durationS: 36_000,
            geometry: [
                ['lat' => -20.0, 'lon' => 130.0],
                ['lat' => -20.0, 'lon' => 140.0],
            ],
        );
        $station = static fn(
            string $id,
            string $name,
            float $longitude,
            float $price,
        ): array => [
            'source' => 'qld',
            'state' => 'QLD',
            'station_id' => $id,
            'station_name' => $name,
            'fuel_code' => 'DL',
            'latitude' => -20.0,
            'longitude' => $longitude,
            'price' => $price,
            'price_status' => 'fresh',
        ];
        $rows = (new FuelauCandidateRoadAccessMeasurer())->measure(
            $corridor,
            [
                $station('mount-isa', 'Mount Isa', 132.0, 200),
                $station('camooweal', 'Camooweal', 134.0, 300),
                $station('barkly', 'Barkly Homestead', 136.5, 300),
                $station('threeways', 'Threeways', 138.5, 200),
            ],
            static function (array $coordinates): array {
                $count = count($coordinates);
                $distances = array_fill(0, $count, array_fill(0, $count, null));
                $durations = array_fill(0, $count, array_fill(0, $count, null));
                for ($index = 0; $index < $count; $index++) {
                    $distances[$index][$index] = 0;
                    $durations[$index][$index] = 0;
                }
                for ($index = 0; $index < $count; $index += 3) {
                    $distances[$index][$index + 1] = 0;
                    $distances[$index + 1][$index + 2] = 0;
                    $distances[$index][$index + 2] = 0;
                    $durations[$index][$index + 1] = 0;
                    $durations[$index + 1][$index + 2] = 0;
                    $durations[$index][$index + 2] = 0;
                }

                return ['distances' => $distances, 'durations' => $durations];
            },
            maximumCandidates: 2,
            vehicle: new FuelauOptimizerVehicle(60, 60, 5, 12),
        );

        self::assertSame(
            ['Mount Isa', 'Barkly Homestead'],
            array_column($rows, 'station_name'),
        );
        $input = (new FuelauFixedCorridorCandidateAdapter())->build($corridor, $rows);
        $adjusted = (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            $input->nodes,
            new FuelauOptimizerVehicle(60, 60, 5, 12),
            new FuelauOptimizerPolicy(
                minimumDiscretionaryPurchaseL: 0,
                minimumNetSavingCents: 0,
            ),
        );
        self::assertSame(60.0, $adjusted->effectiveFuelCapacityL);
    }

    public function testCompleteItineraryCandidateBudgetIsGlobalAndDistanceWeighted(): void
    {
        $leg = static fn(int $index, int $distanceM): FuelauPreparedItineraryLeg =>
            new FuelauPreparedItineraryLeg(
                $index,
                new FuelauRouteCorridor(
                    $distanceM,
                    max(1, intdiv($distanceM, 25)),
                    [
                        ['lat' => -30.0, 'lon' => 120.0 + $index],
                        ['lat' => -30.0, 'lon' => 121.0 + $index],
                    ],
                ),
                new FuelauRouteOptimizationLocation(-30.0, 121.0 + $index, '', true),
                [],
            );
        $budget = new FuelauItineraryRoadCandidateBudget();

        self::assertSame(
            [32, 32],
            $budget->allocate([$leg(0, 2_700_000), $leg(1, 2_700_000)]),
        );
        self::assertSame(
            [8, 56],
            $budget->allocate([$leg(0, 500_000), $leg(1, 3_500_000)]),
        );
        self::assertSame(
            array_fill(0, 20, 3),
            $budget->allocate(array_map(
                static fn(int $index): FuelauPreparedItineraryLeg => $leg($index, 100_000),
                range(0, 19),
            )),
        );
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

    public function testCapacityGapIsFlaggedAndAdditionalFuelUsesPriorStationPrice(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0, 'label' => 'Edmonton'],
            'destinations' => [
                ['lat' => -30.0, 'lon' => 160.0, 'label' => 'Perth'],
            ],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 20,
                'economy_l_per_100km' => 10,
                'reserve_l' => 5,
            ],
            'preferences' => [
                'maximum_fuel_only_stops' => 3,
                'minimum_discretionary_purchase_l' => 0,
                'minimum_net_saving_cents' => 0,
            ],
        ]);
        $nodes = [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station('station:prior', 50_000, 200, 'Prior Fuel'),
            FuelauOptimizerNode::station('station:next', 800_000, 250, 'Next Fuel'),
            new FuelauOptimizerNode('destination', 1_000_000),
        ];
        $candidate = static fn(
            string $nodeId,
            string $name,
            int $progressM,
            float $price,
        ): FuelauProjectedStationCandidate => new FuelauProjectedStationCandidate(
            stableId: $nodeId,
            nodeId: $nodeId,
            label: $name,
            progressM: $progressM,
            progressS: 0,
            offRouteM: 0,
            accessDistanceM: 0,
            accessDurationS: 0,
            accessEstimated: false,
            priceCentsPerL: $price,
            sourceRow: [
                'source' => 'qld',
                'state' => 'QLD',
                'station_id' => $nodeId,
                'station_name' => $name,
                'latitude' => -30.0,
                'longitude' => $progressM / 100_000,
                'itinerary_leg_index' => 0,
            ],
        );
        $input = new FuelauFixedCorridorInput(
            nodes: $nodes,
            candidatesByNodeId: [
                'station:prior' => $candidate('station:prior', 'Prior Fuel', 50_000, 200),
                'station:next' => $candidate('station:next', 'Next Fuel', 800_000, 250),
            ],
            eligibleCandidateCount: 2,
            selectedCandidateCount: 2,
        );
        $policy = fuelauOptimizerPolicyForRequest($request);
        $adjusted = (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            $nodes,
            new FuelauOptimizerVehicle(60, 20, 5, 10),
            $policy,
        );
        $result = new FuelauSingleCorridorOptimizationResult(
            request: $request,
            corridor: new FuelauRouteCorridor(
                1_000_000,
                36_000,
                [
                    ['lat' => -30.0, 'lon' => 150.0],
                    ['lat' => -30.0, 'lon' => 160.0],
                ],
            ),
            input: $input,
            plan: $adjusted->plan,
            policy: $policy,
            itineraryLegs: [[
                'index' => 0,
                'distance_m' => 1_000_000,
                'duration_s' => 36_000,
                'target' => $request->destinations[0]->toArray(),
            ]],
            effectiveFuelCapacityL: $adjusted->effectiveFuelCapacityL,
        );

        $response = $result->toResponseArray();
        $requirement = $response['additional_fuel_requirements'][0];

        self::assertSame(80.0, $adjusted->effectiveFuelCapacityL);
        self::assertSame(20.0, $requirement['additional_fuel_l']);
        self::assertSame(4_000, $requirement['additional_fuel_cost_cents']);
        self::assertSame('Prior Fuel', $requirement['station_name']);
        self::assertSame('Next Fuel', $requirement['next_stop_name']);
        self::assertSame(
            'Leg 1 requires additional 20.0 litres of fuel to reach next stop',
            $requirement['message'],
        );
        self::assertSame(
            'Purchase additional 20.0 litres of fuel at Prior Fuel in order to reach next stop at Next Fuel.',
            $requirement['purchase_instruction'],
        );
        self::assertSame(
            $adjusted->plan->fuelPurchaseCostCents,
            $requirement['leg_fuel_purchase_cost_cents'],
        );
        self::assertTrue($response['itinerary']['legs'][0]['requires_additional_fuel']);
        self::assertSame(4_000, $response['summary']['additional_fuel_cost_cents']);
        self::assertContains(
            'additional_fuel_required',
            $response['stops'][0]['reason_codes'],
        );
    }

    public function testSingleCorridorPlannerBuildsResponseAndMapsPreferences(): void
    {
        $request = $this->optimizationRequest([
            'maximum_fuel_only_stops' => 3,
            'maximum_discretionary_detour_km' => 12,
            'maximum_discretionary_detour_minutes' => 8,
        ]);
        $corridor = new FuelauRouteCorridor(
            distanceM: 600_000,
            durationS: 21_600,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 156.0],
            ],
        );
        $rows = [
            $this->validatedStationRow('required', 150.5, 200, '2026-07-29T00:00:00Z'),
            $this->validatedStationRow('strategic', 153.0, 100, '2026-07-30T00:00:00Z'),
            $this->validatedStationRow('fallback', 155.5, 300, '2026-07-30T00:00:00Z'),
            [
                ...$this->validatedStationRow('stale', 154.0, 50, '2026-01-01T00:00:00Z'),
                'price_status' => 'stale',
            ],
        ];

        $result = (new FuelauSingleCorridorPlanner())->plan($request, $corridor, $rows);
        $response = $result->toResponseArray();

        self::assertSame(12_000, $result->policy->maximumDiscretionaryDetourM);
        self::assertSame(480, $result->policy->maximumDiscretionaryDetourS);
        self::assertSame(60.0, $response['summary']['fuel_used_l']);
        self::assertSame(7800, $response['summary']['fuel_purchase_cost_cents']);
        self::assertSame(26_800, $response['summary']['generalized_cost_cents']);
        self::assertSame('2026-07-29T00:00:00Z', $response['summary']['price_as_of']);
        self::assertCount(2, $response['stops']);
        self::assertSame(3, $response['diagnostics']['candidate_count']);
        self::assertCount(4, $result->exactRouteCoordinates());

        $validator = new FuelauExactRouteValidator();
        self::assertFalse($validator->validate(
            $result,
            ['distance' => 601_000, 'duration' => 21_700],
        )->requiresReoptimization);
        self::assertTrue($validator->validate(
            $result,
            ['distance' => 603_000, 'duration' => 22_000],
        )->requiresReoptimization);
        $conservativeVariance = $validator->validate(
            $result,
            ['distance' => 597_000, 'duration' => 21_450],
        );
        self::assertTrue($conservativeVariance->requiresReoptimization);
        self::assertTrue($validator->isAcceptableConservativeVariance(
            $conservativeVariance,
        ));
        self::assertFalse($validator->isAcceptableConservativeVariance(
            $validator->validate(
                $result,
                ['distance' => 594_000, 'duration' => 21_450],
            ),
        ));

        $exactRouteCalls = 0;
        $validated = (new FuelauSingleCorridorValidationCoordinator())->planAndValidate(
            $request,
            $corridor,
            $rows,
            static function (array $coordinates) use (&$exactRouteCalls): array {
                $exactRouteCalls++;
                self::assertCount(4, $coordinates);

                return ['distance' => 603_000, 'duration' => 22_000];
            },
        );
        self::assertSame(2, $exactRouteCalls);
        self::assertSame(2, $validated->validationPassCount);
        self::assertFalse($validated->validation->requiresReoptimization);
    }

    public function testExpensiveReachableStationsDoNotTriggerAuxiliaryFuel(): void
    {
        $adjusted = (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('expensive-1', 500_000, 999.9),
                FuelauOptimizerNode::station('expensive-2', 1_000_000, 999.9),
                new FuelauOptimizerNode('destination', 1_500_000),
            ],
            new FuelauOptimizerVehicle(60, 60, 5, 10),
            new FuelauOptimizerPolicy(
                minimumDiscretionaryPurchaseL: 0,
                minimumNetSavingCents: 0,
            ),
        );

        self::assertSame(60.0, $adjusted->effectiveFuelCapacityL);
        self::assertSame(
            ['expensive-1', 'expensive-2'],
            array_map(
                static fn(FuelauOptimizerPurchase $purchase): string => $purchase->nodeId,
                $adjusted->plan->purchases,
            ),
        );
    }

    public function testAuxiliaryFuelBridgesOnlyStationlessGap(): void
    {
        $nodes = [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station('before-gap', 500_000, 200),
            FuelauOptimizerNode::station('after-gap', 1_300_000, 200),
            FuelauOptimizerNode::station('expensive-reachable', 1_800_000, 999.9),
            new FuelauOptimizerNode('destination', 2_300_000),
        ];
        $vehicle = new FuelauOptimizerVehicle(60, 60, 5, 10);
        $adjusted = (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            $nodes,
            $vehicle,
            new FuelauOptimizerPolicy(
                minimumDiscretionaryPurchaseL: 0,
                minimumNetSavingCents: 0,
            ),
        );

        self::assertSame(85.0, $adjusted->effectiveFuelCapacityL);
        self::assertContains(
            'expensive-reachable',
            array_map(
                static fn(FuelauOptimizerPurchase $purchase): string => $purchase->nodeId,
                $adjusted->plan->purchases,
            ),
        );
        self::assertSame(
            ['before-gap'],
            array_values(array_map(
                static fn(FuelauOptimizerPurchase $purchase): string => $purchase->nodeId,
                array_filter(
                    $adjusted->plan->purchases,
                    static fn(FuelauOptimizerPurchase $purchase): bool =>
                        $purchase->departureFuelL > $vehicle->tankCapacityL,
                ),
            )),
        );

        $this->expectException(FuelauRouteInfeasibleException::class);
        $this->expectExceptionMessage(
            'The configured stop limit is below the minimum feasible stop count.',
        );
        (new FuelauAdditionalFuelOptimizer())->optimizePractical(
            $nodes,
            $vehicle,
            new FuelauOptimizerPolicy(
                maximumFuelOnlyStops: 2,
                minimumDiscretionaryPurchaseL: 0,
                minimumNetSavingCents: 0,
            ),
        );
    }

    public function testResponseExplainsRequiredSmallPurchaseOverrides(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0],
            'destinations' => [['lat' => -30.0, 'lon' => 156.0]],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 12,
                'economy_l_per_100km' => 10,
                'reserve_l' => 6,
            ],
        ]);
        $corridor = new FuelauRouteCorridor(
            distanceM: 600_000,
            durationS: 21_600,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 156.0],
            ],
        );
        $result = (new FuelauSingleCorridorPlanner())->plan(
            $request,
            $corridor,
            [
                [
                    ...$this->validatedStationRow(
                        'early-safety',
                        150.5,
                        200,
                        '2026-07-30T00:00:00Z',
                    ),
                    'fuel_code' => 'DL',
                ],
                [
                    ...$this->validatedStationRow(
                        'late-safety',
                        155.5,
                        200,
                        '2026-07-30T00:00:00Z',
                    ),
                    'fuel_code' => 'DL',
                ],
            ],
        );
        $warnings = $result->toResponseArray()['warnings'];

        self::assertCount(1, array_filter(
            $warnings,
            static fn(string $warning): bool => str_contains($warning, 'preferred minimum'),
        ));
    }

    public function testSelectedStationRequiresMeasuredRoadAccess(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0],
            'destinations' => [['lat' => -30.0, 'lon' => 151.0]],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'E10',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 12,
                'economy_l_per_100km' => 10,
                'reserve_l' => 6,
            ],
        ]);
        $corridor = new FuelauRouteCorridor(
            distanceM: 100_000,
            durationS: 3_600,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 151.0],
            ],
        );

        $this->expectException(FuelauRoutePlanValidationException::class);

        (new FuelauSingleCorridorPlanner())->plan(
            $request,
            $corridor,
            [[
                ...$this->stationRow('estimated', 150.5, 180),
                'price_status' => 'fresh',
            ]],
        );
    }

    public function testMeasuredCandidateBeyondSafetyDetourIsExcludedBeforeSearch(): void
    {
        $request = $this->optimizationRequest(['maximum_fuel_only_stops' => 3]);
        $corridor = new FuelauRouteCorridor(
            distanceM: 600_000,
            durationS: 21_600,
            geometry: [
                ['lat' => -30.0, 'lon' => 150.0],
                ['lat' => -30.0, 'lon' => 156.0],
            ],
        );
        $unsafe = [
            ...$this->validatedStationRow(
                'unsafe-cheap',
                153.0,
                100,
                '2026-07-30T00:00:00Z',
            ),
            'access_distance_m' => 80_000,
        ];
        $result = (new FuelauSingleCorridorPlanner())->plan(
            $request,
            $corridor,
            [
                $this->validatedStationRow(
                    'on-route-early',
                    150.5,
                    200,
                    '2026-07-30T00:00:00Z',
                ),
                $unsafe,
                $this->validatedStationRow(
                    'on-route-late',
                    155.0,
                    200,
                    '2026-07-30T00:00:00Z',
                ),
            ],
        );

        self::assertSame(2, $result->input->eligibleCandidateCount);
        self::assertArrayNotHasKey(
            'station:nsw:NSW:unsafe-cheap:E10',
            $result->input->candidatesByNodeId,
        );
    }

    public function testLivePlannerOrchestratesBoundedDependencies(): void
    {
        $request = $this->optimizationRequest(['maximum_fuel_only_stops' => 3]);
        $routeLoaderCalls = 0;
        $candidateLoaderCalls = 0;
        $tableLoaderCalls = 0;
        $route = [
            'distance' => 600_000,
            'duration' => 21_600,
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [[150.0, -30.0], [156.0, -30.0]],
            ],
        ];
        $candidateRows = [
            $this->stationRow('required', 150.5, 200, 'Required')
                + ['updated_at' => '2026-07-29T00:00:00Z'],
            $this->stationRow('strategic', 153.0, 100, 'Strategic')
                + ['updated_at' => '2026-07-30T00:00:00Z'],
            $this->stationRow('fallback', 155.5, 300, 'Fallback')
                + ['updated_at' => '2026-07-30T00:00:00Z'],
        ];
        $planner = new FuelauLiveSingleCorridorPlanner(
            routeLoader: static function (array $coordinates) use (&$routeLoaderCalls, $route): array {
                $routeLoaderCalls++;
                self::assertContains(count($coordinates), [2, 4]);

                return $route;
            },
            candidateLoader: static function (
                array $points,
                string $fuel,
            ) use (&$candidateLoaderCalls, $candidateRows): array {
                $candidateLoaderCalls++;
                self::assertCount(13, $points);
                self::assertSame('E10', $fuel);

                return $candidateRows;
            },
            tableLoader: static function (array $coordinates) use (&$tableLoaderCalls): array {
                $tableLoaderCalls++;
                $count = count($coordinates);
                $distances = array_fill(0, $count, array_fill(0, $count, null));
                $durations = array_fill(0, $count, array_fill(0, $count, null));
                for ($index = 0; $index < $count; $index++) {
                    $distances[$index][$index] = 0;
                    $durations[$index][$index] = 0;
                }
                for ($index = 0; $index < $count; $index += 3) {
                    $distances[$index][$index + 1] = 0;
                    $distances[$index + 1][$index + 2] = 0;
                    $distances[$index][$index + 2] = 0;
                    $durations[$index][$index + 1] = 0;
                    $durations[$index + 1][$index + 2] = 0;
                    $durations[$index][$index + 2] = 0;
                }

                return ['distances' => $distances, 'durations' => $durations];
            },
            clock: static fn(): DateTimeImmutable =>
                new DateTimeImmutable('2026-07-30T00:00:00Z'),
        );

        $response = $planner->plan($request);

        self::assertSame(2, $routeLoaderCalls);
        self::assertSame(1, $candidateLoaderCalls);
        self::assertSame(1, $tableLoaderCalls);
        self::assertCount(2, $response['stops']);
        self::assertSame(600_000, $response['summary']['route_distance_m']);
        self::assertSame(26_800, $response['summary']['generalized_cost_cents']);
        self::assertCount(1, $response['route_pieces']);
    }

    public function testLivePlannerSkipsStationDependenciesWhenNoFuelStopIsNeeded(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0],
            'destinations' => [['lat' => -30.0, 'lon' => 150.3]],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 80,
                'starting_fuel_l' => 50,
                'economy_l_per_100km' => 10,
                'reserve_l' => 10,
            ],
        ]);
        $candidateCalls = 0;
        $tableCalls = 0;
        $planner = new FuelauLiveSingleCorridorPlanner(
            routeLoader: static fn(array $coordinates): array => [
                'distance' => 40_000,
                'duration' => 2_400,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[150.0, -30.0], [150.3, -30.0]],
                ],
            ],
            candidateLoader: static function () use (&$candidateCalls): array {
                $candidateCalls++;
                return [];
            },
            tableLoader: static function () use (&$tableCalls): array {
                $tableCalls++;
                return [];
            },
        );

        $response = $planner->plan($request);

        self::assertSame(0, $candidateCalls);
        self::assertSame(0, $tableCalls);
        self::assertSame(1, $response['diagnostics']['osrm_route_request_count']);
        self::assertSame(0, $response['diagnostics']['raw_candidate_count']);
        self::assertCount(0, $response['stops']);
    }

    public function testLiveRoutePlannerOptimizesDirectReturnAsOneItinerary(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0],
            'destinations' => [['lat' => -30.0, 'lon' => 153.0]],
            'return_mode' => 'direct',
            'fuel' => [
                'type' => 'E10',
                'tank_capacity_l' => 70,
                'starting_fuel_l' => 35,
                'economy_l_per_100km' => 10,
                'reserve_l' => 5,
            ],
            'preferences' => [
                'maximum_fuel_only_stops' => 3,
                'minimum_discretionary_purchase_l' => 5,
            ],
        ]);
        $routeCalls = 0;
        $candidateCalls = 0;
        $tableCalls = 0;
        $planner = new FuelauLiveRoutePlanner(
            routeLoader: static function (array $coordinates) use (&$routeCalls): array {
                $routeCalls++;

                return [
                    'distance' => count($coordinates) === 2 ? 300_000 : 600_000,
                    'duration' => count($coordinates) === 2 ? 10_800 : 21_600,
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => array_map(
                            static fn(array $coordinate): array => [
                                $coordinate['lon'],
                                $coordinate['lat'],
                            ],
                            $coordinates,
                        ),
                    ],
                ];
            },
            candidateLoader: static function () use (&$candidateCalls): array {
                $candidateCalls++;

                return [[
                    'source' => 'nsw',
                    'state' => 'NSW',
                    'station_id' => 'round-trip-cheap',
                    'station_name' => 'Round Trip Cheap',
                    'fuel_code' => 'E10',
                    'latitude' => -30.0,
                    'longitude' => 152.5,
                    'price' => 100,
                    'updated_at' => '2026-07-30T00:00:00Z',
                ]];
            },
            tableLoader: static function (array $coordinates) use (&$tableCalls): array {
                $tableCalls++;
                $count = count($coordinates);

                return [
                    'distances' => array_fill(0, $count, array_fill(0, $count, 0)),
                    'durations' => array_fill(0, $count, array_fill(0, $count, 0)),
                ];
            },
            clock: static fn(): DateTimeImmutable =>
                new DateTimeImmutable('2026-07-30T00:00:00Z'),
        );

        $response = $planner->plan($request);

        self::assertSame(3, $routeCalls);
        self::assertSame(2, $candidateCalls);
        self::assertSame(2, $tableCalls);
        self::assertSame(2, $response['itinerary']['leg_count']);
        self::assertCount(1, $response['stops']);
        self::assertSame(30.0, $response['stops'][0]['purchase_l']);
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

    /**
     * @param array<string, mixed> $preferences
     */
    private function optimizationRequest(array $preferences = []): FuelauRouteOptimizationRequest
    {
        return FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0, 'label' => 'Origin'],
            'destinations' => [
                ['lat' => -30.0, 'lon' => 156.0, 'label' => 'Destination'],
            ],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'E10',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 12,
                'economy_l_per_100km' => 10,
                'reserve_l' => 6,
            ],
            'preferences' => $preferences,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedStationRow(
        string $stationId,
        float $longitude,
        float $price,
        string $updatedAt,
    ): array {
        return [
            ...$this->stationRow($stationId, $longitude, $price, ucfirst($stationId)),
            'updated_at' => $updatedAt,
            'price_status' => 'fresh',
            'access_distance_m' => 0,
            'access_duration_s' => 0,
        ];
    }
}
