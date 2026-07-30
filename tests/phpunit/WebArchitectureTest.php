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

    public function testRoutePlannerExposesVersionOneVehicleInputsBehindFeatureFlag(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($script);
        self::assertStringContainsString('for="route-tank-capacity">Tank Capacity (L)', $template);
        self::assertStringContainsString('id="route-starting-fuel"', $template);
        self::assertStringContainsString('id="route-fuel-reserve"', $template);
        self::assertStringContainsString('id="route-optimization-mode"', $template);
        self::assertStringNotContainsString('>Fuel Fill (L)<', $template);
        self::assertStringContainsString("'routeOptimizerV2Enabled' =>", $template);
        self::assertStringContainsString('startingFuel: routeStartingFuel.value.trim()', $script);
        self::assertStringContainsString('fuelReserve: routeFuelReserve.value.trim()', $script);
        self::assertStringContainsString('Starting fuel must be between zero and tank capacity.', $script);
    }

    public function testVersionOneDefaultUsesBackendCompleteItineraryPlanner(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($script);
        self::assertStringContainsString("apiRequest('/api/route/optimize'", $script);
        self::assertStringContainsString('const plan = routeOptimizerV2Default', $script);
        self::assertStringContainsString(
            'destinations: destinations.map(routeOptimizerLocation)',
            $script,
        );
        self::assertStringContainsString('starting_fuel_l: startingFuelL', $script);
        self::assertStringContainsString('reserve_l: reserveL', $script);
        self::assertStringContainsString(
            'Physical stop; fatigue spacing restarts here',
            $script,
        );
    }
}
