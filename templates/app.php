<?php

declare(strict_types=1);

/** @var bool $containerManagementEnabled */
/** @var string $cspNonce */
/** @var array<string, mixed> $mapConfig */
$appCssHash = hash_file('sha256', fuelauProjectRoot() . '/public/resources/app.css');
$appJsHash = hash_file('sha256', fuelauProjectRoot() . '/public/resources/app.js');
$appCssVersion = is_string($appCssHash) ? substr($appCssHash, 0, 12) : 'dev';
$appJsVersion = is_string($appJsHash) ? substr($appJsHash, 0, 12) : 'dev';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FuelAU</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link
        rel="stylesheet"
        href="/resources/maplibre-gl.css"
    >
    <link rel="stylesheet" href="/resources/app.css?v=<?= htmlspecialchars($appCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
        window.fuelauMapConfig = <?= json_encode(
            $mapConfig,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?>;
        window.fuelauAppConfig = <?= json_encode([
            'containerManagementEnabled' => $containerManagementEnabled,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <main class="app-shell">
        <nav class="tabs" aria-label="Primary">
            <button class="tab" type="button" role="tab" aria-selected="true" aria-controls="fuel-prices" id="fuel-prices-tab">Fuel Prices</button>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="fuel-stop-finder" id="fuel-stop-finder-tab">Fuel Stop Finder</button>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="route-planning" id="route-planning-tab">Route Planning</button>
            <?php if ($containerManagementEnabled): ?>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="container-management" id="container-management-tab">Container Management</button>
            <?php endif; ?>
        </nav>

        <section class="content">
            <div class="panel active" role="tabpanel" id="fuel-prices" aria-labelledby="fuel-prices-tab">
                <h1>Fuel Prices</h1>
                <p>App-owned price analytics from the ingested fuel datasets. Western Australia is sourced from the public FuelWatch RSS feed and refreshes after the daily 2:30pm release window. Weekly and monthly trend charts are rendered locally with SVG.</p>

                <div class="fuel-layout">
                    <div class="fuel-toolbar">
                        <div class="field">
                            <label for="fuel-state">State</label>
                            <select id="fuel-state"></select>
                        </div>
                        <div class="field">
                            <label for="fuel-region">Region</label>
                            <select id="fuel-region"></select>
                        </div>
                        <div class="field">
                            <label for="fuel-type">Fuel</label>
                            <select id="fuel-type"></select>
                        </div>
                        <div class="field">
                            <label>&nbsp;</label>
                            <button class="button primary" type="button" id="refresh-fuel-dashboard">Refresh Graphs</button>
                        </div>
                    </div>

                    <div class="status-line" id="fuel-status">Loading fuel dashboard...</div>
                    <div class="summary-grid" id="fuel-summary"></div>

                    <div class="fuel-grid">
                        <section class="surface-block">
                            <h2>Weekly Trend</h2>
                            <p>Rolling daily average over the last six weeks.</p>
                            <div id="fuel-weekly-chart"></div>
                            <div class="chart-meta" id="fuel-weekly-meta"></div>
                        </section>

                        <section class="surface-block">
                            <h2>Monthly Trend</h2>
                            <p>Monthly average over the last twelve months.</p>
                            <div id="fuel-monthly-chart"></div>
                            <div class="chart-meta" id="fuel-monthly-meta"></div>
                        </section>

                        <section class="surface-block">
                            <h2>Recent Snapshot</h2>
                            <p>Most recent prices from the filtered dataset.</p>
                            <div id="fuel-snapshot"></div>
                        </section>
                    </div>

                    <section class="surface-block fuel-map-panel">
                        <h2>Station Map</h2>
                        <p>Click a station to inspect the selected fuel price.</p>
                        <div class="fuel-map-frame" id="fuel-map"></div>
                        <div class="fuel-map-legend" id="fuel-map-legend"></div>
                    </section>
                </div>
            </div>
            <div class="panel" role="tabpanel" id="fuel-stop-finder" aria-labelledby="fuel-stop-finder-tab">
                <h1>Fuel Stop Finder</h1>
                <p>Enter an origin, destination, fuel type, and fuel economy. The planner will find the best station to fill up at between the two points while keeping the route detour sensible.</p>

                <div class="route-layout">
                    <section class="surface-block route-top">
                        <h2>Trip Inputs</h2>
                        <p>Origin, destination, fuel type, and fuel economy are required.</p>

                        <div class="route-input-grid">
                            <div class="field">
                                <label for="fuel-stop-finder-origin">Origin</label>
                                <div class="route-autocomplete">
                                    <input type="text" id="fuel-stop-finder-origin" class="route-autocomplete-input" placeholder="Enter an origin" autocomplete="off">
                                    <div class="route-autocomplete-panel" id="fuel-stop-finder-origin-results" hidden></div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="fuel-stop-finder-destination">Destination</label>
                                <div class="route-autocomplete">
                                    <input type="text" id="fuel-stop-finder-destination" class="route-autocomplete-input" placeholder="Enter a destination" autocomplete="off">
                                    <div class="route-autocomplete-panel" id="fuel-stop-finder-destination-results" hidden></div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="fuel-stop-finder-fuel-type">Fuel Type</label>
                                <select id="fuel-stop-finder-fuel-type"></select>
                            </div>
                            <div class="field">
                                <label for="fuel-stop-finder-economy">Fuel Economy (L/100km)</label>
                                <input type="number" id="fuel-stop-finder-economy" min="0.1" step="0.1" inputmode="decimal" placeholder="0.0">
                            </div>
                        </div>

                        <div class="route-actions">
                            <button class="button primary" type="button" id="fuel-stop-finder-plan">Find Best Stop</button>
                            <button class="button" type="button" id="fuel-stop-finder-reset">Reset</button>
                        </div>

                        <div class="status-line" id="fuel-stop-finder-status">Enter a trip to find the best fuel stop.</div>
                        <div class="status-line route-status-muted" id="fuel-stop-finder-detail"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Trip Summary</h2>
                        <div class="route-summary-grid" id="fuel-stop-finder-summary"></div>
                        <div class="fuel-stop-finder-recommendation" id="fuel-stop-finder-recommendation"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Route Map</h2>
                        <div class="route-map-frame" id="fuel-stop-finder-map"></div>
                        <div class="route-map-legend" id="fuel-stop-finder-map-legend"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Route Breakdown</h2>
                        <div id="fuel-stop-finder-legs"></div>
                    </section>
                </div>
            </div>
            <div class="panel" role="tabpanel" id="route-planning" aria-labelledby="route-planning-tab">
                <h1>Route Planning</h1>
                <p>Plan routes through the app-owned geocoding and routing API. Add multiple destinations, reorder them, and choose how the return leg behaves.</p>

                <div class="route-layout">
                    <section class="surface-block route-top">
                        <h2>Trip Inputs</h2>
                        <p>Origin, destinations, fuel inputs, and return mode live here.</p>

                        <div class="route-input-grid">
                            <div class="field">
                                <label for="route-origin">Origin</label>
                                <div class="route-autocomplete">
                                    <input type="text" id="route-origin" class="route-autocomplete-input" placeholder="Enter an origin" autocomplete="off">
                                    <div class="route-autocomplete-panel" id="route-origin-results" hidden></div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="route-fuel-type">Fuel</label>
                                <select id="route-fuel-type"></select>
                            </div>
                            <div class="field">
                                <label for="route-fuel-fill">Fuel Fill (L)</label>
                                <input type="number" id="route-fuel-fill" min="0" step="0.1" inputmode="decimal" placeholder="0.0">
                            </div>
                            <div class="field">
                                <label for="route-fuel-economy">Fuel Economy (L/100km)</label>
                                <input type="number" id="route-fuel-economy" min="0.1" step="0.1" inputmode="decimal" placeholder="0.0">
                            </div>
                        </div>

                        <div class="route-destinations">
                            <div class="route-destination-header">
                                <div>
                                    <p class="route-muted">Add one or more stops, then reorder them before planning.</p>
                                </div>
                                <button class="button primary" type="button" id="route-add-destination">+</button>
                            </div>
                            <div class="route-destination-list" id="route-destination-list"></div>
                        </div>

                        <div class="route-switches" role="group" aria-label="Return mode">
                            <label class="switch-control">
                                <input type="radio" name="route-return-mode" id="route-return-reverses" value="reverses">
                                <span class="switch-track" aria-hidden="true"></span>
                                <span>Return reverses path</span>
                            </label>
                            <label class="switch-control">
                                <input type="radio" name="route-return-mode" id="route-return-direct" value="direct" checked>
                                <span class="switch-track" aria-hidden="true"></span>
                                <span>Return direct to origin</span>
                            </label>
                            <label class="switch-control">
                                <input type="checkbox" id="route-return-one-way" value="one-way">
                                <span class="switch-track" aria-hidden="true"></span>
                                <span>One Way</span>
                            </label>
                        </div>

                        <div class="route-actions">
                            <button class="button primary" type="button" id="route-plan">Plan Route</button>
                            <button class="button" type="button" id="route-test">Load Test Cities</button>
                            <button class="button" type="button" id="route-reset">Reset</button>
                        </div>

                        <div class="status-line" id="route-status">Enter a trip to build a route.</div>
                        <div class="status-line route-status-muted" id="route-excluded-status"></div>
                    </section>

                    <section class="surface-block route-results">
                        <h2>Route Summary</h2>
                        <div class="route-summary-grid" id="route-summary"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Route Map</h2>
                        <div class="route-map-frame" id="route-map"></div>
                        <div class="route-map-legend" id="route-map-legend"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Leg Breakdown</h2>
                        <div id="route-legs"></div>
                    </section>
                </div>
            </div>
            <?php if ($containerManagementEnabled): ?>
            <div class="panel" role="tabpanel" id="container-management" aria-labelledby="container-management-tab">
                <h1>Container Management</h1>
                <p>Status, logs, restart controls, and constrained cleanup tasks for this Compose project.</p>

                <div class="toolbar">
                    <button class="button primary" type="button" id="refresh-containers">Refresh</button>
                    <button class="button" type="button" id="restart-container" disabled>Restart Selected</button>
                    <button class="button danger" type="button" id="prune-stopped">Prune Stopped Project Containers</button>
                    <button class="button danger" type="button" id="prune-images">Prune Dangling Images</button>
                </div>

                <div class="status-line" id="container-status">Loading container status...</div>
                <div class="container-grid" id="container-grid"></div>

                <h1>Logs</h1>
                <pre class="logs" id="container-logs">Select a container to load logs.</pre>
            </div>
            <?php endif; ?>
        </section>
    </main>

    <script src="/resources/maplibre-gl.js"></script>
    <?php if (($mapConfig['style_id'] ?? null) === 'topo-3d'): ?>
    <script src="/resources/maplibre-contour.min.js"></script>
    <?php endif; ?>
    <script src="/resources/app.js?v=<?= htmlspecialchars($appJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
