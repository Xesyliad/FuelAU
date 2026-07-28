<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FuelDataTest extends TestCase
{
    public function testHistoricalLocationBoundsEncloseRequestedRadius(): void
    {
        $bounds = fuelauHistoricalLocationBounds([
            'lat' => -27.4698,
            'lon' => 153.0251,
            'radius_km' => 80.0,
        ]);

        self::assertIsArray($bounds);
        self::assertLessThan(-27.4698, $bounds['min_lat']);
        self::assertGreaterThan(-27.4698, $bounds['max_lat']);
        self::assertLessThan(153.0251, $bounds['min_lon']);
        self::assertGreaterThan(153.0251, $bounds['max_lon']);
        self::assertEqualsWithDelta(1.447, $bounds['max_lat'] - $bounds['min_lat'], 0.01);
        self::assertEqualsWithDelta(1.624, $bounds['max_lon'] - $bounds['min_lon'], 0.02);
    }

    public function testEveryHistoricalProviderUsesBoundingBoxPrefilter(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/fuel.php');

        self::assertIsString($source);
        self::assertSame(6, substr_count(
            $source,
            "fuelauApplyHistoricalLocationFilters(\$where, \$filters, 's.latitude', 's.longitude');",
        ));
        self::assertStringContainsString(
            'BETWEEN :history_min_lat AND :history_max_lat',
            $source,
        );
    }

    public function testNumericFuelFilterUsesIdWithoutAnOrCondition(): void
    {
        self::assertSame(
            'h.fuel_id = :fuel',
            fuelauNumericFuelFilterCondition(['fuel' => '3'], 'h.fuel_id', 'f.name'),
        );
        self::assertSame(
            'f.name = :fuel',
            fuelauNumericFuelFilterCondition(['fuel' => 'Diesel'], 'h.fuel_id', 'f.name'),
        );
    }
}
