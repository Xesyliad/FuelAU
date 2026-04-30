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
    <link
        rel="stylesheet"
        href="/resources/maplibre-gl.css"
    >
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

        .badge.ok {
            background: #dcfce7;
            color: #166534;
        }

        .badge.warn {
            background: #fef3c7;
            color: #92400e;
        }

        .badge.idle {
            background: #e0f2fe;
            color: #075985;
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
            width: 100%;
            box-sizing: border-box;
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
            width: 100%;
        }

        .field input[type="text"],
        .field input[type="number"] {
            width: 100%;
            box-sizing: border-box;
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
            min-width: 100%;
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
            grid-template-columns: minmax(320px, 2.2fr) minmax(180px, 1fr) minmax(180px, 1fr) minmax(180px, 1fr);
            gap: 12px;
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
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            background: #fbfcfd;
        }

        .route-stop-row.is-dragging {
            opacity: 0.55;
        }

        .route-stop-actions {
            display: grid;
            grid-auto-flow: column;
            gap: 6px;
            align-items: center;
            justify-content: end;
        }

        .route-stop-actions .button {
            min-width: 56px;
            padding: 0 10px;
            font-weight: 700;
        }

        .route-stop-handle,
        .route-stop-remove {
            width: 38px;
            height: 38px;
            min-width: 38px;
            display: inline-grid;
            place-items: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font-weight: 800;
            line-height: 1;
        }

        .route-stop-handle {
            cursor: grab;
        }

        .route-stop-handle:active {
            cursor: grabbing;
        }

        .route-stop-handle:disabled,
        .route-stop-remove:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .route-stop-remove {
            color: var(--danger);
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

        .route-map {
            width: 100%;
            aspect-ratio: 1 / 1;
            display: block;
            border: 1px solid var(--border);
            border-radius: 8px;
            background:
                radial-gradient(circle at center, rgba(15, 118, 110, 0.02), rgba(15, 118, 110, 0.00)),
                #fff;
        }

        .route-map-frame {
            width: 100%;
            aspect-ratio: 1 / 1;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            background:
                radial-gradient(circle at center, rgba(15, 118, 110, 0.02), rgba(15, 118, 110, 0.00)),
                #fff;
        }

        .route-map-frame > .route-empty,
        .route-map-frame > .route-map {
            width: 100%;
            height: 100%;
            border: 0;
            border-radius: 0;
        }

        .route-map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            color: var(--muted);
            font-size: 12px;
        }

        .route-map-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .route-map-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        .route-map-line {
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .route-fuel-marker {
            display: inline-flex;
            align-items: stretch;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 6px 18px rgba(22, 33, 45, 0.12);
            color: var(--text);
            max-width: 280px;
            pointer-events: none;
        }

        .route-fuel-marker-icon {
            width: 22px;
            height: 22px;
            margin-top: 2px;
            border-radius: 6px;
            background: var(--route-fuel-color, #b45309);
            position: relative;
            flex: 0 0 auto;
        }

        .route-fuel-marker-icon::before {
            content: "";
            position: absolute;
            left: 5px;
            top: 4px;
            width: 8px;
            height: 10px;
            border: 2px solid #fff;
            border-radius: 2px;
            box-sizing: border-box;
        }

        .route-fuel-marker-icon::after {
            content: "";
            position: absolute;
            right: -4px;
            top: 8px;
            width: 10px;
            height: 2px;
            background: #fff;
            box-shadow: 4px 6px 0 0 #fff;
            transform: rotate(24deg);
            transform-origin: center;
        }

        .route-fuel-marker-copy {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .route-fuel-marker-copy strong {
            font-size: 12px;
            line-height: 1.2;
            font-weight: 700;
            white-space: normal;
        }

        .route-fuel-marker-copy span {
            font-size: 11px;
            line-height: 1.2;
            color: var(--muted);
            white-space: normal;
        }

        .route-breakdown-row td {
            vertical-align: top;
        }

        .route-breakdown-step {
            display: block;
            font-weight: 700;
            color: var(--text);
        }

        .route-breakdown-subtext {
            display: block;
            color: var(--muted);
            margin-top: 3px;
            font-size: 11px;
        }

        .route-breakdown-stop {
            color: #7c2d12;
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

        .fuel-map-panel {
            display: grid;
            gap: 10px;
        }

        .fuel-map-frame {
            width: 100%;
            aspect-ratio: 16 / 9;
            min-height: 420px;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            background:
                radial-gradient(circle at center, rgba(15, 118, 110, 0.02), rgba(15, 118, 110, 0.00)),
                #fff;
        }

        .fuel-map-frame > .route-empty {
            width: 100%;
            height: 100%;
            border: 0;
            border-radius: 0;
        }

        .fuel-map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
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
    <script>
        window.fuelauMapConfig = <?= json_encode(
            fuelauMapTileConfig(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>;
    </script>
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
                        </div>

                        <div class="route-actions">
                            <button class="button primary" type="button" id="route-plan">Plan Route</button>
                            <button class="button" type="button" id="route-test">Load Test Cities</button>
                            <button class="button" type="button" id="route-reset">Reset</button>
                        </div>

                        <div class="status-line" id="route-status">Enter a trip to build a route.</div>
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

    <script src="/resources/maplibre-gl.js"></script>
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
        const fuelRegion = document.getElementById('fuel-region');
        const fuelType = document.getElementById('fuel-type');
        const fuelStatus = document.getElementById('fuel-status');
        const fuelSummary = document.getElementById('fuel-summary');
        const fuelWeeklyChart = document.getElementById('fuel-weekly-chart');
        const fuelWeeklyMeta = document.getElementById('fuel-weekly-meta');
        const fuelMonthlyChart = document.getElementById('fuel-monthly-chart');
        const fuelMonthlyMeta = document.getElementById('fuel-monthly-meta');
        const fuelSnapshot = document.getElementById('fuel-snapshot');
        const refreshFuelDashboard = document.getElementById('refresh-fuel-dashboard');
        const fuelMap = document.getElementById('fuel-map');
        const fuelMapLegend = document.getElementById('fuel-map-legend');
        const routeOrigin = document.getElementById('route-origin');
        const routeOriginResults = document.getElementById('route-origin-results');
        const routeFuelType = document.getElementById('route-fuel-type');
        const routeFuelFill = document.getElementById('route-fuel-fill');
        const routeFuelEconomy = document.getElementById('route-fuel-economy');
        const routeDestinationList = document.getElementById('route-destination-list');
        const routeAddDestination = document.getElementById('route-add-destination');
        const routeReturnReverses = document.getElementById('route-return-reverses');
        const routeReturnDirect = document.getElementById('route-return-direct');
        const routePlan = document.getElementById('route-plan');
        const routeTest = document.getElementById('route-test');
        const routeReset = document.getElementById('route-reset');
        const routeStatus = document.getElementById('route-status');
        const routeSummary = document.getElementById('route-summary');
        const routeMap = document.getElementById('route-map');
        const routeMapLegend = document.getElementById('route-map-legend');
        const routeLegs = document.getElementById('route-legs');
        let selectedContainerId = null;
        let selectedContainerRestartable = false;
        let fuelOptions = null;
        let routeDestinationCounter = 0;
        let draggedRouteDestinationRow = null;
        let fuelMapInstance = null;
        let fuelMapReady = false;
        let fuelMapPopup = null;
        let fuelMapPendingData = null;
        let routeMapInstance = null;
        let routeFuelMarkers = [];
        const fuelSelectionCookieName = 'fuelau_selected_fuel';
        const fuelRegionCookieName = 'fuelau_selected_region';
        const routePlannerStateKey = 'fuelau_route_planner_state_v1';
        const activeTabKey = 'fuelau_active_tab_v1';

        const fuelRegionCatalog = {
            QLD: [
                { key: 'brisbane', label: 'Brisbane', lat: -27.4698, lon: 153.0251, radius_km: 80 },
                { key: 'gold-coast', label: 'Gold Coast', lat: -28.0167, lon: 153.4000, radius_km: 45 },
                { key: 'sunshine-coast', label: 'Sunshine Coast', lat: -26.6500, lon: 153.0667, radius_km: 45 },
                { key: 'ipswich', label: 'Ipswich', lat: -27.6170, lon: 152.7600, radius_km: 35 },
                { key: 'toowoomba', label: 'Toowoomba', lat: -27.5606, lon: 151.9539, radius_km: 40 },
                { key: 'cairns', label: 'Cairns', lat: -16.9186, lon: 145.7781, radius_km: 35 },
                { key: 'townsville', label: 'Townsville', lat: -19.2589, lon: 146.8169, radius_km: 45 },
                { key: 'mackay', label: 'Mackay', lat: -21.1411, lon: 149.1860, radius_km: 35 },
                { key: 'rockhampton', label: 'Rockhampton', lat: -23.3781, lon: 150.5130, radius_km: 35 },
                { key: 'bundaberg', label: 'Bundaberg', lat: -24.8662, lon: 152.3519, radius_km: 30 },
                { key: 'hervey-bay', label: 'Hervey Bay', lat: -25.2875, lon: 152.8400, radius_km: 30 },
                { key: 'gladstone', label: 'Gladstone', lat: -23.8489, lon: 151.2640, radius_km: 30 },
            ],
            NSW: [
                { key: 'sydney', label: 'Sydney', lat: -33.8688, lon: 151.2093, radius_km: 80 },
                { key: 'newcastle', label: 'Newcastle', lat: -32.9283, lon: 151.7817, radius_km: 45 },
                { key: 'wollongong', label: 'Wollongong', lat: -34.4278, lon: 150.8931, radius_km: 45 },
                { key: 'central-coast', label: 'Central Coast', lat: -33.4250, lon: 151.3430, radius_km: 50 },
                { key: 'maitland', label: 'Maitland', lat: -32.7330, lon: 151.5560, radius_km: 35 },
                { key: 'albury', label: 'Albury', lat: -36.0737, lon: 146.9135, radius_km: 30 },
                { key: 'wagga-wagga', label: 'Wagga Wagga', lat: -35.1150, lon: 147.3670, radius_km: 35 },
                { key: 'tamworth', label: 'Tamworth', lat: -31.0922, lon: 150.9291, radius_km: 35 },
                { key: 'dubbo', label: 'Dubbo', lat: -32.2569, lon: 148.6011, radius_km: 35 },
                { key: 'port-macquarie', label: 'Port Macquarie', lat: -31.4300, lon: 152.9080, radius_km: 35 },
                { key: 'coffs-harbour', label: 'Coffs Harbour', lat: -30.2963, lon: 153.1140, radius_km: 35 },
                { key: 'queanbeyan', label: 'Queanbeyan', lat: -35.3540, lon: 149.2320, radius_km: 25 },
            ],
            VIC: [
                { key: 'melbourne', label: 'Melbourne', lat: -37.8136, lon: 144.9631, radius_km: 80 },
                { key: 'geelong', label: 'Geelong', lat: -38.1499, lon: 144.3617, radius_km: 35 },
                { key: 'ballarat', label: 'Ballarat', lat: -37.5622, lon: 143.8503, radius_km: 35 },
                { key: 'bendigo', label: 'Bendigo', lat: -36.7570, lon: 144.2794, radius_km: 35 },
                { key: 'shepparton', label: 'Shepparton', lat: -36.3805, lon: 145.3995, radius_km: 30 },
                { key: 'mildura', label: 'Mildura', lat: -34.1850, lon: 142.1625, radius_km: 30 },
                { key: 'wodonga', label: 'Wodonga', lat: -36.1248, lon: 146.8881, radius_km: 25 },
                { key: 'warrnambool', label: 'Warrnambool', lat: -38.3800, lon: 142.4800, radius_km: 25 },
                { key: 'traralgon', label: 'Traralgon', lat: -38.1951, lon: 146.5400, radius_km: 25 },
                { key: 'wangaratta', label: 'Wangaratta', lat: -36.3588, lon: 146.3200, radius_km: 25 },
                { key: 'sale', label: 'Sale', lat: -38.1106, lon: 147.0680, radius_km: 25 },
                { key: 'morwell', label: 'Morwell', lat: -38.2346, lon: 146.3910, radius_km: 25 },
            ],
            TAS: [
                { key: 'hobart', label: 'Hobart', lat: -42.8821, lon: 147.3272, radius_km: 50 },
                { key: 'launceston', label: 'Launceston', lat: -41.4332, lon: 147.1441, radius_km: 35 },
                { key: 'devonport', label: 'Devonport', lat: -41.1782, lon: 146.3513, radius_km: 30 },
                { key: 'burnie', label: 'Burnie', lat: -41.0550, lon: 145.9150, radius_km: 25 },
                { key: 'ulverstone', label: 'Ulverstone', lat: -41.1610, lon: 146.1810, radius_km: 25 },
            ],
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                activateTab(tab.id);
            });
        });

        function saveActiveTab(tabId) {
            try {
                window.localStorage.setItem(activeTabKey, tabId);
            } catch (error) {
                void error;
            }
        }

        function loadActiveTab() {
            try {
                return window.localStorage.getItem(activeTabKey) || 'fuel-prices-tab';
            } catch (error) {
                void error;
                return 'fuel-prices-tab';
            }
        }

        function activateTab(tabId) {
            const tab = document.getElementById(tabId);
            if (!tab) {
                return;
            }

            tabs.forEach((item) => item.setAttribute('aria-selected', 'false'));
            panels.forEach((panel) => panel.classList.remove('active'));

            tab.setAttribute('aria-selected', 'true');
            document.getElementById(tab.getAttribute('aria-controls')).classList.add('active');
            saveActiveTab(tab.id);

            if (tab.id === 'container-management-tab') {
                loadContainers();
            }
            if (tab.id === 'fuel-prices-tab') {
                loadFuelDashboard();
                if (fuelMapInstance) {
                    window.setTimeout(() => fuelMapInstance.resize(), 0);
                }
            }
            if (tab.id === 'route-planning-tab' && routeMapInstance) {
                window.setTimeout(() => routeMapInstance.resize(), 0);
            }
        }

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

        function getCookie(name) {
            const prefix = `${encodeURIComponent(name)}=`;
            return document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith(prefix))?.slice(prefix.length) || '';
        }

        function setCookie(name, value, maxAgeDays = 365) {
            const safeValue = encodeURIComponent(String(value || '').trim());
            const maxAge = Math.max(1, Number(maxAgeDays || 365)) * 24 * 60 * 60;
            document.cookie = `${encodeURIComponent(name)}=${safeValue}; path=/; max-age=${maxAge}; samesite=lax`;
        }

        function savedFuelLabel() {
            return decodeURIComponent(getCookie(fuelSelectionCookieName) || '').trim();
        }

        function savedFuelRegionValue() {
            return decodeURIComponent(getCookie(fuelRegionCookieName) || '').trim();
        }

        function persistFuelLabel(label) {
            const value = String(label || '').trim();
            if (value !== '') {
                setCookie(fuelSelectionCookieName, value);
            }
        }

        function persistFuelRegion(value) {
            const nextValue = String(value || '').trim();
            if (nextValue !== '') {
                setCookie(fuelRegionCookieName, nextValue);
            }
        }

        function saveRoutePlannerState(planned = false) {
            try {
                window.localStorage.setItem(routePlannerStateKey, JSON.stringify({
                    origin: routeOrigin.value.trim(),
                    destinations: routeDestinationValues(),
                    fuelFill: routeFuelFill.value.trim(),
                    fuelEconomy: routeFuelEconomy.value.trim(),
                    fuelValue: routeFuelSelectedValue(),
                    returnMode: routeReturnMode(),
                    planned: Boolean(planned),
                }));
            } catch (error) {
                void error;
            }
        }

        function loadRoutePlannerState() {
            try {
                const raw = window.localStorage.getItem(routePlannerStateKey);
                return raw ? JSON.parse(raw) : null;
            } catch (error) {
                return null;
            }
        }

        function clearRoutePlannerState() {
            try {
                window.localStorage.removeItem(routePlannerStateKey);
            } catch (error) {
                void error;
            }
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
                <button class="route-stop-handle" type="button" draggable="true" data-action="drag" aria-label="Drag to reorder destination">☰</button>
                <div class="field route-destination-field">
                    <div class="route-autocomplete">
                        <input type="text" id="route-destination-${routeDestinationCounter}" class="route-destination-input route-autocomplete-input" placeholder="Enter a destination" value="${escapeHtml(value)}" autocomplete="off" aria-label="Destination">
                        <div class="route-autocomplete-panel" hidden></div>
                    </div>
                </div>
                <div class="route-stop-actions">
                    <button class="route-stop-remove" type="button" data-action="remove" aria-label="Remove destination">X</button>
                </div>
            `;

            attachRouteAutocomplete(row.querySelector('.route-destination-input'));

            attachRouteDestinationDrag(row);
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
                const drag = row.querySelector('[data-action="drag"]');
                const remove = row.querySelector('[data-action="remove"]');
                if (drag) {
                    drag.disabled = rows.length === 1;
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

        function attachRouteDestinationDrag(row) {
            const handle = row.querySelector('[data-action="drag"]');
            if (!handle) {
                return;
            }

            handle.addEventListener('dragstart', (event) => {
                if (handle.disabled) {
                    event.preventDefault();
                    return;
                }
                draggedRouteDestinationRow = row;
                row.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', row.dataset.routeDestinationId || '');
            });

            handle.addEventListener('dragend', () => {
                row.classList.remove('is-dragging');
                draggedRouteDestinationRow = null;
                syncRouteDestinationControls();
            });

            row.addEventListener('dragover', (event) => {
                if (!draggedRouteDestinationRow || draggedRouteDestinationRow === row) {
                    return;
                }
                event.preventDefault();
                const rect = row.getBoundingClientRect();
                const placeAfter = event.clientY > rect.top + (rect.height / 2);
                routeDestinationList.insertBefore(
                    draggedRouteDestinationRow,
                    placeAfter ? row.nextSibling : row
                );
            });

            row.addEventListener('drop', (event) => {
                if (!draggedRouteDestinationRow) {
                    return;
                }
                event.preventDefault();
                syncRouteDestinationControls();
            });
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

        function routeGeocodeIsAdministrative(result) {
            const kind = String(result?.type || '').toLowerCase();
            const scope = String(result?.class || '').toLowerCase();
            return kind === 'administrative' || scope === 'boundary';
        }

        function routeGeocodeAddressLine(address) {
            if (!address || typeof address !== 'object') {
                return '';
            }

            const houseNumber = String(address.house_number || '').trim();
            const road = String(address.road || address.pedestrian || address.footway || '').trim();
            const suburb = String(address.suburb || address.city_district || address.neighbourhood || address.city || address.town || address.village || '').trim();
            const state = String(address.state || '').trim();
            const postcode = String(address.postcode || '').trim();

            const street = [houseNumber, road].filter(Boolean).join(' ').trim();
            const locality = [suburb, state, postcode].filter(Boolean).join(' ').trim();

            if (street && locality) {
                return `${street}, ${locality}`;
            }
            if (street) {
                return street;
            }
            if (locality) {
                return locality;
            }
            return '';
        }

        function routeGeocodeLabel(result) {
            const addressLine = routeGeocodeAddressLine(result?.address);
            return addressLine !== '' ? addressLine : String(result?.display_name || '');
        }

        function routeGeocodeInputValue(result, fallback = '') {
            const label = routeGeocodeLabel(result);
            return label !== '' ? label : fallback;
        }

        function renderRouteAutocompleteOptions(input, results) {
            const panel = routeAutocompletePanel(input);
            if (!panel) {
                return;
            }

            const filteredResults = Array.isArray(results)
                ? results.filter((result) => !routeGeocodeIsAdministrative(result))
                : [];

            if (filteredResults.length === 0) {
                panel.innerHTML = '<div class="route-autocomplete-empty">No matches found.</div>';
                panel.hidden = false;
                return;
            }

            panel.innerHTML = filteredResults.map((result) => `
                <button type="button" class="route-autocomplete-option" data-route-match="${escapeHtml(JSON.stringify(result))}">
                    <strong>${escapeHtml(routeGeocodeLabel(result) || result.display_name || '')}</strong>
                    <span>${escapeHtml(result.display_name || [result.class, result.type].filter(Boolean).join(' · ') || 'Geocoding match')}</span>
                </button>
            `).join('');

            panel.querySelectorAll('[data-route-match]').forEach((button) => {
                button.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    const payload = JSON.parse(button.getAttribute('data-route-match') || '{}');
                    input.value = routeGeocodeInputValue(payload, input.value);
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
                        const payload = await apiRequest(`/api/geo/search?q=${encodeURIComponent(query)}&limit=10`);
                        if (state.sequence !== currentSequence || input.value.trim() !== query) {
                            return;
                        }

                        const results = Array.isArray(payload.results) ? payload.results.filter((result) => !routeGeocodeIsAdministrative(result)) : [];
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

        function routeFuelDefaultFillValue() {
            const value = Number(routeFuelFill.value || 0);
            return Number.isFinite(value) ? value : 0;
        }

        function routeFuelDefaultEconomyValue() {
            const value = Number(routeFuelEconomy.value || 0);
            return Number.isFinite(value) ? value : 0;
        }

        function fuelOptionLabelForValue(value) {
            const option = Array.from(fuelType.options).find((item) => item.value === value);
            return option ? option.textContent.trim() : '';
        }

        function fuelOptionValueForLabel(label) {
            const normalized = String(label || '').trim().toLowerCase();
            if (normalized === '') {
                return '';
            }

            const option = Array.from(fuelType.options).find((item) => item.textContent.trim().toLowerCase() === normalized);
            return option ? option.value : '';
        }

        function fuelTypeSelectedLabel() {
            const label = fuelOptionLabelForValue(fuelType.value);
            if (label !== '') {
                return label;
            }
            return fuelType.options[fuelType.selectedIndex]?.textContent?.trim() || 'Diesel';
        }

        function routeFuelChoices() {
            const choices = filteredFuelOptions().filter((item) => String(item.value || '') !== '');
            return choices.length > 0 ? choices : [{ value: 'Diesel', label: 'Diesel' }];
        }

        function routeFuelDefaultValue() {
            const options = routeFuelChoices();
            const current = String(routeFuelType?.value || '').trim();
            const cookieValue = savedFuelLabel();
            if (cookieValue !== '') {
                const cookieMatch = options.find((item) => item.label.trim().toLowerCase() === cookieValue.toLowerCase());
                if (cookieMatch) {
                    return cookieMatch.value;
                }
            }
            if (current !== '' && options.some((item) => item.value === current)) {
                return current;
            }

            const diesel = options.find((item) => item.label.toLowerCase() === 'diesel');
            return diesel ? diesel.value : options[0].value;
        }

        function syncRouteFuelSelector() {
            if (!routeFuelType) {
                return;
            }
            setSelectOptions(routeFuelType, routeFuelChoices(), routeFuelDefaultValue());
        }

        function routeFuelSelectedValue() {
            const value = String(routeFuelType?.value || '').trim();
            return value !== '' ? value : routeFuelDefaultValue();
        }

        function routeFuelSelectedLabel() {
            const option = Array.from(routeFuelType?.options || []).find((item) => item.value === routeFuelSelectedValue());
            return option ? option.textContent.trim() : '';
        }

        function selectedFuelLabel() {
            const cookieValue = savedFuelLabel();
            if (cookieValue !== '') {
                return cookieValue;
            }
            return fuelTypeSelectedLabel();
        }

        function routeFuelQueryLabel() {
            return routeFuelSelectedLabel();
        }

        function renderRouteEmpty(message) {
            return `<div class="route-empty">${escapeHtml(message)}</div>`;
        }

        function routeFuelQuery() {
            return routeFuelSelectedValue();
        }

        function routeFuelPriceText(priceCents) {
            const cents = Number(priceCents || 0);
            return `$${(cents / 100).toFixed(2)}`;
        }

        function routeFuelPriceIsReasonable(price) {
            const value = Number(price);
            return Number.isFinite(value) && value >= 50 && value <= 500;
        }

        function routeFuelSourceIsOfficial(source) {
            return ['qld', 'sa', 'nsw', 'tas', 'vic'].includes(String(source || '').trim().toLowerCase());
        }

        function routeFuelPriceIsFresh(updatedAt, maximumAgeDays = 14) {
            const timestamp = Date.parse(String(updatedAt || '').trim().replace(' ', 'T'));
            if (!Number.isFinite(timestamp)) {
                return false;
            }

            const ageMs = Date.now() - timestamp;
            return ageMs >= 0 && ageMs <= maximumAgeDays * 24 * 60 * 60 * 1000;
        }

        function routeFuelCandidateIsEligible(candidate) {
            return routeFuelSourceIsOfficial(candidate?.source)
                && routeFuelPriceIsReasonable(candidate?.price)
                && routeFuelPriceIsFresh(candidate?.updated_at);
        }

        function routeFuelMinimumPurchaseL(tankCapacityL) {
            return Math.max(15, Number(tankCapacityL || 0) * 0.5);
        }

        function routeFuelReserveL(tankCapacityL) {
            return Math.max(0, Number(tankCapacityL || 0) * 0.1);
        }

        function routeFuelRateLPerKm(economyLPer100km) {
            return Number(economyLPer100km || 0) / 100;
        }

        function routeFuelSafeRangeKm(fuelL, reserveL, economyLPer100km) {
            const rate = routeFuelRateLPerKm(economyLPer100km);
            if (rate <= 0) {
                return 0;
            }
            return Math.max(0, (Number(fuelL || 0) - Number(reserveL || 0)) / rate);
        }

        function routeFuelCandidateProgressKm(candidate, cursor) {
            return Math.max(0, Number(candidate?.progressKm || 0) - Number(cursor?.progressKm || 0));
        }

        function routeFuelCandidateOffRouteKm(candidate) {
            return Math.max(0, Number(candidate?.offRouteDistanceKm ?? candidate?.routeDistanceFromCursorKm ?? 0));
        }

        function routeFuelDetourLimitKm(routeKm, safeRangeKm) {
            const routeDistance = Number(routeKm || 0);
            const rangeDistance = Number(safeRangeKm || 0);
            if (routeDistance <= 30) {
                return 6;
            }
            if (routeDistance <= 250) {
                return 12;
            }
            if (routeDistance <= 800) {
                return Math.min(30, Math.max(15, rangeDistance * 0.08));
            }
            return Math.min(75, Math.max(25, rangeDistance * 0.12));
        }

        function routeFuelDetourCostCents(detourKm, priceCentsPerL, economyLPer100km) {
            const detourFuelL = Number(detourKm || 0) * routeFuelRateLPerKm(economyLPer100km);
            const fuelCost = detourFuelL * Number(priceCentsPerL || 0);
            const distanceTimeCost = Number(detourKm || 0) * 45;
            return fuelCost + distanceTimeCost;
        }

        function routeFuelStopPenaltyCents(routeKm) {
            return Number(routeKm || 0) > 30 ? 1800 : 600;
        }

        function routeFuelMedianPrice(candidates) {
            const prices = candidates
                .map((candidate) => Number(candidate.price || 0))
                .filter((price) => Number.isFinite(price) && price > 0)
                .sort((left, right) => left - right);
            if (prices.length === 0) {
                return 0;
            }
            return prices[Math.floor(prices.length / 2)];
        }

        function routeFuelEarlyStopSavingCents(candidate, refillL, candidatePool) {
            const medianPrice = routeFuelMedianPrice(candidatePool);
            const price = Number(candidate?.price || 0);
            if (medianPrice <= 0 || price <= 0 || price > medianPrice - 10) {
                return 0;
            }
            return (medianPrice - price) * Number(refillL || 0);
        }

        function routeFuelEarlyStopIsWorthwhile(candidate, refillL, candidatePool) {
            return routeFuelEarlyStopSavingCents(candidate, refillL, candidatePool) >= 1000;
        }

        function routeFuelStopLabel(stop) {
            const station = String(stop?.station_name || '').trim();
            const address = String(stop?.address || '').trim();
            const price = routeFuelPriceText(stop?.price);
            const lines = [station];
            if (address !== '') {
                lines.push(address);
            }
            lines.push(`${price}/L`);
            return lines.join('\n');
        }

        function clearRouteFuelMarkers() {
            routeFuelMarkers.forEach((marker) => {
                try {
                    marker.remove();
                } catch (error) {
                    void error;
                }
            });
            routeFuelMarkers = [];
        }

        function createRouteFuelMarkerElement(feature) {
            const color = String(feature?.properties?.color || '#b45309');
            const station = String(feature?.properties?.station_name || '').trim();
            const address = String(feature?.properties?.address || '').trim();
            const price = String(feature?.properties?.price_text || '').trim();
            const wrapper = document.createElement('div');
            wrapper.className = 'route-fuel-marker';
            wrapper.style.setProperty('--route-fuel-color', color);
            wrapper.innerHTML = `
                <span class="route-fuel-marker-icon" aria-hidden="true"></span>
                <span class="route-fuel-marker-copy">
                    <strong>${escapeHtml(station !== '' ? station : 'Fuel stop')}</strong>
                    ${address !== '' ? `<span>${escapeHtml(address)}</span>` : ''}
                    <span>${escapeHtml(price !== '' ? `${price}/L` : 'Price unavailable')}</span>
                </span>
            `;
            return wrapper;
        }

        function haversineKm(left, right) {
            const toRad = Math.PI / 180;
            const leftLat = Number(left?.lat ?? left?.latitude ?? 0);
            const leftLon = Number(left?.lon ?? left?.longitude ?? 0);
            const rightLat = Number(right?.lat ?? right?.latitude ?? 0);
            const rightLon = Number(right?.lon ?? right?.longitude ?? 0);
            const lat1 = leftLat * toRad;
            const lon1 = leftLon * toRad;
            const lat2 = rightLat * toRad;
            const lon2 = rightLon * toRad;
            const dLat = lat2 - lat1;
            const dLon = lon2 - lon1;
            const a = Math.sin(dLat / 2) ** 2
                + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
            return 6371 * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
        }

        function routePoint(lon, lat, progressKm = 0) {
            return {
                lon: Number(lon),
                lat: Number(lat),
                progressKm: Number(progressKm),
            };
        }

        function buildRouteProgress(points) {
            const progress = [];
            let total = 0;
            points.forEach((point, index) => {
                if (index > 0) {
                    total += haversineKm(points[index - 1], point);
                }
                const lon = Number(Array.isArray(point) ? point[0] : point?.lon);
                const lat = Number(Array.isArray(point) ? point[1] : point?.lat);
                progress.push({
                    lon,
                    lat,
                    progressKm: total,
                });
            });
            return progress;
        }

        function sampleRoutePoints(points, limit = 7) {
            if (!Array.isArray(points) || points.length === 0) {
                return [];
            }
            if (points.length <= limit) {
                return points;
            }

            const result = [];
            const step = (points.length - 1) / Math.max(limit - 1, 1);
            for (let index = 0; index < limit; index += 1) {
                result.push(points[Math.round(index * step)]);
            }
            return result;
        }

        async function fetchRouteDetails(from, to, steps = true) {
            const coordinates = `${from.lon},${from.lat};${to.lon},${to.lat}`;
            const payload = await apiRequest(`/api/route?coordinates=${encodeURIComponent(coordinates)}&steps=${steps ? '1' : '0'}`);
            const route = Array.isArray(payload.routes) ? payload.routes[0] : null;
            if (!route || !route.geometry || !Array.isArray(route.geometry.coordinates)) {
                throw new Error('Route service returned no geometry.');
            }

            return {
                from,
                to,
                distanceM: Number(route.distance || 0),
                durationS: Number(route.duration || 0),
                geometry: route.geometry.coordinates.map((coord) => routePoint(coord[0], coord[1])),
                steps: Array.isArray(route.legs)
                    ? route.legs.flatMap((leg) => Array.isArray(leg.steps) ? leg.steps : [])
                    : [],
            };
        }

        function routeStepInstruction(step) {
            const maneuver = step.maneuver || {};
            const type = String(maneuver.type || 'continue');
            const modifier = String(maneuver.modifier || '').replace(/_/g, ' ');
            const name = String(step.name || '').trim();

            if (type === 'depart') {
                return name !== '' ? `Depart onto ${name}` : 'Depart';
            }
            if (type === 'arrive') {
                return name !== '' ? `Arrive at ${name}` : 'Arrive';
            }
            if (type === 'roundabout' || type === 'rotary') {
                const exit = maneuver.exit ? `exit ${maneuver.exit}` : 'the roundabout';
                return `Take ${exit}${name !== '' ? ` onto ${name}` : ''}`;
            }
            if (modifier !== '' && name !== '') {
                return `Turn ${modifier} onto ${name}`;
            }
            if (modifier !== '') {
                return `Turn ${modifier}`;
            }
            if (name !== '') {
                return `Continue on ${name}`;
            }
            return type.replace(/_/g, ' ');
        }

        async function fetchRouteStations(point, fuelQuery) {
            const payload = await apiRequest(`/api/fuel/current?source=all&fuel=${encodeURIComponent(fuelQuery)}&lat=${encodeURIComponent(point.lat)}&lon=${encodeURIComponent(point.lon)}&radius_km=25&limit=20`);
            const rows = Array.isArray(payload.rows) ? payload.rows : [];
            return rows.map((row) => ({
                source: row.source,
                state: row.state,
                station_id: row.station_id,
                station_name: row.station_name,
                address: row.address,
                brand_name: row.brand_name,
                latitude: Number(row.latitude),
                longitude: Number(row.longitude),
                fuel_name: row.fuel_name,
                price: Number(row.price),
                updated_at: row.updated_at,
                distance_km: Number(row.distance_km || 0),
            })).filter((row) => routeFuelCandidateIsEligible(row));
        }

        async function collectRouteFuelCandidates(progress, fuelQuery, sampleLimit = 7, radiusKm = 25) {
            const samplePoints = sampleRoutePoints(progress, sampleLimit);
            const candidateBatches = await Promise.all(samplePoints.map((point) => apiRequest(
                `/api/fuel/current?source=all&fuel=${encodeURIComponent(fuelQuery)}&lat=${encodeURIComponent(point.lat)}&lon=${encodeURIComponent(point.lon)}&radius_km=${encodeURIComponent(radiusKm)}&limit=50`
            )));

            return dedupeRouteStations(candidateBatches.flatMap((payload) => Array.isArray(payload.rows) ? payload.rows : []))
                .filter((candidate) => routeFuelCandidateIsEligible(candidate))
                .map((candidate) => {
                let nearestProgress = progress[0] || routePoint(0, 0, 0);
                let bestDistance = Number.POSITIVE_INFINITY;
                progress.forEach((point) => {
                    const distance = haversineKm(point, candidate);
                    if (distance < bestDistance) {
                        bestDistance = distance;
                        nearestProgress = point;
                    }
                });

                return {
                    ...candidate,
                    routeDistanceFromCursorKm: bestDistance * 1.15,
                    offRouteDistanceKm: bestDistance * 1.15,
                    progressKm: nearestProgress.progressKm,
                };
            }).filter((candidate) => candidate.routeDistanceFromCursorKm <= radiusKm);
        }

        function dedupeRouteStations(rows) {
            const unique = new Map();
            rows.forEach((row) => {
                const key = `${row.source}:${row.state}:${row.station_id}:${row.fuel_name}:${row.price}`;
                if (!unique.has(key)) {
                    unique.set(key, row);
                }
            });
            return Array.from(unique.values());
        }

        function stationKey(candidate) {
            const name = String(candidate.station_name || '').trim().toLowerCase();
            const address = String(candidate.address || '').trim().toLowerCase();
            return [
                String(candidate.source || ''),
                String(candidate.state || ''),
                String(candidate.station_id || ''),
                name,
                address,
            ].join(':');
        }

        function stationNameKey(candidate) {
            return String(candidate.station_name || '').trim().toLowerCase();
        }

        function routeFuelCandidateHasForwardOption(candidate, candidates, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set()) {
            const remainingRouteKm = Math.max(0, Number(routeKm || 0) - Number(candidate.progressKm || 0));
            const fullTankSafeRangeKm = routeFuelSafeRangeKm(tankCapacityL, reserveL, economyLPer100km);
            if (remainingRouteKm <= fullTankSafeRangeKm) {
                return true;
            }

            const detourLimitKm = routeFuelDetourLimitKm(remainingRouteKm, fullTankSafeRangeKm);
            const minimumPurchaseL = routeFuelMinimumPurchaseL(tankCapacityL);
            return candidates.some((nextCandidate) => {
                if (stationKey(nextCandidate) === stationKey(candidate)) {
                    return false;
                }
                if (visitedKeys.has(stationKey(nextCandidate)) || visitedNames.has(stationNameKey(nextCandidate))) {
                    return false;
                }

                const progressDeltaKm = Number(nextCandidate.progressKm || 0) - Number(candidate.progressKm || 0);
                if (progressDeltaKm <= 0 || progressDeltaKm > fullTankSafeRangeKm) {
                    return false;
                }
                if (routeFuelCandidateOffRouteKm(nextCandidate) > detourLimitKm) {
                    return false;
                }

                const arrivalFuelL = Math.max(0, tankCapacityL - (progressDeltaKm * routeFuelRateLPerKm(economyLPer100km)));
                const refillL = Math.max(0, tankCapacityL - arrivalFuelL);
                return refillL >= minimumPurchaseL;
            });
        }

        function selectRouteFuelCandidate(candidates, cursor, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set(), mode = 'standard') {
            const safeRangeKm = routeFuelSafeRangeKm(currentFuelL, reserveL, economyLPer100km);
            const minimumPurchaseL = routeFuelMinimumPurchaseL(tankCapacityL);
            const detourLimitKm = routeFuelDetourLimitKm(routeKm, safeRangeKm);
            const rateLPerKm = routeFuelRateLPerKm(economyLPer100km);
            const candidatePool = candidates
                .filter((candidate) => !visitedKeys.has(stationKey(candidate)))
                .filter((candidate) => !visitedNames.has(stationNameKey(candidate)))
                .filter((candidate) => Number(candidate.progressKm || 0) >= Number(cursor.progressKm || 0) - 0.001);
            const reachable = candidatePool
                .map((candidate) => {
                    const routeProgressKm = routeFuelCandidateProgressKm(candidate, cursor);
                    const offRouteKm = routeFuelCandidateOffRouteKm(candidate);
                    const routeFuelL = routeProgressKm * rateLPerKm;
                    const arrivalFuelL = Math.max(0, currentFuelL - routeFuelL);
                    const refillL = Math.max(0, tankCapacityL - arrivalFuelL);
                    const safeStop = arrivalFuelL >= reserveL;
                    const meaningfulRefill = refillL >= minimumPurchaseL;
                    const cheapEarlyStop = routeFuelEarlyStopIsWorthwhile(candidate, refillL, candidatePool);
                    const forwardFeasible = routeFuelCandidateHasForwardOption(
                        candidate,
                        candidatePool,
                        tankCapacityL,
                        economyLPer100km,
                        reserveL,
                        routeKm,
                        visitedKeys,
                        visitedNames
                    );
                    const purchaseCost = refillL * Number(candidate.price || 0);
                    const detourCost = routeFuelDetourCostCents(offRouteKm, candidate.price, economyLPer100km);
                    const stopPenalty = routeFuelStopPenaltyCents(routeKm);
                    const reservePenalty = Math.max(0, (reserveL * 1.5) - arrivalFuelL) * 500;
                    const weakProgressPenalty = mode === 'initial'
                        ? 0
                        : Math.max(0, minimumPurchaseL - refillL) * 1500;
                    const deadEndPenalty = forwardFeasible ? 0 : 500000;
                    const earlyStopCredit = cheapEarlyStop ? routeFuelEarlyStopSavingCents(candidate, refillL, candidatePool) : 0;
                    const progressCredit = mode === 'initial' ? 0 : routeProgressKm * 1.25;

                    return {
                        ...candidate,
                        routeProgressKm,
                        offRouteKm,
                        arrivalFuelL,
                        refillL,
                        safeStop,
                        meaningfulRefill,
                        cheapEarlyStop,
                        forwardFeasible,
                        effectiveCost: purchaseCost + detourCost + stopPenalty + reservePenalty + weakProgressPenalty + deadEndPenalty - earlyStopCredit - progressCredit,
                    };
                })
                .filter((candidate) => candidate.routeProgressKm > 0.01)
                .filter((candidate) => candidate.routeProgressKm <= safeRangeKm)
                .filter((candidate) => candidate.offRouteKm <= detourLimitKm)
                .filter((candidate) => candidate.safeStop);

            if (reachable.length === 0) {
                return null;
            }

            const practical = reachable.filter((candidate) => candidate.forwardFeasible && (candidate.meaningfulRefill || mode === 'initial'));
            const fallback = reachable.filter((candidate) => candidate.forwardFeasible);
            const pool = practical.length > 0 ? practical : (fallback.length > 0 ? fallback : reachable);

            pool.sort((left, right) => {
                if (left.forwardFeasible !== right.forwardFeasible) {
                    return Number(right.forwardFeasible) - Number(left.forwardFeasible);
                }
                if (left.meaningfulRefill !== right.meaningfulRefill) {
                    return Number(right.meaningfulRefill) - Number(left.meaningfulRefill);
                }
                if (left.effectiveCost !== right.effectiveCost) {
                    return left.effectiveCost - right.effectiveCost;
                }
                if (left.price !== right.price) {
                    return left.price - right.price;
                }
                if (left.offRouteKm !== right.offRouteKm) {
                    return left.offRouteKm - right.offRouteKm;
                }
                return right.routeProgressKm - left.routeProgressKm;
            });

            return pool[0];
        }

        function selectStationCandidate(candidates, cursor, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set()) {
            return selectRouteFuelCandidate(candidates, cursor, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, 'standard');
        }

        function selectInitialFuelCandidate(candidates, cursor, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set()) {
            return selectRouteFuelCandidate(candidates, cursor, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, 'initial');
        }

        function routeFuelGraphEdgeKm(fromNode, toNode) {
            const progressKm = Math.max(0, Number(toNode.progressKm || 0) - Number(fromNode.progressKm || 0));
            const fromOffRouteKm = fromNode.kind === 'station' ? routeFuelCandidateOffRouteKm(fromNode.station) : 0;
            const toOffRouteKm = toNode.kind === 'station' ? routeFuelCandidateOffRouteKm(toNode.station) : 0;
            return progressKm + (fromOffRouteKm * 0.8) + (toOffRouteKm * 1.15);
        }

        function routeFuelGraphStationNodes(candidates, routeKm, detourLimitKm, visitedKeys = new Set(), visitedNames = new Set()) {
            const unique = new Map();
            candidates
                .filter((candidate) => !visitedKeys.has(stationKey(candidate)))
                .filter((candidate) => !visitedNames.has(stationNameKey(candidate)))
                .filter((candidate) => Number(candidate.progressKm || 0) > 0.01)
                .filter((candidate) => Number(candidate.progressKm || 0) < Number(routeKm || 0) - 0.01)
                .filter((candidate) => routeFuelCandidateOffRouteKm(candidate) <= detourLimitKm)
                .forEach((candidate) => {
                    const key = stationKey(candidate);
                    const existing = unique.get(key);
                    if (!existing || Number(candidate.price || 0) < Number(existing.price || 0)) {
                        unique.set(key, candidate);
                    }
                });

            return Array.from(unique.values())
                .sort((left, right) => {
                    if (Number(left.progressKm || 0) !== Number(right.progressKm || 0)) {
                        return Number(left.progressKm || 0) - Number(right.progressKm || 0);
                    }
                    return Number(left.price || 0) - Number(right.price || 0);
                })
                .slice(0, 240)
                .map((candidate, index) => ({
                    kind: 'station',
                    index: index + 1,
                    progressKm: Number(candidate.progressKm || 0),
                    station: candidate,
                }));
        }

        function buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set(), allowSafetyStops = false) {
            const rateLPerKm = routeFuelRateLPerKm(economyLPer100km);
            if (rateLPerKm <= 0) {
                return null;
            }

            const fullTankSafeRangeKm = routeFuelSafeRangeKm(tankCapacityL, reserveL, economyLPer100km);
            const currentSafeRangeKm = routeFuelSafeRangeKm(currentFuelL, reserveL, economyLPer100km);
            const detourLimitKm = routeFuelDetourLimitKm(routeKm, Math.max(fullTankSafeRangeKm, currentSafeRangeKm));
            const strictMinimumRefillL = routeFuelMinimumPurchaseL(tankCapacityL);
            const safetyMinimumRefillL = Math.max(15, tankCapacityL * 0.25);
            const launchFuelExemption = currentFuelL <= (tankCapacityL * 0.25);
            const stationNodes = routeFuelGraphStationNodes(candidates, routeKm, detourLimitKm, visitedKeys, visitedNames);
            const nodes = [
                {
                    kind: 'start',
                    index: 0,
                    progressKm: 0,
                },
                ...stationNodes,
                {
                    kind: 'destination',
                    index: stationNodes.length + 1,
                    progressKm: Number(routeKm || 0),
                },
            ];

            const best = nodes.map(() => null);
            best[0] = {
                cost: 0,
                previousIndex: -1,
                arrivalFuelL: currentFuelL,
                litresPurchased: 0,
                safetyFallback: false,
            };

            for (let fromIndex = 0; fromIndex < nodes.length; fromIndex += 1) {
                const fromBest = best[fromIndex];
                if (!fromBest) {
                    continue;
                }

                const fromNode = nodes[fromIndex];
                const departureFuelL = fromNode.kind === 'start' ? currentFuelL : tankCapacityL;
                for (let toIndex = fromIndex + 1; toIndex < nodes.length; toIndex += 1) {
                    const toNode = nodes[toIndex];
                    const edgeKm = routeFuelGraphEdgeKm(fromNode, toNode);
                    const fuelUsedL = edgeKm * rateLPerKm;
                    const arrivalFuelL = departureFuelL - fuelUsedL;
                    if (arrivalFuelL < reserveL) {
                        if ((Number(toNode.progressKm || 0) - Number(fromNode.progressKm || 0)) > fullTankSafeRangeKm + detourLimitKm) {
                            break;
                        }
                        continue;
                    }

                    let edgeCost = 0;
                    let litresPurchased = 0;
                    let safetyFallback = false;
                    if (toNode.kind === 'station') {
                        const station = toNode.station;
                        litresPurchased = Math.max(0, tankCapacityL - arrivalFuelL);
                        const minimumRefillL = allowSafetyStops ? safetyMinimumRefillL : strictMinimumRefillL;
                        if (!(fromNode.kind === 'start' && launchFuelExemption) && litresPurchased < minimumRefillL) {
                            continue;
                        }
                        safetyFallback = litresPurchased < strictMinimumRefillL;
                        const offRouteKm = routeFuelCandidateOffRouteKm(station);
                        const purchaseCost = litresPurchased * Number(station.price || 0);
                        const detourCost = routeFuelDetourCostCents(offRouteKm, station.price, economyLPer100km);
                        const reservePenalty = Math.max(0, (reserveL * 1.5) - arrivalFuelL) * 500;
                        const cheapFuelCredit = routeFuelEarlyStopSavingCents(station, litresPurchased, candidates);
                        edgeCost = purchaseCost + detourCost + routeFuelStopPenaltyCents(routeKm) + reservePenalty - cheapFuelCredit;
                    } else {
                        edgeCost = routeFuelDetourCostCents(routeFuelGraphEdgeKm(fromNode, toNode) - Math.max(0, Number(toNode.progressKm || 0) - Number(fromNode.progressKm || 0)), 250, economyLPer100km);
                    }

                    const nextCost = fromBest.cost + edgeCost;
                    if (!best[toIndex] || nextCost < best[toIndex].cost) {
                        best[toIndex] = {
                            cost: nextCost,
                            previousIndex: fromIndex,
                            arrivalFuelL,
                            litresPurchased,
                            safetyFallback,
                        };
                    }
                }
            }

            const destinationIndex = nodes.length - 1;
            if (!best[destinationIndex]) {
                return null;
            }

            const stops = [];
            let cursorIndex = best[destinationIndex].previousIndex;
            while (cursorIndex > 0) {
                const node = nodes[cursorIndex];
                const entry = best[cursorIndex];
                if (node.kind === 'station') {
                    stops.push({
                        ...node.station,
                        plannedRefillL: entry.litresPurchased,
                        safetyFallback: entry.safetyFallback,
                    });
                }
                cursorIndex = entry.previousIndex;
            }
            stops.reverse();

            return {
                cost: best[destinationIndex].cost,
                stops,
                safetyFallback: allowSafetyStops,
            };
        }

        function selectRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set()) {
            const strictPlan = buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, false);
            if (strictPlan) {
                return strictPlan;
            }
            return buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, true);
        }

        async function buildRouteFuelPlanSegment(cursor, destination, currentFuelL, tankCapacityL, economyLPer100km, fuelQuery) {
            const chosenStops = [];
            const routePieces = [];
            const visitedStationKeys = new Set();
            const visitedStationNames = new Set();
            let currentPoint = cursor;
            let fuelInTank = currentFuelL;
            const reserveL = routeFuelReserveL(tankCapacityL);

            while (true) {
                const route = await fetchRouteDetails(currentPoint, destination, true);
                const routeKm = route.distanceM / 1000;
                const fuelNeeded = routeKm * (economyLPer100km / 100);
                const progress = buildRouteProgress(route.geometry);
                const sampleLimit = Math.max(12, Math.min(36, Math.ceil(routeKm / 60)));
                const searchRadiusKm = routeKm > 1600 ? 60 : (routeKm > 900 ? 45 : 30);
                let candidates = await collectRouteFuelCandidates(progress, fuelQuery, sampleLimit, searchRadiusKm);
                if (candidates.length === 0) {
                    candidates = await collectRouteFuelCandidates(progress, fuelQuery, Math.min(40, sampleLimit + 8), routeKm > 1600 ? 90 : 60);
                }

                const currentCursor = routePoint(currentPoint.lon, currentPoint.lat, 0);
                if (fuelInTank >= fuelNeeded && (fuelInTank - fuelNeeded) >= reserveL) {
                    routePieces.push({
                        type: 'route',
                        route,
                    });
                    fuelInTank = Math.max(0, fuelInTank - fuelNeeded);
                    break;
                }

                let chosen = null;
                let approach = null;
                let safetyFallback = false;
                const shortSafetyCandidates = [];
                const isFirstStop = routePieces.length === 0;
                const graphPlan = selectRouteFuelGraphPlan(
                    candidates,
                    fuelInTank,
                    tankCapacityL,
                    economyLPer100km,
                    reserveL,
                    routeKm,
                    visitedStationKeys,
                    visitedStationNames
                );
                const graphCandidates = graphPlan ? graphPlan.stops.slice() : [];
                while (graphCandidates.length > 0 || candidates.length > 0) {
                    const nextCandidate = graphCandidates.length > 0
                        ? graphCandidates.shift()
                        : (isFirstStop
                            ? selectInitialFuelCandidate(
                                candidates,
                                currentCursor,
                                fuelInTank,
                                tankCapacityL,
                                economyLPer100km,
                                reserveL,
                                routeKm,
                                visitedStationKeys,
                                visitedStationNames
                            )
                            : selectStationCandidate(
                                candidates,
                                currentCursor,
                                fuelInTank,
                                tankCapacityL,
                                economyLPer100km,
                                reserveL,
                                routeKm,
                                visitedStationKeys,
                                visitedStationNames
                            ));
                    if (!nextCandidate) {
                        break;
                    }

                    const stopPoint = { lon: nextCandidate.longitude, lat: nextCandidate.latitude };
                    const nextApproach = await fetchRouteDetails(currentPoint, stopPoint, true);
                    const nextApproachFuel = (nextApproach.distanceM / 1000) * (economyLPer100km / 100);
                    const nextArrivalFuel = Math.max(0, fuelInTank - nextApproachFuel);
                    const nextRefillL = Math.max(0, tankCapacityL - nextArrivalFuel);
                    if (!isFirstStop && nextRefillL < routeFuelMinimumPurchaseL(tankCapacityL)) {
                        if ((nextCandidate.safetyFallback || graphPlan?.safetyFallback) && nextRefillL >= Math.max(15, tankCapacityL * 0.25) && (fuelInTank - nextApproachFuel) >= reserveL) {
                            chosen = nextCandidate;
                            approach = nextApproach;
                            safetyFallback = true;
                            break;
                        }
                        if (graphCandidates.length === 0 && nextRefillL >= Math.max(15, tankCapacityL * 0.25) && (fuelInTank - nextApproachFuel) >= reserveL) {
                            shortSafetyCandidates.push({
                                candidate: nextCandidate,
                                approach: nextApproach,
                                refillL: nextRefillL,
                            });
                        }
                        visitedStationKeys.add(stationKey(nextCandidate));
                        visitedStationNames.add(stationNameKey(nextCandidate));
                        continue;
                    }
                    if ((fuelInTank - nextApproachFuel) >= reserveL) {
                        chosen = nextCandidate;
                        approach = nextApproach;
                        break;
                    }

                    visitedStationKeys.add(stationKey(nextCandidate));
                    visitedStationNames.add(stationNameKey(nextCandidate));
                }

                if (!chosen && shortSafetyCandidates.length > 0) {
                    shortSafetyCandidates.sort((left, right) => {
                        if (left.candidate.forwardFeasible !== right.candidate.forwardFeasible) {
                            return Number(right.candidate.forwardFeasible) - Number(left.candidate.forwardFeasible);
                        }
                        if (left.refillL !== right.refillL) {
                            return right.refillL - left.refillL;
                        }
                        if (left.candidate.effectiveCost !== right.candidate.effectiveCost) {
                            return left.candidate.effectiveCost - right.candidate.effectiveCost;
                        }
                        return Number(left.candidate.price || 0) - Number(right.candidate.price || 0);
                    });
                    chosen = shortSafetyCandidates[0].candidate;
                    approach = shortSafetyCandidates[0].approach;
                    safetyFallback = true;
                }

                if (!chosen) {
                    throw new Error(`No fuel stop is reachable before running out of fuel on the way to ${destination.display_name || destination.query}.`);
                }

                visitedStationKeys.add(stationKey(chosen));
                visitedStationNames.add(stationNameKey(chosen));
                const stopPoint = { lon: chosen.longitude, lat: chosen.latitude };
                const approachFuel = (approach.distanceM / 1000) * (economyLPer100km / 100);

                routePieces.push({
                    type: 'route',
                    route: approach,
                });

                const fuelAfterArrival = Math.max(0, fuelInTank - approachFuel);
                const litresToBuy = Math.max(0, tankCapacityL - fuelAfterArrival);
                const purchaseCents = litresToBuy * chosen.price;
                chosenStops.push({
                    ...chosen,
                    litresPurchased: litresToBuy,
                    purchaseCents,
                    fuelAfterArrival,
                    safetyFallback,
                });

                routePieces.push({
                    type: 'fuel-stop',
                    station: chosen,
                    litresPurchased: litresToBuy,
                    purchaseCents,
                    safetyFallback,
                });

                fuelInTank = tankCapacityL;
                currentPoint = stopPoint;
                continue;
            }

            return {
                cursor,
                destination,
                routePieces,
                stops: chosenStops,
                remainingFuelL: Math.max(0, fuelInTank),
            };
        }

        async function buildRoutePlan(resolveStops, fuelQuery, tankCapacityL, economyLPer100km) {
            const segments = [];
            let currentFuel = tankCapacityL * 0.2;
            let currentPoint = resolveStops[0];
            let totalDistanceM = 0;
            let totalDurationS = 0;
            let totalFillCostCents = 0;
            let totalFuelUsedL = 0;

            for (let index = 1; index < resolveStops.length; index += 1) {
                const destination = resolveStops[index];
                let segment = null;
                let planningError = null;
                try {
                    segment = await buildRouteFuelPlanSegment(
                        currentPoint,
                        destination,
                        currentFuel,
                        tankCapacityL,
                        economyLPer100km,
                        fuelQuery
                    );
                } catch (error) {
                    planningError = error;
                }

                if (!segment && currentFuel < tankCapacityL) {
                    try {
                        segment = await buildRouteFuelPlanSegment(
                            currentPoint,
                            destination,
                            tankCapacityL,
                            tankCapacityL,
                            economyLPer100km,
                            fuelQuery
                        );
                    } catch (retryError) {
                        planningError = retryError;
                    }
                }

                if (!segment) {
                    throw planningError || new Error(`No fuel stop is reachable before running out of fuel on the way to ${destination.display_name || destination.query}.`);
                }

                const routeItems = segment.routePieces.filter((item) => item.type === 'route');
                routeItems.forEach((item) => {
                    totalDistanceM += item.route.distanceM;
                    totalDurationS += item.route.durationS;
                    totalFuelUsedL += (item.route.distanceM / 1000) * (economyLPer100km / 100);
                });

                segment.stops.forEach((stop) => {
                    totalFillCostCents += stop.purchaseCents;
                });

                segments.push(segment);
                currentPoint = destination;
                currentFuel = Math.max(0, segment.remainingFuelL);
            }

            return {
                fuelQuery,
                tankCapacityL,
                economyLPer100km,
                segments,
                totalDistanceM,
                totalDurationS,
                totalFuelUsedL,
                totalFillCostCents,
                fuelRemainingL: currentFuel,
            };
        }

        function buildRouteSequence(origin, destinations) {
            const nodes = [origin, ...destinations];
            if (routeReturnMode() === 'reverses') {
                if (destinations.length > 0) {
                    nodes.push(...destinations.slice(0, -1).reverse());
                }
                nodes.push(origin);
                return nodes;
            }

            nodes.push(origin);
            return nodes;
        }

        function renderRouteSummary(plan) {
            const cards = [
                ['Distance', formatRouteDistance(plan.totalDistanceM || 0)],
                ['Drive Time', formatRouteDuration(plan.totalDurationS || 0)],
                ['Fuel Type', String(plan.fuelQuery || 'Diesel')],
                ['Fuel Used', `${Number(plan.totalFuelUsedL || 0).toFixed(1)} L`],
                ['Fuel Fill', `${Number(plan.tankCapacityL || 0).toFixed(1)} L`],
                ['Fuel Stops', String(plan.segments.reduce((count, segment) => count + segment.stops.length, 0))],
                ['Total Fill Price', `$${(Number(plan.totalFillCostCents || 0) / 100).toFixed(2)}`],
            ];
            routeSummary.innerHTML = cards.map(([label, value]) => `
                <article class="route-summary-card">
                    <strong>${escapeHtml(value)}</strong>
                    <span>${escapeHtml(label)}</span>
                </article>
            `).join('');
        }

        function renderRouteMap(plan) {
            const segments = Array.isArray(plan.segments) ? plan.segments : [];
            const routeFeatures = [];
            const markerFeatures = [];
            const bounds = [];
            const palette = ['#0f766e', '#2563eb', '#7c3aed', '#b45309', '#c2410c'];

            segments.forEach((segment, segmentIndex) => {
                const routePieces = segment.routePieces.filter((item) => item.type === 'route');
                routePieces.forEach((piece, pieceIndex) => {
                    const routePoints = Array.isArray(piece.route.geometry) ? piece.route.geometry : [];
                    if (routePoints.length > 0) {
                        const coordinates = routePoints.map((point) => [Number(point.lon), Number(point.lat)]);
                        routeFeatures.push({
                            type: 'Feature',
                            properties: {
                                color: palette[segmentIndex % palette.length],
                                segment_index: segmentIndex + 1,
                                piece_index: pieceIndex + 1,
                            },
                            geometry: {
                                type: 'LineString',
                                coordinates,
                            },
                        });
                        routePoints.forEach((point) => bounds.push([Number(point.lat), Number(point.lon)]));
                    }
                    if (pieceIndex === 0) {
                        markerFeatures.push({
                            type: 'Feature',
                            properties: {
                                kind: 'origin',
                                label: `Leg ${segmentIndex + 1} start`,
                                sublabel: piece.route.from.display_name || '',
                                segment_index: segmentIndex + 1,
                            },
                            geometry: {
                                type: 'Point',
                                coordinates: [Number(piece.route.from.lon), Number(piece.route.from.lat)],
                            },
                        });
                    }
                    markerFeatures.push({
                        type: 'Feature',
                        properties: {
                            kind: 'destination',
                            label: pieceIndex === routePieces.length - 1
                                ? `Leg ${segmentIndex + 1} end`
                                : 'Fuel stop approach',
                            sublabel: piece.route.to.display_name || '',
                            segment_index: segmentIndex + 1,
                        },
                        geometry: {
                            type: 'Point',
                            coordinates: [Number(piece.route.to.lon), Number(piece.route.to.lat)],
                        },
                    });
                });
                segment.stops.forEach((stop, stopIndex) => {
                    markerFeatures.push({
                        type: 'Feature',
                        properties: {
                            kind: 'fuel-stop',
                            label: routeFuelStopLabel(stop),
                            sublabel: `${Number(stop.litresPurchased || 0).toFixed(1)} L bought`,
                            segment_index: segmentIndex + 1,
                            stop_index: stopIndex + 1,
                            price: stop.price,
                            price_text: routeFuelPriceText(stop.price),
                            station_name: stop.station_name || '',
                            address: stop.address || '',
                            color: palette[segmentIndex % palette.length],
                        },
                        geometry: {
                            type: 'Point',
                            coordinates: [Number(stop.longitude), Number(stop.latitude)],
                        },
                    });
                    bounds.push([Number(stop.latitude), Number(stop.longitude)]);
                });
            });

            routeMap.innerHTML = '';
            if (!window.maplibregl) {
                routeMap.innerHTML = renderRouteEmpty('Route map unavailable in this browser.');
                routeMapLegend.innerHTML = '';
                return;
            }

            if (routeMapInstance) {
                routeMapInstance.remove();
                routeMapInstance = null;
            }
            clearRouteFuelMarkers();

            if (bounds.length === 0) {
                routeMap.innerHTML = renderRouteEmpty('Plan a route to see the map.');
                routeMapLegend.innerHTML = '';
                return;
            }

            const mapConfig = window.fuelauMapConfig || {};
            const styleUrl = mapConfig.style_url;
            if (!styleUrl) {
                routeMap.innerHTML = renderRouteEmpty('Map style is not configured.');
                routeMapLegend.innerHTML = '';
                return;
            }

            const map = new maplibregl.Map({
                container: routeMap,
                style: styleUrl,
                center: [Number(segments[0]?.routePieces?.[0]?.route?.from?.lon || 133.7751), Number(segments[0]?.routePieces?.[0]?.route?.from?.lat || -25.2744)],
                zoom: 4,
                attributionControl: true,
                preserveDrawingBuffer: false,
            });
            routeMapInstance = map;
            map.addControl(new maplibregl.NavigationControl({ showCompass: true, showZoom: true }), 'top-right');

            map.on('load', () => {
                map.addSource('route-lines', {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: routeFeatures,
                    },
                });
                map.addSource('route-markers', {
                    type: 'geojson',
                    data: {
                        type: 'FeatureCollection',
                        features: markerFeatures,
                    },
                });

                map.addLayer({
                    id: 'route-lines',
                    type: 'line',
                    source: 'route-lines',
                    paint: {
                        'line-color': ['get', 'color'],
                        'line-width': 5,
                        'line-opacity': 0.92,
                    },
                });

                map.addLayer({
                    id: 'route-origin-marker',
                    type: 'circle',
                    source: 'route-markers',
                    filter: ['==', ['get', 'kind'], 'origin'],
                    paint: {
                        'circle-radius': 8,
                        'circle-color': '#166534',
                        'circle-stroke-color': '#ffffff',
                        'circle-stroke-width': 2,
                    },
                });

                map.addLayer({
                    id: 'route-destination-marker',
                    type: 'circle',
                    source: 'route-markers',
                    filter: ['==', ['get', 'kind'], 'destination'],
                    paint: {
                        'circle-radius': 8,
                        'circle-color': '#0f766e',
                        'circle-stroke-color': '#ffffff',
                        'circle-stroke-width': 2,
                    },
                });

                map.addLayer({
                    id: 'route-origin-label',
                    type: 'symbol',
                    source: 'route-markers',
                    filter: ['==', ['get', 'kind'], 'origin'],
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 12,
                        'text-offset': ['literal', [0, -1.3]],
                        'text-anchor': 'top',
                        'text-allow-overlap': true,
                    },
                    paint: {
                        'text-color': '#16212d',
                        'text-halo-color': '#ffffff',
                        'text-halo-width': 1.2,
                    },
                });

                map.addLayer({
                    id: 'route-destination-label',
                    type: 'symbol',
                    source: 'route-markers',
                    filter: ['==', ['get', 'kind'], 'destination'],
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-font': ['Noto Sans Regular'],
                        'text-size': 12,
                        'text-offset': ['literal', [0, 1.2]],
                        'text-anchor': 'bottom',
                        'text-allow-overlap': true,
                    },
                    paint: {
                        'text-color': '#16212d',
                        'text-halo-color': '#ffffff',
                        'text-halo-width': 1.2,
                    },
                });

                const pointBounds = new maplibregl.LngLatBounds();
                bounds.forEach(([lat, lon]) => pointBounds.extend([lon, lat]));
                map.fitBounds(pointBounds, { padding: 36, duration: 0 });

                markerFeatures
                    .filter((feature) => feature.properties.kind === 'fuel-stop')
                    .forEach((feature) => {
                        const marker = new maplibregl.Marker({
                            element: createRouteFuelMarkerElement(feature),
                            anchor: 'bottom',
                        })
                            .setLngLat(feature.geometry.coordinates)
                            .addTo(map);
                        routeFuelMarkers.push(marker);
                    });
            });

            routeMapLegend.innerHTML = [
                '<span class="route-map-chip"><span class="route-map-dot" style="background:#166534"></span>Origin</span>',
                '<span class="route-map-chip"><span class="route-map-dot" style="background:#0f766e"></span>Destination</span>',
                '<span class="route-map-chip"><span class="route-map-dot" style="background:#b45309"></span>Fuel stop</span>',
            ].join('');
        }

        function renderRouteBreakdown(plan) {
            const rows = [];
            plan.segments.forEach((segment, segmentIndex) => {
                segment.routePieces.forEach((piece) => {
                    if (piece.type === 'route') {
                        (piece.route.steps || []).forEach((step, stepIndex) => {
                            rows.push({
                                leg: segmentIndex + 1,
                                type: 'Turn',
                                instruction: routeStepInstruction(step),
                                distance: formatRouteDistance(step.distance || 0),
                                duration: formatRouteDuration(step.duration || 0),
                                details: `${stepIndex + 1} / ${piece.route.steps.length}`,
                            });
                        });
                    } else if (piece.type === 'fuel-stop') {
                        rows.push({
                            leg: segmentIndex + 1,
                            type: 'Fuel stop',
                            instruction: `${piece.station.station_name} at ${piece.station.state} ${piece.station.source.toUpperCase()} - ${routeFuelPriceText(piece.station.price)}/L`,
                            distance: '-',
                            duration: '-',
                            details: `${Number(piece.litresPurchased || 0).toFixed(1)} L, $${(Number(piece.purchaseCents || 0) / 100).toFixed(2)}${piece.safetyFallback ? ' safety stop' : ''}`,
                        });
                    }
                });
            });

            if (rows.length === 0) {
                routeLegs.innerHTML = renderRouteEmpty('Plan a route to see leg breakdowns.');
                return;
            }

            routeLegs.innerHTML = `
                <table class="route-table">
                    <thead>
                        <tr>
                            <th>Leg</th>
                            <th>Type</th>
                            <th>Instruction</th>
                            <th>Distance</th>
                            <th>Duration</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.map((row) => `
                            <tr class="${row.type === 'Fuel stop' ? 'route-breakdown-row route-breakdown-stop' : 'route-breakdown-row'}">
                                <td>${escapeHtml(String(row.leg))}</td>
                                <td>${escapeHtml(row.type)}</td>
                                <td>
                                    <span class="route-breakdown-step">${escapeHtml(row.instruction)}</span>
                                </td>
                                <td>${escapeHtml(row.distance)}</td>
                                <td>${escapeHtml(row.duration)}</td>
                                <td>
                                    <span class="route-breakdown-subtext">${escapeHtml(row.details)}</span>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        async function resolveRouteLocation(query) {
                        const payload = await apiRequest(`/api/geo/search?q=${encodeURIComponent(query)}&limit=10`);
            const results = Array.isArray(payload.results) ? payload.results.filter((result) => !routeGeocodeIsAdministrative(result)) : [];
            const result = results[0] || null;
            if (!result) {
                throw new Error(`No geocoding result for "${query}"`);
            }
            return {
                query,
                display_name: routeGeocodeInputValue(result, result.display_name || query),
                lat: Number(result.lat),
                lon: Number(result.lon),
            };
        }

        function restoreRoutePlannerState(state) {
            if (!state || typeof state !== 'object') {
                return false;
            }

            resetRoutePlanner({ clearStorage: false });
            routeOrigin.value = String(state.origin || '');
            const destinations = Array.isArray(state.destinations) ? state.destinations : [];
            routeDestinationList.innerHTML = '';
            routeDestinationCounter = 0;
            (destinations.length > 0 ? destinations : ['']).forEach((value) => addRouteDestination(String(value || '')));
            routeFuelFill.value = String(state.fuelFill || '');
            routeFuelEconomy.value = String(state.fuelEconomy || '');
            routeReturnDirect.checked = String(state.returnMode || 'direct') !== 'reverses';
            routeReturnReverses.checked = String(state.returnMode || 'direct') === 'reverses';
            syncRouteFuelSelector();
            if (String(state.fuelValue || '').trim() !== '') {
                routeFuelType.value = String(state.fuelValue || '').trim();
            }
            persistFuelLabel(routeFuelSelectedLabel());
            routeStatus.textContent = state.planned ? 'Restored last planned route.' : 'Restored saved route inputs.';
            return true;
        }

        async function planRoute() {
            const originValue = routeOrigin.value.trim();
            const destinationValues = routeDestinationValues();
            const fuelFill = routeFuelDefaultFillValue();
            const fuelEconomy = routeFuelDefaultEconomyValue();

            if (originValue === '') {
                routeStatus.textContent = 'Origin is required.';
                return;
            }
            if (destinationValues.length === 0) {
                routeStatus.textContent = 'At least one destination is required.';
                return;
            }
            if (fuelFill <= 0 || fuelEconomy <= 0) {
                routeStatus.textContent = 'Fuel fill and fuel economy must be greater than zero.';
                return;
            }

            routePlan.disabled = true;
            routeStatus.textContent = 'Resolving locations and building route legs...';
            routeSummary.innerHTML = renderRouteEmpty('Planning route...');
            routeMap.innerHTML = renderRouteEmpty('Resolving locations...');
            routeMapLegend.innerHTML = '';
            routeLegs.innerHTML = renderRouteEmpty('Building legs...');

            try {
                const origin = await resolveRouteLocation(originValue);
                const destinations = await Promise.all(destinationValues.map((value) => resolveRouteLocation(value)));
                const tripSequence = buildRouteSequence(origin, destinations);
                const plan = await buildRoutePlan(tripSequence, routeFuelQueryLabel(), fuelFill, fuelEconomy);
                renderRouteSummary(plan);
                renderRouteMap(plan);
                renderRouteBreakdown(plan);
                saveRoutePlannerState(true);

                const returnMode = routeReturnMode() === 'reverses'
                    ? 'Return reverses path'
                    : 'Return direct to origin';
                routeStatus.textContent = `Planned ${plan.segments.length} legs using ${returnMode}.`;
            } catch (error) {
                routeStatus.textContent = error.message;
                routeSummary.innerHTML = renderRouteEmpty(error.message);
                routeMap.innerHTML = renderRouteEmpty(error.message);
                routeMapLegend.innerHTML = '';
                routeLegs.innerHTML = renderRouteEmpty(error.message);
            } finally {
                routePlan.disabled = false;
            }
        }

        function resetRoutePlanner(options = {}) {
            const clearStorage = options.clearStorage !== false;
            routeOrigin.value = '';
            syncRouteFuelSelector();
            routeFuelFill.value = '';
            routeFuelEconomy.value = '';
            routeReturnDirect.checked = true;
            routeReturnReverses.checked = false;
            routeDestinationList.innerHTML = '';
            routeDestinationCounter = 0;
            addRouteDestination('');
            routeStatus.textContent = 'Enter a trip to build a route.';
            routeSummary.innerHTML = renderRouteEmpty('No route planned yet.');
            routeMap.innerHTML = renderRouteEmpty('No route planned yet.');
            routeMapLegend.innerHTML = '';
            routeLegs.innerHTML = renderRouteEmpty('No route planned yet.');
            if (clearStorage) {
                clearRoutePlannerState();
            }
        }

        function loadRouteTestCities() {
            resetRoutePlanner();
            routeOrigin.value = 'Cairns';
            syncRouteFuelSelector();
            const destinations = Array.from(routeDestinationList.querySelectorAll('.route-stop-row'));
            if (destinations[0]) {
                destinations[0].querySelector('.route-destination-input').value = 'Birdsville';
            }
            addRouteDestination('Brisbane');
            routeFuelFill.value = '60';
            routeFuelEconomy.value = '12';
            routeStatus.textContent = 'Loaded test cities: Cairns -> Birdsville -> Brisbane.';
            planRoute();
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

        function fuelRegionChoices() {
            const state = String(fuelState.value || '').trim().toUpperCase();
            const states = state !== '' ? [state] : Object.keys(fuelRegionCatalog);
            const options = [];
            states.forEach((entryState) => {
                (fuelRegionCatalog[entryState] || []).forEach((region) => {
                    options.push({
                        value: `${entryState}:${region.key}`,
                        label: state === '' ? `${region.label}, ${entryState}` : region.label,
                        state: entryState,
                        key: region.key,
                        lat: region.lat,
                        lon: region.lon,
                        radius_km: region.radius_km,
                    });
                });
            });
            return options;
        }

        function fuelRegionSelectedValue() {
            const current = String(fuelRegion?.value || '').trim();
            if (current !== '') {
                return current;
            }
            const cookieValue = savedFuelRegionValue();
            if (cookieValue !== '') {
                return cookieValue;
            }
            return fuelRegionChoices()[0]?.value || '';
        }

        function fuelRegionSelectedOption() {
            const value = fuelRegionSelectedValue();
            return fuelRegionChoices().find((item) => item.value === value) || null;
        }

        function syncFuelRegions() {
            if (!fuelRegion) {
                return;
            }
            const options = fuelRegionChoices();
            const current = options.find((item) => item.value === fuelRegionSelectedValue());
            setSelectOptions(fuelRegion, options, current ? current.value : (options[0]?.value || ''));
        }

        function syncFuelSelectors() {
            const currentLabel = selectedFuelLabel();
            const options = filteredFuelOptions();
            const desiredDefaultFuel = fuelState.value === 'QLD'
                ? '3'
                : ((fuelState.value === 'NSW' || fuelState.value === 'TAS') ? 'DL' : '');
            const labelFuel = options.find((item) => item.label.toLowerCase() === currentLabel.toLowerCase())?.value || '';
            const fallbackFuel = labelFuel !== ''
                ? labelFuel
                : (options.find((item) => item.value === desiredDefaultFuel)?.value || '');
            setSelectOptions(fuelType, options, fallbackFuel);
        }

        function selectedFuelFilters() {
            const params = new URLSearchParams({
                state: fuelState.value || '',
                fuel: fuelType.value || '',
            });
            const region = fuelRegionSelectedOption();
            if (region) {
                params.set('lat', String(region.lat));
                params.set('lon', String(region.lon));
                params.set('radius_km', String(region.radius_km));
            }
            return params;
        }

        async function handleFuelFilterChange() {
            syncFuelRegions();
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

        function fuelMapColor(price, minPrice, maxPrice) {
            const value = Number(price);
            if (!Number.isFinite(value)) {
                return '#94a3b8';
            }

            const min = Number(minPrice);
            const max = Number(maxPrice);
            if (!Number.isFinite(min) || !Number.isFinite(max) || max <= min) {
                return '#0f766e';
            }

            const ratio = Math.max(0, Math.min(1, (value - min) / (max - min)));
            if (ratio <= 0.5) {
                const local = ratio / 0.5;
                return local <= 0.5 ? '#16a34a' : '#ca8a04';
            }
            const local = (ratio - 0.5) / 0.5;
            return local <= 0.5 ? '#ca8a04' : '#b91c1c';
        }

        function renderFuelMapLegend(rows) {
            if (!fuelMapLegend) {
                return;
            }

            const region = fuelRegionSelectedOption();
            const stationCount = Array.isArray(rows) ? rows.length : 0;
            fuelMapLegend.innerHTML = `
                <span class="route-map-chip"><span class="route-map-dot" style="background:#16a34a"></span>Cheaper</span>
                <span class="route-map-chip"><span class="route-map-dot" style="background:#ca8a04"></span>Mid-range</span>
                <span class="route-map-chip"><span class="route-map-dot" style="background:#b91c1c"></span>Higher</span>
                <span class="route-map-chip"><span class="route-map-dot" style="background:#94a3b8"></span>No price</span>
                <span class="route-map-chip">${escapeHtml(region ? `${region.label}, ${region.state}` : 'Selected region')}</span>
                <span class="route-map-chip">${escapeHtml(selectedFuelLabel() || 'Selected fuel')}</span>
                <span class="route-map-chip">${escapeHtml(`${stationCount} stations plotted`)}</span>
            `;
        }

        function fuelMapPopupHtml(row) {
            const station = escapeHtml(String(row.station_name || '').trim());
            const address = escapeHtml(String(row.address || '').trim());
            const fuelName = escapeHtml(selectedFuelLabel() || String(row.fuel_name || 'Fuel'));
            const price = escapeHtml(formatPrice(row.price));
            const updatedAt = escapeHtml(formatDateTime(row.updated_at));
            const source = escapeHtml(`${String(row.state || '').trim()} · ${String(row.source || '').toUpperCase()}`);
            return `
                <div style="min-width:220px;max-width:280px;font:inherit;color:#16212d;">
                    <strong style="display:block;font-size:13px;line-height:1.3;margin-bottom:4px;">${station}</strong>
                    <div style="font-size:11px;color:#5b6775;line-height:1.3;margin-bottom:6px;">${address}</div>
                    <div style="font-size:12px;line-height:1.35;margin-bottom:4px;"><strong>${fuelName}</strong></div>
                    <div style="font-size:13px;line-height:1.35;margin-bottom:4px;">${price}</div>
                    <div style="font-size:11px;color:#5b6775;line-height:1.3;">${source}</div>
                    <div style="font-size:11px;color:#5b6775;line-height:1.3;">Updated ${updatedAt}</div>
                </div>
            `;
        }

        function fuelMapFeatureCollection(rows) {
            const prices = rows
                .map((row) => Number(row.price))
                .filter((value) => Number.isFinite(value));
            const minPrice = prices.length > 0 ? Math.min(...prices) : null;
            const maxPrice = prices.length > 0 ? Math.max(...prices) : null;
            const features = rows
                .filter((row) => Number.isFinite(Number(row.latitude)) && Number.isFinite(Number(row.longitude)))
                .map((row) => ({
                    type: 'Feature',
                    properties: {
                        station_name: String(row.station_name || ''),
                        address: String(row.address || ''),
                        price: String(row.price ?? ''),
                        price_value: Number(row.price),
                        price_text: formatPrice(row.price),
                        fuel_name: String(row.fuel_name || ''),
                        source: String(row.source || ''),
                        state: String(row.state || ''),
                        updated_at: String(row.updated_at || ''),
                        color: fuelMapColor(row.price, minPrice, maxPrice),
                    },
                    geometry: {
                        type: 'Point',
                        coordinates: [Number(row.longitude), Number(row.latitude)],
                    },
                }));
            return {
                type: 'FeatureCollection',
                features,
                minPrice,
                maxPrice,
            };
        }

        function updateFuelMapSource(collection) {
            if (!fuelMapInstance || !fuelMapReady) {
                fuelMapPendingData = collection;
                return;
            }

            const source = fuelMapInstance.getSource('fuel-stations');
            if (source) {
                source.setData(collection);
            }

            const features = Array.isArray(collection.features) ? collection.features : [];
            if (features.length === 0) {
                fuelMapLegend.innerHTML = '';
                fuelMap.innerHTML = renderRouteEmpty('No fuel stations available for this filter.');
                return;
            }

            const prices = features.map((feature) => Number(feature.properties?.price_value)).filter((value) => Number.isFinite(value));
            const minPrice = prices.length > 0 ? Math.min(...prices) : 0;
            const maxPrice = prices.length > 0 ? Math.max(...prices) : 0;
            const midPrice = prices.length > 0 ? (minPrice + maxPrice) / 2 : 0;
            const colorExpression = prices.length > 0
                ? ['interpolate', ['linear'], ['get', 'price_value'], minPrice, '#16a34a', midPrice, '#ca8a04', maxPrice, '#b91c1c']
                : '#0f766e';
            if (fuelMapInstance.getLayer('fuel-stations-circle')) {
                fuelMapInstance.setPaintProperty('fuel-stations-circle', 'circle-color', colorExpression);
            }

            if (collection.features.length === 1) {
                const only = collection.features[0];
                fuelMapInstance.easeTo({
                    center: only.geometry.coordinates,
                    zoom: 12,
                    duration: 400,
                });
            } else if (collection.features.length > 1) {
                const bounds = new maplibregl.LngLatBounds();
                collection.features.forEach((feature) => {
                    bounds.extend(feature.geometry.coordinates);
                });
                fuelMapInstance.fitBounds(bounds, { padding: 50, maxZoom: 12, duration: 400 });
            }

            renderFuelMapLegend(features);
        }

        function renderFuelMap(rows) {
            if (!fuelMap) {
                return;
            }

            const collection = fuelMapFeatureCollection(Array.isArray(rows) ? rows : []);
            if (!window.maplibregl) {
                fuelMap.innerHTML = renderRouteEmpty('Fuel map unavailable in this browser.');
                fuelMapLegend.innerHTML = '';
                return;
            }

            if (!fuelMapInstance) {
                fuelMap.innerHTML = '';
                const mapConfig = window.fuelauMapConfig || {};
                const styleUrl = mapConfig.style_url;
                if (!styleUrl) {
                    fuelMap.innerHTML = renderRouteEmpty('Map style is not configured.');
                    fuelMapLegend.innerHTML = '';
                    return;
                }

                fuelMapInstance = new maplibregl.Map({
                    container: fuelMap,
                    style: styleUrl,
                    center: [134.0, -25.0],
                    zoom: 4,
                    attributionControl: true,
                    preserveDrawingBuffer: false,
                });
                fuelMapInstance.addControl(new maplibregl.NavigationControl({ showCompass: true, showZoom: true }), 'top-right');
                fuelMapPopup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, offset: 16 });

                fuelMapInstance.on('load', () => {
                    fuelMapReady = true;
                    if (!fuelMapInstance.getSource('fuel-stations')) {
                        fuelMapInstance.addSource('fuel-stations', {
                            type: 'geojson',
                            data: collection,
                        });
                    }
                    if (!fuelMapInstance.getLayer('fuel-stations-circle')) {
                        fuelMapInstance.addLayer({
                            id: 'fuel-stations-circle',
                            type: 'circle',
                            source: 'fuel-stations',
                            paint: {
                                'circle-radius': 7,
                                'circle-stroke-width': 2,
                                'circle-stroke-color': '#ffffff',
                                'circle-opacity': 0.95,
                            },
                        });
                    }

                    fuelMapInstance.on('mouseenter', 'fuel-stations-circle', () => {
                        fuelMapInstance.getCanvas().style.cursor = 'pointer';
                    });
                    fuelMapInstance.on('mouseleave', 'fuel-stations-circle', () => {
                        fuelMapInstance.getCanvas().style.cursor = '';
                    });
                    fuelMapInstance.on('click', 'fuel-stations-circle', (event) => {
                        const feature = event.features && event.features[0];
                        if (!feature || !fuelMapPopup) {
                            return;
                        }
                        fuelMapPopup
                            .setLngLat(feature.geometry.coordinates)
                            .setHTML(fuelMapPopupHtml(feature.properties || {}))
                            .addTo(fuelMapInstance);
                    });

                    updateFuelMapSource(fuelMapPendingData || collection);
                    fuelMapPendingData = null;
                });
            } else if (fuelMapReady) {
                updateFuelMapSource(collection);
            } else {
                fuelMapPendingData = collection;
            }
        }

        async function loadFuelDashboard() {
            fuelStatus.textContent = 'Loading fuel dashboard...';
            try {
                const options = await loadFuelOptions();
                if (!fuelState.options.length) {
                    setSelectOptions(fuelState, options.states, 'QLD');
                }
                syncFuelRegions();
                syncFuelSelectors();
                syncRouteFuelSelector();

                const filters = selectedFuelFilters();
                const [sources, current, weekly, monthly] = await Promise.all([
                    apiRequest('/api/fuel/sources'),
                    apiRequest(`/api/fuel/current?${filters.toString()}&limit=500`),
                    apiRequest(`/api/fuel/history?${filters.toString()}&period=weekly`),
                    apiRequest(`/api/fuel/history?${filters.toString()}&period=monthly`),
                ]);

                renderFuelSummary(sources.sources || {});
                renderLineChart(fuelWeeklyChart, fuelWeeklyMeta, weekly.series || []);
                renderBarChart(fuelMonthlyChart, fuelMonthlyMeta, monthly.series || []);
                renderSnapshot(current.rows || []);
                renderFuelMap(current.rows || []);
                fuelStatus.textContent = `Loaded ${Array.isArray(current.rows) ? current.rows.length : 0} current records for the selected filter.`;
            } catch (error) {
                fuelStatus.textContent = error.message;
                fuelWeeklyChart.innerHTML = chartEmpty(error.message);
                fuelMonthlyChart.innerHTML = chartEmpty(error.message);
                fuelSnapshot.innerHTML = chartEmpty(error.message);
                if (fuelMap) {
                    fuelMap.innerHTML = renderRouteEmpty(error.message);
                }
                if (fuelMapLegend) {
                    fuelMapLegend.innerHTML = '';
                }
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
                const expectedBadge = service.expected_badge || 'idle';
                const expectedState = service.expected_state || (service.kind === 'setup_job' ? 'prepared or exited' : 'running when enabled');
                const expectedDetail = service.expected_detail || '';
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
                    <span class="badge ${escapeHtml(expectedBadge)}">Expected: ${escapeHtml(expectedState)}</span>
                    <div class="container-meta">
                        <span>Service: ${escapeHtml(service.service)}</span>
                        <span>Lifecycle: ${escapeHtml(lifecycle)}</span>
                        <span>Role: ${escapeHtml(service.role || '')}</span>
                        <span>Profile: ${escapeHtml(service.profile || 'default')}</span>
                        ${expectedDetail !== '' ? `<span>Expected Detail: ${escapeHtml(expectedDetail)}</span>` : ''}
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
        routeTest.addEventListener('click', loadRouteTestCities);
        routeReset.addEventListener('click', resetRoutePlanner);

        fuelState.addEventListener('change', handleFuelFilterChange);
        fuelRegion.addEventListener('change', async () => {
            persistFuelRegion(fuelRegionSelectedValue());
            syncFuelSelectors();
            await loadFuelDashboard();
        });
        fuelType.addEventListener('change', async () => {
            persistFuelLabel(fuelTypeSelectedLabel());
            syncRouteFuelSelector();
            await loadFuelDashboard();
        });
        routeFuelType.addEventListener('change', async () => {
            persistFuelLabel(routeFuelSelectedLabel());
            syncFuelSelectors();
            await loadFuelDashboard();
        });
        refreshFuelDashboard.addEventListener('click', loadFuelDashboard);
        attachRouteAutocomplete(routeOrigin);
        (async () => {
            const savedActiveTab = loadActiveTab();
            resetRoutePlanner({ clearStorage: false });
            syncFuelRegions();
            await loadFuelDashboard();

            const savedRouteState = loadRoutePlannerState();
            if (savedRouteState) {
                restoreRoutePlannerState(savedRouteState);
            }

            if (savedActiveTab === 'route-planning-tab') {
                activateTab('route-planning-tab');
                if (savedRouteState && savedRouteState.planned) {
                    await planRoute();
                }
            }
        })();
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

    if ($path === '/api/map/config') {
        fuelauJsonResponse(fuelauMapTileConfig());
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
