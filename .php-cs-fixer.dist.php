<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => [
            'include' => ['@internal'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                __DIR__ . '/benchmarks',
                __DIR__ . '/examples',
                __DIR__ . '/src',
                __DIR__ . '/tests',
            ])
            ->append([
                __FILE__,
                __DIR__ . '/testo.php',
            ]),
    );
