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
    <meta name="theme-color" content="#f4f6f8">
    <title>FuelAU</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
        (() => {
            const themeKey = 'fuelau_theme_v1';
            const supportedThemes = ['system', 'light', 'dark'];
            let preference = 'system';

            try {
                const savedPreference = window.localStorage.getItem(themeKey);
                if (supportedThemes.includes(savedPreference)) {
                    preference = savedPreference;
                }
            } catch (error) {
                // Storage can be unavailable in privacy-restricted browser contexts.
            }

            document.documentElement.dataset.theme = preference;
        })();
    </script>
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
    <main class="app-shell" data-active-tool="fuel-prices">
        <section class="map-stage" aria-label="FuelAU map workspace">
            <div class="map-view base-map-view active" data-map-view="shared" aria-hidden="false">
                <div class="fuel-map-frame" id="fuel-map"></div>
                <div class="map-status-overlay" id="map-status-overlay" role="status" aria-live="polite" hidden></div>
                <div class="fuel-map-legend map-overlay-legend" id="fuel-map-legend" data-map-legend="fuel-prices"></div>
                <div class="route-map-legend map-overlay-legend" id="fuel-stop-finder-map-legend" data-map-legend="fuel-stop-finder" hidden></div>
                <div class="route-map-legend map-overlay-legend" id="route-map-legend" data-map-legend="route-planning" hidden></div>
            </div>
        </section>

        <header class="app-bar">
            <div class="app-brand" aria-label="FuelAU home">
                <span>Fuel</span><strong>AU</strong>
            </div>
            <div class="app-context">
                <span>Map workspace</span>
                <strong id="active-tool-title">Explore prices</strong>
            </div>
            <div class="theme-switcher" role="group" aria-label="Colour theme">
                <button class="theme-option" type="button" data-theme-preference="system" aria-pressed="false">System</button>
                <button class="theme-option" type="button" data-theme-preference="light" aria-pressed="false">Light</button>
                <button class="theme-option" type="button" data-theme-preference="dark" aria-pressed="false">Dark</button>
            </div>
        </header>

        <nav class="tabs tool-navigation" role="tablist" aria-label="Map tools">
            <button class="tab tool-button" type="button" role="tab" aria-selected="true" aria-controls="fuel-prices" id="fuel-prices-tab" data-tool-title="Explore prices">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5 10.5 1H20v9.5L13.5 17 4 7.5Zm11-2.25a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5Z"/></svg>
                <span>Prices</span>
            </button>
            <button class="tab tool-button" type="button" role="tab" aria-selected="false" aria-controls="fuel-stop-finder" id="fuel-stop-finder-tab" data-tool-title="Find a fuel stop" tabindex="-1">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h10v18H5V3Zm2 2v6h6V5H7Zm9 3h2l2 3v7.5a2.5 2.5 0 0 1-5 0V16h2v2.5a.5.5 0 0 0 1 0V12l-2-2V8Z"/></svg>
                <span>Fuel stop</span>
            </button>
            <button class="tab tool-button" type="button" role="tab" aria-selected="false" aria-controls="route-planning" id="route-planning-tab" data-tool-title="Route planning" tabindex="-1">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm12 12a3 3 0 1 1 0 6 3 3 0 0 1 0-6ZM8.5 6H14a4 4 0 0 1 0 8h-4a2 2 0 0 0 0 4h5.5v2H10a4 4 0 0 1 0-8h4a2 2 0 0 0 0-4H8.5V6Z"/></svg>
                <span>Route</span>
            </button>
            <?php if ($containerManagementEnabled): ?>
            <button class="tab tool-button tool-button-admin" type="button" role="tab" aria-selected="false" aria-controls="container-management" id="container-management-tab" data-tool-title="Container management" tabindex="-1">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 8 4.5v9L12 20l-8-4.5v-9L12 2Zm0 2.3L6.2 7.5 12 10.7l5.8-3.2L12 4.3ZM6 9.2v5.1l5 2.8V12L6 9.2Zm7 7.9 5-2.8V9.2L13 12v5.1Z"/></svg>
                <span>Admin</span>
            </button>
            <?php endif; ?>
        </nav>

        <button class="sheet-reopen" type="button" id="workspace-sheet-reopen" aria-controls="workspace-sheet" aria-label="Open active tool panel">
            <span aria-hidden="true">›</span>
        </button>

        <aside class="workspace-sheet" id="workspace-sheet" aria-label="Active tool panel">
            <div class="sheet-grabber" aria-hidden="true"></div>
            <header class="sheet-header">
                <div>
                    <span class="sheet-eyebrow">FuelAU tool</span>
                    <strong id="sheet-tool-title">Explore prices</strong>
                </div>
                <button class="sheet-toggle" type="button" id="workspace-sheet-toggle" aria-controls="workspace-sheet" aria-expanded="true" aria-label="Collapse active tool panel">
                    <span aria-hidden="true">‹</span>
                </button>
            </header>

            <section class="content">
            <div class="panel active" role="tabpanel" id="fuel-prices" aria-labelledby="fuel-prices-tab">
                <h1>Fuel Prices</h1>
                <p>Compare current prices, inspect nearby stations, and open trends when you need more detail.</p>

                <div class="fuel-layout">
                    <div class="fuel-toolbar fuel-filter-bar" role="group" aria-label="Fuel price filters">
                        <div class="field fuel-filter-chip fuel-filter-state">
                            <label for="fuel-state">State</label>
                            <select id="fuel-state"></select>
                        </div>
                        <div class="field fuel-filter-chip fuel-filter-region">
                            <label for="fuel-region">Region</label>
                            <select id="fuel-region"></select>
                        </div>
                        <div class="field fuel-filter-chip fuel-filter-type">
                            <label for="fuel-type">Fuel</label>
                            <select id="fuel-type"></select>
                        </div>
                        <div class="field fuel-filter-refresh">
                            <button class="button primary fuel-refresh-button" type="button" id="refresh-fuel-dashboard" aria-label="Refresh fuel prices and insights" title="Refresh fuel prices and insights">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.1 8A7.5 7.5 0 1 0 20 14h-2.1a5.5 5.5 0 1 1-.45-4H14v2h7V5h-2l.1 3Z"/></svg>
                                <span>Refresh</span>
                            </button>
                        </div>
                    </div>

                    <div class="status-line" id="fuel-status">Loading fuel dashboard...</div>
                    <section class="fuel-station-detail" id="fuel-station-detail" aria-live="polite" hidden></section>

                    <details class="panel-disclosure insights-disclosure" open>
                        <summary>
                            <span>Insights</span>
                            <small>Weekly, monthly, and recent prices</small>
                        </summary>
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
                    </details>

                    <details class="panel-disclosure state-summary-disclosure">
                        <summary>
                            <span>State summaries</span>
                            <small>Coverage and latest reports by state</small>
                        </summary>
                        <div class="summary-grid" id="fuel-summary"></div>
                    </details>

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
                                    <div class="route-autocomplete-panel" hidden></div>
                                </div>
                            </div>
                            <div class="field">
                                <label for="fuel-stop-finder-destination">Destination</label>
                                <div class="route-autocomplete">
                                    <input type="text" id="fuel-stop-finder-destination" class="route-autocomplete-input" placeholder="Enter a destination" autocomplete="off">
                                    <div class="route-autocomplete-panel" hidden></div>
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
                        <p>Configure the vehicle, then add the trip origin and destinations.</p>

                        <details class="panel-disclosure route-settings-disclosure">
                            <summary>
                                <span>Vehicle and fuel settings</span>
                                <small>Fuel, capacity, consumption, and refill preferences</small>
                            </summary>
                            <div class="route-vehicle-configuration">
                                <div class="route-section-heading">
                                    <h3>Vehicle Configuration</h3>
                                    <p>Fuel, capacity, consumption, and refill preferences.</p>
                                </div>
                                <div class="route-vehicle-grid">
                                <div class="field">
                                    <label for="route-fuel-type">Fuel</label>
                                    <select id="route-fuel-type"></select>
                                </div>
                                <div class="field">
                                    <label for="route-tank-capacity">Tank Capacity (L)</label>
                                    <input type="number" id="route-tank-capacity" min="5" max="1500" step="0.5" inputmode="decimal" placeholder="60.0">
                                </div>
                                <div class="field">
                                    <label for="route-starting-fuel">Starting Fuel (L)</label>
                                    <input type="number" id="route-starting-fuel" min="0" max="1500" step="0.5" inputmode="decimal" placeholder="60.0">
                                </div>
                                <div class="field">
                                    <label for="route-fuel-reserve">Fuel Reserve Before Refill (L)</label>
                                    <input type="number" id="route-fuel-reserve" min="0" max="1499.5" step="0.5" inputmode="decimal" placeholder="6.0">
                                </div>
                                <div class="field">
                                    <label for="route-fuel-economy">Fuel Economy (L/100km)</label>
                                    <input type="number" id="route-fuel-economy" min="0.1" step="0.1" inputmode="decimal" placeholder="0.0">
                                </div>
                                <div class="field">
                                    <label for="route-optimization-mode">Optimisation</label>
                                    <select id="route-optimization-mode">
                                        <option value="practical_least_cost">Practical least cost</option>
                                        <option value="fewer_stops">Fewer fuel stops</option>
                                    </select>
                                </div>
                                </div>
                            </div>
                        </details>

                        <div class="route-itinerary-inputs">
                            <div class="field">
                                <label for="route-origin">Origin</label>
                                <div class="route-autocomplete">
                                    <input type="text" id="route-origin" class="route-autocomplete-input" placeholder="Enter an origin" autocomplete="off">
                                    <div class="route-autocomplete-panel" hidden></div>
                                </div>
                            </div>

                            <div class="route-destinations">
                                <div class="route-destination-header">
                                    <div>
                                        <p class="route-muted">Add one or more stops, then reorder them before planning. Plans support up to 20 route legs.</p>
                                    </div>
                                    <button class="button primary" type="button" id="route-add-destination">+</button>
                                </div>
                                <div class="route-destination-list" id="route-destination-list"></div>
                            </div>
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

                        <div class="status-line" id="route-status" role="status" aria-live="polite">Enter a trip to build a route.</div>
                    </section>

                    <section class="surface-block route-results">
                        <h2>Route Summary</h2>
                        <div class="route-summary-grid" id="route-summary"></div>
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
        </aside>
    </main>

    <script src="/resources/maplibre-gl.js"></script>
    <?php if (($mapConfig['style_id'] ?? null) === 'topo-3d'): ?>
    <script src="/resources/maplibre-contour.min.js"></script>
    <?php endif; ?>
    <script src="/resources/app.js?v=<?= htmlspecialchars($appJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
