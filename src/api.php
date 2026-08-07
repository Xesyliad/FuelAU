<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $config
 */
function fuelauDispatchApi(FuelauHttpRequest $request, array $config): never
{
    fuelauEnforceApiMethod($request);

    if ($request->path === '/api/health') {
        fuelauHealthController();
    }

    if ($request->path === '/api/docker/session') {
        fuelauDockerSessionController($request, $config);
    }

    if (str_starts_with($request->path, '/api/docker/')) {
        fuelauAuthorizeDockerController($config);
    }

    if ($request->path === '/api/docker/status') {
        fuelauDockerStatusController();
    }

    if ($request->path === '/api/services/status') {
        fuelauServicesStatusController();
    }

    if ($request->path === '/api/geo/search') {
        fuelauGeoSearchController($request);
    }

    if ($request->path === '/api/geo/reverse') {
        fuelauGeoReverseController($request);
    }

    if ($request->path === '/api/route') {
        fuelauRouteController($request);
    }

    if ($request->path === '/api/route/optimize') {
        fuelauRouteOptimizationController($request);
    }

    if ($request->path === '/api/map/config') {
        fuelauJsonResponse(fuelauMapTileConfig());
    }

    if ($request->path === '/api/fuel/sources') {
        fuelauFuelSourcesController();
    }

    if ($request->path === '/api/fuel/options') {
        fuelauFuelOptionsController();
    }

    if ($request->path === '/api/fuel/route-candidates') {
        fuelauRouteCandidatesController($request);
    }

    if ($request->path === '/api/fuel/current') {
        fuelauCurrentFuelController($request);
    }

    if ($request->path === '/api/fuel/history') {
        fuelauHistoricalFuelController($request);
    }

    if (preg_match('#^/api/docker/containers/([a-f0-9]+)/logs$#', $request->path, $matches) === 1) {
        fuelauDockerLogsController($request, $matches[1]);
    }

    if (preg_match('#^/api/docker/containers/([a-f0-9]+)/restart$#', $request->path, $matches) === 1) {
        fuelauDockerRestartController($matches[1]);
    }

    if ($request->path === '/api/docker/prune') {
        fuelauDockerPruneController($request);
    }

    fuelauJsonResponse([
        'error' => 'not_found',
        'path' => $request->path,
    ], 404);
}

function fuelauEnforceApiMethod(FuelauHttpRequest $request): void
{
    $getOnlyPaths = [
        '/api/health',
        '/api/docker/status',
        '/api/services/status',
        '/api/geo/search',
        '/api/geo/reverse',
        '/api/route',
        '/api/map/config',
        '/api/fuel/sources',
        '/api/fuel/options',
        '/api/fuel/current',
        '/api/fuel/history',
    ];
    if (in_array($request->path, $getOnlyPaths, true) && $request->method !== 'GET') {
        fuelauMethodNotAllowed('GET', 'This endpoint requires GET.');
    }

    if (
        preg_match('#^/api/docker/containers/[a-f0-9]+/logs$#', $request->path) === 1
        && $request->method !== 'GET'
    ) {
        fuelauMethodNotAllowed('GET', 'This endpoint requires GET.');
    }

    if ($request->path === '/api/docker/session' && $request->method !== 'POST') {
        fuelauMethodNotAllowed('POST', 'Container management login requires POST.');
    }

    if ($request->path === '/api/fuel/route-candidates' && $request->method !== 'POST') {
        fuelauMethodNotAllowed('POST', 'Route candidate lookup requires POST.');
    }

    if ($request->path === '/api/route/optimize' && $request->method !== 'POST') {
        fuelauMethodNotAllowed('POST', 'Route optimization requires POST.');
    }

    if (
        (
            preg_match('#^/api/docker/containers/[a-f0-9]+/restart$#', $request->path) === 1
            || $request->path === '/api/docker/prune'
        )
        && $request->method !== 'POST'
    ) {
        fuelauMethodNotAllowed('POST', 'This endpoint requires POST.', true);
    }
}

function fuelauMethodNotAllowed(string $allowedMethod, string $message, bool $dockerResponse = false): never
{
    header("Allow: {$allowedMethod}");
    $payload = [
        'error' => 'method_not_allowed',
        'message' => $message,
    ];
    if ($dockerResponse) {
        fuelauDockerApiResponse($payload, 405);
    }

    fuelauJsonResponse($payload, 405);
}

