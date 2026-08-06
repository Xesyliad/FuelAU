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
const fuelStopFinderOrigin = document.getElementById('fuel-stop-finder-origin');
const fuelStopFinderOriginResults = document.getElementById('fuel-stop-finder-origin-results');
const fuelStopFinderDestination = document.getElementById('fuel-stop-finder-destination');
const fuelStopFinderDestinationResults = document.getElementById('fuel-stop-finder-destination-results');
const fuelStopFinderFuelType = document.getElementById('fuel-stop-finder-fuel-type');
const fuelStopFinderEconomy = document.getElementById('fuel-stop-finder-economy');
const fuelStopFinderPlan = document.getElementById('fuel-stop-finder-plan');
const fuelStopFinderReset = document.getElementById('fuel-stop-finder-reset');
const fuelStopFinderStatus = document.getElementById('fuel-stop-finder-status');
const fuelStopFinderDetail = document.getElementById('fuel-stop-finder-detail');
const fuelStopFinderSummary = document.getElementById('fuel-stop-finder-summary');
const fuelStopFinderRecommendation = document.getElementById('fuel-stop-finder-recommendation');
const fuelStopFinderMap = document.getElementById('fuel-stop-finder-map');
const fuelStopFinderMapLegend = document.getElementById('fuel-stop-finder-map-legend');
const fuelStopFinderLegs = document.getElementById('fuel-stop-finder-legs');
const routeOrigin = document.getElementById('route-origin');
const routeOriginResults = document.getElementById('route-origin-results');
const routeFuelType = document.getElementById('route-fuel-type');
const routeTankCapacity = document.getElementById('route-tank-capacity');
const routeStartingFuel = document.getElementById('route-starting-fuel');
const routeFuelReserve = document.getElementById('route-fuel-reserve');
const routeFuelEconomy = document.getElementById('route-fuel-economy');
const routeOptimizationMode = document.getElementById('route-optimization-mode');
const routeUseOptimizer = document.getElementById('route-use-optimizer');
const routeOptimizerFields = document.querySelectorAll('[data-route-optimizer-field]');
const routeDestinationList = document.getElementById('route-destination-list');
const routeAddDestination = document.getElementById('route-add-destination');
const routeReturnReverses = document.getElementById('route-return-reverses');
const routeReturnDirect = document.getElementById('route-return-direct');
const routeReturnOneWay = document.getElementById('route-return-one-way');
const routePlan = document.getElementById('route-plan');
const routeTest = document.getElementById('route-test');
const routeReset = document.getElementById('route-reset');
const routeStatus = document.getElementById('route-status');
const routeExcludedStatus = document.getElementById('route-excluded-status');
const routeSummary = document.getElementById('route-summary');
const routeMap = document.getElementById('route-map');
const routeMapLegend = document.getElementById('route-map-legend');
const routeLegs = document.getElementById('route-legs');
const containerManagementEnabled = Boolean(window.fuelauAppConfig?.containerManagementEnabled);
const routeOptimizerV2Enabled = Boolean(window.fuelauAppConfig?.routeOptimizerV2Enabled);
const routeOptimizerV2Default = routeOptimizerV2Enabled
    && Boolean(window.fuelauAppConfig?.routeOptimizerV2Default);
let selectedContainerId = null;
let selectedContainerRestartable = false;
let fuelOptions = null;
let routeDestinationCounter = 0;
let draggedRouteDestinationRow = null;
let fuelMapInstance = null;
let fuelMapReady = false;
let fuelMapPopup = null;
let fuelMapPendingData = null;
let fuelCurrentRows = [];
let fuelMapRows = [];
let fuelMapAutoRefreshTimer = null;
let fuelMapAutoRefreshSuppressed = false;
let fuelMapViewportAbortController = null;
let fuelMapViewportLastRequestKey = '';
let fuelMapLegendContext = '';
let fuelStopFinderMapInstance = null;
let fuelStopFinderMarkers = [];
let routeMapInstance = null;
let routeFuelMarkers = [];
const fuelSelectionCookieName = 'fuelau_selected_fuel';
const fuelRegionCookieName = 'fuelau_selected_region';
const fuelStopFinderStateKey = 'fuelau_fuel_stop_finder_state_v1';
const topoStyleId = 'topo-3d';
const topoContourSourceId = 'fuelau-terrain-contours';
const topoContourLayerId = 'fuelau-terrain-contour-lines';
const topoContourLabelLayerId = 'fuelau-terrain-contour-labels';
const topoDemUrl = `${String(window.fuelauMapConfig?.base_url || '/tiles').replace(/\/$/, '')}/data/terrain/{z}/{x}/{y}.png`;
const topoContourThresholds = {
    10: [200, 1000],
    11: [100, 500],
    12: [50, 200],
    13: [20, 100],
    14: [10, 50],
    15: [5, 20],
};
let topoContourDemSource = null;
let topoContourProtocolReady = false;

function fuelauIsTopographicStyle() {
    return String(window.fuelauMapConfig?.style_id || '') === topoStyleId;
}

function fuelauFirstExistingLayer(map, layerIds) {
    return layerIds.find((layerId) => map.getLayer(layerId)) || null;
}

function fuelauEnsureContourProtocol() {
    if (topoContourProtocolReady || !window.mlcontour) {
        return;
    }

    try {
        topoContourDemSource = new window.mlcontour.DemSource({
            url: topoDemUrl,
            encoding: 'terrarium',
            maxzoom: 8,
            worker: true,
            cacheSize: 100,
            timeoutMs: 15000,
        });
        topoContourDemSource.setupMaplibre(maplibregl);
        topoContourProtocolReady = true;
    } catch (error) {
        console.warn('Contour overlays are unavailable for the topographic map.', error);
        topoContourDemSource = null;
    }
}

function fuelauAddTopographicEnhancements(map) {
    if (!fuelauIsTopographicStyle()) {
        return;
    }

    fuelauEnsureContourProtocol();
    if (!topoContourDemSource) {
        return;
    }

    if (!map.getSource(topoContourSourceId)) {
        map.addSource(topoContourSourceId, {
            type: 'vector',
            tiles: [
                topoContourDemSource.contourProtocolUrl({
                    multiplier: 1,
                    thresholds: topoContourThresholds,
                    contourLayer: 'contours',
                    elevationKey: 'ele',
                    levelKey: 'level',
                    overzoom: 1,
                }),
            ],
            maxzoom: 16,
        });
    }

    if (!map.getLayer(topoContourLayerId)) {
        map.addLayer({
            id: topoContourLayerId,
            type: 'line',
            source: topoContourSourceId,
            'source-layer': 'contours',
                paint: {
                    'line-color': [
                        'case',
                        ['==', ['get', 'level'], 1],
                        '#8b5e34',
                        '#a8794a',
                    ],
                    'line-width': [
                        'case',
                        ['==', ['get', 'level'], 1],
                        0.9,
                        0.45,
                    ],
                    'line-opacity': 0.45,
                },
            layout: {
                'line-join': 'round',
                'line-cap': 'round',
            },
        }, fuelauFirstExistingLayer(map, ['road-ferry', 'road-minor', 'road-secondary']) || undefined);
    }

    if (!map.getLayer(topoContourLabelLayerId)) {
        map.addLayer({
            id: topoContourLabelLayerId,
            type: 'symbol',
            source: topoContourSourceId,
            'source-layer': 'contours',
            filter: ['==', ['get', 'level'], 1],
                layout: {
                    'symbol-placement': 'line',
                    'text-field': ['concat', ['to-string', ['get', 'ele']], ' m'],
                    'text-font': ['Noto Sans Regular'],
                    'text-size': 9.5,
                },
                paint: {
                    'text-color': '#6b4c2f',
                    'text-halo-color': '#f9f4ed',
                    'text-halo-width': 1,
                },
            }, fuelauFirstExistingLayer(map, ['road-labels', 'place-label']) || undefined);
    }

}
const routePlannerStateKey = 'fuelau_route_planner_state_v1';
const routePlannerRouteBudgetLimit = 80;
const routePlannerFuelBudgetLimit = 50;
const routePlannerDefaultTankCapacityL = 60;
const routePlannerDefaultStartingFuelL = 60;
const routePlannerDefaultReserveL = 6;
const routePlannerDefaultFuelEconomyLPer100km = 12;
const routePlannerFallbackFuelPriceCentsPerL = 250;
const activeTabKey = 'fuelau_active_tab_v1';
let containerManagementCsrfToken = '';

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
    SA: [
        { key: 'adelaide', label: 'Adelaide', lat: -34.9285, lon: 138.6007, radius_km: 80 },
        { key: 'mount-gambier', label: 'Mount Gambier', lat: -37.8318, lon: 140.7792, radius_km: 35 },
        { key: 'whyalla', label: 'Whyalla', lat: -33.0369, lon: 137.5648, radius_km: 30 },
        { key: 'port-augusta', label: 'Port Augusta', lat: -32.4907, lon: 137.7655, radius_km: 30 },
        { key: 'port-pirie', label: 'Port Pirie', lat: -33.1799, lon: 138.0058, radius_km: 25 },
        { key: 'murray-bridge', label: 'Murray Bridge', lat: -35.1209, lon: 139.2734, radius_km: 25 },
        { key: 'gawler', label: 'Gawler', lat: -34.5984, lon: 138.7454, radius_km: 25 },
        { key: 'port-lincoln', label: 'Port Lincoln', lat: -34.7215, lon: 135.8586, radius_km: 25 },
        { key: 'victor-harbor', label: 'Victor Harbor', lat: -35.5513, lon: 138.6219, radius_km: 25 },
        { key: 'mount-barker', label: 'Mount Barker', lat: -35.0664, lon: 138.8604, radius_km: 20 },
    ],
    WA: [
        { key: 'perth-north', label: 'Perth - North', lat: -31.9505, lon: 115.8605, radius_km: 45 },
        { key: 'perth-south', label: 'Perth - South', lat: -32.0144, lon: 115.8167, radius_km: 45 },
        { key: 'fremantle', label: 'Fremantle', lat: -32.0569, lon: 115.7439, radius_km: 30 },
        { key: 'mandurah', label: 'Mandurah', lat: -32.5269, lon: 115.7217, radius_km: 35 },
        { key: 'bunbury', label: 'Bunbury', lat: -33.3275, lon: 115.6414, radius_km: 35 },
        { key: 'busselton', label: 'Busselton', lat: -33.6550, lon: 115.3500, radius_km: 30 },
        { key: 'augusta-margaret-river', label: 'Augusta / Margaret River', lat: -33.9530, lon: 115.0720, radius_km: 35 },
        { key: 'geraldton', label: 'Geraldton', lat: -28.7761, lon: 114.6140, radius_km: 35 },
        { key: 'albany', label: 'Albany', lat: -35.0228, lon: 117.8814, radius_km: 35 },
        { key: 'kalgoorlie', label: 'Kalgoorlie', lat: -30.7489, lon: 121.4658, radius_km: 35 },
        { key: 'broome', label: 'Broome', lat: -17.9614, lon: 122.2362, radius_km: 35 },
        { key: 'karratha', label: 'Karratha', lat: -20.7377, lon: 116.8463, radius_km: 35 },
        { key: 'port-hedland', label: 'Port Hedland', lat: -20.3104, lon: 118.6060, radius_km: 35 },
        { key: 'kununurra', label: 'Kununurra', lat: -15.7758, lon: 128.7389, radius_km: 35 },
        { key: 'esperance', label: 'Esperance', lat: -33.8590, lon: 121.8896, radius_km: 30 },
        { key: 'northam', label: 'Northam', lat: -31.6540, lon: 116.6734, radius_km: 25 },
        { key: 'narrogin', label: 'Narrogin', lat: -32.9349, lon: 117.1773, radius_km: 25 },
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
    NT: [
        { key: 'darwin', label: 'Darwin', lat: -12.4634, lon: 130.8456, radius_km: 75 },
        { key: 'palmerston', label: 'Palmerston', lat: -12.4860, lon: 130.9833, radius_km: 35 },
        { key: 'katherine', label: 'Katherine', lat: -14.4650, lon: 132.2635, radius_km: 40 },
        { key: 'tennant-creek', label: 'Tennant Creek', lat: -19.6466, lon: 134.1911, radius_km: 35 },
        { key: 'alice-springs', label: 'Alice Springs', lat: -23.6980, lon: 133.8807, radius_km: 60 },
        { key: 'nhulunbuy', label: 'Nhulunbuy', lat: -12.1811, lon: 136.7790, radius_km: 30 },
        { key: 'jabiru', label: 'Jabiru', lat: -12.6700, lon: 132.8300, radius_km: 25 },
        { key: 'yulara', label: 'Yulara', lat: -25.2406, lon: 130.9847, radius_km: 25 },
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

    if (tab.id !== 'fuel-prices-tab') {
        if (fuelMapAutoRefreshTimer) {
            window.clearTimeout(fuelMapAutoRefreshTimer);
            fuelMapAutoRefreshTimer = null;
        }
        if (fuelMapViewportAbortController) {
            fuelMapViewportAbortController.abort();
            fuelMapViewportAbortController = null;
        }
        destroyFuelMap();
    }

    if (containerManagementEnabled && tab.id === 'container-management-tab') {
        loadContainers();
    }
    if (tab.id === 'fuel-prices-tab') {
        loadFuelDashboard();
        if (fuelMapInstance) {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    fuelMapInstance.resize();
                    fuelMapInstance.triggerRepaint();
                });
            });
        }
    }
    if (tab.id === 'fuel-stop-finder-tab' && fuelStopFinderMapInstance) {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                fuelStopFinderMapInstance.resize();
                fuelStopFinderMapInstance.triggerRepaint();
            });
        });
    }
    if (tab.id === 'route-planning-tab' && routeMapInstance) {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                routeMapInstance.resize();
                routeMapInstance.triggerRepaint();
            });
        });
    }
}

function scheduleMapResize(map) {
    if (!map) {
        return;
    }

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            try {
                map.resize();
                map.triggerRepaint();
            } catch (error) {
                void error;
            }
        });
    });
}

function destroyFuelMap() {
    if (fuelMapPopup) {
        fuelMapPopup.remove();
    }
    if (fuelMapInstance) {
        fuelMapInstance.remove();
    }
    fuelMapInstance = null;
    fuelMapReady = false;
    fuelMapPopup = null;
    fuelMapPendingData = null;
    fuelMapViewportLastRequestKey = '';
    if (fuelMap) {
        fuelMap.innerHTML = '';
    }
}

function runWhenMapStyleReady(map, callback) {
    let completed = false;
    let checksRemaining = 600;
    const run = () => {
        if (completed) {
            return;
        }
        completed = true;
        callback();
    };
    const check = () => {
        if (completed || checksRemaining <= 0 || !map?.getContainer?.()?.isConnected) {
            return;
        }
        checksRemaining -= 1;
        if (map.isStyleLoaded()) {
            run();
            return;
        }
        requestAnimationFrame(check);
    };

    map?.once('load', run);
    map?.once('style.load', check);
    queueMicrotask(check);
}

