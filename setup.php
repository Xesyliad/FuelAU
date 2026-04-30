<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

const FUELAU_SCHEMA_VERSION = 5;

function fuelauEnsureRuntimeDirectories(): void
{
    $directories = [
        [__DIR__ . '/var/docker/app-logs', 0777, null, null],
        [__DIR__ . '/var/docker/app-state', 0777, null, null],
        [__DIR__ . '/var/docker/db-data', 0777, 999, 999],
        [__DIR__ . '/var/docker/nominatim-db', 0755, 100, 103],
        [__DIR__ . '/var/docker/nominatim-db/16', 0755, 100, 103],
        [__DIR__ . '/var/docker/nominatim-db/16/main', 0700, 100, 103],
        [__DIR__ . '/var/docker/nominatim-flatnode', 0777, null, null],
        [__DIR__ . '/var/docker/osrm-data', 0777, null, null],
        [__DIR__ . '/var/docker/vic-state', 0777, null, null],
        [__DIR__ . '/var/docker/map-tiles', 0777, null, null],
    ];

    foreach ($directories as [$directory, $mode, $uid, $gid]) {
        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new RuntimeException("Unable to create runtime directory: {$directory}");
        }

        if ($uid !== null && function_exists('chown')) {
            chown($directory, $uid);
        }

        if ($gid !== null && function_exists('chgrp')) {
            chgrp($directory, $gid);
        }

        chmod($directory, $mode);
        if (!is_writable($directory)) {
            throw new RuntimeException("Runtime directory is not writable: {$directory}");
        }
    }
}

