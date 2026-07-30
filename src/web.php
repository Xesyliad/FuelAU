<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/docker.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/routing.php';
require_once __DIR__ . '/fuel.php';
require_once __DIR__ . '/route_optimizer.php';
require_once __DIR__ . '/route_planning.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/api.php';

function fuelauApplyBrowserSecurityHeaders(string $cspNonce): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-{$cspNonce}'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "font-src 'self' data:; "
        . "worker-src 'self' blob:; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
    );
}

function fuelauRenderAppPage(
    bool $containerManagementEnabled,
    bool $routeOptimizerV2Enabled,
    bool $routeOptimizerV2Default,
    string $cspNonce
): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $mapConfig = fuelauMapTileConfig();
    require fuelauProjectRoot() . '/templates/app.php';
}

function fuelauRunWebApplication(): void
{
    $config = fuelauConfig();
    $request = FuelauHttpRequest::fromGlobals();
    $cspNonce = base64_encode(random_bytes(18));
    fuelauApplyBrowserSecurityHeaders($cspNonce);

    try {
        if ($request->path === '/') {
            fuelauRenderAppPage(
                fuelauConfigBool($config, 'CONTAINER_MANAGEMENT_ENABLED', false),
                fuelauConfigBool($config, 'ROUTE_OPTIMIZER_V2_ENABLED', false),
                fuelauConfigBool($config, 'ROUTE_OPTIMIZER_V2_DEFAULT', false),
                $cspNonce,
            );
            return;
        }

        fuelauDispatchApi($request, $config);
    } catch (Throwable $exception) {
        error_log(sprintf(
            'FuelAU request failed for %s: %s',
            $request->path,
            $exception->getMessage(),
        ));
        fuelauJsonResponse([
            'error' => 'server_error',
            'message' => 'An internal error occurred.',
        ], 500);
    }
}