function fuelauHealthController(): never
{
    $database = 'unavailable';
    try {
        fuelauPdo()->query('SELECT 1');
        $database = 'ok';
    } catch (Throwable) {
        $database = 'unavailable';
    }

    $healthy = $database === 'ok';
    fuelauJsonResponse([
        'service' => 'fuelau-api',
        'status' => $healthy ? 'ok' : 'unavailable',
        'database' => $database,
        'time' => gmdate(DATE_ATOM),
    ], $healthy ? 200 : 503);
}

/**
 * @param array<string, mixed> $config
 */
function fuelauDockerSessionController(FuelauHttpRequest $request, array $config): never
{
    if (!fuelauConfigBool($config, 'CONTAINER_MANAGEMENT_ENABLED', false)) {
        fuelauJsonResponse([
            'error' => 'not_found',
            'message' => 'Container management is disabled.',
        ], 404);
    }

    try {
        fuelauRateLimit("container-login:{$request->remoteAddress}", 10, 300);
    } catch (FuelauRateLimitException $exception) {
        header('Retry-After: ' . $exception->retryAfterSeconds());
        fuelauJsonResponse([
            'error' => 'rate_limited',
            'message' => 'Too many container management login attempts.',
        ], 429);
    }

    try {
        $login = FuelauContainerLoginRequest::fromBody($request->jsonObject());
    } catch (FuelauValidationException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }

    $csrfToken = fuelauContainerManagementLogin($config, $login->token);
    if ($csrfToken === null) {
        fuelauJsonResponse([
            'error' => 'unauthorized',
            'message' => 'Invalid container management credentials.',
        ], 401);
    }

    header('Cache-Control: no-store');
    fuelauJsonResponse([
        'status' => 'authenticated',
        'csrf_token' => $csrfToken,
        'expires_in' => 1800,
    ]);
}

/**
 * @param array<string, mixed> $config
 */
function fuelauAuthorizeDockerController(array $config): void
{
    if (!fuelauConfigBool($config, 'CONTAINER_MANAGEMENT_ENABLED', false)) {
        fuelauJsonResponse([
            'error' => 'not_found',
            'message' => 'Container management is disabled.',
        ], 404);
    }

    if (!fuelauContainerManagementAuthorized($config)) {
        fuelauJsonResponse([
            'error' => 'unauthorized',
            'message' => 'Container management login required.',
        ], 401);
    }
}

function fuelauDockerStatusController(): never
{
    fuelauDockerApiResponse([
        'project' => fuelauDockerProject(),
        'services' => fuelauDockerServices(),
        'containers' => fuelauDockerContainers(),
        'disk' => fuelauDockerDiskSummary(),
        'csrf_token' => fuelauContainerManagementCsrfToken(),
        'session_expires_in' => 1800,
    ]);
}

function fuelauServicesStatusController(): never
{
    fuelauJsonResponse([
        'service' => 'fuelau-api',
        'upstreams' => fuelauServiceStatus(),
    ]);
}

function fuelauGeoSearchController(FuelauHttpRequest $request): never
{
    try {
        $search = FuelauGeoSearchRequest::fromQuery($request->query);
    } catch (FuelauValidationException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }

    try {
        fuelauRateLimit("geo-search:{$request->remoteAddress}", 60, 60);
        header('Cache-Control: private, max-age=300');
        fuelauJsonResponse([
            'query' => $search->query,
            'results' => fuelauCachedNominatimSearch(
                $search->query,
                $search->limit,
                fuelauProjectRoot() . '/var/docker/app-state/geocode-cache',
            ),
        ]);
    } catch (FuelauRateLimitException $exception) {
        header('Retry-After: ' . $exception->retryAfterSeconds());
        fuelauJsonResponse([
            'error' => 'rate_limited',
            'message' => 'Geocoding is temporarily rate limited.',
        ], 429);
    } catch (FuelauUpstreamException $exception) {
        error_log('FuelAU geo search failed: ' . $exception->getMessage());
        fuelauJsonResponse([
            'error' => 'upstream_unavailable',
            'message' => fuelauNominatimUnavailableMessage(),
        ], 503);
    }
}

function fuelauGeoReverseController(FuelauHttpRequest $request): never
{
    try {
        $coordinates = FuelauCoordinateRequest::fromQuery($request->query);
        fuelauRateLimit("geo-reverse:{$request->remoteAddress}", 60, 60);
        fuelauJsonResponse([
            'result' => fuelauNominatimReverse($coordinates->latitude, $coordinates->longitude),
        ]);
    } catch (FuelauValidationException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    } catch (FuelauRateLimitException $exception) {
        header('Retry-After: ' . $exception->retryAfterSeconds());
        fuelauJsonResponse([
            'error' => 'rate_limited',
            'message' => 'Reverse geocoding is temporarily rate limited.',
        ], 429);
    } catch (FuelauUpstreamException $exception) {
        error_log('FuelAU geo reverse failed: ' . $exception->getMessage());
        fuelauJsonResponse([
            'error' => 'upstream_unavailable',
            'message' => fuelauNominatimUnavailableMessage(),
        ], 503);
    }
}

