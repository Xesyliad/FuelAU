<?php

declare(strict_types=1);

return [
    'version' => 7,
    'name' => 'baseline_schema',
    'transactional' => false,
    'up' => static function (PDO $pdo): void {
        if (!function_exists('fuelauEnsureBaselineSchema')) {
            throw new RuntimeException('Baseline schema installer is unavailable.');
        }

        fuelauEnsureBaselineSchema($pdo);
        fuelauAssertTablesExist($pdo, [
            'fpq_sites',
            'fpq_site_prices_current',
            'sa_stations',
            'sa_site_prices_current',
            'nsw_stations',
            'nsw_site_prices_current',
            'vic_stations',
            'vic_site_prices_current',
            'nt_stations',
            'nt_site_prices_current',
            'wa_stations',
            'wa_site_prices_current',
        ]);
    },
];
