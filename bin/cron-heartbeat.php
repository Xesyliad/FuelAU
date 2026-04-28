<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$startedAt = gmdate('Y-m-d H:i:s');

try {
    $pdo = fuelauPdo();
    $statement = $pdo->prepare(
        <<<SQL
INSERT INTO `cron_runs` (`job_name`, `started_at_utc`, `finished_at_utc`, `status`, `message`)
VALUES (:job_name, :started_at_utc, :finished_at_utc, :status, :message)
SQL
    );
    $statement->execute([
        'job_name' => 'cron-heartbeat',
        'started_at_utc' => $startedAt,
        'finished_at_utc' => gmdate('Y-m-d H:i:s'),
        'status' => 'success',
        'message' => 'Heartbeat completed.',
    ]);

    fwrite(STDOUT, '[' . gmdate(DATE_ATOM) . '] cron-heartbeat success' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . gmdate(DATE_ATOM) . '] cron-heartbeat error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
