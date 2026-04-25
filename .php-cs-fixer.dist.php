<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        '@PHP84Migration' => true,
        '@PSR12' => true,
        '@PHP83Migration' => true,
        '@PhpCsFixer:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => [
            'include' => ['@all'],
            'scope' => 'all',
            'strict' => true,
        ],
        'native_constant_invocation' => [
            'fix_built_in' => true,
            'exclude' => ['null', 'true', 'false'],
            'scope' => 'all',
            'strict' => true,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
    ])
    ->setFinder($finder);
