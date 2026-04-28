<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

const FUELAU_SCHEMA_VERSION = 2;

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
        ]
    );

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO `schema_migrations` (`version`) VALUES (:version)'
    );
    foreach ([1, FUELAU_SCHEMA_VERSION] as $version) {
        $statement->execute(['version' => $version]);
    }
}

$pdo = fuelauPdo();
fuelauEnsureSchema($pdo);

fwrite(STDOUT, 'FuelAU schema is up to date at version ' . FUELAU_SCHEMA_VERSION . PHP_EOL);
