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
