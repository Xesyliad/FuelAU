<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/migrations',
        __DIR__ . '/tests/phpunit',
    ])
    ->append([
        __DIR__ . '/src/bootstrap.php',
        __DIR__ . '/src/api.php',
        __DIR__ . '/src/http.php',
        __DIR__ . '/src/migrations.php',
        __DIR__ . '/src/request.php',
        __DIR__ . '/src/route_optimizer.php',
        __DIR__ . '/src/web.php',
        __DIR__ . '/templates/app.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => false,
    ])
    ->setFinder($finder);