function fuelauApplyStatements(PDO $pdo, array $statements): void
{
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function fuelauEnsureSchema(PDO $pdo): void
{
    fuelauApplyStatements(
        $pdo,
        [
            <<<SQL
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` INT NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
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
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_brands` (
    `brand_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`brand_id`),
    KEY `idx_fpq_brands_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_geographic_regions` (
    `geo_region_level` TINYINT NOT NULL,
    `geo_region_id` BIGINT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `abbrev` VARCHAR(255) NULL,
    `geo_region_parent_id` BIGINT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`geo_region_level`, `geo_region_id`),
    KEY `idx_fpq_regions_parent` (`geo_region_parent_id`),
    KEY `idx_fpq_regions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_fuel_types` (
    `fuel_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`fuel_id`),
    KEY `idx_fpq_fuel_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_sites` (
    `site_id` BIGINT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `brand_id` INT NULL,
    `postcode` VARCHAR(16) NULL,
    `geo_region_level_1_id` BIGINT NULL,
    `geo_region_level_2_id` BIGINT NULL,
    `geo_region_level_3_id` BIGINT NULL,
    `geo_region_level_4_id` BIGINT NULL,
    `geo_region_level_5_id` BIGINT NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `last_modified_at` DATETIME NULL,
    `google_place_id` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`site_id`),
    KEY `idx_fpq_sites_brand` (`brand_id`),
    KEY `idx_fpq_sites_postcode` (`postcode`),
    KEY `idx_fpq_sites_region_l2` (`geo_region_level_2_id`),
    KEY `idx_fpq_sites_region_l3` (`geo_region_level_3_id`),
    CONSTRAINT `fk_fpq_sites_brand`
        FOREIGN KEY (`brand_id`) REFERENCES `fpq_brands` (`brand_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_site_prices_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `site_id` BIGINT NOT NULL,
    `fuel_id` INT NOT NULL,
    `collection_method` CHAR(1) NOT NULL,
    `transaction_date_utc` DATETIME NOT NULL,
    `price` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fpq_prices_site_fuel_time` (`site_id`, `fuel_id`, `transaction_date_utc`),
    KEY `idx_fpq_prices_date` (`transaction_date_utc`),
    KEY `idx_fpq_prices_fuel` (`fuel_id`),
    KEY `idx_fpq_prices_site` (`site_id`),
    KEY `idx_fpq_prices_date_fuel` (`transaction_date_utc`, `fuel_id`),
    KEY `idx_fpq_prices_date_site` (`transaction_date_utc`, `site_id`),
    KEY `idx_fpq_prices_site_fuel` (`site_id`, `fuel_id`),
    CONSTRAINT `fk_fpq_prices_site`
        FOREIGN KEY (`site_id`) REFERENCES `fpq_sites` (`site_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_fpq_prices_fuel`
        FOREIGN KEY (`fuel_id`) REFERENCES `fpq_fuel_types` (`fuel_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_site_prices_current` (
    `site_id` BIGINT NOT NULL,
    `fuel_id` INT NOT NULL,
    `collection_method` CHAR(1) NOT NULL,
    `transaction_date_utc` DATETIME NOT NULL,
    `price` INT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`site_id`, `fuel_id`),
    KEY `idx_fpq_prices_current_date` (`transaction_date_utc`),
    KEY `idx_fpq_prices_current_fuel` (`fuel_id`),
    CONSTRAINT `fk_fpq_prices_current_site`
        FOREIGN KEY (`site_id`) REFERENCES `fpq_sites` (`site_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_fpq_prices_current_fuel`
        FOREIGN KEY (`fuel_id`) REFERENCES `fpq_fuel_types` (`fuel_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_sync_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(64) NOT NULL,
    `started_at_utc` DATETIME NOT NULL,
    `finished_at_utc` DATETIME NULL,
    `status` ENUM('started', 'success', 'error') NOT NULL,
    `rows_processed` INT NOT NULL DEFAULT 0,
    `message` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_fpq_sync_runs_job_started` (`job_name`, `started_at_utc`),
    KEY `idx_fpq_sync_runs_status_started` (`status`, `started_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_stage_sites` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sync_batch_id` CHAR(36) NOT NULL,
    `site_id` BIGINT NOT NULL,
    `name` VARCHAR(255) NULL,
    `address` VARCHAR(255) NULL,
    `brand_id` INT NULL,
    `postcode` VARCHAR(16) NULL,
    `geo_region_level_1_id` BIGINT NULL,
    `geo_region_level_2_id` BIGINT NULL,
    `geo_region_level_3_id` BIGINT NULL,
    `geo_region_level_4_id` BIGINT NULL,
    `geo_region_level_5_id` BIGINT NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `last_modified_at` VARCHAR(64) NULL,
    `google_place_id` VARCHAR(255) NULL,
    `raw_payload` JSON NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fpq_stage_sites_batch` (`sync_batch_id`),
    KEY `idx_fpq_stage_sites_site_id` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `fpq_stage_prices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sync_batch_id` CHAR(36) NOT NULL,
    `site_id` BIGINT NOT NULL,
    `fuel_id` INT NOT NULL,
    `collection_method` CHAR(1) NULL,
    `transaction_date_utc` VARCHAR(64) NOT NULL,
    `price` DECIMAL(10, 3) NOT NULL,
    `raw_payload` JSON NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fpq_stage_prices_batch` (`sync_batch_id`),
    KEY `idx_fpq_stage_prices_site_id` (`site_id`),
    KEY `idx_fpq_stage_prices_fuel_id` (`fuel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `vic_brands` (
    `brand_id` VARCHAR(64) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `brand_type` VARCHAR(64) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`brand_id`),
    KEY `idx_vic_brands_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `vic_fuel_types` (
    `fuel_code` VARCHAR(32) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`fuel_code`),
    KEY `idx_vic_fuel_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `vic_stations` (
    `station_id` VARCHAR(64) NOT NULL,
    `brand_id` VARCHAR(64) NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `contact_phone` VARCHAR(64) NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `updated_at_utc` DATETIME NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`station_id`),
    KEY `idx_vic_stations_brand` (`brand_id`),
    KEY `idx_vic_stations_name` (`name`),
    CONSTRAINT `fk_vic_stations_brand`
        FOREIGN KEY (`brand_id`) REFERENCES `vic_brands` (`brand_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `vic_site_prices_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `station_id` VARCHAR(64) NOT NULL,
    `fuel_code` VARCHAR(32) NOT NULL,
    `updated_at_utc` DATETIME NOT NULL,
    `is_available` TINYINT(1) NOT NULL DEFAULT 1,
    `price` DECIMAL(10, 3) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vic_prices_station_fuel_time` (`station_id`, `fuel_code`, `updated_at_utc`),
    KEY `idx_vic_prices_history_updated` (`updated_at_utc`),
    KEY `idx_vic_prices_history_station` (`station_id`),
    KEY `idx_vic_prices_history_fuel` (`fuel_code`),
    CONSTRAINT `fk_vic_prices_history_station`
        FOREIGN KEY (`station_id`) REFERENCES `vic_stations` (`station_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_vic_prices_history_fuel`
        FOREIGN KEY (`fuel_code`) REFERENCES `vic_fuel_types` (`fuel_code`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `vic_site_prices_current` (
    `station_id` VARCHAR(64) NOT NULL,
    `fuel_code` VARCHAR(32) NOT NULL,
    `updated_at_utc` DATETIME NOT NULL,
    `is_available` TINYINT(1) NOT NULL DEFAULT 1,
    `price` DECIMAL(10, 3) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`station_id`, `fuel_code`),
    KEY `idx_vic_prices_current_updated` (`updated_at_utc`),
    CONSTRAINT `fk_vic_prices_current_station`
        FOREIGN KEY (`station_id`) REFERENCES `vic_stations` (`station_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_vic_prices_current_fuel`
        FOREIGN KEY (`fuel_code`) REFERENCES `vic_fuel_types` (`fuel_code`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `vic_sync_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(64) NOT NULL,
    `started_at_utc` DATETIME NOT NULL,
    `finished_at_utc` DATETIME NULL,
    `status` ENUM('started', 'success', 'error') NOT NULL,
    `rows_processed` INT NOT NULL DEFAULT 0,
    `message` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_vic_sync_runs_job_started` (`job_name`, `started_at_utc`),
    KEY `idx_vic_sync_runs_status_started` (`status`, `started_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_brands` (
    `brand_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`brand_id`),
    KEY `idx_sa_brands_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_geographic_regions` (
    `geo_region_level` TINYINT NOT NULL,
    `geo_region_id` BIGINT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `abbrev` VARCHAR(255) NULL,
    `geo_region_parent_id` BIGINT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`geo_region_level`, `geo_region_id`),
    KEY `idx_sa_regions_parent` (`geo_region_parent_id`),
    KEY `idx_sa_regions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_fuel_types` (
    `fuel_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`fuel_id`),
    KEY `idx_sa_fuel_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_stations` (
    `station_id` BIGINT NOT NULL,
    `brand_id` INT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `postcode` VARCHAR(16) NULL,
    `geo_region_level_1_id` BIGINT NULL,
    `geo_region_level_2_id` BIGINT NULL,
    `geo_region_level_3_id` BIGINT NULL,
    `geo_region_level_4_id` BIGINT NULL,
    `geo_region_level_5_id` BIGINT NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `last_modified_at` DATETIME NULL,
    `google_place_id` VARCHAR(255) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`station_id`),
    KEY `idx_sa_stations_brand` (`brand_id`),
    KEY `idx_sa_stations_name` (`name`),
    KEY `idx_sa_stations_region_l3` (`geo_region_level_3_id`),
    CONSTRAINT `fk_sa_stations_brand`
        FOREIGN KEY (`brand_id`) REFERENCES `sa_brands` (`brand_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_site_prices_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `station_id` BIGINT NOT NULL,
    `fuel_id` INT NOT NULL,
    `collection_method` CHAR(1) NOT NULL,
    `transaction_date_utc` DATETIME NOT NULL,
    `price` DECIMAL(10, 3) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sa_prices_station_fuel_time` (`station_id`, `fuel_id`, `transaction_date_utc`),
    KEY `idx_sa_prices_history_updated` (`transaction_date_utc`),
    KEY `idx_sa_prices_history_station` (`station_id`),
    KEY `idx_sa_prices_history_fuel` (`fuel_id`),
    CONSTRAINT `fk_sa_prices_history_station`
        FOREIGN KEY (`station_id`) REFERENCES `sa_stations` (`station_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_sa_prices_history_fuel`
        FOREIGN KEY (`fuel_id`) REFERENCES `sa_fuel_types` (`fuel_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_site_prices_current` (
    `station_id` BIGINT NOT NULL,
    `fuel_id` INT NOT NULL,
    `collection_method` CHAR(1) NOT NULL,
    `transaction_date_utc` DATETIME NOT NULL,
    `price` DECIMAL(10, 3) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`station_id`, `fuel_id`),
    KEY `idx_sa_prices_current_updated` (`transaction_date_utc`),
    KEY `idx_sa_prices_current_fuel` (`fuel_id`),
    CONSTRAINT `fk_sa_prices_current_station`
        FOREIGN KEY (`station_id`) REFERENCES `sa_stations` (`station_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_sa_prices_current_fuel`
        FOREIGN KEY (`fuel_id`) REFERENCES `sa_fuel_types` (`fuel_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `sa_sync_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(64) NOT NULL,
    `started_at_utc` DATETIME NOT NULL,
    `finished_at_utc` DATETIME NULL,
    `status` ENUM('started', 'success', 'error') NOT NULL,
    `rows_processed` INT NOT NULL DEFAULT 0,
    `message` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sa_sync_runs_job_started` (`job_name`, `started_at_utc`),
    KEY `idx_sa_sync_runs_status_started` (`status`, `started_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `nsw_brands` (
    `state` CHAR(3) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`state`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `nsw_fuel_types` (
    `state` CHAR(3) NOT NULL,
    `fuel_code` VARCHAR(32) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`state`, `fuel_code`),
    KEY `idx_nsw_fuel_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `nsw_stations` (
    `state` CHAR(3) NOT NULL,
    `station_code` VARCHAR(32) NOT NULL,
    `station_id` VARCHAR(64) NULL,
    `brand_name` VARCHAR(255) NULL,
    `brand_id` VARCHAR(64) NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `latitude` DECIMAL(10, 7) NULL,
    `longitude` DECIMAL(10, 7) NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`state`, `station_code`),
    KEY `idx_nsw_stations_brand_name` (`brand_name`),
    KEY `idx_nsw_stations_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `nsw_site_prices_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `state` CHAR(3) NOT NULL,
    `station_code` VARCHAR(32) NOT NULL,
    `fuel_code` VARCHAR(32) NOT NULL,
    `last_updated_at` DATETIME NOT NULL,
    `price` DECIMAL(10, 3) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nsw_prices_station_fuel_time` (`state`, `station_code`, `fuel_code`, `last_updated_at`),
    KEY `idx_nsw_prices_history_updated` (`last_updated_at`),
    KEY `idx_nsw_prices_history_station` (`state`, `station_code`),
    KEY `idx_nsw_prices_history_fuel` (`state`, `fuel_code`),
    CONSTRAINT `fk_nsw_prices_history_station`
        FOREIGN KEY (`state`, `station_code`) REFERENCES `nsw_stations` (`state`, `station_code`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_nsw_prices_history_fuel`
        FOREIGN KEY (`state`, `fuel_code`) REFERENCES `nsw_fuel_types` (`state`, `fuel_code`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `nsw_site_prices_current` (
    `state` CHAR(3) NOT NULL,
    `station_code` VARCHAR(32) NOT NULL,
    `fuel_code` VARCHAR(32) NOT NULL,
    `last_updated_at` DATETIME NOT NULL,
    `price` DECIMAL(10, 3) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`state`, `station_code`, `fuel_code`),
    KEY `idx_nsw_prices_current_updated` (`last_updated_at`),
    CONSTRAINT `fk_nsw_prices_current_station`
        FOREIGN KEY (`state`, `station_code`) REFERENCES `nsw_stations` (`state`, `station_code`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_nsw_prices_current_fuel`
        FOREIGN KEY (`state`, `fuel_code`) REFERENCES `nsw_fuel_types` (`state`, `fuel_code`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS `nsw_sync_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(64) NOT NULL,
    `started_at_utc` DATETIME NOT NULL,
    `finished_at_utc` DATETIME NULL,
    `status` ENUM('started', 'success', 'error') NOT NULL,
    `rows_processed` INT NOT NULL DEFAULT 0,
    `message` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_nsw_sync_runs_job_started` (`job_name`, `started_at_utc`),
    KEY `idx_nsw_sync_runs_status_started` (`status`, `started_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ]
    );

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO `schema_migrations` (`version`) VALUES (:version)'
    );
    foreach ([1, FUELAU_SCHEMA_VERSION] as $version) {
        $statement->execute(['version' => $version]);
    }
}

fuelauEnsureRuntimeDirectories();

$pdo = fuelauPdo();
fuelauEnsureSchema($pdo);

fwrite(STDOUT, 'FuelAU schema is up to date at version ' . FUELAU_SCHEMA_VERSION . PHP_EOL);