async function apiRequest(url, options = {}, retryOnContainerAuthFailure = true) {
    const headers = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };

    const isContainerRequest = containerManagementEnabled
        && String(url || '').startsWith('/api/docker/');
    const requestMethod = String(options.method || 'GET').toUpperCase();
    if (
        isContainerRequest
        && requestMethod !== 'GET'
        && String(url || '') !== '/api/docker/session'
        && containerManagementCsrfToken !== ''
    ) {
        headers['X-FuelAU-CSRF-Token'] = containerManagementCsrfToken;
    }

    const response = await fetch(url, {
        ...options,
        headers,
    });
    const responseText = await response.text();
    let payload = null;
    try {
        payload = responseText === '' ? null : JSON.parse(responseText);
    } catch (error) {
        console.error('FuelAU API returned a non-JSON response', {
            url,
            status: response.status,
            contentType: response.headers.get('content-type') || '',
            body: responseText.slice(0, 500),
            error,
        });
        const responsePreview = responseText
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 300);
        throw new Error(
            `${response.ok ? 'The server returned an invalid response' : `The server returned an invalid error response (${response.status})`}. `
            + (responsePreview !== '' ? `Response: ${responsePreview}` : 'The response was empty.'),
        );
    }
    if (!response.ok) {
        if (
            retryOnContainerAuthFailure
            && response.status === 401
            && isContainerRequest
            && String(url || '') !== '/api/docker/session'
        ) {
            if (await authenticateContainerManagement()) {
                return apiRequest(url, options, false);
            }
        }

        throw new Error(payload?.message || payload?.error || 'Request failed');
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

async function authenticateContainerManagement() {
    const token = window.prompt('Enter the FuelAU container management token for this browser session:');
    if (token === null) {
        return false;
    }
    const normalized = token.trim();
    if (normalized === '') {
        return false;
    }

    const response = await fetch('/api/docker/session', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token: normalized }),
    });
    const payload = await response.json();
    if (!response.ok) {
        throw new Error(payload.message || payload.error || 'Container management login failed');
    }

    containerManagementCsrfToken = String(payload.csrf_token || '');
    return containerManagementCsrfToken !== '';
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
            tankCapacity: routeTankCapacity.value.trim(),
            startingFuel: routeStartingFuel.value.trim(),
            fuelReserve: routeFuelReserve.value.trim(),
            fuelEconomy: routeFuelEconomy.value.trim(),
            fuelValue: routeFuelSelectedValue(),
            optimizationMode: routeOptimizationMode.value,
            useOptimizer: routeOptimizerSelected(),
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

function saveFuelStopFinderState(planned = false) {
    try {
        window.localStorage.setItem(fuelStopFinderStateKey, JSON.stringify({
            origin: fuelStopFinderOrigin.value.trim(),
            destination: fuelStopFinderDestination.value.trim(),
            fuel: fuelStopFinderFuelType?.value || '',
            economy: fuelStopFinderEconomy.value.trim(),
            planned: Boolean(planned),
        }));
    } catch (error) {
        void error;
    }
}

function loadFuelStopFinderState() {
    try {
        const raw = window.localStorage.getItem(fuelStopFinderStateKey);
        return raw ? JSON.parse(raw) : null;
    } catch (error) {
        return null;
    }
}

function clearFuelStopFinderState() {
    try {
        window.localStorage.removeItem(fuelStopFinderStateKey);
    } catch (error) {
        void error;
    }
}

function fuelStopFinderFuelChoices() {
    return routeFuelChoices();
}

function fuelStopFinderDefaultFuelValue() {
    const options = fuelStopFinderFuelChoices();
    const current = String(fuelStopFinderFuelType?.value || '').trim();
    if (current !== '' && options.some((item) => item.value === current)) {
        return current;
    }

    const diesel = options.find((item) => item.label.toLowerCase() === 'diesel');
    return diesel ? diesel.value : (options[0]?.value || '');
}

function syncFuelStopFinderSelector() {
    if (!fuelStopFinderFuelType) {
        return;
    }
    setSelectOptions(fuelStopFinderFuelType, fuelStopFinderFuelChoices(), fuelStopFinderDefaultFuelValue());
}

function fuelStopFinderFuelSelectedValue() {
    const value = String(fuelStopFinderFuelType?.value || '').trim();
    return value !== '' ? value : fuelStopFinderDefaultFuelValue();
}

function fuelStopFinderFuelSelectedLabel() {
    const option = Array.from(fuelStopFinderFuelType?.options || []).find((item) => item.value === fuelStopFinderFuelSelectedValue());
    return option ? option.textContent.trim() : '';
}

function fuelStopFinderDetourLimitKm(routeKm) {
    const distance = Number(routeKm || 0);
    if (distance <= 80) {
        return 4;
    }
    if (distance <= 250) {
        return 8;
    }
    if (distance <= 800) {
        return 12;
    }
    return 18;
}

function fuelStopFinderCandidateScore(candidate, routeKm, economyLPer100km) {
    const price = Number(candidate?.price || 0);
    const offRouteKm = routeFuelCandidateOffRouteKm(candidate);
    const detourWeight = Math.max(1.5, Math.min(4, Number(economyLPer100km || 0) / 3.5));
    return price + (offRouteKm * detourWeight);
}

function selectFuelStopFinderCandidate(candidates, routeKm, economyLPer100km, detourLimitKm) {
    const routeDistance = Number(routeKm || 0);
    const limit = Number.isFinite(Number(detourLimitKm)) ? Number(detourLimitKm) : Number.POSITIVE_INFINITY;
    const pool = (Array.isArray(candidates) ? candidates : [])
        .filter((candidate) => Number(candidate?.progressKm || 0) > 0.01)
        .filter((candidate) => Number(candidate?.progressKm || 0) < routeDistance - 0.01)
        .filter((candidate) => routeFuelCandidateOffRouteKm(candidate) <= limit)
        .map((candidate) => ({
            ...candidate,
            score: fuelStopFinderCandidateScore(candidate, routeDistance, economyLPer100km),
        }));

    pool.sort((left, right) => {
        if (left.score !== right.score) {
            return left.score - right.score;
        }
        if (left.price !== right.price) {
            return left.price - right.price;
        }
        if (left.offRouteDistanceKm !== right.offRouteDistanceKm) {
            return left.offRouteDistanceKm - right.offRouteDistanceKm;
        }
        return left.progressKm - right.progressKm;
    });

    return pool[0] || null;
}

async function collectFuelStopFinderCandidates(progress, fuelQuery, routeKm, budget = null) {
    const sampleLimit = Math.max(14, Math.min(42, Math.ceil(Number(routeKm || 0) / 45)));
    const attempts = [
        { sampleLimit, radiusKm: Number(routeKm || 0) > 1600 ? 45 : 25 },
        { sampleLimit: Math.min(42, sampleLimit + 6), radiusKm: Number(routeKm || 0) > 1600 ? 75 : 45 },
        { sampleLimit: Math.min(42, sampleLimit + 12), radiusKm: Number(routeKm || 0) > 1600 ? 100 : 60 },
    ];
    const excludedStations = [];

    for (const attempt of attempts) {
        const bundle = await collectRouteFuelCandidates(progress, fuelQuery, attempt.sampleLimit, attempt.radiusKm, budget);
        excludedStations.push(...bundle.excludedStations);
        if (bundle.candidates.length > 0) {
            return {
                candidates: bundle.candidates,
                excludedStations: dedupeRouteExcludedStations(excludedStations),
            };
        }
    }

    return {
        candidates: [],
        excludedStations: dedupeRouteExcludedStations(excludedStations),
    };
}

async function buildFuelStopFinderPlan(origin, destination, fuelQuery, economyLPer100km) {
    const budget = createRoutePlannerBudget();
    const route = await fetchRouteDetails(origin, destination, true, budget);
    const routeKm = route.distanceM / 1000;
    const progress = buildRouteProgress(route.geometry);
    const candidateBundle = await collectFuelStopFinderCandidates(progress, fuelQuery, routeKm, budget);
    const candidates = candidateBundle.candidates;
    const excludedStations = candidateBundle.excludedStations;

    if (candidates.length === 0) {
        throw new Error('No fuel stations were found along this route.');
    }

    const strictDetourLimitKm = fuelStopFinderDetourLimitKm(routeKm);
    const relaxedDetourLimitKm = strictDetourLimitKm * 2;
    const selectedStop = selectFuelStopFinderCandidate(candidates, routeKm, economyLPer100km, strictDetourLimitKm)
        || selectFuelStopFinderCandidate(candidates, routeKm, economyLPer100km, relaxedDetourLimitKm)
        || selectFuelStopFinderCandidate(candidates, routeKm, economyLPer100km, Number.POSITIVE_INFINITY);

    if (!selectedStop) {
        throw new Error('No eligible fuel stop could be selected for this route.');
    }

    const routePieces = [
        {
            type: 'route',
            route,
        },
        {
            type: 'fuel-stop',
            station: selectedStop,
            recommendedOnly: true,
            detourKm: routeFuelCandidateOffRouteKm(selectedStop),
            score: Number(selectedStop.score || 0),
            selectionScope: routeFuelCandidateOffRouteKm(selectedStop) <= strictDetourLimitKm
                ? 'strict'
                : (routeFuelCandidateOffRouteKm(selectedStop) <= relaxedDetourLimitKm ? 'relaxed' : 'expanded'),
        },
    ];

    return {
        fuelQuery,
        segments: [
            {
                routePieces,
                stops: [selectedStop],
                excludedStations,
                remainingFuelL: 0,
            },
        ],
        totalDistanceM: route.distanceM,
        totalDurationS: route.durationS,
        totalFuelUsedL: routeKm * routeFuelRateLPerKm(economyLPer100km),
        totalFillCostCents: 0,
        fuelRemainingL: 0,
        excludedStations,
        selectedStop,
    };
}

function renderFuelStopFinderSummaryCard(label, value) {
    return `
        <article class="route-summary-card">
            <strong>${escapeHtml(String(value || ''))}</strong>
            <span>${escapeHtml(String(label || ''))}</span>
        </article>
    `;
}

function renderFuelStopFinderRecommendation(stop, plan) {
    if (!fuelStopFinderRecommendation) {
        return;
    }

    if (!stop) {
        fuelStopFinderRecommendation.innerHTML = `
            <div class="fuel-stop-finder-card">
                <strong>No station selected</strong>
                <span>No eligible fuel station was found along this route.</span>
            </div>
        `;
        return;
    }

    const scope = String(plan?.segments?.[0]?.routePieces?.find((piece) => piece.type === 'fuel-stop')?.selectionScope || '').trim();
    fuelStopFinderRecommendation.innerHTML = `
        <div class="fuel-stop-finder-card">
            <strong>${escapeHtml(routeFuelStationDisplay(stop) || stop.station_name || 'Recommended stop')}</strong>
            <span>${escapeHtml(String(stop.address || ''))}</span>
            <span>Price: ${escapeHtml(routeFuelPriceText(stop.price) || '$0.00')}/L</span>
            <span>Route detour: ${escapeHtml(Number(stop.offRouteDistanceKm || 0).toFixed(1))} km</span>
            <span>Selected because it is the cheapest eligible stop${scope !== '' ? ` within the ${escapeHtml(scope)} detour window` : ''}.</span>
        </div>
    `;
}

function renderFuelStopFinderSummary(plan, stop, fuelQuery) {
    if (!fuelStopFinderSummary) {
        return;
    }

    const stopLabel = stop
        ? (routeFuelStationDisplay(stop) || stop.station_name || 'Recommended stop')
        : 'No station selected';
    const stopPrice = stop ? `${routeFuelPriceText(stop.price)}/L` : 'N/A';
    const stopDetour = stop ? `${Number(stop.offRouteDistanceKm || 0).toFixed(1)} km` : 'N/A';
    fuelStopFinderSummary.innerHTML = [
        renderFuelStopFinderSummaryCard('Distance', formatRouteDistance(plan.totalDistanceM || 0)),
        renderFuelStopFinderSummaryCard('Drive Time', formatRouteDuration(plan.totalDurationS || 0)),
        renderFuelStopFinderSummaryCard('Fuel Used', `${Number(plan.totalFuelUsedL || 0).toFixed(1)} L`),
        renderFuelStopFinderSummaryCard('Fuel Type', String(fuelQuery || 'Diesel')),
        renderFuelStopFinderSummaryCard('Best Stop', stopLabel),
        renderFuelStopFinderSummaryCard('Stop Price', stopPrice),
        renderFuelStopFinderSummaryCard('Route Detour', stopDetour),
    ].join('');
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

    if (!Array.isArray(results) || results.length === 0) {
        panel.innerHTML = '<div class="route-autocomplete-empty">No matches found.</div>';
        panel.hidden = false;
        return;
    }

    panel.innerHTML = results.map((result) => {
        const fallback = Number(result?.tier || 3) >= 3 || Boolean(result?.is_fallback);
        const label = routeGeocodeLabel(result) || result.label || result.display_name || '';
        const secondary = result.display_name || [result.class, result.type].filter(Boolean).join(' · ') || 'Geocoding match';
        const fallbackLabel = fallback ? '<span class="route-autocomplete-fallback">Fallback</span>' : '';
        return `
        <button type="button" class="route-autocomplete-option${fallback ? ' is-fallback' : ''}" data-route-match="${escapeHtml(JSON.stringify(result))}">
            <strong>${escapeHtml(label)}</strong>
            <span>${escapeHtml(secondary)}${fallbackLabel}</span>
        </button>
    `;}).join('');

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
        abortController: null,
    });

    input.addEventListener('input', () => {
        const state = routeAutocompleteState.get(input);
        if (!state) {
            return;
        }

        if (state.timer) {
            window.clearTimeout(state.timer);
        }
        if (state.abortController) {
            state.abortController.abort();
            state.abortController = null;
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
            const abortController = new AbortController();
            state.abortController = abortController;

            try {
                const payload = await apiRequest(
                    `/api/geo/search?q=${encodeURIComponent(query)}&limit=10`,
                    { signal: abortController.signal }
                );
                if (state.sequence !== currentSequence || input.value.trim() !== query) {
                    return;
                }

                const results = Array.isArray(payload.results) ? payload.results : [];
                renderRouteAutocompleteOptions(input, results);
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
                if (state.sequence === currentSequence) {
                    panel.innerHTML = `<div class="route-autocomplete-empty">${escapeHtml(error.message)}</div>`;
                    panel.hidden = false;
                }
            } finally {
                if (state.abortController === abortController) {
                    state.abortController = null;
                }
            }
        }, 500);
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
    if (routeReturnOneWay.checked) {
        return 'one-way';
    }

    return routeReturnReverses.checked ? 'reverses' : 'direct';
}

function syncRouteReturnModeControls() {
    const oneWayEnabled = routeReturnOneWay.checked;
    routeReturnDirect.disabled = oneWayEnabled;
    routeReturnReverses.disabled = oneWayEnabled;
    routeReturnDirect.closest('.switch-control')?.classList.toggle('is-disabled', oneWayEnabled);
    routeReturnReverses.closest('.switch-control')?.classList.toggle('is-disabled', oneWayEnabled);
}

function routeOptimizerSelected() {
    return routeOptimizerV2Enabled
        && (routeUseOptimizer ? routeUseOptimizer.checked : routeOptimizerV2Default);
}

function syncRouteOptimizerControls() {
    const selected = routeOptimizerSelected();
    routeOptimizerFields.forEach((field) => {
        field.hidden = !selected;
    });
}

function routeTankCapacityValue() {
    const rawValue = String(routeTankCapacity.value || '').trim();
    if (rawValue === '') {
        return routePlannerDefaultTankCapacityL;
    }

    const value = Number(rawValue);
    return Number.isFinite(value) ? value : routePlannerDefaultTankCapacityL;
}

function routeStartingFuelValue() {
    const rawValue = String(routeStartingFuel.value || '').trim();
    if (rawValue === '') {
        return routePlannerDefaultStartingFuelL;
    }

    const value = Number(rawValue);
    return Number.isFinite(value) ? value : routePlannerDefaultStartingFuelL;
}

function routeFuelReserveValue() {
    const rawValue = String(routeFuelReserve.value || '').trim();
    if (rawValue === '') {
        return routePlannerDefaultReserveL;
    }

    const value = Number(rawValue);
    return Number.isFinite(value) ? value : routePlannerDefaultReserveL;
}

function syncRouteVehicleInputBounds() {
    const tankCapacity = Math.max(0, routeTankCapacityValue());
    routeStartingFuel.max = String(tankCapacity);
    routeFuelReserve.max = String(Math.max(0, tankCapacity - 0.5));
}

