<?php

declare(strict_types=1);

return [
    'version' => 10,
    'name' => 'normalize_last_seen_utc',
    'transactional' => true,
    'up' => static function (PDO $pdo): void {
        $offsetSeconds = (int) $pdo->query(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())',
        )->fetchColumn();

        if ($offsetSeconds <= 0) {
            return;
        }

        $tables = [
            'fpq_site_prices_current',
            'sa_site_prices_current',
            'nsw_site_prices_current',
            'vic_site_prices_current',
            'nt_site_prices_current',
        ];
        foreach ($tables as $table) {
            $pdo->exec(
                "UPDATE `{$table}` "
                . 'SET `last_seen_at` = DATE_SUB('
                . "`last_seen_at`, INTERVAL {$offsetSeconds} SECOND"
                . ') '
                . 'WHERE `last_seen_at` > UTC_TIMESTAMP() + INTERVAL 5 MINUTE',
            );

            $futureRows = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$table}` "
                . 'WHERE `last_seen_at` > UTC_TIMESTAMP() + INTERVAL 5 MINUTE',
            )->fetchColumn();
            if ($futureRows !== 0) {
                throw new RuntimeException(
                    "UTC normalization left {$futureRows} future rows in {$table}",
                );
            }
        }
    },
];
