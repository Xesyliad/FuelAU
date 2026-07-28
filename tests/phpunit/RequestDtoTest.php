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