function routeFuelDefaultEconomyValue() {
    const rawValue = String(routeFuelEconomy.value || '').trim();
    if (rawValue === '') {
        return routePlannerDefaultFuelEconomyLPer100km;
    }

    const value = Number(rawValue);
    return Number.isFinite(value) ? value : routePlannerDefaultFuelEconomyLPer100km;
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

function renderRouteError(message) {
    const safeMessage = String(message || 'An unexpected error occurred.');
    return `<div class="route-error" role="alert">
        <strong>Route planning failed</strong>
        <span>${escapeHtml(safeMessage)}</span>
    </div>`;
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
    return ['qld', 'sa', 'nsw', 'wa', 'tas', 'vic', 'nt'].includes(String(source || '').trim().toLowerCase());
}

function routeFuelPriceIsFresh(updatedAt, maximumAgeDays = 14) {
    const raw = String(updatedAt || '').trim();
    let timestamp = Date.parse(raw.replace(' ', 'T'));
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
        const [year, month, day] = raw.split('-').map((part) => Number(part));
        timestamp = new Date(year, month - 1, day).getTime();
    }
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

function routeFuelCandidateExclusionReasons(candidate) {
    const reasons = [];
    if (!routeFuelSourceIsOfficial(candidate?.source)) {
        reasons.push('no government pricing');
    }
    const price = Number(candidate?.price);
    if (!Number.isFinite(price) || price <= 0) {
        reasons.push('pricing does not exist');
    } else if (!routeFuelPriceIsReasonable(price)) {
        reasons.push('pricing is outside the supported range');
    }
    if (!routeFuelPriceIsFresh(candidate?.updated_at)) {
        reasons.push('pricing is older than 14 days');
    }
    return reasons;
}

function routeFuelStationDisplay(candidate) {
    const station = String(candidate?.station_name || '').trim();
    const address = String(candidate?.address || '').trim();
    return [station, address].filter((part) => part !== '').join(' - ');
}

function routeFuelMinimumPurchaseL(tankCapacityL) {
    return Math.max(15, Number(tankCapacityL || 0) * 0.5);
}

function routeFuelRelaxedPurchaseL(tankCapacityL) {
    return Math.max(10, Number(tankCapacityL || 0) * 0.15);
}

function routeFuelContingencyPurchaseL(tankCapacityL) {
    return Math.max(5, Number(tankCapacityL || 0) * 0.05);
}

function routeFuelReserveL(tankCapacityL) {
    return Math.max(0, Number(tankCapacityL || 0) * 0.1);
}

function routeFuelExternalReserveL(routeKm, currentFuelL, tankCapacityL, economyLPer100km) {
    const reserveL = routeFuelReserveL(tankCapacityL);
    const fuelNeededL = Math.max(0, Number(routeKm || 0)) * routeFuelRateLPerKm(economyLPer100km);
    const requiredStartFuelL = fuelNeededL + reserveL;
    return Math.max(0, requiredStartFuelL - Number(currentFuelL || 0));
}

function routeFuelReserveNote(destination, routeKm, currentFuelL, tankCapacityL, economyLPer100km, fuelLabel = routeFuelSelectedLabel()) {
    const destinationLabel = destination?.display_name || destination?.query || routeFuelStationDisplay(destination) || 'destination';
    const normalizedFuelLabel = String(fuelLabel || 'selected fuel').trim() || 'selected fuel';
    const reserveL = routeFuelReserveL(tankCapacityL);
    const requiredExternalReserveL = routeFuelExternalReserveL(routeKm, currentFuelL, tankCapacityL, economyLPer100km);
    return {
        destinationLabel,
        fuelLabel: normalizedFuelLabel,
        routeKm: Number(routeKm || 0),
        currentFuelL: Number(currentFuelL || 0),
        reserveL,
        requiredExternalReserveL,
        message: requiredExternalReserveL > 0
            ? `No usable corridor coverage was found for ${normalizedFuelLabel} on the way to ${destinationLabel}. Choose a different route or start with an additional ${requiredExternalReserveL.toFixed(1)} L external reserve to reach ${destinationLabel} safely.`
            : `No usable corridor coverage was found for ${normalizedFuelLabel} on the way to ${destinationLabel}.`,
    };
}

function mergeRouteFuelReserveNotes(existingNote, nextNote) {
    if (!existingNote) {
        return nextNote;
    }
    if (!nextNote) {
        return existingNote;
    }

    const requiredExternalReserveL = Number(existingNote.requiredExternalReserveL || 0)
        + Number(nextNote.requiredExternalReserveL || 0);
    return {
        ...nextNote,
        requiredExternalReserveL,
        message: requiredExternalReserveL > 0
            ? `Fuel coverage gaps for ${nextNote.fuelLabel} require a total of ${requiredExternalReserveL.toFixed(1)} L external reserve. The final bridged gap is on the way to ${nextNote.destinationLabel}.`
            : nextNote.message,
    };
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

function routeFuelEstimateApproachDistanceKm(candidate, cursor) {
    const routeProgressKm = routeFuelCandidateProgressKm(candidate, cursor);
    const routeDistanceFromCursorKm = Number(candidate?.routeDistanceFromCursorKm || 0);
    if (routeDistanceFromCursorKm > 0) {
        return Math.max(0, routeProgressKm + routeDistanceFromCursorKm);
    }

    return Math.max(0, routeProgressKm + (routeFuelCandidateOffRouteKm(candidate) * 1.15));
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
    const visitIndex = Number(feature?.properties?.visit_index || 1);
    const visitCount = Number(feature?.properties?.visit_count || 1);
    const segmentIndex = Number(feature?.properties?.segment_index || 0);
    const wrapper = document.createElement('div');
    wrapper.className = 'route-fuel-marker';
    wrapper.style.setProperty('--route-fuel-color', color);
    wrapper.innerHTML = `
        <span class="route-fuel-marker-icon" aria-hidden="true"></span>
        <span class="route-fuel-marker-copy">
            <strong>${escapeHtml(station !== '' ? station : 'Fuel stop')}</strong>
            ${visitCount > 1 ? `<span class="route-fuel-marker-visit">Visit ${visitIndex} of ${visitCount}${segmentIndex > 0 ? ` · Leg ${segmentIndex}` : ''}</span>` : ''}
            ${address !== '' ? `<span>${escapeHtml(address)}</span>` : ''}
            <span>${escapeHtml(price !== '' ? `${price}/L` : 'Price unavailable')}</span>
        </span>
    `;
    return wrapper;
}

function annotateRepeatedRouteFuelMarkers(features) {
    const groups = new Map();
    features
        .filter((feature) => feature?.properties?.kind === 'fuel-stop')
        .forEach((feature) => {
            const coordinates = Array.isArray(feature?.geometry?.coordinates) ? feature.geometry.coordinates : [];
            const coordinateKey = coordinates
                .slice(0, 2)
                .map((value) => Number(value).toFixed(5))
                .join(':');
            const key = String(feature?.properties?.station_key || coordinateKey);
            if (!groups.has(key)) {
                groups.set(key, []);
            }
            groups.get(key).push(feature);
        });

    groups.forEach((group) => {
        group.forEach((feature, index) => {
            feature.properties.visit_index = index + 1;
            feature.properties.visit_count = group.length;
        });
    });
}

function routeFuelMarkerOffset(feature) {
    const visitIndex = Math.max(1, Number(feature?.properties?.visit_index || 1));
    const visitCount = Math.max(1, Number(feature?.properties?.visit_count || 1));
    return visitCount > 1 ? [0, -((visitIndex - 1) * 72)] : [0, 0];
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

    const hasProgress = points.every((point) => Number.isFinite(Number(point?.progressKm)));
    if (hasProgress) {
        const result = [];
        const lastIndex = points.length - 1;
        const totalProgress = Number(points[lastIndex]?.progressKm || 0);
        if (totalProgress > 0) {
            let cursor = 0;
            for (let index = 0; index < limit; index += 1) {
                const targetProgress = totalProgress * index / Math.max(limit - 1, 1);
                while (cursor < lastIndex && Number(points[cursor].progressKm || 0) < targetProgress) {
                    cursor += 1;
                }
                const previous = cursor > 0 ? points[cursor - 1] : points[cursor];
                const current = points[cursor] || points[lastIndex];
                const chosen = Math.abs(Number(previous.progressKm || 0) - targetProgress) <= Math.abs(Number(current.progressKm || 0) - targetProgress)
                    ? previous
                    : current;
                if (result[result.length - 1] !== chosen) {
                    result.push(chosen);
                }
            }
            if (result.length > 0) {
                return result;
            }
        }
    }

    const result = [];
    const step = (points.length - 1) / Math.max(limit - 1, 1);
    for (let index = 0; index < limit; index += 1) {
        result.push(points[Math.round(index * step)]);
    }
    return result;
}

function createRoutePlannerBudget() {
    return {
        routeRequestsRemaining: routePlannerRouteBudgetLimit,
        fuelRequestsRemaining: routePlannerFuelBudgetLimit,
    };
}

function routePlannerConsumeBudget(budget, key, amount = 1) {
    if (!budget) {
        return;
    }

    const normalizedAmount = Math.max(0, Number(amount || 0));
    if (normalizedAmount <= 0) {
        return;
    }

    const remainingKey = key === 'fuel' ? 'fuelRequestsRemaining' : 'routeRequestsRemaining';
    budget[remainingKey] = Number(budget[remainingKey] || 0) - normalizedAmount;
    if (budget[remainingKey] < 0) {
        throw new Error(key === 'fuel'
            ? 'Route planning exceeded the fuel lookup budget. Try fewer stops or a simpler route.'
            : 'Route planning exceeded the route lookup budget. Try fewer stops or a simpler route.');
    }
}

async function fetchRouteDetails(from, to, steps = true, budget = null) {
    routePlannerConsumeBudget(budget, 'route');
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

async function collectRouteFuelCandidates(progress, fuelQuery, sampleLimit = 7, radiusKm = 25, budget = null) {
    const samplePoints = sampleRoutePoints(progress, sampleLimit);
    if (budget && Number(budget.fuelRequestsRemaining || 0) <= 0) {
        throw new Error('Route planning exceeded the fuel lookup budget. Try fewer stops or a simpler route.');
    }
    routePlannerConsumeBudget(budget, 'fuel');
    const payload = await apiRequest('/api/fuel/route-candidates', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            points: samplePoints.map((point) => ({
                lat: Number(point.lat),
                lon: Number(point.lon),
            })),
            fuel: fuelQuery,
            radius_km: radiusKm,
            limit: 2000,
        }),
    });

    const deduped = dedupeRouteStations(Array.isArray(payload.rows) ? payload.rows : []);
    const excludedStations = [];
    const excludedKeys = new Set();
    const candidates = deduped
        .filter((candidate) => {
            if (routeFuelCandidateIsEligible(candidate)) {
                return true;
            }
            const reasons = routeFuelCandidateExclusionReasons(candidate);
            if (reasons.length === 0) {
                return false;
            }
            const key = stationKey(candidate);
            if (!excludedKeys.has(key)) {
                excludedKeys.add(key);
                excludedStations.push({
                    ...candidate,
                    exclusionReasons: reasons,
                });
            }
            return false;
        })
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

    return {
        candidates,
        excludedStations,
    };
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

function dedupeRouteExcludedStations(rows) {
    const unique = new Map();
    rows.forEach((row) => {
        const reasons = Array.isArray(row?.exclusionReasons) ? row.exclusionReasons.slice().sort().join('|') : '';
        const key = `${String(row?.source || '')}:${String(row?.state || '')}:${String(row?.station_id || '')}:${String(row?.station_name || '')}:${String(row?.address || '')}:${reasons}`;
        if (!unique.has(key)) {
            unique.set(key, row);
        }
    });
    return Array.from(unique.values());
}

function formatRouteExcludedStations(excludedStations) {
    const rows = Array.isArray(excludedStations) ? excludedStations : [];
    if (rows.length === 0) {
        return '';
    }

    const preview = rows.slice(0, 5).map((station) => {
        const label = routeFuelStationDisplay(station) || 'Unknown station';
        const reasons = Array.isArray(station.exclusionReasons) ? station.exclusionReasons.join(', ') : 'excluded';
        return `${label} (${reasons})`;
    });
    const suffix = rows.length > preview.length ? ` and ${rows.length - preview.length} more` : '';
    return `Excluded route stations: ${preview.join('; ')}${suffix}.`;
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

function selectRouteFuelDestinationFallbackCandidate(candidates, cursor, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set()) {
    const safeRangeKm = routeFuelSafeRangeKm(currentFuelL, reserveL, economyLPer100km);
    const routeWindowKm = Math.max(25, Math.min(140, Math.max(routeKm * 0.12, safeRangeKm * 0.25)));
    const minProgressKm = Math.max(0, routeKm - routeWindowKm);
    const detourLimitKm = routeFuelDetourLimitKm(routeKm, Math.max(safeRangeKm, routeKm));
    const rateLPerKm = routeFuelRateLPerKm(economyLPer100km);
    const minimumPurchaseL = routeFuelMinimumPurchaseL(tankCapacityL);
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
            const progressCredit = routeProgressKm * 1.5;

            return {
                ...candidate,
                routeProgressKm,
                offRouteKm,
                arrivalFuelL,
                refillL,
                meaningfulRefill: refillL >= minimumPurchaseL,
                forwardFeasible,
                effectiveCost: purchaseCost + detourCost + stopPenalty + reservePenalty - progressCredit,
            };
        })
        .filter((candidate) => candidate.routeProgressKm > 0.01)
        .filter((candidate) => candidate.routeProgressKm >= minProgressKm)
        .filter((candidate) => candidate.arrivalFuelL >= 0)
        .filter((candidate) => candidate.offRouteKm <= detourLimitKm);

    if (reachable.length === 0) {
        return null;
    }

    reachable.sort((left, right) => {
        if (left.routeProgressKm !== right.routeProgressKm) {
            return right.routeProgressKm - left.routeProgressKm;
        }
        if (left.forwardFeasible !== right.forwardFeasible) {
            return Number(right.forwardFeasible) - Number(left.forwardFeasible);
        }
        if (left.meaningfulRefill !== right.meaningfulRefill) {
            return Number(right.meaningfulRefill) - Number(left.meaningfulRefill);
        }
        if (left.effectiveCost !== right.effectiveCost) {
            return left.effectiveCost - right.effectiveCost;
        }
        if (left.offRouteKm !== right.offRouteKm) {
            return left.offRouteKm - right.offRouteKm;
        }
        return left.price - right.price;
    });

    return reachable[0];
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

function buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set(), allowSafetyStops = false, allowRelaxedStops = false) {
    const rateLPerKm = routeFuelRateLPerKm(economyLPer100km);
    if (rateLPerKm <= 0) {
        return null;
    }

    const fullTankSafeRangeKm = routeFuelSafeRangeKm(tankCapacityL, reserveL, economyLPer100km);
    const currentSafeRangeKm = routeFuelSafeRangeKm(currentFuelL, reserveL, economyLPer100km);
    const detourLimitKm = routeFuelDetourLimitKm(routeKm, Math.max(fullTankSafeRangeKm, currentSafeRangeKm));
    const strictMinimumRefillL = routeFuelMinimumPurchaseL(tankCapacityL);
    const safetyMinimumRefillL = Math.max(15, tankCapacityL * 0.25);
    const relaxedMinimumRefillL = routeFuelRelaxedPurchaseL(tankCapacityL);
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
            let relaxedFallback = false;
            if (toNode.kind === 'station') {
                const station = toNode.station;
                litresPurchased = Math.max(0, tankCapacityL - arrivalFuelL);
                const minimumRefillL = allowRelaxedStops
                    ? relaxedMinimumRefillL
                    : (allowSafetyStops ? safetyMinimumRefillL : strictMinimumRefillL);
                if (!(fromNode.kind === 'start' && launchFuelExemption) && litresPurchased < minimumRefillL) {
                    continue;
                }
                safetyFallback = litresPurchased < strictMinimumRefillL;
                relaxedFallback = allowRelaxedStops && litresPurchased < safetyMinimumRefillL;
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
                    relaxedFallback,
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
                relaxedFallback: entry.relaxedFallback,
            });
        }
        cursorIndex = entry.previousIndex;
    }
    stops.reverse();

    return {
        cost: best[destinationIndex].cost,
        stops,
        safetyFallback: allowSafetyStops,
        relaxedFallback: allowRelaxedStops,
    };
}

function selectRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys = new Set(), visitedNames = new Set()) {
    const strictPlan = buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, false);
    if (strictPlan) {
        return strictPlan;
    }
    const safetyPlan = buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, true, false);
    if (safetyPlan) {
        return safetyPlan;
    }
    return buildRouteFuelGraphPlan(candidates, currentFuelL, tankCapacityL, economyLPer100km, reserveL, routeKm, visitedKeys, visitedNames, true, true);
}

