<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RouteOptimizerTest extends TestCase
{
    public function testTerminalPurchaseEndsAtReserveInsteadOfFillingTank(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimize(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('station', 500_000, 150),
                new FuelauOptimizerNode('destination', 700_000),
            ],
            new FuelauOptimizerVehicle(60, 60, 6, 10),
        );

        self::assertSame(2400, $plan->fuelPurchaseCostCents);
        self::assertSame(16.0, $plan->purchases[0]->purchaseL);
        self::assertSame(6.0, $plan->endingFuelL);
    }

    public function testExpensiveStationOnlyBridgesVehicleToCheaperStation(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimize(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('expensive', 50_000, 200),
                FuelauOptimizerNode::station('cheap', 400_000, 150),
                new FuelauOptimizerNode('destination', 600_000),
            ],
            new FuelauOptimizerVehicle(60, 12, 6, 10),
        );

        self::assertSame(9800, $plan->fuelPurchaseCostCents);
        self::assertSame(34.0, $plan->purchases[0]->purchaseL);
        self::assertSame(20.0, $plan->purchases[1]->purchaseL);
    }

    public function testUnbridgeableGapIsInfeasible(): void
    {
        $this->expectException(FuelauRouteInfeasibleException::class);

        (new FuelauFuelStateOptimizer())->optimize(
            [
                new FuelauOptimizerNode('origin', 0),
                new FuelauOptimizerNode('destination', 700_000),
            ],
            new FuelauOptimizerVehicle(60, 60, 6, 10),
        );
    }

    public function testStationAccessDistanceConsumesFuel(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimize(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station(
                    'detour',
                    50_000,
                    150,
                    accessDistanceM: 10_000,
                ),
                new FuelauOptimizerNode('destination', 100_000),
            ],
            new FuelauOptimizerVehicle(60, 12, 6, 10),
        );

        self::assertSame(6.0, $plan->purchases[0]->purchaseL);
        self::assertSame(20_000, $plan->purchases[0]->detourDistanceM);
        self::assertSame(6.0, $plan->endingFuelL);
    }

    public function testStationIsNotVisitedWhenNoFuelIsPurchased(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimize(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station(
                    'unneeded',
                    50_000,
                    100,
                    accessDistanceM: 10_000,
                ),
                new FuelauOptimizerNode('destination', 100_000),
            ],
            new FuelauOptimizerVehicle(60, 20, 6, 10),
        );

        self::assertSame(0, $plan->fuelStopCount);
        self::assertSame(10.0, $plan->endingFuelL);
    }

    public function testDetourTimeCanOutweighCheaperFuel(): void
    {
        $nodes = [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station(
                'cheap-detour',
                50_000,
                100,
                accessDistanceM: 20_000,
                accessDurationS: 3_600,
            ),
            FuelauOptimizerNode::station('on-route', 60_000, 200),
            new FuelauOptimizerNode('destination', 300_000),
        ];
        $vehicle = new FuelauOptimizerVehicle(60, 20, 6, 10);

        $fuelOnlyPlan = (new FuelauFuelStateOptimizer())->optimize($nodes, $vehicle);
        $practicalPlan = (new FuelauFuelStateOptimizer())->optimizePractical(
            $nodes,
            $vehicle,
            new FuelauOptimizerPolicy(
                maximumFuelOnlyStops: 1,
                minimumDiscretionaryPurchaseL: 0,
                minimumStopSpacingM: 0,
                minimumStopSpacingS: 0,
                minimumNetSavingCents: 0,
                similarCostCents: 0,
            ),
        );

        self::assertSame('cheap-detour', $fuelOnlyPlan->purchases[0]->nodeId);
        self::assertSame('on-route', $practicalPlan->purchases[0]->nodeId);
        self::assertSame(3700, $practicalPlan->generalizedCostCents);
    }

    public function testDiscretionaryDetourLimitRejectsRemoteCheapFuel(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station(
                    'cheap-detour',
                    50_000,
                    100,
                    accessDistanceM: 20_000,
                ),
                FuelauOptimizerNode::station('on-route', 60_000, 200),
                new FuelauOptimizerNode('destination', 300_000),
            ],
            new FuelauOptimizerVehicle(60, 20, 6, 10),
            new FuelauOptimizerPolicy(
                maximumFuelOnlyStops: 1,
                minimumDiscretionaryPurchaseL: 0,
                minimumStopSpacingM: 0,
                minimumStopSpacingS: 0,
                minimumNetSavingCents: 0,
                driverTimeValueCentsPerHour: 0,
                similarCostCents: 0,
            ),
        );

        self::assertSame('on-route', $plan->purchases[0]->nodeId);
    }

    public function testSparseCorridorCanRequireAStopBeyondNormalDetourLimit(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station(
                    'remote-safety',
                    50_000,
                    180,
                    accessDistanceM: 20_000,
                ),
                new FuelauOptimizerNode('destination', 100_000),
            ],
            new FuelauOptimizerVehicle(60, 13, 6, 10),
        );

        self::assertSame(1, $plan->fuelStopCount);
        self::assertSame('required', $plan->purchases[0]->classification);
        self::assertSame(['sparse_corridor'], $plan->purchases[0]->reasonCodes);
        self::assertSame(40_000, $plan->purchases[0]->detourDistanceM);
    }

    public function testSafetyDetourLimitCannotBeBypassed(): void
    {
        $this->expectException(FuelauRouteInfeasibleException::class);

        (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station(
                    'unsafe-detour',
                    50_000,
                    180,
                    accessDistanceM: 40_000,
                ),
                new FuelauOptimizerNode('destination', 100_000),
            ],
            new FuelauOptimizerVehicle(60, 15, 6, 10),
        );
    }

    public function testSpacingIncludesAccessTravelBetweenStops(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station(
                    'first',
                    50_000,
                    200,
                    progressS: 1_800,
                    accessDistanceM: 10_000,
                ),
                FuelauOptimizerNode::station(
                    'second',
                    190_000,
                    150,
                    progressS: 6_840,
                    accessDistanceM: 10_000,
                ),
                new FuelauOptimizerNode('destination', 400_000, progressS: 14_400),
            ],
            new FuelauOptimizerVehicle(30, 12, 6, 10),
        );

        self::assertSame(2, $plan->fuelStopCount);
        self::assertSame(['reserve_feasibility'], $plan->purchases[1]->reasonCodes);
        self::assertSame(20_000, $plan->purchases[1]->detourDistanceM);
    }

    public function testRequiredShortPurchaseBypassesDiscretionaryThreshold(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('safety', 50_000, 150, progressS: 1_800),
                new FuelauOptimizerNode('destination', 100_000, progressS: 3_600),
            ],
            new FuelauOptimizerVehicle(60, 12, 6, 10),
        );

        self::assertSame(4.0, $plan->purchases[0]->purchaseL);
        self::assertSame('required', $plan->purchases[0]->classification);
        self::assertContains('minimum_purchase_safety_override', $plan->purchases[0]->reasonCodes);
    }

    public function testPracticalPolicyDoesNotChaseEverySmallPriceReduction(): void
    {
        $nodes = [new FuelauOptimizerNode('origin', 0)];
        for ($progressKm = 50; $progressKm <= 550; $progressKm += 50) {
            $nodes[] = FuelauOptimizerNode::station(
                "station-{$progressKm}",
                $progressKm * 1000,
                210 - ($progressKm / 50),
                progressS: (int) (($progressKm / 50) * 1_800),
            );
        }
        $nodes[] = new FuelauOptimizerNode('destination', 600_000, progressS: 21_600);

        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            $nodes,
            new FuelauOptimizerVehicle(60, 12, 6, 10),
            new FuelauOptimizerPolicy(maximumFuelOnlyStops: 10),
        );

        self::assertSame(2, $plan->fuelStopCount);
        self::assertSame(0, $plan->discretionaryStopCount);
        foreach (array_slice($plan->purchases, 1) as $index => $purchase) {
            $previous = $plan->purchases[$index];
            $tooClose = ($purchase->progressM - $previous->progressM) < 150_000
                && ($purchase->progressS - $previous->progressS) < 5_400;
            self::assertTrue(!$tooClose || $purchase->classification === 'required');
        }
    }

    public function testStrategicStopReportsTripWideMarginalSaving(): void
    {
        $plan = (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('required', 50_000, 200, progressS: 1_800),
                FuelauOptimizerNode::station('strategic', 300_000, 100, progressS: 10_800),
                FuelauOptimizerNode::station('fallback', 550_000, 300, progressS: 19_800),
                new FuelauOptimizerNode('destination', 600_000, progressS: 21_600),
            ],
            new FuelauOptimizerVehicle(60, 12, 6, 10),
            new FuelauOptimizerPolicy(maximumFuelOnlyStops: 3),
        );

        self::assertSame(2, $plan->fuelStopCount);
        self::assertSame('required', $plan->purchases[0]->classification);
        self::assertSame('strategic', $plan->purchases[1]->classification);
        self::assertSame(3100, $plan->purchases[1]->marginalNetSavingCents);
    }

    public function testStopLimitBelowMinimumFeasibleCountIsRejected(): void
    {
        $this->expectException(FuelauRouteInfeasibleException::class);

        (new FuelauFuelStateOptimizer())->optimizePractical(
            [
                new FuelauOptimizerNode('origin', 0),
                FuelauOptimizerNode::station('first', 50_000, 200),
                FuelauOptimizerNode::station('last', 550_000, 150),
                new FuelauOptimizerNode('destination', 600_000),
            ],
            new FuelauOptimizerVehicle(60, 12, 6, 10),
            new FuelauOptimizerPolicy(maximumFuelOnlyStops: 1),
        );
    }

    public function testSimilarCostPlansPreferFewerFuelStops(): void
    {
        $nodes = [
            new FuelauOptimizerNode('origin', 0),
            FuelauOptimizerNode::station('first', 50_000, 200, progressS: 1_800),
            FuelauOptimizerNode::station('middle', 300_000, 199, progressS: 10_800),
            FuelauOptimizerNode::station('last', 550_000, 198, progressS: 19_800),
            new FuelauOptimizerNode('destination', 600_000, progressS: 21_600),
        ];
        $vehicle = new FuelauOptimizerVehicle(60, 12, 6, 10);
        $commonPolicy = [
            'maximumFuelOnlyStops' => 3,
            'minimumDiscretionaryPurchaseL' => 0,
            'minimumStopSpacingM' => 0,
            'minimumStopSpacingS' => 0,
            'minimumNetSavingCents' => 0,
            'driverTimeValueCentsPerHour' => 0,
            'fuelOnlyStopSeconds' => 0,
        ];
        $optimizer = new FuelauFuelStateOptimizer();
        $strictCostPlan = $optimizer->optimizePractical(
            $nodes,
            $vehicle,
            new FuelauOptimizerPolicy(...$commonPolicy, similarCostCents: 0),
        );
        $similarCostPlan = $optimizer->optimizePractical(
            $nodes,
            $vehicle,
            new FuelauOptimizerPolicy(
                ...$commonPolicy,
                similarCostCents: 500,
            ),
        );

        self::assertSame(3, $strictCostPlan->fuelStopCount);
        self::assertSame(2, $similarCostPlan->fuelStopCount);
        self::assertLessThanOrEqual(
            500,
            $similarCostPlan->generalizedCostCents - $strictCostPlan->generalizedCostCents,
        );
    }
}
