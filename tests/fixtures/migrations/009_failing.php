<?php

declare(strict_types=1);

return [
    'version' => 9,
    'name' => 'intentional_failure_probe',
    'transactional' => true,
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "INSERT INTO `migration_failure_probe` (`message`) VALUES ('must roll back')"
        );
        throw new RuntimeException('Injected migration failure.');
    },
];