async function buildRouteFuelPlanSegment(cursor, destination, currentFuelL, tankCapacityL, economyLPer100km, fuelQuery, budget = null) {
    const chosenStops = [];
    const routePieces = [];
    const excludedStations = [];
    const visitedStationKeys = new Set();
    const visitedStationNames = new Set();
    let currentPoint = cursor;
    let fuelInTank = currentFuelL;
    const reserveL = routeFuelReserveL(tankCapacityL);
    let reserveNote = null;

    async function confirmRouteApproachCandidate(nextCandidate, minimumRefillL, isFirstStop) {
        const stopPoint = { lon: nextCandidate.longitude, lat: nextCandidate.latitude };
        const nextApproach = await fetchRouteDetails(currentPoint, stopPoint, true, budget);
        const nextApproachFuel = (nextApproach.distanceM / 1000) * (economyLPer100km / 100);
        const nextArrivalFuel = Math.max(0, fuelInTank - nextApproachFuel);
        const nextRefillL = Math.max(0, tankCapacityL - nextArrivalFuel);
        if ((fuelInTank - nextApproachFuel) < reserveL) {
            return null;
        }
        if (!isFirstStop && nextRefillL < minimumRefillL) {
            return null;
        }

        return {
            approach: nextApproach,
            refillL: nextRefillL,
        };
    }

    async function confirmRouteDestinationFallbackCandidate(nextCandidate) {
        const stopPoint = { lon: nextCandidate.longitude, lat: nextCandidate.latitude };
        const nextApproach = await fetchRouteDetails(currentPoint, stopPoint, true, budget);
        const nextApproachFuel = (nextApproach.distanceM / 1000) * (economyLPer100km / 100);
        if (nextApproachFuel > fuelInTank) {
            return null;
        }
        const nextArrivalFuel = Math.max(0, fuelInTank - nextApproachFuel);
        const nextRefillL = Math.max(0, tankCapacityL - nextArrivalFuel);

        return {
            approach: nextApproach,
            refillL: nextRefillL,
            fuelAfterArrival: nextArrivalFuel,
        };
    }

    async function confirmRouteExternalReserveCandidate(nextCandidate) {
        const stopPoint = { lon: nextCandidate.longitude, lat: nextCandidate.latitude };
        const nextApproach = await fetchRouteDetails(currentPoint, stopPoint, true, budget);
        const nextApproachFuel = (nextApproach.distanceM / 1000) * (economyLPer100km / 100);
        const requiredExternalReserveL = Math.max(0, nextApproachFuel + reserveL - fuelInTank);
        if (requiredExternalReserveL <= 0.01) {
            return null;
        }

        return {
            approach: nextApproach,
            requiredExternalReserveL,
        };
    }

    while (true) {
        const route = await fetchRouteDetails(currentPoint, destination, false, budget);
        const routeKm = route.distanceM / 1000;
        const fuelNeeded = routeKm * (economyLPer100km / 100);
        const progress = buildRouteProgress(route.geometry);
        const sampleLimit = Math.max(14, Math.min(42, Math.ceil(routeKm / 45)));
        const searchRadiusKm = routeKm > 1600 ? 75 : (routeKm > 900 ? 50 : 30);
        let candidateBundle = await collectRouteFuelCandidates(progress, fuelQuery, sampleLimit, searchRadiusKm, budget);
        let candidates = candidateBundle.candidates;
        excludedStations.push(...candidateBundle.excludedStations);
        if (candidates.length === 0) {
            candidateBundle = await collectRouteFuelCandidates(progress, fuelQuery, Math.min(42, sampleLimit + 6), routeKm > 1600 ? 90 : 60, budget);
            candidates = candidateBundle.candidates;
            excludedStations.push(...candidateBundle.excludedStations);
        }

        const currentCursor = routePoint(currentPoint.lon, currentPoint.lat, 0);
        if (fuelInTank >= fuelNeeded && (fuelInTank - fuelNeeded) >= reserveL) {
            routePieces.push({
                type: 'route',
                route: await fetchRouteDetails(currentPoint, destination, true, budget),
            });
            fuelInTank = Math.max(0, fuelInTank - fuelNeeded);
            break;
        }

        let chosen = null;
        let approach = null;
        let safetyFallback = false;
        let relaxedFallback = false;
        let contingencyFallback = false;
        const shortSafetyCandidates = [];
        const contingencyCandidates = [];
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
        const graphMinimumRefillL = graphPlan?.relaxedFallback
            ? routeFuelRelaxedPurchaseL(tankCapacityL)
            : (graphPlan?.safetyFallback ? Math.max(15, tankCapacityL * 0.25) : routeFuelMinimumPurchaseL(tankCapacityL));
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

            const estimatedApproachKm = routeFuelEstimateApproachDistanceKm(nextCandidate, currentCursor);
            const estimatedApproachFuel = estimatedApproachKm * (economyLPer100km / 100);
            const estimatedArrivalFuel = Math.max(0, fuelInTank - estimatedApproachFuel);
            const estimatedRefillL = Math.max(0, tankCapacityL - estimatedArrivalFuel);
            if (!isFirstStop && estimatedRefillL < graphMinimumRefillL) {
                if ((nextCandidate.relaxedFallback || graphPlan?.relaxedFallback) && estimatedRefillL >= routeFuelRelaxedPurchaseL(tankCapacityL) && (fuelInTank - estimatedApproachFuel) >= reserveL) {
                    const validation = await confirmRouteApproachCandidate(nextCandidate, routeFuelRelaxedPurchaseL(tankCapacityL), isFirstStop);
                    if (validation) {
                        chosen = nextCandidate;
                        approach = validation.approach;
                        relaxedFallback = true;
                        break;
                    }
                }
                if ((nextCandidate.safetyFallback || graphPlan?.safetyFallback) && estimatedRefillL >= Math.max(15, tankCapacityL * 0.25) && (fuelInTank - estimatedApproachFuel) >= reserveL) {
                    const validation = await confirmRouteApproachCandidate(nextCandidate, Math.max(15, tankCapacityL * 0.25), isFirstStop);
                    if (validation) {
                        chosen = nextCandidate;
                        approach = validation.approach;
                        safetyFallback = true;
                        break;
                    }
                }
                if (estimatedRefillL >= routeFuelRelaxedPurchaseL(tankCapacityL) && (fuelInTank - estimatedApproachFuel) >= reserveL) {
                    shortSafetyCandidates.push({
                        candidate: nextCandidate,
                        estimatedRefillL,
                        relaxedFallback: estimatedRefillL < Math.max(15, tankCapacityL * 0.25),
                    });
                }
                if (estimatedRefillL >= routeFuelContingencyPurchaseL(tankCapacityL) && (fuelInTank - estimatedApproachFuel) >= reserveL) {
                    contingencyCandidates.push({
                        candidate: nextCandidate,
                        estimatedRefillL,
                    });
                }
                visitedStationKeys.add(stationKey(nextCandidate));
                visitedStationNames.add(stationNameKey(nextCandidate));
                continue;
            }
            if ((fuelInTank - estimatedApproachFuel) >= reserveL) {
                const validation = await confirmRouteApproachCandidate(nextCandidate, graphMinimumRefillL, isFirstStop);
                if (validation) {
                    chosen = nextCandidate;
                    approach = validation.approach;
                    break;
                }
            }

            visitedStationKeys.add(stationKey(nextCandidate));
            visitedStationNames.add(stationNameKey(nextCandidate));
        }

        if (!chosen && shortSafetyCandidates.length > 0) {
            shortSafetyCandidates.sort((left, right) => {
                if (left.candidate.forwardFeasible !== right.candidate.forwardFeasible) {
                    return Number(right.candidate.forwardFeasible) - Number(left.candidate.forwardFeasible);
                }
                if (left.estimatedRefillL !== right.estimatedRefillL) {
                    return right.estimatedRefillL - left.estimatedRefillL;
                }
                if (left.candidate.effectiveCost !== right.candidate.effectiveCost) {
                    return left.candidate.effectiveCost - right.candidate.effectiveCost;
                }
                return Number(left.candidate.price || 0) - Number(right.candidate.price || 0);
            });
            for (const option of shortSafetyCandidates) {
                const validation = await confirmRouteApproachCandidate(
                    option.candidate,
                    routeFuelRelaxedPurchaseL(tankCapacityL),
                    isFirstStop
                );
                if (validation) {
                    chosen = option.candidate;
                    approach = validation.approach;
                    relaxedFallback = option.relaxedFallback || false;
                    safetyFallback = !relaxedFallback;
                    break;
                }
            }
        }

        if (!chosen && contingencyCandidates.length > 0) {
            contingencyCandidates.sort((left, right) => {
                if (left.candidate.forwardFeasible !== right.candidate.forwardFeasible) {
                    return Number(right.candidate.forwardFeasible) - Number(left.candidate.forwardFeasible);
                }
                if (left.estimatedRefillL !== right.estimatedRefillL) {
                    return right.estimatedRefillL - left.estimatedRefillL;
                }
                if (left.candidate.effectiveCost !== right.candidate.effectiveCost) {
                    return left.candidate.effectiveCost - right.candidate.effectiveCost;
                }
                return Number(left.candidate.price || 0) - Number(right.candidate.price || 0);
            });
            for (const option of contingencyCandidates) {
                const validation = await confirmRouteApproachCandidate(
                    option.candidate,
                    routeFuelContingencyPurchaseL(tankCapacityL),
                    isFirstStop
                );
                if (validation) {
                    chosen = option.candidate;
                    approach = validation.approach;
                    contingencyFallback = true;
                    break;
                }
            }
        }

        if (!chosen) {
            const externalReserveCandidates = candidates
                .filter((candidate) => Number(candidate.progressKm || 0) > 0.01)
                .filter((candidate) => Number.isFinite(Number(candidate.latitude)) && Number.isFinite(Number(candidate.longitude)))
                .sort((left, right) => {
                    const leftApproachKm = routeFuelEstimateApproachDistanceKm(left, currentCursor);
                    const rightApproachKm = routeFuelEstimateApproachDistanceKm(right, currentCursor);
                    if (leftApproachKm !== rightApproachKm) {
                        return leftApproachKm - rightApproachKm;
                    }
                    return routeFuelCandidateOffRouteKm(left) - routeFuelCandidateOffRouteKm(right);
                });

            for (const externalReserveCandidate of externalReserveCandidates.slice(0, 12)) {
                const validation = await confirmRouteExternalReserveCandidate(externalReserveCandidate);
                if (!validation) {
                    continue;
                }

                const fuelBeforeReserveL = fuelInTank;
                fuelInTank += validation.requiredExternalReserveL;
                reserveNote = mergeRouteFuelReserveNotes(
                    reserveNote,
                    routeFuelReserveNote(
                        externalReserveCandidate,
                        validation.approach.distanceM / 1000,
                        fuelBeforeReserveL,
                        tankCapacityL,
                        economyLPer100km,
                        fuelQuery
                    )
                );
                chosen = externalReserveCandidate;
                approach = validation.approach;
                contingencyFallback = true;
                break;
            }
        }

        if (!chosen) {
            routePlannerConsumeBudget(budget, 'fuel');
            const destinationRows = await fetchRouteStations(destination, fuelQuery);
            const destinationStations = destinationRows
                .map((row) => ({
                    ...row,
                    progressKm: routeKm,
                    routeDistanceFromCursorKm: Number(row.distance_km || 0),
                    offRouteDistanceKm: Number(row.distance_km || 0),
                }))
                .sort((left, right) => {
                    if (left.routeDistanceFromCursorKm !== right.routeDistanceFromCursorKm) {
                        return left.routeDistanceFromCursorKm - right.routeDistanceFromCursorKm;
                    }
                    if (left.price !== right.price) {
                        return left.price - right.price;
                    }
                    return String(left.station_name || '').localeCompare(String(right.station_name || ''));
                });

            for (const destinationStation of destinationStations) {
                const validation = await confirmRouteDestinationFallbackCandidate(destinationStation);
                if (validation) {
                    const routeFuelAfterArrival = validation.fuelAfterArrival;
                    const litresToBuy = Math.max(0, tankCapacityL - routeFuelAfterArrival);
                    const purchaseCents = litresToBuy * Number(destinationStation.price || 0);
                    const destinationFallbackFlag = true;

                    routePieces.push({
                        type: 'route',
                        route,
                    });

                    chosenStops.push({
                        ...destinationStation,
                        litresPurchased: litresToBuy,
                        purchaseCents,
                        fuelAfterArrival: routeFuelAfterArrival,
                        safetyFallback: false,
                        relaxedFallback: false,
                        contingencyFallback: false,
                        destinationFallback: destinationFallbackFlag,
                    });

                    routePieces.push({
                        type: 'fuel-stop',
                        station: destinationStation,
                        litresPurchased: litresToBuy,
                        purchaseCents,
                        safetyFallback: false,
                        relaxedFallback: false,
                        contingencyFallback: false,
                        destinationFallback: destinationFallbackFlag,
                    });

                    fuelInTank = tankCapacityL;
                    currentPoint = destination;
                    chosen = destinationStation;
                    break;
                }
            }

            if (chosen) {
                break;
            }

            const destinationFallbackCandidate = selectRouteFuelDestinationFallbackCandidate(
                candidates,
                currentCursor,
                fuelInTank,
                tankCapacityL,
                economyLPer100km,
                reserveL,
                routeKm,
                visitedStationKeys,
                visitedStationNames
            );
            if (destinationFallbackCandidate) {
                const validation = await confirmRouteDestinationFallbackCandidate(destinationFallbackCandidate);
                if (validation) {
                    const routeFuelAfterArrival = validation.fuelAfterArrival;
                    const litresToBuy = Math.max(0, tankCapacityL - routeFuelAfterArrival);
                    const purchaseCents = litresToBuy * Number(destinationFallbackCandidate.price || 0);
                    const destinationFallbackFlag = true;

                    routePieces.push({
                        type: 'route',
                        route,
                    });

                    chosenStops.push({
                        ...destinationFallbackCandidate,
                        litresPurchased: litresToBuy,
                        purchaseCents,
                        fuelAfterArrival: routeFuelAfterArrival,
                        safetyFallback: false,
                        relaxedFallback: false,
                        contingencyFallback: false,
                        destinationFallback: destinationFallbackFlag,
                    });

                    routePieces.push({
                        type: 'fuel-stop',
                        station: destinationFallbackCandidate,
                        litresPurchased: litresToBuy,
                        purchaseCents,
                        safetyFallback: false,
                        relaxedFallback: false,
                        contingencyFallback: false,
                        destinationFallback: destinationFallbackFlag,
                    });

                    fuelInTank = tankCapacityL;
                    currentPoint = destination;
                    break;
                }
            }

            reserveNote = mergeRouteFuelReserveNotes(
                reserveNote,
                routeFuelReserveNote(destination, routeKm, fuelInTank, tankCapacityL, economyLPer100km, fuelQuery)
            );
            routePieces.push({
                type: 'route',
                route,
            });
            return {
                cursor,
                destination,
                routePieces,
                stops: chosenStops,
                excludedStations,
                remainingFuelL: Number(reserveNote?.requiredExternalReserveL || 0) > 0
                    ? reserveL
                    : Math.max(0, fuelInTank - fuelNeeded),
                requiresExternalReserve: true,
                reserveNote,
            };
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
            relaxedFallback,
            contingencyFallback,
        });

        routePieces.push({
            type: 'fuel-stop',
            station: chosen,
            litresPurchased: litresToBuy,
            purchaseCents,
            safetyFallback,
            relaxedFallback,
            contingencyFallback,
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
        excludedStations,
        remainingFuelL: Math.max(0, fuelInTank),
        reserveNote,
    };
}