function fuelauRouteController(FuelauHttpRequest $request): never
{
    try {
        $route = FuelauRouteRequest::fromQuery($request->query);
    } catch (FuelauValidationException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }

    try {
        fuelauRateLimit("route:{$request->remoteAddress}", 240, 60);
    } catch (FuelauRateLimitException $exception) {
        error_log('FuelAU route rate limit exceeded: ' . $exception->getMessage());
        header('Retry-After: ' . $exception->retryAfterSeconds());
        fuelauJsonResponse([
            'error' => 'rate_limited',
            'message' => 'Route planning is temporarily rate limited. Please try again in a minute.',
        ], 429);
    }

    try {
        fuelauJsonResponse(fuelauRoutePlan($route->coordinates, $route->steps));
    } catch (FuelauUpstreamException $exception) {
        error_log('FuelAU route planning failed: ' . $exception->getMessage());
        fuelauJsonResponse([
            'error' => 'upstream_unavailable',
            'message' => 'Routing service unavailable.',
        ], 503);
    }
}

function fuelauRouteOptimizationController(FuelauHttpRequest $request): never
{
    // Long multi-leg itineraries can require several optimizer passes over a
    // large fuel-state graph. Keep the normal PHP request limit from turning
    // a valid route into an HTML fatal-error response.
    // Route planning is normally bounded well below this ceiling. Keep a
    // five-minute guard for unusually large interstate corridors and slow
    // upstream routing responses so PHP can still return structured JSON.
    set_time_limit(300);

    if (strlen($request->rawBody) > 65_536) {
        fuelauJsonResponse([
            'error' => 'request_too_large',
            'message' => 'Route optimization requests must not exceed 64 KiB.',
        ], 413);
    }

    try {
        $optimizationRequest = FuelauRouteOptimizationRequest::fromBody($request->jsonObject());
    } catch (FuelauValidationException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_request',
            'message' => $exception->getMessage(),
        ], 400);
    }

    try {
        fuelauRateLimit("route-optimize:{$request->remoteAddress}", 30, 60);
    } catch (FuelauRateLimitException $exception) {
        header('Retry-After: ' . $exception->retryAfterSeconds());
        fuelauJsonResponse([
            'error' => 'rate_limited',
            'message' => 'Route optimization is temporarily rate limited.',
        ], 429);
    }

    try {
        fuelauJsonResponse(
            (new FuelauLiveRoutePlanner())->plan($optimizationRequest),
        );
    } catch (FuelauRoutePlanningUnsupportedException $exception) {
        fuelauJsonResponse([
            'error' => 'unsupported_itinerary',
            'message' => $exception->getMessage(),
        ], 422);
    } catch (FuelauRouteInfeasibleException $exception) {
        fuelauJsonResponse([
            'error' => 'route_infeasible',
            'message' => $exception->getMessage(),
        ], 422);
    } catch (FuelauRoutePlanValidationException $exception) {
        error_log('FuelAU exact route validation failed: ' . $exception->getMessage());
        fuelauJsonResponse([
            'error' => 'route_validation_failed',
            'message' => 'The selected fuel-stop route could not be validated reliably.',
        ], 503);
    } catch (FuelauUpstreamException|PDOException $exception) {
        error_log('FuelAU route optimization dependency failed: ' . $exception->getMessage());
        fuelauJsonResponse([
            'error' => 'upstream_unavailable',
            'message' => 'A required routing or fuel-price service is unavailable.',
        ], 503);
    } catch (Throwable $exception) {
        // Keep unexpected optimizer failures machine-readable. PHP warnings
        // are converted to exceptions by the web entrypoint before they can
        // leak an HTML error page into fetch().
        error_log('FuelAU route optimization failed: ' . $exception);
        fuelauJsonResponse([
            'error' => 'server_error',
            'message' => 'Route optimization failed unexpectedly. Please try again.',
        ], 500);
    }
}

function fuelauFuelSourcesController(): never
{
    $cacheDirectory = fuelauProjectRoot() . '/var/docker/app-state/aggregate-cache';
    fuelauJsonResponse([
        'sources' => fuelauCachedFuelSourceSummary(fuelauPdo(), $cacheDirectory),
    ]);
}

