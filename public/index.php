<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

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
            font-family: Arial, Helvetica, sans-serif;
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
                <p>Fuel price data controls will be added here.</p>
            </div>
            <div class="panel" role="tabpanel" id="route-planning" aria-labelledby="route-planning-tab">
                <h1>Route Planning</h1>
                <p>Route planning controls will be added here.</p>
            </div>
            <div class="panel" role="tabpanel" id="container-management" aria-labelledby="container-management-tab">
                <h1>Container Management</h1>
                <p>Container management controls will be added here.</p>
            </div>
        </section>
    </main>

    <script>
        const tabs = document.querySelectorAll('.tab');
        const panels = document.querySelectorAll('.panel');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((item) => item.setAttribute('aria-selected', 'false'));
                panels.forEach((panel) => panel.classList.remove('active'));

                tab.setAttribute('aria-selected', 'true');
                document.getElementById(tab.getAttribute('aria-controls')).classList.add('active');
            });
        });
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