async function buildRoutePlan(resolveStops, fuelQuery, tankCapacityL, economyLPer100km) {
    const budget = createRoutePlannerBudget();
    const segments = [];
    const excludedStations = [];
    let currentFuel = tankCapacityL * 0.2;
    let finalFuelRemaining = currentFuel;
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
                fuelQuery,
                budget
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
                    fuelQuery,
                    budget
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
        excludedStations.push(...(Array.isArray(segment.excludedStations) ? segment.excludedStations : []));
        currentPoint = destination;
        finalFuelRemaining = Math.max(0, segment.remainingFuelL);
        currentFuel = finalFuelRemaining;
    }

    const reserveNote = segments.reduce(
        (note, segment) => mergeRouteFuelReserveNotes(note, segment?.reserveNote || null),
        null
    );
    const totalPurchasedL = segments.reduce(
        (total, segment) => total + segment.stops.reduce(
            (segmentTotal, stop) => segmentTotal + Number(stop.litresPurchased || 0),
            0
        ),
        0
    );
    const stationFillCostCents = totalFillCostCents;
    const averageFillPriceCentsPerL = totalPurchasedL > 0
        ? stationFillCostCents / totalPurchasedL
        : routePlannerFallbackFuelPriceCentsPerL;
    const externalReservePricedL = Number(reserveNote?.requiredExternalReserveL || 0) * 1.2;
    const externalReserveCostCents = externalReservePricedL * averageFillPriceCentsPerL;
    totalFillCostCents = stationFillCostCents + externalReserveCostCents;

    return {
        fuelQuery,
        tankCapacityL,
        economyLPer100km,
        segments,
        totalDistanceM,
        totalDurationS,
        totalFuelUsedL,
        totalFillCostCents,
        stationFillCostCents,
        externalReserveCostCents,
        externalReservePricedL,
        averageFillPriceCentsPerL,
        fuelRemainingL: finalFuelRemaining,
        reserveNote,
        excludedStations: dedupeRouteExcludedStations(excludedStations),
    };
}

function routeOptimizerReturnMode() {
    const mode = routeReturnMode();
    if (mode === 'one-way') {
        return 'one_way';
    }

    return mode === 'reverses' ? 'reverse' : 'direct';
}

function routeOptimizerLocation(location) {
    return {
        lat: Number(location.lat),
        lon: Number(location.lon),
        label: String(location.display_name || location.query || '').slice(0, 200),
        physical_stop: true,
    };
}

function routeOptimizerPlanFromResponse(
    payload,
    origin,
    destinations,
    fuelQuery,
    tankCapacityL,
    economyLPer100km
) {
    const summary = payload?.summary || {};
    const selectedRoute = Array.isArray(payload?.route_pieces)
        ? payload.route_pieces.find((piece) => piece?.kind === 'selected_route')
        : null;
    const routeCoordinates = Array.isArray(selectedRoute?.geometry?.coordinates)
        ? selectedRoute.geometry.coordinates
        : [];
    if (routeCoordinates.length < 2) {
        throw new Error('Optimized route service returned no geometry.');
    }

    const itineraryLegs = Array.isArray(payload?.itinerary?.legs)
        ? payload.itinerary.legs
        : [];
    const finalTarget = itineraryLegs[itineraryLegs.length - 1]?.target || destinations[destinations.length - 1];
    const route = {
        from: origin,
        to: {
            lat: Number(finalTarget?.lat),
            lon: Number(finalTarget?.lon),
            display_name: String(finalTarget?.label || finalTarget?.display_name || 'Destination'),
        },
        distanceM: Number(summary.route_distance_m || selectedRoute?.distance_m || 0),
        durationS: Number(summary.route_duration_s || selectedRoute?.duration_s || 0),
        geometry: routeCoordinates.map((coordinate) => routePoint(coordinate[0], coordinate[1])),
        steps: [],
    };
    const stops = (Array.isArray(payload?.stops) ? payload.stops : []).map((stop) => ({
        source: String(stop?.station?.source || ''),
        state: String(stop?.station?.state || ''),
        station_id: String(stop?.station?.station_id || ''),
        station_name: String(stop?.station?.station_name || ''),
        address: String(stop?.station?.address || ''),
        latitude: Number(stop?.station?.latitude),
        longitude: Number(stop?.station?.longitude),
        price: Number(stop?.price_cents_per_l || 0),
        litresPurchased: Number(stop?.purchase_l || 0),
        purchaseCents: Number(stop?.purchase_cost_cents || 0),
        fuelAfterArrival: Number(stop?.arrival_fuel_l || 0),
        departureFuelL: Number(stop?.departure_fuel_l || 0),
        classification: String(stop?.classification || ''),
        reasonCodes: Array.isArray(stop?.reason_codes) ? stop.reason_codes : [],
        marginalNetSavingCents: Number(stop?.marginal_net_saving_cents || 0),
        additionalFuelL: Number(stop?.additional_fuel_l || 0),
        additionalFuelCostCents: Number(stop?.additional_fuel_cost_cents || 0),
        additionalFuelNextStop: String(stop?.additional_fuel_next_stop || ''),
        additionalFuelLegIndex: Number(stop?.additional_fuel_leg_index || 0),
    }));
    const additionalFuelRequirements = Array.isArray(payload?.additional_fuel_requirements)
        ? payload.additional_fuel_requirements
        : [];
    const routePieces = [
        { type: 'route', route },
        ...stops.map((stop) => ({
            type: 'fuel-stop',
            station: stop,
            litresPurchased: stop.litresPurchased,
            purchaseCents: stop.purchaseCents,
            classification: stop.classification,
            reasonCodes: stop.reasonCodes,
            marginalNetSavingCents: stop.marginalNetSavingCents,
        })),
    ];

    return {
        optimizerVersion: Number(payload?.version || 1),
        optimizerResponse: payload,
        fuelQuery,
        tankCapacityL,
        economyLPer100km,
        segments: [{
            cursor: origin,
            destination: route.to,
            routePieces,
            stops,
            excludedStations: [],
            remainingFuelL: Number(summary.ending_fuel_l || 0),
            reserveNote: null,
        }],
        itineraryLegs,
        itineraryTargets: itineraryLegs.map((leg) => leg.target).filter(Boolean),
        itineraryLegCount: Number(payload?.itinerary?.leg_count || itineraryLegs.length || 1),
        additionalFuelRequirements,
        totalDistanceM: Number(summary.route_distance_m || 0),
        totalDurationS: Number(summary.route_duration_s || 0),
        totalFuelUsedL: Number(summary.fuel_used_l || 0),
        totalFillCostCents: Number(summary.fuel_purchase_cost_cents || 0),
        stationFillCostCents: Number(summary.fuel_purchase_cost_cents || 0),
        externalReserveCostCents: 0,
        externalReservePricedL: 0,
        averageFillPriceCentsPerL: Number(summary.fuel_purchased_l || 0) > 0
            ? Number(summary.fuel_purchase_cost_cents || 0) / Number(summary.fuel_purchased_l)
            : 0,
        fuelRemainingL: Number(summary.ending_fuel_l || 0),
        reserveNote: null,
        excludedStations: [],
        warnings: Array.isArray(payload?.warnings) ? payload.warnings : [],
    };
}

async function buildOptimizedRoutePlan(
    origin,
    destinations,
    fuelQuery,
    tankCapacityL,
    startingFuelL,
    economyLPer100km,
    reserveL
) {
    const payload = await apiRequest('/api/route/optimize', {
        method: 'POST',
        body: JSON.stringify({
            version: 1,
            origin: routeOptimizerLocation(origin),
            destinations: destinations.map(routeOptimizerLocation),
            return_mode: routeOptimizerReturnMode(),
            fuel: {
                type: fuelQuery,
                tank_capacity_l: tankCapacityL,
                starting_fuel_l: startingFuelL,
                economy_l_per_100km: economyLPer100km,
                reserve_l: reserveL,
            },
            preferences: {
                mode: String(routeOptimizationMode.value || 'practical_least_cost'),
            },
        }),
    });

    return routeOptimizerPlanFromResponse(
        payload,
        origin,
        destinations,
        fuelQuery,
        tankCapacityL,
        economyLPer100km
    );
}

function buildRouteSequence(origin, destinations) {
    const nodes = [origin, ...destinations];
    if (routeReturnMode() === 'one-way') {
        return nodes;
    }

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
        ['Tank Capacity', `${Number(plan.tankCapacityL || 0).toFixed(1)} L`],
        ['Fuel Stops', String(plan.segments.reduce((count, segment) => count + segment.stops.length, 0))],
        ['Total Fill Price', `$${(Number(plan.totalFillCostCents || 0) / 100).toFixed(2)}`],
    ];
    if (plan.optimizerResponse) {
        const summary = plan.optimizerResponse.summary || {};
        const corridor = plan.optimizerResponse.corridor || {};
        const alternatives = Array.isArray(plan.optimizerResponse.alternatives)
            ? plan.optimizerResponse.alternatives
            : [];
        cards.push(
            ['Selected Route', corridor.kind === 'alternative' ? 'Alternative' : 'Fastest'],
            ['Routes Compared', String(alternatives.length + 1)],
            ['Required Stops', String(Number(summary.required_stop_count || 0))],
            ['Strategic Stops', String(Number(summary.discretionary_stop_count || 0))],
            ['Combined Stops', String(Number(summary.combined_stop_count || 0))],
            ['Ending Fuel', `${Number(summary.ending_fuel_l || 0).toFixed(1)} L`]
        );
        if (Number(summary.additional_required_fuel_l || 0) > 0) {
            cards.push(
                ['Additional Fuel', `${Number(summary.additional_required_fuel_l).toFixed(1)} L`],
                ['Additional Fuel Cost', `$${(Number(summary.additional_fuel_cost_cents || 0) / 100).toFixed(2)}`]
            );
        }
    }
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
                    station_key: stationKey(stop),
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
    (Array.isArray(plan.itineraryTargets) ? plan.itineraryTargets.slice(0, -1) : [])
        .forEach((target, index) => {
            const latitude = Number(target?.lat);
            const longitude = Number(target?.lon);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                return;
            }
            markerFeatures.push({
                type: 'Feature',
                properties: {
                    kind: 'destination',
                    label: `Itinerary stop ${index + 1}`,
                    sublabel: String(target?.label || ''),
                    segment_index: index + 1,
                },
                geometry: {
                    type: 'Point',
                    coordinates: [longitude, latitude],
                },
            });
            bounds.push([latitude, longitude]);
        });
    annotateRepeatedRouteFuelMarkers(markerFeatures);

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
        pitch: 0,
        bearing: 0,
        attributionControl: true,
        preserveDrawingBuffer: false,
    });
    routeMapInstance = map;
    map.addControl(new maplibregl.NavigationControl({ showCompass: true, showZoom: true }), 'top-right');
    scheduleMapResize(map);

    runWhenMapStyleReady(map, () => {
        fuelauAddTopographicEnhancements(map);
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
        scheduleMapResize(map);

        markerFeatures
            .filter((feature) => feature.properties.kind === 'fuel-stop')
            .forEach((feature) => {
                const marker = new maplibregl.Marker({
                    element: createRouteFuelMarkerElement(feature),
                    anchor: 'bottom',
                    offset: routeFuelMarkerOffset(feature),
                })
                    .setLngLat(feature.geometry.coordinates)
                    .addTo(map);
                routeFuelMarkers.push(marker);
            });
    });
    scheduleMapResize(map);

    routeMapLegend.innerHTML = [
        '<span class="route-map-chip"><span class="route-map-dot" style="background:#166534"></span>Origin</span>',
        '<span class="route-map-chip"><span class="route-map-dot" style="background:#0f766e"></span>Destination</span>',
        '<span class="route-map-chip"><span class="route-map-dot" style="background:#b45309"></span>Fuel stop</span>',
    ].join('');
}

function clearFuelStopFinderMarkers() {
    fuelStopFinderMarkers.forEach((marker) => {
        try {
            marker.remove();
        } catch (error) {
            void error;
        }
    });
    fuelStopFinderMarkers = [];
}

function renderFuelStopFinderMap(plan) {
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
                    station_key: stationKey(stop),
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
    annotateRepeatedRouteFuelMarkers(markerFeatures);

    fuelStopFinderMap.innerHTML = '';
    if (!window.maplibregl) {
        fuelStopFinderMap.innerHTML = renderRouteEmpty('Route map unavailable in this browser.');
        fuelStopFinderMapLegend.innerHTML = '';
        return;
    }

    if (fuelStopFinderMapInstance) {
        fuelStopFinderMapInstance.remove();
        fuelStopFinderMapInstance = null;
    }
    clearFuelStopFinderMarkers();

    if (bounds.length === 0) {
        fuelStopFinderMap.innerHTML = renderRouteEmpty('Plan a route to see the map.');
        fuelStopFinderMapLegend.innerHTML = '';
        return;
    }

    const mapConfig = window.fuelauMapConfig || {};
    const styleUrl = mapConfig.style_url;
    if (!styleUrl) {
        fuelStopFinderMap.innerHTML = renderRouteEmpty('Map style is not configured.');
        fuelStopFinderMapLegend.innerHTML = '';
        return;
    }

    const map = new maplibregl.Map({
        container: fuelStopFinderMap,
        style: styleUrl,
        center: [Number(segments[0]?.routePieces?.[0]?.route?.from?.lon || 133.7751), Number(segments[0]?.routePieces?.[0]?.route?.from?.lat || -25.2744)],
        zoom: 4,
        pitch: 0,
        bearing: 0,
        attributionControl: true,
        preserveDrawingBuffer: false,
    });
    fuelStopFinderMapInstance = map;
    map.addControl(new maplibregl.NavigationControl({ showCompass: true, showZoom: true }), 'top-right');
    scheduleMapResize(map);

    runWhenMapStyleReady(map, () => {
        fuelauAddTopographicEnhancements(map);
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
        scheduleMapResize(map);

        markerFeatures
            .filter((feature) => feature.properties.kind === 'fuel-stop')
            .forEach((feature) => {
                const marker = new maplibregl.Marker({
                    element: createRouteFuelMarkerElement(feature),
                    anchor: 'bottom',
                    offset: routeFuelMarkerOffset(feature),
                })
                    .setLngLat(feature.geometry.coordinates)
                    .addTo(map);
                fuelStopFinderMarkers.push(marker);
            });
    });
    scheduleMapResize(map);

    fuelStopFinderMapLegend.innerHTML = [
        '<span class="route-map-chip"><span class="route-map-dot" style="background:#166534"></span>Origin</span>',
        '<span class="route-map-chip"><span class="route-map-dot" style="background:#0f766e"></span>Destination</span>',
        '<span class="route-map-chip"><span class="route-map-dot" style="background:#b45309"></span>Fuel stop</span>',
    ].join('');
}

