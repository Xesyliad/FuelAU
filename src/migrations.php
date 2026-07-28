<?php

declare(strict_types=1);

function fuelauEnsureMigrationTable(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` INT NOT NULL,
    `name` VARCHAR(255) NULL,
    `checksum` CHAR(64) NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    );
    $pdo->exec(
        'ALTER TABLE `schema_migrations` '
        . 'ADD COLUMN IF NOT EXISTS `name` VARCHAR(255) NULL AFTER `version`, '
        . 'ADD COLUMN IF NOT EXISTS `checksum` CHAR(64) NULL AFTER `name`',
    );
}

function fuelauLoadMigrations(string $directory): array
{
    $paths = glob(rtrim($directory, '/') . '/*.php') ?: [];
    sort($paths, SORT_STRING);
    $migrations = [];

    foreach ($paths as $path) {
        $migration = require $path;
        if (!is_array($migration)) {
            throw new RuntimeException("Migration must return an array: {$path}");
        }

        $version = (int) ($migration['version'] ?? 0);
        $name = trim((string) ($migration['name'] ?? ''));
        $up = $migration['up'] ?? null;
        if ($version <= 0 || $name === '' || !is_callable($up)) {
            throw new RuntimeException("Invalid migration definition: {$path}");
        }
        if (isset($migrations[$version])) {
            throw new RuntimeException("Duplicate migration version {$version}");
        }

        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum)) {
            throw new RuntimeException("Unable to checksum migration: {$path}");
        }
        $migration['path'] = $path;
        $migration['checksum'] = $checksum;
        $migration['transactional'] = (bool) ($migration['transactional'] ?? true);
        $migrations[$version] = $migration;
    }

    ksort($migrations, SORT_NUMERIC);
    return $migrations;
}

function fuelauInstalledMigrations(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT `version`, `name`, `checksum` FROM `schema_migrations` ORDER BY `version`',
    )->fetchAll(PDO::FETCH_ASSOC);
    $installed = [];
    foreach ($rows as $row) {
        $installed[(int) $row['version']] = [
            'name' => (string) ($row['name'] ?? ''),
            'checksum' => (string) ($row['checksum'] ?? ''),
        ];
    }

    return $installed;
}

function fuelauAssertTablesExist(PDO $pdo, array $tables): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `information_schema`.`tables` '
        . 'WHERE `table_schema` = DATABASE() AND `table_name` = :table',
    );
    foreach ($tables as $table) {
        $statement->execute(['table' => (string) $table]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException("Schema assertion failed; missing table: {$table}");
        }
    }
}

function fuelauAssertIndexesExist(PDO $pdo, array $indexes): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM `information_schema`.`statistics` '
        . 'WHERE `table_schema` = DATABASE() '
        . 'AND `table_name` = :table AND `index_name` = :index',
    );
    foreach ($indexes as $table => $index) {
        $statement->execute([
            'table' => (string) $table,
            'index' => (string) $index,
        ]);
        if ((int) $statement->fetchColumn() < 1) {
            throw new RuntimeException(
                "Schema assertion failed; missing index {$index} on {$table}",
            );
        }
    }
}

function fuelauApplyMigrations(PDO $pdo, string $directory): int
{
    fuelauEnsureMigrationTable($pdo);
    $locked = (int) $pdo->query(
        "SELECT GET_LOCK('fuelau_schema_migrations', 30)",
    )->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Timed out waiting for the schema migration lock.');
    }

    try {
        $migrations = fuelauLoadMigrations($directory);
        $installed = fuelauInstalledMigrations($pdo);

        foreach ($migrations as $version => $migration) {
            if (isset($installed[$version])) {
                $installedChecksum = $installed[$version]['checksum'];
                if (
                    $installedChecksum !== ''
                    && !hash_equals($installedChecksum, $migration['checksum'])
                ) {
                    throw new RuntimeException(
                        "Applied migration {$version} no longer matches its checksum.",
                    );
                }
                continue;
            }

            $transactional = $migration['transactional'];
            try {
                if ($transactional) {
                    $pdo->beginTransaction();
                }
                ($migration['up'])($pdo);
                $statement = $pdo->prepare(
                    'INSERT INTO `schema_migrations` '
                    . '(`version`, `name`, `checksum`) '
                    . 'VALUES (:version, :name, :checksum)',
                );
                $statement->execute([
                    'version' => $version,
                    'name' => $migration['name'],
                    'checksum' => $migration['checksum'],
                ]);
                if ($transactional) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new RuntimeException(
                    "Migration {$version} ({$migration['name']}) failed: "
                    . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        $latest = array_key_last($migrations);
        return is_int($latest) ? $latest : 0;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('fuelau_schema_migrations')");
    }
}
