<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FuelDataTest extends TestCase
{
    public function testRouteFuelSelectorOnlyExposesCanonicalGroups(): void
    {
        self::assertSame(
            [
                'Unleaded',
                'Premium Unleaded 95',
                'Premium Unleaded 98',
                'Cheapest Unleaded',
                'Diesel',
                'Premium Diesel',
                'Cheapest Diesel',
                'LPG',
                'CNG/NGV',
                'LNG',
                'Hydrogen',
            ],
            array_column(fuelauRouteFuelProfiles(), 'label'),
        );
        self::assertNotContains('EV charge', array_column(fuelauRouteFuelProfiles(), 'label'));
    }

    public function testEveryKnownAustralianProductIsExplicitlyClassified(): void
    {
        $knownProducts = [
            'qld' => ['2', '3', '4', '5', '6', '8', '11', '12', '13', '14', '16', '19', '21', '22', '23', '999', '1000'],
            'sa' => ['2', '3', '4', '5', '6', '8', '11', '12', '13', '14', '16', '19', '21', '22', '23', '999', '1000'],
            'nsw' => ['B20', 'CNG', 'DL', 'DL-PDL', 'E10', 'E10-U91', 'E85', 'EV', 'H2', 'LNG', 'LPG', 'P95', 'P95-P98', 'P98', 'PDL', 'U91'],
            'vic' => ['B20', 'CNG', 'DSL', 'E10', 'E85', 'LNG', 'LPG', 'P95', 'P98', 'PDSL', 'U91'],
            'wa' => ['1', '2', '4', '5', '6', '10', '11'],
            'nt' => ['B20', 'DL', 'E10', 'E85', 'LAF', 'LPG', 'P95', 'P98', 'PD', 'U91'],
        ];
        $registry = fuelauAustralianFuelProductRegistry();

        foreach ($knownProducts as $source => $codes) {
            $registeredCodes = array_map('strval', array_keys($registry[$source]));
            sort($codes, SORT_STRING);
            sort($registeredCodes, SORT_STRING);
            self::assertSame($codes, $registeredCodes, "Unexpected {$source} fuel classification");
        }
        self::assertTrue($registry['nsw']['EV']['route_excluded']);
        self::assertSame('electric', $registry['nsw']['EV']['class']);
    }

    public function testUnleadedQualityProfilesExcludeEveryEthanolAndSpecialistProduct(): void
    {
        $unleaded = fuelauRouteFuelCodesBySource('unleaded_91_plus');
        self::assertSame(['2', '5', '8'], $unleaded['qld']);
        self::assertSame(['U91', 'P95', 'P95-P98', 'P98'], $unleaded['nsw']);
        self::assertSame(['U91', 'P95', 'P98'], $unleaded['vic']);
        self::assertSame(['1', '2', '6'], $unleaded['wa']);
        self::assertSame(['U91', 'P95', 'P98'], $unleaded['nt']);

        $premium95 = fuelauRouteFuelCodesBySource('premium_unleaded_95_plus');
        self::assertSame(['5', '8'], $premium95['qld']);
        self::assertSame(['P95', 'P95-P98', 'P98'], $premium95['nsw']);
        self::assertSame(['2', '6'], $premium95['wa']);

        $premium98 = fuelauRouteFuelCodesBySource('premium_unleaded_98');
        self::assertSame(['8'], $premium98['qld']);
        self::assertSame(['P98'], $premium98['nsw']);
        self::assertSame(['6'], $premium98['wa']);
    }

    public function testCheapestProfilesIncludeBlendsWithoutCrossingFuelClasses(): void
    {
        $registry = fuelauAustralianFuelProductRegistry();
        foreach (['cheapest_unleaded' => 'petrol', 'cheapest_diesel' => 'diesel'] as $profile => $expectedClass) {
            $codesBySource = fuelauRouteFuelCodesBySource($profile);
            foreach ($codesBySource as $source => $codes) {
                foreach ($codes as $code) {
                    self::assertSame($expectedClass, $registry[$source][$code]['class']);
                }
            }
        }

        self::assertContains('12', fuelauRouteFuelCodesBySource('cheapest_unleaded')['qld']);
        self::assertContains('13', fuelauRouteFuelCodesBySource('cheapest_unleaded')['qld']);
        self::assertContains('E85', fuelauRouteFuelCodesBySource('cheapest_unleaded')['nsw']);
        self::assertContains('B20', fuelauRouteFuelCodesBySource('cheapest_diesel')['nsw']);
        self::assertNotContains('B20', fuelauRouteFuelCodesBySource('diesel')['nsw']);
        self::assertNotContains('DL', fuelauRouteFuelCodesBySource('premium_diesel')['nsw']);
        self::assertContains('PDL', fuelauRouteFuelCodesBySource('premium_diesel')['nsw']);
    }

    public function testNonPetroleumRouteGroupsRemainIsolated(): void
    {
        $registry = fuelauAustralianFuelProductRegistry();
        foreach (['lpg', 'cng', 'lng', 'hydrogen'] as $profile) {
            foreach (fuelauRouteFuelCodesBySource($profile) as $source => $codes) {
                foreach ($codes as $code) {
                    self::assertSame($profile, $registry[$source][$code]['class']);
                }
            }
        }
        self::assertSame(['4'], fuelauRouteFuelCodesBySource('lpg')['qld']);
        self::assertSame(['LPG'], fuelauRouteFuelCodesBySource('lpg')['nsw']);
        self::assertArrayNotHasKey('electric', fuelauRouteFuelCodesBySource('electric'));
    }

    public function testNewUpstreamProductsFailTheClassificationAudit(): void
    {
        self::assertSame([], fuelauUnclassifiedRouteFuelOptions([
            ['source' => 'qld', 'state' => 'QLD', 'fuel_code' => '2', 'fuel_name' => 'Unleaded'],
            ['source' => 'nsw', 'state' => 'NSW', 'fuel_code' => 'EV', 'fuel_name' => 'EV charge'],
        ]));
        self::assertSame(
            [[
                'source' => 'nsw',
                'state' => 'NSW',
                'fuel_code' => 'NEW',
                'fuel_name' => 'New fuel',
            ]],
            fuelauUnclassifiedRouteFuelOptions([
                ['source' => 'nsw', 'state' => 'NSW', 'fuel_code' => 'NEW', 'fuel_name' => 'New fuel'],
            ]),
        );
    }

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

    public function testCoverageWindowsAreMergedRoundRobinWithStableDeduplication(): void
    {
        $row = static fn(string $source, string $state, string $stationId): array => [
            'source' => $source,
            'state' => $state,
            'station_id' => $stationId,
            'fuel_code' => 'E10',
        ];
        $rows = fuelauMergeCoverageCandidateWindows([
            [$row('nsw', 'NSW', 'a'), $row('nsw', 'NSW', 'b'), $row('nsw', 'NSW', 'c')],
            [$row('nsw', 'NSW', 'a'), $row('nsw', 'NSW', 'd')],
            [$row('qld', 'QLD', 'e'), $row('qld', 'QLD', 'f')],
        ], 4);

        self::assertSame(['a', 'd', 'e', 'b'], array_column($rows, 'station_id'));
    }
}