function renderRouteBreakdownInto(targetElement, plan) {
    const rows = [];
    if (plan.optimizerResponse) {
        const corridor = plan.optimizerResponse.corridor || {};
        const alternatives = Array.isArray(plan.optimizerResponse.alternatives)
            ? plan.optimizerResponse.alternatives
            : [];
        if (alternatives.length > 0) {
            const fastest = alternatives.find((candidate) => candidate?.kind === 'fastest');
            const savingCents = corridor.kind === 'alternative'
                ? Number(fastest?.generalized_cost_delta_cents || 0)
                : 0;
            rows.push({
                leg: '-',
                type: 'Notice',
                instruction: corridor.kind === 'alternative'
                    ? 'Alternative route selected for lower complete trip cost'
                    : 'Fastest route retained after complete trip comparison',
                distance: '-',
                duration: '-',
                details: corridor.kind === 'alternative' && savingCents > 0
                    ? `Estimated generalized saving: $${(savingCents / 100).toFixed(2)} after fuel price, driving time and stop burden`
                    : `${alternatives.length + 1} distinct route corridors compared using fuel price, driving time and stop burden`,
            });
        }
    }
    if (plan.reserveNote) {
        rows.push({
            leg: '-',
            type: 'Notice',
            instruction: plan.reserveNote.message,
            distance: '-',
            duration: '-',
            details: `External reserve required: ${Number(plan.reserveNote.requiredExternalReserveL || 0).toFixed(1)} L; priced quantity with 20% allowance: ${Number(plan.externalReservePricedL || 0).toFixed(1)} L; estimated cost: $${(Number(plan.externalReserveCostCents || 0) / 100).toFixed(2)}`,
        });
    }
    (Array.isArray(plan.itineraryLegs) ? plan.itineraryLegs : []).forEach((leg) => {
        const legFuelCostCents = Number(leg.fuel_purchase_cost_cents || 0);
        const additionalFuelCostCents = Number(leg.additional_fuel_cost_cents || 0);
        const fuelDetails = legFuelCostCents > 0
            ? `; fuel purchased: ${Number(leg.fuel_purchased_l || 0).toFixed(1)} L, leg fuel cost: $${(legFuelCostCents / 100).toFixed(2)}${additionalFuelCostCents > 0 ? ` including $${(additionalFuelCostCents / 100).toFixed(2)} for additional fuel` : ''}`
            : '';
        const legRow = {
            leg: Number(leg.index || 0) + 1,
            type: 'Planned stop',
            instruction: String(leg?.target?.label || `Itinerary stop ${Number(leg.index || 0) + 1}`),
            distance: formatRouteDistance(Number(leg.distance_m || 0)),
            duration: formatRouteDuration(Number(leg.duration_s || 0)),
            details: leg?.target?.physical_stop === false ? 'Route waypoint' : 'Planned stop',
        };
        legRow.details += fuelDetails;
        rows.push(legRow);
    });
    (Array.isArray(plan.additionalFuelRequirements) ? plan.additionalFuelRequirements : []).forEach((requirement) => {
        const additionalFuelL = Number(requirement.additional_fuel_l || 0);
        const additionalFuelCostCents = Number(requirement.additional_fuel_cost_cents || 0);
        const legFuelCostCents = Number(requirement.leg_fuel_purchase_cost_cents || 0);
        rows.push({
            leg: Number(requirement.leg_number || Number(requirement.leg_index || 0) + 1),
            type: 'Additional fuel required',
            instruction: String(requirement.message || `Leg ${Number(requirement.leg_index || 0) + 1} requires additional ${additionalFuelL.toFixed(1)} litres of fuel to reach next stop`),
            distance: '-',
            duration: '-',
            details: `${String(requirement.purchase_instruction || '')} Additional fuel cost: $${(additionalFuelCostCents / 100).toFixed(2)} at ${routeFuelPriceText(requirement.price_cents_per_l)}/L. Leg fuel cost including the additional fuel: $${(legFuelCostCents / 100).toFixed(2)}.`,
            additionalFuelRequired: true,
        });
    });
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
                if (piece.recommendedOnly) {
                    const detourKm = Number(piece.detourKm || routeFuelCandidateOffRouteKm(piece.station) || 0);
                    const scope = String(piece.selectionScope || 'strict');
                    rows.push({
                        leg: segmentIndex + 1,
                        type: 'Fuel stop',
                        instruction: `${piece.station.station_name} at ${piece.station.state} ${piece.station.source.toUpperCase()} - ${routeFuelPriceText(piece.station.price)}/L`,
                        distance: '-',
                        duration: '-',
                        details: `Price: ${routeFuelPriceText(piece.station.price)}/L, detour: ${detourKm.toFixed(1)} km, selected from the ${scope} detour window`,
                    });
                } else {
                    const departureTopUp = Array.isArray(piece.reasonCodes)
                        && piece.reasonCodes.includes('origin_departure_top_up');
                    const stopSuffix = piece.destinationFallback
                        ? ' destination reserve stop'
                        : (piece.contingencyFallback ? ' contingency stop' : (piece.relaxedFallback ? ' relaxed stop' : (piece.safetyFallback ? ' safety stop' : '')));
                    const optimizerDetails = departureTopUp
                        ? ', combined with departure'
                        : (piece.classification ? `, ${piece.classification} stop` : '');
                    const savingDetails = Number(piece.marginalNetSavingCents || 0) > 0
                        ? `, saves $${(Number(piece.marginalNetSavingCents) / 100).toFixed(2)}`
                        : '';
                    const reasonDetails = Array.isArray(piece.reasonCodes) && piece.reasonCodes.length > 0
                        ? `, ${piece.reasonCodes.map((reason) => String(reason).replace(/_/g, ' ')).join(', ')}`
                        : '';
                    rows.push({
                        leg: segmentIndex + 1,
                        type: departureTopUp ? 'Departure top-up' : 'Fuel stop',
                        instruction: `${piece.station.station_name} at ${piece.station.state} ${piece.station.source.toUpperCase()} - ${routeFuelPriceText(piece.station.price)}/L`,
                        distance: '-',
                        duration: '-',
                        details: `${Number(piece.litresPurchased || 0).toFixed(1)} L, $${(Number(piece.purchaseCents || 0) / 100).toFixed(2)}${optimizerDetails}${savingDetails}${reasonDetails}${stopSuffix}`,
                    });
                }
            }
        });
    });

    if (!targetElement) {
        return;
    }

    if (rows.length === 0) {
        targetElement.innerHTML = renderRouteEmpty('Plan a route to see leg breakdowns.');
        return;
    }

    targetElement.innerHTML = `
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
                    <tr class="${row.additionalFuelRequired ? 'route-breakdown-row route-breakdown-additional' : (row.type === 'Fuel stop' ? 'route-breakdown-row route-breakdown-stop' : 'route-breakdown-row')}">
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

function renderRouteBreakdown(plan) {
    renderRouteBreakdownInto(routeLegs, plan);
}

async function resolveRouteLocation(query) {
    const payload = await apiRequest(`/api/geo/search?q=${encodeURIComponent(query)}&limit=10`);
    const results = Array.isArray(payload.results) ? payload.results : [];
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
    const savedTankCapacity = String(state.tankCapacity ?? state.fuelFill ?? '').trim();
    const tankCapacity = savedTankCapacity !== ''
        ? savedTankCapacity
        : String(routePlannerDefaultTankCapacityL);
    routeTankCapacity.value = tankCapacity;
    routeStartingFuel.value = String(String(state.startingFuel ?? '').trim() !== ''
        ? state.startingFuel
        : tankCapacity);
    routeFuelReserve.value = String(String(state.fuelReserve ?? '').trim() !== ''
        ? state.fuelReserve
        : Number(tankCapacity) * 0.1);
    routeFuelEconomy.value = String(String(state.fuelEconomy || '').trim() !== '' ? state.fuelEconomy : routePlannerDefaultFuelEconomyLPer100km);
    routeOptimizationMode.value = String(state.optimizationMode || 'practical_least_cost');
    if (!['practical_least_cost', 'fewer_stops'].includes(routeOptimizationMode.value)) {
        routeOptimizationMode.value = 'practical_least_cost';
    }
    if (routeUseOptimizer) {
        routeUseOptimizer.checked = typeof state.useOptimizer === 'boolean'
            ? state.useOptimizer
            : routeOptimizerV2Default;
    }
    syncRouteOptimizerControls();
    syncRouteVehicleInputBounds();
    routeReturnOneWay.checked = String(state.returnMode || 'direct') === 'one-way';
    routeReturnReverses.checked = String(state.returnMode || 'direct') === 'reverses';
    routeReturnDirect.checked = !routeReturnOneWay.checked && !routeReturnReverses.checked;
    syncRouteReturnModeControls();
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
    const tankCapacity = routeTankCapacityValue();
    const startingFuel = routeStartingFuelValue();
    const reserveFuel = routeFuelReserveValue();
    const fuelEconomy = routeFuelDefaultEconomyValue();

    if (originValue === '') {
        routeStatus.textContent = 'Origin is required.';
        return;
    }
    if (destinationValues.length === 0) {
        routeStatus.textContent = 'At least one destination is required.';
        return;
    }
    if (tankCapacity <= 0 || fuelEconomy <= 0) {
        routeStatus.textContent = 'Tank capacity and fuel economy must be greater than zero.';
        return;
    }
    if (
        routeOptimizerSelected()
        && (startingFuel < 0 || startingFuel > tankCapacity)
    ) {
        routeStatus.textContent = 'Starting fuel must be between zero and tank capacity.';
        return;
    }
    if (
        routeOptimizerSelected()
        && (reserveFuel < 0 || reserveFuel >= tankCapacity)
    ) {
        routeStatus.textContent = 'Required reserve must be non-negative and less than tank capacity.';
        return;
    }

    routePlan.disabled = true;
    routeStatus.textContent = 'Resolving locations and building route legs...';
    routeStatus.classList.remove('route-status-warning');
    routeStatus.classList.remove('route-status-error');
    routeSummary.innerHTML = renderRouteEmpty('Planning route...');
    routeMap.innerHTML = renderRouteEmpty('Resolving locations...');
    routeMapLegend.innerHTML = '';
    routeLegs.innerHTML = renderRouteEmpty('Building legs...');
    routeExcludedStatus.textContent = '';

    try {
        const origin = await resolveRouteLocation(originValue);
        const destinations = [];
        for (const value of destinationValues) {
            try {
                destinations.push(await resolveRouteLocation(value));
            } catch (error) {
                throw new Error(`Geocoding failed for "${value}": ${error.message}`);
            }
        }
        const plan = routeOptimizerSelected()
            ? await buildOptimizedRoutePlan(
                origin,
                destinations,
                routeFuelQueryLabel(),
                tankCapacity,
                startingFuel,
                fuelEconomy,
                reserveFuel
            )
            : await buildRoutePlan(
                buildRouteSequence(origin, destinations),
                routeFuelQueryLabel(),
                tankCapacity,
                fuelEconomy
            );
        renderRouteSummary(plan);
        renderRouteMap(plan);
        renderRouteBreakdown(plan);
        routeExcludedStatus.textContent = formatRouteExcludedStations(plan.excludedStations);
        saveRoutePlannerState(true);

        const returnMode = routeReturnMode() === 'reverses'
            ? 'Return reverses path'
            : routeReturnMode() === 'one-way'
                ? 'One Way'
                : 'Return direct to origin';
        const hadContingencyStop = plan.segments.some((segment) => Array.isArray(segment.stops) && segment.stops.some((stop) => stop.contingencyFallback));
        const contingencyMessage = hadContingencyStop ? ' Contingency refill used on one or more legs.' : '';
        const warningMessage = Array.isArray(plan.warnings) && plan.warnings.length > 0
            ? ` ${plan.warnings.join(' ')}`
            : '';
        const plannedLegCount = Number(plan.itineraryLegCount || plan.segments.length);
        routeStatus.classList.toggle(
            'route-status-warning',
            Boolean(plan.reserveNote) || warningMessage !== ''
        );
        routeStatus.textContent = plan.reserveNote
            ? `Planned ${plannedLegCount} legs using ${returnMode}.${contingencyMessage} ${plan.reserveNote.message}${warningMessage}`
            : `Planned ${plannedLegCount} legs using ${returnMode}.${contingencyMessage}${warningMessage}`;
    } catch (error) {
        routeStatus.classList.remove('route-status-warning');
        routeStatus.classList.add('route-status-error');
        const message = String(error?.message || error || 'An unexpected error occurred.');
        routeStatus.textContent = `Route planning failed: ${message}`;
        routeSummary.innerHTML = renderRouteError(message);
        routeMap.innerHTML = renderRouteError(message);
        routeMapLegend.innerHTML = '';
        routeLegs.innerHTML = renderRouteError(message);
        routeExcludedStatus.textContent = '';
    } finally {
        routePlan.disabled = false;
    }
}

function resetRoutePlanner(options = {}) {
    const clearStorage = options.clearStorage !== false;
    routeOrigin.value = '';
    syncRouteFuelSelector();
    routeTankCapacity.value = String(routePlannerDefaultTankCapacityL);
    routeStartingFuel.value = String(routePlannerDefaultStartingFuelL);
    routeFuelReserve.value = String(routePlannerDefaultReserveL);
    routeFuelEconomy.value = String(routePlannerDefaultFuelEconomyLPer100km);
    routeOptimizationMode.value = 'practical_least_cost';
    if (routeUseOptimizer) {
        routeUseOptimizer.checked = routeOptimizerV2Default;
    }
    syncRouteOptimizerControls();
    syncRouteVehicleInputBounds();
    routeReturnDirect.checked = true;
    routeReturnReverses.checked = false;
    routeReturnOneWay.checked = false;
    syncRouteReturnModeControls();
    routeStatus.classList.remove('route-status-warning');
    routeStatus.classList.remove('route-status-error');
    routeExcludedStatus.textContent = '';
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
    routeTankCapacity.value = '60';
    routeStartingFuel.value = '60';
    routeFuelReserve.value = '6';
    routeFuelEconomy.value = '12';
    routeStatus.textContent = 'Loaded test cities: Cairns -> Birdsville -> Brisbane.';
    planRoute();
}

function restoreFuelStopFinderState(state) {
    if (!state || typeof state !== 'object') {
        return false;
    }

    resetFuelStopFinder({ clearStorage: false });
    fuelStopFinderOrigin.value = String(state.origin || '');
    fuelStopFinderDestination.value = String(state.destination || '');
    syncFuelStopFinderSelector();
    if (state.fuel) {
        fuelStopFinderFuelType.value = String(state.fuel || '');
    }
    fuelStopFinderEconomy.value = String(state.economy || '');
    fuelStopFinderStatus.textContent = state.planned ? 'Restored last planned fuel stop route.' : 'Restored saved fuel stop inputs.';
    return true;
}

function resetFuelStopFinder(options = {}) {
    const clearStorage = options.clearStorage !== false;
    fuelStopFinderOrigin.value = '';
    fuelStopFinderDestination.value = '';
    syncFuelStopFinderSelector();
    fuelStopFinderEconomy.value = '';
    fuelStopFinderStatus.classList.remove('route-status-warning');
    fuelStopFinderDetail.textContent = '';
    fuelStopFinderSummary.innerHTML = renderRouteEmpty('No fuel stop route planned yet.');
    fuelStopFinderRecommendation.innerHTML = '';
    fuelStopFinderMap.innerHTML = renderRouteEmpty('No fuel stop route planned yet.');
    fuelStopFinderMapLegend.innerHTML = '';
    fuelStopFinderLegs.innerHTML = renderRouteEmpty('No fuel stop route planned yet.');
    clearFuelStopFinderMarkers();
    if (fuelStopFinderMapInstance) {
        try {
            fuelStopFinderMapInstance.remove();
        } catch (error) {
            void error;
        }
        fuelStopFinderMapInstance = null;
    }
    if (clearStorage) {
        clearFuelStopFinderState();
    }
    fuelStopFinderStatus.textContent = 'Enter a trip to find the best fuel stop.';
}

async function planFuelStopFinder() {
    const originValue = fuelStopFinderOrigin.value.trim();
    const destinationValue = fuelStopFinderDestination.value.trim();
    const economy = Number(fuelStopFinderEconomy.value || 0);
    const fuelQuery = fuelStopFinderFuelSelectedLabel();

    if (originValue === '') {
        fuelStopFinderStatus.textContent = 'Origin is required.';
        return;
    }
    if (destinationValue === '') {
        fuelStopFinderStatus.textContent = 'Destination is required.';
        return;
    }
    if (economy <= 0) {
        fuelStopFinderStatus.textContent = 'Fuel economy must be greater than zero.';
        return;
    }

    fuelStopFinderPlan.disabled = true;
    fuelStopFinderStatus.textContent = 'Resolving locations and searching for the best fuel stop...';
    fuelStopFinderStatus.classList.remove('route-status-warning');
    fuelStopFinderDetail.textContent = '';
    fuelStopFinderSummary.innerHTML = renderRouteEmpty('Planning route...');
    fuelStopFinderRecommendation.innerHTML = '';
    fuelStopFinderMap.innerHTML = renderRouteEmpty('Resolving locations...');
    fuelStopFinderMapLegend.innerHTML = '';
    fuelStopFinderLegs.innerHTML = renderRouteEmpty('Building route...');

    try {
        const origin = await resolveRouteLocation(originValue);
        let destination = null;
        try {
            destination = await resolveRouteLocation(destinationValue);
        } catch (error) {
            throw new Error(`Geocoding failed for "${destinationValue}": ${error.message}`);
        }
        const plan = await buildFuelStopFinderPlan(origin, destination, fuelQuery, economy);
        const stop = plan.selectedStop || (Array.isArray(plan.segments) ? plan.segments.flatMap((segment) => segment.stops || [])[0] : null);
        renderFuelStopFinderSummary(plan, stop, fuelQuery);
        renderFuelStopFinderRecommendation(stop, plan);
        renderFuelStopFinderMap(plan);
        renderRouteBreakdownInto(fuelStopFinderLegs, plan);
        saveFuelStopFinderState(true);

        fuelStopFinderDetail.textContent = `Evaluating ${fuelQuery || 'Diesel'} stations along the route and selecting the cheapest eligible stop with a sensible detour.`;
        const selectionScope = String(plan?.segments?.[0]?.routePieces?.find((piece) => piece.type === 'fuel-stop')?.selectionScope || 'strict');
        fuelStopFinderStatus.classList.toggle('route-status-warning', selectionScope !== 'strict');
        fuelStopFinderStatus.textContent = stop
            ? `Selected ${routeFuelStationDisplay(stop) || stop.station_name || 'a fuel stop'} for this route${selectionScope !== 'strict' ? ` using the ${selectionScope} detour window.` : '.'}`
            : 'No eligible fuel stop was selected for this route.';
    } catch (error) {
        fuelStopFinderStatus.classList.remove('route-status-warning');
        fuelStopFinderStatus.textContent = error.message;
        fuelStopFinderSummary.innerHTML = renderRouteEmpty(error.message);
        fuelStopFinderRecommendation.innerHTML = '';
        fuelStopFinderMap.innerHTML = renderRouteEmpty(error.message);
        fuelStopFinderMapLegend.innerHTML = '';
        fuelStopFinderLegs.innerHTML = renderRouteEmpty(error.message);
        fuelStopFinderDetail.textContent = '';
    } finally {
        fuelStopFinderPlan.disabled = false;
    }
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

function fuelRecordHasRenderablePrice(value) {
    return routeFuelPriceIsReasonable(value);
}

function fuelRecordIsRenderable(row) {
    return fuelRecordHasRenderablePrice(row?.price)
        && routeFuelPriceIsFresh(row?.updated_at);
}

function fuelRowsForRendering(rows) {
    return (Array.isArray(rows) ? rows : []).filter(fuelRecordIsRenderable);
}

function snapshotPriceMovementMarkup(row) {
    const currentPrice = Number(row?.price);
    const previousPriceRaw = row?.previous_price;
    if (previousPriceRaw === null || previousPriceRaw === undefined || previousPriceRaw === '') {
        return '';
    }

    const previousPrice = Number(previousPriceRaw);
    if (!Number.isFinite(currentPrice) || !Number.isFinite(previousPrice) || currentPrice === previousPrice) {
        return '';
    }

    const isDown = currentPrice < previousPrice;
    const delta = Math.abs(currentPrice - previousPrice).toFixed(1);
    const label = isDown
        ? `Down ${delta} c/L from the last reported price`
        : `Up ${delta} c/L from the last reported price`;

    return `<span class="snapshot-movement ${isDown ? 'snapshot-movement-down' : 'snapshot-movement-up'}" title="${escapeHtml(label)}" aria-label="${escapeHtml(label)}"><span class="snapshot-movement-arrow" aria-hidden="true"></span></span>`;
}

function formatCompactDate(value) {
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }
    return parsed.toLocaleDateString('en-AU', { day: '2-digit', month: 'short' });
}

function formatDateTime(value) {
    const text = String(value || '').trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        const parsedDate = new Date(`${text}T00:00:00Z`);
        if (!Number.isNaN(parsedDate.getTime())) {
            return parsedDate.toLocaleDateString('en-AU', {
                day: '2-digit',
                month: 'short',
                timeZone: 'Australia/Brisbane',
            });
        }
        return text;
    }
    const parsed = new Date(text.replace(' ', 'T') + 'Z');
    if (Number.isNaN(parsed.getTime())) {
        return text;
    }
    return parsed.toLocaleString('en-AU', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Australia/Brisbane',
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
    const source = state === 'QLD'
        ? 'qld'
        : (state === 'NSW'
            ? 'nsw'
            : (state === 'WA'
                ? 'wa'
                : (state === 'TAS' ? 'tas' : (state === 'NT' ? 'nt' : 'all'))));
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
        : ((fuelState.value === 'NSW' || fuelState.value === 'TAS') ? 'DL' : (fuelState.value === 'WA' ? '1' : ''));
    const labelFuel = options.find((item) => item.label.toLowerCase() === currentLabel.toLowerCase())?.value || '';
    const fallbackFuel = labelFuel !== ''
        ? labelFuel
        : (options.find((item) => item.value === desiredDefaultFuel)?.value || '');
    setSelectOptions(fuelType, options, fallbackFuel);
    syncFuelStopFinderSelector();
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
        ['SA', summary.sa],
        ['NSW', summary.nsw],
        ['TAS', summary.tas],
        ['VIC', summary.vic],
        ['WA', summary.wa],
        ['NT', summary.nt],
    ];
    cards.forEach(([label, item]) => {
        const currentPrices = Number(item?.current_prices || 0);
        const stations = Number(item?.stations || 0);
        const latestUpdate = item?.latest_update ? formatDateTime(item.latest_update) : 'No data yet';
        const lastChecked = item?.last_checked ? formatDateTime(item.last_checked) : '';
        const card = document.createElement('article');
        card.className = 'summary-card';
        card.innerHTML = `
            <strong>${escapeHtml(String(currentPrices))}</strong>
            <span>${escapeHtml(label)} current prices</span>
            <span>${escapeHtml(String(stations))} stations</span>
            <span>Latest report: ${escapeHtml(latestUpdate)}</span>
            ${lastChecked ? `<span>Last checked: ${escapeHtml(lastChecked)}</span>` : ''}
        `;
        fuelSummary.appendChild(card);
    });
}

function chartEmpty(message) {
    return `<div class="chart-empty">${escapeHtml(message)}</div>`;
}

function buildSmoothPath(points) {
    if (!Array.isArray(points) || points.length === 0) {
        return '';
    }
    if (points.length === 1) {
        return `M ${points[0].x} ${points[0].y}`;
    }

    const path = [`M ${points[0].x} ${points[0].y}`];
    for (let index = 0; index < points.length - 1; index += 1) {
        const current = points[index];
        const next = points[index + 1];
        const previous = points[index - 1] || current;
        const following = points[index + 2] || next;
        const control1X = current.x + (next.x - previous.x) / 6;
        const control1Y = current.y + (next.y - previous.y) / 6;
        const control2X = next.x - (following.x - current.x) / 6;
        const control2Y = next.y - (following.y - current.y) / 6;
        path.push(`C ${control1X} ${control1Y}, ${control2X} ${control2Y}, ${next.x} ${next.y}`);
    }
    return path.join(' ');
}

function buildSmoothAreaPath(upperPoints, lowerPoints) {
    if (!Array.isArray(upperPoints) || !Array.isArray(lowerPoints) || upperPoints.length === 0 || lowerPoints.length === 0) {
        return '';
    }

    const upperPath = buildSmoothPath(upperPoints);
    const lowerPath = buildSmoothPath(lowerPoints.slice().reverse()).replace(/^M\s+/, 'L ');
    if (upperPath === '' || lowerPath === '') {
        return '';
    }

    return `${upperPath} ${lowerPath} Z`;
}

function focusFuelStationsFromPriceRange(minimumPrice, maximumPrice, periodLabel, bucketDate) {
    const minTarget = Number(minimumPrice);
    const maxTarget = Number(maximumPrice);
    if (!Number.isFinite(minTarget) && !Number.isFinite(maxTarget)) {
        renderFuelMap(fuelCurrentRows || []);
        return;
    }

    const priceMatches = (rowPrice, target) => Number.isFinite(target) && Math.abs(Number(rowPrice) - target) <= 0.05;
    const minStations = Number.isFinite(minTarget)
        ? (fuelCurrentRows || []).filter((row) => priceMatches(row.price, minTarget))
        : [];
    const maxStations = Number.isFinite(maxTarget)
        ? (fuelCurrentRows || []).filter((row) => priceMatches(row.price, maxTarget))
        : [];

    renderFuelMap(fuelCurrentRows || [], {
        minStations,
        maxStations,
        minPrice: minTarget,
        maxPrice: maxTarget,
        periodLabel,
        bucketDate,
    });
    if (fuelStatus) {
        fuelStatus.textContent = `${periodLabel} sample ${bucketDate}: showing ${minStations.length} min-price station(s) and ${maxStations.length} max-price station(s).`;
    }
}

function renderLineChart(container, meta, series) {
    if (!Array.isArray(series) || series.length === 0) {
        container.innerHTML = chartEmpty('No weekly data available for this filter.');
        meta.innerHTML = '';
        return;
    }

    const minimumValues = series.map((item) => Number(item.minimum_price ?? item.average_price));
    const maximumValues = series.map((item) => Number(item.maximum_price ?? item.average_price));
    const min = Math.min(...minimumValues);
    const max = Math.max(...maximumValues);
    const spread = Math.max(max - min, 1);
    const width = 640;
    const height = 280;
    const padding = { top: 20, right: 16, bottom: 32, left: 48 };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;
    const points = series.map((item, index) => {
        const x = padding.left + (plotWidth * index / Math.max(series.length - 1, 1));
        const averageY = padding.top + ((max - Number(item.average_price)) / spread) * plotHeight;
        const minY = padding.top + ((max - Number(item.minimum_price ?? item.average_price)) / spread) * plotHeight;
        const maxY = padding.top + ((max - Number(item.maximum_price ?? item.average_price)) / spread) * plotHeight;
        return { x, y: averageY, minY, maxY, item };
    });
    const avgPath = buildSmoothPath(points.map((point) => ({ x: point.x, y: point.y })));
    const minPath = buildSmoothPath(points.map((point) => ({ x: point.x, y: point.minY })));
    const maxPath = buildSmoothPath(points.map((point) => ({ x: point.x, y: point.maxY })));
    const bandPath = buildSmoothAreaPath(
        points.map((point) => ({ x: point.x, y: point.maxY })),
        points.map((point) => ({ x: point.x, y: point.minY }))
    );

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
            ${bandPath ? `<path d="${bandPath}" fill="rgba(15,118,110,0.22)" stroke="none"></path>` : ''}
            ${maxPath ? `<path d="${maxPath}" fill="none" stroke="rgba(15,118,110,0.38)" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round" stroke-linejoin="round"></path>` : ''}
            ${minPath ? `<path d="${minPath}" fill="none" stroke="rgba(15,118,110,0.38)" stroke-width="2" stroke-dasharray="6 4" stroke-linecap="round" stroke-linejoin="round"></path>` : ''}
            <path d="${avgPath}" fill="none" stroke="#0f766e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path>
            ${points.map((point) => `
                <g class="fuel-chart-node" data-bucket-date="${escapeHtml(String(point.item.bucket_date))}" data-min-price="${escapeHtml(String(point.item.minimum_price))}" data-max-price="${escapeHtml(String(point.item.maximum_price))}">
                    <circle cx="${point.x}" cy="${point.maxY}" r="2.5" fill="#0f766e" opacity="0.55"></circle>
                    <circle cx="${point.x}" cy="${point.minY}" r="2.5" fill="#0f766e" opacity="0.55"></circle>
                    <circle cx="${point.x}" cy="${point.y}" r="4" fill="#0f766e"></circle>
                    <title>${formatCompactDate(point.item.bucket_date)}: avg ${formatPrice(point.item.average_price)}, min ${formatPrice(point.item.minimum_price)}, max ${formatPrice(point.item.maximum_price)}</title>
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

    container.querySelectorAll('.fuel-chart-node').forEach((node) => {
        node.style.cursor = 'pointer';
        node.addEventListener('click', () => {
            focusFuelStationsFromPriceRange(
                node.getAttribute('data-min-price'),
                node.getAttribute('data-max-price'),
                'Weekly',
                node.getAttribute('data-bucket-date')
            );
        });
    });
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
                    <g class="fuel-chart-bar" data-bucket-date="${escapeHtml(String(item.bucket_date))}" data-min-price="${escapeHtml(String(item.minimum_price))}" data-max-price="${escapeHtml(String(item.maximum_price))}">
                        <rect x="${x}" y="${y}" width="${barWidth}" height="${Math.max(barHeight, 2)}" rx="4" fill="#0f766e"></rect>
                        <title>${formatCompactDate(item.bucket_date)}: avg ${formatPrice(item.average_price)}, min ${formatPrice(item.minimum_price)}, max ${formatPrice(item.maximum_price)}</title>
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

    container.querySelectorAll('.fuel-chart-bar').forEach((node) => {
        node.style.cursor = 'pointer';
        node.addEventListener('click', () => {
            focusFuelStationsFromPriceRange(
                node.getAttribute('data-min-price'),
                node.getAttribute('data-max-price'),
                'Monthly',
                node.getAttribute('data-bucket-date')
            );
        });
    });
}

function renderSnapshot(rows) {
    const visibleRows = fuelRowsForRendering(rows).sort((left, right) => {
        const leftTime = new Date(String(left?.updated_at || '').replace(' ', 'T') + 'Z').getTime();
        const rightTime = new Date(String(right?.updated_at || '').replace(' ', 'T') + 'Z').getTime();
        return (Number.isFinite(rightTime) ? rightTime : 0) - (Number.isFinite(leftTime) ? leftTime : 0);
    });
    if (visibleRows.length === 0) {
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
                ${visibleRows.slice(0, 8).map((row) => `
                    <tr>
                        <td>${escapeHtml(row.station_name)}<br><span>${escapeHtml(`${row.state} · ${row.source.toUpperCase()}`)}</span></td>
                        <td>${escapeHtml(row.fuel_name)}</td>
                        <td><span class="snapshot-price-row"><span class="snapshot-price">${escapeHtml(formatPrice(row.price))}</span>${snapshotPriceMovementMarkup(row)}</span><br><span>Reported ${escapeHtml(formatDateTime(row.updated_at))}</span>${row.last_seen_at ? `<br><span>Checked ${escapeHtml(formatDateTime(row.last_seen_at))}</span>` : ''}</td>
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

function renderFuelMapLegend(rows, highlight = null) {
    if (!fuelMapLegend) {
        return;
    }

    const region = fuelRegionSelectedOption();
    const stationCount = Array.isArray(rows) ? rows.length : 0;
    const minCount = Array.isArray(highlight?.minStations) ? highlight.minStations.length : 0;
    const maxCount = Array.isArray(highlight?.maxStations) ? highlight.maxStations.length : 0;
    const minLabel = Number.isFinite(Number(highlight?.minPrice)) ? formatPrice(highlight.minPrice) : null;
    const maxLabel = Number.isFinite(Number(highlight?.maxPrice)) ? formatPrice(highlight.maxPrice) : null;
    const mapScope = String(highlight?.mapLabel || fuelMapLegendContext || (region ? `${region.label}, ${region.state}` : 'Selected region'));
    fuelMapLegend.innerHTML = `
        <span class="route-map-chip"><span class="route-map-dot" style="background:#16a34a"></span>Cheaper</span>
        <span class="route-map-chip"><span class="route-map-dot" style="background:#ca8a04"></span>Mid-range</span>
        <span class="route-map-chip"><span class="route-map-dot" style="background:#b91c1c"></span>Higher</span>
        <span class="route-map-chip"><span class="route-map-dot" style="background:#94a3b8"></span>No price</span>
        <span class="route-map-chip">${escapeHtml(mapScope)}</span>
        <span class="route-map-chip">${escapeHtml(selectedFuelLabel() || 'Selected fuel')}</span>
        <span class="route-map-chip">${escapeHtml(`${stationCount} stations plotted`)}</span>
        ${minCount > 0 ? `<span class="route-map-chip"><span class="route-map-dot" style="background:#16a34a"></span>Min ${escapeHtml(minLabel || '')} (${minCount})</span>` : ''}
        ${maxCount > 0 ? `<span class="route-map-chip"><span class="route-map-dot" style="background:#b91c1c"></span>Max ${escapeHtml(maxLabel || '')} (${maxCount})</span>` : ''}
    `;
}

function fuelMapPopupHtml(row) {
    const station = escapeHtml(String(row.station_name || '').trim());
    const address = escapeHtml(String(row.address || '').trim());
    const fuelName = escapeHtml(selectedFuelLabel() || String(row.fuel_name || 'Fuel'));
    const price = escapeHtml(String(row.price_text || formatPrice(row.price)));
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
    const visibleRows = fuelRowsForRendering(rows);
    const prices = visibleRows
        .map((row) => Number(row.price))
        .filter((value) => Number.isFinite(value));
    const minPrice = prices.length > 0 ? Math.min(...prices) : null;
    const maxPrice = prices.length > 0 ? Math.max(...prices) : null;
    const features = visibleRows
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

function fuelMapHighlightCollection(highlight) {
    const minStations = Array.isArray(highlight?.minStations) ? highlight.minStations : [];
    const maxStations = Array.isArray(highlight?.maxStations) ? highlight.maxStations : [];
    const features = [];

    minStations.filter((row) => fuelRecordHasRenderablePrice(row?.price)).forEach((row) => {
        features.push({
            type: 'Feature',
            properties: {
                station_name: String(row.station_name || ''),
                address: String(row.address || ''),
                price: String(row.price ?? ''),
                price_text: formatPrice(row.price),
                fuel_name: String(row.fuel_name || ''),
                source: String(row.source || ''),
                state: String(row.state || ''),
                updated_at: String(row.updated_at || ''),
                kind: 'min',
            },
            geometry: {
                type: 'Point',
                coordinates: [Number(row.longitude), Number(row.latitude)],
            },
        });
    });

    maxStations.filter((row) => fuelRecordHasRenderablePrice(row?.price)).forEach((row) => {
        features.push({
            type: 'Feature',
            properties: {
                station_name: String(row.station_name || ''),
                address: String(row.address || ''),
                price: String(row.price ?? ''),
                price_text: formatPrice(row.price),
                fuel_name: String(row.fuel_name || ''),
                source: String(row.source || ''),
                state: String(row.state || ''),
                updated_at: String(row.updated_at || ''),
                kind: 'max',
            },
            geometry: {
                type: 'Point',
                coordinates: [Number(row.longitude), Number(row.latitude)],
            },
        });
    });

    return {
        type: 'FeatureCollection',
        features,
    };
}

function fuelMapSelectedRegionBounds(radiusKm = 25) {
    const region = fuelRegionSelectedOption();
    const latitude = Number(region?.lat);
    const longitude = Number(region?.lon);
    const radius = Math.max(1, Number(radiusKm || 25));
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        return null;
    }

    const latDelta = radius / 110.574;
    const lonScale = Math.max(Math.cos(latitude * Math.PI / 180), 0.2);
    const lonDelta = radius / (111.320 * lonScale);

    return new maplibregl.LngLatBounds(
        [longitude - lonDelta, latitude - latDelta],
        [longitude + lonDelta, latitude + latDelta]
    );
}

function fuelMapFocusSelectedRegion(radiusKm = 25, duration = 400) {
    if (!fuelMapInstance || !fuelMapReady) {
        return false;
    }

    const bounds = fuelMapSelectedRegionBounds(radiusKm);
    if (!bounds) {
        return false;
    }

    fuelMapAutoRefreshSuppressed = true;
    fuelMapInstance.fitBounds(bounds, {
        padding: 36,
        duration,
    });
    window.setTimeout(() => {
        fuelMapAutoRefreshSuppressed = false;
    }, 700);
    return true;
}

function updateFuelMapSource(collection, highlight = null, preserveViewport = false) {
    if (!fuelMapInstance || !fuelMapReady) {
        fuelMapPendingData = { collection, highlight, preserveViewport };
        return;
    }

    const highlightCollection = fuelMapHighlightCollection(highlight);
    const highlightFeatures = Array.isArray(highlightCollection.features) ? highlightCollection.features : [];
    const hasHighlight = highlightFeatures.length > 0;
    const visibleCollection = hasHighlight
        ? { type: 'FeatureCollection', features: [] }
        : collection;

    const source = fuelMapInstance.getSource('fuel-stations');
    if (source) {
        source.setData(visibleCollection);
    }
    const highlightSource = fuelMapInstance.getSource('fuel-station-highlights');
    if (highlightSource) {
        highlightSource.setData(highlightCollection);
    }

    const features = Array.isArray(collection.features) ? collection.features : [];
    if (features.length === 0) {
        if (!preserveViewport) {
            fuelMapFocusSelectedRegion();
        }
        renderFuelMapLegend([], highlight);
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

    const focusFeatures = hasHighlight ? highlightFeatures : features;
    if (!preserveViewport && focusFeatures.length === 1) {
        const only = focusFeatures[0];
        fuelMapAutoRefreshSuppressed = true;
        fuelMapInstance.easeTo({
            center: only.geometry.coordinates,
            zoom: 15,
            duration: 400,
        });
        window.setTimeout(() => {
            fuelMapAutoRefreshSuppressed = false;
        }, 700);
    } else if (!preserveViewport && focusFeatures.length > 1) {
        if (!fuelMapFocusSelectedRegion()) {
            fuelMapAutoRefreshSuppressed = true;
            const bounds = new maplibregl.LngLatBounds();
            focusFeatures.forEach((feature) => {
                bounds.extend(feature.geometry.coordinates);
            });
            fuelMapInstance.fitBounds(bounds, {
                padding: 50,
                maxZoom: 12,
                duration: 400,
            });
            window.setTimeout(() => {
                fuelMapAutoRefreshSuppressed = false;
            }, 700);
        }
    }

    renderFuelMapLegend(features, highlight);
}

function fuelMapViewportRequestParams() {
    if (!fuelMapInstance || !fuelMapReady) {
        return null;
    }

    const bounds = fuelMapInstance.getBounds();
    const center = bounds.getCenter();
    const northEast = bounds.getNorthEast();
    const southWest = bounds.getSouthWest();
    const radiusKm = Math.max(5, Math.min(300, haversineKm(
        { lat: center.lat, lon: center.lng },
        { lat: northEast.lat, lon: northEast.lng }
    ) * 1.15));
    const params = new URLSearchParams({
        state: fuelState.value || '',
        fuel: fuelType.value || '',
        lat: String(center.lat),
        lon: String(center.lng),
        radius_km: radiusKm.toFixed(1),
    });
    return { params, bounds, center, northEast, southWest };
}

function fuelMapRowsInsideBounds(rows, bounds) {
    if (!bounds || !Array.isArray(rows)) {
        return [];
    }

    return rows.filter((row) => {
        const latitude = Number(row.latitude);
        const longitude = Number(row.longitude);
        return Number.isFinite(latitude)
            && Number.isFinite(longitude)
            && bounds.contains([longitude, latitude]);
    });
}

function fuelPricesTabIsActive() {
    return document.getElementById('fuel-prices-tab')?.getAttribute('aria-selected') === 'true';
}

async function refreshFuelMapForViewport() {
    if (!fuelPricesTabIsActive()) {
        return;
    }

    const request = fuelMapViewportRequestParams();
    if (!request) {
        return;
    }

    const requestKey = request.params.toString();
    if (requestKey === fuelMapViewportLastRequestKey) {
        return;
    }

    if (fuelMapViewportAbortController) {
        fuelMapViewportAbortController.abort();
    }
    const abortController = new AbortController();
    fuelMapViewportAbortController = abortController;
    fuelMapViewportLastRequestKey = requestKey;

    try {
        const payload = await apiRequest(
            `/api/fuel/current?${requestKey}&limit=500`,
            { signal: abortController.signal }
        );
        const rows = fuelRowsForRendering(fuelMapRowsInsideBounds(Array.isArray(payload.rows) ? payload.rows : [], request.bounds));
        fuelMapRows = rows;
        fuelMapLegendContext = 'Visible map area';
        renderFuelMap(fuelMapRows, null, true);
        if (fuelStatus) {
            fuelStatus.textContent = `Loaded ${rows.length} current records for the visible map area.`;
        }
    } catch (error) {
        if (error?.name === 'AbortError') {
            return;
        }
        fuelMapViewportLastRequestKey = '';
        if (fuelStatus) {
            fuelStatus.textContent = error.message;
        }
    } finally {
        if (fuelMapViewportAbortController === abortController) {
            fuelMapViewportAbortController = null;
        }
    }
}

function scheduleFuelMapViewportRefresh() {
    if (fuelMapAutoRefreshSuppressed || !fuelPricesTabIsActive()) {
        return;
    }

    if (fuelMapAutoRefreshTimer) {
        window.clearTimeout(fuelMapAutoRefreshTimer);
    }
    fuelMapAutoRefreshTimer = window.setTimeout(() => {
        fuelMapAutoRefreshTimer = null;
        refreshFuelMapForViewport();
    }, 1500);
}

function renderFuelMap(rows, highlight = null, preserveViewport = false) {
    if (!fuelMap) {
        return;
    }

    const visibleRows = fuelRowsForRendering(rows);
    const collection = fuelMapFeatureCollection(visibleRows);
    const highlightCollection = fuelMapHighlightCollection(highlight);
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

        const region = fuelRegionSelectedOption();
        const initialCenter = region && Number.isFinite(Number(region.lat)) && Number.isFinite(Number(region.lon))
            ? [Number(region.lon), Number(region.lat)]
            : [134.0, -25.0];
        const initialZoom = region ? 9.5 : 4;

        fuelMapInstance = new maplibregl.Map({
            container: fuelMap,
            style: styleUrl,
            center: initialCenter,
            zoom: initialZoom,
            pitch: 0,
            bearing: 0,
            attributionControl: true,
            preserveDrawingBuffer: false,
        });
        fuelMapInstance.addControl(new maplibregl.NavigationControl({ showCompass: true, showZoom: true }), 'top-right');
        fuelMapPopup = new maplibregl.Popup({ closeButton: true, closeOnClick: true, offset: 16 });
        scheduleMapResize(fuelMapInstance);

        runWhenMapStyleReady(fuelMapInstance, () => {
            fuelauAddTopographicEnhancements(fuelMapInstance);
            fuelMapReady = true;
            if (!fuelMapInstance.getSource('fuel-stations')) {
                fuelMapInstance.addSource('fuel-stations', {
                    type: 'geojson',
                    data: collection,
                });
            }
            if (!fuelMapInstance.getSource('fuel-station-highlights')) {
                fuelMapInstance.addSource('fuel-station-highlights', {
                    type: 'geojson',
                    data: highlightCollection,
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
            if (!fuelMapInstance.getLayer('fuel-station-highlight-min')) {
                fuelMapInstance.addLayer({
                    id: 'fuel-station-highlight-min',
                    type: 'circle',
                    source: 'fuel-station-highlights',
                    filter: ['==', ['get', 'kind'], 'min'],
                    paint: {
                        'circle-radius': 10,
                        'circle-color': '#16a34a',
                        'circle-stroke-width': 3,
                        'circle-stroke-color': '#ffffff',
                        'circle-opacity': 0.95,
                    },
                });
            }
            if (!fuelMapInstance.getLayer('fuel-station-highlight-max')) {
                fuelMapInstance.addLayer({
                    id: 'fuel-station-highlight-max',
                    type: 'circle',
                    source: 'fuel-station-highlights',
                    filter: ['==', ['get', 'kind'], 'max'],
                    paint: {
                        'circle-radius': 10,
                        'circle-color': '#b91c1c',
                        'circle-stroke-width': 3,
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
            ['fuel-station-highlight-min', 'fuel-station-highlight-max'].forEach((layerId) => {
                fuelMapInstance.on('mouseenter', layerId, () => {
                    fuelMapInstance.getCanvas().style.cursor = 'pointer';
                });
                fuelMapInstance.on('mouseleave', layerId, () => {
                    fuelMapInstance.getCanvas().style.cursor = '';
                });
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
            ['fuel-station-highlight-min', 'fuel-station-highlight-max'].forEach((layerId) => {
                fuelMapInstance.on('click', layerId, (event) => {
                    const feature = event.features && event.features[0];
                    if (!feature || !fuelMapPopup) {
                        return;
                    }
                    fuelMapPopup
                        .setLngLat(feature.geometry.coordinates)
                        .setHTML(fuelMapPopupHtml(feature.properties || {}))
                        .addTo(fuelMapInstance);
                });
            });
            fuelMapInstance.on('moveend', scheduleFuelMapViewportRefresh);

            updateFuelMapSource(
                (fuelMapPendingData && fuelMapPendingData.collection) || collection,
                (fuelMapPendingData && fuelMapPendingData.highlight) || highlight,
                Boolean(fuelMapPendingData && fuelMapPendingData.preserveViewport) || preserveViewport
            );
            scheduleMapResize(fuelMapInstance);
            fuelMapPendingData = null;
        });
    } else if (fuelMapReady) {
        updateFuelMapSource(collection, highlight, preserveViewport);
        if (!preserveViewport) {
            scheduleMapResize(fuelMapInstance);
        }
    } else {
        fuelMapPendingData = { collection, highlight, preserveViewport };
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

        const currentRows = Array.isArray(current.rows) ? current.rows : [];
        fuelCurrentRows = fuelRowsForRendering(currentRows);
        fuelMapRows = fuelCurrentRows;
        fuelMapLegendContext = '';
        fuelMapViewportLastRequestKey = '';
        if (fuelMapAutoRefreshTimer) {
            window.clearTimeout(fuelMapAutoRefreshTimer);
            fuelMapAutoRefreshTimer = null;
        }
        renderFuelSummary(sources.sources || {});
        renderLineChart(fuelWeeklyChart, fuelWeeklyMeta, weekly.series || []);
        renderBarChart(fuelMonthlyChart, fuelMonthlyMeta, monthly.series || []);
        renderSnapshot(fuelCurrentRows);
        renderFuelMap(fuelMapRows);
        const excludedCount = currentRows.length - fuelCurrentRows.length;
        const excludedMessage = excludedCount > 0
            ? ` Excluded ${excludedCount} stale or out-of-range record${excludedCount === 1 ? '' : 's'}.`
            : '';
        fuelStatus.textContent = `Loaded ${fuelCurrentRows.length} current records for the selected filter.${excludedMessage}`;
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
        containerManagementCsrfToken = String(payload.csrf_token || '');
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

if (containerManagementEnabled && refreshContainers && restartContainer && pruneStopped && pruneImages) {
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
}

routeAddDestination.addEventListener('click', () => addRouteDestination(''));
routePlan.addEventListener('click', planRoute);
routeTest.addEventListener('click', loadRouteTestCities);
routeReset.addEventListener('click', resetRoutePlanner);
routeReturnDirect.addEventListener('change', syncRouteReturnModeControls);
routeReturnReverses.addEventListener('change', syncRouteReturnModeControls);
routeReturnOneWay.addEventListener('change', syncRouteReturnModeControls);
routeTankCapacity.addEventListener('input', syncRouteVehicleInputBounds);
routeUseOptimizer?.addEventListener('change', () => {
    syncRouteOptimizerControls();
    saveRoutePlannerState(false);
});

fuelStopFinderPlan.addEventListener('click', planFuelStopFinder);
fuelStopFinderReset.addEventListener('click', resetFuelStopFinder);
fuelStopFinderOrigin.addEventListener('input', () => saveFuelStopFinderState(false));
fuelStopFinderDestination.addEventListener('input', () => saveFuelStopFinderState(false));
fuelStopFinderFuelType.addEventListener('change', () => saveFuelStopFinderState(false));
fuelStopFinderEconomy.addEventListener('input', () => saveFuelStopFinderState(false));
attachRouteAutocomplete(fuelStopFinderOrigin);
attachRouteAutocomplete(fuelStopFinderDestination);

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
    resetFuelStopFinder({ clearStorage: false });
    syncFuelRegions();
    await loadFuelDashboard();

    const savedRouteState = loadRoutePlannerState();
    if (savedRouteState) {
        restoreRoutePlannerState(savedRouteState);
    }

    const savedFuelStopFinderState = loadFuelStopFinderState();
    if (savedFuelStopFinderState) {
        restoreFuelStopFinderState(savedFuelStopFinderState);
    }

    if (savedActiveTab === 'fuel-stop-finder-tab') {
        activateTab('fuel-stop-finder-tab');
        if (savedFuelStopFinderState && savedFuelStopFinderState.planned) {
            await planFuelStopFinder();
        }
    }
    if (savedActiveTab === 'route-planning-tab') {
        activateTab('route-planning-tab');
        if (savedRouteState && savedRouteState.planned) {
            await planRoute();
        }
    }
})();
