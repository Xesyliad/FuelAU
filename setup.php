<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

const FUELAU_SCHEMA_VERSION = 1;

function fuelauEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` INT NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $pdo->exec(
        <<<SQL
CREATE TABLE IF NOT EXISTS `cron_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(128) NOT NULL,
    `started_at_utc` DATETIME NOT NULL,
    `finished_at_utc` DATETIME NULL,
    `status` ENUM('started', 'success', 'error') NOT NULL,
    `message` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cron_runs_job_started` (`job_name`, `started_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO `schema_migrations` (`version`) VALUES (:version)'
    );
    $statement->execute(['version' => FUELAU_SCHEMA_VERSION]);
}

$pdo = fuelauPdo();
fuelauEnsureSchema($pdo);

fwrite(STDOUT, 'FuelAU schema is up to date at version ' . FUELAU_SCHEMA_VERSION . PHP_EOL);
