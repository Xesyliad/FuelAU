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
        self::assertStringContainsString('document.documentElement.dataset.themeResolved = resolvedTheme;', $template);
        self::assertStringContainsString("window.matchMedia('(prefers-color-scheme: dark)').matches", $template);
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
        self::assertStringContainsString("select option,\nselect optgroup {", $stylesheet);
        self::assertStringContainsString('background-color: var(--control-bg);', $stylesheet);
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
        self::assertStringContainsString("fuelMap.classList.add('is-map-theme-ready');", $script);
        self::assertStringContainsString('.fuel-map-frame .maplibregl-canvas', $stylesheet);
        self::assertStringContainsString('.fuel-map-frame.is-map-theme-ready .maplibregl-canvas', $stylesheet);
        self::assertStringNotContainsString('.setStyle(', $script);
    }

    public function testFuelMapViewportRefreshIsGuardedOnPersistentSharedMap(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('function fuelPricesToolIsActive()', $source);
        self::assertStringContainsString('requestKey === fuelMapViewportLastRequestKey', $source);
        self::assertStringContainsString('if (!preserveViewport)', $source);
        self::assertStringContainsString("error?.name === 'AbortError'", $source);
        self::assertSame(1, substr_count($source, 'new maplibregl.Map('));
        self::assertStringContainsString('function syncSharedMapWorkflow(workflow)', $source);
        self::assertStringContainsString("'fuelau-stop-finder-lines'", $source);
        self::assertStringContainsString("'fuelau-route-planner-lines'", $source);
        self::assertStringNotContainsString('function destroyFuelMap()', $source);
        self::assertStringContainsString('function syncMapFirstShell(toolButton, panelId)', $source);
        self::assertStringContainsString('scheduleMapResize(fuelMapInstance);', $source);
        self::assertStringContainsString("toolButton.getAttribute('aria-pressed') === 'true'", $source);
        self::assertStringNotContainsString('setInterval(', $source);
    }

    public function testPageUsesAMapFirstResponsiveApplicationShell(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.css');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($stylesheet);
        self::assertIsString($script);
        self::assertStringContainsString('class="map-stage"', $template);
        self::assertStringContainsString('data-map-view="shared"', $template);
        self::assertStringContainsString('class="tool-navigation" aria-label="Map tools"', $template);
        self::assertStringContainsString('id="fuel-prices-tool"', $template);
        self::assertStringContainsString('id="fuel-stop-finder-tool"', $template);
        self::assertStringContainsString('id="route-planning-tool"', $template);
        self::assertSame(3, substr_count($template, 'class="tool-button"'));
        self::assertSame(0, substr_count($template, 'role="tab'));
        self::assertSame(0, substr_count($template, 'aria-selected='));
        self::assertStringContainsString('aria-pressed="true" aria-controls="fuel-prices"', $template);
        self::assertStringContainsString('id="fuel-stop-finder" data-tool-panel role="region" aria-label="Fuel stop finder" data-workflow-state="input" hidden', $template);
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
        self::assertStringContainsString("const reducedMotionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');", $script);
        self::assertStringContainsString('function mapMotionDuration(duration = 400)', $script);
        self::assertStringNotContainsString('.tab {', $stylesheet);
        self::assertStringNotContainsString('.tabs {', $stylesheet);
        self::assertStringNotContainsString('.switch-control', $stylesheet);
        self::assertStringNotContainsString('.route-map-frame', $stylesheet);
        self::assertStringContainsString('.fuel-station-brand-badge', $stylesheet);
        self::assertStringContainsString('.snapshot-station-select', $stylesheet);
        self::assertStringContainsString('.workspace-sheet .fuel-filter-bar', $stylesheet);
    }

    public function testExplorePricesUsesCleanSelectableDotsAndBrandAwareDetails(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($source);
        self::assertStringContainsString('const fuelBrandRegistry = {', $source);
        self::assertStringNotContainsString('function registerFuelBrandImages(map)', $source);
        self::assertStringNotContainsString("id: 'fuelau-prices-stations-brand'", $source);
        self::assertStringNotContainsString("id: 'fuelau-prices-highlights-brand'", $source);
        self::assertStringNotContainsString('brand_icon:', $source);
        self::assertStringContainsString("id: 'fuelau-prices-stations-circle'", $source);
        self::assertStringContainsString('class="fuel-station-brand-badge snapshot-brand-badge"', $source);
        self::assertStringContainsString("id: 'fuelau-prices-selection-ring'", $source);
        self::assertStringContainsString('brand_name: String(row.brand_name || \'\')', $source);
        self::assertStringContainsString('function selectFuelStation(properties, coordinates, focusMap = false)', $source);
        self::assertStringContainsString('data-snapshot-index=', $source);
        self::assertStringContainsString('function fuelStationIdentity(row)', $source);
        self::assertStringContainsString('let fuelSelectedStation = null;', $source);
        self::assertStringContainsString('aria-pressed="${isSelected ? \'true\' : \'false\'}"', $source);
        self::assertStringContainsString('fuelSnapshotRows = [selectedRow, ...fuelSnapshotRows].slice(0, 8);', $source);
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
        self::assertStringContainsString('id="route-vehicle-settings-summary"', $template);
        self::assertStringContainsString('class="route-return-selector"', $template);
        self::assertStringContainsString('type="radio" name="route-return-mode" id="route-return-one-way"', $template);
        self::assertStringContainsString('id="route-planner-state" role="status" aria-live="polite"', $template);
        self::assertStringContainsString('class="panel-disclosure route-results-disclosure" id="route-planner-results"', $template);
        self::assertStringContainsString('<h3>Vehicle Configuration</h3>', $template);
        self::assertStringContainsString('class="route-vehicle-grid"', $template);
        self::assertStringNotContainsString('>Fuel Fill (L)<', $template);
        self::assertStringNotContainsString('id="route-use-optimizer"', $template);
        self::assertStringNotContainsString('data-route-optimizer-field', $template);
        self::assertStringContainsString('startingFuel: routeStartingFuel.value.trim()', $script);
        self::assertStringContainsString('fuelReserve: routeFuelReserve.value.trim()', $script);
        self::assertStringContainsString('Starting fuel must be between zero and tank capacity.', $script);
        self::assertStringContainsString('function updateRouteVehicleSettingsSummary()', $script);
        self::assertStringContainsString('function moveRouteDestination(row, direction)', $script);
        self::assertStringContainsString('data-action="move-up"', $script);
        self::assertStringContainsString('data-action="move-down"', $script);
        self::assertStringContainsString('function setRoutePlannerWorkflowState(state)', $script);
        self::assertStringContainsString("renderRouteBreakdownInto(routeLegs, plan, { workflow: 'route-planning' });", $script);
        self::assertStringContainsString('routePlannerResults.open = true;', $script);
        self::assertStringContainsString("map.on('click', layer.id", $script);
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

    public function testFuelStopFinderExposesExplicitResultLifecycleStates(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.css');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($stylesheet);
        self::assertIsString($script);
        self::assertStringContainsString('id="fuel-stop-finder" data-tool-panel role="region" aria-label="Fuel stop finder" data-workflow-state="input" hidden', $template);
        self::assertStringContainsString('id="fuel-stop-finder-state" role="status" aria-live="polite"', $template);
        self::assertStringContainsString('class="panel-disclosure fuel-stop-results-disclosure" id="fuel-stop-finder-results"', $template);
        self::assertStringContainsString('id="fuel-stop-finder-results-summary"', $template);
        $recommendationPosition = strpos($template, 'class="fuel-stop-result-section fuel-stop-recommendation-section"');
        $tripSummaryPosition = strpos($template, '<h2>Trip summary</h2>');
        self::assertIsInt($recommendationPosition);
        self::assertIsInt($tripSummaryPosition);
        self::assertLessThan($tripSummaryPosition, $recommendationPosition);
        self::assertStringContainsString('function setFuelStopFinderWorkflowState(state)', $script);
        self::assertStringContainsString("setFuelStopFinderWorkflowState('calculating');", $script);
        self::assertStringContainsString("setFuelStopFinderWorkflowState('result');", $script);
        self::assertStringContainsString("setFuelStopFinderWorkflowState('stale');", $script);
        self::assertStringContainsString("setFuelStopFinderWorkflowState('error');", $script);
        self::assertStringContainsString('markFuelStopFinderInputChanged', $script);
        self::assertStringContainsString('fuelStopFinderResults.open = true;', $script);
        self::assertStringContainsString("renderRouteBreakdownInto(fuelStopFinderLegs, plan, { workflow: 'fuel-stop-finder' });", $script);
        self::assertStringContainsString('function focusRouteWorkflowOnMap(workflow, options = {}, control = null)', $script);
        self::assertStringContainsString('data-route-map-focus', $script);
        self::assertStringContainsString('fuelMapInstance.setPaintProperty(`${prefix}-lines`, \'line-opacity\'', $script);
        self::assertStringContainsString('#fuel-stop-finder[data-workflow-state="stale"]', $stylesheet);
        self::assertStringContainsString('.fuel-stop-results-content', $stylesheet);
        self::assertStringContainsString('.route-fuel-marker.is-focused', $stylesheet);
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

    public function testRouteFuelSelectorsUseCanonicalNationalGroups(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($script);
        self::assertStringContainsString('fuelOptions?.route_fuels', $script);
        self::assertStringContainsString('type: fuelProfile', $script);
        self::assertStringContainsString('routeFuelSelectedValue(),', $script);
        self::assertStringContainsString(
            'collectFuelStopFinderCandidates(progress, fuelProfile',
            $script,
        );
        self::assertStringNotContainsString(
            'const choices = filteredFuelOptions().filter',
            $script,
        );
    }

    public function testAppStartupNormalizesPersistedCacheOwnership(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/docker/app/entrypoint.sh');

        self::assertIsString($entrypoint);
        self::assertStringContainsString(
            'chown -R www-data:www-data "$app_state_directory"',
            $entrypoint,
        );
    }

    public function testAdministrationUsesSecondaryOverflowAndProtectedManagementSurface(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/app.php');
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.css');
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/resources/app.js');

        self::assertIsString($template);
        self::assertIsString($stylesheet);
        self::assertIsString($script);
        self::assertStringContainsString('id="app-overflow-toggle" aria-expanded="false"', $template);
        self::assertStringContainsString('id="app-overflow-menu" role="menu" hidden', $template);
        self::assertStringContainsString('id="open-container-management" role="menuitem"', $template);
        self::assertStringContainsString('id="container-management-tool" aria-controls="container-management"', $template);
        self::assertStringNotContainsString('tool-button-admin', $template);
        self::assertStringContainsString('class="admin-maintenance"', $template);
        self::assertStringContainsString('id="container-logs-summary"', $template);
        self::assertStringContainsString('function setAppOverflowExpanded(expanded)', $script);
        self::assertStringContainsString("activateTool('container-management-tool');", $script);
        self::assertStringContainsString("window.confirm('Restart the selected container?')", $script);
        self::assertStringContainsString("apiRequest('/api/docker/prune'", $script);
        self::assertStringContainsString('class="admin-overview-card"', $script);
        self::assertStringContainsString('.app-overflow-menu', $stylesheet);
        self::assertStringContainsString('.admin-overview-card', $stylesheet);
        self::assertStringContainsString('.admin-logs .logs', $stylesheet);
    }
}
