<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/web.php';

/**
 * Read-only live validation harness for the feature-gated route optimizer.
 *
 * Usage:
 *   php scripts/validate-route-optimizer.php
 *   php scripts/validate-route-optimizer.php brisbane-cairns
 *   php scripts/validate-route-optimizer.php brisbane-sydney direct
 *   php scripts/validate-route-optimizer.php rural-all
 */

$routes = [
    'brisbane-ipswich' => [
        'origin' => [-27.4698, 153.0251],
        'destination' => [-27.6167, 152.7600],
    ],
    'brisbane-cairns' => [
        'origin' => [-27.4698, 153.0251],
        'destination' => [-16.9186, 145.7781],
    ],
    'brisbane-sydney' => [
        'origin' => [-27.4698, 153.0251],
        'destination' => [-33.8688, 151.2093],
    ],
    'sydney-melbourne' => [
        'origin' => [-33.8688, 151.2093],
        'destination' => [-37.8136, 144.9631],
    ],
    'adelaide-perth' => [
        'origin' => [-34.9285, 138.6007],
        'destination' => [-31.9523, 115.8613],
    ],
    'perth-darwin' => [
        'origin' => [-31.9523, 115.8613],
        'destination' => [-12.4634, 130.8456],
    ],
    'darwin-alice-springs' => [
        'origin' => [-12.4634, 130.8456],
        'destination' => [-23.6980, 133.8807],
    ],
    'alice-springs-adelaide' => [
        'origin' => [-23.6980, 133.8807],
        'destination' => [-34.9285, 138.6007],
    ],
    'toowoomba-dalby' => [
        'origin' => [-27.5598, 151.9507],
        'destination' => [-27.1817, 151.2621],
    ],
    'armidale-tamworth' => [
        'origin' => [-30.5146, 151.6658],
        'destination' => [-31.0927, 150.9320],
    ],
    'rockhampton-emerald' => [
        'origin' => [-23.3781, 150.5100],
        'destination' => [-23.5268, 148.1606],
    ],
    'dubbo-cobar' => [
        'origin' => [-32.2569, 148.6011],
        'destination' => [-31.4980, 145.8383],
    ],
    'charleville-mount-isa' => [
        'origin' => [-26.4016, 146.2383],
        'destination' => [-20.7256, 139.4927],
    ],
    'alice-springs-katherine' => [
        'origin' => [-23.6980, 133.8807],
        'destination' => [-14.4652, 132.2635],
    ],
];
$ruralRouteNames = [
    'toowoomba-dalby',
    'armidale-tamworth',
    'rockhampton-emerald',
    'dubbo-cobar',
    'charleville-mount-isa',
    'alice-springs-katherine',
];

$selectedRoute = trim((string) ($argv[1] ?? 'all'));
$returnMode = trim((string) ($argv[2] ?? 'one_way'));
if (!in_array($returnMode, ['one_way', 'direct', 'reverse'], true)) {
    fwrite(STDERR, 'Return mode must be one_way, direct, or reverse.' . PHP_EOL);
    exit(2);
}
if ($selectedRoute === 'rural-all') {
    $routes = array_intersect_key($routes, array_flip($ruralRouteNames));
} elseif ($selectedRoute !== 'all') {
    if (!isset($routes[$selectedRoute])) {
        fwrite(
            STDERR,
            'Unknown route. Choose rural-all or one of: '
                . implode(', ', array_keys($routes))
                . PHP_EOL,
        );
        exit(2);
    }
    $routes = [$selectedRoute => $routes[$selectedRoute]];
}

