<?php

declare(strict_types=1);

return [
    'version' => 9,
    'name' => 'importer_last_seen',
    'transactional' => false,
    'up' => static function (PDO $pdo): void {
        $tables = [
            'fpq_site_prices_current',
            'sa_site_prices_current',
            'nsw_site_prices_current',
            'vic_site_prices_current',
            'nt_site_prices_current',
        ];
        foreach ($tables as $table) {
            $pdo->exec(
                "ALTER TABLE `{$table}` "
                . "ADD COLUMN IF NOT EXISTS `last_seen_at` DATETIME NULL AFTER `updated_at`",
            );
            $pdo->exec(
                "UPDATE `{$table}` SET `last_seen_at` = `updated_at` "
                . "WHERE `last_seen_at` IS NULL",
            );
            $pdo->exec(
                "ALTER TABLE `{$table}` MODIFY `last_seen_at` DATETIME NOT NULL",
            );
            $pdo->exec(
                "CREATE INDEX IF NOT EXISTS `idx_{$table}_last_seen` "
                . "ON `{$table}` (`last_seen_at`)",
            );
        }
    },
];
