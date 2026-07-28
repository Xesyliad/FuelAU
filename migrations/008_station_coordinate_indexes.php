<?php

declare(strict_types=1);

return [
    'version' => 8,
    'name' => 'station_coordinate_indexes',
    'transactional' => false,
    'up' => static function (PDO $pdo): void {
        $indexes = [
            'fpq_sites' => 'idx_fpq_sites_coordinates',
            'sa_stations' => 'idx_sa_stations_coordinates',
            'nsw_stations' => 'idx_nsw_stations_coordinates',
            'vic_stations' => 'idx_vic_stations_coordinates',
            'nt_stations' => 'idx_nt_stations_coordinates',
            'wa_stations' => 'idx_wa_stations_coordinates',
        ];
        foreach ($indexes as $table => $index) {
            $pdo->exec(
                "CREATE INDEX IF NOT EXISTS `{$index}` "
                . "ON `{$table}` (`latitude`, `longitude`)",
            );
        }
        fuelauAssertIndexesExist($pdo, $indexes);
    },
];
