<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebArchitectureTest extends TestCase
{
    public function testPublicEntryPointOnlyBootstrapsAndDispatches(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        self::assertIsString($source);
        self::assertStringContainsString("require dirname(__DIR__) . '/src/web.php';", $source);
        self::assertStringContainsString('fuelauRunWebApplication();', $source);
        self::assertStringNotContainsString('<html', $source);
        self::assertStringNotContainsString('/api/', $source);
    }

    public function testPageTemplateReferencesExtractedAssets(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');

        self::assertIsString($source);
        self::assertStringContainsString('/resources/app.css', $source);
        self::assertStringContainsString('/resources/app.js', $source);
        self::assertStringContainsString('appCssVersion', $source);
        self::assertStringContainsString('appJsVersion', $source);
        self::assertStringContainsString('/favicon.svg', $source);
        self::assertFileExists(dirname(__DIR__, 2) . '/public/favicon.svg');
    }

    public function testFuelMapViewportRefreshIsGuarded(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('function fuelPricesTabIsActive()', $source);
        self::assertStringContainsString('requestKey === fuelMapViewportLastRequestKey', $source);
        self::assertStringContainsString('if (!preserveViewport)', $source);
        self::assertStringContainsString("error?.name === 'AbortError'", $source);
        self::assertStringContainsString('destroyFuelMap();', $source);
    }

    public function testDashboardFiltersStaleAndImplausiblePrices(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('return routeFuelPriceIsReasonable(value);', $source);
        self::assertStringContainsString('routeFuelPriceIsFresh(row?.updated_at)', $source);
    }
}
