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
    string $cspNonce,
): void {
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

    // PHP emits warnings as HTML by default. Convert them to exceptions while
    // handling API requests so the JSON contract is preserved on failures.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        if ($request->path === '/') {
            fuelauRenderAppPage(
                fuelauConfigBool($config, 'CONTAINER_MANAGEMENT_ENABLED', false),
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
    } finally {
        restore_error_handler();
    }
}
