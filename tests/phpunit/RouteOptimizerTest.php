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
}
