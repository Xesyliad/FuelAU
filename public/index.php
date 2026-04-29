<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/docker.php';
require dirname(__DIR__) . '/src/http.php';
require dirname(__DIR__) . '/src/routing.php';
require dirname(__DIR__) . '/src/fuel.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    if ($path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FuelAU</title>
    <style>
        :root {
            color-scheme: light;
            --page-bg: #f4f6f8;
            --surface: #ffffff;
            --border: #cfd7df;
            --text: #16212d;
            --muted: #5b6775;
            --accent: #0f766e;
            --accent-soft: #e0f2f1;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--page-bg);
            color: var(--text);
            font-family: "Courier New", Courier, monospace;
        }

        .app-shell {
            width: 95%;
            min-height: 95vh;
            margin: 2.5vh auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        .tabs {
            display: flex;
            align-items: stretch;
            justify-content: flex-start;
            gap: 0;
            border-bottom: 1px solid var(--border);
            background: #f9fafb;
        }

        .tab {
            appearance: none;
            border: 0;
            border-right: 1px solid var(--border);
            background: transparent;
            color: var(--muted);
            min-height: 48px;
            padding: 0 22px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .tab[aria-selected="true"] {
            background: var(--accent-soft);
            color: var(--accent);
            box-shadow: inset 0 -3px 0 var(--accent);
        }

        .tab:focus-visible {
            outline: 3px solid rgba(15, 118, 110, 0.35);
            outline-offset: -3px;
        }

        .content {
            padding: 24px;
        }

        .panel {
            display: none;
        }

        .panel.active {
            display: block;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
            line-height: 1.25;
        }

        p {
            max-width: 720px;
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin: 18px 0;
        }

        .button {
            appearance: none;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #ffffff;
            color: var(--text);
            min-height: 36px;
            padding: 0 12px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .button.primary {
            border-color: var(--accent);
            background: var(--accent);
            color: #ffffff;
        }

        .button.danger {
            border-color: #b42318;
            color: #b42318;
        }

        .button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }

        .status-line {
            min-height: 22px;
            color: var(--muted);
            font-size: 14px;
        }

        .container-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
            margin: 18px 0;
        }

        .container-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 14px;
            background: #ffffff;
        }

        .container-card.selected {
            border-color: var(--accent);
            box-shadow: inset 0 0 0 2px rgba(15, 118, 110, 0.18);
        }

        .container-card h2 {
            margin: 0 0 8px;
            font-size: 16px;
            line-height: 1.3;
        }

        .container-meta {
            display: grid;
            gap: 5px;
            margin: 10px 0 12px;
            color: var(--muted);
            font-size: 13px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            min-height: 24px;
            border-radius: 999px;
            padding: 0 8px;
            background: #eceff3;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .badge.running {
            background: #dcfce7;
            color: #166534;
        }

        .badge.exited {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge.planned {
            background: #e0f2fe;
            color: #075985;
        }

        .badge.prepared {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .badge.partial {
            background: #fef3c7;
            color: #92400e;
        }

        .logs {
            min-height: 260px;
            max-height: 360px;
            overflow: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #111827;
            color: #e5e7eb;
            padding: 14px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font: inherit;
        }

        .fuel-layout {
            display: grid;
            gap: 18px;
            margin-top: 18px;
        }

        .fuel-toolbar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .field {
            display: grid;
            gap: 6px;
        }

        .field label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .field select {
            min-height: 38px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fff;
            color: var(--text);
            padding: 0 10px;
            font: inherit;
        }

        .route-autocomplete {
            position: relative;
        }

        .field input[type="text"],
        .field input[type="number"] {
            min-height: 38px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fff;
            color: var(--text);
            padding: 0 10px;
            font: inherit;
        }

        .route-autocomplete-panel {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            z-index: 10;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .route-autocomplete-option {
            display: block;
            width: 100%;
            border: 0;
            border-bottom: 1px solid #edf2f7;
            background: #fff;
            color: var(--text);
            padding: 10px 12px;
            text-align: left;
            cursor: pointer;
            font: inherit;
        }

        .route-autocomplete-option:last-child {
            border-bottom: 0;
        }

        .route-autocomplete-option strong {
            display: block;
            font-size: 13px;
            line-height: 1.3;
        }

        .route-autocomplete-option span {
            display: block;
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.3;
        }

        .route-autocomplete-empty,
        .route-autocomplete-loading {
            padding: 10px 12px;
            color: var(--muted);
            font-size: 12px;
        }

        .fuel-grid {
            display: grid;
            grid-template-columns: 1.2fr 1.2fr 0.9fr;
            gap: 14px;
        }

        .surface-block {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            padding: 14px;
        }

        .surface-block h2 {
            margin: 0 0 6px;
            font-size: 15px;
            line-height: 1.3;
        }

        .surface-block p {
            max-width: none;
            font-size: 13px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .summary-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fbfcfd;
            padding: 12px;
        }

        .summary-card strong {
            display: block;
            font-size: 24px;
            line-height: 1.1;
            margin-bottom: 6px;
            color: var(--accent);
        }

        .summary-card span {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }

        .route-layout {
            display: grid;
            gap: 14px;
        }

        .route-top {
            display: grid;
            gap: 14px;
        }

        .route-input-grid {
            display: grid;
            grid-template-columns: 1.5fr 0.7fr 0.7fr;
            gap: 10px;
        }

        .route-destinations {
            display: grid;
            gap: 10px;
        }

        .route-destination-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .route-destination-list {
            display: grid;
            gap: 10px;
        }

        .route-stop-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: end;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            background: #fbfcfd;
        }

        .route-stop-actions {
            display: grid;
            grid-auto-flow: column;
            gap: 6px;
            align-items: center;
        }

        .route-stop-actions .button {
            min-width: 56px;
            padding: 0 10px;
            font-weight: 700;
        }

        .route-switches {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .switch-control {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 38px;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 0 12px 0 10px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            font: inherit;
            font-weight: 700;
        }

        .switch-control input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .switch-track {
            position: relative;
            width: 34px;
            height: 20px;
            border-radius: 999px;
            background: #cbd5e1;
            flex: 0 0 auto;
            transition: background 0.15s ease;
        }

        .switch-track::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #fff;
            transition: transform 0.15s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.18);
        }

        .switch-control input:checked + .switch-track {
            background: var(--accent);
        }

        .switch-control input:checked + .switch-track::after {
            transform: translateX(14px);
        }

        .switch-control:focus-within {
            outline: 3px solid rgba(15, 118, 110, 0.25);
            outline-offset: 2px;
        }

        .route-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .route-results {
            display: grid;
            gap: 14px;
        }

        .route-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }

        .route-summary-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fbfcfd;
            padding: 12px;
        }

        .route-summary-card strong {
            display: block;
            font-size: 20px;
            line-height: 1.15;
            margin-bottom: 6px;
            color: var(--accent);
        }

        .route-summary-card span {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }

        .route-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .route-table th,
        .route-table td {
            text-align: left;
            padding: 8px 0;
            border-bottom: 1px solid #e8edf2;
            vertical-align: top;
        }

        .route-table th {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
        }

        .route-empty {
            display: grid;
            place-items: center;
            min-height: 120px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .route-muted {
            color: var(--muted);
            font-size: 12px;
        }

        .chart {
            width: 100%;
            height: 280px;
            display: block;
            border: 1px solid var(--border);
            border-radius: 8px;
            background:
                linear-gradient(to bottom, rgba(15, 118, 110, 0.03), rgba(15, 118, 110, 0.01)),
                #fff;
        }

        .chart-empty {
            display: grid;
            place-items: center;
            min-height: 280px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .chart-meta {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        .snapshot-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .snapshot-table th,
        .snapshot-table td {
            text-align: left;
            padding: 8px 0;
            border-bottom: 1px solid #e8edf2;
            vertical-align: top;
        }

        .snapshot-table th {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
        }

        .snapshot-price {
            font-weight: 700;
            color: var(--accent);
        }

        @media (max-width: 640px) {
            .app-shell {
                width: 95%;
                margin: 2.5vh auto;
            }

            .tabs {
                overflow-x: auto;
            }

            .tab {
                min-width: max-content;
                padding: 0 16px;
            }

            .content {
                padding: 18px;
            }
        }

        @media (max-width: 1100px) {
            .fuel-grid {
                grid-template-columns: 1fr;
            }

            .route-input-grid {
                grid-template-columns: 1fr;
            }

            .route-stop-row {
                grid-template-columns: 1fr;
            }

            .route-stop-actions {
                grid-auto-flow: row;
                justify-content: start;
            }
        }
    </style>
</head>
<body>
    <main class="app-shell">
        <nav class="tabs" aria-label="Primary">
            <button class="tab" type="button" role="tab" aria-selected="true" aria-controls="fuel-prices" id="fuel-prices-tab">Fuel Prices</button>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="route-planning" id="route-planning-tab">Route Planning</button>
            <button class="tab" type="button" role="tab" aria-selected="false" aria-controls="container-management" id="container-management-tab">Container Management</button>
        </nav>

        <section class="content">
            <div class="panel active" role="tabpanel" id="fuel-prices" aria-labelledby="fuel-prices-tab">
                <h1>Fuel Prices</h1>
                <p>App-owned price analytics from the ingested fuel datasets. Weekly and monthly trend charts are rendered locally with SVG.</p>

                <div class="fuel-layout">
                    <div class="fuel-toolbar">
                        <div class="field">
                            <label for="fuel-state">State</label>
                            <select id="fuel-state"></select>
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
                                    <h2 style="margin: 0;">Destination</h2>
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
                        </div>

                        <div class="route-actions">
                            <button class="button primary" type="button" id="route-plan">Plan Route</button>
                            <button class="button" type="button" id="route-reset">Reset</button>
                        </div>

                        <div class="status-line" id="route-status">Enter a trip to build a route.</div>
                    </section>

                    <section class="surface-block route-results">
                        <h2>Route Summary</h2>
                        <div class="route-summary-grid" id="route-summary"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Resolved Stops</h2>
                        <div id="route-resolved"></div>
                    </section>

                    <section class="surface-block">
                        <h2>Leg Breakdown</h2>
                        <div id="route-legs"></div>
                    </section>
                </div>
            </div>
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
        </section>
    </main>

    <script>
        const tabs = document.querySelectorAll('.tab');
        const panels = document.querySelectorAll('.panel');
        const containerGrid = document.getElementById('container-grid');
        const containerStatus = document.getElementById('container-status');
        const containerLogs = document.getElementById('container-logs');
        const refreshContainers = document.getElementById('refresh-containers');
        const restartContainer = document.getElementById('restart-container');
        const pruneStopped = document.getElementById('prune-stopped');
        const pruneImages = document.getElementById('prune-images');
        const fuelState = document.getElementById('fuel-state');
        const fuelType = document.getElementById('fuel-type');
        const fuelStatus = document.getElementById('fuel-status');
        const fuelSummary = document.getElementById('fuel-summary');
        const fuelWeeklyChart = document.getElementById('fuel-weekly-chart');
        const fuelWeeklyMeta = document.getElementById('fuel-weekly-meta');
        const fuelMonthlyChart = document.getElementById('fuel-monthly-chart');
        const fuelMonthlyMeta = document.getElementById('fuel-monthly-meta');
        const fuelSnapshot = document.getElementById('fuel-snapshot');
        const refreshFuelDashboard = document.getElementById('refresh-fuel-dashboard');
        const routeOrigin = document.getElementById('route-origin');
        const routeOriginResults = document.getElementById('route-origin-results');
        const routeFuelFill = document.getElementById('route-fuel-fill');
        const routeFuelEconomy = document.getElementById('route-fuel-economy');
        const routeDestinationList = document.getElementById('route-destination-list');
        const routeAddDestination = document.getElementById('route-add-destination');
        const routeReturnReverses = document.getElementById('route-return-reverses');
        const routeReturnDirect = document.getElementById('route-return-direct');
        const routePlan = document.getElementById('route-plan');
        const routeReset = document.getElementById('route-reset');
        const routeStatus = document.getElementById('route-status');
        const routeSummary = document.getElementById('route-summary');
        const routeResolved = document.getElementById('route-resolved');
        const routeLegs = document.getElementById('route-legs');
        let selectedContainerId = null;
        let selectedContainerRestartable = false;
        let fuelOptions = null;
        let routeDestinationCounter = 0;

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((item) => item.setAttribute('aria-selected', 'false'));
                panels.forEach((panel) => panel.classList.remove('active'));

                tab.setAttribute('aria-selected', 'true');
                document.getElementById(tab.getAttribute('aria-controls')).classList.add('active');

                if (tab.id === 'container-management-tab') {
                    loadContainers();
                }
                if (tab.id === 'fuel-prices-tab') {
                    loadFuelDashboard();
                }
            });
        });

        async function apiRequest(url, options = {}) {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                ...options,
            });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.message || payload.error || 'Request failed');
            }
            return payload;
        }

        function renderPorts(ports) {
            if (!Array.isArray(ports) || ports.length === 0) {
                return 'No published ports';
            }

            return ports.map((port) => {
                const privatePort = port.PrivatePort ? `${port.PrivatePort}/${port.Type || 'tcp'}` : '';
                const publicPort = port.PublicPort ? `${port.IP || '0.0.0.0'}:${port.PublicPort}` : '';
                return publicPort ? `${publicPort} -> ${privatePort}` : privatePort;
            }).join(', ');
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (character) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[character]));
        }

        function formatRouteDistance(meters) {
            const distance = Number(meters || 0);
            if (distance >= 1000) {
                return `${(distance / 1000).toFixed(distance >= 10000 ? 0 : 1)} km`;
            }
            return `${distance.toFixed(0)} m`;
        }

        function formatRouteDuration(seconds) {
            const duration = Math.max(0, Math.round(Number(seconds || 0)));
            const hours = Math.floor(duration / 3600);
            const minutes = Math.floor((duration % 3600) / 60);
            if (hours > 0) {
                return `${hours}h ${minutes}m`;
            }
            return `${minutes}m`;
        }

        function createRouteDestinationRow(value = '') {
            routeDestinationCounter += 1;
            const row = document.createElement('div');
            row.className = 'route-stop-row';
            row.dataset.routeDestinationId = String(routeDestinationCounter);
            row.innerHTML = `
                <div class="field">
                    <label for="route-destination-${routeDestinationCounter}">Destination</label>
                    <div class="route-autocomplete">
                        <input type="text" id="route-destination-${routeDestinationCounter}" class="route-destination-input route-autocomplete-input" placeholder="Enter a destination" value="${escapeHtml(value)}" autocomplete="off">
                        <div class="route-autocomplete-panel" hidden></div>
                    </div>
                </div>
                <div class="route-stop-actions">
                    <button class="button" type="button" data-action="up">Up</button>
                    <button class="button" type="button" data-action="down">Down</button>
                    <button class="button danger" type="button" data-action="remove">Remove</button>
                </div>
            `;

            attachRouteAutocomplete(row.querySelector('.route-destination-input'));

            row.querySelector('[data-action="up"]').addEventListener('click', () => moveRouteDestination(row, -1));
            row.querySelector('[data-action="down"]').addEventListener('click', () => moveRouteDestination(row, 1));
            row.querySelector('[data-action="remove"]').addEventListener('click', () => removeRouteDestination(row));
            return row;
        }

        function ensureRouteDestinationRow() {
            if (routeDestinationList.children.length === 0) {
                routeDestinationList.appendChild(createRouteDestinationRow());
            }
            syncRouteDestinationControls();
        }

        function syncRouteDestinationControls() {
            const rows = Array.from(routeDestinationList.querySelectorAll('.route-stop-row'));
            rows.forEach((row, index) => {
                const up = row.querySelector('[data-action="up"]');
                const down = row.querySelector('[data-action="down"]');
                const remove = row.querySelector('[data-action="remove"]');
                if (up) {
                    up.disabled = index === 0;
                }
                if (down) {
                    down.disabled = index === rows.length - 1;
                }
                if (remove) {
                    remove.disabled = rows.length === 1;
                }
            });
        }

        function addRouteDestination(value = '') {
            routeDestinationList.appendChild(createRouteDestinationRow(value));
            syncRouteDestinationControls();
        }

        function moveRouteDestination(row, offset) {
            const rows = Array.from(routeDestinationList.querySelectorAll('.route-stop-row'));
            const currentIndex = rows.indexOf(row);
            const targetIndex = currentIndex + offset;
            if (currentIndex < 0 || targetIndex < 0 || targetIndex >= rows.length) {
                return;
            }

            const reference = offset > 0 ? rows[targetIndex].nextSibling : rows[targetIndex];
            routeDestinationList.insertBefore(row, reference);
            syncRouteDestinationControls();
        }

        function removeRouteDestination(row) {
            const rows = routeDestinationList.querySelectorAll('.route-stop-row');
            if (rows.length === 1) {
                const input = row.querySelector('.route-destination-input');
                if (input) {
                    input.value = '';
                }
                return;
            }

            row.remove();
            syncRouteDestinationControls();
        }

        function routeDestinationValues() {
            return Array.from(routeDestinationList.querySelectorAll('.route-destination-input'))
                .map((input) => input.value.trim())
                .filter((value) => value !== '');
        }

        const routeAutocompleteState = new WeakMap();

        function routeAutocompletePanel(input) {
            const host = input.closest('.route-autocomplete');
            return host ? host.querySelector('.route-autocomplete-panel') : null;
        }

        function clearRouteAutocomplete(input) {
            const panel = routeAutocompletePanel(input);
            if (!panel) {
                return;
            }

            panel.innerHTML = '';
            panel.hidden = true;
        }

        function renderRouteAutocompleteOptions(input, results) {
            const panel = routeAutocompletePanel(input);
            if (!panel) {
                return;
            }

            if (!Array.isArray(results) || results.length === 0) {
                panel.innerHTML = '<div class="route-autocomplete-empty">No matches found.</div>';
                panel.hidden = false;
                return;
            }

            panel.innerHTML = results.map((result) => `
                <button type="button" class="route-autocomplete-option" data-route-match="${escapeHtml(JSON.stringify(result))}">
                    <strong>${escapeHtml(result.display_name || '')}</strong>
                    <span>${escapeHtml([result.class, result.type].filter(Boolean).join(' · ') || 'Geocoding match')}</span>
                </button>
            `).join('');

            panel.querySelectorAll('[data-route-match]').forEach((button) => {
                button.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    const payload = JSON.parse(button.getAttribute('data-route-match') || '{}');
                    input.value = payload.display_name || input.value;
                    clearRouteAutocomplete(input);
                });
            });

            panel.hidden = false;
        }

        function attachRouteAutocomplete(input) {
            if (!input || input.dataset.routeAutocompleteAttached === '1') {
                return;
            }

            input.dataset.routeAutocompleteAttached = '1';
            routeAutocompleteState.set(input, {
                sequence: 0,
                timer: null,
            });

            input.addEventListener('input', () => {
                const state = routeAutocompleteState.get(input);
                if (!state) {
                    return;
                }

                if (state.timer) {
                    window.clearTimeout(state.timer);
                }

                const query = input.value.trim();
                if (query.length < 3) {
                    clearRouteAutocomplete(input);
                    return;
                }

                state.sequence += 1;
                const currentSequence = state.sequence;
                state.timer = window.setTimeout(async () => {
                    const panel = routeAutocompletePanel(input);
                    if (!panel) {
                        return;
                    }

                    panel.innerHTML = '<div class="route-autocomplete-loading">Searching...</div>';
                    panel.hidden = false;

                    try {
                        const payload = await apiRequest(`/api/geo/search?q=${encodeURIComponent(query)}&limit=5`);
                        if (state.sequence !== currentSequence || input.value.trim() !== query) {
                            return;
                        }

                        const results = Array.isArray(payload.results) ? payload.results : [];
                        if (results.length === 1) {
                            input.value = results[0].display_name || query;
                            clearRouteAutocomplete(input);
                            return;
                        }

                        renderRouteAutocompleteOptions(input, results);
                    } catch (error) {
                        if (state.sequence === currentSequence) {
                            panel.innerHTML = `<div class="route-autocomplete-empty">${escapeHtml(error.message)}</div>`;
                            panel.hidden = false;
                        }
                    }
                }, 250);
            });

            input.addEventListener('focus', () => {
                if (input.value.trim().length >= 3) {
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });

            input.addEventListener('blur', () => {
                window.setTimeout(() => clearRouteAutocomplete(input), 150);
            });
        }

        function routeReturnMode() {
            return routeReturnReverses.checked ? 'reverses' : 'direct';
        }

        function renderRouteEmpty(message) {
            return `<div class="route-empty">${escapeHtml(message)}</div>`;
        }

        function renderRouteSummary(summary) {
            const cards = [
                ['Distance', formatRouteDistance(summary.distance_m || 0)],
                ['Drive Time', formatRouteDuration(summary.duration_s || 0)],
                ['Fuel Used', `${Number(summary.fuel_used_l || 0).toFixed(1)} L`],
                ['Fuel Fill', `${Number(summary.fuel_fill_l || 0).toFixed(1)} L`],
                ['Fuel Balance', `${Number(summary.fuel_balance_l || 0).toFixed(1)} L`],
            ];
            routeSummary.innerHTML = cards.map(([label, value]) => `
                <article class="route-summary-card">
                    <strong>${escapeHtml(value)}</strong>
                    <span>${escapeHtml(label)}</span>
                </article>
            `).join('');
        }

        function renderRouteResolved(stops) {
            if (!Array.isArray(stops) || stops.length === 0) {
                routeResolved.innerHTML = renderRouteEmpty('No resolved locations yet.');
                return;
            }

            routeResolved.innerHTML = `
                <table class="route-table">
                    <thead>
                        <tr>
                            <th>Stop</th>
                            <th>Query</th>
                            <th>Resolved</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${stops.map((stop, index) => `
                            <tr>
                                <td>${escapeHtml(index === 0 ? 'Origin' : `Destination ${index}`)}</td>
                                <td>${escapeHtml(stop.query || '')}</td>
                                <td>${escapeHtml(stop.display_name || 'No match')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        function renderRouteLegs(legs) {
            if (!Array.isArray(legs) || legs.length === 0) {
                routeLegs.innerHTML = renderRouteEmpty('Plan a route to see leg breakdowns.');
                return;
            }

            routeLegs.innerHTML = `
                <table class="route-table">
                    <thead>
                        <tr>
                            <th>Leg</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Distance</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${legs.map((leg, index) => `
                            <tr>
                                <td>${escapeHtml(String(index + 1))}</td>
                                <td>${escapeHtml(leg.from?.display_name || leg.from?.query || '')}</td>
                                <td>${escapeHtml(leg.to?.display_name || leg.to?.query || '')}</td>
                                <td>${escapeHtml(formatRouteDistance(leg.distance_m || 0))}</td>
                                <td>${escapeHtml(formatRouteDuration(leg.duration_s || 0))}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        async function resolveRouteLocation(query) {
            const payload = await apiRequest(`/api/geo/search?q=${encodeURIComponent(query)}&limit=1`);
            const result = Array.isArray(payload.results) ? payload.results[0] : null;
            if (!result) {
                throw new Error(`No geocoding result for "${query}"`);
            }
            return {
                query,
                display_name: result.display_name || query,
                lat: Number(result.lat),
                lon: Number(result.lon),
            };
        }

        async function requestRouteLeg(from, to) {
            const coordinates = `${from.lon},${from.lat};${to.lon},${to.lat}`;
            const payload = await apiRequest(`/api/route?coordinates=${encodeURIComponent(coordinates)}&steps=0`);
            const route = Array.isArray(payload.routes) ? payload.routes[0] : null;
            if (!route) {
                throw new Error('Route service returned no route.');
            }
            return {
                from,
                to,
                distance_m: Number(route.distance || 0),
                duration_s: Number(route.duration || 0),
            };
        }

        async function planRoute() {
            const originValue = routeOrigin.value.trim();
            const destinationValues = routeDestinationValues();
            const fuelFill = Number(routeFuelFill.value || 0);
            const fuelEconomy = Number(routeFuelEconomy.value || 0);

            if (originValue === '') {
                routeStatus.textContent = 'Origin is required.';
                return;
            }
            if (destinationValues.length === 0) {
                routeStatus.textContent = 'At least one destination is required.';
                return;
            }

            routePlan.disabled = true;
            routeStatus.textContent = 'Resolving locations and building route legs...';
            routeSummary.innerHTML = renderRouteEmpty('Planning route...');
            routeResolved.innerHTML = renderRouteEmpty('Resolving locations...');
            routeLegs.innerHTML = renderRouteEmpty('Building legs...');

            try {
                const origin = await resolveRouteLocation(originValue);
                const destinations = await Promise.all(destinationValues.map((value) => resolveRouteLocation(value)));
                const forwardStops = [origin, ...destinations];
                const legs = [];

                for (let index = 0; index < forwardStops.length - 1; index += 1) {
                    legs.push(await requestRouteLeg(forwardStops[index], forwardStops[index + 1]));
                }

                if (routeReturnMode() === 'reverses') {
                    for (let index = forwardStops.length - 1; index > 0; index -= 1) {
                        legs.push(await requestRouteLeg(forwardStops[index], forwardStops[index - 1]));
                    }
                } else {
                    legs.push(await requestRouteLeg(forwardStops[forwardStops.length - 1], origin));
                }

                const totalDistance = legs.reduce((sum, leg) => sum + leg.distance_m, 0);
                const totalDuration = legs.reduce((sum, leg) => sum + leg.duration_s, 0);
                const fuelUsed = fuelEconomy > 0 ? (totalDistance / 1000) * (fuelEconomy / 100) : 0;
                const fuelBalance = fuelFill > 0 ? fuelFill - fuelUsed : 0;

                renderRouteSummary({
                    distance_m: totalDistance,
                    duration_s: totalDuration,
                    fuel_used_l: fuelUsed,
                    fuel_fill_l: fuelFill,
                    fuel_balance_l: fuelFill > 0 ? fuelBalance : 0,
                });
                renderRouteResolved([origin, ...destinations]);
                renderRouteLegs(legs);

                const returnMode = routeReturnMode() === 'reverses'
                    ? 'Return reverses path'
                    : 'Return direct to origin';
                routeStatus.textContent = `Planned ${legs.length} legs using ${returnMode}.`;
            } catch (error) {
                routeStatus.textContent = error.message;
                routeSummary.innerHTML = renderRouteEmpty(error.message);
                routeResolved.innerHTML = renderRouteEmpty(error.message);
                routeLegs.innerHTML = renderRouteEmpty(error.message);
            } finally {
                routePlan.disabled = false;
            }
        }

        function resetRoutePlanner() {
            routeOrigin.value = '';
            routeFuelFill.value = '';
            routeFuelEconomy.value = '';
            routeReturnDirect.checked = true;
            routeReturnReverses.checked = false;
            routeDestinationList.innerHTML = '';
            routeDestinationCounter = 0;
            addRouteDestination('');
            routeStatus.textContent = 'Enter a trip to build a route.';
            routeSummary.innerHTML = renderRouteEmpty('No route planned yet.');
            routeResolved.innerHTML = renderRouteEmpty('No route planned yet.');
            routeLegs.innerHTML = renderRouteEmpty('No route planned yet.');
        }

        function formatBytes(bytes) {
            const value = Number(bytes || 0);
            if (value < 1024) {
                return `${value} B`;
            }

            const units = ['KB', 'MB', 'GB', 'TB'];
            let size = value / 1024;
            let unit = 0;
            while (size >= 1024 && unit < units.length - 1) {
                size /= 1024;
                unit += 1;
            }

            return `${size.toFixed(size >= 10 ? 1 : 2)} ${units[unit]}`;
        }

        function formatPrice(value) {
            const amount = Number(value || 0);
            return `${amount.toFixed(1)} c/L`;
        }

        function formatCompactDate(value) {
            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }
            return parsed.toLocaleDateString('en-AU', { day: '2-digit', month: 'short' });
        }

        function formatDateTime(value) {
            const parsed = new Date(value.replace(' ', 'T') + 'Z');
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }
            return parsed.toLocaleString('en-AU', {
                day: '2-digit',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
            });
        }

        function setSelectOptions(select, options, selectedValue) {
            select.innerHTML = '';
            options.forEach((option) => {
                const element = document.createElement('option');
                element.value = option.value;
                element.textContent = option.label;
                if (option.value === selectedValue) {
                    element.selected = true;
                }
                select.appendChild(element);
            });
        }

        async function loadFuelOptions() {
            if (fuelOptions) {
                return fuelOptions;
            }
            fuelOptions = await apiRequest('/api/fuel/options');
            return fuelOptions;
        }

        function filteredFuelOptions() {
            if (!fuelOptions) {
                return [{ value: '', label: 'All Fuels' }];
            }
            const state = fuelState.value || '';
            const source = state === 'QLD' ? 'qld' : (state === 'NSW' ? 'nsw' : (state === 'TAS' ? 'tas' : 'all'));
            return fuelOptions.fuels.filter((item) => {
                if (item.value === '') {
                    return true;
                }
                if (source !== 'all' && item.source !== source && !(source === 'tas' && item.state === 'TAS')) {
                    return false;
                }
                if (state !== '' && item.state !== state) {
                    return false;
                }
                return true;
            });
        }

        function syncFuelSelectors() {
            const currentFuel = fuelType.value;
            const options = filteredFuelOptions();
            const desiredDefaultFuel = fuelState.value === 'QLD'
                ? '3'
                : ((fuelState.value === 'NSW' || fuelState.value === 'TAS') ? 'DL' : '');
            const fallbackFuel = currentFuel !== '' && options.find((item) => item.value === currentFuel)
                ? currentFuel
                : (options.find((item) => item.value === desiredDefaultFuel)?.value || '');
            setSelectOptions(fuelType, options, fallbackFuel);
        }

        function selectedFuelFilters() {
            return new URLSearchParams({
                state: fuelState.value || '',
                fuel: fuelType.value || '',
            });
        }

        async function handleFuelFilterChange() {
            syncFuelSelectors();
            await loadFuelDashboard();
        }

        function renderFuelSummary(summary) {
            fuelSummary.innerHTML = '';
            const cards = [
                ['QLD', summary.qld],
                ['NSW', summary.nsw],
                ['TAS', summary.tas],
            ];
            cards.forEach(([label, item]) => {
                const card = document.createElement('article');
                card.className = 'summary-card';
                card.innerHTML = `
                    <strong>${escapeHtml(String(item.current_prices || 0))}</strong>
                    <span>${escapeHtml(label)} current prices</span>
                    <span>${escapeHtml(String(item.stations || 0))} stations</span>
                    <span>${escapeHtml(item.latest_update || 'No data yet')}</span>
                `;
                fuelSummary.appendChild(card);
            });
        }

        function chartEmpty(message) {
            return `<div class="chart-empty">${escapeHtml(message)}</div>`;
        }

        function renderLineChart(container, meta, series) {
            if (!Array.isArray(series) || series.length === 0) {
                container.innerHTML = chartEmpty('No weekly data available for this filter.');
                meta.innerHTML = '';
                return;
            }

            const values = series.map((item) => Number(item.average_price));
            const min = Math.min(...values);
            const max = Math.max(...values);
            const spread = Math.max(max - min, 1);
            const width = 640;
            const height = 280;
            const padding = { top: 20, right: 16, bottom: 32, left: 48 };
            const plotWidth = width - padding.left - padding.right;
            const plotHeight = height - padding.top - padding.bottom;
            const points = series.map((item, index) => {
                const x = padding.left + (plotWidth * index / Math.max(series.length - 1, 1));
                const y = padding.top + ((max - Number(item.average_price)) / spread) * plotHeight;
                return { x, y, item };
            });
            const polyline = points.map((point) => `${point.x},${point.y}`).join(' ');
            const area = [
                `${padding.left},${height - padding.bottom}`,
                ...points.map((point) => `${point.x},${point.y}`),
                `${points[points.length - 1].x},${height - padding.bottom}`,
            ].join(' ');

            const yTicks = [min, min + spread / 2, max];
            container.innerHTML = `
                <svg class="chart" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-label="Weekly fuel price trend">
                    <rect x="0" y="0" width="${width}" height="${height}" fill="transparent"></rect>
                    ${yTicks.map((tick) => {
                        const y = padding.top + ((max - tick) / spread) * plotHeight;
                        return `<g>
                            <line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" stroke="#e5edf3" stroke-width="1"></line>
                            <text x="${padding.left - 8}" y="${y + 4}" fill="#5b6775" font-size="11" text-anchor="end">${tick.toFixed(1)}</text>
                        </g>`;
                    }).join('')}
                    <polygon points="${area}" fill="rgba(15,118,110,0.12)"></polygon>
                    <polyline points="${polyline}" fill="none" stroke="#0f766e" stroke-width="3"></polyline>
                    ${points.map((point) => `
                        <g>
                            <circle cx="${point.x}" cy="${point.y}" r="4" fill="#0f766e"></circle>
                            <title>${formatCompactDate(point.item.bucket_date)}: ${formatPrice(point.item.average_price)}</title>
                        </g>
                    `).join('')}
                    ${points.filter((_, index) => index % Math.ceil(series.length / 6) === 0 || index === points.length - 1).map((point) => `
                        <text x="${point.x}" y="${height - 10}" fill="#5b6775" font-size="11" text-anchor="middle">${escapeHtml(formatCompactDate(point.item.bucket_date))}</text>
                    `).join('')}
                </svg>
            `;
            meta.innerHTML = `
                <span>Low: ${formatPrice(min)}</span>
                <span>High: ${formatPrice(max)}</span>
                <span>Points: ${series.length}</span>
            `;
        }

        function renderBarChart(container, meta, series) {
            if (!Array.isArray(series) || series.length === 0) {
                container.innerHTML = chartEmpty('No monthly data available for this filter.');
                meta.innerHTML = '';
                return;
            }

            const values = series.map((item) => Number(item.average_price));
            const min = Math.min(...values);
            const max = Math.max(...values);
            const spread = Math.max(max - min, 1);
            const width = 640;
            const height = 280;
            const padding = { top: 20, right: 16, bottom: 40, left: 48 };
            const plotWidth = width - padding.left - padding.right;
            const plotHeight = height - padding.top - padding.bottom;
            const barWidth = Math.max(16, plotWidth / Math.max(series.length * 1.6, 1));

            container.innerHTML = `
                <svg class="chart" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" aria-label="Monthly fuel price trend">
                    ${[min, min + spread / 2, max].map((tick) => {
                        const y = padding.top + ((max - tick) / spread) * plotHeight;
                        return `<g>
                            <line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" stroke="#e5edf3" stroke-width="1"></line>
                            <text x="${padding.left - 8}" y="${y + 4}" fill="#5b6775" font-size="11" text-anchor="end">${tick.toFixed(1)}</text>
                        </g>`;
                    }).join('')}
                    ${series.map((item, index) => {
                        const x = padding.left + (plotWidth * index / Math.max(series.length, 1)) + 6;
                        const barHeight = ((Number(item.average_price) - min) / spread) * plotHeight;
                        const y = height - padding.bottom - barHeight;
                        return `
                            <g>
                                <rect x="${x}" y="${y}" width="${barWidth}" height="${Math.max(barHeight, 2)}" rx="4" fill="#0f766e"></rect>
                                <title>${formatCompactDate(item.bucket_date)}: ${formatPrice(item.average_price)}</title>
                                <text x="${x + barWidth / 2}" y="${height - 12}" fill="#5b6775" font-size="11" text-anchor="middle">${escapeHtml(formatCompactDate(item.bucket_date))}</text>
                            </g>
                        `;
                    }).join('')}
                </svg>
            `;
            meta.innerHTML = `
                <span>Low: ${formatPrice(min)}</span>
                <span>High: ${formatPrice(max)}</span>
                <span>Months: ${series.length}</span>
            `;
        }

        function renderSnapshot(rows) {
            if (!Array.isArray(rows) || rows.length === 0) {
                fuelSnapshot.innerHTML = chartEmpty('No current prices available for this filter.');
                return;
            }

            fuelSnapshot.innerHTML = `
                <table class="snapshot-table">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Fuel</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.slice(0, 8).map((row) => `
                            <tr>
                                <td>${escapeHtml(row.station_name)}<br><span>${escapeHtml(`${row.state} · ${row.source.toUpperCase()}`)}</span></td>
                                <td>${escapeHtml(row.fuel_name)}</td>
                                <td><span class="snapshot-price">${escapeHtml(formatPrice(row.price))}</span><br><span>${escapeHtml(formatDateTime(row.updated_at))}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        async function loadFuelDashboard() {
            fuelStatus.textContent = 'Loading fuel dashboard...';
            try {
                const options = await loadFuelOptions();
                if (!fuelState.options.length) {
                    setSelectOptions(fuelState, options.states, 'QLD');
                    syncFuelSelectors();
                }

                const filters = selectedFuelFilters();
                const [sources, current, weekly, monthly] = await Promise.all([
                    apiRequest('/api/fuel/sources'),
                    apiRequest(`/api/fuel/current?${filters.toString()}&limit=8`),
                    apiRequest(`/api/fuel/history?${filters.toString()}&period=weekly`),
                    apiRequest(`/api/fuel/history?${filters.toString()}&period=monthly`),
                ]);

                renderFuelSummary(sources.sources || {});
                renderLineChart(fuelWeeklyChart, fuelWeeklyMeta, weekly.series || []);
                renderBarChart(fuelMonthlyChart, fuelMonthlyMeta, monthly.series || []);
                renderSnapshot(current.rows || []);
                fuelStatus.textContent = `Loaded ${Array.isArray(current.rows) ? current.rows.length : 0} current records for the selected filter.`;
            } catch (error) {
                fuelStatus.textContent = error.message;
                fuelWeeklyChart.innerHTML = chartEmpty(error.message);
                fuelMonthlyChart.innerHTML = chartEmpty(error.message);
                fuelSnapshot.innerHTML = chartEmpty(error.message);
            }
        }

        function renderContainers(services) {
            containerGrid.innerHTML = '';

            if (services.length === 0) {
                containerGrid.innerHTML = '<p>No Compose services found for this project.</p>';
                selectedContainerId = null;
                selectedContainerRestartable = false;
                restartContainer.disabled = true;
                return;
            }

            let selectedFound = false;

            services.forEach((service) => {
                const container = service.container || {};
                const hasContainer = Boolean(service.has_container && container.id);
                const card = document.createElement('article');
                card.className = `container-card${container.id === selectedContainerId ? ' selected' : ''}`;

                if (container.id === selectedContainerId) {
                    selectedFound = true;
                }

                const state = service.display_state || (hasContainer ? (container.state || 'unknown') : 'not created');
                const statusClass = service.display_badge || (container.state === 'running' ? 'running' : (container.state === 'exited' ? 'exited' : 'planned'));
                const statusText = service.display_status || container.status || 'Not started';
                const lifecycle = service.kind === 'setup_job' ? 'Setup job' : 'Runtime service';
                const dataPaths = Array.isArray(service.data_paths) && service.data_paths.length > 0
                    ? service.data_paths.join(', ')
                    : 'None configured';
                const dataStatus = service.data_status || {};
                const artifactStatus = service.artifacts || {};
                const dataSummary = Number.isFinite(dataStatus.total) && dataStatus.total > 0
                    ? `${dataStatus.ready || 0}/${dataStatus.total} paths present`
                    : 'No managed data paths';
                const artifactSummary = Number.isFinite(artifactStatus.total) && artifactStatus.total > 0
                    ? `${artifactStatus.ready || 0}/${artifactStatus.total} outputs ready`
                    : 'No output checks';
                const source = service.source ? `<span>Source: ${escapeHtml(service.source)}</span>` : '';
                const updates = service.updates ? `<span>Updates: ${escapeHtml(service.updates)}</span>` : '';
                const logLabel = hasContainer ? 'View Logs' : (service.kind === 'setup_job' ? 'Prepared' : 'Unavailable');
                card.innerHTML = `
                    <h2>${escapeHtml(service.title || service.service)}</h2>
                    <span class="badge ${statusClass}">${escapeHtml(state)}</span>
                    <div class="container-meta">
                        <span>Service: ${escapeHtml(service.service)}</span>
                        <span>Lifecycle: ${escapeHtml(lifecycle)}</span>
                        <span>Role: ${escapeHtml(service.role || '')}</span>
                        <span>Profile: ${escapeHtml(service.profile || 'default')}</span>
                        <span>Name: ${escapeHtml(container.name || 'No container created')}</span>
                        <span>Image: ${escapeHtml(container.image || 'Pending image pull/build')}</span>
                        <span>Status: ${escapeHtml(statusText)}</span>
                        <span>Ports: ${escapeHtml(renderPorts(container.ports))}</span>
                        <span>Data: ${escapeHtml(dataPaths)}</span>
                        <span>Data State: ${escapeHtml(dataSummary)}</span>
                        <span>Outputs: ${escapeHtml(artifactSummary)}</span>
                        <span>Start: ${escapeHtml(service.start_command || 'Not configured')}</span>
                        ${source}
                        ${updates}
                    </div>
                    <button class="button" type="button" ${hasContainer ? '' : 'disabled'}>${escapeHtml(logLabel)}</button>
                `;

                card.querySelector('button').addEventListener('click', () => {
                    if (!hasContainer) {
                        return;
                    }
                    selectedContainerId = container.id;
                    selectedContainerRestartable = Boolean(service.allow_restart);
                    restartContainer.disabled = !selectedContainerRestartable;
                    renderContainers(services);
                    loadLogs(container.id);
                });

                containerGrid.appendChild(card);
            });

            if (!selectedFound) {
                selectedContainerId = null;
                selectedContainerRestartable = false;
            }
            restartContainer.disabled = !selectedContainerRestartable;
        }

        async function loadContainers() {
            containerStatus.textContent = 'Loading container status...';
            try {
                const payload = await apiRequest('/api/docker/status');
                renderContainers(payload.services || []);
                const disk = payload.disk || {};
                containerStatus.textContent = `Project: ${payload.project}. Services: ${(payload.services || []).length}. Containers: ${(payload.containers || []).length}. Images: ${disk.image_count || 0}. Build cache: ${formatBytes(disk.build_cache_size)}.`;
            } catch (error) {
                containerStatus.textContent = error.message;
            }
        }

        async function loadLogs(containerId) {
            containerLogs.textContent = 'Loading logs...';
            try {
                const payload = await apiRequest(`/api/docker/containers/${containerId}/logs?tail=200`);
                containerLogs.textContent = payload.logs || 'No log output.';
            } catch (error) {
                containerLogs.textContent = error.message;
            }
        }

        async function runAction(action, confirmText) {
            if (!window.confirm(confirmText)) {
                return;
            }

            containerStatus.textContent = 'Running Docker action...';
            try {
                const payload = await apiRequest('/api/docker/prune', {
                    method: 'POST',
                    body: JSON.stringify({ action }),
                });
                containerStatus.textContent = payload.message || 'Action complete.';
                await loadContainers();
            } catch (error) {
                containerStatus.textContent = error.message;
            }
        }

        refreshContainers.addEventListener('click', loadContainers);
        restartContainer.addEventListener('click', async () => {
            if (!selectedContainerId || !window.confirm('Restart the selected container?')) {
                return;
            }

            containerStatus.textContent = 'Restarting container...';
            try {
                await apiRequest(`/api/docker/containers/${selectedContainerId}/restart`, { method: 'POST' });
                containerStatus.textContent = 'Container restarted.';
                await loadContainers();
                await loadLogs(selectedContainerId);
            } catch (error) {
                containerStatus.textContent = error.message;
            }
        });
        pruneStopped.addEventListener('click', () => runAction(
            'stopped_project_containers',
            'Remove stopped containers that belong to this Compose project?'
        ));
        pruneImages.addEventListener('click', () => runAction(
            'dangling_images',
            'Remove dangling Docker images? This does not remove tagged images.'
        ));

        routeAddDestination.addEventListener('click', () => addRouteDestination(''));
        routePlan.addEventListener('click', planRoute);
        routeReset.addEventListener('click', resetRoutePlanner);

        fuelState.addEventListener('change', handleFuelFilterChange);
        fuelType.addEventListener('change', loadFuelDashboard);
        refreshFuelDashboard.addEventListener('click', loadFuelDashboard);
        attachRouteAutocomplete(routeOrigin);
        resetRoutePlanner();
        loadFuelDashboard();
    </script>
</body>
</html>
        <?php
        return;
    }

    if ($path === '/api/health') {
        $database = 'unavailable';
        try {
            fuelauPdo()->query('SELECT 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        fuelauJsonResponse([
            'service' => 'fuelau-api',
            'status' => 'ok',
            'database' => $database,
            'time' => gmdate(DATE_ATOM),
        ]);
    }

    if ($path === '/api/docker/status') {
        fuelauDockerApiResponse([
            'project' => fuelauDockerProject(),
            'services' => fuelauDockerServices(),
            'containers' => fuelauDockerContainers(),
            'disk' => fuelauDockerDiskSummary(),
        ]);
    }

    if ($path === '/api/services/status') {
        fuelauJsonResponse([
            'service' => 'fuelau-api',
            'upstreams' => fuelauServiceStatus(),
        ]);
    }

    if ($path === '/api/geo/search') {
        $query = trim((string) ($_GET['q'] ?? ''));
        if ($query === '') {
            fuelauJsonResponse([
                'error' => 'invalid_query',
                'message' => 'Missing required query parameter: q',
            ], 400);
        }

        fuelauJsonResponse([
            'query' => $query,
            'results' => fuelauNominatimSearch($query, (int) ($_GET['limit'] ?? 10)),
        ]);
    }

    if ($path === '/api/geo/reverse') {
        $latitude = $_GET['lat'] ?? null;
        $longitude = $_GET['lon'] ?? null;
        if (!is_numeric((string) $latitude) || !is_numeric((string) $longitude)) {
            fuelauJsonResponse([
                'error' => 'invalid_query',
                'message' => 'lat and lon are required numeric query parameters.',
            ], 400);
        }

        fuelauJsonResponse([
            'result' => fuelauNominatimReverse((float) $latitude, (float) $longitude),
        ]);
    }

    if ($path === '/api/route') {
        $coordinates = trim((string) ($_GET['coordinates'] ?? ''));
        if ($coordinates === '') {
            fuelauJsonResponse([
                'error' => 'invalid_query',
                'message' => 'Missing required query parameter: coordinates',
            ], 400);
        }

        fuelauJsonResponse(
            fuelauRoutePlan(
                fuelauParseCoordinates($coordinates),
                (($_GET['steps'] ?? '1') !== '0')
            )
        );
    }

    if ($path === '/api/fuel/sources') {
        fuelauJsonResponse([
            'sources' => fuelauFuelSourceSummary(fuelauPdo()),
        ]);
    }

    if ($path === '/api/fuel/options') {
        fuelauJsonResponse(fuelauFuelOptions(fuelauPdo()));
    }

    if ($path === '/api/fuel/current') {
        $pdo = fuelauPdo();
        $filters = fuelauFuelRequestFilters();
        fuelauJsonResponse([
            'filters' => $filters,
            'rows' => fuelauNormalizedFuelRows($pdo, $filters),
        ]);
    }

    if ($path === '/api/fuel/history') {
        $pdo = fuelauPdo();
        $filters = fuelauHistoricalFilters();
        fuelauJsonResponse([
            'filters' => $filters,
            'series' => fuelauHistoricalSeries($pdo, $filters),
        ]);
    }

    if (preg_match('#^/api/docker/containers/([a-f0-9]+)/logs$#', $path, $matches)) {
        $containerId = fuelauDockerContainerId($matches[1]);
        $tail = max(10, min(1000, (int) ($_GET['tail'] ?? 200)));
        $response = fuelauDockerRequest(
            'GET',
            "/containers/{$containerId}/logs?stdout=1&stderr=1&timestamps=1&tail={$tail}"
        );
        fuelauDockerApiResponse([
            'id' => substr($containerId, 0, 12),
            'logs' => fuelauDockerLogText((string) ($response['raw'] ?? '')),
        ]);
    }

    if (preg_match('#^/api/docker/containers/([a-f0-9]+)/restart$#', $path, $matches)) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            fuelauDockerApiResponse(['error' => 'method_not_allowed'], 405);
        }

        $containerId = fuelauDockerContainerId($matches[1]);
        fuelauDockerRequest('POST', "/containers/{$containerId}/restart?t=10");
        fuelauDockerApiResponse([
            'id' => substr($containerId, 0, 12),
            'status' => 'restarted',
        ]);
    }

    if ($path === '/api/docker/prune') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            fuelauDockerApiResponse(['error' => 'method_not_allowed'], 405);
        }

        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        $action = is_array($input) ? (string) ($input['action'] ?? '') : '';

        if ($action === 'stopped_project_containers') {
            $filters = fuelauDockerFilters([
                'label' => ['com.docker.compose.project=' . fuelauDockerProject()],
            ]);
            $result = fuelauDockerRequest('POST', "/containers/prune?filters={$filters}");
            fuelauDockerApiResponse([
                'message' => 'Stopped project containers pruned.',
                'result' => $result,
            ]);
        }

        if ($action === 'dangling_images') {
            $filters = fuelauDockerFilters(['dangling' => ['true']]);
            $result = fuelauDockerRequest('POST', "/images/prune?filters={$filters}");
            fuelauDockerApiResponse([
                'message' => 'Dangling images pruned.',
                'result' => $result,
            ]);
        }

        fuelauDockerApiResponse([
            'error' => 'invalid_prune_action',
            'message' => 'Unsupported cleanup action.',
        ], 400);
    }

    fuelauJsonResponse([
        'error' => 'not_found',
        'path' => $path,
    ], 404);
} catch (Throwable $exception) {
    fuelauJsonResponse([
        'error' => 'server_error',
        'message' => $exception->getMessage(),
    ], 500);
}