function fuelauFuelOptionsController(): never
{
    fuelauJsonResponse(fuelauCachedFuelOptions(
        fuelauPdo(),
        fuelauProjectRoot() . '/var/docker/app-state/aggregate-cache',
    ));
}

function fuelauRouteCandidatesController(FuelauHttpRequest $request): never
{
    try {
        fuelauRateLimit("fuel-route-candidates:{$request->remoteAddress}", 120, 60);
    } catch (FuelauRateLimitException $exception) {
        error_log('FuelAU route candidate rate limit exceeded: ' . $exception->getMessage());
        header('Retry-After: ' . $exception->retryAfterSeconds());
        fuelauJsonResponse([
            'error' => 'rate_limited',
            'message' => 'Route fuel lookup is temporarily rate limited.',
        ], 429);
    }

    try {
        $candidateRequest = FuelauRouteCandidateRequest::fromBody($request->jsonObject());
        $rows = fuelauCachedRouteCandidateRows(
            fuelauPdo(),
            $candidateRequest->points,
            $candidateRequest->fuel,
            $candidateRequest->radiusKm,
            $candidateRequest->limit,
            fuelauProjectRoot() . '/var/docker/app-state/route-candidate-cache',
        );
        header('Cache-Control: private, max-age=30');
        fuelauJsonResponse([
            'radius_km' => $candidateRequest->radiusKm,
            'rows' => $rows,
        ]);
    } catch (InvalidArgumentException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }
}

function fuelauCurrentFuelController(FuelauHttpRequest $request): never
{
    try {
        $fuelRequest = FuelauFuelFilterRequest::current($request->query);
    } catch (InvalidArgumentException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }

    $filters = $fuelRequest->toCurrentFilters();
    fuelauJsonResponse([
        'filters' => $filters,
        'rows' => fuelauNormalizedFuelRows(fuelauPdo(), $filters),
    ]);
}

function fuelauHistoricalFuelController(FuelauHttpRequest $request): never
{
    try {
        $fuelRequest = FuelauFuelFilterRequest::history($request->query);
    } catch (InvalidArgumentException $exception) {
        fuelauJsonResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }

    $filters = $fuelRequest->toHistoricalFilters();
    header('Cache-Control: private, max-age=60');
    fuelauJsonResponse([
        'filters' => $filters,
        'series' => fuelauCachedHistoricalSeries(
            fuelauPdo(),
            $filters,
            fuelauProjectRoot() . '/var/docker/app-state/history-cache',
        ),
    ]);
}

function fuelauDockerLogsController(FuelauHttpRequest $request, string $matchedContainerId): never
{
    $containerId = fuelauDockerContainerId($matchedContainerId);
    $tail = max(10, min(1000, FuelauRequestValue::int($request->query['tail'] ?? 200, 200)));
    $response = fuelauDockerRequest(
        'GET',
        "/containers/{$containerId}/logs?stdout=1&stderr=1&timestamps=1&tail={$tail}",
    );
    fuelauDockerApiResponse([
        'id' => substr($containerId, 0, 12),
        'logs' => fuelauDockerLogText((string) ($response['raw'] ?? '')),
    ]);
}

function fuelauDockerRestartController(string $matchedContainerId): never
{
    fuelauRequireDockerCsrf();
    $containerId = fuelauDockerContainerId($matchedContainerId);
    fuelauDockerRequest('POST', "/containers/{$containerId}/restart?t=10");
    fuelauDockerApiResponse([
        'id' => substr($containerId, 0, 12),
        'status' => 'restarted',
    ]);
}

function fuelauDockerPruneController(FuelauHttpRequest $request): never
{
    fuelauRequireDockerCsrf();

    try {
        $pruneRequest = FuelauDockerPruneRequest::fromBody($request->jsonObject());
    } catch (FuelauValidationException $exception) {
        fuelauDockerApiResponse([
            'error' => 'invalid_query',
            'message' => $exception->getMessage(),
        ], 400);
    }

    if ($pruneRequest->action === 'stopped_project_containers') {
        $filters = fuelauDockerFilters([
            'label' => ['com.docker.compose.project=' . fuelauDockerProject()],
        ]);
        $result = fuelauDockerRequest('POST', "/containers/prune?filters={$filters}");
        fuelauDockerApiResponse([
            'message' => 'Stopped project containers pruned.',
            'result' => $result,
        ]);
    }

    if ($pruneRequest->action === 'dangling_images') {
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

function fuelauRequireDockerCsrf(): void
{
    if (!fuelauContainerManagementCsrfValid()) {
        fuelauDockerApiResponse([
            'error' => 'csrf_failed',
            'message' => 'The container management session could not verify this action.',
        ], 403);
    }
}
