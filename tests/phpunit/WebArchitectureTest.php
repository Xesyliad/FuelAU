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

    public function testThemeSelectorSupportsPersistedSystemLightAndDarkModes(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.css');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($stylesheet);
        self::assertIsString($script);
        self::assertStringContainsString("const themeKey = 'fuelau_theme_v1';", $template);
        self::assertStringContainsString('document.documentElement.dataset.theme = preference;', $template);
        self::assertStringContainsString('data-theme-preference="system"', $template);
        self::assertStringContainsString('data-theme-preference="light"', $template);
        self::assertStringContainsString('data-theme-preference="dark"', $template);
        $themeBootstrapPosition = strpos($template, "const themeKey = 'fuelau_theme_v1';");
        $stylesheetPosition = strpos($template, 'href="/resources/app.css');
        self::assertIsInt($themeBootstrapPosition);
        self::assertIsInt($stylesheetPosition);
        self::assertLessThan($stylesheetPosition, $themeBootstrapPosition);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $stylesheet);
        self::assertStringContainsString(':root[data-theme="dark"]', $stylesheet);
        self::assertStringContainsString("window.localStorage.setItem(themePreferenceKey", $script);
        self::assertStringContainsString("systemDarkTheme.addEventListener('change'", $script);
        self::assertStringContainsString("option.setAttribute(\n            'aria-pressed'", $script);
        self::assertStringContainsString('const fuelauDarkMapPaint = {', $script);
        self::assertStringContainsString('function fuelauApplyMapTheme(map)', $script);
        self::assertStringContainsString('style = map?.getStyle?.();', $script);
        self::assertStringContainsString('!Array.isArray(style.layers)', $script);
        self::assertStringContainsString('map.setPaintProperty(', $script);
        self::assertStringContainsString(
            "document.addEventListener('fuelau:themechange', fuelauApplyThemeToActiveMaps);",
            $script,
        );
        self::assertStringContainsString('fuelauApplyMapTheme(fuelMapInstance);', $script);
        self::assertStringNotContainsString('.setStyle(', $script);
    }

    public function testFuelMapViewportRefreshIsGuardedOnPersistentSharedMap(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('function fuelPricesTabIsActive()', $source);
        self::assertStringContainsString('requestKey === fuelMapViewportLastRequestKey', $source);
        self::assertStringContainsString('if (!preserveViewport)', $source);
        self::assertStringContainsString("error?.name === 'AbortError'", $source);
        self::assertSame(1, substr_count($source, 'new maplibregl.Map('));
        self::assertStringContainsString('function syncSharedMapWorkflow(workflow)', $source);
        self::assertStringContainsString("'fuelau-stop-finder-lines'", $source);
        self::assertStringContainsString("'fuelau-route-planner-lines'", $source);
        self::assertStringNotContainsString('function destroyFuelMap()', $source);
        self::assertStringContainsString('function syncMapFirstShell(tab, panelId)', $source);
        self::assertStringContainsString('scheduleMapResize(fuelMapInstance);', $source);
    }

    public function testPageUsesAMapFirstResponsiveApplicationShell(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.css');

        self::assertIsString($template);
        self::assertIsString($stylesheet);
        self::assertStringContainsString('class="map-stage"', $template);
        self::assertStringContainsString('data-map-view="shared"', $template);
        self::assertStringContainsString('class="tabs tool-navigation"', $template);
        self::assertStringContainsString('class="workspace-sheet"', $template);
        self::assertStringContainsString('id="workspace-sheet-toggle"', $template);
        self::assertStringContainsString('id="workspace-sheet-reopen"', $template);
        self::assertSame(1, substr_count($template, 'id="fuel-map"'));
        self::assertSame(0, substr_count($template, 'id="fuel-stop-finder-map"'));
        self::assertSame(0, substr_count($template, 'id="route-map"'));
        self::assertSame(1, substr_count($template, 'id="map-status-overlay"'));
        self::assertStringContainsString('class="panel-disclosure insights-disclosure" open', $template);
        self::assertStringContainsString('class="panel-disclosure state-summary-disclosure"', $template);
        self::assertStringContainsString('id="fuel-station-detail"', $template);
        self::assertStringContainsString('class="fuel-toolbar fuel-filter-bar"', $template);
        self::assertStringContainsString('class="field fuel-filter-chip fuel-filter-region"', $template);
        self::assertStringContainsString('class="button primary fuel-refresh-button"', $template);
        $insightsPosition = strpos($template, 'class="panel-disclosure insights-disclosure"');
        $stateSummaryPosition = strpos($template, 'class="panel-disclosure state-summary-disclosure"');
        self::assertIsInt($insightsPosition);
        self::assertIsInt($stateSummaryPosition);
        self::assertLessThan($stateSummaryPosition, $insightsPosition);
        self::assertStringContainsString('/* Map-first application shell */', $stylesheet);
        self::assertStringContainsString('height: 100dvh;', $stylesheet);
        self::assertStringContainsString('@media (max-width: 760px)', $stylesheet);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $stylesheet);
        self::assertStringContainsString('.fuel-station-brand-badge', $stylesheet);
        self::assertStringContainsString('.snapshot-station-select', $stylesheet);
        self::assertStringContainsString('.workspace-sheet .fuel-filter-bar', $stylesheet);
    }

    public function testExplorePricesUsesBrandAwareSelectableStationMarkers(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('const fuelBrandRegistry = {', $source);
        self::assertStringContainsString('function registerFuelBrandImages(map)', $source);
        self::assertStringContainsString("id: 'fuelau-prices-stations-brand'", $source);
        self::assertStringContainsString("id: 'fuelau-prices-selection-ring'", $source);
        self::assertStringContainsString('brand_name: String(row.brand_name || \'\')', $source);
        self::assertStringContainsString('function selectFuelStation(properties, coordinates, focusMap = false)', $source);
        self::assertStringContainsString('data-snapshot-index=', $source);
        self::assertStringContainsString('function splitRoutePointsByItineraryLeg(routePoints, itineraryTargets)', $source);
        self::assertStringContainsString('routeLegColor(stopLegNumber - 1)', $source);
        self::assertStringContainsString('class="route-fuel-marker-sequence"', $source);
        self::assertStringContainsString('function routeFuelWazeUrl(candidate)', $source);
        self::assertStringContainsString("new URL('https://www.waze.com/ul')", $source);
        self::assertStringContainsString("url.searchParams.set('navigate', 'yes')", $source);
        self::assertStringContainsString('class="waze-navigation-link"', $source);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $source);
    }

    public function testDashboardFiltersStaleAndImplausiblePrices(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('return routeFuelPriceIsReasonable(value);', $source);
        self::assertStringContainsString('routeFuelPriceIsFresh(row?.updated_at)', $source);
    }

    public function testTopographicContourWorkerUsesAnAbsoluteDemUrl(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('const topoTileBaseUrl = new URL(', $source);
        self::assertStringContainsString('window.location.origin,', $source);
        self::assertStringContainsString(
            'const topoDemUrl = `${topoTileBaseUrl}data/terrain/{z}/{x}/{y}.png`;',
            $source,
        );
        self::assertStringContainsString('worker: true,', $source);
    }

    public function testRoutePlannerExposesPermanentVehicleConfiguration(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($script);
        self::assertStringContainsString('for="route-tank-capacity">Tank Capacity (L)', $template);
        self::assertStringContainsString('id="route-starting-fuel"', $template);
        self::assertStringContainsString('id="route-fuel-reserve"', $template);
        self::assertStringContainsString('id="route-optimization-mode"', $template);
        self::assertStringContainsString('<h3>Vehicle Configuration</h3>', $template);
        self::assertStringContainsString('class="route-vehicle-grid"', $template);
        self::assertStringNotContainsString('>Fuel Fill (L)<', $template);
        self::assertStringNotContainsString('id="route-use-optimizer"', $template);
        self::assertStringNotContainsString('data-route-optimizer-field', $template);
        self::assertStringContainsString('startingFuel: routeStartingFuel.value.trim()', $script);
        self::assertStringContainsString('fuelReserve: routeFuelReserve.value.trim()', $script);
        self::assertStringContainsString('Starting fuel must be between zero and tank capacity.', $script);
    }

    public function testRouteInputsOfferAccessibleCompletionAfterThreeCharacters(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($script);
        self::assertStringContainsString('const routeAutocompleteMinCharacters = 3;', $script);
        self::assertStringContainsString('function routeCatalogAutocompleteResults(query)', $script);
        self::assertStringContainsString("provider: 'fuelau-catalog'", $script);
        self::assertStringContainsString("input.setAttribute('role', 'combobox')", $script);
        self::assertStringContainsString("event.key === 'ArrowDown'", $script);
        self::assertStringContainsString('routeAutocompleteResolvedLocation(routeOrigin, originValue)', $script);
        self::assertStringContainsString('routeAutocompleteResolvedLocation(fuelStopFinderOrigin, originValue)', $script);
        self::assertStringContainsString('attachRouteAutocomplete(fuelStopFinderDestination);', $script);
        self::assertStringContainsString('attachRouteAutocomplete(routeOrigin);', $script);
    }

    public function testRoutePlannerAlwaysUsesBackendCompleteItineraryPlanner(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($script);
        self::assertStringContainsString("apiRequest('/api/route/optimize'", $script);
        self::assertStringContainsString('const plan = await buildOptimizedRoutePlan(', $script);
        self::assertStringNotContainsString('routeOptimizerSelected', $script);
        self::assertStringNotContainsString('routeUseOptimizer', $script);
        self::assertStringNotContainsString('async function buildRoutePlan(', $script);
        self::assertStringNotContainsString('function buildRouteSequence(', $script);
        self::assertStringNotContainsString('id="route-use-optimizer"', $template);
        self::assertStringContainsString(
            'destinations: destinations.map(routeOptimizerLocation)',
            $script,
        );
        self::assertStringContainsString('starting_fuel_l: startingFuelL', $script);
        self::assertStringContainsString('reserve_l: reserveL', $script);
        self::assertStringContainsString('const routePlannerLegLimit = 20;', $script);
        self::assertStringContainsString('Plans support up to 20 route legs.', $template);
        self::assertStringContainsString(
            'const itineraryLegCount = routeItineraryLegCount(destinationValues.length);',
            $script,
        );
        self::assertStringContainsString("type: 'Leg Destination'", $script);
        self::assertStringContainsString("'Planned stop'", $script);
        self::assertStringNotContainsString('fatigue spacing', $script);
    }
}
