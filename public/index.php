<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/docker.php';

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
        let selectedContainerId = null;

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((item) => item.setAttribute('aria-selected', 'false'));
                panels.forEach((panel) => panel.classList.remove('active'));

                tab.setAttribute('aria-selected', 'true');
                document.getElementById(tab.getAttribute('aria-controls')).classList.add('active');

                if (tab.id === 'container-management-tab') {
                    loadContainers();
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

        function renderContainers(containers) {
            containerGrid.innerHTML = '';

            if (containers.length === 0) {
                containerGrid.innerHTML = '<p>No Compose containers found for this project.</p>';
                selectedContainerId = null;
                restartContainer.disabled = true;
                return;
            }

            containers.forEach((container) => {
                const card = document.createElement('article');
                card.className = `container-card${container.id === selectedContainerId ? ' selected' : ''}`;

                const statusClass = container.state === 'running' ? 'running' : (container.state === 'exited' ? 'exited' : '');
                card.innerHTML = `
                    <h2>${escapeHtml(container.service || container.name)}</h2>
                    <span class="badge ${statusClass}">${escapeHtml(container.state || 'unknown')}</span>
                    <div class="container-meta">
                        <span>Name: ${escapeHtml(container.name)}</span>
                        <span>Image: ${escapeHtml(container.image)}</span>
                        <span>Status: ${escapeHtml(container.status)}</span>
                        <span>Ports: ${escapeHtml(renderPorts(container.ports))}</span>
                    </div>
                    <button class="button" type="button">View Logs</button>
                `;

                card.querySelector('button').addEventListener('click', () => {
                    selectedContainerId = container.id;
                    restartContainer.disabled = false;
                    renderContainers(containers);
                    loadLogs(container.id);
                });

                containerGrid.appendChild(card);
            });
        }

        async function loadContainers() {
            containerStatus.textContent = 'Loading container status...';
            try {
                const payload = await apiRequest('/api/docker/status');
                renderContainers(payload.containers || []);
                const disk = payload.disk || {};
                containerStatus.textContent = `Project: ${payload.project}. Containers: ${(payload.containers || []).length}. Images: ${disk.image_count || 0}. Build cache: ${formatBytes(disk.build_cache_size)}.`;
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
            'containers' => fuelauDockerContainers(),
            'disk' => fuelauDockerDiskSummary(),
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
