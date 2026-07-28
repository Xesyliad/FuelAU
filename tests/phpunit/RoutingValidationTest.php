<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RoutingValidationTest extends TestCase
{
    public function testCoordinateBoundsAreValidated(): void
    {
        $this->expectException(FuelauValidationException::class);
        fuelauParseCoordinates('181,-27;153,-27');
    }

    public function testNominatimLimitIsClamped(): void
    {
        $results = array_map(
            static fn(int $id): array => ['id' => $id],
            range(1, 60),
        );

        self::assertCount(50, fuelauLimitNominatimResults($results, 1000));
        self::assertCount(1, fuelauLimitNominatimResults($results, 0));
    }

    public function testNominatimRetriesTransientAndMalformedResponses(): void
    {
        self::assertTrue(fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('Invalid JSON response from http://nominatim:8080/search'),
        ));
        self::assertTrue(fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('HTTP 500 from http://nominatim:8080/search: query cancelled'),
        ));
        self::assertTrue(fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('HTTP 503 from http://nominatim:8080/search: unavailable'),
        ));
        self::assertFalse(fuelauNominatimShouldRetrySearch(
            new FuelauUpstreamException('HTTP 400 from http://nominatim:8080/search: invalid query'),
        ));
    }

    public function testEmptySnapshotCacheLoaderRunsOnce(): void
    {
        $directory = sys_get_temp_dir() . '/fuelau-phpunit-' . bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $calls = 0;

        try {
            $loader = static function () use (&$calls): array {
                $calls++;
                return [];
            };
            fuelauRememberArray($directory . '/cache.json', 60, $loader);
            fuelauRememberArray($directory . '/cache.json', 60, $loader);
            self::assertSame(1, $calls);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
