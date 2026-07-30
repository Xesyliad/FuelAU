<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RequestDtoTest extends TestCase
{
    public function testGeoSearchRequestNormalizesAndClampsInput(): void
    {
        $request = FuelauGeoSearchRequest::fromQuery([
            'q' => '  Brisbane   City  ',
            'limit' => '500',
        ]);

        self::assertSame('Brisbane City', $request->query);
        self::assertSame(50, $request->limit);
    }

    public function testCoordinateRequestRejectsOutOfRangeValues(): void
    {
        $this->expectException(FuelauValidationException::class);
        FuelauCoordinateRequest::fromQuery([
            'lat' => '-91',
            'lon' => '153',
        ]);
    }

    public function testRouteRequestProducesTypedCoordinatesAndSteps(): void
    {
        $request = FuelauRouteRequest::fromQuery([
            'coordinates' => '153,-27;153.1,-27.1',
            'steps' => '0',
        ]);

        self::assertSame([
            ['lon' => 153.0, 'lat' => -27.0],
            ['lon' => 153.1, 'lat' => -27.1],
        ], $request->coordinates);
        self::assertFalse($request->steps);
    }

    public function testRouteCandidateRequestNormalizesAndClampsInput(): void
    {
        $request = FuelauRouteCandidateRequest::fromBody([
            'points' => [
                ['lat' => '-27.5', 'lon' => '153.0'],
            ],
            'fuel' => '  U91 ',
            'radius_km' => 500,
            'limit' => 999999,
        ]);

        self::assertSame([['lat' => -27.5, 'lon' => 153.0]], $request->points);
        self::assertSame('U91', $request->fuel);
        self::assertSame(100.0, $request->radiusKm);
        self::assertSame(5000, $request->limit);
    }

    public function testRouteOptimizationRequestResolvesDocumentedDefaults(): void
    {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => [
                'lat' => -27.4698,
                'lon' => 153.0251,
                'label' => 'Brisbane',
            ],
            'destinations' => [[
                'lat' => -16.9186,
                'lon' => 145.7781,
                'label' => 'Cairns',
            ]],
            'return_mode' => 'one_way',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 60,
                'economy_l_per_100km' => 10,
                'reserve_l' => 6,
            ],
            'preferences' => [],
        ]);

        self::assertSame(1, $request->version);
        self::assertSame('one_way', $request->returnMode);
        self::assertSame('Brisbane', $request->origin->label);
        self::assertTrue($request->destinations[0]->physicalStop);
        self::assertSame(60.0, $request->fuel->tankCapacityL);
        self::assertSame(60.0, $request->fuel->startingFuelL);
        self::assertSame(6.0, $request->fuel->reserveL);
        self::assertSame('practical_least_cost', $request->preferences->mode);
        self::assertNull($request->preferences->maximumFuelOnlyStops);
        self::assertNull($request->preferences->minimumDiscretionaryPurchaseL);
        self::assertSame(150.0, $request->preferences->minimumStopSpacingKm);
        self::assertSame(90.0, $request->preferences->minimumStopSpacingMinutes);
        self::assertSame(1000, $request->preferences->minimumNetSavingCents);
        self::assertSame(3000, $request->preferences->driverTimeValueCentsPerHour);
    }

    public function testDirectAndReverseItinerarySemanticsAreExpandedInOrder(): void
    {
        $body = [
            'version' => 1,
            'origin' => ['lat' => -30.0, 'lon' => 150.0, 'label' => 'origin'],
            'destinations' => [
                ['lat' => -31.0, 'lon' => 151.0, 'label' => 'first'],
                ['lat' => -32.0, 'lon' => 152.0, 'label' => 'second'],
                ['lat' => -33.0, 'lon' => 153.0, 'label' => 'third'],
            ],
            'return_mode' => 'direct',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 80,
                'starting_fuel_l' => 50,
                'economy_l_per_100km' => 10,
                'reserve_l' => 10,
            ],
        ];
        $direct = FuelauRouteOptimizationRequest::fromBody($body);
        $reverse = FuelauRouteOptimizationRequest::fromBody([
            ...$body,
            'return_mode' => 'reverse',
        ]);

        self::assertSame(
            ['origin', 'first', 'second', 'third', 'origin'],
            array_map(
                static fn (FuelauRouteOptimizationLocation $location): string => $location->label,
                $direct->itineraryLocations(),
            ),
        );
        self::assertSame(
            ['origin', 'first', 'second', 'third', 'second', 'first', 'origin'],
            array_map(
                static fn (FuelauRouteOptimizationLocation $location): string => $location->label,
                $reverse->itineraryLocations(),
            ),
        );
    }

    public function testRouteOptimizationRequestRejectsStartingFuelAboveCapacity(): void
    {
        $this->expectException(FuelauValidationException::class);
        $this->expectExceptionMessage('starting_fuel_l');

        FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -27.4, 'lon' => 153.0],
            'destinations' => [['lat' => -28.0, 'lon' => 153.0]],
            'return_mode' => 'direct',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 61,
                'economy_l_per_100km' => 12,
                'reserve_l' => 6,
            ],
        ]);
    }

    public function testRouteOptimizationRequestRejectsInvalidMeaningfulStopPreference(): void
    {
        $this->expectException(FuelauValidationException::class);
        $this->expectExceptionMessage('maximum_fuel_only_stops');

        FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -27.4, 'lon' => 153.0],
            'destinations' => [['lat' => -28.0, 'lon' => 153.0]],
            'return_mode' => 'direct',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 60,
                'economy_l_per_100km' => 12,
                'reserve_l' => 6,
            ],
            'preferences' => [
                'maximum_fuel_only_stops' => 21,
            ],
        ]);
    }

    public function testRouteOptimizationRequestRejectsNonBooleanPhysicalStop(): void
    {
        $this->expectException(FuelauValidationException::class);
        $this->expectExceptionMessage('physical_stop');

        FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => ['lat' => -27.4, 'lon' => 153.0],
            'destinations' => [[
                'lat' => -28.0,
                'lon' => 153.0,
                'physical_stop' => 'yes',
            ]],
            'return_mode' => 'direct',
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 60,
                'starting_fuel_l' => 60,
                'economy_l_per_100km' => 12,
                'reserve_l' => 6,
            ],
        ]);
    }

    public function testFuelFilterRequestKeepsTypedBoundaryAndLegacyAdapter(): void
    {
        $request = FuelauFuelFilterRequest::current([
            'state' => 'vic',
            'limit' => '25',
        ]);

        self::assertSame('vic', $request->source);
        self::assertSame('VIC', $request->state);
        self::assertSame(25, $request->limit);
        self::assertSame('vic', $request->toCurrentFilters()['source']);
    }

    public function testHttpRequestRejectsNonObjectJson(): void
    {
        $request = new FuelauHttpRequest(
            path: '/api/fuel/route-candidates',
            method: 'POST',
            query: [],
            rawBody: '[]',
        );

        $this->expectException(FuelauValidationException::class);
        $request->jsonObject();
    }
}