$failed = false;
foreach ($routes as $name => $route) {
    $dependencyTimeNs = [
        'route' => 0,
        'candidate' => 0,
        'table' => 0,
    ];
    $planner = new FuelauLiveRoutePlanner(
        routeLoader: static function (array $coordinates) use (&$dependencyTimeNs): array {
            $startedAt = hrtime(true);
            try {
                $payload = fuelauRoutePlan($coordinates, false);
                $route = $payload['routes'][0] ?? null;
                if (($payload['code'] ?? null) !== 'Ok' || !is_array($route)) {
                    throw new FuelauUpstreamException('OSRM did not return a usable route.');
                }

                return $route;
            } finally {
                $dependencyTimeNs['route'] += hrtime(true) - $startedAt;
            }
        },
        candidateLoader: static function (
            array $points,
            string $fuel,
        ) use (&$dependencyTimeNs): array {
            $startedAt = hrtime(true);
            try {
                return fuelauCachedCoverageBalancedRouteCandidateRows(
                    fuelauPdo(),
                    $points,
                    $fuel,
                    75,
                    5_000,
                    fuelauProjectRoot() . '/var/docker/app-state/route-candidate-cache',
                );
            } finally {
                $dependencyTimeNs['candidate'] += hrtime(true) - $startedAt;
            }
        },
        tableLoader: static function (array $coordinates) use (&$dependencyTimeNs): array {
            $startedAt = hrtime(true);
            try {
                return fuelauOsrmTable($coordinates);
            } finally {
                $dependencyTimeNs['table'] += hrtime(true) - $startedAt;
            }
        },
        alternativeRouteLoader: static function (
            array $coordinates,
        ) use (&$dependencyTimeNs): array {
            $startedAt = hrtime(true);
            try {
                $payload = fuelauAlternativeRoutePlan($coordinates, 3, false);
                if (($payload['code'] ?? null) !== 'Ok') {
                    throw new FuelauUpstreamException(
                        'OSRM did not return usable alternative routes.',
                    );
                }

                return is_array($payload['routes'] ?? null)
                    ? array_slice($payload['routes'], 0, 3)
                    : [];
            } finally {
                $dependencyTimeNs['route'] += hrtime(true) - $startedAt;
            }
        },
    );
    $startedAt = hrtime(true);
    try {
        $request = FuelauRouteOptimizationRequest::fromBody([
            'version' => 1,
            'origin' => [
                'lat' => $route['origin'][0],
                'lon' => $route['origin'][1],
            ],
            'destinations' => [[
                'lat' => $route['destination'][0],
                'lon' => $route['destination'][1],
            ]],
            'return_mode' => $returnMode,
            'fuel' => [
                'type' => 'Diesel',
                'tank_capacity_l' => 80,
                'starting_fuel_l' => 50,
                'economy_l_per_100km' => 10,
                'reserve_l' => 10,
            ],
        ]);
        $response = $planner->plan($request);
        $elapsedNs = hrtime(true) - $startedAt;
        $dependencyNs = array_sum($dependencyTimeNs);
        $summary = $response['summary'];
        $result = [
            'route' => $name,
            'return_mode' => $returnMode,
            'status' => 'ok',
            'elapsed_ms' => (int) round($elapsedNs / 1_000_000),
            'dependency_ms' => [
                'route' => (int) round($dependencyTimeNs['route'] / 1_000_000),
                'candidate' => (int) round($dependencyTimeNs['candidate'] / 1_000_000),
                'table' => (int) round($dependencyTimeNs['table'] / 1_000_000),
            ],
            'php_planning_ms' => (int) round(($elapsedNs - $dependencyNs) / 1_000_000),
            'distance_km' => round(((int) $summary['route_distance_m']) / 1_000, 1),
            'duration_hours' => round(((int) $summary['route_duration_s']) / 3_600, 2),
            'fuel_purchased_l' => $summary['fuel_purchased_l'],
            'fuel_cost_dollars' => round(((int) $summary['fuel_purchase_cost_cents']) / 100, 2),
            'ending_fuel_l' => $summary['ending_fuel_l'],
            'required_stops' => $summary['required_stop_count'],
            'discretionary_stops' => $summary['discretionary_stop_count'],
            'combined_stops' => $summary['combined_stop_count'],
            'selected_corridor' => $response['corridor']['id'],
            'selected_corridor_kind' => $response['corridor']['kind'],
            'corridor_selection_reason' => $response['corridor']['selection_reason'],
            'corridors_compared' => $response['diagnostics']['corridor_count'],
            'feasible_corridors' => $response['diagnostics']['feasible_corridor_count'],
            'alternatives' => $response['alternatives'],
            'raw_candidates' => $response['diagnostics']['raw_candidate_count'],
            'evaluated_raw_candidates' =>
                $response['diagnostics']['evaluated_raw_candidate_count'],
            'network_candidates' => $response['diagnostics']['network_shortlist_count'],
            'osrm_route_requests' => $response['diagnostics']['osrm_route_request_count'],
            'osrm_table_requests' => $response['diagnostics']['osrm_table_request_count'],
            'validation_passes' => $response['diagnostics']['validation_pass_count'],
            'stops' => array_map(
                static fn (array $stop): array => [
                    'station' => $stop['station']['station_name'],
                    'classification' => $stop['classification'],
                    'progress_km' => $stop['route_progress_km'],
                    'purchase_l' => $stop['purchase_l'],
                    'price_cents_per_l' => $stop['price_cents_per_l'],
                    'arrival_fuel_l' => $stop['arrival_fuel_l'],
                    'departure_fuel_l' => $stop['departure_fuel_l'],
                    'marginal_net_saving_dollars' => round(
                        ((int) $stop['marginal_net_saving_cents']) / 100,
                        2,
                    ),
                    'reason_codes' => $stop['reason_codes'],
                ],
                $response['stops'],
            ),
            'warnings' => $response['warnings'],
        ];
    } catch (Throwable $exception) {
        $failed = true;
        $elapsedNs = hrtime(true) - $startedAt;
        $dependencyNs = array_sum($dependencyTimeNs);
        $result = [
            'route' => $name,
            'return_mode' => $returnMode,
            'status' => 'failed',
            'elapsed_ms' => (int) round($elapsedNs / 1_000_000),
            'dependency_ms' => [
                'route' => (int) round($dependencyTimeNs['route'] / 1_000_000),
                'candidate' => (int) round($dependencyTimeNs['candidate'] / 1_000_000),
                'table' => (int) round($dependencyTimeNs['table'] / 1_000_000),
            ],
            'php_planning_ms' => (int) round(($elapsedNs - $dependencyNs) / 1_000_000),
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ];
    }

    fwrite(
        STDOUT,
        (json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
            . PHP_EOL,
    );
}

exit($failed ? 1 : 0);
